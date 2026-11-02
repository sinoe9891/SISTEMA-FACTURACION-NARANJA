<?php
/**
 * cobro_archivo.php — Comprobante del cobro de una factura, solo para usuarios de la misma empresa.
 * GET: id (cobro), a (id de un comprobante concreto, si el cobro tiene varios), descargar=1 (opcional)
 */
require_once '../../includes/db.php';
require_once '../../includes/session.php';

$cid = (int)cliente_actual();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$extra = filter_input(INPUT_GET, 'a', FILTER_VALIDATE_INT);   // un comprobante concreto del cobro (si tiene varios)
if ($extra) {
    $st = $pdo->prepare("SELECT archivo AS archivo_adjunto, nombre AS archivo_nombre FROM cobro_archivos WHERE id = ? AND cobro_id = ? AND cliente_id = ?");
    $st->execute([$extra, $id ?: 0, $cid]);
} else {
    $st = $pdo->prepare("SELECT archivo_adjunto, archivo_nombre FROM cobros_factura WHERE id = ? AND cliente_id = ?");
    $st->execute([$id ?: 0, $cid]);
}
$m = $st->fetch(PDO::FETCH_ASSOC);
$base = realpath(__DIR__ . '/includes/uploads/gastos');
$ruta = $m && $m['archivo_adjunto'] ? realpath(__DIR__ . '/includes/uploads/gastos/' . basename($m['archivo_adjunto'])) : false;
if (!$ruta || !$base || strpos($ruta, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($ruta)) {
    http_response_code(404);
    exit('Comprobante no encontrado.');
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($ruta);
$nombre = preg_replace('/[^\w.\- ]+/u', '_', $m['archivo_nombre'] ?: basename($ruta));
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . (!empty($_GET['descargar']) ? 'attachment' : 'inline') . '; filename="' . $nombre . '"');
header('Content-Length: ' . filesize($ruta));
header('X-Content-Type-Options: nosniff');
readfile($ruta);
