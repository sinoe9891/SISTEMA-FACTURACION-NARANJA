<?php
// clientes/naranjaymedia/includes/gasto_marcar_pagado.php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    $cid      = (int)(USUARIO_ROL === 'superadmin' ? ($_SESSION['cliente_seleccionado'] ?? 0) : CLIENTE_ID);
    $gasto_id = filter_input(INPUT_POST, 'gasto_id', FILTER_VALIDATE_INT);
    if (!$gasto_id) throw new Exception("Gasto inválido.");

    $stmtCk = $pdo->prepare("SELECT id FROM gastos WHERE id=? AND cliente_id=? AND estado!='anulado'");
    $stmtCk->execute([$gasto_id, $cid]);
    if (!$stmtCk->fetchColumn()) throw new Exception("Gasto no encontrado.");

    $fecha       = trim($_POST['fecha']       ?? '') ?: date('Y-m-d');
    $metodo      = trim($_POST['metodo_pago'] ?? 'efectivo');
    $tarjeta_id  = filter_input(INPUT_POST, 'tarjeta_id', FILTER_VALIDATE_INT) ?: null;
    $tiene_fact  = !empty($_POST['tiene_factura']);
    $factura_ref = $tiene_fact ? (trim($_POST['factura_ref'] ?? '') ?: null) : null;
    $notas       = trim($_POST['notas'] ?? '') ?: null;

    if (!in_array($metodo, ['efectivo','transferencia','tarjeta','cheque','otro']))
        throw new Exception("Método de pago inválido.");
    if (!DateTime::createFromFormat('Y-m-d', $fecha))
        throw new Exception("Fecha inválida.");

    // Si método = tarjeta, validar que pertenezca al cliente
    if ($metodo === 'tarjeta' && $tarjeta_id) {
        $stmtT = $pdo->prepare("SELECT id FROM tarjetas WHERE id=? AND cliente_id=? AND activa=1");
        $stmtT->execute([$tarjeta_id, $cid]);
        if (!$stmtT->fetchColumn()) {
            $tarjeta_id = null; // Tarjeta no válida, ignorar
        }
    } else {
        $tarjeta_id = null;
    }

    // Manejo de archivo adjunto
    $arch_adj = null;
    $arch_nom = null;
    $uploadDir = __DIR__ . '/uploads/gastos/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);

    if (!empty($_FILES['archivo_adjunto']['name']) && $_FILES['archivo_adjunto']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['archivo_adjunto'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp','pdf']))
            throw new Exception("Tipo de archivo no permitido.");
        if ($file['size'] > 5 * 1024 * 1024)
            throw new Exception("El archivo supera 5 MB.");
        $arch_adj = 'gasto_' . $cid . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $arch_adj))
            throw new Exception("Error al guardar el archivo.");
        $arch_nom = basename($file['name']);
    }

    $sets   = ['estado=?', 'fecha=?', 'metodo_pago=?', 'tarjeta_id=?'];
    $params = ['pagado', $fecha, $metodo, $tarjeta_id];
    if ($factura_ref !== null) { $sets[] = 'factura_ref=?'; $params[] = $factura_ref; }
    if ($notas)                { $sets[] = 'notas=?';       $params[] = $notas; }
    if ($arch_adj)             { $sets[] = 'archivo_adjunto=?'; $params[] = $arch_adj;
                                 $sets[] = 'archivo_nombre=?';  $params[] = $arch_nom; }
    $params[] = $gasto_id;
    $params[] = $cid;

    $pdo->prepare("UPDATE gastos SET " . implode(',', $sets) . " WHERE id=? AND cliente_id=?")->execute($params);
    echo json_encode(['success' => true, 'message' => 'Gasto marcado como pagado.']);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
