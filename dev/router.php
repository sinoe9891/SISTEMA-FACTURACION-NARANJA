<?php
/**
 * dev/router.php — Router para el servidor de pruebas de PHP (solo desarrollo).
 * Imita las reglas de .htaccess del sistema: URLs sin ".php" y uploads bloqueados.
 *
 * Uso (desde la raíz de htdocs de XAMPP), con la BD local de pruebas:
 *   APP_DB=dev php -S localhost:8383 -t /Applications/XAMPP/xamppfiles/htdocs \
 *       /Applications/XAMPP/xamppfiles/htdocs/proyectos/NARANJA/sistemafacturacion/dev/router.php
 *
 * Ver docs/DESARROLLO.md
 */
$docroot = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
$path    = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');

// Nunca servir rutas con "..".
if (strpos($path, '..') !== false) {
    http_response_code(400);
    exit('Ruta inválida.');
}

// includes/uploads/ está bloqueado (igual que su .htaccess): los comprobantes se sirven por gasto_archivo.php
if (preg_match('#/includes/uploads/#', $path)) {
    http_response_code(403);
    exit('Forbidden');
}

// Respaldos y configuración nunca se sirven (igual que clientes/.htaccess)
if (preg_match('/\.(sql|sqlite|bak|env|ini|log|sh)$/i', $path)) {
    http_response_code(403);
    exit('Forbidden');
}

// Multiempresa (clientes/.htaccess): /clientes/<empresa>/… usa la app común (naranjaymedia)
if (preg_match('#^(.*/clientes/)([a-z0-9][a-z0-9-]{1,39})(/.*)?$#', $path, $m)
    && !in_array($m[2], ['naranjaymedia', 'css', 'js'], true) && !is_dir($docroot . $m[1] . $m[2])) {
    if (!isset($m[3])) {
        header('Location: ' . $path . '/', true, 301);
        exit;
    }
    $path = $m[1] . 'naranjaymedia' . $m[3];
}

$file = $docroot . $path;

// Carpeta: buscar index.php
if (is_dir($file)) {
    if (substr($path, -1) !== '/') {
        header('Location: ' . $path . '/');
        exit;
    }
    $file .= 'index.php';
}

// /pagina → /pagina.php (como el RewriteRule de .htaccess)
if (!is_file($file) && is_file($file . '.php')) {
    $file .= '.php';
}

if (!is_file($file)) {
    http_response_code(404);
    exit('No encontrado: ' . htmlspecialchars($path));
}

// Archivos estáticos: que los sirva el servidor integrado
if (pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
    if ($file === $docroot . $path) return false;
    // Estático alcanzado por reescritura (raro): servirlo directo
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    readfile($file);
    exit;
}

// Ejecutar el PHP como si Apache lo hubiera resuelto (rutas relativas, SCRIPT_NAME)
$_SERVER['SCRIPT_FILENAME'] = $file;
$_SERVER['SCRIPT_NAME']     = substr($file, strlen($docroot));
$_SERVER['PHP_SELF']        = $_SERVER['SCRIPT_NAME'];
chdir(dirname($file));
require $file;
