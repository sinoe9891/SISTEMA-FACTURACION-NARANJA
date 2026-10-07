<?php
$titulo = 'Empresas';
require_once '../../includes/db.php';
require_once '../../includes/session.php';

if (USUARIO_ROL !== 'superadmin') {
    header('Location: ./dashboard');
    exit;
}

$empresas = $pdo->query("
    SELECT c.*,
        (SELECT COUNT(*) FROM establecimientos e WHERE e.cliente_id = c.id) AS sucursales,
        (SELECT COUNT(*) FROM usuarios u WHERE u.cliente_id = c.id) AS usuarios,
        (SELECT COUNT(*) FROM facturas f WHERE f.cliente_id = c.id AND f.estado = 'emitida'
            AND f.fecha_emision >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS facturas_mes,
        (SELECT MAX(f.fecha_emision) FROM facturas f WHERE f.cliente_id = c.id) AS ultima_factura
    FROM clientes_saas c
    ORDER BY c.estado = 'inactivo', c.nombre
")->fetchAll(PDO::FETCH_ASSOC);

$totActivas  = count(array_filter($empresas, fn($e) => ($e['estado'] ?? 'activo') === 'activo'));
$totUsuarios = array_sum(array_column($empresas, 'usuarios'));
$totFactMes  = array_sum(array_column($empresas, 'facturas_mes'));
$actual      = (int)($_SESSION['cliente_seleccionado'] ?? 0);

require_once '../../includes/templates/header.php';
?>

<div class="app-page-header">
    <div>
        <h1 class="app-page-title">Empresas</h1>
        <p class="app-page-sub">Clientes del sistema (multiempresa): datos, marca, sucursales y acceso.</p>
    </div>
    <button class="btn btn-primary" id="btnNuevaEmpresa"><i class="bi bi-plus-lg me-1"></i> Nueva empresa</button>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="app-card app-kpi"><div class="app-kpi-label">Empresas</div><div class="app-kpi-value"><?= count($empresas) ?></div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="app-card app-kpi"><div class="app-kpi-label">Activas</div><div class="app-kpi-value"><?= $totActivas ?></div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="app-card app-kpi"><div class="app-kpi-label">Usuarios</div><div class="app-kpi-value"><?= $totUsuarios ?></div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="app-card app-kpi"><div class="app-kpi-label">Facturas del mes</div><div class="app-kpi-value"><?= $totFactMes ?></div></div>
    </div>
</div>

<div class="app-card">
    <div class="app-card-header">
        <span><i class="bi bi-buildings me-1"></i> Listado</span>
        <input type="search" class="form-control form-control-sm" id="buscarEmpresa" placeholder="Buscar…" style="max-width:220px">
    </div>
    <div class="table-responsive">
        <table class="table app-table" id="tablaEmpresas">
            <thead>
                <tr>
                    <th>Empresa</th>
                    <th>Acceso</th>
                    <th>Plan</th>
                    <th>Estado</th>
                    <th class="app-num">Sucursales</th>
                    <th class="app-num">Usuarios</th>
                    <th class="app-num">Facturas mes</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($empresas as $e):
                    $activa = ($e['estado'] ?? 'activo') === 'activo'; ?>
                    <tr data-buscar="<?= htmlspecialchars(mb_strtolower($e['nombre'] . ' ' . $e['subdominio'] . ' ' . $e['rtn'])) ?>">
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <span class="app-select-logo" style="width:36px;height:36px">
                                    <?php if ($e['logo_url']): ?><img src="<?= htmlspecialchars($e['logo_url']) ?>" alt=""><?php else: ?><i class="bi bi-building"></i><?php endif; ?>
                                </span>
                                <div style="min-width:0">
                                    <div class="fw-semibold text-truncate" style="max-width:280px"><?= htmlspecialchars($e['nombre']) ?></div>
                                    <small class="text-muted">RTN <?= htmlspecialchars($e['rtn'] ?: '—') ?><?= (int)$e['id'] === $actual ? ' · <span class="text-primary">seleccionada</span>' : '' ?></small>
                                </div>
                            </div>
                        </td>
                        <td><a href="../<?= rawurlencode($e['subdominio']) ?>/" target="_blank" class="font-monospace small">/clientes/<?= htmlspecialchars($e['subdominio']) ?>/</a></td>
                        <td><span class="app-badge app-badge-info"><?= htmlspecialchars(ucfirst($e['tipo_plan'] ?: 'basico')) ?></span></td>
                        <td><span class="app-badge <?= $activa ? 'app-badge-success' : 'app-badge-muted' ?>"><?= $activa ? 'Activa' : 'Inactiva' ?></span></td>
                        <td class="app-num"><?= (int)$e['sucursales'] ?></td>
                        <td class="app-num"><?= (int)$e['usuarios'] ?></td>
                        <td class="app-num"><?= (int)$e['facturas_mes'] ?></td>
                        <td class="text-end text-nowrap">
                            <button class="btn btn-sm btn-outline-secondary btn-editar" title="Editar"
                                data-empresa='<?= json_encode($e, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>'><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-outline-secondary btn-sucursales" title="Sucursales y puntos de emisión"
                                data-id="<?= (int)$e['id'] ?>" data-nombre="<?= htmlspecialchars($e['nombre']) ?>"><i class="bi bi-shop"></i></button>
                            <button class="btn btn-sm <?= $activa ? 'btn-outline-danger' : 'btn-outline-success' ?> btn-estado"
                                title="<?= $activa ? 'Desactivar' : 'Activar' ?>" data-id="<?= (int)$e['id'] ?>"
                                data-estado="<?= $activa ? 'inactivo' : 'activo' ?>" data-nombre="<?= htmlspecialchars($e['nombre']) ?>">
                                <i class="bi <?= $activa ? 'bi-pause-circle' : 'bi-play-circle' ?>"></i></button>
                            <form method="POST" action="seleccionar_cliente" class="d-inline">
                                <button class="btn btn-sm btn-primary" name="cliente_id" value="<?= (int)$e['id'] ?>" title="Trabajar con esta empresa">
                                    <i class="bi bi-box-arrow-in-right"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: empresa -->
<div class="modal fade" id="modalEmpresa" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
        <form class="modal-content" id="formEmpresa" novalidate>
            <div class="modal-header">
                <h5 class="modal-title" id="tituloModalEmpresa">Nueva empresa</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="id">
                <h6 class="text-muted text-uppercase small fw-bold mb-2">Datos de la empresa</h6>
                <div class="row g-3 mb-3">
                    <div class="col-md-6"><label class="form-label">Nombre comercial *</label><input class="form-control" name="nombre" required maxlength="100"></div>
                    <div class="col-md-6"><label class="form-label">Razón social</label><input class="form-control" name="razon_social" maxlength="255"></div>
                    <div class="col-md-6"><label class="form-label">Nombre corto (menú)</label><input class="form-control" name="alias" maxlength="250" placeholder="Si se deja vacío, se usa el nombre"></div>
                    <div class="col-md-6">
                        <label class="form-label">Subdominio / acceso *</label>
                        <div class="input-group"><span class="input-group-text small">/clientes/</span>
                            <input class="form-control font-monospace" name="subdominio" required maxlength="40" pattern="[a-z0-9][a-z0-9-]{1,39}" placeholder="grupo-velmez"></div>
                        <div class="form-text">Minúsculas, números y guiones. Es la dirección de acceso de la empresa.</div>
                    </div>
                    <div class="col-md-4"><label class="form-label">RTN</label><input class="form-control" name="rtn" maxlength="20" inputmode="numeric" placeholder="14 dígitos"></div>
                    <div class="col-md-4"><label class="form-label">Correo</label><input class="form-control" type="email" name="email" maxlength="100"></div>
                    <div class="col-md-4"><label class="form-label">Teléfono</label><input class="form-control" name="telefono" maxlength="20"></div>
                    <div class="col-md-8"><label class="form-label">Dirección *</label><input class="form-control" name="direccion" required maxlength="250"></div>
                    <div class="col-md-4"><label class="form-label">Plan</label>
                        <select class="form-select" name="tipo_plan">
                            <option value="basico">Básico</option>
                            <option value="profesional">Profesional</option>
                            <option value="empresarial">Empresarial</option>
                        </select>
                    </div>
                </div>

                <h6 class="text-muted text-uppercase small fw-bold mb-2">Marca</h6>
                <div class="row g-3 mb-3">
                    <div class="col-md-6"><label class="form-label">URL del logo</label><input class="form-control" type="url" name="logo_url" placeholder="https://…/logo.png"></div>
                    <div class="col-md-6"><label class="form-label">URL del favicon</label><input class="form-control" type="url" name="favicon_url"></div>
                    <div class="col-md-6"><label class="form-label">Imagen para compartir (OG)</label><input class="form-control" type="url" name="og_image_url"></div>
                    <div class="col-md-6"><label class="form-label">Ícono Apple</label><input class="form-control" type="url" name="apple_touch_icon_url"></div>
                </div>

                <div id="seccionInicial">
                    <h6 class="text-muted text-uppercase small fw-bold mb-2">Configuración inicial</h6>
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label">Primer establecimiento</label><input class="form-control" name="establecimiento_nombre" placeholder="Principal" maxlength="100">
                            <div class="form-text">Se crea con código 001 y punto de emisión 01.</div></div>
                        <div class="col-12"><div class="form-text mb-1">Administrador de la empresa (opcional):</div></div>
                        <div class="col-md-4"><label class="form-label">Nombre</label><input class="form-control" name="admin_nombre" maxlength="100"></div>
                        <div class="col-md-4"><label class="form-label">Correo</label><input class="form-control" type="email" name="admin_correo" maxlength="100" autocomplete="off"></div>
                        <div class="col-md-4"><label class="form-label">Contraseña temporal</label>
                            <div class="input-group">
                                <input class="form-control" type="password" name="admin_clave" minlength="8" autocomplete="new-password">
                                <button class="btn btn-outline-secondary" type="button" id="verClaveAdmin" aria-label="Mostrar contraseña"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="btnGuardarEmpresa"><i class="bi bi-check-lg me-1"></i> Guardar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: sucursales -->
<div class="modal fade" id="modalSucursales" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Sucursales · <span id="sucEmpresaNombre"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div id="listaSucursales" class="d-flex flex-column gap-2"></div>
                <form class="app-card app-card-body mt-3" id="formNuevaSucursal">
                    <div class="fw-semibold mb-2"><i class="bi bi-plus-circle me-1"></i> Nuevo establecimiento</div>
                    <div class="row g-2 align-items-end">
                        <div class="col-8 col-md-7"><label class="form-label">Nombre</label><input class="form-control" name="nombre" required maxlength="100"></div>
                        <div class="col-4 col-md-2"><label class="form-label">Código</label><input class="form-control font-monospace" name="codigo" required pattern="\d{3}" maxlength="3" placeholder="002"></div>
                        <div class="col-12 col-md-3"><button class="btn btn-primary w-100" type="submit">Agregar</button></div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const modalEmp = new bootstrap.Modal(document.getElementById('modalEmpresa'));
    const modalSuc = new bootstrap.Modal(document.getElementById('modalSucursales'));
    const form = document.getElementById('formEmpresa');
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const enviar = (url, datos) => fetch(url, { method: 'POST', body: datos }).then(r => r.json());

    // Buscar
    document.getElementById('buscarEmpresa').addEventListener('input', function () {
        const q = this.value.trim().toLowerCase();
        document.querySelectorAll('#tablaEmpresas tbody tr').forEach(tr => tr.classList.toggle('d-none', q && !tr.dataset.buscar.includes(q)));
    });

    // Nueva / editar
    function abrirEmpresa(e) {
        form.reset();
        form.classList.remove('was-validated');
        form.elements.id.value = e ? e.id : '';
        document.getElementById('tituloModalEmpresa').textContent = e ? 'Editar empresa' : 'Nueva empresa';
        document.getElementById('seccionInicial').classList.toggle('d-none', !!e);
        if (e) ['nombre','razon_social','alias','subdominio','rtn','email','telefono','direccion','tipo_plan','logo_url','favicon_url','og_image_url','apple_touch_icon_url']
            .forEach(k => { if (form.elements[k]) form.elements[k].value = e[k] ?? ''; });
        modalEmp.show();
    }
    document.getElementById('btnNuevaEmpresa').addEventListener('click', () => abrirEmpresa(null));
    document.querySelectorAll('.btn-editar').forEach(b => b.addEventListener('click', () => abrirEmpresa(JSON.parse(b.dataset.empresa))));
    // Sugerir subdominio desde el nombre
    form.elements.nombre.addEventListener('input', function () {
        if (form.elements.id.value || form.elements.subdominio.dataset.tocado) return;
        form.elements.subdominio.value = this.value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
            .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40);
    });
    form.elements.subdominio.addEventListener('input', function () { this.dataset.tocado = '1'; });
    document.getElementById('verClaveAdmin').addEventListener('click', function () {
        const i = form.elements.admin_clave; i.type = i.type === 'password' ? 'text' : 'password';
        this.innerHTML = i.type === 'password' ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
    });
    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        if (!form.checkValidity()) { form.classList.add('was-validated'); return; }
        const btn = document.getElementById('btnGuardarEmpresa');
        btn.disabled = true;
        enviar('includes/empresa_guardar.php', new FormData(form)).then(d => {
            if (!d.success) throw new Error(d.error);
            modalEmp.hide();
            Swal.fire('Listo', d.message, 'success').then(() => location.reload());
        }).catch(err => Swal.fire('No se pudo guardar', err.message, 'error')).finally(() => btn.disabled = false);
    });

    // Activar / desactivar
    document.querySelectorAll('.btn-estado').forEach(b => b.addEventListener('click', () => {
        const activar = b.dataset.estado === 'activo';
        Swal.fire({
            title: activar ? '¿Activar empresa?' : '¿Desactivar empresa?',
            html: `<strong>${esc(b.dataset.nombre)}</strong><br>` + (activar ? 'Sus usuarios podrán volver a entrar.' : 'Sus usuarios no podrán entrar. Los datos se conservan.'),
            icon: activar ? 'question' : 'warning', showCancelButton: true,
            confirmButtonText: activar ? 'Activar' : 'Desactivar', cancelButtonText: 'Cancelar',
            confirmButtonColor: activar ? '#16a34a' : '#dc2626'
        }).then(r => {
            if (!r.isConfirmed) return;
            const fd = new FormData(); fd.append('id', b.dataset.id); fd.append('estado', b.dataset.estado);
            enviar('includes/empresa_estado.php', fd).then(d => d.success ? location.reload() : Swal.fire('Error', d.error, 'error'));
        });
    }));

    // Sucursales
    let empresaSuc = null;
    function cargarSucursales() {
        const cont = document.getElementById('listaSucursales');
        cont.innerHTML = '<div class="text-muted small">Cargando…</div>';
        fetch('includes/empresa_sucursales.php?empresa_id=' + empresaSuc).then(r => r.json()).then(d => {
            if (!d.success) throw new Error(d.error);
            if (!d.establecimientos.length) { cont.innerHTML = '<div class="alert alert-warning mb-0">Sin establecimientos.</div>'; return; }
            cont.innerHTML = d.establecimientos.map(e => `
                <div class="app-card app-card-body py-2">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="font-monospace fw-bold">${esc(e.codigo)}</span>
                        <span class="fw-semibold">${esc(e.nombre)}</span>
                        ${e.codigo_duplicado ? '<span class="app-badge app-badge-warning" title="Otro establecimiento de esta empresa usa el mismo código">Código repetido</span>' : ''}
                        <small class="text-muted">${e.facturas} factura(s)</small>
                        <span class="ms-auto d-flex gap-1">
                            <button class="btn btn-sm btn-outline-secondary py-0 btn-edit-est" data-id="${e.id}" data-nombre="${esc(e.nombre)}" data-codigo="${esc(e.codigo)}" title="Editar"><i class="bi bi-pencil"></i></button>
                            ${+e.facturas === 0 ? `<button class="btn btn-sm btn-outline-danger py-0 btn-del-est" data-id="${e.id}" data-nombre="${esc(e.nombre)}" title="Eliminar"><i class="bi bi-trash"></i></button>` : ''}
                        </span>
                    </div>
                    <div class="d-flex flex-column gap-1 mt-2">
                        ${e.puntos.map(p => `<div class="d-flex flex-wrap align-items-center gap-2 small border rounded px-2 py-1">
                            <span class="font-monospace fw-semibold">Punto ${esc(p.codigo)}</span><span>${esc(p.descripcion || '')}</span>
                            <span class="text-muted">${p.municipio ? esc(p.municipio + ', ' + p.departamento) : 'Sin ubicación'}</span>
                            <span class="text-muted">· ${p.cais} CAI</span>
                            <span class="ms-auto d-flex gap-1">
                                <button class="btn btn-sm btn-link p-0 btn-edit-punto" data-est="${e.id}" data-punto='${esc(JSON.stringify(p))}' title="Editar punto"><i class="bi bi-pencil"></i></button>
                                ${+p.cais === 0 && e.puntos.length > 1 ? `<button class="btn btn-sm btn-link p-0 text-danger btn-del-punto" data-id="${p.id}" title="Eliminar punto"><i class="bi bi-trash"></i></button>` : ''}
                            </span></div>`).join('')}
                        <div><button class="btn btn-sm btn-outline-primary py-0 btn-add-punto" data-est="${e.id}"><i class="bi bi-plus"></i> Punto de emisión</button></div>
                    </div>
                </div>`).join('');
            cont.querySelectorAll('.btn-add-punto').forEach(b => b.addEventListener('click', () => editarPunto(b.dataset.est, null)));
            cont.querySelectorAll('.btn-edit-punto').forEach(b => b.addEventListener('click', () => editarPunto(b.dataset.est, JSON.parse(b.dataset.punto))));
            cont.querySelectorAll('.btn-edit-est').forEach(b => b.addEventListener('click', () => editarEst(b.dataset)));
            cont.querySelectorAll('.btn-del-est').forEach(b => b.addEventListener('click', () => eliminar('eliminar_establecimiento', b.dataset.id, `¿Eliminar el establecimiento «${b.dataset.nombre}» y sus puntos de emisión?`)));
            cont.querySelectorAll('.btn-del-punto').forEach(b => b.addEventListener('click', () => eliminar('eliminar_punto', b.dataset.id, '¿Eliminar este punto de emisión?')));
        }).catch(err => cont.innerHTML = `<div class="alert alert-danger mb-0">${esc(err.message)}</div>`);
    }
    document.querySelectorAll('.btn-sucursales').forEach(b => b.addEventListener('click', () => {
        empresaSuc = b.dataset.id;
        document.getElementById('sucEmpresaNombre').textContent = b.dataset.nombre;
        cargarSucursales();
        modalSuc.show();
    }));
    document.getElementById('formNuevaSucursal').addEventListener('submit', function (ev) {
        ev.preventDefault();
        const fd = new FormData(this); fd.append('accion', 'establecimiento'); fd.append('empresa_id', empresaSuc);
        enviar('includes/empresa_sucursales.php', fd).then(d => {
            if (!d.success) return Swal.fire('Error', d.error, 'error');
            this.reset(); cargarSucursales();
        });
    });
    function editarEst(ds) {
        Swal.fire({
            title: 'Editar establecimiento',
            html: `<input id="eNombre" class="swal2-input" value="${esc(ds.nombre)}" placeholder="Nombre">
                   <input id="eCodigo" class="swal2-input" value="${esc(ds.codigo)}" placeholder="Código (3 dígitos)" maxlength="3">`,
            showCancelButton: true, confirmButtonText: 'Guardar', cancelButtonText: 'Cancelar',
            preConfirm: () => {
                const fd = new FormData();
                fd.append('accion', 'establecimiento'); fd.append('id', ds.id); fd.append('empresa_id', empresaSuc);
                fd.append('nombre', document.getElementById('eNombre').value); fd.append('codigo', document.getElementById('eCodigo').value);
                return enviar('includes/empresa_sucursales.php', fd).then(d => { if (!d.success) throw new Error(d.error); })
                    .catch(e => Swal.showValidationMessage(e.message));
            }
        }).then(r => { if (r.isConfirmed) cargarSucursales(); });
    }
    // Departamentos y municipios (se cargan una vez)
    let ubic = null;
    const ubicaciones = () => ubic ? Promise.resolve(ubic) : fetch('includes/empresa_sucursales.php?ubicaciones=1').then(r => r.json()).then(d => (ubic = d));
    async function editarPunto(est, p) {
        const u = await ubicaciones();
        const opDep = u.departamentos.map(d => `<option value="${d.id}" ${p && +p.departamento_id === +d.id ? 'selected' : ''}>${esc(d.nombre)}</option>`).join('');
        Swal.fire({
            title: p ? 'Editar punto de emisión' : 'Nuevo punto de emisión',
            html: `<div class="text-start small">
                <label class="form-label mb-1">Código</label><input id="pCodigo" class="form-control mb-2" maxlength="3" placeholder="01" value="${p ? esc(p.codigo) : ''}" ${p && +p.cais > 0 ? 'readonly title="Tiene CAI: el código no se puede cambiar"' : ''}>
                <label class="form-label mb-1">Descripción</label><input id="pDesc" class="form-control mb-2" maxlength="100" placeholder="Ej: Punto Tegucigalpa" value="${p ? esc(p.descripcion || '') : ''}">
                <label class="form-label mb-1">Departamento</label><select id="pDep" class="form-select mb-2"><option value="">—</option>${opDep}</select>
                <label class="form-label mb-1">Municipio</label><select id="pMun" class="form-select"></select></div>`,
            showCancelButton: true, confirmButtonText: 'Guardar', cancelButtonText: 'Cancelar', focusConfirm: false,
            didOpen: () => {
                const dep = document.getElementById('pDep'), mun = document.getElementById('pMun');
                const llenar = () => { mun.innerHTML = '<option value="">—</option>' + u.municipios.filter(m => +m.departamento_id === +dep.value)
                    .map(m => `<option value="${m.id}" ${p && +p.municipio_id === +m.id ? 'selected' : ''}>${esc(m.nombre)}</option>`).join(''); };
                dep.addEventListener('change', llenar); llenar();
            },
            preConfirm: () => {
                const fd = new FormData();
                fd.append('accion', 'punto'); fd.append('establecimiento_id', est); if (p) fd.append('id', p.id);
                fd.append('codigo', document.getElementById('pCodigo').value); fd.append('descripcion', document.getElementById('pDesc').value);
                fd.append('departamento_id', document.getElementById('pDep').value); fd.append('municipio_id', document.getElementById('pMun').value);
                return enviar('includes/empresa_sucursales.php', fd).then(d => { if (!d.success) throw new Error(d.error); })
                    .catch(e => Swal.showValidationMessage(e.message));
            }
        }).then(r => { if (r.isConfirmed) cargarSucursales(); });
    }
    async function eliminar(accion, id, pregunta) {
        if (!(await Swal.fire({ title: pregunta, icon: 'warning', showCancelButton: true, confirmButtonText: 'Eliminar', cancelButtonText: 'Cancelar', confirmButtonColor: '#dc3545' })).isConfirmed) return;
        const fd = new FormData(); fd.append('accion', accion); fd.append('id', id);
        enviar('includes/empresa_sucursales.php', fd).then(d => { if (!d.success) return Swal.fire('No se pudo', d.error, 'error'); cargarSucursales(); });
    }
})();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
