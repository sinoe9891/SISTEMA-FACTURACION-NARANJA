<?php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_POST['id'] ?? 0);
    $cliente_id = $_POST['cliente_id'] ?? null;
    $nombre = trim($_POST['nombre'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $precio = floatval($_POST['precio'] ?? 0);
    $tipo_isv = intval($_POST['tipo_isv'] ?? 0);

    if ($id && $cliente_id && $nombre && $precio > 0) {
        $stmt = $pdo->prepare("UPDATE productos SET nombre = ?, descripcion = ?, precio = ?, tipo_isv = ? WHERE id = ? AND cliente_id = ?");
        $stmt->execute([$nombre, $descripcion, $precio, $tipo_isv, $id, $cliente_id]);
    }

    header("Location: ../productos");
    exit;
}
