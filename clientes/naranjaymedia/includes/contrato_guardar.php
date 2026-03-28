<?php

/**
 * contrato_guardar.php — guarda contratos con todos los tipos
 * Tipos: estandar | periodico | rotativo | sin_factura
 * Ruta: clientes/[empresa]/includes/contrato_guardar.php
 */
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");

    $cliente_id = (int)(USUARIO_ROL === 'superadmin'
        ? ($_SESSION['cliente_seleccionado'] ?? 0)
        : CLIENTE_ID);
    if (!$cliente_id) throw new Exception("Cliente no identificado.");

    // ── Campos comunes ────────────────────────────────────────────────────────
    $tipo_contrato   = trim($_POST['tipo_contrato']   ?? 'estandar');
    $receptor_id     = (int)($_POST['receptor_id']    ?? 0);
    $nombre_contrato = trim($_POST['nombre_contrato'] ?? '');
    $fecha_inicio    = trim($_POST['fecha_inicio']    ?? '');
    $fecha_fin       = trim($_POST['fecha_fin']       ?? '') ?: null;
    $dia_pago        = (int)($_POST['dia_pago']       ?? 1);
    $notas           = trim($_POST['notas']           ?? '');
    $monto_total     = (float)($_POST['monto_total']  ?? 0);

    // Periódico y Rotativo
    $frecuencia_meses  = in_array($tipo_contrato, ['periodico', 'rotativo'])
        ? (int)($_POST['frecuencia_meses'] ?? 1) : null;
    $mes_inicio_ciclo  = in_array($tipo_contrato, ['periodico', 'rotativo'])
        ? (int)($_POST['mes_inicio_ciclo'] ?? 0) : null;

    // Sin factura
    $concepto_recibo   = ($tipo_contrato === 'sin_factura')
        ? trim($_POST['concepto_recibo'] ?? '') : null;

    // Servicios (todos los tipos excepto rotativo lo requieren)
    $servicios         = $_POST['servicios'] ?? [];

    // Rotativos
    $rotativos         = $_POST['rotativos'] ?? [];

    // ── Validaciones comunes ─────────────────────────────────────────────────
    $tipos_validos = ['estandar', 'periodico', 'rotativo', 'sin_factura'];
    if (!in_array($tipo_contrato, $tipos_validos))
        throw new Exception("Tipo de contrato inválido.");

    if (!$nombre_contrato)
        throw new Exception("El nombre del contrato es obligatorio.");
    if (!$fecha_inicio)
        throw new Exception("La fecha de inicio es obligatoria.");
    if ($dia_pago < 1 || $dia_pago > 31)
        throw new Exception("Día de pago inválido.");
    if ($fecha_fin && $fecha_fin < $fecha_inicio)
        throw new Exception("La fecha fin no puede ser anterior al inicio.");

    // ── Validaciones por tipo ────────────────────────────────────────────────

    // Cliente principal requerido excepto rotativo (donde puede ser opcional)
    if ($tipo_contrato !== 'rotativo') {
        if (!$receptor_id) throw new Exception("Selecciona un cliente.");
        // Verificar que pertenece al cliente
        $stV = $pdo->prepare("SELECT id FROM clientes_factura WHERE id=? AND cliente_id=?");
        $stV->execute([$receptor_id, $cliente_id]);
        if (!$stV->fetchColumn()) throw new Exception("Cliente no válido.");
    }

    // Periódico: frecuencia y mes de inicio
    if ($tipo_contrato === 'periodico') {
        $freqs_validas = [2, 3, 4, 6, 12];
        if (!in_array($frecuencia_meses, $freqs_validas))
            throw new Exception("Frecuencia de cobro inválida.");
        if ($mes_inicio_ciclo < 1 || $mes_inicio_ciclo > 12)
            throw new Exception("Mes de inicio del ciclo inválido.");
    }

    // Rotativo: validar frecuencia si viene (opcional, default mensual = 1)
    if ($tipo_contrato === 'rotativo' && $frecuencia_meses !== null) {
        $freqs_validas_rot = [1, 2, 3, 4, 6, 12];
        if (!in_array($frecuencia_meses, $freqs_validas_rot))
            throw new Exception("Frecuencia de rotación inválida.");
        if ($mes_inicio_ciclo < 1 || $mes_inicio_ciclo > 12)
            throw new Exception("Mes de inicio del ciclo inválido.");
    }

    // Sin factura: concepto obligatorio
    if ($tipo_contrato === 'sin_factura') {
        if (!$concepto_recibo)
            throw new Exception("Escribe el concepto que aparecerá en el recibo.");
    }

    // Rotativo: al menos 2 turnos
    if ($tipo_contrato === 'rotativo') {
        if (count($rotativos) < 2)
            throw new Exception("El contrato rotativo necesita al menos 2 turnos.");
        foreach ($rotativos as $idx => $rot) {
            $rid_rot = (int)($rot['receptor_id'] ?? 0);
            $mnt_rot = (float)($rot['monto']      ?? 0);
            if (!$rid_rot) throw new Exception("El turno " . ($idx + 1) . " necesita un cliente.");
            if ($mnt_rot <= 0) throw new Exception("El turno " . ($idx + 1) . " necesita un monto válido.");
            $stVR = $pdo->prepare("SELECT id FROM clientes_factura WHERE id=? AND cliente_id=?");
            $stVR->execute([$rid_rot, $cliente_id]);
            if (!$stVR->fetchColumn()) throw new Exception("Cliente del turno " . ($idx + 1) . " no válido.");
        }
        // Receptor principal: si no viene, usar el del turno 0
        if (!$receptor_id) {
            $receptor_id = (int)($rotativos[0]['receptor_id'] ?? 0);
        }
    }

    // Validar servicios (requeridos para todos excepto sin_factura que puede ir vacío si hay concepto)
    $serviciosValidos = [];
    if (!empty($servicios)) {
        foreach ($servicios as $s) {
            $prod_id = (int)($s['producto_id'] ?? 0);
            $monto   = (float)($s['monto']     ?? 0);
            if (!$prod_id || $monto <= 0)
                throw new Exception("Todos los servicios deben tener producto y monto válido.");
            $stP = $pdo->prepare("SELECT id FROM productos_clientes WHERE id=? AND cliente_id=?");
            $stP->execute([$prod_id, $cliente_id]);
            if (!$stP->fetchColumn()) throw new Exception("Producto inválido: $prod_id");
            $serviciosValidos[] = ['producto_id' => $prod_id, 'monto' => $monto];
        }
    } elseif ($tipo_contrato !== 'sin_factura' && $tipo_contrato !== 'rotativo') {
        throw new Exception("Agrega al menos un servicio al contrato.");
    }

    // Calcular monto total
    if ($tipo_contrato === 'rotativo') {
        // Monto promedio de los turnos rotativos (el campo monto del contrato = promedio)
        $sumRot = array_sum(array_column($rotativos, 'monto'));
        $monto_total = round($sumRot / count($rotativos), 2);
    } elseif (!empty($serviciosValidos)) {
        $monto_total = array_sum(array_column($serviciosValidos, 'monto'));
    }

    $primer_producto_id = !empty($serviciosValidos) ? $serviciosValidos[0]['producto_id'] : null;

    // ── Transacción ──────────────────────────────────────────────────────────
    $pdo->beginTransaction();

    // Insertar contrato principal
    $stIns = $pdo->prepare("
        INSERT INTO contratos (
            cliente_id, receptor_id, nombre_contrato,
            producto_id, monto,
            fecha_inicio, fecha_fin, dia_pago,
            estado, tipo_contrato, frecuencia_meses,
            mes_inicio_ciclo, concepto_recibo, notas
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'activo', ?, ?, ?, ?, ?)
    ");
    $stIns->execute([
        $cliente_id,
        $receptor_id,
        $nombre_contrato,
        $primer_producto_id,
        $monto_total,
        $fecha_inicio,
        $fecha_fin,
        $dia_pago,
        $tipo_contrato,
        $frecuencia_meses,
        $mes_inicio_ciclo,
        $concepto_recibo,
        $notas,
    ]);
    $contrato_id = (int)$pdo->lastInsertId();

    // Insertar servicios en pivote
    if (!empty($serviciosValidos)) {
        $stSvc = $pdo->prepare("
            INSERT INTO contratos_servicios (contrato_id, producto_id, monto)
            VALUES (?, ?, ?)
        ");
        foreach ($serviciosValidos as $svc) {
            $stSvc->execute([$contrato_id, $svc['producto_id'], $svc['monto']]);
        }
    }

    // Insertar turnos rotativos
    if ($tipo_contrato === 'rotativo') {
        $stRot = $pdo->prepare("
            INSERT INTO contratos_clientes_rotativos
                (contrato_id, receptor_id, orden, monto)
            VALUES (?, ?, ?, ?)
        ");
        foreach ($rotativos as $idx => $rot) {
            $stRot->execute([
                $contrato_id,
                (int)$rot['receptor_id'],
                $idx,
                (float)$rot['monto'],
            ]);
        }
    }

    $pdo->commit();

    // Mensaje descriptivo
    $msgs = [
        'estandar'    => 'Contrato mensual creado correctamente.',
        'periodico'   => "Contrato periódico cada {$frecuencia_meses} mes(es) creado.",
        'rotativo'    => 'Contrato rotativo con ' . count($rotativos) . ' turnos creado.',
        'sin_factura' => 'Contrato sin facturación (recibo) creado correctamente.',
    ];

    echo json_encode([
        'success'     => true,
        'contrato_id' => $contrato_id,
        'message'     => $msgs[$tipo_contrato] ?? 'Contrato creado correctamente.',
    ]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
