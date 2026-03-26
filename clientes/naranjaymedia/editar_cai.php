<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) die("ID de CAI inválido.");

$cai_id     = (int)$_GET['id'];
$usuario_id = $_SESSION['usuario_id'];

$stmtUser = $pdo->prepare("
    SELECT u.rol, c.id AS cliente_id, c.nombre AS cliente_nombre, c.logo_url
    FROM usuarios u
    INNER JOIN clientes_saas c ON u.cliente_id = c.id
    WHERE u.id = ?
");
$stmtUser->execute([$usuario_id]);
$datos = $stmtUser->fetch();
if (!$datos) die("Usuario inválido.");

$cliente_id = $datos['cliente_id'];
$rol        = $datos['rol'];

if (!in_array($rol, ['admin', 'superadmin'])) die("No tienes permisos para editar CAI.");

if ($rol === 'superadmin') {
    $stmtCAI = $pdo->prepare("SELECT * FROM cai_rangos WHERE id = ?");
    $stmtCAI->execute([$cai_id]);
} else {
    $stmtCAI = $pdo->prepare("SELECT * FROM cai_rangos WHERE id = ? AND cliente_id = ?");
    $stmtCAI->execute([$cai_id, $cliente_id]);
}
$cai = $stmtCAI->fetch();
if (!$cai) die("CAI no encontrado o no autorizado.");

$stmtEstab = $pdo->prepare("SELECT establecimiento_id AS id, nombre FROM establecimientos WHERE cliente_id = ? ORDER BY nombre ASC");
$stmtEstab->execute([$cliente_id]);
$establecimientos = $stmtEstab->fetchAll();

$stmtPuntos = $pdo->prepare("SELECT * FROM puntos_emision WHERE establecimiento_id = ?");
$stmtPuntos->execute([$cai['establecimiento_id']]);
$puntos_emision = $stmtPuntos->fetchAll();

$guardado_ok = isset($_GET['guardado']) && $_GET['guardado'] === '1';

$hoy    = new DateTime();
$limite = new DateTime($cai['fecha_limite']);
$activo = $hoy <= $limite;

$total_facturas   = (int)$cai['rango_fin'] - (int)$cai['rango_inicio'] + 1;
$usadas           = (int)$cai['correlativo_actual'];
$restantes        = $total_facturas - $usadas;
$porcentaje_usado = $total_facturas > 0 ? round(($usadas / $total_facturas) * 100) : 0;

// Establecimiento activo para el header
$establecimiento_activo = $_SESSION['establecimiento_activo'] ?? null;
$nombre_establecimiento = 'No asignado';
if ($establecimiento_activo) {
    $stmt = $pdo->prepare("SELECT nombre FROM establecimientos WHERE establecimiento_id = ?");
    $stmt->execute([$establecimiento_activo]);
    $nombre_establecimiento = $stmt->fetchColumn() ?: 'No asignado';
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
        --warning-bg: #fffbeb;
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

    .ec-page {
        padding: 1.5rem 0 3rem;
    }

    /* Header */
    .ec-header {
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

    .ec-header::before {
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

    .ec-header::after {
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

    .ec-header-body {
        display: flex;
        flex-direction: column;
        gap: .4rem;
    }

    .ec-header-title {
        font-size: 1.35rem;
        font-weight: 700;
        margin: 0;
        display: flex;
        align-items: center;
        gap: .6rem;
    }

    .ec-header-sub {
        font-size: .82rem;
        opacity: .8;
    }

    .ec-header-logo {
        max-height: 52px;
        border-radius: 8px;
        background: rgba(255, 255, 255, .15);
        padding: 4px;
    }

    .badge-activo {
        background: #059669;
        color: #fff;
        padding: .2rem .65rem;
        border-radius: 20px;
        font-size: .75rem;
        font-weight: 600;
    }

    .badge-vencido {
        background: #dc2626;
        color: #fff;
        padding: .2rem .65rem;
        border-radius: 20px;
        font-size: .75rem;
        font-weight: 600;
    }

    /* Stats */
    .ec-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .ec-stat {
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

    .ec-stat:hover {
        box-shadow: var(--shadow-md);
        transform: translateY(-2px);
    }

    .ec-stat-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.15rem;
        flex-shrink: 0;
    }

    .ec-stat-icon.purple {
        background: var(--brand-light);
        color: var(--brand);
    }

    .ec-stat-icon.green {
        background: var(--success-bg);
        color: var(--success);
    }

    .ec-stat-icon.amber {
        background: var(--warning-bg);
        color: var(--warning);
    }

    .ec-stat-val {
        font-size: 1.35rem;
        font-weight: 700;
        color: var(--text-main);
        line-height: 1;
    }

    .ec-stat-lbl {
        font-size: .72rem;
        color: var(--text-muted);
        margin-top: 2px;
    }

    /* Cards */
    .ec-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow-sm);
        margin-bottom: 1.25rem;
        overflow: hidden;
    }

    .ec-card-header {
        padding: 1rem 1.5rem;
        border-bottom: 1px solid var(--border);
        background: var(--surface-2);
        display: flex;
        align-items: center;
        gap: .6rem;
    }

    .ec-card-icon {
        width: 34px;
        height: 34px;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        flex-shrink: 0;
    }

    .ec-card-icon.purple {
        background: var(--brand-light);
        color: var(--brand);
    }

    .ec-card-icon.gray {
        background: #f1f5f9;
        color: var(--text-muted);
    }

    .ec-card-icon.green {
        background: var(--success-bg);
        color: var(--success);
    }

    .ec-card-icon.amber {
        background: var(--warning-bg);
        color: var(--warning);
    }

    .ec-card-title {
        font-weight: 700;
        font-size: .9rem;
        color: var(--text-main);
        margin: 0;
    }

    .ec-card-body {
        padding: 1.5rem;
    }

    /* Fields */
    .ec-field-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
        gap: 1rem;
    }

    .ec-field-full {
        grid-column: 1/-1;
    }

    .ec-field {
        display: flex;
        flex-direction: column;
        gap: .35rem;
    }

    .ec-label {
        font-size: .78rem;
        font-weight: 600;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: .04em;
        display: flex;
        align-items: center;
        gap: .35rem;
    }

    .ec-label .req {
        color: var(--danger);
    }

    .ec-input,
    .ec-select {
        padding: .58rem .85rem;
        border: 1.5px solid var(--border);
        border-radius: var(--radius-sm);
        font-size: .88rem;
        color: var(--text-main);
        background: var(--surface);
        outline: none;
        width: 100%;
        transition: border-color var(--tr), box-shadow var(--tr);
    }

    .ec-input:focus,
    .ec-select:focus {
        border-color: var(--brand);
        box-shadow: 0 0 0 3px rgba(124, 58, 237, .12);
    }

    .ec-hint {
        font-size: .74rem;
        color: var(--text-muted);
    }

    .cai-display {
        font-family: 'Courier New', monospace;
        font-size: .82rem;
        font-weight: 600;
        background: #f8f9fa;
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        padding: .6rem .85rem;
        color: var(--text-main);
    }

    .info-pill {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        background: #fff3e0;
        color: #b05000;
        border: 1px solid #f5c07a;
        border-radius: 20px;
        padding: .18rem .65rem;
        font-size: .73rem;
        font-weight: 600;
    }

    /* Progress */
    .ec-progress {
        height: 8px;
        background: #e2e8f0;
        border-radius: 4px;
        overflow: hidden;
        margin-top: .4rem;
    }

    .ec-progress-bar {
        height: 100%;
        border-radius: 4px;
        transition: width .4s;
    }

    /* Footer */
    .ec-footer {
        display: flex;
        gap: .75rem;
        justify-content: flex-end;
        margin-top: 1.5rem;
        margin-bottom: 2rem;
    }

    .btn-save {
        display: inline-flex;
        align-items: center;
        gap: .45rem;
        padding: .6rem 1.5rem;
        background: var(--brand);
        color: #fff;
        border: none;
        border-radius: var(--radius-sm);
        font-size: .9rem;
        font-weight: 600;
        cursor: pointer;
        box-shadow: 0 2px 8px rgba(124, 58, 237, .25);
        transition: background var(--tr), transform var(--tr);
    }

    .btn-save:hover {
        background: var(--brand-dark);
        transform: translateY(-1px);
    }

    .btn-back {
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

    .btn-back:hover {
        border-color: var(--text-muted);
        color: var(--text-main);
    }

    @media(max-width:640px) {
        .ec-header {
            padding: 1.1rem 1.25rem;
        }

        .ec-header-title {
            font-size: 1.1rem;
        }

        .ec-card-body {
            padding: 1.1rem;
        }
    }
</style>

<div class="ec-page container">

    <!-- Header -->
    <div class="ec-header">
        <div class="ec-header-body">
            <h4 class="ec-header-title">
                <i class="bi bi-key-fill"></i> Editar CAI
                <span class="<?= $activo ? 'badge-activo' : 'badge-vencido' ?>">
                    <?= $activo ? '✅ Activo' : '⛔ Vencido' ?>
                </span>
            </h4>
            <p class="ec-header-sub">
                Sucursal: <?= htmlspecialchars($nombre_establecimiento) ?> &nbsp;·&nbsp;
                Rol: <?= htmlspecialchars(ucfirst($rol)) ?> &nbsp;·&nbsp;
                <?= htmlspecialchars($datos['cliente_nombre']) ?>
            </p>
        </div>
        <?php if (!empty($datos['logo_url'])): ?>
            <img src="https://www.naranjaymediahn.com/wp-content/uploads/2023/06/logo-naranja.svg" alt="Logo" class="ec-header-logo">
        <?php endif; ?>
    </div>

    <?php if ($guardado_ok): ?>
        <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
            <i class="bi bi-check-circle-fill"></i>
            <strong>¡CAI actualizado correctamente!</strong>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="ec-stats">
        <div class="ec-stat">
            <div class="ec-stat-icon purple"><i class="bi bi-hash"></i></div>
            <div>
                <div class="ec-stat-val"><?= number_format($total_facturas) ?></div>
                <div class="ec-stat-lbl">Total facturas</div>
            </div>
        </div>
        <div class="ec-stat">
            <div class="ec-stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
            <div>
                <div class="ec-stat-val" style="color:#059669;"><?= number_format($usadas) ?></div>
                <div class="ec-stat-lbl">Emitidas</div>
            </div>
        </div>
        <div class="ec-stat">
            <div class="ec-stat-icon amber"><i class="bi bi-clock-fill"></i></div>
            <div>
                <div class="ec-stat-val"
                    style="color:<?= $restantes <= 20 ? '#dc2626' : ($restantes <= 100 ? '#d97706' : '#1e293b') ?>;">
                    <?= number_format($restantes) ?></div>
                <div class="ec-stat-lbl">Restantes</div>
            </div>
        </div>
        <div class="ec-stat">
            <div class="ec-stat-icon purple"><i class="bi bi-bar-chart-fill"></i></div>
            <div>
                <div class="ec-stat-val"><?= $porcentaje_usado ?>%</div>
                <div class="ec-stat-lbl">Uso del rango</div>
                <div class="ec-progress">
                    <div class="ec-progress-bar"
                        style="width:<?= $porcentaje_usado ?>%;background:<?= $porcentaje_usado >= 90 ? '#dc2626' : ($porcentaje_usado >= 70 ? '#d97706' : '#059669') ?>;">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <form id="formEditarCAI">
        <input type="hidden" name="id" value="<?= $cai['id'] ?>">

        <!-- Datos del CAI -->
        <div class="ec-card">
            <div class="ec-card-header">
                <div class="ec-card-icon purple"><i class="bi bi-key-fill"></i></div>
                <h6 class="ec-card-title">Datos del CAI</h6>
            </div>
            <div class="ec-card-body">
                <div class="ec-field-grid">
                    <div class="ec-field ec-field-full">
                        <label class="ec-label">
                            <i class="bi bi-upc-scan"></i> Código CAI <span class="req">*</span>
                            <span class="info-pill ms-2"><i class="bi bi-exclamation-triangle-fill"></i> SAR</span>
                        </label>
                        <input type="text" name="cai" class="ec-input font-monospace text-uppercase"
                            value="<?= htmlspecialchars($cai['cai']) ?>"
                            placeholder="D0708E-EE616A-A54EE0-63BE03-090930-0B1100" maxlength="40" required>
                        <span class="ec-hint">Formato: 6 grupos de 6 caracteres separados por guiones.</span>
                    </div>
                    <div class="ec-field">
                        <label class="ec-label"><i class="bi bi-calendar-check"></i> Fecha de recepción <span
                                class="req">*</span></label>
                        <input type="date" name="fecha_recepcion" class="ec-input"
                            value="<?= htmlspecialchars($cai['fecha_recepcion']) ?>" required>
                    </div>
                    <div class="ec-field">
                        <label class="ec-label"><i class="bi bi-calendar-x"></i> Fecha límite <span
                                class="req">*</span></label>
                        <input type="date" name="fecha_limite" class="ec-input"
                            value="<?= htmlspecialchars($cai['fecha_limite']) ?>" required>
                        <?php if (!$activo): ?>
                            <span class="ec-hint" style="color:#dc2626;"><i
                                    class="bi bi-exclamation-circle-fill me-1"></i>Este CAI está vencido.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Establecimiento y punto de emisión -->
        <div class="ec-card">
            <div class="ec-card-header">
                <div class="ec-card-icon gray"><i class="bi bi-building"></i></div>
                <h6 class="ec-card-title">Establecimiento y Punto de Emisión</h6>
            </div>
            <div class="ec-card-body">
                <div class="ec-field-grid">
                    <div class="ec-field">
                        <label class="ec-label"><i class="bi bi-building"></i> Establecimiento <span
                                class="req">*</span></label>
                        <select name="establecimiento_id" id="establecimiento_id" class="ec-select" required>
                            <?php foreach ($establecimientos as $est): ?>
                                <option value="<?= $est['id'] ?>"
                                    <?= $est['id'] == $cai['establecimiento_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($est['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ec-field">
                        <label class="ec-label"><i class="bi bi-printer"></i> Punto de Emisión <span
                                class="req">*</span></label>
                        <select name="punto_emision_id" id="punto_emision_id" class="ec-select" required>
                            <?php foreach ($puntos_emision as $pe): ?>
                                <option value="<?= $pe['id'] ?>" <?= $pe['id'] == $cai['punto_emision_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($pe['codigo_punto']) ?>
                                    <?= !empty($pe['descripcion']) ? ' — ' . htmlspecialchars($pe['descripcion']) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Rango de correlativo -->
        <div class="ec-card">
            <div class="ec-card-header">
                <div class="ec-card-icon green"><i class="bi bi-list-ol"></i></div>
                <h6 class="ec-card-title">Rango de Correlativo</h6>
                <small class="text-muted ms-1">Números autorizados por el SAR</small>
            </div>
            <div class="ec-card-body">
                <div class="ec-field-grid">
                    <div class="ec-field">
                        <label class="ec-label"><i class="bi bi-1-circle"></i> Rango inicio <span
                                class="req">*</span></label>
                        <input type="number" name="rango_inicio" class="ec-input" min="1"
                            value="<?= htmlspecialchars($cai['rango_inicio']) ?>" required>
                    </div>
                    <div class="ec-field">
                        <label class="ec-label"><i class="bi bi-infinity"></i> Rango fin <span
                                class="req">*</span></label>
                        <input type="number" name="rango_fin" class="ec-input" min="1"
                            value="<?= htmlspecialchars($cai['rango_fin']) ?>" required>
                    </div>
                    <div class="ec-field">
                        <label class="ec-label">Correlativo CAI inicio (SAR)</label>
                        <input type="text" name="rango_cai_inicio" class="ec-input font-monospace text-uppercase"
                            value="<?= htmlspecialchars($cai['rango_cai_inicio']) ?>" placeholder="000-002-01-00000001"
                            maxlength="25">
                    </div>
                    <div class="ec-field">
                        <label class="ec-label">Correlativo CAI fin (SAR)</label>
                        <input type="text" name="rango_cai_fin" class="ec-input font-monospace text-uppercase"
                            value="<?= htmlspecialchars($cai['rango_cai_fin']) ?>" placeholder="000-002-01-00000500"
                            maxlength="25">
                    </div>
                    <div class="ec-field ec-field-full">
                        <label class="ec-label"><i class="bi bi-eye"></i> Vista previa rango autorizado</label>
                        <div class="cai-display" id="preview-rango">
                            <span id="preview-inicio"><?= htmlspecialchars($cai['rango_cai_inicio']) ?></span>
                            <span class="text-muted mx-2">al</span>
                            <span id="preview-fin"><?= htmlspecialchars($cai['rango_cai_fin']) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Estado actual (solo si hay facturas emitidas) -->
        <?php if ($usadas > 0): ?>
            <div class="ec-card" style="border-left:4px solid var(--warning);">
                <div class="ec-card-header">
                    <div class="ec-card-icon amber"><i class="bi bi-file-invoice"></i></div>
                    <h6 class="ec-card-title">Estado actual del correlativo</h6>
                    <span class="ms-2"
                        style="background:var(--warning-bg);color:#92400e;border:1px solid #fde68a;border-radius:20px;padding:.15rem .6rem;font-size:.73rem;font-weight:600;">Solo
                        lectura</span>
                </div>
                <div class="ec-card-body">
                    <div class="ec-field-grid">
                        <div class="ec-field">
                            <label class="ec-label">Último correlativo emitido</label>
                            <div class="cai-display"><?= htmlspecialchars($cai['ultimo_correlativo'] ?? '—') ?></div>
                        </div>
                        <div class="ec-field">
                            <label class="ec-label">Facturas emitidas con este CAI</label>
                            <div class="cai-display"><?= number_format($usadas) ?> de <?= number_format($total_facturas) ?>
                                (<?= $porcentaje_usado ?>%)</div>
                        </div>
                    </div>
                    <div class="alert alert-warning d-flex align-items-start gap-2 mb-0 mt-3 py-2">
                        <i class="bi bi-exclamation-triangle-fill mt-1"></i>
                        <div><strong>Advertencia:</strong> Este CAI ya tiene facturas emitidas. No reduzcas el rango por
                            debajo de <strong><?= $usadas ?></strong>.</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="ec-footer">
            <a href="configuracion_cai" class="btn-back">
                <i class="bi bi-arrow-left"></i> Cancelar
            </a>
            <button type="submit" class="btn-save" id="btnGuardar">
                <i class="bi bi-floppy-fill me-1"></i> Guardar cambios
            </button>
        </div>
    </form>
</div>

<script>
    const CLIENTE_ID = <?= json_encode($cliente_id) ?>;

    /* ── Preview ── */
    document.querySelector('input[name="rango_cai_inicio"]').addEventListener('input', function() {
        document.getElementById('preview-inicio').textContent = this.value.toUpperCase() || '—';
    });
    document.querySelector('input[name="rango_cai_fin"]').addEventListener('input', function() {
        document.getElementById('preview-fin').textContent = this.value.toUpperCase() || '—';
    });

    /* ── Auto-upper ── */
    document.querySelectorAll('.text-uppercase').forEach(el => {
        el.addEventListener('input', function() {
            const p = this.selectionStart;
            this.value = this.value.toUpperCase();
            this.setSelectionRange(p, p);
        });
    });

    /* ── Cargar puntos de emisión ── */
    document.getElementById('establecimiento_id').addEventListener('change', function() {
        const estId = this.value;
        const sel = document.getElementById('punto_emision_id');
        sel.innerHTML = '<option value="">— Cargando... —</option>';
        if (!estId) {
            sel.innerHTML = '<option value="">— Seleccione establecimiento —</option>';
            return;
        }
        fetch(
                `../../includes/api/puntos_por_establecimiento.php?establecimiento_id=${estId}&cliente_id=${CLIENTE_ID}`
            )
            .then(r => r.json())
            .then(puntos => {
                sel.innerHTML = '<option value="">— Seleccione punto —</option>';
                if (!puntos.length) {
                    sel.innerHTML = '<option value="">— Sin puntos —</option>';
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
                sel.innerHTML = '<option value="">— Error —</option>';
                Swal.fire('Error', 'No se pudieron cargar los puntos.', 'error');
            });
    });

    /* ── Submit ── */
    document.getElementById('formEditarCAI').addEventListener('submit', function(e) {
        e.preventDefault();
        const btn = document.getElementById('btnGuardar');
        const inicio = parseInt(document.querySelector('input[name="rango_inicio"]').value) || 0;
        const fin = parseInt(document.querySelector('input[name="rango_fin"]').value) || 0;
        if (fin < inicio) {
            Swal.fire('Error de rango', 'El rango fin no puede ser menor que el inicio.', 'error');
            return;
        }
        const emitidas = <?= $usadas ?>;
        if ((fin - inicio + 1) < emitidas) {
            Swal.fire('Rango inválido', `Ya se emitieron ${emitidas} facturas. El rango no puede ser menor.`,
                'error');
            return;
        }

        Swal.fire({
                title: '¿Guardar cambios?',
                text: 'Se actualizarán los datos del CAI.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Sí, guardar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#7c3aed'
            })
            .then(result => {
                if (!result.isConfirmed) return;
                btn.disabled = true;
                btn.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Guardando…';
                fetch('guardar_edicion_cai', {
                        method: 'POST',
                        body: new FormData(document.getElementById('formEditarCAI'))
                    })
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                title: '¡Guardado!',
                                text: data.message || 'CAI actualizado.',
                                icon: 'success',
                                confirmButtonColor: '#7c3aed'
                            }).then(() => window.location.href =
                                `editar_cai?id=<?= $cai['id'] ?>&guardado=1`);
                        } else {
                            Swal.fire('Error', data.error || 'No se pudo guardar.', 'error');
                            btn.disabled = false;
                            btn.innerHTML = '<i class="bi bi-floppy-fill me-1"></i> Guardar cambios';
                        }
                    })
                    .catch(() => {
                        Swal.fire('Error', 'Error inesperado.', 'error');
                        btn.disabled = false;
                        btn.innerHTML = '<i class="bi bi-floppy-fill me-1"></i> Guardar cambios';
                    });
            });
    });
</script>

<?php require_once '../../includes/templates/footer.php'; ?>