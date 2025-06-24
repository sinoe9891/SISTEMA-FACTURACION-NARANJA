<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

$usuario_id = $_SESSION['usuario_id'] ?? null;

try {
	if (!$usuario_id || $_SERVER['REQUEST_METHOD'] !== 'POST') {
		throw new Exception("Acceso denegado.");
	}

	// Obtener cliente_id y rol
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

	// Obtener datos del formulario
	$id = isset($_POST['id']) && ctype_digit($_POST['id']) ? intval($_POST['id']) : null;
	$nombre = trim($_POST['nombre'] ?? '');
	$rtn = trim($_POST['rtn'] ?? '');
	$direccion = trim($_POST['direccion'] ?? '');
	$telefono = trim($_POST['telefono'] ?? null);
	$email = trim($_POST['email'] ?? null);

	if (!$id || $nombre === '' || $rtn === '' || $direccion === '') {
		throw new Exception("Datos incompletos.");
	}

	// Verificar si tiene facturas asociadas
	$stmt = $pdo->prepare("SELECT COUNT(*) FROM facturas WHERE receptor_id = ?");
	$stmt->execute([$id]);
	$tiene_facturas = $stmt->fetchColumn() > 0;

	if ($tiene_facturas) {
		// Solo se puede actualizar dirección, teléfono y email
		$stmt = $pdo->prepare("
			UPDATE clientes_factura
			SET direccion = ?, telefono = ?, email = ?
			WHERE id = ? AND cliente_id = ?
		");
		$stmt->execute([$direccion, $telefono, $email, $id, $cliente_id]);
	} else {
		// Se puede actualizar todo
		$stmt = $pdo->prepare("
			UPDATE clientes_factura
			SET nombre = ?, rtn = ?, direccion = ?, telefono = ?, email = ?
			WHERE id = ? AND cliente_id = ?
		");
		$stmt->execute([$nombre, $rtn, $direccion, $telefono, $email, $id, $cliente_id]);
	}

	header("Location: clientes.php?updated=1");
	exit;

} catch (Exception $e) {
	$error = urlencode($e->getMessage());
	header("Location: clientes.php?error=" . $error);
	exit;
}
