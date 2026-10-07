/**
 * plan-pagos.js — Calculadora y editor del plan de pagos de un contrato.
 *
 *   const ed = PlanPagos('#contenedor', {
 *       conFactura: true,        // false = contrato con recibo (sin ISV)
 *       lineas: [],              // líneas guardadas [{id, tipo, concepto, fecha, monto, isv, total, vinculado, cobro}]
 *       concepto: 'Servicio…',   // concepto sugerido para las cuotas
 *       fecha: '2026-10-05',     // primera fecha sugerida
 *       cuota: 25500,            // monto sugerido por cuota (sin ISV)
 *   });
 *   ed.lineas();   // [{id, tipo, concepto, fecha, monto}] para guardar
 *   ed.conIsv();   // ¿lleva ISV 15 %?
 *
 * Calcula: anticipo (monto o % del total) + N cuotas (mensual, bimestral, trimestral, semestral o anual),
 * a partir del monto por cuota o del total del contrato. Después cada línea se puede editar, quitar o agregar.
 * Las líneas ya cobradas solo permiten cambiar concepto y fecha.
 */
function PlanPagos(contenedor, opciones) {
    const o = Object.assign({ conFactura: true, lineas: [], concepto: '', fecha: '', cuota: '' }, opciones || {});
    const raiz = typeof contenedor === 'string' ? document.querySelector(contenedor) : contenedor;
    const ISV = 0.15;
    const TIPOS = { anticipo: 'Anticipo', cuota: 'Cuota', etapa: 'Etapa', anualidad: 'Anualidad', otro: 'Otro' };
    const FREC = { 1: 'Mensual', 2: 'Cada 2 meses', 3: 'Trimestral', 6: 'Semestral', 12: 'Anual' };
    const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const num = v => { const n = parseFloat(String(v ?? '').replace(/,/g, '')); return isFinite(n) ? n : 0; };
    const r2 = v => Math.round(v * 100) / 100;
    const L = v => 'L ' + v.toLocaleString('es-HN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const hoy = new Date().toLocaleDateString('sv-SE');
    // Suma meses respetando el día (5 de enero + 1 mes = 5 de febrero; 31 de enero + 1 = 28/29 de febrero)
    const sumarMeses = (f, m) => {
        const [y, mm, d] = f.split('-').map(Number);
        const t = new Date(y, mm - 1 + m, 1);
        const ult = new Date(t.getFullYear(), t.getMonth() + 1, 0).getDate();
        return `${t.getFullYear()}-${String(t.getMonth() + 1).padStart(2, '0')}-${String(Math.min(d, ult)).padStart(2, '0')}`;
    };
    const mesTxt = f => MESES[+f.slice(5, 7) - 1] + ' ' + f.slice(0, 4);

    let filas = (o.lineas || []).map(l => ({ id: +l.id || 0, tipo: l.tipo || 'cuota', concepto: l.concepto || '', fecha: l.fecha || '', monto: num(l.monto), vinculado: !!l.vinculado, cobro: l.cobro || '' }));
    let conIsv = o.conFactura ? (o.lineas.length ? o.lineas.some(l => num(l.isv) > 0) : true) : false;

    raiz.innerHTML = `
      <div class="pp-calc border rounded p-3 mb-3" style="background:#f8fafc">
        <div class="fw-semibold mb-2"><i class="bi bi-calculator me-1"></i>Calcular el plan</div>
        <div class="row g-2 align-items-end">
          <div class="col-6 col-md-3"><label class="form-label small mb-1">Total del contrato <span class="text-muted">(sin ISV)</span></label>
            <input type="text" inputmode="decimal" class="form-control form-control-sm" data-pp="total" placeholder="Opcional"></div>
          <div class="col-6 col-md-2"><label class="form-label small mb-1">Anticipo</label>
            <div class="input-group input-group-sm"><input type="text" inputmode="decimal" class="form-control" data-pp="anticipo" placeholder="0">
              <select class="form-select" data-pp="antTipo" style="max-width:62px"><option value="L">L</option><option value="%">%</option></select></div></div>
          <div class="col-6 col-md-2"><label class="form-label small mb-1">Fecha del anticipo</label>
            <input type="date" class="form-control form-control-sm" data-pp="antFecha"></div>
          <div class="col-6 col-md-1"><label class="form-label small mb-1">Cuotas</label>
            <input type="number" min="1" max="120" class="form-control form-control-sm" data-pp="n" value="12"></div>
          <div class="col-6 col-md-2"><label class="form-label small mb-1">Frecuencia</label>
            <select class="form-select form-select-sm" data-pp="frec">${Object.entries(FREC).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}</select></div>
          <div class="col-6 col-md-2"><label class="form-label small mb-1">Monto por cuota <span class="text-muted">(sin ISV)</span></label>
            <input type="text" inputmode="decimal" class="form-control form-control-sm" data-pp="cuota" placeholder="Auto"></div>
          <div class="col-6 col-md-3"><label class="form-label small mb-1">Primera cuota</label>
            <input type="date" class="form-control form-control-sm" data-pp="fecha"></div>
          <div class="col-12 col-md-6"><label class="form-label small mb-1">Concepto de las cuotas</label>
            <input type="text" class="form-control form-control-sm" data-pp="concepto" maxlength="250" placeholder="Ej: Servicio de marketing digital"></div>
          <div class="col-12 col-md-3 d-grid"><button type="button" class="btn btn-sm btn-primary" data-pp="generar"><i class="bi bi-magic me-1"></i>Calcular plan</button></div>
        </div>
        <div class="small text-muted mt-2" data-pp="ayuda">Llena el <strong>monto por cuota</strong> o el <strong>total del contrato</strong> (el resto se divide entre las cuotas). El anticipo es opcional.</div>
      </div>
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <div class="form-check mb-0"><input class="form-check-input" type="checkbox" data-pp="isv" id="ppIsv${Math.random().toString(36).slice(2, 7)}">
          <label class="form-check-label small" data-pp="isvLbl">Lleva ISV 15 % (se factura)</label></div>
        <button type="button" class="btn btn-sm btn-outline-primary" data-pp="agregar"><i class="bi bi-plus-lg me-1"></i>Agregar línea</button>
      </div>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0 pp-tabla">
        <thead class="table-light"><tr><th style="width:34px">#</th><th style="width:118px">Tipo</th><th>Concepto</th><th style="width:150px">Fecha de pago</th><th class="text-end" style="width:140px">Monto</th><th class="text-end" style="width:110px">ISV</th><th class="text-end" style="width:120px">Total</th><th style="width:34px"></th></tr></thead>
        <tbody data-pp="filas"></tbody>
        <tfoot data-pp="pie"></tfoot>
      </table></div>`;
    const $ = k => raiz.querySelector(`[data-pp="${k}"]`);
    $('isv').checked = conIsv;
    $('isv').disabled = !o.conFactura;
    if (!o.conFactura) $('isvLbl').textContent = 'Sin ISV: este contrato se cobra con recibo';
    $('fecha').value = o.fecha || hoy;
    $('antFecha').value = o.fecha || hoy;
    $('concepto').value = o.concepto || '';
    if (num(o.cuota) > 0) $('cuota').value = r2(num(o.cuota));

    function pintar() {
        const tb = $('filas');
        tb.innerHTML = filas.map((f, i) => {
            const isv = conIsv ? r2(f.monto * ISV) : 0, bloq = f.vinculado;
            return `<tr data-i="${i}" class="${bloq ? 'table-success' : ''}">
              <td class="text-muted small">${i + 1}</td>
              <td><select class="form-select form-select-sm" data-c="tipo" ${bloq ? 'disabled' : ''}>${Object.entries(TIPOS).map(([k, v]) => `<option value="${k}" ${k === f.tipo ? 'selected' : ''}>${v}</option>`).join('')}</select></td>
              <td><input type="text" class="form-control form-control-sm" data-c="concepto" maxlength="300" value="${esc(f.concepto)}">${bloq ? `<div class="small text-success mt-1"><i class="bi bi-check-circle-fill"></i> Cobrada · ${esc(f.cobro)}</div>` : ''}</td>
              <td><input type="date" class="form-control form-control-sm" data-c="fecha" value="${esc(f.fecha)}"></td>
              <td><input type="text" inputmode="decimal" class="form-control form-control-sm text-end" data-c="monto" value="${f.monto ? f.monto.toFixed(2) : ''}" ${bloq ? 'readonly title="Ya cobrada: no se cambia el monto"' : ''}></td>
              <td class="text-end small text-muted">${isv ? isv.toLocaleString('es-HN', { minimumFractionDigits: 2 }) : '—'}</td>
              <td class="text-end fw-semibold">${r2(f.monto + isv).toLocaleString('es-HN', { minimumFractionDigits: 2 })}</td>
              <td>${bloq ? '' : '<button type="button" class="btn btn-link btn-sm text-danger p-0" data-c="quitar" title="Quitar"><i class="bi bi-x-lg"></i></button>'}</td></tr>`;
        }).join('') || `<tr><td colspan="8" class="text-center text-muted py-3">Sin líneas. Calcula el plan o agrega líneas a mano.</td></tr>`;
        pie();
    }
    function pie() {
        const m = r2(filas.reduce((a, f) => a + f.monto, 0)), i = conIsv ? r2(filas.reduce((a, f) => a + r2(f.monto * ISV), 0)) : 0;
        $('pie').innerHTML = filas.length ? `<tr class="fw-semibold"><td colspan="4" class="text-end">Total del contrato · ${filas.length} pago${filas.length > 1 ? 's' : ''}</td>
            <td class="text-end">${L(m)}</td><td class="text-end">${i ? L(i) : '—'}</td><td class="text-end text-success">${L(r2(m + i))}</td><td></td></tr>` : '';
    }

    $('filas').addEventListener('input', e => {
        const tr = e.target.closest('tr[data-i]'); if (!tr) return;
        const f = filas[+tr.dataset.i], c = e.target.dataset.c;
        if (c === 'monto') { f.monto = num(e.target.value); const isv = conIsv ? r2(f.monto * ISV) : 0; tr.cells[5].textContent = isv ? isv.toLocaleString('es-HN', { minimumFractionDigits: 2 }) : '—'; tr.cells[6].textContent = r2(f.monto + isv).toLocaleString('es-HN', { minimumFractionDigits: 2 }); pie(); }
        else if (c) f[c] = e.target.value;
    });
    $('filas').addEventListener('change', e => { const tr = e.target.closest('tr[data-i]'); if (tr && e.target.dataset.c === 'tipo') filas[+tr.dataset.i].tipo = e.target.value; });
    $('filas').addEventListener('click', e => {
        const b = e.target.closest('[data-c="quitar"]'); if (!b) return;
        filas.splice(+b.closest('tr').dataset.i, 1); pintar();
    });
    $('agregar').addEventListener('click', () => {
        const ult = filas[filas.length - 1];
        filas.push({ id: 0, tipo: 'cuota', concepto: $('concepto').value || '', fecha: ult ? sumarMeses(ult.fecha, +$('frec').value || 1) : $('fecha').value, monto: ult ? ult.monto : num($('cuota').value), vinculado: false, cobro: '' });
        pintar();
    });
    $('isv').addEventListener('change', () => { conIsv = $('isv').checked; pintar(); });

    $('generar').addEventListener('click', () => {
        const n = Math.max(1, Math.min(120, parseInt($('n').value, 10) || 0));
        const frec = +$('frec').value || 1, total = num($('total').value);
        let ant = num($('anticipo').value);
        if ($('antTipo').value === '%') {
            if (!total) return Swal.fire('Falta el total', 'Para calcular el anticipo en % indica el total del contrato.', 'info');
            ant = r2(total * ant / 100);
        }
        let cuota = num($('cuota').value);
        if (!cuota) {
            if (!total) return Swal.fire('Falta un dato', 'Indica el monto por cuota o el total del contrato.', 'info');
            if (total - ant <= 0) return Swal.fire('Revisa los montos', 'El anticipo no puede ser igual o mayor que el total.', 'warning');
            cuota = r2((total - ant) / n);
        }
        const f0 = $('fecha').value || hoy, concepto = $('concepto').value.trim() || 'Cuota';
        const nuevas = [];
        if (ant > 0) nuevas.push({ id: 0, tipo: 'anticipo', concepto: 'Anticipo' + ($('antTipo').value === '%' ? ` (${num($('anticipo').value)} %)` : ''), fecha: $('antFecha').value || f0, monto: ant, vinculado: false, cobro: '' });
        for (let k = 0; k < n; k++) {
            const fecha = sumarMeses(f0, k * frec);
            nuevas.push({ id: 0, tipo: frec === 12 ? 'anualidad' : 'cuota', concepto: `${concepto} — ${frec === 12 ? 'año ' + fecha.slice(0, 4) : mesTxt(fecha)} (${k + 1}/${n})`, fecha, monto: cuota, vinculado: false, cobro: '' });
        }
        // Si el total no se divide exacto, la diferencia de centavos va en la última cuota
        if (total && !num($('cuota').value)) {
            const dif = r2(total - nuevas.reduce((a, f) => a + f.monto, 0));
            if (dif) nuevas[nuevas.length - 1].monto = r2(nuevas[nuevas.length - 1].monto + dif);
        }
        const cobradas = filas.filter(f => f.vinculado);
        const aplicar = () => { filas = [...cobradas, ...nuevas].sort((a, b) => a.fecha.localeCompare(b.fecha)); pintar(); };
        if (filas.some(f => !f.vinculado)) {
            Swal.fire({ title: '¿Reemplazar el plan?', text: 'Las líneas pendientes se reemplazan por el nuevo cálculo.' + (cobradas.length ? ` Las ${cobradas.length} ya cobradas se conservan.` : ''), icon: 'question', showCancelButton: true, confirmButtonText: 'Reemplazar', cancelButtonText: 'Cancelar' })
                .then(r => { if (r.isConfirmed) aplicar(); });
        } else aplicar();
    });

    pintar();
    return {
        lineas: () => filas.map(f => ({ id: f.id || undefined, tipo: f.tipo, concepto: f.concepto.trim(), fecha: f.fecha, monto: r2(f.monto) })),
        conIsv: () => conIsv,
        vacio: () => !filas.length,
    };
}
