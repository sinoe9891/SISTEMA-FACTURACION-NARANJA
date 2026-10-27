<?php
// socio_prestamo_archivo.php — Comprobante de un préstamo a socio o de uno de sus pagos (solo la misma empresa).
// GET: tipo (prestamo|pago), id
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/socios_prestamos.php';

$cid = (int)cliente_actual();
$tabla = ($_GET['tipo'] ?? '') === 'pago' ? 'socios_prestamo_pagos' : 'socios_prestamos';
$st = $pdo->prepare("SELECT archivo_adjunto, archivo_nombre FROM $tabla WHERE id = ? AND cliente_id = ?");
$st->execute([(int)($_GET['id'] ?? 0), $cid]);
$a = $st->fetch(PDO::FETCH_ASSOC);
$base = realpath(spDir());
$ruta = $a && $a['archivo_adjunto'] ? realpath(spDir() . basename($a['archivo_adjunto'])) : false;
if (!$ruta || !$base || strpos($ruta, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($ruta)) {
    http_response_code(404);
    exit('Comprobante no encontrado.');
}
header('Content-Type: ' . (new finfo(FILEINFO_MIME_TYPE))->file($ruta));
header('Content-Disposition: inline; filename="' . preg_replace('/[^\w.\- ]+/u', '_', $a['archivo_nombre'] ?: basename($ruta)) . '"');
header('Content-Length: ' . filesize($ruta));
header('X-Content-Type-Options: nosniff');
readfile($ruta);
