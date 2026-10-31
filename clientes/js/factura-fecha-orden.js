// factura-fecha-orden.js — Nueva / Editar factura: la fecha de emisión se puede poner libre, pero en un mismo talonario
// cada factura debe tener fecha igual o posterior a la anterior y no posterior a la siguiente. Debajo del campo se ve
// si está en orden (verde) o no (ámbar, con la explicación y una fecha sugerida); al guardar fuera de orden se pregunta.
//   FacturaFechaOrden({ form, input, vecinas: () => ({ anterior, siguiente }), original, alElegir })
//   anterior / siguiente: { correlativo, fecha: 'Y-m-d H:i' } o null; original: fecha que tenía la factura (o null).
window.FacturaFechaOrden = function ({ form, input, vecinas, original = null, alElegir = () => {} }) {
    const norm = v => String(v || '').replace('T', ' ').slice(0, 16);
    const aCampo = f => f.replace(' ', 'T');
    const ver = f => {
        const [d, h] = f.split(' '), [y, m, dd] = d.split('-'); let [hh, mi] = h.split(':').map(Number);
        const ap = hh >= 12 ? 'p. m.' : 'a. m.'; hh = hh % 12 || 12;
        return `${dd}/${m}/${y} ${String(hh).padStart(2, '0')}:${String(mi).padStart(2, '0')} ${ap}`;
    };
    const corto = c => String(c).split('-').pop().replace(/^0+(?=\d{4})/, '');
    const esc = t => String(t).replace(/[<>&"]/g, x => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;' }[x]));
    const ahora = () => { const d = new Date(), p = n => String(n).padStart(2, '0'); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`; };
    const masUnMinuto = f => { const d = new Date(aCampo(f)); d.setMinutes(d.getMinutes() + 1); const p = n => String(n).padStart(2, '0'); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`; };

    const nota = document.createElement('div');
    nota.className = 'small mt-1';
    input.insertAdjacentElement('afterend', nota);

    // { ok, motivo (html), sugerida }
    const revisar = () => {
        const f = norm(input.value), { anterior: a, siguiente: s } = vecinas() || {};
        if (!f) return { ok: true };
        const motivos = [];
        if (a && f < a.fecha) motivos.push(`La factura anterior <strong>${esc(a.correlativo)}</strong> tiene fecha <strong>${ver(a.fecha)}</strong>; esta no puede tener una fecha más vieja.`);
        if (s && f > s.fecha) motivos.push(`La factura siguiente <strong>${esc(s.correlativo)}</strong> tiene fecha <strong>${ver(s.fecha)}</strong>; esta no puede tener una fecha posterior.`);
        if (!motivos.length) return { ok: true, a, s };
        const cabe = x => (!a || x >= a.fecha) && (!s || x <= s.fecha);
        let sugerida = [original && norm(original), ahora(), a && masUnMinuto(a.fecha), s && s.fecha].filter(Boolean).find(cabe);
        if (!sugerida) {   // la anterior y la siguiente ya están desordenadas entre sí
            motivos.push(`Además, la ${esc(corto(a.correlativo))} y la ${esc(corto(s.correlativo))} ya están fuera de orden entre ellas: conviene revisarlas.`);
            sugerida = a.fecha;
        }
        return { ok: false, motivo: motivos.join('<br>'), sugerida };
    };

    const usar = f => { input.value = aCampo(f); alElegir(); pintar(); };
    const pintar = () => {
        const r = revisar(), { anterior: a, siguiente: s } = vecinas() || {};
        if (r.ok) {
            const entre = [a && `después de la ${esc(corto(a.correlativo))} (${ver(a.fecha)})`, s && `antes de la ${esc(corto(s.correlativo))} (${ver(s.fecha)})`].filter(Boolean);
            nota.innerHTML = entre.length ? `<span class="text-success"><i class="bi bi-check-circle me-1"></i>En orden: ${entre.join(' y ')}.</span>` : '';
            return;
        }
        nota.innerHTML = `<div class="alert alert-warning py-2 px-2 mb-0"><i class="bi bi-exclamation-triangle me-1"></i>${r.motivo}`
            + `<div class="mt-1">Los correlativos deben ir en orden de fecha. Sugerida: <strong>${ver(r.sugerida)}</strong> `
            + `<button type="button" class="btn btn-sm btn-outline-dark py-0 ms-1" data-ffo-usar>Usar esta</button></div></div>`;
        nota.querySelector('[data-ffo-usar]').onclick = () => usar(r.sugerida);
    };
    ['input', 'change'].forEach(ev => input.addEventListener(ev, pintar));

    // Al guardar fuera de orden: explicar y dejar elegir (corre antes que el envío de la página)
    let aceptada = false;
    form.addEventListener('submit', async e => {
        if (aceptada) { aceptada = false; return; }
        const r = revisar();
        if (r.ok) return;
        e.preventDefault(); e.stopImmediatePropagation();
        const resp = await Swal.fire({ icon: 'warning', title: 'La fecha no sigue el orden de las facturas',
            html: `<div class="text-start small">${r.motivo}<p class="mt-2 mb-0">Fecha que pusiste: <strong>${ver(norm(input.value))}</strong><br>Fecha sugerida: <strong>${ver(r.sugerida)}</strong></p></div>`,
            showDenyButton: true, showCancelButton: true, confirmButtonText: 'Usar la sugerida', denyButtonText: 'Dejar mi fecha', cancelButtonText: 'Revisar' });
        if (resp.isDismissed) return;
        if (resp.isConfirmed) usar(r.sugerida);
        aceptada = true;
        form.requestSubmit();
    }, true);

    pintar();
    return { pintar };
};
