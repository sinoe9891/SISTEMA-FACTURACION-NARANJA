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
 * Gastos por cobrar a un cliente (o a todos si $receptorId = 0): ya pagados o que vencen este mes, sin factura.
 * Se cobran en la factura del mes: la renovación sale desde el día 1 de su mes; las de meses siguientes, no.
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
              AND g.estado <> 'anulado' AND (g.estado = 'pagado' OR g.fecha <= LAST_DAY(CURDATE()))"
         . ($receptorId ? " AND g.cobrar_receptor_id = ?" : '') . " ORDER BY g.fecha, g.id";
    $st = $pdo->prepare($sql);
    $st->execute($receptorId ? [$cid, $receptorId] : [$cid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Empresas que se facturan como un mismo cliente: las de un contrato rotativo activo (p. ej. Texaco Valeriano:
 * Vista Hermosa, Colonial y David Valeriano). receptor_id => [receptor_id, …] (incluido él mismo).
 */
function gastosGruposReceptores(PDO $pdo, int $cid): array
{
    $grupos = [];
    try {
        $st = $pdo->prepare("SELECT c.id, c.receptor_id AS principal, r.receptor_id FROM contratos c
                             LEFT JOIN contratos_clientes_rotativos r ON r.contrato_id = c.id
                             WHERE c.cliente_id = ? AND c.tipo_contrato = 'rotativo' AND c.estado = 'activo'");
        $st->execute([$cid]);
        $porContrato = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $porContrato[$r['id']][(int)$r['principal']] = true;
            if ($r['receptor_id']) $porContrato[$r['id']][(int)$r['receptor_id']] = true;
        }
        foreach ($porContrato as $miembros) {
            $ids = array_keys($miembros);
            foreach ($ids as $rid) $grupos[$rid] = array_values(array_unique(array_merge($grupos[$rid] ?? [$rid], $ids)));
        }
    } catch (Throwable $e) {
        // sin contratos rotativos
    }
    return $grupos;
}

/** El receptor y las demás empresas de su mismo contrato rotativo. */
function gastosGrupoDe(PDO $pdo, int $cid, int $receptorId): array
{
    return gastosGruposReceptores($pdo, $cid)[$receptorId] ?? [$receptorId];
}

/** Marca gastos como cobrados en una factura (del mismo cliente de la factura —o de su grupo rotativo— y aún sin factura). */
function gastosMarcarCobrados(PDO $pdo, int $cid, array $ids, int $facturaId, int $receptorId): int
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids || !gastosCobrarDisponible($pdo)) return 0;
    $grupo = gastosGrupoDe($pdo, $cid, $receptorId);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $inG = implode(',', array_fill(0, count($grupo), '?'));
    $st = $pdo->prepare("UPDATE gastos SET cobrado_factura_id = ? WHERE cliente_id = ? AND cobrar_receptor_id IN ($inG) AND cobrado_factura_id IS NULL AND id IN ($in)");
    $st->execute(array_merge([$facturaId, $cid], $grupo, $ids));
    return $st->rowCount();
}
