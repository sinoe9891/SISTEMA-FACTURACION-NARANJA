<?php
// comision_recibo_pdf.php — Recibo de pago de una comisión por venta, para que lo firme quien la recibe. GET: id
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/comisiones.php';

$id = (int)($_GET['id'] ?? 0);
try {
    $pdf = comisionReciboPdf($pdo, (int)cliente_actual(), $id);
} catch (Throwable $e) {
    http_response_code(404);
    exit($e->getMessage());
}
header('Content-Type: application/pdf');
header('Content-Disposition: ' . (!empty($_GET['descargar']) ? 'attachment' : 'inline') . '; filename="recibo_comision_' . $id . '.pdf"');
echo $pdf;
