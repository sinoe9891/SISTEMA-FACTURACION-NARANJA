<?php
// clientes/naranjaymedia/includes/colaborador_salario.php — Historial de sueldo (admin, superadmin y Nómina y gastos).
//   POST accion=guardar  colaborador_id, desde, salario_base, puesto, motivo (con id: edita ese ajuste)
//   POST accion=eliminar id
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once '../../../includes/salarios.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    if (!puedeNomina()) throw new Exception("No tienes permiso para cambiar sueldos.");
    if (!salariosDisponible($pdo)) throw new Exception("Falta instalar sql/migraciones/2026-10-08_activos_prestamos.sql.");
    $cid = (int)cliente_actual();
    $pdo->beginTransaction();
    if (($_POST['accion'] ?? '') === 'guardar' && (int)($_POST['id'] ?? 0)) {
        salarioEditar($pdo, $cid, (int)$_POST['id'], (string)($_POST['desde'] ?? ''), (float)($_POST['salario_base'] ?? -1), (string)($_POST['motivo'] ?? ''), (string)($_POST['puesto'] ?? ''));
        $msg = 'Ajuste actualizado.';
    } elseif (($_POST['accion'] ?? '') === 'guardar') {
        salarioRegistrar($pdo, $cid, (int)($_POST['colaborador_id'] ?? 0), (string)($_POST['desde'] ?? ''), (float)($_POST['salario_base'] ?? -1), (string)($_POST['motivo'] ?? ''), (int)USUARIO_ID, (string)($_POST['puesto'] ?? ''));
        $msg = 'Ajuste registrado.';
    } elseif (($_POST['accion'] ?? '') === 'eliminar') {
        $st = $pdo->prepare("SELECT colaborador_id FROM colaborador_salarios WHERE id = ? AND cliente_id = ?");
        $st->execute([(int)($_POST['id'] ?? 0), $cid]);
        $colId = (int)$st->fetchColumn();
        if (!$colId) throw new Exception("Ajuste no encontrado.");
        $pdo->prepare("DELETE FROM colaborador_salarios WHERE id = ? AND cliente_id = ?")->execute([(int)$_POST['id'], $cid]);
        salarioSincronizar($pdo, $cid, $colId);
        $msg = 'Ajuste quitado.';
    } else throw new Exception("Acción no válida.");
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => $msg], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
