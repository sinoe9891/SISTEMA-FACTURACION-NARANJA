<?php
// Cotizaciones → Nueva / Editar: cliente y productos como en la factura, con alta rápida de cliente y de producto.
$titulo = 'Cotización';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/cotizaciones.php';

$cid = (int)cliente_actual();
if (!cotizacionesDisponible($pdo)) { header('Location: cotizaciones'); exit; }
$id = (int)($_GET['id'] ?? 0);
$cfg = cotizacionConfig($pdo, $cid);
$puedeEditar = in_array(USUARIO_ROL, ['admin', 'superadmin', 'facturador'], true);
$cot = null;
$items = [];
if ($id) {
    try {
        $cot = cotizacionObtener($pdo, $cid, $id);
        $items = cotizacionItems($pdo, $id);
    } catch (Throwable $e) {
        header('Location: cotizaciones');
        exit;
    }
}
$soloLectura = !$puedeEditar || ($cot && $cot['estado'] === 'facturada');
$hoy = date('Y-m-d');
$cotForm = [
    'receptor_id' => (int)($cot['receptor_id'] ?? ($_GET['receptor_id'] ?? 0)),
    'fecha' => $cot['fecha'] ?? $hoy,
    'valida_hasta' => $cot['valida_hasta'] ?? date('Y-m-d', strtotime('+' . $cfg['vigencia_dias'] . ' days')),
    'servicio' => $cot['servicio'] ?? '',
    'incluye' => $cot ? (string)$cot['incluye'] : $cfg['incluye'],
    'condiciones' => $cot ? (string)$cot['condiciones'] : $cfg['condiciones'],
    'notas' => $cot['notas'] ?? '',
];
$categorias = cotizacionCategorias($pdo, $cid);
$cotForm['categoria_id'] = (int)($cot['categoria_id'] ?? 0);
$st = $pdo->prepare("SELECT id, nombre, email FROM clientes_factura WHERE cliente_id = ? ORDER BY nombre");
$st->execute([$cid]);
$clientes = $st->fetchAll(PDO::FETCH_ASSOC);
$titulo = $cot ? 'Cotización ' . $cot['numero'] : 'Nueva cotización';
require_once '../../includes/templates/header.php';
?>

<div class="app-page-header">
    <div>
        <h1 class="app-page-title"><i class="bi bi-file-earmark-text me-2"></i><?= htmlspecialchars($titulo) ?></h1>
        <p class="app-page-sub">Elige el cliente y los productos como en una factura. Si el cliente o el producto no existe, créalo aquí mismo.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="cotizaciones" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Cotizaciones</a>
        <?php if ($cot): ?><a href="cotizacion_pdf?id=<?= $id ?>" target="_blank" class="btn btn-outline-danger"><i class="bi bi-file-earmark-pdf me-1"></i> Ver PDF</a><?php endif; ?>
    </div>
</div>

<?php if ($cot && $cot['estado'] === 'facturada'): ?>
    <div class="alert alert-info"><i class="bi bi-receipt me-1"></i> Esta cotización ya se convirtió en la factura
        <a href="ver_factura?id=<?= (int)$cot['factura_id'] ?>" target="_blank" class="fw-semibold"><?= htmlspecialchars((string)$cot['factura_correlativo']) ?></a>: ya no se edita.</div>
<?php endif; ?>

<form id="fCot" novalidate>
    <input type="hidden" name="accion" value="guardar">
    <input type="hidden" name="id" value="<?= $id ?>">
    <fieldset <?= $soloLectura ? 'disabled' : '' ?>>
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="app-card mb-3">
                <div class="app-card-header"><span><i class="bi bi-person me-1"></i> Cliente</span>
                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#mCliente"><i class="bi bi-person-plus me-1"></i> Nuevo cliente</button></div>
                <div class="app-card-body row g-3">
                    <?php if ($categorias): ?>
                    <div class="col-12"><label class="form-label">Categoría <span class="text-muted small">(carga su «Incluye», sus «Condiciones» y su vigencia)</span></label>
                        <div class="d-flex flex-wrap gap-2" id="cCategorias">
                            <input type="hidden" name="categoria_id" id="cCategoria" value="<?= $cotForm['categoria_id'] ?: '' ?>">
                            <button type="button" class="btn btn-sm <?= !$cotForm['categoria_id'] ? 'btn-dark' : 'btn-outline-secondary' ?> c-cat" data-id="">General</button>
                            <?php foreach ($categorias as $k): ?>
                                <button type="button" class="btn btn-sm <?= $cotForm['categoria_id'] === (int)$k['id'] ? 'btn-dark' : 'btn-outline-secondary' ?> c-cat" data-id="<?= (int)$k['id'] ?>"><i class="bi <?= htmlspecialchars($k['icono'] ?: 'bi-tag') ?> me-1"></i><?= htmlspecialchars($k['nombre']) ?></button>
                            <?php endforeach; ?>
                        </div></div>
                    <?php endif; ?>
                    <div class="col-12"><label class="form-label">Cliente *</label>
                        <select name="receptor_id" id="cReceptor" class="form-select" required data-buscar>
                            <option value="">— Seleccione un cliente —</option>
                            <?php foreach ($clientes as $cl): ?><option value="<?= (int)$cl['id'] ?>" <?= (int)$cl['id'] === $cotForm['receptor_id'] ? 'selected' : '' ?>><?= htmlspecialchars($cl['nombre']) ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="col-md-4"><label class="form-label">Fecha *</label><input type="date" name="fecha" id="cFecha" class="form-control" value="<?= $cotForm['fecha'] ?>" required></div>
                    <div class="col-md-4"><label class="form-label">Válida hasta *</label><input type="date" name="valida_hasta" id="cValida" class="form-control" value="<?= $cotForm['valida_hasta'] ?>" required>
                        <div class="form-text" id="cDias"></div></div>
                    <div class="col-md-4"><label class="form-label">Vigencia rápida</label>
                        <select class="form-select" id="cVigencia"><option value="">— Elegir —</option><?php foreach ([7, 15, 30, 45, 60] as $d): ?><option value="<?= $d ?>"><?= $d ?> días</option><?php endforeach; ?></select></div>
                    <div class="col-12"><label class="form-label">Servicio <span class="text-muted small">(resumen que sale en el cuadro del cliente)</span></label>
                        <input name="servicio" class="form-control" maxlength="255" value="<?= htmlspecialchars($cotForm['servicio']) ?>" placeholder="Ej.: Impresión de tarjetas de presentación"></div>
                </div>
            </div>

            <div class="app-card mb-3">
                <div class="app-card-header"><span><i class="bi bi-cart3 me-1"></i> Productos y servicios</span>
                    <span class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-primary" id="cNuevoProd"><i class="bi bi-box-seam me-1"></i> Nuevo producto</button>
                        <button type="button" class="btn btn-sm btn-primary" id="cAgregar"><i class="bi bi-plus-lg me-1"></i> Agregar línea</button>
                    </span></div>
                <div class="app-card-body" id="cLineas"></div>
            </div>

            <div class="app-card mb-3">
                <div class="app-card-header"><span><i class="bi bi-list-check me-1"></i> Incluye y condiciones</span><small class="text-muted fw-normal">El título (opcional) sale en negrita · {dias} = días de vigencia</small></div>
                <div class="app-card-body row g-3">
                    <div class="col-12"><label class="form-label fw-semibold">Incluye <span class="text-muted small fw-normal">(lo que recibe el cliente con este precio; si queda vacío, no sale en el PDF)</span></label>
                        <textarea name="incluye" id="cIncluye" class="form-control" rows="4"><?= htmlspecialchars($cotForm['incluye']) ?></textarea></div>
                    <div class="col-12"><label class="form-label fw-semibold">Condiciones <span class="text-muted small fw-normal">(forma de pago, entrega, cambios, vigencia…)</span></label>
                        <textarea name="condiciones" id="cCondiciones" class="form-control" rows="4"><?= htmlspecialchars($cotForm['condiciones']) ?></textarea></div>
                    <div class="col-12"><label class="form-label">Notas <span class="text-muted small">(opcional, salen al final)</span></label><textarea name="notas" class="form-control" rows="2"><?= htmlspecialchars($cotForm['notas']) ?></textarea></div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="app-card" style="position:sticky;top:calc(var(--app-topbar-h, 60px) + 1rem)">
                <?php if ($cot): $vistaEst = cotizacionEstadoVista($cot); [$eTxt, $eBg, $eFg, $eIco] = COTIZACION_ESTADOS[$vistaEst]; ?>
                <div class="app-card-header"><span><i class="bi bi-flag me-1"></i> Estado</span>
                    <span class="badge" id="cEstadoBadge" style="background:<?= $eBg ?>;color:<?= $eFg ?>"><i class="bi <?= $eIco ?> me-1"></i><?= $eTxt ?></span></div>
                <div class="app-card-body border-bottom">
                    <?php if ($cot['estado'] === 'facturada'): ?>
                        <div class="small text-muted">Ya se facturó: el estado no cambia.</div>
                    <?php elseif ($puedeEditar): ?>
                        <select class="form-select form-select-sm" id="cEstado" data-actual="<?= $cot['estado'] ?>">
                            <?php foreach (['borrador', 'enviada', 'aceptada', 'rechazada'] as $k): ?><option value="<?= $k ?>" <?= $cot['estado'] === $k ? 'selected' : '' ?>><?= COTIZACION_ESTADOS[$k][0] ?></option><?php endforeach; ?>
                        </select>
                        <div class="small text-muted mt-1" id="cEnviadaInfo"><?= $cot['enviada_en'] ? '<i class="bi bi-envelope-check me-1"></i>Enviada el ' . date('d/m/Y', strtotime($cot['enviada_en'])) . ($cot['enviada_a'] ? ' · ' . htmlspecialchars($cot['enviada_a']) : '') : '' ?></div>
                        <?php if ($vistaEst === 'vencida'): ?><div class="small text-danger mt-1">Venció el <?= date('d/m/Y', strtotime($cot['valida_hasta'])) ?> sin respuesta: cambia «Válida hasta» si la vas a mantener.</div><?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="app-card-header"><span><i class="bi bi-calculator me-1"></i> Totales</span></div>
                <div class="app-card-body">
                    <div class="d-flex justify-content-between py-1"><span class="text-muted">Subtotal</span><strong id="tSub">L 0.00</strong></div>
                    <div class="d-flex justify-content-between py-1"><span class="text-muted">ISV 15 %</span><strong id="tI15">L 0.00</strong></div>
                    <div class="d-flex justify-content-between py-1"><span class="text-muted">ISV 18 %</span><strong id="tI18">L 0.00</strong></div>
                    <div class="d-flex justify-content-between pt-2 mt-1 border-top fs-5"><span>Total</span><strong id="tTot" style="color:#e8590c">L 0.00</strong></div>
                </div>
                <?php if (!$soloLectura): ?>
                <div class="app-card-body border-top d-grid gap-2">
                    <button type="submit" class="btn btn-primary" data-luego="lista"><i class="bi bi-floppy me-1"></i> Guardar</button>
                    <button type="submit" class="btn btn-outline-danger" data-luego="pdf"><i class="bi bi-file-earmark-pdf me-1"></i> Guardar y ver PDF</button>
                    <button type="submit" class="btn btn-success" data-luego="enviar"><i class="bi bi-send me-1"></i> Guardar y enviar por correo</button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    </fieldset>
</form>

<!-- Alta rápida de cliente -->
<div class="modal fade" id="mCliente" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><form class="modal-content" id="fCliente" novalidate>
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-person-plus me-1"></i> Nuevo cliente</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body row g-2">
            <input type="hidden" name="accion" value="cliente_crear">
            <div class="col-12"><label class="form-label">Nombre o razón social *</label><input name="nombre" class="form-control" maxlength="100" required></div>
            <div class="col-md-6"><label class="form-label">RTN</label><input name="rtn" class="form-control" maxlength="25"></div>
            <div class="col-md-6"><label class="form-label">Atención (contacto)</label><input name="contacto_nombre" class="form-control" maxlength="150"></div>
            <div class="col-md-6"><label class="form-label">Correo</label><input type="email" name="email" class="form-control" maxlength="100"></div>
            <div class="col-md-6"><label class="form-label">Teléfono</label><input name="telefono" class="form-control" maxlength="50"></div>
            <div class="col-12"><label class="form-label">Dirección</label><input name="direccion" class="form-control"></div>
            <div class="col-12 form-text">Queda en Clientes con todos sus datos; para facturarle después necesitarás RTN y dirección.</div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary">Crear cliente</button></div>
    </form></div>
</div>

<!-- Alta rápida de producto -->
<div class="modal fade" id="mProducto" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><form class="modal-content" id="fProducto" novalidate>
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-box-seam me-1"></i> Nuevo producto o servicio</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body row g-2">
            <input type="hidden" name="accion" value="producto_crear"><input type="hidden" name="receptor_id">
            <div class="col-12"><label class="form-label">Nombre *</label><input name="nombre" class="form-control" maxlength="400" required placeholder="Ej.: Tarjetas de presentación a doble cara"></div>
            <div class="col-md-6"><label class="form-label">Precio unitario (L)</label><input type="number" name="precio" class="form-control" step="0.01" min="0" value="0"></div>
            <div class="col-md-6"><label class="form-label">ISV</label><select name="tipo_isv" class="form-select"><option value="15">15 %</option><option value="18">18 %</option><option value="0">Exento</option></select></div>
            <div class="col-12"><label class="form-label">Descripción</label><textarea name="descripcion" class="form-control" rows="2"></textarea></div>
            <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="para_todos" value="1" id="pTodos"><label class="form-check-label" for="pTodos">Disponible para todos los clientes (si no, solo para este cliente)</label></div></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary">Crear producto</button></div>
    </form></div>
</div>

<script src="../../clientes/js/lista-vinetas.js?v=<?= @filemtime(__DIR__ . '/../js/lista-vinetas.js') ?>"></script>
<script>
// Textos de cada categoría (y los generales para «General»)
const COT_CATEGORIAS = <?= json_encode(['' => ['incluye' => $cfg['incluye'], 'condiciones' => $cfg['condiciones'], 'vigencia_dias' => $cfg['vigencia_dias']]]
    + array_column(array_map(fn($k) => ['id' => (int)$k['id'], 'incluye' => (string)($k['incluye'] ?? '') ?: $cfg['incluye'], 'condiciones' => (string)($k['condiciones'] ?? '') ?: $cfg['condiciones'], 'vigencia_dias' => (int)($k['vigencia_dias'] ?: $cfg['vigencia_dias'])], $categorias), null, 'id'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
ListaVinetas(document.getElementById('cIncluye'), { placeholderTexto: 'Ej.: Una prueba digital para aprobación antes de imprimir.' });
ListaVinetas(document.getElementById('cCondiciones'), { placeholderTitulo: 'Ej.: Forma de pago', placeholderTexto: 'Ej.: 50 % al confirmar el pedido y 50 % contra entrega.', sugerencias: ['Forma de pago', 'Entrega', 'Cambios', 'Vigencia', 'Garantía', 'Soporte', 'Tiempo de entrega'] });
(() => {
    // Categoría: al elegirla se cargan sus textos (si ya se cambiaron a mano, primero se pregunta)
    const inc = document.getElementById('cIncluye'), con = document.getElementById('cCondiciones'), hid = document.getElementById('cCategoria');
    let tocado = <?= $cot ? 'true' : 'false' ?>;
    [inc, con].forEach(t => t.addEventListener('input', () => tocado = true));
    document.querySelectorAll('.c-cat').forEach(b => b.addEventListener('click', async () => {
        if (b.disabled) return;
        const k = COT_CATEGORIAS[b.dataset.id] || COT_CATEGORIAS[''];
        const distinto = inc.value.trim() !== (k.incluye || '').trim() || con.value.trim() !== (k.condiciones || '').trim();
        let cargar = true;
        if (tocado && distinto) {
            const r = await Swal.fire({ icon: 'question', title: '¿Cargar los textos de «' + b.textContent.trim() + '»?', text: 'Se reemplazan el Incluye y las Condiciones que tiene ahora la cotización.',
                showDenyButton: true, showCancelButton: true, confirmButtonText: 'Sí, cargarlos', denyButtonText: 'No, solo cambiar la categoría', cancelButtonText: 'Cancelar' });
            if (r.isDismissed) return;
            cargar = r.isConfirmed;
        }
        hid.value = b.dataset.id;
        document.querySelectorAll('.c-cat').forEach(x => { x.classList.toggle('btn-dark', x === b); x.classList.toggle('btn-outline-secondary', x !== b); });
        if (!cargar) return;
        inc.value = k.incluye || ''; con.value = k.condiciones || '';
        inc.dispatchEvent(new Event('vinetas:recargar')); con.dispatchEvent(new Event('vinetas:recargar'));
        tocado = false;
        if (!<?= $cot ? 'true' : 'false' ?> && k.vigencia_dias) {
            const fF = document.getElementById('cFecha'), fV = document.getElementById('cValida');
            const d = new Date(fF.value + 'T12:00:00'); d.setDate(d.getDate() + +k.vigencia_dias);
            fV.value = d.toISOString().slice(0, 10); fV.dispatchEvent(new Event('change'));
        }
    }));
})();
// Estado de la cotización: se guarda al elegirlo (Enviada pregunta el medio, como en la lista)
(() => {
    const sel = document.getElementById('cEstado');
    if (!sel) return;
    sel.addEventListener('change', async () => {
        const fd = new FormData();
        fd.append('accion', 'estado'); fd.append('id', '<?= $id ?>'); fd.append('estado', sel.value);
        if (sel.value === 'enviada') {
            const r = await Swal.fire({ title: 'Marcar como enviada', text: '¿Por qué medio se la enviaste? No se manda ningún correo.', input: 'select',
                inputOptions: { 'WhatsApp': 'WhatsApp', 'correo': 'Correo', 'en persona': 'En persona', 'mensaje de texto': 'Mensaje de texto', 'otro medio': 'Otro medio' }, inputValue: 'WhatsApp',
                showCancelButton: true, confirmButtonText: 'Guardar', cancelButtonText: 'Cancelar' });
            if (!r.isConfirmed) { sel.value = sel.dataset.actual; return; }
            fd.append('medio', r.value);
        }
        try {
            const d = await fetch('includes/cotizacion_accion.php', { method: 'POST', body: fd }).then(r => r.json());
            if (!d.success) throw new Error(d.error);
            Swal.fire({ icon: 'success', title: d.message, timer: 1200, showConfirmButton: false }).then(() => location.reload());
        } catch (err) { sel.value = sel.dataset.actual; Swal.fire('No se pudo', err.message, 'error'); }
    });
})();
(() => {
    const ITEMS = <?= json_encode(array_map(fn($i) => ['producto_id' => (int)$i['producto_id'], 'descripcion' => $i['descripcion'], 'detalle' => (string)$i['detalle'], 'cantidad' => (float)$i['cantidad'], 'precio' => (float)$i['precio']], $items), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    const ACC = 'includes/cotizacion_accion.php';
    const f = document.getElementById('fCot'), cont = document.getElementById('cLineas'), rec = document.getElementById('cReceptor');
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const L = n => 'L ' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    let productos = [], n = 0, lineaProducto = null;

    const opciones = sel => {
        const v = sel.value;
        sel.innerHTML = '<option value="">— Seleccionar producto —</option>' + productos.map(p => `<option value="${p.id}" data-precio="${p.precio}" data-isv="${p.tipo_isv}" data-nombre="${esc(p.nombre)}">${esc(p.nombre)}${+p.precio > 0 ? ' — L' + (+p.precio).toFixed(2) : ''}</option>`).join('');
        sel.value = v;
    };
    const cargarProductos = async () => {
        const r = rec.value ? await fetch(`../../includes/api/productos_por_receptor.php?receptor_id=${rec.value}`).then(r => r.json()).catch(() => []) : [];
        productos = Array.isArray(r) ? r : [];
        cont.querySelectorAll('.c-prod').forEach(opciones);
        calcular();
    };
    const linea = (d = {}) => {
        const i = n++, div = document.createElement('div');
        div.className = 'c-linea border rounded-3 p-2 mb-2';
        div.style.background = '#fafafa';
        div.innerHTML = `<div class="row g-2 align-items-end">
            <div class="col-md-5"><label class="form-label small mb-1">Producto o servicio *</label><select class="form-select form-select-sm c-prod" name="items[${i}][producto_id]" data-buscar></select></div>
            <div class="col-md-2"><label class="form-label small mb-1">Cantidad</label><input type="number" class="form-control form-control-sm c-cant" name="items[${i}][cantidad]" step="0.01" min="0.01" value="${d.cantidad ?? 1}"></div>
            <div class="col-md-3"><label class="form-label small mb-1">Precio unitario (L)</label><input type="number" class="form-control form-control-sm c-precio" name="items[${i}][precio]" step="0.01" min="0" value="${d.precio ?? ''}"></div>
            <div class="col-md-2 text-end"><div class="small text-muted">Total</div><strong class="c-tot">L 0.00</strong>
                <button type="button" class="btn btn-sm btn-link text-danger p-0 ms-2 c-quitar" title="Quitar línea"><i class="bi bi-trash3"></i></button></div>
            <div class="col-md-6"><input class="form-control form-control-sm c-desc" name="items[${i}][descripcion]" maxlength="400" placeholder="Descripción en la cotización (por defecto, el nombre del producto)" value="${esc(d.descripcion ?? '')}"></div>
            <div class="col-md-6"><input class="form-control form-control-sm" name="items[${i}][detalle]" placeholder="Detalle (opcional)" value="${esc(d.detalle ?? '')}"></div>
        </div>`;
        cont.appendChild(div);
        const sel = div.querySelector('.c-prod');
        opciones(sel);
        if (d.producto_id) {
            if (![...sel.options].some(o => o.value == d.producto_id)) sel.insertAdjacentHTML('beforeend', `<option value="${d.producto_id}" data-isv="15">${esc(d.descripcion)}</option>`);
            sel.value = String(d.producto_id);
        }
        calcular();
        return div;
    };
    const calcular = () => {
        let sub = 0, i15 = 0, i18 = 0;
        cont.querySelectorAll('.c-linea').forEach(l => {
            const t = (+l.querySelector('.c-cant').value || 0) * (+l.querySelector('.c-precio').value || 0);
            const isv = +(l.querySelector('.c-prod').selectedOptions[0]?.dataset.isv ?? 15);
            l.querySelector('.c-tot').textContent = L(t);
            sub += t; if (isv === 15) i15 += t * .15; else if (isv === 18) i18 += t * .18;
        });
        document.getElementById('tSub').textContent = L(sub);
        document.getElementById('tI15').textContent = L(i15);
        document.getElementById('tI18').textContent = L(i18);
        document.getElementById('tTot').textContent = L(sub + i15 + i18);
    };
    cont.addEventListener('input', calcular);
    cont.addEventListener('change', e => {
        if (!e.target.classList.contains('c-prod')) return;
        const o = e.target.selectedOptions[0], l = e.target.closest('.c-linea');
        if (o?.value) {
            if (+o.dataset.precio > 0) l.querySelector('.c-precio').value = (+o.dataset.precio).toFixed(2);
            const desc = l.querySelector('.c-desc');
            if (!desc.value.trim() || desc.dataset.auto === '1') { desc.value = o.dataset.nombre || o.text; desc.dataset.auto = '1'; }
        }
        calcular();
    });
    cont.addEventListener('click', e => {
        if (!e.target.closest('.c-quitar')) return;
        if (cont.querySelectorAll('.c-linea').length > 1) e.target.closest('.c-linea').remove(); else Swal.fire('Debe quedar al menos una línea', '', 'info');
        calcular();
    });
    document.getElementById('cAgregar').addEventListener('click', () => linea());
    rec.addEventListener('change', cargarProductos);

    // Vigencia
    const dias = () => {
        const a = new Date(f.fecha.value), b = new Date(f.valida_hasta.value), d = Math.round((b - a) / 864e5);
        document.getElementById('cDias').textContent = isNaN(d) ? '' : `${d} días de vigencia`;
        // «Vigencia rápida» muestra los días que tiene (si es una de sus opciones)
        const v = document.getElementById('cVigencia');
        v.value = [...v.options].some(o => o.value === String(d)) ? String(d) : '';
    };
    document.getElementById('cVigencia').addEventListener('change', e => {
        if (!e.target.value) return;
        const d = new Date(f.fecha.value + 'T12:00:00'); d.setDate(d.getDate() + +e.target.value);
        f.valida_hasta.value = d.toISOString().slice(0, 10); dias();
    });
    f.fecha.addEventListener('change', dias); f.valida_hasta.addEventListener('change', dias); dias();

    // Alta rápida de cliente
    const post = async fd => { const r = await fetch(ACC, { method: 'POST', body: fd }); const d = await r.json().catch(() => ({ success: false, error: 'Respuesta inesperada (' + r.status + ').' })); if (!d.success) throw new Error(d.error); return d; };
    const mCli = new bootstrap.Modal(document.getElementById('mCliente')), mProd = new bootstrap.Modal(document.getElementById('mProducto'));
    document.getElementById('fCliente').addEventListener('submit', async e => {
        e.preventDefault();
        const fc = e.target;
        if (!fc.nombre.value.trim()) return fc.nombre.focus();
        try {
            const d = await post(new FormData(fc));
            rec.insertAdjacentHTML('beforeend', `<option value="${d.cliente.id}">${esc(d.cliente.nombre)}</option>`);
            rec.value = String(d.cliente.id); rec.dispatchEvent(new Event('change'));
            fc.reset(); mCli.hide();
            Swal.fire({ icon: 'success', title: 'Cliente creado', timer: 1300, showConfirmButton: false });
        } catch (err) { Swal.fire('No se pudo', err.message, 'error'); }
    });
    // Alta rápida de producto (queda elegido en la última línea vacía, o en una nueva)
    document.getElementById('cNuevoProd').addEventListener('click', () => {
        if (!rec.value) return Swal.fire('Primero elige el cliente', 'El producto queda disponible para ese cliente (o para todos).', 'info');
        const fp = document.getElementById('fProducto'); fp.reset(); fp.receptor_id.value = rec.value; mProd.show();
    });
    document.getElementById('fProducto').addEventListener('submit', async e => {
        e.preventDefault();
        const fp = e.target;
        if (!fp.nombre.value.trim()) return fp.nombre.focus();
        try {
            const d = await post(new FormData(fp));
            productos.push(d.producto);
            cont.querySelectorAll('.c-prod').forEach(opciones);
            const libre = [...cont.querySelectorAll('.c-linea')].find(l => !l.querySelector('.c-prod').value) || linea();
            const sel = libre.querySelector('.c-prod'); sel.value = String(d.producto.id); sel.dispatchEvent(new Event('change', { bubbles: true }));
            mProd.hide();
            Swal.fire({ icon: 'success', title: 'Producto creado', timer: 1300, showConfirmButton: false });
        } catch (err) { Swal.fire('No se pudo', err.message, 'error'); }
    });

    // Guardar
    let luego = 'lista';
    f.querySelectorAll('[data-luego]').forEach(b => b.addEventListener('click', () => luego = b.dataset.luego));
    f.addEventListener('submit', async e => {
        e.preventDefault();
        if (!rec.value) return Swal.fire('Falta el cliente', 'Elige el cliente o créalo con «Nuevo cliente».', 'warning');
        const btns = f.querySelectorAll('[data-luego]'); btns.forEach(b => b.disabled = true);
        try {
            const d = await post(new FormData(f));
            if (luego === 'pdf') { window.open('cotizacion_pdf?id=' + d.id, '_blank'); location.href = 'cotizacion_editar?id=' + d.id; }
            else if (luego === 'enviar') location.href = 'cotizaciones?enviar=' + d.id;
            else location.href = 'cotizaciones?guardada=' + d.id;
        } catch (err) { Swal.fire('No se pudo guardar', err.message, 'error'); btns.forEach(b => b.disabled = false); }
    });

    (async () => {
        await cargarProductos();
        (ITEMS.length ? ITEMS : [{}]).forEach(linea);
        cont.querySelectorAll('.c-desc').forEach(d => { if (!d.value) d.dataset.auto = '1'; });
        calcular();
    })();
})();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
