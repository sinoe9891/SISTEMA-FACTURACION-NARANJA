<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/facturacion.php';

header('Content-Type: application/json; charset=utf-8');

try {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		throw new Exception("Método no permitido.");
	}

	$cliente_id = cliente_actual();
	if (!$cliente_id) throw new Exception("Cliente no encontrado.");

	// Toda la lógica (validaciones, correlativo, totales, inventario) está en includes/facturacion.php
	$pdo->beginTransaction();
	$factura = crearFactura($pdo, $cliente_id, (int)USUARIO_ID, $_POST);
	$pdo->commit();

	echo json_encode([
		'success'    => true,
		'message'    => 'Factura creada correctamente.',
		'factura_id' => $factura['id'],
	]);
} catch (Exception $e) {
	if ($pdo->inTransaction()) $pdo->rollBack();
	http_response_code(400);
	echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
