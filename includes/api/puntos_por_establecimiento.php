<?php

/**
 * API: Puntos de emisión por establecimiento
 * Ruta esperada: /includes/api/puntos_por_establecimiento.php
 *
 * GET params:
 *   establecimiento_id  (requerido)
 *   cliente_id          (solo superadmin; el resto usa el cliente de la sesión)
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

// Usuarios normales: siempre el cliente de la sesión.
// Superadmin (acceso global): puede indicar ?cliente_id, si no usa el seleccionado.
$cliente_id = (int)(USUARIO_ROL === 'superadmin'
    ? (ctype_digit((string)($_GET['cliente_id'] ?? '')) && (int)$_GET['cliente_id'] > 0
        ? $_GET['cliente_id']
        : ($_SESSION['cliente_seleccionado'] ?? 0))
    : CLIENTE_ID);

if (!$establecimiento_id) {
    http_response_code(400);
    echo json_encode(['error' => 'establecimiento_id requerido']);
    exit;
}

if (!$cliente_id) {
    http_response_code(403);
    echo json_encode(['error' => 'Cliente no identificado']);
    exit;
}

// ── Consulta ──────────────────────────────────────────────────────────────────
// Siempre verificamos que el establecimiento pertenezca al cliente de la sesión
// para evitar que un cliente vea puntos de otro cliente.
try {
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
