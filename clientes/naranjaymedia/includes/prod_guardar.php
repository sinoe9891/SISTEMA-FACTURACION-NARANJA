<?php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cliente_id = $_POST['cliente_id'] ?? null;
    $nombre = trim($_POST['nombre'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $precio = floatval($_POST['precio'] ?? 0);
    $tipo_isv = intval($_POST['tipo_isv'] ?? 0);

    if ($cliente_id && $nombre && $precio > 0) {
        $stmt = $pdo->prepare("INSERT INTO productos (cliente_id, nombre, descripcion, precio, tipo_isv) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$cliente_id, $nombre, $descripcion, $precio, $tipo_isv]);
    }

    header("Location: ../productos");
    exit;
}
