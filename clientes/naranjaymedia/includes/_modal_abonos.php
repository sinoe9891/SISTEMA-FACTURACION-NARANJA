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
    let cambio = false;

    async function cargar(id) {
        const r = await fetch(URL_CXC + '?factura_id=' + id).then(r => r.json());
        if (!r.success) { Swal.fire('Error', r.error || 'No se pudieron cargar los abonos.', 'error'); return false; }
        const f = r.factura;
        document.getElementById('abFactura').textContent = f.correlativo;
        document.getElementById('abTotal').textContent = L(f.total);
        document.getElementById('abAbonado').textContent = L(f.abonado);
        document.getElementById('abSaldo').textContent = L(f.saldo);
        document.getElementById('abLista').innerHTML = r.cobros.length ? r.cobros.map(c => `
            <tr class="${+c.anulado ? 'text-decoration-line-through text-muted' : ''}">
                <td>${esc(c.fecha.split('-').reverse().join('/'))}</td>
                <td>${esc(c.metodo)}${c.banco ? '<br><small>' + esc(c.banco + ' ' + c.cuenta_numero) + '</small>' : ''}</td>
                <td>${esc(c.referencia || '—')}</td>
                <td class="text-end">${L(c.monto)}</td>
                <td class="text-end">${+c.anulado ? '<small>' + esc(c.motivo_anulacion || 'Anulado') + '</small>'
                    : (esAdmin ? `<button type="button" class="btn btn-link btn-sm p-0 text-danger ab-anular" data-id="${c.id}" title="Anular abono"><i class="bi bi-x-circle"></i></button>` : '')}</td>
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
