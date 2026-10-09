<?php
// clientes/naranjaymedia/includes/documento_accion.php — Documentos de la empresa (solo administradores).
//   GET  ?ver=<id>                          → el archivo (PDF o imagen)
//   POST accion=guardar  id?, nombre, fecha_emision, fecha_vencimiento, adjuntar_por_defecto, notas, archivo (FILE)
//   POST accion=eliminar id
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once '../../../includes/documentos.php';

try {
    if (!permisoPuede($pdo, 'configuracion_documentos') && !permisoPuede($pdo, 'cobros_programados')) throw new Exception("Tu rol no tiene acceso a los documentos de la empresa.");
    if (!docsDisponible($pdo)) throw new Exception("Falta instalar sql/migraciones/2026-10-09_documentos_empresa.sql.");
    $cid = (int)cliente_actual();
    if (!$cid) throw new Exception("Empresa no identificada.");

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ver'])) {
        $d = docObtener($pdo, $cid, (int)$_GET['ver']);
        $ruta = docDir($cid) . $d['archivo'];
        if (!is_file($ruta)) throw new Exception("El archivo ya no está disponible.");
        header('Content-Type: ' . ($d['mime'] ?: 'application/octet-stream'));
        header('Content-Disposition: inline; filename="' . str_replace('"', '', docNombreAdjunto($d)) . '"');
        header('Content-Length: ' . filesize($ruta));
        header('X-Content-Type-Options: nosniff');
        readfile($ruta);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    switch ($_POST['accion'] ?? '') {
        case 'guardar':
            $id = docGuardar($pdo, $cid, (int)USUARIO_ID, $_POST, $_FILES['archivo'] ?? null);
            echo json_encode(['success' => true, 'id' => $id, 'message' => !empty($_POST['id']) ? 'Documento actualizado.' : 'Documento guardado.'], JSON_UNESCAPED_UNICODE);
            break;
        case 'eliminar':
            docEliminar($pdo, $cid, (int)($_POST['id'] ?? 0));
            echo json_encode(['success' => true, 'message' => 'Documento eliminado. Los cobros ya enviados conservan su copia.'], JSON_UNESCAPED_UNICODE);
            break;
        default:
            throw new Exception("Acción no válida.");
    }
} catch (Throwable $e) {
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    if ($e instanceof PDOException) error_log('documento_accion.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e instanceof PDOException ? 'Error de base de datos.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
