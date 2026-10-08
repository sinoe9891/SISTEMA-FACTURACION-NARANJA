<?php
// Estados financieros en formato clásico (solo lectura): Estado de resultados y Balance general.
//   ?tab=resultados&desde=…&hasta=…   ·   ?tab=balance&corte=…&tasa=…
// balance_general.php incluye esta página con la pestaña Balance general.
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/estados_financieros.php';

$cid = (int)cliente_actual();
$fechaOk = fn($v, $d) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v)) ? $v : $d;
$tab = ($tabForzada ?? null) ?: (($_GET['tab'] ?? '') === 'balance' ? 'balance' : 'resultados');
$titulo = $tab === 'balance' ? 'Balance general' : 'Estado de resultados clásico';

$desde = $fechaOk($_GET['desde'] ?? null, date('Y-01-01'));
$hasta = $fechaOk($_GET['hasta'] ?? null, date('Y-m-d'));
if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];
$corte = $fechaOk($_GET['corte'] ?? null, date('Y-m-d'));
$tasa = isset($_GET['tasa']) && is_numeric($_GET['tasa']) ? max(0, (float)$_GET['tasa']) : efTasaReciente($pdo, $cid);

$emp = $pdo->prepare("SELECT nombre, alias, rtn FROM clientes_saas WHERE id = ?");
$emp->execute([$cid]);
$emp = $emp->fetch(PDO::FETCH_ASSOC) ?: ['nombre' => '', 'alias' => '', 'rtn' => ''];

$meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$fechaLarga = fn($f) => (int)date('j', strtotime($f)) . ' de ' . $meses[(int)date('n', strtotime($f))] . ' de ' . date('Y', strtotime($f));
$L = fn($v) => $v < 0 ? '(' . number_format(abs($v), 2) . ')' : number_format($v, 2);
$e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');

// Atajos de período
$hoy = new DateTime('today');
$rangos = [
    'Este mes' => [$hoy->format('Y-m-01'), $hoy->format('Y-m-d')],
    'Mes anterior' => [(new DateTime('first day of last month'))->format('Y-m-d'), (new DateTime('last day of last month'))->format('Y-m-d')],
    'Este año' => [$hoy->format('Y-01-01'), $hoy->format('Y-m-d')],
    'Año anterior' => [($hoy->format('Y') - 1) . '-01-01', ($hoy->format('Y') - 1) . '-12-31'],
];

if ($tab === 'resultados') $r = efResultados($pdo, $cid, $desde, $hasta);
else $b = efBalance($pdo, $cid, $corte, $tasa);

require_once '../../includes/templates/header.php';
?>

<div class="app-page-header no-print">
    <div>
        <h1 class="app-page-title"><i class="bi bi-journal-text me-2"></i>Estados financieros</h1>
        <p class="app-page-sub">Formato clásico, solo para consulta. Se calcula con lo registrado en el sistema.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i> Imprimir / PDF</button>
        <a href="financiero" class="btn btn-outline-primary"><i class="bi bi-graph-up me-1"></i> Gráficos de resultados</a>
    </div>
</div>

<ul class="nav nav-tabs mb-3 no-print">
    <li class="nav-item"><a class="nav-link <?= $tab === 'resultados' ? 'active' : '' ?>" href="estados_financieros"><i class="bi bi-bar-chart-steps me-1"></i> Estado de resultados</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'balance' ? 'active' : '' ?>" href="balance_general"><i class="bi bi-bank me-1"></i> Balance general</a></li>
</ul>

<!-- Filtros -->
<form class="app-card app-card-body mb-3 no-print" method="get" action="<?= $tab === 'balance' ? 'balance_general' : 'estados_financieros' ?>">
    <div class="row g-2 align-items-end">
        <?php if ($tab === 'resultados'): ?>
            <div class="col-6 col-md-2"><label class="form-label small">Desde</label><input type="date" class="form-control form-control-sm" name="desde" value="<?= $desde ?>"></div>
            <div class="col-6 col-md-2"><label class="form-label small">Hasta</label><input type="date" class="form-control form-control-sm" name="hasta" value="<?= $hasta ?>"></div>
            <div class="col-md-auto"><button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i> Ver</button></div>
            <div class="col-md d-flex flex-wrap gap-1 justify-content-md-end">
                <?php foreach ($rangos as $txt => [$d, $h]): ?>
                    <a class="btn btn-sm <?= $d === $desde && $h === $hasta ? 'btn-secondary' : 'btn-outline-secondary' ?>" href="estados_financieros?desde=<?= $d ?>&hasta=<?= $h ?>"><?= $txt ?></a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="col-6 col-md-2"><label class="form-label small">Al</label><input type="date" class="form-control form-control-sm" name="corte" value="<?= $corte ?>"></div>
            <div class="col-6 col-md-2"><label class="form-label small">Tasa L por US$1</label><input type="number" step="0.0001" min="0" class="form-control form-control-sm" name="tasa" value="<?= $tasa > 0 ? $tasa : '' ?>" placeholder="para cuentas en dólares"></div>
            <div class="col-md-auto"><button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i> Ver</button></div>
            <div class="col-md d-flex flex-wrap gap-1 justify-content-md-end">
                <a class="btn btn-sm btn-outline-secondary" href="balance_general">Hoy</a>
                <a class="btn btn-sm btn-outline-secondary" href="balance_general?corte=<?= (new DateTime('last day of last month'))->format('Y-m-d') ?>">Cierre del mes anterior</a>
                <a class="btn btn-sm btn-outline-secondary" href="balance_general?corte=<?= $hoy->format('Y') - 1 ?>-12-31">Cierre del año anterior</a>
            </div>
        <?php endif; ?>
    </div>
</form>

<div class="app-card app-estado">
    <div class="app-estado-head">
        <div class="app-estado-empresa"><?= $e($emp['nombre'] ?: $emp['alias']) ?></div>
        <?php if (!empty($emp['rtn'])): ?><div class="app-estado-sub">RTN <?= $e($emp['rtn']) ?></div><?php endif; ?>
        <div class="app-estado-titulo"><?= $tab === 'balance' ? 'Balance general' : 'Estado de resultados' ?></div>
        <div class="app-estado-sub"><?= $tab === 'balance' ? 'Al ' . $fechaLarga($corte) : 'Del ' . $fechaLarga($desde) . ' al ' . $fechaLarga($hasta) ?></div>
        <div class="app-estado-sub">Expresado en lempiras (L)</div>
    </div>

    <table class="app-estado-tabla">
        <colgroup><col><col class="app-estado-col"><col class="app-estado-col"></colgroup>
        <?php if ($tab === 'resultados'): ?>
            <tr class="app-estado-sec"><td colspan="3">Ingresos</td></tr>
            <tr><td class="app-estado-i1">Ventas gravadas</td><td><?= $L($r['gravado']) ?></td><td></td></tr>
            <tr><td class="app-estado-i1">Ventas exentas y exoneradas</td><td class="<?= $r['n_recibos'] ? '' : 'app-estado-sub-l' ?>"><?= $L($r['ventas_facturas'] - $r['gravado']) ?></td><td></td></tr>
            <?php if ($r['n_recibos']): ?><tr><td class="app-estado-i1">Ingresos con recibo <span class="app-estado-nota">(contratos sin factura)</span></td><td class="app-estado-sub-l"><?= $L($r['recibos']) ?></td><td></td></tr><?php endif; ?>
            <tr class="app-estado-tot"><td>Total de ingresos <span class="app-estado-nota">(<?= $r['facturas'] ?> factura<?= $r['facturas'] === 1 ? '' : 's' ?><?= $r['n_recibos'] ? ', ' . $r['n_recibos'] . ' recibo' . ($r['n_recibos'] === 1 ? '' : 's') : '' ?>, sin ISV)</span></td><td></td><td><?= $L($r['ventas']) ?></td></tr>

            <tr class="app-estado-sec"><td colspan="3">Gastos de operación</td></tr>
            <?php foreach ($r['gastos'] as $i => $g): ?>
                <tr><td class="app-estado-i1"><?= $e($g['nombre']) ?></td><td class="<?= $i === count($r['gastos']) - 1 ? 'app-estado-sub-l' : '' ?>"><?= $L($g['total']) ?></td><td></td></tr>
            <?php endforeach; ?>
            <?php if (!$r['gastos']): ?><tr><td class="app-estado-i1 text-muted">Sin gastos en el período</td><td class="app-estado-sub-l">0.00</td><td></td></tr><?php endif; ?>
            <tr class="app-estado-tot"><td>Total de gastos</td><td></td><td class="app-estado-sub-l"><?= $L($r['total_gastos']) ?></td></tr>

            <tr class="app-estado-final <?= $r['utilidad'] < 0 ? 'neg' : '' ?>"><td><?= $r['utilidad'] < 0 ? 'Pérdida del período' : 'Utilidad del período' ?></td><td></td><td><?= $L($r['utilidad']) ?></td></tr>
            <?php if ($r['ventas'] > 0): ?><tr><td class="app-estado-nota" colspan="3">Margen: <?= number_format($r['utilidad'] / $r['ventas'] * 100, 1) ?>% de los ingresos.</td></tr><?php endif; ?>
        <?php else: ?>
            <?php $grupo = function (array $grupos, string $totalTxt, float $total) use ($L, $e) {
                foreach ($grupos as $nombre => $lineas) {
                    echo '<tr class="app-estado-grp"><td colspan="3">' . $e($nombre) . '</td></tr>';
                    if (!$lineas) echo '<tr><td class="app-estado-i2 text-muted">Sin saldo</td><td class="app-estado-sub-l">0.00</td><td>0.00</td></tr>';
                    foreach ($lineas as $i => $l) echo '<tr><td class="app-estado-i2">' . $e($l['nombre']) . '</td><td class="' . ($i === count($lineas) - 1 ? 'app-estado-sub-l' : '') . '">' . $L($l['monto']) . '</td><td>' . ($i === count($lineas) - 1 ? $L(array_sum(array_column($lineas, 'monto'))) : '') . '</td></tr>';
                }
                echo '<tr class="app-estado-tot"><td>' . $totalTxt . '</td><td></td><td class="app-estado-dbl">' . $L($total) . '</td></tr>';
            }; ?>
            <tr class="app-estado-sec"><td colspan="3">Activo</td></tr>
            <?php $grupo($b['activo'], 'Total activo', $b['total_activo']); ?>

            <tr class="app-estado-sec"><td colspan="3">Pasivo</td></tr>
            <?php $grupo($b['pasivo'], 'Total pasivo', $b['total_pasivo']); ?>

            <tr class="app-estado-sec"><td colspan="3">Patrimonio</td></tr>
            <tr><td class="app-estado-i2">Utilidad del ejercicio <span class="app-estado-nota">(1 de enero al corte)</span></td><td><?= $L($b['utilidad_ejercicio']) ?></td><td></td></tr>
            <?php if (!empty($b['aportes_socios']) || !empty($b['retiros_socios'])): ?>
            <tr><td class="app-estado-i2">Aportes de socios <span class="app-estado-nota">(acumulados al corte)</span></td><td><?= $L($b['aportes_socios']) ?></td><td></td></tr>
            <tr><td class="app-estado-i2">Retiros de socios <span class="app-estado-nota">(acumulados al corte)</span></td><td>− <?= $L($b['retiros_socios']) ?></td><td></td></tr>
            <tr><td class="app-estado-i2">Capital, resultados anteriores y ajustes</td><td class="app-estado-sub-l"><?= $L($b['patrimonio_anterior'] - $b['aportes_socios'] + $b['retiros_socios']) ?></td><td></td></tr>
            <?php else: ?>
            <tr><td class="app-estado-i2">Capital, resultados anteriores y ajustes</td><td class="app-estado-sub-l"><?= $L($b['patrimonio_anterior']) ?></td><td></td></tr>
            <?php endif; ?>
            <tr class="app-estado-tot"><td>Total patrimonio</td><td></td><td class="app-estado-sub-l"><?= $L($b['total_patrimonio']) ?></td></tr>

            <tr class="app-estado-final"><td>Total pasivo más patrimonio</td><td></td><td><?= $L($b['total_pasivo'] + $b['total_patrimonio']) ?></td></tr>
        <?php endif; ?>
    </table>

    <div class="app-estado-notas">
        <?php if ($tab === 'resultados'): ?>
            <p><strong>Cómo se calcula.</strong> Ingresos: subtotal de las facturas emitidas en el período (sin ISV; las anuladas no cuentan). Gastos: gastos registrados con fecha en el período, por categoría, incluidos sueldos (los anulados no cuentan).</p>
            <p>El ISV cobrado en el período (L <?= number_format($r['isv'], 2) ?>) no es ingreso: se declara y se paga al SAR.</p>
        <?php else: ?>
            <p><strong>Cómo se calcula.</strong> Efectivo: saldo de cada cuenta bancaria a la fecha. Cuentas por cobrar: facturas emitidas hasta la fecha menos los abonos recibidos hasta esa fecha. Cuentas por pagar: gastos registrados como pendientes. ISV: el de facturas que aún no se marcan como declaradas.</p>
            <p>El patrimonio es la diferencia entre activo y pasivo. Los aportes y retiros de socios se registran en «Socios: aportes y retiros». «Capital, resultados anteriores y ajustes» incluye lo que el sistema no registra: dinero que no pasó por las cuentas bancarias y activos fijos (mobiliario y equipo se registran como gasto).</p>
            <?php foreach ($b['usd_sin_convertir'] as $u): ?><p class="text-danger">La cuenta <?= $e($u['nombre']) ?> tiene US$ <?= number_format($u['usd'], 2) ?> que no se sumaron: escribe la tasa de cambio arriba.</p><?php endforeach; ?>
        <?php endif; ?>
        <p class="app-estado-pie">Generado el <?= date('d/m/Y g:i a') ?> · Los números entre paréntesis son negativos.</p>
    </div>
</div>

<?php require_once '../../includes/templates/footer.php'; ?>
