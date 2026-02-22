<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

try {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		throw new Exception("Método no permitido.");
	}

	if (!isset($_SESSION['usuario_id'])) {
		throw new Exception("Usuario no autenticado.");
	}

	$usuario_id = $_SESSION['usuario_id'];
	$stmt = $pdo->prepare("
        SELECT c.id AS cliente_id FROM usuarios u
        INNER JOIN clientes_saas c ON u.cliente_id = c.id
        WHERE u.id = ?
    ");
	$stmt->execute([$usuario_id]);
	$cliente = $stmt->fetch();
	if (!$cliente) throw new Exception("Cliente no encontrado.");
	$cliente_id = $cliente['cliente_id'];

	// ── Recibir datos ─────────────────────────────────────────────────────────
	$receptor_id        = filter_input(INPUT_POST, 'receptor_id',   FILTER_VALIDATE_INT);
	$cai_id             = filter_input(INPUT_POST, 'cai_rango_id',  FILTER_VALIDATE_INT);
	$condicion_pago     = trim($_POST['condicion_pago'] ?? '');   // fix: FILTER_SANITIZE_STRING eliminado en PHP 8.2
	$contrato_id        = filter_input(INPUT_POST, 'contrato_id',   FILTER_VALIDATE_INT) ?: null;
	$exonerado          = isset($_POST['exonerado']) ? 1 : 0;
	$estado             = $_POST['estado']        ?? 'emitida';
	$fecha_emision      = $_POST['fecha_emision'] ?? date('Y-m-d H:i:s');
	$establecimiento_id = filter_input(INPUT_POST, 'establecimiento_id', FILTER_VALIDATE_INT);
	$productos          = $_POST['productos'] ?? [];

	$orden_compra_exenta    = $exonerado ? trim($_POST['orden_compra_exenta']     ?? '') : null;
	$constancia_exoneracion = $exonerado ? trim($_POST['constancia_exoneracion']  ?? '') : null;
	$registro_sag           = $exonerado ? trim($_POST['registro_sag']            ?? '') : null;

	if ($exonerado && (!$orden_compra_exenta || !$constancia_exoneracion || !$registro_sag)) {
		throw new Exception("Debe llenar todos los campos de exoneración.");
	}

	// ── Validaciones básicas ──────────────────────────────────────────────────
	if (!$receptor_id)        throw new Exception("Selecciona un cliente receptor.");
	if (!$cai_id)             throw new Exception("Selecciona un CAI válido.");
	if (!$condicion_pago)     throw new Exception("Selecciona la condición de pago.");
	if (!$establecimiento_id) throw new Exception("Establecimiento no especificado. Verifica tu sesión.");
	if (empty($productos))    throw new Exception("Agrega al menos un producto.");

	// ── Validar que pertenecen al cliente ─────────────────────────────────────
	$stmt = $pdo->prepare("SELECT COUNT(*) FROM cai_rangos WHERE id = ? AND cliente_id = ? AND fecha_limite >= CURDATE()");
	$stmt->execute([$cai_id, $cliente_id]);
	if (!$stmt->fetchColumn()) throw new Exception("CAI inválido o vencido.");

	$stmt = $pdo->prepare("SELECT COUNT(*) FROM clientes_factura WHERE id = ? AND cliente_id = ?");
	$stmt->execute([$receptor_id, $cliente_id]);
	if (!$stmt->fetchColumn()) throw new Exception("Cliente receptor inválido.");

	$stmt = $pdo->prepare("SELECT COUNT(*) FROM establecimientos WHERE establecimiento_id = ? AND cliente_id = ?");
	$stmt->execute([$establecimiento_id, $cliente_id]);
	if (!$stmt->fetchColumn()) throw new Exception("Establecimiento inválido para este cliente.");

	// ── Validar contrato si se proporcionó ────────────────────────────────────
	if ($contrato_id) {
		$stmt = $pdo->prepare("
			SELECT COUNT(*) FROM contratos
			WHERE id = ? AND cliente_id = ? AND receptor_id = ? AND estado = 'activo'
		");
		$stmt->execute([$contrato_id, $cliente_id, $receptor_id]);
		if (!$stmt->fetchColumn()) throw new Exception("El contrato seleccionado no corresponde a este cliente.");
	}

	// ── Transacción ───────────────────────────────────────────────────────────
	$pdo->beginTransaction();

	// Punto de emisión
	$stmt = $pdo->prepare("SELECT punto_emision_id FROM cai_rangos WHERE id = ?");
	$stmt->execute([$cai_id]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);
	if (!$row || empty($row['punto_emision_id'])) throw new Exception("No se encontró el punto de emisión del CAI.");
	$punto_emision_id = (int)$row['punto_emision_id'];

	// Correlativo
	$correlativo = generarCorrelativoFactura($pdo, $cai_id, $cliente_id, $establecimiento_id, $punto_emision_id);

	// ── Calcular totales ──────────────────────────────────────────────────────
	$subtotal = $isv_15 = $isv_18 = $gravado_total = $exento_total = 0;
	$importe_exonerado = $importe_gravado_15 = $importe_gravado_18 = 0;

	$stmtProd = $pdo->prepare("
		SELECT p.id,
		       COALESCE(
		           (SELECT precio_especial FROM precios_especiales
		            WHERE producto_id = p.id AND cliente_id = :cid LIMIT 1),
		           p.precio
		       ) AS precio_unitario,
		       p.tipo_isv
		FROM productos_clientes p
		WHERE p.cliente_id = :cid
	");
	$stmtProd->execute(['cid' => $cliente_id]);
	$productos_db = [];
	foreach ($stmtProd->fetchAll() as $p) $productos_db[$p['id']] = $p;

	foreach ($productos as $item) {
		$prod_id  = (int)$item['id'];
		$cantidad = (float)$item['cantidad'];
		if (!isset($productos_db[$prod_id])) throw new Exception("Producto inválido: $prod_id");

		$precio_unitario = isset($item['precio']) && $item['precio'] !== ''
			? (float)$item['precio']
			: (float)$productos_db[$prod_id]['precio_unitario'];
		$tipo_isv      = (int)$productos_db[$prod_id]['tipo_isv'];
		$subtotal_item = round($cantidad * $precio_unitario, 2);
		$subtotal     += $subtotal_item;

		if ($exonerado) {
			$importe_exonerado += $subtotal_item;
			$exento_total      += $subtotal_item;
		} else {
			if ($tipo_isv === 15) {
				$importe_gravado_15 += $subtotal_item;
				$isv_15             += $subtotal_item * 0.15;
				$gravado_total      += $subtotal_item;
			} elseif ($tipo_isv === 18) {
				$importe_gravado_18 += $subtotal_item;
				$isv_18             += $subtotal_item * 0.18;
				$gravado_total      += $subtotal_item;
			} else {
				$exento_total += $subtotal_item;
			}
		}
	}

	$total        = $subtotal + $isv_15 + $isv_18;
	$monto_letras = numeroALetras($total);

	// ── INSERT factura ────────────────────────────────────────────────────────
	$stmtIns = $pdo->prepare("
        INSERT INTO facturas (
            cliente_id, cai_id, receptor_id, contrato_id, establecimiento_id,
            correlativo, fecha_emision, estado_declarada, enviada_receptor,
            condicion_pago, exonerado,
            orden_compra_exenta, constancia_exoneracion, registro_sag,
            gravado_total, exento_total, importe_exonerado,
            importe_gravado_15, importe_gravado_18,
            subtotal, isv_15, isv_18, total, monto_letras, estado
        ) VALUES (
            :cliente_id, :cai_id, :receptor_id, :contrato_id, :establecimiento_id,
            :correlativo, :fecha_emision, 0, 0,
            :condicion_pago, :exonerado,
            :orden_compra_exenta, :constancia_exoneracion, :registro_sag,
            :gravado_total, :exento_total, :importe_exonerado,
            :importe_gravado_15, :importe_gravado_18,
            :subtotal, :isv_15, :isv_18, :total, :monto_letras, :estado
        )
    ");

	$stmtIns->execute([
		':cliente_id'             => $cliente_id,
		':cai_id'                 => $cai_id,
		':receptor_id'            => $receptor_id,
		':contrato_id'            => $contrato_id,
		':establecimiento_id'     => $establecimiento_id,
		':correlativo'            => $correlativo,
		':fecha_emision'          => $fecha_emision,
		':condicion_pago'         => $condicion_pago,
		':exonerado'              => $exonerado,
		':orden_compra_exenta'    => $orden_compra_exenta,
		':constancia_exoneracion' => $constancia_exoneracion,
		':registro_sag'           => $registro_sag,
		':gravado_total'          => $gravado_total,
		':exento_total'           => $exento_total,
		':importe_exonerado'      => $importe_exonerado,
		':importe_gravado_15'     => $importe_gravado_15,
		':importe_gravado_18'     => $importe_gravado_18,
		':subtotal'               => $subtotal,
		':isv_15'                 => $isv_15,
		':isv_18'                 => $isv_18,
		':total'                  => $total,
		':monto_letras'           => $monto_letras,
		':estado'                 => $estado,
	]);

	$factura_id = $pdo->lastInsertId();

	// Actualizar correlativo en CAI
	$pdo->prepare("UPDATE cai_rangos SET ultimo_correlativo = ? WHERE id = ?")
		->execute([$correlativo, $cai_id]);

	// ── INSERT ítems de factura ───────────────────────────────────────────────
	$stmtItem = $pdo->prepare("
		INSERT INTO factura_items_receptor
		    (factura_id, producto_id, descripcion_html, cantidad, precio_unitario, subtotal, isv_aplicado)
		VALUES
		    (:fid, :pid, :desc, :cant, :precio, :sub, :isv)
	");

	foreach ($productos as $item) {
		$prod_id         = (int)$item['id'];
		$cantidad        = (float)$item['cantidad'];
		$precio_unitario = isset($item['precio']) && $item['precio'] !== ''
			? (float)$item['precio']
			: (float)$productos_db[$prod_id]['precio_unitario'];
		$tipo_isv      = (int)$productos_db[$prod_id]['tipo_isv'];
		$subtotal_item = round($cantidad * $precio_unitario, 2);
		$descripcion   = trim($item['detalles'] ?? '');

		$stmtItem->execute([
			':fid'    => $factura_id,
			':pid'    => $prod_id,
			':desc'   => $descripcion,
			':cant'   => $cantidad,
			':precio' => $precio_unitario,
			':sub'    => $subtotal_item,
			':isv'    => in_array($tipo_isv, [15, 18]) ? $tipo_isv : 0,
		]);
	}

	$pdo->commit();

	echo json_encode([
		'success'    => true,
		'message'    => 'Factura creada correctamente.',
		'factura_id' => $factura_id,
	]);

} catch (Exception $e) {
	if ($pdo->inTransaction()) $pdo->rollBack();
	http_response_code(400);
	echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}