<?php
// clientes/naranjaymedia/includes/categoria_gasto_eliminar.php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $cid = (int)(USUARIO_ROL==='superadmin' ? ($_SESSION['cliente_seleccionado']??0) : CLIENTE_ID);
    $id  = filter_input(INPUT_POST,'id',FILTER_VALIDATE_INT);
    if (!$id) throw new Exception("Categoría no identificada.");
    // Verificar que no tiene gastos
    $sv = $pdo->prepare("SELECT COUNT(*) FROM gastos WHERE categoria_id=? AND cliente_id=? AND estado!='anulado'");
    $sv->execute([$id,$cid]);
    if ($sv->fetchColumn() > 0) throw new Exception("No se puede eliminar: tiene gastos asociados.");
    $pdo->prepare("DELETE FROM categorias_gastos WHERE id=? AND cliente_id=?")->execute([$id,$cid]);
    echo json_encode(['success'=>true,'message'=>'Categoría eliminada.']);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
