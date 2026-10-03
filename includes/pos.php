<?php
/**
 * pos.php — Punto de venta: turnos de caja, ventas, pagos, movimientos de efectivo y cierre.
 * Tablas: sql/migraciones/2026-10-03_pos.sql. Cada venta crea una factura con crearFactura().
 *
 * Reglas:
 * - Una caja = un punto de emisión. Una caja solo puede tener un turno abierto, y un cajero también.
 * - Efectivo esperado = fondo inicial + efectivo de ventas con factura emitida + entradas − retiros.
 *   (Si una factura se anula, su efectivo deja de contar automáticamente.)
 * - Cada venta trae un identificador único (idempotencia): si el navegador reintenta por un
 *   corte de red, no se cobra ni se factura dos veces.
 */
require_once __DIR__ . '/facturacion.php';
require_once __DIR__ . '/intentos.php';

const POS_TOLERANCIA_CIERRE = 1.00;   // diferencia de caja sin justificación (L)
const POS_ROLES_VENTA = ['admin', 'superadmin', 'facturador'];

function posDisponible(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = (bool)$pdo->query("SHOW TABLES LIKE 'pos_ventas'")->fetchColumn() && invDisponible($pdo);
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

function posMonto($m, string $campo = 'monto', bool $permitirCero = false): float
{
    if (!is_numeric($m) || (float)$m < 0 || (!$permitirCero && (float)$m <= 0)) throw new Exception("El $campo debe ser " . ($permitirCero ? "0 o más." : "mayor que 0."));
    return round((float)$m, 2);
}

/** Cajas (puntos de emisión) del establecimiento con su CAI vigente y turno abierto, si hay. */
function posCajas(PDO $pdo, int $cid, int $eid): array
{
    $stmt = $pdo->prepare("
        SELECT p.id, p.codigo_punto, p.descripcion,
            (SELECT c.id FROM cai_rangos c WHERE c.punto_emision_id = p.id AND c.cliente_id = ? AND c.establecimiento_id = e.establecimiento_id
                AND c.fecha_limite >= CURDATE() AND c.correlativo_actual < (c.rango_fin - c.rango_inicio + 1)
                ORDER BY c.fecha_limite, c.rango_inicio LIMIT 1) AS cai_id,
            (SELECT t.id FROM pos_turnos t WHERE t.punto_emision_id = p.id AND t.estado = 'abierto' LIMIT 1) AS turno_abierto,
            (SELECT u.nombre FROM pos_turnos t JOIN usuarios u ON u.id = t.usuario_id WHERE t.punto_emision_id = p.id AND t.estado = 'abierto' LIMIT 1) AS cajero
        FROM puntos_emision p JOIN establecimientos e ON e.establecimiento_id = p.establecimiento_id
        WHERE p.establecimiento_id = ? AND e.cliente_id = ?
        ORDER BY p.codigo_punto
    ");
    $stmt->execute([$cid, $eid, $cid]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function posTurnoDelUsuario(PDO $pdo, int $cid, int $uid): ?array
{
    $stmt = $pdo->prepare("SELECT t.*, p.codigo_punto, p.descripcion AS caja, e.nombre AS tienda
        FROM pos_turnos t JOIN puntos_emision p ON p.id = t.punto_emision_id JOIN establecimientos e ON e.establecimiento_id = t.establecimiento_id
        WHERE t.cliente_id = ? AND t.usuario_id = ? AND t.estado = 'abierto' LIMIT 1");
    $stmt->execute([$cid, $uid]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function posTurno(PDO $pdo, int $cid, int $tid, bool $bloquear = false): array
{
    $stmt = $pdo->prepare("SELECT * FROM pos_turnos WHERE id = ? AND cliente_id = ?" . ($bloquear ? " FOR UPDATE" : ""));
    $stmt->execute([$tid, $cid]);
    $t = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$t) throw new Exception("Turno de caja no encontrado.");
    return $t;
}

function posAbrirTurno(PDO $pdo, int $cid, int $uid, int $eid, int $puntoId, $montoInicial): int
{
    $monto = posMonto($montoInicial, 'fondo inicial', true);
    // Bloquear la caja para que dos personas no la abran al mismo tiempo
    $stmt = $pdo->prepare("SELECT p.id FROM puntos_emision p JOIN establecimientos e ON e.establecimiento_id = p.establecimiento_id
        WHERE p.id = ? AND p.establecimiento_id = ? AND e.cliente_id = ? FOR UPDATE");
    $stmt->execute([$puntoId, $eid, $cid]);
    if (!$stmt->fetchColumn()) throw new Exception("Caja no válida para este establecimiento.");
    if (posTurnoDelUsuario($pdo, $cid, $uid)) throw new Exception("Ya tienes un turno de caja abierto: ciérralo antes de abrir otro.");
    $stmt = $pdo->prepare("SELECT u.nombre FROM pos_turnos t JOIN usuarios u ON u.id = t.usuario_id WHERE t.punto_emision_id = ? AND t.estado = 'abierto'");
    $stmt->execute([$puntoId]);
    if ($quien = $stmt->fetchColumn()) throw new Exception("Esa caja ya está abierta por $quien.");
    $cajas = array_column(posCajas($pdo, $cid, $eid), 'cai_id', 'id');
    if (empty($cajas[$puntoId])) throw new Exception("Esta caja no tiene un CAI vigente con números disponibles: configúralo en Configuración CAI.");
    $pdo->prepare("INSERT INTO pos_turnos (cliente_id, establecimiento_id, punto_emision_id, usuario_id, monto_inicial) VALUES (?, ?, ?, ?, ?)")
        ->execute([$cid, $eid, $puntoId, $uid, $monto]);
    return (int)$pdo->lastInsertId();
}

/** Receptor "CONSUMIDOR FINAL" de la empresa (se crea si no existe). */
function posConsumidorFinal(PDO $pdo, int $cid): int
{
    $stmt = $pdo->prepare("SELECT id FROM clientes_factura WHERE cliente_id = ? AND nombre = 'CONSUMIDOR FINAL' ORDER BY id LIMIT 1");
    $stmt->execute([$cid]);
    if ($id = $stmt->fetchColumn()) return (int)$id;
    $pdo->prepare("INSERT INTO clientes_factura (cliente_id, nombre, rtn, direccion) VALUES (?, 'CONSUMIDOR FINAL', '', '')")->execute([$cid]);
    return (int)$pdo->lastInsertId();
}

/**
 * Registra una venta: factura + pagos. $d: idempotencia, receptor_id?, items[] (id, cantidad, precio?),
 * efectivo_recibido, pagos[] (forma tarjeta|transferencia, monto, referencia, ultimos4).
 * Devuelve [venta_id, factura_id, correlativo, total, cambio, repetida].
 */
function posVender(PDO $pdo, int $cid, int $uid, array $d): array
{
    $idem = strtolower((string)($d['idempotencia'] ?? ''));
    if (!preg_match('/^[a-f0-9]{32}$/', $idem)) throw new Exception("Falta el identificador de la venta. Recarga la página.");

    // ¿Ya se registró esta misma venta (reintento)? Se devuelve la existente.
    $stmt = $pdo->prepare("SELECT v.id, v.factura_id, v.total, v.cambio, f.correlativo FROM pos_ventas v JOIN facturas f ON f.id = v.factura_id WHERE v.cliente_id = ? AND v.idempotencia = ?");
    $stmt->execute([$cid, $idem]);
    if ($v = $stmt->fetch(PDO::FETCH_ASSOC)) {
        return ['venta_id' => (int)$v['id'], 'factura_id' => (int)$v['factura_id'], 'correlativo' => $v['correlativo'], 'total' => (float)$v['total'], 'cambio' => (float)$v['cambio'], 'repetida' => true];
    }

    $turno = posTurnoDelUsuario($pdo, $cid, $uid);
    if (!$turno) throw new Exception("No tienes un turno de caja abierto.");
    $turno = posTurno($pdo, $cid, (int)$turno['id'], true);
    if ($turno['estado'] !== 'abierto') throw new Exception("El turno de caja está cerrado.");

    $items = array_values(array_filter((array)($d['items'] ?? []), fn($i) => is_array($i) && !empty($i['id'])));
    if (!$items) throw new Exception("El carrito está vacío.");
    $cajas = array_column(posCajas($pdo, $cid, (int)$turno['establecimiento_id']), 'cai_id', 'id');
    $caiId = $cajas[$turno['punto_emision_id']] ?? null;
    if (!$caiId) throw new Exception("La caja se quedó sin CAI vigente o sin números disponibles.");
    $receptor = (int)($d['receptor_id'] ?? 0) ?: posConsumidorFinal($pdo, $cid);

    // Pagos que no son efectivo
    $otros = [];
    foreach ((array)($d['pagos'] ?? []) as $p) {
        if (!is_array($p) || empty($p['forma']) || ($p['monto'] ?? '') === '' || (float)$p['monto'] == 0) continue;
        $forma = $p['forma'];
        if (!in_array($forma, ['tarjeta', 'transferencia'], true)) throw new Exception("Forma de pago inválida.");
        $ref = mb_substr(trim((string)($p['referencia'] ?? '')), 0, 100);
        if ($forma === 'tarjeta' && $ref === '') throw new Exception("Indica el número de autorización del pago con tarjeta.");
        $u4 = preg_replace('/\D/', '', (string)($p['ultimos4'] ?? ''));
        if ($u4 !== '' && strlen($u4) !== 4) throw new Exception("Los últimos dígitos de la tarjeta deben ser 4.");
        $otros[] = ['forma' => $forma, 'monto' => posMonto($p['monto']), 'referencia' => $ref ?: null, 'ultimos4' => $u4 ?: null];
    }

    $factura = crearFactura($pdo, $cid, $uid, [
        'receptor_id' => $receptor, 'cai_rango_id' => $caiId, 'establecimiento_id' => $turno['establecimiento_id'],
        'condicion_pago' => 'contado', 'productos' => $items,
    ]);
    $total = $factura['total'];
    $sumaOtros = round(array_sum(array_column($otros, 'monto')), 2);
    if ($sumaOtros > $total + 0.004) throw new Exception("Los pagos con tarjeta/transferencia (L " . number_format($sumaOtros, 2) . ") superan el total (L " . number_format($total, 2) . ").");
    $restante = round($total - $sumaOtros, 2);
    $recibido = posMonto($d['efectivo_recibido'] ?? 0, 'efectivo recibido', true);
    if ($restante > 0 && $recibido + 0.004 < $restante) throw new Exception("Falta por cobrar L " . number_format($restante - $recibido, 2) . ".");
    $efectivoAplicado = $restante;
    $cambio = $restante > 0 ? round($recibido - $restante, 2) : 0.0;
    if ($restante <= 0) $recibido = 0.0;

    try {
        $pdo->prepare("INSERT INTO pos_ventas (cliente_id, turno_id, factura_id, total, efectivo_recibido, cambio, idempotencia, usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$cid, $turno['id'], $factura['id'], $total, $recibido, $cambio, $idem, $uid]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') throw new Exception("Esta venta ya se está registrando (envío repetido).");
        throw $e;
    }
    $ventaId = (int)$pdo->lastInsertId();
    $insP = $pdo->prepare("INSERT INTO pos_venta_pagos (venta_id, forma, monto, referencia, ultimos4) VALUES (?, ?, ?, ?, ?)");
    if ($efectivoAplicado > 0) $insP->execute([$ventaId, 'efectivo', $efectivoAplicado, null, null]);
    foreach ($otros as $p) $insP->execute([$ventaId, $p['forma'], $p['monto'], $p['referencia'], $p['ultimos4']]);

    return ['venta_id' => $ventaId, 'factura_id' => $factura['id'], 'correlativo' => $factura['correlativo'], 'total' => $total, 'cambio' => $cambio, 'repetida' => false];
}

/** Resumen del turno (Corte X mientras está abierto; base del Corte Z). */
function posResumen(PDO $pdo, int $cid, int $tid): array
{
    $t = posTurno($pdo, $cid, $tid);
    $q = fn(string $sql, array $p = []) => (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return $s; })();
    $porForma = ['efectivo' => 0.0, 'tarjeta' => 0.0, 'transferencia' => 0.0];
    foreach ($q("SELECT pg.forma, SUM(pg.monto) FROM pos_venta_pagos pg JOIN pos_ventas v ON v.id = pg.venta_id JOIN facturas f ON f.id = v.factura_id
                 WHERE v.turno_id = ? AND f.estado = 'emitida' GROUP BY pg.forma", [$tid])->fetchAll(PDO::FETCH_NUM) as [$f, $m]) $porForma[$f] = round((float)$m, 2);
    [$nVentas, $totVentas] = $q("SELECT COUNT(*), COALESCE(SUM(v.total), 0) FROM pos_ventas v JOIN facturas f ON f.id = v.factura_id WHERE v.turno_id = ? AND f.estado = 'emitida'", [$tid])->fetch(PDO::FETCH_NUM);
    [$nAnul, $totAnul] = $q("SELECT COUNT(*), COALESCE(SUM(v.total), 0) FROM pos_ventas v JOIN facturas f ON f.id = v.factura_id WHERE v.turno_id = ? AND f.estado <> 'emitida'", [$tid])->fetch(PDO::FETCH_NUM);
    $mov = ['entrada' => 0.0, 'retiro' => 0.0];
    foreach ($q("SELECT tipo, SUM(monto) FROM pos_movimientos_caja WHERE turno_id = ? GROUP BY tipo", [$tid])->fetchAll(PDO::FETCH_NUM) as [$tp, $m]) $mov[$tp] = round((float)$m, 2);
    $esperado = round((float)$t['monto_inicial'] + $porForma['efectivo'] + $mov['entrada'] - $mov['retiro'], 2);
    return [
        'turno' => $t, 'ventas' => (int)$nVentas, 'total_ventas' => round((float)$totVentas, 2), 'por_forma' => $porForma,
        'anuladas' => (int)$nAnul, 'total_anuladas' => round((float)$totAnul, 2),
        'entradas' => $mov['entrada'], 'retiros' => $mov['retiro'], 'efectivo_esperado' => $esperado,
    ];
}

/**
 * Entrada o retiro de efectivo. Un cajero (facturador) necesita la autorización de un
 * administrador de la empresa (correo + clave), con límite de intentos.
 */
function posMovimientoCaja(PDO $pdo, int $cid, int $uid, string $rol, array $d): int
{
    $turno = posTurnoDelUsuario($pdo, $cid, $uid);
    if (!$turno) throw new Exception("No tienes un turno de caja abierto.");
    $tipo = $d['tipo'] ?? '';
    if (!in_array($tipo, ['entrada', 'retiro'], true)) throw new Exception("Tipo de movimiento inválido.");
    $monto = posMonto($d['monto'] ?? 0);
    $motivo = trim((string)($d['motivo'] ?? ''));
    if ($motivo === '') throw new Exception("Indica el motivo.");
    if (!in_array($rol, ['admin', 'superadmin'], true)) posAutorizar($pdo, $cid, (string)($d['usuario_autoriza'] ?? ''), (string)($d['clave_autoriza'] ?? ''));
    if ($tipo === 'retiro') {
        $r = posResumen($pdo, $cid, (int)$turno['id']);
        if ($monto > $r['efectivo_esperado'] + 0.004) throw new Exception("No hay suficiente efectivo en caja (esperado L " . number_format($r['efectivo_esperado'], 2) . ").");
    }
    $pdo->prepare("INSERT INTO pos_movimientos_caja (cliente_id, turno_id, tipo, monto, motivo, usuario_id) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$cid, $turno['id'], $tipo, $monto, mb_substr($motivo, 0, 255), $uid]);
    return (int)$pdo->lastInsertId();
}

/** Valida credenciales de un administrador de la empresa (o superadmin). */
function posAutorizar(PDO $pdo, int $cid, string $correo, string $clave): int
{
    if ($correo === '' || $clave === '') throw new Exception("Se requiere la autorización de un administrador.");
    if ($min = intentosBloqueado($pdo, 'autoriza', $correo)) throw new Exception("Demasiados intentos fallidos de autorización. Intenta de nuevo en $min minuto(s).");
    $stmt = $pdo->prepare("SELECT id, rol, cliente_id, clave, estado FROM usuarios WHERE correo = ?");
    $stmt->execute([$correo]);
    $a = $stmt->fetch(PDO::FETCH_ASSOC);
    $ok = $a && password_verify($clave, $a['clave']) && ($a['estado'] ?? 'activo') === 'activo'
        && ($a['rol'] === 'superadmin' || ($a['rol'] === 'admin' && (int)$a['cliente_id'] === $cid));
    if (!$ok) {
        intentosRegistrarFallo($pdo, 'autoriza', $correo);
        throw new Exception("Autorización inválida: se requiere un administrador de la empresa.");
    }
    intentosLimpiar($pdo, 'autoriza', $correo);
    return (int)$a['id'];
}

/** Cierre (Corte Z) con arqueo ciego: el cajero cuenta el efectivo sin ver el esperado. */
function posCerrarTurno(PDO $pdo, int $cid, int $uid, string $rol, array $d): array
{
    $tid = (int)($d['turno_id'] ?? 0);
    $t = posTurno($pdo, $cid, $tid, true);
    if ($t['estado'] !== 'abierto') throw new Exception("El turno ya está cerrado.");
    if ((int)$t['usuario_id'] !== $uid && !in_array($rol, ['admin', 'superadmin'], true)) throw new Exception("Solo el cajero del turno o un administrador puede cerrarlo.");
    $contado = posMonto($d['efectivo_contado'] ?? null, 'efectivo contado', true);
    $r = posResumen($pdo, $cid, $tid);
    $dif = round($contado - $r['efectivo_esperado'], 2);
    $just = trim((string)($d['justificacion'] ?? ''));
    if (abs($dif) > POS_TOLERANCIA_CIERRE && $just === '') {
        throw new Exception("Hay una diferencia de L " . number_format($dif, 2) . " (" . ($dif > 0 ? 'sobrante' : 'faltante') . "). Escribe una justificación para cerrar.");
    }
    $pdo->prepare("UPDATE pos_turnos SET estado = 'cerrado', cerrado_en = NOW(), efectivo_esperado = ?, efectivo_contado = ?, diferencia = ?, justificacion = ? WHERE id = ?")
        ->execute([$r['efectivo_esperado'], $contado, $dif, mb_substr($just, 0, 255) ?: null, $tid]);
    return ['esperado' => $r['efectivo_esperado'], 'contado' => $contado, 'diferencia' => $dif];
}
