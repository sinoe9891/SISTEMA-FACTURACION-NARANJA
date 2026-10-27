<?php
// socio_prestamo_pdf.php — PDF de control de un préstamo a socio (tabla de cuotas, pagos y firmas). GET: id, descargar=1 (opcional)
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/socios_prestamos.php';

$cid = (int)cliente_actual();
$id = (int)($_GET['id'] ?? 0);
try {
    $pdf = spPdf($pdo, $cid, $id);
} catch (Throwable $e) {
    http_response_code(404);
    exit('Préstamo no encontrado.');
}
header('Content-Type: application/pdf');
header('Content-Disposition: ' . (!empty($_GET['descargar']) ? 'attachment' : 'inline') . '; filename="control_prestamo_' . $id . '.pdf"');
echo $pdf;
