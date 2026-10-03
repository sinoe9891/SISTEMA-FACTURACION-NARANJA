<?php
/**
 * inventario.php — Existencias por tienda, kardex, entradas, ajustes, traslados y
 * descuento automático al facturar. Tablas: sql/migraciones/2026-10-03_inventario.sql
 *
 * Reglas:
 * - Solo los productos tipo "bien" llevan inventario; los "servicio" no se tocan.
 * - Existencia física = cantidad; disponible = cantidad − reservado.
 * - Todo descuento es atómico (UPDATE … WHERE disponible >= n): dos ventas simultáneas
 *   nunca dejan la existencia negativa ni venden lo que no hay.
 * - Cada cambio queda en el kardex (inv_movimientos) con el saldo resultante.
 * - Costo: promedio ponderado global del producto, recalculado en cada entrada con costo.
 */

const INV_SUMA  = ['entrada', 'ajuste_entrada', 'anulacion_venta', 'traslado_entrada'];
const INV_RESTA = ['ajuste_salida', 'venta', 'traslado_salida'];

function invDisponible(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = (bool)$pdo->query("SHOW TABLES LIKE 'inv_movimientos'")->fetchColumn();
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

function invCantidad($c): float
{
    if (!is_numeric($c) || (float)$c <= 0) throw new Exception("La cantidad debe ser mayor que 0.");
    return round((float)$c, 3);
}

function invProducto(PDO $pdo, int $cid, int $pid, bool $soloBien = true): array
{
    $stmt = $pdo->prepare("SELECT * FROM productos_clientes WHERE id = ? AND cliente_id = ?");
    $stmt->execute([$pid, $cid]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) throw new Exception("Producto no encontrado.");
    if ($soloBien && ($p['tipo'] ?? 'servicio') !== 'bien') throw new Exception("«{$p['nombre']}» es un servicio: no lleva inventario.");
    return $p;
}

function invEstablecimiento(PDO $pdo, int $cid, int $eid): array
{
    $stmt = $pdo->prepare("SELECT establecimiento_id AS id, nombre FROM establecimientos WHERE establecimiento_id = ? AND cliente_id = ?");
    $stmt->execute([$eid, $cid]);
    $e = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$e) throw new Exception("Establecimiento no válido.");
    return $e;
}

function invFmt(float $n): string
{
    return rtrim(rtrim(number_format($n, 3, '.', ','), '0'), '.');
}

/**
 * Aplica un movimiento de inventario de forma atómica y lo registra en el kardex.
 * $extra: costo_unitario, documento, documento_id, referencia, notas, usuario_id
 * Devuelve el saldo (existencia en la tienda) después del movimiento.
 */
function invMover(PDO $pdo, int $cid, int $pid, int $eid, string $tipo, float $cantidad, array $extra = []): float
{
    if (!in_array($tipo, INV_SUMA, true) && !in_array($tipo, INV_RESTA, true)) throw new Exception("Tipo de movimiento de inventario inválido.");
    $cantidad = invCantidad($cantidad);
    $pdo->prepare("INSERT IGNORE INTO inv_existencias (cliente_id, producto_id, establecimiento_id, cantidad, reservado) VALUES (?, ?, ?, 0, 0)")
        ->execute([$cid, $pid, $eid]);

    if (in_array($tipo, INV_RESTA, true)) {
        $stmt = $pdo->prepare("UPDATE inv_existencias SET cantidad = cantidad - ?
            WHERE producto_id = ? AND establecimiento_id = ? AND cliente_id = ? AND cantidad - reservado >= ?");
        $stmt->execute([$cantidad, $pid, $eid, $cid, $cantidad]);
        if (!$stmt->rowCount()) {
            $d = $pdo->prepare("SELECT cantidad - reservado FROM inv_existencias WHERE producto_id = ? AND establecimiento_id = ?");
            $d->execute([$pid, $eid]);
            $disp = (float)$d->fetchColumn();
            $nom = $pdo->prepare("SELECT nombre FROM productos_clientes WHERE id = ?");
            $nom->execute([$pid]);
            throw new Exception("Existencia insuficiente de «" . $nom->fetchColumn() . "»: hay " . invFmt($disp) . " disponible(s) y se necesitan " . invFmt($cantidad) . ".");
        }
    } else {
        $pdo->prepare("UPDATE inv_existencias SET cantidad = cantidad + ? WHERE producto_id = ? AND establecimiento_id = ? AND cliente_id = ?")
            ->execute([$cantidad, $pid, $eid, $cid]);
    }

    $s = $pdo->prepare("SELECT cantidad FROM inv_existencias WHERE producto_id = ? AND establecimiento_id = ?");
    $s->execute([$pid, $eid]);
    $saldo = round((float)$s->fetchColumn(), 3);

    $pdo->prepare("INSERT INTO inv_movimientos (cliente_id, producto_id, establecimiento_id, tipo, cantidad, costo_unitario, saldo, documento, documento_id, referencia, notas, usuario_id)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$cid, $pid, $eid, $tipo, $cantidad, $extra['costo_unitario'] ?? null, $saldo,
            $extra['documento'] ?? null, $extra['documento_id'] ?? null,
            isset($extra['referencia']) ? mb_substr((string)$extra['referencia'], 0, 100) : null,
            isset($extra['notas']) ? mb_substr((string)$extra['notas'], 0, 255) : null,
            $extra['usuario_id'] ?? null]);
    return $saldo;
}

/** Entrada por compra; con costo recalcula el costo promedio ponderado del producto. */
function invEntrada(PDO $pdo, int $cid, int $usuario, array $d): float
{
    $p = invProducto($pdo, $cid, (int)($d['producto_id'] ?? 0));
    $e = invEstablecimiento($pdo, $cid, (int)($d['establecimiento_id'] ?? 0));
    $cant = invCantidad($d['cantidad'] ?? 0);
    $costo = ($d['costo_unitario'] ?? '') === '' ? null : (float)$d['costo_unitario'];
    if ($costo !== null && $costo < 0) throw new Exception("El costo no puede ser negativo.");

    if ($costo !== null) {
        $t = $pdo->prepare("SELECT COALESCE(SUM(cantidad), 0) FROM inv_existencias WHERE producto_id = ? FOR UPDATE");
        $t->execute([$p['id']]);
        $stockTotal = (float)$t->fetchColumn();
        $nuevoCosto = ($stockTotal + $cant) > 0 ? (($stockTotal * (float)$p['costo']) + ($cant * $costo)) / ($stockTotal + $cant) : $costo;
        $pdo->prepare("UPDATE productos_clientes SET costo = ? WHERE id = ?")->execute([round($nuevoCosto, 4), $p['id']]);
    }
    return invMover($pdo, $cid, (int)$p['id'], (int)$e['id'], 'entrada', $cant, [
        'costo_unitario' => $costo, 'documento' => 'compra', 'referencia' => $d['referencia'] ?? null,
        'notas' => $d['notas'] ?? null, 'usuario_id' => $usuario,
    ]);
}

/** Ajuste por conteo físico: la existencia queda igual a lo contado. */
function invAjuste(PDO $pdo, int $cid, int $usuario, array $d): float
{
    $p = invProducto($pdo, $cid, (int)($d['producto_id'] ?? 0));
    $e = invEstablecimiento($pdo, $cid, (int)($d['establecimiento_id'] ?? 0));
    if (!is_numeric($d['contado'] ?? null) || (float)$d['contado'] < 0) throw new Exception("Indica la cantidad contada (0 o más).");
    $motivo = trim((string)($d['motivo'] ?? ''));
    if ($motivo === '') throw new Exception("Indica el motivo del ajuste.");
    $contado = round((float)$d['contado'], 3);
    $s = $pdo->prepare("SELECT cantidad, reservado FROM inv_existencias WHERE producto_id = ? AND establecimiento_id = ? FOR UPDATE");
    $s->execute([$p['id'], $e['id']]);
    [$actual, $reservado] = array_map('floatval', $s->fetch(PDO::FETCH_NUM) ?: [0, 0]);
    if ($contado < $reservado) throw new Exception("Hay " . invFmt($reservado) . " unidad(es) apartadas: el conteo no puede ser menor.");
    $dif = round($contado - $actual, 3);
    if (abs($dif) < 0.0005) throw new Exception("El conteo coincide con la existencia: no hay nada que ajustar.");
    return invMover($pdo, $cid, (int)$p['id'], (int)$e['id'], $dif > 0 ? 'ajuste_entrada' : 'ajuste_salida', abs($dif), [
        'documento' => 'ajuste', 'notas' => $motivo, 'usuario_id' => $usuario,
    ]);
}

// ── Traslados ────────────────────────────────────────────────────────────────

/** Crea un traslado: sale de la tienda de origen ahora y queda "en tránsito". */
function invCrearTraslado(PDO $pdo, int $cid, int $usuario, array $d): int
{
    $o = invEstablecimiento($pdo, $cid, (int)($d['origen_id'] ?? 0));
    $de = invEstablecimiento($pdo, $cid, (int)($d['destino_id'] ?? 0));
    if ($o['id'] === $de['id']) throw new Exception("El origen y el destino deben ser tiendas distintas.");
    $items = array_values(array_filter((array)($d['items'] ?? []), fn($i) => !empty($i['producto_id'])));
    if (!$items) throw new Exception("Agrega al menos un producto al traslado.");
    $pdo->prepare("INSERT INTO inv_traslados (cliente_id, origen_id, destino_id, notas, usuario_id) VALUES (?, ?, ?, ?, ?)")
        ->execute([$cid, $o['id'], $de['id'], mb_substr(trim((string)($d['notas'] ?? '')), 0, 255) ?: null, $usuario]);
    $tid = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare("INSERT INTO inv_traslado_items (traslado_id, producto_id, cantidad) VALUES (?, ?, ?)");
    foreach ($items as $it) {
        $p = invProducto($pdo, $cid, (int)$it['producto_id']);
        $cant = invCantidad($it['cantidad'] ?? 0);
        invMover($pdo, $cid, (int)$p['id'], (int)$o['id'], 'traslado_salida', $cant, [
            'documento' => 'traslado', 'documento_id' => $tid, 'notas' => "Hacia {$de['nombre']}", 'usuario_id' => $usuario]);
        $ins->execute([$tid, $p['id'], $cant]);
    }
    return $tid;
}

function invTraslado(PDO $pdo, int $cid, int $tid): array
{
    $stmt = $pdo->prepare("SELECT * FROM inv_traslados WHERE id = ? AND cliente_id = ? FOR UPDATE");
    $stmt->execute([$tid, $cid]);
    $t = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$t) throw new Exception("Traslado no encontrado.");
    $i = $pdo->prepare("SELECT * FROM inv_traslado_items WHERE traslado_id = ?");
    $i->execute([$tid]);
    $t['items'] = $i->fetchAll(PDO::FETCH_ASSOC);
    return $t;
}

/** La tienda de destino recibe la mercadería. */
function invRecibirTraslado(PDO $pdo, int $cid, int $usuario, int $tid): void
{
    $t = invTraslado($pdo, $cid, $tid);
    if ($t['estado'] !== 'en_transito') throw new Exception("El traslado ya fue {$t['estado']}.");
    $o = invEstablecimiento($pdo, $cid, (int)$t['origen_id']);
    foreach ($t['items'] as $it) {
        invMover($pdo, $cid, (int)$it['producto_id'], (int)$t['destino_id'], 'traslado_entrada', (float)$it['cantidad'], [
            'documento' => 'traslado', 'documento_id' => $tid, 'notas' => "Desde {$o['nombre']}", 'usuario_id' => $usuario]);
    }
    $pdo->prepare("UPDATE inv_traslados SET estado = 'recibido', fecha_recibido = NOW(), recibido_por = ? WHERE id = ?")->execute([$usuario, $tid]);
}

/** Anula un traslado en tránsito: la mercadería vuelve a la tienda de origen. */
function invAnularTraslado(PDO $pdo, int $cid, int $usuario, int $tid): void
{
    $t = invTraslado($pdo, $cid, $tid);
    if ($t['estado'] !== 'en_transito') throw new Exception("Solo se anulan traslados en tránsito (este está {$t['estado']}).");
    foreach ($t['items'] as $it) {
        invMover($pdo, $cid, (int)$it['producto_id'], (int)$t['origen_id'], 'traslado_entrada', (float)$it['cantidad'], [
            'documento' => 'traslado', 'documento_id' => $tid, 'notas' => 'Traslado anulado: regresa al origen', 'usuario_id' => $usuario]);
    }
    $pdo->prepare("UPDATE inv_traslados SET estado = 'anulado' WHERE id = ?")->execute([$tid]);
}

// ── Facturación ──────────────────────────────────────────────────────────────

/** Cantidad neta descontada por una factura, por producto y tienda (ventas − anulaciones). */
function invNetoFactura(PDO $pdo, int $facturaId): array
{
    $stmt = $pdo->prepare("
        SELECT producto_id, establecimiento_id,
               SUM(CASE WHEN tipo = 'venta' THEN cantidad ELSE -cantidad END) AS neto
        FROM inv_movimientos
        WHERE documento = 'factura' AND documento_id = ? AND tipo IN ('venta', 'anulacion_venta')
        GROUP BY producto_id, establecimiento_id HAVING neto > 0.0005
    ");
    $stmt->execute([$facturaId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Descuenta del inventario los bienes de la factura (en su establecimiento). */
function invDescontarFactura(PDO $pdo, int $cid, int $facturaId, int $eid, int $usuario): void
{
    if (!invDisponible($pdo)) return;
    $stmt = $pdo->prepare("
        SELECT i.producto_id, SUM(i.cantidad) AS cantidad
        FROM factura_items_receptor i JOIN productos_clientes p ON p.id = i.producto_id
        WHERE i.factura_id = ? AND p.tipo = 'bien' GROUP BY i.producto_id
    ");
    $stmt->execute([$facturaId]);
    $corr = $pdo->prepare("SELECT correlativo FROM facturas WHERE id = ?");
    $corr->execute([$facturaId]);
    $ref = $corr->fetchColumn();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $it) {
        invMover($pdo, $cid, (int)$it['producto_id'], $eid, 'venta', (float)$it['cantidad'], [
            'documento' => 'factura', 'documento_id' => $facturaId, 'referencia' => $ref, 'usuario_id' => $usuario]);
    }
}

/** Devuelve al inventario lo que la factura había descontado (anulación, eliminación o edición). */
function invRevertirFactura(PDO $pdo, int $cid, int $facturaId, int $usuario, string $motivo): void
{
    if (!invDisponible($pdo)) return;
    foreach (invNetoFactura($pdo, $facturaId) as $n) {
        invMover($pdo, $cid, (int)$n['producto_id'], (int)$n['establecimiento_id'], 'anulacion_venta', (float)$n['neto'], [
            'documento' => 'factura', 'documento_id' => $facturaId, 'notas' => $motivo, 'usuario_id' => $usuario]);
    }
}
