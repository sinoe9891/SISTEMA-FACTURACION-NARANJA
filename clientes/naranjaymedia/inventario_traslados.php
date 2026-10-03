<?php
$titulo = 'Inventario';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/inventario.php';

$cid = cliente_actual();
if (!invDisponible($pdo)) {
    header('Location: ./inventario');
    exit;
}
$puedeEditar = in_array(USUARIO_ROL, ['admin', 'superadmin'], true);

$st = $pdo->prepare("SELECT establecimiento_id AS id, nombre, codigo_establecimiento AS codigo FROM establecimientos WHERE cliente_id = ? ORDER BY codigo_establecimiento, nombre");
$st->execute([$cid]);
$tiendas = $st->fetchAll(PDO::FETCH_ASSOC);
$nombreTienda = array_column($tiendas, 'nombre', 'id');

$st = $pdo->prepare("SELECT id, nombre, sku, unidad FROM productos_clientes WHERE cliente_id = ? AND tipo = 'bien' AND activo = 1 ORDER BY nombre");
$st->execute([$cid]);
$productos = $st->fetchAll(PDO::FETCH_ASSOC);

// Existencias disponibles por tienda (para mostrar en el formulario)
$st = $pdo->prepare("SELECT producto_id, establecimiento_id, cantidad - reservado AS disp FROM inv_existencias WHERE cliente_id = ?");
$st->execute([$cid]);
$disp = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $disp[$r['establecimiento_id']][$r['producto_id']] = (float)$r['disp'];

$st = $pdo->prepare("SELECT t.*, u.nombre AS usuario, r.nombre AS receptor
    FROM inv_traslados t LEFT JOIN usuarios u ON u.id = t.usuario_id LEFT JOIN usuarios r ON r.id = t.recibido_por
    WHERE t.cliente_id = ? ORDER BY t.estado = 'en_transito' DESC, t.fecha_envio DESC LIMIT 200");
$st->execute([$cid]);
$traslados = $st->fetchAll(PDO::FETCH_ASSOC);
$items = [];
if ($traslados) {
    $ids = array_column($traslados, 'id');
    $st = $pdo->prepare("SELECT i.*, p.nombre, p.unidad FROM inv_traslado_items i JOIN productos_clientes p ON p.id = i.producto_id WHERE i.traslado_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i) $items[$i['traslado_id']][] = $i;
}
$badge = ['en_transito' => ['En tránsito', 'warning'], 'recibido' => ['Recibido', 'success'], 'anulado' => ['Anulado', 'danger']];

require_once '../../includes/templates/header.php';
?>

<div class="app-page-header">
    <div>
        <a href="inventario" class="small text-muted text-decoration-none"><i class="bi bi-arrow-left"></i> Inventario</a>
        <h1 class="app-page-title">Traslados entre tiendas</h1>
        <p class="app-page-sub">La mercadería sale del origen al enviar y entra al destino cuando se marca como recibida.</p>
    </div>
    <?php if ($puedeEditar): ?><button class="btn btn-primary" id="btnNuevo" <?= count($tiendas) > 1 && $productos ? '' : 'disabled title="Se necesitan 2 tiendas y productos con inventario"' ?>><i class="bi bi-truck me-1"></i> Nuevo traslado</button><?php endif; ?>
</div>

<div class="d-flex flex-column gap-3">
    <?php if (!$traslados): ?><div class="app-card app-card-body text-center text-muted py-5"><i class="bi bi-truck fs-2 d-block mb-2"></i>No hay traslados.</div><?php endif; ?>
    <?php foreach ($traslados as $t): ?>
        <div class="app-card" id="t<?= (int)$t['id'] ?>">
            <div class="app-card-header flex-wrap">
                <span><span class="fw-bold">#<?= (int)$t['id'] ?></span> · <?= htmlspecialchars($nombreTienda[$t['origen_id']] ?? '?') ?> <i class="bi bi-arrow-right"></i> <?= htmlspecialchars($nombreTienda[$t['destino_id']] ?? '?') ?>
                    <span class="app-badge app-badge-<?= $badge[$t['estado']][1] ?> ms-1"><?= $badge[$t['estado']][0] ?></span></span>
                <span class="small text-muted">Enviado <?= date('d/m/Y H:i', strtotime($t['fecha_envio'])) ?> por <?= htmlspecialchars($t['usuario'] ?? '—') ?><?= $t['fecha_recibido'] ? ' · recibido ' . date('d/m/Y H:i', strtotime($t['fecha_recibido'])) . ' por ' . htmlspecialchars($t['receptor'] ?? '—') : '' ?></span>
            </div>
            <div class="app-card-body py-2">
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($items[$t['id']] ?? [] as $i): ?><span class="app-badge app-badge-muted"><?= invFmt((float)$i['cantidad']) ?> <?= htmlspecialchars($i['unidad']) ?> · <?= htmlspecialchars($i['nombre']) ?></span><?php endforeach; ?>
                </div>
                <?php if ($t['notas']): ?><div class="small text-muted mt-1"><?= htmlspecialchars($t['notas']) ?></div><?php endif; ?>
                <?php if ($puedeEditar && $t['estado'] === 'en_transito'): ?>
                    <div class="mt-2 d-flex gap-2">
                        <button class="btn btn-sm btn-success btn-recibir" data-id="<?= (int)$t['id'] ?>"><i class="bi bi-check2-circle"></i> Marcar recibido</button>
                        <button class="btn btn-sm btn-outline-danger btn-anular" data-id="<?= (int)$t['id'] ?>">Anular</button>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($puedeEditar): ?>
    <div class="modal fade" id="modalTraslado" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-fullscreen-sm-down">
            <form class="modal-content" id="formTraslado" novalidate>
                <div class="modal-header"><h5 class="modal-title">Nuevo traslado</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
                <div class="modal-body">
                    <input type="hidden" name="accion" value="traslado">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6"><label class="form-label">Desde *</label><select class="form-select" name="origen_id" id="trOrigen"><?php foreach ($tiendas as $t): ?><option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['codigo'] . ' · ' . $t['nombre']) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-6"><label class="form-label">Hacia *</label><select class="form-select" name="destino_id" id="trDestino"><?php foreach ($tiendas as $i => $t): ?><option value="<?= (int)$t['id'] ?>" <?= $i === 1 ? 'selected' : '' ?>><?= htmlspecialchars($t['codigo'] . ' · ' . $t['nombre']) ?></option><?php endforeach; ?></select></div>
                    </div>
                    <div id="trItems" class="d-flex flex-column gap-2"></div>
                    <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="trAgregar"><i class="bi bi-plus"></i> Agregar producto</button>
                    <div class="mt-3"><label class="form-label">Notas</label><input class="form-control" name="notas" maxlength="255"></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Enviar traslado</button></div>
            </form>
        </div>
    </div>
<?php endif; ?>

<script src="../../clientes/js/app-acciones.js?v=<?= @filemtime(__DIR__ . '/../js/app-acciones.js') ?>"></script>
<script>
(function () {
    const A = AppAcciones('includes/inventario_accion.php');
    document.querySelectorAll('.btn-recibir').forEach(b => b.addEventListener('click', () =>
        Swal.fire({ title: '¿Marcar el traslado #' + b.dataset.id + ' como recibido?', text: 'Las cantidades entrarán a la tienda de destino.', icon: 'question', showCancelButton: true, confirmButtonText: 'Recibido', cancelButtonText: 'Cancelar' })
            .then(r => { if (r.isConfirmed) A.accion({ accion: 'traslado_recibir', id: b.dataset.id }).catch(() => {}); })));
    document.querySelectorAll('.btn-anular').forEach(b => b.addEventListener('click', () =>
        Swal.fire({ title: '¿Anular el traslado #' + b.dataset.id + '?', text: 'La mercadería regresará a la tienda de origen.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Anular', confirmButtonColor: '#dc2626', cancelButtonText: 'Cancelar' })
            .then(r => { if (r.isConfirmed) A.accion({ accion: 'traslado_anular', id: b.dataset.id }).catch(() => {}); })));

    const f = document.getElementById('formTraslado');
    if (!f) return;
    const productos = <?= json_encode($productos, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const disp = <?= json_encode($disp ?: new stdClass()) ?>;
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    let n = 0;
    function fila() {
        const i = n++;
        const div = document.createElement('div');
        div.className = 'row g-2 align-items-end tr-item';
        div.innerHTML = `<div class="col-7"><label class="form-label small mb-1">Producto</label><select class="form-select" name="items[${i}][producto_id]" required>${productos.map(p => `<option value="${p.id}">${esc(p.nombre)}${p.sku ? ' · ' + esc(p.sku) : ''}</option>`).join('')}</select></div>
            <div class="col-3"><label class="form-label small mb-1">Cantidad</label><input class="form-control" type="number" step="0.001" min="0.001" name="items[${i}][cantidad]" required><div class="form-text tr-disp"></div></div>
            <div class="col-2"><button type="button" class="btn btn-outline-danger w-100 tr-quitar" title="Quitar"><i class="bi bi-trash"></i></button></div>`;
        div.querySelector('.tr-quitar').addEventListener('click', () => { div.remove(); });
        div.querySelector('select').addEventListener('change', mostrarDisp);
        document.getElementById('trItems').appendChild(div);
        mostrarDisp();
    }
    function mostrarDisp() {
        const o = document.getElementById('trOrigen').value;
        document.querySelectorAll('.tr-item').forEach(r => {
            const pid = r.querySelector('select').value;
            r.querySelector('.tr-disp').textContent = 'Disp.: ' + ((disp[o] || {})[pid] || 0);
        });
    }
    document.getElementById('trOrigen').addEventListener('change', mostrarDisp);
    document.getElementById('trAgregar').addEventListener('click', fila);
    document.getElementById('btnNuevo').addEventListener('click', () => {
        f.reset(); document.getElementById('trItems').innerHTML = ''; fila(); A.modal('modalTraslado').show();
    });
    A.formulario(f);
})();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
