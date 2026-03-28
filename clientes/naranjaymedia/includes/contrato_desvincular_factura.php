<?php

/**
 * contrato_desvincular_factura.php
 * Desvincula una factura de su contrato (pone contrato_id = NULL).
 * La factura queda libre y puede vincularse a otro contrato.
 *
 * POST: factura_id, contrato_id (para verificar que pertenece al contrato correcto)
 * Ruta: clientes/[empresa]/includes/contrato_desvincular_factura.php
 */
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");

    $cliente_id  = (int)(USUARIO_ROL === 'superadmin'
        ? ($_SESSION['cliente_seleccionado'] ?? 0)
        : CLIENTE_ID);

    $factura_id  = (int)($_POST['factura_id']  ?? 0);
    $contrato_id = (int)($_POST['contrato_id'] ?? 0);

    if (!$factura_id || !$contrato_id)
        throw new Exception("Datos incompletos.");

    // Verificar que la factura pertenece al cliente y a este contrato
    $stmtF = $pdo->prepare("
        SELECT id, correlativo, contrato_id
        FROM facturas
        WHERE id = ? AND cliente_id = ? AND estado = 'emitida'
    ");
    $stmtF->execute([$factura_id, $cliente_id]);
    $factura = $stmtF->fetch(PDO::FETCH_ASSOC);

    if (!$factura)
        throw new Exception("Factura no encontrada.");
    if ((int)$factura['contrato_id'] !== $contrato_id)
        throw new Exception("Esta factura no pertenece a este contrato.");

    // Desvincular
    $pdo->prepare("
        UPDATE facturas SET contrato_id = NULL, periodo_mes = NULL, periodo_anio = NULL
        WHERE id = ? AND cliente_id = ? AND contrato_id = ?
    ")->execute([$factura_id, $cliente_id, $contrato_id]);

    echo json_encode([
        'success'     => true,
        'message'     => "Factura {$factura['correlativo']} desvinculada correctamente.",
        'correlativo' => $factura['correlativo'],
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
