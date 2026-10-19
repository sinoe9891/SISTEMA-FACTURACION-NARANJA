<?php
/**
 * gastos_operativos_exportar.php — Reporte de gastos operativos con los filtros de la lista (PDF para el cliente o el
 * archivo, Excel para trabajarlo). El rol Gastos operativos solo exporta sus clientes asignados.
 * GET: formato (pdf|xlsx), desde, hasta, cliente, tipo, estado, moneda, usuario
 */
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/gastos_operativos.php';
require_once '../../includes/cotizaciones.php';   // logo de la empresa para el PDF
require_once '../../includes/xlsx.php';

$cid = (int)cliente_actual();
if (!gastosOpDisponible($pdo) || !$cid) { http_response_code(404); exit('Módulo no instalado.'); }
$esAdmin = gastosOpEsAdmin(USUARIO_ROL);
$fechaOk = fn($v, $d) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v)) ? $v : $d;
$desde = $fechaOk($_GET['desde'] ?? null, date('Y-m-01'));
$hasta = $fechaOk($_GET['hasta'] ?? null, date('Y-m-d'));
if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];
$f = ['desde' => $desde, 'hasta' => $hasta, 'receptor' => (int)($_GET['cliente'] ?? 0), 'tipo' => (int)($_GET['tipo'] ?? 0),
    'estado' => isset(GASTO_OP_ESTADOS[$_GET['estado'] ?? '']) ? $_GET['estado'] : '', 'moneda' => in_array($_GET['moneda'] ?? '', ['HNL', 'USD'], true) ? $_GET['moneda'] : '',
    'usuario' => $esAdmin ? (int)($_GET['usuario'] ?? 0) : 0];
$lista = array_reverse(gastosOpLista($pdo, $cid, gastosOpPermitidos($pdo, (int)USUARIO_ID, USUARIO_ROL), $f));   // del más viejo al más nuevo
$validos = array_values(array_filter($lista, fn($g) => $g['estado'] !== 'rechazado'));
$tot = gastosOpTotales($lista);
$st = $pdo->prepare("SELECT * FROM clientes_saas WHERE id = ?");
$st->execute([$cid]);
$emp = $st->fetch(PDO::FETCH_ASSOC) ?: [];
$clienteNom = '';
if ($f['receptor']) { $st = $pdo->prepare("SELECT nombre FROM clientes_factura WHERE id = ? AND cliente_id = ?"); $st->execute([$f['receptor'], $cid]); $clienteNom = (string)$st->fetchColumn(); }
$archivosDe = function (int $id) use ($pdo): string {
    return implode(', ', array_map(fn($a) => $a['nombre'], gastosOpArchivos($pdo, $id)));
};
$nombreBase = 'gastos_operativos_' . ($clienteNom ? preg_replace('/[^a-z0-9]+/', '_', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $clienteNom))) . '_' : '') . $desde . '_' . $hasta;

if (($_GET['formato'] ?? 'pdf') === 'xlsx') {
    $x = new XlsxSimple('Gastos operativos');
    $x->columnas([['N.º', 7, 'entero'], ['Fecha', 12, 'fecha'], ['Cliente', 30], ['Tipo', 22], ['Descripción', 40], ['Campaña', 24], ['Inicio pauta', 12, 'fecha'], ['Fin pauta', 12, 'fecha'],
        ['Proveedor', 20], ['Factura / recibo', 16], ['Moneda', 8], ['Monto', 13, 'dinero'], ['Tasa', 9], ['En lempiras', 14, 'dinero'], ['Pago', 22], ['Tarjeta', 22],
        ['Se cobra al cliente', 10], ['Estado', 12], ['Registrado por', 20], ['Gastos N.º', 10], ['Archivos', 40], ['Notas', 30]]);
    foreach ($lista as $g)
        $x->fila([(int)$g['id'], $g['fecha'], $g['cliente'], $g['tipo'], $g['descripcion'], (string)$g['campana'], (string)$g['pauta_inicio'], (string)$g['pauta_fin'],
            (string)$g['proveedor'], (string)$g['factura_ref'], $g['moneda'], (float)$g['monto'], $g['tasa_cambio'] ? (float)$g['tasa_cambio'] : '', (float)$g['monto_lempiras'],
            GASTO_OP_METODOS[$g['metodo_pago']], (string)$g['tarjeta'], (int)$g['cobrar_cliente'] ? 'Sí' : 'No', GASTO_OP_ESTADOS[$g['estado']][0], (string)$g['registrado_por'],
            $g['gasto_id'] ? (int)$g['gasto_id'] : '', $archivosDe((int)$g['id']), (string)$g['notas']], false, $g['estado'] === 'rechazado');
    $x->fila(['', '', '', '', 'TOTAL EN DÓLARES (sin rechazados)', '', '', '', '', '', 'USD', $tot['USD']], true);
    $x->fila(['', '', '', '', 'TOTAL EN LEMPIRAS (sin rechazados)', '', '', '', '', '', 'L', $tot['HNL']], true);
    $x->fila(['', '', '', '', 'TODO EN LEMPIRAS', '', '', '', '', '', '', '', '', $tot['lempiras']], true);
    $x->descargar($nombreBase . '.xlsx');
    exit;
}

// PDF
$e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
$porTipo = [];
foreach ($validos as $g) {
    $k = $g['tipo'];
    $porTipo[$k] ??= ['USD' => 0.0, 'HNL' => 0.0, 'L' => 0.0, 'n' => 0];
    $porTipo[$k][$g['moneda'] === 'USD' ? 'USD' : 'HNL'] += (float)$g['monto'];
    $porTipo[$k]['L'] += (float)$g['monto_lempiras'];
    $porTipo[$k]['n']++;
}
uasort($porTipo, fn($a, $b) => $b['L'] <=> $a['L']);
$filas = '';
foreach ($lista as $i => $g) {
    $rech = $g['estado'] === 'rechazado';
    $filas .= '<tr class="' . ($i % 2 ? 'par' : '') . ($rech ? ' rech' : '') . '"><td>' . date('d/m/Y', strtotime($g['fecha'])) . '</td><td>' . $e($g['cliente']) . '</td><td>' . $e($g['tipo'])
        . ($g['pauta_inicio'] ? '<div class="sub">' . date('d/m', strtotime($g['pauta_inicio'])) . ' – ' . date('d/m/Y', strtotime($g['pauta_fin'])) . '</div>' : '') . '</td><td>' . $e($g['descripcion'])
        . '<div class="sub">' . $e(implode(' · ', array_filter([$g['campana'] ? 'Campaña: ' . $g['campana'] : '', $g['proveedor'], $g['factura_ref'] ? 'Fact. ' . $g['factura_ref'] : '', $g['tarjeta'] ? 'Tarjeta ' . $g['tarjeta'] : '']))) . '</div>'
        . (($a = $archivosDe((int)$g['id'])) ? '<div class="sub">📎 ' . $e($a) . '</div>' : '<div class="sub falta">Sin comprobante</div>') . '</td>'
        . '<td class="r">' . monedaFmt($g['monto'], $g['moneda']) . ($g['moneda'] === 'USD' ? '<div class="sub">× ' . rtrim(rtrim(number_format((float)$g['tasa_cambio'], 4), '0'), '.') . '</div>' : '') . '</td>'
        . '<td class="r">' . number_format((float)$g['monto_lempiras'], 2) . '</td><td>' . GASTO_OP_ESTADOS[$g['estado']][0] . '</td></tr>';
}
$resumen = '';
foreach ($porTipo as $k => $t)
    $resumen .= '<tr><td>' . $e($k) . ' <span class="sub">(' . $t['n'] . ')</span></td><td class="r">' . ($t['USD'] ? '$ ' . number_format($t['USD'], 2) : '—') . '</td><td class="r">' . ($t['HNL'] ? 'L ' . number_format($t['HNL'], 2) : '—') . '</td><td class="r"><strong>L ' . number_format($t['L'], 2) . '</strong></td></tr>';
$logo = cotizacionLogoB64($emp);
$alias = ($emp['alias'] ?? '') ?: ($emp['nombre'] ?? '');
$html = '<!doctype html><html><head><meta charset="utf-8"><style>
@page { margin: 26px 30px 40px; }
body { font-family: "DejaVu Sans", sans-serif; font-size: 8.6pt; color: #1e293b; }
.top { width: 100%; border-bottom: 3px solid #e8590c; padding-bottom: 8px; margin-bottom: 12px; }
.top td { vertical-align: middle; }
h1 { font-size: 15pt; margin: 0; color: #0f172a; }
.muted, .sub { color: #64748b; font-size: 7.6pt; }
.falta { color: #b91c1c; }
table.d { width: 100%; border-collapse: collapse; }
table.d th { background: #0f172a; color: #fff; text-align: left; padding: 5px 6px; font-size: 7.8pt; }
table.d td { padding: 5px 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
tr.par td { background: #f8fafc; }
tr.rech td { color: #94a3b8; text-decoration: line-through; }
.r { text-align: right; white-space: nowrap; }
.tot td { font-weight: bold; background: #fff7f2; border-top: 2px solid #e8590c; }
.cajas { width: 100%; margin-bottom: 12px; border-collapse: separate; border-spacing: 6px 0; }
.cajas td { background: #f1f5f9; border-radius: 6px; padding: 8px 10px; }
.cajas .v { font-size: 12pt; font-weight: bold; color: #0f172a; }
.pie { position: fixed; bottom: -24px; left: 0; right: 0; font-size: 7pt; color: #94a3b8; text-align: center; }
</style></head><body>
<div class="pie">' . $e($alias) . ' · Reporte de gastos operativos · generado el ' . date('d/m/Y H:i') . ' por ' . $e(USUARIO_NOMBRE) . '</div>
<table class="top"><tr><td>' . ($logo ? '<img src="' . $logo . '" style="height:46px">' : '<strong style="font-size:14pt;color:#e8590c">' . $e($alias) . '</strong>') . '</td>
<td style="text-align:right"><h1>Reporte de gastos operativos</h1><div class="muted">' . ($clienteNom ? 'Cliente: <strong>' . $e($clienteNom) . '</strong> · ' : '')
    . 'Del ' . date('d/m/Y', strtotime($desde)) . ' al ' . date('d/m/Y', strtotime($hasta)) . ($f['estado'] ? ' · ' . GASTO_OP_ESTADOS[$f['estado']][0] : '') . '</div></td></tr></table>
<table class="cajas"><tr>
<td><div class="muted">En dólares</div><div class="v">$ ' . number_format($tot['USD'], 2) . '</div></td>
<td><div class="muted">En lempiras</div><div class="v">L ' . number_format($tot['HNL'], 2) . '</div></td>
<td><div class="muted">Todo en lempiras</div><div class="v">L ' . number_format($tot['lempiras'], 2) . '</div></td>
<td><div class="muted">Gastos</div><div class="v">' . count($validos) . '</div></td></tr></table>'
    . ($resumen ? '<table class="d" style="margin-bottom:14px"><tr><th>Por tipo</th><th class="r">Dólares</th><th class="r">Lempiras</th><th class="r">Total en lempiras</th></tr>' . $resumen . '</table>' : '')
    . '<table class="d"><thead><tr><th style="width:52px">Fecha</th><th style="width:110px">Cliente</th><th style="width:95px">Tipo</th><th>Descripción y respaldo</th><th class="r" style="width:70px">Monto</th><th class="r" style="width:68px">En L</th><th style="width:52px">Estado</th></tr></thead><tbody>'
    . ($filas ?: '<tr><td colspan="7" style="text-align:center;padding:20px" class="muted">No hay gastos con estos filtros.</td></tr>')
    . '<tr class="tot"><td colspan="5" class="r">Total en lempiras (sin rechazados)</td><td class="r">' . number_format($tot['lempiras'], 2) . '</td><td></td></tr></tbody></table>
<p class="muted" style="margin-top:10px">Los montos en dólares se convierten a la tasa registrada en cada gasto. Los rechazados aparecen tachados y no suman.</p>
</body></html>';

require_once '../../vendor/autoload.php';
$opt = new \Dompdf\Options();
$opt->set('defaultFont', 'DejaVu Sans');
$opt->set('isRemoteEnabled', false);
$pdf = new \Dompdf\Dompdf($opt);
$pdf->loadHtml($html, 'UTF-8');
$pdf->setPaper('letter', 'landscape');
$pdf->render();
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $nombreBase . '.pdf"');
echo $pdf->output();
