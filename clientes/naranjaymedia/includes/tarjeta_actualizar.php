<?php
// clientes/naranjaymedia/includes/tarjeta_actualizar.php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
header('Content-Type: application/json; charset=utf-8');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    $cid = (int)(USUARIO_ROL === 'superadmin' ? ($_SESSION['cliente_seleccionado'] ?? 0) : CLIENTE_ID);
    $id  = filter_input(INPUT_POST, 'tarjeta_id', FILTER_VALIDATE_INT);
    if (!$id) throw new Exception("Tarjeta no identificada.");

    $stmtCk = $pdo->prepare("SELECT id FROM tarjetas WHERE id=? AND cliente_id=?");
    $stmtCk->execute([$id, $cid]);
    if (!$stmtCk->fetchColumn()) throw new Exception("Tarjeta no encontrada.");

    // Toggle activa/inactiva
    if (!empty($_POST['_toggle_activa'])) {
        $activa = (int)!($_POST['activa_actual'] ?? 0);
        $pdo->prepare("UPDATE tarjetas SET activa=? WHERE id=? AND cliente_id=?")->execute([$activa, $id, $cid]);
        echo json_encode(['success' => true]);
        exit;
    }

    $banco   = trim($_POST['banco']           ?? '');
    $tipo    = trim($_POST['tipo']            ?? 'visa');
    $digitos = trim($_POST['ultimos_digitos'] ?? '');
    $titular = trim($_POST['nombre_titular']  ?? '') ?: null;
    $notas   = trim($_POST['notas']           ?? '') ?: null;

    if (!$banco) throw new Exception("El banco es obligatorio.");
    if (!preg_match('/^\d{4}$/', $digitos)) throw new Exception("Los últimos 4 dígitos deben ser 4 números.");
    if (!in_array($tipo, ['visa','mastercard','amex','debito','credito','otro'])) throw new Exception("Tipo inválido.");

    $pdo->prepare("UPDATE tarjetas SET banco=?,tipo=?,ultimos_digitos=?,nombre_titular=?,notas=?
                   WHERE id=? AND cliente_id=?")->execute([$banco,$tipo,$digitos,$titular,$notas,$id,$cid]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
