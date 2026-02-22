<?php
/**
 * API: Contratos activos de un receptor
 * Ruta: ../../includes/api/contratos_por_receptor.php
 * GET: ?receptor_id=X&cliente_id=Y
 */
require_once '../db.php';
require_once '../session.php';

header('Content-Type: application/json; charset=utf-8');

$receptor_id = (int)($_GET['receptor_id'] ?? 0);
$cliente_id  = (int)($_GET['cliente_id']  ?? 0);

if (!$receptor_id || !$cliente_id) {
    echo json_encode([]);
    exit;
}

// Traer contratos con sus servicios (puede tener varios)
$stmt = $pdo->prepare("
    SELECT 
        c.id, c.nombre_contrato, c.monto, c.dia_pago, c.fecha_inicio, c.fecha_fin,
        GROUP_CONCAT(p.nombre ORDER BY p.nombre SEPARATOR ' + ') AS servicios_nombres,
        COUNT(cs.id) AS total_servicios
    FROM contratos c
    LEFT JOIN contratos_servicios cs ON cs.contrato_id = c.id
    LEFT JOIN productos_clientes   p  ON p.id = cs.producto_id
    WHERE c.receptor_id = ?
      AND c.cliente_id  = ?
      AND c.estado      = 'activo'
    GROUP BY c.id
    ORDER BY c.nombre_contrato ASC
");
$stmt->execute([$receptor_id, $cliente_id]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));