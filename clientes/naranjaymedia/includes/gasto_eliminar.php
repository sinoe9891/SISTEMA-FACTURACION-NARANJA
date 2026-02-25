<?php
// clientes/naranjaymedia/includes/gasto_eliminar.php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
header('Content-Type: application/json; charset=utf-8');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    $cid      = (int)(USUARIO_ROL === 'superadmin' ? ($_SESSION['cliente_seleccionado'] ?? 0) : CLIENTE_ID);
    $gasto_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $accion   = trim($_POST['accion'] ?? 'anular');
    if (!$gasto_id) throw new Exception("Gasto no identificado.");

    $sv = $pdo->prepare("SELECT estado FROM gastos WHERE id=? AND cliente_id=?");
    $sv->execute([$gasto_id, $cid]);
    if (!$sv->fetchColumn()) throw new Exception("Gasto no encontrado.");

    if ($accion === 'eliminar' && in_array(USUARIO_ROL, ['admin','superadmin'])) {
        $pdo->prepare("DELETE FROM gastos WHERE id=? AND cliente_id=?")->execute([$gasto_id, $cid]);
        echo json_encode(['success' => true, 'message' => 'Gasto eliminado.']);
    } else {
        $pdo->prepare("UPDATE gastos SET estado='anulado' WHERE id=? AND cliente_id=?")->execute([$gasto_id, $cid]);
        echo json_encode(['success' => true, 'message' => 'Gasto anulado.']);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
