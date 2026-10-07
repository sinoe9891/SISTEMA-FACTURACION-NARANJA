<?php
// Recibo de cobro en PDF (contratos sin factura): ?id=<recibo_id>[&descargar=1]
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/recibo_pdf.php';

try {
    if (in_array(USUARIO_ROL, ['nomina'], true)) throw new Exception("No autorizado.");
    $cid = (int)cliente_actual();
    $id = (int)($_GET['id'] ?? 0);
    $r = reciboDatos($pdo, $cid, $id);
    $pdf = reciboPdf($pdo, $cid, $id);
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . (!empty($_GET['descargar']) ? 'attachment' : 'inline') . '; filename="' . reciboArchivo($r) . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
} catch (Throwable $e) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
}
