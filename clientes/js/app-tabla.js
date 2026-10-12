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
 * data-buscar si existe, o el texto de la fila. Una celda con class="app-n" se numera 1, 2, 3…
 * según el orden de las filas visibles (se recalcula al buscar o filtrar).
 */
function AppTabla(tabla, opciones) {
    const o = Object.assign({ vacio: 'Sin resultados.' }, opciones || {});
    const $ = s => (typeof s === 'string' ? document.querySelector(s) : s);
    const tbl = $(tabla), buscar = $(o.buscar), sel = $(o.porPagina), pie = $(o.pie);
    if (!tbl) return { refrescar() {} };
    const tbody = tbl.tBodies[0];
    const filasDom = () => Array.from(tbody.querySelectorAll('tr[data-fila]'));   // en el orden actual (se puede ordenar)
    const cols = tbl.tHead ? tbl.tHead.rows[0].cells.length : 1;
    const vacia = document.createElement('tr');
    vacia.innerHTML = `<td colspan="${cols}" class="text-center text-muted py-4"></td>`;
    vacia.style.display = 'none';
    tbody.appendChild(vacia);
    let pagina = 1;

    function texto(tr) { return (tr.dataset.buscar || tr.textContent).toLowerCase(); }

    function refrescar() {
        const q = (buscar?.value || '').trim().toLowerCase();
        const filas = filasDom();
        const visibles = filas.filter(tr => (!q || texto(tr).includes(q)) && (!o.filtro || o.filtro(tr)));
        const n = parseInt(sel?.value || o.porPaginaInicial || 10, 10);
        const paginas = Math.max(1, Math.ceil(visibles.length / n));
        pagina = Math.min(pagina, paginas);
        const ini = (pagina - 1) * n;
        filas.forEach(tr => (tr.style.display = 'none'));
        visibles.forEach((tr, i) => { const n = tr.querySelector('.app-n'); if (n) n.textContent = i + 1; });
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
    const api = { refrescar: () => { pagina = 1; refrescar(); } };
    tbl.__appTabla = api;
    return api;
}

/*
 * Paginación automática: cualquier <table data-paginar> muestra 10 filas por página (o data-paginar="N")
 * con el pie «Mostrando…» debajo. Las filas son las tr[data-fila]; si no tiene, todas las del <tbody>
 * con más de una celda (las de «sin datos» con colspan no cuentan). Si caben en una página, no hace nada.
 */
(function () {
    if (window.__appTablaAuto) return;   // el archivo puede cargarse dos veces (página + footer)
    window.__appTablaAuto = true;
    const iniciar = () => document.querySelectorAll('table[data-paginar]').forEach(t => {
        if (t.__paginada || !t.tBodies[0]) return;
        t.__paginada = true;
        const n = parseInt(t.dataset.paginar, 10) || 10;
        const rows = Array.from(t.tBodies[0].rows);
        if (!rows.some(r => r.hasAttribute('data-fila'))) rows.forEach(r => { if (r.cells.length > 1) r.setAttribute('data-fila', ''); });
        if (rows.filter(r => r.hasAttribute('data-fila')).length <= n) return;
        const pie = document.createElement('div');
        pie.className = 'app-pager';
        (t.closest('.table-responsive') || t).after(pie);
        AppTabla(t, { pie, porPaginaInicial: n, vacio: t.dataset.vacio || 'Sin resultados.' });
    });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar); else iniciar();
})();

/*
 * Orden por columnas: cualquier <table data-ordenar> ordena al hacer clic en el encabezado (asc/desc).
 * Valor de cada celda: data-sort si lo tiene (p. ej. fecha 2026-01-31 o período 2026-01), si no su texto;
 * montos («L 1,234.56») y fechas dd/mm/aaaa se comparan con window.tablaComparar. No ordenan: los
 * encabezados vacíos, la columna # (.app-n) y los que tengan class="no-orden". La columna .app-n se
 * numera 1, 2, 3… en el orden que se ve. Funciona junto con data-paginar / AppTabla.
 */
(function () {
    if (window.__appOrdenar) return;
    window.__appOrdenar = true;
    const valor = td => (td ? (td.dataset.sort ?? td.textContent) : '').trim();
    const comparar = (a, b) => (window.tablaComparar ? window.tablaComparar(a, b) : String(a).localeCompare(String(b), 'es', { numeric: true }));
    const numerar = t => { let n = 0; Array.from(t.tBodies[0].rows).forEach(r => { const c = r.querySelector('td.app-n'); if (c) c.textContent = ++n; }); };
    const iniciar = () => document.querySelectorAll('table[data-ordenar]').forEach(t => {
        if (t.__ordenar || !t.tHead || !t.tBodies[0]) return;
        t.__ordenar = true;
        const ths = Array.from(t.tHead.rows[0].cells);
        ths.forEach((th, i) => {
            if (!th.textContent.trim() || th.classList.contains('app-n') || th.classList.contains('no-orden')) return;
            th.classList.add('app-th-orden');
            th.addEventListener('click', () => {
                const dir = th.classList.contains('orden-asc') ? 'desc' : 'asc';
                ths.forEach(x => x.classList.remove('orden-asc', 'orden-desc'));
                th.classList.add('orden-' + dir);
                const body = t.tBodies[0];
                const filas = Array.from(body.rows).filter(r => r.cells.length > 1);
                filas.sort((a, b) => (dir === 'asc' ? 1 : -1) * comparar(valor(a.cells[i]), valor(b.cells[i])));
                filas.forEach(r => body.appendChild(r));
                if (t.__appTabla) t.__appTabla.refrescar(); else numerar(t);
            });
        });
        if (!t.__appTabla) numerar(t);
    });
    // Después de data-paginar (que se inicia en DOMContentLoaded), para que la numeración siga a la paginación
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => setTimeout(iniciar)); else setTimeout(iniciar);
})();
