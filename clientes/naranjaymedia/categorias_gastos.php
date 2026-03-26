<?php
$titulo = 'Categorías de Gastos';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/templates/header.php';

$cliente_id = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);

$stmt = $pdo->prepare("
    SELECT cg.*, COUNT(g.id) AS total_gastos, COALESCE(SUM(g.monto),0) AS monto_total
    FROM categorias_gastos cg
    LEFT JOIN gastos g ON g.categoria_id=cg.id AND g.estado!='anulado'
    WHERE cg.cliente_id=?
    GROUP BY cg.id ORDER BY cg.nombre ASC
");
$stmt->execute([$cliente_id]);
$categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_cats    = count($categorias);
$activas       = count(array_filter($categorias, fn($c) => $c['activa']));
$total_monto_cat = array_sum(array_column($categorias, 'monto_total'));

$colores_predefinidos = ['#0d6efd', '#6f42c1', '#d63384', '#dc3545', '#fd7e14', '#ffc107', '#198754', '#20c997', '#0dcaf0', '#6c757d', '#495057'];
$iconos_predefinidos  = ['fa-tag', 'fa-users', 'fa-building', 'fa-bolt', 'fa-bullhorn', 'fa-laptop-code', 'fa-car', 'fa-file-invoice', 'fa-university', 'fa-tools', 'fa-shopping-cart', 'fa-phone', 'fa-globe', 'fa-ellipsis-h'];

require_once '../../includes/templates/header.php';
// header already included above... let me fix:
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>
:root {
    --brand: #dc2626;
    --brand-light: #fef2f2;
    --brand-dark: #b91c1c;
    --success: #10b981;
    --success-bg: #ecfdf5;
    --danger: #ef4444;
    --danger-bg: #fef2f2;
    --surface: #fff;
    --surface-2: #f8fafc;
    --border: #e2e8f0;
    --text-main: #1e293b;
    --text-muted: #64748b;
    --shadow-sm: 0 1px 3px rgba(0, 0, 0, .06);
    --shadow-md: 0 4px 16px rgba(0, 0, 0, .08);
    --radius: 14px;
    --radius-sm: 8px;
    --tr: .2s cubic-bezier(.4, 0, .2, 1);
}

.cg-page {
    padding: 1.5rem 0 3rem;
}

.cg-header {
    background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%);
    border-radius: var(--radius);
    padding: 1.5rem 2rem;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1.5rem;
    box-shadow: var(--shadow-md);
    position: relative;
    overflow: hidden;
}

.cg-header::before {
    content: '';
    position: absolute;
    top: -40px;
    right: -40px;
    width: 180px;
    height: 180px;
    border-radius: 50%;
    background: rgba(255, 255, 255, .08);
    pointer-events: none;
}

.cg-header::after {
    content: '';
    position: absolute;
    bottom: -60px;
    left: 30%;
    width: 260px;
    height: 140px;
    border-radius: 50%;
    background: rgba(255, 255, 255, .05);
    pointer-events: none;
}

.cg-header-title {
    font-size: 1.35rem;
    font-weight: 700;
    margin: 0;
}

.cg-header-sub {
    font-size: .82rem;
    opacity: .8;
    margin: .25rem 0 0;
}

/* Stats */
.cg-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.cg-stat {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 1rem 1.1rem;
    display: flex;
    align-items: center;
    gap: .8rem;
    box-shadow: var(--shadow-sm);
    transition: box-shadow var(--tr), transform var(--tr);
}

.cg-stat:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}

.cg-stat-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    flex-shrink: 0;
}

.cg-stat-icon.purple {
    background: #ede9fe;
    color: #7c3aed;
}

.cg-stat-icon.green {
    background: var(--success-bg);
    color: var(--success);
}

.cg-stat-icon.red {
    background: var(--danger-bg);
    color: var(--danger);
}

.cg-stat-val {
    font-size: 1.4rem;
    font-weight: 700;
    color: var(--text-main);
    line-height: 1;
}

.cg-stat-lbl {
    font-size: .72rem;
    color: var(--text-muted);
    margin-top: 2px;
}

/* Toolbar */
.cg-toolbar {
    display: flex;
    align-items: center;
    gap: .75rem;
    flex-wrap: wrap;
    margin-bottom: 1.25rem;
}

.cg-search-wrap {
    position: relative;
    flex: 1 1 200px;
}

.cg-search-wrap>i {
    position: absolute;
    left: .8rem;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
    font-size: .9rem;
    pointer-events: none;
}

.cg-search {
    width: 100%;
    padding: .52rem .8rem .52rem 2.2rem;
    border: 1.5px solid var(--border);
    border-radius: var(--radius-sm);
    font-size: .875rem;
    background: var(--surface);
    color: var(--text-main);
    outline: none;
    transition: border-color var(--tr);
}

.cg-search:focus {
    border-color: #7c3aed;
    box-shadow: 0 0 0 3px rgba(124, 58, 237, .1);
}

.cg-search::placeholder {
    color: #94a3b8;
}

.btn-new-cat {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    padding: .55rem 1.1rem;
    background: #7c3aed;
    color: #fff;
    border-radius: var(--radius-sm);
    font-size: .88rem;
    font-weight: 600;
    border: none;
    cursor: pointer;
    white-space: nowrap;
    box-shadow: 0 2px 8px rgba(124, 58, 237, .25);
    transition: background var(--tr), transform var(--tr);
}

.btn-new-cat:hover {
    background: #5b21b6;
    transform: translateY(-1px);
}

/* Grid de categorías */
.cg-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}

.cg-cat-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-sm);
    padding: 1.1rem 1.25rem;
    display: flex;
    align-items: center;
    gap: .85rem;
    transition: box-shadow var(--tr), transform var(--tr);
    position: relative;
    overflow: hidden;
}

.cg-cat-card:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}

.cg-cat-card.inactiva {
    opacity: .55;
}

.cg-cat-card::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 3px;
    border-radius: 3px 0 0 3px;
}

.cg-cat-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    flex-shrink: 0;
}

.cg-cat-body {
    flex: 1;
    min-width: 0;
}

.cg-cat-name {
    font-weight: 700;
    font-size: .92rem;
    color: var(--text-main);
    margin-bottom: .2rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.cg-cat-meta {
    font-size: .76rem;
    color: var(--text-muted);
}

.cg-cat-actions {
    display: flex;
    gap: .35rem;
    flex-shrink: 0;
}

.btn-ico {
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 7px;
    border: 1.5px solid var(--border);
    background: var(--surface);
    color: var(--text-muted);
    font-size: .8rem;
    cursor: pointer;
    transition: all var(--tr);
}

.btn-ico:hover {
    border-color: #7c3aed;
    color: #7c3aed;
    background: #ede9fe;
}

.btn-ico.danger:hover {
    border-color: #ef4444;
    color: #ef4444;
    background: #fef2f2;
}

/* Empty state */
.cg-empty-grid {
    text-align: center;
    padding: 3.5rem 1rem;
    color: var(--text-muted);
    grid-column: 1/-1;
}

/* Modal */
.modal-content {
    border: none;
    border-radius: var(--radius);
    box-shadow: 0 20px 60px rgba(0, 0, 0, .15);
}

.modal-header {
    border-bottom: 1px solid var(--border);
    padding: 1.1rem 1.5rem;
}

.modal-body {
    padding: 1.5rem;
}

.modal-footer {
    border-top: 1px solid var(--border);
    padding: .9rem 1.5rem;
}

.mf-label {
    font-size: .78rem;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: .04em;
    margin-bottom: .35rem;
    display: block;
}

.mf-input {
    width: 100%;
    padding: .58rem .85rem;
    border: 1.5px solid var(--border);
    border-radius: var(--radius-sm);
    font-size: .88rem;
    color: var(--text-main);
    background: var(--surface);
    outline: none;
    transition: border-color var(--tr);
}

.mf-input:focus {
    border-color: #7c3aed;
    box-shadow: 0 0 0 3px rgba(124, 58, 237, .1);
}

.mb-mf {
    margin-bottom: .9rem;
}

.color-chip {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    cursor: pointer;
    border: 2px solid transparent;
    transition: transform .12s, border-color .12s;
}

.color-chip:hover {
    transform: scale(1.15);
}

.color-chip.activo {
    border-color: #000 !important;
}

.icono-chip {
    width: 34px;
    height: 34px;
    background: #f8f9fa;
    border: 1.5px solid var(--border);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all .12s;
}

.icono-chip:hover {
    background: #ede9fe;
    border-color: #7c3aed;
}

.icono-chip.activo {
    background: #ede9fe;
    border-color: #7c3aed;
    color: #7c3aed;
}

.preview-circle {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.btn-modal-save {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    padding: .58rem 1.3rem;
    background: #7c3aed;
    color: #fff;
    border: none;
    border-radius: var(--radius-sm);
    font-size: .88rem;
    font-weight: 600;
    cursor: pointer;
    transition: background var(--tr);
}

.btn-modal-save:hover {
    background: #5b21b6;
}
</style>

<div class="cg-page container" style="max-width:960px;">

    <!-- Header -->
    <div class="cg-header">
        <div>
            <h4 class="cg-header-title">🏷️ Categorías de Gastos</h4>
            <p class="cg-header-sub">Organiza tus gastos por categorías para mejor análisis</p>
        </div>
        <a href="gastos" class="d-flex align-items-center gap-2 text-white text-decoration-none"
            style="opacity:.8;font-size:.85rem;">
            <i class="bi bi-arrow-left"></i> Volver a Gastos
        </a>
    </div>

    <!-- Stats -->
    <div class="cg-stats">
        <div class="cg-stat">
            <div class="cg-stat-icon purple"><i class="bi bi-tags-fill"></i></div>
            <div>
                <div class="cg-stat-val"><?= $total_cats ?></div>
                <div class="cg-stat-lbl">Total categorías</div>
            </div>
        </div>
        <div class="cg-stat">
            <div class="cg-stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
            <div>
                <div class="cg-stat-val"><?= $activas ?></div>
                <div class="cg-stat-lbl">Activas</div>
            </div>
        </div>
        <div class="cg-stat">
            <div class="cg-stat-icon red"><i class="bi bi-cash-coin"></i></div>
            <div>
                <div class="cg-stat-val" style="font-size:1.1rem;">L <?= number_format($total_monto_cat, 0) ?></div>
                <div class="cg-stat-lbl">Total registrado</div>
            </div>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="cg-toolbar">
        <button class="btn-new-cat" id="btnNuevaCat">
            <i class="bi bi-plus-lg"></i> Nueva Categoría
        </button>
        <div class="cg-search-wrap">
            <i class="bi bi-search"></i>
            <input type="text" id="cgSearch" class="cg-search" placeholder="Filtrar categorías…" autocomplete="off">
        </div>
    </div>

    <!-- Grid -->
    <div class="cg-grid" id="cgGrid">
        <?php if (empty($categorias)): ?>
        <div class="cg-empty-grid">
            <i class="bi bi-tags" style="font-size:2.5rem;opacity:.2;display:block;margin-bottom:.7rem;"></i>
            <div style="font-weight:600;">No hay categorías aún</div>
            <div style="font-size:.85rem;margin-top:.3rem;">Crea la primera para organizar tus gastos.</div>
        </div>
        <?php else: ?>
        <?php foreach ($categorias as $cat): ?>
        <div class="cg-cat-card <?= !$cat['activa'] ? 'inactiva' : '' ?>"
            style="--cat-color:<?= htmlspecialchars($cat['color']) ?>;"
            data-search="<?= strtolower(htmlspecialchars($cat['nombre'])) ?>">
            <div
                style="position:absolute;left:0;top:0;bottom:0;width:3px;background:<?= htmlspecialchars($cat['color']) ?>;border-radius:3px 0 0 3px;">
            </div>
            <div class="cg-cat-icon"
                style="background:<?= htmlspecialchars($cat['color']) ?>18;border:1.5px solid <?= htmlspecialchars($cat['color']) ?>40;">
                <i class="fa-solid <?= htmlspecialchars($cat['icono']) ?>"
                    style="color:<?= htmlspecialchars($cat['color']) ?>;font-size:16px;"></i>
            </div>
            <div class="cg-cat-body">
                <div class="cg-cat-name"><?= htmlspecialchars($cat['nombre']) ?></div>
                <div class="cg-cat-meta">
                    <?= $cat['total_gastos'] ?> gasto(s)
                    <?php if ($cat['monto_total'] > 0): ?> · L
                    <?= number_format((float)$cat['monto_total'], 2) ?><?php endif; ?>
                    <?php if (!$cat['activa']): ?> · <span style="color:#ef4444;">Inactiva</span><?php endif; ?>
                </div>
            </div>
            <div class="cg-cat-actions">
                <button class="btn-ico btn-editar-cat" title="Editar"
                    data-cat='<?= json_encode($cat, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>'>
                    <i class="bi bi-pencil-fill"></i>
                </button>
                <?php if ($cat['total_gastos'] == 0): ?>
                <button class="btn-ico danger btn-borrar-cat" title="Eliminar" data-id="<?= $cat['id'] ?>"
                    data-nombre="<?= htmlspecialchars($cat['nombre']) ?>">
                    <i class="bi bi-trash3-fill"></i>
                </button>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Categoría -->
<div class="modal fade" id="modalCat" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="modalCatTitulo">
                    <i class="bi bi-tags-fill me-2" style="color:#7c3aed;"></i>Nueva Categoría
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formCat">
                    <input type="hidden" name="cat_id" id="cat_id">
                    <div class="mb-mf">
                        <label class="mf-label">Nombre <span style="color:#ef4444;">*</span></label>
                        <input type="text" name="nombre_cat" id="nombre_cat" class="mf-input"
                            placeholder="Ej: Marketing Digital" maxlength="120" required>
                    </div>
                    <div class="mb-mf">
                        <label class="mf-label">Color</label>
                        <div style="display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:.5rem;">
                            <?php foreach ($colores_predefinidos as $color): ?>
                            <div class="color-chip" style="background:<?= $color ?>;" data-color="<?= $color ?>"></div>
                            <?php endforeach; ?>
                        </div>
                        <input type="color" name="color_cat" id="color_cat" class="form-control form-control-color"
                            value="#0d6efd" style="width:48px;height:36px;cursor:pointer;">
                    </div>
                    <div class="mb-mf">
                        <label class="mf-label">Ícono (Font Awesome)</label>
                        <div style="display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:.5rem;">
                            <?php foreach ($iconos_predefinidos as $ico): ?>
                            <div class="icono-chip" data-icono="<?= $ico ?>" title="<?= $ico ?>">
                                <i class="fa-solid <?= $ico ?>" style="font-size:13px;"></i>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <input type="text" name="icono_cat" id="icono_cat" class="mf-input" placeholder="fa-tag"
                            value="fa-tag">
                    </div>
                    <!-- Preview -->
                    <div
                        style="display:flex;align-items:center;gap:.85rem;padding:.75rem 1rem;background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0;">
                        <div class="preview-circle" id="previewCircle"
                            style="background:#0d6efd18;border:2px solid #0d6efd;">
                            <i id="previewIco" class="fa-solid fa-tag" style="color:#0d6efd;font-size:16px;"></i>
                        </div>
                        <span id="previewNombre" style="font-weight:700;font-size:.92rem;">Vista previa</span>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn-modal-save" id="btnGuardarCat">
                    <i class="bi bi-floppy-fill me-1"></i> Guardar
                </button>
            </div>
        </div>
    </div>
</div>

<script>
/* ── Filtrar grid en tiempo real ── */
document.getElementById('cgSearch').addEventListener('input', function() {
    const q = this.value.trim().toLowerCase();
    document.querySelectorAll('.cg-cat-card').forEach(c => {
        c.style.display = (!q || c.dataset.search.includes(q)) ? '' : 'none';
    });
});

/* ── Preview en tiempo real ── */
function actualizarPreview() {
    const color = document.getElementById('color_cat').value;
    const icono = document.getElementById('icono_cat').value.trim() || 'fa-tag';
    const nombre = document.getElementById('nombre_cat').value.trim() || 'Vista previa';
    document.getElementById('previewCircle').style.cssText =
        `background:${color}18;border:2px solid ${color};width:42px;height:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;`;
    const ico = document.getElementById('previewIco');
    ico.className = `fa-solid ${icono}`;
    ico.style.color = color;
    document.getElementById('previewNombre').textContent = nombre;
}

['color_cat', 'icono_cat', 'nombre_cat'].forEach(id => {
    document.getElementById(id).addEventListener('input', actualizarPreview);
    document.getElementById(id).addEventListener('change', actualizarPreview);
});

document.querySelectorAll('.color-chip').forEach(c => {
    c.addEventListener('click', () => {
        document.querySelectorAll('.color-chip').forEach(x => x.classList.remove('activo'));
        c.classList.add('activo');
        document.getElementById('color_cat').value = c.dataset.color;
        actualizarPreview();
    });
});

document.querySelectorAll('.icono-chip').forEach(c => {
    c.addEventListener('click', () => {
        document.querySelectorAll('.icono-chip').forEach(x => x.classList.remove('activo'));
        c.classList.add('activo');
        document.getElementById('icono_cat').value = c.dataset.icono;
        actualizarPreview();
    });
});

/* ── Abrir modal nuevo ── */
document.getElementById('btnNuevaCat').addEventListener('click', () => {
    document.getElementById('modalCatTitulo').innerHTML =
        '<i class="bi bi-tags-fill me-2" style="color:#7c3aed;"></i>Nueva Categoría';
    document.getElementById('formCat').reset();
    document.getElementById('cat_id').value = '';
    document.getElementById('color_cat').value = '#0d6efd';
    document.getElementById('icono_cat').value = 'fa-tag';
    actualizarPreview();
    new bootstrap.Modal(document.getElementById('modalCat')).show();
});

/* ── Abrir modal editar ── */
document.querySelectorAll('.btn-editar-cat').forEach(btn => {
    btn.addEventListener('click', () => {
        const c = JSON.parse(btn.dataset.cat);
        document.getElementById('modalCatTitulo').innerHTML =
            '<i class="bi bi-pencil-square me-2" style="color:#7c3aed;"></i>Editar Categoría';
        document.getElementById('cat_id').value = c.id;
        document.getElementById('nombre_cat').value = c.nombre;
        document.getElementById('color_cat').value = c.color;
        document.getElementById('icono_cat').value = c.icono;
        actualizarPreview();
        new bootstrap.Modal(document.getElementById('modalCat')).show();
    });
});

/* ── Guardar ── */
document.getElementById('btnGuardarCat').addEventListener('click', () => {
    const nombre = document.getElementById('nombre_cat').value.trim();
    if (!nombre) return Swal.fire({
        icon: 'warning',
        title: 'Nombre requerido',
        text: 'Escribe un nombre para la categoría.',
        confirmButtonColor: '#7c3aed'
    });

    const esEditar = !!document.getElementById('cat_id').value;
    const url = esEditar ? 'includes/categoria_gasto_actualizar.php' : 'includes/categoria_gasto_guardar.php';
    const btn = document.getElementById('btnGuardarCat');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Guardando…';

    fetch(url, {
            method: 'POST',
            body: new FormData(document.getElementById('formCat'))
        })
        .then(r => r.json())
        .then(d => {
            if (d.success) {
                Swal.fire({
                    icon: 'success',
                    title: '¡Listo!',
                    timer: 1400,
                    showConfirmButton: false
                }).then(() => location.reload());
            } else {
                Swal.fire('Error', d.error, 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-floppy-fill me-1"></i> Guardar';
            }
        })
        .catch(() => {
            Swal.fire('Error', 'Error de conexión.', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-floppy-fill me-1"></i> Guardar';
        });
});

/* ── Borrar ── */
document.querySelectorAll('.btn-borrar-cat').forEach(btn => {
    btn.addEventListener('click', () => {
        const id = btn.dataset.id,
            nombre = btn.dataset.nombre;
        Swal.fire({
            title: '¿Eliminar categoría?',
            html: `<strong>${nombre}</strong>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#6b7280',
            confirmButtonText: '<i class="bi bi-trash3-fill me-1"></i>Sí, eliminar',
            cancelButtonText: 'Cancelar',
            reverseButtons: true
        }).then(r => {
            if (!r.isConfirmed) return;
            const fd = new FormData();
            fd.append('id', id);
            fetch('includes/categoria_gasto_eliminar.php', {
                    method: 'POST',
                    body: fd
                })
                .then(res => res.json())
                .then(d => {
                    if (d.success) Swal.fire({
                        icon: 'success',
                        timer: 1400,
                        showConfirmButton: false
                    }).then(() => location.reload());
                    else Swal.fire('Error', d.error, 'error');
                });
        });
    });
});
</script>

<?php require_once '../../includes/templates/footer.php'; ?>