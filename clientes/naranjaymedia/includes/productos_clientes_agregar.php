<?php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';

try {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		throw new Exception("Método no permitido.");
	}

	$cliente_id     = $_POST['cliente_id']     ?? null;
	$receptores_id  = $_POST['receptores_id']  ?? null;
	$nombre         = trim($_POST['nombre'] ?? '');
	$descripcion    = trim($_POST['descripcion'] ?? '');
	$precio         = floatval($_POST['precio'] ?? 0);
	$tipo_isv       = intval($_POST['tipo_isv'] ?? 0);
	$precio_fijo    = isset($_POST['precio_fijo']) ? 1 : 0;

	if (!$cliente_id || !$receptores_id || $nombre === '' || $descripcion === '' || $precio <= 0) {
		throw new Exception("Faltan campos obligatorios.");
	}

	// Insertar en productos_clientes
	$stmt = $pdo->prepare("INSERT INTO productos_clientes 
		(cliente_id, receptores_id, nombre, descripcion, precio, tipo_isv, precio_fijo) 
		VALUES (?, ?, ?, ?, ?, ?, ?)");
	$stmt->execute([
		$cliente_id,
		$receptores_id,
		$nombre,
		$descripcion,
		$precio,
		$tipo_isv,
		$precio_fijo
	]);

	// Redirigir con éxito
	header("Location: ../productos_clientes?exito=1");
	exit;

} catch (Exception $e) {
	error_log("Error al agregar producto cliente: " . $e->getMessage());
	header("Location: ../productos_clientes?error=" . urlencode($e->getMessage()));
	exit;
}
