<?php
// includes/prestamo_guardar.php
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

// ── Recoger y validar campos ──────────────────────────────────────────────────
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

// Si no mandaron fecha_primera_cuota (adelanto/multa), usar la fecha del préstamo
if (empty($fecha_primera_cuota)) $fecha_primera_cuota = $fecha;

// Validaciones básicas
if (!$colaborador_id || !$tipo || $monto_total <= 0 || empty($descripcion)) {
    echo json_encode(['success' => false, 'error' => 'Datos incompletos o inválidos.']);
    exit;
}

$tipos_validos = ['prestamo', 'adelanto', 'bono', 'multa'];
if (!in_array($tipo, $tipos_validos)) {
    echo json_encode(['success' => false, 'error' => 'Tipo no válido.']);
    exit;
}

// Verificar que el colaborador pertenece al cliente
$stmtCheck = $pdo->prepare("SELECT id, tipo_pago FROM colaboradores WHERE id = ? AND cliente_id = ? AND activo = 1");
$stmtCheck->execute([$colaborador_id, $cliente_id]);
$colab = $stmtCheck->fetch(PDO::FETCH_ASSOC);
if (!$colab) {
    echo json_encode(['success' => false, 'error' => 'Colaborador no encontrado.']);
    exit;
}

// ── Lógica según tipo ─────────────────────────────────────────────────────────
// Bono: no genera cuotas de descuento (es un pago extra, no deuda)
// Multa/Adelanto: 1 cuota
// Préstamo: N cuotas
switch ($tipo) {
    case 'bono':
        $num_cuotas = 0;   // sin cuotas de descuento
        break;
    case 'adelanto':
    case 'multa':
        $num_cuotas = 1;
        break;
        // prestamo: usa el valor ingresado
}

$monto_cuota = ($num_cuotas > 0) ? round($monto_total / $num_cuotas, 2) : 0;

// ── Insertar préstamo ─────────────────────────────────────────────────────────
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
        ($tipo === 'bono') ? 0 : $monto_total,   // bonos no tienen saldo pendiente
        $descripcion,
        $fecha,
        $num_cuotas,
        $frecuencia,
        $monto_cuota,
        $descuento_auto,
        $notas
    ]);
    $prestamo_id = (int)$pdo->lastInsertId();

    // ── Generar cuotas ────────────────────────────────────────────────────────
    if ($num_cuotas > 0) {
        $stmtCuota = $pdo->prepare("
            INSERT INTO colaborador_prestamo_cuotas
                (prestamo_id, cliente_id, colaborador_id, numero_cuota, monto, fecha_esperada, estado)
            VALUES (?, ?, ?, ?, ?, ?, 'pendiente')
        ");

        // La base es la fecha de la PRIMERA CUOTA, no la del préstamo
        $fecha_base  = new DateTime($fecha_primera_cuota);
        $dia_destino = (int)$fecha_base->format('d'); // ej: 28, 30, 31

        for ($i = 1; $i <= $num_cuotas; $i++) {
            $monto_esta_cuota = ($i === $num_cuotas)
                ? round($monto_total - ($monto_cuota * ($num_cuotas - 1)), 2)
                : $monto_cuota;

            if ($i === 1) {
                $fecha_cuota = clone $fecha_base;
            } elseif ($frecuencia === 'quincenal') {
                // Quincenal: sumar días desde la base, sin problema de meses
                $fecha_cuota = clone $fecha_base;
                $fecha_cuota->modify('+' . (($i - 1) * 15) . ' days');
            } else {
                // Mensual: calcular año/mes destino y forzar el día correcto
                $fecha_cuota = clone $fecha_base;
                $fecha_cuota->modify('+' . ($i - 1) . ' month');

                // Forzar el día original, capado al último día del mes destino
                // Ej: día 31 en febrero → 28/29; día 30 en febrero → 28/29
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
        'bono'     => 'Bono',
        'multa'    => 'Multa/Descuento'
    ];

    echo json_encode([
        'success'     => true,
        'prestamo_id' => $prestamo_id,
        'message'     => $etiquetas[$tipo] . ' registrado correctamente.'
            . ($num_cuotas > 0 ? " Se generaron {$num_cuotas} cuota(s)." : ''),
    ]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => 'Error al guardar: ' . $e->getMessage()]);
}
