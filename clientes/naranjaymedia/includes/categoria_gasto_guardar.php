<?php
// clientes/naranjaymedia/includes/categoria_gasto_guardar.php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $cid    = (int)(USUARIO_ROL==='superadmin' ? ($_SESSION['cliente_seleccionado']??0) : CLIENTE_ID);
    $nombre = trim($_POST['nombre_cat'] ?? '');
    $color  = trim($_POST['color_cat']  ?? '#6c757d');
    $icono  = trim($_POST['icono_cat']  ?? 'fa-tag');
    if (!$nombre) throw new Exception("El nombre es obligatorio.");
    $pdo->prepare("INSERT INTO categorias_gastos (cliente_id,nombre,color,icono) VALUES (?,?,?,?)")
        ->execute([$cid, $nombre, $color, $icono]);
    echo json_encode(['success'=>true, 'message'=>'Categoría creada.']);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
