<?php
$titulo = 'Categorías de Gastos';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/templates/header.php';

$cliente_id = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);

$stmt = $pdo->prepare("
    SELECT cg.*, COUNT(g.id) AS total_gastos
    FROM categorias_gastos cg
    LEFT JOIN gastos g ON g.categoria_id = cg.id AND g.estado != 'anulado'
    WHERE cg.cliente_id = ?
    GROUP BY cg.id ORDER BY cg.nombre ASC
");
$stmt->execute([$cliente_id]);
$categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);

$colores_predefinidos = ['#0d6efd','#6f42c1','#d63384','#dc3545','#fd7e14','#ffc107','#198754','#20c997','#0dcaf0','#6c757d','#495057'];
$iconos_predefinidos  = ['fa-tag','fa-users','fa-building','fa-bolt','fa-bullhorn','fa-laptop-code','fa-car','fa-file-invoice','fa-university','fa-tools','fa-shopping-cart','fa-phone','fa-globe','fa-ellipsis-h'];
?>

<div class="container-xxl mt-4" style="max-width:900px">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <a href="gastos" class="btn btn-sm btn-outline-secondary me-2">
                <i class="fa-solid fa-arrow-left me-1"></i> Volver a Gastos
            </a>
            <h4 class="d-inline-block mb-0">
                <i class="fa-solid fa-tags me-2 text-secondary"></i>Categorías de Gastos
            </h4>
        </div>
        <button class="btn btn-primary" id="btnNuevaCat">
            <i class="fa-solid fa-plus me-1"></i> Nueva Categoría
        </button>
    </div>

    <div class="row g-3">
        <?php if (empty($categorias)): ?>
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body text-center py-5 text-muted">
                        <i class="fa-solid fa-tags fa-2x mb-2 d-block opacity-25"></i>
                        No hay categorías. Crea la primera.
                    </div>
                </div>
            </div>
        <?php else: ?>
        <?php foreach ($categorias as $cat): ?>
            <div class="col-md-6 col-lg-4">
                <div class="card border-0 shadow-sm h-100 <?= !$cat['activa'] ? 'opacity-50' : '' ?>">
                    <div class="card-body p-3 d-flex align-items-center gap-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                             style="width:44px;height:44px;background:<?= htmlspecialchars($cat['color']) ?>20;border:2px solid <?= htmlspecialchars($cat['color']) ?>">
                            <i class="fa-solid <?= htmlspecialchars($cat['icono']) ?>" style="color:<?= htmlspecialchars($cat['color']) ?>;font-size:16px"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="fw-bold"><?= htmlspecialchars($cat['nombre']) ?></div>
                            <small class="text-muted"><?= $cat['total_gastos'] ?> gasto(s) registrado(s)</small>
                        </div>
                        <div class="d-flex flex-column gap-1">
                            <button class="btn btn-xs btn-outline-primary btn-editar-cat"
                                    data-cat='<?= json_encode($cat, JSON_HEX_APOS|JSON_HEX_QUOT) ?>'
                                    title="Editar">
                                <i class="fa-solid fa-pen-to-square"></i>
                            </button>
                            <?php if ($cat['total_gastos'] == 0): ?>
                            <button class="btn btn-xs btn-outline-danger btn-borrar-cat"
                                    data-id="<?= $cat['id'] ?>"
                                    data-nombre="<?= htmlspecialchars($cat['nombre']) ?>"
                                    title="Eliminar">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Categoría -->
<div class="modal fade" id="modalCat" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom py-3">
                <h5 class="modal-title fw-bold" id="modalCatTitulo">
                    <i class="fa-solid fa-tags me-2"></i>Nueva Categoría
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form id="formCat">
                    <input type="hidden" name="cat_id" id="cat_id">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nombre <span class="text-danger">*</span></label>
                        <input type="text" name="nombre_cat" id="nombre_cat" class="form-control"
                               placeholder="Ej: Marketing Digital" maxlength="120" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Color</label>
                        <div class="d-flex gap-2 flex-wrap mb-2">
                            <?php foreach ($colores_predefinidos as $color): ?>
                                <div class="color-chip rounded-circle cursor-pointer"
                                     style="width:28px;height:28px;background:<?= $color ?>;cursor:pointer;border:2px solid transparent"
                                     data-color="<?= $color ?>"></div>
                            <?php endforeach; ?>
                        </div>
                        <input type="color" name="color_cat" id="color_cat" class="form-control form-control-color" value="#0d6efd">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ícono (Font Awesome)</label>
                        <div class="d-flex gap-2 flex-wrap mb-2">
                            <?php foreach ($iconos_predefinidos as $ico): ?>
                                <div class="icono-chip d-flex align-items-center justify-content-center rounded-2"
                                     style="width:34px;height:34px;background:#f8f9fa;cursor:pointer;border:1.5px solid #dee2e6"
                                     data-icono="<?= $ico ?>" title="<?= $ico ?>">
                                    <i class="fa-solid <?= $ico ?> small"></i>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <input type="text" name="icono_cat" id="icono_cat" class="form-control"
                               placeholder="fa-tag" value="fa-tag">
                    </div>
                    <!-- Preview -->
                    <div class="d-flex align-items-center gap-3 p-3 rounded-3 bg-light">
                        <div id="previewCircle" class="rounded-circle d-flex align-items-center justify-content-center"
                             style="width:42px;height:42px;background:#0d6efd20;border:2px solid #0d6efd">
                            <i id="previewIco" class="fa-solid fa-tag" style="color:#0d6efd"></i>
                        </div>
                        <span id="previewNombre" class="fw-bold">Vista previa</span>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-top">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary px-4" id="btnGuardarCat">
                    <i class="fa-solid fa-floppy-disk me-1"></i> Guardar
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.btn-xs { padding: 3px 7px; font-size: 11px; }
.color-chip:hover, .icono-chip:hover { opacity: .8; transform: scale(1.1); }
.color-chip.activo { border-color: #000 !important; }
.icono-chip.activo { background: #e7f1ff !important; border-color: #0d6efd !important; }
</style>

<script>
$(function () {
    // Preview en tiempo real
    function actualizarPreview() {
        const color  = $('#color_cat').val();
        const icono  = $('#icono_cat').val().trim() || 'fa-tag';
        const nombre = $('#nombre_cat').val().trim() || 'Vista previa';
        $('#previewCircle').css({ background: color+'20', border: '2px solid '+color });
        $('#previewIco').attr('class', 'fa-solid ' + icono).css('color', color);
        $('#previewNombre').text(nombre);
    }
    $('#color_cat, #icono_cat, #nombre_cat').on('input change', actualizarPreview);

    // Chips de color
    $(document).on('click', '.color-chip', function () {
        $('.color-chip').css('border-color','transparent');
        $(this).css('border-color','#000');
        $('#color_cat').val($(this).data('color')).trigger('change');
    });

    // Chips de ícono
    $(document).on('click', '.icono-chip', function () {
        $('.icono-chip').removeClass('activo');
        $(this).addClass('activo');
        $('#icono_cat').val($(this).data('icono')).trigger('input');
    });

    // Abrir modal nuevo
    $('#btnNuevaCat').on('click', function () {
        $('#modalCatTitulo').html('<i class="fa-solid fa-tags me-2"></i>Nueva Categoría');
        $('#formCat')[0].reset();
        $('#cat_id').val('');
        $('#color_cat').val('#0d6efd');
        $('#icono_cat').val('fa-tag');
        actualizarPreview();
        $('#modalCat').modal('show');
    });

    // Abrir modal editar
    $(document).on('click', '.btn-editar-cat', function () {
        const c = $(this).data('cat');
        $('#modalCatTitulo').html('<i class="fa-solid fa-pen-to-square me-2"></i>Editar Categoría');
        $('#cat_id').val(c.id);
        $('#nombre_cat').val(c.nombre);
        $('#color_cat').val(c.color);
        $('#icono_cat').val(c.icono);
        actualizarPreview();
        $('#modalCat').modal('show');
    });

    // Guardar categoría
    $('#btnGuardarCat').on('click', function () {
        const nombre = $('#nombre_cat').val().trim();
        if (!nombre) return Swal.fire({ icon:'warning', title:'Nombre requerido', text:'Escribe un nombre para la categoría.' });

        const esEditar = !!$('#cat_id').val();
        const url = esEditar ? 'includes/categoria_gasto_actualizar.php' : 'includes/categoria_gasto_guardar.php';
        const btn = $(this);
        btn.prop('disabled',true).html('<i class="fa-solid fa-spinner fa-spin me-1"></i> Guardando...');

        $.post(url, $('#formCat').serialize())
            .done(d => {
                if (d.success) {
                    Swal.fire({ icon:'success', title:'¡Listo!', timer:1400, showConfirmButton:false })
                        .then(() => location.reload());
                } else {
                    Swal.fire('Error', d.error, 'error');
                    btn.prop('disabled',false).html('<i class="fa-solid fa-floppy-disk me-1"></i> Guardar');
                }
            })
            .fail(() => {
                Swal.fire('Error','Error de conexión.','error');
                btn.prop('disabled',false).html('<i class="fa-solid fa-floppy-disk me-1"></i> Guardar');
            });
    });

    // Borrar categoría
    $(document).on('click', '.btn-borrar-cat', function () {
        const id = $(this).data('id'), nombre = $(this).data('nombre');
        Swal.fire({
            title: '¿Eliminar categoría?',
            html: `<strong>${nombre}</strong>`,
            icon: 'warning', showCancelButton:true,
            confirmButtonColor:'#dc3545', confirmButtonText:'Sí, eliminar', cancelButtonText:'Cancelar'
        }).then(r => {
            if (!r.isConfirmed) return;
            $.post('includes/categoria_gasto_eliminar.php', { id }, d => {
                if (d.success) Swal.fire({ icon:'success', timer:1400, showConfirmButton:false }).then(() => location.reload());
                else Swal.fire('Error', d.error, 'error');
            }, 'json');
        });
    });
});
</script>

</body>
</html>
