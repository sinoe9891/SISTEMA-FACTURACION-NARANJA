<?php
/**
 * _comprobante_modal.php — Ventana para adjuntar, cambiar o quitar un comprobante (botones .btn-comp de comprobanteBotonesFila()).
 * Se incluye en la página después de la tabla; envía a includes/comprobante_accion.php y recarga.
 */
?>
<div class="modal fade" id="modalComp" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <form class="modal-content" id="formComp" novalidate>
            <div class="modal-header"><h5 class="modal-title"><i class="bi bi-paperclip me-1"></i> Comprobante</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
            <div class="modal-body">
                <input type="hidden" name="tipo" id="compTipo"><input type="hidden" name="id" id="compId">
                <label class="form-label small">Archivo <span class="text-muted">(JPG, PNG o PDF, máx. 5 MB)</span></label>
                <input type="file" name="comprobante" id="compArchivo" class="form-control form-control-sm" accept="image/jpeg,image/png,image/webp,application/pdf">
                <div class="form-check mt-2 d-none" id="compQuitarGrp"><input class="form-check-input" type="checkbox" name="quitar_comprobante" value="1" id="compQuitar"><label class="form-check-label small" for="compQuitar">Quitar el comprobante actual</label></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary btn-sm" type="submit">Guardar</button></div>
        </form>
    </div>
</div>
<script>
(function () {
    const form = document.getElementById('formComp'), modal = new bootstrap.Modal(document.getElementById('modalComp'));
    document.querySelectorAll('.btn-comp').forEach(b => b.addEventListener('click', () => {
        form.reset();
        document.getElementById('compTipo').value = b.dataset.tipo; document.getElementById('compId').value = b.dataset.id;
        document.getElementById('compQuitarGrp').classList.toggle('d-none', b.dataset.tiene !== '1');
        modal.show();
    }));
    form.addEventListener('submit', e => {
        e.preventDefault();
        if (!document.getElementById('compArchivo').files.length && !document.getElementById('compQuitar').checked) { Swal.fire('Falta el archivo', 'Elige el comprobante o marca «Quitar».', 'info'); return; }
        const btn = form.querySelector('[type=submit]'); btn.disabled = true;
        fetch('includes/comprobante_accion.php', { method: 'POST', body: new FormData(form) }).then(r => r.json()).then(d => {
            if (!d.success) throw new Error(d.error || 'No se pudo guardar.');
            modal.hide(); Swal.fire({ icon: 'success', title: d.message, timer: 1500, showConfirmButton: false }).then(() => location.reload());
        }).catch(err => Swal.fire('Error', err.message, 'error')).finally(() => btn.disabled = false);
    });
})();
</script>
