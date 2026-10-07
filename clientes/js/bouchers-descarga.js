/**
 * bouchers-descarga.js — Descarga de bouchers con barra de progreso.
 *   bouchersZip([ids])  → genera los PDF por partes (boucher_lote.php) mostrando «12 de 55…» y descarga el ZIP.
 *   bouchersPdf([ids])  → abre en otra pestaña un solo PDF con todos (avisa que puede tardar).
 * Lo usan Bouchers, Pagos de nómina y la ficha del colaborador. Requiere SweetAlert2.
 */
(function () {
    const URL_LOTE = 'boucher_lote.php';
    const post = async datos => {
        const fd = new FormData();
        Object.entries(datos).forEach(([k, v]) => fd.append(k, v));
        const r = await fetch(URL_LOTE, { method: 'POST', body: fd });
        let d;
        try { d = await r.json(); } catch (e) { throw new Error('Respuesta inesperada del servidor (' + r.status + ').'); }
        if (!d.success) throw new Error(d.error || 'No se pudo completar.');
        return d;
    };

    window.bouchersZip = async function (ids) {
        ids = (ids || []).map(String).filter(Boolean);
        if (!ids.length) return;
        let cancelado = false;
        Swal.fire({
            title: 'Generando bouchers…',
            html: `<div class="text-start small mb-2" id="bzTexto">Preparando ${ids.length} boucher(s)…</div>
                   <div class="progress" style="height:14px"><div class="progress-bar progress-bar-striped progress-bar-animated" id="bzBarra" style="width:2%"></div></div>
                   <div class="small text-muted mt-2">La descarga del ZIP empieza sola al terminar. No cierres esta pestaña.</div>`,
            showConfirmButton: false, showCancelButton: true, cancelButtonText: 'Cancelar', allowOutsideClick: false,
        }).then(r => { if (r.dismiss === Swal.DismissReason.cancel) cancelado = true; });
        const pintar = (hechos, total) => {
            const p = Math.max(2, Math.round(hechos / total * 100));
            const b = document.getElementById('bzBarra'), t = document.getElementById('bzTexto');
            if (b) b.style.width = p + '%';
            if (t) t.textContent = `Generando ${hechos} de ${total} (${p}%)…`;
        };
        try {
            const ini = await post({ accion: 'iniciar', ids: ids.join(',') });
            let estado = { hechos: 0, total: ini.total };
            pintar(0, ini.total);
            while (estado.hechos < estado.total) {
                if (cancelado) return;
                estado = await post({ accion: 'paso', token: ini.token });
                pintar(estado.hechos, estado.total);
            }
            if (cancelado) return;
            const t = document.getElementById('bzTexto');
            if (t) t.textContent = 'Armando el ZIP y descargando…';
            location.href = URL_LOTE + '?descargar=' + ini.token;
            setTimeout(() => Swal.fire({ icon: 'success', title: 'Listo', text: `${estado.total} boucher(s) en el ZIP. Revisa tus descargas.`, timer: 3500, showConfirmButton: false }), 1200);
        } catch (e) {
            if (!cancelado) Swal.fire('No se pudo', e.message, 'error');
        }
    };

    window.bouchersPdf = function (ids) {
        ids = (ids || []).map(String).filter(Boolean);
        if (!ids.length) return;
        if (ids.length > 15) Swal.fire({ icon: 'info', title: 'Abriendo el PDF…', text: `Son ${ids.length} bouchers: la pestaña nueva puede tardar unos segundos en mostrarlo.`, timer: 4000, showConfirmButton: false });
        window.open('boucher_pdf.php?ids=' + ids.join(',') + '&vista=1', '_blank');
    };
})();
