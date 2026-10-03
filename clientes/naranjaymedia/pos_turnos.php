<?php
$titulo = 'Turnos de caja';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/pos.php';

$cid = cliente_actual();
$uid = (int)USUARIO_ID;
$esAdmin = in_array(USUARIO_ROL, ['admin', 'superadmin'], true);
$instalado = posDisponible($pdo);
$L = fn($v) => 'L ' . number_format((float)$v, 2);

$detalle = null;
$turnos = [];
if ($instalado && in_array(USUARIO_ROL, POS_ROLES_VENTA, true)) {
    if (!empty($_GET['id'])) {
        try {
            $t = posTurno($pdo, $cid, (int)$_GET['id']);
            if ($esAdmin || (int)$t['usuario_id'] === $uid) {
                $detalle = posResumen($pdo, $cid, (int)$t['id']);
                // Arqueo ciego: el cajero no ve el esperado mientras su turno sigue abierto
                if ($t['estado'] === 'abierto' && !$esAdmin) $detalle['efectivo_esperado'] = null;
                $st = $pdo->prepare("SELECT v.*, f.correlativo, f.estado AS estado_factura FROM pos_ventas v JOIN facturas f ON f.id = v.factura_id WHERE v.turno_id = ? ORDER BY v.id");
                $st->execute([$t['id']]);
                $detalle['lista'] = $st->fetchAll(PDO::FETCH_ASSOC);
                $st = $pdo->prepare("SELECT m.*, u.nombre AS usuario FROM pos_movimientos_caja m LEFT JOIN usuarios u ON u.id = m.usuario_id WHERE m.turno_id = ? ORDER BY m.id");
                $st->execute([$t['id']]);
                $detalle['movs'] = $st->fetchAll(PDO::FETCH_ASSOC);
                $st = $pdo->prepare("SELECT u.nombre AS cajero, e.nombre AS tienda, p.codigo_punto FROM pos_turnos t JOIN usuarios u ON u.id = t.usuario_id JOIN establecimientos e ON e.establecimiento_id = t.establecimiento_id JOIN puntos_emision p ON p.id = t.punto_emision_id WHERE t.id = ?");
                $st->execute([$t['id']]);
                $detalle += $st->fetch(PDO::FETCH_ASSOC);
            }
        } catch (Exception $e) {
            $detalle = null;
        }
    }
    $st = $pdo->prepare("
        SELECT t.*, u.nombre AS cajero, e.nombre AS tienda, p.codigo_punto,
               (SELECT COUNT(*) FROM pos_ventas v JOIN facturas f ON f.id = v.factura_id WHERE v.turno_id = t.id AND f.estado = 'emitida') AS ventas,
               (SELECT COALESCE(SUM(v.total), 0) FROM pos_ventas v JOIN facturas f ON f.id = v.factura_id WHERE v.turno_id = t.id AND f.estado = 'emitida') AS total
        FROM pos_turnos t JOIN usuarios u ON u.id = t.usuario_id
        JOIN establecimientos e ON e.establecimiento_id = t.establecimiento_id JOIN puntos_emision p ON p.id = t.punto_emision_id
        WHERE t.cliente_id = ?" . ($esAdmin ? "" : " AND t.usuario_id = ?") . "
        ORDER BY t.estado = 'abierto' DESC, t.abierto_en DESC LIMIT 200
    ");
    $st->execute($esAdmin ? [$cid] : [$cid, $uid]);
    $turnos = $st->fetchAll(PDO::FETCH_ASSOC);
}

require_once '../../includes/templates/header.php';
?>

<?php if ($detalle): $t = $detalle['turno']; $cerrado = $t['estado'] === 'cerrado'; ?>
    <div class="app-page-header no-print">
        <div><a href="pos_turnos" class="small text-muted text-decoration-none"><i class="bi bi-arrow-left"></i> Turnos</a>
            <h1 class="app-page-title"><?= $cerrado ? 'Corte Z' : 'Corte X (parcial)' ?> · turno #<?= (int)$t['id'] ?></h1></div>
        <button class="btn btn-outline-primary" onclick="window.print()"><i class="bi bi-printer me-1"></i> Imprimir</button>
    </div>
    <div class="app-card app-card-body mx-auto" style="max-width:640px">
        <div class="text-center mb-3">
            <div class="fw-bold"><?= htmlspecialchars($clienteNombre ?? '') ?></div>
            <div class="small text-muted"><?= htmlspecialchars($detalle['tienda']) ?> · Caja <?= htmlspecialchars($detalle['codigo_punto']) ?> · Cajero: <?= htmlspecialchars($detalle['cajero']) ?></div>
            <div class="small text-muted">Apertura <?= date('d/m/Y H:i', strtotime($t['abierto_en'])) ?><?= $cerrado ? ' · Cierre ' . date('d/m/Y H:i', strtotime($t['cerrado_en'])) : ' · (turno abierto)' ?></div>
        </div>
        <table class="table table-sm">
            <tr><td>Fondo inicial</td><td class="app-num"><?= $L($t['monto_inicial']) ?></td></tr>
            <tr><td>Ventas (<?= $detalle['ventas'] ?>)</td><td class="app-num fw-semibold"><?= $L($detalle['total_ventas']) ?></td></tr>
            <tr><td class="ps-4">Efectivo</td><td class="app-num"><?= $L($detalle['por_forma']['efectivo']) ?></td></tr>
            <tr><td class="ps-4">Tarjeta</td><td class="app-num"><?= $L($detalle['por_forma']['tarjeta']) ?></td></tr>
            <tr><td class="ps-4">Transferencia</td><td class="app-num"><?= $L($detalle['por_forma']['transferencia']) ?></td></tr>
            <tr><td>Entradas de efectivo</td><td class="app-num"><?= $L($detalle['entradas']) ?></td></tr>
            <tr><td>Retiros de efectivo</td><td class="app-num">− <?= $L($detalle['retiros']) ?></td></tr>
            <tr><td>Ventas anuladas (<?= $detalle['anuladas'] ?>)</td><td class="app-num text-muted"><?= $L($detalle['total_anuladas']) ?></td></tr>
            <?php if ($detalle['efectivo_esperado'] !== null): ?><tr class="fw-bold"><td>Efectivo esperado</td><td class="app-num"><?= $L($detalle['efectivo_esperado']) ?></td></tr><?php endif; ?>
            <?php if ($cerrado): ?>
                <tr class="fw-bold"><td>Efectivo contado</td><td class="app-num"><?= $L($t['efectivo_contado']) ?></td></tr>
                <tr class="fw-bold <?= abs((float)$t['diferencia']) < 0.005 ? 'text-success' : 'text-danger' ?>"><td>Diferencia</td><td class="app-num"><?= $L($t['diferencia']) ?> <?= (float)$t['diferencia'] > 0 ? '(sobrante)' : ((float)$t['diferencia'] < 0 ? '(faltante)' : '') ?></td></tr>
                <?php if ($t['justificacion']): ?><tr><td colspan="2" class="small">Justificación: <?= htmlspecialchars($t['justificacion']) ?></td></tr><?php endif; ?>
            <?php endif; ?>
        </table>
        <?php if ($detalle['movs']): ?>
            <h6 class="mt-3">Movimientos de efectivo</h6>
            <table class="table table-sm small"><?php foreach ($detalle['movs'] as $m): ?><tr><td><?= date('H:i', strtotime($m['creado_en'])) ?></td><td><?= $m['tipo'] === 'retiro' ? 'Retiro' : 'Entrada' ?> · <?= htmlspecialchars($m['motivo']) ?></td><td class="app-num"><?= $L($m['monto']) ?></td></tr><?php endforeach; ?></table>
        <?php endif; ?>
        <h6 class="mt-3">Ventas</h6>
        <table class="table table-sm small">
            <?php foreach ($detalle['lista'] as $v): ?><tr class="<?= $v['estado_factura'] !== 'emitida' ? 'text-decoration-line-through text-muted' : '' ?>"><td><?= date('H:i', strtotime($v['creado_en'])) ?></td><td><a href="ver_factura?id=<?= (int)$v['factura_id'] ?>" target="_blank" class="font-monospace"><?= htmlspecialchars($v['correlativo']) ?></a></td><td class="app-num"><?= $L($v['total']) ?></td></tr><?php endforeach; ?>
            <?php if (!$detalle['lista']): ?><tr><td class="text-muted">Sin ventas.</td></tr><?php endif; ?>
        </table>
    </div>
<?php else: ?>
    <div class="app-page-header">
        <div><h1 class="app-page-title">Turnos de caja</h1><p class="app-page-sub"><?= $esAdmin ? 'Todos los turnos de la empresa.' : 'Tus turnos.' ?></p></div>
        <a href="pos" class="btn btn-primary"><i class="bi bi-cart3 me-1"></i> Ir al punto de venta</a>
    </div>
    <?php if (!$instalado): ?><div class="alert alert-warning">El punto de venta no está instalado.</div><?php endif; ?>
    <div class="app-card"><div class="table-responsive"><table class="table app-table">
        <thead><tr><th>#</th><th>Tienda / caja</th><th>Cajero</th><th>Apertura</th><th>Cierre</th><th class="app-num">Ventas</th><th class="app-num">Total</th><th class="app-num">Diferencia</th><th></th></tr></thead>
        <tbody>
            <?php if (!$turnos): ?><tr><td colspan="9" class="text-center text-muted py-4">Sin turnos.</td></tr><?php endif; ?>
            <?php foreach ($turnos as $t): ?>
                <tr>
                    <td><?= (int)$t['id'] ?></td>
                    <td><?= htmlspecialchars($t['tienda']) ?> · caja <?= htmlspecialchars($t['codigo_punto']) ?></td>
                    <td><?= htmlspecialchars($t['cajero']) ?></td>
                    <td class="text-nowrap small"><?= date('d/m/Y H:i', strtotime($t['abierto_en'])) ?></td>
                    <td class="text-nowrap small"><?= $t['cerrado_en'] ? date('d/m/Y H:i', strtotime($t['cerrado_en'])) : '<span class="app-badge app-badge-success">Abierto</span>' ?></td>
                    <td class="app-num"><?= (int)$t['ventas'] ?></td>
                    <td class="app-num"><?= $L($t['total']) ?></td>
                    <td class="app-num <?= $t['diferencia'] !== null && abs((float)$t['diferencia']) >= 0.005 ? 'text-danger fw-semibold' : '' ?>"><?= $t['diferencia'] !== null ? $L($t['diferencia']) : '—' ?></td>
                    <td class="text-end"><a href="pos_turnos?id=<?= (int)$t['id'] ?>" class="btn btn-sm btn-outline-secondary"><?= $t['estado'] === 'cerrado' ? 'Corte Z' : 'Corte X' ?></a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div></div>
<?php endif; ?>

<style>@media print { .no-print { display: none !important; } }</style>
<?php require_once '../../includes/templates/footer.php'; ?>
