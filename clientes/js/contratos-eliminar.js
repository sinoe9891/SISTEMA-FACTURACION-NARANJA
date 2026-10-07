/**
 * contratos-eliminar.js — Diálogo para eliminar uno o varios contratos (Lista de contratos y Editar contrato).
 *   eliminarContratos([ids], { despues: url })
 * Muestra cuántas facturas, recibos, pagos anticipados y pagos del plan tiene cada contrato y pregunta qué hacer
 * con las facturas: dejarlas sin contrato o pasarlas (con recibos y anticipos) a otro contrato del mismo cliente.
 */
window.eliminarContratos = async function (ids, opciones = {}) {
    const URL = 'includes/contrato_eliminar.php';
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const L = n => 'L ' + Number(n).toLocaleString('es-HN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const leer = async r => { const t = await r.text(); let d; try { d = JSON.parse(t); } catch (e) { throw new Error('Respuesta inesperada del servidor (' + r.status + ').'); } if (!d.success) throw new Error(d.error); return d; };
    if (!ids.length) return Swal.fire('Selecciona contratos', 'Marca al menos un contrato.', 'info');
    let info;
    try { info = await fetch(URL + '?info=1&ids=' + ids.join(',')).then(leer); } catch (e) { return Swal.fire('No se pudo', e.message, 'error'); }

    const cs = info.contratos;
    const nFact = cs.reduce((s, c) => s + +c.facturas, 0), nCaja = cs.reduce((s, c) => s + +c.recibos + +c.anticipos, 0);
    const filas = cs.map(c => `<tr><td class="text-nowrap">#${c.id}</td><td>${esc(c.cliente)}<div class="small text-muted">${esc(c.nombre_contrato)}</div></td>
        <td class="text-end">${+c.facturas || '—'}</td><td class="text-end">${(+c.recibos + +c.anticipos) || '—'}</td><td class="text-end">${+c.plan || '—'}</td></tr>`).join('');
    const opDest = info.destinos.map(d => `<option value="${d.id}">#${d.id} · ${esc(d.nombre_contrato)} · ${esc(d.estado)} · ${L(d.monto)}</option>`).join('');
    const puedeReasignar = info.un_cliente && info.destinos.length;
    const necesitaContrato = nCaja > 0;

    const html = `<div class="text-start small">
        <table class="table table-sm align-middle mb-2"><thead><tr><th>#</th><th>Contrato</th><th class="text-end">Facturas</th><th class="text-end">Recibos</th><th class="text-end">Plan</th></tr></thead><tbody>${filas}</tbody></table>
        ${nFact || nCaja ? `<div class="fw-semibold mb-1">¿Qué pasa con ${nFact ? nFact + ' factura(s)' : ''}${nFact && nCaja ? ' y ' : ''}${nCaja ? nCaja + ' recibo(s)/anticipo(s)' : ''}?</div>
        <div class="form-check"><input class="form-check-input" type="radio" name="ceModo" id="ceHuerf" value="huerfanas" ${necesitaContrato ? 'disabled' : 'checked'}>
            <label class="form-check-label" for="ceHuerf">Dejar las facturas sin contrato <span class="text-muted">(siguen en el historial y en cuentas por cobrar)</span>
            ${necesitaContrato ? '<div class="text-danger">No se puede: los recibos y pagos anticipados necesitan un contrato.</div>' : ''}</label></div>
        <div class="form-check mt-1"><input class="form-check-input" type="radio" name="ceModo" id="ceReas" value="reasignar" ${puedeReasignar ? '' : 'disabled'} ${necesitaContrato && puedeReasignar ? 'checked' : ''}>
            <label class="form-check-label" for="ceReas">Pasarlas a otro contrato del mismo cliente</label>
            ${puedeReasignar ? `<select class="form-select form-select-sm mt-1" id="ceDestino">${opDest}</select>`
                : `<div class="text-muted">${info.un_cliente ? 'Este cliente no tiene otro contrato.' : 'Elige contratos de un solo cliente para poder pasarlas.'}</div>`}</div>`
        : '<div class="text-muted">No tiene facturas, recibos ni pagos anticipados.</div>'}
        <div class="text-danger mt-2">Se borran el contrato, sus servicios y su plan de pagos. No se puede deshacer (queda un respaldo en el servidor).</div></div>`;

    const r = await Swal.fire({
        title: cs.length === 1 ? '¿Eliminar el contrato #' + cs[0].id + '?' : '¿Eliminar ' + cs.length + ' contratos?', html, icon: 'warning', width: 680,
        showCancelButton: true, confirmButtonText: '<i class="bi bi-trash me-1"></i>Eliminar', cancelButtonText: 'Cancelar', confirmButtonColor: '#dc2626', focusCancel: true,
        preConfirm: () => {
            const modo = document.querySelector('input[name=ceModo]:checked')?.value || 'huerfanas';
            if ((nFact || nCaja) && !document.querySelector('input[name=ceModo]:checked')) { Swal.showValidationMessage('Elige qué hacer con las facturas.'); return false; }
            const fd = new FormData();
            ids.forEach(i => fd.append('ids[]', i));
            fd.append('facturas', modo);
            if (modo === 'reasignar') fd.append('destino_id', document.getElementById('ceDestino')?.value || '');
            return fetch(URL, { method: 'POST', body: fd }).then(leer).catch(e => Swal.showValidationMessage(e.message));
        }
    });
    if (!r.isConfirmed || !r.value) return;
    await Swal.fire({ icon: 'success', title: 'Listo', text: r.value.message });
    if (opciones.despues) location.href = opciones.despues; else location.reload();
};
