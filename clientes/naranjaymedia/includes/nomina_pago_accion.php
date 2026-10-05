<?php
// clientes/naranjaymedia/includes/nomina_pago_accion.php — Editar, anular y eliminar pagos de nómina (solo administradores).
//   GET  ?vinculos=<gasto_id>                       → datos del pago y lo que se revertiría al anular
//   POST accion=editar  id, fecha, metodo_pago, notas, comprobante (archivo opcional)
//   POST accion=anular  id, motivo   → anula y deshace cuotas descontadas y bonos/viáticos liquidados
//   POST accion=eliminar id          → borra definitivamente un pago ya anulado
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once '../../../includes/nomina_pagos.php';
require_once __DIR__ . '/_gasto_adjuntos.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if (!in_array(USUARIO_ROL, ['admin', 'superadmin'], true)) throw new Exception("Solo un administrador puede modificar pagos de nómina.");
    $cid = (int)cliente_actual();
    if (!$cid) throw new Exception("Empresa no identificada.");

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $gid = (int)($_GET['vinculos'] ?? 0);
        $g = nominaPagoObtener($pdo, $cid, $gid);
        echo json_encode(['success' => true, 'pago' => array_intersect_key($g, array_flip(['id', 'descripcion', 'monto', 'fecha', 'metodo_pago', 'notas', 'estado', 'archivo_nombre']))]
            + nominaPagoVinculos($pdo, $cid, $gid), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    $gid = (int)($_POST['id'] ?? 0);

    switch ($_POST['accion'] ?? '') {
        case 'editar':
            $adj = guardarAdjuntoGasto($_FILES['comprobante'] ?? null, $cid);
            nominaPagoEditar($pdo, $cid, $gid, $_POST, $adj);
            echo json_encode(['success' => true, 'message' => 'Pago actualizado.'], JSON_UNESCAPED_UNICODE);
            break;

        case 'anular':
            $v = nominaPagoAnular($pdo, $cid, $gid, (string)($_POST['motivo'] ?? ''));
            $det = [];
            if ($v['cuotas']) $det[] = count($v['cuotas']) . ' cuota(s) de préstamo vuelven a pendiente';
            if ($v['bonos_viaticos']) $det[] = count($v['bonos_viaticos']) . ' bono(s)/viático(s) vuelven a pendiente';
            echo json_encode(['success' => true, 'message' => 'Pago anulado. La quincena queda libre para registrarla de nuevo.' . ($det ? ' ' . implode('; ', $det) . '.' : '')], JSON_UNESCAPED_UNICODE);
            break;

        case 'eliminar':
            $g = nominaPagoObtener($pdo, $cid, $gid);
            if ($g['estado'] !== 'anulado') throw new Exception("Primero anula el pago; solo se eliminan pagos anulados.");
            $pdo->prepare("DELETE FROM gastos WHERE id = ? AND cliente_id = ? AND estado = 'anulado'")->execute([$gid, $cid]);
            borrarAdjuntoGastoSiHuerfano($pdo, $g['archivo_adjunto']);
            echo json_encode(['success' => true, 'message' => 'Pago eliminado definitivamente.'], JSON_UNESCAPED_UNICODE);
            break;

        default:
            throw new Exception("Acción no válida.");
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    if ($e instanceof PDOException) error_log('nomina_pago_accion.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e instanceof PDOException ? 'Error de base de datos.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
