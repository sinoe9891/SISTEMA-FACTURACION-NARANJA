<?php
// includes/contrato_cancelar.php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';

header('Content-Type: application/json; charset=utf-8');

$cliente_id = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);

$id = (int)($_POST['id'] ?? 0);

if (!$id) {
    echo json_encode(['ok' => false, 'msg' => 'ID no válido.']);
    exit;
}

$stmt = $pdo->prepare("
    UPDATE contratos SET estado = 'cancelado'
    WHERE id = ? AND cliente_id = ? AND estado NOT IN ('cancelado')
");
$stmt->execute([$id, $cliente_id]);

if ($stmt->rowCount() > 0) {
    echo json_encode(['ok' => true]);
} else {
    echo json_encode(['ok' => false, 'msg' => 'No se pudo cancelar el contrato.']);
}
exit;
