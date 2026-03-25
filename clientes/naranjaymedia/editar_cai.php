<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

// ── Validar ID ────────────────────────────────────────────────────────────────
if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) {
    die("ID de CAI inválido.");
}

$cai_id     = (int)$_GET['id'];
$usuario_id = $_SESSION['usuario_id'];

// ── Obtener datos del usuario / cliente ───────────────────────────────────────
$stmtUser = $pdo->prepare("
    SELECT u.rol, c.id AS cliente_id, c.nombre AS cliente_nombre, c.logo_url
    FROM usuarios u
    INNER JOIN clientes_saas c ON u.cliente_id = c.id
    WHERE u.id = ?
");
$stmtUser->execute([$usuario_id]);
$datos = $stmtUser->fetch();

if (!$datos) {
    die("Usuario inválido.");
}

$cliente_id = $datos['cliente_id'];
$rol        = $datos['rol'];

// Solo admins pueden editar CAI
if (!in_array($rol, ['admin', 'superadmin'])) {
    die("No tienes permisos para editar CAI.");
}

// ── Cargar el CAI (validando que pertenezca al cliente) ───────────────────────
// Solo se consulta cai_rangos para evitar errores por diferencias en columnas de otras tablas
if ($rol === 'superadmin') {
    $stmtCAI = $pdo->prepare("SELECT * FROM cai_rangos WHERE id = ?");
    $stmtCAI->execute([$cai_id]);
} else {
    $stmtCAI = $pdo->prepare("SELECT * FROM cai_rangos WHERE id = ? AND cliente_id = ?");
    $stmtCAI->execute([$cai_id, $cliente_id]);
}

$cai = $stmtCAI->fetch();

if (!$cai) {
    die("CAI no encontrado o no autorizado.");
}

// ── Cargar establecimientos del cliente (para el selector) ────────────────────
$stmtEstab = $pdo->prepare("
    SELECT establecimiento_id AS id, nombre
    FROM establecimientos
    WHERE cliente_id = ?
    ORDER BY nombre ASC
");
$stmtEstab->execute([$cliente_id]);
$establecimientos = $stmtEstab->fetchAll();

// ── Cargar puntos de emisión del establecimiento actual ───────────────────────
$stmtPuntos = $pdo->prepare("
    SELECT *
    FROM puntos_emision
    WHERE establecimiento_id = ?
");
$stmtPuntos->execute([$cai['establecimiento_id']]);
$puntos_emision = $stmtPuntos->fetchAll();

// ── Mensaje de éxito tras guardar (POST/redirect/GET) ─────────────────────────
$guardado_ok = isset($_GET['guardado']) && $_GET['guardado'] === '1';

require_once '../../includes/templates/header.php';
?>

<style>
    .badge-activo {
        background-color: #198754;
    }

    .badge-vencido {
        background-color: #dc3545;
    }

    .cai-display {
        font-family: 'Courier New', monospace;
        font-size: 13px;
        letter-spacing: 0.5px;
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 6px;
        padding: 8px 12px;
        color: #333;
    }

    .section-icon {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
        margin-right: 8px;
    }

    .field-readonly {
        background-color: #f8f9fa !important;
        cursor: not-allowed;
        color: #6c757d;
    }

    .card-header-custom {
        background: #fff;
        border-bottom: 1px solid #e9ecef;
        padding: 1rem 1.25rem;
    }

    .info-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #fff3e0;
        color: #b05000;
        border: 1px solid #f5c07a;
        border-radius: 20px;
        padding: 3px 12px;
        font-size: 12px;
        font-weight: 600;
    }

    .facturas-counter {
        background: linear-gradient(135deg, #e36f1f 0%, #f5a623 100%);
        color: white;
        border-radius: 12px;
        padding: 16px 20px;
    }
</style>

<div class="container mt-4 mb-5">

    <!-- ── Cabecera ──────────────────────────────────────────────────────────── -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <a href="configuracion_cai" class="btn btn-sm btn-outline-secondary me-2">
                <i class="fa-solid fa-arrow-left me-1"></i> Volver
            </a>
            <h4 class="d-inline-block mb-0">
                <i class="fa-solid fa-key me-2 text-primary"></i>Editar CAI
            </h4>
            <div class="mt-1">
                <small class="text-muted">
                    Modifica los datos del CAI y su rango de autorización.
                </small>
                <?php
                $hoy    = new DateTime();
                $limite = new DateTime($cai['fecha_limite']);
                $activo = $hoy <= $limite;
                ?>
                <span class="badge ms-2 <?= $activo ? 'badge-activo' : 'badge-vencido' ?>">
                    <?= $activo ? '✅ Activo' : '⛔ Vencido' ?>
                </span>
            </div>
        </div>
        <?php if (!empty($datos['logo_url'])): ?>
            <img src="<?= htmlspecialchars($datos['logo_url']) ?>" alt="Logo" style="max-height: 55px;">
        <?php endif; ?>
    </div>

    <!-- ── Alerta guardado exitoso ────────────────────────────────────────────── -->
    <?php if ($guardado_ok): ?>
        <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
            <i class="fa-solid fa-circle-check fa-lg"></i>
            <div><strong>¡CAI actualizado correctamente!</strong> Los cambios han sido guardados.</div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- ── Resumen rápido ─────────────────────────────────────────────────────── -->
    <?php
    $total_facturas   = (int)$cai['rango_fin'] - (int)$cai['rango_inicio'] + 1;
    $usadas           = (int)$cai['correlativo_actual'];
    $restantes        = $total_facturas - $usadas;
    $porcentaje_usado = $total_facturas > 0 ? round(($usadas / $total_facturas) * 100) : 0;
    ?>
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="section-icon bg-primary bg-opacity-10 text-primary">
                        <i class="fa-solid fa-hashtag"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Total facturas</div>
                        <div class="fw-bold fs-5"><?= number_format($total_facturas) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="section-icon bg-success bg-opacity-10 text-success">
                        <i class="fa-solid fa-check-circle"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Emitidas</div>
                        <div class="fw-bold fs-5 text-success"><?= number_format($usadas) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="section-icon bg-warning bg-opacity-10 text-warning">
                        <i class="fa-solid fa-clock"></i>
                    </div>
                    <div>
                        <div class="text-muted small">Restantes</div>
                        <div class="fw-bold fs-5 <?= $restantes <= 20 ? 'text-danger' : 'text-warning' ?>">
                            <?= number_format($restantes) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small mb-1">Uso del rango</div>
                    <div class="fw-bold mb-1"><?= $porcentaje_usado ?>%</div>
                    <div class="progress" style="height:8px;">
                        <div class="progress-bar <?= $porcentaje_usado >= 90 ? 'bg-danger' : ($porcentaje_usado >= 70 ? 'bg-warning' : 'bg-success') ?>"
                            style="width: <?= $porcentaje_usado ?>%"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Formulario ─────────────────────────────────────────────────────────── -->
    <form id="formEditarCAI">
        <input type="hidden" name="id" value="<?= $cai['id'] ?>">

        <!-- 1. Datos del CAI ──────────────────────────────────────────────────── -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header-custom d-flex align-items-center">
                <span class="section-icon bg-primary bg-opacity-10 text-primary">
                    <i class="fa-solid fa-key"></i>
                </span>
                <h6 class="mb-0 fw-bold">Datos del CAI</h6>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">

                    <div class="col-12">
                        <label class="form-label fw-semibold">
                            Código CAI <span class="text-danger">*</span>
                            <span class="info-pill ms-2">
                                <i class="fa-solid fa-triangle-exclamation fa-xs"></i>
                                Emitido por el SAR
                            </span>
                        </label>
                        <input type="text" name="cai" class="form-control font-monospace text-uppercase"
                            value="<?= htmlspecialchars($cai['cai']) ?>"
                            placeholder="Ej: D0708E-EE616A-A54EE0-63BE03-090930-0B1100" maxlength="40" required>
                        <div class="form-text">Formato: 6 grupos de 6 caracteres separados por guiones.</div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Fecha de recepción <span class="text-danger">*</span>
                        </label>
                        <input type="date" name="fecha_recepcion" class="form-control"
                            value="<?= htmlspecialchars($cai['fecha_recepcion']) ?>" required>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Fecha límite de emisión <span class="text-danger">*</span>
                        </label>
                        <input type="date" name="fecha_limite" class="form-control"
                            value="<?= htmlspecialchars($cai['fecha_limite']) ?>" required>
                        <?php if (!$activo): ?>
                            <div class="form-text text-danger">
                                <i class="fa-solid fa-circle-exclamation me-1"></i>
                                Este CAI está vencido. Actualiza la fecha si corresponde.
                            </div>
                        <?php endif; ?>
                    </div>

                </div>
            </div>
        </div>

        <!-- 2. Establecimiento y Punto de Emisión ────────────────────────────── -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header-custom d-flex align-items-center">
                <span class="section-icon bg-secondary bg-opacity-10 text-secondary">
                    <i class="fa-solid fa-building"></i>
                </span>
                <h6 class="mb-0 fw-bold">Establecimiento y Punto de Emisión</h6>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Establecimiento</label>
                        <select name="establecimiento_id" id="establecimiento_id" class="form-select" required>
                            <?php foreach ($establecimientos as $est): ?>
                                <option value="<?= $est['id'] ?>"
                                    <?= $est['id'] == $cai['establecimiento_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($est['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Punto de Emisión</label>
                        <select name="punto_emision_id" id="punto_emision_id" class="form-select" required>
                            <option value="">— Cargando... —</option>
                            <?php foreach ($puntos_emision as $pe): ?>
                                <option value="<?= $pe['id'] ?>"
                                    <?= $pe['id'] == $cai['punto_emision_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($pe['codigo_punto']) ?>
                                    <?= !empty($pe['descripcion']) ? ' — ' . htmlspecialchars($pe['descripcion']) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                </div>
            </div>
        </div>

        <!-- 3. Rango de Correlativo ──────────────────────────────────────────── -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header-custom d-flex align-items-center">
                <span class="section-icon bg-success bg-opacity-10 text-success">
                    <i class="fa-solid fa-list-ol"></i>
                </span>
                <h6 class="mb-0 fw-bold">Rango de Correlativo</h6>
                <small class="text-muted ms-2">Números autorizados por el SAR</small>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Rango inicio <span class="text-danger">*</span>
                        </label>
                        <input type="number" name="rango_inicio" class="form-control" min="1"
                            value="<?= htmlspecialchars($cai['rango_inicio']) ?>" required>
                        <div class="form-text">Número de inicio del rango autorizado.</div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Rango fin <span class="text-danger">*</span>
                        </label>
                        <input type="number" name="rango_fin" class="form-control" min="1"
                            value="<?= htmlspecialchars($cai['rango_fin']) ?>" required>
                        <div class="form-text">Número final del rango autorizado.</div>
                    </div>

                    <!-- Correlativos CAI completos (formato SAR) -->
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Correlativo CAI inicio (formato SAR)
                        </label>
                        <input type="text" name="rango_cai_inicio" class="form-control font-monospace text-uppercase"
                            value="<?= htmlspecialchars($cai['rango_cai_inicio']) ?>"
                            placeholder="Ej: 000-002-01-00000101" maxlength="25">
                        <div class="form-text">Correlativo completo con prefijo de sucursal.</div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Correlativo CAI fin (formato SAR)
                        </label>
                        <input type="text" name="rango_cai_fin" class="form-control font-monospace text-uppercase"
                            value="<?= htmlspecialchars($cai['rango_cai_fin']) ?>" placeholder="Ej: 000-002-01-00000200"
                            maxlength="25">
                        <div class="form-text">Correlativo completo del final del rango.</div>
                    </div>

                    <!-- Vista previa del rango autorizado -->
                    <div class="col-12">
                        <label class="form-label fw-semibold text-muted small">Vista previa — Rango autorizado</label>
                        <div class="cai-display" id="preview-rango">
                            <span id="preview-inicio"><?= htmlspecialchars($cai['rango_cai_inicio']) ?></span>
                            <span class="text-muted mx-2">al</span>
                            <span id="preview-fin"><?= htmlspecialchars($cai['rango_cai_fin']) ?></span>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- 4. Correlativo Actual (solo lectura, informativo) ────────────────── -->
        <?php if ($usadas > 0): ?>
            <div class="card border-0 shadow-sm mb-4 border-start border-warning border-3">
                <div class="card-header-custom d-flex align-items-center">
                    <span class="section-icon bg-warning bg-opacity-10 text-warning">
                        <i class="fa-solid fa-file-invoice"></i>
                    </span>
                    <h6 class="mb-0 fw-bold">Estado actual del correlativo</h6>
                    <span class="badge bg-warning text-dark ms-2">Solo lectura</span>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-muted small">Último correlativo emitido</label>
                            <div class="cai-display">
                                <?= htmlspecialchars($cai['ultimo_correlativo'] ?? '—') ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-muted small">Facturas emitidas con este CAI</label>
                            <div class="cai-display">
                                <?= number_format($usadas) ?> de <?= number_format($total_facturas) ?>
                                <span class="text-muted ms-2">(<?= $porcentaje_usado ?>% utilizado)</span>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="alert alert-warning d-flex align-items-start gap-2 mb-0 py-2">
                                <i class="fa-solid fa-triangle-exclamation mt-1"></i>
                                <div>
                                    <strong>Advertencia:</strong> Este CAI ya tiene facturas emitidas.
                                    Modifica los rangos solo si hay un error tipográfico al registrarlo.
                                    <strong>No reduzcas el rango por debajo de <?= $usadas ?>.</strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- ── Botones ────────────────────────────────────────────────────────── -->
        <div class="d-flex gap-2 justify-content-end mb-5">
            <a href="lista_cai" class="btn btn-outline-secondary px-4">
                <i class="fa-solid fa-xmark me-1"></i> Cancelar
            </a>
            <button type="submit" class="btn btn-primary px-5" id="btnGuardar">
                <i class="fa-solid fa-floppy-disk me-2"></i>Guardar cambios
            </button>
        </div>

    </form>
</div>

<script>
    const CLIENTE_ID = <?= json_encode($cliente_id) ?>;

    // ── Vista previa del rango ────────────────────────────────────────────────────
    document.querySelector('input[name="rango_cai_inicio"]').addEventListener('input', function() {
        document.getElementById('preview-inicio').textContent = this.value.toUpperCase() || '—';
    });
    document.querySelector('input[name="rango_cai_fin"]').addEventListener('input', function() {
        document.getElementById('preview-fin').textContent = this.value.toUpperCase() || '—';
    });

    // ── Auto-mayúsculas en campos CAI ─────────────────────────────────────────────
    document.querySelectorAll('.text-uppercase').forEach(el => {
        el.addEventListener('input', function() {
            const pos = this.selectionStart;
            this.value = this.value.toUpperCase();
            this.setSelectionRange(pos, pos);
        });
    });

    // ── Cargar puntos de emisión al cambiar establecimiento ───────────────────────
    document.getElementById('establecimiento_id').addEventListener('change', function() {
        const estabId = this.value;
        const selPunto = document.getElementById('punto_emision_id');
        selPunto.innerHTML = '<option value="">— Cargando... —</option>';

        if (!estabId) {
            selPunto.innerHTML = '<option value="">— Seleccione establecimiento —</option>';
            return;
        }

        fetch(
                `../../includes/api/puntos_por_establecimiento.php?establecimiento_id=${estabId}&cliente_id=${CLIENTE_ID}`
            )
            .then(r => r.json())
            .then(puntos => {
                selPunto.innerHTML = '<option value="">— Seleccione punto de emisión —</option>';
                if (puntos.length === 0) {
                    selPunto.innerHTML = '<option value="">— Sin puntos registrados —</option>';
                    return;
                }
                puntos.forEach(p => {
                    const o = document.createElement('option');
                    o.value = p.id;
                    o.textContent = p.codigo_punto + (p.descripcion ? ' — ' + p.descripcion : '');
                    selPunto.appendChild(o);
                });
            })
            .catch(() => {
                selPunto.innerHTML = '<option value="">— Error al cargar —</option>';
                Swal.fire('Error', 'No se pudieron cargar los puntos de emisión.', 'error');
            });
    });

    // ── Envío del formulario vía AJAX ─────────────────────────────────────────────
    document.getElementById('formEditarCAI').addEventListener('submit', function(e) {
        e.preventDefault();

        const btn = document.getElementById('btnGuardar');

        // Validación: rango fin >= rango inicio
        const inicio = parseInt(document.querySelector('input[name="rango_inicio"]').value) || 0;
        const fin = parseInt(document.querySelector('input[name="rango_fin"]').value) || 0;
        if (fin < inicio) {
            Swal.fire('Error de rango', 'El rango fin no puede ser menor que el rango inicio.', 'error');
            return;
        }

        // Validación: rango fin >= facturas ya emitidas
        const emitidas = <?= $usadas ?>;
        const totalNuevo = fin - inicio + 1;
        if (totalNuevo < emitidas) {
            Swal.fire(
                'Rango inválido',
                `Ya se emitieron ${emitidas} facturas con este CAI. El rango no puede ser menor a ese número.`,
                'error'
            );
            return;
        }

        Swal.fire({
            title: '¿Guardar cambios?',
            text: 'Se actualizarán los datos del CAI.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Sí, guardar',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#0d6efd',
        }).then(result => {
            if (!result.isConfirmed) return;

            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Guardando...';

            fetch('guardar_edicion_cai', {
                    method: 'POST',
                    body: new FormData(document.getElementById('formEditarCAI'))
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire({
                            title: '¡Guardado!',
                            text: data.message || 'CAI actualizado correctamente.',
                            icon: 'success',
                            confirmButtonText: 'Aceptar',
                            confirmButtonColor: '#198754'
                        }).then(() => {
                            window.location.href = `editar_cai?id=<?= $cai['id'] ?>&guardado=1`;
                        });
                    } else {
                        Swal.fire('Error', data.error || 'No se pudo guardar.', 'error');
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-2"></i>Guardar cambios';
                    }
                })
                .catch(() => {
                    Swal.fire('Error', 'Error inesperado al comunicarse con el servidor.', 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-2"></i>Guardar cambios';
                });
        });
    });
</script>

<?php require_once '../../includes/templates/footer.php'; ?>