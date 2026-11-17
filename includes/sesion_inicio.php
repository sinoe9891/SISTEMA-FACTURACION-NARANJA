<?php
/**
 * sesion_inicio.php — Inicia la sesión PHP con cookies seguras.
 * Usar en vez de session_start() en todas las páginas (login, logout, session.php…).
 */
// Segundos sin usar el sistema antes de cerrar la sesión (10 minutos) (el header avisa con una cuenta regresiva antes de que pase)
const SESION_INACTIVIDAD_SEG = 600;

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

/**
 * Página a la que volver tras iniciar sesión (enlaces de correos o páginas abiertas sin sesión).
 * Solo se acepta una ruta de la misma empresa (la carpeta del inicio de sesión): nunca otro sitio.
 * $carpeta = ruta de la carpeta del login, con barra final (p. ej. /clientes/naranjaymedia/).
 */
function destinoVolverValido(?string $volver, string $carpeta): ?string
{
    $volver = (string)$volver;
    if ($volver === '' || strlen($volver) > 600 || preg_match('/[\x00-\x1f\x7f\\\\]/', $volver)) return null;
    if ($volver[0] !== '/' || str_starts_with($volver, '//')) return null;
    $ruta = parse_url($volver, PHP_URL_PATH);
    if (!is_string($ruta) || str_contains($ruta, '..') || !str_starts_with($ruta, $carpeta)) return null;
    if ($ruta === $carpeta) return null;   // el propio login
    $pagina = strtolower(basename($ruta));
    if (in_array($pagina, ['', 'index', 'index.php', 'sesion_cerrada', 'sesion_cerrada.php', 'logout', 'logout.php', 'cerrar_sesion', 'cerrar_sesion.php'], true)) return null;
    return $volver;
}

/** Carpeta del login (con barra final) a partir de la URL actual: /clientes/<empresa>/ */
function carpetaLoginActual(): string
{
    $ruta = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    return str_ends_with($ruta, '/') ? $ruta : rtrim(dirname($ruta), '/') . '/';
}
