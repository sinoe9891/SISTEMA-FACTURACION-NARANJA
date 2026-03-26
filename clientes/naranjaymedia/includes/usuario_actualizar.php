<?php
/* clientes/naranjaymedia/includes/usuario_actualizar.php */
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/session.php';
header('Content-Type: application/json');

$rol_actual = USUARIO_ROL;

if (!in_array($rol_actual, ['admin', 'superadmin'])) {
    echo json_encode(['success' => false, 'error' => 'Sin permisos.']);
    exit;
}

$id         = (int)($_POST['user_id']    ?? 0);
$nombre     = trim($_POST['nombre']      ?? '');
$correo     = trim($_POST['correo']      ?? '');
$clave      = trim($_POST['clave']       ?? '');
$rol        = trim($_POST['rol']         ?? '');
$estado     = trim($_POST['estado']      ?? 'activo');
$cliente_id = (int)($_POST['cliente_id'] ?? 0);

if (!$id || !$nombre || !$correo || !$rol) {
    echo json_encode(['success' => false, 'error' => 'Campos incompletos.']);
    exit;
}
if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Correo no válido.']);
    exit;
}

// Obtener rol actual del usuario a editar
$stmtOld = $pdo->prepare("SELECT rol, cliente_id FROM usuarios WHERE id = ?");
$stmtOld->execute([$id]);
$oldUser = $stmtOld->fetch();
if (!$oldUser) {
    echo json_encode(['success' => false, 'error' => 'Usuario no encontrado.']);
    exit;
}
$oldRol = $oldUser['rol'];

// No cambiar rol de superadmin ni asignar superadmin
if ($oldRol === 'superadmin' && $rol !== 'superadmin') {
    echo json_encode(['success' => false, 'error' => 'No se puede cambiar el rol de un superadmin.']);
    exit;
}
if ($oldRol !== 'superadmin' && $rol === 'superadmin') {
    echo json_encode(['success' => false, 'error' => 'No se puede asignar el rol superadmin.']);
    exit;
}

// Admin: solo puede editar usuarios de su propio cliente, no puede crear admin
if ($rol_actual === 'admin') {
    $stmtMe = $pdo->prepare("SELECT cliente_id FROM usuarios WHERE id = ?");
    $stmtMe->execute([$_SESSION['usuario_id']]);
    $me = $stmtMe->fetch();
    $mi_cliente = (int)($me['cliente_id'] ?? 0);

    // Forzar el mismo cliente
    $cliente_id = $mi_cliente;

    // No puede editar usuarios de otro cliente
    if ((int)$oldUser['cliente_id'] !== $mi_cliente) {
        echo json_encode(['success' => false, 'error' => 'No puedes editar usuarios de otro cliente.']);
        exit;
    }
    // No puede asignar rol admin
    if ($rol === 'admin') {
        echo json_encode(['success' => false, 'error' => 'Solo el superadmin puede asignar el rol admin.']);
        exit;
    }
}

if (!$cliente_id) {
    echo json_encode(['success' => false, 'error' => 'Cliente requerido.']);
    exit;
}

// Correo único (excluyendo el mismo usuario)
$stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE correo = ? AND id != ?");
$stmtCheck->execute([$correo, $id]);
if ($stmtCheck->fetchColumn() > 0) {
    echo json_encode(['success' => false, 'error' => 'El correo ya está en uso por otro usuario.']);
    exit;
}

if ($clave) {
    $hash = password_hash($clave, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE usuarios SET nombre=?, correo=?, clave=?, rol=?, estado=?, cliente_id=? WHERE id=?")
        ->execute([$nombre, $correo, $hash, $rol, $estado, $cliente_id, $id]);
} else {
    $pdo->prepare("UPDATE usuarios SET nombre=?, correo=?, rol=?, estado=?, cliente_id=? WHERE id=?")
        ->execute([$nombre, $correo, $rol, $estado, $cliente_id, $id]);
}

echo json_encode(['success' => true, 'message' => 'Usuario actualizado correctamente.']);
