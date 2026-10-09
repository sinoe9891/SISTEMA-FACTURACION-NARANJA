<?php
// clientes/naranjaymedia/includes/firmante_accion.php — Firmantes de los documentos de pago (solo administradores).
//   GET  ?firma=<rol>                         → imagen de la firma (vista previa)
//   POST accion=guardar rol, nombre, cargo     · accion=subir rol, firma (PNG/JPG)  · accion=quitar rol
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once '../../../includes/firmantes.php';

const FIRMANTE_DIR = __DIR__ . '/uploads/firmas/';

try {
    if (!permisoPuede($pdo, 'configuracion_firmas')) throw new Exception("Tu rol no tiene acceso a Firmas.");
    if (!firmantesDisponible($pdo)) throw new Exception("Falta instalar sql/migraciones/2026-10-06_firmantes_concepto.sql.");
    $cid = (int)cliente_actual();
    $rol = (string)($_REQUEST['firma'] ?? $_REQUEST['rol'] ?? '');
    if (!isset(FIRMANTES_ROLES[$rol])) throw new Exception("Firmante no válido.");
    $actual = firmantesLista($pdo, $cid)[$rol];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $ruta = $actual['firma'] ? realpath(FIRMANTE_DIR . $actual['firma']) : false;
        if (!$ruta || !str_starts_with($ruta, realpath(FIRMANTE_DIR) ?: '-')) { http_response_code(404); exit; }
        header('Content-Type: image/png');
        header('Cache-Control: private, no-store');
        readfile($ruta);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    $guardar = function (array $campos) use ($pdo, $cid, $rol) {
        $sets = implode(', ', array_map(fn($k) => "$k = VALUES($k)", array_keys($campos)));
        $pdo->prepare("INSERT INTO documento_firmantes (cliente_id, rol, " . implode(', ', array_keys($campos)) . ", actualizado_por) VALUES (?, ?, "
            . implode(', ', array_fill(0, count($campos), '?')) . ", ?) ON DUPLICATE KEY UPDATE $sets, actualizado_por = VALUES(actualizado_por)")
            ->execute([$cid, $rol, ...array_values($campos), (int)USUARIO_ID]);
    };
    switch ($_POST['accion'] ?? '') {
        case 'guardar':
            $guardar(['nombre' => mb_substr(trim((string)($_POST['nombre'] ?? '')), 0, 150) ?: null, 'cargo' => mb_substr(trim((string)($_POST['cargo'] ?? '')), 0, 150) ?: null]);
            echo json_encode(['success' => true, 'message' => FIRMANTES_ROLES[$rol] . ': guardado.'], JSON_UNESCAPED_UNICODE);
            break;
        case 'subir':
            $f = $_FILES['firma'] ?? null;
            if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new Exception("Elige la imagen de la firma.");
            if ($f['size'] > 3 * 1024 * 1024) throw new Exception("La imagen supera 3 MB.");
            if (!is_dir(FIRMANTE_DIR . $cid) && !mkdir(FIRMANTE_DIR . $cid, 0775, true)) throw new Exception("No se pudo crear la carpeta de firmas.");
            $rel = $cid . '/doc_' . $rol . '_' . bin2hex(random_bytes(6)) . '.png';
            firmaNormalizar($f['tmp_name'], FIRMANTE_DIR . $rel);
            $guardar(['firma' => $rel]);
            if ($actual['firma'] && is_file(FIRMANTE_DIR . $actual['firma'])) @unlink(FIRMANTE_DIR . $actual['firma']);
            echo json_encode(['success' => true, 'message' => 'Firma guardada.'], JSON_UNESCAPED_UNICODE);
            break;
        case 'quitar':
            $guardar(['firma' => null]);
            if ($actual['firma'] && is_file(FIRMANTE_DIR . $actual['firma'])) @unlink(FIRMANTE_DIR . $actual['firma']);
            echo json_encode(['success' => true, 'message' => 'Firma eliminada.'], JSON_UNESCAPED_UNICODE);
            break;
        default:
            throw new Exception("Acción no válida.");
    }
} catch (Throwable $e) {
    if (!headers_sent()) { http_response_code(400); header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode(['success' => false, 'error' => $e instanceof PDOException ? 'Error de base de datos.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
