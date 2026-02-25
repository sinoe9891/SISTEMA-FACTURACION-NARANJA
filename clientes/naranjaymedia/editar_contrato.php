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

// ── Cargar servicios actuales del contrato (tabla pivote) ─────────────────────
$stmtSvcs = $pdo->prepare("
    SELECT cs.id AS pivot_id, cs.producto_id, cs.monto,
           pc.nombre AS producto_nombre
    FROM contratos_servicios cs
    LEFT JOIN productos_clientes pc ON pc.id = cs.producto_id
    WHERE cs.contrato_id = ?
    ORDER BY cs.id ASC
");
$stmtSvcs->execute([$id]);
$servicios_actuales = $stmtSvcs->fetchAll(PDO::FETCH_ASSOC);

// Si no hay servicios en pivote pero sí producto_id legacy → crear fila virtual
if (empty($servicios_actuales) && $contrato['producto_id']) {
    $stmtLegacy = $pdo->prepare("SELECT nombre FROM productos_clientes WHERE id = ?");
    $stmtLegacy->execute([$contrato['producto_id']]);
    $nomLegacy = $stmtLegacy->fetchColumn();
    $servicios_actuales = [[
        'pivot_id'        => null,
        'producto_id'     => $contrato['producto_id'],
        'monto'           => $contrato['monto'],
        'producto_nombre' => $nomLegacy ?: 'Servicio actual',
    ]];
}

// Clientes (receptores)
$stmtClientes = $pdo->prepare("
    SELECT id, nombre, rtn FROM clientes_factura
    WHERE cliente_id = ? ORDER BY nombre ASC
");
$stmtClientes->execute([$cliente_id]);
$clientes_lista = $stmtClientes->fetchAll(PDO::FETCH_ASSOC);

// Los productos se cargan por AJAX filtrados por receptor (igual que crear_contrato)
$productos_iniciales = [];

require_once '../../includes/templates/header.php';
?>

<div class="container-xxl mt-4" style="max-width:900px">

    <!-- ── Cabecera ─────────────────────────────────────────────────────────── -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <a href="contratos" class="btn btn-sm btn-outline-secondary me-2">
                <i class="fa-solid fa-arrow-left me-1"></i> Volver
            </a>
            <h4 class="d-inline-block mb-0">
                <i class="fa-solid fa-file-contract me-2 text-warning"></i>Editar Contrato
            </h4>
            <div><small class="text-muted"><?= htmlspecialchars($contrato['nombre_contrato']) ?> — ID #<?= $contrato['id'] ?></small></div>
        </div>
        <?php
        $badgeColor = [
            'activo'    => 'success',
            'pausado'   => 'warning',
            'cancelado' => 'danger',
            'vencido'   => 'secondary',
        ];
        ?>
        <span class="badge bg-<?= $badgeColor[$contrato['estado']] ?? 'secondary' ?> fs-6 px-3 py-2">
            <?= ucfirst($contrato['estado']) ?>
        </span>
    </div>

    <form id="formEditar" novalidate>
        <input type="hidden" name="id" value="<?= $contrato['id'] ?>">

        <!-- ── Sección 1: Cliente e info ─────────────────────────────────────── -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-bottom py-3">
                <h6 class="mb-0 fw-bold">
                    <i class="fa-solid fa-user me-2 text-warning"></i>Información del Cliente
                </h6>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-md-7">
                        <label class="form-label fw-semibold">Cliente <span class="text-danger">*</span></label>
                        <select name="receptor_id" id="receptor_id" class="form-select form-select-lg" required>
                            <option value="">— Seleccionar —</option>
                            <?php foreach ($clientes_lista as $cl): ?>
                                <option value="<?= $cl['id'] ?>"
                                    <?= $cl['id'] == $contrato['receptor_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cl['nombre']) ?>
                                    <?= $cl['rtn'] ? ' · RTN: '.htmlspecialchars($cl['rtn']) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label fw-semibold">Nombre del Contrato <span class="text-danger">*</span></label>
                        <input type="text" name="nombre_contrato" class="form-control form-control-lg"
                               value="<?= htmlspecialchars($contrato['nombre_contrato']) ?>"
                               maxlength="200" required>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Sección 2: Servicios ──────────────────────────────────────────── -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
                <h6 class="mb-0 fw-bold">
                    <i class="fa-solid fa-box me-2 text-warning"></i>Servicios del Contrato
                </h6>
                <button type="button" class="btn btn-sm btn-outline-warning" id="btnAgregarServicio">
                    <i class="fa-solid fa-plus me-1"></i> Agregar servicio
                </button>
            </div>
            <div class="card-body p-4">
                <div id="contenedorServicios">
                    <?php foreach ($servicios_actuales as $idx => $svc): ?>
                    <div class="servicio-item border rounded-3 p-3 mb-3 bg-light position-relative">
                        <button type="button"
                                class="btn btn-sm btn-outline-danger position-absolute top-0 end-0 m-2 btn-quitar-servicio"
                                title="Quitar servicio">
                            <i class="fa-solid fa-times"></i>
                        </button>
                        <div class="row g-2 align-items-end">
                            <div class="col-md-7">
                                <label class="form-label fw-semibold small mb-1">Servicio</label>
                                <select name="servicios[<?= $idx ?>][producto_id]"
                                        class="form-select select-servicio" required>
                                    <option value="">— Cargando servicios... —</option>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label fw-semibold small mb-1">Monto mensual (L)</label>
                                <div class="input-group">
                                    <span class="input-group-text">L</span>
                                    <input type="number" name="servicios[<?= $idx ?>][monto]"
                                           class="form-control input-monto"
                                           value="<?= number_format((float)$svc['monto'], 2, '.', '') ?>"
                                           min="0" step="0.01" placeholder="0.00" required>
                                </div>
                                <small class="text-muted precio-sugerido">
                                    <?php if ((float)$svc['monto'] > 0): ?>
                                        Precio actual: L <?= number_format((float)$svc['monto'], 2) ?>
                                    <?php endif; ?>
                                </small>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Resumen total -->
                <div id="resumenTotal" class="mt-3 pt-3 border-top">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-semibold text-muted">Monto mensual total del contrato:</span>
                        <span class="fs-4 fw-bold text-warning" id="totalMonto">L 0.00</span>
                    </div>
                </div>
                <input type="hidden" name="monto_total" id="monto_total" value="0">
            </div>
        </div>

        <!-- ── Sección 3: Vigencia y Estado ──────────────────────────────────── -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-bottom py-3">
                <h6 class="mb-0 fw-bold">
                    <i class="fa-solid fa-calendar me-2 text-warning"></i>Vigencia, Pago y Estado
                </h6>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Fecha Inicio <span class="text-danger">*</span></label>
                        <input type="date" name="fecha_inicio" class="form-control"
                               value="<?= $contrato['fecha_inicio'] ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Fecha Fin</label>
                        <input type="date" name="fecha_fin" id="fecha_fin" class="form-control"
                               value="<?= $contrato['fecha_fin'] ?? '' ?>">
                        <small class="text-muted">Vacío = indefinido</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Día de Cobro <span class="text-danger">*</span></label>
                        <select name="dia_pago" class="form-select" required>
                            <?php for ($d = 1; $d <= 31; $d++): ?>
                                <option value="<?= $d ?>" <?= $d == $contrato['dia_pago'] ? 'selected' : '' ?>>
                                    Día <?= $d ?> de cada mes
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Estado</label>
                        <select name="estado" class="form-select">
                            <option value="activo"    <?= $contrato['estado'] === 'activo'    ? 'selected' : '' ?>>✅ Activo</option>
                            <option value="pausado"   <?= $contrato['estado'] === 'pausado'   ? 'selected' : '' ?>>⏸️ Pausado</option>
                            <option value="cancelado" <?= $contrato['estado'] === 'cancelado' ? 'selected' : '' ?>>❌ Cancelado</option>
                            <option value="vencido"   <?= $contrato['estado'] === 'vencido'   ? 'selected' : '' ?>>⌛ Vencido</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Sección 4: Notas ──────────────────────────────────────────────── -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-bottom py-3">
                <h6 class="mb-0 fw-bold">
                    <i class="fa-solid fa-note-sticky me-2 text-secondary"></i>Notas (opcional)
                </h6>
            </div>
            <div class="card-body p-4">
                <textarea name="notas" class="form-control" rows="3"
                          placeholder="Condiciones especiales, acuerdos adicionales..."
                          maxlength="1000"><?= htmlspecialchars($contrato['notas'] ?? '') ?></textarea>
            </div>
        </div>

        <!-- ── Botones ───────────────────────────────────────────────────────── -->
        <div class="d-flex gap-2 justify-content-end mb-5">
            <a href="contratos" class="btn btn-outline-secondary px-4">Cancelar</a>
            <button type="submit" class="btn btn-warning px-5" id="btnGuardar">
                <i class="fa-solid fa-floppy-disk me-1"></i> Guardar Cambios
            </button>
        </div>
    </form>
</div>

<!-- Template de fila de servicio nueva (oculto) -->
<template id="templateServicio">
    <div class="servicio-item border rounded-3 p-3 mb-3 bg-light position-relative">
        <button type="button"
                class="btn btn-sm btn-outline-danger position-absolute top-0 end-0 m-2 btn-quitar-servicio"
                title="Quitar servicio">
            <i class="fa-solid fa-times"></i>
        </button>
        <div class="row g-2 align-items-end">
            <div class="col-md-7">
                <label class="form-label fw-semibold small mb-1">Servicio</label>
                <select name="servicios[][producto_id]" class="form-select select-servicio" required>
                    <option value="">— Seleccionar servicio —</option>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label fw-semibold small mb-1">Monto mensual (L)</label>
                <div class="input-group">
                    <span class="input-group-text">L</span>
                    <input type="number" name="servicios[][monto]"
                           class="form-control input-monto"
                           min="0" step="0.01" placeholder="0.00" required>
                </div>
                <small class="text-muted precio-sugerido"></small>
            </div>
        </div>
    </div>
</template>

<script>
const CLIENTE_ID_EDIT   = <?= json_encode($cliente_id) ?>;
const RECEPTOR_ID_INIT  = <?= json_encode((int)$contrato['receptor_id']) ?>;
const SERVICIOS_ACTUALES = <?= json_encode(array_column($servicios_actuales, 'producto_id')) ?>;

let productosDisponibles = [];
let servicioIndexEdit    = <?= count($servicios_actuales) ?>;

// ── Al cargar la página: pedir productos del receptor correcto ────────────────
document.addEventListener('DOMContentLoaded', () => {
    if (!RECEPTOR_ID_INIT) return;
    cargarProductosPorReceptor(RECEPTOR_ID_INIT, true);
});

// ── Función central: carga productos y llena todos los selects ────────────────
function cargarProductosPorReceptor(receptorId, esInicial = false) {
    fetch(`../../includes/api/productos_por_receptor.php?cliente_id=${CLIENTE_ID_EDIT}&receptor_id=${receptorId}`)
        .then(r => r.json())
        .then(prods => {
            productosDisponibles = prods;
            document.querySelectorAll('.select-servicio').forEach((sel, i) => {
                // Al cargar por primera vez, pre-seleccionar el producto guardado
                const preseleccionar = esInicial ? (SERVICIOS_ACTUALES[i] ?? null) : null;
                poblarSelect(sel, prods, preseleccionar);
            });
            recalcularTotal();
            adjuntarEventosFilas();
        })
        .catch(() => Swal.fire('Error', 'No se pudieron cargar los servicios.', 'error'));
}

function poblarSelect(sel, prods, preseleccionarId = null) {
    const valorActual = preseleccionarId ?? sel.value;
    sel.innerHTML = '<option value="">— Seleccionar servicio —</option>';
    prods.forEach(prod => {
        const opt = document.createElement('option');
        opt.value = prod.id;
        opt.textContent = prod.nombre + (parseFloat(prod.precio) > 0 ? ` — L${parseFloat(prod.precio).toFixed(2)}` : '');
        opt.setAttribute('data-precio', prod.precio);
        if (prod.id == valorActual) opt.selected = true;
        sel.appendChild(opt);
    });
}

// ── Al cambiar receptor: recargar productos del nuevo receptor ────────────────
document.getElementById('receptor_id').addEventListener('change', function () {
    if (!this.value) return;
    cargarProductosPorReceptor(this.value, false);
});

// ── Agregar fila de servicio ──────────────────────────────────────────────────
document.getElementById('btnAgregarServicio').addEventListener('click', () => {
    if (productosDisponibles.length === 0) {
        return Swal.fire('Aviso', 'No hay servicios disponibles.', 'warning');
    }

    const template = document.getElementById('templateServicio');
    const clone    = template.content.cloneNode(true);
    const fila     = clone.querySelector('.servicio-item');
    const select   = fila.querySelector('.select-servicio');
    const idx      = servicioIndexEdit++;

    // Renombrar con índice único
    fila.querySelectorAll('[name]').forEach(el => {
        el.name = el.name.replace('[]', `[${idx}]`);
    });

    // Llenar opciones
    productosDisponibles.forEach(prod => {
        const opt = document.createElement('option');
        opt.value = prod.id;
        opt.textContent = prod.nombre + (parseFloat(prod.precio) > 0 ? ` — L${parseFloat(prod.precio).toFixed(2)}` : '');
        opt.setAttribute('data-precio', prod.precio);
        select.appendChild(opt);
    });

    adjuntarEventosFila(fila);
    document.getElementById('contenedorServicios').appendChild(fila);
    recalcularTotal();
});

// ── Adjuntar eventos a filas existentes (al cargar) ──────────────────────────
function adjuntarEventosFilas() {
    document.querySelectorAll('.servicio-item').forEach(fila => adjuntarEventosFila(fila));
}

function adjuntarEventosFila(fila) {
    const sel   = fila.querySelector('.select-servicio');
    const input = fila.querySelector('.input-monto');
    const btn   = fila.querySelector('.btn-quitar-servicio');

    sel?.addEventListener('change', function () {
        const precio = parseFloat(this.selectedOptions[0]?.getAttribute('data-precio')) || 0;
        const sugerido = this.closest('.servicio-item').querySelector('.precio-sugerido');
        if (precio > 0) {
            input.value = precio.toFixed(2);
            if (sugerido) sugerido.textContent = `Precio base: L ${precio.toFixed(2)}`;
        }
        recalcularTotal();
    });

    input?.addEventListener('input', recalcularTotal);

    btn?.addEventListener('click', function () {
        if (document.querySelectorAll('.servicio-item').length > 1) {
            this.closest('.servicio-item').remove();
            recalcularTotal();
        } else {
            Swal.fire('Aviso', 'El contrato debe tener al menos un servicio.', 'warning');
        }
    });
}

function recalcularTotal() {
    let total = 0;
    document.querySelectorAll('.input-monto').forEach(inp => total += parseFloat(inp.value) || 0);
    document.getElementById('monto_total').value = total.toFixed(2);
    document.getElementById('totalMonto').textContent = 'L ' + total.toLocaleString('es-HN', {
        minimumFractionDigits: 2, maximumFractionDigits: 2
    });
}

// ── Submit AJAX ───────────────────────────────────────────────────────────────
document.getElementById('formEditar').addEventListener('submit', function (e) {
    e.preventDefault();

    if (!document.querySelector('[name="receptor_id"]').value)
        return Swal.fire({ icon: 'warning', title: 'Falta el cliente', text: 'Selecciona un cliente.' });
    if (!document.querySelector('[name="nombre_contrato"]').value.trim())
        return Swal.fire({ icon: 'warning', title: 'Nombre requerido', text: 'Escribe un nombre para el contrato.' });

    const filas = document.querySelectorAll('.servicio-item');
    if (filas.length === 0)
        return Swal.fire({ icon: 'warning', title: 'Sin servicios', text: 'Agrega al menos un servicio.' });

    let totalFinal = 0, invalido = false;
    filas.forEach(f => {
        const prod  = f.querySelector('.select-servicio').value;
        const monto = parseFloat(f.querySelector('.input-monto').value) || 0;
        if (!prod || monto <= 0) invalido = true;
        totalFinal += monto;
    });
    if (invalido)
        return Swal.fire({ icon: 'warning', title: 'Datos incompletos', text: 'Todos los servicios deben tener producto y monto.' });

    const fi = document.querySelector('[name="fecha_inicio"]').value;
    const ff = document.getElementById('fecha_fin').value;
    if (!fi) return Swal.fire({ icon: 'warning', title: 'Fecha requerida', text: 'La fecha de inicio es obligatoria.' });
    if (ff && ff < fi) return Swal.fire({ icon: 'error', title: 'Fechas inválidas', text: 'La fecha fin no puede ser anterior al inicio.' });

    document.getElementById('monto_total').value = totalFinal.toFixed(2);

    const btn = document.getElementById('btnGuardar');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Guardando...';

    fetch('includes/contrato_actualizar.php', { method: 'POST', body: new FormData(this) })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                Swal.fire({ icon: 'success', title: '¡Cambios guardados!', text: 'El contrato fue actualizado.', confirmButtonText: 'Ver contratos' })
                    .then(() => window.location.href = 'contratos');
            } else {
                Swal.fire({ icon: 'error', title: 'Error al guardar', text: data.error || 'Error inesperado.' });
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i> Guardar Cambios';
            }
        })
        .catch(() => {
            Swal.fire({ icon: 'error', title: 'Error de conexión', text: 'No se pudo contactar el servidor.' });
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i> Guardar Cambios';
        });
});
</script>

</body>
</html>