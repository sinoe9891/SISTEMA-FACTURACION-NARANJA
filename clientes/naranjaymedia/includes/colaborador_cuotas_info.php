<?php
// clientes/naranjaymedia/includes/colaborador_cuotas_info.php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $cid      = (int)(USUARIO_ROL === 'superadmin'
        ? ($_SESSION['cliente_seleccionado'] ?? 0)
        : CLIENTE_ID);
    $colab_id = filter_input(INPUT_GET, 'colab_id', FILTER_VALIDATE_INT);
    if (!$colab_id || !$cid) throw new Exception("Parámetros inválidos.");

    $sv = $pdo->prepare("SELECT nombre, apellido, tipo_pago FROM colaboradores WHERE id=? AND cliente_id=? AND activo=1");
    $sv->execute([$colab_id, $cid]);
    $c = $sv->fetch(PDO::FETCH_ASSOC);
    if (!$c) throw new Exception("Colaborador no encontrado.");

    $nombreCompleto = $c['nombre'] . ' ' . $c['apellido'];

    // ── Período de referencia ─────────────────────────────────────────────────
    $fecha_ref_raw = trim($_GET['fecha_ref'] ?? '');
    if ($fecha_ref_raw && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_ref_raw)) {
        $ref_anio = (int)date('Y', strtotime($fecha_ref_raw));
        $ref_mes  = (int)date('n', strtotime($fecha_ref_raw));
    } else {
        $ref_anio = (int)date('Y');
        $ref_mes  = (int)date('n');
    }

    // ── Quincenas ya pagadas ──────────────────────────────────────────────────
    $q1_pagada = false;
    $q2_pagada = false;
    if ($c['tipo_pago'] === 'quincenal') {
        $stmtQP = $pdo->prepare("
            SELECT quincena_num FROM gastos
            WHERE cliente_id = ? AND descripcion LIKE ?
              AND YEAR(fecha) = ? AND MONTH(fecha) = ?
              AND estado != 'anulado' AND quincena_num IN (1, 2)
        ");
        $stmtQP->execute([$cid, 'Sueldo ' . $nombreCompleto . '%', $ref_anio, $ref_mes]);
        foreach ($stmtQP->fetchAll(PDO::FETCH_COLUMN) as $qn) {
            if ((int)$qn === 1) $q1_pagada = true;
            if ((int)$qn === 2) $q2_pagada = true;
        }
    }

    // ── Cuotas de préstamos con descuento automático ──────────────────────────
    $stmtCuotas = $pdo->prepare("
        SELECT c.id          AS cuota_id,
               c.monto       AS cuota_monto,
               c.numero_cuota,
               p.id          AS prestamo_id,
               p.descripcion AS prest_desc
        FROM colaborador_prestamo_cuotas c
        JOIN colaborador_prestamos p ON p.id = c.prestamo_id
        WHERE p.colaborador_id = ? AND p.cliente_id = ?
          AND p.estado = 'activo' AND p.descuento_auto = 1
          AND c.estado = 'pendiente'
          AND c.id = (
              SELECT c2.id FROM colaborador_prestamo_cuotas c2
              WHERE c2.prestamo_id = p.id AND c2.estado = 'pendiente'
              ORDER BY c2.numero_cuota ASC LIMIT 1
          )
        ORDER BY p.id ASC
    ");
    $stmtCuotas->execute([$colab_id, $cid]);
    $cuotas = $stmtCuotas->fetchAll(PDO::FETCH_ASSOC);

    // ── Bonos activos ─────────────────────────────────────────────────────────
    $stmtBonos = $pdo->prepare("
        SELECT id, descripcion, monto_total, fecha
        FROM colaborador_prestamos
        WHERE colaborador_id = ? AND cliente_id = ?
          AND tipo = 'bono' AND estado = 'activo'
        ORDER BY fecha ASC
    ");
    $stmtBonos->execute([$colab_id, $cid]);
    $bonos = $stmtBonos->fetchAll(PDO::FETCH_ASSOC);

    // ── Viáticos activos ──────────────────────────────────────────────────────
    $stmtViat = $pdo->prepare("
        SELECT id, descripcion, monto_total, fecha
        FROM colaborador_prestamos
        WHERE colaborador_id = ? AND cliente_id = ?
          AND tipo = 'viatico' AND estado = 'activo'
        ORDER BY fecha ASC
    ");
    $stmtViat->execute([$colab_id, $cid]);
    $viaticos = $stmtViat->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'   => true,
        'q1_pagada' => $q1_pagada,
        'q2_pagada' => $q2_pagada,
        'ref_anio'  => $ref_anio,
        'ref_mes'   => $ref_mes,
        'cuotas'    => $cuotas,
        'bonos'     => $bonos,
        'viaticos'  => $viaticos,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
