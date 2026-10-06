<?php
/**
 * firmas.php — Imágenes de firma (colaboradores y firmantes de documentos): se guardan como PNG con fondo blanco,
 * recortadas al trazo (sin márgenes) y de máximo 900 × 400 px. Las usan bouchers y recibos.
 */

/** Imagen subida → PNG con fondo blanco (las transparencias quedan blancas), recortada a la firma, sin metadatos. */
function firmaNormalizar(string $tmp, string $destino): void
{
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (!in_array($mime, ['image/png', 'image/jpeg'], true)) throw new Exception("La firma debe ser una imagen PNG o JPG.");
    if (!function_exists('imagecreatefromstring')) {   // sin GD: se guarda tal cual (ya validada)
        if (!copy($tmp, $destino)) throw new Exception("No se pudo guardar la firma.");
        return;
    }
    $src = @imagecreatefromstring((string)file_get_contents($tmp));
    if (!$src) throw new Exception("La imagen está dañada o no se puede leer.");
    $w = imagesx($src); $h = imagesy($src);
    if ($w < 60 || $h < 25) throw new Exception("La imagen es muy pequeña para una firma.");
    // 1) Fondo blanco (las zonas transparentes quedan blancas)
    $plano = imagecreatetruecolor($w, $h);
    imagefill($plano, 0, 0, imagecolorallocate($plano, 255, 255, 255));
    imagecopy($plano, $src, 0, 0, 0, 0, $w, $h);
    imagedestroy($src);
    // 2) Recorte automático: el rectángulo que contiene el trazo (píxeles oscuros), con un margen pequeño
    $paso = max(1, (int)floor(max($w, $h) / 1200));   // en imágenes grandes se revisa uno de cada N píxeles
    $x1 = $w; $y1 = $h; $x2 = -1; $y2 = -1;
    for ($y = 0; $y < $h; $y += $paso) {
        for ($x = 0; $x < $w; $x += $paso) {
            $c = imagecolorat($plano, $x, $y);
            if (((($c >> 16) & 255) * 299 + (($c >> 8) & 255) * 587 + ($c & 255) * 114) / 1000 < 200) {
                if ($x < $x1) $x1 = $x; if ($x > $x2) $x2 = $x;
                if ($y < $y1) $y1 = $y; if ($y > $y2) $y2 = $y;
            }
        }
    }
    if ($x2 < 0) throw new Exception("No se ve ningún trazo: usa una firma oscura sobre fondo claro.");
    $m = (int)round(max($x2 - $x1, $y2 - $y1) * 0.04) + $paso;
    $x1 = max(0, $x1 - $m); $y1 = max(0, $y1 - $m); $x2 = min($w - 1, $x2 + $m); $y2 = min($h - 1, $y2 + $m);
    $cw = $x2 - $x1 + 1; $ch = $y2 - $y1 + 1;
    // 3) Tamaño final: máximo 900 × 400 px manteniendo la proporción
    $k = min(1, 900 / $cw, 400 / $ch);
    $nw = max(1, (int)round($cw * $k)); $nh = max(1, (int)round($ch * $k));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $plano, 0, 0, $x1, $y1, $nw, $nh, $cw, $ch);
    if (!imagepng($dst, $destino, 6)) throw new Exception("No se pudo guardar la firma.");
    imagedestroy($plano); imagedestroy($dst);
}
