/**
 * app-tabla.js — Búsqueda y paginación en el navegador para tablas .app-table.
 *
 *   const t = AppTabla('#miTabla', {
 *       buscar: '#buscador',          // <input> de búsqueda (opcional)
 *       porPagina: '#porPagina',      // <select> con 10/25/50 (opcional; por defecto 10)
 *       pie: '#miTablaPie',           // contenedor .app-pager donde va "Mostrando…" y los botones
 *       filtro: tr => true,           // filtro adicional (opcional)
 *       vacio: 'Sin resultados.'      // texto cuando no hay filas visibles
 *   });
 *   t.refrescar();                    // vuelve a aplicar (p. ej. al cambiar un filtro propio)
 *
 * Las filas que cuentan son las de <tbody> con atributo data-fila; el texto buscado es
 * data-buscar si existe, o el texto de la fila.
 */
function AppTabla(tabla, opciones) {
    const o = Object.assign({ vacio: 'Sin resultados.' }, opciones || {});
    const $ = s => (typeof s === 'string' ? document.querySelector(s) : s);
    const tbl = $(tabla), buscar = $(o.buscar), sel = $(o.porPagina), pie = $(o.pie);
    if (!tbl) return { refrescar() {} };
    const tbody = tbl.tBodies[0];
    const filas = Array.from(tbody.querySelectorAll('tr[data-fila]'));
    const cols = tbl.tHead ? tbl.tHead.rows[0].cells.length : 1;
    const vacia = document.createElement('tr');
    vacia.innerHTML = `<td colspan="${cols}" class="text-center text-muted py-4"></td>`;
    vacia.style.display = 'none';
    tbody.appendChild(vacia);
    let pagina = 1;

    function texto(tr) { return (tr.dataset.buscar || tr.textContent).toLowerCase(); }

    function refrescar() {
        const q = (buscar?.value || '').trim().toLowerCase();
        const visibles = filas.filter(tr => (!q || texto(tr).includes(q)) && (!o.filtro || o.filtro(tr)));
        const n = parseInt(sel?.value || o.porPaginaInicial || 10, 10);
        const paginas = Math.max(1, Math.ceil(visibles.length / n));
        pagina = Math.min(pagina, paginas);
        const ini = (pagina - 1) * n;
        filas.forEach(tr => (tr.style.display = 'none'));
        visibles.slice(ini, ini + n).forEach(tr => (tr.style.display = ''));
        vacia.firstChild.textContent = o.vacio;
        vacia.style.display = visibles.length ? 'none' : '';
        if (!pie) return;
        const fin = Math.min(ini + n, visibles.length);
        pie.innerHTML = `<span class="app-pager-info">${visibles.length ? `Mostrando ${ini + 1}–${fin} de ${visibles.length}` : 'Sin resultados'}</span><div class="app-pager-btns"></div>`;
        const btns = pie.lastChild;
        const boton = (html, p, extra = '') => {
            const b = document.createElement('span');
            b.className = 'app-page-btn ' + extra;
            b.innerHTML = html;
            if (!extra.includes('disabled') && !extra.includes('active')) b.addEventListener('click', () => { pagina = p; refrescar(); });
            btns.appendChild(b);
        };
        boton('<i class="bi bi-chevron-double-left"></i>', 1, pagina === 1 ? 'disabled' : '');
        boton('<i class="bi bi-chevron-left"></i>', pagina - 1, pagina === 1 ? 'disabled' : '');
        let desde = Math.max(1, pagina - 2), hasta = Math.min(paginas, desde + 4);
        desde = Math.max(1, hasta - 4);
        for (let p = desde; p <= hasta; p++) boton(String(p), p, p === pagina ? 'active' : '');
        boton('<i class="bi bi-chevron-right"></i>', pagina + 1, pagina === paginas ? 'disabled' : '');
        boton('<i class="bi bi-chevron-double-right"></i>', paginas, pagina === paginas ? 'disabled' : '');
    }

    buscar?.addEventListener('input', () => { pagina = 1; refrescar(); });
    sel?.addEventListener('change', () => { pagina = 1; refrescar(); });
    refrescar();
    return { refrescar: () => { pagina = 1; refrescar(); } };
}
