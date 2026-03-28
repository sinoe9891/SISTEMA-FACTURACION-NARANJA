<?php

/**
 * contrato_actualizar_periodo.php
 * Cambia el periodo_mes / periodo_anio de una factura vinculada.
 * No toca fecha_emision.
 *
 * POST: factura_id, contrato_id, periodo_mes, periodo_anio
 * Ruta: clientes/[empresa]/includes/contrato_actualizar_periodo.php
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
    $periodo_mes = (int)($_POST['periodo_mes'] ?? 0);
    $periodo_anio = (int)($_POST['periodo_anio'] ?? 0);

    if (!$factura_id || !$contrato_id)      throw new Exception("Datos incompletos.");
    if ($periodo_mes < 1 || $periodo_mes > 12) throw new Exception("Mes inválido.");
    if ($periodo_anio < 2020 || $periodo_anio > 2099) throw new Exception("Año inválido.");

    // Verificar que la factura pertenece a este contrato y cliente
    $stmtF = $pdo->prepare("
        SELECT id, correlativo, periodo_mes, periodo_anio
        FROM facturas
        WHERE id = ? AND cliente_id = ? AND contrato_id = ? AND estado = 'emitida'
    ");
    $stmtF->execute([$factura_id, $cliente_id, $contrato_id]);
    $factura = $stmtF->fetch(PDO::FETCH_ASSOC);
    if (!$factura) throw new Exception("Factura no encontrada o no pertenece a este contrato.");

    $pdo->prepare("
        UPDATE facturas SET periodo_mes = ?, periodo_anio = ?
        WHERE id = ? AND cliente_id = ?
    ")->execute([$periodo_mes, $periodo_anio, $factura_id, $cliente_id]);

    $meses = [
        '',
        'Enero',
        'Febrero',
        'Marzo',
        'Abril',
        'Mayo',
        'Junio',
        'Julio',
        'Agosto',
        'Septiembre',
        'Octubre',
        'Noviembre',
        'Diciembre'
    ];

    echo json_encode([
        'success'  => true,
        'message'  => "Período actualizado a {$meses[$periodo_mes]} {$periodo_anio}.",
        'periodo'  => "{$meses[$periodo_mes]} {$periodo_anio}",
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
