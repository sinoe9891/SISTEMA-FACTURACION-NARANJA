<?php
// Configuración: abre la primera pestaña que el usuario puede ver (las pestañas están en includes/templates/config_tabs.php)
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/respaldos.php';
if (in_array(USUARIO_ROL, ['admin', 'superadmin'], true)) $destino = 'configuracion_cai';
elseif (function_exists('respaldoPuede') && respaldoPuede()) $destino = 'respaldos';
else $destino = 'dashboard';
header('Location: ' . $destino);
exit;
