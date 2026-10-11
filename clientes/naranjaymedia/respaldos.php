<?php
// Configuración → Respaldos: copias diarias de la base de datos completa (ISO/IEC 27001, control 8.13).
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/respaldos.php';

if (!respaldoPuede()) {
    respaldoLog('acceso_denegado', ['detalle' => 'página de respaldos']);
    header('Location: dashboard');
    exit;
}

$error = null;
try {
    $copias = respaldoLista(true);
    $copiasArch = respaldoArchivosLista(true);
    $bitacora = respaldoBitacora(300);
    $dir = respaldoDir();
} catch (Throwable $e) {
    $copias = $copiasArch = $bitacora = [];
    $dir = '';
    $error = $e->getMessage();
}
$usuarios = $pdo->query("SELECT id, nombre FROM usuarios")->fetchAll(PDO::FETCH_KEY_PAIR);
$ultima = $copias[0] ?? null;
$horas = $ultima ? (time() - strtotime($ultima['creado'])) / 3600 : null;
$espacio = array_sum(array_column($copias, 'bytes'));
$danadas = count(array_filter($copias, fn($c) => ($c['integro'] ?? null) === false));
$esSuper = USUARIO_ROL === 'superadmin';
$ultimaArch = $copiasArch[0] ?? null;
$tam = fn($b) => $b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : number_format($b / 1024, 0) . ' KB';
$eventos = [
    'creado' => ['Respaldo creado', 'success', 'bi-database-add'], 'descargado' => ['Descarga', 'info', 'bi-download'],
    'eliminado' => ['Eliminado por retención', 'muted', 'bi-trash'], 'verificado' => ['Prueba de integridad', 'info', 'bi-shield-check'],
    'error' => ['Error al respaldar', 'danger', 'bi-exclamation-triangle'], 'descarga_bloqueada' => ['Descarga bloqueada', 'danger', 'bi-shield-x'],
    'acceso_denegado' => ['Acceso denegado', 'warning', 'bi-person-x'],
    'archivos_creado' => ['Respaldo de archivos', 'success', 'bi-folder-plus'], 'archivos_restaurado' => ['Archivos restaurados', 'warning', 'bi-folder-symlink'],
    'restaurado' => ['Base restaurada', 'warning', 'bi-arrow-counterclockwise'], 'restauracion_error' => ['Error al restaurar', 'danger', 'bi-exclamation-octagon'],
];

require_once '../../includes/templates/header.php';
require_once '../../includes/templates/config_tabs.php';
?>

<div class="app-page-header">
    <div>
        <h1 class="app-page-title"><i class="bi bi-database-check me-2"></i>Respaldos</h1>
        <p class="app-page-sub">Cada día a las 2:15 a.m.: la base de datos completa (últimas <?= RESPALDO_COPIAS ?> copias) y los archivos subidos — comprobantes, firmas, documentos y PDF de cobros (últimas <?= RESPALDO_ARCHIVOS_COPIAS ?> copias).</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-primary" id="btnCrear" <?= $error ? 'disabled' : '' ?>><i class="bi bi-database-add me-1"></i> Respaldar base ahora</button>
        <button type="button" class="btn btn-outline-primary" id="btnCrearArch" <?= $error ? 'disabled' : '' ?>><i class="bi bi-folder-plus me-1"></i> Respaldar archivos ahora</button>
    </div>
</div>

<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($danadas): ?><div class="alert alert-danger"><i class="bi bi-shield-x me-1"></i> <?= $danadas ?> copia(s) no pasan la verificación de huella SHA-256: no las uses para restaurar y crea un respaldo nuevo.</div><?php endif; ?>
<?php if (!$error && (!$ultima || $horas > 26)): ?>
    <div class="alert alert-warning"><i class="bi bi-clock-history me-1"></i> <?= $ultima ? 'El último respaldo tiene ' . floor($horas) . ' horas.' : 'Aún no hay respaldos.' ?> Revisa que el cron diario esté activo en cPanel o crea uno ahora.</div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="app-card app-kpi"><div class="app-kpi-label">Último respaldo</div>
        <div class="app-kpi-value fs-5"><?= $ultima ? date('d/m/Y g:i a', strtotime($ultima['creado'])) : '—' ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="app-card app-kpi"><div class="app-kpi-label">Copias guardadas</div>
        <div class="app-kpi-value"><?= count($copias) ?> <span class="fs-6 text-muted">de <?= RESPALDO_COPIAS ?></span></div></div></div>
    <div class="col-6 col-lg-3"><div class="app-card app-kpi"><div class="app-kpi-label">Espacio usado</div>
        <div class="app-kpi-value"><?= $tam($espacio) ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="app-card app-kpi"><div class="app-kpi-label">Integridad</div>
        <div class="app-kpi-value <?= $danadas ? 'text-danger' : 'text-success' ?>"><?= $copias ? ($danadas ? $danadas . ' con fallas' : 'Correcta') : '—' ?></div></div></div>
</div>

<div class="app-card mb-3">
    <div class="app-card-header"><span><i class="bi bi-archive me-1"></i> Copias disponibles</span><span class="app-badge"><?= count($copias) ?></span></div>
    <div class="table-responsive">
        <table class="table app-table mb-0">
            <thead><tr><th class="app-n">#</th><th>Fecha</th><th>Origen</th><th class="app-num">Tamaño</th><th class="app-num">Tablas</th><th class="app-num">Filas</th><th class="text-center">Integridad</th><th class="text-end">Acciones</th></tr></thead>
            <tbody>
                <?php if (!$copias): ?><tr><td colspan="8" class="text-center text-muted py-4">Aún no hay respaldos. Usa «Crear respaldo ahora».</td></tr><?php endif; ?>
                <?php foreach ($copias as $i => $c): ?>
                    <tr>
                        <td class="app-n"><?= $i + 1 ?></td>
                        <td class="text-nowrap"><?= date('d/m/Y g:i a', strtotime($c['creado'])) ?><div class="small text-muted font-monospace"><?= htmlspecialchars($c['archivo']) ?></div></td>
                        <td><?= ($c['origen'] ?? '') === 'antes_de_restaurar' ? '<span class="app-badge app-badge-warning"><i class="bi bi-life-preserver"></i> Antes de restaurar</span>' : '' ?><?= ($c['origen'] ?? '') === 'antes_de_restaurar' ? '' : (($c['origen'] ?? '') === 'cron' ? '<span class="app-badge app-badge-info"><i class="bi bi-clock"></i> Automático</span>'
                            : '<span class="app-badge"><i class="bi bi-hand-index"></i> Manual</span>' . (!empty($c['usuario']) ? '<div class="small text-muted">' . htmlspecialchars($usuarios[$c['usuario']] ?? '#' . $c['usuario']) . '</div>' : '')) ?></td>
                        <td class="app-num"><?= $tam($c['bytes']) ?></td>
                        <td class="app-num"><?= $c['tablas'] ?? '—' ?></td>
                        <td class="app-num"><?= isset($c['filas']) ? number_format($c['filas']) : '—' ?></td>
                        <td class="text-center"><?= ($c['integro'] ?? null) === true ? '<span class="app-badge app-badge-success" title="SHA-256: ' . htmlspecialchars($c['sha256']) . '"><i class="bi bi-shield-check"></i> Correcta</span>'
                            : (($c['integro'] ?? null) === false ? '<span class="app-badge app-badge-danger"><i class="bi bi-shield-x"></i> Dañada</span>' : '<span class="text-muted small">Sin huella</span>') ?></td>
                        <td class="text-end text-nowrap">
                            <button type="button" class="btn btn-sm btn-outline-secondary btn-probar" data-archivo="<?= htmlspecialchars($c['archivo']) ?>" title="Descomprimir y comprobar que el respaldo está completo"><i class="bi bi-shield-check"></i></button>
                            <?php if (($c['integro'] ?? null) !== false): ?>
                                <a class="btn btn-sm btn-primary" href="includes/respaldo_accion.php?descargar=<?= urlencode($c['archivo']) ?>" title="Descargar (.sql.gz)"><i class="bi bi-download"></i><span class="d-none d-xxl-inline"> Descargar</span></a>
                                <?php if ($esSuper): ?><button type="button" class="btn btn-sm btn-outline-danger btn-restaurar" data-archivo="<?= htmlspecialchars($c['archivo']) ?>" data-fecha="<?= date('d/m/Y g:i a', strtotime($c['creado'])) ?>" title="Restaurar la base completa a como estaba en esta copia"><i class="bi bi-arrow-counterclockwise"></i></button><?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="app-card mb-3">
    <div class="app-card-header"><span><i class="bi bi-folder-check me-1"></i> Archivos subidos (comprobantes, firmas, documentos, PDF de cobros)</span><span class="app-badge"><?= count($copiasArch) ?> de <?= RESPALDO_ARCHIVOS_COPIAS ?></span></div>
    <div class="table-responsive">
        <table class="table app-table mb-0">
            <thead><tr><th class="app-n">#</th><th>Fecha</th><th>Origen</th><th class="app-num">Archivos</th><th class="app-num">Tamaño</th><th class="text-center">Integridad</th><th class="text-end">Acciones</th></tr></thead>
            <tbody>
                <?php if (!$copiasArch): ?><tr><td colspan="7" class="text-center text-muted py-4">Aún no hay respaldo de archivos. Usa «Respaldar archivos ahora» (el cron lo hace cada día).</td></tr><?php endif; ?>
                <?php foreach ($copiasArch as $i => $c): ?>
                    <tr>
                        <td class="app-n"><?= $i + 1 ?></td>
                        <td class="text-nowrap"><?= date('d/m/Y g:i a', strtotime($c['creado'])) ?><div class="small text-muted font-monospace"><?= htmlspecialchars($c['archivo']) ?></div></td>
                        <td><?= ($c['origen'] ?? '') === 'cron' ? '<span class="app-badge app-badge-info"><i class="bi bi-clock"></i> Automático</span>' : '<span class="app-badge"><i class="bi bi-hand-index"></i> Manual</span>' . (!empty($c['usuario']) ? '<div class="small text-muted">' . htmlspecialchars($usuarios[$c['usuario']] ?? '#' . $c['usuario']) . '</div>' : '') ?></td>
                        <td class="app-num"><?= isset($c['archivos']) ? number_format($c['archivos']) : '—' ?></td>
                        <td class="app-num"><?= $tam($c['bytes']) ?></td>
                        <td class="text-center"><?= ($c['integro'] ?? null) === true ? '<span class="app-badge app-badge-success"><i class="bi bi-shield-check"></i> Correcta</span>' : (($c['integro'] ?? null) === false ? '<span class="app-badge app-badge-danger"><i class="bi bi-shield-x"></i> Dañada</span>' : '<span class="text-muted small">Sin huella</span>') ?></td>
                        <td class="text-end text-nowrap">
                            <?php if (($c['integro'] ?? null) !== false): ?>
                                <a class="btn btn-sm btn-primary" href="includes/respaldo_accion.php?descargar=<?= urlencode($c['archivo']) ?>" title="Descargar (.zip)"><i class="bi bi-download"></i><span class="d-none d-xxl-inline"> Descargar</span></a>
                                <?php if ($esSuper): ?><button type="button" class="btn btn-sm btn-outline-warning btn-restaurar-arch" data-archivo="<?= htmlspecialchars($c['archivo']) ?>" data-fecha="<?= date('d/m/Y g:i a', strtotime($c['creado'])) ?>" title="Recuperar archivos de esta copia"><i class="bi bi-folder-symlink"></i></button><?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="app-card h-100">
            <div class="app-card-header"><span><i class="bi bi-journal-text me-1"></i> Bitácora</span><span class="small text-muted fw-normal">Quién creó, descargó o probó cada copia</span></div>
            <div class="table-responsive">
                <table data-paginar class="table app-table mb-0">
                    <thead><tr><th>Fecha</th><th>Evento</th><th>Detalle</th><th>Usuario</th></tr></thead>
                    <tbody>
                        <?php if (!$bitacora): ?><tr><td colspan="4" class="text-center text-muted py-4">Sin eventos todavía.</td></tr><?php endif; ?>
                        <?php foreach ($bitacora as $b): [$txt, $col, $ico] = $eventos[$b['evento']] ?? [$b['evento'], 'muted', 'bi-dot']; ?>
                            <tr>
                                <td class="small text-nowrap"><?= date('d/m/Y g:i a', strtotime($b['fecha'])) ?></td>
                                <td><span class="app-badge app-badge-<?= $col ?>"><i class="bi <?= $ico ?>"></i> <?= $txt ?></span></td>
                                <td class="small"><?= isset($b['archivo']) ? '<span class="font-monospace text-nowrap" title="' . htmlspecialchars($b['archivo']) . '">' . htmlspecialchars(str_replace(['respaldo_', '.sql.gz'], '', $b['archivo'])) . '</span>' : htmlspecialchars($b['detalle'] ?? '') ?><?= isset($b['resultado']) ? ' · ' . htmlspecialchars($b['resultado']) : '' ?><?= isset($b['motivo']) ? '<div class="text-muted">' . htmlspecialchars($b['motivo']) . '</div>' : '' ?></td>
                                <td class="small"><?= !empty($b['usuario']) ? htmlspecialchars($usuarios[$b['usuario']] ?? '#' . $b['usuario']) : '<span class="text-muted">Cron</span>' ?>
                                    <?= ($b['ip'] ?? 'cron') !== 'cron' ? '<div class="text-muted">' . htmlspecialchars($b['ip']) . '</div>' : '' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="app-card app-card-body h-100 small">
            <h6 class="fw-semibold"><i class="bi bi-shield-lock me-1"></i> Política de respaldo (ISO/IEC 27001 · 8.13)</h6>
            <ul class="mb-3 ps-3">
                <li><strong>Qué:</strong> la base de datos completa (todas las empresas y usuarios del sistema) y los archivos subidos (comprobantes, firmas, documentos, PDF de cobros).</li>
                <li><strong>Cuándo:</strong> todos los días a las 2:15 a.m. (hora de Honduras), y cuando se pide aquí.</li>
                <li><strong>Retención:</strong> base: las últimas <?= RESPALDO_COPIAS ?> copias; archivos: las últimas <?= RESPALDO_ARCHIVOS_COPIAS ?>. Al crear una nueva se borra la más antigua.</li>
                <li><strong>Dónde:</strong> fuera de la carpeta pública del sitio, solo legible por la cuenta del servidor.</li>
                <li><strong>Integridad:</strong> huella SHA-256 de cada copia; se comprueba antes de cada descarga.</li>
                <li><strong>Acceso:</strong> superadmin y administrador de Naranja &amp; Media; cada acción queda en la bitácora.</li>
            </ul>
            <h6 class="fw-semibold"><i class="bi bi-cloud-arrow-down me-1"></i> Recomendado</h6>
            <ul class="mb-3 ps-3">
                <li>Descarga <strong>una copia por semana</strong> y guárdala fuera del servidor (disco cifrado o nube de la empresa): si el hosting falla, las copias de aquí se pierden con él.</li>
                <li>Una vez al mes usa <i class="bi bi-shield-check"></i> para probar una copia, y cada trimestre restáurala en una base de prueba.</li>
                <li>El archivo contiene datos personales y contraseñas cifradas: no lo envíes por correo ni lo dejes en carpetas compartidas.</li>
            </ul>
            <h6 class="fw-semibold"><i class="bi bi-arrow-counterclockwise me-1"></i> Cómo restaurar</h6>
            <ul class="mb-0 ps-3">
                <li><strong>Base de datos</strong> (solo superadmin): botón <i class="bi bi-arrow-counterclockwise"></i> de la copia. <strong>Reemplaza todas las tablas de todas las empresas</strong> con lo de esa fecha; antes se guarda automáticamente el estado actual («Antes de restaurar») para poder deshacerlo. Las bitácoras se conservan completas (no vuelven a la fecha del respaldo) y la de Respaldos está fuera de la base.</li>
                <li><strong>Archivos</strong> (solo superadmin): botón <i class="bi bi-folder-symlink"></i>. Recupera los que faltan o, si eliges, también reemplaza los que cambiaron. <strong>Nunca borra</strong> archivos.</li>
                <li>Fuera del sistema: importar el <code>.sql.gz</code> en phpMyAdmin y descomprimir el <code>.zip</code> en la carpeta del sitio.</li>
            </ul>
        </div>
    </div>
</div>

<script>
(() => {
    const leer = async r => { const t = await r.text(); let d; try { d = JSON.parse(t); } catch (e) { throw new Error('Respuesta inesperada del servidor (' + r.status + ').'); } if (!d.success) throw new Error(d.error); return d; };
    const post = fd => fetch('includes/respaldo_accion.php', { method: 'POST', body: fd }).then(leer);
    document.getElementById('btnCrear')?.addEventListener('click', async () => {
        if (!(await Swal.fire({ title: '¿Crear un respaldo ahora?', text: 'Se copia toda la base de datos. Si ya hay <?= RESPALDO_COPIAS ?> copias, se borra la más antigua.', icon: 'question', showCancelButton: true, confirmButtonText: 'Crear', cancelButtonText: 'Cancelar' })).isConfirmed) return;
        Swal.fire({ title: 'Respaldando…', text: 'Puede tardar unos segundos.', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        const fd = new FormData(); fd.append('accion', 'crear');
        post(fd).then(d => Swal.fire({ icon: 'success', title: 'Listo', text: d.message }).then(() => location.reload()))
            .catch(e => Swal.fire('No se pudo', e.message, 'error'));
    });
    document.getElementById('btnCrearArch')?.addEventListener('click', async () => {
        if (!(await Swal.fire({ title: '¿Respaldar los archivos ahora?', text: 'Se copian comprobantes, firmas, documentos y PDF de cobros. Si ya hay <?= RESPALDO_ARCHIVOS_COPIAS ?> copias, se borra la más antigua.', icon: 'question', showCancelButton: true, confirmButtonText: 'Respaldar', cancelButtonText: 'Cancelar' })).isConfirmed) return;
        Swal.fire({ title: 'Respaldando archivos…', text: 'Puede tardar un minuto.', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        const fd = new FormData(); fd.append('accion', 'crear_archivos');
        post(fd).then(d => Swal.fire({ icon: 'success', title: 'Listo', text: d.message }).then(() => location.reload()))
            .catch(e => Swal.fire('No se pudo', e.message, 'error'));
    });
    // Restaurar la base: confirmar escribiendo RESTAURAR
    document.querySelectorAll('.btn-restaurar').forEach(b => b.addEventListener('click', async () => {
        const r = await Swal.fire({
            icon: 'warning', title: 'Restaurar la base de datos',
            html: `Toda la información de <strong>todas las empresas</strong> volverá a como estaba el <strong>${b.dataset.fecha}</strong>. Lo registrado después se perderá.<br><br>Antes se guardará una copia del estado actual para poder deshacerlo. Las bitácoras (permisos, correos enviados, facturas e inicios de sesión) se conservan completas.<br><br>Escribe <strong>RESTAURAR</strong> para confirmar:`,
            input: 'text', inputPlaceholder: 'RESTAURAR', showCancelButton: true, confirmButtonText: 'Restaurar', cancelButtonText: 'Cancelar', confirmButtonColor: '#dc2626',
            preConfirm: v => v.trim() === 'RESTAURAR' || Swal.showValidationMessage('Escribe RESTAURAR (en mayúsculas).'),
        });
        if (!r.isConfirmed) return;
        Swal.fire({ title: 'Restaurando…', text: 'No cierres esta pestaña.', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        const fd = new FormData(); fd.append('accion', 'restaurar'); fd.append('archivo', b.dataset.archivo); fd.append('confirmacion', 'RESTAURAR');
        post(fd).then(d => Swal.fire({ icon: 'success', title: 'Base restaurada', text: d.message }).then(() => location.reload()))
            .catch(e => Swal.fire('No se pudo restaurar', e.message, 'error'));
    }));
    document.querySelectorAll('.btn-restaurar-arch').forEach(b => b.addEventListener('click', async () => {
        const r = await Swal.fire({
            icon: 'question', title: 'Restaurar archivos', html: `Copia del <strong>${b.dataset.fecha}</strong>. Nunca se borra ningún archivo.`,
            input: 'radio', inputOptions: { faltantes: 'Solo los que faltan (recomendado)', reemplazar: 'También reemplazar los que cambiaron' }, inputValue: 'faltantes',
            showCancelButton: true, confirmButtonText: 'Restaurar', cancelButtonText: 'Cancelar',
        });
        if (!r.isConfirmed) return;
        Swal.fire({ title: 'Restaurando archivos…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        const fd = new FormData(); fd.append('accion', 'restaurar_archivos'); fd.append('archivo', b.dataset.archivo); fd.append('modo', r.value);
        post(fd).then(d => Swal.fire({ icon: 'success', title: 'Listo', text: d.message }).then(() => location.reload()))
            .catch(e => Swal.fire('No se pudo', e.message, 'error'));
    }));
    document.querySelectorAll('.btn-probar').forEach(b => b.addEventListener('click', () => {
        Swal.fire({ title: 'Probando el respaldo…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        const fd = new FormData(); fd.append('accion', 'probar'); fd.append('archivo', b.dataset.archivo);
        post(fd).then(d => Swal.fire({ icon: d.ok ? 'success' : 'error', title: d.ok ? 'Respaldo correcto' : 'Respaldo con problemas', text: d.message }).then(() => location.reload()))
            .catch(e => Swal.fire('No se pudo', e.message, 'error'));
    }));
})();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
