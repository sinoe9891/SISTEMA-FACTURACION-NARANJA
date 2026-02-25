<?php
// clientes/naranjaymedia/includes/prestamo_editar.php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';

header('Content-Type: application/json');

$cliente_id = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);

$prestamo_id  = (int)($_POST['prestamo_id']   ?? 0);
$descripcion  = trim($_POST['descripcion']    ?? '');
$fecha        = trim($_POST['fecha']          ?? '');
$notas        = trim($_POST['notas']          ?? '');
$descuento_auto = isset($_POST['descuento_auto']) ? 1 : 0;
$nuevo_estado = trim($_POST['estado']         ?? '');

if (!$prestamo_id || empty($descripcion) || empty($fecha)) {
    echo json_encode(['success' => false, 'error' => 'Datos incompletos.']);
    exit;
}

$estados_validos = ['activo', 'pagado', 'cancelado'];
if (!in_array($nuevo_estado, $estados_validos)) {
    echo json_encode(['success' => false, 'error' => 'Estado no válido.']);
    exit;
}

// Cargar préstamo actual
$stmtCheck = $pdo->prepare("
    SELECT p.*, COUNT(c.id) AS total_cuotas,
           SUM(CASE WHEN c.estado = 'pagado' THEN 1 ELSE 0 END) AS cuotas_pagadas,
           SUM(CASE WHEN c.estado = 'pagado' THEN c.monto ELSE 0 END) AS monto_ya_pagado
    FROM colaborador_prestamos p
    LEFT JOIN colaborador_prestamo_cuotas c ON c.prestamo_id = p.id
    WHERE p.id = ? AND p.cliente_id = ?
    GROUP BY p.id
");
$stmtCheck->execute([$prestamo_id, $cliente_id]);
$pr = $stmtCheck->fetch(PDO::FETCH_ASSOC);

if (!$pr) {
    echo json_encode(['success' => false, 'error' => 'Préstamo no encontrado.']);
    exit;
}

$estado_actual = $pr['estado'];

// Validar transiciones de estado permitidas
// cancelado→activo NO permitido (datos inconsistentes)
// pagado→activo NO permitido (podría romper saldos)
if ($estado_actual === 'cancelado' && $nuevo_estado !== 'cancelado') {
    echo json_encode(['success' => false, 'error' => 'No se puede reactivar un préstamo cancelado.']);
    exit;
}
if ($estado_actual === 'pagado' && $nuevo_estado === 'activo') {
    echo json_encode(['success' => false, 'error' => 'No se puede reactivar un préstamo ya pagado. Use "Editar Cuota" para revertir pagos específicos.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // ── Efectos según cambio de estado ────────────────────────────────────────
    if ($nuevo_estado !== $estado_actual) {

        if ($nuevo_estado === 'cancelado') {
            // Cancelar cuotas pendientes
            $pdo->prepare("
                UPDATE colaborador_prestamo_cuotas
                SET estado = 'cancelado'
                WHERE prestamo_id = ? AND estado = 'pendiente'
            ")->execute([$prestamo_id]);

            // Saldo = 0
            $pdo->prepare("
                UPDATE colaborador_prestamos
                SET saldo_pendiente = 0
                WHERE id = ?
            ")->execute([$prestamo_id]);
        }

        elseif ($nuevo_estado === 'pagado') {
            // Marcar todas las cuotas pendientes como pagadas (sin fecha_pago ni método,
            // es un cierre manual/forzado — se registra con notas)
            $pdo->prepare("
                UPDATE colaborador_prestamo_cuotas
                SET estado = 'pagado',
                    fecha_pago  = CURDATE(),
                    metodo_pago = 'otro',
                    notas       = 'Marcado como pagado manualmente'
                WHERE prestamo_id = ? AND estado = 'pendiente'
            ")->execute([$prestamo_id]);

            // Saldo = 0
            $pdo->prepare("
                UPDATE colaborador_prestamos
                SET saldo_pendiente = 0
                WHERE id = ?
            ")->execute([$prestamo_id]);
        }
    }

    // ── Actualizar campos editables ───────────────────────────────────────────
    $stmtUpd = $pdo->prepare("
        UPDATE colaborador_prestamos
        SET descripcion   = ?,
            fecha         = ?,
            notas         = ?,
            descuento_auto = ?,
            estado        = ?
        WHERE id = ? AND cliente_id = ?
    ");
    $stmtUpd->execute([
        $descripcion, $fecha, $notas, $descuento_auto,
        $nuevo_estado, $prestamo_id, $cliente_id
    ]);

    $pdo->commit();

    $msgs_estado = [
        'cancelado' => 'Préstamo cancelado y cuotas pendientes cerradas.',
        'pagado'    => 'Préstamo marcado como pagado. Cuotas pendientes cerradas.',
        'activo'    => 'Préstamo actualizado.',
    ];

    echo json_encode([
        'success' => true,
        'message' => $msgs_estado[$nuevo_estado] ?? 'Préstamo actualizado.',
        'estado_cambio' => ($nuevo_estado !== $estado_actual),
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
