<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

$usuario_id = $_SESSION['usuario_id'] ?? null;

try {
	if (!$usuario_id) {
		throw new Exception("Sesión no válida o expirada.");
	}

	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		throw new Exception("Método no permitido.");
	}

	if (!isset($_POST['id']) || !ctype_digit($_POST['id'])) {
		throw new Exception("ID inválido.");
	}

	$cliente_factura_id = (int) $_POST['id'];

	// Obtener rol y cliente_id
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

	// Validar que el cliente exista y pertenezca al cliente_id
	$stmt = $pdo->prepare("SELECT * FROM clientes_factura WHERE id = ? AND cliente_id = ?");
	$stmt->execute([$cliente_factura_id, $cliente_id]);
	if (!$stmt->fetch()) {
		throw new Exception("Cliente no encontrado o no pertenece a este cliente.");
	}

	// Verificar relaciones con facturas
	$stmt = $pdo->prepare("SELECT COUNT(*) FROM facturas WHERE receptor_id = ?");
	$stmt->execute([$cliente_factura_id]);
	if ($stmt->fetchColumn() > 0) {
		throw new Exception("No se puede eliminar este cliente porque tiene facturas asociadas.");
	}

	// Eliminar con transacción
	$pdo->beginTransaction();
	$stmt = $pdo->prepare("DELETE FROM clientes_factura WHERE id = ? AND cliente_id = ?");
	$stmt->execute([$cliente_factura_id, $cliente_id]);
	$pdo->commit();

	header('Location: clientes?deleted=1');
	exit;

} catch (Exception $e) {
	if ($pdo->inTransaction()) {
		$pdo->rollBack();
	}
	// Redireccionar con mensaje de error codificado
	$error = urlencode($e->getMessage());
	header("Location: clientes.php?error=" . $error);
	exit;
}
