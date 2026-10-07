<?php
/**
 * documentos.php — Documentos de la empresa (Constancia de pago a cuenta del SAR, solvencias, permisos…)
 * con fecha de vencimiento. Se adjuntan a los cobros por correo y avisan cuando vencen.
 *   docsDisponible($pdo)                     → ¿migración 2026-10-09_documentos_empresa instalada?
 *   docsLista($pdo, $cid)                    → documentos con 'dias' (para vencer; negativo = vencido) y 'estado'
 *   docGuardar($pdo, $cid, $uid, $datos, $archivo)  → id (crea, o edita con $datos['id']; el archivo es opcional al editar)
 *   docEliminar($pdo, $cid, $id)
 *   docSemaforo($doc)                        → [fondo, texto, etiqueta] (vencido / por vencer / vigente)
 * Archivos: clientes/naranjaymedia/includes/uploads/documentos/<cid>/ (se sirven con includes/documento_accion.php).
 */

const DOC_AVISO_DIAS = 30;          // «por vencer» desde 30 días antes
const DOC_MAX_BYTES = 10 * 1024 * 1024;
const DOC_TIPOS = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

function docsDisponible(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try { $ok = (bool)$pdo->query("SHOW TABLES LIKE 'empresa_documentos'")->fetchColumn() && (bool)$pdo->query("SHOW TABLES LIKE 'cobros_programados_documentos'")->fetchColumn(); } catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

function docDir(int $cid): string
{
    return __DIR__ . '/../clientes/naranjaymedia/includes/uploads/documentos/' . $cid . '/';
}

/** Días para vencer (negativo = vencido; null = no vence) y estado. */
function docEstado(array $d): array
{
    if (empty($d['fecha_vencimiento'])) return $d + ['dias' => null, 'estado' => 'sin_vencimiento'];
    $dias = (int)(new DateTime('today'))->diff(new DateTime($d['fecha_vencimiento']))->format('%r%a');
    return $d + ['dias' => $dias, 'estado' => $dias < 0 ? 'vencido' : ($dias <= DOC_AVISO_DIAS ? 'por_vencer' : 'vigente')];
}

function docsLista(PDO $pdo, int $cid): array
{
    if (!docsDisponible($pdo)) return [];
    $st = $pdo->prepare("SELECT * FROM empresa_documentos WHERE cliente_id = ? ORDER BY fecha_vencimiento IS NULL, fecha_vencimiento, nombre");
    $st->execute([$cid]);
    return array_map('docEstado', $st->fetchAll(PDO::FETCH_ASSOC));
}

function docObtener(PDO $pdo, int $cid, int $id): array
{
    $st = $pdo->prepare("SELECT * FROM empresa_documentos WHERE id = ? AND cliente_id = ?");
    $st->execute([$id, $cid]);
    $d = $st->fetch(PDO::FETCH_ASSOC);
    if (!$d) throw new Exception("Documento no encontrado.");
    return docEstado($d);
}

/** Semáforo por días que faltan (verde no: verde es solo para lo pagado). */
function docSemaforo(array $d): array
{
    $dias = $d['dias'];
    if ($dias === null) return ['#f1f5f9', '#475569', 'No vence'];
    if ($dias < 0) return ['#fee2e2', '#991b1b', 'Vencido hace ' . abs($dias) . ' d'];
    if ($dias === 0) return ['#fee2e2', '#991b1b', 'Vence hoy'];
    if ($dias <= 7) return ['#ffedd5', '#9a3412', 'Vence en ' . $dias . ' d'];
    if ($dias <= DOC_AVISO_DIAS) return ['#fef9c3', '#854d0e', 'Vence en ' . $dias . ' d'];
    return ['#f1f5f9', '#475569', 'Vigente · ' . $dias . ' d'];
}

/**
 * Crea o edita un documento. $archivo = entrada de $_FILES (obligatoria al crear; al editar, si viene, reemplaza
 * el archivo: así se «renueva» la constancia con el archivo nuevo y su nueva fecha de vencimiento).
 */
function docGuardar(PDO $pdo, int $cid, int $uid, array $d, ?array $archivo): int
{
    $id = (int)($d['id'] ?? 0);
    $nombre = trim((string)($d['nombre'] ?? ''));
    if ($nombre === '') throw new Exception("Escribe el nombre del documento.");
    $fecha = function ($k) use ($d) {
        $v = trim((string)($d[$k] ?? ''));
        if ($v === '') return null;
        $dt = DateTime::createFromFormat('!Y-m-d', $v);
        if (!$dt || $dt->format('Y-m-d') !== $v) throw new Exception("Fecha inválida.");
        return $v;
    };
    $emision = $fecha('fecha_emision');
    $vence = $fecha('fecha_vencimiento');
    if ($emision && $vence && $vence < $emision) throw new Exception("El vencimiento no puede ser antes de la emisión.");
    $previo = $id ? docObtener($pdo, $cid, $id) : null;

    $nuevo = null;
    $hayArchivo = $archivo && ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($hayArchivo) {
        if ($archivo['error'] !== UPLOAD_ERR_OK) throw new Exception("No se pudo subir el archivo (código {$archivo['error']}).");
        if ((int)$archivo['size'] > DOC_MAX_BYTES) throw new Exception("El archivo supera 10 MB.");
        $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']);
        if (!isset(DOC_TIPOS[$mime])) throw new Exception("Solo PDF, JPG, PNG o WEBP.");
        $dir = docDir($cid);
        if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new Exception("No se pudo crear la carpeta de documentos.");
        $nuevo = ['archivo' => 'doc_' . bin2hex(random_bytes(10)) . '.' . DOC_TIPOS[$mime], 'mime' => $mime, 'tamano' => (int)$archivo['size'],
                  'archivo_nombre' => mb_substr(preg_replace('/[^\w\s.\-()áéíóúñÁÉÍÓÚÑ]/u', '_', basename((string)$archivo['name'])), 0, 200)];
        $mover = is_uploaded_file($archivo['tmp_name']) ? 'move_uploaded_file' : 'copy';   // copy: pruebas automáticas
        if (!$mover($archivo['tmp_name'], $dir . $nuevo['archivo'])) throw new Exception("No se pudo guardar el archivo.");
    } elseif (!$id) {
        throw new Exception("Adjunta el archivo del documento (PDF o imagen).");
    }

    $campos = [$nombre, $emision, $vence, !empty($d['adjuntar_por_defecto']) ? 1 : 0, mb_substr(trim((string)($d['notas'] ?? '')), 0, 255) ?: null];
    try {
        if ($id) {
            $pdo->prepare("UPDATE empresa_documentos SET nombre = ?, fecha_emision = ?, fecha_vencimiento = ?, adjuntar_por_defecto = ?, notas = ?" .
                          ($nuevo ? ", archivo = ?, archivo_nombre = ?, mime = ?, tamano = ?" : '') . " WHERE id = ? AND cliente_id = ?")
                ->execute(array_merge($campos, $nuevo ? [$nuevo['archivo'], $nuevo['archivo_nombre'], $nuevo['mime'], $nuevo['tamano']] : [], [$id, $cid]));
        } else {
            $pdo->prepare("INSERT INTO empresa_documentos (nombre, fecha_emision, fecha_vencimiento, adjuntar_por_defecto, notas, archivo, archivo_nombre, mime, tamano, cliente_id, usuario_id)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute(array_merge($campos, [$nuevo['archivo'], $nuevo['archivo_nombre'], $nuevo['mime'], $nuevo['tamano'], $cid, $uid]));
            $id = (int)$pdo->lastInsertId();
        }
    } catch (Throwable $e) {
        if ($nuevo) @unlink(docDir($cid) . $nuevo['archivo']);
        throw $e;
    }
    // El archivo anterior ya no se usa (los cobros guardan su propia copia)
    if ($nuevo && $previo) @unlink(docDir($cid) . $previo['archivo']);
    return $id;
}

function docEliminar(PDO $pdo, int $cid, int $id): void
{
    $d = docObtener($pdo, $cid, $id);
    $pdo->prepare("DELETE FROM empresa_documentos WHERE id = ? AND cliente_id = ?")->execute([$id, $cid]);
    @unlink(docDir($cid) . $d['archivo']);
}

/** Nombre con que va adjunto en el correo: «Constancia de pago a cuenta SAR.pdf». */
function docNombreAdjunto(array $d): string
{
    $ext = pathinfo($d['archivo'], PATHINFO_EXTENSION);
    return trim(preg_replace('/[\\\\\/:*?"<>|]+/', ' ', $d['nombre'])) . '.' . $ext;
}
