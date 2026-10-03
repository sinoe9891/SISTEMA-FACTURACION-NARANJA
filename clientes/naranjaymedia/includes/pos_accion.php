<?php
// clientes/naranjaymedia/includes/pos_accion.php
// Punto de venta. GET ?resumen=turno_id (corte X); POST accion=abrir|vender|movimiento|cerrar
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once '../../../includes/pos.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if (!posDisponible($pdo)) throw new Exception("El punto de venta no está instalado (faltan las migraciones de inventario y POS).");
    if (!in_array(USUARIO_ROL, POS_ROLES_VENTA, true)) throw new Exception("No tienes permiso para usar el punto de venta.");
    $cid = cliente_actual();
    if (!$cid) throw new Exception("Empresa no identificada.");
    $uid = (int)USUARIO_ID;

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $tid = (int)($_GET['resumen'] ?? 0);
        $t = posTurno($pdo, $cid, $tid);
        if ((int)$t['usuario_id'] !== $uid && !in_array(USUARIO_ROL, ['admin', 'superadmin'], true)) throw new Exception("No puedes ver el corte de otro cajero.");
        $r = posResumen($pdo, $cid, $tid);
        // Arqueo ciego: al cajero no se le muestra el efectivo esperado mientras el turno está abierto
        if ($t['estado'] === 'abierto' && !in_array(USUARIO_ROL, ['admin', 'superadmin'], true)) unset($r['efectivo_esperado']);
        echo json_encode(['success' => true] + $r, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");

    $accion = $_POST['accion'] ?? '';
    $pdo->beginTransaction();
    switch ($accion) {
        case 'abrir':
            $eid = (int)($_SESSION['establecimiento_activo'] ?? 0);
            $id = posAbrirTurno($pdo, $cid, $uid, $eid, (int)($_POST['punto_emision_id'] ?? 0), $_POST['monto_inicial'] ?? 0);
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Caja abierta.', 'turno_id' => $id]);
            break;

        case 'vender':
            $v = posVender($pdo, $cid, $uid, $_POST);
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => $v['repetida'] ? 'La venta ya estaba registrada.' : 'Venta registrada.'] + $v, JSON_UNESCAPED_UNICODE);
            break;

        case 'movimiento':
            $id = posMovimientoCaja($pdo, $cid, $uid, USUARIO_ROL, $_POST);
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => ($_POST['tipo'] ?? '') === 'retiro' ? 'Retiro registrado.' : 'Entrada registrada.', 'id' => $id]);
            break;

        case 'cerrar':
            $c = posCerrarTurno($pdo, $cid, $uid, USUARIO_ROL, $_POST);
            $pdo->commit();
            $txt = abs($c['diferencia']) < 0.005 ? 'Caja cuadrada.' : ('Diferencia: L ' . number_format($c['diferencia'], 2) . ($c['diferencia'] > 0 ? ' (sobrante)' : ' (faltante)'));
            echo json_encode(['success' => true, 'message' => 'Caja cerrada. ' . $txt] + $c, JSON_UNESCAPED_UNICODE);
            break;

        default:
            throw new Exception("Acción no válida.");
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    if ($e instanceof PDOException || $e instanceof LogicException) error_log('pos_accion.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => ($e instanceof PDOException) ? 'Error de base de datos.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
