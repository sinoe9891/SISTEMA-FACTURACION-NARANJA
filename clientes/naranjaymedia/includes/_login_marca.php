<?php
if (!isset($pdo)) { http_response_code(404); exit; }
// Empresa de la URL (/clientes/<carpeta>/) y su marca, para las pantallas sin sesión (recuperar/restablecer contraseña).
$__seg = array_values(array_filter(explode('/', trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/'))));
$__pos = array_search('clientes', array_reverse($__seg, true), true);
$loginSubcarpeta = $__pos !== false && isset($__seg[$__pos + 1]) ? strtolower($__seg[$__pos + 1]) : basename(dirname(__DIR__));
$__st = $pdo->prepare("SELECT id, nombre, logo_url, favicon_url FROM clientes_saas WHERE subdominio = ? LIMIT 1");
$__st->execute([$loginSubcarpeta]);
$loginEmpresa = $__st->fetch(PDO::FETCH_ASSOC) ?: ['id' => 0, 'nombre' => 'Sistema de Facturación', 'logo_url' => null, 'favicon_url' => null];
$loginLogo = $loginEmpresa['logo_url'] ?: 'https://www.naranjaymediahn.com/logo.png';
$__https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$loginBase = ($__https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/';
if (empty($_SESSION['csrf_publico'])) $_SESSION['csrf_publico'] = bin2hex(random_bytes(16));
