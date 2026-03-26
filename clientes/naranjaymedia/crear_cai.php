<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

$usuario_id             = $_SESSION['usuario_id'];
$establecimiento_activo = $_SESSION['establecimiento_activo'] ?? null;

$stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->execute([$usuario_id]);
$usuario     = $stmt->fetch();
$rol_usuario = $usuario['rol'];
$cliente_id  = $usuario['cliente_id'] ?? null;

if ($rol_usuario !== 'superadmin' && $cliente_id) {
    $stmt = $pdo->prepare("SELECT nombre, logo_url FROM clientes_saas WHERE id = ?");
    $stmt->execute([$cliente_id]);
    $cliente        = $stmt->fetch();
    $cliente_nombre = $cliente['nombre'];
    $logo_url       = $cliente['logo_url'] ?? '';
} else {
    $cliente_nombre = 'Todos los clientes';
    $logo_url       = '';
}

if (!in_array($rol_usuario, ['admin', 'superadmin'])) {
    echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
    <script>Swal.fire('Acceso denegado','Solo administradores pueden agregar CAI.','error').then(()=>window.location.href='configuracion_cai');</script>";
    exit;
}

$nombre_establecimiento = 'No asignado';
if ($establecimiento_activo) {
    $stmt = $pdo->prepare("SELECT nombre FROM establecimientos WHERE establecimiento_id = ?");
    $stmt->execute([$establecimiento_activo]);
    $nombre_establecimiento = $stmt->fetchColumn() ?: 'No asignado';
}

// Establecimientos disponibles
if ($rol_usuario === 'superadmin') {
    $stmt = $pdo->query("SELECT establecimiento_id AS id, nombre FROM establecimientos ORDER BY nombre ASC");
} else {
    $stmt = $pdo->prepare("SELECT establecimiento_id AS id, nombre FROM establecimientos WHERE cliente_id = ? ORDER BY nombre ASC");
    $stmt->execute([$cliente_id]);
}
$establecimientos = $stmt->fetchAll();

// Puntos de emisión del establecimiento activo (para pre-carga)
$puntos_emision = [];
if ($establecimiento_activo) {
    $stmt = $pdo->prepare("SELECT * FROM puntos_emision WHERE establecimiento_id = ?");
    $stmt->execute([$establecimiento_activo]);
    $puntos_emision = $stmt->fetchAll();
}

require_once '../../includes/templates/header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>
    :root {
        --brand: #7c3aed;
        --brand-light: #ede9fe;
        --brand-dark: #5b21b6;
        --success: #10b981;
        --success-bg: #ecfdf5;
        --danger: #ef4444;
        --danger-bg: #fef2f2;
        --warning: #f59e0b;
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

    .cc-page {
        padding: 1.5rem 0 3rem;
    }

    /* Header */
    .cc-header {
        background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%);
        border-radius: var(--radius);
        padding: 1.5rem 2rem;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1.75rem;
        box-shadow: var(--shadow-md);
        position: relative;
        overflow: hidden;
    }

    .cc-header::before {
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

    .cc-header::after {
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

    .cc-header-title {
        font-size: 1.35rem;
        font-weight: 700;
        margin: 0;
    }

    .cc-header-sub {
        font-size: .82rem;
        opacity: .8;
        margin: .25rem 0 0;
    }

    .cc-header-logo {
        max-height: 52px;
        border-radius: 8px;
        background: rgba(255, 255, 255, .15);
        padding: 4px;
    }

    /* Form card */
    .cc-form-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow-sm);
        overflow: hidden;
    }

    .cc-form-card-header {
        padding: 1rem 1.5rem;
        border-bottom: 1px solid var(--border);
        background: var(--surface-2);
        display: flex;
        align-items: center;
        gap: .6rem;
    }

    .cc-form-card-icon {
        width: 34px;
        height: 34px;
        border-radius: 8px;
        background: var(--brand-light);
        color: var(--brand);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        flex-shrink: 0;
    }

    .cc-form-card-title {
        font-weight: 700;
        font-size: .95rem;
        color: var(--text-main);
        margin: 0;
    }

    .cc-form-body {
        padding: 1.75rem;
    }

    /* Fields */
    .cc-field-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
        gap: 1.1rem;
    }

    .cc-field-full {
        grid-column: 1/-1;
    }

    .cc-field {
        display: flex;
        flex-direction: column;
        gap: .35rem;
    }

    .cc-label {
        font-size: .8rem;
        font-weight: 600;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: .04em;
        display: flex;
        align-items: center;
        gap: .35rem;
    }

    .cc-label .req {
        color: var(--danger);
        font-size: .85rem;
    }

    .cc-input,
    .cc-select,
    .cc-textarea {
        padding: .6rem .85rem;
        border: 1.5px solid var(--border);
        border-radius: var(--radius-sm);
        font-size: .9rem;
        color: var(--text-main);
        background: var(--surface);
        transition: border-color var(--tr), box-shadow var(--tr);
        outline: none;
        width: 100%;
    }

    .cc-input:focus,
    .cc-select:focus,
    .cc-textarea:focus {
        border-color: var(--brand);
        box-shadow: 0 0 0 3px rgba(124, 58, 237, .12);
    }

    .cc-input::placeholder,
    .cc-textarea::placeholder {
        color: #94a3b8;
    }

    .cc-input.is-invalid,
    .cc-select.is-invalid {
        border-color: var(--danger);
        box-shadow: 0 0 0 3px rgba(239, 68, 68, .1);
    }

    .cc-input.font-mono {
        font-family: 'Courier New', monospace;
        letter-spacing: .04em;
    }

    .cc-hint {
        font-size: .75rem;
        color: var(--text-muted);
        margin-top: .1rem;
    }

    /* Section divider */
    .cc-section {
        font-size: .72rem;
        font-weight: 700;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: .08em;
        padding: .5rem 0 .25rem;
        border-top: 1px solid var(--border);
        margin-top: .5rem;
        display: flex;
        align-items: center;
        gap: .5rem;
    }

    .cc-section::after {
        content: '';
        flex: 1;
        height: 1px;
        background: var(--border);
    }

    /* Preview CAI */
    .cai-preview {
        background: #f5f3ff;
        border: 1.5px solid #c4b5fd;
        border-radius: var(--radius-sm);
        padding: .65rem 1rem;
        font-family: 'Courier New', monospace;
        font-size: .88rem;
        color: #4c1d95;
        letter-spacing: .05em;
        min-height: 2.4rem;
        display: flex;
        align-items: center;
        gap: .5rem;
    }

    .cai-preview.empty {
        color: #94a3b8;
        font-family: inherit;
        font-size: .85rem;
        letter-spacing: 0;
    }

    /* Rango preview */
    .rango-preview {
        background: #f0fdf4;
        border: 1.5px solid #a7f3d0;
        border-radius: var(--radius-sm);
        padding: .65rem 1rem;
        font-size: .85rem;
        color: #065f46;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: .5rem;
    }

    /* Info pill */
    .info-pill {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        background: #fff3e0;
        color: #b05000;
        border: 1px solid #f5c07a;
        border-radius: 20px;
        padding: .2rem .65rem;
        font-size: .75rem;
        font-weight: 600;
    }

    /* Footer */
    .cc-form-footer {
        padding: 1.1rem 1.75rem;
        border-top: 1px solid var(--border);
        background: var(--surface-2);
        display: flex;
        align-items: center;
        gap: .75rem;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    .btn-save {
        display: inline-flex;
        align-items: center;
        gap: .45rem;
        padding: .6rem 1.4rem;
        background: var(--brand);
        color: #fff;
        border-radius: var(--radius-sm);
        font-size: .9rem;
        font-weight: 600;
        border: none;
        cursor: pointer;
        white-space: nowrap;
        box-shadow: 0 2px 8px rgba(124, 58, 237, .25);
        transition: background var(--tr), transform var(--tr);
    }

    .btn-save:hover {
        background: var(--brand-dark);
        transform: translateY(-1px);
    }

    .btn-cancel {
        display: inline-flex;
        align-items: center;
        gap: .45rem;
        padding: .6rem 1.2rem;
        background: var(--surface);
        color: var(--text-muted);
        border: 1.5px solid var(--border);
        border-radius: var(--radius-sm);
        font-size: .9rem;
        font-weight: 600;
        text-decoration: none;
        cursor: pointer;
        transition: all var(--tr);
    }

    .btn-cancel:hover {
        border-color: var(--text-muted);
        color: var(--text-main);
        background: #f1f5f9;
    }

    @media(max-width:640px) {
        .cc-header {
            padding: 1.1rem 1.25rem;
        }

        .cc-header-title {
            font-size: 1.1rem;
        }

        .cc-form-body {
            padding: 1.25rem;
        }

        .cc-form-footer {
            padding: .9rem 1.25rem;
            justify-content: stretch;
        }

        .btn-save,
        .btn-cancel {
            flex: 1;
            justify-content: center;
        }
    }
</style>

<div class="cc-page container">

    <!-- Header -->
    <div class="cc-header">
        <div>
            <h4 class="cc-header-title">🔑 Crear Nuevo Rango CAI</h4>
            <p class="cc-header-sub">
                Sucursal: <?= htmlspecialchars($nombre_establecimiento) ?> &nbsp;·&nbsp;
                Rol: <?= htmlspecialchars(ucfirst($rol_usuario)) ?> &nbsp;·&nbsp;
                <?= htmlspecialchars($cliente_nombre) ?>
            </p>
        </div>
        <?php if ($logo_url): ?>
            <img src="<?= htmlspecialchars($logo_url) ?>" alt="Logo" class="cc-header-logo">
        <?php endif; ?>
    </div>

    <div class="cc-form-card">
        <div class="cc-form-card-header">
            <div class="cc-form-card-icon"><i class="bi bi-key-fill"></i></div>
            <h5 class="cc-form-card-title">Datos del Código de Autorización de Impresión (CAI)</h5>
        </div>

        <form method="POST" action="guardar_cai" id="ccForm" novalidate>
            <div class="cc-form-body">

                <!-- ── Sección 1: Código CAI ── -->
                <div class="cc-section"><i class="bi bi-key me-1"></i>Código CAI</div>

                <div class="cc-field-grid" style="margin-top:.9rem;">
                    <div class="cc-field cc-field-full">
                        <label class="cc-label" for="cai">
                            <i class="bi bi-upc-scan"></i> Código CAI
                            <span class="req">*</span>
                            <span class="info-pill ms-2"><i class="bi bi-exclamation-triangle-fill"></i> Emitido por el
                                SAR</span>
                        </label>
                        <input type="text" id="cai" name="cai" class="cc-input cc-input font-mono text-uppercase"
                            placeholder="Ej: D0708E-EE616A-A54EE0-63BE03-090930-0B1100" maxlength="40" required
                            autocomplete="off" oninput="updateCaiPreview(this.value)">
                        <span class="cc-hint">Formato: 6 grupos de 6 caracteres separados por guiones.</span>
                    </div>

                    <div class="cc-field cc-field-full">
                        <label class="cc-label"><i class="bi bi-eye"></i> Vista previa CAI</label>
                        <div class="cai-preview empty" id="caiPreview">
                            <i class="bi bi-dash"></i> Ingresa el código CAI para ver la vista previa
                        </div>
                    </div>
                </div>

                <!-- ── Sección 2: Establecimiento ── -->
                <div class="cc-section" style="margin-top:1.25rem;"><i class="bi bi-building me-1"></i>Establecimiento y
                    Punto de Emisión</div>

                <div class="cc-field-grid" style="margin-top:.9rem;">
                    <div class="cc-field">
                        <label class="cc-label" for="establecimiento_id">
                            <i class="bi bi-building"></i> Establecimiento <span class="req">*</span>
                        </label>
                        <select id="establecimiento_id" name="establecimiento_id" class="cc-select" required>
                            <option value="">— Seleccione establecimiento —</option>
                            <?php foreach ($establecimientos as $est): ?>
                                <option value="<?= $est['id'] ?>" <?= $est['id'] == $establecimiento_activo ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($est['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="cc-field">
                        <label class="cc-label" for="punto_emision_id">
                            <i class="bi bi-printer"></i> Punto de Emisión <span class="req">*</span>
                        </label>
                        <select id="punto_emision_id" name="punto_emision_id" class="cc-select" required>
                            <option value="">— Seleccione establecimiento primero —</option>
                            <?php foreach ($puntos_emision as $pe): ?>
                                <option value="<?= $pe['id'] ?>">
                                    <?= htmlspecialchars($pe['codigo_punto']) ?>
                                    <?= !empty($pe['descripcion']) ? ' — ' . htmlspecialchars($pe['descripcion']) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- ── Sección 3: Fechas ── -->
                <div class="cc-section" style="margin-top:1.25rem;"><i class="bi bi-calendar3 me-1"></i>Fechas de
                    vigencia</div>

                <div class="cc-field-grid" style="margin-top:.9rem;">
                    <div class="cc-field">
                        <label class="cc-label" for="fecha_recepcion">
                            <i class="bi bi-calendar-check"></i> Fecha de recepción <span class="req">*</span>
                        </label>
                        <input type="date" id="fecha_recepcion" name="fecha_recepcion" class="cc-input"
                            value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="cc-field">
                        <label class="cc-label" for="fecha_limite">
                            <i class="bi bi-calendar-x"></i> Fecha límite de emisión <span class="req">*</span>
                        </label>
                        <input type="date" id="fecha_limite" name="fecha_limite" class="cc-input" required>
                        <span class="cc-hint">Fecha hasta la que el SAR autoriza la emisión de facturas.</span>
                    </div>
                </div>

                <!-- ── Sección 4: Rango de correlativos ── -->
                <div class="cc-section" style="margin-top:1.25rem;"><i class="bi bi-list-ol me-1"></i>Rango de
                    correlativos autorizados</div>

                <div class="cc-field-grid" style="margin-top:.9rem;">
                    <div class="cc-field">
                        <label class="cc-label" for="rango_inicio">
                            <i class="bi bi-1-circle"></i> Rango inicio <span class="req">*</span>
                        </label>
                        <input type="number" id="rango_inicio" name="rango_inicio" class="cc-input" min="1"
                            placeholder="Ej: 1" required oninput="updateRangoPreview()">
                        <span class="cc-hint">Número de inicio del rango autorizado.</span>
                    </div>
                    <div class="cc-field">
                        <label class="cc-label" for="rango_fin">
                            <i class="bi bi-infinity"></i> Rango fin <span class="req">*</span>
                        </label>
                        <input type="number" id="rango_fin" name="rango_fin" class="cc-input" min="1"
                            placeholder="Ej: 500" required oninput="updateRangoPreview()">
                        <span class="cc-hint">Número final del rango autorizado.</span>
                    </div>

                    <!-- Correlativos SAR completos -->
                    <div class="cc-field">
                        <label class="cc-label" for="rango_cai_inicio">
                            <i class="bi bi-file-earmark-text"></i> Correlativo CAI inicio (SAR)
                        </label>
                        <input type="text" id="rango_cai_inicio" name="rango_cai_inicio"
                            class="cc-input font-mono text-uppercase" placeholder="Ej: 000-002-01-00000001"
                            maxlength="25" oninput="updatePreviewCorr()" style="font-family:'Courier New',monospace;">
                        <span class="cc-hint">Correlativo completo con prefijo de sucursal.</span>
                    </div>
                    <div class="cc-field">
                        <label class="cc-label" for="rango_cai_fin">
                            <i class="bi bi-file-earmark-text-fill"></i> Correlativo CAI fin (SAR)
                        </label>
                        <input type="text" id="rango_cai_fin" name="rango_cai_fin"
                            class="cc-input font-mono text-uppercase" placeholder="Ej: 000-002-01-00000500"
                            maxlength="25" oninput="updatePreviewCorr()" style="font-family:'Courier New',monospace;">
                        <span class="cc-hint">Correlativo completo del final del rango.</span>
                    </div>

                    <!-- Rango preview -->
                    <div class="cc-field cc-field-full" id="rangoPreviewWrap" style="display:none;">
                        <label class="cc-label"><i class="bi bi-eye"></i> Resumen del rango</label>
                        <div class="rango-preview">
                            <span><i class="bi bi-hash"></i> Facturas autorizadas: <strong
                                    id="prevCount">—</strong></span>
                            <span><i class="bi bi-arrow-left-right"></i> <span id="prevRange">—</span></span>
                        </div>
                    </div>
                </div>

            </div><!-- /cc-form-body -->

            <div class="cc-form-footer">
                <a href="configuracion_cai" class="btn-cancel">
                    <i class="bi bi-arrow-left"></i> Cancelar
                </a>
                <button type="submit" class="btn-save" id="ccSubmit">
                    <i class="bi bi-floppy-fill"></i> Guardar CAI
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    const CLIENTE_ID = <?= json_encode($cliente_id) ?>;

    /* ── CAI preview ── */
    function updateCaiPreview(val) {
        const preview = document.getElementById('caiPreview');
        const v = val.trim().toUpperCase();
        if (!v) {
            preview.textContent = '— Ingresa el código CAI para ver la vista previa';
            preview.classList.add('empty');
            return;
        }
        preview.innerHTML = `<i class="bi bi-key-fill me-1"></i> ${v}`;
        preview.classList.remove('empty');
    }

    /* ── Rango preview ── */
    function updateRangoPreview() {
        const ini = parseInt(document.getElementById('rango_inicio').value) || 0;
        const fin = parseInt(document.getElementById('rango_fin').value) || 0;
        const wrap = document.getElementById('rangoPreviewWrap');
        if (ini > 0 && fin > 0) {
            wrap.style.display = '';
            document.getElementById('prevCount').textContent = fin >= ini ? (fin - ini + 1).toLocaleString('es') :
                '⚠ Rango inválido';
            document.getElementById('prevRange').textContent = `${ini.toLocaleString('es')} → ${fin.toLocaleString('es')}`;
        } else {
            wrap.style.display = 'none';
        }
    }

    /* ── Correlativo preview ── */
    function updatePreviewCorr() {
        const ini = document.getElementById('rango_cai_inicio').value.trim().toUpperCase();
        const fin = document.getElementById('rango_cai_fin').value.trim().toUpperCase();
        document.getElementById('rango_cai_inicio').value = ini;
        document.getElementById('rango_cai_fin').value = fin;
    }

    /* ── Auto-upper CAI ── */
    document.getElementById('cai').addEventListener('input', function() {
        const p = this.selectionStart;
        this.value = this.value.toUpperCase();
        this.setSelectionRange(p, p);
        updateCaiPreview(this.value);
    });

    /* ── Cargar puntos de emisión al cambiar establecimiento ── */
    document.getElementById('establecimiento_id').addEventListener('change', function() {
        const estId = this.value;
        const sel = document.getElementById('punto_emision_id');
        sel.innerHTML = '<option value="">— Cargando... —</option>';
        if (!estId) {
            sel.innerHTML = '<option value="">— Seleccione establecimiento primero —</option>';
            return;
        }
        fetch(
                `../../includes/api/puntos_por_establecimiento.php?establecimiento_id=${estId}&cliente_id=${CLIENTE_ID}`
            )
            .then(r => r.json())
            .then(puntos => {
                sel.innerHTML = '<option value="">— Seleccione punto de emisión —</option>';
                if (!puntos.length) {
                    sel.innerHTML = '<option value="">— Sin puntos registrados —</option>';
                    return;
                }
                puntos.forEach(p => {
                    const o = document.createElement('option');
                    o.value = p.id;
                    o.textContent = p.codigo_punto + (p.descripcion ? ' — ' + p.descripcion : '');
                    sel.appendChild(o);
                });
            })
            .catch(() => {
                sel.innerHTML = '<option value="">— Error al cargar —</option>';
                Swal.fire('Error', 'No se pudieron cargar los puntos de emisión.', 'error');
            });
    });

    /* ── Validation + submit ── */
    document.getElementById('ccForm').addEventListener('submit', function(e) {
        const required = ['cai', 'establecimiento_id', 'punto_emision_id', 'fecha_recepcion', 'fecha_limite',
            'rango_inicio', 'rango_fin'
        ];
        let valid = true;
        required.forEach(id => {
            const el = document.getElementById(id);
            if (!el) return;
            el.classList.remove('is-invalid');
            if (!el.value.trim()) {
                el.classList.add('is-invalid');
                valid = false;
            }
        });

        if (!valid) {
            e.preventDefault();
            Swal.fire({
                title: 'Campos requeridos',
                text: 'Por favor completa todos los campos obligatorios.',
                icon: 'warning',
                confirmButtonColor: '#7c3aed'
            });
            return;
        }

        const ini = parseInt(document.getElementById('rango_inicio').value);
        const fin = parseInt(document.getElementById('rango_fin').value);
        if (fin < ini) {
            e.preventDefault();
            Swal.fire({
                title: 'Rango inválido',
                text: 'El rango fin no puede ser menor que el rango inicio.',
                icon: 'error',
                confirmButtonColor: '#7c3aed'
            });
            return;
        }

        const rec = document.getElementById('fecha_recepcion').value;
        const limite = document.getElementById('fecha_limite').value;
        if (limite < rec) {
            e.preventDefault();
            Swal.fire({
                title: 'Fechas inválidas',
                text: 'La fecha límite no puede ser anterior a la fecha de recepción.',
                icon: 'error',
                confirmButtonColor: '#7c3aed'
            });
            return;
        }

        const btn = document.getElementById('ccSubmit');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span> Guardando…';
    });

    /* ── Remove invalid on input ── */
    document.querySelectorAll('.cc-input,.cc-select').forEach(el => el.addEventListener('change', () => el.classList.remove(
        'is-invalid')));
    document.querySelectorAll('.cc-input').forEach(el => el.addEventListener('input', () => el.classList.remove(
        'is-invalid')));
</script>

<?php require_once '../../includes/templates/footer.php'; ?>