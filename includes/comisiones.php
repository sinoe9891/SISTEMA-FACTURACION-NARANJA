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
    $st = $pdo->prepare("SELECT c.*, v.nombre AS vendedor, v.identidad, v.tipo AS vendedor_tipo, f.correlativo, DATE(f.fecha_emision) AS fecha_factura, f.subtotal, f.total, f.pagada, f.estado AS factura_estado,
                                cf.nombre AS cliente, g.fecha AS fecha_pago, g.metodo_pago, g.archivo_adjunto AS gasto_archivo
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
    $st = $pdo->prepare("SELECT c.*, v.nombre AS vendedor, v.identidad, f.correlativo, f.pagada, f.estado AS factura_estado FROM factura_comisiones c
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
            trim('Comisión #' . $id . ($c['identidad'] ? ' · identidad ' . $c['identidad'] : '') . '. ' . (string)($d['notas'] ?? '')), $adjunto[0] ?? null, $adjunto[1] ?? null, $uid]);
    $gid = (int)$pdo->lastInsertId();
    $cuenta = (int)($d['cuenta_id'] ?? 0);
    if ($cuenta) bancoSalidaGasto($pdo, $cid, $gid, $cuenta, $uid);
    $pdo->prepare("UPDATE factura_comisiones SET estado = 'pagada', gasto_id = ?, pagada_en = ?, monto = ? WHERE id = ?")->execute([$gid, $fecha, $monto, $id]);
    return $gid;
}

/** Deshace el pago: anula el gasto (y su salida del banco) y la comisión vuelve a «por pagar». */
function comisionAnularPago(PDO $pdo, int $cid, int $id): void
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
    comisionRecalcular($pdo, $cid, (int)$c['factura_id']);
}
