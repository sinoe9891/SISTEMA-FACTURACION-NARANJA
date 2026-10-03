<?php
$titulo = 'Cuentas por cobrar';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/cuentas.php';

$cid = cliente_actual();
$instalado = cxcDisponible($pdo);
$puedeCobrar = in_array(USUARIO_ROL, ['admin', 'superadmin', 'facturador'], true);
$facturas = $instalado && $cid ? cxcFacturasPendientes($pdo, $cid) : [];
$cuentasHnl = ($instalado && bancosDisponible($pdo)) ? array_values(array_filter(bancoCuentas($pdo, $cid, true), fn($c) => $c['moneda'] === 'HNL')) : [];

$tramos = ['0-30' => 0.0, '31-60' => 0.0, '61-90' => 0.0, '90+' => 0.0];
$porCliente = [];
foreach ($facturas as $f) {
    $t = cxcTramo((int)$f['dias']);
    $tramos[$t] += (float)$f['saldo'];
    $k = $f['receptor_id'];
    $porCliente[$k] ??= ['nombre' => $f['receptor'], 'rtn' => $f['receptor_rtn'], 'facturas' => 0, 'saldo' => 0.0, 'mayor' => 0] + array_fill_keys(array_keys($tramos), 0.0);
    $porCliente[$k]['facturas']++;
    $porCliente[$k]['saldo'] += (float)$f['saldo'];
    $porCliente[$k][$t] += (float)$f['saldo'];
    $porCliente[$k]['mayor'] = max($porCliente[$k]['mayor'], (int)$f['dias']);
}
uasort($porCliente, fn($a, $b) => $b['saldo'] <=> $a['saldo']);
$total = array_sum($tramos);
$L = fn($v) => 'L ' . number_format((float)$v, 2);
$colorTramo = ['0-30' => 'success', '31-60' => 'warning', '61-90' => 'warning', '90+' => 'danger'];

require_once '../../includes/templates/header.php';
?>

<div class="app-page-header">
    <div>
        <h1 class="app-page-title">Cuentas por cobrar</h1>
        <p class="app-page-sub">Facturas emitidas con saldo pendiente, por antigüedad desde la fecha de emisión.</p>
    </div>
</div>

<?php if (!$instalado): ?>
    <div class="alert alert-warning">Falta instalar el módulo: ejecuta <code>sql/migraciones/2026-10-03_cuentas_cobrar.sql</code>.</div>
<?php else: ?>
    <div class="row g-3 mb-3">
        <div class="col-12 col-xl-4"><div class="app-card app-kpi"><div class="app-kpi-label">Total por cobrar</div><div class="app-kpi-value"><?= $L($total) ?></div><small class="text-muted"><?= count($facturas) ?> factura(s) · <?= count($porCliente) ?> cliente(s)</small></div></div>
        <?php foreach ($tramos as $t => $v): ?>
            <div class="col-6 col-md-3 col-xl-2"><div class="app-card app-kpi"><div class="app-kpi-label"><?= $t ?> días</div><div class="app-kpi-value fs-5 text-<?= $v > 0 ? $colorTramo[$t] : 'muted' ?>"><?= $L($v) ?></div></div></div>
        <?php endforeach; ?>
    </div>

    <div class="app-card mb-3">
        <div class="app-card-header"><span><i class="bi bi-people me-1"></i> Por cliente</span></div>
        <div class="table-responsive">
            <table class="table app-table">
                <thead><tr><th>Cliente</th><th class="app-num">Facturas</th><?php foreach ($tramos as $t => $v): ?><th class="app-num"><?= $t ?></th><?php endforeach; ?><th class="app-num">Saldo</th></tr></thead>
                <tbody>
                    <?php if (!$porCliente): ?><tr><td colspan="7" class="text-center text-muted py-4"><i class="bi bi-check-circle text-success"></i> No hay saldos pendientes.</td></tr><?php endif; ?>
                    <?php foreach ($porCliente as $rid => $p): ?>
                        <tr>
                            <td><a href="#" class="link-cliente" data-id="<?= (int)$rid ?>"><?= htmlspecialchars($p['nombre']) ?></a><?= $p['mayor'] > 90 ? ' <span class="app-badge app-badge-danger">+90 días</span>' : '' ?><div class="small text-muted"><?= htmlspecialchars($p['rtn'] ?? '') ?></div></td>
                            <td class="app-num"><?= $p['facturas'] ?></td>
                            <?php foreach (array_keys($tramos) as $t): ?><td class="app-num <?= $p[$t] > 0 ? 'text-' . $colorTramo[$t] : 'text-muted' ?>"><?= $p[$t] > 0 ? number_format($p[$t], 2) : '—' ?></td><?php endforeach; ?>
                            <td class="app-num fw-semibold"><?= $L($p['saldo']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="app-card">
        <div class="app-card-header">
            <span><i class="bi bi-receipt me-1"></i> Facturas pendientes <span id="filtroCliente" class="app-badge app-badge-info d-none"></span></span>
            <input type="search" class="form-control form-control-sm" id="buscarFactura" placeholder="Buscar…" style="max-width:220px">
        </div>
        <div class="table-responsive">
            <table class="table app-table" id="tablaPendientes">
                <thead><tr><th>Factura</th><th>Cliente</th><th>Emisión</th><th class="app-num">Días</th><th class="app-num">Total</th><th class="app-num">Abonado</th><th class="app-num">Saldo</th><th class="text-end">Acciones</th></tr></thead>
                <tbody>
                    <?php foreach ($facturas as $f): $t = cxcTramo((int)$f['dias']); ?>
                        <tr data-cliente="<?= (int)$f['receptor_id'] ?>" data-buscar="<?= htmlspecialchars(mb_strtolower($f['correlativo'] . ' ' . $f['receptor'])) ?>">
                            <td class="font-monospace small"><a href="ver_factura?id=<?= (int)$f['id'] ?>" target="_blank"><?= htmlspecialchars($f['correlativo']) ?></a></td>
                            <td><?= htmlspecialchars($f['receptor']) ?></td>
                            <td class="text-nowrap"><?= date('d/m/Y', strtotime($f['fecha_emision'])) ?></td>
                            <td class="app-num"><span class="app-badge app-badge-<?= $colorTramo[$t] ?>"><?= (int)$f['dias'] ?></span></td>
                            <td class="app-num"><?= number_format((float)$f['total'], 2) ?></td>
                            <td class="app-num text-success"><?= (float)$f['abonado'] > 0 ? number_format((float)$f['abonado'], 2) : '—' ?></td>
                            <td class="app-num fw-semibold"><?= number_format((float)$f['saldo'], 2) ?></td>
                            <td class="text-end text-nowrap">
                                <button class="btn btn-sm btn-outline-secondary btn-historial" data-id="<?= (int)$f['id'] ?>" title="Abonos"><i class="bi bi-clock-history"></i></button>
                                <?php if ($puedeCobrar): ?>
                                    <button class="btn btn-sm btn-success btn-cobrar" data-id="<?= (int)$f['id'] ?>" data-saldo="<?= number_format((float)$f['saldo'], 2, '.', '') ?>"
                                        data-corr="<?= htmlspecialchars($f['correlativo']) ?>" data-fecha="<?= substr($f['fecha_emision'], 0, 10) ?>"><i class="bi bi-cash-coin"></i> Cobrar</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$facturas): ?><tr><td colspan="8" class="text-center text-muted py-4">Sin facturas pendientes.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($puedeCobrar): ?>
        <div class="modal fade" id="modalCobro" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-fullscreen-sm-down">
                <form class="modal-content" id="formCobro" novalidate>
                    <div class="modal-header"><h5 class="modal-title">Registrar cobro · <span id="cobroCorr" class="font-monospace"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
                    <div class="modal-body row g-3">
                        <input type="hidden" name="accion" value="cobrar"><input type="hidden" name="factura_id">
                        <div class="col-12"><div class="alert alert-info py-2 mb-0 small">Saldo pendiente: <strong id="cobroSaldo"></strong></div></div>
                        <div class="col-6"><label class="form-label">Monto *</label><input class="form-control" type="number" step="0.01" min="0.01" name="monto" required></div>
                        <div class="col-6"><label class="form-label">Fecha *</label><input class="form-control" type="date" name="fecha" required value="<?= date('Y-m-d') ?>"></div>
                        <div class="col-6"><label class="form-label">Método</label>
                            <select class="form-select" name="metodo"><option value="transferencia">Transferencia</option><option value="efectivo">Efectivo</option><option value="cheque">Cheque</option><option value="tarjeta">Tarjeta</option><option value="otro">Otro</option></select></div>
                        <div class="col-6"><label class="form-label">Referencia</label><input class="form-control" name="referencia" maxlength="100"></div>
                        <div class="col-12"><label class="form-label">Depositado en</label>
                            <select class="form-select" name="cuenta_id"><option value="">— No registrar en banco —</option>
                                <?php foreach ($cuentasHnl as $c): ?><option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['banco'] . ' ' . $c['numero']) ?></option><?php endforeach; ?></select>
                            <div class="form-text">Si eliges una cuenta, el cobro aparece como entrada en Bancos.</div></div>
                        <div class="col-12"><label class="form-label">Notas</label><input class="form-control" name="notas" maxlength="255"></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-success" type="submit">Registrar cobro</button></div>
                </form>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<script>
(function () {
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const L = v => 'L ' + Number(v).toLocaleString('es-HN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const filas = () => document.querySelectorAll('#tablaPendientes tbody tr[data-cliente]');
    let cliente = null;
    function filtrar() {
        const q = (document.getElementById('buscarFactura')?.value || '').trim().toLowerCase();
        filas().forEach(tr => tr.classList.toggle('d-none', (cliente && tr.dataset.cliente !== cliente) || (q && !tr.dataset.buscar.includes(q))));
    }
    document.getElementById('buscarFactura')?.addEventListener('input', filtrar);
    document.querySelectorAll('.link-cliente').forEach(a => a.addEventListener('click', ev => {
        ev.preventDefault();
        cliente = cliente === a.dataset.id ? null : a.dataset.id;
        const b = document.getElementById('filtroCliente');
        b.textContent = cliente ? a.textContent + ' ✕' : '';
        b.classList.toggle('d-none', !cliente);
        filtrar();
        document.getElementById('tablaPendientes').scrollIntoView({ behavior: 'smooth' });
    }));
    document.getElementById('filtroCliente')?.addEventListener('click', () => { cliente = null; document.getElementById('filtroCliente').classList.add('d-none'); filtrar(); });

    const form = document.getElementById('formCobro');
    if (form) {
        const modal = new bootstrap.Modal(document.getElementById('modalCobro'));
        document.querySelectorAll('.btn-cobrar').forEach(b => b.addEventListener('click', () => {
            form.reset(); form.classList.remove('was-validated');
            form.elements.factura_id.value = b.dataset.id;
            form.elements.monto.value = b.dataset.saldo;
            form.elements.monto.max = b.dataset.saldo;
            form.elements.fecha.min = b.dataset.fecha;
            document.getElementById('cobroCorr').textContent = b.dataset.corr;
            document.getElementById('cobroSaldo').textContent = L(b.dataset.saldo);
            modal.show();
        }));
        form.addEventListener('submit', ev => {
            ev.preventDefault();
            if (!form.checkValidity()) { form.classList.add('was-validated'); return; }
            const btn = form.querySelector('[type=submit]'); btn.disabled = true;
            fetch('includes/cxc_accion.php', { method: 'POST', body: new FormData(form) }).then(r => r.json()).then(d => {
                if (!d.success) throw new Error(d.error);
                modal.hide();
                Swal.fire({ icon: 'success', title: d.message }).then(() => location.reload());
            }).catch(e => Swal.fire('No se pudo registrar', e.message, 'error')).finally(() => btn.disabled = false);
        });
    }

    document.querySelectorAll('.btn-historial').forEach(b => b.addEventListener('click', () => {
        fetch('includes/cxc_accion.php?factura_id=' + b.dataset.id).then(r => r.json()).then(d => {
            if (!d.success) throw new Error(d.error);
            const filasH = d.cobros.length ? d.cobros.map(c => `<tr class="${+c.anulado ? 'text-muted text-decoration-line-through' : ''}">
                <td>${esc(c.fecha.split('-').reverse().join('/'))}</td><td>${esc(c.metodo)}${c.banco ? '<br><small>' + esc(c.banco + ' ' + c.cuenta_numero) + '</small>' : ''}</td>
                <td>${esc(c.referencia || '')}</td><td class="text-end">${L(c.monto)}</td>
                <td>${+c.anulado ? '<small>' + esc(c.motivo_anulacion || 'Anulado') + '</small>' : (<?= in_array(USUARIO_ROL, ['admin', 'superadmin'], true) ? 'true' : 'false' ?> ? `<button class="btn btn-sm btn-link text-danger p-0 anular-cobro" data-id="${c.id}">Anular</button>` : '')}</td></tr>`).join('')
                : '<tr><td colspan="5" class="text-center text-muted">Sin abonos registrados.</td></tr>';
            Swal.fire({
                title: 'Factura ' + esc(d.factura.correlativo), width: 640,
                html: `<div class="text-start small mb-2">Total ${L(d.factura.total)} · Abonado ${L(d.factura.abonado)} · <strong>Saldo ${L(d.factura.saldo)}</strong></div>
                       <div class="table-responsive"><table class="table table-sm text-start"><thead><tr><th>Fecha</th><th>Método</th><th>Ref.</th><th class="text-end">Monto</th><th></th></tr></thead><tbody>${filasH}</tbody></table></div>`,
                showConfirmButton: false, showCloseButton: true,
                didOpen: () => document.querySelectorAll('.anular-cobro').forEach(x => x.addEventListener('click', () => {
                    Swal.fire({ title: 'Anular abono', input: 'text', inputPlaceholder: 'Motivo (obligatorio)', showCancelButton: true, confirmButtonText: 'Anular', confirmButtonColor: '#dc2626', cancelButtonText: 'Cancelar', inputValidator: v => !v.trim() && 'Indica el motivo' })
                        .then(r => {
                            if (!r.isConfirmed) return;
                            const fd = new FormData(); fd.append('accion', 'anular_cobro'); fd.append('id', x.dataset.id); fd.append('motivo', r.value);
                            fetch('includes/cxc_accion.php', { method: 'POST', body: fd }).then(r => r.json()).then(d => d.success ? location.reload() : Swal.fire('Error', d.error, 'error'));
                        });
                }))
            });
        }).catch(e => Swal.fire('Error', e.message, 'error'));
    }));
})();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
