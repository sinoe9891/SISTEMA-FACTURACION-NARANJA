<?php
/**
 * cotizacion_pdf.php — PDF de una cotización (el mismo que se adjunta al correo).
 * GET: id, descargar=1 (opcional)
 */
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/cotizaciones.php';

try {
    if (!cotizacionesDisponible($pdo) || !permisoPuede($pdo, 'cotizaciones')) throw new Exception("Sin acceso.");
    $cid = (int)cliente_actual();
    $c = cotizacionObtener($pdo, $cid, (int)($_GET['id'] ?? 0));
    $pdf = cotizacionPdf($pdo, $cid, (int)$c['id']);
} catch (Throwable $e) {
    http_response_code(404);
    exit(htmlspecialchars($e->getMessage()));
}
header('Content-Type: application/pdf');
header('Content-Disposition: ' . (!empty($_GET['descargar']) ? 'attachment' : 'inline') . '; filename="' . cotizacionArchivo($c) . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
echo $pdf;
