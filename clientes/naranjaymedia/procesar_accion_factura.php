<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

function renderFacturaPdfToString($factura_id)
{
	global $pdo;
	$previousGet = $_GET;
	$_GET['id'] = (string) $factura_id;
	$_GET['pdf_bytes'] = 1;

	ob_start();
	try {
		include __DIR__ . '/ver_factura.php';
		return ob_get_clean();
	} catch (Throwable $e) {
		ob_end_clean();
		throw $e;
	} finally {
		$_GET = $previousGet;
	}
}

try {
	$data = json_decode(file_get_contents('php://input'), true);
	if (!is_array($data)) {
		$data = [];
	}

	$requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
	$accion = $data['accion'] ?? ($_GET['accion'] ?? null);
	$accionExport = $accion === 'exportar_pdfs' || $accion === 'exportar_pdf_single';
	if ($requestMethod !== 'POST' && !($requestMethod === 'GET' && $accionExport)) {
		throw new Exception('Método no permitido.');
	}

	if (!isset($_SESSION['usuario_id'])) {
		throw new Exception('Sesión expirada.');
	}

	$facturador_id = $_SESSION['usuario_id'];
	$ip_usuario = $_SERVER['REMOTE_ADDR'];
	$factura_id = $data['factura_id'] ?? ($_GET['factura_id'] ?? null);
	$factura_ids = $data['factura_ids'] ?? null;
	if (empty($factura_ids) && !empty($_GET['factura_ids'])) {
		$factura_ids = array_map('intval', explode(',', $_GET['factura_ids']));
	}

	if ($accion === 'exportar_pdf_single') {
		$factura_id = (int) $factura_id;
		if (!$factura_id) {
			throw new Exception('Factura no especificada.');
		}

		$stmtUser = $pdo->prepare("SELECT cliente_id, rol FROM usuarios WHERE id = ?");
		$stmtUser->execute([$facturador_id]);
		$usuario = $stmtUser->fetch(PDO::FETCH_ASSOC);
		if (!$usuario || !in_array($usuario['rol'], ['admin', 'superadmin'])) {
			throw new Exception('No tienes permisos para exportar facturas.');
		}

		$stmt = $pdo->prepare("SELECT id, correlativo, cliente_id FROM facturas WHERE id = ?");
		$stmt->execute([$factura_id]);
		$factura_row = $stmt->fetch(PDO::FETCH_ASSOC);
		if (!$factura_row) {
			throw new Exception('Factura no encontrada.');
		}
		if ($usuario['rol'] !== 'superadmin' && (int)$usuario['cliente_id'] !== (int)$factura_row['cliente_id']) {
			throw new Exception('No autorizado para esta factura.');
		}

		$filename = 'factura_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $factura_row['correlativo'] ?? $factura_id) . '.pdf';
		$rendered = renderFacturaPdfToString($factura_id);
		if ($rendered === false || $rendered === '') {
			throw new Exception('No se pudo generar el PDF de la factura.');
		}

		header('Content-Type: application/pdf');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		header('Content-Length: ' . strlen($rendered));
		echo $rendered;
		exit;
	}

	if ($accion === 'exportar_pdfs') {
		if (!is_array($factura_ids) || empty($factura_ids)) {
			throw new Exception('Debes seleccionar al menos una factura.');
		}

		$factura_ids = array_values(array_unique(array_filter(array_map('intval', $factura_ids))));
		if (empty($factura_ids)) {
			throw new Exception('Debes seleccionar al menos una factura.');
		}

		$allowed = false;
		$stmtUser = $pdo->prepare("SELECT cliente_id, rol FROM usuarios WHERE id = ?");
		$stmtUser->execute([$facturador_id]);
		$usuario = $stmtUser->fetch(PDO::FETCH_ASSOC);
		if ($usuario && in_array($usuario['rol'], ['admin', 'superadmin'])) {
			$allowed = true;
		}
		if (!$allowed) {
			throw new Exception('No tienes permisos para exportar facturas.');
		}

		$zipPath = tempnam(sys_get_temp_dir(), 'facturas_');
		if ($zipPath === false) {
			throw new Exception('No se pudo preparar la descarga.');
		}
		unlink($zipPath);
		$zipPath = sys_get_temp_dir() . '/facturas_' . uniqid() . '.zip';
		$zip = new ZipArchive();
		if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
			throw new Exception('No se pudo crear el archivo ZIP.');
		}

		foreach ($factura_ids as $factura_id_iter) {
			$stmt = $pdo->prepare("SELECT id, correlativo, cliente_id FROM facturas WHERE id = ?");
			$stmt->execute([$factura_id_iter]);
			$factura_row = $stmt->fetch(PDO::FETCH_ASSOC);
			if (!$factura_row) {
				continue;
			}
			if ($usuario['rol'] !== 'superadmin' && (int)$usuario['cliente_id'] !== (int)$factura_row['cliente_id']) {
				continue;
			}

			$filename = 'factura_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $factura_row['correlativo'] ?? $factura_id_iter) . '.pdf';
			$rendered = renderFacturaPdfToString($factura_id_iter);
			if ($rendered === false) {
				continue;
			}
			$zip->addFromString($filename, $rendered);
		}

		$zip->close();

		header('Content-Type: application/zip');
		header('Content-Disposition: attachment; filename="facturas_' . count($factura_ids) . '.zip"');
		header('Content-Length: ' . filesize($zipPath));
		readfile($zipPath);
		unlink($zipPath);
		exit;
	}
	$motivo = trim($data['motivo'] ?? '');
	$usuario_autoriza = trim($data['usuario_autoriza'] ?? '');
	$clave_autoriza = $data['clave_autoriza'] ?? '';
	$estados = $data['estados'] ?? [];

	if (!in_array($accion, ['eliminar', 'anular', 'restaurar', 'editar_estados'])) {
		throw new Exception('Acción no válida.');
	}

	if ($accion === 'editar_estados') {
		if (!is_array($factura_ids) || empty($factura_ids)) {
			throw new Exception('Debes seleccionar al menos una factura.');
		}
		$factura_ids = array_values(array_unique(array_filter(array_map('intval', $factura_ids))));
		if (empty($factura_ids) || !$motivo || !$usuario_autoriza || !$clave_autoriza) {
			throw new Exception('Datos incompletos.');
		}
	} else {
		if (!$factura_id || !$motivo || !$usuario_autoriza || !$clave_autoriza) {
			throw new Exception('Datos incompletos.');
		}
		$factura_ids = [(int)$factura_id];
	}

	if ($accion !== 'exportar_pdfs') {
		header('Content-Type: application/json; charset=utf-8');
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

	// Verificar si es última factura solo para usuarios normales
	$es_superadmin = ($autorizador['rol'] === 'superadmin');
	$es_ultima = true; // Por defecto permitir al superadmin

	$pdo->beginTransaction();

	try {
		foreach ($factura_ids as $factura_id_iter) {
			$stmt = $pdo->prepare("SELECT id, correlativo, cai_id, estado, cliente_id FROM facturas WHERE id = ?");
			$stmt->execute([$factura_id_iter]);
			$factura = $stmt->fetch(PDO::FETCH_ASSOC);

			if (!$factura) {
				throw new Exception('Factura no encontrada.');
			}

			// Validar si el autorizador pertenece al mismo cliente (excepto si es superadmin)
			if ($autorizador['rol'] !== 'superadmin' && $autorizador['cliente_id'] != $factura['cliente_id']) {
				throw new Exception('El usuario autorizador no pertenece al mismo cliente.');
			}

			// ELIMINAR FACTURA
			if ($accion === 'eliminar') {
				if (!$es_ultima && !$es_superadmin) {
					throw new Exception('Solo puede eliminarse la última factura del CAI.');
				}

				// Eliminar factura e items
				$pdo->prepare("DELETE FROM factura_items_receptor WHERE factura_id = ?")->execute([$factura_id_iter]);
				$pdo->prepare("DELETE FROM facturas WHERE id = ?")->execute([$factura_id_iter]);

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
					->execute([$factura_id_iter, $facturador_id, $autorizador['id'], 'eliminada', $motivo, $ip_usuario]);
				continue;
			}

			// ANULAR FACTURA
			if ($accion === 'anular') {
				if ($factura['estado'] !== 'emitida') {
					throw new Exception('Solo se pueden anular facturas emitidas.');
				}

				$pdo->prepare("UPDATE facturas SET estado = 'anulada' WHERE id = ?")->execute([$factura_id_iter]);

				$pdo->prepare("INSERT INTO bitacora_facturas (factura_id, usuario_id, autorizador_id, accion, motivo, fecha)
				VALUES (?, ?, ?, ?, ?, NOW())")
					->execute([$factura_id_iter, $facturador_id, $autorizador['id'], 'anulada', $motivo]);
				continue;
			}

			// RESTAURAR FACTURA
			if ($accion === 'restaurar') {
				if ($factura['estado'] !== 'anulada') {
					throw new Exception('Solo se pueden reactivar facturas anuladas.');
				}

				$pdo->prepare("UPDATE facturas SET estado = 'emitida' WHERE id = ?")->execute([$factura_id_iter]);

				$pdo->prepare("INSERT INTO bitacora_facturas (factura_id, usuario_id, autorizador_id, accion, motivo, fecha)
				VALUES (?, ?, ?, ?, ?, NOW())")
					->execute([$factura_id_iter, $facturador_id, $autorizador['id'], 'emitida', $motivo]);
				continue;
			}

			// EDITAR ESTADOS
			if ($accion === 'editar_estados') {
				$camposPermitidos = ['pagada', 'estado_declarada', 'enviada_receptor'];
				$sets = [];
				$valores = [];
				foreach ($camposPermitidos as $campo) {
					if (isset($estados[$campo]) && $estados[$campo] !== '' && $estados[$campo] !== null) {
						$sets[] = "$campo = ?";
						$valores[] = (int) $estados[$campo];
					}
				}
				if (empty($sets)) {
					throw new Exception('Debes seleccionar al menos un estado para cambiar.');
				}
				$valores[] = $factura_id_iter;

				$pdo->prepare("UPDATE facturas SET " . implode(', ', $sets) . " WHERE id = ?")
					->execute($valores);

				$pdo->prepare("INSERT INTO bitacora_facturas (factura_id, usuario_id, autorizador_id, accion, motivo, fecha)
				VALUES (?, ?, ?, ?, ?, NOW())")
					->execute([$factura_id_iter, $facturador_id, $autorizador['id'], 'estado_actualizado', $motivo]);
			}
		}

		$pdo->commit();
		echo json_encode(['success' => true, 'message' => $accion === 'editar_estados' ? 'Estados actualizados correctamente.' : ($accion === 'anular' ? 'Facturas anuladas correctamente.' : 'Operación realizada correctamente.')]);
		exit;
	} catch (Throwable $e) {
		if ($pdo->inTransaction()) {
			$pdo->rollBack();
		}
		throw $e;
	}
} catch (Throwable $e) {
	if (isset($pdo) && $pdo->inTransaction()) {
		$pdo->rollBack();
	}
	error_log('procesar_accion_factura.php: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
	if (!headers_sent()) {
		header('Content-Type: application/json; charset=utf-8');
	}
	echo json_encode(['success' => false, 'error' => $e->getMessage()]);
	exit;
}
