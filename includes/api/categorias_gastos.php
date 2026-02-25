<?php
// includes/api/categorias_gastos.php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../session.php';

header('Content-Type: application/json; charset=utf-8');

$cliente_id = CLIENTE_ID;
if (!$cliente_id) {
    http_response_code(403);
    echo json_encode(['error' => 'Sin cliente']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT id, nombre, color, icono
    FROM categorias_gastos
    WHERE cliente_id = ? AND activa = 1
    ORDER BY nombre ASC
");
$stmt->execute([$cliente_id]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
