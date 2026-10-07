<?php
/**
 * bancos.php — Lógica del módulo de bancos (cuentas, movimientos, transferencias, cheques).
 * Tablas: sql/migraciones/2026-10-03_bancos.sql
 *
 * Reglas:
 * - Los montos se guardan siempre positivos, en la moneda de la cuenta; `sentido` dice si entra o sale.
 * - Saldo contable = saldo inicial + entradas − salidas (sin anulados).
 * - Un cheque emitido no mueve el saldo hasta que se marca como cobrado; mientras tanto es "comprometido".
 * - Nada se borra: movimientos y cheques se anulan (queda el rastro).
 */

const BANCO_TIPOS_MOV = ['deposito', 'retiro', 'transferencia', 'cheque', 'pago_gasto', 'cobro_factura', 'comision', 'interes', 'ajuste'];
const BANCO_TIPOS_MANUALES = [
    'deposito' => 'entrada', 'interes' => 'entrada',
    'retiro' => 'salida', 'comision' => 'salida',
    'ajuste' => null,   // ajuste: el usuario elige el sentido
];

function bancosDisponible(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = (bool)$pdo->query("SHOW TABLES LIKE 'movimientos_bancarios'")->fetchColumn();
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

function bancoFecha(string $f): string
{
    $d = DateTime::createFromFormat('Y-m-d', $f);
    if (!$d || $d->format('Y-m-d') !== $f) throw new Exception("Fecha inválida.");
    return $f;
}

function bancoMonto($m): float
{
    if (!is_numeric($m) || (float)$m <= 0) throw new Exception("El monto debe ser mayor que 0.");
    return round((float)$m, 2);
}

/** Cuenta de la empresa (lanza excepción si no existe o no es suya). */
function bancoCuenta(PDO $pdo, int $cid, int $cuentaId, bool $soloActiva = false): array
{
    $stmt = $pdo->prepare("SELECT * FROM cuentas_bancarias WHERE id = ? AND cliente_id = ?");
    $stmt->execute([$cuentaId, $cid]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$c) throw new Exception("Cuenta bancaria no encontrada.");
    if ($soloActiva && !(int)$c['activa']) throw new Exception("La cuenta {$c['banco']} {$c['numero']} está desactivada.");
    return $c;
}

/** Cuentas de la empresa con saldo contable y cheques comprometidos. */
/** ' selected' si la cuenta es la predeterminada (para los <select> de cobros y pagos). */
function bancoSel(array $c): string
{
    return !empty($c['predeterminada']) ? ' selected' : '';
}

function bancoCuentas(PDO $pdo, int $cid, bool $soloActivas = false): array
{
    $stmt = $pdo->prepare("
        SELECT c.*,
            c.saldo_inicial
              + COALESCE((SELECT SUM(CASE WHEN m.sentido = 'entrada' THEN m.monto ELSE -m.monto END)
                          FROM movimientos_bancarios m WHERE m.cuenta_id = c.id AND m.anulado = 0), 0) AS saldo,
            COALESCE((SELECT SUM(ch.monto) FROM cheques ch WHERE ch.cuenta_id = c.id AND ch.estado = 'emitido'), 0) AS comprometido,
            (SELECT MAX(m.fecha) FROM movimientos_bancarios m WHERE m.cuenta_id = c.id AND m.anulado = 0) AS ultimo_movimiento
        FROM cuentas_bancarias c
        WHERE c.cliente_id = ?" . ($soloActivas ? " AND c.activa = 1" : "") . "
        ORDER BY c.activa DESC, c.moneda, c.banco, c.numero
    ");
    $stmt->execute([$cid]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function bancoSaldo(PDO $pdo, int $cuentaId): float
{
    $stmt = $pdo->prepare("
        SELECT c.saldo_inicial + COALESCE(SUM(CASE WHEN m.sentido = 'entrada' THEN m.monto ELSE -m.monto END), 0)
        FROM cuentas_bancarias c
        LEFT JOIN movimientos_bancarios m ON m.cuenta_id = c.id AND m.anulado = 0
        WHERE c.id = ? GROUP BY c.id
    ");
    $stmt->execute([$cuentaId]);
    return round((float)$stmt->fetchColumn(), 2);
}

/** Inserta un movimiento ya validado. Devuelve su id. */
function bancoInsertarMovimiento(PDO $pdo, int $cid, int $cuentaId, array $m): int
{
    $pdo->prepare("
        INSERT INTO movimientos_bancarios
            (cliente_id, cuenta_id, fecha, sentido, tipo, monto, descripcion, referencia, tasa_cambio,
             transferencia_grupo, cheque_id, gasto_id, factura_id, usuario_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $cid, $cuentaId, $m['fecha'], $m['sentido'], $m['tipo'], $m['monto'], $m['descripcion'],
        $m['referencia'] ?? null, $m['tasa_cambio'] ?? null, $m['transferencia_grupo'] ?? null,
        $m['cheque_id'] ?? null, $m['gasto_id'] ?? null, $m['factura_id'] ?? null, $m['usuario_id'] ?? null,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Depósito por un cobro (recibo o pago anticipado) en una cuenta en lempiras.
 * Si el cobro es anterior al saldo inicial de la cuenta, ese dinero ya está en ese saldo:
 * la cuenta queda ligada pero no se crea el depósito (devuelve null), para no contarlo dos veces.
 */
function bancoDepositoCobro(PDO $pdo, int $cid, int $cuentaId, string $fecha, float $monto, string $descripcion, ?string $referencia, int $usuario): ?int
{
    $cuenta = bancoCuenta($pdo, $cid, $cuentaId, true);
    if ($cuenta['moneda'] !== 'HNL') throw new Exception("Elige una cuenta en lempiras.");
    if (!empty($cuenta['fecha_saldo_inicial']) && $fecha < $cuenta['fecha_saldo_inicial']) return null;
    return bancoInsertarMovimiento($pdo, $cid, $cuentaId, [
        'fecha' => $fecha, 'sentido' => 'entrada', 'tipo' => 'deposito', 'monto' => $monto,
        'descripcion' => mb_substr($descripcion, 0, 255), 'referencia' => $referencia, 'usuario_id' => $usuario,
    ]);
}

/**
 * Salida del banco por un pago (nómina, préstamo o adelanto a colaborador…). Igual que en los cobros:
 * si es anterior al saldo inicial de la cuenta, ese dinero ya salió antes de ese saldo y no se registra.
 */
function bancoSalidaPago(PDO $pdo, int $cid, int $cuentaId, string $fecha, float $monto, string $descripcion, ?string $referencia, int $usuario, ?int $gastoId = null, string $tipo = 'pago_gasto'): ?int
{
    $cuenta = bancoCuenta($pdo, $cid, $cuentaId, true);
    if ($cuenta['moneda'] !== 'HNL') throw new Exception("Elige una cuenta en lempiras.");
    if (!empty($cuenta['fecha_saldo_inicial']) && $fecha < $cuenta['fecha_saldo_inicial']) return null;
    return bancoInsertarMovimiento($pdo, $cid, $cuentaId, [
        'fecha' => $fecha, 'sentido' => 'salida', 'tipo' => $tipo, 'monto' => $monto,
        'descripcion' => mb_substr($descripcion, 0, 255), 'referencia' => $referencia, 'gasto_id' => $gastoId, 'usuario_id' => $usuario,
    ]);
}

/** Movimiento manual: depósito, retiro, comisión, interés o ajuste. */
function bancoMovimientoManual(PDO $pdo, int $cid, int $usuario, array $d): int
{
    $tipo = $d['tipo'] ?? '';
    if (!array_key_exists($tipo, BANCO_TIPOS_MANUALES)) throw new Exception("Tipo de movimiento inválido.");
    $sentido = BANCO_TIPOS_MANUALES[$tipo] ?? ($d['sentido'] ?? '');
    if (!in_array($sentido, ['entrada', 'salida'], true)) throw new Exception("Indica si el ajuste suma o resta.");
    $cuenta = bancoCuenta($pdo, $cid, (int)($d['cuenta_id'] ?? 0), true);
    $desc = trim((string)($d['descripcion'] ?? ''));
    if ($desc === '') throw new Exception("La descripción es obligatoria.");
    return bancoInsertarMovimiento($pdo, $cid, (int)$cuenta['id'], [
        'fecha' => bancoFecha((string)($d['fecha'] ?? '')),
        'sentido' => $sentido, 'tipo' => $tipo,
        'monto' => bancoMonto($d['monto'] ?? 0),
        'descripcion' => mb_substr($desc, 0, 255),
        'referencia' => mb_substr(trim((string)($d['referencia'] ?? '')), 0, 100) ?: null,
        'usuario_id' => $usuario,
    ]);
}

/**
 * Transferencia entre dos cuentas de la empresa. Si las monedas son distintas se necesita
 * la tasa (lempiras por 1 dólar) y el monto destino se calcula con ella.
 * Devuelve [monto_origen, monto_destino].
 */
function bancoTransferir(PDO $pdo, int $cid, int $usuario, array $d): array
{
    $origen  = bancoCuenta($pdo, $cid, (int)($d['origen_id'] ?? 0), true);
    $destino = bancoCuenta($pdo, $cid, (int)($d['destino_id'] ?? 0), true);
    if ($origen['id'] === $destino['id']) throw new Exception("La cuenta de origen y la de destino deben ser distintas.");
    $monto = bancoMonto($d['monto'] ?? 0);
    $fecha = bancoFecha((string)($d['fecha'] ?? ''));
    $tasa = null;
    $montoDestino = $monto;
    if ($origen['moneda'] !== $destino['moneda']) {
        if (!is_numeric($d['tasa_cambio'] ?? null) || (float)$d['tasa_cambio'] <= 0)
            throw new Exception("Las cuentas tienen monedas distintas: indica la tasa de cambio (lempiras por 1 dólar).");
        $tasa = round((float)$d['tasa_cambio'], 4);
        $montoDestino = $origen['moneda'] === 'USD' ? round($monto * $tasa, 2) : round($monto / $tasa, 2);
        if ($montoDestino <= 0) throw new Exception("El monto convertido es demasiado pequeño.");
    }
    $nota  = trim((string)($d['descripcion'] ?? ''));
    $grupo = bin2hex(random_bytes(8));
    $base  = ['fecha' => $fecha, 'tipo' => 'transferencia', 'tasa_cambio' => $tasa, 'transferencia_grupo' => $grupo,
              'referencia' => mb_substr(trim((string)($d['referencia'] ?? '')), 0, 100) ?: null, 'usuario_id' => $usuario];
    bancoInsertarMovimiento($pdo, $cid, (int)$origen['id'], $base + [
        'sentido' => 'salida', 'monto' => $monto,
        'descripcion' => mb_substr("Transferencia a {$destino['banco']} {$destino['numero']}" . ($nota ? " — $nota" : ''), 0, 255),
    ]);
    bancoInsertarMovimiento($pdo, $cid, (int)$destino['id'], $base + [
        'sentido' => 'entrada', 'monto' => $montoDestino,
        'descripcion' => mb_substr("Transferencia desde {$origen['banco']} {$origen['numero']}" . ($nota ? " — $nota" : ''), 0, 255),
    ]);
    return [$monto, $montoDestino];
}

/** Anula un movimiento (y su pareja si es transferencia). Los de cheques se anulan desde el cheque. */
function bancoAnularMovimiento(PDO $pdo, int $cid, int $movId): void
{
    $stmt = $pdo->prepare("SELECT * FROM movimientos_bancarios WHERE id = ? AND cliente_id = ?");
    $stmt->execute([$movId, $cid]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$m) throw new Exception("Movimiento no encontrado.");
    if ((int)$m['anulado']) throw new Exception("El movimiento ya está anulado.");
    if ($m['cheque_id']) throw new Exception("Este movimiento es de un cheque: anula el cheque desde la chequera.");
    if (!empty($m['gasto_id'])) throw new Exception("Este movimiento es el pago de un gasto: anula el gasto desde Gastos (se anula también este movimiento).");
    if ((int)$m['conciliado']) throw new Exception("El movimiento está conciliado con el estado de cuenta: quita la conciliación antes de anularlo.");
    if ($m['transferencia_grupo']) {
        $pdo->prepare("UPDATE movimientos_bancarios SET anulado = 1 WHERE transferencia_grupo = ? AND cliente_id = ?")->execute([$m['transferencia_grupo'], $cid]);
    } else {
        $pdo->prepare("UPDATE movimientos_bancarios SET anulado = 1 WHERE id = ?")->execute([$movId]);
    }
}

// ── Cheques ──────────────────────────────────────────────────────────────────

function bancoEmitirCheque(PDO $pdo, int $cid, int $usuario, array $d): int
{
    $cuenta = bancoCuenta($pdo, $cid, (int)($d['cuenta_id'] ?? 0), true);
    if ($cuenta['tipo'] !== 'cheques') throw new Exception("Solo las cuentas de cheques tienen chequera.");
    $numero = trim((string)($d['numero'] ?? ''));
    if (!preg_match('/^[0-9A-Za-z-]{1,20}$/', $numero)) throw new Exception("Número de cheque inválido.");
    $benef = trim((string)($d['beneficiario'] ?? ''));
    if ($benef === '') throw new Exception("El beneficiario es obligatorio.");
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cheques WHERE cuenta_id = ? AND numero = ?");
    $stmt->execute([$cuenta['id'], $numero]);
    if ($stmt->fetchColumn()) throw new Exception("El cheque N.° $numero ya existe en esta cuenta.");
    $pdo->prepare("
        INSERT INTO cheques (cliente_id, cuenta_id, numero, fecha_emision, beneficiario, monto, concepto, gasto_id, usuario_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $cid, $cuenta['id'], $numero, bancoFecha((string)($d['fecha_emision'] ?? '')), mb_substr($benef, 0, 150),
        bancoMonto($d['monto'] ?? 0), mb_substr(trim((string)($d['concepto'] ?? '')), 0, 255) ?: null,
        ($d['gasto_id'] ?? null) ?: null, $usuario,
    ]);
    return (int)$pdo->lastInsertId();
}

function bancoChequeDeEmpresa(PDO $pdo, int $cid, int $id): array
{
    $stmt = $pdo->prepare("SELECT ch.*, c.banco, c.numero AS cuenta_numero FROM cheques ch JOIN cuentas_bancarias c ON c.id = ch.cuenta_id WHERE ch.id = ? AND ch.cliente_id = ? FOR UPDATE");
    $stmt->execute([$id, $cid]);
    $ch = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ch) throw new Exception("Cheque no encontrado.");
    return $ch;
}

/** El banco pagó el cheque: ahora sí sale el dinero de la cuenta. */
function bancoCobrarCheque(PDO $pdo, int $cid, int $usuario, int $id, string $fecha): void
{
    $ch = bancoChequeDeEmpresa($pdo, $cid, $id);
    if ($ch['estado'] !== 'emitido') throw new Exception("Solo se puede marcar como cobrado un cheque emitido (estado actual: {$ch['estado']}).");
    $fecha = bancoFecha($fecha);
    if ($fecha < $ch['fecha_emision']) throw new Exception("La fecha de cobro no puede ser anterior a la emisión.");
    bancoInsertarMovimiento($pdo, $cid, (int)$ch['cuenta_id'], [
        'fecha' => $fecha, 'sentido' => 'salida', 'tipo' => 'cheque', 'monto' => (float)$ch['monto'],
        'descripcion' => mb_substr("Cheque N.° {$ch['numero']} — {$ch['beneficiario']}", 0, 255),
        'referencia' => $ch['numero'], 'cheque_id' => $ch['id'], 'gasto_id' => $ch['gasto_id'], 'usuario_id' => $usuario,
    ]);
    $pdo->prepare("UPDATE cheques SET estado = 'cobrado', fecha_cobro = ? WHERE id = ?")->execute([$fecha, $id]);
}

/** Anula el cheque (si ya estaba cobrado, también anula su movimiento). */
function bancoAnularCheque(PDO $pdo, int $cid, int $id, string $motivo): void
{
    $motivo = trim($motivo);
    if ($motivo === '') throw new Exception("Indica el motivo de la anulación.");
    $ch = bancoChequeDeEmpresa($pdo, $cid, $id);
    if ($ch['estado'] === 'anulado') throw new Exception("El cheque ya está anulado.");
    $pdo->prepare("UPDATE movimientos_bancarios SET anulado = 1 WHERE cheque_id = ? AND cliente_id = ?")->execute([$id, $cid]);
    $pdo->prepare("UPDATE cheques SET estado = 'anulado', motivo_anulacion = ? WHERE id = ?")->execute([mb_substr($motivo, 0, 255), $id]);
}

function bancoMoneda(float $monto, string $moneda): string
{
    return ($moneda === 'USD' ? '$ ' : 'L ') . number_format($monto, 2);
}
