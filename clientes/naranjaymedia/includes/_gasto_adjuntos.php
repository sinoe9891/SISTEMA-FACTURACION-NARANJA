<?php
// clientes/naranjaymedia/includes/_gasto_adjuntos.php
// Utilidades compartidas para comprobantes de gastos (solo funciones, sin salida).
// Lo usan gasto_guardar, gasto_actualizar, gasto_marcar_pagado y gasto_eliminar.

const GASTO_ADJ_MAX_BYTES = 5 * 1024 * 1024;
const GASTO_ADJ_TIPOS = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'application/pdf' => 'pdf',
];

function gastoAdjuntoDir(): string
{
    return __DIR__ . '/uploads/gastos/';
}

/**
 * Procesa un comprobante opcional.
 * Devuelve [archivo_guardado, nombre_original] o null si no se envió archivo.
 * Lanza Exception con un mensaje claro si el archivo se envió pero no es válido
 * (antes estos casos se ignoraban y el gasto quedaba sin comprobante sin avisar).
 */
function guardarAdjuntoGasto(?array $file, int $cid): ?array
{
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if (!$file || $error === UPLOAD_ERR_NO_FILE || ($file['name'] ?? '') === '') return null;

    switch ($error) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            throw new Exception("El comprobante supera el tamaño máximo permitido por el servidor (" . ini_get('upload_max_filesize') . ").");
        case UPLOAD_ERR_PARTIAL:
            throw new Exception("El comprobante no se subió completo. Intenta de nuevo.");
        default:
            throw new Exception("No se pudo subir el comprobante (código $error).");
    }

    if (!is_uploaded_file($file['tmp_name'])) throw new Exception("Archivo temporal inválido.");
    if ($file['size'] > GASTO_ADJ_MAX_BYTES) throw new Exception("El comprobante supera 5 MB.");

    // Validar por contenido real, no solo por la extensión del nombre
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(GASTO_ADJ_TIPOS[$mime])) throw new Exception("Tipo de archivo no permitido. Solo JPG, PNG, WEBP o PDF.");

    $dir = gastoAdjuntoDir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new Exception("No se pudo crear la carpeta de comprobantes.");
    if (!is_writable($dir)) throw new Exception("La carpeta de comprobantes no tiene permisos de escritura.");

    $archivo = 'gasto_' . $cid . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . GASTO_ADJ_TIPOS[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . $archivo)) throw new Exception("No se pudo guardar el comprobante.");

    return [$archivo, mb_substr(basename($file['name']), 0, 255)];
}

/**
 * Borra un comprobante de gastos del disco si ya ningún gasto lo referencia.
 * Solo actúa sobre uploads/gastos (los de nómina se gestionan en su módulo).
 */
function borrarAdjuntoGastoSiHuerfano(PDO $pdo, ?string $archivo): void
{
    if (!$archivo || strpos($archivo, '/') !== false) return;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM gastos WHERE archivo_adjunto = ?");
    $stmt->execute([$archivo]);
    if ((int)$stmt->fetchColumn() > 0) return;
    $ruta = gastoAdjuntoDir() . basename($archivo);
    if (is_file($ruta)) @unlink($ruta);
}

/** Métodos de pago válidos (coinciden con el ENUM de gastos.metodo_pago). */
const GASTO_METODOS_PAGO = ['efectivo', 'transferencia', 'cheque', 'tarjeta', 'otro'];

/** Devuelve el id si la categoría pertenece al cliente; si no, null. */
function categoriaGastoValida(PDO $pdo, ?int $categoria_id, int $cid): ?int
{
    if (!$categoria_id) return null;
    $stmt = $pdo->prepare("SELECT id FROM categorias_gastos WHERE id = ? AND cliente_id = ?");
    $stmt->execute([$categoria_id, $cid]);
    if (!$stmt->fetchColumn()) throw new Exception("Categoría no válida.");
    return $categoria_id;
}

/**
 * Devuelve el id si la tarjeta pertenece al cliente (y está activa, si se pide).
 * Al editar un gasto viejo se permite su tarjeta aunque hoy esté desactivada.
 */
function tarjetaGastoValida(PDO $pdo, ?int $tarjeta_id, int $cid, bool $soloActivas = true): ?int
{
    if (!$tarjeta_id) return null;
    $stmt = $pdo->prepare("SELECT id FROM tarjetas WHERE id = ? AND cliente_id = ?" . ($soloActivas ? " AND activa = 1" : ""));
    $stmt->execute([$tarjeta_id, $cid]);
    if (!$stmt->fetchColumn()) throw new Exception("Tarjeta no válida.");
    return $tarjeta_id;
}
