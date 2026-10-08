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
	// Emitida desde el plan de pagos del contrato («Emitir su factura»): queda ligada a esa línea
	$avisoPlan = '';
	$planLinea = (int)($_POST['plan_linea'] ?? 0);
	if ($planLinea && !empty($_POST['contrato_id'])) {
		require_once '../../includes/contrato_plan.php';
		try {
			if (planDisponible($pdo)) planVincular($pdo, (int)$cliente_id, $planLinea, 'factura', (int)$factura['id']);
		} catch (Exception $e) {
			$avisoPlan = ' No se ligó al plan de pagos: ' . $e->getMessage();
		}
	}
	// Gastos que se le cobraban al cliente y se incluyeron en esta factura
	$cobrados = 0;
	if (!empty($_POST['gastos_cobrar'])) {
		require_once '../../includes/gastos_cobrar.php';
		$cobrados = gastosMarcarCobrados($pdo, (int)$cliente_id, (array)$_POST['gastos_cobrar'], (int)$factura['id'], (int)($_POST['receptor_id'] ?? 0));
	}
	$pdo->commit();
	if ($cobrados) $avisoPlan .= ' ' . $cobrados . ' gasto(s) del cliente quedaron como cobrados en esta factura.';

	echo json_encode([
		'success'    => true,
		'message'    => 'Factura creada correctamente.' . $avisoPlan,
		'factura_id' => $factura['id'],
	]);
} catch (Exception $e) {
	if ($pdo->inTransaction()) $pdo->rollBack();
	http_response_code(400);
	echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
