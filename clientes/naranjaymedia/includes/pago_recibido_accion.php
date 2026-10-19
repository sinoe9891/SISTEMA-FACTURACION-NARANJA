<?php
// clientes/naranjaymedia/includes/pago_recibido_accion.php — Pagos recibidos (ver includes/pagos_recibidos.php).
//   GET  ?ver=<tipo>&id=<id>                      → datos del pago para editarlo (JSON)
//   POST accion=editar tipo, id, fecha, metodo, referencia, notas, cuenta_id, comprobante (archivo), quitar_comprobante
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once '../../../includes/pagos_recibidos.php';
require_once __DIR__ . '/_gasto_adjuntos.php';
header('Content-Type: application/json; charset=utf-8');

$adj = null;
try {
    if (!pagosRecibidosDisponible($pdo)) throw new Exception("Falta instalar sql/migraciones/2026-10-19_pagos_recibidos.sql.");
    $cid = (int)cliente_actual();
    if (!$cid) throw new Exception("Empresa no identificada.");
    $tipo = (string)($_REQUEST['tipo'] ?? '');
    $id = (int)($_REQUEST['id'] ?? $_GET['ver'] ?? 0);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $tipo = (string)($_GET['ver'] ?? $tipo);
        $mp = pagoRecibidoMapa($tipo);
        $p = pagoRecibidoObtener($pdo, $cid, $tipo, (int)($_GET['id'] ?? 0));
        echo json_encode(['success' => true, 'pago' => [
            'tipo' => $tipo, 'id' => (int)$p['id'], 'fecha' => substr((string)$p[$mp['fecha']], 0, 10), 'monto' => (float)$p['monto'],
            'metodo' => $p[$mp['metodo']], 'referencia' => $mp['referencia'] ? (string)$p[$mp['referencia']] : null, 'notas' => (string)$p[$mp['notas']],
            'cuenta_id' => (int)$p['cuenta_id'] ?: '', 'cuenta' => (function () use ($pdo, $p) { if (!(int)$p['cuenta_id']) return ''; $st = $pdo->prepare("SELECT CONCAT(banco, ' ', numero) FROM cuentas_bancarias WHERE id = ?"); $st->execute([(int)$p['cuenta_id']]); return (string)$st->fetchColumn(); })(), 'archivo' => $p['archivo_adjunto'] ? (string)$p['archivo_nombre'] : '', 'anulado' => (int)$p['esta_anulado'],
            'documento' => pagoRecibidoDocumento($pdo, $tipo, $p),
        ]], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!in_array(USUARIO_ROL, ['admin', 'superadmin', 'facturador'], true)) throw new Exception("Tu rol solo puede consultar los pagos.");
    if (($_POST['accion'] ?? '') !== 'editar') throw new Exception("Acción no válida.");
    $adj = guardarAdjuntoGasto($_FILES['comprobante'] ?? null, $cid);
    $pdo->beginTransaction();
    pagoRecibidoEditar($pdo, $cid, (int)USUARIO_ID, $tipo, $id, $_POST, $adj);
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Pago actualizado.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if ($adj) @unlink(gastoAdjuntoDir() . $adj[0]);
    http_response_code(400);
    if ($e instanceof PDOException) error_log('pago_recibido_accion.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e instanceof PDOException ? 'Error de base de datos.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
