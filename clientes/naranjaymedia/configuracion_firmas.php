<?php
// Configuración → Firmas de documentos: quién elabora, autoriza y da el Vo.Bo. en bouchers y recibos de pago.
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/firmantes.php';

if (!permisoPuede($pdo, 'configuracion_firmas')) {
    header('Location: dashboard');
    exit;
}
$cid = (int)cliente_actual();
$instalada = firmantesDisponible($pdo);
$firmantes = $instalada ? firmantesLista($pdo, $cid) : [];
// Sello de la empresa (para facturas y cotizaciones)
$sello = null;
try {
    $stS = $pdo->prepare("SELECT sello, sello_facturas, sello_cotizaciones FROM clientes_saas WHERE id = ?");
    $stS->execute([$cid]);
    $sello = $stS->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $sello = null;   // sin la migración de cotizaciones
}
$ayuda = [
    'elaborado' => 'Quien prepara el pago y el documento.',
    'autorizado' => 'Quien aprueba el pago.',
    'vobo' => 'Visto bueno final (gerencia).',
];
require_once '../../includes/templates/header.php';
require_once '../../includes/templates/config_tabs.php';
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
    <?php if ($sello !== null): ?>
    <div class="app-card mt-3" id="selloCard">
        <div class="app-card-header"><span><i class="bi bi-patch-check me-1"></i> Sello de la empresa</span><span class="small text-muted fw-normal">Se pone junto a la firma en los documentos que elijas.</span></div>
        <div class="app-card-body row g-3 align-items-center">
            <div class="col-md-4">
                <div class="border rounded d-flex align-items-center justify-content-center" style="height:150px;background:repeating-conic-gradient(#f1f5f9 0 25%,#fff 0 50%) 0 0/16px 16px">
                    <?php if ($sello['sello']): ?><img src="includes/firmante_accion.php?sello=1&v=<?= substr(md5($sello['sello']), 0, 8) ?>" alt="Sello" style="max-height:140px;max-width:95%">
                    <?php else: ?><span class="small text-muted">Sin sello</span><?php endif; ?>
                </div>
            </div>
            <div class="col-md-8">
                <label class="btn btn-sm btn-outline-primary mb-2"><i class="bi bi-upload me-1"></i> <?= $sello['sello'] ? 'Cambiar sello' : 'Subir sello' ?>
                    <input type="file" id="selloArchivo" accept="image/png,image/jpeg,image/webp" hidden></label>
                <?php if ($sello['sello']): ?><button type="button" class="btn btn-sm btn-link text-danger mb-2" id="selloQuitar">Quitar</button><?php endif; ?>
                <div class="form-text mb-3">PNG con fondo transparente se ve mejor (también JPG). Se ajusta a 600 px.</div>
                <div class="form-check form-switch"><input class="form-check-input sello-uso" type="checkbox" role="switch" id="selloFact" name="facturas" <?= (int)$sello['sello_facturas'] ? 'checked' : '' ?> style="cursor:pointer">
                    <label class="form-check-label" for="selloFact">Ponerlo en las facturas</label></div>
                <div class="form-check form-switch"><input class="form-check-input sello-uso" type="checkbox" role="switch" id="selloCot" name="cotizaciones" <?= (int)$sello['sello_cotizaciones'] ? 'checked' : '' ?> style="cursor:pointer">
                    <label class="form-check-label" for="selloCot">Ponerlo en las cotizaciones</label></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
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
    // Sello
    document.getElementById('selloArchivo')?.addEventListener('change', e => {
        if (!e.target.files[0]) return;
        const fd = new FormData(); fd.append('accion', 'sello_subir'); fd.append('sello', e.target.files[0]);
        Swal.fire({ title: 'Guardando sello…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        enviar(fd).then(listo).catch(err => { error(err); e.target.value = ''; });
    });
    document.getElementById('selloQuitar')?.addEventListener('click', async () => {
        if (!(await Swal.fire({ title: '¿Quitar el sello?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Quitar', cancelButtonText: 'Cancelar' })).isConfirmed) return;
        const fd = new FormData(); fd.append('accion', 'sello_quitar');
        enviar(fd).then(listo).catch(error);
    });
    document.querySelectorAll('.sello-uso').forEach(sw => sw.addEventListener('change', () => {
        const fd = new FormData(); fd.append('accion', 'sello_uso');
        if (document.getElementById('selloFact').checked) fd.append('facturas', '1');
        if (document.getElementById('selloCot').checked) fd.append('cotizaciones', '1');
        enviar(fd).then(() => Swal.fire({ icon: 'success', title: 'Guardado', timer: 1000, showConfirmButton: false })).catch(error);
    }));
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
