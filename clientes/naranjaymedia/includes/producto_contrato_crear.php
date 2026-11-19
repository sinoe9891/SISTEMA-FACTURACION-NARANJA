<?php
/**
 * producto_contrato_crear.php — Crea un servicio nuevo desde crear o editar contrato y lo asigna al cliente del contrato.
 * POST: receptor_id, nombre, descripcion (opcional), precio (sin ISV), tipo_isv (15|18|0).
 * Devuelve JSON { success, producto: { id, nombre, descripcion, precio, tipo_isv, precio_fijo } }.
 */
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    if (!in_array(USUARIO_ROL, ['admin', 'superadmin', 'facturador'], true)) throw new Exception("No tienes permiso para crear servicios.");
    // La empresa sale de la sesión, nunca del formulario
    $cid = (int)cliente_actual();
    if (!$cid) throw new Exception("Empresa no identificada.");
    $receptor = (int)($_POST['receptor_id'] ?? 0);
    $nombre = mb_substr(trim((string)($_POST['nombre'] ?? '')), 0, 400);
    $desc = trim((string)($_POST['descripcion'] ?? ''));
    $precio = round((float)str_replace(',', '', (string)($_POST['precio'] ?? 0)), 3);
    $isv = (string)($_POST['tipo_isv'] ?? '15');
    if (!in_array($isv, ['15', '18', '0'], true)) throw new Exception("ISV inválido.");
    if ($nombre === '') throw new Exception("Escribe el nombre del servicio.");
    if ($precio <= 0) throw new Exception("El precio debe ser mayor que 0.");
    $st = $pdo->prepare("SELECT COUNT(*) FROM clientes_factura WHERE id = ? AND cliente_id = ?");
    $st->execute([$receptor, $cid]);
    if (!$st->fetchColumn()) throw new Exception("Primero elegí el cliente del contrato.");
    $pdo->prepare("INSERT INTO productos_clientes (cliente_id, receptores_id, nombre, descripcion, precio, tipo_isv, precio_fijo, tipo) VALUES (?, ?, ?, ?, ?, ?, 0, 'servicio')")
        ->execute([$cid, $receptor, $nombre, $desc !== '' ? $desc : $nombre, $precio, $isv]);
    $id = (int)$pdo->lastInsertId();
    echo json_encode(['success' => true, 'producto' => ['id' => $id, 'nombre' => $nombre, 'descripcion' => $desc !== '' ? $desc : $nombre, 'precio' => number_format($precio, 3, '.', ''), 'tipo_isv' => $isv, 'precio_fijo' => 0]], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e instanceof PDOException ? 'Error de base de datos.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
