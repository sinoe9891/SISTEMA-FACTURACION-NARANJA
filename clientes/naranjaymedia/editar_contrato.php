<?php
$titulo = 'Editar Contrato';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

$cliente_id = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Location: contratos');
    exit;
}

// Verificar propiedad
$stmt = $pdo->prepare("SELECT * FROM contratos WHERE id = ? AND cliente_id = ?");
$stmt->execute([$id, $cliente_id]);
$contrato = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$contrato) {
    header('Location: contratos');
    exit;
}

$tipo_actual = $contrato['tipo_contrato'] ?? 'estandar';

// ── Servicios actuales ────────────────────────────────────────────────────────
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

if (empty($servicios_actuales) && $contrato['producto_id']) {
    $stmtL = $pdo->prepare("SELECT nombre FROM productos_clientes WHERE id = ?");
    $stmtL->execute([$contrato['producto_id']]);
    $nomLegacy = $stmtL->fetchColumn();
    $servicios_actuales = [[
        'pivot_id'        => null,
        'producto_id'     => $contrato['producto_id'],
        'monto'           => $contrato['monto'],
        'producto_nombre' => $nomLegacy ?: 'Servicio actual',
    ]];
}

// ── Rotativos actuales ────────────────────────────────────────────────────────
$stmtRot = $pdo->prepare("
    SELECT r.receptor_id, r.orden, r.monto, cf.nombre AS receptor_nombre
    FROM contratos_clientes_rotativos r
    LEFT JOIN clientes_factura cf ON cf.id = r.receptor_id
    WHERE r.contrato_id = ? AND r.activo = 1
    ORDER BY r.orden ASC
");
$stmtRot->execute([$id]);
$rotativos_actuales = $stmtRot->fetchAll(PDO::FETCH_ASSOC);

// ── Clientes ──────────────────────────────────────────────────────────────────
$stmtClientes = $pdo->prepare("
    SELECT id, nombre, rtn FROM clientes_factura
    WHERE cliente_id = ? ORDER BY nombre ASC
");
$stmtClientes->execute([$cliente_id]);
$clientes_lista = $stmtClientes->fetchAll(PDO::FETCH_ASSOC);

$meses = [
    'Enero',
    'Febrero',
    'Marzo',
    'Abril',
    'Mayo',
    'Junio',
    'Julio',
    'Agosto',
    'Septiembre',
    'Octubre',
    'Noviembre',
    'Diciembre'
];

require_once '../../includes/templates/header.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
    rel="stylesheet">

<style>
    :root {
        --brand: #0f766e;
        --brand-dk: #065f46;
        --brand-lt: #ccfbf1;
        --surface: #fff;
        --surface-2: #f8fafc;
        --border: #e2e8f0;
        --text: #0f172a;
        --muted: #64748b;
        --radius: 16px;
        --radius-sm: 10px;
        --shadow-sm: 0 1px 3px rgba(0, 0, 0, .06);
        --shadow-md: 0 6px 24px rgba(0, 0, 0, .09);
        --tr: .18s cubic-bezier(.4, 0, .2, 1);
        font-family: 'Plus Jakarta Sans', sans-serif;
    }

    .ec-wrap {
        max-width: 1100px;
        margin: 0 auto;
        padding: 1.5rem 0 4rem;
    }

    /* Hero */
    .ec-hero {
        background: linear-gradient(135deg, #d97706 0%, #92400e 100%);
        border-radius: var(--radius);
        padding: 1.4rem 2rem;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1.75rem;
        box-shadow: 0 8px 32px rgba(217, 119, 6, .25);
        position: relative;
        overflow: hidden;
    }

    .ec-hero::before {
        content: '';
        position: absolute;
        top: -50px;
        right: -50px;
        width: 200px;
        height: 200px;
        border-radius: 50%;
        background: rgba(255, 255, 255, .07);
        pointer-events: none;
    }

    .ec-hero-title {
        font-size: 1.3rem;
        font-weight: 800;
        margin: 0;
    }

    .ec-hero-sub {
        font-size: .82rem;
        opacity: .78;
        margin: .15rem 0 0;
    }

    /* Cards */
    .ec-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow-sm);
        margin-bottom: 1.25rem;
        overflow: hidden;
        transition: box-shadow var(--tr);
    }

    .ec-card:hover {
        box-shadow: var(--shadow-md);
    }

    .ec-card-hdr {
        padding: .9rem 1.4rem;
        border-bottom: 1px solid var(--border);
        background: var(--surface-2);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .75rem;
    }

    .ec-card-title {
        font-size: .92rem;
        font-weight: 700;
        color: var(--text);
        display: flex;
        align-items: center;
        gap: .5rem;
    }

    .ec-card-body {
        padding: 1.25rem 1.4rem;
    }

    /* Inputs */
    .mf-label {
        font-size: .72rem;
        font-weight: 700;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: .05em;
        margin-bottom: .3rem;
        display: block;
    }

    .mf-input,
    .mf-select {
        width: 100%;
        padding: .62rem .9rem;
        border: 1.5px solid var(--border);
        border-radius: var(--radius-sm);
        font-size: .88rem;
        color: var(--text);
        background: var(--surface);
        outline: none;
        transition: border-color var(--tr), box-shadow var(--tr);
        font-family: inherit;
    }

    .mf-input:focus,
    .mf-select:focus {
        border-color: var(--brand);
        box-shadow: 0 0 0 3px rgba(15, 118, 110, .1);
    }

    /* Tipo selector */
    .tipo-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: .7rem;
    }

    @media(max-width:600px) {
        .tipo-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    .tipo-card {
        position: relative;
        border: 2px solid var(--border);
        border-radius: 12px;
        padding: 1rem .75rem .85rem;
        text-align: center;
        cursor: pointer;
        transition: border-color .18s, background .18s, box-shadow .18s, transform .15s;
        background: var(--surface);
        user-select: none;
        overflow: hidden;
    }

    .tipo-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 3px;
        border-radius: 12px 12px 0 0;
        transition: opacity .18s;
        opacity: 0;
    }

    .tipo-card:hover:not(.tc-sel) {
        border-color: #cbd5e1;
        background: #f8fafc;
        transform: translateY(-1px);
    }

    .tipo-card.tc-sel {
        box-shadow: 0 4px 16px rgba(0, 0, 0, .1);
        transform: translateY(-2px);
    }

    .tipo-card.tc-sel::before {
        opacity: 1;
    }

    .tc-radio {
        position: absolute;
        opacity: 0;
        width: 0;
        height: 0;
        pointer-events: none;
    }

    .tc-icon {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        margin: 0 auto .55rem;
        transition: transform .18s;
    }

    .tipo-card.tc-sel .tc-icon {
        transform: scale(1.1);
    }

    .tc-label {
        font-weight: 700;
        font-size: .8rem;
        color: var(--text);
        margin-bottom: 2px;
    }

    .tc-desc {
        font-size: .67rem;
        color: var(--muted);
    }

    .tc-check {
        position: absolute;
        top: 7px;
        right: 8px;
        font-size: .8rem;
        opacity: 0;
        transition: opacity .18s, transform .18s;
        transform: scale(.5);
    }

    .tipo-card.tc-sel .tc-check {
        opacity: 1;
        transform: scale(1);
    }

    .tc-estandar {
        --c-bg: #f0fdf4;
        --c-bd: #a7f3d0;
        --c-col: #059669;
        --c-ib: #d1fae5;
    }

    .tc-periodico {
        --c-bg: #eff6ff;
        --c-bd: #bfdbfe;
        --c-col: #1d4ed8;
        --c-ib: #dbeafe;
    }

    .tc-rotativo {
        --c-bg: #fffbeb;
        --c-bd: #fde68a;
        --c-col: #d97706;
        --c-ib: #fef3c7;
    }

    .tc-sinfact {
        --c-bg: #fdf4ff;
        --c-bd: #e9d5ff;
        --c-col: #7c3aed;
        --c-ib: #ede9fe;
    }

    .tipo-card.tc-sel {
        background: var(--c-bg);
        border-color: var(--c-bd);
    }

    .tipo-card.tc-sel::before {
        background: var(--c-col);
    }

    .tipo-card.tc-sel .tc-label {
        color: var(--c-col);
    }

    .tipo-card.tc-sel .tc-check {
        color: var(--c-col);
    }

    .tc-icon {
        background: var(--c-ib);
        color: var(--c-col);
    }

    /* Servicio/Rotativo items */
    .svc-item {
        background: var(--surface-2);
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        padding: .85rem 1rem;
        margin-bottom: .75rem;
        position: relative;
        transition: border-color var(--tr);
    }

    .svc-item:hover {
        border-color: #94a3b8;
    }

    .rot-item {
        background: #fffbeb;
        border: 1px solid #fde68a;
        border-radius: var(--radius-sm);
        padding: .85rem 1rem;
        margin-bottom: .75rem;
        position: relative;
    }

    .rot-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 26px;
        height: 26px;
        border-radius: 50%;
        background: #d97706;
        color: #fff;
        font-size: .72rem;
        font-weight: 800;
        flex-shrink: 0;
    }

    /* Info boxes */
    .info-box {
        border-radius: var(--radius-sm);
        padding: .7rem 1rem;
        font-size: .82rem;
        display: flex;
        align-items: flex-start;
        gap: .6rem;
    }

    .ib-green {
        background: #f0fdf4;
        border: 1px solid #a7f3d0;
        color: #065f46;
    }

    .ib-blue {
        background: #eff6ff;
        border: 1px solid #bfdbfe;
        color: #1e40af;
    }

    .ib-amber {
        background: #fffbeb;
        border: 1px solid #fde68a;
        color: #92400e;
    }

    .ib-purple {
        background: #fdf4ff;
        border: 1px solid #e9d5ff;
        color: #5b21b6;
    }

    .ib-red {
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #991b1b;
    }

    /* Total bar */
    .total-bar {
        background: linear-gradient(135deg, #059669, #047857);
        color: #fff;
        border-radius: var(--radius-sm);
        padding: .85rem 1.2rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-weight: 700;
        margin-top: .75rem;
    }

    /* Btn save */
    .btn-save {
        display: inline-flex;
        align-items: center;
        gap: .5rem;
        padding: .75rem 2rem;
        background: var(--brand);
        color: #fff;
        border: none;
        border-radius: var(--radius-sm);
        font-size: .9rem;
        font-weight: 700;
        cursor: pointer;
        box-shadow: 0 4px 16px rgba(15, 118, 110, .3);
        transition: background var(--tr), transform var(--tr);
        font-family: inherit;
    }

    .btn-save:hover {
        background: var(--brand-dk);
        transform: translateY(-1px);
    }

    .btn-save:disabled {
        opacity: .65;
        cursor: not-allowed;
        transform: none;
    }

    /* Estado badge */
    .estado-pill {
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        padding: .35rem .9rem;
        border-radius: 20px;
        font-size: .8rem;
        font-weight: 700;
    }

    .ep-activo {
        background: #d1fae5;
        color: #065f46;
    }

    .ep-pausado {
        background: #fef3c7;
        color: #92400e;
    }

    .ep-cancelado {
        background: #fee2e2;
        color: #991b1b;
    }

    .ep-vencido {
        background: #f1f5f9;
        color: #475569;
    }
</style>

<div class="container-xxl ec-wrap">

    <!-- Hero -->
    <div class="ec-hero">
        <div>
            <h4 class="ec-hero-title">✏️ Editar Contrato</h4>
            <p class="ec-hero-sub">
                <?= htmlspecialchars($contrato['nombre_contrato']) ?> &nbsp;·&nbsp; ID #<?= $contrato['id'] ?>
            </p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <?php
            $epCls = ['activo' => 'ep-activo', 'pausado' => 'ep-pausado', 'cancelado' => 'ep-cancelado', 'vencido' => 'ep-vencido'];
            $epIco = ['activo' => '✅', 'pausado' => '⏸', 'cancelado' => '❌', 'vencido' => '⌛'];
            ?>
            <span class="estado-pill <?= $epCls[$contrato['estado']] ?? 'ep-vencido' ?>">
                <?= ($epIco[$contrato['estado']] ?? '') ?> <?= ucfirst($contrato['estado']) ?>
            </span>
            <a href="contratos" class="btn btn-sm"
                style="background:rgba(255,255,255,.18);color:#fff;border:1px solid rgba(255,255,255,.3);font-weight:600">
                <i class="bi bi-arrow-left me-1"></i>Volver
            </a>
        </div>
    </div>

    <form id="formEditar" novalidate>
        <input type="hidden" name="id" value="<?= $contrato['id'] ?>">

        <!-- ── SECCIÓN 1: Tipo ──────────────────────────────────────────── -->
        <div class="ec-card">
            <div class="ec-card-hdr">
                <span class="ec-card-title">
                    <i class="bi bi-lightning-charge-fill text-warning"></i>
                    Tipo de Contrato
                </span>
                <span class="info-box ib-amber py-1 px-2" style="font-size:.74rem">
                    <i class="bi bi-exclamation-triangle-fill" style="flex-shrink:0"></i>
                    Cambiar el tipo puede afectar la lógica de cobro
                </span>
            </div>
            <div class="ec-card-body">
                <div class="tipo-grid">
                    <div class="tipo-card tc-estandar <?= $tipo_actual === 'estandar' ? 'tc-sel' : '' ?>"
                        data-tipo="estandar">
                        <input type="radio" class="tc-radio" name="tipo_contrato" value="estandar"
                            <?= $tipo_actual === 'estandar' ? 'checked' : '' ?>>
                        <div class="tc-icon"><i class="bi bi-calendar-check-fill"></i></div>
                        <div class="tc-label">Estándar</div>
                        <div class="tc-desc">Cobro mensual fijo</div>
                        <div class="tc-check"><i class="bi bi-check-circle-fill"></i></div>
                    </div>
                    <div class="tipo-card tc-periodico <?= $tipo_actual === 'periodico' ? 'tc-sel' : '' ?>"
                        data-tipo="periodico">
                        <input type="radio" class="tc-radio" name="tipo_contrato" value="periodico"
                            <?= $tipo_actual === 'periodico' ? 'checked' : '' ?>>
                        <div class="tc-icon"><i class="bi bi-arrow-repeat"></i></div>
                        <div class="tc-label">Periódico</div>
                        <div class="tc-desc">Cada N meses</div>
                        <div class="tc-check"><i class="bi bi-check-circle-fill"></i></div>
                    </div>
                    <div class="tipo-card tc-rotativo <?= $tipo_actual === 'rotativo' ? 'tc-sel' : '' ?>"
                        data-tipo="rotativo">
                        <input type="radio" class="tc-radio" name="tipo_contrato" value="rotativo"
                            <?= $tipo_actual === 'rotativo' ? 'checked' : '' ?>>
                        <div class="tc-icon"><i class="bi bi-people-fill"></i></div>
                        <div class="tc-label">Rotativo</div>
                        <div class="tc-desc">Alterna clientes</div>
                        <div class="tc-check"><i class="bi bi-check-circle-fill"></i></div>
                    </div>
                    <div class="tipo-card tc-sinfact <?= $tipo_actual === 'sin_factura' ? 'tc-sel' : '' ?>"
                        data-tipo="sin_factura">
                        <input type="radio" class="tc-radio" name="tipo_contrato" value="sin_factura"
                            <?= $tipo_actual === 'sin_factura' ? 'checked' : '' ?>>
                        <div class="tc-icon"><i class="bi bi-receipt"></i></div>
                        <div class="tc-label">Sin Factura</div>
                        <div class="tc-desc">Solo recibo</div>
                        <div class="tc-check"><i class="bi bi-check-circle-fill"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── SECCIÓN 2: Cliente principal ────────────────────────────── -->
        <div class="ec-card">
            <div class="ec-card-hdr">
                <span class="ec-card-title">
                    <i class="bi bi-person-vcard text-primary"></i>
                    Información del Cliente
                </span>
            </div>
            <div class="ec-card-body">
                <div class="row g-3">
                    <div class="col-md-7">
                        <label class="mf-label" id="lbl-receptor">
                            Cliente <span class="text-danger">*</span>
                        </label>
                        <select name="receptor_id" id="receptor_id" class="mf-select" required>
                            <option value="">— Seleccionar —</option>
                            <?php foreach ($clientes_lista as $cl): ?>
                                <option value="<?= $cl['id'] ?>" <?= $cl['id'] == $contrato['receptor_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cl['nombre']) ?>
                                    <?= $cl['rtn'] ? ' · RTN: ' . htmlspecialchars($cl['rtn']) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="mf-label">Nombre del Contrato <span class="text-danger">*</span></label>
                        <input type="text" name="nombre_contrato" class="mf-input"
                            value="<?= htmlspecialchars($contrato['nombre_contrato']) ?>" maxlength="200" required>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── SECCIÓN 3: Rotativos ─────────────────────────────────────── -->
        <div class="ec-card <?= $tipo_actual !== 'rotativo' ? 'd-none' : '' ?>" id="bloque-rotativos">
            <div class="ec-card-hdr">
                <span class="ec-card-title">
                    <i class="bi bi-people-fill text-warning"></i>
                    Ciclo de Clientes Rotativos
                </span>
                <button type="button" class="btn btn-sm btn-outline-warning" id="btnAgregarRotativo">
                    <i class="bi bi-plus-lg me-1"></i>Agregar turno
                </button>
            </div>
            <div class="ec-card-body">
                <div class="info-box ib-amber mb-3">
                    <i class="bi bi-arrow-repeat" style="flex-shrink:0"></i>
                    <div>El cobro rota en orden. Cada turno puede tener monto diferente.</div>
                </div>
                <div id="contenedor-rotativos">
                    <?php foreach ($rotativos_actuales as $idx => $rot): ?>
                        <div class="rot-item">
                            <button type="button"
                                class="btn btn-sm btn-outline-danger position-absolute top-0 end-0 m-2 btn-quitar-rot"
                                style="font-size:.72rem;padding:2px 7px">
                                <i class="bi bi-x-lg"></i>
                            </button>
                            <div class="d-flex align-items-center gap-2 mb-3">
                                <div class="rot-badge rot-num"><?= $idx + 1 ?></div>
                                <span class="fw-semibold text-muted" style="font-size:.82rem">Turno</span>
                            </div>
                            <div class="row g-2 align-items-end">
                                <div class="col-md-7">
                                    <label class="mf-label">Cliente <span class="text-danger">*</span></label>
                                    <select name="rotativos[<?= $idx ?>][receptor_id]" class="mf-select sel-rot" required>
                                        <option value="">— Seleccionar —</option>
                                        <?php foreach ($clientes_lista as $cl): ?>
                                            <option value="<?= $cl['id'] ?>"
                                                <?= $cl['id'] == $rot['receptor_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($cl['nombre']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-5">
                                    <label class="mf-label">Monto (L) <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">L</span>
                                        <input type="number" name="rotativos[<?= $idx ?>][monto]"
                                            class="form-control inp-rot-monto"
                                            value="<?= number_format((float)$rot['monto'], 2, '.', '') ?>" min="0" step="0.01"
                                            required>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ── SECCIÓN 4: Servicios ─────────────────────────────────────── -->
        <div class="ec-card" id="bloque-servicios">
            <div class="ec-card-hdr">
                <span class="ec-card-title">
                    <i class="bi bi-box-seam text-success"></i>
                    Servicios del Contrato
                </span>
                <button type="button" class="btn btn-sm btn-outline-success" id="btnAgregarSvc">
                    <i class="bi bi-plus-lg me-1"></i>Agregar servicio
                </button>
            </div>
            <div class="ec-card-body">
                <div id="contenedor-svc">
                    <?php foreach ($servicios_actuales as $idx => $svc): ?>
                        <div class="svc-item">
                            <button type="button"
                                class="btn btn-sm btn-outline-danger position-absolute top-0 end-0 m-2 btn-quitar-svc"
                                style="font-size:.72rem;padding:2px 7px">
                                <i class="bi bi-x-lg"></i>
                            </button>
                            <div class="row g-2 align-items-end">
                                <div class="col-md-7">
                                    <label class="mf-label">Servicio <span class="text-danger">*</span></label>
                                    <select name="servicios[<?= $idx ?>][producto_id]" class="mf-select sel-svc" required>
                                        <option value="">— Cargando… —</option>
                                    </select>
                                </div>
                                <div class="col-md-5">
                                    <label class="mf-label">Monto mensual (L) <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text">L</span>
                                        <input type="number" name="servicios[<?= $idx ?>][monto]"
                                            class="form-control inp-monto"
                                            value="<?= number_format((float)$svc['monto'], 2, '.', '') ?>" min="0" step="0.01"
                                            required>
                                    </div>
                                    <small class="text-muted precio-sug" style="font-size:.72rem">
                                        <?php if ((float)$svc['monto'] > 0): ?>Precio actual: L
                                        <?= number_format((float)$svc['monto'], 2) ?><?php endif; ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div id="resumen-total" class="mt-1">
                    <div class="total-bar">
                        <span style="opacity:.9"><i class="bi bi-check-circle-fill me-2"></i>Monto total:</span>
                        <span id="lbl-total" style="font-size:1.15rem">L 0.00</span>
                    </div>
                </div>
                <input type="hidden" name="monto_total" id="monto_total" value="0">
            </div>
        </div>

        <!-- ── SECCIÓN 5: Concepto recibo ─────────────────────────────── -->
        <div class="ec-card <?= $tipo_actual !== 'sin_factura' ? 'd-none' : '' ?>" id="bloque-concepto">
            <div class="ec-card-hdr">
                <span class="ec-card-title">
                    <i class="bi bi-receipt" style="color:#7c3aed"></i>
                    Concepto del Recibo
                </span>
            </div>
            <div class="ec-card-body">
                <label class="mf-label">Descripción del recibo <span class="text-danger">*</span></label>
                <textarea name="concepto_recibo" id="concepto_recibo" class="mf-input" rows="2"
                    placeholder="Ej: Servicio mensual de mantenimiento web" maxlength="300"
                    style="height:auto;resize:vertical"><?= htmlspecialchars($contrato['concepto_recibo'] ?? '') ?></textarea>
            </div>
        </div>

        <!-- ── SECCIÓN 6: Vigencia y condiciones ──────────────────────── -->
        <div class="ec-card">
            <div class="ec-card-hdr">
                <span class="ec-card-title">
                    <i class="bi bi-calendar3 text-info"></i>
                    Vigencia, Pago y Estado
                </span>
            </div>
            <div class="ec-card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="mf-label">Fecha Inicio <span class="text-danger">*</span></label>
                        <input type="date" name="fecha_inicio" class="mf-input" value="<?= $contrato['fecha_inicio'] ?>"
                            required>
                    </div>
                    <div class="col-md-3">
                        <label class="mf-label">Fecha Fin</label>
                        <input type="date" name="fecha_fin" id="fecha_fin" class="mf-input"
                            value="<?= $contrato['fecha_fin'] ?? '' ?>">
                        <small class="text-muted" style="font-size:.72rem">Vacío = indefinido</small>
                    </div>
                    <div class="col-md-3">
                        <label class="mf-label">Día de Cobro <span class="text-danger">*</span></label>
                        <select name="dia_pago" class="mf-select" required>
                            <?php for ($d = 1; $d <= 31; $d++): ?>
                                <option value="<?= $d ?>" <?= $d == $contrato['dia_pago'] ? 'selected' : '' ?>>
                                    Día <?= $d ?> de cada mes
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="mf-label">Estado</label>
                        <select name="estado" class="mf-select">
                            <option value="activo" <?= $contrato['estado'] === 'activo'   ? 'selected' : '' ?>>✅ Activo
                            </option>
                            <option value="pausado" <?= $contrato['estado'] === 'pausado'  ? 'selected' : '' ?>>⏸ Pausado
                            </option>
                            <option value="cancelado" <?= $contrato['estado'] === 'cancelado' ? 'selected' : '' ?>>❌ Cancelado
                            </option>
                            <option value="vencido" <?= $contrato['estado'] === 'vencido'  ? 'selected' : '' ?>>⌛ Vencido
                            </option>
                        </select>
                    </div>

                    <!-- Frecuencia (periódico y rotativo) -->
                    <div class="col-md-3 <?= !in_array($tipo_actual, ['periodico', 'rotativo']) ? 'd-none' : '' ?>"
                        id="grp-frecuencia">
                        <label class="mf-label" id="lbl-frecuencia">Frecuencia <span
                                class="text-danger">*</span></label>
                        <select name="frecuencia_meses" id="frecuencia_meses" class="mf-select">
                            <option value="1" <?= ($contrato['frecuencia_meses'] ?? 0) == 1 ? 'selected' : '' ?>>Mensual
                            </option>
                            <option value="2" <?= ($contrato['frecuencia_meses'] ?? 0) == 2 ? 'selected' : '' ?>>Cada 2 meses
                            </option>
                            <option value="3" <?= ($contrato['frecuencia_meses'] ?? 0) == 3 ? 'selected' : '' ?>>Cada 3 meses
                                (Trimestral)</option>
                            <option value="4" <?= ($contrato['frecuencia_meses'] ?? 0) == 4 ? 'selected' : '' ?>>Cada 4 meses
                            </option>
                            <option value="6" <?= ($contrato['frecuencia_meses'] ?? 0) == 6 ? 'selected' : '' ?>>Cada 6 meses
                                (Semestral)</option>
                            <option value="12" <?= ($contrato['frecuencia_meses'] ?? 0) == 12 ? 'selected' : '' ?>>Anual
                            </option>
                        </select>
                    </div>

                    <!-- Mes inicio ciclo -->
                    <div class="col-md-3 <?= !in_array($tipo_actual, ['periodico', 'rotativo']) ? 'd-none' : '' ?>"
                        id="grp-mes-inicio">
                        <label class="mf-label">Mes Inicio Ciclo <span class="text-danger">*</span></label>
                        <select name="mes_inicio_ciclo" id="mes_inicio_ciclo" class="mf-select">
                            <?php foreach ($meses as $i => $m): ?>
                                <option value="<?= $i + 1 ?>" <?= ($contrato['mes_inicio_ciclo'] ?? 0) == ($i + 1) ? 'selected' : '' ?>>
                                    <?= $m ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Preview cobros -->
                <div id="preview-periodico"
                    class="<?= !in_array($tipo_actual, ['periodico', 'rotativo']) ? 'd-none' : '' ?> mt-3">
                    <div class="info-box ib-blue">
                        <i class="bi bi-calendar2-week" style="flex-shrink:0;margin-top:1px"></i>
                        <div>
                            <strong id="lbl-preview-title">Próximos cobros:</strong>
                            <span id="txt-preview-cobros" class="ms-2 text-muted"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── SECCIÓN 7: Notas ────────────────────────────────────────── -->
        <div class="ec-card">
            <div class="ec-card-hdr">
                <span class="ec-card-title">
                    <i class="bi bi-sticky text-secondary"></i>
                    Notas <small class="text-muted fw-normal" style="font-size:.78rem">(opcional)</small>
                </span>
            </div>
            <div class="ec-card-body">
                <textarea name="notas" class="mf-input" rows="2"
                    placeholder="Condiciones especiales, acuerdos adicionales…" maxlength="1000"
                    style="height:auto;resize:vertical"><?= htmlspecialchars($contrato['notas'] ?? '') ?></textarea>
            </div>
        </div>

        <!-- Botones -->
        <div class="d-flex gap-3 justify-content-end align-items-center">
            <a href="contratos" class="btn btn-outline-secondary px-4">Cancelar</a>
            <button type="submit" class="btn-save" id="btnGuardar">
                <i class="bi bi-floppy-fill"></i> Guardar Cambios
            </button>
        </div>
    </form>
</div>

<!-- Template servicio -->
<template id="tpl-svc">
    <div class="svc-item">
        <button type="button" class="btn btn-sm btn-outline-danger position-absolute top-0 end-0 m-2 btn-quitar-svc"
            style="font-size:.72rem;padding:2px 7px">
            <i class="bi bi-x-lg"></i>
        </button>
        <div class="row g-2 align-items-end">
            <div class="col-md-7">
                <label class="mf-label">Servicio <span class="text-danger">*</span></label>
                <select name="servicios[][producto_id]" class="mf-select sel-svc" required>
                    <option value="">— Seleccionar servicio —</option>
                </select>
            </div>
            <div class="col-md-5">
                <label class="mf-label">Monto mensual (L) <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text">L</span>
                    <input type="number" name="servicios[][monto]" class="form-control inp-monto" min="0" step="0.01"
                        placeholder="0.00" required>
                </div>
                <small class="text-muted precio-sug" style="font-size:.72rem"></small>
            </div>
        </div>
    </div>
</template>

<!-- Template rotativo -->
<template id="tpl-rot">
    <div class="rot-item">
        <button type="button" class="btn btn-sm btn-outline-danger position-absolute top-0 end-0 m-2 btn-quitar-rot"
            style="font-size:.72rem;padding:2px 7px">
            <i class="bi bi-x-lg"></i>
        </button>
        <div class="d-flex align-items-center gap-2 mb-3">
            <div class="rot-badge rot-num">1</div>
            <span class="fw-semibold text-muted" style="font-size:.82rem">Turno</span>
        </div>
        <div class="row g-2 align-items-end">
            <div class="col-md-7">
                <label class="mf-label">Cliente <span class="text-danger">*</span></label>
                <select name="rotativos[][receptor_id]" class="mf-select sel-rot" required>
                    <option value="">— Seleccionar —</option>
                    <?php foreach ($clientes_lista as $cl): ?>
                        <option value="<?= $cl['id'] ?>"><?= htmlspecialchars($cl['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label class="mf-label">Monto (L) <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text">L</span>
                    <input type="number" name="rotativos[][monto]" class="form-control inp-rot-monto" min="0"
                        step="0.01" placeholder="0.00" required>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
    const CLIE_ID = <?= json_encode($cliente_id) ?>;
    const RECEPTOR_INIT = <?= json_encode((int)$contrato['receptor_id']) ?>;
    const SVCS_INIT = <?= json_encode(array_column($servicios_actuales, 'producto_id')) ?>;
    const TIPO_INIT = <?= json_encode($tipo_actual) ?>;

    let productos = [];
    let svcIndex = <?= count($servicios_actuales) ?>;
    let rotIndex = <?= max(count($rotativos_actuales), 0) ?>;
    let tipoActual = TIPO_INIT;

    /* ── Tipo selector ───────────────────────────────────────────────── */
    document.querySelectorAll('.tipo-card').forEach(card => {
        card.addEventListener('click', () => {
            document.querySelectorAll('.tipo-card').forEach(c => c.classList.remove('tc-sel'));
            card.classList.add('tc-sel');
            tipoActual = card.dataset.tipo;
            card.querySelector('.tc-radio').checked = true;
            actualizarUI(tipoActual);
        });
    });

    function actualizarUI(tipo) {
        const esTodos = tipo === 'rotativo' || tipo === 'sin_factura';
        const esPeriodFq = tipo === 'periodico' || tipo === 'rotativo';
        document.getElementById('bloque-rotativos').classList.toggle('d-none', tipo !== 'rotativo');
        document.getElementById('bloque-concepto').classList.toggle('d-none', tipo !== 'sin_factura');
        document.getElementById('grp-frecuencia').classList.toggle('d-none', !esPeriodFq);
        document.getElementById('grp-mes-inicio').classList.toggle('d-none', !esPeriodFq);
        document.getElementById('preview-periodico').classList.toggle('d-none', !esPeriodFq);
        document.getElementById('lbl-receptor').innerHTML =
            tipo === 'rotativo' ?
            'Cliente principal (referencia) <span class="text-muted fw-normal">(opcional)</span>' :
            'Cliente <span class="text-danger">*</span>';
        const lblPrev = document.getElementById('lbl-preview-title');
        if (lblPrev) lblPrev.textContent = tipo === 'rotativo' ? 'Próximos ciclos:' : 'Próximos cobros:';
        const lbl = document.getElementById('lbl-frecuencia');
        if (lbl) lbl.innerHTML = (tipo === 'rotativo' ? 'Frecuencia de rotación' : 'Frecuencia') +
            ' <span class="text-danger">*</span>';
        if (tipo === 'rotativo' &&
            document.querySelectorAll('#contenedor-rotativos .rot-item').length === 0) {
            agregarRotativo();
            agregarRotativo();
        }
        if (esTodos && productos.length === 0) cargarTodosProductos();
        if (!esTodos && tipo !== TIPO_INIT) {
            // Volvemos a modo receptor
            productos = [];
            if (RECEPTOR_INIT) cargarProductosPorReceptor(RECEPTOR_INIT, true);
        }
        if (esPeriodFq) actualizarPreview();
    }

    /* ── Cargar productos ────────────────────────────────────────────── */
    function cargarProductosPorReceptor(receptorId, esInicial = false) {
        fetch(`../../includes/api/productos_por_receptor.php?receptor_id=${receptorId}`)
            .then(r => r.json())
            .then(prods => {
                productos = prods;
                document.querySelectorAll('.sel-svc').forEach((sel, i) => {
                    poblarSelect(sel, prods, esInicial ? (SVCS_INIT[i] ?? null) : null);
                });
                recalcTotal();
                adjuntarEventosSvc();
            })
            .catch(() => Swal.fire('Error', 'No se pudieron cargar los servicios.', 'error'));
    }

    function cargarTodosProductos() {
        fetch(`../../includes/api/productos_por_receptor.php?receptor_id=0&todos=1`)
            .then(r => r.json())
            .then(prods => {
                productos = prods;
                document.querySelectorAll('.sel-svc').forEach((sel, i) => {
                    poblarSelect(sel, prods, SVCS_INIT[i] ?? null);
                });
                recalcTotal();
                adjuntarEventosSvc();
            }).catch(() => {});
    }

    function poblarSelect(sel, prods, preselId = null) {
        const actual = preselId ?? sel.value;
        sel.innerHTML = '<option value="">— Seleccionar servicio —</option>';
        prods.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p.id;
            opt.textContent = p.nombre + (parseFloat(p.precio) > 0 ? ` — L${parseFloat(p.precio).toFixed(2)}` : '');
            opt.dataset.precio = p.precio;
            if (p.id == actual) opt.selected = true;
            sel.appendChild(opt);
        });
    }

    document.getElementById('receptor_id').addEventListener('change', function() {
        if (!this.value) return;
        cargarProductosPorReceptor(this.value, false);
    });

    /* ── Agregar servicio ────────────────────────────────────────────── */
    document.getElementById('btnAgregarSvc').addEventListener('click', agregarSvc);

    function agregarSvc() {
        if (!productos.length) {
            Swal.fire('Aviso', 'No hay servicios disponibles.', 'warning');
            return;
        }
        const tpl = document.getElementById('tpl-svc');
        const clone = tpl.content.cloneNode(true);
        const item = clone.querySelector('.svc-item');
        const idx = svcIndex++;
        item.querySelectorAll('[name]').forEach(el => {
            el.name = el.name.replace('[]', `[${idx}]`);
        });
        const sel = item.querySelector('.sel-svc');
        poblarSelect(sel, productos, null);
        adjuntarEventosFila(item);
        document.getElementById('contenedor-svc').appendChild(item);
        recalcTotal();
    }

    function adjuntarEventosSvc() {
        document.querySelectorAll('.svc-item').forEach(f => adjuntarEventosFila(f));
    }

    function adjuntarEventosFila(fila) {
        const sel = fila.querySelector('.sel-svc');
        const input = fila.querySelector('.inp-monto');
        const btn = fila.querySelector('.btn-quitar-svc');
        sel?.addEventListener('change', function() {
            const precio = parseFloat(this.selectedOptions[0]?.dataset.precio) || 0;
            if (precio > 0) {
                input.value = precio.toFixed(2);
                const sug = this.closest('.svc-item').querySelector('.precio-sug');
                if (sug) sug.textContent = `Precio base: L ${precio.toFixed(2)}`;
            }
            recalcTotal();
        });
        input?.addEventListener('input', recalcTotal);
        btn?.addEventListener('click', function() {
            if (document.querySelectorAll('#contenedor-svc .svc-item').length > 1) {
                this.closest('.svc-item').remove();
                recalcTotal();
            } else {
                Swal.fire('Aviso', 'Debe haber al menos un servicio.', 'warning');
            }
        });
    }

    /* ── Agregar rotativo ────────────────────────────────────────────── */
    document.getElementById('btnAgregarRotativo').addEventListener('click', agregarRotativo);

    function agregarRotativo() {
        const tpl = document.getElementById('tpl-rot');
        const clone = tpl.content.cloneNode(true);
        const item = clone.querySelector('.rot-item');
        const idx = rotIndex++;
        item.querySelectorAll('[name]').forEach(el => {
            el.name = el.name.replace('[]', `[${idx}]`);
        });
        item.querySelector('.rot-num').textContent = idx + 1;
        item.querySelector('.inp-rot-monto').addEventListener('input', recalcTotal);
        item.querySelector('.btn-quitar-rot').addEventListener('click', function() {
            const items = document.querySelectorAll('#contenedor-rotativos .rot-item');
            if (items.length <= 2) {
                Swal.fire('Aviso', 'Mínimo 2 turnos.', 'warning');
                return;
            }
            this.closest('.rot-item').remove();
            document.querySelectorAll('#contenedor-rotativos .rot-num').forEach((e, i) => e.textContent = i + 1);
            recalcTotal();
        });
        document.getElementById('contenedor-rotativos').appendChild(item);
    }

    /* ── Total ───────────────────────────────────────────────────────── */
    function recalcTotal() {
        let total = 0;
        document.querySelectorAll('.inp-monto').forEach(i => total += parseFloat(i.value) || 0);
        document.getElementById('monto_total').value = total.toFixed(2);
        document.getElementById('lbl-total').textContent = 'L ' + total.toLocaleString('es-HN', {
            minimumFractionDigits: 2
        });
    }

    /* ── Preview cobros ──────────────────────────────────────────────── */
    document.getElementById('frecuencia_meses').addEventListener('change', actualizarPreview);
    document.getElementById('mes_inicio_ciclo').addEventListener('change', actualizarPreview);

    function actualizarPreview() {
        const freq = parseInt(document.getElementById('frecuencia_meses').value) || 3;
        const mesI = parseInt(document.getElementById('mes_inicio_ciclo').value) || (new Date().getMonth() + 1);
        const meses = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
        const anio = new Date().getFullYear();
        const cobros = [];
        for (let i = 0; i < 5; i++) {
            const mesAbs = (mesI - 1 + freq * i);
            cobros.push(meses[mesAbs % 12] + ' ' + (anio + Math.floor(mesAbs / 12)));
        }
        document.getElementById('txt-preview-cobros').textContent = cobros.join(' → ') + ' → …';
    }

    /* ── Submit ──────────────────────────────────────────────────────── */
    document.getElementById('formEditar').addEventListener('submit', function(e) {
        e.preventDefault();
        const tipo = tipoActual;
        const rid = document.getElementById('receptor_id').value;
        if (tipo !== 'rotativo' && !rid)
            return Swal.fire({
                icon: 'warning',
                title: 'Falta el cliente',
                text: 'Selecciona un cliente.'
            });
        if (!document.querySelector('[name="nombre_contrato"]').value.trim())
            return Swal.fire({
                icon: 'warning',
                title: 'Nombre requerido',
                text: 'Escribe un nombre.'
            });
        if (tipo === 'sin_factura' && !document.getElementById('concepto_recibo').value.trim())
            return Swal.fire({
                icon: 'warning',
                title: 'Falta el concepto',
                text: 'Escribe el concepto del recibo.'
            });
        if (tipo === 'rotativo') {
            const turnos = document.querySelectorAll('#contenedor-rotativos .rot-item');
            if (turnos.length < 2)
                return Swal.fire({
                    icon: 'warning',
                    title: 'Sin turnos',
                    text: 'Mínimo 2 turnos rotativos.'
                });
            let err = false;
            turnos.forEach(t => {
                if (!t.querySelector('.sel-rot').value) err = true;
            });
            if (err) return Swal.fire({
                icon: 'warning',
                title: 'Datos incompletos',
                text: 'Todos los turnos necesitan cliente.'
            });
        }
        const filas = document.querySelectorAll('#contenedor-svc .svc-item');
        if (filas.length === 0 && tipo !== 'sin_factura')
            return Swal.fire({
                icon: 'warning',
                title: 'Sin servicios',
                text: 'Agrega al menos un servicio.'
            });
        let totalFinal = 0,
            err = false;
        filas.forEach(f => {
            const prod = f.querySelector('.sel-svc').value,
                monto = parseFloat(f.querySelector('.inp-monto').value) || 0;
            if (!prod || monto <= 0) err = true;
            totalFinal += monto;
        });
        if (err && tipo !== 'sin_factura')
            return Swal.fire({
                icon: 'warning',
                title: 'Datos incompletos',
                text: 'Todos los servicios necesitan producto y monto.'
            });
        const fi = document.querySelector('[name="fecha_inicio"]').value;
        const ff = document.getElementById('fecha_fin').value;
        if (!fi) return Swal.fire({
            icon: 'warning',
            title: 'Fecha requerida',
            text: 'La fecha de inicio es obligatoria.'
        });
        if (ff && ff < fi) return Swal.fire({
            icon: 'error',
            title: 'Fechas inválidas',
            text: 'La fecha fin no puede ser anterior al inicio.'
        });

        if (tipo !== 'rotativo') {
            document.getElementById('monto_total').value = totalFinal.toFixed(2);
        } else {
            let sumRot = 0;
            const cnt = document.querySelectorAll('#contenedor-rotativos .rot-item').length;
            document.querySelectorAll('.inp-rot-monto').forEach(i => sumRot += parseFloat(i.value) || 0);
            document.getElementById('monto_total').value = (sumRot / Math.max(cnt, 1)).toFixed(2);
        }

        const btn = document.getElementById('btnGuardar');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Guardando…';

        fetch('includes/contrato_actualizar.php', {
                method: 'POST',
                body: new FormData(this)
            })
            .then(r => r.json()).then(d => {
                if (d.success) {
                    Swal.fire({
                            icon: 'success',
                            title: '¡Cambios guardados!',
                            text: 'El contrato fue actualizado.',
                            confirmButtonText: 'Ver contratos'
                        })
                        .then(() => window.location.href = 'contratos');
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error al guardar',
                        text: d.error || 'Error inesperado.'
                    });
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-floppy-fill"></i> Guardar Cambios';
                }
            }).catch(() => {
                Swal.fire({
                    icon: 'error',
                    title: 'Error de conexión'
                });
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-floppy-fill"></i> Guardar Cambios';
            });
    });

    /* ── Init ────────────────────────────────────────────────────────── */
    document.addEventListener('DOMContentLoaded', () => {
        const esTodos = TIPO_INIT === 'rotativo' || TIPO_INIT === 'sin_factura';
        if (esTodos) cargarTodosProductos();
        else if (RECEPTOR_INIT) cargarProductosPorReceptor(RECEPTOR_INIT, true);
        actualizarPreview();
        adjuntarEventosSvc();
        // Eventos de quitar en rotativos iniciales
        document.querySelectorAll('#contenedor-rotativos .btn-quitar-rot').forEach(btn => {
            btn.addEventListener('click', function() {
                const items = document.querySelectorAll('#contenedor-rotativos .rot-item');
                if (items.length <= 2) {
                    Swal.fire('Aviso', 'Mínimo 2 turnos.', 'warning');
                    return;
                }
                this.closest('.rot-item').remove();
                document.querySelectorAll('#contenedor-rotativos .rot-num').forEach((e, i) => e
                    .textContent = i + 1);
                recalcTotal();
            });
        });
        document.querySelectorAll('.inp-rot-monto').forEach(i => i.addEventListener('input', recalcTotal));
    });
</script>

<?php require_once '../../includes/templates/footer.php'; ?>