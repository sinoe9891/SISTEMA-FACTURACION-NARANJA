<?php
/**
 * sesion_inicio.php — Inicia la sesión PHP con cookies seguras.
 * Usar en vez de session_start() en todas las páginas (login, logout, session.php…).
 */
function iniciarSesionSegura(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    ini_set('session.use_strict_mode', '1');   // no acepta IDs de sesión inventados
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,                  // solo por HTTPS cuando el sitio lo usa
        'httponly' => true,                    // JavaScript no puede leer la cookie
        'samesite' => 'Lax',                   // no se envía en POST desde otros sitios
    ]);
    session_start();
}
