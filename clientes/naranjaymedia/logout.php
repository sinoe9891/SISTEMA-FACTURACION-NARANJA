<?php
// Cierra la sesión por completo: datos, cookie e identificador
require_once '../../includes/sesion_inicio.php';
iniciarSesionSegura();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header("Location: ./");
exit();
