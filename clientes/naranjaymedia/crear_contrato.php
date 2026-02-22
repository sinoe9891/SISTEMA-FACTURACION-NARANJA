<?php
$titulo = 'Nuevo Contrato';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';
require_once '../../includes/templates/header.php';

$cliente_id = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);

// Clientes (receptores)
$stmtClientes = $pdo->prepare("
    SELECT id, nombre, rtn FROM clientes_factura
    WHERE cliente_id = ? ORDER BY nombre ASC
");
$stmtClientes->execute([$cliente_id]);
$clientes_lista = $stmtClientes->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container-xxl mt-4" style="max-width:900px">

    <!-- Cabecera -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <a href="contratos" class="btn btn-sm btn-outline-secondary me-2">
                <i class="fa-solid fa-arrow-left me-1"></i> Volver
            </a>
            <h4 class="d-inline-block mb-0">
                <i class="fa-solid fa-file-contract me-2 text-primary"></i>Nuevo Contrato
            </h4>
            <div><small class="text-muted">Complete la información del contrato de servicio</small></div>
        </div>
    </div>

    <?php if (empty($clientes_lista)): ?>
        <div class="alert alert-warning">
            <i class="fa-solid fa-triangle-exclamation me-2"></i>
            No tienes clientes registrados. <a href="crear_cliente">Agregar cliente primero</a>.
        </div>
    <?php else: ?>

    <form id="formContrato" method="POST" action="includes/contrato_guardar.php" novalidate>

        <!-- ── Sección 1: Cliente ────────────────────────────────────────────── -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-bottom py-3">
                <h6 class="mb-0 fw-bold">
                    <i class="fa-solid fa-user me-2 text-primary"></i>Información del Cliente
                </h6>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">
                            Cliente <span class="text-danger">*</span>
                        </label>
                        <select name="receptor_id" id="receptor_id" class="form-select form-select-lg" required>
                            <option value="">— Seleccionar cliente —</option>
                            <?php foreach ($clientes_lista as $cl): ?>
                                <option value="<?= $cl['id'] ?>">
                                    <?= htmlspecialchars($cl['nombre']) ?>
                                    <?= $cl['rtn'] ? ' · RTN: ' . htmlspecialchars($cl['rtn']) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">
                            Nombre del Contrato <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="nombre_contrato" class="form-control form-control-lg"
                               placeholder="Ej: Gestión Digital 2026"
                               maxlength="200" required>
                    </div>
                </div>

                <!-- Aviso si ya tiene contratos activos -->
                <div id="avisoContrato" class="alert alert-warning py-2 mt-3 d-none small mb-0">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i>
                    <span id="textoAvisoContrato"></span>
                </div>
            </div>
        </div>

        <!-- ── Sección 2: Servicios ──────────────────────────────────────────── -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
                <h6 class="mb-0 fw-bold">
                    <i class="fa-solid fa-box me-2 text-primary"></i>Servicios del Contrato
                </h6>
                <button type="button" class="btn btn-sm btn-outline-primary" id="btnAgregarServicio">
                    <i class="fa-solid fa-plus me-1"></i> Agregar servicio
                </button>
            </div>
            <div class="card-body p-4">

                <div id="avisoSinCliente" class="text-center py-4 text-muted">
                    <i class="fa-solid fa-arrow-up fa-lg mb-2 d-block"></i>
                    Primero selecciona un cliente para cargar sus servicios disponibles.
                </div>

                <div id="contenedorServicios" class="d-none">
                    <!-- Los items de servicio se agregan aquí -->
                </div>

                <!-- Resumen total -->
                <div id="resumenTotal" class="d-none mt-3 pt-3 border-top">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-semibold text-muted">Monto mensual total del contrato:</span>
                        <span class="fs-4 fw-bold text-primary" id="totalMonto">L 0.00</span>
                    </div>
                </div>

                <input type="hidden" name="monto_total" id="monto_total" value="0">
            </div>
        </div>

        <!-- ── Sección 3: Vigencia ────────────────────────────────────────────── -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-bottom py-3">
                <h6 class="mb-0 fw-bold">
                    <i class="fa-solid fa-calendar me-2 text-primary"></i>Vigencia y Pago
                </h6>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">
                            Fecha Inicio <span class="text-danger">*</span>
                        </label>
                        <input type="date" name="fecha_inicio" class="form-control"
                               value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Fecha Fin</label>
                        <input type="date" name="fecha_fin" id="fecha_fin" class="form-control">
                        <small class="text-muted">Vacío = contrato indefinido</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">
                            Día de Cobro <span class="text-danger">*</span>
                        </label>
                        <select name="dia_pago" class="form-select" required>
                            <?php for ($d = 1; $d <= 31; $d++): ?>
                                <option value="<?= $d ?>" <?= $d === 1 ? 'selected' : '' ?>>
                                    Día <?= $d ?> de cada mes
                                </option>
                            <?php endfor; ?>
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
                          maxlength="1000"></textarea>
            </div>
        </div>

        <!-- ── Botones ───────────────────────────────────────────────────────── -->
        <div class="d-flex gap-2 justify-content-end mb-5">
            <a href="contratos" class="btn btn-outline-secondary px-4">Cancelar</a>
            <button type="submit" class="btn btn-primary px-5">
                <i class="fa-solid fa-floppy-disk me-1"></i> Guardar Contrato
            </button>
        </div>

    </form>
    <?php endif; ?>
</div>

<!-- Template de fila de servicio (oculto) -->
<template id="templateServicio">
    <div class="servicio-item border rounded-3 p-3 mb-3 bg-light position-relative">
        <button type="button" class="btn btn-sm btn-outline-danger position-absolute top-0 end-0 m-2 btn-quitar-servicio"
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
                    <input type="number" name="servicios[][monto]" class="form-control input-monto"
                           min="0" step="0.01" placeholder="0.00" required>
                </div>
                <small class="text-muted precio-sugerido"></small>
            </div>
        </div>
    </div>
</template>

<script>
const CLIENTE_ID_CONTRATO = <?= json_encode($cliente_id) ?>;
let productosDisponibles = [];
let servicioIndex = 0;

// ── Al cambiar receptor: cargar sus productos por AJAX ────────────────────────
document.getElementById('receptor_id').addEventListener('change', function () {
    const receptorId = this.value;
    const aviso      = document.getElementById('avisoContrato');
    const texto      = document.getElementById('textoAvisoContrato');
    const sinCliente = document.getElementById('avisoSinCliente');
    const contenedor = document.getElementById('contenedorServicios');
    const resumen    = document.getElementById('resumenTotal');

    aviso.classList.add('d-none');
    contenedor.innerHTML = '';
    contenedor.classList.add('d-none');
    resumen.classList.add('d-none');
    document.getElementById('monto_total').value = 0;
    document.getElementById('totalMonto').textContent = 'L 0.00';
    productosDisponibles = [];
    servicioIndex = 0;

    if (!receptorId) {
        sinCliente.classList.remove('d-none');
        return;
    }
    sinCliente.classList.add('d-none');

    // Cargar productos del receptor
    fetch(`../../includes/api/productos_por_receptor.php?cliente_id=${CLIENTE_ID_CONTRATO}&receptor_id=${receptorId}`)
        .then(r => r.json())
        .then(prods => {
            productosDisponibles = prods;
            contenedor.classList.remove('d-none');
            resumen.classList.remove('d-none');
            // Agregar el primer servicio por defecto
            agregarFilaServicio();
        })
        .catch(() => Swal.fire('Error', 'No se pudieron cargar los servicios.', 'error'));

    // Verificar contratos existentes
    fetch(`includes/contrato_verificar.php?receptor_id=${receptorId}`)
        .then(r => r.json())
        .then(res => {
            if (res.tiene_activo) {
                texto.textContent = `Este cliente ya tiene ${res.cantidad} contrato(s) activo(s). Verifica antes de crear uno nuevo.`;
                aviso.classList.remove('d-none');
            }
        }).catch(() => {});
});

// ── Agregar fila de servicio ──────────────────────────────────────────────────
document.getElementById('btnAgregarServicio').addEventListener('click', agregarFilaServicio);

function agregarFilaServicio() {
    if (productosDisponibles.length === 0) {
        Swal.fire('Aviso', 'Primero selecciona un cliente.', 'warning');
        return;
    }

    const template = document.getElementById('templateServicio');
    const clone    = template.content.cloneNode(true);
    const fila     = clone.querySelector('.servicio-item');
    const select   = fila.querySelector('.select-servicio');

    // Actualizar índice en names
    const idx = servicioIndex++;
    fila.querySelectorAll('[name]').forEach(el => {
        el.name = el.name.replace('[]', `[${idx}]`);
    });

    // Llenar opciones
    productosDisponibles.forEach(prod => {
        const opt = document.createElement('option');
        opt.value = prod.id;
        opt.textContent = prod.nombre;
        opt.setAttribute('data-precio', prod.precio);
        select.appendChild(opt);
    });

    // Al seleccionar producto → pre-llenar precio
    select.addEventListener('change', function () {
        const precio = parseFloat(this.selectedOptions[0]?.getAttribute('data-precio')) || 0;
        const inputMonto = this.closest('.servicio-item').querySelector('.input-monto');
        const sugerido   = this.closest('.servicio-item').querySelector('.precio-sugerido');
        if (precio > 0) {
            inputMonto.value = precio.toFixed(2);
            sugerido.textContent = `Precio base: L ${precio.toFixed(2)}`;
        }
        recalcularTotal();
    });

    // Al cambiar monto → recalcular
    fila.querySelector('.input-monto').addEventListener('input', recalcularTotal);

    // Quitar fila
    fila.querySelector('.btn-quitar-servicio').addEventListener('click', function () {
        if (document.querySelectorAll('.servicio-item').length > 1) {
            this.closest('.servicio-item').remove();
            recalcularTotal();
        } else {
            Swal.fire('Aviso', 'El contrato debe tener al menos un servicio.', 'warning');
        }
    });

    document.getElementById('contenedorServicios').appendChild(fila);
}

function recalcularTotal() {
    let total = 0;
    document.querySelectorAll('.input-monto').forEach(inp => {
        total += parseFloat(inp.value) || 0;
    });
    document.getElementById('monto_total').value = total.toFixed(2);
    document.getElementById('totalMonto').textContent = 'L ' + total.toLocaleString('es-HN', {
        minimumFractionDigits: 2, maximumFractionDigits: 2
    });
}

// ── Submit con AJAX + SweetAlert ─────────────────────────────────────────────
document.getElementById('formContrato').addEventListener('submit', function (e) {
    e.preventDefault();

    // ── Validaciones en cliente ──────────────────────────────────────────────
    if (!document.getElementById('receptor_id').value) {
        return Swal.fire({ icon: 'warning', title: 'Falta el cliente', text: 'Selecciona un cliente antes de guardar.' });
    }
    if (!document.querySelector('[name="nombre_contrato"]').value.trim()) {
        return Swal.fire({ icon: 'warning', title: 'Nombre requerido', text: 'Escribe un nombre o referencia para el contrato.' });
    }

    const filas = document.querySelectorAll('.servicio-item');
    if (filas.length === 0) {
        return Swal.fire({ icon: 'warning', title: 'Sin servicios', text: 'Agrega al menos un servicio al contrato.' });
    }

    let totalFinal = 0;
    let servicioInvalido = false;
    filas.forEach(fila => {
        const prod  = fila.querySelector('.select-servicio').value;
        const monto = parseFloat(fila.querySelector('.input-monto').value) || 0;
        if (!prod || monto <= 0) servicioInvalido = true;
        totalFinal += monto;
    });
    if (servicioInvalido) {
        return Swal.fire({ icon: 'warning', title: 'Datos incompletos', text: 'Todos los servicios deben tener producto seleccionado y monto mayor a 0.' });
    }

    const fi = document.querySelector('[name="fecha_inicio"]').value;
    const ff = document.getElementById('fecha_fin').value;
    if (!fi) {
        return Swal.fire({ icon: 'warning', title: 'Fecha requerida', text: 'La fecha de inicio es obligatoria.' });
    }
    if (ff && ff < fi) {
        return Swal.fire({ icon: 'error', title: 'Fechas inválidas', text: 'La fecha de fin no puede ser anterior a la fecha de inicio.' });
    }

    document.getElementById('monto_total').value = totalFinal.toFixed(2);

    // ── Enviar por AJAX ──────────────────────────────────────────────────────
    const btn = this.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Guardando...';

    fetch(this.action, { method: 'POST', body: new FormData(this) })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                Swal.fire({
                    icon: 'success',
                    title: '¡Contrato guardado!',
                    text: 'El contrato fue creado correctamente.',
                    confirmButtonText: 'Ver contratos'
                }).then(() => {
                    window.location.href = 'contratos';
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Error al guardar',
                    text: data.error || 'Ocurrió un error inesperado.'
                });
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i> Guardar Contrato';
            }
        })
        .catch(() => {
            Swal.fire({ icon: 'error', title: 'Error de conexión', text: 'No se pudo contactar al servidor. Intenta de nuevo.' });
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i> Guardar Contrato';
        });
});
</script>

</body>
</html>