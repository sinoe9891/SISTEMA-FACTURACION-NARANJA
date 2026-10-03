<?php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';

try {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		throw new Exception("Método no permitido.");
	}

	$cliente_id = (int)(USUARIO_ROL === 'superadmin'
		? ($_SESSION['cliente_seleccionado'] ?? 0)
		: CLIENTE_ID);
	$id = (int)($_POST['id'] ?? 0);

	if (!$id || !$cliente_id) {
		throw new Exception("ID inválido.");
	}

	$stmt = $pdo->prepare("DELETE FROM productos_clientes WHERE id = ? AND cliente_id = ?");
	$stmt->execute([$id, $cliente_id]);

	if ($stmt->rowCount() === 0) {
		throw new Exception("Producto no encontrado.");
	}

	echo json_encode(["status" => "ok"]);
} catch (Exception $e) {
	error_log("Error al eliminar producto cliente: " . $e->getMessage());
	http_response_code(500);
	echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
