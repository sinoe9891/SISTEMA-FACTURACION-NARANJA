<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

try {
	$data = json_decode(file_get_contents('php://input'), true);

	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		throw new Exception('Método no permitido.');
	}

	if (!isset($_SESSION['usuario_id'])) {
		throw new Exception('Sesión expirada.');
	}

	$facturador_id = $_SESSION['usuario_id'];
	$ip_usuario = $_SERVER['REMOTE_ADDR'];
	$factura_id = $data['factura_id'] ?? null;
	$accion = $data['accion'] ?? null;
	$motivo = trim($data['motivo'] ?? '');
	$usuario_autoriza = trim($data['usuario_autoriza'] ?? '');
	$clave_autoriza = $data['clave_autoriza'] ?? '';

	if (!$factura_id || !in_array($accion, ['eliminar', 'anular', 'restaurar']) || !$motivo || !$usuario_autoriza || !$clave_autoriza) {
		throw new Exception('Datos incompletos.');
	}

	// Validar usuario que autoriza
	$stmt = $pdo->prepare("SELECT id, rol, cliente_id, clave FROM usuarios WHERE correo = ?");
	$stmt->execute([$usuario_autoriza]);
	$autorizador = $stmt->fetch(PDO::FETCH_ASSOC);

	if (!$autorizador || !password_verify($clave_autoriza, $autorizador['clave'])) {
		throw new Exception('Credenciales inválidas.');
	}

	if (!in_array($autorizador['rol'], ['admin', 'superadmin'])) {
		throw new Exception('El usuario no tiene permisos para autorizar.');
	}

	// Obtener info de la factura
	$stmt = $pdo->prepare("SELECT id, correlativo, cai_id, estado, cliente_id FROM facturas WHERE id = ?");
	$stmt->execute([$factura_id]);
	$factura = $stmt->fetch(PDO::FETCH_ASSOC);

	if (!$factura) {
		throw new Exception('Factura no encontrada.');
	}

	// Validar si el autorizador pertenece al mismo cliente (excepto si es superadmin)
	if ($autorizador['rol'] !== 'superadmin' && $autorizador['cliente_id'] != $factura['cliente_id']) {
		throw new Exception('El usuario autorizador no pertenece al mismo cliente.');
	}

	// Verificar si es última factura solo para usuarios normales
	$es_superadmin = ($autorizador['rol'] === 'superadmin');
	$es_ultima = true; // Por defecto permitir al superadmin

	// ELIMINAR FACTURA
	if ($accion === 'eliminar') {
		if (!$es_ultima && !$es_superadmin) {
			throw new Exception('Solo puede eliminarse la última factura del CAI.');
		}

		$pdo->beginTransaction();

		// Eliminar factura e items
		$pdo->prepare("DELETE FROM factura_items WHERE factura_id = ?")->execute([$factura_id]);
		$pdo->prepare("DELETE FROM facturas WHERE id = ?")->execute([$factura_id]);

		// Obtener nuevo último correlativo (ya sin la factura eliminada)
		$stmtNuevoUltimo = $pdo->prepare("
			SELECT correlativo 
			FROM facturas 
			WHERE cai_id = ? AND cliente_id = ? 
			ORDER BY correlativo DESC 
			LIMIT 1
		");
		$stmtNuevoUltimo->execute([$factura['cai_id'], $factura['cliente_id']]);
		$nuevo_ultimo_correlativo = $stmtNuevoUltimo->fetchColumn() ?: 0;

		// Actualizar el CAI SIEMPRE, no solo si NO es superadmin
		// Actualizar el CAI SIEMPRE, no solo si NO es superadmin
		$stmtUpdateCAI = $pdo->prepare("
			UPDATE cai_rangos 
			SET correlativo_actual = correlativo_actual - 1,
				ultimo_correlativo = ?
			WHERE id = ?
		");
		$stmtUpdateCAI->execute([$nuevo_ultimo_correlativo, $factura['cai_id']]);


		// Bitácora
		$pdo->prepare("INSERT INTO bitacora_facturas (factura_id, usuario_id, autorizador_id, accion, motivo, fecha, ip)
		VALUES (?, ?, ?, ?, ?, NOW(), ?)")
			->execute([$factura_id, $facturador_id, $autorizador['id'], 'eliminada', $motivo, $ip_usuario]);

		$pdo->commit();

		echo json_encode(['success' => true, 'message' => 'Factura eliminada correctamente.']);
		exit;
	}


	// ANULAR FACTURA
	if ($accion === 'anular') {
		if ($factura['estado'] !== 'emitida') {
			throw new Exception('Solo se pueden anular facturas emitidas.');
		}

		$pdo->prepare("UPDATE facturas SET estado = 'anulada' WHERE id = ?")->execute([$factura_id]);

		$pdo->prepare("INSERT INTO bitacora_facturas (factura_id, usuario_id, autorizador_id, accion, motivo, fecha)
               VALUES (?, ?, ?, ?, ?, NOW())")
			->execute([$factura_id, $facturador_id, $autorizador['id'], 'anulada', $motivo]);

		echo json_encode(['success' => true, 'message' => 'Factura anulada correctamente.']);
		exit;
	}

	// RESTAURAR FACTURA
	if ($accion === 'restaurar') {
		if ($factura['estado'] !== 'anulada') {
			throw new Exception('Solo se pueden reactivar facturas anuladas.');
		}

		$pdo->prepare("UPDATE facturas SET estado = 'emitida' WHERE id = ?")->execute([$factura_id]);

		$pdo->prepare("INSERT INTO bitacora_facturas (factura_id, usuario_id, autorizador_id, accion, motivo, fecha)
		   VALUES (?, ?, ?, ?, ?, NOW())")
			->execute([$factura_id, $facturador_id, $autorizador['id'], 'emitida', $motivo]);

		echo json_encode(['success' => true, 'message' => 'Factura restaurada correctamente.']);
		exit;
	}

	throw new Exception('Acción no válida.');
} catch (Exception $e) {
	if ($pdo->inTransaction()) {
		$pdo->rollBack();
	}
	echo json_encode(['success' => false, 'error' => $e->getMessage()]);
	exit;
}
