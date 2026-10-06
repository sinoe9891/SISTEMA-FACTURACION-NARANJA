<?php
/**
 * bouchers.php — Boucher en PDF de cada pago (gasto pagado), con el formato del Excel «BOUCHERS»:
 * logo, banco, lugar, fecha, «Páguese por esta transferencia a…», cantidad, concepto, la captura de la
 * transferencia y el bloque de quien recibe con su firma digital (si el pago es a un colaborador).
 * Lo usan bouchers.php (lista, vista previa, PDF y ZIP) y boucher_pdf.php.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/nomina_pagos.php';

if (!function_exists('esGastoNomina')) {   // normalmente viene de session.php; aquí por si se usa sin sesión (pruebas, scripts)
    function esGastoNomina(string $descripcion): bool
    {
        return (bool)preg_match('/^(Sueldo |Bono: |Vi[aá]tico: |Pago adicional - )/u', $descripcion);
    }
}

const BOUCHER_MAX = 400;   // por descarga (un año completo cabe)
const BOUCHER_TIPOS = ['' => 'Todos los pagos', 'nomina' => 'Solo nómina (colaboradores)', 'otros' => 'Otros gastos'];

/** Filtros de la página de bouchers (por defecto: el mes en curso). */
function boucherFiltros(array $g): array
{
    $fecha = fn($v, $d) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : $d;
    $f = ['desde' => $fecha($g['desde'] ?? null, date('Y-m-01')), 'hasta' => $fecha($g['hasta'] ?? null, date('Y-m-t')),
          'tipo' => isset(BOUCHER_TIPOS[$t = (string)($g['tipo'] ?? '')]) ? $t : ''];
    if ($f['desde'] > $f['hasta']) [$f['desde'], $f['hasta']] = [$f['hasta'], $f['desde']];
    if (defined('USUARIO_ROL') && USUARIO_ROL === 'nomina') $f['tipo'] = 'nomina';   // el rol Nómina solo ve pagos a colaboradores
    return $f;
}

/** Contexto común (logo, ciudad, banco, colaboradores…) para armar muchos bouchers sin repetir consultas. */
function boucherContexto(PDO $pdo, int $cid, string $uploads): array
{
    $b64 = fn(?string $r) => ($r && is_file($r) && str_starts_with($m = (string)(new finfo(FILEINFO_MIME_TYPE))->file($r), 'image/')) ? 'data:' . $m . ';base64,' . base64_encode(file_get_contents($r)) : '';
    $emp = $pdo->prepare("SELECT nombre, alias, logo_url FROM clientes_saas WHERE id = ?");
    $emp->execute([$cid]);
    $emp = $emp->fetch(PDO::FETCH_ASSOC) ?: [];
    $logo = '';
    if (!empty($emp['logo_url']) && !preg_match('/\.svg($|\?)/i', $emp['logo_url'])) {
        $d = @file_get_contents($emp['logo_url'], false, stream_context_create(['http' => ['timeout' => 4], 'https' => ['timeout' => 4]]));
        if ($d && str_starts_with($m = (string)(new finfo(FILEINFO_MIME_TYPE))->buffer($d), 'image/')) $logo = 'data:' . $m . ';base64,' . base64_encode($d);
    }
    if (!$logo) $logo = $b64(__DIR__ . '/../clientes/css/logo-correo.png');

    $st = $pdo->prepare("SELECT nombre FROM establecimientos WHERE cliente_id = ? ORDER BY establecimiento_id = ? DESC, establecimiento_id LIMIT 1");
    $st->execute([$cid, (int)($_SESSION['establecimiento_activo'] ?? 0)]);
    $bancoPred = '';
    $stMov = null;
    try {
        $s2 = $pdo->prepare("SELECT banco FROM cuentas_bancarias WHERE cliente_id = ? AND activa = 1 ORDER BY predeterminada DESC, id LIMIT 1");
        $s2->execute([$cid]);
        $bancoPred = (string)$s2->fetchColumn();
        $stMov = $pdo->prepare("SELECT c.banco FROM movimientos_bancarios m JOIN cuentas_bancarias c ON c.id = m.cuenta_id WHERE m.gasto_id = ? AND m.anulado = 0 LIMIT 1");
    } catch (Throwable $ignorar) {}
    $colabs = $pdo->prepare("SELECT * FROM colaboradores WHERE cliente_id = ?");
    $colabs->execute([$cid]);
    $tarjetas = [];
    try {
        $s3 = $pdo->prepare("SELECT id, banco, ultimos_digitos FROM tarjetas WHERE cliente_id = ?");
        $s3->execute([$cid]);
        foreach ($s3->fetchAll(PDO::FETCH_ASSOC) as $t) $tarjetas[(int)$t['id']] = $t;
    } catch (Throwable $ignorar) {}
    // Descuentos y extras ligados al pago de nómina (los marca colaborador_pago_guardar.php en las notas)
    $stCuotas = $stExtras = null;
    try {
        $stCuotas = $pdo->prepare("SELECT q.monto, q.numero_cuota, p.tipo, p.descripcion, p.num_cuotas FROM colaborador_prestamo_cuotas q JOIN colaborador_prestamos p ON p.id = q.prestamo_id
                                   WHERE q.cliente_id = ? AND q.estado = 'pagado' AND q.notas REGEXP ? ORDER BY q.id");
        $stExtras = $pdo->prepare("SELECT tipo, descripcion, monto_total FROM colaborador_prestamos WHERE cliente_id = ? AND tipo IN ('bono','viatico') AND notas REGEXP ? ORDER BY id");
    } catch (Throwable $ignorar) {}
    return [
        'stCuotas' => $stCuotas, 'stExtras' => $stExtras,
        'cid' => $cid, 'uploads' => rtrim($uploads, '/') . '/', 'b64' => $b64, 'logo' => $logo, 'ciudad' => (string)$st->fetchColumn(),
        'banco' => $bancoPred, 'stMov' => $stMov, 'colabs' => $colabs->fetchAll(PDO::FETCH_ASSOC), 'tarjetas' => $tarjetas,
        'usuarios' => $pdo->query("SELECT id, nombre FROM usuarios")->fetchAll(PDO::FETCH_KEY_PAIR),
    ];
}

/** Colaborador cuyo nombre completo aparece en la descripción del gasto (o null). */
function boucherColaborador(array $ctx, string $descripcion): ?array
{
    foreach ($ctx['colabs'] as $c) {
        $n = trim($c['nombre'] . ' ' . $c['apellido']);
        if ($n !== '' && mb_stripos($descripcion, $n) !== false) return $c;
    }
    return null;
}

/** Pagos (gastos pagados) del período, para la lista y los lotes. */
function boucherGastos(PDO $pdo, int $cid, array $f): array
{
    $st = $pdo->prepare("SELECT g.*, cg.nombre AS categoria FROM gastos g LEFT JOIN categorias_gastos cg ON cg.id = g.categoria_id
                         WHERE g.cliente_id = ? AND g.estado = 'pagado' AND g.fecha BETWEEN ? AND ? ORDER BY g.fecha DESC, g.id DESC");   // más reciente primero
    $st->execute([$cid, $f['desde'], $f['hasta']]);
    return array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC), function ($g) use ($f) {
        $nom = esGastoNomina((string)$g['descripcion']);
        return $f['tipo'] === '' || ($f['tipo'] === 'nomina' ? $nom : !$nom);
    }));
}

/** Datos ya resueltos de un boucher (beneficiario, concepto, banco, imágenes…). */
function boucherDatos(array $ctx, array $g): array
{
    $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $ts = strtotime($g['fecha']);
    $periodo = $meses[(int)date('n', $ts)] . ' ' . date('Y', $ts);
    $c = esGastoNomina((string)$g['descripcion']) ? boucherColaborador($ctx, (string)$g['descripcion']) : null;
    $nombreColab = $c ? trim($c['nombre'] . ' ' . $c['apellido']) : '';
    if ($c && nominaTipo($g['descripcion']) === 'sueldo') {
        $q = $g['quincena_num'] === null ? '' : ((int)$g['quincena_num'] === 1 ? '1ra quincena ' : '2da quincena ');
        $concepto = 'Pago ' . ($c['puesto'] ?: 'de sueldo') . ', ' . $q . $periodo;
    } elseif ($c) {
        $concepto = trim(preg_replace('/\s+—\s+' . preg_quote($nombreColab, '/') . '$/u', '', $g['descripcion'])) . ' · ' . $periodo;
    } else {
        $concepto = (string)$g['descripcion'];
    }
    $banco = '';
    if ($ctx['stMov']) { $ctx['stMov']->execute([$g['id']]); $banco = (string)$ctx['stMov']->fetchColumn(); }
    $metodo = (string)$g['metodo_pago'];
    if ($metodo === 'tarjeta' && !empty($g['tarjeta_id']) && isset($ctx['tarjetas'][(int)$g['tarjeta_id']])) {
        $t = $ctx['tarjetas'][(int)$g['tarjeta_id']];
        $banco = $t['banco'] . ' · tarjeta ••' . $t['ultimos_digitos'];
    }
    $comp = '';
    $compPdf = false;
    foreach (array_filter([$g['archivo_adjunto'] ? $ctx['uploads'] . 'comprobantes_nomina/' . $g['archivo_adjunto'] : null,
                           $g['archivo_adjunto'] ? $ctx['uploads'] . 'gastos/' . basename($g['archivo_adjunto']) : null]) as $r) {
        if (is_file($r)) { $comp = ($ctx['b64'])($r); $compPdf = !$comp; break; }
    }
    // Desglose: descuentos (cuotas de préstamos/adelantos) y extras (bonos/viáticos) aplicados en este pago
    $desc = $extra = [];
    if ($c && $ctx['stCuotas']) {
        $ctx['stCuotas']->execute([$ctx['cid'], 'gasto #' . $g['id'] . '([^0-9]|$)']);
        foreach ($ctx['stCuotas']->fetchAll(PDO::FETCH_ASSOC) as $q)
            $desc[] = ['texto' => ucfirst($q['tipo']) . ': ' . $q['descripcion'] . ' (cuota ' . $q['numero_cuota'] . ($q['num_cuotas'] ? ' de ' . $q['num_cuotas'] : '') . ')', 'monto' => (float)$q['monto']];
        $ctx['stExtras']->execute([$ctx['cid'], 'gasto #' . $g['id'] . ' el ']);
        foreach ($ctx['stExtras']->fetchAll(PDO::FETCH_ASSOC) as $x)
            $extra[] = ['texto' => ($x['tipo'] === 'bono' ? 'Bono' : 'Viático') . ': ' . $x['descripcion'], 'monto' => (float)$x['monto_total']];
    }
    // Notas con descuentos escritos a mano (pagos registrados antes de ligar las cuotas)
    $obs = '';
    if ($c && !$desc && preg_match('/descuent|anticipo|adelanto|deducc/iu', (string)$g['notas'])) $obs = trim(preg_replace('/\s+/', ' ', (string)$g['notas']));
    return [
        'descuentos' => $desc, 'extras' => $extra, 'observaciones' => $obs,
        'id' => (int)$g['id'], 'fecha' => $g['fecha'], 'monto' => (float)$g['monto'], 'concepto' => $concepto, 'colaborador' => $c,
        'beneficiario' => $nombreColab ?: (trim((string)$g['proveedor']) ?: ''), 'banco' => $banco ?: ($metodo === 'efectivo' ? '' : $ctx['banco']),
        'metodo' => $metodo, 'referencia' => preg_match('/ref\.\s*([A-Za-z0-9-]+)/i', (string)$g['notas'], $m) ? $m[1] : (string)($g['factura_ref'] ?? ''),
        'comprobante' => $comp, 'comprobante_pdf' => $compPdf, 'firma' => $c && $c['url_firma'] ? ($ctx['b64'])($ctx['uploads'] . 'firmas/' . $c['url_firma']) : '',
        'elaborado' => $ctx['usuarios'][$g['usuario_id'] ?? 0] ?? '', 'categoria' => $g['categoria'] ?? '',
    ];
}

/** HTML de una página de boucher (va dentro de boucherDocumento). */
function boucherPagina(array $ctx, array $d): string
{
    $e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
    $metodoTxt = ['transferencia' => 'Transferencia', 'efectivo' => 'Efectivo', 'cheque' => 'Cheque', 'tarjeta' => 'Tarjeta'][$d['metodo']] ?? ucfirst($d['metodo']);
    $verbo = ['transferencia' => 'esta transferencia', 'cheque' => 'este cheque', 'tarjeta' => 'este cargo a tarjeta', 'efectivo' => 'este pago en efectivo'][$d['metodo']] ?? 'este pago';
    $c = $d['colaborador'];
    ob_start(); ?>
    <div class="pagina">
        <table class="enc"><tr>
            <td class="enc-logo"><?php if ($ctx['logo']): ?><img src="<?= $ctx['logo'] ?>" alt=""><?php endif; ?></td>
            <td class="enc-tit"><div class="titulo">BOUCHER</div><div class="sub"><?= $e(mb_strtoupper($metodoTxt)) ?></div></td>
            <td class="enc-num"><div class="num-lbl">N.º</div><div class="num"><?= str_pad((string)$d['id'], 5, '0', STR_PAD_LEFT) ?></div></td>
        </tr></table>
        <div class="franja"></div>

        <table class="datos"><tr>
            <td><span>Banco</span><?= $e($d['banco'] ? mb_strtoupper($d['banco']) : '—') ?></td>
            <td><span>Lugar</span><?= $e($ctx['ciudad'] ?: '—') ?></td>
            <td><span>Fecha</span><?= date('d/m/Y', strtotime($d['fecha'])) ?></td>
            <td><span>Referencia</span><?= $e($d['referencia'] ?: '—') ?></td>
        </tr></table>

        <div class="paguese"><span>Páguese por <?= $verbo ?> a</span><strong><?= $e($d['beneficiario'] ?: '—') ?></strong></div>

        <table class="monto">
            <tr><td class="lbl">Concepto</td><td><?= $e($d['concepto']) ?><?= $d['categoria'] ? '<div class="cat">' . $e($d['categoria']) . '</div>' : '' ?></td></tr>
            <?php if ($d['descuentos'] || $d['extras']):
                $base = $d['monto'] + array_sum(array_column($d['descuentos'], 'monto')) - array_sum(array_column($d['extras'], 'monto')); ?>
                <tr class="det"><td class="lbl">Pago del período</td><td><span class="det-n">L <?= number_format($base, 2) ?></span></td></tr>
                <?php foreach ($d['descuentos'] as $x): ?><tr class="det menos"><td class="lbl">Descuento</td><td><span class="det-t"><?= $e($x['texto']) ?></span><span class="det-n">− L <?= number_format($x['monto'], 2) ?></span></td></tr><?php endforeach; ?>
                <?php foreach ($d['extras'] as $x): ?><tr class="det mas"><td class="lbl">Más</td><td><span class="det-t"><?= $e($x['texto']) ?></span><span class="det-n">+ L <?= number_format($x['monto'], 2) ?></span></td></tr><?php endforeach; ?>
            <?php endif; ?>
            <tr class="total"><td class="lbl">Total</td><td class="cifra">L <?= number_format($d['monto'], 2) ?></td></tr>
            <tr><td colspan="2" class="letras">Son: <?= $e(numeroALetras($d['monto'])) ?></td></tr>
            <?php if ($d['observaciones']): ?><tr><td class="lbl">Observaciones</td><td class="obs"><?= $e($d['observaciones']) ?></td></tr><?php endif; ?>
        </table>

        <div class="comp">
            <?php if ($d['comprobante']): ?><img src="<?= $d['comprobante'] ?>" alt="Comprobante">
            <?php elseif ($d['comprobante_pdf']): ?><div class="comp-vacio">El comprobante de este pago es un PDF: se archiva por separado.</div>
            <?php else: ?><div class="comp-vacio">Sin captura del comprobante.</div><?php endif; ?>
        </div>

        <table class="firmas-int"><tr>
            <td><div class="quien"><?= $e($d['elaborado'] ?: ' ') ?></div><div class="linea">Elaborado por</div></td>
            <td><div class="quien"> </div><div class="linea">Revisado</div></td>
            <td><div class="quien"> </div><div class="linea">Autorizado</div></td>
            <td><div class="quien"> </div><div class="linea">Vo.Bo.</div></td>
        </tr></table>

        <div class="recibe-tit">Recibí conforme</div>
        <table class="recibe"><tr>
            <td class="r-datos">
                <div><span>Nombre</span><?= $e($d['beneficiario'] ?: ' ') ?></div>
                <div><span>No. Identidad</span><?= $e(($c['dpi'] ?? '') ?: ' ') ?></div>
                <div><span>Fecha</span><?= date('d/m/Y', strtotime($d['fecha'])) ?></div>
            </td>
            <td class="r-firma">
                <div class="firma-caja"><?php if ($d['firma']): ?><img src="<?= $d['firma'] ?>" alt="Firma"><?php endif; ?></div>
                <div class="linea">Firma<?= $c && !$d['firma'] ? ' · <span class="sin">sin firma registrada</span>' : '' ?></div>
            </td>
        </tr></table>
    </div>
    <?php
    return (string)ob_get_clean();
}

/** Documento HTML completo con una o varias páginas de boucher. */
function boucherDocumento(array $paginas): string
{
    return '<!doctype html><html><head><meta charset="utf-8"><style>
        @page { margin: 1.1cm 1.4cm; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10px; color: #1e293b; }
        .pagina { page-break-after: always; } .pagina:last-child { page-break-after: auto; }
        table { width: 100%; border-collapse: collapse; }
        .enc td { vertical-align: middle; } .enc-logo { width: 28%; } .enc-logo img { max-height: 72px; max-width: 160px; }
        .enc-tit { text-align: center; } .titulo { font-size: 24px; font-weight: bold; letter-spacing: 6px; color: #0f172a; }
        .sub { font-size: 10px; color: #e4550d; letter-spacing: 3px; margin-top: 2px; font-weight: bold; }
        .enc-num { width: 28%; text-align: right; } .num-lbl { font-size: 9px; color: #64748b; } .num { font-size: 16px; font-weight: bold; color: #0f172a; }
        .franja { height: 4px; background: #e4550d; margin: 10px 0 12px; border-radius: 2px; }
        .datos td { width: 25%; padding: 7px 8px; background: #f8fafc; border: 1px solid #e2e8f0; font-size: 10.5px; font-weight: bold; }
        .datos span, .recibe span { display: block; font-size: 8px; font-weight: normal; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 2px; }
        .paguese { margin: 12px 0 10px; padding: 9px 10px; border-left: 4px solid #e4550d; background: #fff7ed; }
        .paguese span { display: block; font-size: 9px; color: #9a3412; text-transform: uppercase; letter-spacing: 1px; }
        .paguese strong { font-size: 14px; color: #0f172a; }
        .monto td { padding: 7px 8px; border-bottom: 1px solid #e2e8f0; } .monto .lbl { width: 20%; color: #64748b; font-size: 9px; text-transform: uppercase; letter-spacing: 1px; }
        .cat { color: #94a3b8; font-size: 8.5px; margin-top: 2px; }
        .monto .total td { background: #0f172a; color: #fff; border: 0; } .monto .total .lbl { color: #cbd5e1; }
        .cifra { font-size: 15px; font-weight: bold; text-align: right; }
        .letras { font-style: italic; color: #475569; font-size: 9.5px; }
        .det td { padding: 4px 8px; font-size: 9.5px; } .det-t { color: #334155; } .det-n { float: right; font-weight: bold; }
        .det.menos .det-n, .det.menos .lbl { color: #b91c1c; } .det.mas .det-n, .det.mas .lbl { color: #047857; }
        .obs { font-size: 8.5px; color: #64748b; }
        .comp { margin: 12px 0 6px; text-align: center; border: 1px solid #e2e8f0; border-radius: 6px; padding: 6px; }
        .comp img { max-width: 100%; max-height: 300px; } .comp-vacio { color: #94a3b8; padding: 26px 0; }
        .firmas-int { margin-top: 10px; } .firmas-int td { width: 25%; text-align: center; padding: 0 8px; vertical-align: bottom; }
        .quien { min-height: 13px; font-size: 9.5px; color: #1e293b; }
        .linea { border-top: 1px solid #64748b; padding-top: 3px; color: #64748b; font-size: 8.5px; text-transform: uppercase; letter-spacing: 1px; }
        .recibe-tit { margin-top: 14px; font-size: 9px; font-weight: bold; color: #0f172a; text-transform: uppercase; letter-spacing: 2px; border-bottom: 2px solid #0f172a; padding-bottom: 3px; }
        .recibe td { vertical-align: bottom; padding-top: 8px; } .r-datos { width: 50%; } .r-datos div { margin-bottom: 7px; font-size: 10.5px; }
        /* Firma 50% más grande y montada un poco sobre la línea (como firmada a mano) */
        .r-firma { text-align: center; } .firma-caja { height: 108px; margin-bottom: -18px; } .firma-caja img { max-height: 105px; max-width: 320px; }
        .sin { color: #b91c1c; text-transform: none; letter-spacing: 0; }
    </style></head><body>' . implode('', $paginas) . '</body></html>';
}

/** PDF (bytes) con las páginas dadas. */
function boucherPdf(array $paginas): string
{
    $opt = new \Dompdf\Options();
    $opt->set('defaultFont', 'DejaVu Sans');
    $opt->set('isRemoteEnabled', false);
    $dompdf = new \Dompdf\Dompdf($opt);
    $dompdf->loadHtml(boucherDocumento($paginas), 'UTF-8');
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();
    return (string)$dompdf->output();
}

/** Nombre de archivo de un boucher: 2026-09-30_Danny-Sinoe-Velasquez_245.pdf */
function boucherArchivo(array $d): string
{
    $n = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $d['beneficiario'] ?: $d['concepto']) ?: 'pago';
    $n = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $n), '-');
    return $d['fecha'] . '_' . substr($n ?: 'pago', 0, 40) . '_' . $d['id'] . '.pdf';
}
