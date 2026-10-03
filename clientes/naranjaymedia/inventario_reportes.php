<?php
$titulo = 'Inventario';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/inventario.php';

$cid = cliente_actual();
if (!invDisponible($pdo)) {
    header('Location: ./inventario');
    exit;
}
$dias = (int)($_GET['dias'] ?? 30);
if (!in_array($dias, [7, 30, 90, 365], true)) $dias = 30;

// Valor del inventario por tienda (al costo promedio)
$st = $pdo->prepare("
    SELECT e.nombre, COUNT(DISTINCT CASE WHEN x.cantidad > 0 THEN x.producto_id END) AS productos,
           COALESCE(SUM(x.cantidad), 0) AS unidades, COALESCE(SUM(x.cantidad * p.costo), 0) AS valor,
           COALESCE(SUM(x.cantidad * p.precio), 0) AS valor_venta
    FROM establecimientos e
    LEFT JOIN inv_existencias x ON x.establecimiento_id = e.establecimiento_id
    LEFT JOIN productos_clientes p ON p.id = x.producto_id AND p.tipo = 'bien'
    WHERE e.cliente_id = ? GROUP BY e.establecimiento_id ORDER BY valor DESC
");
$st->execute([$cid]);
$valorTiendas = $st->fetchAll(PDO::FETCH_ASSOC);

// Más vendidos en el período (ventas netas de anulaciones)
$st = $pdo->prepare("
    SELECT p.id, p.nombre, p.unidad, p.precio, p.costo,
           SUM(CASE WHEN m.tipo = 'venta' THEN m.cantidad ELSE -m.cantidad END) AS vendido
    FROM inv_movimientos m JOIN productos_clientes p ON p.id = m.producto_id
    WHERE m.cliente_id = ? AND m.tipo IN ('venta', 'anulacion_venta') AND m.fecha >= (NOW() - INTERVAL ? DAY)
    GROUP BY p.id HAVING vendido > 0 ORDER BY vendido DESC LIMIT 20
");
$st->execute([$cid, $dias]);
$masVendidos = $st->fetchAll(PDO::FETCH_ASSOC);

// Sin ventas en el período pero con existencia (capital detenido)
$st = $pdo->prepare("
    SELECT p.id, p.nombre, p.unidad, p.costo, SUM(x.cantidad) AS existencia,
           (SELECT MAX(m.fecha) FROM inv_movimientos m WHERE m.producto_id = p.id AND m.tipo = 'venta') AS ultima_venta
    FROM productos_clientes p JOIN inv_existencias x ON x.producto_id = p.id
    WHERE p.cliente_id = ? AND p.tipo = 'bien' AND p.activo = 1
    GROUP BY p.id
    HAVING existencia > 0 AND (ultima_venta IS NULL OR ultima_venta < (NOW() - INTERVAL ? DAY))
    ORDER BY SUM(x.cantidad) * p.costo DESC LIMIT 50
");
$st->execute([$cid, $dias]);
$sinMovimiento = $st->fetchAll(PDO::FETCH_ASSOC);

// Por reponer: bajo mínimo en alguna tienda
$st = $pdo->prepare("
    SELECT p.nombre, p.unidad, p.stock_minimo, e.nombre AS tienda, COALESCE(x.cantidad, 0) AS cantidad
    FROM productos_clientes p
    CROSS JOIN establecimientos e
    LEFT JOIN inv_existencias x ON x.producto_id = p.id AND x.establecimiento_id = e.establecimiento_id
    WHERE p.cliente_id = ? AND e.cliente_id = p.cliente_id AND p.tipo = 'bien' AND p.activo = 1
      AND COALESCE(x.cantidad, 0) <= p.stock_minimo
      AND (p.stock_minimo > 0 OR x.producto_id IS NOT NULL)
    ORDER BY COALESCE(x.cantidad, 0) = 0 DESC, p.nombre, e.nombre LIMIT 100
");
$st->execute([$cid]);
$reponer = $st->fetchAll(PDO::FETCH_ASSOC);

$totValor = array_sum(array_column($valorTiendas, 'valor'));
$totVenta = array_sum(array_column($valorTiendas, 'valor_venta'));

require_once '../../includes/templates/header.php';
?>

<div class="app-page-header">
    <div>
        <a href="inventario" class="small text-muted text-decoration-none"><i class="bi bi-arrow-left"></i> Inventario</a>
        <h1 class="app-page-title">Reportes de inventario</h1>
        <p class="app-page-sub">Valor por tienda, productos más vendidos, sin movimiento y por reponer.</p>
    </div>
    <form method="GET" class="d-flex align-items-center gap-2">
        <label class="small text-muted" for="dias">Período</label>
        <select name="dias" id="dias" class="form-select form-select-sm" onchange="this.form.submit()">
            <?php foreach ([7 => '7 días', 30 => '30 días', 90 => '90 días', 365 => '1 año'] as $d => $t): ?><option value="<?= $d ?>" <?= $d === $dias ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?>
        </select>
    </form>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-xl-3"><div class="app-card app-kpi"><div class="app-kpi-label">Valor al costo</div><div class="app-kpi-value">L <?= number_format($totValor, 2) ?></div></div></div>
    <div class="col-6 col-xl-3"><div class="app-card app-kpi"><div class="app-kpi-label">Valor a precio de venta</div><div class="app-kpi-value">L <?= number_format($totVenta, 2) ?></div></div></div>
    <div class="col-6 col-xl-3"><div class="app-card app-kpi"><div class="app-kpi-label">Margen potencial</div><div class="app-kpi-value text-success">L <?= number_format($totVenta - $totValor, 2) ?></div></div></div>
    <div class="col-6 col-xl-3"><div class="app-card app-kpi"><div class="app-kpi-label">Por reponer</div><div class="app-kpi-value <?= $reponer ? 'text-warning' : '' ?>"><?= count($reponer) ?></div></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="app-card h-100">
            <div class="app-card-header"><span><i class="bi bi-shop me-1"></i> Valor por tienda</span></div>
            <div class="table-responsive"><table class="table app-table">
                <thead><tr><th>Tienda</th><th class="app-num">Productos</th><th class="app-num">Unidades</th><th class="app-num">Valor costo</th></tr></thead>
                <tbody><?php foreach ($valorTiendas as $t): ?><tr><td><?= htmlspecialchars($t['nombre']) ?></td><td class="app-num"><?= (int)$t['productos'] ?></td><td class="app-num"><?= invFmt((float)$t['unidades']) ?></td><td class="app-num fw-semibold">L <?= number_format((float)$t['valor'], 2) ?></td></tr><?php endforeach; ?></tbody>
            </table></div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="app-card h-100">
            <div class="app-card-header"><span><i class="bi bi-cart-check me-1"></i> Por reponer (en o bajo el mínimo)</span></div>
            <div class="table-responsive"><table class="table app-table">
                <thead><tr><th>Producto</th><th>Tienda</th><th class="app-num">Existencia</th><th class="app-num">Mínimo</th></tr></thead>
                <tbody>
                    <?php if (!$reponer): ?><tr><td colspan="4" class="text-center text-muted py-4">Nada por reponer.</td></tr><?php endif; ?>
                    <?php foreach ($reponer as $r): ?><tr><td><?= htmlspecialchars($r['nombre']) ?></td><td class="small"><?= htmlspecialchars($r['tienda']) ?></td><td class="app-num <?= (float)$r['cantidad'] <= 0 ? 'text-danger' : 'text-warning' ?>"><?= invFmt((float)$r['cantidad']) ?></td><td class="app-num text-muted"><?= invFmt((float)$r['stock_minimo']) ?></td></tr><?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="app-card h-100">
            <div class="app-card-header"><span><i class="bi bi-graph-up-arrow me-1"></i> Más vendidos (<?= $dias ?> días)</span></div>
            <div class="table-responsive"><table class="table app-table">
                <thead><tr><th>Producto</th><th class="app-num">Vendido</th><th class="app-num">Ingreso aprox.</th></tr></thead>
                <tbody>
                    <?php if (!$masVendidos): ?><tr><td colspan="3" class="text-center text-muted py-4">Sin ventas en el período.</td></tr><?php endif; ?>
                    <?php foreach ($masVendidos as $m): ?><tr><td><a href="inventario_kardex?producto_id=<?= (int)$m['id'] ?>"><?= htmlspecialchars($m['nombre']) ?></a></td><td class="app-num"><?= invFmt((float)$m['vendido']) ?> <small class="text-muted"><?= htmlspecialchars($m['unidad']) ?></small></td><td class="app-num">L <?= number_format((float)$m['vendido'] * (float)$m['precio'], 2) ?></td></tr><?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="app-card h-100">
            <div class="app-card-header"><span><i class="bi bi-hourglass-split me-1"></i> Sin ventas en <?= $dias ?> días (con existencia)</span></div>
            <div class="table-responsive"><table class="table app-table">
                <thead><tr><th>Producto</th><th class="app-num">Existencia</th><th class="app-num">Valor detenido</th><th>Última venta</th></tr></thead>
                <tbody>
                    <?php if (!$sinMovimiento): ?><tr><td colspan="4" class="text-center text-muted py-4">Todo se está moviendo.</td></tr><?php endif; ?>
                    <?php foreach ($sinMovimiento as $s): ?><tr><td><a href="inventario_kardex?producto_id=<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['nombre']) ?></a></td><td class="app-num"><?= invFmt((float)$s['existencia']) ?></td><td class="app-num">L <?= number_format((float)$s['existencia'] * (float)$s['costo'], 2) ?></td><td class="small text-muted"><?= $s['ultima_venta'] ? date('d/m/Y', strtotime($s['ultima_venta'])) : 'Nunca' ?></td></tr><?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </div>
</div>

<?php require_once '../../includes/templates/footer.php'; ?>
