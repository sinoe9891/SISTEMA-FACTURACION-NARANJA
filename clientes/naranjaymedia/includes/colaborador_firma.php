<?php
// clientes/naranjaymedia/includes/colaborador_firma.php — Firma digital del colaborador (imagen) para bouchers y recibos.
//   GET  ?id=<colaborador>                    → la imagen (para la vista previa; la carpeta no es pública)
//   POST accion=subir  id, firma (PNG/JPG)   → la guarda como PNG con fondo blanco (máx. 900 px de ancho)
//   POST accion=quitar id
// Se guarda en includes/uploads/firmas/<empresa>/ y la ruta relativa en colaboradores.url_firma.
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';

const FIRMA_DIR = __DIR__ . '/uploads/firmas/';

function firmaColaborador(PDO $pdo, int $cid, int $id): array
{
    $st = $pdo->prepare("SELECT id, nombre, apellido, url_firma FROM colaboradores WHERE id = ? AND cliente_id = ?");
    $st->execute([$id, $cid]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) throw new Exception("Colaborador no encontrado.");
    return $c;
}

/** Imagen subida → PNG con fondo blanco (las transparencias quedan blancas), sin metadatos. */
function firmaNormalizar(string $tmp, string $destino): void
{
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (!in_array($mime, ['image/png', 'image/jpeg'], true)) throw new Exception("La firma debe ser una imagen PNG o JPG.");
    if (!function_exists('imagecreatefromstring')) {   // sin GD: se guarda tal cual (ya validada)
        if (!copy($tmp, $destino)) throw new Exception("No se pudo guardar la firma.");
        return;
    }
    $src = @imagecreatefromstring((string)file_get_contents($tmp));
    if (!$src) throw new Exception("La imagen está dañada o no se puede leer.");
    $w = imagesx($src); $h = imagesy($src);
    if ($w < 60 || $h < 25) throw new Exception("La imagen es muy pequeña para una firma.");
    $nw = min($w, 900); $nh = (int)round($h * $nw / $w);
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    if (!imagepng($dst, $destino, 6)) throw new Exception("No se pudo guardar la firma.");
    imagedestroy($src); imagedestroy($dst);
}

try {
    if (!puedeNomina()) throw new Exception("No tienes permiso para administrar firmas.");
    $cid = (int)cliente_actual();
    $id = (int)($_REQUEST['id'] ?? 0);
    $c = firmaColaborador($pdo, $cid, $id);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $ruta = $c['url_firma'] ? realpath(FIRMA_DIR . $c['url_firma']) : false;
        if (!$ruta || !str_starts_with($ruta, realpath(FIRMA_DIR) ?: '-')) { http_response_code(404); exit; }
        header('Content-Type: ' . (new finfo(FILEINFO_MIME_TYPE))->file($ruta));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($ruta);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    $anterior = $c['url_firma'] ? FIRMA_DIR . $c['url_firma'] : null;
    switch ($_POST['accion'] ?? '') {
        case 'subir':
            $f = $_FILES['firma'] ?? null;
            if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new Exception("Elige la imagen de la firma.");
            if ($f['size'] > 3 * 1024 * 1024) throw new Exception("La imagen supera 3 MB.");
            $dir = FIRMA_DIR . $cid . '/';
            if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new Exception("No se pudo crear la carpeta de firmas.");
            $rel = $cid . '/firma_' . $id . '_' . bin2hex(random_bytes(6)) . '.png';
            firmaNormalizar($f['tmp_name'], FIRMA_DIR . $rel);
            $pdo->prepare("UPDATE colaboradores SET url_firma = ? WHERE id = ? AND cliente_id = ?")->execute([$rel, $id, $cid]);
            if ($anterior && is_file($anterior)) @unlink($anterior);
            echo json_encode(['success' => true, 'message' => 'Firma guardada. Ya sale en los bouchers y recibos.'], JSON_UNESCAPED_UNICODE);
            break;
        case 'quitar':
            $pdo->prepare("UPDATE colaboradores SET url_firma = NULL WHERE id = ? AND cliente_id = ?")->execute([$id, $cid]);
            if ($anterior && is_file($anterior)) @unlink($anterior);
            echo json_encode(['success' => true, 'message' => 'Firma eliminada.'], JSON_UNESCAPED_UNICODE);
            break;
        default:
            throw new Exception("Acción no válida.");
    }
} catch (Throwable $e) {
    if (!headers_sent()) { http_response_code(400); header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode(['success' => false, 'error' => $e instanceof PDOException ? 'Error de base de datos.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
