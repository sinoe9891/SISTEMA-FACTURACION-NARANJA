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
try {
    $p = invProducto($pdo, $cid, (int)($_GET['producto_id'] ?? 0));
} catch (Exception $e) {
    header('Location: ./inventario');
    exit;
}
$st = $pdo->prepare("SELECT establecimiento_id AS id, nombre FROM establecimientos WHERE cliente_id = ? ORDER BY codigo_establecimiento, nombre");
$st->execute([$cid]);
$tiendas = $st->fetchAll(PDO::FETCH_ASSOC);
$tienda = (int)($_GET['establecimiento_id'] ?? 0);
if ($tienda && !in_array($tienda, array_map('intval', array_column($tiendas, 'id')), true)) $tienda = 0;

$sql = "SELECT m.*, e.nombre AS tienda, u.nombre AS usuario
        FROM inv_movimientos m
        JOIN establecimientos e ON e.establecimiento_id = m.establecimiento_id
        LEFT JOIN usuarios u ON u.id = m.usuario_id
        WHERE m.producto_id = ? AND m.cliente_id = ?" . ($tienda ? " AND m.establecimiento_id = ?" : "") . "
        ORDER BY m.fecha DESC, m.id DESC LIMIT 500";
$st = $pdo->prepare($sql);
$st->execute($tienda ? [$p['id'], $cid, $tienda] : [$p['id'], $cid]);
$movs = $st->fetchAll(PDO::FETCH_ASSOC);

$st = $pdo->prepare("SELECT e.nombre, x.cantidad, x.reservado FROM inv_existencias x JOIN establecimientos e ON e.establecimiento_id = x.establecimiento_id WHERE x.producto_id = ? ORDER BY e.nombre");
$st->execute([$p['id']]);
$exist = $st->fetchAll(PDO::FETCH_ASSOC);

$etq = [
    'entrada' => ['Entrada', 'success'], 'ajuste_entrada' => ['Ajuste +', 'info'], 'ajuste_salida' => ['Ajuste −', 'warning'],
    'venta' => ['Venta', 'danger'], 'anulacion_venta' => ['Devolución / anulación', 'success'],
    'traslado_salida' => ['Traslado sale', 'muted'], 'traslado_entrada' => ['Traslado entra', 'muted'],
];

require_once '../../includes/templates/header.php';
?>

<div class="app-page-header">
    <div>
        <a href="inventario" class="small text-muted text-decoration-none"><i class="bi bi-arrow-left"></i> Inventario</a>
        <h1 class="app-page-title"><?= htmlspecialchars($p['nombre']) ?></h1>
        <p class="app-page-sub font-monospace"><?= htmlspecialchars(trim(($p['sku'] ? 'SKU ' . $p['sku'] : '') . ($p['codigo_barras'] ? ' · ' . $p['codigo_barras'] : ''), ' ·')) ?> · costo promedio L <?= number_format((float)$p['costo'], 2) ?> · precio L <?= number_format((float)$p['precio'], 2) ?></p>
    </div>
</div>

<div class="row g-3 mb-3">
    <?php foreach ($exist as $e): ?>
        <div class="col-6 col-md-3"><div class="app-card app-kpi"><div class="app-kpi-label"><?= htmlspecialchars($e['nombre']) ?></div>
            <div class="app-kpi-value <?= (float)$e['cantidad'] <= (float)$p['stock_minimo'] ? 'text-warning' : '' ?>"><?= invFmt((float)$e['cantidad']) ?> <small class="fs-6 text-muted"><?= htmlspecialchars($p['unidad']) ?></small></div>
            <?php if ((float)$e['reservado'] > 0): ?><small class="text-muted"><?= invFmt((float)$e['reservado']) ?> reservado(s)</small><?php endif; ?></div></div>
    <?php endforeach; ?>
    <?php if (!$exist): ?><div class="col-12"><div class="alert alert-info mb-0">Sin existencias registradas todavía.</div></div><?php endif; ?>
</div>

<div class="app-card">
    <div class="app-card-header">
        <span><i class="bi bi-journal-text me-1"></i> Kardex (últimos 500 movimientos)</span>
        <form method="GET"><input type="hidden" name="producto_id" value="<?= (int)$p['id'] ?>">
            <select name="establecimiento_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="0">Todas las tiendas</option>
                <?php foreach ($tiendas as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $tienda === (int)$t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['nombre']) ?></option><?php endforeach; ?>
            </select></form>
    </div>
    <div class="table-responsive">
        <table class="table app-table">
            <thead><tr><th>Fecha</th><th>Tienda</th><th>Movimiento</th><th>Documento</th><th class="app-num">Entra</th><th class="app-num">Sale</th><th class="app-num">Saldo tienda</th><th>Usuario</th></tr></thead>
            <tbody>
                <?php if (!$movs): ?><tr><td colspan="8" class="text-center text-muted py-4">Sin movimientos.</td></tr><?php endif; ?>
                <?php foreach ($movs as $m): $suma = in_array($m['tipo'], INV_SUMA, true); ?>
                    <tr>
                        <td class="text-nowrap small"><?= date('d/m/Y H:i', strtotime($m['fecha'])) ?></td>
                        <td class="small"><?= htmlspecialchars($m['tienda']) ?></td>
                        <td><span class="app-badge app-badge-<?= $etq[$m['tipo']][1] ?>"><?= $etq[$m['tipo']][0] ?></span><?= $m['notas'] ? '<div class="small text-muted">' . htmlspecialchars($m['notas']) . '</div>' : '' ?></td>
                        <td class="small">
                            <?php if ($m['documento'] === 'factura' && $m['documento_id']): ?><a href="ver_factura?id=<?= (int)$m['documento_id'] ?>" target="_blank" class="font-monospace"><?= htmlspecialchars($m['referencia'] ?: '#' . $m['documento_id']) ?></a>
                            <?php elseif ($m['documento'] === 'traslado'): ?><a href="inventario_traslados#t<?= (int)$m['documento_id'] ?>">Traslado #<?= (int)$m['documento_id'] ?></a>
                            <?php else: ?><?= htmlspecialchars(ucfirst((string)$m['documento'])) ?><?= $m['referencia'] ? ' · ' . htmlspecialchars($m['referencia']) : '' ?><?php endif; ?>
                            <?= $m['costo_unitario'] !== null ? '<div class="text-muted">costo L ' . number_format((float)$m['costo_unitario'], 2) . '</div>' : '' ?>
                        </td>
                        <td class="app-num text-success"><?= $suma ? invFmt((float)$m['cantidad']) : '' ?></td>
                        <td class="app-num text-danger"><?= $suma ? '' : invFmt((float)$m['cantidad']) ?></td>
                        <td class="app-num fw-semibold"><?= invFmt((float)$m['saldo']) ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($m['usuario'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once '../../includes/templates/footer.php'; ?>
