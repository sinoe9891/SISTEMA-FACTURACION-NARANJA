<?php
// Exporta los pagos de nómina (con los mismos filtros de la página) a XLSX o PDF.
//   ?formato=xlsx|pdf&desde=…&hasta=…&colaborador=…&tipo=…&quincena=…&comprobante=…&aviso=…
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/nomina_pagos.php';
require_once '../../includes/xlsx.php';

if (!in_array(USUARIO_ROL, ['admin', 'superadmin'], true)) {
    http_response_code(403);
    exit('No autorizado.');
}
$cid = cliente_actual();
$f = nominaFiltros($_GET);
$pagos = nominaPagos($pdo, $cid, $f);
$res = nominaResumen($pagos);
usort($pagos, fn($a, $b) => strcmp($a['fecha'], $b['fecha']) ?: strcmp($a['colaborador'], $b['colaborador']));
$emp = $pdo->prepare("SELECT nombre, alias, rtn FROM clientes_saas WHERE id = ?");
$emp->execute([$cid]);
$emp = $emp->fetch(PDO::FETCH_ASSOC) ?: [];
$rango = date('d/m/Y', strtotime($f['desde'])) . ' al ' . date('d/m/Y', strtotime($f['hasta']));
$archivo = 'pagos_nomina_' . $f['desde'] . '_' . $f['hasta'];

if (($_GET['formato'] ?? '') === 'xlsx') {
    $x = new XlsxSimple('Pagos de nómina');
    $x->columnas([
        ['Fecha', 12, 'fecha'], ['Colaborador', 32], ['Concepto', 12], ['Descripción', 46], ['Período', 26],
        ['Método', 14], ['Referencia', 16], ['Monto', 14, 'dinero'], ['Comprobante', 12], ['Aviso enviado', 18],
    ]);
    foreach ($pagos as $p) {
        $x->fila([$p['fecha'], $p['colaborador'], $p['tipo_txt'], $p['descripcion'], $p['periodo'], ucfirst((string)$p['metodo_pago']),
            $p['referencia'], (float)$p['monto'], $p['archivo_adjunto'] ? 'Sí' : 'No', $p['aviso_enviado'] ? date('d/m/Y H:i', strtotime($p['aviso_enviado'])) : '']);
    }
    $x->fila([]);
    $x->fila(['TOTAL', $res['n'] . ' pago(s)', null, 'Del ' . $rango, null, null, null, round($res['total'], 2)], true);
    $x->fila([]);
    $x->fila(['RESUMEN POR COLABORADOR'], true);
    foreach ($res['por_colaborador'] as $c) $x->fila([null, $c['nombre'], $c['n'] . ' pago(s)', 'Sueldos L ' . number_format($c['sueldos'], 2) . ($c['otros'] > 0 ? ' · otros L ' . number_format($c['otros'], 2) : ''), null, null, null, round($c['total'], 2)]);
    $x->fila([]);
    $x->fila(['RESUMEN POR MES'], true);
    foreach ($res['por_mes'] as $ym => $m) $x->fila([null, nominaMesTxt($ym), $m['n'] . ' pago(s)', null, null, null, null, round($m['total'], 2)]);
    $x->descargar($archivo . '.xlsx');
    exit;
}

// PDF
$e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
$L = fn($v) => 'L ' . number_format((float)$v, 2);
$filtros = [];
if ($f['colaborador']) $filtros[] = 'Colaborador: ' . ($pagos[0]['colaborador'] ?? '—');
if ($f['tipo']) $filtros[] = 'Tipo: ' . NOMINA_TIPOS[$f['tipo']];
if ($f['quincena']) $filtros[] = 'Quincena: ' . ($f['quincena'] === 'mensual' ? 'mensual' : $f['quincena'] . 'ª');
if ($f['comprobante']) $filtros[] = ($f['comprobante'] === 'con' ? 'Con' : 'Sin') . ' comprobante';
if ($f['aviso']) $filtros[] = 'Aviso ' . ($f['aviso'] === 'si' ? 'enviado' : 'sin enviar');
ob_start(); ?>
<!doctype html><html><head><meta charset="utf-8"><style>
    @page { margin: 1.3cm 1.2cm; }
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9.5px; color: #1e293b; }
    h1 { font-size: 16px; margin: 0 0 2px; } h2 { font-size: 12px; margin: 16px 0 6px; color: #334155; }
    .sub { color: #64748b; margin-bottom: 10px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #f1f5f9; text-align: left; font-size: 8.5px; text-transform: uppercase; color: #475569; padding: 5px; border-bottom: 1px solid #cbd5e1; }
    td { padding: 4px 5px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; } .tot td { font-weight: bold; background: #f8fafc; border-top: 1px solid #94a3b8; }
    .kpis td { border: 1px solid #e2e8f0; padding: 7px; width: 25%; } .kpis b { font-size: 13px; display: block; }
    .pie { margin-top: 14px; color: #94a3b8; font-size: 8px; }
</style></head><body>
<h1>Pagos de nómina · <?= $e($emp['alias'] ?: ($emp['nombre'] ?? '')) ?></h1>
<div class="sub">Del <?= $e($rango) ?><?= $filtros ? ' · ' . $e(implode(' · ', $filtros)) : '' ?> · Generado el <?= date('d/m/Y g:i a') ?></div>
<table class="kpis"><tr>
    <td>Total pagado<b><?= $L($res['total']) ?></b></td>
    <td>Pagos<b><?= $res['n'] ?></b></td>
    <td>Colaboradores<b><?= count($res['por_colaborador']) ?></b></td>
    <td>Con comprobante<b><?= $res['comprobantes'] ?>/<?= $res['n'] ?></b></td>
</tr></table>

<h2>Resumen por colaborador</h2>
<table><tr><th>Colaborador</th><th class="num">Pagos</th><th class="num">Sueldos</th><th class="num">Bonos y otros</th><th class="num">Total</th></tr>
<?php foreach ($res['por_colaborador'] as $c): ?><tr><td><?= $e($c['nombre']) ?></td><td class="num"><?= $c['n'] ?></td><td class="num"><?= $L($c['sueldos']) ?></td><td class="num"><?= $c['otros'] > 0 ? $L($c['otros']) : '—' ?></td><td class="num"><?= $L($c['total']) ?></td></tr><?php endforeach; ?>
<tr class="tot"><td>Total</td><td class="num"><?= $res['n'] ?></td><td></td><td></td><td class="num"><?= $L($res['total']) ?></td></tr></table>

<h2>Resumen por mes</h2>
<table><tr><th>Mes</th><th class="num">Pagos</th><th class="num">Total</th></tr>
<?php foreach ($res['por_mes'] as $ym => $m): ?><tr><td><?= $e(nominaMesTxt($ym)) ?></td><td class="num"><?= $m['n'] ?></td><td class="num"><?= $L($m['total']) ?></td></tr><?php endforeach; ?>
</table>

<h2>Detalle de pagos</h2>
<table><tr><th>#</th><th>Fecha</th><th>Colaborador</th><th>Concepto</th><th>Período</th><th>Método / ref.</th><th class="num">Monto</th><th>Comp.</th></tr>
<?php $n = 0; foreach ($pagos as $p): ?><tr>
    <td><?= ++$n ?></td><td><?= date('d/m/Y', strtotime($p['fecha'])) ?></td><td><?= $e($p['colaborador']) ?></td>
    <td><?= $e($p['tipo_txt']) ?></td><td><?= $e($p['periodo']) ?></td>
    <td><?= $e(ucfirst((string)$p['metodo_pago'])) ?><?= $p['referencia'] ? ' · ' . $e($p['referencia']) : '' ?></td>
    <td class="num"><?= $L($p['monto']) ?></td><td><?= $p['archivo_adjunto'] ? 'Sí' : '—' ?></td></tr>
<?php endforeach; ?>
<tr class="tot"><td colspan="6">Total</td><td class="num"><?= $L($res['total']) ?></td><td></td></tr></table>
<div class="pie">Reporte interno generado por el sistema de facturación. Montos en lempiras tal como fueron registrados.</div>
</body></html>
<?php
$html = ob_get_clean();
require_once '../../vendor/autoload.php';
$opt = new \Dompdf\Options();
$opt->set('defaultFont', 'DejaVu Sans');
$dompdf = new \Dompdf\Dompdf($opt);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('letter', 'landscape');
$dompdf->render();
$dompdf->stream($archivo . '.pdf', ['Attachment' => false]);
