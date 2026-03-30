<?php

/**
 * includes/recibo_guardar.php
 * Guarda un recibo de cobro para contratos tipo sin_factura.
 * Ruta: clientes/naranjaymedia/includes/recibo_guardar.php
 */
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    $cid = (int)(USUARIO_ROL === 'superadmin' ? ($_SESSION['cliente_seleccionado'] ?? 0) : CLIENTE_ID);
    if (!$cid) throw new Exception("Cliente no identificado.");

    $contrato_id   = filter_input(INPUT_POST, 'contrato_id',  FILTER_VALIDATE_INT);
    $receptor_id   = filter_input(INPUT_POST, 'receptor_id',  FILTER_VALIDATE_INT);
    $monto         = (float)($_POST['monto'] ?? 0);
    $fecha         = trim($_POST['fecha_emision'] ?? '');
    $periodo_mes   = (int)($_POST['periodo_mes']  ?? 0);
    $periodo_anio  = (int)($_POST['periodo_anio'] ?? 0);
    $descripcion   = trim($_POST['descripcion']   ?? '');
    $metodo_pago   = trim($_POST['metodo_pago']   ?? 'transferencia');
    $notas         = trim($_POST['notas']         ?? '') ?: null;

    if (!$contrato_id) throw new Exception("Contrato inválido.");
    if ($monto <= 0)   throw new Exception("El monto debe ser mayor a 0.");
    if (!$fecha)       throw new Exception("La fecha es obligatoria.");
    if (!$descripcion) throw new Exception("La descripción es obligatoria.");
    if ($periodo_mes < 1 || $periodo_mes > 12) throw new Exception("Mes inválido.");
    if ($periodo_anio < 2020) throw new Exception("Año inválido.");

    // Verificar que el contrato pertenece al cliente y es sin_factura
    $stmtCk = $pdo->prepare("SELECT id FROM contratos WHERE id=? AND cliente_id=? AND tipo_contrato='sin_factura'");
    $stmtCk->execute([$contrato_id, $cid]);
    if (!$stmtCk->fetchColumn()) throw new Exception("Contrato no encontrado.");

    $pdo->beginTransaction();

    // Obtener próximo número (con lock)
    $stmtN = $pdo->prepare("SELECT COALESCE(MAX(numero_recibo),0)+1 FROM contratos_recibos WHERE cliente_id=?");
    $stmtN->execute([$cid]);
    $num = (int)$stmtN->fetchColumn();

    // Detectar si la columna se llama 'concepto' o 'descripcion'
    $colCheck = $pdo->query("SHOW COLUMNS FROM contratos_recibos LIKE 'concepto'")->fetch();
    $colDesc  = $colCheck ? 'concepto' : 'descripcion';

    $stmtIns = $pdo->prepare("
        INSERT INTO contratos_recibos
            (cliente_id, contrato_id, receptor_id, numero_recibo,
             {$colDesc}, monto, fecha_emision, periodo_mes, periodo_anio,
             metodo_pago, notas, estado, usuario_id)
        VALUES (?,?,?,?, ?,?,?,?,?, ?,?,'emitido',?)
    ");
    $stmtIns->execute([
        $cid,
        $contrato_id,
        $receptor_id,
        $num,
        $descripcion,
        $monto,
        $fecha,
        $periodo_mes,
        $periodo_anio,
        $metodo_pago,
        $notas,
        defined('USUARIO_ID') ? (int)USUARIO_ID : (int)($_SESSION['usuario_id'] ?? 0)
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'recibo_id' => (int)$pdo->lastInsertId(),
        'numero' => str_pad($num, 5, '0', STR_PAD_LEFT),
    ]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
