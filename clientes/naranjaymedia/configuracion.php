<?php
// Configuración: abre la primera pestaña que el usuario puede ver (las pestañas están en includes/templates/config_tabs.php)
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/respaldos.php';
$destino = 'dashboard';
foreach (array_keys(PERMISOS_CONFIG) as $p) if (permisoPuede($pdo, $p)) { $destino = $p; break; }
if ($destino === 'dashboard' && function_exists('respaldoPuede') && respaldoPuede()) $destino = 'respaldos';
header('Location: ' . $destino);
exit;
