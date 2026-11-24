/**
 * comprobante-vista.js — Vista previa de comprobantes sin salir de la página.
 * Cualquier enlace a un comprobante (pago, cobro, gasto, préstamo, banco…) se abre en una ventana con la imagen o el PDF,
 * con botones para abrirlo en otra pestaña o descargarlo. Con Ctrl/Cmd/Shift o clic central se abre normal en otra pestaña.
 */
(function () {
    const RUTA = /(?:^|\/)(pago_recibido_archivo|comprobante_archivo|cobro_archivo|movimiento_archivo|gasto_archivo|gasto_operativo_archivo|socio_prestamo_archivo|socio_archivo|comision_archivo|prestamo_comprobante)(?:\.php)?\?/;
    let modal = null, urlObjeto = null;

    function armar() {
        if (modal) return modal;
        const el = document.createElement('div');
        el.className = 'modal fade'; el.tabIndex = -1; el.setAttribute('aria-hidden', 'true');
        el.innerHTML = '<div class="modal-dialog modal-xl modal-dialog-centered modal-fullscreen-md-down"><div class="modal-content">'
            + '<div class="modal-header py-2"><h6 class="modal-title"><i class="bi bi-paperclip me-1"></i> Comprobante</h6>'
            + '<div class="ms-auto me-2 d-flex gap-2"><a class="btn btn-sm btn-outline-secondary cv-abrir" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i>Abrir</a>'
            + '<a class="btn btn-sm btn-outline-secondary cv-bajar"><i class="bi bi-download me-1"></i>Descargar</a></div>'
            + '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>'
            + '<div class="modal-body p-2 text-center cv-cuerpo" style="min-height:60vh;background:#f1f5f9"></div></div></div>';
        document.body.appendChild(el);
        el.addEventListener('hidden.bs.modal', () => { el.querySelector('.cv-cuerpo').innerHTML = ''; if (urlObjeto) { URL.revokeObjectURL(urlObjeto); urlObjeto = null; } });
        return modal = { el, bs: new bootstrap.Modal(el) };
    }

    function abrir(href) {
        const m = armar(), cuerpo = m.el.querySelector('.cv-cuerpo');
        const u = new URL(href, location.href);
        m.el.querySelector('.cv-abrir').href = u.href;
        const bajar = m.el.querySelector('.cv-bajar'); bajar.removeAttribute('href'); bajar.removeAttribute('download');
        cuerpo.innerHTML = '<div class="py-5 text-muted"><div class="spinner-border spinner-border-sm me-2"></div>Cargando…</div>';
        m.bs.show();
        fetch(u.href, { credentials: 'same-origin' }).then(r => { if (!r.ok) throw new Error('No se encontró el comprobante.'); return r.blob(); }).then(b => {
            urlObjeto = URL.createObjectURL(b);
            bajar.href = urlObjeto; bajar.download = 'comprobante' + (b.type === 'application/pdf' ? '.pdf' : b.type === 'image/png' ? '.png' : b.type === 'image/webp' ? '.webp' : '.jpg');
            if (b.type.startsWith('image/')) cuerpo.innerHTML = '<img src="' + urlObjeto + '" alt="Comprobante" style="max-width:100%;max-height:78vh">';
            else if (b.type === 'application/pdf') cuerpo.innerHTML = '<iframe src="' + urlObjeto + '" style="width:100%;height:78vh;border:0" title="Comprobante"></iframe>';
            else { cuerpo.innerHTML = '<div class="py-5 text-muted">Este tipo de archivo no se puede mostrar aquí. Usá «Descargar».</div>'; }
        }).catch(e => { cuerpo.innerHTML = '<div class="py-5 text-danger">' + (e.message || 'No se pudo cargar.') + '</div>'; });
    }

    document.addEventListener('click', e => {
        if (e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
        const a = e.target.closest('a[href]');
        if (!a || a.hasAttribute('onclick') || a.hasAttribute('download') || !RUTA.test(a.getAttribute('href'))) return;
        if (/[?&]descargar=1/.test(a.getAttribute('href')) || typeof bootstrap === 'undefined') return;
        e.preventDefault();
        abrir(a.getAttribute('href'));
    });
})();
