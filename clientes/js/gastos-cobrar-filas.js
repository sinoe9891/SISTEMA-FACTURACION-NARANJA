// gastos-cobrar-filas.js — Nueva / Editar factura: al marcar un gasto de cobrarle al cliente (hosting, dominio, pauta…)
// se agrega su línea al detalle (producto parecido, cantidad 1, precio a cobrar y la descripción del gasto);
// al desmarcarlo se quita esa línea. Las casillas llevan data-desc y data-precio (en lempiras).
//   GastosCobrarFilas({ agregar: () => …, recalcular: () => …, aMoneda: l => … })
window.GastosCobrarFilas = function ({ agregar, recalcular, aMoneda }) {
    const norm = t => String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    // Palabras del gasto → palabras del producto (la primera regla que coincide)
    const REGLAS = [
        [['meta ads', 'facebook', 'instagram'], ['meta ads']],
        [['google ads'], ['google ads']],
        [['tiktok'], ['tiktok']],
        [['google cloud'], ['google cloud']],
        [['app store'], ['app store']],
        [['play store'], ['play store']],
        [['hosting', 'dominio'], ['hosting y dominio', 'hosting web', 'hosting']],
    ];
    const producto = (sel, desc) => {
        const d = norm(desc), ops = [...sel.options].filter(o => o.value);
        for (const [gasto, prod] of REGLAS) {
            if (!gasto.some(p => d.includes(p))) continue;
            for (const p of prod) { const o = ops.find(x => norm(x.text).includes(p)); if (o) return o; }
        }
        return null;
    };
    const vacia = it => !it.querySelector('select[name$="[id]"]')?.value && !(parseFloat(it.querySelector('input[name$="[precio]"]')?.value) || 0);

    document.addEventListener('change', e => {
        const cb = e.target.closest('input[name="gastos_cobrar[]"]');
        if (!cb) return;
        const cont = document.getElementById('productos-container');
        const fila = cont.querySelector(`.producto-item[data-gc="${CSS.escape(cb.value)}"]`);
        if (!cb.checked) {   // se desmarca: se quita la línea que agregó (si es la única, se deja en blanco)
            if (fila) {
                if (cont.querySelectorAll('.producto-item').length > 1) fila.remove();
                else { delete fila.dataset.gc; fila.querySelectorAll('input[name$="[precio]"], input[name$="[precio_unitario]"]').forEach(i => i.value = ''); }
                recalcular();
            }
            return;
        }
        if (fila) return;
        let item = [...cont.querySelectorAll('.producto-item')].find(vacia);
        if (!item) { agregar(); item = cont.querySelector('.producto-item:last-child'); }
        if (!item) return;
        item.dataset.gc = cb.value;
        const sel = item.querySelector('select[name$="[id]"]');
        const op = sel && producto(sel, cb.dataset.desc);
        if (op) { sel.value = op.value; sel.dispatchEvent(new Event('change', { bubbles: true })); }
        // Después del cambio de producto (que pone su precio de catálogo): el precio a cobrar del gasto
        const precio = Math.round(aMoneda(parseFloat(cb.dataset.precio) || 0) * 100) / 100;
        const cant = item.querySelector('input[name$="[cantidad]"]');
        if (cant) cant.value = 1;
        const pu = item.querySelector('input[name$="[precio_unitario]"]'), pt = item.querySelector('input[name$="[precio]"]');
        if (pu) pu.value = precio.toFixed(2);
        if (pt) pt.value = precio.toFixed(2);
        const det = item.querySelector('textarea[name$="[detalles]"], textarea[name$="[descripcion_html]"]');
        if (det) det.value = String(cb.dataset.desc || '').replace(/^Gasto operativo · /, '');
        recalcular();
        if (!op && sel) {
            item.scrollIntoView({ behavior: 'smooth', block: 'center' });
            if (window.Swal) Swal.fire({ toast: true, position: 'top-end', icon: 'info', title: 'Línea agregada: elegí el producto o servicio.', timer: 3500, showConfirmButton: false });
        }
    });
};
