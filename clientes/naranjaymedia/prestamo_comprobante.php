<?php
/**
 * prestamo_comprobante.php — Comprobante de un adelanto, bono, viático, multa o préstamo de colaborador.
 * GET: id (movimiento), descargar=1 (opcional). Solo administradores y Nómina, de la misma empresa.
 */
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once __DIR__ . '/includes/prestamo_comprobante_lib.php';

if (!puedeNomina()) { http_response_code(403); exit('Sin permiso.'); }
$cid = (int)cliente_actual();

// POST: adjuntar o reemplazar el comprobante de un movimiento ya registrado (id + comprobante)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    try {
        $st = $pdo->prepare("SELECT id, colaborador_id FROM colaborador_prestamos WHERE id = ? AND cliente_id = ?");
        $st->execute([(int)($_POST['id'] ?? 0), $cid]);
        $mov = $st->fetch(PDO::FETCH_ASSOC);
        if (!$mov) throw new Exception('Movimiento no encontrado.');
        $comp = prestamoComprobanteSubir((int)$mov['colaborador_id']);
        if (!$comp) throw new Exception('Elegí un archivo.');
        $pdo->prepare("UPDATE colaborador_prestamos SET archivo_adjunto = ?, archivo_nombre = ? WHERE id = ? AND cliente_id = ?")->execute([$comp[0], $comp[1], $mov['id'], $cid]);
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
$st = $pdo->prepare("SELECT archivo_adjunto, archivo_nombre FROM colaborador_prestamos WHERE id = ? AND cliente_id = ?");
$st->execute([(int)($_GET['id'] ?? 0), $cid]);
$m = $st->fetch(PDO::FETCH_ASSOC);
$ruta = $m ? prestamoComprobanteRuta($m['archivo_adjunto']) : false;
if (!$ruta) { http_response_code(404); exit('Comprobante no encontrado.'); }
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($ruta);
$nombre = preg_replace('/[^\w.\- ]+/u', '_', $m['archivo_nombre'] ?: basename($ruta));
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . (!empty($_GET['descargar']) ? 'attachment' : 'inline') . '; filename="' . $nombre . '"');
header('Content-Length: ' . filesize($ruta));
header('X-Content-Type-Options: nosniff');
readfile($ruta);
