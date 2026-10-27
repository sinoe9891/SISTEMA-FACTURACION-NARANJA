<?php
// Pagos y gastos → Comisiones por venta: las comisiones de las facturas (esperando cobro, por pagar, pagadas), pagarlas
// (gasto + salida del banco + comprobante), editarlas, quitarlas, y el catálogo de vendedores (colaboradores y externos).
// Los filtros se aplican sin recargar (se reemplaza la zona #cmZona).
$titulo = 'Comisiones por venta';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/comisiones.php';

$cid = (int)cliente_actual();
$instalado = comisionesDisponible($pdo);
$esAdmin = in_array(USUARIO_ROL, ['admin', 'superadmin'], true);
$puedeEditar = $esAdmin || USUARIO_ROL === 'facturador';
$fechaOk = fn($v, $d) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v)) ? $v : $d;
$desde = $fechaOk($_GET['desde'] ?? null, date('Y-01-01'));
$hasta = $fechaOk($_GET['hasta'] ?? null, date('Y-m-d'));
if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];
$fVend = (int)($_GET['vendedor'] ?? 0);
$fEstado = isset(COMISION_VISTAS[$_GET['estado'] ?? '']) ? $_GET['estado'] : '';
$vendedores = $instalado ? vendedoresLista($pdo, $cid, false) : [];
$lista = $instalado ? comisionesLista($pdo, $cid, ['desde' => $desde, 'hasta' => $hasta, 'vendedor' => $fVend, 'estado' => $fEstado]) : [];
$archivos = $instalado ? comisionArchivos($pdo, $cid, array_column($lista, 'id')) : [];   // comprobantes de cada pago
$tot = ['esperando' => 0.0, 'por_pagar' => 0.0, 'pagada' => 0.0];
$n = ['esperando' => 0, 'por_pagar' => 0, 'pagada' => 0];
foreach ($lista as $c) if (isset($tot[$c['vista']])) { $tot[$c['vista']] += (float)$c['monto']; $n[$c['vista']]++; }
$cuentasHnl = bancosDisponible($pdo) ? array_values(array_filter(bancoCuentas($pdo, $cid, true), fn($c) => $c['moneda'] === 'HNL')) : [];
$hoy = new DateTime('today');
$rangos = ['Este mes' => [$hoy->format('Y-m-01'), $hoy->format('Y-m-d')], 'Mes anterior' => [(new DateTime('first day of last month'))->format('Y-m-d'), (new DateTime('last day of last month'))->format('Y-m-d')],
    'Este año' => [$hoy->format('Y-01-01'), $hoy->format('Y-m-d')], 'Todo' => ['2000-01-01', $hoy->format('Y-m-d')]];
$L = fn($v) => 'L ' . number_format((float)$v, 2);
$e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
require_once '../../includes/templates/header.php';
?>

<div class="app-page-header">
    <div>
        <h1 class="app-page-title"><i class="bi bi-person-badge me-2"></i>Comisiones por venta</h1>
        <p class="app-page-sub">Se asignan en la factura (vendedor y % del subtotal o cantidad fija). Quedan «Esperando cobro» hasta que el cliente paga y luego «Por pagar»; al pagarlas se registran como gasto.</p>
    </div>
    <?php if ($instalado): ?><div class="d-flex gap-2 flex-wrap"><button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#mVendedores"><i class="bi bi-people me-1"></i> Vendedores</button>
        <a href="generar_factura" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Nueva factura</a></div><?php endif; ?>
</div>

<?php if (!$instalado): ?>
    <div class="alert alert-warning">Falta instalar <code>sql/migraciones/2026-10-27_comisiones.sql</code>.</div>
<?php else: ?>
<form id="cmFiltros" class="app-card app-card-body mb-3" method="get">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2"><label class="form-label small">Desde</label><input type="date" class="form-control form-control-sm" name="desde" value="<?= $desde ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small">Hasta</label><input type="date" class="form-control form-control-sm" name="hasta" value="<?= $hasta ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label small">Vendedor</label><select class="form-select form-select-sm" name="vendedor"><option value="">Todos</option>
            <?php foreach ($vendedores as $v): ?><option value="<?= (int)$v['id'] ?>" <?= $fVend === (int)$v['id'] ? 'selected' : '' ?>><?= $e($v['nombre']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small">Estado</label><select class="form-select form-select-sm" name="estado"><option value="">Todos</option>
            <?php foreach (COMISION_VISTAS as $k => [$t]): ?><option value="<?= $k ?>" <?= $fEstado === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-3 d-flex flex-wrap gap-1"><?php foreach ($rangos as $t => [$d, $h]): ?><a class="btn btn-sm <?= $d === $desde && $h === $hasta ? 'btn-secondary' : 'btn-outline-secondary' ?> cm-rango" href="?desde=<?= $d ?>&hasta=<?= $h ?>" data-desde="<?= $d ?>" data-hasta="<?= $h ?>"><?= $t ?></a><?php endforeach; ?></div>
    </div>
</form>

<div id="cmZona">
    <div class="app-stats">
        <?php foreach (['esperando' => ['gray', 'bi-hourglass'], 'por_pagar' => ['amber', 'bi-cash-coin'], 'pagada' => ['green', 'bi-check-circle']] as $k => [$col, $ico]): ?>
            <a href="#" class="app-stat text-decoration-none cm-ver" data-estado="<?= $k ?>"><div class="app-stat-icon <?= $col ?>"><i class="bi <?= $ico ?>"></i></div><div><div class="app-stat-val"><?= $L($tot[$k]) ?></div><div class="app-stat-lbl"><?= COMISION_VISTAS[$k][0] ?> · <?= $n[$k] ?></div></div></a>
        <?php endforeach; ?>
    </div>
    <div class="app-card">
        <div class="app-card-header flex-wrap gap-2"><span><i class="bi bi-list-ul me-1"></i> Comisiones de facturas del <?= date('d/m/Y', strtotime($desde)) ?> al <?= date('d/m/Y', strtotime($hasta)) ?></span>
            <div class="app-toolbar flex-grow-1 justify-content-end">
                <div class="app-search" style="max-width:300px"><i class="bi bi-search"></i><input type="search" class="form-control form-control-sm" id="cmBuscar" placeholder="Buscar factura, cliente, vendedor…"></div>
                <select class="form-select form-select-sm" id="cmPorPagina" style="width:auto"><option value="25">25/pág</option><option value="50">50/pág</option><option value="100">100/pág</option><option value="100000">Todas</option></select></div></div>
        <div class="table-responsive"><table class="table app-table" id="tCom" data-ordenar>
            <thead><tr><th class="app-n">#</th><th>Factura</th><th>Cliente</th><th>Vendedor</th><th>Comisión</th><th class="app-num">Monto</th><th>Estado</th><th class="text-end no-orden"></th></tr></thead>
            <tbody>
            <?php foreach ($lista as $c): [$vTxt, $vBg, $vFg, $vIco] = COMISION_VISTAS[$c['vista']]; ?>
                <tr data-fila data-buscar="<?= $e(mb_strtolower($c['correlativo'] . ' ' . $c['cliente'] . ' ' . $c['vendedor'] . ' ' . $c['identidad'])) ?>">
                    <td class="app-n"></td>
                    <td class="text-nowrap" data-sort="<?= $c['fecha_factura'] ?>"><a href="ver_factura?id=<?= (int)$c['factura_id'] ?>" target="_blank" class="font-monospace small"><?= $e($c['correlativo']) ?></a><div class="small text-muted"><?= date('d/m/Y', strtotime($c['fecha_factura'])) ?></div></td>
                    <td><?= $e($c['cliente']) ?></td>
                    <td><?= $e($c['vendedor']) ?><div class="small text-muted"><?= $c['vendedor_tipo'] === 'colaborador' ? 'Colaborador' : 'Externo' ?><?= $c['identidad'] ? ' · ID ' . $e($c['identidad']) : '' ?><?= !empty($c['cuenta_banco']) ? '<br><i class="bi bi-bank me-1"></i>' . $e($c['cuenta_banco']) : '' ?></div></td>
                    <td class="small"><?= $c['tipo'] === 'fijo' ? 'Cantidad fija' : rtrim(rtrim(number_format((float)$c['valor'], 2), '0'), '.') . ' % de ' . $L($c['base']) ?><?= $c['notas'] ? '<div class="text-muted">' . $e($c['notas']) . '</div>' : '' ?></td>
                    <td class="app-num fw-semibold" data-sort="<?= (float)$c['monto'] ?>"><?= $L($c['monto']) ?></td>
                    <td><span class="badge" style="background:<?= $vBg ?>;color:<?= $vFg ?>"><i class="bi <?= $vIco ?> me-1"></i><?= $vTxt ?></span>
                        <?php if ($c['vista'] === 'pagada'): ?><div class="small text-muted"><?= date('d/m/Y', strtotime((string)$c['pagada_en'])) ?> · <a href="gasto_ver?id=<?= (int)$c['gasto_id'] ?>">gasto #<?= (int)$c['gasto_id'] ?></a></div>
                            <?php $arch = $archivos[(int)$c['id']] ?? []; if ($arch): ?><div class="small"><?php foreach ($arch as $k => $a): ?><a href="comision_archivo?archivo=<?= (int)$a['id'] ?>" target="_blank" class="me-2 text-nowrap" title="<?= $e($a['nombre']) ?>"><i class="bi bi-paperclip"></i>Comprobante<?= count($arch) > 1 ? ' ' . ($k + 1) : '' ?></a><?php endforeach; ?></div>
                            <?php elseif (!empty($c['gasto_archivo'])): ?><div class="small"><a href="gasto_archivo?id=<?= (int)$c['gasto_id'] ?>" target="_blank"><i class="bi bi-paperclip"></i>Comprobante</a></div><?php endif; endif; ?></td>
                    <td class="text-end text-nowrap">
                        <?php if ($puedeEditar && $c['vista'] !== 'pagada' && $c['vista'] !== 'anulada'): ?><button class="btn btn-sm btn-outline-primary cm-editar" data-c='<?= $e(json_encode(['id' => (int)$c['id'], 'vendedor_id' => (int)$c['vendedor_id'], 'tipo' => $c['tipo'], 'valor' => (float)$c['valor'], 'notas' => (string)$c['notas'], 'correlativo' => $c['correlativo']])) ?>' title="Editar"><i class="bi bi-pencil"></i></button><?php endif; ?>
                        <?php if ($esAdmin && in_array($c['vista'], ['por_pagar', 'esperando'], true)): ?><button class="btn btn-sm btn-success cm-pagar" data-id="<?= (int)$c['id'] ?>" data-monto="<?= (float)$c['monto'] ?>" data-cobrada="<?= (int)$c['pagada'] ?>" data-desc="<?= $e($c['vendedor'] . ' · factura ' . $c['correlativo'] . (!empty($c['cuenta_banco']) ? ' · pagar a ' . $c['cuenta_banco'] : '')) ?>" title="Pagar"><i class="bi bi-cash"></i></button><?php endif; ?>
                        <?php if ($c['vista'] === 'pagada'): ?><a class="btn btn-sm btn-outline-secondary" href="comision_recibo_pdf?id=<?= (int)$c['id'] ?>" target="_blank" title="Recibo para que lo firme quien recibe la comisión"><i class="bi bi-file-earmark-pdf"></i> Recibo</a><?php endif; ?>
                        <?php if ($esAdmin && $c['vista'] === 'pagada'): ?><button class="btn btn-sm btn-outline-primary cm-editar-pago" data-p='<?= $e(json_encode(['id' => (int)$c['id'], 'fecha' => (string)($c['fecha_pago'] ?: substr((string)$c['pagada_en'], 0, 10)), 'monto' => (float)$c['monto'], 'metodo' => (string)($c['metodo_pago'] ?: 'transferencia'), 'cuenta' => (int)$c['cuenta_pago'], 'comprobante' => !empty($c['gasto_archivo']), 'archivos' => array_map(fn($a) => ['id' => (int)$a['id'], 'nombre' => $a['nombre']], $archivos[(int)$c['id']] ?? [])])) ?>' data-desc="<?= $e($c['vendedor'] . ' · factura ' . $c['correlativo']) ?>" title="Editar el pago"><i class="bi bi-pencil-square"></i></button><?php endif; ?>
                        <?php if ($esAdmin && $c['vista'] === 'pagada'): ?><button class="btn btn-sm btn-outline-danger cm-anular" data-id="<?= (int)$c['id'] ?>" title="Anular el pago"><i class="bi bi-arrow-counterclockwise"></i></button><?php endif; ?>
                        <?php if ($esAdmin && $c['vista'] !== 'pagada'): ?><button class="btn btn-sm btn-outline-danger cm-quitar" data-id="<?= (int)$c['id'] ?>" title="Quitar la comisión"><i class="bi bi-trash3"></i></button><?php endif; ?>
                    </td></tr>
            <?php endforeach; ?>
            <?php if (!$lista): ?><tr><td colspan="8" class="text-center text-muted py-4">No hay comisiones con estos filtros. Se agregan al crear o editar una factura.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
        <div class="app-pager" id="cmPie"></div>
    </div>
</div>

<!-- Editar comisión -->
<div class="modal fade" id="mEditar" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" id="fEditar" novalidate>
    <div class="modal-header"><h5 class="modal-title">Editar comisión · <span id="eFact" class="font-monospace"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body row g-2"><input type="hidden" name="accion" value="editar"><input type="hidden" name="id">
        <div class="col-12"><label class="form-label small">Vendedor</label><select name="vendedor_id" class="form-select"><?php foreach ($vendedores as $v): ?><option value="<?= (int)$v['id'] ?>"><?= $e($v['nombre']) ?><?= $v['identidad'] ? ' · ID ' . $e($v['identidad']) : '' ?></option><?php endforeach; ?></select></div>
        <div class="col-6"><label class="form-label small">Comisión</label><select name="tipo" class="form-select"><option value="porcentaje">% del subtotal</option><option value="fijo">Cantidad fija (L)</option></select></div>
        <div class="col-6"><label class="form-label small">Valor</label><input type="number" name="valor" class="form-control" step="0.01" min="0"></div>
        <div class="col-12"><label class="form-label small">Nota</label><input name="notas" class="form-control" maxlength="255"></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary">Guardar</button></div>
</form></div></div>

<!-- Pagar comisión -->
<div class="modal fade" id="mPagar" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" id="fPagar" novalidate>
    <div class="modal-header"><h5 class="modal-title" id="pTitulo">Pagar comisión</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body row g-2"><input type="hidden" name="accion" value="pagar"><input type="hidden" name="id">
        <div class="col-12 small text-muted" id="pDesc"></div>
        <div class="col-12 d-none" id="pAviso"><div class="alert alert-warning py-2 small mb-0"><input class="form-check-input me-1" type="checkbox" name="antes_de_cobro" value="1" id="pAntes"><label for="pAntes">El cliente aún no paga la factura: pagar la comisión igual</label></div></div>
        <div class="col-6"><label class="form-label small">Fecha</label><input type="date" name="fecha" class="form-control"></div>
        <div class="col-6"><label class="form-label small">Monto (L)</label><input type="number" name="monto" class="form-control" step="0.01" min="0.01"></div>
        <div class="col-6"><label class="form-label small">Método</label><select name="metodo" class="form-select"><option value="transferencia">Transferencia</option><option value="efectivo">Efectivo</option><option value="cheque">Cheque</option><option value="otro">Otro</option></select></div>
        <div class="col-6"><label class="form-label small">Sale de la cuenta</label><select name="cuenta_id" class="form-select"><option value="">— No registrar en banco —</option><?php foreach ($cuentasHnl as $cb): if (!(int)$cb['activa']) continue; ?><option value="<?= (int)$cb['id'] ?>" <?= !empty($cb['predeterminada']) ? 'selected' : '' ?>><?= $e($cb['banco'] . ' ' . $cb['numero']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label small">Comprobantes (opcional, podés elegir varios)</label><div id="pArchivos" class="small mb-1"></div><input type="file" name="comprobantes[]" multiple class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.webp,.pdf"><div class="form-text d-none" id="pCompHint">Los comprobantes que ya tiene se quedan; los nuevos se agregan.</div></div>
        <div class="col-12"><label class="form-label small">Notas</label><input name="notas" class="form-control" maxlength="200"></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-success" id="pBoton">Pagar</button></div>
</form></div></div>

<!-- Vendedores -->
<div class="modal fade" id="mVendedores" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="bi bi-people me-1"></i> Vendedores</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><p class="small text-muted">Los colaboradores activos aparecen solos (con su DPI). Agrega aquí a los externos (referidores) con su número de identidad.</p>
        <div class="table-responsive"><table class="table table-sm align-middle">
            <thead><tr><th>Nombre</th><th>ID</th><th>Cuenta para pagarle</th><th>Teléfono</th><th>Correo</th><th class="text-center">Activo</th><th></th></tr></thead>
            <tbody>
            <?php foreach (array_merge($vendedores, [['id' => 0, 'tipo' => 'externo', 'nombre' => '', 'identidad' => '', 'cuenta_banco' => '', 'telefono' => '', 'email' => '', 'activo' => 1]]) as $v): ?>
                <tr class="v-fila" data-id="<?= (int)$v['id'] ?>">
                    <td><input class="form-control form-control-sm" name="nombre" value="<?= $e($v['nombre']) ?>" placeholder="<?= $v['id'] ? '' : 'Nuevo vendedor…' ?>" maxlength="150"><?= $v['tipo'] === 'colaborador' ? '<div class="small text-muted">Colaborador</div>' : '' ?></td>
                    <td><input class="form-control form-control-sm" name="identidad" value="<?= $e($v['identidad']) ?>" maxlength="30" style="width:140px"></td>
                    <td><input class="form-control form-control-sm" name="cuenta_banco" value="<?= $e($v['cuenta_banco'] ?? '') ?>" maxlength="150" placeholder="Ej.: BAC 729176511" style="width:170px"></td>
                    <td><input class="form-control form-control-sm" name="telefono" value="<?= $e($v['telefono']) ?>" maxlength="40" style="width:120px"></td>
                    <td><input class="form-control form-control-sm" name="email" value="<?= $e($v['email']) ?>" maxlength="150"></td>
                    <td class="text-center"><input class="form-check-input" type="checkbox" name="activo" <?= (int)$v['activo'] ? 'checked' : '' ?>></td>
                    <td><?php if ($puedeEditar): ?><button class="btn btn-sm <?= $v['id'] ? 'btn-outline-primary' : 'btn-primary' ?> v-guardar"><?= $v['id'] ? 'Guardar' : 'Agregar' ?></button><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div></div>
</div></div></div>
<?php endif; ?>

<script src="../../clientes/js/app-tabla.js?v=<?= @filemtime(__DIR__ . '/../js/app-tabla.js') ?>"></script>
<script>
(() => {
    const f = document.getElementById('cmFiltros');
    if (!f) return;
    const ACC = 'includes/comision_accion.php';
    const post = async fd => { const r = await fetch(ACC, { method: 'POST', body: fd }); const d = await r.json().catch(() => ({ success: false, error: 'Respuesta inesperada (' + r.status + ').' })); if (!d.success) throw new Error(d.error); return d; };
    const listo = d => Swal.fire({ icon: 'success', title: d.message, timer: 1500, showConfirmButton: false }).then(() => cargar(location.pathname + location.search));
    let pedido = 0;
    const iniciarTabla = () => { AppTabla(document.getElementById('tCom'), { buscar: '#cmBuscar', porPagina: '#cmPorPagina', pie: '#cmPie', vacio: 'Sin resultados.' }); window.AppOrdenarIniciar?.(); };
    iniciarTabla();
    const cargar = async url => {
        const yo = ++pedido, z = document.getElementById('cmZona'); z.style.opacity = '.5';
        try {
            const r = await fetch(url, { credentials: 'same-origin' }); if (!r.ok) throw new Error();
            const doc = new DOMParser().parseFromString(await r.text(), 'text/html'); if (yo !== pedido) return;
            z.replaceWith(doc.getElementById('cmZona'));
            document.querySelectorAll('.cm-rango').forEach(a => { const on = a.dataset.desde === f.desde.value && a.dataset.hasta === f.hasta.value; a.classList.toggle('btn-secondary', on); a.classList.toggle('btn-outline-secondary', !on); });
            history.replaceState(null, '', url); iniciarTabla();
        } catch (e) { location.href = url; } finally { document.getElementById('cmZona').style.removeProperty('opacity'); }
    };
    const urlForm = () => location.pathname + '?' + new URLSearchParams(new FormData(f)).toString();
    f.addEventListener('change', () => cargar(urlForm()));
    document.addEventListener('click', async e => {
        const a = e.target.closest('.cm-rango'); if (a) { e.preventDefault(); f.desde.value = a.dataset.desde; f.hasta.value = a.dataset.hasta; return cargar(urlForm()); }
        const s = e.target.closest('.cm-ver'); if (s) { e.preventDefault(); f.estado.value = s.dataset.estado; return cargar(urlForm()); }
        const ed = e.target.closest('.cm-editar');
        if (ed) { const c = JSON.parse(ed.dataset.c), fe = document.getElementById('fEditar'); fe.id.value = c.id; fe.vendedor_id.value = c.vendedor_id; fe.tipo.value = c.tipo; fe.valor.value = c.valor; fe.notas.value = c.notas; document.getElementById('eFact').textContent = c.correlativo; bootstrap.Modal.getOrCreateInstance(document.getElementById('mEditar')).show(); return; }
        const pg = e.target.closest('.cm-pagar');
        const aq = e.target.closest('.cm-arch-quitar');
        if (aq) { if (!(await Swal.fire({ icon: 'warning', title: '¿Quitar este comprobante?', showCancelButton: true, confirmButtonText: 'Quitar', cancelButtonText: 'Cancelar', confirmButtonColor: '#dc2626' })).isConfirmed) return;
            const fd = new FormData(); fd.append('accion', 'archivo_quitar'); fd.append('archivo', aq.dataset.id);
            try { await post(fd); aq.closest('.badge').remove(); cargar(location.pathname + location.search); } catch (x) { Swal.fire('No se pudo', x.message, 'error'); } return; }
        const ep = e.target.closest('.cm-editar-pago');
        if (ep) { const p = JSON.parse(ep.dataset.p), fp = document.getElementById('fPagar'); fp.reset(); fp.accion.value = 'editar_pago'; fp.id.value = p.id;
            fp.fecha.value = p.fecha; fp.monto.value = p.monto; fp.metodo.value = p.metodo; fp.cuenta_id.value = p.cuenta || '';
            document.getElementById('pTitulo').textContent = 'Editar pago de la comisión'; document.getElementById('pBoton').textContent = 'Guardar cambios';
            document.getElementById('pCompHint').classList.toggle('d-none', !p.comprobante && !p.archivos.length);
            document.getElementById('pArchivos').innerHTML = p.archivos.map(a => '<span class="badge text-bg-light border me-1 mb-1 fw-normal"><a href="comision_archivo?archivo=' + a.id + '" target="_blank"><i class="bi bi-paperclip"></i>' + a.nombre.replace(/[&<>"]/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ch])) + '</a> <button type="button" class="btn btn-link btn-sm p-0 text-danger cm-arch-quitar" data-id="' + a.id + '" title="Quitar"><i class="bi bi-x-lg"></i></button></span>').join('')
                || (p.comprobante ? '<span class="text-muted"><i class="bi bi-paperclip"></i>Ya tiene un comprobante.</span>' : '');
            document.getElementById('pDesc').textContent = ep.dataset.desc + ' · se anula el pago anterior y se registra con estos datos'; document.getElementById('pAviso').classList.add('d-none');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('mPagar')).show(); return; }
        if (pg) { const fp = document.getElementById('fPagar'); fp.reset(); fp.accion.value = 'pagar'; document.getElementById('pTitulo').textContent = 'Pagar comisión'; document.getElementById('pBoton').textContent = 'Pagar'; document.getElementById('pCompHint').classList.add('d-none'); document.getElementById('pArchivos').innerHTML = ''; fp.id.value = pg.dataset.id; fp.monto.value = pg.dataset.monto; fp.fecha.value = new Date().toLocaleDateString('en-CA');
            document.getElementById('pDesc').textContent = pg.dataset.desc; document.getElementById('pAviso').classList.toggle('d-none', pg.dataset.cobrada === '1');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('mPagar')).show(); return; }
        const an = e.target.closest('.cm-anular');
        if (an) { if (!(await Swal.fire({ icon: 'warning', title: '¿Anular el pago?', text: 'Se anula el gasto y su salida del banco; la comisión vuelve a «Por pagar».', showCancelButton: true, confirmButtonText: 'Anular', cancelButtonText: 'Cancelar', confirmButtonColor: '#dc2626' })).isConfirmed) return;
            const fd = new FormData(); fd.append('accion', 'anular_pago'); fd.append('id', an.dataset.id); try { listo(await post(fd)); } catch (x) { Swal.fire('No se pudo', x.message, 'error'); } return; }
        const q = e.target.closest('.cm-quitar');
        if (q) { if (!(await Swal.fire({ icon: 'warning', title: '¿Quitar la comisión de la factura?', showCancelButton: true, confirmButtonText: 'Quitar', cancelButtonText: 'Cancelar', confirmButtonColor: '#dc2626' })).isConfirmed) return;
            const fd = new FormData(); fd.append('accion', 'quitar'); fd.append('id', q.dataset.id); try { listo(await post(fd)); } catch (x) { Swal.fire('No se pudo', x.message, 'error'); } }
    });
    for (const [fid, mid] of [['fEditar', 'mEditar'], ['fPagar', 'mPagar']]) document.getElementById(fid)?.addEventListener('submit', async e => {
        e.preventDefault();
        try { const d = await post(new FormData(e.target)); bootstrap.Modal.getInstance(document.getElementById(mid))?.hide();
            if (fid === 'fPagar') {   // Pagada: ofrece el recibo para que lo firme quien recibe la comisión
                const id = e.target.id.value;
                const r = await Swal.fire({ icon: 'success', title: d.message, text: 'Imprime el recibo para que lo firme quien recibe la comisión.', showCancelButton: true, confirmButtonText: '<i class="bi bi-file-earmark-pdf"></i> Abrir recibo', cancelButtonText: 'Cerrar' });
                if (r.isConfirmed) window.open('comision_recibo_pdf?id=' + encodeURIComponent(id), '_blank');
                return cargar(location.pathname + location.search);
            }
            listo(d); } catch (x) { Swal.fire('No se pudo', x.message, 'error'); }
    });
    document.getElementById('mVendedores')?.addEventListener('click', async e => {
        const b = e.target.closest('.v-guardar'); if (!b) return;
        const tr = b.closest('tr'), fd = new FormData(); fd.append('accion', 'vendedor_guardar'); if (+tr.dataset.id) fd.append('id', tr.dataset.id);
        tr.querySelectorAll('input').forEach(i => { if (i.type === 'checkbox') fd.append(i.name, i.checked ? '1' : '0'); else fd.append(i.name, i.value); });
        try { await post(fd); Swal.fire({ icon: 'success', title: 'Vendedor guardado', timer: 1000, showConfirmButton: false }).then(() => location.reload()); } catch (x) { Swal.fire('No se pudo', x.message, 'error'); }
    });
})();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
