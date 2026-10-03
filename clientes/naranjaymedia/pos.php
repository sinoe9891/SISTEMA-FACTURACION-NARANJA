<?php
$titulo = 'Punto de venta';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/pos.php';

$cid = cliente_actual();
$uid = (int)USUARIO_ID;
$eid = (int)($_SESSION['establecimiento_activo'] ?? 0);
$instalado = posDisponible($pdo);
$permitido = in_array(USUARIO_ROL, POS_ROLES_VENTA, true);
$esAdmin = in_array(USUARIO_ROL, ['admin', 'superadmin'], true);
$turno = ($instalado && $permitido) ? posTurnoDelUsuario($pdo, $cid, $uid) : null;
$cajas = ($instalado && $permitido && !$turno && $eid) ? posCajas($pdo, $cid, $eid) : [];

$catalogo = $receptores = [];
if ($turno) {
    $st = $pdo->prepare("
        SELECT p.id, p.nombre, p.precio, p.tipo_isv, p.tipo, p.sku, p.codigo_barras, p.unidad,
               COALESCE(x.cantidad - x.reservado, 0) AS disponible
        FROM productos_clientes p
        LEFT JOIN inv_existencias x ON x.producto_id = p.id AND x.establecimiento_id = ?
        WHERE p.cliente_id = ? AND p.receptores_id IS NULL AND p.activo = 1 AND p.precio > 0
        ORDER BY p.nombre
    ");
    $st->execute([(int)$turno['establecimiento_id'], $cid]);
    $catalogo = array_map(fn($p) => [
        'id' => (int)$p['id'], 'nombre' => $p['nombre'], 'precio' => (float)$p['precio'], 'isv' => (int)$p['tipo_isv'],
        'bien' => $p['tipo'] === 'bien', 'sku' => (string)$p['sku'], 'barras' => (string)$p['codigo_barras'],
        'unidad' => $p['unidad'], 'disp' => (float)$p['disponible'],
    ], $st->fetchAll(PDO::FETCH_ASSOC));
    $st = $pdo->prepare("SELECT id, nombre, rtn FROM clientes_factura WHERE cliente_id = ? ORDER BY nombre = 'CONSUMIDOR FINAL' DESC, nombre");
    $st->execute([$cid]);
    $receptores = $st->fetchAll(PDO::FETCH_ASSOC);
}

require_once '../../includes/templates/header.php';
?>

<?php if (!$instalado): ?>
    <div class="alert alert-warning">El punto de venta no está instalado. Ejecuta <code>sql/migraciones/2026-10-03_inventario.sql</code> y <code>2026-10-03_pos.sql</code>.</div>
<?php elseif (!$permitido): ?>
    <div class="alert alert-warning">Tu rol no tiene acceso al punto de venta.</div>
<?php elseif (!$turno): ?>
    <!-- ══════════ Apertura de caja ══════════ -->
    <div class="app-page-header"><div><h1 class="app-page-title">Punto de venta</h1><p class="app-page-sub">Abre una caja para empezar a vender.</p></div>
        <a href="pos_turnos" class="btn btn-outline-primary"><i class="bi bi-clock-history me-1"></i> Turnos</a></div>
    <div class="row justify-content-center">
        <div class="col-lg-6">
            <form class="app-card app-card-body" id="formAbrir" novalidate>
                <input type="hidden" name="accion" value="abrir">
                <h2 class="h5 mb-3"><i class="bi bi-unlock me-1"></i> Abrir caja</h2>
                <?php if (!$cajas): ?>
                    <div class="alert alert-warning mb-0">Este establecimiento no tiene cajas (puntos de emisión). Créalas en Empresas → Sucursales.</div>
                <?php else: ?>
                    <label class="form-label">Caja</label>
                    <div class="d-flex flex-column gap-2 mb-3">
                        <?php foreach ($cajas as $i => $c): $libre = !$c['turno_abierto'] && $c['cai_id']; ?>
                            <label class="app-select-item <?= $libre ? '' : 'opacity-50' ?>" style="cursor:<?= $libre ? 'pointer' : 'not-allowed' ?>">
                                <input type="radio" class="form-check-input me-1" name="punto_emision_id" value="<?= (int)$c['id'] ?>" <?= $libre ? '' : 'disabled' ?> <?= $libre && !isset($marcada) ? 'checked' : '' ?><?php if ($libre) $marcada = true; ?>>
                                <span class="app-select-text"><strong>Caja <?= htmlspecialchars($c['codigo_punto']) ?><?= $c['descripcion'] ? ' · ' . htmlspecialchars($c['descripcion']) : '' ?></strong>
                                    <small><?= $c['turno_abierto'] ? 'Abierta por ' . htmlspecialchars($c['cajero']) : ($c['cai_id'] ? 'Disponible' : 'Sin CAI vigente') ?></small></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <label class="form-label" for="montoInicial">Fondo inicial en efectivo</label>
                    <div class="input-group mb-3"><span class="input-group-text">L</span><input type="number" step="0.01" min="0" class="form-control" id="montoInicial" name="monto_inicial" value="0" required></div>
                    <button class="btn btn-primary w-100" type="submit" <?= isset($marcada) ? '' : 'disabled' ?>><i class="bi bi-cash-stack me-1"></i> Abrir caja</button>
                <?php endif; ?>
            </form>
        </div>
    </div>
<?php else: ?>
    <!-- ══════════ Venta ══════════ -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div class="small text-muted"><i class="bi bi-shop"></i> <?= htmlspecialchars($turno['tienda']) ?> · <strong>Caja <?= htmlspecialchars($turno['codigo_punto']) ?></strong> · abierta <?= date('d/m H:i', strtotime($turno['abierto_en'])) ?></div>
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-sm btn-outline-secondary" id="btnMovimiento"><i class="bi bi-arrow-down-up"></i> Entrada / retiro</button>
            <button class="btn btn-sm btn-outline-secondary" id="btnCorteX"><i class="bi bi-receipt"></i> Corte X</button>
            <button class="btn btn-sm btn-outline-danger" id="btnCerrar"><i class="bi bi-lock"></i> Cerrar caja</button>
        </div>
    </div>

    <div class="row g-3 pos-layout">
        <div class="col-lg-7">
            <div class="app-card app-card-body">
                <div class="input-group mb-3">
                    <span class="input-group-text"><i class="bi bi-upc-scan"></i></span>
                    <input type="search" class="form-control form-control-lg" id="buscar" placeholder="Buscar o escanear código… (F2)" autocomplete="off" autofocus>
                </div>
                <div class="pos-grid" id="catalogo"></div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="app-card pos-carrito">
                <div class="app-card-header"><span><i class="bi bi-cart3 me-1"></i> Venta</span><button class="btn btn-sm btn-link text-danger p-0" id="btnVaciar">Vaciar</button></div>
                <div class="app-card-body py-2">
                    <label class="form-label small mb-1" for="receptor">Cliente</label>
                    <select class="form-select form-select-sm mb-2" id="receptor">
                        <option value="">CONSUMIDOR FINAL</option>
                        <?php foreach ($receptores as $r): if ($r['nombre'] === 'CONSUMIDOR FINAL') continue; ?><option value="<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['nombre'] . ($r['rtn'] ? ' · ' . $r['rtn'] : '')) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="pos-lineas" id="lineas"></div>
                <div class="app-card-body border-top">
                    <div class="d-flex justify-content-between small"><span>Subtotal</span><span id="tSub">L 0.00</span></div>
                    <div class="d-flex justify-content-between small"><span>ISV 15 %</span><span id="tIsv15">L 0.00</span></div>
                    <div class="d-flex justify-content-between small" id="filaIsv18"><span>ISV 18 %</span><span id="tIsv18">L 0.00</span></div>
                    <div class="d-flex justify-content-between align-items-center mt-2"><span class="fw-bold">Total</span><span class="pos-total" id="tTotal">L 0.00</span></div>
                    <button class="btn btn-success btn-lg w-100 mt-3" id="btnCobrar" disabled><i class="bi bi-cash-coin me-1"></i> Cobrar (F10)</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de cobro -->
    <div class="modal fade" id="modalCobro" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
            <form class="modal-content" id="formCobro" novalidate>
                <div class="modal-header"><h5 class="modal-title">Cobrar</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
                <div class="modal-body">
                    <div class="text-center mb-3"><div class="text-muted small">Total a pagar</div><div class="pos-total-grande" id="cTotal"></div></div>
                    <label class="form-label">Efectivo recibido</label>
                    <div class="input-group input-group-lg mb-2"><span class="input-group-text">L</span><input type="number" step="0.01" min="0" class="form-control" id="cEfectivo" inputmode="decimal"></div>
                    <div class="d-flex flex-wrap gap-2 mb-3" id="rapidos"></div>
                    <details class="mb-2" id="detTarjeta"><summary class="fw-semibold small">Tarjeta</summary>
                        <div class="row g-2 mt-1"><div class="col-5"><input type="number" step="0.01" min="0" class="form-control" id="cTarjeta" placeholder="Monto"></div>
                            <div class="col-4"><input class="form-control" id="cAutorizacion" placeholder="Autorización" maxlength="100"></div>
                            <div class="col-3"><input class="form-control" id="cUltimos4" placeholder="Últ. 4" maxlength="4" inputmode="numeric"></div></div></details>
                    <details class="mb-2" id="detTransf"><summary class="fw-semibold small">Transferencia</summary>
                        <div class="row g-2 mt-1"><div class="col-5"><input type="number" step="0.01" min="0" class="form-control" id="cTransf" placeholder="Monto"></div>
                            <div class="col-7"><input class="form-control" id="cTransfRef" placeholder="Referencia" maxlength="100"></div></div></details>
                    <div class="pos-resumen-cobro mt-3"><div><span>Pendiente</span><strong id="cPendiente"></strong></div><div><span>Cambio</span><strong id="cCambio" class="text-success"></strong></div></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-success btn-lg" type="submit" id="btnConfirmar">Confirmar venta</button></div>
            </form>
        </div>
    </div>
<?php endif; ?>

<style>
    .pos-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; max-height: calc(100vh - 260px); overflow-y: auto; }
    .pos-prod { border: 1px solid var(--app-border); border-radius: var(--app-radius-sm); padding: 10px; text-align: left; background: var(--app-surface); min-height: 96px; display: flex; flex-direction: column; justify-content: space-between; }
    .pos-prod:hover, .pos-prod:focus-visible { border-color: var(--app-accent); box-shadow: 0 0 0 3px var(--app-accent-lt); outline: none; }
    .pos-prod[disabled] { opacity: .45; }
    .pos-prod .n { font-size: 13px; font-weight: 600; line-height: 1.25; }
    .pos-prod .p { font-size: 14px; font-weight: 700; color: var(--app-accent); }
    .pos-lineas { max-height: calc(100vh - 480px); min-height: 120px; overflow-y: auto; }
    .pos-linea { display: grid; grid-template-columns: 1fr auto auto auto; gap: 8px; align-items: center; padding: 8px 16px; border-bottom: 1px solid var(--app-border); }
    .pos-linea input { width: 70px; }
    .pos-total { font-size: 26px; font-weight: 800; font-variant-numeric: tabular-nums; }
    .pos-total-grande { font-size: 38px; font-weight: 800; font-variant-numeric: tabular-nums; }
    .pos-resumen-cobro { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .pos-resumen-cobro div { background: #f8fafc; border: 1px solid var(--app-border); border-radius: var(--app-radius-sm); padding: 8px 12px; display: flex; flex-direction: column; }
    .pos-resumen-cobro strong { font-size: 20px; font-variant-numeric: tabular-nums; }
    @media (max-width: 991.98px) { .pos-grid { max-height: 45vh; } .pos-lineas { max-height: none; } }
</style>

<script src="../../clientes/js/app-acciones.js?v=<?= @filemtime(__DIR__ . '/../js/app-acciones.js') ?>"></script>
<script>
(function () {
    const A = AppAcciones('includes/pos_accion.php');
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const L = v => 'L ' + Number(v || 0).toLocaleString('es-HN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const r2 = v => Math.round((v + Number.EPSILON) * 100) / 100;

    const fAbrir = document.getElementById('formAbrir');
    if (fAbrir) { A.formulario(fAbrir); return; }
    if (!document.getElementById('catalogo')) return;

    const TURNO = <?= (int)($turno['id'] ?? 0) ?>;
    const ES_ADMIN = <?= $esAdmin ? 'true' : 'false' ?>;
    const productos = <?= json_encode($catalogo, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const porId = Object.fromEntries(productos.map(p => [p.id, p]));
    const CLAVE = 'pos_carrito_' + TURNO;
    const nuevoId = () => [...crypto.getRandomValues(new Uint8Array(16))].map(b => b.toString(16).padStart(2, '0')).join('');

    // Estado del carrito (se guarda en el navegador para no perder la venta al recargar)
    let estado = { items: [], idem: nuevoId() };
    try { const g = JSON.parse(localStorage.getItem(CLAVE)); if (g && Array.isArray(g.items)) estado = g; } catch (e) {}
    const guardar = () => { try { localStorage.setItem(CLAVE, JSON.stringify(estado)); } catch (e) {} };

    // ── Catálogo ───────────────────────────────────────────────────────────────
    function pintarCatalogo(q) {
        q = (q || '').trim().toLowerCase();
        const lista = q ? productos.filter(p => (p.nombre + ' ' + p.sku + ' ' + p.barras).toLowerCase().includes(q)) : productos;
        document.getElementById('catalogo').innerHTML = lista.slice(0, 120).map(p => `
            <button type="button" class="pos-prod" data-id="${p.id}" ${p.bien && p.disp <= 0 ? 'disabled title="Sin existencia"' : ''}>
                <span class="n">${esc(p.nombre)}</span>
                <span class="d-flex justify-content-between align-items-end mt-1"><span class="p">${L(p.precio)}</span>
                ${p.bien ? `<span class="app-badge ${p.disp <= 0 ? 'app-badge-danger' : 'app-badge-muted'}">${p.disp}</span>` : ''}</span>
            </button>`).join('') || '<div class="text-muted small p-3">Sin resultados.</div>';
    }
    document.getElementById('catalogo').addEventListener('click', e => { const b = e.target.closest('.pos-prod'); if (b) agregar(+b.dataset.id); });
    const buscar = document.getElementById('buscar');
    buscar.addEventListener('input', () => pintarCatalogo(buscar.value));
    // Lector de código de barras: escribe el código y envía Enter
    buscar.addEventListener('keydown', e => {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const q = buscar.value.trim().toLowerCase();
        if (!q) return;
        const exacto = productos.find(p => p.barras.toLowerCase() === q || p.sku.toLowerCase() === q);
        const filtrados = productos.filter(p => (p.nombre + ' ' + p.sku + ' ' + p.barras).toLowerCase().includes(q));
        const p = exacto || (filtrados.length === 1 ? filtrados[0] : null);
        if (p) { agregar(p.id); buscar.value = ''; pintarCatalogo(''); }
        else if (!filtrados.length) Swal.fire({ icon: 'warning', title: 'Código no encontrado', text: buscar.value, timer: 1500, showConfirmButton: false });
    });

    // ── Carrito ────────────────────────────────────────────────────────────────
    function agregar(id, cant = 1) {
        const p = porId[id]; if (!p) return;
        const l = estado.items.find(i => i.id === id);
        const nueva = (l ? l.cantidad : 0) + cant;
        if (p.bien && nueva > p.disp) { Swal.fire({ icon: 'warning', title: 'Existencia insuficiente', text: `Solo hay ${p.disp} de ${p.nombre}.`, timer: 1800, showConfirmButton: false }); return; }
        if (l) l.cantidad = nueva; else estado.items.push({ id, cantidad: cant });
        pintarCarrito();
    }
    function totales() {
        let sub = 0, i15 = 0, i18 = 0;
        estado.items.forEach(i => { const p = porId[i.id]; if (!p) return; const s = r2(i.cantidad * p.precio); sub += s; if (p.isv === 15) i15 += s * .15; if (p.isv === 18) i18 += s * .18; });
        sub = r2(sub); i15 = r2(i15); i18 = r2(i18);
        return { sub, i15, i18, total: r2(sub + i15 + i18) };
    }
    function pintarCarrito() {
        estado.items = estado.items.filter(i => porId[i.id] && i.cantidad > 0);
        document.getElementById('lineas').innerHTML = estado.items.length ? estado.items.map(i => { const p = porId[i.id]; return `
            <div class="pos-linea" data-id="${i.id}">
                <div><div class="small fw-semibold">${esc(p.nombre)}</div><div class="small text-muted">${L(p.precio)} c/u</div></div>
                <input type="number" class="form-control form-control-sm pos-cant" min="0.001" step="${p.bien ? 1 : 0.001}" value="${i.cantidad}" aria-label="Cantidad">
                <div class="small fw-semibold text-end" style="min-width:80px">${L(r2(i.cantidad * p.precio))}</div>
                <button class="btn btn-sm btn-link text-danger p-0 pos-quitar" aria-label="Quitar"><i class="bi bi-x-lg"></i></button>
            </div>`; }).join('') : '<div class="text-center text-muted small py-4">Agrega productos tocándolos o escaneando su código.</div>';
        const t = totales();
        document.getElementById('tSub').textContent = L(t.sub);
        document.getElementById('tIsv15').textContent = L(t.i15);
        document.getElementById('tIsv18').textContent = L(t.i18);
        document.getElementById('filaIsv18').classList.toggle('d-none', !t.i18);
        document.getElementById('tTotal').textContent = L(t.total);
        document.getElementById('btnCobrar').disabled = !estado.items.length;
        guardar();
    }
    document.getElementById('lineas').addEventListener('change', e => {
        if (!e.target.classList.contains('pos-cant')) return;
        const id = +e.target.closest('.pos-linea').dataset.id, p = porId[id], v = parseFloat(e.target.value) || 0;
        const l = estado.items.find(i => i.id === id);
        if (p.bien && v > p.disp) { Swal.fire('Existencia insuficiente', `Solo hay ${p.disp}.`, 'warning'); e.target.value = l.cantidad; return; }
        l.cantidad = v; pintarCarrito();
    });
    document.getElementById('lineas').addEventListener('click', e => {
        if (!e.target.closest('.pos-quitar')) return;
        const id = +e.target.closest('.pos-linea').dataset.id;
        estado.items = estado.items.filter(i => i.id !== id); pintarCarrito();
    });
    document.getElementById('btnVaciar').addEventListener('click', () => {
        if (!estado.items.length) return;
        Swal.fire({ title: '¿Vaciar la venta?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Vaciar', cancelButtonText: 'No' })
            .then(r => { if (r.isConfirmed) { estado = { items: [], idem: nuevoId() }; pintarCarrito(); } });
    });

    // ── Cobro ─────────────────────────────────────────────────────────────────
    const fC = document.getElementById('formCobro');
    const num = id => parseFloat(document.getElementById(id).value) || 0;
    function calcularCobro() {
        const total = totales().total, otros = r2(num('cTarjeta') + num('cTransf'));
        const resto = r2(Math.max(total - otros, 0)), ef = num('cEfectivo');
        document.getElementById('cPendiente').textContent = L(Math.max(r2(resto - ef), 0));
        document.getElementById('cCambio').textContent = L(resto > 0 ? Math.max(r2(ef - resto), 0) : 0);
        return { total, otros, resto, ef };
    }
    ['cEfectivo', 'cTarjeta', 'cTransf'].forEach(id => document.getElementById(id).addEventListener('input', calcularCobro));
    function abrirCobro() {
        if (!estado.items.length) return;
        fC.reset();
        const t = totales().total;
        document.getElementById('cTotal').textContent = L(t);
        document.getElementById('rapidos').innerHTML = [['Exacto', t], ...[100, 200, 500, 1000].filter(v => v > t).slice(0, 3).map(v => ['L ' + v, v])]
            .map(([e, v]) => `<button type="button" class="btn btn-outline-secondary" data-v="${v}">${e}</button>`).join('');
        calcularCobro();
        A.modal('modalCobro').show();
        setTimeout(() => document.getElementById('cEfectivo').focus(), 300);
    }
    document.getElementById('rapidos').addEventListener('click', e => { const b = e.target.closest('[data-v]'); if (b) { document.getElementById('cEfectivo').value = b.dataset.v; calcularCobro(); } });
    document.getElementById('btnCobrar').addEventListener('click', abrirCobro);
    fC.addEventListener('submit', ev => {
        ev.preventDefault();
        const c = calcularCobro();
        if (c.otros > c.total + 0.004) return Swal.fire('Revisa los montos', 'Tarjeta y transferencia superan el total.', 'warning');
        if (c.ef + 0.004 < c.resto) return Swal.fire('Falta dinero', 'Pendiente: ' + L(c.resto - c.ef), 'warning');
        if (num('cTarjeta') > 0 && !document.getElementById('cAutorizacion').value.trim()) return Swal.fire('Falta la autorización', 'Escribe el número de autorización del voucher.', 'warning');
        const fd = new FormData();
        fd.append('accion', 'vender'); fd.append('idempotencia', estado.idem);
        fd.append('receptor_id', document.getElementById('receptor').value);
        fd.append('efectivo_recibido', c.ef);
        estado.items.forEach((i, n) => { fd.append(`items[${n}][id]`, i.id); fd.append(`items[${n}][cantidad]`, i.cantidad); });
        if (num('cTarjeta') > 0) { fd.append('pagos[0][forma]', 'tarjeta'); fd.append('pagos[0][monto]', num('cTarjeta')); fd.append('pagos[0][referencia]', document.getElementById('cAutorizacion').value); fd.append('pagos[0][ultimos4]', document.getElementById('cUltimos4').value); }
        if (num('cTransf') > 0) { fd.append('pagos[1][forma]', 'transferencia'); fd.append('pagos[1][monto]', num('cTransf')); fd.append('pagos[1][referencia]', document.getElementById('cTransfRef').value); }
        const btn = document.getElementById('btnConfirmar'); btn.disabled = true;
        A.accion(fd, { sinRecargar: true }).then(d => {
            A.modal('modalCobro').hide();
            // Venta lista: actualizar existencias locales y empezar una venta nueva
            estado.items.forEach(i => { const p = porId[i.id]; if (p && p.bien) p.disp = r2(p.disp - i.cantidad); });
            estado = { items: [], idem: nuevoId() }; pintarCarrito(); pintarCatalogo(buscar.value);
            return Swal.fire({
                icon: 'success', title: 'Venta ' + esc(d.correlativo),
                html: `<div class="fs-5">Total ${L(d.total)}</div>${d.cambio > 0 ? `<div class="display-6 fw-bold text-success mt-2">Cambio: ${L(d.cambio)}</div>` : ''}`,
                showCancelButton: true, confirmButtonText: '<i class="bi bi-printer"></i> Imprimir factura', cancelButtonText: 'Nueva venta'
            }).then(r => { if (r.isConfirmed) window.open('ver_factura?id=' + d.factura_id, '_blank'); buscar.focus(); });
        }).catch(() => {}).finally(() => btn.disabled = false);   // si falló la red, se reintenta con el mismo identificador
    });

    // ── Caja: movimientos, corte X, cierre ─────────────────────────────────────
    document.getElementById('btnMovimiento').addEventListener('click', () => {
        Swal.fire({
            title: 'Movimiento de efectivo',
            html: `<div class="text-start"><select id="mTipo" class="form-select mb-2"><option value="retiro">Retiro (sale efectivo)</option><option value="entrada">Entrada (ingresa efectivo)</option></select>
                <input id="mMonto" type="number" step="0.01" min="0.01" class="form-control mb-2" placeholder="Monto">
                <input id="mMotivo" class="form-control mb-2" placeholder="Motivo">
                ${ES_ADMIN ? '' : '<div class="small text-muted mb-1">Autorización de un administrador:</div><input id="mUsr" class="form-control mb-2" placeholder="Correo del administrador" autocomplete="off"><input id="mClave" type="password" class="form-control" placeholder="Contraseña" autocomplete="off">'}</div>`,
            showCancelButton: true, confirmButtonText: 'Registrar', cancelButtonText: 'Cancelar', focusConfirm: false,
            preConfirm: () => {
                const d = { accion: 'movimiento', tipo: mTipo.value, monto: mMonto.value, motivo: mMotivo.value };
                if (!ES_ADMIN) { d.usuario_autoriza = document.getElementById('mUsr').value; d.clave_autoriza = document.getElementById('mClave').value; }
                return A.accion(d, { sinRecargar: true }).catch(e => Swal.showValidationMessage(e.message));
            }
        }).then(r => { if (r.isConfirmed && r.value) Swal.fire({ icon: 'success', title: r.value.message, timer: 1400, showConfirmButton: false }); });
    });
    document.getElementById('btnCorteX').addEventListener('click', () => {
        fetch('includes/pos_accion.php?resumen=' + TURNO).then(r => r.json()).then(d => {
            if (!d.success) throw new Error(d.error);
            Swal.fire({ title: 'Corte X (parcial)', html: `<table class="table table-sm text-start">
                <tr><td>Fondo inicial</td><td class="text-end">${L(d.turno.monto_inicial)}</td></tr>
                <tr><td>Ventas (${d.ventas})</td><td class="text-end">${L(d.total_ventas)}</td></tr>
                <tr><td>· Efectivo</td><td class="text-end">${L(d.por_forma.efectivo)}</td></tr>
                <tr><td>· Tarjeta</td><td class="text-end">${L(d.por_forma.tarjeta)}</td></tr>
                <tr><td>· Transferencia</td><td class="text-end">${L(d.por_forma.transferencia)}</td></tr>
                <tr><td>Entradas / retiros</td><td class="text-end">${L(d.entradas)} / ${L(d.retiros)}</td></tr>
                <tr><td>Anuladas (${d.anuladas})</td><td class="text-end">${L(d.total_anuladas)}</td></tr>
                ${d.efectivo_esperado !== undefined ? `<tr class="fw-bold"><td>Efectivo esperado</td><td class="text-end">${L(d.efectivo_esperado)}</td></tr>` : ''}</table>` });
        }).catch(e => Swal.fire('Error', e.message, 'error'));
    });
    document.getElementById('btnCerrar').addEventListener('click', () => {
        if (estado.items.length) return Swal.fire('Venta en curso', 'Cobra o vacía la venta antes de cerrar la caja.', 'warning');
        const cerrar = just => Swal.fire({
            title: 'Cerrar caja (Corte Z)', icon: 'question',
            html: `<p class="small text-muted">Cuenta el efectivo de la caja y escribe el total. El sistema calcula la diferencia.</p>
                   <input id="zContado" type="number" step="0.01" min="0" class="form-control mb-2" placeholder="Efectivo contado (L)">
                   ${just ? '<textarea id="zJust" class="form-control" placeholder="Justificación de la diferencia" rows="2"></textarea>' : ''}`,
            showCancelButton: true, confirmButtonText: 'Cerrar caja', confirmButtonColor: '#dc2626', cancelButtonText: 'Cancelar', focusConfirm: false,
            preConfirm: () => {
                const v = document.getElementById('zContado').value;
                if (v === '') return Swal.showValidationMessage('Escribe el efectivo contado');
                return fetch('includes/pos_accion.php', { method: 'POST', body: Object.entries({ accion: 'cerrar', turno_id: TURNO, efectivo_contado: v, justificacion: just ? document.getElementById('zJust').value : '' }).reduce((f, [k, x]) => (f.append(k, x), f), new FormData()) })
                    .then(r => r.json());
            }
        }).then(r => {
            if (!r.isConfirmed) return;
            const d = r.value;
            if (!d.success && /justificación/.test(d.error)) return Swal.fire('Diferencia en caja', d.error, 'warning').then(() => cerrar(true));
            if (!d.success) return Swal.fire('No se pudo cerrar', d.error, 'error');
            try { localStorage.removeItem(CLAVE); } catch (e) {}
            Swal.fire({ icon: 'success', title: d.message, html: `Esperado ${L(d.esperado)} · Contado ${L(d.contado)}` }).then(() => location.href = 'pos_turnos');
        });
        cerrar(false);
    });

    // Atajos de teclado
    document.addEventListener('keydown', e => {
        if (e.key === 'F2') { e.preventDefault(); buscar.focus(); buscar.select(); }
        if (e.key === 'F10') { e.preventDefault(); abrirCobro(); }
    });
    pintarCatalogo(''); pintarCarrito();
})();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
