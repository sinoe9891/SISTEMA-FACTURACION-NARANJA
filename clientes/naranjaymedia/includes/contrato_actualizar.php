<?php
// includes/contrato_actualizar.php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Método no permitido.']);
    exit;
}

$cliente_id      = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);

$id              = (int)($_POST['id']             ?? 0);
$receptor_id     = (int)($_POST['receptor_id']    ?? 0);
$producto_id     = (int)($_POST['producto_id']    ?? 0);
$nombre_contrato = trim($_POST['nombre_contrato'] ?? '');
$monto           = (float)($_POST['monto']         ?? 0);
$fecha_inicio    = trim($_POST['fecha_inicio']     ?? '');
$fecha_fin       = trim($_POST['fecha_fin']        ?? '') ?: null;
$dia_pago        = (int)($_POST['dia_pago']        ?? 1);
$estado          = trim($_POST['estado']           ?? 'activo');
$notas           = trim($_POST['notas']            ?? '') ?: null;

// ── Validaciones ────────────────────────────────────────────────────────────
if (!$id || !$receptor_id || !$producto_id || !$nombre_contrato || $monto <= 0 || !$fecha_inicio) {
    echo json_encode(['ok' => false, 'msg' => 'Todos los campos obligatorios son requeridos.']);
    exit;
}

// Verificar propiedad del contrato
$stmtOwn = $pdo->prepare("SELECT id FROM contratos WHERE id = ? AND cliente_id = ?");
$stmtOwn->execute([$id, $cliente_id]);
if (!$stmtOwn->fetch()) {
    echo json_encode(['ok' => false, 'msg' => 'Contrato no encontrado.']);
    exit;
}

// Verificar que el producto pertenece al cliente (tabla productos_clientes)
$stmtProd = $pdo->prepare("SELECT id FROM productos_clientes WHERE id = ? AND cliente_id = ?");
$stmtProd->execute([$producto_id, $cliente_id]);
if (!$stmtProd->fetch()) {
    echo json_encode(['ok' => false, 'msg' => 'Servicio no válido.']);
    exit;
}

if ($fecha_fin && $fecha_fin < $fecha_inicio) {
    echo json_encode(['ok' => false, 'msg' => 'La fecha fin no puede ser anterior a la fecha de inicio.']);
    exit;
}

$estadosValidos = ['activo', 'vencido', 'cancelado', 'pausado'];
if (!in_array($estado, $estadosValidos)) {
    echo json_encode(['ok' => false, 'msg' => 'Estado no válido.']);
    exit;
}

// ── Actualizar ───────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    UPDATE contratos SET
        receptor_id     = ?,
        producto_id     = ?,
        nombre_contrato = ?,
        monto           = ?,
        fecha_inicio    = ?,
        fecha_fin       = ?,
        dia_pago        = ?,
        estado          = ?,
        notas           = ?
    WHERE id = ? AND cliente_id = ?
");
$stmt->execute([
    $receptor_id, $producto_id, $nombre_contrato, $monto,
    $fecha_inicio, $fecha_fin, $dia_pago, $estado, $notas,
    $id, $cliente_id
]);

$esAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
if ($esAjax) {
    echo json_encode(['ok' => true, 'msg' => 'Contrato actualizado.']);
} else {
    header('Location: ../contratos?updated=1');
}
exit;
