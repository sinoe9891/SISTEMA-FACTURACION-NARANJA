<?php
// Cobros → Pagos recibidos: abonos a facturas, recibos y pagos anticipados en una sola lista, con su cuenta y su comprobante.
// Los filtros se aplican sin recargar (se piden los datos y se reemplaza la zona #prZona).
$titulo = 'Pagos recibidos';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/pagos_recibidos.php';

$cid = (int)cliente_actual();
$instalado = pagosRecibidosDisponible($pdo);
$puedeEditar = in_array(USUARIO_ROL, ['admin', 'superadmin', 'facturador'], true);
$fechaOk = fn($v, $d) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v)) ? $v : $d;
$desde = $fechaOk($_GET['desde'] ?? null, date('Y-01-01'));
$hasta = $fechaOk($_GET['hasta'] ?? null, date('Y-m-d'));
if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];
$fTipo = isset(PAGO_RECIBIDO_TIPOS[$_GET['tipo'] ?? '']) ? $_GET['tipo'] : '';
$fCliente = (int)($_GET['cliente'] ?? 0);
$fCuenta = (string)($_GET['cuenta'] ?? '');            // '' todas · 'ninguna' sin banco · id
$fComp = in_array($_GET['comprobante'] ?? '', ['con', 'sin'], true) ? $_GET['comprobante'] : '';
$fAnulados = !empty($_GET['anulados']);

$todos = $instalado ? pagosRecibidosLista($pdo, $cid, $desde, $hasta) : [];
$clientes = [];
foreach ($todos as $r) $clientes[(int)$r['receptor_id']] = $r['cliente'];
asort($clientes);
$cuentas = bancosDisponible($pdo) ? array_values(array_filter(bancoCuentas($pdo, $cid), fn($c) => $c['moneda'] === 'HNL')) : [];
$pagos = array_values(array_filter($todos, fn($r) => ($fAnulados || !(int)$r['anulado'])
    && (!$fTipo || $r['tipo'] === $fTipo)
    && (!$fCliente || (int)$r['receptor_id'] === $fCliente)
    && ($fCuenta === '' || ($fCuenta === 'ninguna' ? !$r['cuenta_id'] : (int)$r['cuenta_id'] === (int)$fCuenta))
    && (!$fComp || ($fComp === 'con') === (bool)(int)$r['con_comprobante'])));
$vigentes = array_filter($pagos, fn($r) => !(int)$r['anulado']);
$total = array_sum(array_column($vigentes, 'monto'));
$sinComp = count(array_filter($vigentes, fn($r) => !(int)$r['con_comprobante']));
$sinBanco = count(array_filter($vigentes, fn($r) => !$r['cuenta_id']));
$porTipo = [];
foreach ($vigentes as $r) $porTipo[$r['tipo']] = ($porTipo[$r['tipo']] ?? 0) + (float)$r['monto'];
$L = fn($v) => 'L ' . number_format((float)$v, 2);
$hoy = new DateTime('today');
$rangos = [
    'Este mes' => [$hoy->format('Y-m-01'), $hoy->format('Y-m-d')],
    'Mes anterior' => [(new DateTime('first day of last month'))->format('Y-m-d'), (new DateTime('last day of last month'))->format('Y-m-d')],
    'Este año' => [$hoy->format('Y-01-01'), $hoy->format('Y-m-d')],
    'Año anterior' => [($hoy->format('Y') - 1) . '-01-01', ($hoy->format('Y') - 1) . '-12-31'],
];
$iconoTipo = ['factura' => 'bi-receipt', 'recibo' => 'bi-file-earmark-text', 'anticipo' => 'bi-piggy-bank'];
require_once '../../includes/templates/header.php';
?>

<div class="app-page-header">
    <div>
        <h1 class="app-page-title"><i class="bi bi-wallet2 me-2"></i>Pagos recibidos</h1>
        <p class="app-page-sub">Todo lo que han pagado los clientes: abonos a facturas, recibos de contratos y pagos anticipados. Edita la fecha, el método, la cuenta del banco o adjunta el comprobante.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap"><a href="cuentas_cobrar" class="btn btn-outline-secondary"><i class="bi bi-cash-coin me-1"></i> Cuentas por cobrar</a></div>
</div>

<?php if (!$instalado): ?>
    <div class="alert alert-warning">Falta instalar <code>sql/migraciones/2026-10-19_pagos_recibidos.sql</code>.</div>
<?php else: ?>
<form id="prFiltros" class="app-card app-card-body mb-3" method="get">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2"><label class="form-label small">Desde</label><input type="date" class="form-control form-control-sm" name="desde" value="<?= $desde ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small">Hasta</label><input type="date" class="form-control form-control-sm" name="hasta" value="<?= $hasta ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small">Tipo</label><select class="form-select form-select-sm" name="tipo"><option value="">Todos</option>
            <?php foreach (PAGO_RECIBIDO_TIPOS as $k => $t): ?><option value="<?= $k ?>" <?= $fTipo === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small">Cuenta</label><select class="form-select form-select-sm" name="cuenta"><option value="">Todas</option><option value="ninguna" <?= $fCuenta === 'ninguna' ? 'selected' : '' ?>>Sin banco</option>
            <?php foreach ($cuentas as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $fCuenta === (string)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['banco'] . ' ' . $c['numero']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small">Comprobante</label><select class="form-select form-select-sm" name="comprobante"><option value="">Todos</option>
            <option value="sin" <?= $fComp === 'sin' ? 'selected' : '' ?>>Sin comprobante</option><option value="con" <?= $fComp === 'con' ? 'selected' : '' ?>>Con comprobante</option></select></div>
        <div class="col-6 col-md-2"><label class="form-label small">Cliente</label><select class="form-select form-select-sm" name="cliente" data-buscar><option value="">Todos</option>
            <?php foreach ($clientes as $k => $n): ?><option value="<?= $k ?>" <?= $fCliente === $k ? 'selected' : '' ?>><?= htmlspecialchars($n) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 d-flex flex-wrap gap-1 align-items-center">
            <?php foreach ($rangos as $txt => [$d, $h]): ?><a class="btn btn-sm <?= $d === $desde && $h === $hasta ? 'btn-secondary' : 'btn-outline-secondary' ?> pr-rango" href="?desde=<?= $d ?>&hasta=<?= $h ?>" data-desde="<?= $d ?>" data-hasta="<?= $h ?>"><?= $txt ?></a><?php endforeach; ?>
            <div class="form-check form-switch ms-md-3"><input class="form-check-input" type="checkbox" role="switch" name="anulados" value="1" id="prAnulados" <?= $fAnulados ? 'checked' : '' ?> style="cursor:pointer"><label class="form-check-label small" for="prAnulados">Ver anulados</label></div>
        </div>
    </div>
</form>

<div id="prZona">
    <div class="app-stats">
        <div class="app-stat"><div class="app-stat-icon green"><i class="bi bi-wallet2"></i></div><div><div class="app-stat-val"><?= $L($total) ?></div><div class="app-stat-lbl"><?= count($vigentes) ?> pago<?= count($vigentes) === 1 ? '' : 's' ?> recibido<?= count($vigentes) === 1 ? '' : 's' ?></div></div></div>
        <?php foreach (PAGO_RECIBIDO_TIPOS as $k => $t): ?>
            <div class="app-stat"><div class="app-stat-icon blue"><i class="bi <?= $iconoTipo[$k] ?>"></i></div><div><div class="app-stat-val"><?= $L($porTipo[$k] ?? 0) ?></div><div class="app-stat-lbl"><?= ['factura' => 'Abonos a facturas', 'recibo' => 'Recibos', 'anticipo' => 'Pagos anticipados'][$k] ?></div></div></div>
        <?php endforeach; ?>
        <a href="#" class="app-stat text-decoration-none pr-ver-sin" title="Ver solo los que no tienen comprobante"><div class="app-stat-icon <?= $sinComp ? 'amber' : 'gray' ?>"><i class="bi bi-paperclip"></i></div><div><div class="app-stat-val"><?= $sinComp ?></div><div class="app-stat-lbl">Sin comprobante<?= $sinBanco ? ' · ' . $sinBanco . ' sin banco' : '' ?></div></div></a>
    </div>

    <div class="app-card">
        <div class="app-card-header flex-wrap gap-2">
            <span><i class="bi bi-list-ul me-1"></i> Pagos del <?= date('d/m/Y', strtotime($desde)) ?> al <?= date('d/m/Y', strtotime($hasta)) ?></span>
            <div class="app-toolbar flex-grow-1 justify-content-end">
                <div class="app-search" style="max-width:320px"><i class="bi bi-search"></i><input type="search" class="form-control form-control-sm" id="prBuscar" placeholder="Buscar cliente, documento, referencia…"></div>
                <select class="form-select form-select-sm" id="prPorPagina" style="width:auto"><option value="25">25/pág</option><option value="50">50/pág</option><option value="100">100/pág</option><option value="100000">Todas</option></select>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table app-table" id="tPagos" data-ordenar>
                <thead><tr><th class="app-n">#</th><th>Fecha</th><th>Tipo</th><th>Documento</th><th>Cliente</th><th>Método</th><th>Cuenta</th><th class="app-num">Monto</th><th class="text-center">Comprobante</th><th class="text-end no-orden"></th></tr></thead>
                <tbody>
                <?php foreach ($pagos as $r): $anul = (int)$r['anulado']; ?>
                    <tr data-fila class="<?= $anul ? 'text-muted text-decoration-line-through' : '' ?>" data-buscar="<?= htmlspecialchars(mb_strtolower($r['cliente'] . ' ' . $r['documento'] . ' ' . $r['referencia'] . ' ' . $r['notas'] . ' ' . $r['contrato'] . ' ' . $r['aplicado_a'])) ?>">
                        <td class="app-n"></td>
                        <td class="text-nowrap" data-sort="<?= $r['fecha'] ?>"><?= date('d/m/Y', strtotime($r['fecha'])) ?></td>
                        <td class="text-nowrap small"><i class="bi <?= $iconoTipo[$r['tipo']] ?> me-1 text-muted"></i><?= PAGO_RECIBIDO_TIPOS[$r['tipo']] ?></td>
                        <td class="text-nowrap small"><a href="<?= htmlspecialchars($r['documento_url']) ?>" target="_blank" class="font-monospace"><?= htmlspecialchars($r['documento']) ?></a>
                            <?php if ($r['aplicado_a']): ?><div class="text-muted">aplicado a <?= htmlspecialchars(substr($r['aplicado_a'], -8)) ?></div><?php endif; ?></td>
                        <td><?= htmlspecialchars($r['cliente']) ?><?php if ($r['contrato']): ?><div class="small text-muted">Contrato #<?= (int)$r['contrato_id'] ?></div><?php endif; ?></td>
                        <td class="small"><?= PAGO_RECIBIDO_METODOS[$r['metodo']] ?? htmlspecialchars((string)$r['metodo']) ?><?php if ($r['referencia']): ?><div class="text-muted"><?= htmlspecialchars($r['referencia']) ?></div><?php endif; ?></td>
                        <td class="small"><?= $r['cuenta'] ? htmlspecialchars($r['cuenta']) . ((int)$r['conciliado'] ? ' <i class="bi bi-check2-all text-success" title="Conciliado"></i>' : '') : '<span class="text-muted">—</span>' ?></td>
                        <td class="app-num fw-semibold"><?= number_format((float)$r['monto'], 2) ?></td>
                        <td class="text-center"><?= (int)$r['con_comprobante'] ? '<a href="pago_recibido_archivo?tipo=' . $r['tipo'] . '&id=' . (int)$r['id'] . '" target="_blank" title="Ver comprobante"><i class="bi bi-paperclip fs-5"></i></a>' : '<span class="badge bg-light text-secondary border">Sin</span>' ?></td>
                        <td class="text-end text-nowrap"><?php if ($puedeEditar && !$anul): ?><button class="btn btn-sm btn-outline-primary pr-editar" data-tipo="<?= $r['tipo'] ?>" data-id="<?= (int)$r['id'] ?>" title="Editar y adjuntar comprobante"><i class="bi bi-pencil"></i></button><?php endif; ?><?= $anul ? '<span class="small">Anulado</span>' : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$pagos): ?><tr><td colspan="10" class="text-center text-muted py-4">No hay pagos con estos filtros.</td></tr><?php endif; ?>
                </tbody>
                <?php if ($vigentes): ?><tfoot><tr class="fw-bold"><td colspan="7" class="text-end">Total recibido</td><td class="app-num"><?= $L($total) ?></td><td colspan="2"></td></tr></tfoot><?php endif; ?>
            </table>
        </div>
        <div class="app-pager" id="prPie"></div>
    </div>
</div>

<?php if ($puedeEditar): ?>
<div class="modal fade" id="mPago" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down"><form class="modal-content" id="fPago" novalidate>
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-pencil me-1"></i> Editar pago · <span id="pDoc" class="font-monospace"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body row g-2">
            <input type="hidden" name="accion" value="editar"><input type="hidden" name="tipo"><input type="hidden" name="id">
            <div class="col-12"><div class="alert alert-light border py-2 mb-1 small">Monto: <strong id="pMonto"></strong> · para cambiarlo, anula el pago donde se registró y regístralo de nuevo.</div></div>
            <div class="col-6"><label class="form-label">Fecha *</label><input type="date" name="fecha" class="form-control" required></div>
            <div class="col-6"><label class="form-label">Método</label><select name="metodo" class="form-select"><?php foreach (PAGO_RECIBIDO_METODOS as $k => $t): ?><option value="<?= $k ?>"><?= $t ?></option><?php endforeach; ?></select></div>
            <div class="col-12" id="pRefGrp"><label class="form-label">Referencia</label><input name="referencia" class="form-control" maxlength="100" placeholder="N.º de transferencia"></div>
            <div class="col-12"><label class="form-label">Depositado en</label><select name="cuenta_id" class="form-select"><option value="">— No registrar en banco —</option>
                <?php foreach ($cuentas as $c): if (!(int)$c['activa']) continue; ?><option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['banco'] . ' ' . $c['numero']) ?></option><?php endforeach; ?></select>
                <div class="form-text">Al cambiar la cuenta, el depósito se mueve a esa cuenta en Bancos; «No registrar» lo quita del banco.</div></div>
            <div class="col-12"><label class="form-label" id="pNotasLbl">Notas</label><input name="notas" class="form-control" maxlength="255"></div>
            <div class="col-12"><label class="form-label">Comprobante <span class="text-muted small">(JPG, PNG o PDF, máx. 5 MB)</span></label>
                <div id="pCompActual" class="small mb-1"></div>
                <input type="file" name="comprobante" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf">
                <div class="form-check mt-1 d-none" id="pQuitarGrp"><input class="form-check-input" type="checkbox" name="quitar_comprobante" value="1" id="pQuitar"><label class="form-check-label small" for="pQuitar">Quitar el comprobante actual</label></div></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary"><i class="bi bi-floppy me-1"></i> Guardar</button></div>
    </form></div>
</div>
<?php endif; ?>
<?php endif; ?>

<script src="../../clientes/js/app-tabla.js?v=<?= @filemtime(__DIR__ . '/../js/app-tabla.js') ?>"></script>
<script>
(() => {
    const f = document.getElementById('prFiltros');
    if (!f) return;
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    let tabla = null, pedido = 0;
    const iniciarTabla = () => {
        const t = document.getElementById('tPagos');
        tabla = AppTabla(t, { buscar: '#prBuscar', porPagina: '#prPorPagina', pie: '#prPie', vacio: 'Sin resultados.' });
        window.AppOrdenarIniciar?.();   // la tabla es nueva: vuelve a armar el orden por columnas
    };
    iniciarTabla();

    // Filtros sin recargar: se piden los datos y se reemplaza la zona de tarjetas y tabla
    const cargar = async url => {
        const yo = ++pedido, z = document.getElementById('prZona');
        z.style.opacity = '.5';
        try {
            const r = await fetch(url, { credentials: 'same-origin' });
            if (!r.ok) throw new Error();
            const doc = new DOMParser().parseFromString(await r.text(), 'text/html');
            if (yo !== pedido) return;
            z.replaceWith(doc.getElementById('prZona'));
            document.querySelectorAll('.pr-rango').forEach(a => { const on = a.dataset.desde === f.desde.value && a.dataset.hasta === f.hasta.value; a.classList.toggle('btn-secondary', on); a.classList.toggle('btn-outline-secondary', !on); });
            history.replaceState(null, '', url);
            iniciarTabla();
        } catch (e) { location.href = url; }
        finally { document.getElementById('prZona').style.removeProperty('opacity'); }
    };
    const urlForm = () => location.pathname + '?' + new URLSearchParams(new FormData(f)).toString();
    f.addEventListener('change', () => cargar(urlForm()));
    f.addEventListener('submit', e => { e.preventDefault(); cargar(urlForm()); });
    document.addEventListener('click', e => {
        const a = e.target.closest('.pr-rango');
        if (a) { e.preventDefault(); f.desde.value = a.dataset.desde; f.hasta.value = a.dataset.hasta; cargar(urlForm()); }
        if (e.target.closest('.pr-ver-sin')) { e.preventDefault(); f.comprobante.value = 'sin'; cargar(urlForm()); }
    });

    // Editar un pago (y su comprobante)
    const fp = document.getElementById('fPago');
    if (!fp) return;
    const modal = new bootstrap.Modal(document.getElementById('mPago'));
    const L = n => 'L ' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    document.addEventListener('click', async e => {
        const b = e.target.closest('.pr-editar');
        if (!b) return;
        const d = await fetch(`includes/pago_recibido_accion.php?ver=${b.dataset.tipo}&id=${b.dataset.id}`).then(r => r.json());
        if (!d.success) return Swal.fire('No se pudo', d.error, 'error');
        const p = d.pago;
        fp.reset();
        fp.tipo.value = p.tipo; fp.id.value = p.id; fp.fecha.value = p.fecha; fp.metodo.value = p.metodo; fp.notas.value = p.notas || '';
        fp.referencia.value = p.referencia || '';
        fp.querySelectorAll('option.pr-cta-extra').forEach(o => o.remove());
        fp.cuenta_id.value = String(p.cuenta_id || '');
        if (fp.cuenta_id.value !== String(p.cuenta_id || '')) {   // su cuenta ya no está activa: se muestra igual
            fp.cuenta_id.insertAdjacentHTML('beforeend', `<option class="pr-cta-extra" value="${p.cuenta_id}">${esc(p.cuenta || 'Cuenta #' + p.cuenta_id)} (inactiva)</option>`);
            fp.cuenta_id.value = String(p.cuenta_id);
        }
        document.getElementById('pRefGrp').classList.toggle('d-none', p.referencia === null);
        document.getElementById('pNotasLbl').textContent = p.tipo === 'anticipo' ? 'Concepto' : 'Notas';
        document.getElementById('pDoc').textContent = p.documento;
        document.getElementById('pMonto').textContent = L(p.monto);
        document.getElementById('pCompActual').innerHTML = p.archivo ? `<i class="bi bi-paperclip"></i> <a href="pago_recibido_archivo?tipo=${p.tipo}&id=${p.id}" target="_blank">${esc(p.archivo)}</a> · si subes otro, lo reemplaza` : '<span class="text-muted">Sin comprobante.</span>';
        document.getElementById('pQuitarGrp').classList.toggle('d-none', !p.archivo);
        modal.show();
    });
    fp.addEventListener('submit', async e => {
        e.preventDefault();
        if (!fp.fecha.value) return fp.fecha.focus();
        const btn = fp.querySelector('.btn-primary'); btn.disabled = true;
        try {
            const r = await fetch('includes/pago_recibido_accion.php', { method: 'POST', body: new FormData(fp) });
            const d = await r.json().catch(() => ({ success: false, error: 'Respuesta inesperada (' + r.status + ').' }));
            if (!d.success) throw new Error(d.error);
            modal.hide();
            Swal.fire({ icon: 'success', title: d.message, timer: 1300, showConfirmButton: false });
            cargar(location.pathname + location.search);
        } catch (err) { Swal.fire('No se pudo guardar', err.message, 'error'); }
        finally { btn.disabled = false; }
    });
})();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
