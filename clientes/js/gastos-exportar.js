/**
 * gastos-exportar.js — ZIP de gastos con barra de progreso (gastos_exportar.php):
 *   gastosExportarZip([ids], bouchers) → copia comprobantes (y genera bouchers si se pide) por partes y descarga el ZIP
 *   con gastos.xlsx, comprobantes/ y bouchers/. Requiere SweetAlert2.
 */
(function () {
    const URL_EXP = 'gastos_exportar.php';
    const post = async datos => {
        const fd = new FormData();
        Object.entries(datos).forEach(([k, v]) => fd.append(k, v));
        const r = await fetch(URL_EXP, { method: 'POST', body: fd });
        let d;
        try { d = await r.json(); } catch (e) { throw new Error('Respuesta inesperada del servidor (' + r.status + ').'); }
        if (!d.success) throw new Error(d.error || 'No se pudo completar.');
        return d;
    };

    window.gastosExportarZip = async function (ids, bouchers) {
        ids = (ids || []).map(String).filter(Boolean);
        if (!ids.length) return;
        let cancelado = false;
        Swal.fire({
            title: 'Preparando el ZIP…',
            html: `<div class="text-start small mb-2" id="gxTexto">Preparando ${ids.length} gasto(s)…</div>
                   <div class="progress" style="height:14px"><div class="progress-bar progress-bar-striped progress-bar-animated" id="gxBarra" style="width:2%"></div></div>
                   <div class="small text-muted mt-2">La descarga empieza sola al terminar. No cierres esta pestaña.</div>`,
            showConfirmButton: false, showCancelButton: true, cancelButtonText: 'Cancelar', allowOutsideClick: false,
        }).then(r => { if (r.dismiss === Swal.DismissReason.cancel) cancelado = true; });
        const pintar = (hechos, total) => {
            const p = Math.max(2, Math.round(hechos / total * 100));
            const b = document.getElementById('gxBarra'), t = document.getElementById('gxTexto');
            if (b) b.style.width = p + '%';
            if (t) t.textContent = `Procesando ${hechos} de ${total} (${p}%)…`;
        };
        try {
            const ini = await post({ accion: 'iniciar', ids: ids.join(','), bouchers: bouchers ? 1 : 0 });
            let estado = { hechos: 0, total: ini.total };
            pintar(0, ini.total);
            while (estado.hechos < estado.total) {
                if (cancelado) return;
                estado = await post({ accion: 'paso', token: ini.token });
                pintar(estado.hechos, estado.total);
            }
            if (cancelado) return;
            const t = document.getElementById('gxTexto');
            if (t) t.textContent = 'Armando el ZIP y descargando…';
            location.href = URL_EXP + '?descargar=' + ini.token;
            setTimeout(() => Swal.fire({ icon: 'success', title: 'Listo', text: `${estado.total} gasto(s) en el ZIP. Revisa tus descargas.`, timer: 3500, showConfirmButton: false }), 1200);
        } catch (e) {
            if (!cancelado) Swal.fire('No se pudo', e.message, 'error');
        }
    };
})();
