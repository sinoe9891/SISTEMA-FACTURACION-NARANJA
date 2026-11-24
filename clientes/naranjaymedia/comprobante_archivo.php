<?php
/**
 * comprobante_archivo.php — Comprobante de un cheque, préstamo recibido o activo fijo, solo para la misma empresa.
 * GET: tipo (cheque|prestamo|activo), id, descargar=1 (opcional)
 */
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/comprobantes.php';

$cid = (int)cliente_actual();
try {
    $r = comprobanteDisponible($pdo) ? comprobanteRegistro($pdo, $cid, (string)($_GET['tipo'] ?? ''), (int)($_GET['id'] ?? 0)) : null;
} catch (Throwable $e) {
    $r = null;
}
$base = realpath(__DIR__ . '/includes/uploads/gastos');
$ruta = $r && !empty($r['archivo_adjunto']) ? realpath(__DIR__ . '/includes/uploads/gastos/' . basename($r['archivo_adjunto'])) : false;
if (!$ruta || !$base || strpos($ruta, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($ruta)) {
    http_response_code(404);
    exit('Comprobante no encontrado.');
}
$nombre = preg_replace('/[^\w.\- ]+/u', '_', $r['archivo_nombre'] ?: basename($ruta));
header('Content-Type: ' . (new finfo(FILEINFO_MIME_TYPE))->file($ruta));
header('Content-Disposition: ' . (!empty($_GET['descargar']) ? 'attachment' : 'inline') . '; filename="' . $nombre . '"');
header('Content-Length: ' . filesize($ruta));
header('X-Content-Type-Options: nosniff');
readfile($ruta);
