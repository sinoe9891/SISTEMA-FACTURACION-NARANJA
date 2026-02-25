<?php
/**
 * contrato_actualizar.php — actualiza contrato con multi-servicios
 * Ruta: clientes/naranjaymedia/includes/contrato_actualizar.php
 */
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");

    $cliente_id = (int)(USUARIO_ROL === 'superadmin'
        ? ($_SESSION['cliente_seleccionado'] ?? 0)
        : CLIENTE_ID);

    $contrato_id     = (int)($_POST['id']              ?? 0);
    $receptor_id     = (int)($_POST['receptor_id']     ?? 0);
    $nombre_contrato = trim($_POST['nombre_contrato']  ?? '');
    $fecha_inicio    = trim($_POST['fecha_inicio']     ?? '');
    $fecha_fin       = trim($_POST['fecha_fin']        ?? '') ?: null;
    $dia_pago        = (int)($_POST['dia_pago']        ?? 1);
    $estado          = trim($_POST['estado']           ?? 'activo');
    $notas           = trim($_POST['notas']            ?? '');
    $servicios       = $_POST['servicios']             ?? [];

    // ── Validaciones ──────────────────────────────────────────────────────────
    if (!$contrato_id)     throw new Exception("Contrato no identificado.");
    if (!$receptor_id)     throw new Exception("Selecciona un cliente.");
    if (!$nombre_contrato) throw new Exception("El nombre del contrato es obligatorio.");
    if (!$fecha_inicio)    throw new Exception("La fecha de inicio es obligatoria.");
    if (empty($servicios)) throw new Exception("Agrega al menos un servicio al contrato.");
    if ($dia_pago < 1 || $dia_pago > 31) throw new Exception("Día de pago inválido.");
    if ($fecha_fin && $fecha_fin < $fecha_inicio) throw new Exception("La fecha fin no puede ser anterior al inicio.");
    if (!in_array($estado, ['activo','pausado','cancelado','vencido'])) throw new Exception("Estado inválido.");

    // Verificar propiedad
    $stmtV = $pdo->prepare("SELECT id FROM contratos WHERE id = ? AND cliente_id = ?");
    $stmtV->execute([$contrato_id, $cliente_id]);
    if (!$stmtV->fetchColumn()) throw new Exception("Contrato no encontrado o sin permiso.");

    // Validar servicios
    $serviciosValidos = [];
    foreach ($servicios as $s) {
        $prod_id = (int)($s['producto_id'] ?? 0);
        $monto   = (float)($s['monto']     ?? 0);
        if (!$prod_id || $monto <= 0) throw new Exception("Todos los servicios deben tener producto y monto válido.");

        $stmtProd = $pdo->prepare("SELECT id FROM productos_clientes WHERE id = ? AND cliente_id = ?");
        $stmtProd->execute([$prod_id, $cliente_id]);
        if (!$stmtProd->fetchColumn()) throw new Exception("Producto inválido: ID $prod_id");

        $serviciosValidos[] = ['producto_id' => $prod_id, 'monto' => $monto];
    }

    $monto_total        = array_sum(array_column($serviciosValidos, 'monto'));
    $primer_producto_id = $serviciosValidos[0]['producto_id'];

    // ── Transacción ───────────────────────────────────────────────────────────
    $pdo->beginTransaction();

    // Actualizar tabla principal
    $pdo->prepare("
        UPDATE contratos SET
            receptor_id     = ?,
            nombre_contrato = ?,
            producto_id     = ?,
            monto           = ?,
            fecha_inicio    = ?,
            fecha_fin       = ?,
            dia_pago        = ?,
            estado          = ?,
            notas           = ?
        WHERE id = ? AND cliente_id = ?
    ")->execute([
        $receptor_id,
        $nombre_contrato,
        $primer_producto_id,
        $monto_total,
        $fecha_inicio,
        $fecha_fin,
        $dia_pago,
        $estado,
        $notas,
        $contrato_id,
        $cliente_id,
    ]);

    // Borrar servicios anteriores y reinsertar (replace completo)
    $pdo->prepare("DELETE FROM contratos_servicios WHERE contrato_id = ?")
        ->execute([$contrato_id]);

    $stmtSvc = $pdo->prepare("
        INSERT INTO contratos_servicios (contrato_id, producto_id, monto)
        VALUES (?, ?, ?)
    ");
    foreach ($serviciosValidos as $svc) {
        $stmtSvc->execute([$contrato_id, $svc['producto_id'], $svc['monto']]);
    }

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Contrato actualizado correctamente.']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}