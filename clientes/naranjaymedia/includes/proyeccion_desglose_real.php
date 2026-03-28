<?php

/**
 * proyeccion_desglose_real.php
 * Devuelve desglose real de ingresos/egresos de un mes ya cerrado.
 * GET: anio, mes
 * Ruta: clientes/naranjaymedia/includes/proyeccion_desglose_real.php
 */
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';

header('Content-Type: application/json; charset=utf-8');

$cliente_id = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);

$anio = (int)($_GET['anio'] ?? 0);
$mes  = (int)($_GET['mes']  ?? 0);

if (!$anio || !$mes) {
    echo json_encode(['error' => 'Parámetros inválidos']);
    exit;
}

// Ingresos reales
$stmtI = $pdo->prepare("
    SELECT COALESCE(SUM(subtotal), 0) AS ing_total
    FROM facturas
    WHERE cliente_id=? AND estado='emitida'
      AND YEAR(fecha_emision)=? AND MONTH(fecha_emision)=?
");
$stmtI->execute([$cliente_id, $anio, $mes]);
$ing_total = (float)$stmtI->fetchColumn();

// Gastos reales del mes con categoría
$stmtG = $pdo->prepare("
    SELECT cg.nombre AS categoria, cg.color, g.tipo,
           COALESCE(g.descripcion, cg.nombre) AS descripcion,
           g.monto
    FROM gastos g
    INNER JOIN categorias_gastos cg ON cg.id = g.categoria_id
    WHERE g.cliente_id=? AND g.estado != 'anulado'
      AND YEAR(g.fecha)=? AND MONTH(g.fecha)=?
    ORDER BY g.monto DESC
    LIMIT 50
");
$stmtG->execute([$cliente_id, $anio, $mes]);
$gastos = $stmtG->fetchAll(PDO::FETCH_ASSOC);
$egr_total = array_sum(array_column($gastos, 'monto'));

echo json_encode([
    'ing_total' => $ing_total,
    'egr_total' => $egr_total,
    'flujo'     => $ing_total - $egr_total,
    'gastos'    => $gastos,
]);
