<?php
$titulo = 'Cuentas por pagar';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/cuentas.php';

$cid = cliente_actual();
$gastos = $cid ? cxpPendientes($pdo, $cid) : [];
$cuentasHnl = bancosDisponible($pdo) ? array_values(array_filter(bancoCuentas($pdo, $cid, true), fn($c) => $c['moneda'] === 'HNL')) : [];

$grupos = ['vencido' => 0.0, 'hoy_7' => 0.0, '8_30' => 0.0, 'mas_30' => 0.0];
$porProveedor = [];
foreach ($gastos as &$g) {
    $d = (int)$g['dias'];                       // > 0 = días de atraso; <= 0 = faltan -d días
    $g['grupo'] = $d > 0 ? 'vencido' : ($d >= -7 ? 'hoy_7' : ($d >= -30 ? '8_30' : 'mas_30'));
    $grupos[$g['grupo']] += (float)$g['monto'];
    $prov = trim((string)$g['proveedor']) ?: 'Sin proveedor';
    $porProveedor[$prov] ??= ['n' => 0, 'total' => 0.0, 'vencido' => 0.0];
    $porProveedor[$prov]['n']++;
    $porProveedor[$prov]['total'] += (float)$g['monto'];
    if ($d > 0) $porProveedor[$prov]['vencido'] += (float)$g['monto'];
}
unset($g);
uasort($porProveedor, fn($a, $b) => $b['vencido'] <=> $a['vencido'] ?: $b['total'] <=> $a['total']);
$total = array_sum($grupos);
$L = fn($v) => 'L ' . number_format((float)$v, 2);
$etq = ['vencido' => ['Vencido', 'danger'], 'hoy_7' => ['Próximos 7 días', 'warning'], '8_30' => ['8 a 30 días', 'info'], 'mas_30' => ['Más de 30 días', 'muted']];

require_once '../../includes/templates/header.php';
?>

<div class="app-page-header">
    <div>
        <h1 class="app-page-title">Cuentas por pagar</h1>
        <p class="app-page-sub">Gastos pendientes (proveedores, servicios, préstamos, salarios) según su fecha de pago.</p>
    </div>
    <a href="gastos" class="btn btn-outline-primary"><i class="bi bi-wallet2 me-1"></i> Ir a Gastos</a>
</div>

<?php $iconoGrupo = ['vencido' => ['bi-exclamation-octagon', 'red'], 'hoy_7' => ['bi-alarm', 'amber'], '8_30' => ['bi-calendar-week', 'teal'], 'mas_30' => ['bi-calendar3', 'gray']]; ?>
<div class="app-stats">
    <div class="app-stat"><div class="app-stat-icon"><i class="bi bi-wallet2"></i></div>
        <div><div class="app-stat-val"><?= $L($total) ?></div><div class="app-stat-lbl">Total por pagar · <?= count($gastos) ?> pago(s)</div></div></div>
    <?php foreach ($grupos as $k => $v): ?>
        <div class="app-stat"><div class="app-stat-icon <?= $v > 0 ? $iconoGrupo[$k][1] : 'gray' ?>"><i class="bi <?= $iconoGrupo[$k][0] ?>"></i></div>
            <div><div class="app-stat-val <?= $v > 0 ? 'text-' . $etq[$k][1] : 'text-muted' ?>"><?= $L($v) ?></div><div class="app-stat-lbl"><?= $etq[$k][0] ?></div></div></div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <div class="col-12">
        <div class="app-card h-100">
            <div class="app-card-header"><span><i class="bi bi-truck me-1"></i> Por proveedor</span></div>
            <div class="table-responsive">
                <table class="table app-table">
                    <thead><tr><th class="app-n">#</th><th>Proveedor</th><th class="app-num">Vencido</th><th class="app-num">Total</th></tr></thead>
                    <tbody>
                        <?php if (!$porProveedor): ?><tr><td colspan="4" class="text-center text-muted py-4">Sin pendientes.</td></tr><?php endif; ?>
                        <?php $nProv = 0; foreach ($porProveedor as $p => $v): ?>
                            <tr><td class="app-n"><?= ++$nProv ?></td><td><?= htmlspecialchars($p) ?><div class="small text-muted"><?= $v['n'] ?> pago(s)</div></td>
                                <td class="app-num <?= $v['vencido'] > 0 ? 'text-danger' : 'text-muted' ?>"><?= $v['vencido'] > 0 ? number_format($v['vencido'], 2) : '—' ?></td>
                                <td class="app-num fw-semibold"><?= number_format($v['total'], 2) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="app-card">
            <div class="app-card-header flex-wrap">
                <span><i class="bi bi-calendar-event me-1"></i> Calendario de pagos</span>
                <div class="app-toolbar flex-grow-1 justify-content-end">
                    <div class="app-search" style="max-width:280px"><i class="bi bi-search"></i><input type="search" class="form-control form-control-sm" id="buscarPago" placeholder="Buscar gasto o proveedor…"></div>
                    <select class="form-select form-select-sm" id="porPagina" style="width:auto"><option value="10">10/pág</option><option value="25">25/pág</option><option value="50">50/pág</option></select>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table app-table" id="tablaPagos">
                    <thead><tr><th class="app-n">#</th><th>Fecha</th><th>Descripción</th><th>Proveedor</th><th class="app-num">Monto</th><th>Estado</th><th class="text-end"></th></tr></thead>
                    <tbody>
                        <?php foreach ($gastos as $g): $d = (int)$g['dias']; ?>
                            <tr data-fila>
                                <td class="app-n"></td>
                                <td class="text-nowrap"><?= date('d/m/Y', strtotime($g['fecha'])) ?></td>
                                <td><a href="gasto_ver?id=<?= (int)$g['id'] ?>"><?= htmlspecialchars($g['descripcion']) ?></a><?= $g['frecuencia'] !== 'unico' ? ' <span class="app-badge app-badge-muted">' . htmlspecialchars(ucfirst($g['frecuencia'])) . '</span>' : '' ?><div class="small text-muted"><?= htmlspecialchars($g['categoria'] ?? '') ?></div></td>
                                <td class="small"><?= htmlspecialchars($g['proveedor'] ?? '') ?></td>
                                <td class="app-num fw-semibold"><?= number_format((float)$g['monto'], 2) ?></td>
                                <td class="text-nowrap"><span class="app-badge app-badge-<?= $etq[$g['grupo']][1] ?>"><?= $d > 0 ? "Vencido hace $d d" : ($d === 0 ? 'Vence hoy' : 'En ' . -$d . ' d') ?></span></td>
                                <td class="text-end"><button class="btn btn-sm btn-success btn-pagar" data-id="<?= (int)$g['id'] ?>" data-desc="<?= htmlspecialchars($g['descripcion']) ?>" data-monto="<?= number_format((float)$g['monto'], 2, '.', '') ?>"><i class="bi bi-check-lg"></i> Pagar</button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="app-pager" id="pagosPie"></div>
        </div>
    </div>
</div>

<script src="../../clientes/js/app-tabla.js?v=<?= @filemtime(__DIR__ . '/../js/app-tabla.js') ?>"></script>
<script>
(function () {
    AppTabla('#tablaPagos', { buscar: '#buscarPago', porPagina: '#porPagina', pie: '#pagosPie', vacio: 'Todo al día: no hay pagos pendientes.' });
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const cuentas = <?= json_encode(array_map(fn($c) => ['id' => (int)$c['id'], 'txt' => $c['banco'] . ' ' . $c['numero'], 'pred' => !empty($c['predeterminada'])], $cuentasHnl), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    document.querySelectorAll('.btn-pagar').forEach(b => b.addEventListener('click', () => {
        Swal.fire({
            title: 'Pagar gasto',
            html: `<div class="text-start">
                <div class="mb-2"><strong>${esc(b.dataset.desc)}</strong> · L ${Number(b.dataset.monto).toLocaleString('es-HN', { minimumFractionDigits: 2 })}</div>
                <label class="form-label">Fecha de pago</label><input type="date" id="pFecha" class="form-control" value="${new Date().toLocaleDateString('sv-SE')}">
                <label class="form-label mt-2">Método</label><select id="pMetodo" class="form-select"><option value="transferencia">Transferencia</option><option value="efectivo">Efectivo</option><option value="cheque">Cheque</option><option value="tarjeta">Tarjeta</option><option value="otro">Otro</option></select>
                <label class="form-label mt-2">Sale de la cuenta</label><select id="pCuenta" class="form-select"><option value="">— No registrar en banco —</option>${cuentas.map(c => `<option value="${c.id}"${c.pred ? ' selected' : ''}>${esc(c.txt)}</option>`).join('')}</select>
                <label class="form-label mt-2">Comprobante (opcional)</label><input type="file" id="pArchivo" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf"></div>`,
            showCancelButton: true, confirmButtonText: 'Registrar pago', cancelButtonText: 'Cancelar', confirmButtonColor: '#16a34a', focusConfirm: false,
            preConfirm: () => {
                const fd = new FormData();
                fd.append('gasto_id', b.dataset.id);
                fd.append('fecha', document.getElementById('pFecha').value);
                fd.append('metodo_pago', document.getElementById('pMetodo').value);
                fd.append('cuenta_id', document.getElementById('pCuenta').value);
                const a = document.getElementById('pArchivo').files[0];
                if (a) fd.append('archivo_adjunto', a);
                return fetch('includes/gasto_marcar_pagado.php', { method: 'POST', body: fd }).then(r => r.json())
                    .then(d => { if (!d.success) throw new Error(d.error); return d; })
                    .catch(e => Swal.showValidationMessage(e.message));
            }
        }).then(r => { if (r.isConfirmed) Swal.fire({ icon: 'success', title: r.value.message }).then(() => location.reload()); });
    }));
})();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
