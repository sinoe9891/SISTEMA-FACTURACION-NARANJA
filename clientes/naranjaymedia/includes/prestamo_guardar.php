<?php
// clientes/naranjaymedia/includes/prestamo_guardar.php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';

header('Content-Type: application/json');

$cliente_id = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);

if (!$cliente_id) {
    echo json_encode(['success' => false, 'error' => 'Sin cliente activo.']);
    exit;
}

$colaborador_id       = (int)($_POST['colaborador_id']       ?? 0);
$tipo                 = trim($_POST['tipo']                  ?? '');
$monto_total          = (float)($_POST['monto_total']        ?? 0);
$descripcion          = trim($_POST['descripcion']           ?? '');
$fecha                = trim($_POST['fecha']                 ?? date('Y-m-d'));
$fecha_primera_cuota  = trim($_POST['fecha_primera_cuota']   ?? $fecha);
$num_cuotas           = max(1, (int)($_POST['num_cuotas']    ?? 1));
$frecuencia           = in_array($_POST['frecuencia_cuota'] ?? '', ['quincenal', 'mensual'])
    ? $_POST['frecuencia_cuota']
    : 'mensual';
$descuento_auto       = isset($_POST['descuento_auto']) ? 1 : 0;
$notas                = trim($_POST['notas'] ?? '');

if (empty($fecha_primera_cuota)) $fecha_primera_cuota = $fecha;

if (!$colaborador_id || !$tipo || $monto_total <= 0 || empty($descripcion)) {
    echo json_encode(['success' => false, 'error' => 'Datos incompletos o inválidos.']);
    exit;
}

// ── FIX: 'viatico' singular (coincide con el valor del formulario y la DB) ──
$tipos_validos = ['prestamo', 'adelanto', 'bono', 'multa', 'viatico'];

if (!in_array($tipo, $tipos_validos)) {
    echo json_encode(['success' => false, 'error' => 'Tipo no válido: ' . htmlspecialchars($tipo)]);
    exit;
}

$stmtCheck = $pdo->prepare("SELECT id, tipo_pago FROM colaboradores WHERE id = ? AND cliente_id = ? AND activo = 1");
$stmtCheck->execute([$colaborador_id, $cliente_id]);
$colab = $stmtCheck->fetch(PDO::FETCH_ASSOC);
if (!$colab) {
    echo json_encode(['success' => false, 'error' => 'Colaborador no encontrado.']);
    exit;
}

// ── Lógica según tipo ─────────────────────────────────────────────────────────
// bono:    no genera cuotas de descuento (es pago extra)
// viatico: no genera cuotas, no descuento auto (suma a la nómina)
// adelanto/multa: 1 cuota
// prestamo: N cuotas
switch ($tipo) {
    case 'bono':
    case 'viatico':
        $num_cuotas     = 0;
        $descuento_auto = 0;  // viáticos y bonos nunca se descuentan, suman
        break;
    case 'adelanto':
    case 'multa':
        $num_cuotas = 1;
        break;
        // prestamo: usa el valor ingresado
}

$monto_cuota = ($num_cuotas > 0) ? round($monto_total / $num_cuotas, 2) : 0;

try {
    $pdo->beginTransaction();

    $stmtIns = $pdo->prepare("
        INSERT INTO colaborador_prestamos
            (cliente_id, colaborador_id, tipo, monto_total, saldo_pendiente,
             descripcion, fecha, num_cuotas, frecuencia_cuota, monto_cuota,
             descuento_auto, estado, notas)
        VALUES
            (?, ?, ?, ?, ?,
             ?, ?, ?, ?, ?,
             ?, 'activo', ?)
    ");
    $stmtIns->execute([
        $cliente_id,
        $colaborador_id,
        $tipo,
        $monto_total,
        // bonos y viáticos: saldo_pendiente = 0 (no son deudas)
        in_array($tipo, ['bono', 'viatico']) ? 0 : $monto_total,
        $descripcion,
        $fecha,
        $num_cuotas,
        $frecuencia,
        $monto_cuota,
        $descuento_auto,
        $notas
    ]);
    $prestamo_id = (int)$pdo->lastInsertId();

    // ── Generar cuotas (solo préstamo, adelanto, multa) ───────────────────────
    if ($num_cuotas > 0) {
        $stmtCuota = $pdo->prepare("
            INSERT INTO colaborador_prestamo_cuotas
                (prestamo_id, cliente_id, colaborador_id, numero_cuota, monto, fecha_esperada, estado)
            VALUES (?, ?, ?, ?, ?, ?, 'pendiente')
        ");

        $fecha_base  = new DateTime($fecha_primera_cuota);
        $dia_destino = (int)$fecha_base->format('d');

        for ($i = 1; $i <= $num_cuotas; $i++) {
            $monto_esta_cuota = ($i === $num_cuotas)
                ? round($monto_total - ($monto_cuota * ($num_cuotas - 1)), 2)
                : $monto_cuota;

            if ($i === 1) {
                $fecha_cuota = clone $fecha_base;
            } elseif ($frecuencia === 'quincenal') {
                $fecha_cuota = clone $fecha_base;
                $fecha_cuota->modify('+' . (($i - 1) * 15) . ' days');
            } else {
                $fecha_cuota = clone $fecha_base;
                $fecha_cuota->modify('+' . ($i - 1) . ' month');
                $ultimo_dia_mes = (int)$fecha_cuota->format('t');
                $dia_ajustado   = min($dia_destino, $ultimo_dia_mes);
                $fecha_cuota->setDate(
                    (int)$fecha_cuota->format('Y'),
                    (int)$fecha_cuota->format('m'),
                    $dia_ajustado
                );
            }

            $stmtCuota->execute([
                $prestamo_id,
                $cliente_id,
                $colaborador_id,
                $i,
                $monto_esta_cuota,
                $fecha_cuota->format('Y-m-d')
            ]);
        }
    }

    $pdo->commit();

    $etiquetas = [
        'prestamo' => 'Préstamo',
        'adelanto' => 'Adelanto',
        'bono'     => 'Bono/Gratificación',
        'viatico'  => 'Viático',
        'multa'    => 'Multa/Descuento',
    ];

    $msg = ($etiquetas[$tipo] ?? ucfirst($tipo)) . ' registrado correctamente.';
    if ($num_cuotas > 0) $msg .= " Se generaron {$num_cuotas} cuota(s).";
    if ($tipo === 'viatico') $msg .= " Se liquidará al procesar la nómina.";
    if ($tipo === 'bono')    $msg .= " Se pagará al registrar la nómina.";

    echo json_encode([
        'success'     => true,
        'prestamo_id' => $prestamo_id,
        'message'     => $msg,
    ]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => 'Error al guardar: ' . $e->getMessage()]);
}
