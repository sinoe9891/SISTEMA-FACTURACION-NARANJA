<?php
/**
 * gasto_operativo_archivo.php — Comprobante o reporte de un gasto operativo (solo la misma empresa y, para el rol
 * Gastos operativos, solo de sus clientes asignados). GET: id (del archivo), descargar=1 (opcional)
 */
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/gastos_operativos.php';

$cid = (int)cliente_actual();
$a = null;
try {
    $st = $pdo->prepare("SELECT * FROM gastos_op_archivos WHERE id = ? AND cliente_id = ?");
    $st->execute([(int)($_GET['id'] ?? 0), $cid]);
    $a = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($a) gastosOpObtener($pdo, $cid, (int)$a['gasto_op_id'], gastosOpPermitidos($pdo, (int)USUARIO_ID, USUARIO_ROL));
} catch (Throwable $e) {
    $a = null;
}
$base = realpath(gastosOpDir());
$ruta = $a ? realpath(gastosOpDir() . basename($a['archivo'])) : false;
if (!$ruta || !$base || strpos($ruta, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($ruta)) {
    http_response_code(404);
    exit('Archivo no encontrado.');
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($ruta);
$enLinea = empty($_GET['descargar']) && in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true);
$nombre = preg_replace('/[^\w.\- ]+/u', '_', $a['nombre'] ?: basename($ruta));
header('Content-Type: ' . ($enLinea ? $mime : 'application/octet-stream'));
header('Content-Disposition: ' . ($enLinea ? 'inline' : 'attachment') . '; filename="' . $nombre . '"');
header('Content-Length: ' . filesize($ruta));
header('X-Content-Type-Options: nosniff');
readfile($ruta);
