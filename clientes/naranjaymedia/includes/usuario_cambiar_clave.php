<?php
/* clientes/naranjaymedia/includes/usuario_cambiar_clave.php */
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/session.php';
header('Content-Type: application/json');

$rol_actual          = USUARIO_ROL;
$usuario_id_logueado = (int)($_SESSION['usuario_id'] ?? 0);

if (!in_array($rol_actual, ['admin', 'superadmin'])) {
    echo json_encode(['success' => false, 'error' => 'Sin permisos.']);
    exit;
}

$id    = (int)($_POST['user_id']    ?? 0);
$clave = trim($_POST['nueva_clave'] ?? '');

if (!$id || strlen($clave) < 6) {
    echo json_encode(['success' => false, 'error' => 'Contraseña muy corta (mínimo 6 caracteres).']);
    exit;
}

// Admin: solo puede cambiar clave de usuarios de su cliente
if ($rol_actual === 'admin') {
    $stmtMe = $pdo->prepare("SELECT cliente_id FROM usuarios WHERE id = ?");
    $stmtMe->execute([$usuario_id_logueado]);
    $me = $stmtMe->fetch();
    $mi_cliente = (int)($me['cliente_id'] ?? 0);

    $stmtTarget = $pdo->prepare("SELECT cliente_id, rol FROM usuarios WHERE id = ?");
    $stmtTarget->execute([$id]);
    $target = $stmtTarget->fetch();

    if (!$target || (int)$target['cliente_id'] !== $mi_cliente) {
        echo json_encode(['success' => false, 'error' => 'No puedes modificar usuarios de otro cliente.']);
        exit;
    }
    if ($target['rol'] === 'superadmin') {
        echo json_encode(['success' => false, 'error' => 'No puedes cambiar la clave de un superadmin.']);
        exit;
    }
}

$hash = password_hash($clave, PASSWORD_DEFAULT);
$pdo->prepare("UPDATE usuarios SET clave = ? WHERE id = ?")->execute([$hash, $id]);

echo json_encode(['success' => true]);
