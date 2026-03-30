<?php
// clientes/naranjaymedia/includes/tarjeta_guardar.php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
header('Content-Type: application/json; charset=utf-8');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    $cid   = (int)(USUARIO_ROL === 'superadmin' ? ($_SESSION['cliente_seleccionado'] ?? 0) : CLIENTE_ID);
    if (!$cid) throw new Exception("Cliente no identificado.");

    $banco   = trim($_POST['banco']           ?? '');
    $tipo    = trim($_POST['tipo']            ?? 'visa');
    $digitos = trim($_POST['ultimos_digitos'] ?? '');
    $titular = trim($_POST['nombre_titular']  ?? '') ?: null;
    $notas   = trim($_POST['notas']           ?? '') ?: null;

    if (!$banco)   throw new Exception("El banco es obligatorio.");
    if (!preg_match('/^\d{4}$/', $digitos)) throw new Exception("Los últimos 4 dígitos deben ser exactamente 4 números.");
    if (!in_array($tipo, ['visa','mastercard','amex','debito','credito','otro'])) throw new Exception("Tipo inválido.");

    $pdo->prepare("INSERT INTO tarjetas (cliente_id, banco, tipo, ultimos_digitos, nombre_titular, notas)
                   VALUES (?,?,?,?,?,?)")->execute([$cid, $banco, $tipo, $digitos, $titular, $notas]);
    echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
