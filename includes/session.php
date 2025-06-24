<?php
session_start();
require_once 'db.php';

// Verifica que haya sesión activa
if (!isset($_SESSION['usuario_id'])) {
	// Detectar cliente para redirigir al index.php correcto
	$uri = $_SERVER['REQUEST_URI'];
	$uri_parts = explode('/', trim($uri, '/'));
	$cliente_detectado = null;

	for ($i = count($uri_parts) - 1; $i >= 0; $i--) {
		if ($uri_parts[$i] === 'clientes' && isset($uri_parts[$i + 1])) {
			$cliente_detectado = $uri_parts[$i + 1];
			break;
		}
	}

	if ($cliente_detectado) {
		header("Location: /clientes/{$cliente_detectado}/");
	} else {
		header("Location: /");
	}
	exit();
}

$usuario_id = $_SESSION['usuario_id'];

// Obtener info del usuario y cliente si aplica
$stmt = $pdo->prepare("SELECT u.*, c.subdominio FROM usuarios u 
                       LEFT JOIN clientes_saas c ON u.cliente_id = c.id 
                       WHERE u.id = ?");
$stmt->execute([$usuario_id]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

// Obtener establecimientos asignados si no están ya en sesión
if (!isset($_SESSION['establecimientos'])) {
	$stmtEstab = $pdo->prepare("SELECT establecimiento_id FROM usuario_establecimientos WHERE usuario_id = ?");
	$stmtEstab->execute([$usuario_id]);
	$_SESSION['establecimientos'] = $stmtEstab->fetchAll(PDO::FETCH_COLUMN);
}

define('USUARIO_ESTABLECIMIENTOS', $_SESSION['establecimientos'] ?? []);

// Detectar subdominio (ej. ccic.naranjaymediahn.com)
$host = $_SERVER['HTTP_HOST'];
$host_parts = explode('.', $host);
if ($host_parts[0] === 'www') array_shift($host_parts);
$subdominio_detectado = (count($host_parts) > 2) ? $host_parts[0] : null;

// Detectar cliente desde URL (modo local con /clientes/CLIENTE)
$uri = $_SERVER['REQUEST_URI'];
$uri_parts = explode('/', trim($uri, '/'));
$cliente_en_url = null;

for ($i = count($uri_parts) - 1; $i >= 0; $i--) {
	if ($uri_parts[$i] === 'clientes' && isset($uri_parts[$i + 1])) {
		$cliente_en_url = $uri_parts[$i + 1];
		break;
	}
}

// Detectar cliente según entorno
$cliente_detectado = $cliente_en_url ?? $subdominio_detectado;

// Validar acceso solo si NO es superadmin
if ($usuario['rol'] !== 'superadmin' && $cliente_detectado !== $usuario['subdominio']) {
	session_destroy();
	header("Location: /clientes/{$cliente_detectado}/");
	exit("Acceso no autorizado (Cliente incorrecto)");
}

// Definir constantes
define('USUARIO_ID', $usuario['id']);
define('USUARIO_NOMBRE', $usuario['nombre']);
define('USUARIO_ROL', $usuario['rol']);

// Determinar CLIENTE_ID para superadmin
if ($usuario['rol'] === 'superadmin') {
	$current_script = basename($_SERVER['SCRIPT_NAME']);

	if (!isset($_SESSION['cliente_seleccionado'])) {
		// Evita redirigir si ya estás en seleccionar_cliente.php o en logout.php
		if (!in_array($current_script, ['seleccionar_cliente.php', 'logout.php'])) {
			header("Location: ./seleccionar_cliente.php");
			exit;
		}

		// No definimos aún CLIENTE_ID porque aún no se ha seleccionado
		define('CLIENTE_ID', null);
		define('CLIENTE_SUBDOMINIO', null);
	} else {
		define('CLIENTE_ID', $_SESSION['cliente_seleccionado']);
		define('CLIENTE_SUBDOMINIO', null);
	}
}

