<?php
/* clientes/naranjaymedia/includes/usuario_establecimientos_guardar.php */
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/session.php';
header('Content-Type: application/json');

$rol_actual          = USUARIO_ROL;
$usuario_id_logueado = (int)($_SESSION['usuario_id'] ?? 0);

if (!in_array($rol_actual, ['admin', 'superadmin'])) {
    echo json_encode(['success' => false, 'error' => 'Sin permisos.']);
    exit;
}

$input           = json_decode(file_get_contents('php://input'), true);
$usuario_id      = (int)($input['usuario_id']      ?? 0);
$establecimientos = $input['establecimientos'] ?? [];

if (!$usuario_id) {
    echo json_encode(['success' => false, 'error' => 'ID de usuario inválido.']);
    exit;
}

// Admin: solo puede gestionar establecimientos de usuarios de su cliente
if ($rol_actual === 'admin') {
    $stmtMe = $pdo->prepare("SELECT cliente_id FROM usuarios WHERE id = ?");
    $stmtMe->execute([$usuario_id_logueado]);
    $me = $stmtMe->fetch();
    $mi_cliente = (int)($me['cliente_id'] ?? 0);

    $stmtCheck = $pdo->prepare("SELECT cliente_id FROM usuarios WHERE id = ?");
    $stmtCheck->execute([$usuario_id]);
    $target = $stmtCheck->fetch();

    if (!$target || (int)$target['cliente_id'] !== $mi_cliente) {
        echo json_encode(['success' => false, 'error' => 'No puedes modificar usuarios de otro cliente.']);
        exit;
    }
}

try {
    $pdo->beginTransaction();

    // Eliminar asignaciones actuales
    $pdo->prepare("DELETE FROM usuario_establecimientos WHERE usuario_id = ?")->execute([$usuario_id]);

    // Insertar nuevas
    $stmt = $pdo->prepare("INSERT INTO usuario_establecimientos (usuario_id, establecimiento_id) VALUES (?, ?)");
    foreach ($establecimientos as $estab_id) {
        $estab_id = (int)$estab_id;
        if ($estab_id > 0) {
            $stmt->execute([$usuario_id, $estab_id]);
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
