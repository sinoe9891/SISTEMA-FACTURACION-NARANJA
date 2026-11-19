<?php
// Comprobante de un movimiento de colaborador (adelanto, bono, viático, multa, préstamo): JPG, PNG, WEBP o PDF de hasta 5 MB.
// Se guarda junto a los comprobantes de nómina y la ruta queda en colaborador_prestamos.archivo_adjunto.

/** Sube el archivo del campo «comprobante». Devuelve [ruta, nombre] o null si no mandaron archivo; lanza Exception si es inválido. */
function prestamoComprobanteSubir(int $colabId): ?array
{
    $f = $_FILES['comprobante'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($f['error'] !== UPLOAD_ERR_OK) throw new Exception('No se pudo subir el comprobante.');
    if (!is_uploaded_file($f['tmp_name'])) throw new Exception('Archivo temporal inválido.');
    if ((int)$f['size'] > 5 * 1024 * 1024) throw new Exception('El comprobante supera 5 MB.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $permitidos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    if (!isset($permitidos[$mime])) throw new Exception('Comprobante no permitido: solo JPG, PNG, WEBP o PDF.');
    $sub = date('Y') . '/' . date('m') . '/';
    $dir = __DIR__ . '/uploads/comprobantes_nomina/' . $sub;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new Exception('No se pudo crear la carpeta de comprobantes.');
    $nombre = 'mov_' . $colabId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $permitidos[$mime];
    if (!move_uploaded_file($f['tmp_name'], $dir . $nombre)) throw new Exception('No se pudo guardar el comprobante.');
    return [$sub . $nombre, mb_substr(preg_replace('/[^\w.\- ]+/u', '_', (string)$f['name']), 0, 200)];
}

/** Ruta física de un comprobante ya guardado (o false si no existe o queda fuera de la carpeta). */
function prestamoComprobanteRuta(?string $rel)
{
    $base = realpath(__DIR__ . '/uploads/comprobantes_nomina');
    $ruta = $rel ? realpath(__DIR__ . '/uploads/comprobantes_nomina/' . $rel) : false;
    return ($base && $ruta && strpos($ruta, $base . DIRECTORY_SEPARATOR) === 0 && is_file($ruta)) ? $ruta : false;
}
