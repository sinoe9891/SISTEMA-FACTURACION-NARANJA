<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

$usuario_id = $_SESSION['usuario_id'] ?? null;

try {
	if (!$usuario_id) {
		throw new Exception("Sesión no válida.");
	}

	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		throw new Exception("Método no permitido.");
	}

	$stmt = $pdo->prepare("
		SELECT u.rol, c.id AS cliente_id
		FROM usuarios u
		INNER JOIN clientes_saas c ON u.cliente_id = c.id
		WHERE u.id = ?
	");
	$stmt->execute([$usuario_id]);
	$datos = $stmt->fetch();

	if (!$datos || !in_array($datos['rol'], ['admin', 'superadmin'])) {
		throw new Exception("Acceso denegado.");
	}

	$cliente_id = $datos['cliente_id'];

	// Recoger y limpiar datos del formulario
	$nombre     = trim($_POST['nombre'] ?? '');
	$rtn        = trim($_POST['rtn'] ?? '');
	$direccion  = trim($_POST['direccion'] ?? '');
	$telefono   = trim($_POST['telefono'] ?? null);
	$email      = trim($_POST['email'] ?? null);

	if ($nombre === '' || $rtn === '' || $direccion === '') {
		throw new Exception("Nombre, RTN y Dirección son obligatorios.");
	}

	// Verificar si ya existe un RTN para este cliente
	$stmt = $pdo->prepare("SELECT COUNT(*) FROM clientes_factura WHERE rtn = ? AND cliente_id = ?");
	$stmt->execute([$rtn, $cliente_id]);
	if ($stmt->fetchColumn() > 0) {
		throw new Exception("Este RTN ya está registrado para este cliente.");
	}

	// Insertar cliente nuevo
	$stmt = $pdo->prepare("
		INSERT INTO clientes_factura (cliente_id, nombre, rtn, direccion, telefono, email)
		VALUES (?, ?, ?, ?, ?, ?)
	");
	$stmt->execute([$cliente_id, $nombre, $rtn, $direccion, $telefono, $email]);

	header("Location: clientes.php?created=1");
	exit;

} catch (Exception $e) {
	$error = urlencode($e->getMessage());
	header("Location: clientes.php?error=$error");
	exit;
}
