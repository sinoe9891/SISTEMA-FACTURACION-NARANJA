/**
 * nomina-pago.js — Editar, anular y eliminar pagos de nómina (botones con data-nomina-accion="editar|anular|eliminar"
 * y data-id). Se usa en la ficha del colaborador, en «Pagos de nómina» y en «Gastos». Requiere SweetAlert2.
 * Anular deshace las cuotas descontadas y los bonos/viáticos liquidados, y deja la quincena libre.
 */
(() => {
    const URL_NP = (document.currentScript?.dataset.base || '') + 'includes/nomina_pago_accion.php';
    const esc = t => String(t ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const L = n => 'L ' + Number(n).toLocaleString('es-HN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const leer = async r => { const t = await r.text(); let d; try { d = JSON.parse(t); } catch (e) { throw new Error('Respuesta inesperada del servidor (' + r.status + ').'); } if (!d.success) throw new Error(d.error); return d; };
    const datos = id => fetch(URL_NP + '?vinculos=' + id).then(leer);
    const post = fd => fetch(URL_NP, { method: 'POST', body: fd }).then(leer);
    const listo = d => Swal.fire({ icon: 'success', title: 'Listo', text: d.message }).then(() => location.reload());
    const fallo = e => Swal.fire('No se pudo', e.message, 'error');

    async function editar(id) {
        let d;
        try { d = await datos(id); } catch (e) { return fallo(e); }
        const p = d.pago, met = ['transferencia', 'efectivo', 'cheque', 'tarjeta', 'otro'];
        const r = await Swal.fire({
            title: 'Editar pago de nómina', width: 560,
            html: `<div class="text-start small">
                <div class="mb-2 fw-semibold">${esc(p.descripcion)} · ${L(p.monto)}</div>
                <label class="form-label mb-1">Fecha del pago</label><input type="date" id="npFecha" class="form-control mb-2" value="${esc(p.fecha)}">
                <label class="form-label mb-1">Método</label><select id="npMetodo" class="form-select mb-2">${met.map(m => `<option value="${m}" ${m === p.metodo_pago ? 'selected' : ''}>${m[0].toUpperCase() + m.slice(1)}</option>`).join('')}</select>
                <label class="form-label mb-1">Notas / referencia</label><textarea id="npNotas" class="form-control mb-2" rows="2">${esc(p.notas)}</textarea>
                <label class="form-label mb-1">Reemplazar comprobante <span class="text-muted">(opcional${p.archivo_nombre ? ' · actual: ' + esc(p.archivo_nombre) : ''})</span></label>
                <input type="file" id="npArchivo" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp">
                <div class="text-muted mt-2">El monto, la quincena y los descuentos no se editan: si están mal, anula el pago y regístralo de nuevo.</div></div>`,
            showCancelButton: true, confirmButtonText: 'Guardar', cancelButtonText: 'Cancelar', focusConfirm: false,
            preConfirm: () => {
                const fd = new FormData();
                fd.append('accion', 'editar'); fd.append('id', id);
                fd.append('fecha', document.getElementById('npFecha').value);
                fd.append('metodo_pago', document.getElementById('npMetodo').value);
                fd.append('notas', document.getElementById('npNotas').value);
                const f = document.getElementById('npArchivo').files[0];
                if (f) fd.append('comprobante', f);
                return post(fd).catch(e => Swal.showValidationMessage(e.message));
            }
        });
        if (r.isConfirmed && r.value) listo(r.value);
    }

    async function anular(id) {
        let d;
        try { d = await datos(id); } catch (e) { return fallo(e); }
        const p = d.pago, rev = [];
        d.cuotas.forEach(q => rev.push(`Cuota ${q.numero_cuota} de ${esc(q.tipo)} «${esc(q.descripcion)}» (${L(q.monto)}) vuelve a pendiente`));
        d.bonos_viaticos.forEach(b => rev.push(`${b.tipo === 'bono' ? 'Bono' : 'Viático'} «${esc(b.descripcion)}» (${L(b.monto_total)}) vuelve a pendiente`));
        d.gastos_extra.forEach(g => rev.push(`Se anula también: ${esc(g.descripcion)} (${L(g.monto)})`));
        const r = await Swal.fire({
            title: 'Anular pago de nómina', icon: 'warning', width: 600,
            html: `<div class="text-start small">
                <div class="mb-2"><strong>${esc(p.descripcion)}</strong><br>${L(p.monto)} · ${esc(p.fecha.split('-').reverse().join('/'))}</div>
                ${rev.length ? '<div class="mb-1">Al anular:</div><ul class="mb-2">' + rev.map(t => `<li>${t}</li>`).join('') + '</ul>' : ''}
                <div class="mb-2">La quincena queda libre para registrarla de nuevo con la fecha correcta. El pago queda en el historial como «anulado».</div>
                <label class="form-label mb-1">Motivo *</label><input id="npMotivo" class="form-control" placeholder="Ej: fecha equivocada"></div>`,
            showCancelButton: true, confirmButtonText: 'Anular pago', cancelButtonText: 'Cancelar', confirmButtonColor: '#dc3545', focusConfirm: false,
            preConfirm: () => {
                const m = document.getElementById('npMotivo').value.trim();
                if (!m) return Swal.showValidationMessage('Escribe el motivo.');
                const fd = new FormData();
                fd.append('accion', 'anular'); fd.append('id', id); fd.append('motivo', m);
                return post(fd).catch(e => Swal.showValidationMessage(e.message));
            }
        });
        if (r.isConfirmed && r.value) listo(r.value);
    }

    async function eliminar(id) {
        const r = await Swal.fire({ title: '¿Eliminar definitivamente?', text: 'El pago anulado se borra del historial. No se puede deshacer.', icon: 'warning',
            showCancelButton: true, confirmButtonText: 'Eliminar', cancelButtonText: 'Cancelar', confirmButtonColor: '#dc3545' });
        if (!r.isConfirmed) return;
        const fd = new FormData();
        fd.append('accion', 'eliminar'); fd.append('id', id);
        post(fd).then(listo).catch(fallo);
    }

    document.addEventListener('click', e => {
        const b = e.target.closest('[data-nomina-accion]');
        if (!b) return;
        e.preventDefault(); e.stopPropagation();
        ({ editar, anular, eliminar })[b.dataset.nominaAccion]?.(+b.dataset.id);
    }, true);
})();
