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

	if ($accion === 'generar_mensaje') {
		header('Content-Type: application/json; charset=utf-8');

		$tipo = $data['tipo'] ?? '';
		if (!in_array($tipo, ['envio_factura', 'saldo_pendiente'])) {
			throw new Exception('Tipo de mensaje inválido.');
		}
		if (!is_array($factura_ids) || empty($factura_ids)) {
			throw new Exception('Debes seleccionar al menos una factura.');
		}
		$factura_ids = array_values(array_unique(array_filter(array_map('intval', $factura_ids))));
		if (empty($factura_ids)) {
			throw new Exception('Debes seleccionar al menos una factura.');
		}

		$stmtUser = $pdo->prepare("SELECT cliente_id, rol FROM usuarios WHERE id = ?");
		$stmtUser->execute([$facturador_id]);
		$usuario = $stmtUser->fetch(PDO::FETCH_ASSOC);
		if (!$usuario || !in_array($usuario['rol'], ['admin', 'superadmin'])) {
			throw new Exception('No tienes permisos para esta acción.');
		}

		$placeholders = implode(',', array_fill(0, count($factura_ids), '?'));
		$stmtFacturas = $pdo->prepare("
			SELECT f.id, f.correlativo, f.total, f.receptor_id, f.cliente_id,
			       cf.nombre AS receptor_nombre, cf.contacto_nombre
			FROM facturas f
			INNER JOIN clientes_factura cf ON f.receptor_id = cf.id
			WHERE f.id IN ($placeholders)
		");
		$stmtFacturas->execute($factura_ids);
		$facturasSel = $stmtFacturas->fetchAll(PDO::FETCH_ASSOC);

		if (empty($facturasSel)) {
			throw new Exception('No se encontraron las facturas seleccionadas.');
		}

		$cliente_id_factura = (int) $facturasSel[0]['cliente_id'];
		foreach ($facturasSel as $f) {
			if ($usuario['rol'] !== 'superadmin' && (int)$usuario['cliente_id'] !== (int)$f['cliente_id']) {
				throw new Exception('No tienes permisos sobre alguna de las facturas seleccionadas.');
			}
		}

		$receptorIds = array_unique(array_column($facturasSel, 'receptor_id'));
		if (count($receptorIds) > 1) {
			throw new Exception('Selecciona facturas de un solo cliente para redactar el mensaje.');
		}

		$receptor_nombre = $facturasSel[0]['receptor_nombre'];
		$contacto_nombre = trim($facturasSel[0]['contacto_nombre'] ?? '');
		$saludo = $contacto_nombre !== ''
			? 'Buen día, ' . $contacto_nombre . ':'
			: 'Estimado equipo de ' . $receptor_nombre . ':';

		$total = array_sum(array_map(fn($f) => (float)$f['total'], $facturasSel));

		$mesesEs = [
			1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
			7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
		];
		$mes_actual = $mesesEs[(int) date('n')];
		$anio_actual = date('Y');

		// h() = escapa para HTML; b() = escapa y envuelve en <strong> para la versión enriquecida
		$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
		$b = fn($v) => '<strong>' . htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') . '</strong>';

		if ($tipo === 'envio_factura') {
			$conceptos = [];
			foreach ($facturasSel as $f) {
				$stmtItems = $pdo->prepare("
					SELECT fi.descripcion_html, p.nombre AS nombre_producto
					FROM factura_items_receptor fi
					LEFT JOIN productos_clientes p ON fi.producto_id = p.id
					WHERE fi.factura_id = ?
				");
				$stmtItems->execute([$f['id']]);
				foreach ($stmtItems->fetchAll(PDO::FETCH_ASSOC) as $item) {
					$linea = $item['nombre_producto'] ?: 'Servicio';
					if (!empty($item['descripcion_html'])) {
						$linea .= ' - ' . strip_tags($item['descripcion_html']);
					}
					$conceptos[] = $linea;
				}
			}
			$conceptos = array_values(array_unique($conceptos));

			if (count($facturasSel) === 1) {
				$intro = 'la factura correspondiente al N.° ' . $facturasSel[0]['correlativo'] . ', que incluye los siguientes conceptos:';
				$introHtml = 'la factura correspondiente al N.° ' . $b($facturasSel[0]['correlativo']) . ', que incluye los siguientes conceptos:';
			} else {
				$numeros = implode(', ', array_map(fn($f) => 'N.° ' . $f['correlativo'], $facturasSel));
				$numerosHtml = implode(', ', array_map(fn($f) => 'N.° ' . $b($f['correlativo']), $facturasSel));
				$intro = 'las ' . count($facturasSel) . ' facturas correspondientes (' . $numeros . '), que incluyen los siguientes conceptos:';
				$introHtml = 'las ' . count($facturasSel) . ' facturas correspondientes (' . $numerosHtml . '), que incluyen los siguientes conceptos:';
			}
			$listaConceptos = implode("\n", array_map(fn($c) => '- ' . $c, $conceptos));
			$listaConceptosHtml = implode("\n", array_map(fn($c) => '- ' . $h($c), $conceptos));
			$detalle_facturas = $intro . "\n\n" . $listaConceptos;
			$detalle_facturas_html = $introHtml . "\n\n" . $listaConceptosHtml;
		} else {
			$lineas = array_map(
				fn($f) => 'Factura N.° ' . $f['correlativo'] . ': L ' . number_format((float)$f['total'], 2),
				$facturasSel
			);
			$lineasHtml = array_map(
				fn($f) => 'Factura N.° ' . $b($f['correlativo']) . ': ' . $b('L ' . number_format((float)$f['total'], 2)),
				$facturasSel
			);
			$detalle_facturas = implode("\n", $lineas);
			$detalle_facturas_html = implode("\n", $lineasHtml);
		}

		$stmtCuentas = $pdo->prepare("SELECT * FROM configuracion_cuentas_pago WHERE cliente_id = ? AND activo = 1 ORDER BY orden, id");
		$stmtCuentas->execute([$cliente_id_factura]);
		$cuentas = $stmtCuentas->fetchAll(PDO::FETCH_ASSOC);
		if (!empty($cuentas)) {
			$lineasCuentas = array_map(function ($c) {
				$tipo_txt = !empty($c['tipo_cuenta']) ? $c['tipo_cuenta'] . ' ' : '';
				return '- ' . $c['banco'] . ' (' . $tipo_txt . $c['numero_cuenta'] . ') a nombre de ' . $c['titular'];
			}, $cuentas);
			$lineasCuentasHtml = array_map(function ($c) use ($h, $b) {
				$tipo_txt = !empty($c['tipo_cuenta']) ? $h($c['tipo_cuenta']) . ' ' : '';
				return '- ' . $b($c['banco']) . ' (' . $tipo_txt . $h($c['numero_cuenta']) . ') a nombre de ' . $h($c['titular']);
			}, $cuentas);
			$cuentas_pago = "Formas de pago:\n" . implode("\n", $lineasCuentas);
			$cuentas_pago_html = '<strong>Formas de pago:</strong>' . "\n" . implode("\n", $lineasCuentasHtml);
		} else {
			$cuentas_pago = '';
			$cuentas_pago_html = '';
		}

		$stmtPlantilla = $pdo->prepare("SELECT * FROM configuracion_mensajes WHERE cliente_id = ? AND tipo = ?");
		$stmtPlantilla->execute([$cliente_id_factura, $tipo]);
		$plantilla = $stmtPlantilla->fetch(PDO::FETCH_ASSOC);

		if (!$plantilla) {
			$defaults = [
				'envio_factura' => "{{saludo}}\n\nEspero que se encuentre bien.\n\nAdjunto {{detalle_facturas}}\n\n{{cuentas_pago}}\n\nQuedo atento a cualquier consulta o confirmación de recepción.\n\nSaludos cordiales,",
				'saldo_pendiente' => "{{saludo}}\n\nEspero que se encuentre muy bien.\n\nLe escribo para darle seguimiento a las siguientes facturas pendientes de pago:\n\n{{detalle_facturas}}\n\nPor lo anterior, el saldo total pendiente asciende a L {{total}}.\n\n{{cuentas_pago}}\n\nAgradecemos mucho su apoyo y gestión. Quedamos atentos a su confirmación.\n\nSaludos cordiales,"
			];
			$contenido = $defaults[$tipo];
			$asunto = $tipo === 'envio_factura'
				? 'Facturas {{cliente_nombre}} - Mes de {{mes_actual}} de {{anio_actual}}'
				: 'Saldo pendiente de pago - {{cliente_nombre}}';
		} else {
			$contenido = $plantilla['contenido'];
			$asunto = $plantilla['asunto'];
		}

		// Versión texto plano (sin etiquetas, para pegar en editores de texto simple)
		$reemplazos = [
			'{{saludo}}' => $saludo,
			'{{cliente_nombre}}' => $receptor_nombre,
			'{{detalle_facturas}}' => $detalle_facturas,
			'{{total}}' => number_format($total, 2),
			'{{cuentas_pago}}' => $cuentas_pago,
			'{{mes_actual}}' => $mes_actual,
			'{{anio_actual}}' => $anio_actual,
		];
		$textoFinal = strtr($contenido, $reemplazos);
		$asunto = strtr($asunto, $reemplazos);

		// Versión HTML con negritas (para pegar en Gmail/Outlook con formato)
		$contenidoEscapado = htmlspecialchars($contenido, ENT_QUOTES, 'UTF-8');
		$reemplazosHtml = [
			'{{saludo}}' => $b($saludo),
			'{{cliente_nombre}}' => $h($receptor_nombre),
			'{{detalle_facturas}}' => $detalle_facturas_html,
			'{{total}}' => $b(number_format($total, 2)),
			'{{cuentas_pago}}' => $cuentas_pago_html,
			'{{mes_actual}}' => $h($mes_actual),
			'{{anio_actual}}' => $h($anio_actual),
		];
		$textoFinalHtml = nl2br(strtr($contenidoEscapado, $reemplazosHtml), false);

		echo json_encode([
			'success' => true,
			'asunto' => $asunto,
			'mensaje' => $textoFinal,
			'mensaje_html' => $textoFinalHtml,
		]);
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
