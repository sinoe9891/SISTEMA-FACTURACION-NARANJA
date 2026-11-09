<?php
/**
 * cuentas.php — Cuentas por cobrar (facturas) y por pagar (gastos pendientes).
 * Tablas: sql/migraciones/2026-10-03_cuentas_cobrar.sql (+ bancos.php para cobrar a una cuenta).
 *
 * Saldo de una factura emitida:
 *   - con abonos registrados: total − abonos (no anulados)
 *   - sin abonos: 0 si está marcada pagada (registros anteriores a este módulo), si no el total.
 */
require_once __DIR__ . '/bancos.php';

const CXC_METODOS = ['efectivo', 'transferencia', 'cheque', 'tarjeta', 'otro'];

function cxcDisponible(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = (bool)$pdo->query("SHOW TABLES LIKE 'cobros_factura'")->fetchColumn();
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

/** Abonado a cada factura (cobros no anulados, incluidos los anticipos aplicados): [factura_id => monto]. */
function cxcAbonadoPorFactura(PDO $pdo, int $cid): array
{
    if (!cxcDisponible($pdo)) return [];
    $st = $pdo->prepare("SELECT factura_id, SUM(monto) FROM cobros_factura WHERE cliente_id = ? AND anulado = 0 GROUP BY factura_id");
    $st->execute([$cid]);
    return array_map('floatval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
}

/** Lo que falta cobrar de una factura según su total, lo abonado y su marca «pagada» (misma regla que cxcSqlSaldo). */
function cxcSaldoDe(float $total, float $abonado, bool $pagada): float
{
    if ($abonado > 0) return max(0.0, round($total - $abonado, 2));
    return $pagada ? 0.0 : round($total, 2);
}

/**
 * Fecha de pago (vencimiento) de una factura, 'Y-m-d':
 *  - de un contrato: el día de pago del contrato en el mes de su período (o, sin período, el mes de emisión);
 *  - sin contrato: contado vence el día de emisión; crédito, 30 días después.
 */
function facturaVencimiento(string $emision, ?int $periodoMes, ?int $periodoAnio, ?int $diaPago, ?string $condicion): string
{
    $e = substr($emision, 0, 10);
    if ($diaPago) {
        $m = $periodoMes ?: (int)substr($e, 5, 2);
        $a = $periodoAnio ?: (int)substr($e, 0, 4);
        return sprintf('%04d-%02d-%02d', $a, $m, min(max(1, $diaPago), (int)date('t', mktime(0, 0, 0, $m, 1, $a))));
    }
    return strtolower((string)$condicion) === 'credito' ? date('Y-m-d', strtotime($e . ' +30 days')) : $e;
}

/**
 * Semáforo del vencimiento de una factura con saldo: clase vencida (rojo) | hoy (naranja) | pronto (ámbar, 1–7 días) | futura (neutro).
 * Devuelve ['clase', 'dias' (negativo = ya venció), 'txt'].
 */
function facturaVencEstado(string $vence, ?string $hoy = null): array
{
    $dias = (int)round((strtotime($vence) - strtotime($hoy ?? date('Y-m-d'))) / 86400);
    if ($dias < 0) return ['clase' => 'vencida', 'dias' => $dias, 'txt' => 'Vencida hace ' . (-$dias) . ' d'];
    if ($dias === 0) return ['clase' => 'hoy', 'dias' => 0, 'txt' => 'Vence hoy'];
    if ($dias <= 7) return ['clase' => 'pronto', 'dias' => $dias, 'txt' => 'Vence en ' . $dias . ' d'];
    return ['clase' => 'futura', 'dias' => $dias, 'txt' => 'Vence ' . date('d/m', strtotime($vence))];
}

/** Expresión SQL del saldo de la factura f (requiere el alias f y la subconsulta de abonos). */
function cxcSqlSaldo(): string
{
    return "CASE
        WHEN COALESCE(ab.abonado, 0) > 0 THEN GREATEST(f.total - ab.abonado, 0)
        WHEN f.pagada = 1 THEN 0
        ELSE f.total END";
}

/**
 * Desde cuándo se debe una factura: la fecha de emisión, o —si es de un contrato y su período es anterior— el día de pago
 * de ese período según el contrato (p. ej. septiembre facturado el 16/10 con día de pago 30 → se debe desde el 30/09).
 * Requiere los alias f (facturas) y ct (contratos, LEFT JOIN por f.contrato_id).
 */
function cxcSqlDesde(): string
{
    $inicioMes = "DATE_ADD(MAKEDATE(f.periodo_anio, 1), INTERVAL (f.periodo_mes - 1) MONTH)";
    return "CASE WHEN f.periodo_mes IS NOT NULL AND f.periodo_anio IS NOT NULL AND ct.id IS NOT NULL
        THEN LEAST(DATE(f.fecha_emision), DATE_ADD($inicioMes, INTERVAL (LEAST(GREATEST(COALESCE(ct.dia_pago, 1), 1), DAY(LAST_DAY($inicioMes))) - 1) DAY))
        ELSE DATE(f.fecha_emision) END";
}

/** Facturas emitidas con saldo pendiente (y su antigüedad en días, desde que se debe: ver cxcSqlDesde). */
function cxcConPrograma(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) { try { $ok = (bool)$pdo->query("SHOW TABLES LIKE 'programas'")->fetchColumn(); } catch (Throwable $e) { $ok = false; } }
    return $ok;
}

function cxcFacturasPendientes(PDO $pdo, int $cid, ?int $receptor = null): array
{
    $sql = "
        SELECT f.id, f.correlativo, f.fecha_emision, f.total, f.pagada, f.receptor_id, f.condicion_pago,
               cf.nombre AS receptor, cf.rtn AS receptor_rtn, " . (cxcConPrograma($pdo) ? "(SELECT pg.nombre FROM programas pg WHERE pg.id = f.programa_id)" : "NULL") . " AS programa,
               COALESCE(f.periodo_mes, MONTH(f.fecha_emision)) AS periodo_mes,
               COALESCE(f.periodo_anio, YEAR(f.fecha_emision)) AS periodo_anio,
               COALESCE(ab.abonado, 0) AS abonado,
               " . cxcSqlSaldo() . " AS saldo,
               " . cxcSqlDesde() . " AS desde,
               DATEDIFF(CURDATE(), " . cxcSqlDesde() . ") AS dias
        FROM facturas f
        JOIN clientes_factura cf ON cf.id = f.receptor_id
        LEFT JOIN contratos ct ON ct.id = f.contrato_id AND ct.cliente_id = f.cliente_id
        LEFT JOIN (SELECT factura_id, SUM(monto) AS abonado FROM cobros_factura WHERE anulado = 0 GROUP BY factura_id) ab ON ab.factura_id = f.id
        WHERE f.cliente_id = ? AND f.estado = 'emitida'" . ($receptor ? " AND f.receptor_id = ?" : "") . "
        HAVING saldo > 0.004
        ORDER BY f.fecha_emision";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($receptor ? [$cid, $receptor] : [$cid]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function cxcTramo(int $dias): string
{
    return $dias <= 30 ? '0-30' : ($dias <= 60 ? '31-60' : ($dias <= 90 ? '61-90' : '90+'));
}

/** Datos de una factura de la empresa con su saldo (bloquea la fila para registrar cobros). */
function cxcFactura(PDO $pdo, int $cid, int $facturaId, bool $bloquear = false): array
{
    $stmt = $pdo->prepare("SELECT * FROM facturas WHERE id = ? AND cliente_id = ?" . ($bloquear ? " FOR UPDATE" : ""));
    $stmt->execute([$facturaId, $cid]);
    $f = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$f) throw new Exception("Factura no encontrada.");
    $ab = $pdo->prepare("SELECT COALESCE(SUM(monto), 0) FROM cobros_factura WHERE factura_id = ? AND anulado = 0");
    $ab->execute([$facturaId]);
    $f['abonado'] = round((float)$ab->fetchColumn(), 2);
    $f['saldo'] = $f['abonado'] > 0 ? max(0, round((float)$f['total'] - $f['abonado'], 2)) : ((int)$f['pagada'] ? 0.0 : round((float)$f['total'], 2));
    return $f;
}

/** Marca pagada/no pagada según los abonos vigentes. */
function cxcActualizarPagada(PDO $pdo, int $facturaId): void
{
    $stmt = $pdo->prepare("SELECT f.total, COALESCE(SUM(c.monto), 0), COUNT(c.id)
        FROM facturas f LEFT JOIN cobros_factura c ON c.factura_id = f.id AND c.anulado = 0 WHERE f.id = ? GROUP BY f.id");
    $stmt->execute([$facturaId]);
    [$total, $abonado, $n] = $stmt->fetch(PDO::FETCH_NUM);
    if ((int)$n === 0) return; // sin abonos: se respeta la marca manual existente
    $pdo->prepare("UPDATE facturas SET pagada = ? WHERE id = ?")->execute([(float)$abonado + 0.004 >= (float)$total ? 1 : 0, $facturaId]);
}

/** Registra un abono. Si viene cuenta_id, el dinero entra a esa cuenta bancaria (HNL). */
/** Comprobantes del cobro: acepta [archivo, nombre] (uno) o una lista de ellos → lista de [archivo, nombre]. */
function cxcAdjuntosLista(?array $a): array
{
    if (!$a) return [];
    return is_array($a[0] ?? null) ? array_values($a) : [$a];
}

/** Comprobantes de varios cobros: [cobro_id => [[id, nombre], …]] (los de la tabla cobro_archivos; si no hay, el principal del cobro). */
function cxcArchivosDeCobros(PDO $pdo, int $cid, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return [];
    $out = [];
    try {
        $st = $pdo->prepare("SELECT id, cobro_id, nombre FROM cobro_archivos WHERE cliente_id = ? AND cobro_id IN (" . implode(',', $ids) . ") ORDER BY id");
        $st->execute([$cid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) $out[(int)$a['cobro_id']][] = ['id' => (int)$a['id'], 'nombre' => $a['nombre']];
    } catch (Throwable $e) { /* sin la tabla de varios comprobantes */ }
    return $out;
}

function cxcRegistrarCobro(PDO $pdo, int $cid, int $usuario, array $d, ?array $adjunto = null): int
{
    $adjuntos = cxcAdjuntosLista($adjunto);
    $adjunto = $adjuntos[0] ?? null;   // el primero queda como principal del cobro y de su entrada en el banco
    $f = cxcFactura($pdo, $cid, (int)($d['factura_id'] ?? 0), true);
    if ($f['estado'] !== 'emitida') throw new Exception("Solo se cobran facturas emitidas.");
    if ($f['saldo'] <= 0) throw new Exception("La factura {$f['correlativo']} no tiene saldo pendiente.");
    $monto = bancoMonto($d['monto'] ?? 0);
    if ($monto > $f['saldo'] + 0.004) throw new Exception("El abono (L " . number_format($monto, 2) . ") supera el saldo de la factura (L " . number_format($f['saldo'], 2) . ").");
    $fecha = bancoFecha((string)($d['fecha'] ?? ''));
    if ($fecha < substr($f['fecha_emision'], 0, 10)) throw new Exception("La fecha del cobro no puede ser anterior a la factura.");
    $metodo = $d['metodo'] ?? 'transferencia';
    if (!in_array($metodo, CXC_METODOS, true)) throw new Exception("Método de pago inválido.");
    $ref = mb_substr(trim((string)($d['referencia'] ?? '')), 0, 100) ?: null;

    // Facturas pagadas antes de este módulo (marca manual sin abonos) no tienen saldo: ya se validó arriba.
    $movId = null;
    $cuentaId = (int)($d['cuenta_id'] ?? 0) ?: null;
    if ($cuentaId) {
        if (!bancosDisponible($pdo)) throw new Exception("El módulo de bancos no está instalado.");
        $cuenta = bancoCuenta($pdo, $cid, $cuentaId, true);
        if ($cuenta['moneda'] !== 'HNL') throw new Exception("Las facturas son en lempiras: elige una cuenta en lempiras.");
        $movId = bancoInsertarMovimiento($pdo, $cid, $cuentaId, [
            'fecha' => $fecha, 'sentido' => 'entrada', 'tipo' => 'cobro_factura', 'monto' => $monto,
            'descripcion' => mb_substr("Cobro factura {$f['correlativo']}", 0, 255), 'referencia' => $ref,
            'factura_id' => $f['id'], 'usuario_id' => $usuario,
        ]);
    }
    $pdo->prepare("INSERT INTO cobros_factura (cliente_id, factura_id, fecha, monto, metodo, referencia, cuenta_id, movimiento_id, notas, usuario_id)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$cid, $f['id'], $fecha, $monto, $metodo, $ref, $cuentaId, $movId, mb_substr(trim((string)($d['notas'] ?? '')), 0, 255) ?: null, $usuario]);
    $id = (int)$pdo->lastInsertId();
    // Comprobante (captura de la transferencia…): en el cobro y en su entrada del banco
    if ($adjunto) {
        $pdo->prepare("UPDATE cobros_factura SET archivo_adjunto = ?, archivo_nombre = ? WHERE id = ?")->execute([$adjunto[0], $adjunto[1], $id]);
        if ($movId) {
            try { $pdo->prepare("UPDATE movimientos_bancarios SET archivo_adjunto = ?, archivo_nombre = ? WHERE id = ? AND archivo_adjunto IS NULL")->execute([$adjunto[0], $adjunto[1], $movId]); }
            catch (PDOException $e) { /* sin comprobante en movimientos */ }
        }
    }
    // Todos los comprobantes (la misma captura puede servir a varios cobros: los archivos se comparten)
    if ($adjuntos) {
        try {
            $ins = $pdo->prepare("INSERT INTO cobro_archivos (cliente_id, cobro_id, archivo, nombre, usuario_id) VALUES (?, ?, ?, ?, ?)");
            foreach ($adjuntos as [$arch, $nom]) $ins->execute([$cid, $id, $arch, $nom, $usuario]);
        } catch (PDOException $e) { /* sin la tabla: queda el principal */ }
    }
    cxcActualizarPagada($pdo, (int)$f['id']);
    return $id;
}

/**
 * Cobra varias facturas de una vez (p. ej. una transferencia que paga dos facturas): a cada una se le registra su saldo,
 * con la misma fecha, método, referencia, cuenta (cada factura entra al banco como su propio depósito) y comprobante.
 * Una factura marcada «pagada» a mano sin abonos se cobra por su total (así su dinero entra al banco).
 * $cuentas (opcional): [factura_id => cuenta_id] para que cada factura entre a su propia cuenta ('' = no registrar en banco).
 * Devuelve [factura_id => cobro_id].
 */
function cxcCobrarVarias(PDO $pdo, int $cid, int $usuario, array $ids, array $d, ?array $adjunto = null, array $cuentas = []): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) throw new Exception("Elige al menos una factura.");
    $out = [];
    foreach ($ids as $fid) {
        $f = cxcFactura($pdo, $cid, $fid, true);
        if ($f['saldo'] <= 0 && (float)$f['abonado'] <= 0 && (int)$f['pagada']) {
            // Pagada a mano sin cobro registrado: se cobra completa (vuelve a quedar pagada al registrar el abono)
            $pdo->prepare("UPDATE facturas SET pagada = 0 WHERE id = ? AND cliente_id = ?")->execute([$fid, $cid]);
            $f = cxcFactura($pdo, $cid, $fid, true);
        }
        if ($f['saldo'] <= 0) throw new Exception("La factura {$f['correlativo']} ya está cobrada.");
        $dd = $d;
        if (array_key_exists($fid, $cuentas)) $dd['cuenta_id'] = (int)$cuentas[$fid] ?: null;
        $out[$fid] = cxcRegistrarCobro($pdo, $cid, $usuario, ['factura_id' => $fid, 'monto' => $f['saldo']] + $dd, $adjunto);
    }
    return $out;
}

/** Anula un abono (y su movimiento bancario, si lo tiene). */
function cxcAnularCobro(PDO $pdo, int $cid, int $cobroId, string $motivo): void
{
    $motivo = trim($motivo);
    if ($motivo === '') throw new Exception("Indica el motivo de la anulación.");
    $stmt = $pdo->prepare("SELECT * FROM cobros_factura WHERE id = ? AND cliente_id = ? FOR UPDATE");
    $stmt->execute([$cobroId, $cid]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$c) throw new Exception("Cobro no encontrado.");
    if ((int)$c['anulado']) throw new Exception("El cobro ya está anulado.");
    // Abono que vino de un anticipo del contrato: el dinero sigue recibido, el anticipo vuelve a quedar sin aplicar
    $deAnticipo = false;
    if ($pdo->query("SHOW TABLES LIKE 'contratos_anticipos'")->fetchColumn()) {
        $u = $pdo->prepare("UPDATE contratos_anticipos SET factura_id = NULL, cobro_id = NULL WHERE cobro_id = ? AND cliente_id = ?");
        $u->execute([$cobroId, $cid]);
        $deAnticipo = $u->rowCount() > 0;
    }
    if ($c['movimiento_id'] && !$deAnticipo) {
        $m = $pdo->prepare("SELECT conciliado FROM movimientos_bancarios WHERE id = ?");
        $m->execute([$c['movimiento_id']]);
        if ((int)$m->fetchColumn()) throw new Exception("El depósito de este cobro ya está conciliado en el banco: quita la conciliación primero.");
        $pdo->prepare("UPDATE movimientos_bancarios SET anulado = 1 WHERE id = ?")->execute([$c['movimiento_id']]);
    }
    $pdo->prepare("UPDATE cobros_factura SET anulado = 1, motivo_anulacion = ? WHERE id = ?")->execute([mb_substr($motivo, 0, 255), $cobroId]);
    // Si quedan otros abonos se recalcula; si no queda ninguno, la factura vuelve a "no pagada"
    $quedan = $pdo->prepare("SELECT COUNT(*) FROM cobros_factura WHERE factura_id = ? AND anulado = 0");
    $quedan->execute([$c['factura_id']]);
    if ($quedan->fetchColumn()) cxcActualizarPagada($pdo, (int)$c['factura_id']);
    else $pdo->prepare("UPDATE facturas SET pagada = 0 WHERE id = ?")->execute([$c['factura_id']]);
}

/**
 * Edita un abono ya registrado: fecha, método, referencia, notas, cuenta del banco y comprobantes.
 * El monto NO se cambia (si está mal se anula y se registra de nuevo). Mantiene al día su entrada en el banco:
 * mismo cambio de fecha, referencia y cuenta; con cuenta nueva la crea; sin cuenta la anula. Un depósito ya conciliado no se mueve.
 * $nuevos = comprobantes nuevos [[archivo, nombre], …]; $quitar = ids de cobro_archivos a quitar (o 'principal' para el comprobante antiguo).
 */
function cxcEditarCobro(PDO $pdo, int $cid, int $usuario, int $cobroId, array $d, array $nuevos = [], array $quitar = []): void
{
    $st = $pdo->prepare("SELECT c.*, f.fecha_emision, f.correlativo FROM cobros_factura c JOIN facturas f ON f.id = c.factura_id WHERE c.id = ? AND c.cliente_id = ? FOR UPDATE");
    $st->execute([$cobroId, $cid]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) throw new Exception("Cobro no encontrado.");
    if ((int)$c['anulado']) throw new Exception("Un abono anulado no se edita.");
    if ($pdo->query("SHOW TABLES LIKE 'contratos_anticipos'")->fetchColumn()) {
        $a = $pdo->prepare("SELECT COUNT(*) FROM contratos_anticipos WHERE cobro_id = ? AND cliente_id = ?");
        $a->execute([$cobroId, $cid]);
        if ($a->fetchColumn()) throw new Exception("Este abono viene de un pago anticipado del contrato: se edita desde el contrato.");
    }
    $fecha = bancoFecha((string)($d['fecha'] ?? ''));
    if ($fecha < substr($c['fecha_emision'], 0, 10)) throw new Exception("La fecha del cobro no puede ser anterior a la factura.");
    $metodo = $d['metodo'] ?? $c['metodo'];
    if (!in_array($metodo, CXC_METODOS, true)) throw new Exception("Método de pago inválido.");
    $ref = mb_substr(trim((string)($d['referencia'] ?? '')), 0, 100) ?: null;
    $notas = mb_substr(trim((string)($d['notas'] ?? '')), 0, 255) ?: null;

    // Entrada en el banco
    $cuentaActual = $c['cuenta_id'] ? (int)$c['cuenta_id'] : null;
    $cuentaNueva = array_key_exists('cuenta_id', $d) ? ((int)$d['cuenta_id'] ?: null) : $cuentaActual;
    $movId = $c['movimiento_id'] ? (int)$c['movimiento_id'] : null;
    if ($cuentaNueva && (!$movId || $cuentaNueva !== $cuentaActual)) {
        if (!bancosDisponible($pdo)) throw new Exception("El módulo de bancos no está instalado.");
        if (bancoCuenta($pdo, $cid, $cuentaNueva, true)['moneda'] !== 'HNL') throw new Exception("Las facturas son en lempiras: elige una cuenta en lempiras.");
    }
    if ($movId) {
        $m = $pdo->prepare("SELECT * FROM movimientos_bancarios WHERE id = ? AND cliente_id = ?");
        $m->execute([$movId, $cid]);
        $mov = $m->fetch(PDO::FETCH_ASSOC);
        if ($mov && (int)$mov['conciliado'] && ($fecha !== $mov['fecha'] || $ref !== ($mov['referencia'] ?: null) || $cuentaNueva !== (int)$mov['cuenta_id'])) {
            throw new Exception("El depósito de este cobro ya está conciliado en el banco: quita la conciliación para cambiar su fecha, referencia o cuenta.");
        }
        if ($cuentaNueva === null) {
            $pdo->prepare("UPDATE movimientos_bancarios SET anulado = 1 WHERE id = ?")->execute([$movId]);
            $movId = null;
        } else {
            $pdo->prepare("UPDATE movimientos_bancarios SET cuenta_id = ?, fecha = ?, referencia = ? WHERE id = ?")->execute([$cuentaNueva, $fecha, $ref, $movId]);
        }
    } elseif ($cuentaNueva) {
        $movId = bancoInsertarMovimiento($pdo, $cid, $cuentaNueva, [
            'fecha' => $fecha, 'sentido' => 'entrada', 'tipo' => 'cobro_factura', 'monto' => (float)$c['monto'],
            'descripcion' => mb_substr("Cobro factura {$c['correlativo']}", 0, 255), 'referencia' => $ref,
            'factura_id' => (int)$c['factura_id'], 'usuario_id' => $usuario,
        ]);
    }
    $pdo->prepare("UPDATE cobros_factura SET fecha = ?, metodo = ?, referencia = ?, notas = ?, cuenta_id = ?, movimiento_id = ? WHERE id = ?")
        ->execute([$fecha, $metodo, $ref, $notas, $cuentaNueva, $movId, $cobroId]);

    // Comprobantes: el antiguo (solo el principal del cobro) pasa a la tabla para poder quitarlo; se quitan y se agregan los pedidos
    if ($quitar || $nuevos) {
        try {
            $q = $pdo->prepare("SELECT COUNT(*) FROM cobro_archivos WHERE cobro_id = ? AND cliente_id = ?");
            $q->execute([$cobroId, $cid]);
            if (!$q->fetchColumn() && $c['archivo_adjunto']) {
                $pdo->prepare("INSERT INTO cobro_archivos (cliente_id, cobro_id, archivo, nombre, usuario_id) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$cid, $cobroId, $c['archivo_adjunto'], $c['archivo_nombre'] ?: $c['archivo_adjunto'], $usuario]);
            }
            $del = $pdo->prepare("DELETE FROM cobro_archivos WHERE id = ? AND cobro_id = ? AND cliente_id = ?");
            foreach ($quitar as $qid) {
                if ($qid === 'principal') $pdo->prepare("DELETE FROM cobro_archivos WHERE cobro_id = ? AND cliente_id = ? AND archivo = ?")->execute([$cobroId, $cid, $c['archivo_adjunto']]);
                else $del->execute([(int)$qid, $cobroId, $cid]);
            }
            $ins = $pdo->prepare("INSERT INTO cobro_archivos (cliente_id, cobro_id, archivo, nombre, usuario_id) VALUES (?, ?, ?, ?, ?)");
            foreach ($nuevos as [$arch, $nom]) $ins->execute([$cid, $cobroId, $arch, $nom, $usuario]);
            // El primero que quede es el principal del cobro y de su entrada en el banco
            $p = $pdo->prepare("SELECT archivo, nombre FROM cobro_archivos WHERE cobro_id = ? AND cliente_id = ? ORDER BY id LIMIT 1");
            $p->execute([$cobroId, $cid]);
            $pr = $p->fetch(PDO::FETCH_ASSOC) ?: ['archivo' => null, 'nombre' => null];
            $pdo->prepare("UPDATE cobros_factura SET archivo_adjunto = ?, archivo_nombre = ? WHERE id = ?")->execute([$pr['archivo'], $pr['nombre'], $cobroId]);
            if ($movId) {
                try { $pdo->prepare("UPDATE movimientos_bancarios SET archivo_adjunto = ?, archivo_nombre = ? WHERE id = ?")->execute([$pr['archivo'], $pr['nombre'], $movId]); }
                catch (PDOException $e) { /* sin comprobante en movimientos */ }
            }
        } catch (PDOException $e) {
            throw new Exception("No se pudieron actualizar los comprobantes (falta la tabla cobro_archivos).");
        }
    }
}

/** Gastos pendientes (cuentas por pagar) con días de atraso (negativo = aún no vence). */
function cxpPendientes(PDO $pdo, int $cid): array
{
    $stmt = $pdo->prepare("
        SELECT g.id, g.descripcion, g.proveedor, g.monto, g.fecha, g.frecuencia, g.tipo, g.metodo_pago,
               g.gasto_grupo_id, g.dia_pago, g.fecha_vencimiento, g.categoria_id,
               cg.nombre AS categoria, DATEDIFF(CURDATE(), g.fecha) AS dias
        FROM gastos g LEFT JOIN categorias_gastos cg ON cg.id = g.categoria_id
        WHERE g.cliente_id = ? AND g.estado = 'pendiente'
        ORDER BY g.fecha
    ");
    $stmt->execute([$cid]);
    $gastos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return cxpConCuotasFuturas($pdo, $cid, $gastos);
}

/**
 * Series con fecha de fin (p. ej. un préstamo de 11 cuotas): agrega las cuotas que faltan y que el sistema
 * todavía no registró (la siguiente se crea al pagar la anterior), marcadas con 'futura' => true.
 * A cada gasto de una serie le pone 'cuota' (número) y 'cuotas' (total de la serie).
 */
function cxpConCuotasFuturas(PDO $pdo, int $cid, array $gastos): array
{
    $grupos = array_unique(array_filter(array_map(fn($g) => (int)$g['gasto_grupo_id'], $gastos)));
    if (!$grupos) return $gastos;
    $st = $pdo->prepare("SELECT gasto_grupo_id g, fecha FROM gastos WHERE cliente_id = ? AND estado <> 'anulado' AND gasto_grupo_id IN (" . implode(',', $grupos) . ") ORDER BY fecha, id");
    $st->execute([$cid]);
    $fechas = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $fechas[(int)$r['g']][] = $r['fecha'];

    $futuras = [];
    $ultimo = [];   // último gasto pendiente de cada serie (de él salen las cuotas futuras)
    foreach ($gastos as $g) if ($g['gasto_grupo_id'] && (!isset($ultimo[$g['gasto_grupo_id']]) || $g['fecha'] > $ultimo[$g['gasto_grupo_id']]['fecha'])) $ultimo[$g['gasto_grupo_id']] = $g;
    foreach ($ultimo as $grupo => $g) {
        if (!in_array($g['frecuencia'], ['mensual', 'anual'], true) || empty($g['fecha_vencimiento']) || str_starts_with((string)$g['descripcion'], 'Sueldo ')) continue;
        $f = new DateTime($g['fecha']);
        $dia = (int)($g['dia_pago'] ?: $f->format('j'));
        for ($k = 0; $k < 120; $k++) {
            $f->modify('first day of this month')->modify($g['frecuencia'] === 'anual' ? '+1 year' : '+1 month');
            $f->setDate((int)$f->format('Y'), (int)$f->format('n'), min($dia, (int)$f->format('t')));
            if ($f->format('Y-m-d') > $g['fecha_vencimiento']) break;
            $fechas[$grupo][] = $f->format('Y-m-d');
            $futuras[] = ['id' => 0, 'futura' => true, 'fecha' => $f->format('Y-m-d'), 'dias' => (int)floor((strtotime(date('Y-m-d')) - $f->getTimestamp()) / 86400)] + $g;
        }
    }
    $todos = array_merge($gastos, $futuras);
    foreach ($todos as &$g) {
        if (!$g['gasto_grupo_id'] || empty($g['fecha_vencimiento'])) continue;   // series sin fin: no se numeran
        $lista = $fechas[(int)$g['gasto_grupo_id']] ?? [];
        sort($lista);
        $g['cuota'] = array_search($g['fecha'], $lista, true) + 1;
        $g['cuotas'] = count($lista);
    }
    unset($g);
    usort($todos, fn($a, $b) => $a['fecha'] <=> $b['fecha']);
    return $todos;
}
