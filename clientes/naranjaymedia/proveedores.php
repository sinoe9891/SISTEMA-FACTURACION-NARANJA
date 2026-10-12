<?php
// Proveedores: catálogo de a quién se le paga (con su logo). Gastos y licencias lo eligen de una lista.
$titulo = 'Proveedores';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/proveedores.php';

if (!permisoPuede($pdo, 'proveedores')) {
    header('Location: dashboard');
    exit;
}
$cid = (int)cliente_actual();
$instalado = proveedoresDisponible($pdo);
$lista = $instalado ? proveedoresLista($pdo, $cid) : [];
$L = fn($v) => 'L ' . number_format((float)$v, 2);
$activos = count(array_filter($lista, fn($p) => (int)$p['activo']));
$totalPagado = array_sum(array_column($lista, 'pagado'));
require_once '../../includes/templates/header.php';
?>

<div class="app-page-header">
    <div>
        <h1 class="app-page-title"><i class="bi bi-truck me-2"></i>Proveedores</h1>
        <p class="app-page-sub">A quién se le paga: personas, empresas, software, bancos y gobierno. En Gastos y Licencias se elige de esta lista. Con su sitio web, el logo se descarga solo.</p>
    </div>
    <?php if ($instalado): ?><div><button class="btn btn-primary" id="btnNuevoProv"><i class="bi bi-plus-circle me-1"></i> Nuevo proveedor</button></div><?php endif; ?>
</div>

<?php if (!$instalado): ?>
    <div class="alert alert-warning">Falta instalar el módulo de proveedores (migración 2026-10-11_proveedores.sql).</div>
<?php else: ?>

<div class="app-stats">
    <div class="app-stat"><div class="app-stat-icon"><i class="bi bi-truck"></i></div><div><div class="app-stat-val"><?= $activos ?></div><div class="app-stat-lbl">Proveedores activos (de <?= count($lista) ?>)</div></div></div>
    <div class="app-stat"><div class="app-stat-icon amber"><i class="bi bi-cash-stack"></i></div><div><div class="app-stat-val"><?= $L($totalPagado) ?></div><div class="app-stat-lbl">Pagado en total (gastos pagados)</div></div></div>
</div>

<div class="app-card">
    <div class="app-card-body d-flex flex-wrap gap-2 align-items-center border-bottom">
        <div class="input-group" style="max-width:320px">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="search" class="form-control" id="pvBuscar" placeholder="Buscar proveedor, RTN, sitio…" autocomplete="off">
        </div>
        <select class="form-select" id="pvTipo" style="width:auto" aria-label="Tipo"><option value="">Todos los tipos</option><?php foreach (PROVEEDOR_TIPOS as $k => [$t]): ?><option value="<?= $k ?>"><?= $t ?></option><?php endforeach; ?></select>
        <select class="form-select" id="pvEstado" style="width:auto" aria-label="Estado"><option value="1" selected>Activos</option><option value="0">Inactivos</option><option value="">Todos</option></select>
        <span class="small text-muted ms-auto" id="pvCuenta"></span>
    </div>
    <div class="table-responsive">
        <table class="table app-table mb-0 align-middle" id="pvTabla">
            <thead><tr>
                <th class="pv-orden" data-col="0" style="cursor:pointer">Proveedor <i class="bi bi-arrow-down-up small text-muted"></i></th>
                <th class="pv-orden" data-col="1" style="cursor:pointer">Tipo <i class="bi bi-arrow-down-up small text-muted"></i></th>
                <th>Contacto</th>
                <th class="app-num pv-orden" data-col="2" style="cursor:pointer">Gastos <i class="bi bi-arrow-down-up small text-muted"></i></th>
                <th class="app-num pv-orden" data-col="3" style="cursor:pointer">Pagado <i class="bi bi-arrow-down-up small text-muted"></i></th>
                <th class="pv-orden" data-col="4" style="cursor:pointer">Último pago <i class="bi bi-arrow-down-up small text-muted"></i></th>
                <th class="text-end">Acciones</th>
            </tr></thead>
            <tbody>
                <?php if (!$lista): ?><tr><td colspan="7" class="text-center text-muted py-4">Sin proveedores todavía.</td></tr><?php endif; ?>
                <?php foreach ($lista as $p): $act = (int)$p['activo']; $tipo = PROVEEDOR_TIPOS[$p['tipo']] ?? PROVEEDOR_TIPOS['otro']; $dom = proveedorDominio($p['sitio_web']); ?>
                    <tr class="<?= $act ? '' : 'text-muted' ?>" data-fila data-activo="<?= $act ?>" data-tipo="<?= htmlspecialchars($p['tipo']) ?>"
                        data-buscar="<?= htmlspecialchars(mb_strtolower(implode(' ', [$p['nombre'], $tipo[0], $p['rtn'], $p['sitio_web'], $p['telefono'], $p['correo'], $p['notas']]))) ?>"
                        data-orden='<?= htmlspecialchars(json_encode([mb_strtolower($p['nombre']), $tipo[0], (int)$p['gastos_n'], (float)$p['pagado'], (string)$p['ultimo_pago']], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'>
                        <td><div class="d-flex align-items-center gap-2"><?= proveedorAvatar($p, 32) ?>
                            <div><div class="fw-semibold"><?= htmlspecialchars($p['nombre']) ?><?= $act ? '' : ' <span class="badge bg-light text-secondary border">Inactivo</span>' ?></div>
                                <div class="small text-muted"><?= $dom ? '<a href="https://' . htmlspecialchars($dom) . '" target="_blank" rel="noopener" class="text-muted">' . htmlspecialchars($dom) . '</a>' : '' ?><?= $p['rtn'] ? ($dom ? ' · ' : '') . 'RTN ' . htmlspecialchars($p['rtn']) : '' ?><?= (int)$p['licencias_n'] ? ' · ' . (int)$p['licencias_n'] . ' licencia(s)' : '' ?></div></div></div></td>
                        <td><span class="small" style="color:<?= $tipo[2] ?>"><i class="bi <?= $tipo[1] ?> me-1"></i><?= $tipo[0] ?></span></td>
                        <td class="small"><?= htmlspecialchars(implode(' · ', array_filter([$p['telefono'], $p['correo']]))) ?: '<span class="text-muted">—</span>' ?></td>
                        <td class="app-num"><?= (int)$p['gastos_n'] ? '<a href="gastos?vista=anual&anio=' . date('Y') . '" class="text-decoration-none" title="Ver gastos">' . (int)$p['gastos_n'] . '</a>' : '0' ?></td>
                        <td class="app-num fw-semibold"><?= $L($p['pagado']) ?></td>
                        <td class="text-nowrap small"><?= $p['ultimo_pago'] ? date('d/m/Y', strtotime($p['ultimo_pago'])) : '—' ?></td>
                        <td class="text-end text-nowrap">
                            <button class="btn btn-sm btn-outline-secondary py-0 btn-editar-prov" data-p='<?= htmlspecialchars(json_encode($p, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>' title="Editar"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-outline-secondary py-0 btn-logo-prov" data-id="<?= (int)$p['id'] ?>" data-web="<?= $dom ? 1 : 0 ?>" title="Cambiar logo"><i class="bi bi-image"></i></button>
                            <button class="btn btn-sm <?= $act ? 'btn-outline-danger' : 'btn-outline-success' ?> py-0 btn-activo-prov" data-id="<?= (int)$p['id'] ?>" data-activo="<?= $act ? 0 : 1 ?>" title="<?= $act ? 'Desactivar' : 'Activar' ?>"><i class="bi <?= $act ? 'bi-pause-circle' : 'bi-play-circle' ?>"></i></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="modalProv" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="provTitulo"><i class="bi bi-truck me-1"></i> Nuevo proveedor</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <form id="formProv" class="row g-3">
                <input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" value="0">
                <div class="col-12"><label class="form-label small">Nombre <span class="text-danger">*</span></label><input class="form-control" name="nombre" maxlength="150" required></div>
                <div class="col-md-6"><label class="form-label small">Tipo</label><select class="form-select" name="tipo"><?php foreach (PROVEEDOR_TIPOS as $k => [$t]): ?><option value="<?= $k ?>"><?= $t ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label small">Sitio web <span class="text-muted">(para el logo)</span></label><input class="form-control" name="sitio_web" maxlength="150" placeholder="godaddy.com"></div>
                <div class="col-md-6"><label class="form-label small">RTN</label><input class="form-control" name="rtn" maxlength="20"></div>
                <div class="col-md-6"><label class="form-label small">Teléfono</label><input class="form-control" name="telefono" maxlength="40"></div>
                <div class="col-12"><label class="form-label small">Correo</label><input class="form-control" type="email" name="correo" maxlength="150"></div>
                <div class="col-12"><label class="form-label small">Notas</label><textarea class="form-control" name="notas" rows="2" placeholder="Cuenta, qué se le compra, condiciones…"></textarea></div>
            </form>
        </div>
        <div class="modal-footer"><button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" id="btnGuardarProv"><i class="bi bi-check-circle me-1"></i>Guardar</button></div>
    </div></div>
</div>
<?php endif; ?>

<script>
(() => {
    const post = fd => fetch('includes/proveedor_accion.php', { method: 'POST', body: fd }).then(async r => { const d = await r.json().catch(() => ({ success: false, error: 'Respuesta inesperada del servidor.' })); if (!d.success) throw new Error(d.error); return d; });
    const modal = document.getElementById('modalProv') ? new bootstrap.Modal('#modalProv') : null, f = document.getElementById('formProv');
    document.getElementById('btnNuevoProv')?.addEventListener('click', () => { f.reset(); f.id.value = 0; document.getElementById('provTitulo').innerHTML = '<i class="bi bi-truck me-1"></i> Nuevo proveedor'; modal.show(); });
    document.querySelectorAll('.btn-editar-prov').forEach(b => b.addEventListener('click', () => {
        const p = JSON.parse(b.dataset.p); f.reset();
        ['id', 'nombre', 'tipo', 'sitio_web', 'rtn', 'telefono', 'correo', 'notas'].forEach(k => f.elements[k].value = p[k] ?? '');
        document.getElementById('provTitulo').innerHTML = '<i class="bi bi-pencil me-1"></i> Editar proveedor'; modal.show();
    }));
    document.getElementById('btnGuardarProv')?.addEventListener('click', () => {
        if (!f.reportValidity()) return;
        const b = document.getElementById('btnGuardarProv'); b.disabled = true;
        post(new FormData(f)).then(d => Swal.fire({ icon: 'success', title: d.message, timer: 1300, showConfirmButton: false }).then(() => location.reload()))
            .catch(e => Swal.fire('No se pudo guardar', e.message, 'error')).finally(() => b.disabled = false);
    });
    document.querySelectorAll('.btn-activo-prov').forEach(b => b.addEventListener('click', () => {
        const fd = new FormData(); fd.append('accion', 'activo'); fd.append('id', b.dataset.id); fd.append('activo', b.dataset.activo);
        post(fd).then(() => location.reload()).catch(e => Swal.fire('No se pudo', e.message, 'error'));
    }));
    document.querySelectorAll('.btn-logo-prov').forEach(b => b.addEventListener('click', async () => {
        const r = await Swal.fire({ title: 'Logo del proveedor', html: `<input type="file" id="pvLogo" class="form-control" accept=".png,.jpg,.jpeg,.webp"><div class="small text-muted mt-2">PNG, JPG o WEBP, máx. 1 MB.</div>`,
            showCancelButton: true, confirmButtonText: 'Subir', cancelButtonText: 'Cancelar', showDenyButton: b.dataset.web === '1', denyButtonText: 'Bajarlo del sitio web',
            preConfirm: () => document.getElementById('pvLogo').files[0] || Swal.showValidationMessage('Selecciona una imagen.') });
        if (r.isDismissed) return;
        const fd = new FormData(); fd.append('accion', 'logo'); fd.append('id', b.dataset.id);
        if (r.isDenied) fd.append('recargar', 1); else fd.append('logo', r.value);
        post(fd).then(() => location.reload()).catch(e => Swal.fire('No se pudo', e.message, 'error'));
    }));
    // Búsqueda al momento, filtros y orden
    const tb = document.querySelector('#pvTabla tbody'), filas = [...tb.querySelectorAll('tr[data-fila]')];
    let col = 0, dir = 1;
    const pintar = () => {
        const q = document.getElementById('pvBuscar').value.trim().toLowerCase(), t = document.getElementById('pvTipo').value, e = document.getElementById('pvEstado').value;
        const r = filas.filter(tr => (!q || tr.dataset.buscar.includes(q)) && (!t || tr.dataset.tipo === t) && (!e || tr.dataset.activo === e))
            .sort((a, b) => { const x = JSON.parse(a.dataset.orden)[col], y = JSON.parse(b.dataset.orden)[col]; return (typeof x === 'number' ? x - y : String(x).localeCompare(String(y), 'es', { numeric: true })) * dir; });
        filas.forEach(tr => tr.style.display = 'none');
        r.forEach(tr => { tr.style.display = ''; tb.appendChild(tr); });
        document.getElementById('pvCuenta').textContent = `${r.length} de ${filas.length} proveedores`;
        document.querySelectorAll('#pvTabla .pv-orden i').forEach(i => i.className = 'bi bi-arrow-down-up small text-muted');
        document.querySelector(`#pvTabla .pv-orden[data-col="${col}"] i`).className = 'bi ' + (dir > 0 ? 'bi-arrow-up' : 'bi-arrow-down') + ' small';
    };
    document.getElementById('pvBuscar').addEventListener('input', pintar);
    ['pvTipo', 'pvEstado'].forEach(id => document.getElementById(id).addEventListener('change', pintar));
    document.querySelectorAll('#pvTabla .pv-orden').forEach(th => th.addEventListener('click', () => { const c = +th.dataset.col; dir = col === c ? -dir : (c >= 2 ? -1 : 1); col = c; pintar(); }));
    if (filas.length) pintar();
})();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
