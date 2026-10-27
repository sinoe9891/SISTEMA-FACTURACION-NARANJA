<?php
/**
 * socios_prestamos.php — Préstamos a socios (aparte de nómina).
 * La empresa le presta a un socio (sale del banco) y él lo devuelve en cuotas quincenales (los días 15 y fin de mes) o
 * mensuales. Los pagos (abonos) entran al banco y se aplican a las cuotas en orden: una cuota puede quedar pagada, parcial,
 * pendiente o vencida. El saldo es una cuenta por cobrar del Balance (no es gasto ni retiro). Si no se va a devolver, el
 * saldo se condona y pasa a «Retiro de socio». Avisos por correo antes de cada cuota y si se atrasa; PDF de control.
 * Requiere sql/migraciones/2026-10-26_socios_prestamos.sql.
 */
require_once __DIR__ . '/bancos.php';

const SP_ESTADOS = [
    'activo' => ['Activo', '#dbeafe', '#1e40af'],
    'pagado' => ['Pagado', '#dcfce7', '#166534'],
    'condonado' => ['Pasado a retiro', '#fef3c7', '#92400e'],
    'anulado' => ['Anulado', '#f1f5f9', '#64748b'],
];
const SP_CUOTA_ESTADOS = [
    'pagada' => ['Pagada', '#dcfce7', '#166534'],
    'parcial' => ['Abonada', '#fef9c3', '#854d0e'],
    'pendiente' => ['Pendiente', '#f1f5f9', '#475569'],
    'proxima' => ['Por vencer', '#ffedd5', '#9a3412'],
    'vencida' => ['Vencida', '#fee2e2', '#991b1b'],
];
const SP_METODOS = ['transferencia' => 'Transferencia', 'deposito' => 'Depósito', 'efectivo' => 'Efectivo', 'cheque' => 'Cheque', 'otro' => 'Otro'];

function spDisponible(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try { $ok = (bool)$pdo->query("SHOW TABLES LIKE 'socios_prestamos'")->fetchColumn(); } catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

/**
 * Fechas de las cuotas. Quincenal: los días 15 y último de cada mes, desde la primera (si cae del 1 al 15 se ajusta al 15;
 * si cae después, a fin de mes). Mensual: el mismo día de cada mes (o el último si el mes es más corto).
 */
function spFechas(string $primera, int $n, string $frecuencia): array
{
    $t = strtotime($primera);
    $y = (int)date('Y', $t); $m = (int)date('n', $t); $d = (int)date('j', $t);
    $fin = fn($y, $m) => (int)date('t', mktime(0, 0, 0, $m, 1, $y));
    $out = [];
    if ($frecuencia === 'quincenal') {
        $mitad = $d <= 15;   // true = el 15; false = fin de mes
        for ($i = 0; $i < $n; $i++) {
            $out[] = sprintf('%04d-%02d-%02d', $y, $m, $mitad ? 15 : $fin($y, $m));
            if (!$mitad) { $m++; if ($m > 12) { $m = 1; $y++; } }
            $mitad = !$mitad;
        }
        return $out;
    }
    for ($i = 0; $i < $n; $i++) {
        $mm = $m + $i; $yy = $y + intdiv($mm - 1, 12); $mm = ($mm - 1) % 12 + 1;
        $out[] = sprintf('%04d-%02d-%02d', $yy, $mm, min($d, $fin($yy, $mm)));
    }
    return $out;
}

/** Cuotas iguales (la última ajusta los centavos): [[numero, fecha, monto], …]. */
function spCalcularCuotas(float $monto, int $n, string $frecuencia, string $primera): array
{
    $cuota = round($monto / $n, 2);
    $out = [];
    foreach (spFechas($primera, $n, $frecuencia) as $i => $f)
        $out[] = ['numero' => $i + 1, 'fecha' => $f, 'monto' => $i === $n - 1 ? round($monto - $cuota * ($n - 1), 2) : $cuota];
    return $out;
}

function spSocio(PDO $pdo, int $cid, int $socioId): array
{
    $st = $pdo->prepare("SELECT * FROM socios WHERE id = ? AND cliente_id = ?");
    $st->execute([$socioId, $cid]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) throw new Exception("Elige el socio.");
    return $s;
}

/** Guarda el comprobante (con los de gastos). Devuelve [archivo, nombre] o null. */
function spSubir(?array $f, int $cid, string $prefijo): ?array
{
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || ($f['name'] ?? '') === '') return null;
    if ($f['error'] !== UPLOAD_ERR_OK) throw new Exception("No se pudo subir el comprobante (código {$f['error']}).");
    if ((int)$f['size'] > 5 * 1024 * 1024) throw new Exception("El comprobante supera 5 MB.");
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ok = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    if (!isset($ok[$mime])) throw new Exception("Comprobante: solo JPG, PNG, WEBP o PDF.");
    $archivo = $prefijo . '_' . $cid . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ok[$mime];
    if (!move_uploaded_file($f['tmp_name'], spDir() . $archivo)) throw new Exception("No se pudo guardar el comprobante.");
    return [$archivo, mb_substr(basename((string)$f['name']), 0, 255)];
}

function spDir(): string
{
    return __DIR__ . '/../clientes/naranjaymedia/includes/uploads/gastos/';
}

/** Crea el préstamo con sus cuotas; si se elige la cuenta, registra la salida del banco. Devuelve el id. */
function spCrear(PDO $pdo, int $cid, int $uid, array $d, ?array $archivo = null): int
{
    $socio = spSocio($pdo, $cid, (int)($d['socio_id'] ?? 0));
    $desc = mb_substr(trim((string)($d['descripcion'] ?? '')), 0, 255);
    if ($desc === '') throw new Exception("Escribe para qué es el préstamo.");
    $monto = round((float)str_replace(',', '', (string)($d['monto'] ?? 0)), 2);
    if ($monto <= 0) throw new Exception("El monto debe ser mayor que 0.");
    $n = (int)($d['num_cuotas'] ?? 0);
    if ($n < 1 || $n > 120) throw new Exception("Las cuotas deben ser entre 1 y 120.");
    $frec = ($d['frecuencia'] ?? 'quincenal') === 'mensual' ? 'mensual' : 'quincenal';
    $fecha = bancoFecha((string)($d['fecha'] ?? ''));
    $primera = (string)($d['primera_cuota'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $primera) || !strtotime($primera)) throw new Exception("Elige la fecha de la primera cuota.");
    if ($primera < $fecha) throw new Exception("La primera cuota no puede ser antes del préstamo.");
    $correo = spCorreos((string)($d['aviso_correo'] ?? '') ?: (string)($socio['email'] ?? ''));
    $cc = spCorreos((string)($d['aviso_cc'] ?? ''));
    $cuenta = (int)($d['cuenta_id'] ?? 0) ?: null;
    $ref = mb_substr(trim((string)($d['referencia'] ?? '')), 0, 80) ?: null;
    $deudor = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($d['deudor_nombre'] ?? ''))), 0, 150) ?: null;
    $pdo->prepare("INSERT INTO socios_prestamos (cliente_id, socio_id, descripcion, monto, fecha, num_cuotas, frecuencia, primera_cuota, referencia, archivo_adjunto, archivo_nombre,
                   avisar, aviso_dias, aviso_correo, aviso_cc, notas, usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$cid, (int)$socio['id'], $desc, $monto, $fecha, $n, $frec, $primera, $ref, $archivo[0] ?? null, $archivo[1] ?? null,
            !empty($d['avisar']) ? 1 : 0, max(0, min(30, (int)($d['aviso_dias'] ?? 3))), $correo ?: null, $cc ?: null, trim((string)($d['notas'] ?? '')) ?: null, $uid]);
    $id = (int)$pdo->lastInsertId();
    if ($deudor) $pdo->prepare("UPDATE socios_prestamos SET deudor_nombre = ? WHERE id = ?")->execute([$deudor, $id]);
    $ins = $pdo->prepare("INSERT INTO socios_prestamo_cuotas (prestamo_id, numero, fecha, monto) VALUES (?, ?, ?, ?)");
    foreach (spCalcularCuotas($monto, $n, $frec, $primera) as $c) $ins->execute([$id, $c['numero'], $c['fecha'], $c['monto']]);
    if ($cuenta) {
        $mov = bancoSalidaPago($pdo, $cid, $cuenta, $fecha, $monto, 'Préstamo a socio: ' . $socio['nombre'] . ' · ' . $desc, $ref, $uid, null, 'prestamo_socio');
        $pdo->prepare("UPDATE socios_prestamos SET cuenta_id = ?, movimiento_id = ? WHERE id = ?")->execute([$cuenta, $mov, $id]);
        if ($mov && $archivo) $pdo->prepare("UPDATE movimientos_bancarios SET archivo_adjunto = ?, archivo_nombre = ? WHERE id = ?")->execute([$archivo[0], $archivo[1], $mov]);
    }
    return $id;
}

function spObtener(PDO $pdo, int $cid, int $id): array
{
    $st = $pdo->prepare("SELECT p.*, s.nombre AS socio, s.email AS socio_email, CONCAT(b.banco, ' ', b.numero) AS cuenta FROM socios_prestamos p
                         JOIN socios s ON s.id = p.socio_id LEFT JOIN cuentas_bancarias b ON b.id = p.cuenta_id WHERE p.id = ? AND p.cliente_id = ?");
    $st->execute([$id, $cid]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) throw new Exception("Préstamo no encontrado.");
    return $p;
}

/** Nombre que se muestra en correos y PDF: el de «a nombre de» (un tercero) o el del socio. */
function spNombreVisible(array $p): string
{
    return trim((string)($p['deudor_nombre'] ?? '')) ?: (string)$p['socio'];
}

/** ¿El préstamo sale a nombre de otra persona (no del socio)? */
function spEsTercero(array $p): bool
{
    return trim((string)($p['deudor_nombre'] ?? '')) !== '';
}

/**
 * Préstamo con sus cuotas (estado de cada una según los pagos aplicados en orden), pagos y totales.
 * $hoy permite calcular «vencida» a otra fecha (pruebas). Devuelve ['prestamo', 'cuotas', 'pagos', 'pagado', 'saldo', 'proxima', 'vencidas', 'vencido_monto'].
 */
function spDetalle(PDO $pdo, int $cid, int $id, ?string $hoy = null): array
{
    $hoy ??= date('Y-m-d');
    $p = spObtener($pdo, $cid, $id);
    $st = $pdo->prepare("SELECT * FROM socios_prestamo_cuotas WHERE prestamo_id = ? ORDER BY numero");
    $st->execute([$id]);
    $cuotas = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $pdo->prepare("SELECT g.*, CONCAT(b.banco, ' ', b.numero) AS cuenta FROM socios_prestamo_pagos g LEFT JOIN cuentas_bancarias b ON b.id = g.cuenta_id WHERE g.prestamo_id = ? ORDER BY g.fecha, g.id");
    $st->execute([$id]);
    $pagos = $st->fetchAll(PDO::FETCH_ASSOC);
    $pagado = round(array_sum(array_map(fn($g) => (int)$g['anulado'] ? 0 : (float)$g['monto'], $pagos)), 2);
    $disponible = $pagado + (float)$p['condonado_monto'];
    $saldoTras = (float)$p['monto'];
    $proxima = null; $vencidas = 0; $vencidoMonto = 0.0;
    foreach ($cuotas as &$c) {
        $aplicado = round(min((float)$c['monto'], max(0, $disponible)), 2);
        $disponible = round($disponible - $aplicado, 2);
        $c['pagado'] = $aplicado;
        $c['pendiente'] = round((float)$c['monto'] - $aplicado, 2);
        $saldoTras = round($saldoTras - (float)$c['monto'], 2);   // saldo programado: lo que quedará al pagar esta cuota
        $c['saldo'] = $saldoTras;
        $c['dias'] = (int)round((strtotime($c['fecha']) - strtotime($hoy)) / 86400);
        if ($c['pendiente'] <= 0.004) $c['estado'] = 'pagada';
        elseif (in_array($p['estado'], ['anulado', 'condonado'], true)) $c['estado'] = 'pendiente';
        elseif ($c['fecha'] < $hoy) { $c['estado'] = 'vencida'; $vencidas++; $vencidoMonto += $c['pendiente']; }
        elseif ($aplicado > 0) $c['estado'] = 'parcial';
        else $c['estado'] = (strtotime($c['fecha']) - strtotime($hoy)) / 86400 <= max(3, (int)$p['aviso_dias']) ? 'proxima' : 'pendiente';
        if ($c['estado'] !== 'pagada' && !$proxima && !in_array($p['estado'], ['anulado', 'condonado'], true)) $proxima = $c;
    }
    unset($c);
    $saldo = in_array($p['estado'], ['anulado', 'condonado'], true) ? 0.0 : round((float)$p['monto'] - $pagado - (float)$p['condonado_monto'], 2);
    return ['prestamo' => $p, 'cuotas' => $cuotas, 'pagos' => $pagos, 'pagado' => $pagado, 'saldo' => max(0, $saldo), 'proxima' => $proxima,
        'vencidas' => $vencidas, 'vencido_monto' => round($vencidoMonto, 2)];
}

/** Todos los préstamos de la empresa con su resumen (para la lista de Socios). */
function spLista(PDO $pdo, int $cid): array
{
    if (!spDisponible($pdo)) return [];
    $st = $pdo->prepare("SELECT id FROM socios_prestamos WHERE cliente_id = ? ORDER BY estado = 'activo' DESC, fecha DESC, id DESC");
    $st->execute([$cid]);
    return array_map(fn($id) => spDetalle($pdo, $cid, (int)$id), $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Registra un pago (abono) del socio; si se elige la cuenta, entra al banco. Al llegar a cero, el préstamo queda pagado. */
function spPagar(PDO $pdo, int $cid, int $uid, int $id, array $d, ?array $archivo = null): int
{
    $det = spDetalle($pdo, $cid, $id);
    $p = $det['prestamo'];
    if ($p['estado'] !== 'activo') throw new Exception("El préstamo no está activo.");
    $monto = round((float)str_replace(',', '', (string)($d['monto'] ?? 0)), 2);
    if ($monto <= 0) throw new Exception("El monto del pago debe ser mayor que 0.");
    if ($monto > $det['saldo'] + 0.004) throw new Exception("El pago (L " . number_format($monto, 2) . ") es mayor que el saldo (L " . number_format($det['saldo'], 2) . "). Si es más, registra el excedente como aporte.");
    $fecha = bancoFecha((string)($d['fecha'] ?? ''));
    if ($fecha < $p['fecha']) throw new Exception("El pago no puede ser antes del préstamo.");
    $metodo = isset(SP_METODOS[$d['metodo'] ?? '']) ? $d['metodo'] : 'transferencia';
    $ref = mb_substr(trim((string)($d['referencia'] ?? '')), 0, 80) ?: null;
    $cuenta = (int)($d['cuenta_id'] ?? 0) ?: null;
    $mov = $cuenta ? bancoDepositoCobro($pdo, $cid, $cuenta, $fecha, $monto, 'Pago de préstamo de socio: ' . $p['socio'] . ' (#' . $id . ')', $ref, $uid) : null;
    if ($mov && $archivo) $pdo->prepare("UPDATE movimientos_bancarios SET archivo_adjunto = ?, archivo_nombre = ? WHERE id = ?")->execute([$archivo[0], $archivo[1], $mov]);
    $pdo->prepare("INSERT INTO socios_prestamo_pagos (prestamo_id, cliente_id, fecha, monto, metodo, referencia, cuenta_id, movimiento_id, archivo_adjunto, archivo_nombre, notas, usuario_id)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$id, $cid, $fecha, $monto, $metodo, $ref, $cuenta, $mov, $archivo[0] ?? null, $archivo[1] ?? null, mb_substr(trim((string)($d['notas'] ?? '')), 0, 255) ?: null, $uid]);
    $pagoId = (int)$pdo->lastInsertId();
    if ($det['saldo'] - $monto <= 0.004) $pdo->prepare("UPDATE socios_prestamos SET estado = 'pagado', cerrado_en = ? WHERE id = ?")->execute([$fecha, $id]);
    return $pagoId;
}

/** Anula un pago (y su depósito en el banco); el préstamo vuelve a activo si había quedado pagado. */
function spAnularPago(PDO $pdo, int $cid, int $pagoId, string $motivo): void
{
    $motivo = mb_substr(trim($motivo), 0, 150);
    if ($motivo === '') throw new Exception("Escribe el motivo.");
    $st = $pdo->prepare("SELECT g.*, p.estado AS prestamo_estado FROM socios_prestamo_pagos g JOIN socios_prestamos p ON p.id = g.prestamo_id WHERE g.id = ? AND g.cliente_id = ? AND g.anulado = 0");
    $st->execute([$pagoId, $cid]);
    $g = $st->fetch(PDO::FETCH_ASSOC);
    if (!$g) throw new Exception("El pago no existe o ya está anulado.");
    if ($g['prestamo_estado'] === 'condonado') throw new Exception("El saldo de este préstamo ya pasó a retiro: no se anulan sus pagos.");
    if ($g['movimiento_id']) bancoAnularMovimiento($pdo, $cid, (int)$g['movimiento_id']);
    $pdo->prepare("UPDATE socios_prestamo_pagos SET anulado = 1, notas = CONCAT(COALESCE(notas, ''), ?) WHERE id = ?")->execute([' | Anulado el ' . date('d/m/Y') . ': ' . $motivo, $pagoId]);
    $pdo->prepare("UPDATE socios_prestamos SET estado = 'activo', cerrado_en = NULL WHERE id = ? AND estado = 'pagado'")->execute([(int)$g['prestamo_id']]);
}

/**
 * Ajusta el movimiento del banco ligado a un préstamo o a un pago: lo crea, lo mueve de cuenta, cambia fecha/monto/referencia
 * o lo anula si ya no hay cuenta. Uno conciliado con el estado de cuenta no cambia de cuenta, fecha ni monto. Devuelve el id.
 */
function spMovimiento(PDO $pdo, int $cid, int $uid, ?int $movId, ?int $cuenta, string $sentido, string $fecha, float $monto, ?string $ref, string $desc): ?int
{
    if ($movId) {
        $st = $pdo->prepare("SELECT * FROM movimientos_bancarios WHERE id = ? AND cliente_id = ?");
        $st->execute([$movId, $cid]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m || (int)$m['anulado']) $movId = null;
        else {
            $cambia = (int)$m['cuenta_id'] !== (int)$cuenta || $m['fecha'] !== $fecha || abs((float)$m['monto'] - $monto) > 0.004;
            if ($cambia && (int)$m['conciliado']) throw new Exception("El movimiento del banco ya está conciliado: quita la conciliación en Bancos para cambiar la cuenta, la fecha o el monto.");
            if (!$cuenta) { bancoAnularMovimiento($pdo, $cid, $movId); return null; }
            if ($cambia) bancoCuenta($pdo, $cid, $cuenta, (int)$m['cuenta_id'] !== $cuenta);
            $pdo->prepare("UPDATE movimientos_bancarios SET cuenta_id = ?, fecha = ?, monto = ?, referencia = ?, descripcion = ? WHERE id = ?")
                ->execute([$cuenta, $fecha, $monto, $ref, mb_substr($desc, 0, 255), $movId]);
            if ($sentido === 'salida') {   // la Ley Poblacional se recalcula en las dos cuentas
                bancoLeyPoblacionalSincronizar($pdo, $cid, (int)$m['cuenta_id']);
                if ((int)$m['cuenta_id'] !== $cuenta) bancoLeyPoblacionalSincronizar($pdo, $cid, $cuenta);
            }
            return $movId;
        }
    }
    if (!$cuenta) return null;
    return $sentido === 'salida'
        ? bancoSalidaPago($pdo, $cid, $cuenta, $fecha, $monto, $desc, $ref, $uid, null, 'prestamo_socio')
        : bancoDepositoCobro($pdo, $cid, $cuenta, $fecha, $monto, $desc, $ref, $uid);
}

/**
 * Edita un pago: fecha, monto (sin pasar del saldo), método, referencia, cuenta (el depósito se mueve, se crea o se anula),
 * notas y comprobante.
 */
function spEditarPago(PDO $pdo, int $cid, int $uid, int $pagoId, array $d, ?array $archivo = null): void
{
    $st = $pdo->prepare("SELECT * FROM socios_prestamo_pagos WHERE id = ? AND cliente_id = ? AND anulado = 0");
    $st->execute([$pagoId, $cid]);
    $g = $st->fetch(PDO::FETCH_ASSOC);
    if (!$g) throw new Exception("El pago no existe o está anulado.");
    $det = spDetalle($pdo, $cid, (int)$g['prestamo_id']);
    $p = $det['prestamo'];
    if (in_array($p['estado'], ['condonado', 'anulado'], true)) throw new Exception("El préstamo está cerrado: sus pagos ya no se editan.");
    $monto = round((float)str_replace(',', '', (string)($d['monto'] ?? $g['monto'])), 2);
    if ($monto <= 0) throw new Exception("El monto debe ser mayor que 0.");
    $maximo = $det['saldo'] + (float)$g['monto'];
    if ($monto > $maximo + 0.004) throw new Exception("El pago no puede pasar del saldo (L " . number_format($maximo, 2) . ").");
    $fecha = bancoFecha((string)($d['fecha'] ?? $g['fecha']));
    if ($fecha < $p['fecha']) throw new Exception("El pago no puede ser antes del préstamo.");
    $metodo = isset(SP_METODOS[$d['metodo'] ?? '']) ? $d['metodo'] : $g['metodo'];
    $ref = mb_substr(trim((string)($d['referencia'] ?? '')), 0, 80) ?: null;
    $cuenta = (int)($d['cuenta_id'] ?? 0) ?: null;
    $mov = spMovimiento($pdo, $cid, $uid, (int)$g['movimiento_id'] ?: null, $cuenta, 'entrada', $fecha, $monto, $ref, 'Pago de préstamo de socio: ' . $p['socio'] . ' (#' . $p['id'] . ')');
    $sets = "fecha = ?, monto = ?, metodo = ?, referencia = ?, cuenta_id = ?, movimiento_id = ?, notas = ?";
    $vals = [$fecha, $monto, $metodo, $ref, $cuenta, $mov, mb_substr(trim((string)($d['notas'] ?? '')), 0, 255) ?: null];
    if ($archivo) { $sets .= ", archivo_adjunto = ?, archivo_nombre = ?"; array_push($vals, $archivo[0], $archivo[1]); }
    $pdo->prepare("UPDATE socios_prestamo_pagos SET $sets WHERE id = ?")->execute([...$vals, $pagoId]);
    $arch = $archivo ?: ($g['archivo_adjunto'] ? [$g['archivo_adjunto'], $g['archivo_nombre']] : null);
    if ($mov && $arch) $pdo->prepare("UPDATE movimientos_bancarios SET archivo_adjunto = ?, archivo_nombre = ? WHERE id = ?")->execute([$arch[0], $arch[1], $mov]);
    spRecalcularEstado($pdo, $cid, (int)$p['id']);
}

/**
 * Edita el préstamo: concepto, notas, referencia, comprobante, monto, fecha, cuenta de salida y el plan de cuotas
 * (cuántas, frecuencia y primera). El plan se vuelve a calcular; lo ya pagado se aplica de nuevo a las cuotas en orden.
 */
function spEditar(PDO $pdo, int $cid, int $uid, int $id, array $d, ?array $archivo = null): void
{
    $det = spDetalle($pdo, $cid, $id);
    $p = $det['prestamo'];
    if (in_array($p['estado'], ['condonado', 'anulado'], true)) throw new Exception("El préstamo está cerrado: ya no se edita.");
    $desc = mb_substr(trim((string)($d['descripcion'] ?? $p['descripcion'])), 0, 255);
    if ($desc === '') throw new Exception("Escribe para qué es el préstamo.");
    $monto = round((float)str_replace(',', '', (string)($d['monto'] ?? $p['monto'])), 2);
    if ($monto <= 0) throw new Exception("El monto debe ser mayor que 0.");
    if ($monto < $det['pagado'] - 0.004) throw new Exception("El monto no puede ser menor que lo ya pagado (L " . number_format($det['pagado'], 2) . ").");
    $n = (int)($d['num_cuotas'] ?? $p['num_cuotas']);
    if ($n < 1 || $n > 120) throw new Exception("Las cuotas deben ser entre 1 y 120.");
    $frec = ($d['frecuencia'] ?? $p['frecuencia']) === 'mensual' ? 'mensual' : 'quincenal';
    $fecha = bancoFecha((string)($d['fecha'] ?? $p['fecha']));
    $primera = (string)($d['primera_cuota'] ?? $p['primera_cuota']);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $primera) || $primera < $fecha) throw new Exception("Revisa la fecha de la primera cuota.");
    foreach ($det['pagos'] as $g) if (!(int)$g['anulado'] && $g['fecha'] < $fecha) throw new Exception("Hay pagos antes de esa fecha del préstamo.");
    $ref = mb_substr(trim((string)($d['referencia'] ?? '')), 0, 80) ?: null;
    $cuenta = array_key_exists('cuenta_id', $d) ? ((int)$d['cuenta_id'] ?: null) : ((int)$p['cuenta_id'] ?: null);
    $mov = spMovimiento($pdo, $cid, $uid, (int)$p['movimiento_id'] ?: null, $cuenta, 'salida', $fecha, $monto, $ref, 'Préstamo a socio: ' . $p['socio'] . ' · ' . $desc);
    $sets = "descripcion = ?, monto = ?, fecha = ?, num_cuotas = ?, frecuencia = ?, primera_cuota = ?, referencia = ?, cuenta_id = ?, movimiento_id = ?, notas = ?";
    $vals = [$desc, $monto, $fecha, $n, $frec, $primera, $ref, $cuenta, $mov, trim((string)($d['notas'] ?? $p['notas'])) ?: null];
    if ($archivo) { $sets .= ", archivo_adjunto = ?, archivo_nombre = ?"; array_push($vals, $archivo[0], $archivo[1]); }
    $pdo->prepare("UPDATE socios_prestamos SET $sets WHERE id = ?")->execute([...$vals, $id]);
    $arch = $archivo ?: ($p['archivo_adjunto'] ? [$p['archivo_adjunto'], $p['archivo_nombre']] : null);
    if ($mov && $arch) $pdo->prepare("UPDATE movimientos_bancarios SET archivo_adjunto = ?, archivo_nombre = ? WHERE id = ?")->execute([$arch[0], $arch[1], $mov]);
    $pdo->prepare("DELETE FROM socios_prestamo_cuotas WHERE prestamo_id = ?")->execute([$id]);
    $ins = $pdo->prepare("INSERT INTO socios_prestamo_cuotas (prestamo_id, numero, fecha, monto) VALUES (?, ?, ?, ?)");
    foreach (spCalcularCuotas($monto, $n, $frec, $primera) as $c) $ins->execute([$id, $c['numero'], $c['fecha'], $c['monto']]);
    spRecalcularEstado($pdo, $cid, $id);
}

/** Pagado / activo según el saldo actual. */
function spRecalcularEstado(PDO $pdo, int $cid, int $id): void
{
    $det = spDetalle($pdo, $cid, $id);
    $p = $det['prestamo'];
    if (!in_array($p['estado'], ['activo', 'pagado'], true)) return;
    $ultimo = array_reduce($det['pagos'], fn($m, $g) => (int)$g['anulado'] ? $m : max($m, $g['fecha']), '');
    if ($det['saldo'] <= 0.004) $pdo->prepare("UPDATE socios_prestamos SET estado = 'pagado', cerrado_en = ? WHERE id = ?")->execute([$ultimo ?: date('Y-m-d'), $id]);
    else $pdo->prepare("UPDATE socios_prestamos SET estado = 'activo', cerrado_en = NULL WHERE id = ?")->execute([$id]);
}

/** El socio no devolverá el saldo: pasa a «Retiro de socio» (un gasto con su socio) y el préstamo se cierra. Devuelve el id del gasto. */
function spCondonar(PDO $pdo, int $cid, int $uid, int $id, string $motivo, string $fecha): int
{
    $motivo = mb_substr(trim($motivo), 0, 200);
    if ($motivo === '') throw new Exception("Escribe el motivo.");
    $fecha = bancoFecha($fecha);
    $det = spDetalle($pdo, $cid, $id);
    $p = $det['prestamo'];
    if ($p['estado'] !== 'activo' || $det['saldo'] <= 0) throw new Exception("No hay saldo por pasar a retiro.");
    $pdo->prepare("INSERT INTO gastos (cliente_id, descripcion, monto, fecha, frecuencia, tipo, naturaleza, metodo_pago, notas, estado, usuario_id, socio_id)
                   VALUES (?, ?, ?, ?, 'unico', 'variable', 'retiro', 'otro', ?, 'pagado', ?, ?)")
        ->execute([$cid, mb_substr('Retiro de socio: saldo del préstamo #' . $id . ' · ' . $p['descripcion'], 0, 300), $det['saldo'], $fecha,
            'El socio no devolverá el saldo del préstamo #' . $id . ' (L ' . number_format((float)$p['monto'], 2) . '). Motivo: ' . $motivo . '. No sale del banco: el dinero ya había salido al prestarlo.', $uid, (int)$p['socio_id']]);
    $gid = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE socios_prestamos SET estado = 'condonado', condonado_monto = ?, condonado_gasto_id = ?, cerrado_en = ?, notas = CONCAT(COALESCE(notas, ''), ?) WHERE id = ?")
        ->execute([$det['saldo'], $gid, $fecha, ' | Saldo pasado a retiro el ' . date('d/m/Y', strtotime($fecha)) . ': ' . $motivo, $id]);
    return $gid;
}

/** Anula un préstamo sin pagos (también la salida del banco). */
function spAnular(PDO $pdo, int $cid, int $id, string $motivo): void
{
    $motivo = mb_substr(trim($motivo), 0, 200);
    if ($motivo === '') throw new Exception("Escribe el motivo.");
    $det = spDetalle($pdo, $cid, $id);
    if ($det['prestamo']['estado'] !== 'activo') throw new Exception("Solo se anula un préstamo activo.");
    if ($det['pagado'] > 0) throw new Exception("Ya tiene pagos: anúlalos primero o pasa el saldo a retiro.");
    if ($det['prestamo']['movimiento_id']) bancoAnularMovimiento($pdo, $cid, (int)$det['prestamo']['movimiento_id']);
    $pdo->prepare("UPDATE socios_prestamos SET estado = 'anulado', cerrado_en = CURDATE(), notas = CONCAT(COALESCE(notas, ''), ?) WHERE id = ?")->execute([' | Anulado el ' . date('d/m/Y') . ': ' . $motivo, $id]);
}

/** Cambia los avisos por correo del préstamo. */
function spAvisos(PDO $pdo, int $cid, int $id, array $d): void
{
    spObtener($pdo, $cid, $id);
    $correo = spCorreos((string)($d['aviso_correo'] ?? ''));
    $cc = spCorreos((string)($d['aviso_cc'] ?? ''));
    if (!empty($d['avisar']) && $correo === '') throw new Exception("Escribe el correo al que llegan los avisos.");
    $pdo->prepare("UPDATE socios_prestamos SET avisar = ?, aviso_dias = ?, aviso_correo = ?, aviso_cc = ? WHERE id = ? AND cliente_id = ?")
        ->execute([!empty($d['avisar']) ? 1 : 0, max(0, min(30, (int)($d['aviso_dias'] ?? 3))), $correo ?: null, $cc ?: null, $id, $cid]);
    if (array_key_exists('deudor_nombre', $d))
        $pdo->prepare("UPDATE socios_prestamos SET deudor_nombre = ? WHERE id = ? AND cliente_id = ?")->execute([mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$d['deudor_nombre'])), 0, 150) ?: null, $id, $cid]);
    // El correo del socio queda guardado para sus próximos préstamos
    if (!empty($d['guardar_en_socio']) && $correo !== '') $pdo->prepare("UPDATE socios SET email = ? WHERE id = (SELECT socio_id FROM socios_prestamos WHERE id = ?) AND cliente_id = ?")->execute([explode(',', $correo)[0], $id, $cid]);
}

/** Lista de correos separada por comas (o punto y coma), validada y sin repetidos. */
function spCorreos(string $txt): string
{
    $out = [];
    foreach (preg_split('/[,;\s]+/', $txt) as $c) {
        $c = trim($c);
        if ($c === '') continue;
        if (!filter_var($c, FILTER_VALIDATE_EMAIL)) throw new Exception("Correo inválido: $c");
        $out[mb_strtolower($c)] = $c;
    }
    return implode(', ', $out);
}

/** Saldo por cobrar a socios al corte (Balance): préstamos menos lo pagado hasta esa fecha (y lo pasado a retiro). */
function spSaldoAlCorte(PDO $pdo, int $cid, string $corte): float
{
    if (!spDisponible($pdo)) return 0.0;
    $st = $pdo->prepare("SELECT p.id, p.monto, p.estado, p.condonado_monto, p.cerrado_en,
                                COALESCE((SELECT SUM(g.monto) FROM socios_prestamo_pagos g WHERE g.prestamo_id = p.id AND g.anulado = 0 AND g.fecha <= ?), 0) AS pagado
                         FROM socios_prestamos p WHERE p.cliente_id = ? AND p.estado <> 'anulado' AND p.fecha <= ?");
    $st->execute([$corte, $cid, $corte]);
    $t = 0.0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $cond = $p['estado'] === 'condonado' && $p['cerrado_en'] && $p['cerrado_en'] <= $corte ? (float)$p['condonado_monto'] : 0.0;
        $t += max(0, (float)$p['monto'] - (float)$p['pagado'] - $cond);
    }
    return round($t, 2);
}

/**
 * Cuentas de la empresa para devolver el préstamo (cuentas en lempiras de Bancos): primero la cuenta de donde salió
 * el préstamo. No usa las cuentas de cobro a clientes (pueden ser personales de un socio).
 */
function spCuentasPagoHtml(PDO $pdo, int $cid, array $p, bool $paraCorreo = true): string
{
    try {
        $st = $pdo->prepare("SELECT id, banco, tipo, numero, titular FROM cuentas_bancarias WHERE cliente_id = ? AND activa = 1 AND moneda = 'HNL' ORDER BY id = ? DESC, tipo = 'ahorro' DESC, id");
        $st->execute([$cid, (int)$p['cuenta_id']]);
        $cs = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return ''; }
    if (!$cs) return '';
    $e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
    $li = implode('', array_map(fn($c) => '<li><strong>' . $e($c['banco']) . '</strong> · cuenta de ' . ($c['tipo'] === 'cheques' ? 'cheques' : 'ahorro') . ' <strong>' . $e($c['numero']) . '</strong>'
        . ($c['titular'] ? ' a nombre de ' . $e($c['titular']) : '') . ((int)$c['id'] === (int)$p['cuenta_id'] ? ' <em>(de aquí salió el préstamo)</em>' : '') . '</li>', $cs));
    return '<div style="margin-top:' . ($paraCorreo ? '16px' : '12px') . '"><strong>Cuentas de la empresa para hacer el pago:</strong><ul style="margin:6px 0 0;padding-left:18px">' . $li . '</ul></div>';
}

/** Movimientos del préstamo: la transferencia al socio (de qué cuenta salió) y cada pago del socio (a qué cuenta entró). */
function spMovimientosHtml(array $det, bool $paraCorreo = true): string
{
    $p = $det['prestamo'];
    $e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
    $td = 'style="padding:5px 8px;border-bottom:1px solid #e2e8f0;font-size:' . ($paraCorreo ? '13px' : '8.4pt') . '"';
    $tdr = 'style="padding:5px 8px;border-bottom:1px solid #e2e8f0;text-align:right;white-space:nowrap;font-size:' . ($paraCorreo ? '13px' : '8.4pt') . '"';
    $th = 'style="background:#e2e8f0;padding:5px 8px;text-align:left;font-size:' . ($paraCorreo ? '12px' : '8pt') . '"';
    $filas = '<tr><td ' . $td . '>' . date('d/m/Y', strtotime($p['fecha'])) . '</td><td ' . $td . '>Transferencia de la empresa (préstamo)</td><td ' . $td . '>' . ($p['cuenta'] ? 'Salió de ' . $e($p['cuenta']) : '—') . ($p['referencia'] ? ' · ref. ' . $e($p['referencia']) : '') . '</td><td ' . $tdr . ' >− ' . number_format((float)$p['monto'], 2) . '</td></tr>';
    foreach ($det['pagos'] as $g) {
        if ((int)$g['anulado']) continue;
        $filas .= '<tr><td ' . $td . '>' . date('d/m/Y', strtotime($g['fecha'])) . '</td><td ' . $td . '>Pago (' . $e(SP_METODOS[$g['metodo']] ?? $g['metodo']) . ')</td><td ' . $td . '>' . ($g['cuenta'] ? 'Entró a ' . $e($g['cuenta']) : '—')
            . ($g['referencia'] ? ' · ref. ' . $e($g['referencia']) : '') . '</td><td ' . $tdr . '>+ ' . number_format((float)$g['monto'], 2) . '</td></tr>';
    }
    return '<div style="margin-top:16px"><strong>Transferencias</strong><table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-top:6px"><thead><tr><th ' . $th . '>Fecha</th><th ' . $th . '>Movimiento</th><th ' . $th . '>Cuenta de la empresa</th><th ' . $th . ' style="text-align:right">L</th></tr></thead><tbody>' . $filas . '</tbody></table></div>';
}

/** HTML de la tabla de control (PDF y correo). */
function spHtmlControl(PDO $pdo, int $cid, array $det, bool $paraCorreo = false): string
{
    $p = $det['prestamo'];
    $e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
    $L = fn($v) => 'L ' . number_format((float)$v, 2);
    $filas = '';
    foreach ($det['cuotas'] as $i => $c) {
        [$txt, $bg, $fg] = SP_CUOTA_ESTADOS[$c['estado']];
        $filas .= '<tr style="background:' . ($i % 2 ? '#f8fafc' : '#fff') . '"><td style="padding:5px 8px;text-align:center">' . (int)$c['numero'] . '</td>'
            . '<td style="padding:5px 8px">' . date('d/m/Y', strtotime($c['fecha'])) . '</td>'
            . '<td style="padding:5px 8px;text-align:right">' . number_format((float)$c['monto'], 2) . '</td>'
            . '<td style="padding:5px 8px;text-align:right">' . ($c['pagado'] > 0 ? number_format($c['pagado'], 2) : '—') . '</td>'
            . '<td style="padding:5px 8px;text-align:right">' . number_format($c['saldo'], 2) . '</td>'
            . '<td style="padding:5px 8px;text-align:center"><span style="background:' . $bg . ';color:' . $fg . ';padding:2px 8px;border-radius:10px;font-size:' . ($paraCorreo ? '12px' : '7.5pt') . '">' . $txt . '</span></td></tr>';
    }
    $th = 'style="background:#0f172a;color:#fff;padding:6px 8px;text-align:%s;font-size:' . ($paraCorreo ? '12px' : '8pt') . '"';
    return '<table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:' . ($paraCorreo ? '13px' : '8.6pt') . '">'
        . '<thead><tr><th ' . sprintf($th, 'center') . '>N.º</th><th ' . sprintf($th, 'left') . '>Fecha de pago</th><th ' . sprintf($th, 'right') . '>Cuota</th><th ' . sprintf($th, 'right') . '>Pagado</th><th ' . sprintf($th, 'right') . '>Saldo al pagarla</th><th ' . sprintf($th, 'center') . '>Estado</th></tr></thead>'
        . '<tbody>' . $filas . '</tbody>'
        . '<tfoot><tr style="font-weight:bold;background:#fff7f2"><td></td><td style="padding:6px 8px">Totales</td><td style="padding:6px 8px;text-align:right">' . number_format((float)$p['monto'], 2) . '</td>'
        . '<td style="padding:6px 8px;text-align:right">' . number_format($det['pagado'], 2) . '</td><td style="padding:6px 8px;text-align:right">' . number_format($det['saldo'], 2) . '</td><td></td></tr></tfoot></table>';
}

/**
 * Firmas del PDF: quien recibe el préstamo y los firmantes de Configuración → Firmas («Autorizado por» y «Vo.Bo.»),
 * con su nombre, cargo e imagen de firma si la tienen.
 */
function spFirmasHtml(PDO $pdo, int $cid, array $p, callable $e): string
{
    require_once __DIR__ . '/firmantes.php';
    $f = firmantesParaPdf($pdo, $cid, __DIR__ . '/../clientes/naranjaymedia/includes/uploads');
    $celda = fn(string $titulo, string $nombre, string $cargo, string $img) => '<td style="width:33%;text-align:center;padding:0 10px;vertical-align:top">'
        . '<div style="height:46px">' . ($img ? '<img src="' . $img . '" style="max-height:46px;max-width:150px">' : '') . '</div>'
        . '<div style="border-top:1px solid #334155;padding-top:4px">' . ($nombre !== '' ? '<strong>' . $e($nombre) . '</strong>' : '&nbsp;') . '<br><span class="muted">' . ($cargo !== '' ? $e($cargo) : '&nbsp;') . '</span>'
        . '<br><span class="muted">' . $titulo . '</span></div></td>';
    return '<table style="width:100%;margin-top:40px"><tr>'
        . $celda(spEsTercero($p) ? 'Recibe el préstamo' : 'Socio', spNombreVisible($p), '', '')
        . $celda('Autorizado por', (string)($f['autorizado']['nombre'] ?? ''), (string)($f['autorizado']['cargo'] ?? ''), (string)($f['autorizado']['firma_b64'] ?? ''))
        . $celda('Vo.Bo.', (string)($f['vobo']['nombre'] ?? ''), (string)($f['vobo']['cargo'] ?? ''), (string)($f['vobo']['firma_b64'] ?? ''))
        . '</tr></table>';
}

/** PDF de control del préstamo: datos, tabla de cuotas, pagos recibidos y firmas. */
function spPdf(PDO $pdo, int $cid, int $id): string
{
    require_once __DIR__ . '/cotizaciones.php';   // logo de la empresa
    $det = spDetalle($pdo, $cid, $id);
    $p = $det['prestamo'];
    $st = $pdo->prepare("SELECT * FROM clientes_saas WHERE id = ?");
    $st->execute([$cid]);
    $emp = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
    $L = fn($v) => 'L ' . number_format((float)$v, 2);
    $logo = cotizacionLogoB64($emp);
    $alias = ($emp['alias'] ?? '') ?: ($emp['nombre'] ?? '');
    $pagos = '';
    foreach ($det['pagos'] as $g)
        $pagos .= '<tr' . ((int)$g['anulado'] ? ' style="color:#94a3b8;text-decoration:line-through"' : '') . '><td>' . date('d/m/Y', strtotime($g['fecha'])) . '</td><td>' . $e(SP_METODOS[$g['metodo']] ?? $g['metodo'])
            . ($g['referencia'] ? ' · ' . $e($g['referencia']) : '') . '</td><td>' . $e((string)$g['cuenta']) . '</td><td style="text-align:right">' . number_format((float)$g['monto'], 2) . '</td></tr>';
    [$estTxt] = SP_ESTADOS[$p['estado']];
    $html = '<!doctype html><html><head><meta charset="utf-8"><style>
@page { margin: 28px 34px 40px; } body { font-family: "DejaVu Sans", sans-serif; font-size: 8.8pt; color: #1e293b; }
.top { width:100%; border-bottom:3px solid #e8590c; padding-bottom:8px; margin-bottom:12px; } h1 { font-size:15pt; margin:0; }
.muted { color:#64748b; font-size:8pt; } .datos td { padding:3px 10px 3px 0; vertical-align:top; } .k { color:#64748b; }
.cajas { width:100%; border-collapse:separate; border-spacing:6px 0; margin:10px 0 14px; } .cajas td { background:#f1f5f9; border-radius:6px; padding:8px 10px; }
.v { font-size:12pt; font-weight:bold; } h2 { font-size:10.5pt; margin:16px 0 6px; }
table.p { width:100%; border-collapse:collapse; } table.p th { background:#e2e8f0; text-align:left; padding:4px 6px; font-size:8pt; } table.p td { padding:4px 6px; border-bottom:1px solid #e2e8f0; }
.firmas { width:100%; margin-top:46px; } .firmas td { width:50%; text-align:center; padding:0 20px; } .linea { border-top:1px solid #334155; padding-top:4px; }
.pie { position:fixed; bottom:-26px; left:0; right:0; text-align:center; font-size:7pt; color:#94a3b8; }
</style></head><body>
<div class="pie">' . $e($alias) . ' · Control de préstamo #' . $id . ' · generado el ' . date('d/m/Y H:i') . '</div>
<table class="top"><tr><td>' . ($logo ? '<img src="' . $logo . '" style="height:46px">' : '<strong style="color:#e8590c;font-size:14pt">' . $e($alias) . '</strong>') . '</td>
<td style="text-align:right"><h1>Control de préstamo</h1><div class="muted">Préstamo #' . $id . ' · ' . $e($estTxt) . '</div></td></tr></table>
<table class="datos">
<tr><td class="k">' . (spEsTercero($p) ? 'A nombre de' : 'Socio') . '</td><td><strong>' . $e(spNombreVisible($p)) . '</strong></td><td class="k">Fecha del préstamo</td><td>' . date('d/m/Y', strtotime($p['fecha'])) . '</td></tr>
<tr><td class="k">Concepto</td><td>' . $e($p['descripcion']) . '</td><td class="k">Salió de</td><td>' . $e($p['cuenta'] ?: '—') . ($p['referencia'] ? ' · ref. ' . $e($p['referencia']) : '') . '</td></tr>
<tr><td class="k">Forma de pago</td><td>' . (int)$p['num_cuotas'] . ' cuotas ' . ($p['frecuencia'] === 'quincenal' ? 'quincenales (días 15 y fin de mes)' : 'mensuales') . '</td><td class="k">Primera cuota</td><td>' . date('d/m/Y', strtotime($p['primera_cuota'])) . '</td></tr>
</table>
<table class="cajas"><tr>
<td><div class="muted">Monto prestado</div><div class="v">' . $L($p['monto']) . '</div></td>
<td><div class="muted">Pagado</div><div class="v" style="color:#166534">' . $L($det['pagado']) . '</div></td>
<td><div class="muted">Saldo pendiente</div><div class="v" style="color:' . ($det['vencidas'] ? '#b91c1c' : '#0f172a') . '">' . $L($det['saldo']) . '</div></td>
<td><div class="muted">' . ($det['proxima'] ? 'Próxima cuota' : 'Estado') . '</div><div class="v">' . ($det['proxima'] ? date('d/m/Y', strtotime($det['proxima']['fecha'])) . ' · ' . $L($det['proxima']['pendiente']) : $e($estTxt)) . '</div></td>
</tr></table>'
        . ($det['vencidas'] ? '<p style="color:#b91c1c;margin:0 0 8px"><strong>' . $det['vencidas'] . ' cuota' . ($det['vencidas'] === 1 ? '' : 's') . ' vencida' . ($det['vencidas'] === 1 ? '' : 's') . ' por ' . $L($det['vencido_monto']) . '.</strong></p>' : '')
        . spHtmlControl($pdo, $cid, $det)
        . ($p['estado'] === 'activo' ? spCuentasPagoHtml($pdo, $cid, $p, false) : '')
        . ($pagos ? '<h2>Pagos recibidos</h2><table class="p"><thead><tr><th>Fecha</th><th>Método</th><th>Cuenta</th><th style="text-align:right">Monto</th></tr></thead><tbody>' . $pagos . '</tbody></table>' : '')
        . ($p['estado'] === 'condonado' ? '<p style="margin-top:10px"><strong>Saldo pasado a retiro de socio:</strong> ' . $L($p['condonado_monto']) . ' (gasto N.º ' . (int)$p['condonado_gasto_id'] . ').</p>' : '')
        . spFirmasHtml($pdo, $cid, $p, $e)
        . '</body></html>';
    require_once __DIR__ . '/../vendor/autoload.php';
    $opt = new \Dompdf\Options();
    $opt->set('defaultFont', 'DejaVu Sans');
    $opt->set('isRemoteEnabled', false);
    $pdf = new \Dompdf\Dompdf($opt);
    $pdf->loadHtml($html, 'UTF-8');
    $pdf->setPaper('letter', 'portrait');
    $pdf->render();
    return (string)$pdf->output();
}

/**
 * Correo con la tabla de control y el PDF adjunto. $motivo: 'bienvenida' (información del préstamo y su cuadro de pagos),
 * 'por_vencer' | 'vencida' (recordatorios) | 'manual' (estado).
 * Va al correo de avisos del préstamo (o del socio) y, en copia, a los correos de Configuración (Responder a).
 */
function spEnviarAviso(PDO $pdo, int $cid, int $id, string $motivo, ?array $cuota = null, ?int $uid = null): string
{
    require_once __DIR__ . '/correo.php';
    $det = spDetalle($pdo, $cid, $id);
    $p = $det['prestamo'];
    $para = trim((string)($p['aviso_correo'] ?: $p['socio_email']));
    if ($para === '') throw new Exception("El préstamo no tiene correo para los avisos.");
    $perfil = null;
    foreach (['facturacion', 'nomina'] as $pf) { $cfg = correoConfig($pdo, $cid, $pf); if ($cfg && (int)($cfg['activo'] ?? 0) && !empty($cfg['clave_cifrada'])) { $perfil = $pf; break; } }
    if (!$perfil) throw new Exception("La empresa no tiene una cuenta de correo lista para enviar.");
    $cfg = correoConfig($pdo, $cid, $perfil) ?? [];
    // Copia: la del préstamo y los correos de Configuración (Responder a)
    $cc = implode(', ', array_unique(array_diff(array_merge(correoLista((string)($p['aviso_cc'] ?? '')), correoLista((string)($cfg['responder_a'] ?? ''))), correoLista($para))));
    $cuota ??= $det['proxima'];
    $e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
    $L = fn($v) => 'L ' . number_format((float)$v, 2);
    $nombre = explode(' ', trim(spNombreVisible($p)))[0];
    if ($motivo === 'bienvenida') {
        $c1 = $det['cuotas'][0] ?? null;
        $ult = end($det['cuotas']) ?: null;
        $asunto = 'Información de tu préstamo #' . $id . ' · ' . $L($p['monto']) . ' en ' . (int)$p['num_cuotas'] . ' cuotas';
        $intro = 'te compartimos la información del préstamo «' . $e($p['descripcion']) . '» por <strong>' . $L($p['monto']) . '</strong> del ' . date('d/m/Y', strtotime($p['fecha'])) . '. '
            . 'Se devuelve en <strong>' . (int)$p['num_cuotas'] . ' cuotas ' . ($p['frecuencia'] === 'quincenal' ? 'quincenales (los días 15 y fin de mes)' : 'mensuales') . ' de ' . $L($c1['monto'] ?? 0) . '</strong>'
            . ($c1 ? ', la primera el <strong>' . date('d/m/Y', strtotime($c1['fecha'])) . '</strong> y la última el ' . date('d/m/Y', strtotime($ult['fecha'])) : '') . '. '
            . 'Abajo está el cuadro de pagos; también lo adjuntamos en PDF. Te avisaremos por este medio antes de cada cuota.';
        $cuota = null;
    } elseif ($motivo === 'vencida' && $cuota) {
        $asunto = 'Cuota vencida del préstamo #' . $id . ' · ' . $L($det['vencido_monto']);
        $intro = 'tienes <strong>' . $det['vencidas'] . ' cuota' . ($det['vencidas'] === 1 ? '' : 's') . ' vencida' . ($det['vencidas'] === 1 ? '' : 's') . '</strong> del préstamo «' . $e($p['descripcion']) . '» por <strong>' . $L($det['vencido_monto']) . '</strong>. La más antigua venció el ' . date('d/m/Y', strtotime($cuota['fecha'])) . '.';
    } elseif ($cuota) {
        $asunto = 'Recordatorio: cuota ' . (int)$cuota['numero'] . ' del préstamo #' . $id . ' · ' . $L($cuota['pendiente']) . ' el ' . date('d/m/Y', strtotime($cuota['fecha']));
        $intro = 'te recordamos la cuota <strong>' . (int)$cuota['numero'] . ' de ' . (int)$p['num_cuotas'] . '</strong> del préstamo «' . $e($p['descripcion']) . '»: <strong>' . $L($cuota['pendiente']) . '</strong> el <strong>' . date('d/m/Y', strtotime($cuota['fecha'])) . '</strong>.';
    } else {
        $asunto = 'Estado del préstamo #' . $id . ' · saldo ' . $L($det['saldo']);
        $intro = 'te compartimos el estado del préstamo «' . $e($p['descripcion']) . '».';
    }
    $cuerpo = '<p style="margin:0 0 14px">Hola, ' . $e($nombre) . ':</p><p style="margin:0 0 14px">' . ucfirst($intro) . '</p>'
        . '<p style="margin:14px 0">Prestado: <strong>' . $L($p['monto']) . '</strong> · Pagado: <strong>' . $L($det['pagado']) . '</strong> · Saldo: <strong>' . $L($det['saldo']) . '</strong></p>'
        . spHtmlControl($pdo, $cid, $det, true)
        . spMovimientosHtml($det)
        . ($p['estado'] === 'activo' ? spCuentasPagoHtml($pdo, $cid, $p) : '')
        . '<p style="margin-top:14px;font-size:13px;color:#64748b">Adjuntamos el control del préstamo en PDF. Cuando hagas el pago, envía el comprobante para registrarlo.</p>';
    $html = spCorreoMarco($pdo, $cid, $cfg, $asunto, $cuerpo);
    $tmp = tempnam(sys_get_temp_dir(), 'sp');
    file_put_contents($tmp, spPdf($pdo, $cid, $id));
    try {
        correoEnviar($pdo, $cid, $para, mb_substr($asunto, 0, 255), $html, strip_tags(str_replace(['</p>', '</tr>'], ["\n\n", "\n"], $html)),
            [['ruta' => $tmp, 'nombre' => 'control_prestamo_' . $id . '.pdf']], 'prestamo_socio_' . $motivo, $cuota ? (int)$cuota['id'] : $id, $uid, $perfil, $cc);
    } finally {
        @unlink($tmp);
    }
    return $para;
}

/** Formato de la empresa para los correos de préstamos: logo arriba, el contenido en una tarjeta y el pie automático. */
function spCorreoMarco(PDO $pdo, int $cid, array $cfg, string $titulo, string $cuerpo): string
{
    $e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
    $st = $pdo->prepare("SELECT nombre, alias FROM clientes_saas WHERE id = ?");
    $st->execute([$cid]);
    $emp = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $alias = ($emp['alias'] ?? '') ?: ($emp['nombre'] ?? '');
    $logo = !empty($cfg['logo_url']) ? '<img src="' . $e($cfg['logo_url']) . '" alt="' . $e($alias) . '" height="52" style="height:52px;width:auto;max-width:200px;border:0;display:block">'
        : '<span style="font-size:20px;font-weight:700;color:#e8590c">' . $e($alias) . '</span>';
    if (!empty($cfg['enlace_url'])) $logo = '<a href="' . $e($cfg['enlace_url']) . '" target="_blank" style="text-decoration:none">' . $logo . '</a>';
    return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#f1f5f9;font-family:Segoe UI,Helvetica,Arial,sans-serif">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 12px"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0">'
        . '<tr><td style="padding:22px 28px;border-bottom:4px solid #e8590c">' . $logo . '</td></tr>'
        . '<tr><td style="padding:26px 28px 6px;font-size:18px;font-weight:700;color:#0f172a">' . $e($titulo) . '</td></tr>'
        . '<tr><td style="padding:10px 28px 28px;font-size:14.5px;line-height:1.6;color:#1e293b">' . $cuerpo . '</td></tr>'
        . '<tr><td style="padding:16px 28px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:11.5px;line-height:1.55;color:#94a3b8">'
        . (function_exists('correoPieAutomatico') ? correoPieAutomatico($cfg, $e) : '') . ' La información de este mensaje es confidencial y está dirigida únicamente a su destinatario.</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/**
 * Avisos automáticos (cron, desde las 8:00): cada cuota se recuerda una vez unos días antes (aviso_dias); una vencida, el día
 * siguiente y luego cada 7 días mientras siga vencida. No repite un aviso ya enviado; con 3 errores en el día deja de intentar.
 */
function spAvisosCron(PDO $pdo, callable $log): void
{
    if (!spDisponible($pdo) || (int)date('G') < 8) return;
    require_once __DIR__ . '/correo.php';
    if (!correoDisponible($pdo)) return;
    $hoy = date('Y-m-d');
    $ya = $pdo->prepare("SELECT MAX(creado_en) FROM correos_enviados WHERE cliente_id = ? AND tipo = ? AND referencia_id = ? AND estado = 'enviado'");
    $fallos = $pdo->prepare("SELECT COUNT(*) FROM correos_enviados WHERE cliente_id = ? AND tipo LIKE 'prestamo_socio_%' AND referencia_id = ? AND estado = 'error' AND DATE(creado_en) = CURDATE()");
    foreach ($pdo->query("SELECT id, cliente_id FROM socios_prestamos WHERE estado = 'activo' AND avisar = 1")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cid = (int)$r['cliente_id'];
        try {
            $det = spDetalle($pdo, $cid, (int)$r['id'], $hoy);
            if (trim((string)($det['prestamo']['aviso_correo'] ?: $det['prestamo']['socio_email'])) === '') continue;   // sin correo: no hay a quién avisar
            $dias = (int)$det['prestamo']['aviso_dias'];
            $venc = array_values(array_filter($det['cuotas'], fn($c) => $c['estado'] === 'vencida'));
            if ($venc) {
                $c = $venc[0];
                $ya->execute([$cid, 'prestamo_socio_vencida', $c['id']]);
                $ult = $ya->fetchColumn();
                if ($ult && strtotime($ult) > strtotime('-7 days')) continue;
                $motivo = 'vencida';
            } else {
                $c = $det['proxima'];
                if (!$c || $c['dias'] > $dias || $c['dias'] < 0) continue;
                $ya->execute([$cid, 'prestamo_socio_por_vencer', $c['id']]);
                if ($ya->fetchColumn()) continue;
                $motivo = 'por_vencer';
            }
            $fallos->execute([$cid, $c['id']]);
            if ($fallos->fetchColumn() >= 3) continue;
            $para = spEnviarAviso($pdo, $cid, (int)$r['id'], $motivo, $c);
            $log("Préstamo a socio #{$r['id']}: aviso de cuota " . ($motivo === 'vencida' ? 'vencida' : 'por vencer') . " enviado a $para");
        } catch (Throwable $ex) {
            $log("Préstamo a socio #{$r['id']}: " . $ex->getMessage());
        }
    }
}

// ── Comprobantes (uno o varios por préstamo y por pago) ─────────────────────

/** $_FILES de un input múltiple (name="comprobantes[]") → lista de archivos sueltos (también acepta uno solo). */
function spArchivosPost(?array $f): array
{
    if (!$f || !isset($f['name'])) return [];
    if (!is_array($f['name'])) return [$f];
    $out = [];
    foreach ($f['name'] as $i => $n) $out[] = ['name' => $n, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    return $out;
}

/** Guarda varios comprobantes subidos. Devuelve [[archivo, nombre], …] (si uno no es válido, falla y no queda ninguno). */
function spSubirVarios(array $files, int $cid, string $prefijo): array
{
    $hechos = [];
    try {
        foreach ($files as $f) if ($s = spSubir($f, $cid, $prefijo)) $hechos[] = $s;
    } catch (Throwable $e) {
        foreach ($hechos as $h) @unlink(spDir() . $h[0]);
        throw $e;
    }
    return $hechos;
}

/** Registra comprobantes en un préstamo (o en uno de sus pagos) y deja el primero en el movimiento del banco si no tiene. */
function spArchivosAgregar(PDO $pdo, int $cid, int $uid, int $prestamoId, ?int $pagoId, array $guardados): void
{
    if (!$guardados) return;
    $ins = $pdo->prepare("INSERT INTO socios_prestamo_archivos (cliente_id, prestamo_id, pago_id, archivo, nombre, usuario_id) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($guardados as [$a, $n]) $ins->execute([$cid, $prestamoId, $pagoId, $a, $n, $uid]);
    $tabla = $pagoId ? 'socios_prestamo_pagos' : 'socios_prestamos';
    $id = $pagoId ?: $prestamoId;
    $pdo->prepare("UPDATE $tabla SET archivo_adjunto = ?, archivo_nombre = ? WHERE id = ? AND archivo_adjunto IS NULL")->execute([$guardados[0][0], $guardados[0][1], $id]);
    $st = $pdo->prepare("SELECT movimiento_id FROM $tabla WHERE id = ?");
    $st->execute([$id]);
    if ($mov = (int)$st->fetchColumn())
        $pdo->prepare("UPDATE movimientos_bancarios SET archivo_adjunto = ?, archivo_nombre = ? WHERE id = ? AND archivo_adjunto IS NULL")->execute([$guardados[0][0], $guardados[0][1], $mov]);
}

/** Comprobantes del préstamo: ['prestamo' => [...], pago_id => [...]]. */
function spArchivos(PDO $pdo, int $cid, int $prestamoId): array
{
    $out = ['prestamo' => []];
    try {
        $st = $pdo->prepare("SELECT id, pago_id, archivo, nombre, creado_en FROM socios_prestamo_archivos WHERE prestamo_id = ? AND cliente_id = ? ORDER BY id");
        $st->execute([$prestamoId, $cid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) $out[$a['pago_id'] ? (int)$a['pago_id'] : 'prestamo'][] = $a;
    } catch (Throwable $e) {
    }
    return $out;
}

/** Quita un comprobante (y el archivo si ya nadie lo usa). Si era el principal, pasa el siguiente a su lugar. */
function spArchivoQuitar(PDO $pdo, int $cid, int $archivoId): void
{
    $st = $pdo->prepare("SELECT * FROM socios_prestamo_archivos WHERE id = ? AND cliente_id = ?");
    $st->execute([$archivoId, $cid]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) throw new Exception("Comprobante no encontrado.");
    $pdo->prepare("DELETE FROM socios_prestamo_archivos WHERE id = ?")->execute([$archivoId]);
    $tabla = $a['pago_id'] ? 'socios_prestamo_pagos' : 'socios_prestamos';
    $id = $a['pago_id'] ?: $a['prestamo_id'];
    $st = $pdo->prepare("SELECT archivo, nombre FROM socios_prestamo_archivos WHERE prestamo_id = ? AND pago_id <=> ? ORDER BY id LIMIT 1");
    $st->execute([$a['prestamo_id'], $a['pago_id']]);
    $sig = $st->fetch(PDO::FETCH_ASSOC) ?: ['archivo' => null, 'nombre' => null];
    $st = $pdo->prepare("SELECT movimiento_id, archivo_adjunto FROM $tabla WHERE id = ?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r && $r['archivo_adjunto'] === $a['archivo']) {
        $pdo->prepare("UPDATE $tabla SET archivo_adjunto = ?, archivo_nombre = ? WHERE id = ?")->execute([$sig['archivo'], $sig['nombre'], $id]);
        if ($r['movimiento_id']) $pdo->prepare("UPDATE movimientos_bancarios SET archivo_adjunto = ?, archivo_nombre = ? WHERE id = ? AND archivo_adjunto = ?")->execute([$sig['archivo'], $sig['nombre'], (int)$r['movimiento_id'], $a['archivo']]);
    }
    $uso = $pdo->prepare("SELECT (SELECT COUNT(*) FROM socios_prestamo_archivos WHERE archivo = ?) + (SELECT COUNT(*) FROM movimientos_bancarios WHERE archivo_adjunto = ?) + (SELECT COUNT(*) FROM gastos WHERE archivo_adjunto = ?)");
    $uso->execute([$a['archivo'], $a['archivo'], $a['archivo']]);
    if (!(int)$uso->fetchColumn()) @unlink(spDir() . basename($a['archivo']));
}
