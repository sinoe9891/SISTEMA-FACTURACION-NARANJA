<?php
/**
 * contrato_guardar.php — guarda contratos con uno o múltiples servicios
 * Ruta: clientes/[empresa]/includes/contrato_guardar.php
 */
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once '../../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");

    $cliente_id = (int)(USUARIO_ROL === 'superadmin'
        ? ($_SESSION['cliente_seleccionado'] ?? 0)
        : CLIENTE_ID);

    // ── Datos generales ───────────────────────────────────────────────────────
    $receptor_id     = (int)($_POST['receptor_id']     ?? 0);
    $nombre_contrato = trim($_POST['nombre_contrato']  ?? '');
    $fecha_inicio    = trim($_POST['fecha_inicio']     ?? '');
    $fecha_fin       = trim($_POST['fecha_fin']        ?? '') ?: null;
    $dia_pago        = (int)($_POST['dia_pago']        ?? 1);
    $notas           = trim($_POST['notas']            ?? '');
    $monto_total     = (float)($_POST['monto_total']   ?? 0);
    $servicios       = $_POST['servicios']             ?? [];

    // ── Validaciones ──────────────────────────────────────────────────────────
    if (!$receptor_id)                throw new Exception("Selecciona un cliente.");
    if (!$nombre_contrato)            throw new Exception("El nombre del contrato es obligatorio.");
    if (!$fecha_inicio)               throw new Exception("La fecha de inicio es obligatoria.");
    if (empty($servicios))            throw new Exception("Agrega al menos un servicio al contrato.");
    if ($dia_pago < 1 || $dia_pago > 31) throw new Exception("Día de pago inválido.");
    if ($fecha_fin && $fecha_fin < $fecha_inicio) throw new Exception("La fecha fin no puede ser anterior al inicio.");

    // Validar servicios
    $serviciosValidos = [];
    foreach ($servicios as $s) {
        $prod_id = (int)($s['producto_id'] ?? 0);
        $monto   = (float)($s['monto']     ?? 0);
        if (!$prod_id || $monto <= 0) throw new Exception("Todos los servicios deben tener producto y monto válido.");

        // Verificar que el producto pertenece al cliente
        $stmtV = $pdo->prepare("SELECT id FROM productos_clientes WHERE id = ? AND cliente_id = ?");
        $stmtV->execute([$prod_id, $cliente_id]);
        if (!$stmtV->fetchColumn()) throw new Exception("Producto inválido: $prod_id");

        $serviciosValidos[] = ['producto_id' => $prod_id, 'monto' => $monto];
    }

    // Recalcular monto total por seguridad
    $monto_total = array_sum(array_column($serviciosValidos, 'monto'));

    // Primer producto como referencia principal (para compatibilidad con vista de lista)
    $primer_producto_id = $serviciosValidos[0]['producto_id'];

    // ── Transacción ───────────────────────────────────────────────────────────
    $pdo->beginTransaction();

    $stmtIns = $pdo->prepare("
        INSERT INTO contratos (
            cliente_id, receptor_id, nombre_contrato,
            producto_id, monto, fecha_inicio, fecha_fin,
            dia_pago, notas, estado
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'activo')
    ");
    $stmtIns->execute([
        $cliente_id,
        $receptor_id,
        $nombre_contrato,
        $primer_producto_id,
        $monto_total,
        $fecha_inicio,
        $fecha_fin,
        $dia_pago,
        $notas,
    ]);
    $contrato_id = $pdo->lastInsertId();

    // Insertar servicios en la tabla pivote
    $stmtSvc = $pdo->prepare("
        INSERT INTO contratos_servicios (contrato_id, producto_id, monto)
        VALUES (?, ?, ?)
    ");
    foreach ($serviciosValidos as $svc) {
        $stmtSvc->execute([$contrato_id, $svc['producto_id'], $svc['monto']]);
    }

    $pdo->commit();

    echo json_encode(['success' => true, 'contrato_id' => $contrato_id]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}