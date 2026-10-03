<?php
// clientes/naranjaymedia/includes/cxc_accion.php
// Cuentas por cobrar.
//   GET  ?factura_id=X          → abonos de la factura (JSON)
//   POST accion=cobrar          → registra un abono (admin/superadmin/facturador)
//   POST accion=anular_cobro    → anula un abono (admin/superadmin)
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once '../../../includes/cuentas.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if (!cxcDisponible($pdo)) throw new Exception("El módulo de cuentas por cobrar no está instalado (falta sql/migraciones/2026-10-03_cuentas_cobrar.sql).");
    $cid = cliente_actual();
    if (!$cid) throw new Exception("Empresa no identificada.");

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $f = cxcFactura($pdo, $cid, (int)($_GET['factura_id'] ?? 0));
        $stmt = $pdo->prepare("SELECT c.id, c.fecha, c.monto, c.metodo, c.referencia, c.anulado, c.motivo_anulacion,
                b.banco, b.numero AS cuenta_numero, u.nombre AS usuario
            FROM cobros_factura c
            LEFT JOIN cuentas_bancarias b ON b.id = c.cuenta_id
            LEFT JOIN usuarios u ON u.id = c.usuario_id
            WHERE c.factura_id = ? AND c.cliente_id = ? ORDER BY c.fecha, c.id");
        $stmt->execute([$f['id'], $cid]);
        echo json_encode(['success' => true, 'factura' => ['id' => $f['id'], 'correlativo' => $f['correlativo'], 'total' => (float)$f['total'], 'abonado' => $f['abonado'], 'saldo' => $f['saldo']],
            'cobros' => $stmt->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    $accion = $_POST['accion'] ?? '';
    $pdo->beginTransaction();
    if ($accion === 'cobrar') {
        if (!in_array(USUARIO_ROL, ['admin', 'superadmin', 'facturador'], true)) throw new Exception("No tienes permiso para registrar cobros.");
        $id = cxcRegistrarCobro($pdo, $cid, (int)USUARIO_ID, $_POST);
        $f = cxcFactura($pdo, $cid, (int)$_POST['factura_id']);
        $pdo->commit();
        echo json_encode(['success' => true, 'id' => $id, 'saldo' => $f['saldo'],
            'message' => $f['saldo'] > 0 ? 'Abono registrado. Saldo pendiente: L ' . number_format($f['saldo'], 2) : 'Factura cobrada por completo.'], JSON_UNESCAPED_UNICODE);
    } elseif ($accion === 'anular_cobro') {
        if (!in_array(USUARIO_ROL, ['admin', 'superadmin'], true)) throw new Exception("Solo un administrador puede anular cobros.");
        cxcAnularCobro($pdo, $cid, (int)($_POST['id'] ?? 0), (string)($_POST['motivo'] ?? ''));
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Cobro anulado.']);
    } else {
        throw new Exception("Acción no válida.");
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    if ($e instanceof PDOException) error_log('cxc_accion.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e instanceof PDOException ? 'Error de base de datos.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
