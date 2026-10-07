<?php

/**
 * includes/recibo_guardar.php
 * Guarda un recibo de cobro para contratos tipo sin_factura.
 * Ruta: clientes/naranjaymedia/includes/recibo_guardar.php
 */
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once '../../../includes/recibos.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    $cid = (int)(USUARIO_ROL === 'superadmin' ? ($_SESSION['cliente_seleccionado'] ?? 0) : CLIENTE_ID);
    if (!$cid) throw new Exception("Cliente no identificado.");

    $contrato_id = filter_input(INPUT_POST, 'contrato_id', FILTER_VALIDATE_INT);
    if (!$contrato_id) throw new Exception("Contrato inválido.");

    $pdo->beginTransaction();
    [$recId, $num] = reciboRegistrar($pdo, $cid, defined('USUARIO_ID') ? (int)USUARIO_ID : (int)($_SESSION['usuario_id'] ?? 0), $contrato_id, $_POST);
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'recibo_id' => $recId,
        'numero' => str_pad($num, 5, '0', STR_PAD_LEFT),
    ]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
