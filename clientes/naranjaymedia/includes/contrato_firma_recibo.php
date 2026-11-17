<?php
// clientes/naranjaymedia/includes/contrato_firma_recibo.php — Quién firma los recibos de un contrato.
//   POST contrato_id, firma = '' (el predeterminado de Configuración → Firmas) | 'vobo' | 'autorizado'
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once '../../../includes/firmantes.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    if (!in_array(USUARIO_ROL, ['admin', 'superadmin', 'facturador'], true)) throw new Exception("No tienes permiso para administrar contratos.");
    if (!firmaRecibosDisponible($pdo)) throw new Exception("Falta instalar sql/migraciones/2026-11-17_firma_recibos.sql.");
    $cid = (int)cliente_actual();
    $contratoId = (int)($_POST['contrato_id'] ?? 0);
    $firma = (string)($_POST['firma'] ?? '');
    if ($firma !== '' && !isset(FIRMANTES_RECIBO_ROLES[$firma])) throw new Exception("Firmante no válido.");
    $u = $pdo->prepare("UPDATE contratos SET firma_recibo = ? WHERE id = ? AND cliente_id = ?");
    $u->execute([$firma ?: null, $contratoId, $cid]);
    $st = $pdo->prepare("SELECT COUNT(*) FROM contratos WHERE id = ? AND cliente_id = ?");
    $st->execute([$contratoId, $cid]);
    if (!(int)$st->fetchColumn()) throw new Exception("Contrato no encontrado.");
    $f = firmantesLista($pdo, $cid)[firmaReciboRol($pdo, $cid, $contratoId)];
    echo json_encode(['success' => true, 'message' => 'Los recibos de este contrato los firma ' . ($f['nombre'] ?: 'el firmante configurado') . '.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e instanceof PDOException ? 'Error de base de datos.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
