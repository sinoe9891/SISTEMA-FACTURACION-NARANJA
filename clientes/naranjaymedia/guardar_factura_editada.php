<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	die("Método no permitido.");
}

// Recolección de datos
$factura_id = $_POST['factura_id'] ?? null;
$receptor_id = $_POST['receptor_id'] ?? null;
$fecha_emision = $_POST['fecha_emision'] ?? null;
$condicion_pago = $_POST['condicion_pago'] ?? 'Contado';
$exonerado = isset($_POST['exonerado']) ? 1 : 0;
$orden_compra_exenta = trim($_POST['orden_compra_exenta'] ?? '');
$constancia_exoneracion = trim($_POST['constancia_exoneracion'] ?? '');
$registro_sag = trim($_POST['registro_sag'] ?? '');
$productos = $_POST['productos'] ?? [];

$motivo = htmlspecialchars(trim($_POST['motivo'] ?? ''), ENT_QUOTES, 'UTF-8');
$usuario_autoriza = trim($_POST['usuario_autoriza'] ?? '');
$clave_autoriza = trim($_POST['clave_autoriza'] ?? '');

if (!$motivo || !$usuario_autoriza || !$clave_autoriza) {
	die("Todos los campos de autorización son obligatorios.");
}

$usuario_id = $_SESSION['usuario_id'];
$ip = $_SERVER['REMOTE_ADDR'];

// Validar autorizador
$stmt = $pdo->prepare("SELECT id, rol, clave FROM usuarios WHERE correo = ?");
$stmt->execute([$usuario_autoriza]);
$autorizador = $stmt->fetch();

if (!$autorizador || !password_verify($clave_autoriza, $autorizador['clave'])) {
	die("Usuario o contraseña incorrecta.");
}

if (!in_array($autorizador['rol'], ['admin', 'superadmin'])) {
	die("Solo un admin o superadmin puede autorizar cambios.");
}
// Validar usuario actual
$stmt = $pdo->prepare("SELECT id, rol, cliente_id FROM usuarios WHERE id = ?");
$stmt->execute([$usuario_id]);
$usuario = $stmt->fetch();

if (!$usuario) {
	die("Usuario no válido.");
}

$es_admin = in_array($usuario['rol'], ['admin', 'superadmin']);
$cliente_id = $usuario['cliente_id'];

// Obtener factura
$stmt = $pdo->prepare("SELECT * FROM facturas WHERE id = ?");
$stmt->execute([$factura_id]);
$factura = $stmt->fetch();

if (!$factura) {
	die("Factura no encontrada.");
}

if (!$es_admin && $factura['cliente_id'] != $cliente_id) {
	die("Acceso no autorizado.");
}

// Solo puede editar la última factura si no es admin
if (!$es_admin) {
	$stmt = $pdo->prepare("SELECT MAX(id) FROM facturas WHERE cliente_id = ?");
	$stmt->execute([$cliente_id]);
	$max_id = $stmt->fetchColumn();

	if ($factura['id'] != $max_id) {
		die("Solo puede editar la última factura emitida.");
	}
}

try {
	$pdo->beginTransaction();

	// Eliminar productos previos
	$stmt = $pdo->prepare("DELETE FROM factura_items_receptor WHERE factura_id = ?");
	$stmt->execute([$factura_id]);

	// Recalcular totales
	$subtotal = 0;
	$importe_gravado_15 = 0;
	$importe_gravado_18 = 0;
	$isv_15 = 0;
	$isv_18 = 0;
	$gravado_total = 0;

	foreach ($productos as $item) {
		$cantidad = (float)$item['cantidad'];
		$precio_unitario = (float)$item['precio_unitario'];
		$producto_id = (int)$item['id'];
		$descripcion = trim($item['descripcion_html'] ?? '');
		$subtotal_item = $cantidad * $precio_unitario;
		$subtotal += $subtotal_item;

		$stmtISV = $pdo->prepare("SELECT tipo_isv FROM productos_clientes WHERE id = ? AND cliente_id = ?");
		$stmtISV->execute([$producto_id, $cliente_id]);
		$tipo_isv = (int) $stmtISV->fetchColumn();

		$isv_aplicado_item = 0;
		$isv15_item = 0;
		$isv18_item = 0;

		if (!$exonerado) {
			if ($tipo_isv === 15) {
				$isv15_item = round($subtotal_item * 0.15, 2);
				$isv_15 += $isv15_item;
				$importe_gravado_15 += $subtotal_item;
			} elseif ($tipo_isv === 18) {
				$isv18_item = round($subtotal_item * 0.18, 2);
				$isv_18 += $isv18_item;
				$importe_gravado_18 += $subtotal_item;
			}
		}


		$isv_aplicado_item = $tipo_isv;

		$stmtInsert = $pdo->prepare("
		INSERT INTO factura_items_receptor 
		(factura_id, producto_id, cantidad, precio_unitario, subtotal, isv_aplicado, isv_15, isv_18, descripcion_html)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
	");
		$stmtInsert->execute([
			$factura_id,
			$producto_id,
			$cantidad,
			$precio_unitario,
			$subtotal_item,
			$isv_aplicado_item,
			$isv15_item,
			$isv18_item,
			$descripcion
		]);
	}

	$gravado_total = $importe_gravado_15 + $importe_gravado_18;
	$total = $subtotal + $isv_15;
	$monto_letras = numeroALetras($total);

	// Actualizar factura
	$stmt = $pdo->prepare("
	UPDATE facturas
	SET fecha_emision = ?, condicion_pago = ?, exonerado = ?, orden_compra_exenta = ?, constancia_exoneracion = ?, 
		registro_sag = ?, subtotal = ?, isv_15 = ?, isv_18 = ?, total = ?, monto_letras = ?, 
		gravado_total = ?, importe_gravado_15 = ?, importe_gravado_18 = ?
	WHERE id = ?
");

	$stmt->execute([
		$fecha_emision,
		$condicion_pago,
		$exonerado,
		$orden_compra_exenta,
		$constancia_exoneracion,
		$registro_sag,
		$subtotal,
		$isv_15,
		$isv_18,
		$subtotal + $isv_15 + $isv_18,
		$monto_letras,
		$gravado_total,
		$importe_gravado_15,
		$importe_gravado_18,
		$factura_id
	]);




	// Registrar en bitácora
	$stmt = $pdo->prepare("
		INSERT INTO bitacora_facturas (factura_id, usuario_id, autorizador_id, accion, motivo, fecha, ip)
		VALUES (?, ?, ?, 'editada', ?, NOW(), ?)
	");
	$stmt->execute([$factura_id, $usuario_id, $autorizador['id'], $motivo, $ip]);

	$pdo->commit();

	header("Location: lista_facturas?success=1");
	exit;
} catch (Exception $e) {
	$pdo->rollBack();
	die("Error al guardar cambios: " . $e->getMessage());
}
