<?php
// Configuración → Documentos de la empresa: constancias y permisos con fecha de vencimiento, para adjuntar en los cobros por correo.
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/documentos.php';

if (!in_array(USUARIO_ROL, ['admin', 'superadmin'], true)) {
    header('Location: dashboard');
    exit;
}
$cid = (int)cliente_actual();
$instalada = docsDisponible($pdo);
$docs = $instalada ? docsLista($pdo, $cid) : [];
$e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
require_once '../../includes/templates/header.php';
?>

<div class="app-page-header">
    <div>
        <h1 class="app-page-title"><i class="bi bi-folder-check me-2"></i>Documentos de la empresa</h1>
        <p class="app-page-sub">Constancia de pago a cuenta del SAR, solvencias y permisos: súbelos con su fecha de vencimiento para adjuntarlos en los cobros por correo. Te avisamos en el inicio y por correo cuando estén por vencer o vencidos.</p>
    </div>
    <button class="btn btn-primary" id="btnNuevoDoc" <?= $instalada ? '' : 'disabled' ?>><i class="bi bi-upload me-1"></i> Subir documento</button>
</div>

<?php if (!$instalada): ?>
    <div class="alert alert-warning">Falta instalar <code>sql/migraciones/2026-10-09_documentos_empresa.sql</code>.</div>
<?php else: ?>
    <div class="app-card">
        <div class="app-card-header"><span><i class="bi bi-files me-1"></i> Documentos</span><span class="app-badge"><?= count($docs) ?></span></div>
        <div class="table-responsive">
            <table class="table app-table mb-0">
                <thead><tr><th>Documento</th><th>Emisión</th><th>Vence</th><th>Estado</th><th>Cobros</th><th class="text-end">Acciones</th></tr></thead>
                <tbody>
                    <?php if (!$docs): ?><tr><td colspan="6" class="text-center text-muted py-4">Aún no hay documentos. Usa «Subir documento» (por ejemplo, la Constancia de pago a cuenta del SAR).</td></tr><?php endif; ?>
                    <?php foreach ($docs as $d): [$bg, $fg, $txt] = docSemaforo($d); ?>
                        <tr>
                            <td><a href="includes/documento_accion.php?ver=<?= (int)$d['id'] ?>" target="_blank" class="fw-semibold"><i class="bi <?= $d['mime'] === 'application/pdf' ? 'bi-file-earmark-pdf text-danger' : 'bi-file-earmark-image text-primary' ?> me-1"></i><?= $e($d['nombre']) ?></a>
                                <div class="small text-muted"><?= $e($d['archivo_nombre']) ?><?= $d['notas'] ? ' · ' . $e($d['notas']) : '' ?></div></td>
                            <td class="small text-nowrap"><?= $d['fecha_emision'] ? date('d/m/Y', strtotime($d['fecha_emision'])) : '—' ?></td>
                            <td class="small text-nowrap"><?= $d['fecha_vencimiento'] ? date('d/m/Y', strtotime($d['fecha_vencimiento'])) : '—' ?></td>
                            <td><span class="badge rounded-pill" style="background:<?= $bg ?>;color:<?= $fg ?>"><?= $txt ?></span></td>
                            <td class="small"><?= (int)$d['adjuntar_por_defecto'] ? '<i class="bi bi-paperclip"></i> Se marca solo al enviar facturas' : '<span class="text-muted">A elección</span>' ?></td>
                            <td class="text-end text-nowrap">
                                <button class="btn btn-sm btn-outline-primary btn-editar-doc" data-doc='<?= json_encode(array_intersect_key($d, array_flip(['id', 'nombre', 'fecha_emision', 'fecha_vencimiento', 'adjuntar_por_defecto', 'notas', 'archivo_nombre'])), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>' title="Editar o renovar (subir el archivo nuevo)"><i class="bi bi-arrow-repeat"></i></button>
                                <button class="btn btn-sm btn-outline-danger btn-eliminar-doc" data-id="<?= (int)$d['id'] ?>" data-nombre="<?= $e($d['nombre']) ?>" title="Eliminar"><i class="bi bi-trash"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="modal fade" id="mDoc" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
        <form id="fDoc" enctype="multipart/form-data">
            <div class="modal-header"><h5 class="modal-title" id="mDocTitulo">Subir documento</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" id="dId">
                <label class="form-label">Nombre *</label>
                <input class="form-control mb-2" name="nombre" id="dNombre" maxlength="150" required placeholder="Ej.: Constancia de pago a cuenta SAR" list="dSugeridos">
                <datalist id="dSugeridos"><option value="Constancia de pago a cuenta SAR"><option value="Solvencia fiscal SAR"><option value="Permiso de operación municipal"><option value="RTN de la empresa"></datalist>
                <div class="row g-2 mb-2">
                    <div class="col-6"><label class="form-label">Emisión</label><input type="date" class="form-control" name="fecha_emision" id="dEmision"></div>
                    <div class="col-6"><label class="form-label">Vence</label><input type="date" class="form-control" name="fecha_vencimiento" id="dVence"><div class="form-text">Vacío si no vence.</div></div>
                </div>
                <label class="form-label">Archivo (PDF o imagen, máx. 10 MB) <span id="dArchivoReq">*</span></label>
                <input type="file" class="form-control mb-1" name="archivo" id="dArchivo" accept="application/pdf,image/jpeg,image/png,image/webp">
                <div class="form-text mb-2" id="dArchivoActual"></div>
                <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="adjuntar_por_defecto" value="1" id="dDefecto">
                    <label class="form-check-label" for="dDefecto">Marcarlo solo al enviar facturas por correo</label></div>
                <label class="form-label">Notas</label><input class="form-control" name="notas" id="dNotas" maxlength="255" placeholder="Opcional">
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit"><i class="bi bi-floppy me-1"></i> Guardar</button></div>
        </form>
    </div></div></div>

    <script>
    (function () {
        const modal = () => bootstrap.Modal.getOrCreateInstance(document.getElementById('mDoc'));
        const leer = async r => { const t = await r.text(); let d; try { d = JSON.parse(t); } catch (e) { throw new Error('Respuesta inesperada del servidor (' + r.status + ').'); } if (!d.success) throw new Error(d.error); return d; };
        const abrir = d => {
            document.getElementById('mDocTitulo').textContent = d ? 'Editar o renovar documento' : 'Subir documento';
            document.getElementById('dId').value = d ? d.id : '';
            document.getElementById('dNombre').value = d ? d.nombre : '';
            document.getElementById('dEmision').value = d ? (d.fecha_emision || '') : '';
            document.getElementById('dVence').value = d ? (d.fecha_vencimiento || '') : '';
            document.getElementById('dNotas').value = d ? (d.notas || '') : '';
            document.getElementById('dDefecto').checked = d ? !!+d.adjuntar_por_defecto : false;
            document.getElementById('dArchivo').value = '';
            document.getElementById('dArchivoReq').hidden = !!d;
            document.getElementById('dArchivoActual').textContent = d ? 'Actual: ' + d.archivo_nombre + '. Para renovarlo, sube el archivo nuevo y cambia la fecha de vencimiento.' : '';
            modal().show();
        };
        document.getElementById('btnNuevoDoc')?.addEventListener('click', () => abrir(null));
        document.querySelectorAll('.btn-editar-doc').forEach(b => b.addEventListener('click', () => abrir(JSON.parse(b.dataset.doc))));
        document.getElementById('fDoc')?.addEventListener('submit', ev => {
            ev.preventDefault();
            Swal.fire({ title: 'Guardando…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            fetch('includes/documento_accion.php', { method: 'POST', body: new FormData(ev.target) }).then(leer)
                .then(d => Swal.fire({ icon: 'success', title: d.message, timer: 1400, showConfirmButton: false }).then(() => location.reload()))
                .catch(err => Swal.fire('No se pudo', err.message, 'error'));
        });
        document.querySelectorAll('.btn-eliminar-doc').forEach(b => b.addEventListener('click', async () => {
            if (!(await Swal.fire({ title: '¿Eliminar «' + b.dataset.nombre + '»?', text: 'Los cobros ya enviados conservan su copia.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Eliminar', cancelButtonText: 'Cancelar' })).isConfirmed) return;
            const fd = new FormData(); fd.append('accion', 'eliminar'); fd.append('id', b.dataset.id);
            fetch('includes/documento_accion.php', { method: 'POST', body: fd }).then(leer).then(() => location.reload()).catch(err => Swal.fire('No se pudo', err.message, 'error'));
        }));
    })();
    </script>
<?php endif; ?>

<?php require_once '../../includes/templates/footer.php'; ?>
