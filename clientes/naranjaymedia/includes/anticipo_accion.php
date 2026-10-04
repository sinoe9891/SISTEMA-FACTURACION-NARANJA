<?php
// clientes/naranjaymedia/includes/anticipo_accion.php
// Pagos anticipados de contratos (dinero recibido antes de emitir la factura).
//   POST accion=registrar  → contrato_id, fecha, monto, metodo, referencia, concepto, cuenta_id
//   POST accion=anular     → id, motivo (admin)
//   POST accion=aplicar    → contrato_id, factura_id: convierte los anticipos pendientes en abonos de la factura
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once '../../../includes/anticipos.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    if (!anticiposDisponible($pdo)) throw new Exception("Falta instalar sql/migraciones/2026-10-03_contratos_anticipos.sql.");
    if (!in_array(USUARIO_ROL, ['admin', 'superadmin', 'facturador'], true)) throw new Exception("No tienes permiso para registrar pagos.");
    $cid = cliente_actual();
    if (!$cid) throw new Exception("Empresa no identificada.");
    $uid = (int)USUARIO_ID;
    $accion = $_POST['accion'] ?? '';

    $pdo->beginTransaction();
    switch ($accion) {
        case 'registrar':
            $id = anticipoRegistrar($pdo, $cid, $uid, $_POST);
            $msg = 'Pago anticipado registrado.';
            break;
        case 'anular':
            if (!in_array(USUARIO_ROL, ['admin', 'superadmin'], true)) throw new Exception("Solo un administrador puede anular pagos.");
            anticipoAnular($pdo, $cid, (int)($_POST['id'] ?? 0), (string)($_POST['motivo'] ?? ''));
            $msg = 'Pago anticipado anulado.';
            break;
        case 'aplicar':
            [$n, $monto, $saldo] = anticipoAplicar($pdo, $cid, $uid, (int)($_POST['contrato_id'] ?? 0), (int)($_POST['factura_id'] ?? 0));
            $msg = "$n pago(s) aplicados como abonos (L " . number_format($monto, 2) . "). " . ($saldo > 0 ? 'Saldo de la factura: L ' . number_format($saldo, 2) . '.' : 'La factura quedó pagada.');
            break;
        default:
            throw new Exception("Acción no válida.");
    }
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => $msg] + (isset($id) ? ['id' => $id] : []), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    if ($e instanceof PDOException) error_log('anticipo_accion.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e instanceof PDOException ? 'Error de base de datos.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
