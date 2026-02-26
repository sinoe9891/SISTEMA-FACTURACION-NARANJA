<?php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $cid = (int)(USUARIO_ROL === 'superadmin'
        ? ($_SESSION['cliente_seleccionado'] ?? 0)
        : CLIENTE_ID);

    $prestamo_id = filter_input(INPUT_POST, 'prestamo_id', FILTER_VALIDATE_INT);
    if (!$prestamo_id || !$cid) throw new Exception("Parámetros inválidos.");

    // Verificar que pertenece al cliente
    $stmt = $pdo->prepare("SELECT id, descripcion FROM colaborador_prestamos WHERE id = ? AND cliente_id = ?");
    $stmt->execute([$prestamo_id, $cid]);
    $pr = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pr) throw new Exception("Préstamo no encontrado.");

    // Eliminar cuotas primero (FK)
    $pdo->prepare("DELETE FROM colaborador_prestamo_cuotas WHERE prestamo_id = ?")->execute([$prestamo_id]);
    // Eliminar préstamo
    $pdo->prepare("DELETE FROM colaborador_prestamos WHERE id = ? AND cliente_id = ?")->execute([$prestamo_id, $cid]);

    echo json_encode(['success' => true, 'message' => 'Préstamo eliminado correctamente.'], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}