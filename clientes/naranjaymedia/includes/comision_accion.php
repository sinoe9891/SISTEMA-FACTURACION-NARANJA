<?php
// clientes/naranjaymedia/includes/comision_accion.php — Comisiones por venta (ver includes/comisiones.php).
//   POST accion=vendedor_guardar (id?, nombre, identidad, telefono, email, notas, activo)
//        accion=editar (id, vendedor_id, tipo, valor, notas) · accion=quitar (id)
//        accion=pagar (id, fecha, monto, metodo, cuenta_id, notas, antes_de_cobro, comprobante) · accion=anular_pago (id)
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once '../../../includes/comisiones.php';
require_once __DIR__ . '/_gasto_adjuntos.php';
header('Content-Type: application/json; charset=utf-8');

$adj = null;
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    if (!comisionesDisponible($pdo)) throw new Exception("Falta instalar sql/migraciones/2026-10-27_comisiones.sql.");
    $cid = (int)cliente_actual();
    if (!$cid) throw new Exception("Empresa no identificada.");
    $uid = (int)USUARIO_ID;
    $accion = (string)($_POST['accion'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $admin = in_array(USUARIO_ROL, ['admin', 'superadmin'], true);
    if (!in_array(USUARIO_ROL, ['admin', 'superadmin', 'facturador'], true)) throw new Exception("Tu rol no maneja comisiones.");
    if (in_array($accion, ['pagar', 'editar_pago', 'anular_pago', 'quitar'], true) && !$admin) throw new Exception("Solo un administrador paga o quita comisiones.");
    if (in_array($accion, ['pagar', 'editar_pago'], true)) $adj = guardarAdjuntoGasto($_FILES['comprobante'] ?? null, $cid);

    $pdo->beginTransaction();
    $extra = [];
    switch ($accion) {
        case 'vendedor_guardar':
            $v = vendedorGuardar($pdo, $cid, $_POST);
            $extra['vendedor'] = ['id' => (int)$v['id'], 'nombre' => $v['nombre'], 'identidad' => (string)$v['identidad']];
            $msg = 'Vendedor guardado.';
            break;
        case 'editar':
            $st = $pdo->prepare("SELECT factura_id FROM factura_comisiones WHERE id = ? AND cliente_id = ?");
            $st->execute([$id, $cid]);
            $fid = (int)$st->fetchColumn();
            if (!$fid) throw new Exception("Comisión no encontrada.");
            comisionDesdeFactura($pdo, $cid, $uid, $fid, ['comision_activa' => 1, 'comision_vendedor_id' => $_POST['vendedor_id'] ?? 0, 'comision_tipo' => $_POST['tipo'] ?? '',
                'comision_valor' => $_POST['valor'] ?? 0, 'comision_notas' => $_POST['notas'] ?? '']);
            comisionRecalcular($pdo, $cid, $fid);
            $msg = 'Comisión actualizada.';
            break;
        case 'quitar':
            $st = $pdo->prepare("DELETE FROM factura_comisiones WHERE id = ? AND cliente_id = ? AND estado <> 'pagada'");
            $st->execute([$id, $cid]);
            if (!$st->rowCount()) throw new Exception("No se puede quitar (ya está pagada o no existe).");
            $msg = 'Comisión quitada de la factura.';
            break;
        case 'pagar':
            $extra['gasto_id'] = comisionPagar($pdo, $cid, $uid, $id, $_POST, $adj);
            $msg = 'Comisión pagada (gasto N.º ' . $extra['gasto_id'] . ').';
            break;
        case 'editar_pago':
            $extra['gasto_id'] = comisionEditarPago($pdo, $cid, $uid, $id, $_POST, $adj);
            $msg = 'Pago corregido (gasto N.º ' . $extra['gasto_id'] . ').';
            break;
        case 'anular_pago':
            comisionAnularPago($pdo, $cid, $id);
            $msg = 'Pago anulado: la comisión vuelve a «Por pagar».';
            break;
        default:
            throw new Exception("Acción no válida.");
    }
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => $msg] + $extra, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if ($adj) @unlink(gastoAdjuntoDir() . $adj[0]);
    http_response_code(400);
    if ($e instanceof PDOException) error_log('comision_accion.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e instanceof PDOException ? 'Error de base de datos.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
