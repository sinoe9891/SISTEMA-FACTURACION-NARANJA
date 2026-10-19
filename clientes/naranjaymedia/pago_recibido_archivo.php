<?php
/**
 * pago_recibido_archivo.php — Comprobante de un pago recibido (abono, recibo o anticipo), solo para la misma empresa.
 * GET: tipo (factura|recibo|anticipo), id, descargar=1 (opcional)
 */
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/pagos_recibidos.php';

$cid = (int)cliente_actual();
try {
    $p = pagoRecibidoObtener($pdo, $cid, (string)($_GET['tipo'] ?? ''), (int)($_GET['id'] ?? 0));
} catch (Throwable $e) {
    $p = null;
}
$base = realpath(__DIR__ . '/includes/uploads/gastos');
$ruta = $p && !empty($p['archivo_adjunto']) ? realpath(__DIR__ . '/includes/uploads/gastos/' . basename($p['archivo_adjunto'])) : false;
if (!$ruta || !$base || strpos($ruta, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($ruta)) {
    http_response_code(404);
    exit('Comprobante no encontrado.');
}
$nombre = preg_replace('/[^\w.\- ]+/u', '_', $p['archivo_nombre'] ?: basename($ruta));
header('Content-Type: ' . (new finfo(FILEINFO_MIME_TYPE))->file($ruta));
header('Content-Disposition: ' . (!empty($_GET['descargar']) ? 'attachment' : 'inline') . '; filename="' . $nombre . '"');
header('Content-Length: ' . filesize($ruta));
header('X-Content-Type-Options: nosniff');
readfile($ruta);
