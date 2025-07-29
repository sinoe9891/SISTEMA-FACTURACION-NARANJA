<?php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';

try {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		throw new Exception("Método no permitido.");
	}

	$id             = $_POST['id'] ?? null;
	$cliente_id     = $_POST['cliente_id'] ?? null;
	$nombre         = trim($_POST['nombre'] ?? '');
	$descripcion    = trim($_POST['descripcion'] ?? '');
	$precio         = floatval($_POST['precio'] ?? 0);
	$tipo_isv       = intval($_POST['tipo_isv'] ?? 0);
	$precio_fijo    = isset($_POST['precio_fijo']) ? 1 : 0;

	if (!$id || !$cliente_id || $nombre === '' || $descripcion === '' || $precio <= 0) {
		throw new Exception("Faltan campos obligatorios.");
	}

	$stmt = $pdo->prepare("UPDATE productos_clientes 
		SET nombre = ?, descripcion = ?, precio = ?, tipo_isv = ?, precio_fijo = ?
		WHERE id = ? AND cliente_id = ?");
	$stmt->execute([
		$nombre,
		$descripcion,
		$precio,
		$tipo_isv,
		$precio_fijo,
		$id,
		$cliente_id
	]);

	header("Location: ../productos_clientes?actualizado=1");
	exit;

} catch (Exception $e) {
	error_log("Error al editar producto cliente: " . $e->getMessage());
	header("Location: ../productos_clientes?error=" . urlencode($e->getMessage()));
	exit;
}
