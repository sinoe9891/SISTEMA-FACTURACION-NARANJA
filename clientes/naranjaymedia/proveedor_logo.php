<?php
// Logo de un proveedor (solo con sesión). GET id
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/proveedores.php';
$cid = (int)cliente_actual();
$p = proveedoresDisponible($pdo) ? proveedorObtener($pdo, $cid, (int)($_GET['id'] ?? 0)) : null;
$ruta = $p && $p['logo'] ? proveedorLogoDir($cid) . basename($p['logo']) : '';
if (!$ruta || !is_file($ruta)) { http_response_code(404); exit; }
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($ruta) ?: 'application/octet-stream';
if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/x-icon', 'image/vnd.microsoft.icon'], true)) { http_response_code(415); exit; }
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($ruta));
header('Cache-Control: private, max-age=2592000');   // el nombre cambia si cambia el logo (?v=)
header('X-Content-Type-Options: nosniff');
readfile($ruta);
