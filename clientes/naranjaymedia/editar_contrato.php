<?php
$titulo = 'Editar Contrato';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

$cliente_id = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: contratos'); exit; }

// Verificar propiedad
$stmt = $pdo->prepare("SELECT * FROM contratos WHERE id = ? AND cliente_id = ?");
$stmt->execute([$id, $cliente_id]);
$contrato = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$contrato) { header('Location: contratos'); exit; }

// Solo clientes — servicios se cargan por AJAX
$stmtClientes = $pdo->prepare("
    SELECT id, nombre, rtn FROM clientes_factura
    WHERE cliente_id = ? ORDER BY nombre
");
$stmtClientes->execute([$cliente_id]);
$clientes_lista = $stmtClientes->fetchAll(PDO::FETCH_ASSOC);

// Nombre del servicio actual para mostrarlo de entrada
$nombreServicioActual = '';
if ($contrato['producto_id']) {
    $stmtSvc = $pdo->prepare("SELECT nombre, precio FROM productos_clientes WHERE id = ? AND cliente_id = ?");
    $stmtSvc->execute([$contrato['producto_id'], $cliente_id]);
    $svcActual = $stmtSvc->fetch(PDO::FETCH_ASSOC);
    $nombreServicioActual = $svcActual['nombre'] ?? '';
}

require_once '../../includes/templates/header.php';
?>

<div class="container mt-4" style="max-width:780px">

    <div class="mb-4">
        <a href="contratos" class="btn btn-sm btn-outline-secondary me-2">
            <i class="fa-solid fa-arrow-left me-1"></i> Volver
        </a>
        <h4 class="d-inline-block mb-0">
            <i class="fa-solid fa-file-contract me-2 text-warning"></i>Editar Contrato
        </h4>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <form id="formEditar" method="POST" action="includes/contrato_actualizar.php" novalidate>
                <input type="hidden" name="id" value="<?= $contrato['id'] ?>">

                <!-- Cliente -->
                <div class="mb-3">
                    <label class="form-label fw-semibold">
                        <i class="fa-solid fa-user me-1 text-warning"></i> Cliente <span class="text-danger">*</span>
                    </label>
                    <select name="receptor_id" id="receptor_id" class="form-select" required>
                        <option value="">— Seleccionar —</option>
                        <?php foreach ($clientes_lista as $cl): ?>
                            <option value="<?= $cl['id'] ?>"
                                <?= $cl['id'] == $contrato['receptor_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cl['nombre']) ?>
                                <?= $cl['rtn'] ? ' (RTN: ' . htmlspecialchars($cl['rtn']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Nombre -->
                <div class="mb-3">
                    <label class="form-label fw-semibold">
                        <i class="fa-solid fa-tag me-1 text-warning"></i> Nombre / Referencia <span class="text-danger">*</span>
                    </label>
                    <input type="text" name="nombre_contrato" class="form-control"
                           value="<?= htmlspecialchars($contrato['nombre_contrato']) ?>"
                           maxlength="200" required>
                </div>

                <!-- Servicio + Monto -->
                <div class="row g-3 mb-3">
                    <div class="col-md-7">
                        <label class="form-label fw-semibold">
                            <i class="fa-solid fa-box me-1 text-warning"></i> Servicio <span class="text-danger">*</span>
                        </label>
                        <select name="producto_id" id="producto_id" class="form-select" required>
                            <?php if ($contrato['producto_id'] && $nombreServicioActual): ?>
                                <option value="<?= $contrato['producto_id'] ?>"
                                        data-precio="<?= $contrato['monto'] ?>" selected>
                                    <?= htmlspecialchars($nombreServicioActual) ?> — L <?= number_format((float)$contrato['monto'], 2) ?>
                                </option>
                            <?php else: ?>
                                <option value="">— Selecciona un cliente primero —</option>
                            <?php endif; ?>
                        </select>
                        <small class="text-muted">Al cambiar cliente, los servicios se actualizan automáticamente.</small>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label fw-semibold">
                            <i class="fa-solid fa-money-bill me-1 text-warning"></i> Monto (L) <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text">L</span>
                            <input type="number" name="monto" id="monto" class="form-control"
                                   value="<?= $contrato['monto'] ?>" min="0" step="0.01" required>
                        </div>
                    </div>
                </div>

                <!-- Fechas -->
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Fecha Inicio <span class="text-danger">*</span></label>
                        <input type="date" name="fecha_inicio" class="form-control"
                               value="<?= $contrato['fecha_inicio'] ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Fecha Fin</label>
                        <input type="date" name="fecha_fin" id="fecha_fin" class="form-control"
                               value="<?= $contrato['fecha_fin'] ?? '' ?>">
                        <small class="text-muted">Vacío = indefinido</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Día de Pago <span class="text-danger">*</span></label>
                        <select name="dia_pago" class="form-select" required>
                            <?php for ($d = 1; $d <= 31; $d++): ?>
                                <option value="<?= $d ?>" <?= $d == $contrato['dia_pago'] ? 'selected' : '' ?>>
                                    Día <?= $d ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <!-- Estado -->
                <div class="mb-3">
                    <label class="form-label fw-semibold">Estado</label>
                    <select name="estado" class="form-select">
                        <option value="activo"    <?= $contrato['estado'] === 'activo'    ? 'selected' : '' ?>>Activo</option>
                        <option value="pausado"   <?= $contrato['estado'] === 'pausado'   ? 'selected' : '' ?>>Pausado</option>
                        <option value="cancelado" <?= $contrato['estado'] === 'cancelado' ? 'selected' : '' ?>>Cancelado</option>
                        <option value="vencido"   <?= $contrato['estado'] === 'vencido'   ? 'selected' : '' ?>>Vencido</option>
                    </select>
                </div>

                <!-- Notas -->
                <div class="mb-4">
                    <label class="form-label fw-semibold">Notas</label>
                    <textarea name="notas" class="form-control" rows="3"
                              maxlength="1000"><?= htmlspecialchars($contrato['notas'] ?? '') ?></textarea>
                </div>

                <div class="d-flex gap-2 justify-content-end">
                    <a href="contratos" class="btn btn-outline-secondary">Cancelar</a>
                    <button type="submit" class="btn btn-warning px-4">
                        <i class="fa-solid fa-floppy-disk me-1"></i> Guardar Cambios
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const clienteId        = <?= json_encode($cliente_id) ?>;
const productoIdActual = <?= json_encode((int)$contrato['producto_id']) ?>;

// ── Función reutilizable: cargar servicios de un receptor ─────────────────────
function cargarServicios(receptorId, preseleccionarId = null, resetearMonto = true) {
    const selectServicio = document.getElementById('producto_id');

    selectServicio.innerHTML = '<option value="">— Cargando... —</option>';
    selectServicio.disabled  = true;
    if (resetearMonto) document.getElementById('monto').value = '';

    if (!receptorId) {
        selectServicio.innerHTML = '<option value="">— Selecciona un cliente primero —</option>';
        return;
    }

    fetch(`../../includes/api/productos_por_receptor.php?cliente_id=${clienteId}&receptor_id=${receptorId}`)
        .then(r => r.json())
        .then(productos => {
            selectServicio.innerHTML = '<option value="">— Seleccionar servicio —</option>';

            if (productos.length === 0) {
                selectServicio.innerHTML = '<option value="">Sin servicios asignados a este cliente</option>';
                selectServicio.disabled = true;
                return;
            }

            productos.forEach(prod => {
                const opt       = document.createElement('option');
                opt.value       = prod.id;
                opt.textContent = `${prod.nombre} — L ${parseFloat(prod.precio).toFixed(2)}`;
                opt.setAttribute('data-precio', prod.precio);
                if (preseleccionarId && prod.id == preseleccionarId) {
                    opt.selected = true;
                }
                selectServicio.appendChild(opt);
            });

            selectServicio.disabled = false;

            // Actualizar monto con el servicio pre-seleccionado
            const seleccionado = selectServicio.selectedOptions[0];
            if (seleccionado?.value && seleccionado.getAttribute('data-precio')) {
                document.getElementById('monto').value =
                    parseFloat(seleccionado.getAttribute('data-precio')).toFixed(2);
            }
        })
        .catch(() => {
            selectServicio.innerHTML = '<option value="">Error al cargar servicios</option>';
            Swal.fire('Error', 'No se pudieron cargar los servicios.', 'error');
        });
}

// ── Al cargar la página: recargar lista completa del receptor actual ───────────
window.addEventListener('DOMContentLoaded', () => {
    const receptorId = document.getElementById('receptor_id')?.value;
    if (receptorId) {
        // Sin resetear monto (ya tiene el valor del contrato)
        cargarServicios(receptorId, productoIdActual, false);
    }
});

// ── Al cambiar cliente: recargar servicios (sí resetea monto) ─────────────────
document.getElementById('receptor_id')?.addEventListener('change', function () {
    cargarServicios(this.value, null, true);
});

// ── Al cambiar servicio: actualizar monto ─────────────────────────────────────
document.getElementById('producto_id')?.addEventListener('change', function () {
    const precio = this.selectedOptions[0]?.getAttribute('data-precio');
    if (precio) document.getElementById('monto').value = parseFloat(precio).toFixed(2);
});

// ── Validación de fechas ──────────────────────────────────────────────────────
document.getElementById('formEditar')?.addEventListener('submit', function (e) {
    const fi = document.querySelector('[name="fecha_inicio"]').value;
    const ff = document.getElementById('fecha_fin').value;
    if (ff && ff < fi) {
        e.preventDefault();
        Swal.fire('Fechas inválidas', 'La fecha fin no puede ser anterior a la fecha de inicio.', 'error');
    }
});
</script>

</body>
</html>