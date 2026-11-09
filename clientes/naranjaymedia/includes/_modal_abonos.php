<?php
// clientes/naranjaymedia/includes/_modal_abonos.php
// Ventana de abonos de una factura: ver, registrar y anular (usa includes/cxc_accion.php).
// Se abre con cualquier botón .btn-abonos[data-id="<factura_id>"]. Al cerrar, si hubo cambios, recarga la página.
// Requiere: $pdo, cliente_actual() y las funciones de includes/cuentas.php.
$__abPuedeCobrar = in_array(USUARIO_ROL, ['admin', 'superadmin', 'facturador'], true);
$__abEsAdmin = in_array(USUARIO_ROL, ['admin', 'superadmin'], true);
$__abCuentas = bancosDisponible($pdo)
    ? array_values(array_filter(bancoCuentas($pdo, cliente_actual(), true), fn($c) => $c['moneda'] === 'HNL'))
    : [];
?>
<div class="modal fade" id="modalAbonos" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-coin me-1"></i> Abonos · <span id="abFactura" class="font-monospace"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex flex-wrap justify-content-between gap-2 small mb-2">
                    <span>Total <strong id="abTotal"></strong></span>
                    <span>Abonado <strong id="abAbonado" class="text-success"></strong></span>
                    <span>Saldo <strong id="abSaldo" class="text-danger"></strong></span>
                </div>
                <div class="table-responsive mb-3">
                    <table class="table table-sm small mb-0">
                        <thead><tr><th>Fecha</th><th>Método</th><th>Referencia</th><th class="text-end">Monto</th><th></th></tr></thead>
                        <tbody id="abLista"></tbody>
                    </table>
                </div>
                <?php if ($__abPuedeCobrar): ?>
                    <form id="abForm" class="border-top pt-3" novalidate>
                        <input type="hidden" name="accion" value="cobrar">
                        <input type="hidden" name="factura_id" id="abFacturaId">
                        <div class="row g-2">
                            <div class="col-6"><label class="form-label small">Fecha *</label><input type="date" name="fecha" id="abFecha" class="form-control form-control-sm" required></div>
                            <div class="col-6"><label class="form-label small">Monto (L) *</label><input type="number" name="monto" id="abMonto" class="form-control form-control-sm" step="0.01" min="0.01" required></div>
                            <div class="col-6"><label class="form-label small">Método</label>
                                <select name="metodo" class="form-select form-select-sm">
                                    <option value="transferencia">Transferencia</option><option value="efectivo">Efectivo</option>
                                    <option value="cheque">Cheque</option><option value="tarjeta">Tarjeta</option><option value="otro">Otro</option>
                                </select></div>
                            <div class="col-6"><label class="form-label small">Referencia</label><input type="text" name="referencia" class="form-control form-control-sm" maxlength="100" placeholder="N.º de transferencia"></div>
                            <?php if ($__abCuentas): ?>
                                <div class="col-12"><label class="form-label small">Depositado en</label>
                                    <select name="cuenta_id" class="form-select form-select-sm"><option value="">— No registrar en banco —</option>
                                        <?php foreach ($__abCuentas as $__c): ?><option value="<?= (int)$__c['id'] ?>"<?= bancoSel($__c) ?>><?= htmlspecialchars($__c['banco'] . ' ' . $__c['numero']) ?></option><?php endforeach; ?>
                                    </select></div>
                            <?php endif; ?>
                            <div class="col-12"><label class="form-label small">Comprobantes <span class="text-muted">(captura de la transferencia, cheque… · JPG, PNG o PDF, máx. 5 MB cada uno · uno o varios)</span></label>
                                <input type="file" name="comprobantes[]" multiple class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp,application/pdf"></div>
                            <div class="col-12"><label class="form-label small">Notas</label><input type="text" name="notas" class="form-control form-control-sm" maxlength="255"></div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm mt-3 w-100"><i class="bi bi-plus-lg me-1"></i> Registrar abono</button>
                    </form>
                <?php endif; ?>
                <div id="abSinSaldo" class="alert alert-success small py-2 mb-0" style="display:none"><i class="bi bi-check-circle me-1"></i> Factura pagada por completo.</div>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    const URL_CXC = 'includes/cxc_accion.php';
    const esAdmin = <?= $__abEsAdmin ? 'true' : 'false' ?>;
    const modalEl = document.getElementById('modalAbonos');
    const modal = new bootstrap.Modal(modalEl);
    const form = document.getElementById('abForm');
    const L = n => 'L ' + Number(n).toLocaleString('es-HN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const hoy = '<?= date('Y-m-d') ?>';
    const puedeEditar = <?= $__abPuedeCobrar ? 'true' : 'false' ?>;
    const CUENTAS = <?= json_encode(array_map(fn($c) => ['id' => (int)$c['id'], 'txt' => $c['banco'] . ' ' . $c['numero']], $__abCuentas), JSON_UNESCAPED_UNICODE) ?>;
    const cobros = {};
    let cambio = false;

    async function cargar(id) {
        const r = await fetch(URL_CXC + '?factura_id=' + id).then(r => r.json());
        if (!r.success) { Swal.fire('Error', r.error || 'No se pudieron cargar los abonos.', 'error'); return false; }
        const f = r.factura;
        document.getElementById('abFactura').textContent = f.correlativo;
        document.getElementById('abTotal').textContent = L(f.total);
        document.getElementById('abAbonado').textContent = L(f.abonado);
        document.getElementById('abSaldo').textContent = L(f.saldo);
        r.cobros.forEach(c => { cobros[c.id] = c; });
        document.getElementById('abLista').innerHTML = r.cobros.length ? r.cobros.map(c => `
            <tr class="${+c.anulado ? 'text-decoration-line-through text-muted' : ''}">
                <td>${esc(c.fecha.split('-').reverse().join('/'))}</td>
                <td>${esc(c.metodo)}${c.banco ? '<br><small>' + esc(c.banco + ' ' + c.cuenta_numero) + '</small>' : ''}</td>
                <td>${esc(c.referencia || '—')}${(c.archivos || []).length > 1 ? c.archivos.map((a, i) => ` <a href="cobro_archivo.php?id=${c.id}&a=${a.id}" target="_blank" title="${esc(a.nombre)}"><i class="bi bi-paperclip"></i>${i + 1}</a>`).join('') : (+c.con_comprobante ? ` <a href="cobro_archivo.php?id=${c.id}" target="_blank" title="Ver comprobante"><i class="bi bi-paperclip"></i></a>` : '')}</td>
                <td class="text-end">${L(c.monto)}</td>
                <td class="text-end">${+c.anulado ? '<small>' + esc(c.motivo_anulacion || 'Anulado') + '</small>'
                    : (puedeEditar && +c.editable ? `<button type="button" class="btn btn-link btn-sm p-0 me-1 ab-editar" data-id="${c.id}" title="Editar abono (fecha, método, referencia, nota, cuenta, comprobantes)"><i class="bi bi-pencil-fill"></i></button>` : '')
                        + (esAdmin ? `<button type="button" class="btn btn-link btn-sm p-0 text-danger ab-anular" data-id="${c.id}" title="Anular abono"><i class="bi bi-x-circle"></i></button>` : '')}</td>
            </tr>`).join('') : '<tr><td colspan="5" class="text-center text-muted">Sin abonos registrados.</td></tr>';
        if (form) {
            form.style.display = f.saldo > 0 ? '' : 'none';
            form.reset();
            document.getElementById('abFacturaId').value = f.id;
            document.getElementById('abFecha').value = hoy;
            document.getElementById('abMonto').value = f.saldo.toFixed(2);
            document.getElementById('abMonto').max = f.saldo.toFixed(2);
        }
        document.getElementById('abSinSaldo').style.display = f.saldo > 0 ? 'none' : '';
        return true;
    }

    document.addEventListener('click', async e => {
        const b = e.target.closest('.btn-abonos');
        if (!b) return;
        e.preventDefault();
        if (await cargar(b.dataset.id)) modal.show();
    });

    form?.addEventListener('submit', async e => {
        e.preventDefault();
        if (!form.checkValidity()) { form.classList.add('was-validated'); return; }
        const btn = form.querySelector('button[type=submit]');
        btn.disabled = true;
        try {
            const r = await fetch(URL_CXC, { method: 'POST', body: new FormData(form) }).then(r => r.json());
            if (!r.success) throw new Error(r.error || 'No se pudo registrar.');
            cambio = true;
            await cargar(document.getElementById('abFacturaId').value);
            Swal.fire({ icon: 'success', title: r.message, timer: 1800, showConfirmButton: false });
        } catch (err) {
            Swal.fire('Error', err.message, 'error');
        } finally { btn.disabled = false; }
    });

    // Editar un abono ya registrado (el monto no se cambia: si está mal, se anula y se registra de nuevo)
    document.getElementById('abLista').addEventListener('click', async e => {
        const eb = e.target.closest('.ab-editar');
        if (!eb) return;
        const c = cobros[eb.dataset.id];
        if (!c) return;
        const metodos = { transferencia: 'Transferencia', efectivo: 'Efectivo', cheque: 'Cheque', tarjeta: 'Tarjeta', otro: 'Otro' };
        const arch = (c.archivos || []).length ? c.archivos : (+c.con_comprobante ? [{ id: 'principal', nombre: 'Comprobante' }] : []);
        const r = await Swal.fire({
            title: 'Editar abono · ' + L(c.monto), showCancelButton: true, confirmButtonText: 'Guardar', cancelButtonText: 'Cancelar', width: 520,
            html: `<div class="text-start small">
                <div class="row g-2">
                    <div class="col-6"><label class="form-label mb-0">Fecha</label><input type="date" id="ecFecha" class="form-control form-control-sm" value="${esc(c.fecha)}" max="${hoy}"></div>
                    <div class="col-6"><label class="form-label mb-0">Método</label><select id="ecMetodo" class="form-select form-select-sm">${Object.entries(metodos).map(([k, t]) => `<option value="${k}" ${k === c.metodo ? 'selected' : ''}>${t}</option>`).join('')}</select></div>
                    <div class="col-12"><label class="form-label mb-0">Referencia</label><input type="text" id="ecRef" class="form-control form-control-sm" maxlength="100" value="${esc(c.referencia || '')}" placeholder="N.º de transferencia"></div>
                    ${CUENTAS.length ? `<div class="col-12"><label class="form-label mb-0">Depositado en</label><select id="ecCuenta" class="form-select form-select-sm"><option value="">— No registrar en banco —</option>${CUENTAS.map(x => `<option value="${x.id}" ${+x.id === +c.cuenta_id ? 'selected' : ''}>${esc(x.txt)}</option>`).join('')}</select></div>` : ''}
                    <div class="col-12"><label class="form-label mb-0">Nota</label><input type="text" id="ecNotas" class="form-control form-control-sm" maxlength="255" value="${esc(c.notas || '')}"></div>
                    <div class="col-12"><label class="form-label mb-0">Comprobantes</label>
                        ${arch.map(a => { const u = 'cobro_archivo.php?id=' + c.id + (a.id === 'principal' ? '' : '&a=' + a.id), pdf = /\.pdf$/i.test(a.nombre || ''); return `<div class="d-flex align-items-center gap-2 py-1 border-bottom"><a href="${u}" target="_blank" title="Abrir el comprobante">${pdf ? '<i class="bi bi-file-earmark-pdf text-danger fs-3"></i>' : `<img src="${u}" alt="" style="height:56px;max-width:90px;object-fit:cover;border:1px solid #dee2e6;border-radius:4px">`}</a><a href="${u}" target="_blank" class="flex-grow-1 text-truncate">${esc(a.nombre)}</a><div class="form-check mb-0"><input class="form-check-input ec-quitar" type="checkbox" value="${a.id}" id="ecq${a.id}"><label class="form-check-label" for="ecq${a.id}">Quitar</label></div></div>`; }).join('') || '<div class="text-muted">Sin comprobantes.</div>'}
                        <input type="file" id="ecArchivos" class="form-control form-control-sm mt-1" multiple accept="image/jpeg,image/png,image/webp,application/pdf">
                        <div class="form-text">Agregá uno o varios (máx. 10, 5 MB cada uno).</div></div>
                    <div class="col-12 text-muted">El monto no se cambia: si está mal, anulá el abono y registralo de nuevo.</div>
                </div></div>`,
            preConfirm: () => {
                if (!document.getElementById('ecFecha').value) { Swal.showValidationMessage('Escribí la fecha del pago.'); return false; }
                return { fecha: document.getElementById('ecFecha').value, metodo: document.getElementById('ecMetodo').value, ref: document.getElementById('ecRef').value,
                    cuenta: document.getElementById('ecCuenta') ? document.getElementById('ecCuenta').value : null, notas: document.getElementById('ecNotas').value,
                    quitar: [...document.querySelectorAll('.ec-quitar:checked')].map(i => i.value), archivos: [...document.getElementById('ecArchivos').files] };
            }
        });
        if (!r.isConfirmed) return;
        const fd = new FormData();
        fd.append('accion', 'editar_cobro'); fd.append('id', c.id); fd.append('fecha', r.value.fecha); fd.append('metodo', r.value.metodo);
        fd.append('referencia', r.value.ref); fd.append('notas', r.value.notas);
        if (r.value.cuenta !== null) fd.append('cuenta_id', r.value.cuenta);
        r.value.quitar.forEach(q => fd.append('quitar[]', q)); r.value.archivos.forEach(f => fd.append('comprobantes[]', f));
        const d = await fetch(URL_CXC, { method: 'POST', body: fd, credentials: 'same-origin' }).then(x => x.json()).catch(() => ({ success: false, error: 'Error de conexión.' }));
        if (!d.success) return Swal.fire('No se pudo editar', d.error, 'error');
        cambio = true;
        await cargar(document.getElementById('abFacturaId').value);
        Swal.fire({ icon: 'success', title: d.message, timer: 1500, showConfirmButton: false });
    });

    document.getElementById('abLista').addEventListener('click', e => {
        const b = e.target.closest('.ab-anular');
        if (!b) return;
        Swal.fire({ title: 'Anular abono', input: 'text', inputPlaceholder: 'Motivo (obligatorio)', showCancelButton: true,
            confirmButtonText: 'Anular', confirmButtonColor: '#dc2626', cancelButtonText: 'Cancelar', inputValidator: v => !v.trim() && 'Indica el motivo' })
            .then(async res => {
                if (!res.isConfirmed) return;
                const fd = new FormData();
                fd.append('accion', 'anular_cobro'); fd.append('id', b.dataset.id); fd.append('motivo', res.value);
                const r = await fetch(URL_CXC, { method: 'POST', body: fd }).then(r => r.json());
                if (!r.success) return Swal.fire('Error', r.error || 'No se pudo anular.', 'error');
                cambio = true;
                cargar(document.getElementById('abFacturaId').value);
            });
    });

    // Al cerrar, recargar para actualizar saldos y totales de la página
    modalEl.addEventListener('hidden.bs.modal', () => { if (cambio) location.reload(); });
})();
</script>
