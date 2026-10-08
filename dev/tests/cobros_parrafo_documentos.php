<?php
// Sin base de datos ni envíos: php dev/tests/cobros_parrafo_documentos.php
require_once __DIR__ . '/../../includes/cobros.php';
$original = '<p>Información personalizada &lt;pendiente&gt;.</p><p>Formas de pago: Banco 123</p><p>Saludos cordiales,</p>';
$uno = cobroMensajeDocumentos($original, ['Constancia SAR'], 'saldo_pendiente');
foreach ([$uno, htmlentities($uno, ENT_NOQUOTES, 'UTF-8', false), str_replace('ó', '&#243;', $uno)] as $caso) {
    // El editor codifica texto, no las etiquetas HTML.
    $caso = str_replace(['&lt;p&gt;', '&lt;/p&gt;', '&lt;br&gt;', '&lt;strong&gt;', '&lt;/strong&gt;'], ['<p>', '</p>', '<br>', '<strong>', '</strong>'], $caso);
    $duplicado = $caso . $caso;
    [$html, $texto] = cobroPlantilla($duplicado, [], [], [], false, ['Constancia SAR'], 'saldo_pendiente');
    if (substr_count($html, 'Para facilitar') !== 1 || substr_count($texto, 'Para facilitar') !== 1) throw new Exception('Párrafo duplicado en envío/vista previa');
    if (str_contains(cobroMensajeDocumentos($duplicado, [], 'saldo_pendiente'), 'Para facilitar')) throw new Exception('No retiró párrafos al desmarcar');
    if (!str_contains($html, '&lt;pendiente&gt;')) throw new Exception('Se alteró texto personalizado');
}
echo "OK: párrafos duplicados, entidades, texto personalizado y retiro de documentos.\n";
