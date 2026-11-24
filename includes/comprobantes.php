<?php
/**
 * comprobantes.php — Comprobante opcional (JPG, PNG, WEBP o PDF) de cheques, préstamos recibidos y activos fijos.
 * Los archivos se guardan como los de gastos (includes/uploads/gastos); aquí solo se liga el archivo con el registro.
 * Ver: comprobante_archivo.php (ver), includes/comprobante_accion.php (adjuntar, cambiar, quitar).
 */
const COMPROBANTE_TABLAS = ['cheque' => 'cheques', 'prestamo' => 'prestamos_recibidos', 'activo' => 'activos_fijos', 'gasto' => 'gastos'];
/** Quién puede adjuntar, cambiar o quitar el comprobante (el mismo rol que administra ese registro). */
const COMPROBANTE_ROLES = ['cheque' => ['admin', 'superadmin', 'nomina'], 'prestamo' => ['admin', 'superadmin'], 'activo' => ['admin', 'superadmin'], 'gasto' => ['admin', 'superadmin', 'nomina']];

function comprobanteDisponible(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try { $ok = (bool)$pdo->query("SHOW COLUMNS FROM cheques LIKE 'archivo_adjunto'")->fetchColumn(); } catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

function comprobantePuede(string $tipo, string $rol): bool
{
    return in_array($rol, COMPROBANTE_ROLES[$tipo] ?? [], true);
}

/** Fila (id, archivo_adjunto, archivo_nombre) del registro, solo de la misma empresa. */
function comprobanteRegistro(PDO $pdo, int $cid, string $tipo, int $id): array
{
    if (!isset(COMPROBANTE_TABLAS[$tipo])) throw new Exception("Tipo inválido.");
    $st = $pdo->prepare("SELECT id, archivo_adjunto, archivo_nombre FROM " . COMPROBANTE_TABLAS[$tipo] . " WHERE id = ? AND cliente_id = ?");
    $st->execute([$id, $cid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new Exception("Registro no encontrado.");
    return $r;
}

/** Liga un archivo ya guardado ([archivo, nombre]) al registro, o quita el que tenía. */
function comprobanteAsignar(PDO $pdo, int $cid, string $tipo, int $id, ?array $adjunto, bool $quitar = false): void
{
    comprobanteRegistro($pdo, $cid, $tipo, $id);
    $t = COMPROBANTE_TABLAS[$tipo];
    if ($adjunto) $pdo->prepare("UPDATE $t SET archivo_adjunto = ?, archivo_nombre = ? WHERE id = ? AND cliente_id = ?")->execute([$adjunto[0], $adjunto[1], $id, $cid]);
    elseif ($quitar) $pdo->prepare("UPDATE $t SET archivo_adjunto = NULL, archivo_nombre = NULL WHERE id = ? AND cliente_id = ?")->execute([$id, $cid]);
}

/**
 * Botones de una fila: ver el comprobante (vista previa) y adjuntar / cambiar / quitar.
 * $detalle: texto extra para el título (p. ej. de qué pago es). $alerta: si falta, el botón sale en ámbar.
 */
function comprobanteBotonesFila(string $tipo, int $id, bool $tiene, bool $puede, string $detalle = '', bool $alerta = false): string
{
    $h = '';
    $ver = $tipo === 'gasto' ? 'gasto_archivo?id=' . $id : 'comprobante_archivo?tipo=' . $tipo . '&id=' . $id;   // los gastos ya tienen su propio visor
    $sufijo = $detalle !== '' ? ' (' . htmlspecialchars($detalle, ENT_QUOTES) . ')' : '';
    if ($tiene) $h .= '<a href="' . $ver . '" target="_blank" class="btn btn-sm btn-outline-success" title="Ver el comprobante' . $sufijo . '"><i class="bi bi-paperclip"></i></a> ';
    if ($puede) {
        $cls = !$tiene && $alerta ? 'btn-outline-warning' : 'btn-outline-secondary';
        $titulo = $tiene ? 'Cambiar o quitar el comprobante' . $sufijo : ($alerta ? 'Falta el comprobante' . $sufijo . ': clic para adjuntarlo' : 'Adjuntar un comprobante (opcional)' . $sufijo);
        $h .= '<button type="button" class="btn btn-sm ' . $cls . ' btn-comp" data-tipo="' . $tipo . '" data-id="' . $id . '" data-tiene="' . ($tiene ? 1 : 0) . '" title="' . $titulo . '"><i class="bi bi-'
            . ($tiene ? 'pencil-square' : ($alerta ? 'exclamation-triangle' : 'cloud-upload')) . '"></i></button> ';
    }
    return $h;
}
