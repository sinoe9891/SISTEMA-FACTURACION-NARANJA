<?php
/**
 * contrato_plan.php — Plan de pagos de un contrato (tabla contratos_plan).
 *
 * El plan es el calendario acordado con el cliente: anticipo, cuotas (mensuales, trimestrales,
 * anuales…) o etapas, con su monto sin ISV, el ISV y el total. Cada línea se liga a cómo se cobró:
 *   - recibo_id   → recibo emitido (contratos «sin factura»)
 *   - factura_id  → factura del contrato (pagada = cobrada; con saldo = facturada por cobrar)
 *   - anticipo_id → pago anticipado (dinero recibido antes de facturar)
 * El estado no se guarda: se calcula con esos vínculos, así nunca queda desfasado.
 * Tabla: sql/migraciones/2026-10-06_contratos_plan.sql
 */
require_once __DIR__ . '/anticipos.php';
require_once __DIR__ . '/recibos.php';

const PLAN_TIPOS = ['anticipo' => 'Anticipo', 'cuota' => 'Cuota', 'etapa' => 'Etapa', 'anualidad' => 'Anualidad', 'otro' => 'Otro'];
const PLAN_ISV = 0.15;

function planDisponible(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = (bool)$pdo->query("SHOW TABLES LIKE 'contratos_plan'")->fetchColumn();
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

function planContrato(PDO $pdo, int $cid, int $contratoId): array
{
    $st = $pdo->prepare("SELECT * FROM contratos WHERE id = ? AND cliente_id = ?");
    $st->execute([$contratoId, $cid]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) throw new Exception("Contrato no encontrado.");
    return $c;
}

/** ¿El contrato se cobra con factura (lleva ISV)? Los «sin factura» se cobran con recibo. */
function planConFactura(array $contrato): bool
{
    return ($contrato['tipo_contrato'] ?? '') !== 'sin_factura';
}

/**
 * Líneas del plan con su estado calculado:
 *   estado: pagado | facturado (factura emitida con saldo) | vencido | pendiente
 *   cobro:  texto corto de cómo se cobró (Recibo 00012 · 05/10/2026, Factura 000-001-01-…, Anticipo …)
 */
function planLineas(PDO $pdo, int $cid, int $contratoId): array
{
    if (!planDisponible($pdo)) return [];
    $hayCobros = (bool)$pdo->query("SHOW TABLES LIKE 'cobros_factura'")->fetchColumn();
    $st = $pdo->prepare("
        SELECT p.*,
               r.numero_recibo, r.fecha_emision AS recibo_fecha, r.estado AS recibo_estado,
               f.correlativo, f.fecha_emision AS factura_fecha, f.estado AS factura_estado, f.total AS factura_total, f.pagada AS factura_pagada,
               " . ($hayCobros ? "(SELECT COALESCE(SUM(cb.monto),0) FROM cobros_factura cb WHERE cb.factura_id = f.id AND cb.anulado = 0)" : "0") . " AS factura_abonado,
               a.fecha AS anticipo_fecha, a.anulado AS anticipo_anulado, a.factura_id AS anticipo_factura_id, a.monto AS anticipo_monto
        FROM contratos_plan p
        LEFT JOIN contratos_recibos r ON r.id = p.recibo_id AND r.cliente_id = p.cliente_id
        LEFT JOIN facturas f ON f.id = p.factura_id AND f.cliente_id = p.cliente_id
        LEFT JOIN contratos_anticipos a ON a.id = p.anticipo_id AND a.cliente_id = p.cliente_id
        WHERE p.cliente_id = ? AND p.contrato_id = ?
        ORDER BY p.fecha, p.orden, p.id");
    $st->execute([$cid, $contratoId]);
    $hoy = date('Y-m-d');
    $lineas = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $l['estado'] = $l['fecha'] < $hoy ? 'vencido' : 'pendiente';
        $l['cobro'] = '';
        $l['fecha_pago'] = null;
        $l['vinculado'] = false;
        if ($l['recibo_id'] && $l['recibo_estado'] === 'emitido') {
            $l['estado'] = 'pagado';
            $l['cobro'] = 'Recibo ' . str_pad((string)$l['numero_recibo'], 5, '0', STR_PAD_LEFT);
            $l['fecha_pago'] = $l['recibo_fecha'];
            $l['vinculado'] = true;
        } elseif ($l['anticipo_id'] && $l['anticipo_fecha'] !== null && !(int)$l['anticipo_anulado']) {
            $l['estado'] = 'pagado';
            $l['cobro'] = 'Pago anticipado' . ($l['anticipo_factura_id'] ? ' (aplicado a factura)' : ' (sin factura aún)');
            $l['fecha_pago'] = $l['anticipo_fecha'];
            $l['vinculado'] = true;
        } elseif ($l['factura_id'] && $l['factura_estado'] === 'emitida') {
            // Misma regla que cuentas por cobrar: con abonos manda la suma; sin abonos, la marca «pagada»
            $saldo = (float)$l['factura_abonado'] > 0 ? (float)$l['factura_total'] - (float)$l['factura_abonado'] : ((int)$l['factura_pagada'] ? 0 : (float)$l['factura_total']);
            $l['estado'] = $saldo > 0.004 ? 'facturado' : 'pagado';
            $l['cobro'] = 'Factura ' . $l['correlativo'];
            $l['fecha_pago'] = $l['factura_fecha'];
            $l['vinculado'] = true;
        }
        $l['dias'] = (int)((strtotime($hoy) - strtotime($l['fecha'])) / 86400);   // > 0 = días de atraso
        $lineas[] = $l;
    }
    return $lineas;
}

/** Resumen del plan: total acordado, cobrado, pendiente, vencido y próximo pago. */
function planResumen(array $lineas): array
{
    $r = ['total' => 0.0, 'monto' => 0.0, 'isv' => 0.0, 'cobrado' => 0.0, 'facturado' => 0.0, 'pendiente' => 0.0, 'vencido' => 0.0, 'n' => count($lineas), 'n_pagadas' => 0, 'proximo' => null];
    foreach ($lineas as $l) {
        $r['total'] += (float)$l['total'];
        $r['monto'] += (float)$l['monto'];
        $r['isv'] += (float)$l['isv'];
        if ($l['estado'] === 'pagado') { $r['cobrado'] += (float)$l['total']; $r['n_pagadas']++; }
        elseif ($l['estado'] === 'facturado') $r['facturado'] += (float)$l['total'];
        else {
            $r['pendiente'] += (float)$l['total'];
            if ($l['estado'] === 'vencido') $r['vencido'] += (float)$l['total'];
            elseif (!$r['proximo']) $r['proximo'] = $l;
        }
    }
    foreach (['total', 'monto', 'isv', 'cobrado', 'facturado', 'pendiente', 'vencido'] as $k) $r[$k] = round($r[$k], 2);
    return $r;
}

/**
 * Guarda el plan completo. $lineas: [{id?, tipo, concepto, fecha, monto}], montos sin ISV.
 * Las líneas ya cobradas se conservan: se puede cambiar su concepto y fecha, no su monto, y no se pueden quitar.
 */
function planGuardar(PDO $pdo, int $cid, int $contratoId, array $lineas, bool $conIsv): int
{
    $c = planContrato($pdo, $cid, $contratoId);
    if (!planConFactura($c)) $conIsv = false;   // con recibo no hay ISV
    $actuales = [];
    foreach (planLineas($pdo, $cid, $contratoId) as $l) $actuales[(int)$l['id']] = $l;

    $limpias = [];
    foreach (array_values($lineas) as $i => $l) {
        $n = $i + 1;
        $concepto = mb_substr(trim((string)($l['concepto'] ?? '')), 0, 300);
        $fecha = trim((string)($l['fecha'] ?? ''));
        $monto = round((float)str_replace(',', '', (string)($l['monto'] ?? 0)), 2);
        $tipo = (string)($l['tipo'] ?? 'cuota');
        if ($concepto === '') throw new Exception("Línea $n: falta el concepto.");
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha)) throw new Exception("Línea $n: fecha inválida.");
        if ($monto <= 0) throw new Exception("Línea $n: el monto debe ser mayor a 0.");
        if (!isset(PLAN_TIPOS[$tipo])) $tipo = 'otro';
        $id = (int)($l['id'] ?? 0);
        if ($id && !isset($actuales[$id])) throw new Exception("Línea $n: no pertenece a este contrato.");
        if ($id && $actuales[$id]['vinculado'] && abs((float)$actuales[$id]['monto'] - $monto) > 0.004)
            throw new Exception("Línea $n («{$actuales[$id]['concepto']}») ya está cobrada: no se puede cambiar su monto. Desvincula el cobro primero.");
        $isv = $id && $actuales[$id]['vinculado'] ? (float)$actuales[$id]['isv'] : ($conIsv ? round($monto * PLAN_ISV, 2) : 0.0);
        $limpias[] = compact('id', 'tipo', 'concepto', 'fecha', 'monto', 'isv') + ['orden' => $n];
    }
    if (count($limpias) > 240) throw new Exception("El plan admite hasta 240 líneas.");
    $quedan = array_filter(array_column($limpias, 'id'));
    foreach ($actuales as $id => $l) {
        if (!in_array($id, $quedan, true) && $l['vinculado']) throw new Exception("«{$l['concepto']}» ya está cobrada ({$l['cobro']}): no se puede quitar del plan.");
    }

    $del = $pdo->prepare("DELETE FROM contratos_plan WHERE id = ? AND cliente_id = ?");
    foreach (array_keys($actuales) as $id) if (!in_array($id, $quedan, true)) $del->execute([$id, $cid]);
    $upd = $pdo->prepare("UPDATE contratos_plan SET orden = ?, tipo = ?, concepto = ?, fecha = ?, monto = ?, isv = ?, total = ? WHERE id = ? AND cliente_id = ?");
    $ins = $pdo->prepare("INSERT INTO contratos_plan (cliente_id, contrato_id, orden, tipo, concepto, fecha, monto, isv, total) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($limpias as $l) {
        $total = round($l['monto'] + $l['isv'], 2);
        if ($l['id']) $upd->execute([$l['orden'], $l['tipo'], $l['concepto'], $l['fecha'], $l['monto'], $l['isv'], $total, $l['id'], $cid]);
        else $ins->execute([$cid, $contratoId, $l['orden'], $l['tipo'], $l['concepto'], $l['fecha'], $l['monto'], $l['isv'], $total]);
    }
    return count($limpias);
}

function planLinea(PDO $pdo, int $cid, int $id): array
{
    $st = $pdo->prepare("SELECT contrato_id FROM contratos_plan WHERE id = ? AND cliente_id = ?");
    $st->execute([$id, $cid]);
    $contratoId = (int)$st->fetchColumn();
    if (!$contratoId) throw new Exception("Línea del plan no encontrada.");
    foreach (planLineas($pdo, $cid, $contratoId) as $l) if ((int)$l['id'] === $id) return $l;
    throw new Exception("Línea del plan no encontrada.");
}

/** Cobra una línea de un contrato «sin factura» emitiendo su recibo. */
function planCobrarRecibo(PDO $pdo, int $cid, int $usuario, int $lineaId, array $d): int
{
    $l = planLinea($pdo, $cid, $lineaId);
    if ($l['vinculado']) throw new Exception("Esta línea ya está cobrada ({$l['cobro']}).");
    $fecha = (string)($d['fecha'] ?? date('Y-m-d'));
    [$recId] = reciboRegistrar($pdo, $cid, $usuario, (int)$l['contrato_id'], [
        'monto' => $l['total'], 'fecha_emision' => $fecha, 'descripcion' => $l['concepto'],
        'metodo_pago' => $d['metodo'] ?? 'transferencia', 'notas' => $d['notas'] ?? null,
        'periodo_mes' => (int)substr($l['fecha'], 5, 2), 'periodo_anio' => (int)substr($l['fecha'], 0, 4),
        'cuenta_id' => $d['cuenta_id'] ?? 0,
    ]);
    $pdo->prepare("UPDATE contratos_plan SET recibo_id = ?, factura_id = NULL, anticipo_id = NULL WHERE id = ? AND cliente_id = ?")->execute([$recId, $lineaId, $cid]);
    return $recId;
}

/** Cobra una línea de un contrato con factura como pago anticipado (dinero recibido antes de facturar). */
function planRegistrarAnticipo(PDO $pdo, int $cid, int $usuario, int $lineaId, array $d): int
{
    $l = planLinea($pdo, $cid, $lineaId);
    if ($l['vinculado']) throw new Exception("Esta línea ya está cobrada ({$l['cobro']}).");
    $antId = anticipoRegistrar($pdo, $cid, $usuario, [
        'contrato_id' => $l['contrato_id'], 'monto' => $d['monto'] ?? $l['total'], 'fecha' => $d['fecha'] ?? date('Y-m-d'),
        'metodo' => $d['metodo'] ?? 'transferencia', 'referencia' => $d['referencia'] ?? '', 'concepto' => $l['concepto'], 'cuenta_id' => $d['cuenta_id'] ?? 0,
    ]);
    $pdo->prepare("UPDATE contratos_plan SET anticipo_id = ?, factura_id = NULL, recibo_id = NULL WHERE id = ? AND cliente_id = ?")->execute([$antId, $lineaId, $cid]);
    return $antId;
}

/** Liga una línea con un cobro que ya existe: factura del contrato, recibo o pago anticipado. */
function planVincular(PDO $pdo, int $cid, int $lineaId, string $tipo, int $refId): void
{
    $l = planLinea($pdo, $cid, $lineaId);
    if ($l['vinculado']) throw new Exception("Esta línea ya está cobrada ({$l['cobro']}). Desvincúlala primero.");
    $tablas = ['factura' => ["SELECT id FROM facturas WHERE id = ? AND cliente_id = ? AND contrato_id = ? AND estado = 'emitida'", 'factura_id'],
               'recibo' => ["SELECT id FROM contratos_recibos WHERE id = ? AND cliente_id = ? AND contrato_id = ? AND estado = 'emitido'", 'recibo_id'],
               'anticipo' => ["SELECT id FROM contratos_anticipos WHERE id = ? AND cliente_id = ? AND contrato_id = ? AND anulado = 0", 'anticipo_id']];
    if (!isset($tablas[$tipo])) throw new Exception("Tipo de cobro inválido.");
    [$sql, $col] = $tablas[$tipo];
    $st = $pdo->prepare($sql);
    $st->execute([$refId, $cid, $l['contrato_id']]);
    if (!$st->fetchColumn()) throw new Exception("Ese cobro no existe o no es de este contrato.");
    $st = $pdo->prepare("SELECT concepto FROM contratos_plan WHERE cliente_id = ? AND $col = ? AND id <> ?");
    $st->execute([$cid, $refId, $lineaId]);
    if ($otra = $st->fetchColumn()) throw new Exception("Ese cobro ya está ligado a «{$otra}».");
    $pdo->prepare("UPDATE contratos_plan SET factura_id = NULL, recibo_id = NULL, anticipo_id = NULL, $col = ? WHERE id = ? AND cliente_id = ?")->execute([$refId, $lineaId, $cid]);
}

/** Quita el vínculo de cobro de una línea (el recibo, factura o anticipo no se borran). */
function planDesvincular(PDO $pdo, int $cid, int $lineaId): void
{
    planLinea($pdo, $cid, $lineaId);
    $pdo->prepare("UPDATE contratos_plan SET factura_id = NULL, recibo_id = NULL, anticipo_id = NULL WHERE id = ? AND cliente_id = ?")->execute([$lineaId, $cid]);
}

/**
 * ISV incluido en pagos anticipados que aún no tienen factura: hay que apartarlo, porque se
 * declarará al emitir la factura. Por contrato (o de toda la empresa si $contratoId = 0).
 * Los anticipos se registran con ISV incluido: ISV = monto × 15 / 115.
 */
function planIsvPorApartar(PDO $pdo, int $cid, int $contratoId = 0, ?string $corte = null): float
{
    if (!anticiposDisponible($pdo)) return 0.0;
    $sql = "SELECT COALESCE(SUM(a.monto),0) FROM contratos_anticipos a JOIN contratos c ON c.id = a.contrato_id AND c.cliente_id = a.cliente_id
            WHERE a.cliente_id = ? AND a.anulado = 0 AND a.factura_id IS NULL AND c.tipo_contrato <> 'sin_factura'";
    $p = [$cid];
    if ($contratoId) { $sql .= " AND a.contrato_id = ?"; $p[] = $contratoId; }
    if ($corte) { $sql .= " AND a.fecha <= ?"; $p[] = $corte; }
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return round((float)$st->fetchColumn() * PLAN_ISV / (1 + PLAN_ISV), 2);
}

/** Cobros esperados por mes según los planes (líneas no cobradas), para la proyección de flujo: ['YYYY-M' => monto sin ISV]. */
function planPendientesPorMes(PDO $pdo, int $cid): array
{
    $out = [];
    if (!planDisponible($pdo)) return $out;
    $st = $pdo->prepare("SELECT DISTINCT contrato_id FROM contratos_plan WHERE cliente_id = ?");
    $st->execute([$cid]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $contratoId) {
        foreach (planLineas($pdo, $cid, (int)$contratoId) as $l) {
            if ($l['estado'] === 'pagado' || $l['estado'] === 'facturado') continue;
            $k = (int)substr($l['fecha'], 0, 4) . '-' . (int)substr($l['fecha'], 5, 2);
            $out[$k][(int)$contratoId] = ($out[$k][(int)$contratoId] ?? 0) + (float)$l['monto'];
        }
    }
    return $out;
}

/** Ids de contratos que tienen plan de pagos. */
function planContratosConPlan(PDO $pdo, int $cid): array
{
    if (!planDisponible($pdo)) return [];
    $st = $pdo->prepare("SELECT DISTINCT contrato_id FROM contratos_plan WHERE cliente_id = ?");
    $st->execute([$cid]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}
