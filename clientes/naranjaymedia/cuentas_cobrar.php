<?php
$titulo = 'Cuentas por cobrar';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/cuentas.php';

$cid = cliente_actual();
$instalado = cxcDisponible($pdo);
$puedeCobrar = in_array(USUARIO_ROL, ['admin', 'superadmin', 'facturador'], true);
$puedeCorreo = in_array(USUARIO_ROL, ['admin', 'superadmin'], true);   // «Cobros por correo» es solo de administradores
$facturas = $instalado && $cid ? cxcFacturasPendientes($pdo, $cid) : [];
$cuentasHnl = ($instalado && bancosDisponible($pdo)) ? array_values(array_filter(bancoCuentas($pdo, $cid, true), fn($c) => $c['moneda'] === 'HNL')) : [];

$tramos = ['0-30' => 0.0, '31-60' => 0.0, '61-90' => 0.0, '90+' => 0.0];
$porCliente = [];
foreach ($facturas as $f) {
    $t = cxcTramo((int)$f['dias']);
    $tramos[$t] += (float)$f['saldo'];
    $k = $f['receptor_id'];
    $porCliente[$k] ??= ['nombre' => $f['receptor'], 'rtn' => $f['receptor_rtn'], 'facturas' => 0, 'saldo' => 0.0, 'mayor' => 0, 'meses' => [], 'desde' => null] + array_fill_keys(array_keys($tramos), 0.0);
    $porCliente[$k]['meses'][sprintf('%04d-%02d', $f['periodo_anio'], $f['periodo_mes'])] = true;
    $porCliente[$k]['desde'] = min($porCliente[$k]['desde'] ?? $f['fecha_emision'], $f['fecha_emision']);
    $porCliente[$k]['facturas']++;
    $porCliente[$k]['saldo'] += (float)$f['saldo'];
    $porCliente[$k][$t] += (float)$f['saldo'];
    $porCliente[$k]['mayor'] = max($porCliente[$k]['mayor'], (int)$f['dias']);
}
uasort($porCliente, fn($a, $b) => $b['saldo'] <=> $a['saldo']);
$total = array_sum($tramos);
$L = fn($v) => 'L ' . number_format((float)$v, 2);
$colorTramo = ['0-30' => 'success', '31-60' => 'warning', '61-90' => 'warning', '90+' => 'danger'];
$mesCorto = [1 => 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
$periodo = fn($m, $a) => $mesCorto[(int)$m] . ' ' . $a;

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
    <?php $iconoTramo = ['0-30' => ['bi-hourglass-top', 'green'], '31-60' => ['bi-hourglass-split', 'amber'], '61-90' => ['bi-hourglass-bottom', 'amber'], '90+' => ['bi-exclamation-octagon', 'red']]; ?>
    <div class="app-stats">
        <div class="app-stat"><div class="app-stat-icon"><i class="bi bi-cash-stack"></i></div>
            <div><div class="app-stat-val"><?= $L($total) ?></div><div class="app-stat-lbl" title="<?= count($facturas) ?> factura(s) de <?= count($porCliente) ?> cliente(s)">Por cobrar · <?= count($facturas) ?> facturas</div></div></div>
        <?php foreach ($tramos as $t => $v): ?>
            <div class="app-stat"><div class="app-stat-icon <?= $v > 0 ? $iconoTramo[$t][1] : 'gray' ?>"><i class="bi <?= $iconoTramo[$t][0] ?>"></i></div>
                <div><div class="app-stat-val <?= $v > 0 ? 'text-' . $colorTramo[$t] : 'text-muted' ?>"><?= $L($v) ?></div><div class="app-stat-lbl"><?= $t ?> días</div></div></div>
        <?php endforeach; ?>
    </div>

    <div class="app-card mb-3">
        <div class="app-card-header"><span><i class="bi bi-people me-1"></i> Por cliente</span><small class="text-muted fw-normal">Clic en el nombre para ver su estado de cuenta</small></div>
        <div class="table-responsive">
            <table class="table app-table">
                <thead><tr><th class="app-n">#</th><th>Cliente</th><th>Meses adeudados</th><th class="app-num">Facturas</th><?php foreach ($tramos as $t => $v): ?><th class="app-num"><?= $t ?></th><?php endforeach; ?><th class="app-num">Saldo</th><?php if ($puedeCorreo): ?><th></th><?php endif; ?></tr></thead>
                <tbody>
                    <?php if (!$porCliente): ?><tr><td colspan="10" class="text-center text-muted py-4"><i class="bi bi-check-circle text-success"></i> No hay saldos pendientes.</td></tr><?php endif; ?>
                    <?php $nCli = 0; foreach ($porCliente as $rid => $p): ksort($p['meses']); $mesesTxt = array_map(fn($k) => $periodo((int)substr($k, 5), substr($k, 0, 4)), array_keys($p['meses'])); ?>
                        <tr>
                            <td class="app-n"><?= ++$nCli ?></td>
                            <td><a href="estado_cuenta?receptor_id=<?= (int)$rid ?>" title="Ver estado de cuenta"><?= htmlspecialchars($p['nombre']) ?></a><?= $p['mayor'] > 90 ? ' <span class="app-badge app-badge-danger">+90 días</span>' : '' ?><div class="small text-muted"><?= htmlspecialchars($p['rtn'] ?? '') ?></div></td>
                            <td class="small"><?= htmlspecialchars(implode(', ', $mesesTxt)) ?><div class="text-muted">Debe desde el <?= date('d/m/Y', strtotime($p['desde'])) ?></div></td>
                            <td class="app-num"><?= $p['facturas'] ?></td>
                            <?php foreach (array_keys($tramos) as $t): ?><td class="app-num <?= $p[$t] > 0 ? 'text-' . $colorTramo[$t] : 'text-muted' ?>"><?= $p[$t] > 0 ? number_format($p[$t], 2) : '—' ?></td><?php endforeach; ?>
                            <td class="app-num fw-semibold"><?= $L($p['saldo']) ?></td>
                            <?php if ($puedeCorreo): ?><td class="text-end"><a class="btn btn-sm btn-outline-primary text-nowrap" href="cobros_programados?receptor_id=<?= (int)$rid ?>" title="Enviar o programar el cobro de su saldo por correo"><i class="bi bi-send"></i> Cobrar por correo</a></td><?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="app-card">
        <div class="app-card-header flex-wrap">
            <span><i class="bi bi-receipt me-1"></i> Facturas pendientes</span>
            <div class="app-toolbar flex-grow-1 justify-content-end">
                <div class="app-search" style="max-width:320px"><i class="bi bi-search"></i><input type="search" class="form-control form-control-sm" id="buscarFactura" placeholder="Buscar factura o cliente…"></div>
                <select class="form-select form-select-sm" id="porPagina" style="width:auto"><option value="10">10/pág</option><option value="25">25/pág</option><option value="50">50/pág</option></select>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table app-table" id="tablaPendientes">
                <thead><tr><th class="app-n">#</th><th>Factura</th><th>Cliente</th><th>Período</th><th>Emisión</th><th class="app-num">Días</th><th class="app-num">Total</th><th class="app-num">Abonado</th><th class="app-num">Saldo</th><th class="text-end">Acciones</th></tr></thead>
                <tbody>
                    <?php foreach ($facturas as $f): $t = cxcTramo((int)$f['dias']); ?>
                        <tr data-fila data-cliente="<?= (int)$f['receptor_id'] ?>" data-buscar="<?= htmlspecialchars(mb_strtolower($f['correlativo'] . ' ' . $f['receptor'] . ' ' . $periodo($f['periodo_mes'], $f['periodo_anio']))) ?>">
                            <td class="app-n"></td>
                            <td class="font-monospace small text-nowrap"><a href="ver_factura?id=<?= (int)$f['id'] ?>" target="_blank"><?= htmlspecialchars($f['correlativo']) ?></a></td>
                            <td><a href="estado_cuenta?receptor_id=<?= (int)$f['receptor_id'] ?>"><?= htmlspecialchars($f['receptor']) ?></a></td>
                            <td class="text-nowrap small"><?= $periodo($f['periodo_mes'], $f['periodo_anio']) ?></td>
                            <td class="text-nowrap"><?= date('d/m/Y', strtotime($f['fecha_emision'])) ?></td>
                            <td class="app-num"><span class="app-badge app-badge-<?= $colorTramo[$t] ?>"><?= (int)$f['dias'] ?></span></td>
                            <td class="app-num"><?= number_format((float)$f['total'], 2) ?></td>
                            <td class="app-num text-success"><?= (float)$f['abonado'] > 0 ? number_format((float)$f['abonado'], 2) : '—' ?></td>
                            <td class="app-num fw-semibold"><?= number_format((float)$f['saldo'], 2) ?></td>
                            <td class="text-end text-nowrap">
                                <button class="btn btn-sm btn-outline-secondary btn-abonos" data-id="<?= (int)$f['id'] ?>" title="Ver abonos"><i class="bi bi-clock-history"></i></button>
                                <?php if ($puedeCorreo): ?><a class="btn btn-sm btn-outline-primary" href="cobros_programados?receptor_id=<?= (int)$f['receptor_id'] ?>&facturas=<?= (int)$f['id'] ?>" title="Cobrar esta factura por correo"><i class="bi bi-send"></i></a><?php endif; ?>
                                <?php if ($puedeCobrar): ?>
                                    <button class="btn btn-sm btn-success btn-cobrar" data-id="<?= (int)$f['id'] ?>" data-saldo="<?= number_format((float)$f['saldo'], 2, '.', '') ?>"
                                        data-corr="<?= htmlspecialchars($f['correlativo']) ?>" data-fecha="<?= substr($f['fecha_emision'], 0, 10) ?>"><i class="bi bi-cash-coin"></i> Cobrar</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="app-pager" id="pendientesPie"></div>
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
                                <?php foreach ($cuentasHnl as $c): ?><option value="<?= (int)$c['id'] ?>"<?= bancoSel($c) ?>><?= htmlspecialchars($c['banco'] . ' ' . $c['numero']) ?></option><?php endforeach; ?></select>
                            <div class="form-text">Si eliges una cuenta, el cobro aparece como entrada en Bancos.</div></div>
                        <div class="col-12"><label class="form-label">Notas</label><input class="form-control" name="notas" maxlength="255"></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-success" type="submit">Registrar cobro</button></div>
                </form>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<script src="../../clientes/js/app-tabla.js?v=<?= @filemtime(__DIR__ . '/../js/app-tabla.js') ?>"></script>
<script>
(function () {
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const L = v => 'L ' + Number(v).toLocaleString('es-HN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    AppTabla('#tablaPendientes', { buscar: '#buscarFactura', porPagina: '#porPagina', pie: '#pendientesPie', vacio: 'Sin facturas pendientes.' });

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

})();
</script>

<?php if ($instalado) require __DIR__ . '/includes/_modal_abonos.php'; ?>
<?php require_once '../../includes/templates/footer.php'; ?>
