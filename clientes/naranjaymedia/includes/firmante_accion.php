<?php
// clientes/naranjaymedia/includes/firmante_accion.php — Firmantes de los documentos de pago (solo administradores).
//   GET  ?firma=<rol>                         → imagen de la firma (vista previa)
//   POST accion=guardar rol, nombre, cargo     · accion=subir rol, firma (PNG/JPG)  · accion=quitar rol
//   Sello de la empresa: GET ?sello=1 (imagen) · POST accion=sello_subir sello · sello_quitar · sello_uso facturas=0|1 cotizaciones=0|1
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once '../../../includes/firmantes.php';

const FIRMANTE_DIR = __DIR__ . '/uploads/firmas/';

try {
    if (!permisoPuede($pdo, 'configuracion_firmas')) throw new Exception("Tu rol no tiene acceso a Firmas.");
    if (!firmantesDisponible($pdo)) throw new Exception("Falta instalar sql/migraciones/2026-10-06_firmantes_concepto.sql.");
    $cid = (int)cliente_actual();

    // ── Sello de la empresa (clientes_saas.sello): imagen a color tal cual (no se recorta como las firmas) ──
    $esSello = isset($_GET['sello']) || str_starts_with((string)($_POST['accion'] ?? ''), 'sello_');
    if ($esSello) {
        if (!$pdo->query("SHOW COLUMNS FROM clientes_saas LIKE 'sello'")->fetchColumn()) throw new Exception("Falta instalar sql/migraciones/2026-10-12_cotizaciones.sql.");
        $st = $pdo->prepare("SELECT sello FROM clientes_saas WHERE id = ?");
        $st->execute([$cid]);
        $selloActual = (string)$st->fetchColumn();
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $ruta = $selloActual ? realpath(FIRMANTE_DIR . $selloActual) : false;
            if (!$ruta || !str_starts_with($ruta, realpath(FIRMANTE_DIR) ?: '-')) { http_response_code(404); exit; }
            header('Content-Type: ' . ((new finfo(FILEINFO_MIME_TYPE))->file($ruta) ?: 'image/png'));
            header('Cache-Control: private, no-store');
            readfile($ruta);
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        switch ($_POST['accion']) {
            case 'sello_subir':
                $f = $_FILES['sello'] ?? null;
                if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new Exception("Elige la imagen del sello.");
                if ($f['size'] > 3 * 1024 * 1024) throw new Exception("La imagen supera 3 MB.");
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
                $img = match ($mime) { 'image/png' => @imagecreatefrompng($f['tmp_name']), 'image/jpeg' => @imagecreatefromjpeg($f['tmp_name']), 'image/webp' => @imagecreatefromwebp($f['tmp_name']), default => false };
                if (!$img) throw new Exception("Usa una imagen PNG (mejor con fondo transparente), JPG o WEBP.");
                // Máximo 600 px de lado, conservando la transparencia
                $w = imagesx($img); $h = imagesy($img); $k = min(1, 600 / max($w, $h));
                $out = imagecreatetruecolor(max(1, (int)round($w * $k)), max(1, (int)round($h * $k)));
                imagealphablending($out, false); imagesavealpha($out, true);
                imagefill($out, 0, 0, imagecolorallocatealpha($out, 255, 255, 255, 127));
                imagecopyresampled($out, $img, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
                if (!is_dir(FIRMANTE_DIR . $cid) && !mkdir(FIRMANTE_DIR . $cid, 0775, true)) throw new Exception("No se pudo crear la carpeta.");
                $rel = $cid . '/sello_' . bin2hex(random_bytes(6)) . '.png';
                imagepng($out, FIRMANTE_DIR . $rel, 6);
                $pdo->prepare("UPDATE clientes_saas SET sello = ? WHERE id = ?")->execute([$rel, $cid]);
                if ($selloActual && is_file(FIRMANTE_DIR . $selloActual)) @unlink(FIRMANTE_DIR . $selloActual);
                echo json_encode(['success' => true, 'message' => 'Sello guardado.'], JSON_UNESCAPED_UNICODE);
                break;
            case 'sello_quitar':
                $pdo->prepare("UPDATE clientes_saas SET sello = NULL WHERE id = ?")->execute([$cid]);
                if ($selloActual && is_file(FIRMANTE_DIR . $selloActual)) @unlink(FIRMANTE_DIR . $selloActual);
                echo json_encode(['success' => true, 'message' => 'Sello eliminado.'], JSON_UNESCAPED_UNICODE);
                break;
            case 'sello_uso':
                $pdo->prepare("UPDATE clientes_saas SET sello_facturas = ?, sello_cotizaciones = ? WHERE id = ?")->execute([empty($_POST['facturas']) ? 0 : 1, empty($_POST['cotizaciones']) ? 0 : 1, $cid]);
                echo json_encode(['success' => true, 'message' => 'Guardado.'], JSON_UNESCAPED_UNICODE);
                break;
            default:
                throw new Exception("Acción no válida.");
        }
        exit;
    }

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
