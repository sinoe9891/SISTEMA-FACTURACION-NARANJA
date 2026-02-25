<?php
// clientes/naranjaymedia/includes/prestamo_cuota_editar.php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';

header('Content-Type: application/json');

$cliente_id    = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);

$cuota_id      = (int)($_POST['cuota_id']      ?? 0);
$fecha_esperada = trim($_POST['fecha_esperada'] ?? '');
$fecha_pago    = trim($_POST['fecha_pago']      ?? '') ?: null;
$metodo_pago   = trim($_POST['metodo_pago']     ?? '') ?: null;
$notas         = trim($_POST['notas']           ?? '');
$nuevo_estado  = trim($_POST['estado']          ?? '');

if (!$cuota_id || empty($fecha_esperada) || !in_array($nuevo_estado, ['pendiente', 'pagado', 'cancelado'])) {
    echo json_encode(['success' => false, 'error' => 'Datos incompletos o inválidos.']);
    exit;
}

// Si se marca como pagado, fecha_pago es obligatoria
if ($nuevo_estado === 'pagado' && empty($fecha_pago)) {
    $fecha_pago = date('Y-m-d');
}

// Cargar cuota + préstamo
$stmtC = $pdo->prepare("
    SELECT c.*, p.saldo_pendiente, p.monto_total, p.estado AS prestamo_estado, p.tipo
    FROM colaborador_prestamo_cuotas c
    JOIN colaborador_prestamos p ON p.id = c.prestamo_id
    WHERE c.id = ? AND c.cliente_id = ?
");
$stmtC->execute([$cuota_id, $cliente_id]);
$cuota = $stmtC->fetch(PDO::FETCH_ASSOC);

if (!$cuota) {
    echo json_encode(['success' => false, 'error' => 'Cuota no encontrada.']);
    exit;
}

$estado_anterior = $cuota['estado'];

try {
    $pdo->beginTransaction();

    // ── Recalcular saldo del préstamo según el cambio de estado ──────────────
    $saldo_actual = (float)$cuota['saldo_pendiente'];
    $monto_cuota  = (float)$cuota['monto'];
    $nuevo_saldo  = $saldo_actual;
    $nuevo_estado_prestamo = $cuota['prestamo_estado'];

    if ($estado_anterior !== $nuevo_estado) {

        // Caso 1: pendiente → pagado (o cancelado → pagado)
        // Se descuenta del saldo
        if ($nuevo_estado === 'pagado' && $estado_anterior !== 'pagado') {
            $nuevo_saldo = max(0, round($saldo_actual - $monto_cuota, 2));
        }

        // Caso 2: pagado → pendiente (reversión de pago)
        // Se devuelve al saldo
        elseif ($estado_anterior === 'pagado' && $nuevo_estado === 'pendiente') {
            $nuevo_saldo = round($saldo_actual + $monto_cuota, 2);
            // Si el préstamo estaba marcado como pagado, volver a activo
            if ($cuota['prestamo_estado'] === 'pagado') {
                $nuevo_estado_prestamo = 'activo';
            }
        }

        // Caso 3: pagado → cancelado
        // Se devuelve al saldo (se asume que ya no va a pagarse)
        elseif ($estado_anterior === 'pagado' && $nuevo_estado === 'cancelado') {
            $nuevo_saldo = round($saldo_actual + $monto_cuota, 2);
        }
    }

    // Verificar si quedan cuotas pendientes después de este cambio
    // (para determinar si el préstamo queda pagado)
    $stmtPend = $pdo->prepare("
        SELECT COUNT(*) FROM colaborador_prestamo_cuotas
        WHERE prestamo_id = ?
          AND id <> ?
          AND estado = 'pendiente'
    ");
    $stmtPend->execute([$cuota['prestamo_id'], $cuota_id]);
    $otras_pendientes = (int)$stmtPend->fetchColumn();

    // Si no quedan pendientes y el nuevo estado es pagado/cancelado → cerrar préstamo
    if ($otros_pendientes = ($otras_pendientes === 0)) {
        if ($nuevo_estado === 'pagado' && $nuevo_saldo <= 0) {
            $nuevo_estado_prestamo = 'pagado';
        }
    }

    // ── Actualizar cuota ──────────────────────────────────────────────────────
    $stmtUpdC = $pdo->prepare("
        UPDATE colaborador_prestamo_cuotas
        SET estado         = ?,
            fecha_esperada = ?,
            fecha_pago     = ?,
            metodo_pago    = ?,
            notas          = ?
        WHERE id = ?
    ");
    $stmtUpdC->execute([
        $nuevo_estado, $fecha_esperada, $fecha_pago,
        ($nuevo_estado === 'pagado' ? ($metodo_pago ?: 'otro') : null),
        $notas, $cuota_id
    ]);

    // ── Actualizar saldo del préstamo ─────────────────────────────────────────
    $pdo->prepare("
        UPDATE colaborador_prestamos
        SET saldo_pendiente = ?, estado = ?
        WHERE id = ?
    ")->execute([$nuevo_saldo, $nuevo_estado_prestamo, $cuota['prestamo_id']]);

    $pdo->commit();

    $msgs = [];
    if ($estado_anterior !== $nuevo_estado) {
        $lbl = ['pendiente'=>'pendiente','pagado'=>'pagada','cancelado'=>'cancelada'];
        $msgs[] = 'Cuota marcada como ' . ($lbl[$nuevo_estado] ?? $nuevo_estado) . '.';
    } else {
        $msgs[] = 'Cuota actualizada.';
    }
    if ($estado_anterior === 'pagado' && $nuevo_estado === 'pendiente') {
        $msgs[] = 'Pago revertido. Saldo del préstamo actualizado.';
    }
    if ($nuevo_estado_prestamo === 'pagado' && $cuota['prestamo_estado'] !== 'pagado') {
        $msgs[] = '🎉 ¡Préstamo completamente saldado!';
    }
    if ($nuevo_estado_prestamo === 'activo' && $cuota['prestamo_estado'] === 'pagado') {
        $msgs[] = 'El préstamo volvió a estado Activo.';
    }

    echo json_encode([
        'success'              => true,
        'message'              => implode(' ', $msgs),
        'nuevo_saldo'          => $nuevo_saldo,
        'prestamo_estado'      => $nuevo_estado_prestamo,
        'pago_revertido'       => ($estado_anterior === 'pagado' && $nuevo_estado === 'pendiente'),
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
