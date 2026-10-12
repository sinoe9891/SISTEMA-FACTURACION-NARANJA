/**
 * lista-vinetas.js — Editor de viñetas (Incluye / Condiciones de las cotizaciones).
 *   ListaVinetas(textarea, { placeholderTitulo, placeholderTexto, sugerencias: ['Forma de pago', …] })
 * Cada viñeta es una fila con un título opcional (sale en negrita en el PDF) y el texto. El <textarea> original se
 * oculta y guarda el contenido como siempre: una viñeta por línea y «Título: texto» cuando lleva título.
 * Filas: agregar, quitar, subir y bajar; Enter en el texto crea la siguiente viñeta.
 */
function ListaVinetas(ta, opciones) {
    if (!ta || ta.__vinetas) return;
    ta.__vinetas = true;
    const o = Object.assign({ placeholderTitulo: 'Título (opcional)', placeholderTexto: 'Escribe la viñeta…', sugerencias: [] }, opciones || {});
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const listaId = 'lv' + Math.random().toString(36).slice(2, 8);
    const caja = document.createElement('div');
    caja.className = 'lv-caja';
    caja.innerHTML = `<div class="lv-filas"></div>
        <button type="button" class="btn btn-sm btn-outline-primary lv-agregar"><i class="bi bi-plus-lg me-1"></i>Agregar viñeta</button>
        ${o.sugerencias.length ? `<datalist id="${listaId}">${o.sugerencias.map(s => `<option value="${esc(s)}">`).join('')}</datalist>` : ''}`;
    ta.style.display = 'none';
    ta.insertAdjacentElement('afterend', caja);
    const filas = caja.querySelector('.lv-filas');

    const leer = () => String(ta.value || '').split(/\r?\n/).map(l => l.trim().replace(/^[-•*]\s*/, '')).filter(Boolean).map(l => {
        const m = l.match(/^([^:]{2,40}):\s*(.+)$/);
        return m ? { t: m[1].trim(), x: m[2].trim() } : { t: '', x: l };
    });
    const guardar = () => {
        ta.value = Array.from(filas.querySelectorAll('.lv-fila')).map(f => {
            const t = f.querySelector('.lv-t').value.replace(/:/g, '').trim(), x = f.querySelector('.lv-x').value.replace(/\r?\n/g, ' ').trim();
            return x ? (t ? `${t}: ${x}` : x) : '';
        }).filter(Boolean).join('\n');
        ta.dispatchEvent(new Event('input', { bubbles: true }));
    };
    // El texto crece con lo que se escribe (sin barra de desplazamiento)
    const alto = x => { x.style.height = 'auto'; x.style.height = x.scrollHeight + 2 + 'px'; };
    const fila = (d = { t: '', x: '' }, despues = null) => {
        const f = document.createElement('div');
        f.className = 'lv-fila';
        f.innerHTML = `<span class="lv-punto">•</span>
            <input type="text" class="form-control form-control-sm lv-t" maxlength="40" placeholder="${esc(o.placeholderTitulo)}" value="${esc(d.t)}" ${o.sugerencias.length ? `list="${listaId}"` : ''}>
            <textarea class="form-control form-control-sm lv-x" rows="1" placeholder="${esc(o.placeholderTexto)}">${esc(d.x)}</textarea>
            <span class="lv-acc">
                <button type="button" class="btn btn-sm btn-link p-0 lv-sub" title="Subir"><i class="bi bi-arrow-up"></i></button>
                <button type="button" class="btn btn-sm btn-link p-0 lv-baj" title="Bajar"><i class="bi bi-arrow-down"></i></button>
                <button type="button" class="btn btn-sm btn-link p-0 text-danger lv-del" title="Quitar"><i class="bi bi-x-lg"></i></button>
            </span>`;
        if (despues) despues.after(f); else filas.appendChild(f);
        alto(f.querySelector('.lv-x'));
        return f;
    };
    const pintar = () => { filas.innerHTML = ''; const v = leer(); (v.length ? v : [{ t: '', x: '' }]).forEach(d => fila(d)); };

    filas.addEventListener('input', e => { if (e.target.matches('.lv-x')) alto(e.target); guardar(); });
    // Al mostrarse (p. ej. dentro de una ventana que estaba oculta) se recalcula el alto
    new ResizeObserver(() => filas.querySelectorAll('.lv-x').forEach(alto)).observe(filas);
    filas.addEventListener('click', e => {
        const f = e.target.closest('.lv-fila');
        if (!f) return;
        if (e.target.closest('.lv-del')) { if (filas.children.length > 1) f.remove(); else f.querySelectorAll('input').forEach(i => i.value = ''); }
        else if (e.target.closest('.lv-sub') && f.previousElementSibling) f.previousElementSibling.before(f);
        else if (e.target.closest('.lv-baj') && f.nextElementSibling) f.nextElementSibling.after(f);
        else return;
        guardar();
    });
    filas.addEventListener('keydown', e => {
        if (e.key !== 'Enter' || !e.target.matches('.lv-t, .lv-x')) return;
        e.preventDefault();
        const f = e.target.closest('.lv-fila');
        if (e.target.matches('.lv-t')) return f.querySelector('.lv-x').focus();
        fila(undefined, f).querySelector('.lv-x').focus();
    });
    caja.querySelector('.lv-agregar').addEventListener('click', () => fila().querySelector('.lv-x').focus());
    // Si el código cambia el textarea (p. ej. «restablecer»), se vuelve a pintar
    ta.addEventListener('vinetas:recargar', pintar);
    pintar();
}
