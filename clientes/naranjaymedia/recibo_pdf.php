<?php
// Recibo de cobro en PDF: ?id=<recibo_id> (contratos sin factura) o ?anticipo=<id> (pago anticipado) [&descargar=1]
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/recibo_pdf.php';

try {
    if (in_array(USUARIO_ROL, ['nomina'], true)) throw new Exception("No autorizado.");
    $cid = (int)cliente_actual();
    if (isset($_GET['anticipo'])) {   // recibo de un pago anticipado (?anticipo=<id>)
        $id = (int)$_GET['anticipo'];
        $pdf = anticipoPdf($pdo, $cid, $id);
        $archivo = anticipoArchivo(anticipoDatos($pdo, $cid, $id));
    } else {
        $id = (int)($_GET['id'] ?? 0);
        $pdf = reciboPdf($pdo, $cid, $id);
        $archivo = reciboArchivo(reciboDatos($pdo, $cid, $id));
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . (!empty($_GET['descargar']) ? 'attachment' : 'inline') . '; filename="' . $archivo . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
} catch (Throwable $e) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
}
