<?php
/* clientes/naranjaymedia/includes/usuario_eliminar.php */
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/session.php';
header('Content-Type: application/json');

$rol_actual          = USUARIO_ROL;
$usuario_id_logueado = (int)($_SESSION['usuario_id'] ?? 0);

if (!in_array($rol_actual, ['admin', 'superadmin'])) {
    echo json_encode(['success' => false, 'error' => 'Sin permisos para eliminar usuarios.']);
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if (!$id) {
    echo json_encode(['success' => false, 'error' => 'ID inválido.']);
    exit;
}

// Obtener usuario a eliminar
$stmtTarget = $pdo->prepare("SELECT id, rol, cliente_id FROM usuarios WHERE id = ?");
$stmtTarget->execute([$id]);
$target = $stmtTarget->fetch();
if (!$target) {
    echo json_encode(['success' => false, 'error' => 'Usuario no encontrado.']);
    exit;
}

// Superadmin nunca se puede eliminar
if ($target['rol'] === 'superadmin') {
    echo json_encode(['success' => false, 'error' => 'El superadministrador no puede ser eliminado.']);
    exit;
}

// No puede eliminarse a sí mismo
if ($id === $usuario_id_logueado) {
    echo json_encode(['success' => false, 'error' => 'No puedes eliminarte a ti mismo.']);
    exit;
}

// Restricciones adicionales para admin (no superadmin)
if ($rol_actual === 'admin') {
    $stmtMe = $pdo->prepare("SELECT cliente_id FROM usuarios WHERE id = ?");
    $stmtMe->execute([$usuario_id_logueado]);
    $me        = $stmtMe->fetch();
    $mi_cliente = (int)($me['cliente_id'] ?? 0);

    // Solo puede eliminar usuarios de su propio cliente
    if ((int)$target['cliente_id'] !== $mi_cliente) {
        echo json_encode(['success' => false, 'error' => 'No puedes eliminar usuarios de otro cliente.']);
        exit;
    }
    // Admin no puede eliminar a otro admin
    if ($target['rol'] === 'admin') {
        echo json_encode(['success' => false, 'error' => 'Solo el superadmin puede eliminar administradores.']);
        exit;
    }
}

// Primero eliminar asignaciones de establecimientos
$pdo->prepare("DELETE FROM usuario_establecimientos WHERE usuario_id = ?")->execute([$id]);
// Eliminar usuario
$pdo->prepare("DELETE FROM usuarios WHERE id = ?")->execute([$id]);

echo json_encode(['success' => true]);
