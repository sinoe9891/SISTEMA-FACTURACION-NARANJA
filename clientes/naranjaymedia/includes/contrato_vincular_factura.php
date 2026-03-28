<?php

/**
 * contrato_vincular_factura.php
 * Vincula una factura libre (contrato_id IS NULL) a este contrato.
 *
 * POST: factura_id, contrato_id, periodo_mes (opcional), periodo_anio (opcional)
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
    $periodo_mes  = isset($_POST['periodo_mes'])  && (int)$_POST['periodo_mes']  ? (int)$_POST['periodo_mes']  : null;
    $periodo_anio = isset($_POST['periodo_anio']) && (int)$_POST['periodo_anio'] ? (int)$_POST['periodo_anio'] : null;

    if (!$factura_id || !$contrato_id)
        throw new Exception("Datos incompletos (factura_id=$factura_id, contrato_id=$contrato_id).");
    if (!$cliente_id)
        throw new Exception("Sesión no válida. Recarga la página.");

    // Verificar que la factura pertenece al cliente y está libre
    $stmtF = $pdo->prepare("
        SELECT id, contrato_id, receptor_id, fecha_emision
        FROM facturas
        WHERE id = ? AND cliente_id = ? AND estado = 'emitida'
    ");
    $stmtF->execute([$factura_id, $cliente_id]);
    $factura = $stmtF->fetch(PDO::FETCH_ASSOC);

    if (!$factura)
        throw new Exception("Factura #$factura_id no encontrada (cliente=$cliente_id).");
    if ($factura['contrato_id'])
        throw new Exception("Esta factura ya está vinculada al contrato #{$factura['contrato_id']}. Desvincúlala primero.");

    // Verificar que el contrato pertenece al cliente
    $stmtC = $pdo->prepare("SELECT id FROM contratos WHERE id = ? AND cliente_id = ?");
    $stmtC->execute([$contrato_id, $cliente_id]);
    if (!$stmtC->fetchColumn())
        throw new Exception("Contrato #$contrato_id no encontrado.");

    // Si no se pasó periodo, usar fecha_emision como fallback
    if (!$periodo_mes || !$periodo_anio) {
        $dt = new DateTime($factura['fecha_emision']);
        $periodo_mes  = $periodo_mes  ?: (int)$dt->format('n');
        $periodo_anio = $periodo_anio ?: (int)$dt->format('Y');
    }

    // Vincular — sin verificar rowCount (puede fallar con algunos drivers de MySQL)
    $pdo->prepare("
        UPDATE facturas
        SET contrato_id  = ?,
            periodo_mes  = ?,
            periodo_anio = ?
        WHERE id = ? AND cliente_id = ?
    ")->execute([$contrato_id, $periodo_mes, $periodo_anio, $factura_id, $cliente_id]);

    // Verificar que quedó vinculado
    $stmtCheck = $pdo->prepare("SELECT contrato_id FROM facturas WHERE id = ? AND cliente_id = ?");
    $stmtCheck->execute([$factura_id, $cliente_id]);
    $nuevoContrato = $stmtCheck->fetchColumn();

    if ((int)$nuevoContrato !== $contrato_id)
        throw new Exception("El UPDATE no surtió efecto. Verifica permisos de BD.");

    echo json_encode([
        'success'      => true,
        'message'      => "Factura vinculada al contrato correctamente.",
        'periodo'      => "$periodo_mes/$periodo_anio",
        'contrato_id'  => $contrato_id,
        'fecha_emision' => $factura['fecha_emision'],
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
