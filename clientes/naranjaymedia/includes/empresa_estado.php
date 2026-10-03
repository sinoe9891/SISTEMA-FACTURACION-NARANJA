<?php
// clientes/naranjaymedia/includes/empresa_estado.php
// Superadmin: activa o desactiva una empresa. Desactivada, sus usuarios no pueden entrar
// (y las sesiones abiertas se cierran en la siguiente página). Sus datos se conservan.
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    if (USUARIO_ROL !== 'superadmin') throw new Exception("Solo el superadmin puede cambiar el estado de una empresa.");
    $id     = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $estado = $_POST['estado'] ?? '';
    if (!$id || !in_array($estado, ['activo', 'inactivo'], true)) throw new Exception("Datos inválidos.");
    $stmt = $pdo->prepare("UPDATE clientes_saas SET estado = ? WHERE id = ?");
    $stmt->execute([$estado, $id]);
    if (!$stmt->rowCount()) {
        $existe = $pdo->prepare("SELECT COUNT(*) FROM clientes_saas WHERE id = ?");
        $existe->execute([$id]);
        if (!$existe->fetchColumn()) throw new Exception("Empresa no encontrada.");
    }
    echo json_encode(['success' => true, 'message' => $estado === 'activo' ? 'Empresa activada.' : 'Empresa desactivada.']);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
