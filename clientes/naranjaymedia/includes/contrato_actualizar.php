<?php

/**
 * contrato_actualizar.php — actualiza contratos con todos los tipos
 * Tipos: estandar | periodico | rotativo | sin_factura
 * Ruta: clientes/[empresa]/includes/contrato_actualizar.php
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
    $contrato_id     = (int)($_POST['id']              ?? 0);
    $tipo_contrato   = trim($_POST['tipo_contrato']    ?? 'estandar');
    $receptor_id     = (int)($_POST['receptor_id']     ?? 0);
    $nombre_contrato = trim($_POST['nombre_contrato']  ?? '');
    $fecha_inicio    = trim($_POST['fecha_inicio']     ?? '');
    $fecha_fin       = trim($_POST['fecha_fin']        ?? '') ?: null;
    $dia_pago        = (int)($_POST['dia_pago']        ?? 1);
    $estado          = trim($_POST['estado']           ?? 'activo');
    $notas           = trim($_POST['notas']            ?? '');
    $monto_total     = (float)($_POST['monto_total']   ?? 0);
    $servicios       = $_POST['servicios']             ?? [];
    $rotativos       = $_POST['rotativos']             ?? [];

    // Periódico y Rotativo
    $frecuencia_meses = in_array($tipo_contrato, ['periodico', 'rotativo'])
        ? (int)($_POST['frecuencia_meses'] ?? 1) : null;
    $mes_inicio_ciclo = in_array($tipo_contrato, ['periodico', 'rotativo'])
        ? (int)($_POST['mes_inicio_ciclo'] ?? 0) : null;

    // Sin factura
    $concepto_recibo = ($tipo_contrato === 'sin_factura')
        ? trim($_POST['concepto_recibo'] ?? '') : null;

    // ── Validaciones comunes ──────────────────────────────────────────────────
    if (!$contrato_id)     throw new Exception("Contrato no identificado.");
    if (!in_array($tipo_contrato, ['estandar', 'periodico', 'rotativo', 'sin_factura']))
        throw new Exception("Tipo de contrato inválido.");
    if (!$nombre_contrato) throw new Exception("El nombre del contrato es obligatorio.");
    if (!$fecha_inicio)    throw new Exception("La fecha de inicio es obligatoria.");
    if ($dia_pago < 1 || $dia_pago > 31) throw new Exception("Día de pago inválido.");
    if ($fecha_fin && $fecha_fin < $fecha_inicio) throw new Exception("La fecha fin no puede ser anterior al inicio.");
    if (!in_array($estado, ['activo', 'pausado', 'cancelado', 'vencido'])) throw new Exception("Estado inválido.");

    // Verificar propiedad
    $stmtV = $pdo->prepare("SELECT id FROM contratos WHERE id=? AND cliente_id=?");
    $stmtV->execute([$contrato_id, $cliente_id]);
    if (!$stmtV->fetchColumn()) throw new Exception("Contrato no encontrado o sin permiso.");

    // ── Validaciones por tipo ─────────────────────────────────────────────────
    if ($tipo_contrato !== 'rotativo') {
        if (!$receptor_id) throw new Exception("Selecciona un cliente.");
        $stVR = $pdo->prepare("SELECT id FROM clientes_factura WHERE id=? AND cliente_id=?");
        $stVR->execute([$receptor_id, $cliente_id]);
        if (!$stVR->fetchColumn()) throw new Exception("Cliente no válido.");
    }

    if ($tipo_contrato === 'periodico') {
        if (!in_array($frecuencia_meses, [2, 3, 4, 6, 12]))
            throw new Exception("Frecuencia de cobro inválida.");
        if ($mes_inicio_ciclo < 1 || $mes_inicio_ciclo > 12)
            throw new Exception("Mes de inicio inválido.");
    }

    if ($tipo_contrato === 'rotativo' && $frecuencia_meses !== null) {
        if (!in_array($frecuencia_meses, [1, 2, 3, 4, 6, 12]))
            throw new Exception("Frecuencia de rotación inválida.");
        if ($mes_inicio_ciclo < 1 || $mes_inicio_ciclo > 12)
            throw new Exception("Mes de inicio inválido.");
    }

    if ($tipo_contrato === 'sin_factura' && !$concepto_recibo)
        throw new Exception("Escribe el concepto que aparecerá en el recibo.");

    if ($tipo_contrato === 'rotativo') {
        if (count($rotativos) < 2) throw new Exception("El contrato rotativo necesita al menos 2 turnos.");
        foreach ($rotativos as $idx => $rot) {
            $rid_rot = (int)($rot['receptor_id'] ?? 0);
            $mnt_rot = (float)($rot['monto']     ?? 0);
            if (!$rid_rot) throw new Exception("El turno " . ($idx + 1) . " necesita un cliente.");
            if ($mnt_rot <= 0) throw new Exception("El turno " . ($idx + 1) . " necesita un monto válido.");
            $stR = $pdo->prepare("SELECT id FROM clientes_factura WHERE id=? AND cliente_id=?");
            $stR->execute([$rid_rot, $cliente_id]);
            if (!$stR->fetchColumn()) throw new Exception("Cliente del turno " . ($idx + 1) . " no válido.");
        }
        if (!$receptor_id) $receptor_id = (int)($rotativos[0]['receptor_id'] ?? 0);
    }

    // ── Validar servicios ─────────────────────────────────────────────────────
    $serviciosValidos = [];
    if (!empty($servicios)) {
        foreach ($servicios as $s) {
            $prod_id = (int)($s['producto_id'] ?? 0);
            $monto   = (float)($s['monto']     ?? 0);
            if (!$prod_id || $monto <= 0)
                throw new Exception("Todos los servicios deben tener producto y monto válido.");
            $stP = $pdo->prepare("SELECT id FROM productos_clientes WHERE id=? AND cliente_id=?");
            $stP->execute([$prod_id, $cliente_id]);
            if (!$stP->fetchColumn()) throw new Exception("Producto inválido: ID $prod_id");
            $serviciosValidos[] = ['producto_id' => $prod_id, 'monto' => $monto];
        }
    } elseif ($tipo_contrato !== 'sin_factura' && $tipo_contrato !== 'rotativo') {
        throw new Exception("Agrega al menos un servicio al contrato.");
    }

    // ── Calcular monto total ──────────────────────────────────────────────────
    if ($tipo_contrato === 'rotativo') {
        $sumRot = array_sum(array_column($rotativos, 'monto'));
        $monto_total = round($sumRot / count($rotativos), 2);
    } elseif (!empty($serviciosValidos)) {
        $monto_total = array_sum(array_column($serviciosValidos, 'monto'));
    }

    $primer_producto_id = !empty($serviciosValidos) ? $serviciosValidos[0]['producto_id'] : null;

    // ── Transacción ───────────────────────────────────────────────────────────
    $pdo->beginTransaction();

    // Actualizar contrato principal
    $pdo->prepare("
        UPDATE contratos SET
            tipo_contrato    = ?,
            receptor_id      = ?,
            nombre_contrato  = ?,
            producto_id      = ?,
            monto            = ?,
            fecha_inicio     = ?,
            fecha_fin        = ?,
            dia_pago         = ?,
            estado           = ?,
            frecuencia_meses = ?,
            mes_inicio_ciclo = ?,
            concepto_recibo  = ?,
            notas            = ?
        WHERE id = ? AND cliente_id = ?
    ")->execute([
        $tipo_contrato,
        $receptor_id,
        $nombre_contrato,
        $primer_producto_id,
        $monto_total,
        $fecha_inicio,
        $fecha_fin,
        $dia_pago,
        $estado,
        $frecuencia_meses,
        $mes_inicio_ciclo,
        $concepto_recibo,
        $notas,
        $contrato_id,
        $cliente_id,
    ]);

    // Reemplazar servicios
    $pdo->prepare("DELETE FROM contratos_servicios WHERE contrato_id=?")->execute([$contrato_id]);
    if (!empty($serviciosValidos)) {
        $stSvc = $pdo->prepare("INSERT INTO contratos_servicios (contrato_id,producto_id,monto) VALUES (?,?,?)");
        foreach ($serviciosValidos as $svc) {
            $stSvc->execute([$contrato_id, $svc['producto_id'], $svc['monto']]);
        }
    }

    // Reemplazar turnos rotativos
    $pdo->prepare("DELETE FROM contratos_clientes_rotativos WHERE contrato_id=?")->execute([$contrato_id]);
    if ($tipo_contrato === 'rotativo') {
        $stRot = $pdo->prepare("
            INSERT INTO contratos_clientes_rotativos (contrato_id,receptor_id,orden,monto)
            VALUES (?,?,?,?)
        ");
        foreach ($rotativos as $idx => $rot) {
            $stRot->execute([$contrato_id, (int)$rot['receptor_id'], $idx, (float)$rot['monto']]);
        }
    }

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Contrato actualizado correctamente.']);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
