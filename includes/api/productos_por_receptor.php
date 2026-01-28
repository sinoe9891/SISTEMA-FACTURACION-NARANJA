<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../session.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $receptor_id = isset($_GET['receptor_id']) ? (int)$_GET['receptor_id'] : 0;
    if ($receptor_id <= 0) {
        echo json_encode([]);
        exit;
    }

    // cliente_id real desde sesión/usuario (no de GET)
    $usuario_id = (int)$_SESSION['usuario_id'];

    $stmt = $pdo->prepare("
        SELECT c.id AS cliente_id
        FROM usuarios u
        INNER JOIN clientes_saas c ON u.cliente_id = c.id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([$usuario_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(403);
        echo json_encode(['error' => 'Cliente no encontrado']);
        exit;
    }
    $cliente_id = (int)$row['cliente_id'];

    // Validar receptor pertenece al cliente
    $stmtV = $pdo->prepare("SELECT COUNT(*) FROM clientes_factura WHERE id = ? AND cliente_id = ?");
    $stmtV->execute([$receptor_id, $cliente_id]);
    if ((int)$stmtV->fetchColumn() === 0) {
        http_response_code(403);
        echo json_encode(['error' => 'Receptor inválido']);
        exit;
    }

    $stmtP = $pdo->prepare("
        SELECT id, nombre, descripcion, precio, tipo_isv, precio_fijo
        FROM productos_clientes
        WHERE cliente_id = ?
          AND (receptores_id IS NULL OR receptores_id = ?)
        ORDER BY nombre ASC
    ");
    $stmtP->execute([$cliente_id, $receptor_id]);

    echo json_encode($stmtP->fetchAll(PDO::FETCH_ASSOC));

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error al consultar productos.', 'detalle' => $e->getMessage()]);
}
