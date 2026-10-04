<?php
/**
 * anticipos.php — Pagos anticipados de un contrato (dinero recibido antes de emitir la factura).
 *
 * Caso típico: proyecto por etapas que se factura al final por el total. Cada pago se registra
 * como anticipo del contrato; al emitir la factura, anticipoAplicar() los convierte en abonos
 * (cobros_factura) de esa factura, con su fecha original, sin duplicar el movimiento bancario.
 * Tabla: sql/migraciones/2026-10-03_contratos_anticipos.sql
 */
require_once __DIR__ . '/cuentas.php';

function anticiposDisponible(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = (bool)$pdo->query("SHOW TABLES LIKE 'contratos_anticipos'")->fetchColumn() && cxcDisponible($pdo);
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

/** Anticipos del contrato (vigentes y anulados), con la factura a la que se aplicaron. */
function anticiposContrato(PDO $pdo, int $cid, int $contratoId): array
{
    $st = $pdo->prepare("
        SELECT a.*, f.correlativo, b.banco, b.numero AS cuenta_numero
        FROM contratos_anticipos a
        LEFT JOIN facturas f ON f.id = a.factura_id
        LEFT JOIN cuentas_bancarias b ON b.id = a.cuenta_id
        WHERE a.cliente_id = ? AND a.contrato_id = ?
        ORDER BY a.fecha, a.id");
    $st->execute([$cid, $contratoId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function anticipoContratoValido(PDO $pdo, int $cid, int $contratoId): array
{
    $st = $pdo->prepare("SELECT * FROM contratos WHERE id = ? AND cliente_id = ?");
    $st->execute([$contratoId, $cid]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) throw new Exception("Contrato no encontrado.");
    return $c;
}

/** Registra un pago anticipado. Si se indica cuenta, entra como depósito en Bancos. */
function anticipoRegistrar(PDO $pdo, int $cid, int $usuario, array $d): int
{
    $c = anticipoContratoValido($pdo, $cid, (int)($d['contrato_id'] ?? 0));
    $monto = bancoMonto($d['monto'] ?? 0);
    $fecha = bancoFecha((string)($d['fecha'] ?? ''));
    $metodo = $d['metodo'] ?? 'transferencia';
    if (!in_array($metodo, CXC_METODOS, true)) throw new Exception("Método de pago inválido.");
    $ref = mb_substr(trim((string)($d['referencia'] ?? '')), 0, 100) ?: null;
    $concepto = mb_substr(trim((string)($d['concepto'] ?? '')), 0, 255) ?: null;

    $movId = null;
    $cuentaId = (int)($d['cuenta_id'] ?? 0) ?: null;
    if ($cuentaId) {
        $cuenta = bancoCuenta($pdo, $cid, $cuentaId, true);
        if ($cuenta['moneda'] !== 'HNL') throw new Exception("Elige una cuenta en lempiras.");
        $movId = bancoInsertarMovimiento($pdo, $cid, $cuentaId, [
            'fecha' => $fecha, 'sentido' => 'entrada', 'tipo' => 'deposito', 'monto' => $monto,
            'descripcion' => mb_substr('Anticipo contrato #' . $c['id'] . ($concepto ? " · $concepto" : ''), 0, 255),
            'referencia' => $ref, 'usuario_id' => $usuario,
        ]);
    }
    $pdo->prepare("INSERT INTO contratos_anticipos (cliente_id, contrato_id, fecha, monto, metodo, referencia, concepto, cuenta_id, movimiento_id, usuario_id)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$cid, $c['id'], $fecha, $monto, $metodo, $ref, $concepto, $cuentaId, $movId, $usuario]);
    return (int)$pdo->lastInsertId();
}

/** Anula un anticipo que aún no se aplicó a una factura (y su depósito bancario, si lo tiene). */
function anticipoAnular(PDO $pdo, int $cid, int $id, string $motivo): void
{
    $motivo = trim($motivo);
    if ($motivo === '') throw new Exception("Indica el motivo de la anulación.");
    $st = $pdo->prepare("SELECT * FROM contratos_anticipos WHERE id = ? AND cliente_id = ? FOR UPDATE");
    $st->execute([$id, $cid]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) throw new Exception("Anticipo no encontrado.");
    if ((int)$a['anulado']) throw new Exception("El anticipo ya está anulado.");
    if ($a['factura_id']) throw new Exception("El anticipo ya se aplicó a una factura: anula primero ese abono en la factura.");
    if ($a['movimiento_id']) bancoAnularMovimiento($pdo, $cid, (int)$a['movimiento_id']);
    $pdo->prepare("UPDATE contratos_anticipos SET anulado = 1, motivo_anulacion = ? WHERE id = ?")->execute([mb_substr($motivo, 0, 255), $id]);
}

/**
 * Aplica los anticipos pendientes del contrato a una factura emitida del mismo contrato:
 * cada anticipo se vuelve un abono con su fecha y referencia (sin otro movimiento bancario).
 * Devuelve [aplicados, monto aplicado, saldo de la factura].
 */
function anticipoAplicar(PDO $pdo, int $cid, int $usuario, int $contratoId, int $facturaId): array
{
    $f = cxcFactura($pdo, $cid, $facturaId, true);
    if ((int)$f['contrato_id'] !== $contratoId) throw new Exception("La factura no pertenece a este contrato.");
    if ($f['estado'] !== 'emitida') throw new Exception("Solo se aplican anticipos a facturas emitidas.");
    $st = $pdo->prepare("SELECT * FROM contratos_anticipos WHERE cliente_id = ? AND contrato_id = ? AND anulado = 0 AND factura_id IS NULL ORDER BY fecha, id FOR UPDATE");
    $st->execute([$cid, $contratoId]);
    $pend = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$pend) throw new Exception("No hay anticipos pendientes de aplicar.");
    $total = array_sum(array_column($pend, 'monto'));
    if ($total > $f['saldo'] + 0.004) {
        throw new Exception("Los anticipos (L " . number_format($total, 2) . ") superan el saldo de la factura (L " . number_format($f['saldo'], 2) . ").");
    }
    $ins = $pdo->prepare("INSERT INTO cobros_factura (cliente_id, factura_id, fecha, monto, metodo, referencia, cuenta_id, movimiento_id, notas, usuario_id)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $upd = $pdo->prepare("UPDATE contratos_anticipos SET factura_id = ?, cobro_id = ? WHERE id = ?");
    foreach ($pend as $a) {
        // El dinero ya entró al banco al registrar el anticipo: el abono solo lo referencia
        $ins->execute([$cid, $f['id'], $a['fecha'], $a['monto'], $a['metodo'], $a['referencia'], $a['cuenta_id'], $a['movimiento_id'],
            mb_substr('Anticipo aplicado' . ($a['concepto'] ? ': ' . $a['concepto'] : ''), 0, 255), $usuario]);
        $upd->execute([$f['id'], (int)$pdo->lastInsertId(), $a['id']]);
    }
    cxcActualizarPagada($pdo, (int)$f['id']);
    $f = cxcFactura($pdo, $cid, $facturaId);
    return [count($pend), round($total, 2), $f['saldo']];
}
