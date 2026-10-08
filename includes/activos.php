<?php
/**
 * activos.php — Activos fijos (con depreciación) y préstamos recibidos.
 * Tablas: sql/migraciones/2026-10-08_activos_prestamos.sql
 *
 * Depreciación en línea recta: (costo − valor residual) / vida útil en meses, desde el mes siguiente
 * a la compra y hasta completar la vida útil o la fecha de baja. Cada mes entra como gasto
 * («Depreciación de activos fijos») y el valor en libros del activo baja en el Balance.
 *
 * Gastos.naturaleza separa lo que no es gasto del período: abonos a capital de préstamos (capital),
 * compras de activos (activo) y anticipos a proveedores (anticipo). Solo «gasto» resta en resultados.
 */

const ACTIVO_CATEGORIAS = [
    'equipo_computo' => ['Equipo de cómputo', 36],
    'mobiliario'     => ['Mobiliario y equipo de oficina', 60],
    'vehiculo'       => ['Vehículos', 60],
    'maquinaria'     => ['Maquinaria y equipo', 120],
    'edificio'       => ['Edificios e instalaciones', 240],
    'otro'           => ['Otros activos', 60],
];
const GASTO_NATURALEZAS = ['gasto' => 'Gasto', 'capital' => 'Abono a capital de préstamo', 'activo' => 'Compra de activo fijo', 'anticipo' => 'Anticipo a proveedor', 'isv' => 'Pago de ISV al SAR (no es gasto)'];

function activosDisponible(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = (bool)$pdo->query("SHOW TABLES LIKE 'activos_fijos'")->fetchColumn() && (bool)$pdo->query("SHOW COLUMNS FROM gastos LIKE 'naturaleza'")->fetchColumn();
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

/** Condición SQL: el gasto cuenta en el Estado de resultados (sin la columna, todo cuenta). */
function gastoEsGastoSql(PDO $pdo, string $alias = ''): string
{
    return activosDisponible($pdo) ? " AND " . ($alias ? "$alias." : '') . "naturaleza = 'gasto'" : '';
}

/** Índice de mes (año*12 + mes-1) de una fecha Y-m-d. */
function activoMes(string $f): int
{
    return (int)substr($f, 0, 4) * 12 + (int)substr($f, 5, 2) - 1;
}

/** Primer y último mes (índices) en que se deprecia el activo. */
function activoRango(array $a): array
{
    $ini = activoMes($a['fecha_compra']) + 1;                         // desde el mes siguiente a la compra
    $fin = $ini + max(1, (int)$a['vida_util_meses']) - 1;
    if (!empty($a['fecha_baja'])) $fin = min($fin, activoMes($a['fecha_baja']));
    return [$ini, $fin];
}

function activoCuotaMensual(array $a): float
{
    return round(max(0, (float)$a['costo'] - (float)$a['valor_residual']) / max(1, (int)$a['vida_util_meses']), 2);
}

/** Depreciación del activo entre dos fechas (meses completos que tocan el período). La última cuota cuadra el total. */
function activoDepreciacion(array $a, string $desde, string $hasta): float
{
    [$ini, $fin] = activoRango($a);
    $d = max($ini, activoMes($desde));
    $h = min($fin, activoMes($hasta));
    if ($h < $d) return 0.0;
    $cuota = activoCuotaMensual($a);
    $total = max(0, (float)$a['costo'] - (float)$a['valor_residual']);
    $antes = ($d - $ini) * $cuota;                                     // lo depreciado antes del período
    $hastaFin = min($total, ($h - $ini + 1) * $cuota);
    if ($h === $ini + (int)$a['vida_util_meses'] - 1) $hastaFin = $total;   // último mes: ajusta centavos
    return round(max(0, $hastaFin - $antes), 2);
}

/** Depreciación acumulada al corte. */
function activoAcumulada(array $a, string $corte): float
{
    if ($a['fecha_compra'] > $corte) return 0.0;
    return activoDepreciacion($a, $a['fecha_compra'], $corte);
}

function activosLista(PDO $pdo, int $cid): array
{
    if (!activosDisponible($pdo)) return [];
    $st = $pdo->prepare("SELECT * FROM activos_fijos WHERE cliente_id = ? ORDER BY fecha_compra DESC, id DESC");
    $st->execute([$cid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Total de depreciación del período (para el Estado de resultados). */
function activosDepreciacionPeriodo(PDO $pdo, int $cid, string $desde, string $hasta): float
{
    $t = 0.0;
    foreach (activosLista($pdo, $cid) as $a) $t += activoDepreciacion($a, $desde, $hasta);
    return round($t, 2);
}

/** Activos al corte: [nombre, costo, acumulada, neto] (para el Balance). */
function activosBalance(PDO $pdo, int $cid, string $corte): array
{
    $out = [];
    foreach (activosLista($pdo, $cid) as $a) {
        if ($a['fecha_compra'] > $corte || (!empty($a['fecha_baja']) && $a['fecha_baja'] <= $corte)) continue;
        $acum = activoAcumulada($a, $corte);
        $out[] = ['id' => (int)$a['id'], 'nombre' => $a['nombre'], 'costo' => (float)$a['costo'], 'acumulada' => $acum, 'neto' => round((float)$a['costo'] - $acum, 2)];
    }
    return $out;
}

// ── Préstamos recibidos ──────────────────────────────────────────────────────

function prestamosRecibidos(PDO $pdo, int $cid): array
{
    if (!activosDisponible($pdo)) return [];
    $st = $pdo->prepare("SELECT * FROM prestamos_recibidos WHERE cliente_id = ? AND estado <> 'anulado' ORDER BY fecha DESC, id DESC");
    $st->execute([$cid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Capital pagado de un préstamo al corte (gastos pagados con naturaleza «capital» ligados al préstamo). */
function prestamoCapitalPagado(PDO $pdo, int $cid, int $prestamoId, string $corte): float
{
    $st = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM gastos WHERE cliente_id = ? AND prestamo_id = ? AND naturaleza = 'capital' AND estado = 'pagado' AND fecha <= ?");
    $st->execute([$cid, $prestamoId, $corte]);
    return round((float)$st->fetchColumn(), 2);
}

/** Saldo de cada préstamo al corte (para el Balance y la ficha). */
function prestamosBalance(PDO $pdo, int $cid, string $corte): array
{
    $out = [];
    foreach (prestamosRecibidos($pdo, $cid) as $p) {
        if ($p['fecha'] > $corte) continue;
        $pagado = prestamoCapitalPagado($pdo, $cid, (int)$p['id'], $corte);
        $saldo = round(max(0, (float)$p['monto'] - $pagado), 2);
        $out[] = ['id' => (int)$p['id'], 'nombre' => $p['acreedor'] . ($p['descripcion'] ? ' · ' . $p['descripcion'] : ''), 'monto' => (float)$p['monto'], 'pagado' => $pagado, 'saldo' => $saldo];
    }
    return $out;
}

/** Anticipos a proveedores pagados y aún sin factura (dinero a favor) al corte. */
function anticiposProveedorSaldo(PDO $pdo, int $cid, string $corte): float
{
    if (!activosDisponible($pdo)) return 0.0;
    $st = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM gastos WHERE cliente_id = ? AND naturaleza = 'anticipo' AND estado = 'pagado' AND fecha <= ?");
    $st->execute([$cid, $corte]);
    return round((float)$st->fetchColumn(), 2);
}
