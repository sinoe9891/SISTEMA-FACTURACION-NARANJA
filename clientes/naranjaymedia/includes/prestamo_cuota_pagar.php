<?php
// includes/prestamo_cuota_pagar.php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';

header('Content-Type: application/json');

$cliente_id = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);

$cuota_id    = (int)($_POST['cuota_id']    ?? 0);
$fecha_pago  = trim($_POST['fecha_pago']  ?? date('Y-m-d'));
$metodo_pago = trim($_POST['metodo_pago'] ?? 'efectivo');
$notas       = trim($_POST['notas']       ?? '');

if (!$cuota_id) {
    echo json_encode(['success' => false, 'error' => 'Cuota no especificada.']);
    exit;
}

// Verificar que la cuota pertenece al cliente
$stmtC = $pdo->prepare("
    SELECT c.*, p.monto_total, p.saldo_pendiente, p.tipo
    FROM colaborador_prestamo_cuotas c
    JOIN colaborador_prestamos p ON p.id = c.prestamo_id
    WHERE c.id = ? AND c.cliente_id = ?
");
$stmtC->execute([$cuota_id, $cliente_id]);
$cuota = $stmtC->fetch(PDO::FETCH_ASSOC);

if (!$cuota) {
    echo json_encode(['success' => false, 'error' => 'Cuota no encontrada.']);
    exit;
}
if ($cuota['estado'] === 'pagado') {
    echo json_encode(['success' => false, 'error' => 'Esta cuota ya está pagada.']);
    exit;
}
if ($cuota['estado'] === 'cancelado') {
    echo json_encode(['success' => false, 'error' => 'Esta cuota está cancelada.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Marcar cuota como pagada
    $stmtUpd = $pdo->prepare("
        UPDATE colaborador_prestamo_cuotas
        SET estado = 'pagado', fecha_pago = ?, metodo_pago = ?, notas = ?
        WHERE id = ?
    ");
    $stmtUpd->execute([$fecha_pago, $metodo_pago, $notas, $cuota_id]);

    // Reducir saldo del préstamo
    $nuevo_saldo = max(0, round((float)$cuota['saldo_pendiente'] - (float)$cuota['monto'], 2));

    // Verificar si quedan cuotas pendientes
    $stmtPend = $pdo->prepare("
        SELECT COUNT(*) FROM colaborador_prestamo_cuotas
        WHERE prestamo_id = ? AND estado = 'pendiente'
    ");
    $stmtPend->execute([$cuota['prestamo_id']]);
    $pendientes_restantes = (int)$stmtPend->fetchColumn();
    // El que acabamos de pagar aún figura como pendiente en el conteo anterior,
    // así que si pendientes_restantes = 1, ahora serán 0
    $pendientes_reales = $pendientes_restantes - 1;

    $nuevo_estado_prestamo = ($pendientes_reales <= 0 || $nuevo_saldo <= 0)
        ? 'pagado'
        : 'activo';

    $stmtUpdP = $pdo->prepare("
        UPDATE colaborador_prestamos
        SET saldo_pendiente = ?, estado = ?
        WHERE id = ?
    ");
    $stmtUpdP->execute([$nuevo_saldo, $nuevo_estado_prestamo, $cuota['prestamo_id']]);

    $pdo->commit();

    echo json_encode([
        'success'           => true,
        'message'           => 'Cuota #' . $cuota['numero_cuota'] . ' marcada como pagada.',
        'nuevo_saldo'       => $nuevo_saldo,
        'prestamo_pagado'   => ($nuevo_estado_prestamo === 'pagado'),
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}