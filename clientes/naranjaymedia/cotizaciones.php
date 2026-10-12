<?php
// Cotizaciones: lista con estados (borrador, enviada, aceptada, rechazada, facturada, vencida), envío por correo y paso a factura.
$titulo = 'Cotizaciones';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/cotizaciones.php';

$cid = (int)cliente_actual();
$instalada = cotizacionesDisponible($pdo);
$puedeEditar = in_array(USUARIO_ROL, ['admin', 'superadmin', 'facturador'], true);
$esAdmin = in_array(USUARIO_ROL, ['admin', 'superadmin'], true);
$puedeFacturar = $puedeEditar && permisoPuede($pdo, 'generar_factura');
$lista = [];
$cfg = [];
if ($instalada) {
    $st = $pdo->prepare("SELECT c.*, cf.nombre AS cliente, cf.email AS cliente_email, f.correlativo AS factura_correlativo,
                                (SELECT COUNT(*) FROM cotizacion_items i WHERE i.cotizacion_id = c.id) AS lineas
                         FROM cotizaciones c JOIN clientes_factura cf ON cf.id = c.receptor_id LEFT JOIN facturas f ON f.id = c.factura_id
                         WHERE c.cliente_id = ? ORDER BY c.fecha DESC, c.id DESC");
    $st->execute([$cid]);
    $lista = $st->fetchAll(PDO::FETCH_ASSOC);
    $cfg = cotizacionConfig($pdo, $cid);
}
$cuenta = array_fill_keys(array_keys(COTIZACION_ESTADOS), 0);
$montos = array_fill_keys(array_keys(COTIZACION_ESTADOS), 0.0);
foreach ($lista as &$c) {
    $c['vista'] = cotizacionEstadoVista($c);
    $cuenta[$c['vista']]++;
    $montos[$c['vista']] += (float)$c['total'];
}
unset($c);
require_once '../../includes/templates/header.php';
?>

<div class="app-page-header">
    <div>
        <h1 class="app-page-title"><i class="bi bi-file-earmark-text me-2"></i>Cotizaciones</h1>
        <p class="app-page-sub">Propuestas a clientes con su PDF, envío por correo y paso a factura cuando el cliente acepta.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($esAdmin && $instalada): ?><button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#mConfig"><i class="bi bi-gear me-1"></i> Textos por defecto</button><?php endif; ?>
        <?php if ($puedeEditar && $instalada): ?><a href="cotizacion_editar" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Nueva cotización</a><?php endif; ?>
    </div>
</div>

<?php if (!$instalada): ?>
    <div class="alert alert-warning">Falta instalar <code>sql/migraciones/2026-10-12_cotizaciones.sql</code>.</div>
<?php else: ?>
    <div class="app-stats">
        <?php foreach (['borrador', 'enviada', 'aceptada', 'facturada', 'vencida', 'rechazada'] as $k): [$txt, $bg, $fg, $ico] = COTIZACION_ESTADOS[$k]; ?>
            <a href="#" class="app-stat text-decoration-none cot-filtro-rapido" data-estado="<?= $k ?>">
                <div class="app-stat-icon" style="background:<?= $bg ?>;color:<?= $fg ?>"><i class="bi <?= $ico ?>"></i></div>
                <div><div class="app-stat-val"><?= $cuenta[$k] ?></div><div class="app-stat-lbl"><?= $txt ?> · L <?= number_format($montos[$k], 2) ?></div></div>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="app-card">
        <div class="app-card-header flex-wrap gap-2">
            <span><i class="bi bi-list-ul me-1"></i> Cotizaciones</span>
            <div class="app-toolbar flex-grow-1 justify-content-end">
                <div class="app-search" style="max-width:320px"><i class="bi bi-search"></i><input type="search" class="form-control form-control-sm" id="cotBuscar" placeholder="Buscar número, cliente o servicio…"></div>
                <select class="form-select form-select-sm" id="cotEstado" style="width:auto"><option value="">Todos los estados</option>
                    <?php foreach (COTIZACION_ESTADOS as $k => $e): ?><option value="<?= $k ?>"><?= $e[0] ?></option><?php endforeach; ?></select>
                <select class="form-select form-select-sm" id="cotPorPagina" style="width:auto"><option value="25">25/pág</option><option value="50">50/pág</option><option value="100">100/pág</option></select>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table app-table" id="tCot" data-ordenar>
                <thead><tr><th class="app-n">#</th><th>Número</th><th>Fecha</th><th>Cliente</th><th>Servicio</th><th>Válida hasta</th><th class="app-num">Total</th><th>Estado</th><th class="text-end no-orden">Acciones</th></tr></thead>
                <tbody>
                    <?php foreach ($lista as $c): [$txt, $bg, $fg, $ico] = COTIZACION_ESTADOS[$c['vista']]; $dRest = (int)floor((strtotime($c['valida_hasta']) - strtotime(date('Y-m-d'))) / 86400); ?>
                        <tr data-fila data-estado="<?= $c['vista'] ?>" data-buscar="<?= htmlspecialchars(mb_strtolower($c['numero'] . ' ' . $c['cliente'] . ' ' . $c['servicio'])) ?>">
                            <td class="app-n"></td>
                            <td class="font-monospace small text-nowrap"><a href="cotizacion_editar?id=<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['numero']) ?></a></td>
                            <td class="text-nowrap" data-sort="<?= $c['fecha'] ?>"><?= date('d/m/Y', strtotime($c['fecha'])) ?></td>
                            <td><?= htmlspecialchars($c['cliente']) ?><?= $c['enviada_a'] ? '<div class="small text-muted" title="Enviada el ' . date('d/m/Y g:i a', strtotime($c['enviada_en'])) . '"><i class="bi bi-envelope-check me-1"></i>' . htmlspecialchars($c['enviada_a']) . '</div>' : '' ?></td>
                            <td class="small"><?= htmlspecialchars((string)$c['servicio']) ?: '<span class="text-muted">' . (int)$c['lineas'] . ' línea(s)</span>' ?></td>
                            <td class="text-nowrap" data-sort="<?= $c['valida_hasta'] ?>"><?= date('d/m/Y', strtotime($c['valida_hasta'])) ?>
                                <?php if (in_array($c['vista'], ['borrador', 'enviada'], true)): ?><div class="small <?= $dRest <= 3 ? 'text-danger' : 'text-muted' ?>"><?= $dRest === 0 ? 'vence hoy' : 'faltan ' . $dRest . ' día' . ($dRest === 1 ? '' : 's') ?></div><?php endif; ?></td>
                            <td class="app-num fw-semibold">L <?= number_format((float)$c['total'], 2) ?></td>
                            <td><span class="badge" style="background:<?= $bg ?>;color:<?= $fg ?>"><i class="bi <?= $ico ?> me-1"></i><?= $txt ?></span>
                                <?php if ($c['factura_id']): ?><div class="small"><a href="ver_factura?id=<?= (int)$c['factura_id'] ?>" target="_blank" class="font-monospace"><?= htmlspecialchars((string)$c['factura_correlativo']) ?></a></div><?php endif; ?></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-danger" href="cotizacion_pdf?id=<?= (int)$c['id'] ?>" target="_blank" title="Ver PDF"><i class="bi bi-file-earmark-pdf"></i></a>
                                <?php if ($puedeEditar): ?>
                                    <button class="btn btn-sm btn-outline-success cot-enviar" data-id="<?= (int)$c['id'] ?>" title="Enviar por correo"><i class="bi bi-send"></i></button>
                                    <?php if ($c['estado'] !== 'facturada' && $puedeFacturar): ?><a class="btn btn-sm btn-outline-primary" href="generar_factura?cotizacion_id=<?= (int)$c['id'] ?>" title="Convertir en factura"><i class="bi bi-receipt"></i></a><?php endif; ?>
                                    <div class="btn-group">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' title="Más"><i class="bi bi-three-dots"></i></button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <?php if ($c['estado'] !== 'facturada'): ?>
                                                <li><a class="dropdown-item" href="cotizacion_editar?id=<?= (int)$c['id'] ?>"><i class="bi bi-pencil me-2"></i>Editar</a></li>
                                                <li><a class="dropdown-item cot-estado" href="#" data-id="<?= (int)$c['id'] ?>" data-estado="aceptada"><i class="bi bi-hand-thumbs-up me-2 text-success"></i>Marcar aceptada</a></li>
                                                <li><a class="dropdown-item cot-estado" href="#" data-id="<?= (int)$c['id'] ?>" data-estado="rechazada"><i class="bi bi-hand-thumbs-down me-2 text-danger"></i>Marcar rechazada</a></li>
                                                <?php if ($c['estado'] !== 'borrador'): ?><li><a class="dropdown-item cot-estado" href="#" data-id="<?= (int)$c['id'] ?>" data-estado="<?= $c['enviada_en'] ? 'enviada' : 'borrador' ?>"><i class="bi bi-arrow-counterclockwise me-2"></i>Quitar respuesta</a></li><?php endif; ?>
                                            <?php endif; ?>
                                            <li><a class="dropdown-item cot-duplicar" href="#" data-id="<?= (int)$c['id'] ?>"><i class="bi bi-copy me-2"></i>Duplicar</a></li>
                                            <li><a class="dropdown-item" href="cotizacion_pdf?id=<?= (int)$c['id'] ?>&descargar=1"><i class="bi bi-download me-2"></i>Descargar PDF</a></li>
                                            <?php if ($esAdmin && $c['estado'] !== 'facturada'): ?><li><hr class="dropdown-divider"></li><li><a class="dropdown-item text-danger cot-eliminar" href="#" data-id="<?= (int)$c['id'] ?>" data-numero="<?= htmlspecialchars($c['numero']) ?>"><i class="bi bi-trash3 me-2"></i>Eliminar</a></li><?php endif; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="app-pager" id="cotPie"></div>
    </div>

    <!-- Enviar por correo -->
    <div class="modal fade" id="mEnviar" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down"><form class="modal-content" id="fEnviar" novalidate>
            <div class="modal-header"><h5 class="modal-title"><i class="bi bi-send me-1"></i> Enviar cotización <span class="font-monospace" id="eNumero"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="accion" value="enviar"><input type="hidden" name="id">
                <div id="eAviso" class="alert alert-warning small d-none">La cuenta de correo de facturación no está configurada: ve a Configuración → Correo.</div>
                <div class="row g-2">
                    <div class="col-md-6"><label class="form-label">Para *</label><input name="para" class="form-control" placeholder="correo@cliente.com (varios separados por coma)"></div>
                    <div class="col-md-6"><label class="form-label">CC <span class="text-muted small">(opcional)</span></label><input name="cc" class="form-control"></div>
                    <div class="col-12"><label class="form-label">Asunto *</label><input name="asunto" class="form-control" maxlength="255"></div>
                    <div class="col-12"><label class="form-label">Mensaje</label>
                        <div id="eMensaje" class="form-control" contenteditable="true" style="min-height:220px;overflow:auto;line-height:1.6"></div></div>
                    <div class="col-12 small text-muted" id="eCopias"></div>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <button type="button" class="btn btn-outline-secondary" id="eVista"><i class="bi bi-eye me-1"></i> Ver cómo llegará</button>
                <span><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button class="btn btn-success"><i class="bi bi-send me-1"></i> Enviar con el PDF</button></span>
            </div>
        </form></div>
    </div>
    <div class="modal fade" id="mVista" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Vista del correo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <iframe id="eIframe" style="width:100%;height:70vh;border:0" sandbox=""></iframe></div></div>
    </div>

    <?php if ($esAdmin): ?>
    <!-- Textos por defecto -->
    <div class="modal fade" id="mConfig" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable"><form class="modal-content" id="fConfig">
            <div class="modal-header"><h5 class="modal-title"><i class="bi bi-gear me-1"></i> Textos por defecto de las cotizaciones</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body row g-2">
                <input type="hidden" name="accion" value="config">
                <div class="col-md-3"><label class="form-label">Vigencia (días)</label><input type="number" name="vigencia_dias" class="form-control" min="1" max="365" value="<?= (int)$cfg['vigencia_dias'] ?>"></div>
                <div class="col-md-9"><label class="form-label">Lema bajo el logo</label><input name="lema" class="form-control" maxlength="200" value="<?= htmlspecialchars($cfg['lema']) ?>"></div>
                <div class="col-md-5"><label class="form-label">Correo de contacto</label><input name="contacto_email" class="form-control" maxlength="150" value="<?= htmlspecialchars($cfg['contacto_email']) ?>" placeholder="Si se deja vacío, el de la empresa"></div>
                <div class="col-md-3"><label class="form-label">Teléfono</label><input name="contacto_telefono" class="form-control" maxlength="50" value="<?= htmlspecialchars($cfg['contacto_telefono']) ?>"></div>
                <div class="col-md-4"><label class="form-label">Firma</label><select name="firmante" class="form-select">
                    <?php foreach (FIRMANTES_ROLES as $k => $t): ?><option value="<?= $k ?>" <?= $cfg['firmante'] === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select>
                    <div class="form-text">Nombre, cargo y firma de Configuración → Firmas.</div></div>
                <div class="col-12"><label class="form-label fw-semibold">Incluye</label><textarea name="incluye" id="cfgIncluye" class="form-control" rows="4"><?= htmlspecialchars($cfg['incluye']) ?></textarea></div>
                <div class="col-12"><label class="form-label fw-semibold">Condiciones</label><textarea name="condiciones" id="cfgCondiciones" class="form-control" rows="4"><?= htmlspecialchars($cfg['condiciones']) ?></textarea></div>
                <div class="col-12"><label class="form-label">Asunto del correo</label><input name="asunto_correo" class="form-control" maxlength="255" value="<?= htmlspecialchars($cfg['asunto_correo']) ?>"></div>
                <div class="col-12"><label class="form-label">Mensaje del correo <span class="text-muted small">(vacío = el mensaje profesional del sistema)</span></label><textarea name="mensaje_correo" class="form-control" rows="5"><?= htmlspecialchars($cfg['mensaje_correo']) ?></textarea>
                    <div class="form-text">Variables: {numero} {cliente} {contacto} {servicio} {total} {valida_hasta} {empresa}. En Incluye y Condiciones: {dias}.</div></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary">Guardar</button></div>
        </form></div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<script src="../../clientes/js/app-tabla.js?v=<?= @filemtime(__DIR__ . '/../js/app-tabla.js') ?>"></script>
<script src="../../clientes/js/lista-vinetas.js?v=<?= @filemtime(__DIR__ . '/../js/lista-vinetas.js') ?>"></script>
<script>
ListaVinetas(document.getElementById('cfgIncluye'), { placeholderTexto: 'Ej.: Una prueba digital para aprobación.' });
ListaVinetas(document.getElementById('cfgCondiciones'), { placeholderTitulo: 'Ej.: Forma de pago', sugerencias: ['Forma de pago', 'Entrega', 'Cambios', 'Vigencia', 'Garantía', 'Soporte', 'Tiempo de entrega'] });
</script>
<script>
(() => {
    const ACC = 'includes/cotizacion_accion.php';
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const post = async fd => { const r = await fetch(ACC, { method: 'POST', body: fd }); const d = await r.json().catch(() => ({ success: false, error: 'Respuesta inesperada (' + r.status + ').' })); if (!d.success) throw new Error(d.error); return d; };
    const fd = o => { const f = new FormData(); Object.entries(o).forEach(([k, v]) => f.append(k, v)); return f; };
    const estado = document.getElementById('cotEstado');
    if (!estado) return;
    const tabla = AppTabla('#tCot', { buscar: '#cotBuscar', porPagina: '#cotPorPagina', pie: '#cotPie', filtro: tr => !estado.value || tr.dataset.estado === estado.value, vacio: 'Sin cotizaciones.' });
    estado.addEventListener('change', () => tabla.refrescar());
    document.querySelectorAll('.cot-filtro-rapido').forEach(a => a.addEventListener('click', e => { e.preventDefault(); estado.value = estado.value === a.dataset.estado ? '' : a.dataset.estado; tabla.refrescar(); }));

    document.addEventListener('click', async e => {
        const est = e.target.closest('.cot-estado'), dup = e.target.closest('.cot-duplicar'), del = e.target.closest('.cot-eliminar'), env = e.target.closest('.cot-enviar');
        try {
            if (est) { e.preventDefault(); await post(fd({ accion: 'estado', id: est.dataset.id, estado: est.dataset.estado })); location.reload(); }
            if (dup) { e.preventDefault(); const d = await post(fd({ accion: 'duplicar', id: dup.dataset.id })); location.href = 'cotizacion_editar?id=' + d.id; }
            if (del) {
                e.preventDefault();
                if (!(await Swal.fire({ icon: 'warning', title: '¿Eliminar la cotización ' + del.dataset.numero + '?', showCancelButton: true, confirmButtonText: 'Eliminar', confirmButtonColor: '#dc2626', cancelButtonText: 'Cancelar' })).isConfirmed) return;
                await post(fd({ accion: 'eliminar', id: del.dataset.id })); location.reload();
            }
            if (env) abrirEnvio(env.dataset.id);
        } catch (err) { Swal.fire('No se pudo', err.message, 'error'); }
    });

    // Envío por correo
    const mEnv = document.getElementById('mEnviar') && new bootstrap.Modal(document.getElementById('mEnviar'));
    const fE = document.getElementById('fEnviar');
    async function abrirEnvio(id) {
        const d = await fetch(ACC + '?correo=' + id).then(r => r.json());
        if (!d.success) return Swal.fire('No se pudo', d.error, 'error');
        fE.reset(); fE.id.value = id; fE.para.value = d.para; fE.asunto.value = d.asunto;
        document.getElementById('eNumero').textContent = d.numero;
        document.getElementById('eMensaje').innerHTML = d.mensaje;
        document.getElementById('eAviso').classList.toggle('d-none', d.configurado);
        document.getElementById('eCopias').innerHTML = (d.copias.length ? '<i class="bi bi-eye-slash me-1"></i>Copia oculta a: ' + esc(d.copias.join(', ')) + '. ' : '')
            + (d.responder_a ? '<i class="bi bi-reply me-1"></i>Las respuestas del cliente llegan a: ' + esc(d.responder_a) + '.' : '');
        mEnv.show();
    }
    fE?.addEventListener('submit', async e => {
        e.preventDefault();
        if (!fE.para.value.trim()) return fE.para.focus();
        const b = fE.querySelector('.btn-success'); b.disabled = true; b.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Enviando…';
        try {
            const f = new FormData(fE); f.append('mensaje', document.getElementById('eMensaje').innerHTML);
            const d = await post(f);
            mEnv.hide();
            Swal.fire({ icon: 'success', title: 'Enviada', text: d.message }).then(() => location.reload());
        } catch (err) { Swal.fire('No se pudo enviar', err.message, 'error'); }
        finally { b.disabled = false; b.innerHTML = '<i class="bi bi-send me-1"></i> Enviar con el PDF'; }
    });
    document.getElementById('eVista')?.addEventListener('click', async () => {
        try {
            const d = await post(fd({ accion: 'vista_correo', id: fE.id.value, mensaje: document.getElementById('eMensaje').innerHTML }));
            document.getElementById('eIframe').srcdoc = d.html;
            new bootstrap.Modal(document.getElementById('mVista')).show();
        } catch (err) { Swal.fire('No se pudo', err.message, 'error'); }
    });

    document.getElementById('fConfig')?.addEventListener('submit', async e => {
        e.preventDefault();
        try { const d = await post(new FormData(e.target)); Swal.fire({ icon: 'success', title: d.message, timer: 1400, showConfirmButton: false }).then(() => location.reload()); }
        catch (err) { Swal.fire('No se pudo', err.message, 'error'); }
    });

    const q = new URLSearchParams(location.search);
    if (q.get('enviar')) { history.replaceState(null, '', location.pathname); abrirEnvio(q.get('enviar')); }
    if (q.get('guardada')) { history.replaceState(null, '', location.pathname); Swal.fire({ icon: 'success', title: 'Cotización guardada', timer: 1400, showConfirmButton: false }); }
})();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
