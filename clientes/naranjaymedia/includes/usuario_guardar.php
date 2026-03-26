<?php
/* clientes/naranjaymedia/includes/usuario_guardar.php */
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/session.php';
header('Content-Type: application/json');

$rol_actual = USUARIO_ROL;

if (!in_array($rol_actual, ['admin', 'superadmin'])) {
    echo json_encode(['success' => false, 'error' => 'Sin permisos.']);
    exit;
}

$nombre     = trim($_POST['nombre']     ?? '');
$correo     = trim($_POST['correo']     ?? '');
$clave      = trim($_POST['clave']      ?? '');
$rol        = trim($_POST['rol']        ?? '');
$estado     = trim($_POST['estado']     ?? 'activo');
$cliente_id = (int)($_POST['cliente_id'] ?? 0);

if (!$nombre || !$correo || !$clave || !$rol) {
    echo json_encode(['success' => false, 'error' => 'Campos incompletos.']);
    exit;
}
if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Correo no válido.']);
    exit;
}
if ($rol === 'superadmin') {
    echo json_encode(['success' => false, 'error' => 'No se puede crear un superadmin desde este formulario.']);
    exit;
}

// Admin: forzar su propio cliente y no puede crear admin
if ($rol_actual === 'admin') {
    $stmtMe = $pdo->prepare("SELECT cliente_id FROM usuarios WHERE id = ?");
    $stmtMe->execute([$_SESSION['usuario_id']]);
    $me = $stmtMe->fetch();
    $cliente_id = (int)($me['cliente_id'] ?? 0);
    if ($rol === 'admin') {
        echo json_encode(['success' => false, 'error' => 'Solo el superadmin puede crear administradores.']);
        exit;
    }
}

if (!$cliente_id) {
    echo json_encode(['success' => false, 'error' => 'Cliente requerido.']);
    exit;
}

// Verificar correo único
$stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE correo = ?");
$stmtCheck->execute([$correo]);
if ($stmtCheck->fetchColumn() > 0) {
    echo json_encode(['success' => false, 'error' => 'El correo ya está registrado.']);
    exit;
}

$hash = password_hash($clave, PASSWORD_DEFAULT);

$pdo->prepare("INSERT INTO usuarios (cliente_id, nombre, correo, clave, rol, estado) VALUES (?,?,?,?,?,?)")
    ->execute([$cliente_id, $nombre, $correo, $hash, $rol, $estado]);

echo json_encode(['success' => true, 'message' => 'Usuario creado correctamente.']);
