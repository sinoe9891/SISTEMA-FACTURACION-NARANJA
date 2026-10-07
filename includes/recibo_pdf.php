<?php
/**
 * recibo_pdf.php — PDF del recibo de cobro de un contrato «sin factura» (dompdf).
 *   reciboPdf($pdo, $cid, $reciboId)     → bytes del PDF (para descargar o adjuntar al correo)
 *   reciboArchivo($recibo)               → nombre del archivo: recibo_00012.pdf
 * Firma: «Recibido por» = firmante «Autorizado por» de Configuración → Firmas de documentos.
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

    $firm = firmantesParaPdf($pdo, $cid, __DIR__ . '/../clientes/naranjaymedia/includes/uploads')['autorizado'] ?? ['nombre' => '', 'cargo' => '', 'firma_b64' => ''];
    $MES = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $fecha = (int)substr($r['fecha_emision'], 8, 2) . ' de ' . $MES[(int)substr($r['fecha_emision'], 5, 2)] . ' de ' . substr($r['fecha_emision'], 0, 4);
    $periodo = $r['periodo_mes'] ? ucfirst($MES[(int)$r['periodo_mes']]) . ' ' . (int)$r['periodo_anio'] : '';
    $metodos = ['transferencia' => 'Transferencia bancaria', 'efectivo' => 'Efectivo', 'cheque' => 'Cheque', 'tarjeta' => 'Tarjeta', 'otro' => 'Otro'];
    $num = str_pad((string)$r['numero_recibo'], 5, '0', STR_PAD_LEFT);
    $empresa = $emp['nombre'] ?? '';
    $fila = fn($k, $v) => $v === '' ? '' : '<tr><td class="k">' . $k . '</td><td>' . $v . '</td></tr>';

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
        .firma { margin-top: 60px; width: 46%; text-align: center; }
        .firma .caja { height: 64px; }
        .firma .caja img { max-height: 60px; max-width: 220px; }
        .firma .linea { border-top: 1px solid #334155; padding-top: 5px; font-size: 10.5px; }
        .pie { position: fixed; bottom: -10px; left: 0; right: 0; font-size: 9px; color: #94a3b8; text-align: center; }
    </style></head><body>
    <table class="top"><tr>
        <td style="width:60%">' . ($logo ? '<img src="' . $logo . '" style="height:54px;max-width:210px"><br>' : '') . '
            <div class="emp"><strong style="color:#0f172a">' . $e($empresa) . '</strong>'
                . (!empty($emp['rtn']) ? '<br>RTN: ' . $e($emp['rtn']) : '')
                . (!empty($emp['direccion']) ? '<br>' . $e($emp['direccion']) : '')
                . (!empty($emp['telefono']) || !empty($emp['email']) ? '<br>' . $e(trim(($emp['telefono'] ?? '') . ' · ' . ($emp['email'] ?? ''), ' ·')) : '') . '</div></td>
        <td class="tit"><h1>RECIBO</h1><div class="n">N.º ' . $num . '</div><div style="margin-top:6px">' . $e($fecha) . '</div></td>
    </tr></table>

    <div class="monto"><div>Recibimos de <strong>' . $e($r['cliente']) . '</strong>' . ($r['cliente_rtn'] ? ' (RTN ' . $e($r['cliente_rtn']) . ')' : '') . ' la cantidad de:</div>
        <div class="v">L ' . number_format((float)$r['monto'], 2) . '</div>
        <div class="l">' . $e(numeroALetras((float)$r['monto'])) . '</div></div>

    <table class="d">'
        . $fila('Concepto', $e($r['concepto']))
        . $fila('Período', $e($periodo))
        . $fila('Contrato', '#' . (int)$r['contrato'] . ' · ' . $e($r['nombre_contrato']))
        . $fila('Forma de pago', $e($metodos[$r['metodo_pago']] ?? $r['metodo_pago']))
        . $fila('Notas', $e($r['notas'] ?? ''))
        . ($r['estado'] === 'anulado' ? $fila('Estado', '<strong style="color:#b91c1c">ANULADO</strong>') : '') . '
    </table>

    <div class="firma">
        <div class="caja">' . (!empty($firm['firma_b64']) ? '<img src="' . $firm['firma_b64'] . '">' : '') . '</div>
        <div class="linea">Recibido por' . ($firm['nombre'] ? '<br><strong>' . $e($firm['nombre']) . '</strong>' : '') . ($firm['cargo'] ? '<br>' . $e($firm['cargo']) : '') . '<br>' . $e($empresa) . '</div>
    </div>

    <div class="pie">Este recibo no es una factura y no incluye ISV. Recibo N.º ' . $num . ' · ' . $e($empresa) . '</div>
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
