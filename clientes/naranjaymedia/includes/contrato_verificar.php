<?php
// includes/contrato_verificar.php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';

header('Content-Type: application/json; charset=utf-8');

$cliente_id  = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);

$receptor_id = (int)($_GET['receptor_id'] ?? 0);

if (!$receptor_id) {
    echo json_encode(['tiene_activo' => false, 'cantidad' => 0]);
    exit;
}

$stmt = $pdo->prepare("
    SELECT COUNT(*) AS cantidad
    FROM contratos
    WHERE cliente_id  = ?
      AND receptor_id = ?
      AND estado      = 'activo'
");
$stmt->execute([$cliente_id, $receptor_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$cantidad = (int)($row['cantidad'] ?? 0);

echo json_encode(['tiene_activo' => $cantidad > 0, 'cantidad' => $cantidad]);
exit;
