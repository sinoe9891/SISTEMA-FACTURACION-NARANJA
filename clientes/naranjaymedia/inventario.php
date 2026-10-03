<?php
$titulo = 'Inventario';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/inventario.php';

$cid = cliente_actual();
$instalado = invDisponible($pdo);
$puedeEditar = in_array(USUARIO_ROL, ['admin', 'superadmin'], true);

$tiendas = $productos = $servicios = [];
$existencias = [];
$kpi = ['productos' => 0, 'valor' => 0.0, 'bajo' => 0, 'agotados' => 0, 'transito' => 0];
$tiendaFiltro = (int)($_GET['tienda'] ?? 0);
$soloBajo = !empty($_GET['bajo']);

if ($instalado && $cid) {
    $st = $pdo->prepare("SELECT establecimiento_id AS id, nombre, codigo_establecimiento AS codigo FROM establecimientos WHERE cliente_id = ? ORDER BY codigo_establecimiento, nombre");
    $st->execute([$cid]);
    $tiendas = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare("SELECT * FROM productos_clientes WHERE cliente_id = ? AND tipo = 'bien' ORDER BY activo DESC, nombre");
    $st->execute([$cid]);
    $productos = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare("SELECT producto_id, establecimiento_id, cantidad, reservado FROM inv_existencias WHERE cliente_id = ?");
    $st->execute([$cid]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) $existencias[$e['producto_id']][$e['establecimiento_id']] = $e;

    $st = $pdo->prepare("SELECT id, nombre FROM productos_clientes WHERE cliente_id = ? AND tipo = 'servicio' AND receptores_id IS NULL ORDER BY nombre");
    $st->execute([$cid]);
    $servicios = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare("SELECT COUNT(*) FROM inv_traslados WHERE cliente_id = ? AND estado = 'en_transito'");
    $st->execute([$cid]);
    $kpi['transito'] = (int)$st->fetchColumn();

    foreach ($productos as &$p) {
        $p['total'] = 0.0;
        $p['enTienda'] = 0.0;
        foreach ($tiendas as $t) {
            $c = (float)($existencias[$p['id']][$t['id']]['cantidad'] ?? 0);
            $p['total'] += $c;
            if (!$tiendaFiltro || $tiendaFiltro === (int)$t['id']) $p['enTienda'] += $c;
        }
        $p['estado'] = $p['enTienda'] <= 0 ? 'agotado' : ($p['enTienda'] <= (float)$p['stock_minimo'] ? 'bajo' : 'ok');
        if ((int)$p['activo']) {
            $kpi['productos']++;
            $kpi['valor'] += $p['total'] * (float)$p['costo'];
            if ($p['estado'] === 'agotado') $kpi['agotados']++;
            elseif ($p['estado'] === 'bajo') $kpi['bajo']++;
        }
    }
    unset($p);
    if ($soloBajo) $productos = array_values(array_filter($productos, fn($p) => $p['estado'] !== 'ok'));
}
$tiendasVista = $tiendaFiltro ? array_values(array_filter($tiendas, fn($t) => (int)$t['id'] === $tiendaFiltro)) : $tiendas;
$q = fn($v) => invFmt((float)$v);

require_once '../../includes/templates/header.php';
?>

<div class="app-page-header">
    <div>
        <h1 class="app-page-title">Inventario</h1>
        <p class="app-page-sub">Existencias de productos (bienes) por tienda. Los servicios no llevan inventario.</p>
    </div>
    <?php if ($instalado && $puedeEditar): ?>
        <div class="d-flex flex-wrap gap-2">
            <a href="inventario_traslados" class="btn btn-outline-primary"><i class="bi bi-truck me-1"></i> Traslados<?= $kpi['transito'] ? ' <span class="badge bg-warning text-dark">' . $kpi['transito'] . '</span>' : '' ?></a>
            <button class="btn btn-outline-primary" data-abrir="modalEntrada" <?= $productos ? '' : 'disabled' ?>><i class="bi bi-box-arrow-in-down me-1"></i> Entrada</button>
            <button class="btn btn-primary" id="btnNuevoProducto"><i class="bi bi-plus-lg me-1"></i> Producto</button>
        </div>
    <?php endif; ?>
</div>

<?php if (!$instalado): ?>
    <div class="alert alert-warning">El inventario no está instalado. Ejecuta <code>sql/migraciones/2026-10-03_inventario.sql</code>.</div>
<?php else: ?>
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-4 col-xl"><div class="app-card app-kpi"><div class="app-kpi-label">Productos</div><div class="app-kpi-value"><?= $kpi['productos'] ?></div></div></div>
        <div class="col-6 col-md-4 col-xl"><div class="app-card app-kpi"><div class="app-kpi-label">Valor (al costo)</div><div class="app-kpi-value">L <?= number_format($kpi['valor'], 2) ?></div></div></div>
        <div class="col-6 col-md-4 col-xl"><a href="?bajo=1<?= $tiendaFiltro ? '&tienda=' . $tiendaFiltro : '' ?>" class="text-decoration-none"><div class="app-card app-kpi"><div class="app-kpi-label">Bajo mínimo</div><div class="app-kpi-value <?= $kpi['bajo'] ? 'text-warning' : '' ?>"><?= $kpi['bajo'] ?></div></div></a></div>
        <div class="col-6 col-md-4 col-xl"><a href="?bajo=1<?= $tiendaFiltro ? '&tienda=' . $tiendaFiltro : '' ?>" class="text-decoration-none"><div class="app-card app-kpi"><div class="app-kpi-label">Agotados</div><div class="app-kpi-value <?= $kpi['agotados'] ? 'text-danger' : '' ?>"><?= $kpi['agotados'] ?></div></div></a></div>
        <div class="col-6 col-md-4 col-xl"><a href="inventario_traslados" class="text-decoration-none"><div class="app-card app-kpi"><div class="app-kpi-label">En tránsito</div><div class="app-kpi-value"><?= $kpi['transito'] ?></div></div></a></div>
    </div>

    <div class="app-card">
        <div class="app-card-header flex-wrap">
            <form class="d-flex flex-wrap gap-2 align-items-center" method="GET">
                <select name="tienda" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
                    <option value="0">Todas las tiendas</option>
                    <?php foreach ($tiendas as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $tiendaFiltro === (int)$t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['codigo'] . ' · ' . $t['nombre']) ?></option><?php endforeach; ?>
                </select>
                <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" name="bajo" value="1" id="chkBajo" <?= $soloBajo ? 'checked' : '' ?> onchange="this.form.submit()"><label class="form-check-label small" for="chkBajo">Solo bajo mínimo / agotados</label></div>
            </form>
            <div class="d-flex gap-2 align-items-center">
                <a href="inventario_reportes" class="small">Reportes →</a>
                <input type="search" class="form-control form-control-sm" id="buscar" placeholder="Nombre, SKU o código…" style="max-width:220px">
            </div>
        </div>
        <div class="table-responsive">
            <table class="table app-table" id="tablaInv">
                <thead>
                    <tr>
                        <th style="min-width:220px">Producto</th>
                        <?php foreach ($tiendasVista as $t): ?><th class="app-num" title="<?= htmlspecialchars($t['nombre']) ?>"><?= htmlspecialchars($t['nombre']) ?></th><?php endforeach; ?>
                        <?php if (!$tiendaFiltro && count($tiendas) > 1): ?><th class="app-num">Total</th><?php endif; ?>
                        <th class="app-num">Mín.</th><th class="app-num">Precio</th><th class="app-num">Costo</th><th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$productos): ?><tr><td colspan="<?= count($tiendasVista) + 6 ?>" class="text-center text-muted py-5"><i class="bi bi-box-seam fs-2 d-block mb-2"></i><?= $soloBajo ? 'Nada bajo el mínimo.' : 'Aún no hay productos con inventario. Crea uno con «Producto» o convierte un producto existente.' ?></td></tr><?php endif; ?>
                    <?php foreach ($productos as $p): ?>
                        <tr data-buscar="<?= htmlspecialchars(mb_strtolower($p['nombre'] . ' ' . $p['sku'] . ' ' . $p['codigo_barras'])) ?>" class="<?= (int)$p['activo'] ? '' : 'opacity-50' ?>">
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($p['nombre']) ?>
                                    <?php if ($p['estado'] === 'agotado'): ?><span class="app-badge app-badge-danger">Agotado</span><?php elseif ($p['estado'] === 'bajo'): ?><span class="app-badge app-badge-warning">Bajo mínimo</span><?php endif; ?></div>
                                <div class="small text-muted font-monospace"><?= htmlspecialchars(trim(($p['sku'] ? 'SKU ' . $p['sku'] : '') . ($p['codigo_barras'] ? ' · ' . $p['codigo_barras'] : ''), ' ·')) ?: '—' ?> · <?= htmlspecialchars($p['unidad']) ?></div>
                            </td>
                            <?php foreach ($tiendasVista as $t):
                                $ex = $existencias[$p['id']][$t['id']] ?? null; $c = (float)($ex['cantidad'] ?? 0); $r = (float)($ex['reservado'] ?? 0); ?>
                                <td class="app-num <?= $c <= 0 ? 'text-danger' : ($c <= (float)$p['stock_minimo'] ? 'text-warning' : '') ?>">
                                    <a href="inventario_kardex?producto_id=<?= (int)$p['id'] ?>&establecimiento_id=<?= (int)$t['id'] ?>" class="text-reset text-decoration-none"><?= $q($c) ?></a>
                                    <?php if ($r > 0): ?><div class="small text-muted" title="Apartado / reservado"><?= $q($r) ?> res.</div><?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <?php if (!$tiendaFiltro && count($tiendas) > 1): ?><td class="app-num fw-semibold"><?= $q($p['total']) ?></td><?php endif; ?>
                            <td class="app-num text-muted"><?= $q($p['stock_minimo']) ?></td>
                            <td class="app-num"><?= number_format((float)$p['precio'], 2) ?></td>
                            <td class="app-num text-muted"><?= number_format((float)$p['costo'], 2) ?></td>
                            <td class="text-end text-nowrap">
                                <a href="inventario_kardex?producto_id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Kardex"><i class="bi bi-journal-text"></i></a>
                                <?php if ($puedeEditar): ?>
                                    <button class="btn btn-sm btn-outline-secondary btn-editar" title="Editar" data-p='<?= json_encode(array_intersect_key($p, array_flip(['id', 'nombre', 'tipo', 'sku', 'codigo_barras', 'unidad', 'precio', 'tipo_isv', 'stock_minimo', 'costo'])), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>'><i class="bi bi-pencil"></i></button>
                                    <button class="btn btn-sm btn-outline-primary btn-entrada" data-id="<?= (int)$p['id'] ?>" title="Entrada"><i class="bi bi-box-arrow-in-down"></i></button>
                                    <button class="btn btn-sm btn-outline-warning btn-ajuste" data-id="<?= (int)$p['id'] ?>" data-nombre="<?= htmlspecialchars($p['nombre']) ?>" title="Ajuste por conteo"><i class="bi bi-clipboard-check"></i></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($puedeEditar): ?>
        <?php
        $optsProductos = implode('', array_map(fn($p) => '<option value="' . (int)$p['id'] . '">' . htmlspecialchars($p['nombre'] . ($p['sku'] ? ' · ' . $p['sku'] : '')) . '</option>', array_filter($productos, fn($p) => (int)$p['activo'])));
        $optsTiendas = implode('', array_map(fn($t) => '<option value="' . (int)$t['id'] . '"' . ((int)$t['id'] === (int)($_SESSION['establecimiento_activo'] ?? 0) ? ' selected' : '') . '>' . htmlspecialchars($t['codigo'] . ' · ' . $t['nombre']) . '</option>', $tiendas));
        ?>
        <!-- Modal producto -->
        <div class="modal fade" id="modalProducto" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-fullscreen-sm-down">
                <form class="modal-content" id="formProducto" novalidate>
                    <div class="modal-header"><h5 class="modal-title" id="tituloProducto">Nuevo producto</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
                    <div class="modal-body row g-3">
                        <input type="hidden" name="accion" value="producto_guardar"><input type="hidden" name="id">
                        <?php if ($servicios): ?>
                            <div class="col-12" id="wrapConvertir">
                                <label class="form-label">¿Convertir un producto existente en bien con inventario?</label>
                                <select class="form-select" id="convertirExistente"><option value="">— No, crear uno nuevo —</option>
                                    <?php foreach ($servicios as $s): ?><option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['nombre']) ?></option><?php endforeach; ?></select>
                                <div class="form-text">Solo productos generales (no asignados a un cliente).</div>
                            </div>
                        <?php endif; ?>
                        <div class="col-md-8"><label class="form-label">Nombre *</label><input class="form-control" name="nombre" required maxlength="400"></div>
                        <div class="col-md-4"><label class="form-label">Tipo</label><select class="form-select" name="tipo"><option value="bien">Bien (con inventario)</option><option value="servicio">Servicio</option></select></div>
                        <div class="col-md-4"><label class="form-label">SKU</label><input class="form-control font-monospace" name="sku" maxlength="50"></div>
                        <div class="col-md-5"><label class="form-label">Código de barras</label><input class="form-control font-monospace" name="codigo_barras" maxlength="64" placeholder="Escanéalo aquí"></div>
                        <div class="col-md-3"><label class="form-label">Unidad</label><input class="form-control" name="unidad" maxlength="20" value="unidad" list="unidades"><datalist id="unidades"><option>unidad</option><option>caja</option><option>paquete</option><option>libra</option><option>kg</option><option>litro</option><option>galón</option><option>metro</option></datalist></div>
                        <div class="col-6 col-md-3"><label class="form-label">Precio de venta</label><input class="form-control" type="number" step="0.01" min="0" name="precio" value="0"></div>
                        <div class="col-6 col-md-3"><label class="form-label">ISV</label><select class="form-select" name="tipo_isv"><option value="15">15 %</option><option value="18">18 %</option><option value="0">Exento</option></select></div>
                        <div class="col-6 col-md-3"><label class="form-label">Stock mínimo</label><input class="form-control" type="number" step="0.001" min="0" name="stock_minimo" value="0"></div>
                        <div class="col-6 col-md-3" id="wrapCosto"><label class="form-label">Costo inicial</label><input class="form-control" type="number" step="0.0001" min="0" name="costo" value="0"></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Guardar</button></div>
                </form>
            </div>
        </div>

        <!-- Modal entrada -->
        <div class="modal fade" id="modalEntrada" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-fullscreen-sm-down">
                <form class="modal-content" id="formEntrada" novalidate>
                    <div class="modal-header"><h5 class="modal-title">Entrada de mercadería</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
                    <div class="modal-body row g-3">
                        <input type="hidden" name="accion" value="entrada">
                        <div class="col-12"><label class="form-label">Producto *</label><select class="form-select" name="producto_id" required><?= $optsProductos ?></select></div>
                        <div class="col-12"><label class="form-label">Tienda *</label><select class="form-select" name="establecimiento_id" required><?= $optsTiendas ?></select></div>
                        <div class="col-6"><label class="form-label">Cantidad *</label><input class="form-control" type="number" step="0.001" min="0.001" name="cantidad" required></div>
                        <div class="col-6"><label class="form-label">Costo unitario</label><input class="form-control" type="number" step="0.0001" min="0" name="costo_unitario" placeholder="Opcional"></div>
                        <div class="col-12"><label class="form-label">Referencia</label><input class="form-control" name="referencia" maxlength="100" placeholder="N.° de factura del proveedor"></div>
                        <div class="col-12"><label class="form-label">Notas</label><input class="form-control" name="notas" maxlength="255"></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Registrar entrada</button></div>
                </form>
            </div>
        </div>

        <!-- Modal ajuste -->
        <div class="modal fade" id="modalAjuste" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-fullscreen-sm-down">
                <form class="modal-content" id="formAjuste" novalidate>
                    <div class="modal-header"><h5 class="modal-title">Ajuste por conteo · <span id="ajusteNombre"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
                    <div class="modal-body row g-3">
                        <input type="hidden" name="accion" value="ajuste"><input type="hidden" name="producto_id">
                        <div class="col-12"><label class="form-label">Tienda *</label><select class="form-select" name="establecimiento_id" required><?= $optsTiendas ?></select></div>
                        <div class="col-12"><label class="form-label">Cantidad contada físicamente *</label><input class="form-control" type="number" step="0.001" min="0" name="contado" required>
                            <div class="form-text">La existencia quedará igual a lo contado; la diferencia queda en el kardex.</div></div>
                        <div class="col-12"><label class="form-label">Motivo *</label><input class="form-control" name="motivo" required maxlength="255" placeholder="Conteo mensual, merma, producto dañado…"></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-warning" type="submit">Ajustar</button></div>
                </form>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<script src="../../clientes/js/app-acciones.js?v=<?= @filemtime(__DIR__ . '/../js/app-acciones.js') ?>"></script>
<script>
(function () {
    document.getElementById('buscar')?.addEventListener('input', function () {
        const q = this.value.trim().toLowerCase();
        document.querySelectorAll('#tablaInv tbody tr[data-buscar]').forEach(tr => tr.classList.toggle('d-none', q && !tr.dataset.buscar.includes(q)));
    });
    const fp = document.getElementById('formProducto');
    if (!fp) return;
    const A = AppAcciones('includes/inventario_accion.php');
    [fp, document.getElementById('formEntrada'), document.getElementById('formAjuste')].forEach(A.formulario);
    document.querySelectorAll('[data-abrir]').forEach(b => b.addEventListener('click', () => A.modal(b.dataset.abrir).show()));

    function abrirProducto(p) {
        fp.reset(); fp.classList.remove('was-validated');
        fp.elements.id.value = p ? p.id : '';
        document.getElementById('tituloProducto').textContent = p ? 'Editar producto' : 'Nuevo producto';
        document.getElementById('wrapConvertir')?.classList.toggle('d-none', !!p);
        document.getElementById('wrapCosto').classList.toggle('d-none', !!p);
        if (p) ['nombre', 'tipo', 'sku', 'codigo_barras', 'unidad', 'precio', 'tipo_isv', 'stock_minimo'].forEach(k => fp.elements[k].value = p[k] ?? '');
        A.modal('modalProducto').show();
    }
    document.getElementById('btnNuevoProducto').addEventListener('click', () => abrirProducto(null));
    document.querySelectorAll('.btn-editar').forEach(b => b.addEventListener('click', () => abrirProducto(JSON.parse(b.dataset.p))));
    // Convertir un servicio existente: se edita ese producto con tipo "bien"
    document.getElementById('convertirExistente')?.addEventListener('change', function () {
        fp.elements.id.value = this.value;
        if (this.value) { fp.elements.nombre.value = this.selectedOptions[0].textContent.trim(); fp.elements.tipo.value = 'bien'; }
    });
    // El lector de código de barras envía Enter: que no envíe el formulario
    fp.elements.codigo_barras.addEventListener('keydown', e => { if (e.key === 'Enter') e.preventDefault(); });

    document.querySelectorAll('.btn-entrada').forEach(b => b.addEventListener('click', () => {
        const f = document.getElementById('formEntrada'); f.reset(); f.elements.producto_id.value = b.dataset.id; A.modal('modalEntrada').show();
    }));
    document.querySelectorAll('.btn-ajuste').forEach(b => b.addEventListener('click', () => {
        const f = document.getElementById('formAjuste'); f.reset(); f.elements.producto_id.value = b.dataset.id;
        document.getElementById('ajusteNombre').textContent = b.dataset.nombre; A.modal('modalAjuste').show();
    }));
})();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
