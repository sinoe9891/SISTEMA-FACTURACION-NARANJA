<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';
require_once '../../includes/intentos.php';
require_once '../../includes/inventario.php';
require_once '../../includes/factura_contrato.php';
require_once '../../includes/facturacion.php';

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

$estado            = in_array($_POST['estado'] ?? 'emitida', ['emitida', 'anulada', 'borrador'])
	? $_POST['estado'] : 'emitida';

$estado_declarada  = isset($_POST['estado_declarada']) ? 1 : 0;
$pagada            = isset($_POST['pagada']) ? 1 : 0;
$enviada_receptor  = isset($_POST['enviada_receptor']) ? 1 : 0;




if (!$motivo || !$usuario_autoriza || !$clave_autoriza) {
	die("Todos los campos de autorización son obligatorios.");
}

$usuario_id = $_SESSION['usuario_id'];
$ip = $_SERVER['REMOTE_ADDR'];

// Validar autorizador (con límite de intentos fallidos)
if ($min = intentosBloqueado($pdo, 'autoriza', $usuario_autoriza)) {
	die("Demasiados intentos fallidos de autorización. Intenta de nuevo en $min minuto(s).");
}
$stmt = $pdo->prepare("SELECT id, rol, clave, cliente_id, estado FROM usuarios WHERE correo = ?");
$stmt->execute([$usuario_autoriza]);
$autorizador = $stmt->fetch();

if (!$autorizador || !password_verify($clave_autoriza, $autorizador['clave']) || ($autorizador['estado'] ?? 'activo') !== 'activo') {
	intentosRegistrarFallo($pdo, 'autoriza', $usuario_autoriza);
	die("Usuario o contraseña incorrecta.");
}
intentosLimpiar($pdo, 'autoriza', $usuario_autoriza);

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

// Solo el superadmin puede editar facturas de otra empresa (antes cualquier admin podía)
if ($usuario['rol'] !== 'superadmin' && (int)$factura['cliente_id'] !== (int)$cliente_id) {
	die("Acceso no autorizado.");
}
// Quien autoriza también debe ser de la empresa de la factura (o superadmin)
if ($autorizador['rol'] !== 'superadmin' && (int)$autorizador['cliente_id'] !== (int)$factura['cliente_id']) {
	die("El usuario que autoriza no pertenece a la empresa de esta factura.");
}
// A partir de aquí todo se calcula con la empresa de la factura
$cliente_factura = (int)$factura['cliente_id'];

if ($fecha_emision !== null && !DateTime::createFromFormat('Y-m-d H:i:s', $fecha_emision) && !DateTime::createFromFormat('Y-m-d\TH:i', $fecha_emision) && !DateTime::createFromFormat('Y-m-d', $fecha_emision)) {
	die("Fecha de emisión inválida.");
}
if (empty($productos) || !is_array($productos)) {
	die("La factura debe tener al menos un producto.");
}
foreach ($productos as $i => $item) {
	$n = $i + 1;
	if (!is_numeric($item['cantidad'] ?? null) || (float)$item['cantidad'] <= 0) die("Línea $n: la cantidad debe ser mayor que 0.");
	if (!is_numeric($item['precio_unitario'] ?? null) || (float)$item['precio_unitario'] < 0) die("Línea $n: el precio no puede ser negativo.");
	$stmtP = $pdo->prepare("SELECT COUNT(*) FROM productos_clientes WHERE id = ? AND cliente_id = ?");
	$stmtP->execute([(int)($item['id'] ?? 0), $cliente_factura]);
	if (!$stmtP->fetchColumn()) die("Línea $n: producto inválido.");
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

// Cambiar el receptor: admin y superadmin, pero si la factura ya fue declarada ante SAR,
// solo el superadmin puede forzar el cambio.
$es_superadmin_receptor = $usuario['rol'] === 'superadmin';
$facturaYaDeclarada = ($factura['estado_declarada'] == 1 || $factura['estado_declarada'] === 'si');
$puedeEditarReceptor = $es_superadmin_receptor || (in_array($usuario['rol'], ['admin', 'superadmin']) && !$facturaYaDeclarada);
$cambiar_receptor = $puedeEditarReceptor && $receptor_id && (int)$receptor_id !== (int)$factura['receptor_id'];

if (!$puedeEditarReceptor && $receptor_id && (int)$receptor_id !== (int)$factura['receptor_id']) {
	die("Esta factura ya fue declarada ante SAR: solo un superadmin puede cambiar el receptor.");
}

if ($cambiar_receptor) {
	$stmtRecep = $pdo->prepare("SELECT id, nombre FROM clientes_factura WHERE id = ? AND cliente_id = ?");
	$stmtRecep->execute([$receptor_id, $factura['cliente_id']]);
	$nuevoReceptor = $stmtRecep->fetch(PDO::FETCH_ASSOC);
	if (!$nuevoReceptor) {
		die("Receptor inválido.");
	}
}

try {
	$pdo->beginTransaction();

    // Campo ausente: conservar asociación (compatibilidad con formularios anteriores).
    $contratoId = $factura['contrato_id'] ? (int)$factura['contrato_id'] : null;
    if (array_key_exists('contrato_id', $_POST)) {
        $valorContrato = trim((string)$_POST['contrato_id']);
        if ($valorContrato !== '' && (!ctype_digit($valorContrato) || (int)$valorContrato < 1)) throw new Exception('Contrato inválido.');
        $contratoId = $valorContrato === '' ? null : (int)$valorContrato;
    }
    $cambioContrato = (int)$contratoId !== (int)$factura['contrato_id'];
    if ($cambioContrato && !$es_admin) throw new Exception('Solo un administrador puede cambiar el contrato asociado.');
    if ($cambioContrato || $cambiar_receptor) facturaValidarCambioContrato($pdo, $factura, $contratoId, $cambiar_receptor ? (int)$receptor_id : (int)$factura['receptor_id']);

	// Inventario: devolver lo que descontaban los productos anteriores (se vuelve a descontar al final)
	invRevertirFactura($pdo, $cliente_factura, (int)$factura_id, (int)$usuario_id, 'Factura editada');

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
		$stmtISV->execute([$producto_id, $cliente_factura]);
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
	// Total con ISV 15 % y 18 % (antes las letras omitían el 18 % y no coincidían con el total)
	$total = $subtotal + $isv_15 + $isv_18;
	if ($total <= 0) throw new Exception("El total de la factura debe ser mayor que 0.");
	$monto_letras = numeroALetras($total);

	// Sigue sin contrato: si es una mensualidad, se liga sola al contrato del cliente (igual que al crearla)
	if (!$contratoId && !$factura['contrato_id']) {
		$contratoId = facturaContratoAutomatico($pdo, (int)$factura['cliente_id'], $cambiar_receptor ? (int)$receptor_id : (int)$factura['receptor_id'], (float)$subtotal);
		if ($contratoId) $cambioContrato = true;
	}

	// Actualizar factura
	$setReceptor = $cambiar_receptor ? "receptor_id = ?,\n        " : "";
	$stmt = $pdo->prepare("
    UPDATE facturas
    SET $setReceptor fecha_emision        = ?,
        condicion_pago       = ?,
        exonerado            = ?,
        orden_compra_exenta  = ?,
        constancia_exoneracion = ?,
        registro_sag         = ?,
        subtotal             = ?,
        isv_15               = ?,
        isv_18               = ?,
        total                = ?,
        monto_letras         = ?,
        gravado_total        = ?,
        importe_gravado_15   = ?,
        importe_gravado_18   = ?,
        estado               = ?,
        estado_declarada     = ?,
        pagada               = ?,
        enviada_receptor     = ?,
        contrato_id          = ?
    WHERE id = ?
");
	$valoresUpdate = [];
	if ($cambiar_receptor) {
		$valoresUpdate[] = (int) $receptor_id;
	}
	array_push(
		$valoresUpdate,
		$fecha_emision,
		$condicion_pago,
		$exonerado,
		$orden_compra_exenta,
		$constancia_exoneracion,
		$registro_sag,
		$subtotal,
		$isv_15,
		$isv_18,
		$total,
		$monto_letras,
		$gravado_total,
		$importe_gravado_15,
		$importe_gravado_18,
		$estado,
		$estado_declarada,
		$pagada,
		$enviada_receptor,
        $contratoId,
		$factura_id
	);
	$stmt->execute($valoresUpdate);

	if ($cambiar_receptor) {
		$motivoReceptor = 'Receptor cambiado de #' . $factura['receptor_id'] . ' a #' . $nuevoReceptor['id'] . ' (' . $nuevoReceptor['nombre'] . '). Motivo: ' . $motivo;
		$pdo->prepare("INSERT INTO bitacora_facturas (factura_id, usuario_id, autorizador_id, accion, motivo, fecha)
			VALUES (?, ?, ?, ?, ?, NOW())")
			->execute([$factura_id, $usuario_id, $autorizador['id'], 'receptor_cambiado', $motivoReceptor]);
	}


	/* ---------- detectar cambios de estado ---------- */
	$cambioEstado = (
		$factura['estado']             !== $estado ||
		$factura['estado_declarada']   !=  $estado_declarada ||
		$factura['pagada']             !=  $pagada ||
		$factura['enviada_receptor']   !=  $enviada_receptor
	);

	$accion  = $cambioEstado ? 'cambio_estado' : 'editada';
	$detalles = $cambioEstado ? json_encode([
		'previo' => [
			'estado'            => $factura['estado'],
			'estado_declarada'  => $factura['estado_declarada'],
			'pagada'            => $factura['pagada'],
			'enviada_receptor'  => $factura['enviada_receptor']
		],
		'nuevo'  => [
			'estado'            => $estado,
			'estado_declarada'  => $estado_declarada,
			'pagada'            => $pagada,
			'enviada_receptor'  => $enviada_receptor
		]
	], JSON_UNESCAPED_UNICODE) : null;



    if ($cambioContrato) {
        $detalleContrato = $detalles ? json_decode($detalles, true) : [];
        $detalleContrato['contrato'] = ['anterior'=>$factura['contrato_id'], 'nuevo'=>$contratoId];
        $detalles = json_encode($detalleContrato, JSON_UNESCAPED_UNICODE);
    }

	// Registrar en bitácora
	$stmt = $pdo->prepare("
    INSERT INTO bitacora_facturas
    (factura_id, usuario_id, autorizador_id, accion, motivo, detalles, fecha, ip)
    VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
");
	$stmt->execute([
		$factura_id,
		$usuario_id,
		$autorizador['id'],
		$accion,          // 'editada' o 'cambio_estado'
		$motivo,
		$detalles,        // null o JSON con cambios
		$ip
	]);

	// Inventario: descontar los productos nuevos si la factura sigue emitida
	if ($estado === 'emitida') {
		invDescontarFactura($pdo, $cliente_factura, (int)$factura_id, (int)$factura['establecimiento_id'], (int)$usuario_id);
	}

	$pdo->commit();

	header("Location: lista_facturas?success=1");
	exit;
} catch (Exception $e) {
	if ($pdo->inTransaction()) $pdo->rollBack();
	die("Error al guardar cambios: " . htmlspecialchars($e->getMessage()));
}
