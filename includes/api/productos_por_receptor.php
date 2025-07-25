<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';

header('Content-Type: application/json');

$cliente_id = $_SESSION['cliente_id'] ?? null;
$receptor_id = $_GET['receptor_id'] ?? null;

if (!$cliente_id || !$receptor_id) {
    echo json_encode([]); // alguno está vacío
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT id, nombre, descripcion, precio, tipo_isv, precio_fijo
        FROM productos_clientes
        WHERE cliente_id = ? AND (receptores_id IS NULL OR receptores_id = ?)
    ");
    $stmt->execute([$cliente_id, $receptor_id]);
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($productos);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error al consultar productos.', 'detalle' => $e->getMessage()]);
}
