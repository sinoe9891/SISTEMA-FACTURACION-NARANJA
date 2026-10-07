<?php
// clientes/naranjaymedia/includes/gasto_eliminar.php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once __DIR__ . '/_gasto_adjuntos.php';
require_once '../../../includes/bancos.php';
header('Content-Type: application/json; charset=utf-8');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    $cid      = (int)(USUARIO_ROL === 'superadmin' ? ($_SESSION['cliente_seleccionado'] ?? 0) : CLIENTE_ID);
    $gasto_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $accion   = trim($_POST['accion'] ?? 'anular');
    if (!$gasto_id) throw new Exception("Gasto no identificado.");

    $sv = $pdo->prepare("SELECT estado, archivo_adjunto FROM gastos WHERE id=? AND cliente_id=?");
    $sv->execute([$gasto_id, $cid]);
    $gasto = $sv->fetch(PDO::FETCH_ASSOC);
    if (!$gasto) throw new Exception("Gasto no encontrado.");

    // Si el pago salió de un banco, también se anula ese movimiento (salvo que ya esté conciliado)
    if (bancosDisponible($pdo)) {
        $conc = $pdo->prepare("SELECT COUNT(*) FROM movimientos_bancarios WHERE gasto_id=? AND cliente_id=? AND anulado=0 AND conciliado=1");
        $conc->execute([$gasto_id, $cid]);
        if ($conc->fetchColumn()) throw new Exception("El pago de este gasto ya está conciliado en el banco: quita la conciliación antes de anularlo.");
        $pdo->prepare("UPDATE movimientos_bancarios SET anulado=1 WHERE gasto_id=? AND cliente_id=?")->execute([$gasto_id, $cid]);
    }

    if ($accion === 'eliminar' && in_array(USUARIO_ROL, ['admin','superadmin'])) {
        $pdo->prepare("DELETE FROM gastos WHERE id=? AND cliente_id=?")->execute([$gasto_id, $cid]);
        // El comprobante se borra solo si ningún otro gasto lo usa (anular lo conserva)
        borrarAdjuntoGastoSiHuerfano($pdo, $gasto['archivo_adjunto']);
        echo json_encode(['success' => true, 'message' => 'Gasto eliminado.']);
    } else {
        $pdo->prepare("UPDATE gastos SET estado='anulado' WHERE id=? AND cliente_id=?")->execute([$gasto_id, $cid]);
        echo json_encode(['success' => true, 'message' => 'Gasto anulado.']);
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
