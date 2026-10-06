<?php
// Bouchers en PDF (ver includes/bouchers.php).
//   ?gasto_id=N[&vista=1]                       → un boucher (vista=1 lo abre en el navegador)
//   ?lote=1&desde=…&hasta=…&tipo=…              → todos los del período en un solo PDF
//   ?lote=1&formato=zip&desde=…&hasta=…&tipo=…  → ZIP con un PDF por pago
//   ?ids=1,2,3[&formato=zip]                     → solo los seleccionados (PDF con todos o ZIP)
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/bouchers.php';
require_once '../../vendor/autoload.php';

if (!in_array(USUARIO_ROL, ['admin', 'superadmin', 'nomina'], true)) { http_response_code(403); exit('No autorizado.'); }
$cid = (int)cliente_actual();
@set_time_limit(300);
$ctx = boucherContexto($pdo, $cid, __DIR__ . '/includes/uploads');

if (!empty($_GET['ids'])) {
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$_GET['ids']))))), 0, BOUCHER_MAX + 1);
    $gastos = [];
    if ($ids) {
        $st = $pdo->prepare("SELECT g.*, cg.nombre AS categoria FROM gastos g LEFT JOIN categorias_gastos cg ON cg.id = g.categoria_id
                             WHERE g.cliente_id = ? AND g.estado = 'pagado' AND g.id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY g.fecha, g.id");
        $st->execute([$cid, ...$ids]);
        $gastos = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    $nombre = 'bouchers_seleccionados_' . date('Y-m-d');
} elseif (!empty($_GET['lote'])) {
    $f = boucherFiltros($_GET);
    $gastos = array_reverse(boucherGastos($pdo, $cid, $f));   // en el PDF/ZIP, del más antiguo al más reciente
    $nombre = 'bouchers_' . $f['desde'] . '_al_' . $f['hasta'];
} else {
    $st = $pdo->prepare("SELECT g.*, cg.nombre AS categoria FROM gastos g LEFT JOIN categorias_gastos cg ON cg.id = g.categoria_id WHERE g.id = ? AND g.cliente_id = ? AND g.estado <> 'anulado'");
    $st->execute([(int)($_GET['gasto_id'] ?? 0), $cid]);
    $gastos = array_filter([$st->fetch(PDO::FETCH_ASSOC)]);
    $nombre = null;
}
// El rol Nómina solo imprime pagos a colaboradores
if (USUARIO_ROL === 'nomina') $gastos = array_values(array_filter($gastos, fn($g) => esGastoNomina((string)$g['descripcion'])));
if (!$gastos) { http_response_code(404); exit('No hay pagos para generar bouchers.'); }
if (count($gastos) > BOUCHER_MAX) { http_response_code(400); exit('Son ' . count($gastos) . ' pagos: el máximo por descarga es ' . BOUCHER_MAX . '. Reduce el rango de fechas.'); }

$datos = array_map(fn($g) => boucherDatos($ctx, $g), $gastos);

if (($_GET['formato'] ?? '') === 'zip') {
    $tmp = tempnam(sys_get_temp_dir(), 'bzip');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) exit('No se pudo crear el ZIP.');
    foreach ($datos as $d) $zip->addFromString(boucherArchivo($d), boucherPdf([boucherPagina($ctx, $d)]));
    $zip->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $nombre . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

$pdf = boucherPdf(array_map(fn($d) => boucherPagina($ctx, $d), $datos));
header('Content-Type: application/pdf');
header('Content-Disposition: ' . (empty($_GET['vista']) ? 'attachment' : 'inline') . '; filename="' . ($nombre ? $nombre . '.pdf' : boucherArchivo($datos[0])) . '"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
