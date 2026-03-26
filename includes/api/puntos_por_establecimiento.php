<?php

/**
 * API: Puntos de emisión por establecimiento
 * Ruta esperada: /includes/api/puntos_por_establecimiento.php
 *
 * GET params:
 *   establecimiento_id  (requerido)
 *   cliente_id          (opcional, para validar pertenencia)
 */

require_once dirname(__DIR__) . '/db.php';        // ../../includes/db.php
require_once dirname(__DIR__) . '/session.php';   // ../../includes/session.php

header('Content-Type: application/json; charset=utf-8');

// ── Validar sesión ────────────────────────────────────────────────────────────
if (empty($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

// ── Validar parámetros ────────────────────────────────────────────────────────
$establecimiento_id = isset($_GET['establecimiento_id']) && ctype_digit((string)$_GET['establecimiento_id'])
    ? (int)$_GET['establecimiento_id']
    : null;

$cliente_id = isset($_GET['cliente_id']) && ctype_digit((string)$_GET['cliente_id'])
    ? (int)$_GET['cliente_id']
    : null;

if (!$establecimiento_id) {
    http_response_code(400);
    echo json_encode(['error' => 'establecimiento_id requerido']);
    exit;
}

// ── Consulta ──────────────────────────────────────────────────────────────────
// Si se pasa cliente_id, verificamos que el establecimiento pertenezca al cliente
// para evitar que un cliente vea puntos de otro cliente.
try {
    if ($cliente_id) {
        // Verificar que el establecimiento pertenezca al cliente
        $stmtCheck = $pdo->prepare("
            SELECT COUNT(*) FROM establecimientos
            WHERE establecimiento_id = ? AND cliente_id = ?
        ");
        $stmtCheck->execute([$establecimiento_id, $cliente_id]);

        if ((int)$stmtCheck->fetchColumn() === 0) {
            http_response_code(403);
            echo json_encode(['error' => 'Establecimiento no pertenece al cliente']);
            exit;
        }
    }

    $stmt = $pdo->prepare("
        SELECT
            id,
            establecimiento_id,
            codigo_punto,
            descripcion,
            departamento_id,
            municipio_id
        FROM puntos_emision
        WHERE establecimiento_id = ?
        ORDER BY codigo_punto ASC
    ");
    $stmt->execute([$establecimiento_id]);
    $puntos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($puntos, JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error de base de datos: ' . $e->getMessage()]);
}
