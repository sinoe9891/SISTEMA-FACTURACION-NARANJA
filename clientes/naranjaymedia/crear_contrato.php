<?php
$titulo = 'Nuevo Contrato';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';
require_once '../../includes/templates/header.php';

$cliente_id = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);

$stmtClientes = $pdo->prepare("
    SELECT id, nombre, rtn FROM clientes_factura
    WHERE cliente_id = ? ORDER BY nombre ASC
");
$stmtClientes->execute([$cliente_id]);
$clientes_lista = $stmtClientes->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
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

    .cc-wrap {
        max-width: 860px;
        margin: 0 auto;
        padding: 1.5rem 0 4rem;
    }

    /* Hero */
    .cc-hero {
        background: linear-gradient(135deg, #0f766e 0%, #4f46e5 100%);
        border-radius: var(--radius);
        padding: 1.6rem 2rem;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1.75rem;
        box-shadow: 0 8px 32px rgba(15, 118, 110, .2);
        position: relative;
        overflow: hidden;
    }

    .cc-hero::before {
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

    .cc-hero-title {
        font-size: 1.35rem;
        font-weight: 800;
        margin: 0;
    }

    .cc-hero-sub {
        font-size: .82rem;
        opacity: .78;
        margin: .2rem 0 0;
    }

    /* Steps indicator */
    .cc-steps {
        display: flex;
        align-items: center;
        gap: 0;
        margin-bottom: 1.75rem;
        background: var(--surface-2);
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: .5rem;
        overflow-x: auto;
    }

    .cc-step {
        flex: 1;
        min-width: 100px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: .5rem;
        padding: .6rem .5rem;
        border-radius: 8px;
        font-size: .78rem;
        font-weight: 600;
        color: var(--muted);
        transition: all var(--tr);
        white-space: nowrap;
    }

    .cc-step.active {
        background: var(--brand);
        color: #fff;
        box-shadow: 0 2px 8px rgba(15, 118, 110, .3);
    }

    .cc-step.done {
        background: var(--brand-lt);
        color: var(--brand);
    }

    .cc-step-num {
        width: 22px;
        height: 22px;
        border-radius: 50%;
        border: 2px solid currentColor;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: .7rem;
        font-weight: 700;
        flex-shrink: 0;
    }

    .cc-step.active .cc-step-num {
        background: rgba(255, 255, 255, .25);
        border-color: transparent;
    }

    .cc-step.done .cc-step-num {
        background: var(--brand);
        color: #fff;
        border-color: var(--brand);
    }

    /* Cards */
    .cc-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow-sm);
        margin-bottom: 1.25rem;
        overflow: hidden;
        transition: box-shadow var(--tr);
    }

    .cc-card:hover {
        box-shadow: var(--shadow-md);
    }

    .cc-card-hdr {
        padding: .9rem 1.4rem;
        border-bottom: 1px solid var(--border);
        background: var(--surface-2);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .75rem;
    }

    .cc-card-title {
        font-size: .92rem;
        font-weight: 700;
        color: var(--text);
        display: flex;
        align-items: center;
        gap: .5rem;
    }

    .cc-card-body {
        padding: 1.25rem 1.4rem;
    }

    /* Labels e inputs */
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

    /* colores por tipo */
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

    /* Servicio items */
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

    /* Rotativo turno items */
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

    /* Resumen total */
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

    /* Btn guardar */
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

    /* aviso clientes sin cargar */
    .aviso-cliente {
        text-align: center;
        padding: 2rem;
        color: var(--muted);
        font-size: .85rem;
    }
</style>

<div class="container-xxl cc-wrap">

    <!-- Hero -->
    <div class="cc-hero">
        <div>
            <h4 class="cc-hero-title">📄 Nuevo Contrato</h4>
            <p class="cc-hero-sub">Define el tipo, servicios, vigencia y condiciones de cobro</p>
        </div>
        <a href="contratos" class="btn btn-sm"
            style="background:rgba(255,255,255,.18);color:#fff;border:1px solid rgba(255,255,255,.3);font-weight:600">
            <i class="bi bi-arrow-left me-1"></i>Volver
        </a>
    </div>

    <!-- Steps -->
    <div class="cc-steps">
        <div class="cc-step active" id="step-ind-1">
            <div class="cc-step-num">1</div> Tipo y Cliente
        </div>
        <div class="cc-step" id="step-ind-2">
            <div class="cc-step-num">2</div> Servicios
        </div>
        <div class="cc-step" id="step-ind-3">
            <div class="cc-step-num">3</div> Vigencia y Cobro
        </div>
        <div class="cc-step" id="step-ind-4">
            <div class="cc-step-num">4</div> Confirmar
        </div>
    </div>

    <?php if (empty($clientes_lista)): ?>
        <div class="cc-card">
            <div class="cc-card-body text-center py-5">
                <i class="bi bi-people" style="font-size:3rem;opacity:.2;display:block;margin-bottom:.75rem"></i>
                <div class="fw-bold mb-2">No hay clientes registrados</div>
                <a href="crear_cliente" class="btn btn-success">
                    <i class="bi bi-plus me-1"></i>Agregar primer cliente
                </a>
            </div>
        </div>
    <?php else: ?>

        <form id="formContrato" novalidate>

            <!-- ── SECCIÓN 1: Tipo de Contrato ──────────────────────────── -->
            <div class="cc-card">
                <div class="cc-card-hdr">
                    <span class="cc-card-title">
                        <i class="bi bi-lightning-charge-fill text-warning"></i>
                        Tipo de Contrato
                    </span>
                </div>
                <div class="cc-card-body">
                    <div class="tipo-grid">

                        <!-- Estándar -->
                        <div class="tipo-card tc-estandar tc-sel" data-tipo="estandar">
                            <input type="radio" class="tc-radio" name="tipo_contrato" id="tc_estandar" value="estandar"
                                checked>
                            <div class="tc-icon"><i class="bi bi-calendar-check-fill"></i></div>
                            <div class="tc-label">Estándar</div>
                            <div class="tc-desc">Cobro mensual fijo</div>
                            <div class="tc-check"><i class="bi bi-check-circle-fill"></i></div>
                        </div>

                        <!-- Periódico -->
                        <div class="tipo-card tc-periodico" data-tipo="periodico">
                            <input type="radio" class="tc-radio" name="tipo_contrato" id="tc_periodico" value="periodico">
                            <div class="tc-icon"><i class="bi bi-arrow-repeat"></i></div>
                            <div class="tc-label">Periódico</div>
                            <div class="tc-desc">Cada 2, 3, 6 meses…</div>
                            <div class="tc-check"><i class="bi bi-check-circle-fill"></i></div>
                        </div>

                        <!-- Rotativo -->
                        <div class="tipo-card tc-rotativo" data-tipo="rotativo">
                            <input type="radio" class="tc-radio" name="tipo_contrato" id="tc_rotativo" value="rotativo">
                            <div class="tc-icon"><i class="bi bi-people-fill"></i></div>
                            <div class="tc-label">Rotativo</div>
                            <div class="tc-desc">Alterna entre clientes</div>
                            <div class="tc-check"><i class="bi bi-check-circle-fill"></i></div>
                        </div>

                        <!-- Sin factura -->
                        <div class="tipo-card tc-sinfact" data-tipo="sin_factura">
                            <input type="radio" class="tc-radio" name="tipo_contrato" id="tc_sinfact" value="sin_factura">
                            <div class="tc-icon"><i class="bi bi-receipt"></i></div>
                            <div class="tc-label">Sin Factura</div>
                            <div class="tc-desc">Solo genera recibo</div>
                            <div class="tc-check"><i class="bi bi-check-circle-fill"></i></div>
                        </div>

                    </div>

                    <!-- Info contextual por tipo -->
                    <div id="info-estandar" class="info-box ib-green mt-3">
                        <i class="bi bi-info-circle-fill" style="margin-top:1px;flex-shrink:0"></i>
                        <div><strong>Cobro mensual:</strong> Se genera una factura el mismo día de cada mes. La factura se
                            emite con CAI/SAR.</div>
                    </div>
                    <div id="info-periodico" class="info-box ib-blue mt-3 d-none">
                        <i class="bi bi-info-circle-fill" style="margin-top:1px;flex-shrink:0"></i>
                        <div><strong>Cobro periódico:</strong> Se cobra cada N meses (trimestral, semestral, etc.). Define
                            la frecuencia y el mes de inicio del primer ciclo.</div>
                    </div>
                    <div id="info-rotativo" class="info-box ib-amber mt-3 d-none">
                        <i class="bi bi-info-circle-fill" style="margin-top:1px;flex-shrink:0"></i>
                        <div><strong>Cobro rotativo:</strong> El cobro alterna entre varios clientes. Ej: Mes 1 → Cliente A,
                            Mes 2 → Cliente B, Mes 3 → Cliente A… Cada cliente puede tener un monto diferente.</div>
                    </div>
                    <div id="info-sinfact" class="info-box ib-purple mt-3 d-none">
                        <i class="bi bi-info-circle-fill" style="margin-top:1px;flex-shrink:0"></i>
                        <div><strong>Sin factura SAR:</strong> Se emite un recibo interno numerado (REC-2026-001). No
                            consume CAI ni se reporta al SAR. Ideal para servicios informales o clientes sin RTN.</div>
                    </div>
                </div>
            </div>

            <!-- ── SECCIÓN 2: Cliente principal ─────────────────────────── -->
            <div class="cc-card" id="bloque-cliente-principal">
                <div class="cc-card-hdr">
                    <span class="cc-card-title">
                        <i class="bi bi-person-vcard text-primary"></i>
                        Información del Cliente
                    </span>
                </div>
                <div class="cc-card-body">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="mf-label" id="lbl-receptor">
                                Cliente <span class="text-danger">*</span>
                            </label>
                            <select name="receptor_id" id="receptor_id" class="mf-select" required>
                                <option value="">— Seleccionar cliente —</option>
                                <?php foreach ($clientes_lista as $cl): ?>
                                    <option value="<?= $cl['id'] ?>">
                                        <?= htmlspecialchars($cl['nombre']) ?>
                                        <?= $cl['rtn'] ? ' · RTN: ' . htmlspecialchars($cl['rtn']) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="aviso-cliente" class="mt-2 d-none">
                                <div class="info-box ib-amber py-2">
                                    <i class="bi bi-exclamation-triangle-fill" style="flex-shrink:0;margin-top:1px"></i>
                                    <span id="txt-aviso-cliente"></span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <label class="mf-label">Nombre del Contrato <span class="text-danger">*</span></label>
                            <input type="text" name="nombre_contrato" id="nombre_contrato" class="mf-input"
                                placeholder="Ej: Gestión Digital 2026" maxlength="200" required>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── SECCIÓN 3: Clientes rotativos (solo tipo rotativo) ────── -->
            <div class="cc-card d-none" id="bloque-rotativos">
                <div class="cc-card-hdr">
                    <span class="cc-card-title">
                        <i class="bi bi-people-fill text-warning"></i>
                        Ciclo de Clientes Rotativos
                    </span>
                    <button type="button" class="btn btn-sm btn-outline-warning" id="btnAgregarRotativo">
                        <i class="bi bi-plus-lg me-1"></i>Agregar turno
                    </button>
                </div>
                <div class="cc-card-body">
                    <div class="info-box ib-amber mb-3">
                        <i class="bi bi-arrow-repeat" style="flex-shrink:0"></i>
                        <div>El cobro rota en orden: Turno 1 → Turno 2 → Turno 3 → Turno 1…
                            Cada turno puede tener monto diferente.</div>
                    </div>
                    <div id="contenedor-rotativos">
                        <!-- Se genera dinámicamente -->
                    </div>
                </div>
            </div>

            <!-- ── SECCIÓN 4: Servicios ──────────────────────────────────── -->
            <div class="cc-card" id="bloque-servicios">
                <div class="cc-card-hdr">
                    <span class="cc-card-title">
                        <i class="bi bi-box-seam text-success"></i>
                        Servicios del Contrato
                    </span>
                    <button type="button" class="btn btn-sm btn-outline-success" id="btnAgregarSvc" disabled>
                        <i class="bi bi-plus-lg me-1"></i>Agregar servicio
                    </button>
                </div>
                <div class="cc-card-body">
                    <div id="aviso-sin-cliente" class="aviso-cliente">
                        <i class="bi bi-arrow-up-circle d-block mb-2" style="font-size:1.8rem;opacity:.3"></i>
                        Primero selecciona un cliente para cargar sus servicios disponibles.
                    </div>
                    <div id="contenedor-svc" class="d-none"></div>
                    <div id="resumen-total" class="d-none">
                        <div class="total-bar">
                            <span style="opacity:.9">
                                <i class="bi bi-check-circle-fill me-2"></i>Monto mensual total:
                            </span>
                            <span id="lbl-total" style="font-size:1.15rem">L 0.00</span>
                        </div>
                    </div>
                    <input type="hidden" name="monto_total" id="monto_total" value="0">
                </div>
            </div>

            <!-- ── SECCIÓN 5: Concepto de recibo (solo sin_factura) ─────── -->
            <div class="cc-card d-none" id="bloque-concepto">
                <div class="cc-card-hdr">
                    <span class="cc-card-title">
                        <i class="bi bi-receipt text-purple" style="color:#7c3aed"></i>
                        Concepto del Recibo
                    </span>
                </div>
                <div class="cc-card-body">
                    <label class="mf-label">
                        Descripción que aparecerá en el recibo <span class="text-danger">*</span>
                    </label>
                    <textarea name="concepto_recibo" id="concepto_recibo" class="mf-input" rows="2"
                        placeholder="Ej: Servicio mensual de mantenimiento web — período correspondiente" maxlength="300"
                        style="height:auto;resize:vertical"></textarea>
                    <small class="text-muted" style="font-size:.75rem">
                        Este texto aparece impreso en el recibo interno. No se reporta al SAR.
                    </small>
                </div>
            </div>

            <!-- ── SECCIÓN 6: Vigencia, Cobro y Periodicidad ─────────────── -->
            <div class="cc-card">
                <div class="cc-card-hdr">
                    <span class="cc-card-title">
                        <i class="bi bi-calendar3 text-info"></i>
                        Vigencia y Condiciones de Cobro
                    </span>
                </div>
                <div class="cc-card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="mf-label">Fecha Inicio <span class="text-danger">*</span></label>
                            <input type="date" name="fecha_inicio" class="mf-input" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="mf-label">Fecha Fin</label>
                            <input type="date" name="fecha_fin" id="fecha_fin" class="mf-input">
                            <small class="text-muted" style="font-size:.72rem">Vacío = indefinido</small>
                        </div>
                        <div class="col-md-3">
                            <label class="mf-label">Día de Cobro <span class="text-danger">*</span></label>
                            <select name="dia_pago" class="mf-select" required>
                                <?php for ($d = 1; $d <= 31; $d++): ?>
                                    <option value="<?= $d ?>" <?= $d === 1 ? 'selected' : '' ?>>
                                        Día <?= $d ?> de cada mes
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <!-- Frecuencia (solo periódico) -->
                        <div class="col-md-3 d-none" id="grp-frecuencia">
                            <label class="mf-label">Frecuencia <span class="text-danger">*</span></label>
                            <select name="frecuencia_meses" id="frecuencia_meses" class="mf-select">
                                <option value="2">Cada 2 meses</option>
                                <option value="3">Cada 3 meses (Trimestral)</option>
                                <option value="4">Cada 4 meses</option>
                                <option value="6">Cada 6 meses (Semestral)</option>
                                <option value="12">Anual</option>
                            </select>
                        </div>

                        <!-- Mes inicio ciclo (solo periódico) -->
                        <div class="col-md-3 d-none" id="grp-mes-inicio">
                            <label class="mf-label">Mes Primer Cobro <span class="text-danger">*</span></label>
                            <select name="mes_inicio_ciclo" id="mes_inicio_ciclo" class="mf-select">
                                <?php
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
                                foreach ($meses as $i => $m): ?>
                                    <option value="<?= $i + 1 ?>" <?= ($i + 1) == (int)date('n') ? 'selected' : '' ?>>
                                        <?= $m ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted" style="font-size:.72rem">
                                Mes en que se realiza el primer cobro del ciclo
                            </small>
                        </div>

                    </div>

                    <!-- Preview cobros periódico -->
                    <div id="preview-periodico" class="d-none mt-3">
                        <div class="info-box ib-blue">
                            <i class="bi bi-calendar2-week" style="flex-shrink:0;margin-top:1px"></i>
                            <div>
                                <strong>Próximos cobros proyectados:</strong>
                                <span id="txt-preview-cobros" class="ms-2 text-muted"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── SECCIÓN 7: Notas ──────────────────────────────────────── -->
            <div class="cc-card">
                <div class="cc-card-hdr">
                    <span class="cc-card-title">
                        <i class="bi bi-sticky text-secondary"></i>
                        Notas <small class="text-muted fw-normal" style="font-size:.78rem">(opcional)</small>
                    </span>
                </div>
                <div class="cc-card-body">
                    <textarea name="notas" class="mf-input" rows="2"
                        placeholder="Condiciones especiales, acuerdos adicionales…" maxlength="1000"
                        style="height:auto;resize:vertical"></textarea>
                </div>
            </div>

            <!-- ── Botones ───────────────────────────────────────────────── -->
            <div class="d-flex gap-3 justify-content-end align-items-center">
                <a href="contratos" class="btn btn-outline-secondary px-4">Cancelar</a>
                <button type="submit" class="btn-save" id="btnGuardar">
                    <i class="bi bi-floppy-fill"></i> Guardar Contrato
                </button>
            </div>

        </form>
    <?php endif; ?>
</div>

<!-- Template servicio -->
<template id="tpl-svc">
    <div class="svc-item">
        <button type="button" class="btn btn-sm btn-outline-danger
            position-absolute top-0 end-0 m-2 btn-quitar-svc" style="font-size:.72rem;padding:2px 7px">
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
        <button type="button" class="btn btn-sm btn-outline-danger
            position-absolute top-0 end-0 m-2 btn-quitar-rot" style="font-size:.72rem;padding:2px 7px">
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
                    <option value="">— Seleccionar cliente —</option>
                    <?php foreach ($clientes_lista as $cl): ?>
                        <option value="<?= $cl['id'] ?>">
                            <?= htmlspecialchars($cl['nombre']) ?>
                        </option>
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
    let productos = [];
    let svcIndex = 0;
    let rotIndex = 0;
    let tipoActual = 'estandar';

    /* ══ TIPO SELECTOR ══════════════════════════════════════════════ */
    document.querySelectorAll('.tipo-card').forEach(card => {
        card.addEventListener('click', () => {
            document.querySelectorAll('.tipo-card').forEach(c => c.classList.remove('tc-sel'));
            card.classList.add('tc-sel');
            const tipo = card.dataset.tipo;
            card.querySelector('.tc-radio').checked = true;
            tipoActual = tipo;
            actualizarUI(tipo);
        });
    });

    function actualizarUI(tipo) {
        // Info boxes
        ['estandar', 'periodico', 'rotativo', 'sinfact'].forEach(t => {
            const el = document.getElementById('info-' + t);
            if (el) el.classList.toggle('d-none', t !== (tipo === 'sin_factura' ? 'sinfact' : tipo));
        });
        // Bloques condicionales
        document.getElementById('bloque-rotativos').classList.toggle('d-none', tipo !== 'rotativo');
        document.getElementById('bloque-concepto').classList.toggle('d-none', tipo !== 'sin_factura');
        document.getElementById('grp-frecuencia').classList.toggle('d-none', tipo !== 'periodico');
        document.getElementById('grp-mes-inicio').classList.toggle('d-none', tipo !== 'periodico');
        document.getElementById('preview-periodico').classList.toggle('d-none', tipo !== 'periodico');
        // Label cliente principal
        document.getElementById('lbl-receptor').innerHTML =
            tipo === 'rotativo' ?
            'Cliente principal (referencia) <span class="text-muted fw-normal">(opcional en rotativo)</span>' :
            'Cliente <span class="text-danger">*</span>';
        // Si rotativo y no hay turnos aún, agregar 2 por defecto
        if (tipo === 'rotativo' &&
            document.querySelectorAll('#contenedor-rotativos .rot-item').length === 0) {
            agregarRotativo();
            agregarRotativo();
        }
        // Preview cobros
        if (tipo === 'periodico') actualizarPreviewCobros();
    }

    /* ══ SERVICIOS POR RECEPTOR ═════════════════════════════════════ */
    document.getElementById('receptor_id').addEventListener('change', function() {
        const rid = this.value;
        const aviso = document.getElementById('aviso-cliente');
        const txt = document.getElementById('txt-aviso-cliente');

        // Reset servicios
        document.getElementById('contenedor-svc').innerHTML = '';
        document.getElementById('contenedor-svc').classList.add('d-none');
        document.getElementById('aviso-sin-cliente').classList.remove('d-none');
        document.getElementById('resumen-total').classList.add('d-none');
        document.getElementById('btnAgregarSvc').disabled = true;
        productos = [];
        svcIndex = 0;
        recalcTotal();
        aviso.classList.add('d-none');

        if (!rid) return;

        // Verificar contratos activos
        fetch(`includes/contrato_verificar.php?receptor_id=${rid}`)
            .then(r => r.json()).then(res => {
                if (res.tiene_activo) {
                    txt.textContent =
                        `Este cliente ya tiene ${res.cantidad} contrato(s) activo(s). Verifica antes de continuar.`;
                    aviso.classList.remove('d-none');
                }
            }).catch(() => {});

        // Cargar productos
        fetch(`../../includes/api/productos_por_receptor.php?cliente_id=${CLIE_ID}&receptor_id=${rid}`)
            .then(r => r.json()).then(prods => {
                productos = prods;
                document.getElementById('aviso-sin-cliente').classList.add('d-none');
                document.getElementById('contenedor-svc').classList.remove('d-none');
                document.getElementById('resumen-total').classList.remove('d-none');
                document.getElementById('btnAgregarSvc').disabled = false;
                if (prods.length === 0) {
                    document.getElementById('contenedor-svc').innerHTML =
                        '<div class="text-center py-3 text-muted" style="font-size:.85rem">' +
                        '<i class="bi bi-box-seam d-block mb-2" style="font-size:1.5rem;opacity:.3"></i>' +
                        'No hay servicios registrados para este cliente.<br>' +
                        '<a href="productos_clientes" class="text-success small">Agregar servicios →</a></div>';
                } else {
                    agregarServicio();
                }
                recalcTotal();
            }).catch(() => Swal.fire('Error', 'No se pudieron cargar los servicios.', 'error'));
    });

    /* ══ AGREGAR SERVICIO ══════════════════════════════════════════ */
    document.getElementById('btnAgregarSvc').addEventListener('click', agregarServicio);

    function agregarServicio() {
        if (!productos.length) return;
        const tpl = document.getElementById('tpl-svc');
        const clone = tpl.content.cloneNode(true);
        const item = clone.querySelector('.svc-item');
        const idx = svcIndex++;

        // Renombrar fields
        item.querySelectorAll('[name]').forEach(el => {
            el.name = el.name.replace('[]', `[${idx}]`);
        });

        // Llenar select
        const sel = item.querySelector('.sel-svc');
        productos.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p.id;
            opt.textContent = p.nombre + (parseFloat(p.precio) > 0 ?
                ` — L${parseFloat(p.precio).toFixed(2)}` : '');
            opt.dataset.precio = p.precio;
            sel.appendChild(opt);
        });

        // Eventos
        sel.addEventListener('change', function() {
            const precio = parseFloat(this.selectedOptions[0]?.dataset.precio) || 0;
            const inp = this.closest('.svc-item').querySelector('.inp-monto');
            const sug = this.closest('.svc-item').querySelector('.precio-sug');
            if (precio > 0) {
                inp.value = precio.toFixed(2);
                if (sug) sug.textContent = `Precio base: L ${precio.toFixed(2)}`;
            }
            recalcTotal();
        });
        item.querySelector('.inp-monto').addEventListener('input', recalcTotal);
        item.querySelector('.btn-quitar-svc').addEventListener('click', function() {
            const items = document.querySelectorAll('#contenedor-svc .svc-item');
            if (items.length <= 1) {
                Swal.fire('Aviso', 'El contrato debe tener al menos un servicio.', 'warning');
                return;
            }
            this.closest('.svc-item').remove();
            recalcTotal();
        });

        document.getElementById('contenedor-svc').appendChild(item);
        recalcTotal();
    }

    /* ══ AGREGAR ROTATIVO ══════════════════════════════════════════ */
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
                Swal.fire('Aviso', 'Debe haber al menos 2 turnos rotativos.', 'warning');
                return;
            }
            this.closest('.rot-item').remove();
            // Renumerar
            document.querySelectorAll('#contenedor-rotativos .rot-num').forEach((el, i) => {
                el.textContent = i + 1;
            });
            recalcTotal();
        });

        document.getElementById('contenedor-rotativos').appendChild(item);
    }

    /* ══ RECALCULAR TOTAL ══════════════════════════════════════════ */
    function recalcTotal() {
        let total = 0;
        document.querySelectorAll('.inp-monto').forEach(i => total += parseFloat(i.value) || 0);
        document.getElementById('monto_total').value = total.toFixed(2);
        document.getElementById('lbl-total').textContent =
            'L ' + total.toLocaleString('es-HN', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
    }

    /* ══ PREVIEW COBROS PERIÓDICO ══════════════════════════════════ */
    document.getElementById('frecuencia_meses').addEventListener('change', actualizarPreviewCobros);
    document.getElementById('mes_inicio_ciclo').addEventListener('change', actualizarPreviewCobros);

    function actualizarPreviewCobros() {
        const freq = parseInt(document.getElementById('frecuencia_meses').value) || 3;
        const mesI = parseInt(document.getElementById('mes_inicio_ciclo').value) || parseInt('<?= date('n') ?>');
        const meses = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
        const anio = new Date().getFullYear();
        const cobros = [];
        for (let i = 0; i < 5; i++) {
            const mesAbs = (mesI - 1 + freq * i);
            const m = mesAbs % 12;
            const a = anio + Math.floor(mesAbs / 12);
            cobros.push(meses[m] + ' ' + a);
        }
        document.getElementById('txt-preview-cobros').textContent = cobros.join(' → ') + ' → …';
    }

    /* ══ STEPS INDICATOR ═══════════════════════════════════════════ */
    function setStep(n) {
        for (let i = 1; i <= 4; i++) {
            const el = document.getElementById('step-ind-' + i);
            el.classList.remove('active', 'done');
            if (i < n) el.classList.add('done');
            if (i === n) el.classList.add('active');
        }
    }
    // Paso dinámico según scroll / focus (simple)
    document.querySelector('[name="receptor_id"]').addEventListener('focus', () => setStep(1));
    document.getElementById('btnAgregarSvc').addEventListener('focus', () => setStep(2));
    document.querySelector('[name="fecha_inicio"]').addEventListener('focus', () => setStep(3));

    /* ══ SUBMIT ════════════════════════════════════════════════════ */
    document.getElementById('formContrato').addEventListener('submit', function(e) {
        e.preventDefault();
        setStep(4);

        // Validaciones
        const tipo = tipoActual;
        const rid = document.getElementById('receptor_id').value;
        if (tipo !== 'rotativo' && !rid)
            return Swal.fire({
                icon: 'warning',
                title: 'Falta el cliente',
                text: 'Selecciona un cliente antes de guardar.'
            });

        if (!document.querySelector('[name="nombre_contrato"]').value.trim())
            return Swal.fire({
                icon: 'warning',
                title: 'Falta el nombre',
                text: 'Escribe un nombre para el contrato.'
            });

        if (tipo === 'rotativo') {
            const turnos = document.querySelectorAll('#contenedor-rotativos .rot-item');
            if (turnos.length < 2)
                return Swal.fire({
                    icon: 'warning',
                    title: 'Sin turnos',
                    'text': 'Agrega al menos 2 turnos rotativos.'
                });
            let invalido = false;
            turnos.forEach(t => {
                if (!t.querySelector('.sel-rot').value) invalido = true;
            });
            if (invalido)
                return Swal.fire({
                    icon: 'warning',
                    title: 'Datos incompletos',
                    text: 'Todos los turnos deben tener cliente seleccionado.'
                });
        }

        const filas = document.querySelectorAll('#contenedor-svc .svc-item');
        if (filas.length === 0 && tipo !== 'sin_factura')
            return Swal.fire({
                icon: 'warning',
                title: 'Sin servicios',
                text: 'Agrega al menos un servicio al contrato.'
            });

        let totalFinal = 0,
            invalido = false;
        filas.forEach(f => {
            const prod = f.querySelector('.sel-svc').value;
            const monto = parseFloat(f.querySelector('.inp-monto').value) || 0;
            if (!prod || monto <= 0) invalido = true;
            totalFinal += monto;
        });
        if (invalido && tipo !== 'sin_factura')
            return Swal.fire({
                icon: 'warning',
                title: 'Datos incompletos',
                text: 'Todos los servicios deben tener producto y monto mayor a 0.'
            });

        if (tipo === 'sin_factura' && !document.getElementById('concepto_recibo').value.trim())
            return Swal.fire({
                icon: 'warning',
                title: 'Falta el concepto',
                text: 'Escribe el concepto que aparecerá en el recibo.'
            });

        if (tipo === 'periodico' && !document.getElementById('frecuencia_meses').value)
            return Swal.fire({
                icon: 'warning',
                title: 'Falta la frecuencia',
                text: 'Selecciona cada cuántos meses se cobra.'
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
            let totalRot = 0;
            document.querySelectorAll('.inp-rot-monto').forEach(i => totalRot += parseFloat(i.value) || 0);
            document.getElementById('monto_total').value = (totalRot / Math.max(document.querySelectorAll(
                '.rot-item').length, 1)).toFixed(2);
        }

        const btn = document.getElementById('btnGuardar');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Guardando…';

        fetch('includes/contrato_guardar.php', {
                method: 'POST',
                body: new FormData(this)
            })
            .then(r => r.json()).then(data => {
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: '¡Contrato creado!',
                        text: 'El contrato fue guardado correctamente.',
                        confirmButtonText: 'Ver contratos'
                    }).then(() => window.location.href = 'contratos');
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error al guardar',
                        text: data.error || 'Error inesperado.'
                    });
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-floppy-fill"></i> Guardar Contrato';
                }
            }).catch(() => {
                Swal.fire({
                    icon: 'error',
                    title: 'Error de conexión'
                });
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-floppy-fill"></i> Guardar Contrato';
            });
    });

    // Init
    actualizarUI('estandar');
    actualizarPreviewCobros();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>