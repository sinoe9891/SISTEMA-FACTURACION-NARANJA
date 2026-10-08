<?php
// includes/prestamo_cancelar.php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';

header('Content-Type: application/json');
// Solo administradores y el rol Nómina manejan colaboradores, pagos y préstamos
if (!puedeNomina()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No tienes permiso para esta acción de colaboradores.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$cliente_id  = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);

$prestamo_id = (int)($_POST['prestamo_id'] ?? 0);

if (!$prestamo_id) {
    echo json_encode(['success' => false, 'error' => 'Préstamo no especificado.']);
    exit;
}

$stmtCheck = $pdo->prepare("SELECT id FROM colaborador_prestamos WHERE id = ? AND cliente_id = ?");
$stmtCheck->execute([$prestamo_id, $cliente_id]);
if (!$stmtCheck->fetch()) {
    echo json_encode(['success' => false, 'error' => 'Préstamo no encontrado.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Cancelar cuotas pendientes
    $pdo->prepare("
        UPDATE colaborador_prestamo_cuotas
        SET estado = 'cancelado'
        WHERE prestamo_id = ? AND estado = 'pendiente'
    ")->execute([$prestamo_id]);

    // Cancelar el préstamo
    $pdo->prepare("
        UPDATE colaborador_prestamos
        SET estado = 'cancelado', saldo_pendiente = 0
        WHERE id = ?
    ")->execute([$prestamo_id]);

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Préstamo cancelado correctamente.']);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}