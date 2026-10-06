<?php
// Configuración → Firmas de documentos: quién elabora, autoriza y da el Vo.Bo. en bouchers y recibos de pago.
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/firmantes.php';

if (!in_array(USUARIO_ROL, ['admin', 'superadmin'], true)) {
    header('Location: dashboard');
    exit;
}
$cid = (int)cliente_actual();
$instalada = firmantesDisponible($pdo);
$firmantes = $instalada ? firmantesLista($pdo, $cid) : [];
$ayuda = [
    'elaborado' => 'Quien prepara el pago y el documento.',
    'autorizado' => 'Quien aprueba el pago.',
    'vobo' => 'Visto bueno final (gerencia).',
];
require_once '../../includes/templates/header.php';
?>

<div class="app-page-header">
    <div>
        <h1 class="app-page-title"><i class="bi bi-pen me-2"></i>Firmas de documentos</h1>
        <p class="app-page-sub">Nombre, cargo y firma de quienes firman los bouchers y recibos de pago. Aplica a todos los documentos, también a los de pagos anteriores.</p>
    </div>
</div>

<?php if (!$instalada): ?>
    <div class="alert alert-warning">Falta instalar <code>sql/migraciones/2026-10-06_firmantes_concepto.sql</code>.</div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach (FIRMANTES_ROLES as $rol => $titulo): $f = $firmantes[$rol]; ?>
            <div class="col-md-4">
                <form class="app-card h-100 f-firmante" data-rol="<?= $rol ?>">
                    <div class="app-card-header"><span><?= $titulo ?></span><span class="small text-muted fw-normal"><?= $ayuda[$rol] ?></span></div>
                    <div class="app-card-body">
                        <label class="form-label small">Nombre</label>
                        <input class="form-control form-control-sm mb-2" name="nombre" maxlength="150" value="<?= htmlspecialchars($f['nombre']) ?>" placeholder="Nombre completo">
                        <label class="form-label small">Cargo</label>
                        <input class="form-control form-control-sm mb-3" name="cargo" maxlength="150" value="<?= htmlspecialchars($f['cargo']) ?>" placeholder="Ej: Gerente Administrativo">
                        <label class="form-label small d-flex justify-content-between">Firma
                            <?php if ($f['firma']): ?><a href="#" class="small text-danger f-quitar">Quitar</a><?php endif; ?></label>
                        <div class="border rounded d-flex align-items-center justify-content-center mb-2" style="height:110px;background:#fff">
                            <?php if ($f['firma']): ?><img src="includes/firmante_accion.php?firma=<?= $rol ?>&v=<?= substr(md5($f['firma']), 0, 8) ?>" alt="Firma" style="max-height:100px;max-width:95%">
                            <?php else: ?><span class="small text-muted">Sin firma</span><?php endif; ?>
                        </div>
                        <label class="btn btn-sm btn-outline-primary w-100 mb-0"><i class="bi bi-upload me-1"></i> <?= $f['firma'] ? 'Cambiar firma' : 'Subir firma' ?>
                            <input type="file" class="f-archivo" accept="image/png,image/jpeg" hidden></label>
                        <div class="form-text">PNG o JPG, firma oscura sobre fondo blanco; se recorta sola.</div>
                    </div>
                    <div class="app-card-body border-top pt-2"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-floppy me-1"></i> Guardar nombre y cargo</button></div>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="small text-muted mt-3"><i class="bi bi-info-circle me-1"></i> «Elaborado por»: si no tiene nombre, el documento muestra al usuario que registró el pago. Un firmante no necesita ser colaborador.</p>
<?php endif; ?>

<script>
(() => {
    const enviar = async fd => {
        const r = await fetch('includes/firmante_accion.php', { method: 'POST', body: fd });
        let d; try { d = await r.json(); } catch (e) { throw new Error('Respuesta inesperada del servidor (' + r.status + ').'); }
        if (!d.success) throw new Error(d.error);
        return d;
    };
    const listo = d => Swal.fire({ icon: 'success', title: 'Listo', text: d.message, timer: 1400, showConfirmButton: false }).then(() => location.reload());
    const error = e => Swal.fire('No se pudo', e.message, 'error');
    document.querySelectorAll('.f-firmante').forEach(form => {
        const rol = form.dataset.rol;
        form.addEventListener('submit', e => {
            e.preventDefault();
            const fd = new FormData(form); fd.append('accion', 'guardar'); fd.append('rol', rol);
            enviar(fd).then(listo).catch(error);
        });
        form.querySelector('.f-archivo').addEventListener('change', e => {
            if (!e.target.files[0]) return;
            const fd = new FormData(); fd.append('accion', 'subir'); fd.append('rol', rol); fd.append('firma', e.target.files[0]);
            Swal.fire({ title: 'Guardando firma…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            enviar(fd).then(listo).catch(err => { error(err); e.target.value = ''; });
        });
        form.querySelector('.f-quitar')?.addEventListener('click', async e => {
            e.preventDefault();
            if (!(await Swal.fire({ title: '¿Quitar la firma?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Quitar', cancelButtonText: 'Cancelar' })).isConfirmed) return;
            const fd = new FormData(); fd.append('accion', 'quitar'); fd.append('rol', rol);
            enviar(fd).then(listo).catch(error);
        });
    });
})();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
