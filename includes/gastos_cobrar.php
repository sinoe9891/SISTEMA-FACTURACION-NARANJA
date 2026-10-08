<?php
/**
 * gastos_cobrar.php — Gastos que la empresa paga y luego le cobra al cliente (reembolsables), p. ej. el hosting de un cliente.
 * El gasto guarda a quién se le cobra (cobrar_receptor_id) y en qué factura se incluyó (cobrado_factura_id).
 * Mientras no tenga factura, Nueva factura lo avisa al elegir ese cliente.
 */

function gastosCobrarDisponible(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try { $ok = (bool)$pdo->query("SHOW COLUMNS FROM gastos LIKE 'cobrar_receptor_id'")->fetchColumn(); } catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

/**
 * Gastos por cobrar a un cliente (o a todos si $receptorId = 0): ya pagados o con fecha cumplida, sin factura.
 * Los programados a futuro (p. ej. la renovación del próximo año) no se avisan hasta que llegue su fecha.
 */
function gastosPorCobrar(PDO $pdo, int $cid, int $receptorId = 0): array
{
    if (!gastosCobrarDisponible($pdo)) return [];
    // Si viene de una licencia, también su comisión (precio al cliente = costo + %)
    $lic = false;
    try { $lic = (bool)$pdo->query("SHOW COLUMNS FROM gastos LIKE 'licencia_id'")->fetchColumn(); } catch (Throwable $e) { $lic = false; }
    $sql = "SELECT g.id, g.fecha, g.descripcion, g.monto, g.estado, g.proveedor, g.cobrar_receptor_id, cf.nombre AS cliente"
         . ($lic ? ", l.comision_pct" : ", NULL AS comision_pct") . "
            FROM gastos g JOIN clientes_factura cf ON cf.id = g.cobrar_receptor_id" . ($lic ? " LEFT JOIN licencias l ON l.id = g.licencia_id" : '') . "
            WHERE g.cliente_id = ? AND g.cobrar_receptor_id IS NOT NULL AND g.cobrado_factura_id IS NULL
              AND g.estado <> 'anulado' AND (g.estado = 'pagado' OR g.fecha <= CURDATE())"
         . ($receptorId ? " AND g.cobrar_receptor_id = ?" : '') . " ORDER BY g.fecha, g.id";
    $st = $pdo->prepare($sql);
    $st->execute($receptorId ? [$cid, $receptorId] : [$cid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Marca gastos como cobrados en una factura (solo los del mismo cliente de la factura y aún sin factura). */
function gastosMarcarCobrados(PDO $pdo, int $cid, array $ids, int $facturaId, int $receptorId): int
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids || !gastosCobrarDisponible($pdo)) return 0;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("UPDATE gastos SET cobrado_factura_id = ? WHERE cliente_id = ? AND cobrar_receptor_id = ? AND cobrado_factura_id IS NULL AND id IN ($in)");
    $st->execute(array_merge([$facturaId, $cid, $receptorId], $ids));
    return $st->rowCount();
}
