<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

try {
	// Validar que venga método POST
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		throw new Exception("Método no permitido.");
	}

	// Validar usuario logueado y obtener cliente_id
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
	if (!$cliente) {
		throw new Exception("Cliente no encontrado para usuario.");
	}
	$cliente_id = $cliente['cliente_id'];

	// Recoger y validar datos del formulario (receptor, cai, productos, etc)
	$receptor_id = filter_input(INPUT_POST, 'receptor_id', FILTER_VALIDATE_INT);
	$cai_id = filter_input(INPUT_POST, 'cai_rango_id', FILTER_VALIDATE_INT);
	$condicion_pago = filter_input(INPUT_POST, 'condicion_pago', FILTER_SANITIZE_STRING);
	
	
	$exonerado = isset($_POST['exonerado']) ? 1 : 0;
	$orden_compra_exenta = $exonerado ? trim($_POST['orden_compra_exenta'] ?? '') : null;
	$constancia_exoneracion = $exonerado ? trim($_POST['constancia_exoneracion'] ?? '') : null;
	$registro_sag = $exonerado ? trim($_POST['registro_sag'] ?? '') : null;
	if ($exonerado) {
		if (!$orden_compra_exenta || !$constancia_exoneracion || !$registro_sag) {
			throw new Exception("Debe llenar todos los campos de exoneración.");
		}
	}
	$estado = $_POST['estado'] ?? 'emitida';
	$fecha_emision = $_POST['fecha_emision'] ?? date('Y-m-d H:i:s');
	$establecimiento_id = filter_input(INPUT_POST, 'establecimiento_id', FILTER_VALIDATE_INT);
	if (!$establecimiento_id) {
		throw new Exception("Establecimiento no especificado.");
	}
	$productos = $_POST['productos'] ?? [];

	// print_r($_POST['productos']);
	// print_r($_POST, true);
	if (!$receptor_id || !$cai_id || !$condicion_pago || empty($productos)) {
		echo $receptor_id, $cai_id, $condicion_pago, $productos;
		throw new Exception("Faltan datos obligatorios.");
	}
	// Validar que CAI y receptor pertenecen al cliente
	$stmt = $pdo->prepare("SELECT COUNT(*) FROM cai_rangos WHERE id = ? AND cliente_id = ? AND fecha_limite >= CURDATE()");
	$stmt->execute([$cai_id, $cliente_id]);
	if ($stmt->fetchColumn() == 0) {
		throw new Exception("CAI inválido o inactivo.");
	}
	$stmt = $pdo->prepare("SELECT COUNT(*) FROM clientes_factura WHERE id = ? AND cliente_id = ?");
	$stmt->execute([$receptor_id, $cliente_id]);
	if ($stmt->fetchColumn() == 0) {
		throw new Exception("Cliente receptor inválido.");
	}
	$stmt = $pdo->prepare("SELECT COUNT(*) FROM establecimientos WHERE establecimiento_id = ? AND cliente_id = ?");
	$stmt->execute([$establecimiento_id, $cliente_id]);
	if ($stmt->fetchColumn() == 0) {
		throw new Exception("Establecimiento inválido para este cliente.");
	}

	// Empezar transacción
	$pdo->beginTransaction();

	$stmt = $pdo->prepare("SELECT punto_emision_id FROM cai_rangos WHERE id = ?");
	$stmt->execute([$cai_id]);
	$row = $stmt->fetch(PDO::FETCH_ASSOC);

	if (!$row || empty($row['punto_emision_id'])) {
		throw new Exception("No se encontró el punto de emisión para el CAI seleccionado.");
	}

	$punto_emision_id = (int)$row['punto_emision_id'];
	// 1. Calcular correlativo basado en el CAI
	$correlativo = generarCorrelativoFactura($pdo, $cai_id, $cliente_id, $establecimiento_id, $punto_emision_id);

	// 2. Calcular totales y campos fiscales
	$subtotal = 0;
	$isv_15 = 0;
	$isv_18 = 0;
	$gravado_total = 0;
	$exento_total = 0;
	$importe_exonerado = 0;
	$importe_gravado_15 = 0;
	$importe_gravado_18 = 0;

	// Obtener datos productos con precio_base e isv para validar
	$stmtProd = $pdo->prepare("
	SELECT p.id, 
	       COALESCE((
	         SELECT precio_especial FROM precios_especiales 
	         WHERE producto_id = p.id AND cliente_id = :cliente_id LIMIT 1
	       ), p.precio) AS precio_unitario,
	       p.tipo_isv
	FROM productos_clientes p
	WHERE p.cliente_id = :cliente_id
");
	$stmtProd->execute(['cliente_id' => $cliente_id]);
	$productos_db = [];
	foreach ($stmtProd->fetchAll() as $p) {
		$productos_db[$p['id']] = $p;
	}

	foreach ($productos as $item) {
		$prod_id = intval($item['id']);
		$cantidad = floatval($item['cantidad']);

		if (!isset($productos_db[$prod_id])) {
			throw new Exception("Producto inválido en el detalle: $prod_id");
		}

		$precio_unitario = floatval($productos_db[$prod_id]['precio_unitario']); // ✅ PRECIO REAL
		$tipo_isv = (int)$productos_db[$prod_id]['tipo_isv'];

		$subtotal_item = round($cantidad * $precio_unitario, 2);
		$subtotal += $subtotal_item;

		if ($exonerado) {
			$importe_exonerado += $subtotal_item;
			$exento_total += $subtotal_item;
		} else {
			switch ((int)$tipo_isv) {
				case 15:
					$importe_gravado_15 += $subtotal_item;
					$isv_15 += $subtotal_item * 0.15;
					$gravado_total += $subtotal_item;
					break;
				case 18:
					$importe_gravado_18 += $subtotal_item;
					$isv_18 += $subtotal_item * 0.18;
					$gravado_total += $subtotal_item;
					break;
				default:
					$exento_total += $subtotal_item;
					break;
			}
		}
	}

	$total = $subtotal + $isv_15 + $isv_18;

	// Función para convertir número a letras (puedes incluir o llamar la que ya tienes)

	$monto_letras = numeroALetras($total);

	// 3. Insertar factura
	$stmtInsertFactura = $pdo->prepare("
        INSERT INTO facturas (
    		cliente_id, cai_id, receptor_id, establecimiento_id, correlativo, fecha_emision, estado_declarada, condicion_pago, exonerado,
            orden_compra_exenta, constancia_exoneracion, registro_sag,
            gravado_total, exento_total, importe_exonerado, importe_gravado_15, importe_gravado_18,
            subtotal, isv_15, isv_18, total, monto_letras, estado
        ) VALUES (
            :cliente_id, :cai_id, :receptor_id, :establecimiento_id, :correlativo, :fecha_emision, :estado_declarada, :condicion_pago, :exonerado,
            :orden_compra_exenta, :constancia_exoneracion, :registro_sag,
            :gravado_total, :exento_total, :importe_exonerado, :importe_gravado_15, :importe_gravado_18,
            :subtotal, :isv_15, :isv_18, :total, :monto_letras, :estado
        )
    ");

	$stmtInsertFactura->execute([
		':cliente_id' => $cliente_id,
		':cai_id' => $cai_id,
		':receptor_id' => $receptor_id,
		':establecimiento_id' => $establecimiento_id,
		':correlativo' => $correlativo,
		':fecha_emision' => $fecha_emision,
		':estado_declarada' => 'no',
		':condicion_pago' => $condicion_pago,
		':exonerado' => $exonerado,
		':orden_compra_exenta' => $orden_compra_exenta,
		':constancia_exoneracion' => $constancia_exoneracion,
		':registro_sag' => $registro_sag,
		':gravado_total' => $gravado_total,
		':exento_total' => $exento_total,
		':importe_exonerado' => $importe_exonerado,
		':importe_gravado_15' => $importe_gravado_15,
		':importe_gravado_18' => $importe_gravado_18,
		':subtotal' => $subtotal,
		':isv_15' => $isv_15,
		':isv_18' => $isv_18,
		':total' => $total,
		':monto_letras' => $monto_letras,
		':estado' => $estado
	]);

	$factura_id = $pdo->lastInsertId();
	// Actualizar el último correlativo del CAI
	$pdo->prepare("UPDATE cai_rangos SET ultimo_correlativo = ? WHERE id = ?")
		->execute([$correlativo, $cai_id]);
	// 4. Insertar detalles (factura_items_receptor)
	$stmtInsertItem = $pdo->prepare("
		INSERT INTO factura_items_receptor (factura_id, producto_id, descripcion_html, cantidad, precio_unitario, subtotal, isv_aplicado) 
		VALUES (:factura_id, :producto_id, :descripcion_html, :cantidad, :precio_unitario, :subtotal, :isv_aplicado)
	");

	foreach ($productos as $item) {
		$prod_id = intval($item['id']);
		$cantidad = floatval($item['cantidad']);
		$descripcion = trim($item['detalles'] ?? '');
		if (!isset($productos_db[$prod_id])) {
			throw new Exception("Producto inválido: $prod_id");
		}

		$precio_unitario = floatval($productos_db[$prod_id]['precio_unitario']);
		$tipo_isv = (int) $productos_db[$prod_id]['tipo_isv'];
		$subtotal_item = round($cantidad * $precio_unitario, 2);

		$stmtInsertItem->execute([
			':factura_id' => $factura_id,
			':producto_id' => $prod_id,
			':descripcion_html' => $descripcion,
			':cantidad' => $cantidad,
			':precio_unitario' => $precio_unitario,
			':subtotal' => $subtotal_item,
			':isv_aplicado' => in_array($tipo_isv, [15, 18]) ? $tipo_isv : 0
		]);
	}


	// TODO: Aquí podrías generar PDF y actualizar la factura con la URL del PDF

	$pdo->commit();

	echo json_encode([
		'success' => true,
		'message' => 'Factura creada correctamente.',
		'factura_id' => $factura_id,
		'redirect_url' => "ver_factura?id=$factura_id"
	]);
} catch (Exception $e) {
	if ($pdo->inTransaction()) {
		$pdo->rollBack();
	}
	http_response_code(400);
	echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
