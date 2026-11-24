<?php
// clientes/naranjaymedia/includes/comprobante_accion.php — Adjuntar, cambiar o quitar el comprobante de un cheque, préstamo recibido o activo fijo.
//   POST tipo (cheque|prestamo|activo), id, comprobante (archivo) o quitar_comprobante=1
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once '../../../includes/comprobantes.php';
require_once __DIR__ . '/_gasto_adjuntos.php';
header('Content-Type: application/json; charset=utf-8');

$adj = null;
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    if (!comprobanteDisponible($pdo)) throw new Exception("Falta instalar sql/migraciones/2026-11-23_comprobantes_cheques_prestamos_activos.sql.");
    $cid = (int)cliente_actual();
    if (!$cid) throw new Exception("Empresa no identificada.");
    $tipo = (string)($_POST['tipo'] ?? '');
    if (!isset(COMPROBANTE_TABLAS[$tipo])) throw new Exception("Tipo inválido.");
    if (!comprobantePuede($tipo, (string)USUARIO_ROL)) throw new Exception("Tu rol no puede cambiar este comprobante.");
    $id = (int)($_POST['id'] ?? 0);
    $quitar = !empty($_POST['quitar_comprobante']);
    $adj = guardarAdjuntoGasto($_FILES['comprobante'] ?? null, $cid);
    if (!$adj && !$quitar) throw new Exception("Elige un archivo.");
    $pdo->beginTransaction();
    comprobanteAsignar($pdo, $cid, $tipo, $id, $adj, $quitar);
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => $adj ? 'Comprobante guardado.' : 'Comprobante quitado.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if ($adj) @unlink(gastoAdjuntoDir() . $adj[0]);
    http_response_code(400);
    if ($e instanceof PDOException) error_log('comprobante_accion.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e instanceof PDOException ? 'Error de base de datos.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
