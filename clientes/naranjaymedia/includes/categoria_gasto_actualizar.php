<?php
// clientes/naranjaymedia/includes/categoria_gasto_actualizar.php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $cid    = (int)(USUARIO_ROL==='superadmin' ? ($_SESSION['cliente_seleccionado']??0) : CLIENTE_ID);
    $id     = filter_input(INPUT_POST,'cat_id',FILTER_VALIDATE_INT);
    $nombre = trim($_POST['nombre_cat'] ?? '');
    $color  = trim($_POST['color_cat']  ?? '#6c757d');
    $icono  = trim($_POST['icono_cat']  ?? 'fa-tag');
    if (!$id)     throw new Exception("Categoría no identificada.");
    if (!$nombre) throw new Exception("El nombre es obligatorio.");
    $sv = $pdo->prepare("SELECT id FROM categorias_gastos WHERE id=? AND cliente_id=?");
    $sv->execute([$id,$cid]);
    if (!$sv->fetchColumn()) throw new Exception("Categoría no encontrada.");
    $pdo->prepare("UPDATE categorias_gastos SET nombre=?,color=?,icono=? WHERE id=? AND cliente_id=?")
        ->execute([$nombre,$color,$icono,$id,$cid]);
    echo json_encode(['success'=>true,'message'=>'Categoría actualizada.']);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
