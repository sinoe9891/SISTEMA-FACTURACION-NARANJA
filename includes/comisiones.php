<?php
/**
 * comisiones.php — Comisiones por venta.
 * En la factura (opcional) se elige un vendedor (colaborador o externo, con su número de identidad) y la comisión:
 * porcentaje del subtotal sin ISV o cantidad fija. Estados para el usuario:
 *   «Esperando cobro» (la factura aún no se paga) → «Por pagar» (el cliente ya pagó) → «Pagada» (se registró el gasto).
 * Al pagarla se crea un gasto (categoría «Comisiones por venta») y, si se elige la cuenta, sale del banco.
 * Requiere sql/migraciones/2026-10-27_comisiones.sql.
 */
require_once __DIR__ . '/bancos.php';

function comisionesDisponible(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try { $ok = (bool)$pdo->query("SHOW TABLES LIKE 'factura_comisiones'")->fetchColumn(); } catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

/** Vendedores de la empresa (los colaboradores activos que aún no están, se agregan solos al listar). */
function vendedoresLista(PDO $pdo, int $cid, bool $soloActivos = true): array
{
    if (!comisionesDisponible($pdo)) return [];
    // Los colaboradores activos aparecen como vendedores (con su DPI como identidad)
    try {
        $pdo->prepare("INSERT INTO vendedores (cliente_id, tipo, colaborador_id, nombre, identidad, telefono, email)
                       SELECT c.cliente_id, 'colaborador', c.id, TRIM(CONCAT(c.nombre, ' ', COALESCE(c.apellido, ''))), c.dpi, c.telefono, c.email FROM colaboradores c
                       WHERE c.cliente_id = ? AND c.activo = 1 AND NOT EXISTS (SELECT 1 FROM vendedores v WHERE v.colaborador_id = c.id AND v.cliente_id = c.cliente_id)")->execute([$cid]);
    } catch (Throwable $e) {
    }
    $st = $pdo->prepare("SELECT * FROM vendedores WHERE cliente_id = ?" . ($soloActivos ? " AND activo = 1" : "") . " ORDER BY tipo = 'colaborador' DESC, nombre");
    $st->execute([$cid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function vendedorGuardar(PDO $pdo, int $cid, array $d): array
{
    $id = (int)($d['id'] ?? 0);
    $nombre = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($d['nombre'] ?? ''))), 0, 150);
    if ($nombre === '') throw new Exception("Escribe el nombre del vendedor.");
    $identidad = mb_substr(preg_replace('/[^0-9A-Za-z-]/', '', (string)($d['identidad'] ?? '')), 0, 30) ?: null;
    $email = trim((string)($d['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception("Correo inválido.");
    if ($identidad) {
        $st = $pdo->prepare("SELECT nombre FROM vendedores WHERE cliente_id = ? AND identidad = ? AND id <> ?");
        $st->execute([$cid, $identidad, $id]);
        if ($otro = $st->fetchColumn()) throw new Exception("Ya existe un vendedor con esa identidad: $otro.");
    }
    $vals = [$nombre, $identidad, mb_substr(trim((string)($d['telefono'] ?? '')), 0, 40) ?: null, $email ?: null, mb_substr(trim((string)($d['notas'] ?? '')), 0, 255) ?: null,
        array_key_exists('activo', $d) ? (!empty($d['activo']) ? 1 : 0) : 1];
    if ($id) {
        $st = $pdo->prepare("UPDATE vendedores SET nombre = ?, identidad = ?, telefono = ?, email = ?, notas = ?, activo = ? WHERE id = ? AND cliente_id = ?");
        $st->execute([...$vals, $id, $cid]);
    } else {
        $pdo->prepare("INSERT INTO vendedores (nombre, identidad, telefono, email, notas, activo, cliente_id, tipo) VALUES (?, ?, ?, ?, ?, ?, ?, 'externo')")->execute([...$vals, $cid]);
        $id = (int)$pdo->lastInsertId();
    }
    // Cuenta para pagarle (banco y número), si la columna existe
    if (array_key_exists('cuenta_banco', $d)) {
        try { $pdo->prepare("UPDATE vendedores SET cuenta_banco = ? WHERE id = ? AND cliente_id = ?")->execute([mb_substr(trim((string)$d['cuenta_banco']), 0, 150) ?: null, $id, $cid]); } catch (PDOException $e) { }
    }
    $st = $pdo->prepare("SELECT * FROM vendedores WHERE id = ? AND cliente_id = ?");
    $st->execute([$id, $cid]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    if (!$v) throw new Exception("Vendedor no encontrado.");
    return $v;
}

/** Monto de la comisión: % del subtotal (sin ISV) o cantidad fija. */
function comisionCalcular(string $tipo, float $valor, float $subtotal): float
{
    return $tipo === 'fijo' ? round($valor, 2) : round($subtotal * $valor / 100, 2);
}

/**
 * Guarda (o quita) la comisión de una factura con lo que viene del formulario:
 * comision_activa, comision_vendedor_id, comision_tipo (porcentaje|fijo), comision_valor, comision_notas.
 * Sin el campo comision_activa en el formulario, no toca nada. Una comisión ya pagada no se cambia ni se quita.
 */
function comisionDesdeFactura(PDO $pdo, int $cid, int $uid, int $facturaId, array $d): void
{
    if (!comisionesDisponible($pdo) || !array_key_exists('comision_activa', $d)) return;
    $st = $pdo->prepare("SELECT * FROM factura_comisiones WHERE factura_id = ? AND cliente_id = ?");
    $st->execute([$facturaId, $cid]);
    $actual = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($actual && $actual['estado'] === 'pagada') {
        comisionRecalcular($pdo, $cid, $facturaId);   // solo actualiza la base si cambió el subtotal (no el monto pagado)
        return;
    }
    if (empty($d['comision_activa'])) {
        if ($actual) $pdo->prepare("DELETE FROM factura_comisiones WHERE id = ?")->execute([(int)$actual['id']]);
        return;
    }
    $vend = (int)($d['comision_vendedor_id'] ?? 0);
    $st = $pdo->prepare("SELECT COUNT(*) FROM vendedores WHERE id = ? AND cliente_id = ?");
    $st->execute([$vend, $cid]);
    if (!$st->fetchColumn()) throw new Exception("Elige el vendedor de la comisión (o quítala).");
    $tipo = ($d['comision_tipo'] ?? '') === 'fijo' ? 'fijo' : 'porcentaje';
    $valor = round((float)str_replace(',', '', (string)($d['comision_valor'] ?? 0)), 4);
    if ($valor <= 0) throw new Exception("Escribe el porcentaje o la cantidad de la comisión.");
    if ($tipo === 'porcentaje' && $valor > 100) throw new Exception("El porcentaje de comisión no puede pasar de 100.");
    $st = $pdo->prepare("SELECT subtotal FROM facturas WHERE id = ? AND cliente_id = ?");
    $st->execute([$facturaId, $cid]);
    $sub = (float)$st->fetchColumn();
    $monto = comisionCalcular($tipo, $valor, $sub);
    $notas = mb_substr(trim((string)($d['comision_notas'] ?? '')), 0, 255) ?: null;
    if ($actual) {
        $pdo->prepare("UPDATE factura_comisiones SET vendedor_id = ?, tipo = ?, valor = ?, base = ?, monto = ?, notas = ?, estado = 'pendiente' WHERE id = ?")
            ->execute([$vend, $tipo, $valor, $sub, $monto, $notas, (int)$actual['id']]);
    } else {
        $pdo->prepare("INSERT INTO factura_comisiones (cliente_id, factura_id, vendedor_id, tipo, valor, base, monto, notas, usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$cid, $facturaId, $vend, $tipo, $valor, $sub, $monto, $notas, $uid]);
    }
}

/** Si cambia el subtotal de la factura, la comisión en porcentaje (aún no pagada) se recalcula. */
function comisionRecalcular(PDO $pdo, int $cid, int $facturaId): void
{
    if (!comisionesDisponible($pdo)) return;
    $st = $pdo->prepare("SELECT c.*, f.subtotal, f.estado AS factura_estado FROM factura_comisiones c JOIN facturas f ON f.id = c.factura_id WHERE c.factura_id = ? AND c.cliente_id = ?");
    $st->execute([$facturaId, $cid]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c || $c['estado'] === 'pagada') return;
    if ($c['factura_estado'] === 'anulada') {   // factura anulada: no hay comisión
        $pdo->prepare("UPDATE factura_comisiones SET estado = 'anulada' WHERE id = ?")->execute([(int)$c['id']]);
        return;
    }
    $pdo->prepare("UPDATE factura_comisiones SET base = ?, monto = ?, estado = 'pendiente' WHERE id = ?")
        ->execute([(float)$c['subtotal'], comisionCalcular($c['tipo'], (float)$c['valor'], (float)$c['subtotal']), (int)$c['id']]);
}

/** Comisión de una factura (para mostrarla y editarla). */
function comisionDeFactura(PDO $pdo, int $cid, int $facturaId): ?array
{
    if (!comisionesDisponible($pdo)) return null;
    $st = $pdo->prepare("SELECT c.*, v.nombre AS vendedor, v.identidad FROM factura_comisiones c JOIN vendedores v ON v.id = c.vendedor_id WHERE c.factura_id = ? AND c.cliente_id = ?");
    $st->execute([$facturaId, $cid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Comisiones con su estado para el usuario: esperando (la factura no está pagada), por_pagar, pagada, anulada.
 * Filtros: vendedor, estado (esperando|por_pagar|pagada|anulada), desde, hasta (fecha de la factura).
 */
function comisionesLista(PDO $pdo, int $cid, array $f = []): array
{
    if (!comisionesDisponible($pdo)) return [];
    $w = ["c.cliente_id = ?"];
    $p = [$cid];
    if (!empty($f['vendedor'])) { $w[] = "c.vendedor_id = ?"; $p[] = (int)$f['vendedor']; }
    if (!empty($f['desde'])) { $w[] = "DATE(f.fecha_emision) >= ?"; $p[] = $f['desde']; }
    if (!empty($f['hasta'])) { $w[] = "DATE(f.fecha_emision) <= ?"; $p[] = $f['hasta']; }
    $st = $pdo->prepare("SELECT c.*, v.nombre AS vendedor, v.identidad, v.tipo AS vendedor_tipo, " . (comisionVendCuenta($pdo) ? "v.cuenta_banco" : "NULL AS cuenta_banco") . ", f.correlativo, DATE(f.fecha_emision) AS fecha_factura, f.subtotal, f.total, f.pagada, f.estado AS factura_estado,
                                cf.nombre AS cliente, g.fecha AS fecha_pago, g.metodo_pago, g.archivo_adjunto AS gasto_archivo,
                                (SELECT mb.cuenta_id FROM movimientos_bancarios mb WHERE mb.gasto_id = c.gasto_id AND mb.anulado = 0 AND mb.sentido = 'salida' ORDER BY mb.id LIMIT 1) AS cuenta_pago
                         FROM factura_comisiones c JOIN vendedores v ON v.id = c.vendedor_id JOIN facturas f ON f.id = c.factura_id
                         JOIN clientes_factura cf ON cf.id = f.receptor_id LEFT JOIN gastos g ON g.id = c.gasto_id
                         WHERE " . implode(' AND ', $w) . " ORDER BY f.fecha_emision DESC, c.id DESC");
    $st->execute($p);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $c['vista'] = $c['estado'] === 'pagada' ? 'pagada' : ($c['estado'] === 'anulada' || $c['factura_estado'] === 'anulada' ? 'anulada' : ((int)$c['pagada'] ? 'por_pagar' : 'esperando'));
        if (!empty($f['estado']) && $c['vista'] !== $f['estado']) continue;
        $out[] = $c;
    }
    return $out;
}

function comisionVendCuenta(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) { try { $ok = (bool)$pdo->query("SHOW COLUMNS FROM vendedores LIKE 'cuenta_banco'")->fetchColumn(); } catch (Throwable $e) { $ok = false; } }
    return $ok;
}

const COMISION_VISTAS = [
    'esperando' => ['Esperando cobro', '#f1f5f9', '#475569', 'bi-hourglass'],
    'por_pagar' => ['Por pagar', '#ffedd5', '#9a3412', 'bi-cash-coin'],
    'pagada' => ['Pagada', '#dcfce7', '#166534', 'bi-check-circle'],
    'anulada' => ['Anulada', '#fee2e2', '#991b1b', 'bi-x-circle'],
];

/** Categoría de Gastos para las comisiones (se crea la primera vez). */
function comisionCategoria(PDO $pdo, int $cid): int
{
    $st = $pdo->prepare("SELECT id FROM categorias_gastos WHERE cliente_id = ? AND nombre = 'Comisiones por venta' LIMIT 1");
    $st->execute([$cid]);
    if ($id = (int)$st->fetchColumn()) return $id;
    $pdo->prepare("INSERT INTO categorias_gastos (cliente_id, nombre, color, icono) VALUES (?, 'Comisiones por venta', '#0ea5e9', 'fa-handshake')")->execute([$cid]);
    return (int)$pdo->lastInsertId();
}

/**
 * Paga una comisión: crea el gasto (categoría «Comisiones por venta», pagado, con el vendedor como beneficiario) y, si se
 * elige la cuenta, la salida del banco. $adjunto = [archivo, nombre] del comprobante (carpeta de gastos). Devuelve el id del gasto.
 */
function comisionPagar(PDO $pdo, int $cid, int $uid, int $id, array $d, ?array $adjunto = null): int
{
    $st = $pdo->prepare("SELECT c.*, v.*, c.id AS id, c.estado AS estado, c.notas AS notas, v.nombre AS vendedor, f.correlativo, f.pagada, f.estado AS factura_estado FROM factura_comisiones c
                         JOIN vendedores v ON v.id = c.vendedor_id JOIN facturas f ON f.id = c.factura_id WHERE c.id = ? AND c.cliente_id = ? FOR UPDATE");
    $st->execute([$id, $cid]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) throw new Exception("Comisión no encontrada.");
    if ($c['estado'] === 'pagada') throw new Exception("La comisión ya está pagada.");
    if ($c['estado'] === 'anulada' || $c['factura_estado'] === 'anulada') throw new Exception("La factura está anulada: no hay comisión que pagar.");
    if (!(int)$c['pagada'] && empty($d['antes_de_cobro'])) throw new Exception("El cliente aún no paga la factura {$c['correlativo']}. Si igual la quieres pagar, confírmalo.");
    $fecha = bancoFecha((string)($d['fecha'] ?? ''));
    $metodo = in_array($d['metodo'] ?? '', ['efectivo', 'transferencia', 'cheque', 'tarjeta', 'otro'], true) ? $d['metodo'] : 'transferencia';
    $monto = round((float)str_replace(',', '', (string)($d['monto'] ?? $c['monto'])), 2);
    if ($monto <= 0) throw new Exception("El monto debe ser mayor que 0.");
    $desc = mb_substr('Comisión por venta: ' . $c['vendedor'] . ' · factura ' . $c['correlativo'], 0, 300);
    $pdo->prepare("INSERT INTO gastos (cliente_id, categoria_id, descripcion, monto, fecha, frecuencia, tipo, naturaleza, metodo_pago, proveedor, factura_ref, notas, archivo_adjunto, archivo_nombre, estado, usuario_id)
                   VALUES (?, ?, ?, ?, ?, 'unico', 'variable', 'gasto', ?, ?, ?, ?, ?, ?, 'pagado', ?)")
        ->execute([$cid, comisionCategoria($pdo, $cid), $desc, $monto, $fecha, $metodo, $c['vendedor'], $c['correlativo'],
            trim('Comisión #' . $id . ($c['identidad'] ? ' · identidad ' . $c['identidad'] : '') . (!empty($c['cuenta_banco']) ? ' · cuenta ' . $c['cuenta_banco'] : '') . '. ' . (string)($d['notas'] ?? '')), $adjunto[0] ?? null, $adjunto[1] ?? null, $uid]);
    $gid = (int)$pdo->lastInsertId();
    $cuenta = (int)($d['cuenta_id'] ?? 0);
    if ($cuenta) bancoSalidaGasto($pdo, $cid, $gid, $cuenta, $uid);
    $pdo->prepare("UPDATE factura_comisiones SET estado = 'pagada', gasto_id = ?, pagada_en = ?, monto = ? WHERE id = ?")->execute([$gid, $fecha, $monto, $id]);
    return $gid;
}

/** Deshace el pago: anula el gasto (y su salida del banco) y la comisión vuelve a «por pagar». */
/** Corrige el pago de una comisión: anula el gasto y su salida del banco y registra el pago con los datos nuevos.
 *  Los comprobantes que tenía se quedan y los nuevos se agregan. Devuelve el id del gasto nuevo. */
function comisionEditarPago(PDO $pdo, int $cid, int $uid, int $id, array $d, ?array $adjunto = null): int
{
    $st = $pdo->prepare("SELECT g.archivo_adjunto, g.archivo_nombre FROM factura_comisiones c LEFT JOIN gastos g ON g.id = c.gasto_id WHERE c.id = ? AND c.cliente_id = ? AND c.estado = 'pagada'");
    $st->execute([$id, $cid]);
    $antes = $st->fetch(PDO::FETCH_ASSOC);
    if (!$antes) throw new Exception("La comisión no está pagada.");
    // Los comprobantes que ya tenía se quedan (los de antes de poder subir varios pasan a la lista)
    if ($antes['archivo_adjunto'] && !comisionArchivos($pdo, $cid, [$id])) comisionArchivosAgregar($pdo, $cid, $uid, $id, [[$antes['archivo_adjunto'], $antes['archivo_nombre']]]);
    $actuales = comisionArchivos($pdo, $cid, [$id])[$id] ?? [];
    if ($actuales) $adjunto = [$actuales[0]['archivo'], $actuales[0]['nombre']];   // el principal sigue siendo el primero de la lista
    comisionAnularPago($pdo, $cid, $id, true);
    return comisionPagar($pdo, $cid, $uid, $id, ['antes_de_cobro' => 1] + $d, $adjunto);
}

function comisionAnularPago(PDO $pdo, int $cid, int $id, bool $conservarArchivos = false): void
{
    $st = $pdo->prepare("SELECT * FROM factura_comisiones WHERE id = ? AND cliente_id = ?");
    $st->execute([$id, $cid]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c || $c['estado'] !== 'pagada') throw new Exception("La comisión no está pagada.");
    if ($c['gasto_id']) {
        $st = $pdo->prepare("SELECT id, conciliado FROM movimientos_bancarios WHERE gasto_id = ? AND cliente_id = ? AND anulado = 0");
        $st->execute([(int)$c['gasto_id'], $cid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
            if ((int)$m['conciliado']) throw new Exception("La salida del banco ya está conciliada: quita la conciliación primero.");
            $pdo->prepare("UPDATE movimientos_bancarios SET anulado = 1 WHERE id = ?")->execute([(int)$m['id']]);
        }
        $pdo->prepare("UPDATE gastos SET estado = 'anulado' WHERE id = ? AND cliente_id = ?")->execute([(int)$c['gasto_id'], $cid]);
        $stc = $pdo->prepare("SELECT cuenta_id FROM movimientos_bancarios WHERE gasto_id = ? LIMIT 1");
        $stc->execute([(int)$c['gasto_id']]);
        if ($cta = (int)$stc->fetchColumn()) bancoLeyPoblacionalSincronizar($pdo, $cid, $cta);
    }
    $pdo->prepare("UPDATE factura_comisiones SET estado = 'pendiente', gasto_id = NULL, pagada_en = NULL WHERE id = ?")->execute([$id]);
    // Anulado el pago, sus comprobantes quedan solo en el gasto anulado
    if (!$conservarArchivos && comisionArchivosDisponible($pdo)) $pdo->prepare("DELETE FROM comision_archivos WHERE comision_id = ? AND cliente_id = ?")->execute([$id, $cid]);
    comisionRecalcular($pdo, $cid, (int)$c['factura_id']);
}

// ── Comprobantes del pago (uno o varios) ───────────────────────────────────

function comisionArchivosDisponible(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try { $pdo->query("SELECT 1 FROM comision_archivos LIMIT 0"); $ok = true; } catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

/** $_FILES de un input múltiple (name="comprobantes[]") → lista de archivos sueltos (también acepta uno solo). */
function comisionArchivosPost(?array $f): array
{
    if (!$f || !isset($f['name'])) return [];
    if (!is_array($f['name'])) return [$f];
    $out = [];
    foreach ($f['name'] as $i => $n) $out[] = ['name' => $n, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    return $out;
}

/** Registra comprobantes [[archivo, nombre], …] en la comisión y deja el primero en su gasto (y su salida del banco) si no tiene. */
function comisionArchivosAgregar(PDO $pdo, int $cid, int $uid, int $id, array $guardados): void
{
    if (!$guardados || !comisionArchivosDisponible($pdo)) return;
    $ins = $pdo->prepare("INSERT INTO comision_archivos (cliente_id, comision_id, archivo, nombre, usuario_id) VALUES (?, ?, ?, ?, ?)");
    foreach ($guardados as [$a, $n]) $ins->execute([$cid, $id, $a, $n, $uid]);
    $st = $pdo->prepare("SELECT gasto_id FROM factura_comisiones WHERE id = ? AND cliente_id = ?");
    $st->execute([$id, $cid]);
    if ($gid = (int)$st->fetchColumn()) comisionArchivoPrincipal($pdo, $cid, $gid, $guardados[0], true);
}

/** Pone el comprobante principal del gasto de la comisión (y de su salida del banco). $soloSiFalta: no reemplaza uno que ya tenga. */
function comisionArchivoPrincipal(PDO $pdo, int $cid, int $gid, ?array $a, bool $soloSiFalta = false): void
{
    $cond = $soloSiFalta ? " AND archivo_adjunto IS NULL" : "";
    $pdo->prepare("UPDATE gastos SET archivo_adjunto = ?, archivo_nombre = ? WHERE id = ? AND cliente_id = ?$cond")->execute([$a[0] ?? null, $a[1] ?? null, $gid, $cid]);
    try {
        $pdo->prepare("UPDATE movimientos_bancarios SET archivo_adjunto = ?, archivo_nombre = ? WHERE gasto_id = ? AND cliente_id = ? AND anulado = 0$cond")->execute([$a[0] ?? null, $a[1] ?? null, $gid, $cid]);
    } catch (PDOException $e) {
        // sin la columna de comprobante en movimientos
    }
}

/** Comprobantes de varias comisiones: [comision_id => [[id, archivo, nombre], …]]. */
function comisionArchivos(PDO $pdo, int $cid, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids || !comisionArchivosDisponible($pdo)) return [];
    $st = $pdo->prepare("SELECT id, comision_id, archivo, nombre FROM comision_archivos WHERE cliente_id = ? AND comision_id IN (" . implode(',', $ids) . ") ORDER BY id");
    $st->execute([$cid]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) $out[(int)$a['comision_id']][] = $a;
    return $out;
}

/** Quita un comprobante del pago. Si era el principal del gasto, pasa el siguiente a su lugar. */
function comisionArchivoQuitar(PDO $pdo, int $cid, int $archivoId): void
{
    $st = $pdo->prepare("SELECT a.*, c.gasto_id FROM comision_archivos a JOIN factura_comisiones c ON c.id = a.comision_id WHERE a.id = ? AND a.cliente_id = ?");
    $st->execute([$archivoId, $cid]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) throw new Exception("Comprobante no encontrado.");
    $pdo->prepare("DELETE FROM comision_archivos WHERE id = ?")->execute([$archivoId]);
    if (!$a['gasto_id']) return;
    $st = $pdo->prepare("SELECT archivo_adjunto FROM gastos WHERE id = ?");
    $st->execute([(int)$a['gasto_id']]);
    if ($st->fetchColumn() !== $a['archivo']) return;
    $sig = comisionArchivos($pdo, $cid, [(int)$a['comision_id']])[(int)$a['comision_id']][0] ?? null;
    comisionArchivoPrincipal($pdo, $cid, (int)$a['gasto_id'], $sig ? [$sig['archivo'], $sig['nombre']] : null);
}

/** Recibo de pago de una comisión (PDF) para que lo firme quien la recibe. Solo comisiones pagadas. */
function comisionReciboPdf(PDO $pdo, int $cid, int $id): string
{
    require_once __DIR__ . '/functions.php';
    require_once __DIR__ . '/cotizaciones.php';   // logo de la empresa
    require_once __DIR__ . '/firmantes.php';
    $st = $pdo->prepare("SELECT c.id, c.tipo, c.valor, c.base, c.monto, c.estado, c.pagada_en, c.gasto_id, c.notas,
                                v.nombre AS vendedor, v.identidad, v.tipo AS vendedor_tipo, " . (comisionVendCuenta($pdo) ? "v.cuenta_banco" : "NULL AS cuenta_banco") . ",
                                f.correlativo, DATE(f.fecha_emision) AS fecha_factura, cf.nombre AS cliente, g.metodo_pago
                         FROM factura_comisiones c JOIN vendedores v ON v.id = c.vendedor_id JOIN facturas f ON f.id = c.factura_id
                         JOIN clientes_factura cf ON cf.id = f.receptor_id LEFT JOIN gastos g ON g.id = c.gasto_id
                         WHERE c.id = ? AND c.cliente_id = ?");
    $st->execute([$id, $cid]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) throw new Exception("Comisión no encontrada.");
    if ($c['estado'] !== 'pagada') throw new Exception("La comisión aún no está pagada.");
    $st = $pdo->prepare("SELECT * FROM clientes_saas WHERE id = ?");
    $st->execute([$cid]);
    $emp = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
    $alias = ($emp['alias'] ?? '') ?: ($emp['nombre'] ?? '');
    $logo = cotizacionLogoB64($emp);
    $f = firmantesParaPdf($pdo, $cid, __DIR__ . '/../clientes/naranjaymedia/includes/uploads');
    $metodos = ['transferencia' => 'Transferencia', 'efectivo' => 'Efectivo', 'cheque' => 'Cheque', 'tarjeta' => 'Tarjeta', 'otro' => 'Otro'];
    $calculo = $c['tipo'] === 'fijo' ? 'Cantidad fija' : rtrim(rtrim(number_format((float)$c['valor'], 2), '0'), '.') . ' % del subtotal sin ISV (L ' . number_format((float)$c['base'], 2) . ')';
    $num = 'C-' . str_pad((string)$c['id'], 5, '0', STR_PAD_LEFT);
    $celda = fn(string $titulo, string $nombre, string $sub, string $img) => '<td style="width:33%;text-align:center;padding:0 10px;vertical-align:top">'
        . '<div style="height:50px">' . ($img ? '<img src="' . $img . '" style="max-height:50px;max-width:150px">' : '') . '</div>'
        . '<div style="border-top:1px solid #334155;padding-top:4px">' . ($nombre !== '' ? '<strong>' . $e($nombre) . '</strong>' : '&nbsp;') . '<br><span class="m">' . ($sub !== '' ? $e($sub) : '&nbsp;') . '</span>'
        . '<br><span class="m">' . $titulo . '</span></div></td>';
    $fila = fn($k, $v) => '<tr><td class="k">' . $k . '</td><td>' . $v . '</td></tr>';
    $html = '<!doctype html><html><head><meta charset="utf-8"><style>
@page { margin: 34px 40px 40px; } body { font-family: "DejaVu Sans", sans-serif; font-size: 9.6pt; color: #1e293b; }
.top { width:100%; border-bottom:3px solid #e8590c; padding-bottom:8px; margin-bottom:16px; } h1 { font-size:14pt; margin:0; white-space:nowrap; } .m { color:#64748b; font-size:8.4pt; }
.monto { background:#fff7f2; border:1px solid #fde1d0; border-radius:8px; padding:12px 16px; margin:12px 0 16px; }
.monto .v { font-size:18pt; font-weight:bold; color:#0f172a; } table.d { width:100%; border-collapse:collapse; } table.d td { padding:6px 4px; border-bottom:1px solid #e2e8f0; vertical-align:top; } .k { color:#64748b; width:34%; }
</style></head><body>
<table class="top"><tr><td>' . ($logo ? '<img src="' . $logo . '" style="height:48px">' : '<strong style="color:#e8590c;font-size:14pt">' . $e($alias) . '</strong>') . '<div class="m">' . $e($emp['nombre'] ?? '') . ($emp['rtn'] ?? '' ? ' · RTN ' . $e($emp['rtn']) : '') . '</div></td>
<td style="text-align:right"><h1>Recibo de pago de comisión</h1><div class="m">N.º ' . $num . ' · ' . date('d/m/Y', strtotime((string)$c['pagada_en'])) . '</div></td></tr></table>
<p>Recibí de <strong>' . $e($emp['nombre'] ?? $alias) . '</strong> la cantidad de:</p>
<div class="monto"><div class="v">L ' . number_format((float)$c['monto'], 2) . '</div><div class="m">' . $e(numeroALetras((float)$c['monto'])) . '</div></div>
<p>en concepto de <strong>comisión por venta</strong> de la factura ' . $e($c['correlativo']) . ' del ' . date('d/m/Y', strtotime($c['fecha_factura'])) . ' al cliente ' . $e($c['cliente']) . '.</p>
<table class="d">'
        . $fila('Recibe', '<strong>' . $e($c['vendedor']) . '</strong>' . ($c['vendedor_tipo'] === 'colaborador' ? ' (colaborador)' : ''))
        . $fila('Identidad', $e($c['identidad'] ?: '—'))
        . $fila('Cálculo de la comisión', $e($calculo))
        . $fila('Forma de pago', $e($metodos[$c['metodo_pago']] ?? 'Transferencia') . ($c['cuenta_banco'] ? ' a la cuenta ' . $e($c['cuenta_banco']) : ''))
        . ($c['notas'] ? $fila('Notas', $e($c['notas'])) : '')
        . '</table>
<table style="width:100%;margin-top:70px"><tr>'
        . $celda('Recibí conforme', $c['vendedor'], $c['identidad'] ? 'ID ' . $c['identidad'] : '', '')
        . $celda('Autorizado por', (string)($f['autorizado']['nombre'] ?? ''), (string)($f['autorizado']['cargo'] ?? ''), (string)($f['autorizado']['firma_b64'] ?? ''))
        . $celda('Vo.Bo.', (string)($f['vobo']['nombre'] ?? ''), (string)($f['vobo']['cargo'] ?? ''), (string)($f['vobo']['firma_b64'] ?? ''))
        . '</tr></table></body></html>';
    require_once __DIR__ . '/../vendor/autoload.php';
    $opt = new \Dompdf\Options();
    $opt->set('defaultFont', 'DejaVu Sans');
    $opt->set('isRemoteEnabled', false);
    $pdf = new \Dompdf\Dompdf($opt);
    $pdf->loadHtml($html, 'UTF-8');
    $pdf->setPaper('letter', 'portrait');
    $pdf->render();
    return (string)$pdf->output();
}
