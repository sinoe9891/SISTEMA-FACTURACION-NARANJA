<?php
/**
 * estados_financieros.php — Datos del Estado de resultados (formato clásico) y del Balance general.
 * Solo lectura: se calcula con lo que ya registra el sistema (facturas, cobros, gastos, bancos,
 * préstamos a colaboradores y anticipos de contratos). Toda la empresa (todos los establecimientos).
 */
require_once __DIR__ . '/activos.php';

/** Tabla existe (los módulos opcionales pueden no estar instalados). */
function efHay(PDO $pdo, string $tabla): bool
{
    static $c = [];
    return $c[$tabla] ??= (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($tabla))->fetchColumn();
}

/**
 * Ingresos cobrados con recibo (contratos «sin factura»): no llevan ISV, el monto completo es ingreso.
 * Un recibo se emite al recibir el pago, así que su fecha es la del cobro.
 */
function efRecibos(PDO $pdo, int $cid, string $desde, string $hasta): array
{
    if (!efHay($pdo, 'contratos_recibos')) return ['total' => 0.0, 'n' => 0];
    $st = $pdo->prepare("SELECT COALESCE(SUM(monto),0) total, COUNT(*) n FROM contratos_recibos WHERE cliente_id = ? AND estado = 'emitido' AND fecha_emision BETWEEN ? AND ?");
    $st->execute([$cid, $desde, $hasta]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return ['total' => (float)$r['total'], 'n' => (int)$r['n']];
}

/**
 * Estado de resultados del período [$desde, $hasta]:
 * ventas (subtotal de facturas emitidas, sin ISV) + recibos (sin factura) − gastos por categoría = utilidad del período.
 */
function efResultados(PDO $pdo, int $cid, string $desde, string $hasta): array
{
    $st = $pdo->prepare("SELECT COALESCE(SUM(gravado_total),0) gravado, COALESCE(SUM(exento_total + importe_exonerado),0) exento,
                                COALESCE(SUM(subtotal),0) subtotal, COALESCE(SUM(isv_15 + isv_18),0) isv, COUNT(*) n
                         FROM facturas WHERE cliente_id = ? AND estado = 'emitida' AND DATE(fecha_emision) BETWEEN ? AND ?");
    $st->execute([$cid, $desde, $hasta]);
    $v = array_map('floatval', $st->fetch(PDO::FETCH_ASSOC));

    $st = $pdo->prepare("SELECT COALESCE(cg.nombre, 'Otros gastos') nombre, COALESCE(SUM(g.monto),0) total, COUNT(*) n
                         FROM gastos g LEFT JOIN categorias_gastos cg ON cg.id = g.categoria_id
                         WHERE g.cliente_id = ? AND g.estado <> 'anulado' AND g.fecha BETWEEN ? AND ?" . gastoEsGastoSql($pdo, 'g') . "
                         GROUP BY COALESCE(cg.nombre, 'Otros gastos') ORDER BY total DESC");
    $st->execute([$cid, $desde, $hasta]);
    $gastos = $st->fetchAll(PDO::FETCH_ASSOC);
    // Depreciación de activos fijos del período (gasto que no sale del banco)
    $dep = activosDepreciacionPeriodo($pdo, $cid, $desde, $hasta);
    if ($dep > 0) {
        $gastos[] = ['nombre' => 'Depreciación de activos fijos', 'total' => $dep, 'n' => 0];
        usort($gastos, fn($a, $b) => (float)$b['total'] <=> (float)$a['total']);
    }
    $totalGastos = array_sum(array_column($gastos, 'total'));

    $rec = efRecibos($pdo, $cid, $desde, $hasta);
    $ventas = $v['subtotal'] + $rec['total'];

    return [
        'ventas' => $ventas, 'ventas_facturas' => $v['subtotal'], 'gravado' => $v['gravado'], 'exento' => $v['exento'], 'isv' => $v['isv'], 'facturas' => (int)$v['n'],
        'recibos' => $rec['total'], 'n_recibos' => $rec['n'],
        'gastos' => $gastos, 'total_gastos' => (float)$totalGastos, 'depreciacion' => $dep,
        'utilidad' => round($ventas - $totalGastos, 2),
    ];
}

/** Tasa L/US$ más reciente registrada en bancos (0 si no hay). */
function efTasaReciente(PDO $pdo, int $cid): float
{
    // Primero la tasa del día (BCH compra o referencia); si no hay, la última usada en Bancos
    require_once __DIR__ . '/tasa_cambio.php';
    if (($t = tasaValoracion($pdo)) > 0) return $t;
    if (!efHay($pdo, 'movimientos_bancarios')) return 0.0;
    $st = $pdo->prepare("SELECT tasa_cambio FROM movimientos_bancarios WHERE cliente_id = ? AND tasa_cambio IS NOT NULL AND tasa_cambio > 0 ORDER BY fecha DESC, id DESC LIMIT 1");
    $st->execute([$cid]);
    return (float)$st->fetchColumn();
}

/**
 * Balance general al $corte. $tasa convierte las cuentas en dólares (0 = no se suman).
 * Patrimonio = Activo − Pasivo; se separa la utilidad del ejercicio (1 de enero al corte).
 */
function efBalance(PDO $pdo, int $cid, string $corte, float $tasa): array
{
    $activo = $pasivo = [];

    // Efectivo: saldo de cada cuenta bancaria al corte
    $bancos = [];
    $sinConvertir = [];
    if (efHay($pdo, 'cuentas_bancarias')) {
        $st = $pdo->prepare("SELECT c.banco, c.tipo, c.numero, c.moneda,
                                    c.saldo_inicial + COALESCE(SUM(CASE WHEN m.sentido = 'entrada' THEN m.monto ELSE -m.monto END), 0) saldo
                             FROM cuentas_bancarias c
                             LEFT JOIN movimientos_bancarios m ON m.cuenta_id = c.id AND m.anulado = 0 AND m.fecha <= ?
                             WHERE c.cliente_id = ? AND (c.fecha_saldo_inicial IS NULL OR c.fecha_saldo_inicial <= ?)
                             GROUP BY c.id ORDER BY c.moneda, c.banco");
        $st->execute([$corte, $cid, $corte]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $saldo = round((float)$b['saldo'], 2);
            if (abs($saldo) < 0.005) continue;
            $nombre = $b['banco'] . ' ' . ($b['tipo'] === 'cheques' ? 'cheques' : 'ahorro') . ' ••' . substr((string)$b['numero'], -4);
            if ($b['moneda'] === 'USD') {
                if ($tasa > 0) $bancos[] = ['nombre' => $nombre . ' (US$ ' . number_format($saldo, 2) . ' × ' . rtrim(rtrim(number_format($tasa, 4), '0'), '.') . ')', 'monto' => round($saldo * $tasa, 2)];
                else $sinConvertir[] = ['nombre' => $nombre, 'usd' => $saldo];
            } else {
                $bancos[] = ['nombre' => $nombre, 'monto' => $saldo];
            }
        }
    }
    $activo['Efectivo y equivalentes'] = $bancos;

    // Cuentas por cobrar a clientes: facturas emitidas hasta el corte, menos lo cobrado hasta el corte
    $hayCobros = efHay($pdo, 'cobros_factura');
    $st = $pdo->prepare("SELECT f.total, f.pagada, " . ($hayCobros
            ? "COALESCE((SELECT SUM(c.monto) FROM cobros_factura c WHERE c.factura_id = f.id AND c.anulado = 0 AND c.fecha <= ?), 0) abonado,
               (SELECT COUNT(*) FROM cobros_factura c WHERE c.factura_id = f.id AND c.anulado = 0) n_cobros"
            : "0 abonado, 0 n_cobros") . "
                         FROM facturas f WHERE f.cliente_id = ? AND f.estado = 'emitida' AND DATE(f.fecha_emision) <= ?");
    $st->execute($hayCobros ? [$corte, $cid, $corte] : [$cid, $corte]);
    $cxc = 0.0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
        // Con abonos registrados se usa lo cobrado al corte; sin abonos, la marca «pagada» de la factura
        $saldo = (int)$f['n_cobros'] > 0 ? (float)$f['total'] - (float)$f['abonado'] : ((int)$f['pagada'] ? 0 : (float)$f['total']);
        $cxc += max(0, $saldo);
    }
    $activo['Cuentas por cobrar'] = [['nombre' => 'Clientes (facturas pendientes de cobro)', 'monto' => round($cxc, 2)]];

    // Préstamos y adelantos a colaboradores pendientes de descontar
    if (efHay($pdo, 'colaborador_prestamos')) {
        $st = $pdo->prepare("SELECT COALESCE(SUM(saldo_pendiente),0) FROM colaborador_prestamos WHERE cliente_id = ? AND tipo IN ('prestamo','adelanto') AND estado = 'activo' AND fecha <= ?");
        $st->execute([$cid, $corte]);
        $prest = round((float)$st->fetchColumn(), 2);
        if ($prest > 0) $activo['Cuentas por cobrar'][] = ['nombre' => 'Préstamos y adelantos a colaboradores', 'monto' => $prest];
    }

    // Anticipos a proveedores (pagados, aún sin su factura): dinero a favor
    $antProv = anticiposProveedorSaldo($pdo, $cid, $corte);
    if ($antProv > 0) $activo['Cuentas por cobrar'][] = ['nombre' => 'Anticipos a proveedores', 'monto' => $antProv];

    // Activos fijos a su valor en libros (costo − depreciación acumulada)
    $af = activosBalance($pdo, $cid, $corte);
    if ($af) $activo['Activos fijos'] = array_map(fn($a) => ['nombre' => $a['nombre'] . ' (costo L ' . number_format($a['costo'], 2) . ' − depreciación L ' . number_format($a['acumulada'], 2) . ')', 'monto' => $a['neto']], $af);

    // Pasivo: gastos registrados y aún no pagados (las cuotas de préstamos ya están en «Préstamos por pagar»)
    $st = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM gastos WHERE cliente_id = ? AND estado = 'pendiente' AND fecha <= ?" . (activosDisponible($pdo) ? " AND naturaleza <> 'capital'" : ''));
    $st->execute([$cid, $corte]);
    $pasivo['Cuentas por pagar'] = [['nombre' => 'Proveedores y gastos pendientes de pago', 'monto' => round((float)$st->fetchColumn(), 2)]];

    // Bonos y viáticos aprobados que aún no se pagan al colaborador
    if (efHay($pdo, 'colaborador_prestamos')) {
        $st = $pdo->prepare("SELECT COALESCE(SUM(monto_total),0) FROM colaborador_prestamos WHERE cliente_id = ? AND tipo IN ('bono','viatico') AND estado = 'activo' AND fecha <= ?");
        $st->execute([$cid, $corte]);
        $bv = round((float)$st->fetchColumn(), 2);
        if ($bv > 0) $pasivo['Cuentas por pagar'][] = ['nombre' => 'Bonos y viáticos por pagar a colaboradores', 'monto' => $bv];
    }

    // ISV cobrado en facturas aún no declaradas
    $st = $pdo->prepare("SELECT COALESCE(SUM(isv_15 + isv_18),0) FROM facturas WHERE cliente_id = ? AND estado = 'emitida' AND estado_declarada = 0 AND DATE(fecha_emision) <= ?");
    $st->execute([$cid, $corte]);
    $pasivo['Impuestos por pagar'] = [['nombre' => 'ISV cobrado en facturas no declaradas', 'monto' => round((float)$st->fetchColumn(), 2)]];

    // Préstamos recibidos: capital pendiente al corte
    foreach (prestamosBalance($pdo, $cid, $corte) as $p) if ($p['saldo'] > 0) $pasivo['Préstamos por pagar'][] = ['nombre' => $p['nombre'], 'monto' => $p['saldo']];

    // Anticipos de clientes que aún no se aplican a una factura
    if (efHay($pdo, 'contratos_anticipos')) {
        $st = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM contratos_anticipos WHERE cliente_id = ? AND anulado = 0 AND factura_id IS NULL AND fecha <= ?");
        $st->execute([$cid, $corte]);
        $ant = round((float)$st->fetchColumn(), 2);
        // El ISV que viene dentro de esos pagos se declara al facturar: se aparta como impuesto por pagar
        require_once __DIR__ . '/contrato_plan.php';
        $isvAnt = planIsvPorApartar($pdo, $cid, 0, $corte);
        if ($isvAnt > 0) $pasivo['Impuestos por pagar'][] = ['nombre' => 'ISV incluido en pagos anticipados (por apartar)', 'monto' => $isvAnt];
        if ($ant > 0) $pasivo['Anticipos de clientes'] = [['nombre' => 'Pagos recibidos de proyectos aún sin facturar' . ($isvAnt > 0 ? ' (sin el ISV)' : ''), 'monto' => round($ant - $isvAnt, 2)]];
    }

    $suma = fn(array $grupos) => round(array_sum(array_map(fn($g) => array_sum(array_column($g, 'monto')), $grupos)), 2);
    $totalActivo = $suma($activo);
    $totalPasivo = $suma($pasivo);
    $patrimonio = round($totalActivo - $totalPasivo, 2);
    $ejercicio = efResultados($pdo, $cid, substr($corte, 0, 4) . '-01-01', $corte)['utilidad'];

    return [
        'activo' => $activo, 'pasivo' => $pasivo, 'total_activo' => $totalActivo, 'total_pasivo' => $totalPasivo,
        'utilidad_ejercicio' => $ejercicio, 'patrimonio_anterior' => round($patrimonio - $ejercicio, 2), 'total_patrimonio' => $patrimonio,
        'usd_sin_convertir' => $sinConvertir,
    ];
}
