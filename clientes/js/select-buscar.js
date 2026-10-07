/**
 * select-buscar.js — Convierte un <select data-buscar> en un buscador con autocompletado (sin librerías).
 * El <select> original se queda en el formulario (oculto) y sigue siendo la fuente de verdad: al elegir se pone su
 * valor y se dispara «change», así que la lógica existente de cada página no cambia. Se actualiza solo cuando:
 *   - cambian sus opciones (p. ej. al cargar los productos del cliente),
 *   - el código le pone otro valor (select.value = … / selectedIndex = …),
 *   - se clona una fila con el select (agregar línea): se arma de nuevo para la copia.
 * Búsqueda sin acentos ni mayúsculas, por cualquier parte del texto. Teclado: ↑ ↓ Enter Esc.
 */
(function () {
    const vivos = new WeakMap();   // select → widget armado
    const norm = t => String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    function armar(sel) {
        if (vivos.has(sel)) return;
        // Copia de una fila clonada: quitar el widget copiado (no tiene eventos)
        const viejo = sel.nextElementSibling;
        if (viejo && viejo.classList.contains('sb-widget')) viejo.remove();

        const w = document.createElement('div');
        w.className = 'sb-widget';
        w.innerHTML = '<input type="text" class="form-control sb-input" autocomplete="off" spellcheck="false">'
            + '<button type="button" class="sb-limpiar" tabindex="-1" aria-label="Quitar">&times;</button>'
            + '<div class="sb-lista" role="listbox" hidden></div>';
        sel.insertAdjacentElement('afterend', w);
        sel.classList.add('sb-oculto');
        sel.tabIndex = -1;
        const inp = w.querySelector('.sb-input'), lista = w.querySelector('.sb-lista'), limpiar = w.querySelector('.sb-limpiar');
        const placeholder = () => (sel.options[0] && sel.options[0].value === '' ? sel.options[0].text.trim() : '') || 'Buscar…';
        let activo = -1, filtradas = [];

        const etiqueta = () => {
            const o = sel.selectedOptions[0];
            inp.value = o && o.value !== '' ? o.text.trim().replace(/\s+/g, ' ') : '';
            inp.placeholder = placeholder();
            limpiar.hidden = !inp.value || sel.disabled || sel.required;
            inp.disabled = sel.disabled;
            w.classList.toggle('sb-disabled', sel.disabled);
        };
        const pintar = q => {
            const nq = norm(q);
            filtradas = [...sel.options].filter(o => o.value !== '' && !o.disabled && (!nq || norm(o.text).includes(nq)));
            activo = filtradas.findIndex(o => o.selected);
            if (activo < 0 && nq) activo = 0;
            lista.innerHTML = filtradas.length
                ? filtradas.slice(0, 200).map((o, i) => `<div class="sb-op${i === activo ? ' activo' : ''}${o.selected ? ' elegido' : ''}" data-i="${i}" role="option">${resaltar(o.text.trim(), nq)}</div>`).join('')
                  + (filtradas.length > 200 ? `<div class="sb-vacio">… ${filtradas.length - 200} más: sigue escribiendo para filtrar</div>` : '')
                : '<div class="sb-vacio">Sin resultados</div>';
            lista.hidden = false;
            ubicar();
            mover(0);
        };
        // La lista va «fija» sobre la página: así no la recortan las tarjetas con overflow oculto
        const ubicar = () => {
            if (lista.hidden) return;
            const r = inp.getBoundingClientRect(), abajo = window.innerHeight - r.bottom, arriba = r.top;
            const alto = Math.min(280, Math.max(abajo, arriba) - 12);
            lista.style.maxHeight = alto + 'px';
            lista.style.left = r.left + 'px';
            lista.style.width = r.width + 'px';
            if (abajo < 200 && arriba > abajo) { lista.style.top = ''; lista.style.bottom = (window.innerHeight - r.top + 2) + 'px'; }
            else { lista.style.bottom = ''; lista.style.top = (r.bottom + 2) + 'px'; }
        };
        window.addEventListener('scroll', ubicar, true);
        window.addEventListener('resize', ubicar);
        const resaltar = (txt, nq) => {
            if (!nq) return esc(txt);
            const i = norm(txt).indexOf(nq);
            return i < 0 ? esc(txt) : esc(txt.slice(0, i)) + '<mark>' + esc(txt.slice(i, i + nq.length)) + '</mark>' + esc(txt.slice(i + nq.length));
        };
        const mover = d => {
            const ops = lista.querySelectorAll('.sb-op');
            if (!ops.length) return;
            activo = Math.max(0, Math.min(ops.length - 1, activo + d));
            ops.forEach((o, i) => o.classList.toggle('activo', i === activo));
            ops[activo]?.scrollIntoView({ block: 'nearest' });
        };
        const cerrar = () => { lista.hidden = true; etiqueta(); };
        const elegir = o => {
            if (!o) return;
            const antes = sel.value;
            sel.value = o.value;
            cerrar();
            if (antes !== sel.value) sel.dispatchEvent(new Event('change', { bubbles: true }));
        };

        inp.addEventListener('focus', () => { inp.select(); pintar(''); });
        inp.addEventListener('click', () => { if (lista.hidden) pintar(''); });   // ya tenía el foco: volver a abrir la lista
        inp.addEventListener('input', () => pintar(inp.value));
        inp.addEventListener('keydown', e => {
            if (e.key === 'ArrowDown') { e.preventDefault(); if (lista.hidden) pintar(''); else mover(1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); mover(-1); }
            else if (e.key === 'Enter') { if (!lista.hidden) { e.preventDefault(); elegir(filtradas[activo]); } }
            else if (e.key === 'Escape') { cerrar(); inp.blur(); }
            else if (e.key === 'Tab') { if (!lista.hidden && inp.value && filtradas[activo] && norm(inp.value) !== norm(sel.selectedOptions[0]?.text)) elegir(filtradas[activo]); else cerrar(); }
        });
        lista.addEventListener('mousedown', e => {            // mousedown: antes del blur del campo
            const op = e.target.closest('.sb-op');
            if (!op) return;
            e.preventDefault();
            elegir(filtradas[+op.dataset.i]);
        });
        inp.addEventListener('blur', () => setTimeout(cerrar, 120));
        limpiar.addEventListener('click', () => { if (sel.value !== '' && sel.options[0]?.value === '') { sel.value = ''; sel.dispatchEvent(new Event('change', { bubbles: true })); } etiqueta(); inp.focus(); });
        // Si el formulario pide el campo obligatorio, el aviso del navegador sale en el select oculto: llevar el foco al buscador
        sel.addEventListener('invalid', () => setTimeout(() => inp.focus(), 0));
        sel.addEventListener('change', etiqueta);
        new MutationObserver(etiqueta).observe(sel, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled'] });
        // Valor puesto por código (sin evento): se refleja en el buscador
        for (const prop of ['value', 'selectedIndex']) {
            const d = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, prop);
            Object.defineProperty(sel, prop, { configurable: true, get() { return d.get.call(this); }, set(v) { d.set.call(this, v); etiqueta(); } });
        }
        vivos.set(sel, w);
        etiqueta();
    }

    const buscar = raiz => {
        if (raiz.matches && raiz.matches('select[data-buscar]')) armar(raiz);
        raiz.querySelectorAll && raiz.querySelectorAll('select[data-buscar]').forEach(s => {
            if (!s.closest('[style*="display:none"], [style*="display: none"], template')) armar(s);   // plantillas ocultas: se arman al clonarlas
        });
    };
    const iniciar = () => {
        buscar(document);
        new MutationObserver(ms => ms.forEach(m => m.addedNodes.forEach(n => n.nodeType === 1 && buscar(n)))).observe(document.body, { childList: true, subtree: true });
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar); else iniciar();
    window.SelectBuscar = { armar };
})();
