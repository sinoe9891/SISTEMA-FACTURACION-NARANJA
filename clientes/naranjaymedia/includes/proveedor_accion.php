<?php
// clientes/naranjaymedia/includes/proveedor_accion.php — Proveedores.
//   POST accion=guardar  id (0 = nuevo), nombre, tipo, rtn, sitio_web, telefono, correo, notas  → también alta rápida desde un select
//   POST accion=activo   id, activo (1/0)
//   POST accion=logo     id + archivo «logo» (PNG/JPG/WEBP, máx. 1 MB) o recargar=1 (vuelve a bajarlo del sitio web)
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once '../../../includes/proveedores.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    $cid = (int)cliente_actual();
    if (!$cid || !proveedoresDisponible($pdo)) throw new Exception("Falta instalar el módulo de proveedores.");
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'guardar') {
        $p = proveedorGuardar($pdo, $cid, $_POST, (int)($_POST['id'] ?? 0));
        echo json_encode(['success' => true, 'message' => 'Proveedor guardado.', 'proveedor' => ['id' => (int)$p['id'], 'nombre' => $p['nombre'], 'tipo' => $p['tipo'], 'logo' => $p['logo']]], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $p = proveedorObtener($pdo, $cid, (int)($_POST['id'] ?? 0));
    if (!$p) throw new Exception("El proveedor no existe.");
    if ($accion === 'activo') {
        $pdo->prepare("UPDATE proveedores SET activo = ? WHERE id = ? AND cliente_id = ?")->execute([empty($_POST['activo']) ? 0 : 1, (int)$p['id'], $cid]);
        echo json_encode(['success' => true, 'message' => empty($_POST['activo']) ? 'Proveedor desactivado (ya no sale en las listas).' : 'Proveedor activado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($accion === 'logo') {
        if (!empty($_POST['recargar'])) {
            if (!$p['sitio_web']) throw new Exception("El proveedor no tiene sitio web.");
            $logo = proveedorDescargarLogo($cid, (int)$p['id'], $p['sitio_web']);
            if (!$logo) throw new Exception("No se encontró un logo en ese sitio. Puedes subir uno.");
        } else {
            $f = $_FILES['logo'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new Exception("Selecciona una imagen.");
            if ($f['size'] > 1048576) throw new Exception("El logo supera 1 MB.");
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
            $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime] ?? null;
            if (!$ext) throw new Exception("Usa una imagen PNG, JPG o WEBP.");
            $logo = (int)$p['id'] . '_' . substr(sha1_file($f['tmp_name']), 0, 8) . '.' . $ext;
            if (!move_uploaded_file($f['tmp_name'], proveedorLogoDir($cid) . $logo)) throw new Exception("No se pudo guardar el logo.");
        }
        $pdo->prepare("UPDATE proveedores SET logo = ? WHERE id = ? AND cliente_id = ?")->execute([$logo, (int)$p['id'], $cid]);
        echo json_encode(['success' => true, 'message' => 'Logo actualizado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    throw new Exception("Acción no válida.");
} catch (Throwable $e) {
    http_response_code(400);
    if ($e instanceof PDOException) error_log('proveedor_accion.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e instanceof PDOException ? 'Error de base de datos.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
