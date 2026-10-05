<?php
// clientes/naranjaymedia/includes/respaldo_accion.php — Respaldos de la base de datos (superadmin y admin de la empresa dueña).
//   GET  ?descargar=<archivo>              → descarga la copia (comprueba antes su huella SHA-256)
//   POST accion=crear                      → crea una copia ahora (aplica la retención de 7)
//   POST accion=probar archivo=<archivo>   → prueba de lectura completa (integridad)
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once '../../../includes/respaldos.php';

try {
    if (!respaldoPuede()) {
        respaldoLog('acceso_denegado', ['detalle' => $_SERVER['REQUEST_METHOD'] . ' ' . ($_GET['descargar'] ?? $_POST['accion'] ?? '')]);
        throw new Exception("No tienes permiso para administrar respaldos.");
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['descargar'])) {
        $archivo = (string)$_GET['descargar'];
        $ruta = respaldoRuta($archivo);
        $meta = is_file($ruta . '.json') ? (json_decode((string)file_get_contents($ruta . '.json'), true) ?: []) : [];
        if (!empty($meta['sha256']) && !hash_equals($meta['sha256'], hash_file('sha256', $ruta))) {
            respaldoLog('descarga_bloqueada', ['archivo' => $archivo, 'motivo' => 'huella SHA-256 no coincide']);
            throw new Exception("El respaldo está dañado (su huella SHA-256 no coincide); no se descarga.");
        }
        respaldoLog('descargado', ['archivo' => $archivo, 'bytes' => filesize($ruta)]);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $archivo . '"');
        header('Content-Length: ' . filesize($ruta));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        if (!empty($meta['sha256'])) header('X-Checksum-SHA256: ' . $meta['sha256']);
        readfile($ruta);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    switch ($_POST['accion'] ?? '') {
        case 'crear':
            $m = respaldoCrear($pdo, 'manual', (int)USUARIO_ID);
            echo json_encode(['success' => true, 'message' => 'Respaldo creado: ' . $m['archivo'] . ' (' . number_format($m['bytes'] / 1024, 0) . ' KB, ' . $m['tablas'] . ' tablas).'], JSON_UNESCAPED_UNICODE);
            break;
        case 'probar':
            $r = respaldoProbar((string)($_POST['archivo'] ?? ''));
            echo json_encode(['success' => true] + $r + ['message' => $r['ok']
                ? "Correcto: huella SHA-256 válida, volcado completo con {$r['tablas']} tablas ({$r['mb_sql']} MB de SQL)."
                : 'Con problemas: ' . implode(', ', array_filter([$r['huella'] ? '' : 'la huella no coincide', $r['completo'] ? '' : 'el volcado está incompleto'])) . '.'], JSON_UNESCAPED_UNICODE);
            break;
        default:
            throw new Exception("Acción no válida.");
    }
} catch (Throwable $e) {
    if (!headers_sent()) { http_response_code(400); header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode(['success' => false, 'error' => $e instanceof PDOException ? 'Error de base de datos.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
