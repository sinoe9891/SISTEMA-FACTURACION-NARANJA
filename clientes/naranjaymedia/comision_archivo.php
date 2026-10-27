<?php
// comision_archivo.php — Uno de los comprobantes del pago de una comisión (solo la misma empresa). GET: archivo
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/comisiones.php';
require_once __DIR__ . '/includes/_gasto_adjuntos.php';

$st = $pdo->prepare("SELECT archivo, nombre FROM comision_archivos WHERE id = ? AND cliente_id = ?");
$st->execute([(int)($_GET['archivo'] ?? 0), (int)cliente_actual()]);
$a = $st->fetch(PDO::FETCH_ASSOC);
$base = realpath(gastoAdjuntoDir());
$ruta = $a ? realpath(gastoAdjuntoDir() . basename($a['archivo'])) : false;
if (!$ruta || !$base || strpos($ruta, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($ruta)) {
    http_response_code(404);
    exit('Comprobante no encontrado.');
}
header('Content-Type: ' . (new finfo(FILEINFO_MIME_TYPE))->file($ruta));
header('Content-Disposition: inline; filename="' . preg_replace('/[^\w.\- ]+/u', '_', $a['nombre'] ?: basename($ruta)) . '"');
header('Content-Length: ' . filesize($ruta));
header('X-Content-Type-Options: nosniff');
readfile($ruta);
