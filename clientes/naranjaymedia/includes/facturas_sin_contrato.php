<?php

/**
 * facturas_sin_contrato.php
 * Devuelve facturas sin contrato asignado (contrato_id IS NULL).
 *
 * Para contratos rotativos, incluye facturas de TODOS los receptores del ciclo.
 * Para el resto, solo del receptor principal.
 *
 * GET ?receptor_id=X          → receptor principal
 * GET ?contrato_id=Y          → detecta rotativos y expande receptores
 *
 * Ruta: clientes/[empresa]/includes/facturas_sin_contrato.php
 */
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $cliente_id  = (int)(USUARIO_ROL === 'superadmin'
        ? ($_SESSION['cliente_seleccionado'] ?? 0)
        : CLIENTE_ID);

    $receptor_id  = (int)($_GET['receptor_id']  ?? 0);
    $contrato_id  = (int)($_GET['contrato_id']  ?? 0);

    // Construir lista de receptor_ids a incluir
    $receptor_ids = [];

    if ($contrato_id) {
        // Obtener tipo y receptor principal del contrato
        $stmtCt = $pdo->prepare("
            SELECT tipo_contrato, receptor_id
            FROM contratos
            WHERE id = ? AND cliente_id = ?
        ");
        $stmtCt->execute([$contrato_id, $cliente_id]);
        $ct = $stmtCt->fetch(PDO::FETCH_ASSOC);

        if ($ct) {
            if ($ct['tipo_contrato'] === 'rotativo') {
                // Agregar todos los receptores de los turnos rotativos
                $stmtRot = $pdo->prepare("
                    SELECT DISTINCT receptor_id
                    FROM contratos_clientes_rotativos
                    WHERE contrato_id = ? AND activo = 1
                ");
                $stmtRot->execute([$contrato_id]);
                $receptor_ids = array_column($stmtRot->fetchAll(PDO::FETCH_ASSOC), 'receptor_id');
            }
            // Siempre incluir el receptor principal si existe
            if ((int)$ct['receptor_id'] > 0) {
                $receptor_ids[] = (int)$ct['receptor_id'];
            }
        }
    }

    // También incluir el receptor_id pasado por GET
    if ($receptor_id > 0) {
        $receptor_ids[] = $receptor_id;
    }

    // Deduplicar y filtrar 0s
    $receptor_ids = array_values(array_unique(array_filter($receptor_ids)));

    if (empty($receptor_ids)) {
        echo json_encode([]);
        exit;
    }

    // Construir placeholders para IN
    $placeholders = implode(',', array_fill(0, count($receptor_ids), '?'));

    $params = array_merge([$cliente_id], $receptor_ids);

    $stmt = $pdo->prepare("
        SELECT
            f.id,
            f.correlativo,
            DATE(f.fecha_emision)  AS fecha_emision,
            f.total,
            f.subtotal,
            f.isv_15,
            f.isv_18,
            NULL                   AS notas,
            cf.nombre              AS receptor_nombre,
            MONTH(f.fecha_emision) AS mes,
            YEAR(f.fecha_emision)  AS anio
        FROM facturas f
        INNER JOIN clientes_factura cf
               ON cf.id = f.receptor_id AND cf.cliente_id = f.cliente_id
        WHERE f.cliente_id   = ?
          AND f.receptor_id  IN ($placeholders)
          AND f.estado       = 'emitida'
          AND f.contrato_id  IS NULL
        ORDER BY f.fecha_emision DESC
        LIMIT 100
    ");
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
