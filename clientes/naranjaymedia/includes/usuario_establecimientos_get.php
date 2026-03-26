<?php
/* clientes/naranjaymedia/includes/usuario_establecimientos_get.php */
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/session.php';
header('Content-Type: application/json');

$rol_actual          = USUARIO_ROL;
$usuario_id_logueado = (int)($_SESSION['usuario_id'] ?? 0);

if (!in_array($rol_actual, ['admin', 'superadmin'])) {
    echo json_encode([]);
    exit;
}

$usuario_id = (int)($_GET['usuario_id'] ?? 0);
if (!$usuario_id) {
    echo json_encode([]);
    exit;
}

// Admin: solo puede consultar establecimientos de usuarios de su cliente
if ($rol_actual === 'admin') {
    $stmtMe = $pdo->prepare("SELECT cliente_id FROM usuarios WHERE id = ?");
    $stmtMe->execute([$usuario_id_logueado]);
    $me = $stmtMe->fetch();
    $mi_cliente = (int)($me['cliente_id'] ?? 0);

    $stmtCheck = $pdo->prepare("SELECT cliente_id FROM usuarios WHERE id = ?");
    $stmtCheck->execute([$usuario_id]);
    $target = $stmtCheck->fetch();

    if (!$target || (int)$target['cliente_id'] !== $mi_cliente) {
        echo json_encode([]);
        exit;
    }
}

$stmt = $pdo->prepare("SELECT establecimiento_id FROM usuario_establecimientos WHERE usuario_id = ?");
$stmt->execute([$usuario_id]);
$ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo json_encode($ids);
