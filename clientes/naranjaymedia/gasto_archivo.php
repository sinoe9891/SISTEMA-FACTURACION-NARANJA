<?php
/**
 * gasto_archivo.php — Entrega el comprobante de un gasto solo a usuarios con sesión
 * de la misma empresa. Reemplaza los enlaces directos a includes/uploads/, que
 * quedan bloqueados por includes/uploads/.htaccess.
 *
 * GET: id (gasto), descargar=1 (opcional, fuerza descarga)
 */
require_once '../../includes/db.php';
require_once '../../includes/session.php';

$cid = (int)(USUARIO_ROL === 'superadmin' ? ($_SESSION['cliente_seleccionado'] ?? 0) : CLIENTE_ID);
$id  = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$stmt = $pdo->prepare("SELECT archivo_adjunto, archivo_nombre FROM gastos WHERE id = ? AND cliente_id = ?");
$stmt->execute([$id ?: 0, $cid]);
$g = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$g || empty($g['archivo_adjunto'])) {
    http_response_code(404);
    exit('Comprobante no encontrado.');
}

// Los comprobantes de gastos se guardan en uploads/gastos/<archivo>; los de nómina en
// uploads/comprobantes_nomina/<AAAA/MM/archivo> (o sin subcarpeta en registros viejos).
$base      = realpath(__DIR__ . '/includes/uploads');
$archivo   = $g['archivo_adjunto'];
$candidatos = [
    __DIR__ . '/includes/uploads/gastos/' . basename($archivo),
    __DIR__ . '/includes/uploads/comprobantes_nomina/' . $archivo,
];

$ruta = null;
foreach ($candidatos as $c) {
    $real = realpath($c);
    // Solo archivos dentro de la carpeta de uploads (evita rutas con ../)
    if ($real && $base && strpos($real, $base . DIRECTORY_SEPARATOR) === 0 && is_file($real)) {
        $ruta = $real;
        break;
    }
}

if (!$ruta) {
    http_response_code(404);
    exit('El archivo del comprobante no existe en el servidor.');
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($ruta) ?: 'application/octet-stream';
$permitidos = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
$nombre = $g['archivo_nombre'] ?: basename($ruta);
$nombre = str_replace(['"', "\r", "\n"], '', $nombre);
$disposicion = (!empty($_GET['descargar']) || !in_array($mime, $permitidos, true)) ? 'attachment' : 'inline';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($ruta));
header('Content-Disposition: ' . $disposicion . '; filename="' . $nombre . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
readfile($ruta);
exit;
