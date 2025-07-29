<?php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';

try {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		throw new Exception("Método no permitido.");
	}

	$id = $_POST['id'] ?? null;

	if (!$id) {
		throw new Exception("ID inválido.");
	}

	$stmt = $pdo->prepare("DELETE FROM productos_clientes WHERE id = ?");
	$stmt->execute([$id]);

	echo json_encode(["status" => "ok"]);
} catch (Exception $e) {
	error_log("Error al eliminar producto cliente: " . $e->getMessage());
	http_response_code(500);
	echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
