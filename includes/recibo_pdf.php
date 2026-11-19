<?php
/**
 * recibo_pdf.php — PDF del recibo de cobro de un contrato «sin factura» (dompdf).
 *   reciboPdf($pdo, $cid, $reciboId)     → bytes del PDF (para descargar o adjuntar al correo)
 *   reciboArchivo($recibo)               → nombre del archivo: recibo_00012.pdf
 * Firma: «Recibido por» = quien se elige en Configuración → Firmas de documentos (por defecto el Vo.Bo., representante legal) o en el contrato.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/firmantes.php';
require_once __DIR__ . '/../vendor/autoload.php';

function reciboDatos(PDO $pdo, int $cid, int $reciboId): array
{
    $st = $pdo->prepare("
        SELECT r.*, cf.nombre AS cliente, cf.rtn AS cliente_rtn, c.nombre_contrato, c.id AS contrato
        FROM contratos_recibos r
        JOIN clientes_factura cf ON cf.id = r.receptor_id AND cf.cliente_id = r.cliente_id
        JOIN contratos c ON c.id = r.contrato_id AND c.cliente_id = r.cliente_id
        WHERE r.id = ? AND r.cliente_id = ?");
    $st->execute([$reciboId, $cid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new Exception("Recibo no encontrado.");
    return $r;
}

function reciboArchivo(array $r): string
{
    return 'recibo_' . str_pad((string)$r['numero_recibo'], 5, '0', STR_PAD_LEFT) . '.pdf';
}

function reciboPdf(PDO $pdo, int $cid, int $reciboId): string
{
    $r = reciboDatos($pdo, $cid, $reciboId);
    return reciboRender($pdo, $cid, $r + ['titulo' => 'RECIBO', 'num' => str_pad((string)$r['numero_recibo'], 5, '0', STR_PAD_LEFT),
        'pie' => 'Este recibo no es una factura y no incluye ISV.']);
}

/** Recibo de un pago anticipado (cliente que paga antes de facturar): no es factura; la factura se emite después. */
function anticipoDatos(PDO $pdo, int $cid, int $anticipoId): array
{
    $st = $pdo->prepare("
        SELECT a.*, a.monto, a.fecha AS fecha_emision, a.concepto, a.metodo AS metodo_pago, a.referencia AS notas,
               cf.nombre AS cliente, cf.rtn AS cliente_rtn, c.nombre_contrato, c.id AS contrato, c.receptor_id
        FROM contratos_anticipos a
        JOIN contratos c ON c.id = a.contrato_id AND c.cliente_id = a.cliente_id
        JOIN clientes_factura cf ON cf.id = c.receptor_id AND cf.cliente_id = c.cliente_id
        WHERE a.id = ? AND a.cliente_id = ?");
    $st->execute([$anticipoId, $cid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new Exception("Pago anticipado no encontrado.");
    $r['estado'] = (int)$r['anulado'] ? 'anulado' : 'emitido';
    $r['periodo_mes'] = null;
    $r['notas'] = $r['referencia'] ? 'Referencia: ' . $r['referencia'] : '';
    return $r;
}

function anticipoArchivo(array $a): string
{
    return 'recibo_anticipo_' . str_pad((string)$a['id'], 5, '0', STR_PAD_LEFT) . '.pdf';
}

function anticipoPdf(PDO $pdo, int $cid, int $anticipoId): string
{
    $r = anticipoDatos($pdo, $cid, $anticipoId);
    return reciboRender($pdo, $cid, $r + ['titulo' => 'RECIBO DE ANTICIPO', 'num' => 'A-' . str_pad((string)$r['id'], 5, '0', STR_PAD_LEFT),
        'pie' => 'Pago anticipado: no es una factura. La factura se emitirá al cierre y este pago se aplicará como abono.']);
}

/** Aviso de cobro de un pago del plan (todavía sin cobrar): lo que el cliente debe pagar, para qué fecha y adónde. No es factura ni recibo. */
function avisoCobroDatos(PDO $pdo, int $cid, int $planId): array
{
    require_once __DIR__ . '/contrato_plan.php';
    $st = $pdo->prepare("
        SELECT p.contrato_id, cf.nombre AS cliente, cf.rtn AS cliente_rtn, c.nombre_contrato, c.id AS contrato
        FROM contratos_plan p
        JOIN contratos c ON c.id = p.contrato_id AND c.cliente_id = p.cliente_id
        JOIN clientes_factura cf ON cf.id = c.receptor_id AND cf.cliente_id = c.cliente_id
        WHERE p.id = ? AND p.cliente_id = ?");
    $st->execute([$planId, $cid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new Exception("Pago del plan no encontrado.");
    $linea = null;
    foreach (planLineas($pdo, $cid, (int)$r['contrato_id']) as $l) if ((int)$l['id'] === $planId) $linea = $l;
    if (!$linea) throw new Exception("Pago del plan no encontrado.");
    if ($linea['estado'] === 'pagado') throw new Exception("Este pago ya está cobrado: no necesita aviso de cobro.");
    return $r + ['plan_id' => $planId, 'linea' => $linea, 'concepto' => $linea['concepto'], 'monto' => (float)$linea['total'],
        'fecha_emision' => date('Y-m-d'), 'periodo_mes' => null, 'metodo_pago' => '', 'notas' => '', 'estado' => 'emitido'];
}

function avisoCobroArchivo(array $a): string
{
    return 'aviso_de_cobro_' . str_pad((string)$a['plan_id'], 5, '0', STR_PAD_LEFT) . '.pdf';
}

function avisoCobroPdf(PDO $pdo, int $cid, int $planId): string
{
    $r = avisoCobroDatos($pdo, $cid, $planId);
    return reciboRender($pdo, $cid, $r + ['titulo' => 'AVISO DE COBRO', 'num' => str_pad((string)$planId, 5, '0', STR_PAD_LEFT), 'aviso' => true,
        'pie' => 'Este aviso no es una factura ni un recibo; es el detalle del pago pendiente. Si ya realizó el pago, haga caso omiso.']);
}

/** Arma el PDF de un recibo con los datos ya resueltos (titulo, num, pie, cliente, monto, concepto…). */
function reciboRender(PDO $pdo, int $cid, array $r): string
{
    $emp = $pdo->prepare("SELECT nombre, alias, rtn, direccion, telefono, email, logo_url FROM clientes_saas WHERE id = ?");
    $emp->execute([$cid]);
    $emp = $emp->fetch(PDO::FETCH_ASSOC) ?: [];
    $e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');

    // Logo incrustado (dompdf no descarga imágenes remotas)
    $logo = '';
    if (!empty($emp['logo_url']) && !preg_match('/\.svg($|\?)/i', $emp['logo_url'])) {
        $d = @file_get_contents($emp['logo_url'], false, stream_context_create(['http' => ['timeout' => 4], 'https' => ['timeout' => 4]]));
        if ($d && str_starts_with($m = (string)(new finfo(FILEINFO_MIME_TYPE))->buffer($d), 'image/')) $logo = 'data:' . $m . ';base64,' . base64_encode($d);
    }
    $local = __DIR__ . '/../clientes/css/logo-correo.png';
    if (!$logo && is_file($local)) $logo = 'data:image/png;base64,' . base64_encode(file_get_contents($local));

    $firm = firmantesParaPdf($pdo, $cid, __DIR__ . '/../clientes/naranjaymedia/includes/uploads')[firmaReciboRol($pdo, $cid, isset($r['contrato']) ? (int)$r['contrato'] : null)] ?? ['nombre' => '', 'cargo' => '', 'firma_b64' => ''];
    // Sello de la empresa (Configuración → Firmas), si se eligió ponerlo en los recibos
    $sello = '';
    try {
        $stSl = $pdo->prepare("SELECT sello, sello_recibos FROM clientes_saas WHERE id = ?");
        $stSl->execute([$cid]);
        $rSl = $stSl->fetch(PDO::FETCH_ASSOC);
        $rutaSl = $rSl && $rSl['sello'] && (int)$rSl['sello_recibos'] ? realpath(__DIR__ . '/../clientes/naranjaymedia/includes/uploads/firmas/' . $rSl['sello']) : false;
        if ($rutaSl && is_file($rutaSl)) $sello = 'data:' . ((new finfo(FILEINFO_MIME_TYPE))->file($rutaSl) ?: 'image/png') . ';base64,' . base64_encode(file_get_contents($rutaSl));
    } catch (Throwable $e) { $sello = ''; }   // sin la migración del sello
    $MES = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $fecha = (int)substr($r['fecha_emision'], 8, 2) . ' de ' . $MES[(int)substr($r['fecha_emision'], 5, 2)] . ' de ' . substr($r['fecha_emision'], 0, 4);
    $periodo = $r['periodo_mes'] ? ucfirst($MES[(int)$r['periodo_mes']]) . ' ' . (int)$r['periodo_anio'] : '';
    $metodos = ['transferencia' => 'Transferencia bancaria', 'efectivo' => 'Efectivo', 'cheque' => 'Cheque', 'tarjeta' => 'Tarjeta', 'otro' => 'Otro'];
    $num = $r['num'];
    $empresa = $emp['nombre'] ?? '';
    $fila = fn($k, $v) => $v === '' ? '' : '<tr><td class="k">' . $k . '</td><td>' . $v . '</td></tr>';
    $aviso = !empty($r['aviso']);
    $cuentasHtml = '';
    if ($aviso) {
        $stC = $pdo->prepare("SELECT * FROM configuracion_cuentas_pago WHERE cliente_id = ? AND activo = 1 ORDER BY orden, id");
        $stC->execute([$cid]);
        foreach ($stC->fetchAll(PDO::FETCH_ASSOC) as $c)
            $cuentasHtml .= '<div><strong>' . $e($c['banco']) . '</strong> · ' . (!empty($c['tipo_cuenta']) ? $e($c['tipo_cuenta']) . ' ' : '') . $e($c['numero_cuenta']) . ' · a nombre de ' . $e($c['titular']) . '</div>';
    }
    $lin = $r['linea'] ?? null;
    $fPago = $lin && !empty($lin['fecha']) ? (int)substr($lin['fecha'], 8, 2) . ' de ' . $MES[(int)substr($lin['fecha'], 5, 2)] . ' de ' . substr($lin['fecha'], 0, 4) : '';

    $html = '<!doctype html><html><head><meta charset="utf-8"><style>
        @page { margin: 36px 42px; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #1e293b; }
        .top { width: 100%; border-bottom: 3px solid #e4550d; padding-bottom: 10px; }
        .top td { vertical-align: top; }
        .emp { font-size: 10px; color: #475569; line-height: 1.5; }
        .tit { text-align: right; }
        .tit h1 { margin: 0; font-size: 22px; letter-spacing: 1px; color: #0f172a; }
        .tit .n { font-size: 13px; color: #e4550d; font-weight: bold; }
        .monto { margin: 22px 0 6px; padding: 14px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; }
        .monto .v { font-size: 20px; font-weight: bold; color: #0f172a; }
        .monto .l { font-size: 10.5px; color: #475569; margin-top: 4px; }
        table.d { width: 100%; border-collapse: collapse; margin-top: 14px; }
        table.d td { padding: 7px 8px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        table.d td.k { width: 26%; color: #64748b; }
        .firma { margin-top: 60px; width: 46%; text-align: center; position: relative; }
        .firma .sello { position: absolute; right: -55px; top: -12px; height: 104px; }
        .firma table.caja { width: 100%; border-collapse: collapse; }
        .firma table.caja td { height: 83px; vertical-align: bottom; text-align: center; padding: 0; }
        .firma table.caja img { max-height: 78px; max-width: 286px; margin-bottom: -14px; }
        .firma .linea { border-top: 1px solid #334155; padding-top: 5px; font-size: 10.5px; }
        .pie { position: fixed; bottom: -10px; left: 0; right: 0; font-size: 9px; color: #94a3b8; text-align: center; }
    </style></head><body>
    <table class="top"><tr>
        <td style="width:60%">' . ($logo ? '<img src="' . $logo . '" style="height:54px;max-width:210px"><br>' : '') . '
            <div class="emp"><strong style="color:#0f172a">' . $e($empresa) . '</strong>'
                . (!empty($emp['rtn']) ? '<br>RTN: ' . $e($emp['rtn']) : '')
                . (!empty($emp['direccion']) ? '<br>' . $e($emp['direccion']) : '')
                . (!empty($emp['telefono']) || !empty($emp['email']) ? '<br>' . $e(trim(($emp['telefono'] ?? '') . ' · ' . ($emp['email'] ?? ''), ' ·')) : '') . '</div></td>
        <td class="tit"><h1>' . $e($r['titulo']) . '</h1><div class="n">N.º ' . $num . '</div><div style="margin-top:6px">' . $e($fecha) . '</div></td>
    </tr></table>

    <div class="monto"><div>' . ($aviso ? 'Se solicita a <strong>' : 'Recibimos de <strong>') . $e($r['cliente']) . '</strong>' . ($r['cliente_rtn'] ? ' (RTN ' . $e($r['cliente_rtn']) . ')' : '') . ($aviso ? ' el pago de:' : ' la cantidad de:') . '</div>
        <div class="v">L ' . number_format((float)$r['monto'], 2) . '</div>
        <div class="l">' . $e(numeroALetras((float)$r['monto'])) . '</div></div>

    <table class="d">'
        . $fila('Concepto', $e($r['concepto']))
        . $fila('Período', $e($periodo))
        . $fila('Contrato', '#' . (int)$r['contrato'] . ' · ' . $e($r['nombre_contrato']))
        . ($aviso ? $fila('Fecha de pago', $e($fPago) . ($lin && $lin['estado'] === 'vencido' ? ' <strong style="color:#b91c1c">(vencido: ' . (int)$lin['dias'] . ' día' . ((int)$lin['dias'] === 1 ? '' : 's') . ' de atraso)</strong>' : ''))
            . ($lin && (float)$lin['isv'] > 0 ? $fila('Valor sin ISV', 'L ' . number_format((float)$lin['monto'], 2)) . $fila('ISV 15 %', 'L ' . number_format((float)$lin['isv'], 2)) : '')
            . $fila('Formas de pago', $cuentasHtml) : $fila('Forma de pago', $e($metodos[$r['metodo_pago']] ?? $r['metodo_pago'])))
        . $fila('Notas', $e($r['notas'] ?? ''))
        . ($r['estado'] === 'anulado' ? $fila('Estado', '<strong style="color:#b91c1c">ANULADO</strong>') : '') . '
    </table>

    ' . ('<div class="firma">
        ' . ($sello ? '<img class="sello" src="' . $sello . '">' : '') . '<table class="caja"><tr><td>' . (!empty($firm['firma_b64']) ? '<img src="' . $firm['firma_b64'] . '">' : '') . '</td></tr></table>
        <div class="linea">' . ($aviso ? 'Emitido por' : 'Recibido por') . ($firm['nombre'] ? '<br><strong>' . $e($firm['nombre']) . '</strong>' : '') . ($firm['cargo'] ? '<br>' . $e($firm['cargo']) : '') . '<br>' . $e($empresa) . '</div>
    </div>') . '

    <div class="pie">' . $e($r['pie']) . ($aviso ? ' Aviso N.º ' : ' Recibo N.º ') . $num . ' · ' . $e($empresa) . '</div>
    </body></html>';

    $opt = new \Dompdf\Options();
    $opt->set('defaultFont', 'DejaVu Sans');
    $opt->set('isRemoteEnabled', false);
    $dompdf = new \Dompdf\Dompdf($opt);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();
    return (string)$dompdf->output();
}
