<?php
// clientes/naranjaymedia/includes/contrato_plan_accion.php
// Plan de pagos de un contrato (ver includes/contrato_plan.php).
//   GET  ?contrato_id=X                         → líneas con su estado + resumen + cobros que se pueden vincular
//   POST accion=guardar  contrato_id, con_isv, lineas (JSON [{id?, tipo, concepto, fecha, monto}])
//   POST accion=cobrar_recibo     id, fecha, metodo, notas            (contratos sin factura)
//   POST accion=cobrar_anticipo   id, fecha, metodo, referencia, cuenta_id, monto (contratos con factura)
//   POST accion=vincular          id, tipo (factura|recibo|anticipo), ref_id
//   POST accion=desvincular       id
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once '../../../includes/contrato_plan.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if (!planDisponible($pdo)) throw new Exception("Falta instalar sql/migraciones/2026-10-06_contratos_plan.sql.");
    if (!in_array(USUARIO_ROL, ['admin', 'superadmin', 'facturador'], true)) throw new Exception("No tienes permiso para administrar contratos.");
    $cid = (int)cliente_actual();
    if (!$cid) throw new Exception("Empresa no identificada.");
    $uid = (int)USUARIO_ID;

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $contratoId = (int)($_GET['contrato_id'] ?? 0);
        $c = planContrato($pdo, $cid, $contratoId);
        $lineas = planLineas($pdo, $cid, $contratoId);
        // Cobros del contrato que aún no están ligados a ninguna línea (para «Vincular»)
        $libres = ['factura' => [], 'recibo' => [], 'anticipo' => []];
        $st = $pdo->prepare("SELECT f.id, CONCAT('Factura ', f.correlativo, ' · ', DATE_FORMAT(f.fecha_emision, '%d/%m/%Y'), ' · L ', FORMAT(f.total, 2)) txt FROM facturas f
                             WHERE f.cliente_id = ? AND f.contrato_id = ? AND f.estado = 'emitida' AND NOT EXISTS (SELECT 1 FROM contratos_plan p WHERE p.factura_id = f.id) ORDER BY f.fecha_emision");
        $st->execute([$cid, $contratoId]);
        $libres['factura'] = $st->fetchAll(PDO::FETCH_ASSOC);
        $st = $pdo->prepare("SELECT r.id, CONCAT('Recibo ', LPAD(r.numero_recibo, 5, '0'), ' · ', DATE_FORMAT(r.fecha_emision, '%d/%m/%Y'), ' · L ', FORMAT(r.monto, 2)) txt FROM contratos_recibos r
                             WHERE r.cliente_id = ? AND r.contrato_id = ? AND r.estado = 'emitido' AND NOT EXISTS (SELECT 1 FROM contratos_plan p WHERE p.recibo_id = r.id) ORDER BY r.fecha_emision");
        $st->execute([$cid, $contratoId]);
        $libres['recibo'] = $st->fetchAll(PDO::FETCH_ASSOC);
        if (anticiposDisponible($pdo)) {
            $st = $pdo->prepare("SELECT a.id, CONCAT('Pago anticipado · ', DATE_FORMAT(a.fecha, '%d/%m/%Y'), ' · L ', FORMAT(a.monto, 2), IFNULL(CONCAT(' · ', a.concepto), '')) txt FROM contratos_anticipos a
                                 WHERE a.cliente_id = ? AND a.contrato_id = ? AND a.anulado = 0 AND NOT EXISTS (SELECT 1 FROM contratos_plan p WHERE p.anticipo_id = a.id) ORDER BY a.fecha");
            $st->execute([$cid, $contratoId]);
            $libres['anticipo'] = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        echo json_encode(['success' => true, 'con_factura' => planConFactura($c), 'lineas' => $lineas, 'resumen' => planResumen($lineas), 'libres' => $libres], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    $accion = $_POST['accion'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    $pdo->beginTransaction();
    switch ($accion) {
        case 'guardar':
            $lineas = json_decode((string)($_POST['lineas'] ?? '[]'), true);
            if (!is_array($lineas)) throw new Exception("Plan inválido.");
            $n = planGuardar($pdo, $cid, (int)($_POST['contrato_id'] ?? 0), $lineas, !empty($_POST['con_isv']));
            $msg = $n ? "Plan de pagos guardado ($n línea" . ($n > 1 ? 's' : '') . ")." : 'Plan de pagos eliminado.';
            break;
        case 'cobrar_recibo':
            planCobrarRecibo($pdo, $cid, $uid, $id, $_POST);
            $msg = 'Recibo emitido y pago registrado.';
            break;
        case 'cobrar_anticipo':
            $extraResp = ['anticipo_id' => planRegistrarAnticipo($pdo, $cid, $uid, $id, $_POST)];
            $msg = 'Pago registrado como pago anticipado (se aplica a la factura cuando la emitas).';
            break;
        case 'vincular':
            planVincular($pdo, $cid, $id, (string)($_POST['tipo'] ?? ''), (int)($_POST['ref_id'] ?? 0));
            $msg = 'Cobro vinculado.';
            break;
        case 'desvincular':
            planDesvincular($pdo, $cid, $id);
            $msg = 'Se quitó el vínculo. El recibo, la factura o el pago anticipado no se borraron.';
            break;
        default:
            throw new Exception("Acción no válida.");
    }
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => $msg] + ($extraResp ?? []), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    if ($e instanceof PDOException) error_log('contrato_plan_accion.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e instanceof PDOException ? 'Error de base de datos.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
