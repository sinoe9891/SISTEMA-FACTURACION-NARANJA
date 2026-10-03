<?php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';

try {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		throw new Exception("Método no permitido.");
	}

	// El cliente sale de la sesión, nunca del formulario
	$cliente_id     = (int)(USUARIO_ROL === 'superadmin'
		? ($_SESSION['cliente_seleccionado'] ?? 0)
		: CLIENTE_ID);
	$receptores_id  = (int)($_POST['receptores_id'] ?? 0);
	$nombre         = trim($_POST['nombre'] ?? '');
	$descripcion    = trim($_POST['descripcion'] ?? '');
	$precio         = floatval($_POST['precio'] ?? 0);
	$tipo_isv       = intval($_POST['tipo_isv'] ?? 0);
	$precio_fijo    = isset($_POST['precio_fijo']) ? 1 : 0;

	if (!$cliente_id || !$receptores_id || $nombre === '' || $descripcion === '' || $precio <= 0) {
		throw new Exception("Faltan campos obligatorios.");
	}

	// El receptor debe pertenecer al mismo cliente
	$stmtR = $pdo->prepare("SELECT COUNT(*) FROM clientes_factura WHERE id = ? AND cliente_id = ?");
	$stmtR->execute([$receptores_id, $cliente_id]);
	if (!$stmtR->fetchColumn()) {
		throw new Exception("Receptor no válido.");
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
