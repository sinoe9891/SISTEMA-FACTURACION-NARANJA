<?php
/**
 * sesion_inicio.php — Inicia la sesión PHP con cookies seguras.
 * Usar en vez de session_start() en todas las páginas (login, logout, session.php…).
 */
// Segundos sin usar el sistema antes de cerrar la sesión (5 minutos) (el header avisa con una cuenta regresiva antes de que pase)
const SESION_INACTIVIDAD_SEG = 300;

/** Segundos que le quedan a la sesión según su última actividad (null = recién iniciada). */
function sesionSegundosRestantes(?int $ultimaActividad, ?int $ahora = null): int
{
    if ($ultimaActividad === null) return SESION_INACTIVIDAD_SEG;
    return max(0, SESION_INACTIVIDAD_SEG - (($ahora ?? time()) - $ultimaActividad));
}

function iniciarSesionSegura(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    ini_set('session.use_strict_mode', '1');   // no acepta IDs de sesión inventados
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string)(SESION_INACTIVIDAD_SEG + 600));   // que el servidor no la borre antes del límite de la app
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,                  // solo por HTTPS cuando el sitio lo usa
        'httponly' => true,                    // JavaScript no puede leer la cookie
        'samesite' => 'Lax',                   // no se envía en POST desde otros sitios
    ]);
    session_start();
}
