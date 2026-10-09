<?php
/**
 * licencias.php — Licencias y suscripciones (Adobe, Dropbox, hosting, dominios, correos…).
 *   - De la empresa: son su gasto.
 *   - De un cliente (receptor_id): la empresa la paga y se la cobra con comisión en la factura del mes de la renovación.
 * Cada licencia activa tiene siempre un gasto pendiente para su próxima renovación (gastos.licencia_id). Al pagarlo,
 * la recurrencia de gastos (mensual/anual) crea el siguiente; licenciasSincronizar() corrige lo que falte.
 */

const LICENCIA_COMISION_DEFECTO = 35.0;   // % sobre el costo (definido por la empresa; se cambia en cada licencia)

function licenciasDisponible(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try { $ok = (bool)$pdo->query("SHOW TABLES LIKE 'licencias'")->fetchColumn() && (bool)$pdo->query("SHOW COLUMNS FROM gastos LIKE 'licencia_id'")->fetchColumn(); }
        catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

/**
 * Tasa del dólar para licencias en USD (0 si no hay): la VENTA del BCH, porque al pagar con tarjeta en dólares
 * el banco te vende esos dólares. Sin BCH, la de referencia (o la última usada en Bancos).
 */
function licenciaTasa(PDO $pdo, int $cid): float
{
    static $t = [];
    if (!isset($t[$cid])) {
        require_once __DIR__ . '/tasa_cambio.php';
        $u = tasaDisponible($pdo) ? tasaUltima($pdo) : null;
        $t[$cid] = $u ? (float)($u['venta'] ?: $u['referencia'] ?: 0) : 0.0;
        if ($t[$cid] <= 0) {
            require_once __DIR__ . '/estados_financieros.php';
            $t[$cid] = (float)efTasaReciente($pdo, $cid);
        }
    }
    return $t[$cid];
}

/** Costo en lempiras (las de dólares, a la tasa más reciente). */
function licenciaCostoHnl(PDO $pdo, int $cid, array $l): float
{
    $c = (float)$l['costo'];
    if (($l['moneda'] ?? 'HNL') === 'USD') $c = ($t = licenciaTasa($pdo, $cid)) > 0 ? $c * $t : 0.0;
    return round($c, 2);
}

/** Precio al cliente: costo + comisión. */
function licenciaPrecio(float $costoHnl, float $pct): float
{
    return round($costoHnl * (1 + $pct / 100), 2);
}

/** Frecuencia del gasto de la renovación: mensual/anual se repiten solos al pagarse; cada 2 años lo programa la licencia. */
function licenciaFrecuenciaGasto(string $f): string
{
    return in_array($f, ['mensual', 'anual'], true) ? $f : 'unico';
}

/** Cuántas veces se paga al año (para los totales anuales). */
function licenciaVecesAnio(string $f): float
{
    return ['mensual' => 12.0, 'anual' => 1.0, 'bienal' => 0.5][$f] ?? 1.0;
}

const LICENCIA_FRECUENCIAS = ['anual' => 'Anual', 'mensual' => 'Mensual', 'bienal' => 'Cada 2 años'];

function licenciasLista(PDO $pdo, int $cid, bool $soloActivas = false): array
{
    if (!licenciasDisponible($pdo)) return [];
    $st = $pdo->prepare("SELECT l.*, cf.nombre AS cliente_nombre, cg.nombre AS categoria
                         FROM licencias l LEFT JOIN clientes_factura cf ON cf.id = l.receptor_id
                         LEFT JOIN categorias_gastos cg ON cg.id = l.categoria_id
                         WHERE l.cliente_id = ?" . ($soloActivas ? " AND l.activa = 1" : '') . " ORDER BY l.activa DESC, l.proxima_renovacion, l.nombre");
    $st->execute([$cid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function licenciaObtener(PDO $pdo, int $cid, int $id): array
{
    $st = $pdo->prepare("SELECT * FROM licencias WHERE id = ? AND cliente_id = ?");
    $st->execute([$id, $cid]);
    $l = $st->fetch(PDO::FETCH_ASSOC);
    if (!$l) throw new Exception("La licencia no existe.");
    return $l;
}

function licenciaSiguienteFecha(string $fecha, string $frecuencia): string
{
    $d = new DateTime($fecha);
    $dia = (int)$d->format('j');
    $d->modify('first day of this month')->modify(['mensual' => '+1 month', 'bienal' => '+2 years'][$frecuencia] ?? '+1 year');
    $d->setDate((int)$d->format('Y'), (int)$d->format('n'), min($dia, (int)$d->format('t')));
    return $d->format('Y-m-d');
}

/**
 * Deja cada licencia activa con un gasto pendiente para su próxima renovación (con el costo, la categoría y el cliente
 * al día) y la fecha de la licencia igual a la de ese gasto. Las inactivas no tienen gastos pendientes.
 */
function licenciasSincronizar(PDO $pdo, int $cid, int $usuario = 0): void
{
    if (!licenciasDisponible($pdo)) return;
    foreach (licenciasLista($pdo, $cid) as $l) {
        $id = (int)$l['id'];
        if (!(int)$l['activa']) {
            $pdo->prepare("UPDATE gastos SET estado = 'anulado', notas = CONCAT(COALESCE(notas, ''), ' | Licencia desactivada.') WHERE cliente_id = ? AND licencia_id = ? AND estado = 'pendiente'")->execute([$cid, $id]);
            continue;
        }
        $desc = 'Licencia: ' . $l['nombre'];
        $monto = licenciaCostoHnl($pdo, $cid, $l);
        $st = $pdo->prepare("SELECT * FROM gastos WHERE cliente_id = ? AND licencia_id = ? AND estado = 'pendiente' ORDER BY fecha LIMIT 1");
        $st->execute([$cid, $id]);
        $pend = $st->fetch(PDO::FETCH_ASSOC);
        if ($pend) {
            // Datos de la licencia al día en el gasto pendiente; la fecha manda la del gasto (p. ej. si se movió al pagar)
            $pdo->prepare("UPDATE gastos SET descripcion = ?, monto = ?, categoria_id = ?, proveedor = ?, frecuencia = ?, metodo_pago = ?, cobrar_receptor_id = ? WHERE id = ?")
                ->execute([$desc, $monto, $l['categoria_id'] ?: null, $l['proveedor'] ?: null, licenciaFrecuenciaGasto($l['frecuencia']), $l['metodo_pago'], $l['receptor_id'] ?: null, (int)$pend['id']]);
            if ($pend['fecha'] !== $l['proxima_renovacion'])
                $pdo->prepare("UPDATE licencias SET proxima_renovacion = ? WHERE id = ?")->execute([$pend['fecha'], $id]);
            continue;
        }
        // Sin pendiente: la siguiente después del último pago (o la fecha de la licencia si aún no hay pagos)
        $st = $pdo->prepare("SELECT MAX(fecha) FROM gastos WHERE cliente_id = ? AND licencia_id = ? AND estado = 'pagado'");
        $st->execute([$cid, $id]);
        $ultimo = $st->fetchColumn();
        $fecha = $l['proxima_renovacion'];
        if ($ultimo && $fecha <= $ultimo) $fecha = licenciaSiguienteFecha($ultimo, $l['frecuencia']);
        $st = $pdo->prepare("SELECT MIN(id) FROM gastos WHERE cliente_id = ? AND licencia_id = ?");
        $st->execute([$cid, $id]);
        $grupo = (int)$st->fetchColumn() ?: null;
        $pdo->prepare("INSERT INTO gastos (cliente_id, categoria_id, descripcion, monto, fecha, frecuencia, dia_pago, gasto_grupo_id, tipo, metodo_pago, proveedor, notas, estado, usuario_id, cobrar_receptor_id, licencia_id)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'fijo', ?, ?, ?, 'pendiente', ?, ?, ?)")
            ->execute([$cid, $l['categoria_id'] ?: null, $desc, $monto, $fecha, licenciaFrecuenciaGasto($l['frecuencia']), (int)substr($fecha, 8, 2), $grupo, $l['metodo_pago'], $l['proveedor'] ?: null,
                       'Renovación de la licencia #' . $id . ($l['moneda'] === 'USD' ? ' (USD ' . number_format((float)$l['costo'], 2) . ' a la tasa del día)' : ''), $usuario, $l['receptor_id'] ?: null, $id]);
        $nuevo = (int)$pdo->lastInsertId();
        if (!$grupo) $pdo->prepare("UPDATE gastos SET gasto_grupo_id = ? WHERE id = ?")->execute([$nuevo, $nuevo]);
        $pdo->prepare("UPDATE licencias SET proxima_renovacion = ? WHERE id = ?")->execute([$fecha, $id]);
    }
}

/** Ingresos esperados por licencias de clientes en un mes (renovaciones de ese mes, con comisión). */
function licenciasIngresosMes(PDO $pdo, int $cid, int $anio, int $mes): array
{
    $ini = sprintf('%04d-%02d-01', $anio, $mes);
    $fin = date('Y-m-t', strtotime($ini));
    $out = [];
    foreach (licenciasLista($pdo, $cid, true) as $l) {
        if (empty($l['receptor_id'])) continue;
        $f = $l['proxima_renovacion'];
        while ($f < $ini) $f = licenciaSiguienteFecha($f, $l['frecuencia']);
        if ($f > $fin) continue;
        $out[] = ['cliente' => $l['cliente_nombre'] ?: 'Cliente', 'nombre' => 'Licencia: ' . $l['nombre'] . ' · renovación ' . date('d/m/Y', strtotime($f)),
                  'monto' => licenciaPrecio(licenciaCostoHnl($pdo, $cid, $l), (float)$l['comision_pct']), 'regla' => 'Licencia que se le cobra al cliente (costo + ' . rtrim(rtrim(number_format((float)$l['comision_pct'], 2), '0'), '.') . ' %)'];
    }
    return $out;
}

/** Días que faltan para la renovación → color del semáforo (verde solo si está al día y lejos). */
function licenciaSemaforo(string $fecha): array
{
    $dias = (int)floor((strtotime($fecha) - strtotime(date('Y-m-d'))) / 86400);
    if ($dias < 0) return [$dias, 'rojo', 'Vencida hace ' . abs($dias) . ' día(s)'];
    if ($dias <= 7) return [$dias, 'rojo', $dias === 0 ? 'Vence hoy' : "En $dias día(s)"];
    if ($dias <= 30) return [$dias, 'amarillo', "En $dias días"];
    return [$dias, 'gris', "En $dias días"];
}
