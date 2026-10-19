<?php
// clientes/naranjaymedia/includes/programa_accion.php — Programas de un cliente (ver includes/programas.php).
//   GET  ?receptor_id=<id>                              → programas del cliente con cuántos contratos y facturas tienen
//   POST accion=guardar (id?, receptor_id, nombre, notas, activo)
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once '../../../includes/programas.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if (!programasDisponible($pdo)) throw new Exception("Falta instalar sql/migraciones/2026-10-19_programas.sql.");
    $cid = (int)cliente_actual();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $st = $pdo->prepare("SELECT p.*, (SELECT COUNT(*) FROM contratos c WHERE c.programa_id = p.id) AS contratos,
                                    (SELECT COUNT(*) FROM facturas f WHERE f.programa_id = p.id AND f.estado = 'emitida') AS facturas
                             FROM programas p WHERE p.cliente_id = ? AND p.receptor_id = ? ORDER BY p.activo DESC, p.nombre");
        $st->execute([$cid, (int)($_GET['receptor_id'] ?? 0)]);
        echo json_encode(['success' => true, 'programas' => $st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!in_array(USUARIO_ROL, ['admin', 'superadmin'], true)) throw new Exception("Solo un administrador maneja los programas.");
    if (($_POST['accion'] ?? '') !== 'guardar') throw new Exception("Acción no válida.");
    $id = programaGuardar($pdo, $cid, $_POST);
    echo json_encode(['success' => true, 'id' => $id, 'message' => 'Programa guardado.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e instanceof PDOException ? 'Error de base de datos.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
