<?php
// clientes/naranjaymedia/includes/tarjeta_eliminar.php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
header('Content-Type: application/json; charset=utf-8');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    $cid = (int)(USUARIO_ROL === 'superadmin' ? ($_SESSION['cliente_seleccionado'] ?? 0) : CLIENTE_ID);
    $id  = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id) throw new Exception("Tarjeta no identificada.");

    // Verificar que no tenga gastos asociados
    $stmtUso = $pdo->prepare("SELECT COUNT(*) FROM gastos WHERE tarjeta_id=? AND cliente_id=?");
    $stmtUso->execute([$id, $cid]);
    if ((int)$stmtUso->fetchColumn() > 0)
        throw new Exception("No se puede eliminar: hay gastos asociados a esta tarjeta. Desactívala en su lugar.");

    $pdo->prepare("DELETE FROM tarjetas WHERE id=? AND cliente_id=?")->execute([$id, $cid]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
