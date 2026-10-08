<?php
// socio_archivo.php — Entrega el comprobante de un aporte de socio (sesión y empresa verificadas; uploads/ está bloqueado).
require_once '../../includes/db.php';
require_once '../../includes/session.php';
$cid = (int)cliente_actual();
$st = $pdo->prepare("SELECT archivo_adjunto FROM socios_aportes WHERE id = ? AND cliente_id = ?");
$st->execute([(int)($_GET['id'] ?? 0), $cid]);
$arch = (string)$st->fetchColumn();
$base = realpath(__DIR__ . '/includes/uploads/gastos');
$ruta = $arch ? realpath(__DIR__ . '/includes/uploads/gastos/' . basename($arch)) : false;
if (!$ruta || !$base || strpos($ruta, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($ruta)) { http_response_code(404); exit('Comprobante no encontrado.'); }
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($ruta) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($ruta));
header('Content-Disposition: ' . (in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true) ? 'inline' : 'attachment') . '; filename="' . basename($ruta) . '"');
header('X-Content-Type-Options: nosniff');
readfile($ruta);
