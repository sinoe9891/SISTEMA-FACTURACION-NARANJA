<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';
require_once '../../includes/dashboard.php';
require_once '../../includes/templates/header.php';

/** @var string   $fecha_inicio */
/** @var string   $fecha_fin */
/** @var array    $datos */
/** @var int      $total_facturas */
/** @var array    $ingresos */
/** @var array    $ingresos_anuales */
/** @var array    $resumen_receptores */
/** @var array    $detalle_receptores */
/** @var array    $contratos_proximos_pagos */
/** @var array    $contratos_por_vencer */
/** @var array    $datos_trimestrales */
/** @var array    $datos_semestrales */
/** @var array    $datos_anuales_reporte */
/** @var array    $datos_comp_trim */
/** @var array    $datos_comp_sem */
/** @var array    $anios_disponibles */
/** @var int      $anio_reporte */
/** @var string|null $fecha_desde_rep */
/** @var string|null $fecha_hasta_rep */
/** @var bool     $usar_rango_rep */
/** @var string   $rep_desde */
/** @var string   $rep_hasta */
/** @var string   $active_tab_rep */
/** @var array    $comp_trim_by */
/** @var array    $comp_sem_by */
/** @var array    $colores_comp_bg */
/** @var array    $colores_comp_brd */
/** @var string[] $trimestres_labels */
/** @var float[]  $trimestres_subtotal */
/** @var float[]  $trimestres_isv */
/** @var float[]  $trimestres_total */
/** @var array    $trimestres_tabla */
/** @var string[] $semestres_labels */
/** @var float[]  $semestres_subtotal */
/** @var float[]  $semestres_isv */
/** @var float[]  $semestres_total */
/** @var array    $semestres_tabla */
/** @var string[] $anuales_labels */
/** @var float[]  $anuales_subtotal */
/** @var float[]  $anuales_isv */
/** @var float[]  $anuales_total */
/** @var float    $trim_total_anio */
/** @var float    $trim_isv_anio */
/** @var array    $comp_trim_datasets_js */
/** @var array    $comp_sem_datasets_js */
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>
    :root {
        --brand: #1e40af;
        --brand-light: #eff6ff;
        --border: #e2e8f0;
        --surface: #fff;
        --surface-2: #f8fafc;
        --text-main: #1e293b;
        --text-muted: #64748b;
        --shadow-sm: 0 1px 3px rgba(0, 0, 0, .06);
        --shadow-md: 0 4px 16px rgba(0, 0, 0, .08);
        --radius: 14px;
        --radius-sm: 8px;
        --tr: .2s cubic-bezier(.4, 0, .2, 1);
    }

    .db-header {
        background: linear-gradient(135deg, #1e40af 0%, #1e3a8a 100%);
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

    .db-header::before {
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

    .db-header::after {
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

    .db-header-logo {
        max-height: 52px;
        border-radius: 8px;
        background: rgba(255, 255, 255, .15);
        padding: 4px;
    }

    .db-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .db-stat {
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

    .db-stat:hover {
        box-shadow: var(--shadow-md);
        transform: translateY(-2px);
    }

    .db-stat-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        flex-shrink: 0;
    }

    .si-blue {
        background: #dbeafe;
        color: #1e40af;
    }

    .si-green {
        background: #d1fae5;
        color: #059669;
    }

    .si-yellow {
        background: #fef9c3;
        color: #ca8a04;
    }

    .si-red {
        background: #fee2e2;
        color: #dc2626;
    }

    .si-purple {
        background: #ede9fe;
        color: #7c3aed;
    }

    .si-teal {
        background: #ccfbf1;
        color: #0f766e;
    }

    .db-stat-val {
        font-size: 1.3rem;
        font-weight: 700;
        color: var(--text-main);
        line-height: 1;
    }

    .db-stat-lbl {
        font-size: .72rem;
        color: var(--text-muted);
        margin-top: 2px;
    }

    .cai-vencido {
        background: #fef2f2;
        border: 1px solid #fecaca;
        border-radius: var(--radius-sm);
        padding: 1rem;
        display: flex;
        align-items: center;
        gap: .75rem;
    }

    .db-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow-sm);
        overflow: hidden;
        margin-bottom: 1.5rem;
    }

    .db-card-header {
        padding: 1rem 1.5rem;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: var(--surface-2);
        gap: 1rem;
        flex-wrap: wrap;
    }

    .db-card-title {
        font-weight: 700;
        font-size: .95rem;
        color: var(--text-main);
    }

    .contrato-card {
        border-radius: var(--radius-sm);
        overflow: hidden;
        box-shadow: var(--shadow-sm);
        border: 1px solid var(--border);
    }

    .contrato-card.urgente {
        border-left: 4px solid #dc2626 !important;
    }

    .contrato-card.alerta {
        border-left: 4px solid #d97706 !important;
    }
</style>

<div class="container-xxl mt-4 pb-5">

    <!-- Header -->
    <div class="db-header mb-4">
        <div>
            <h4 style="font-size:1.35rem;font-weight:700;margin:0"><?= $emoji ?> <?= $saludo ?>,
                <?= htmlspecialchars(USUARIO_NOMBRE) ?></h4>
            <p style="font-size:.82rem;opacity:.8;margin:.3rem 0 0">
                Sucursal: <strong><?= htmlspecialchars($nombre_establecimiento) ?></strong>
                &nbsp;·&nbsp; Rol: <?= htmlspecialchars(ucfirst($datos['rol'])) ?>
                &nbsp;·&nbsp; <?= htmlspecialchars($datos['cliente_nombre']) ?>
            </p>
        </div>
        <?php if (!empty($datos['logo_url'])): ?>
            <img src="<?= htmlspecialchars($datos['logo_url']) ?>" alt="Logo" class="db-header-logo">
        <?php endif; ?>
    </div>

    <!-- Filtro -->
    <div class="db-card mb-4">
        <div class="card-body py-3 px-4">
            <form method="POST" class="row g-2 align-items-end">
                <div class="col-12 col-sm-auto">
                    <label class="form-label small fw-semibold mb-1">Desde:</label>
                    <input type="date" name="fecha_inicio" class="form-control form-control-sm"
                        value="<?= htmlspecialchars($fecha_inicio) ?>">
                </div>
                <div class="col-12 col-sm-auto">
                    <label class="form-label small fw-semibold mb-1">Hasta:</label>
                    <input type="date" name="fecha_fin" class="form-control form-control-sm"
                        value="<?= htmlspecialchars($fecha_fin) ?>">
                </div>
                <div class="col-12 col-sm-auto">
                    <button class="btn btn-primary btn-sm" type="submit"><i
                            class="bi bi-funnel-fill me-1"></i>Filtrar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Alerta CAI -->
    <?php if (!empty($alerta_cai_vencido)): ?>
        <div class="alert alert-danger d-flex align-items-center gap-3 mb-4 rounded-3 shadow-sm">
            <i class="bi bi-exclamation-triangle-fill fs-4"></i>
            <div>⏰ Tu CAI está por vencer. Fecha límite: <strong><?= formatFechaLimite($fecha_limite) ?></strong></div>
        </div>
    <?php endif; ?>

    <!-- ══ Stats strip — TODOS LOS MONTOS CON 2 DECIMALES ══════════════════ -->
    <div class="db-stats">
        <div class="db-stat">
            <div class="db-stat-icon si-blue"><i class="bi bi-receipt-cutoff"></i></div>
            <div>
                <div class="db-stat-val"><?= (int)$total_facturas ?></div>
                <div class="db-stat-lbl">Facturas emitidas</div>
            </div>
        </div>
        <div class="db-stat">
            <div class="db-stat-icon si-green"><i class="bi bi-check-circle-fill"></i></div>
            <div>
                <div class="db-stat-val"><?= (int)$facturas_restantes ?></div>
                <div class="db-stat-lbl">Facturas restantes</div>
            </div>
        </div>
        <div class="db-stat">
            <div class="db-stat-icon si-yellow"><i class="bi bi-calendar-event-fill"></i></div>
            <div>
                <div class="db-stat-val" style="font-size:1rem;"><?= formatFechaLimite($fecha_limite) ?></div>
                <div class="db-stat-lbl">Límite CAI
                    <?php if ($dias_restantes_cai !== null): ?>(<?= (int)$dias_restantes_cai ?>d)<?php endif; ?></div>
            </div>
        </div>
        <!-- ← FIX: 2 decimales ↓ -->
        <div class="db-stat">
            <div class="db-stat-icon si-teal"><i class="bi bi-cash-coin"></i></div>
            <div>
                <div class="db-stat-val" style="font-size:.88rem;">L
                    <?= number_format((float)($totales_mes['total'] ?? 0), 2) ?></div>
                <div class="db-stat-lbl">Total <?= date('M Y') ?></div>
            </div>
        </div>
        <!-- ← FIX: 2 decimales ↓ -->
        <div class="db-stat">
            <div class="db-stat-icon si-purple"><i class="bi bi-graph-up-arrow"></i></div>
            <div>
                <div class="db-stat-val" style="font-size:.88rem;">L
                    <?= number_format((float)($totales_anio['total'] ?? 0), 2) ?></div>
                <div class="db-stat-lbl">Acumulado <?= date('Y') ?></div>
            </div>
        </div>
        <?php if (($cant_no_declaradas ?? 0) > 0): ?>
            <div class="db-stat" style="border-color:#fecaca;">
                <div class="db-stat-icon si-red"><i class="bi bi-exclamation-triangle-fill"></i></div>
                <div>
                    <div class="db-stat-val text-danger"><?= (int)$cant_no_declaradas ?></div>
                    <div class="db-stat-lbl">Sin declarar</div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($ingresos)): ?>

        <!-- Totales mes / año / pendientes de pago / no declaradas -->
        <div class="row g-3 mb-4">
            <div class="col-md-6 col-lg-3">
                <div class="db-card mb-0 h-100">
                    <div class="db-card-header"><span class="db-card-title"><i
                                class="bi bi-currency-dollar me-2 text-success"></i>Totales <?= date('F Y') ?></span></div>
                    <div class="card-body px-4 py-3">
                        <div class="d-flex justify-content-between mb-1"><span class="text-muted small">Subtotal</span><span
                                class="fw-semibold">L <?= number_format($totales_mes['subtotal'] ?? 0, 2) ?></span></div>
                        <div class="d-flex justify-content-between mb-1"><span class="text-muted small">ISV</span><span
                                class="fw-semibold">L <?= number_format($totales_mes['isv'] ?? 0, 2) ?></span></div>
                        <div class="d-flex justify-content-between pt-2 border-top mt-1"><span
                                class="fw-bold">Total</span><span class="fw-bold text-success">L
                                <?= number_format($totales_mes['total'] ?? 0, 2) ?></span></div>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="db-card mb-0 h-100">
                    <div class="db-card-header"><span class="db-card-title"><i
                                class="bi bi-calendar3 me-2 text-primary"></i>Año a la fecha <?= date('Y') ?></span></div>
                    <div class="card-body px-4 py-3">
                        <div class="d-flex justify-content-between mb-1"><span class="text-muted small">Subtotal</span><span
                                class="fw-semibold">L <?= number_format($totales_anio['subtotal'] ?? 0, 2) ?></span></div>
                        <div class="d-flex justify-content-between mb-1"><span class="text-muted small">ISV</span><span
                                class="fw-semibold">L <?= number_format($totales_anio['isv'] ?? 0, 2) ?></span></div>
                        <div class="d-flex justify-content-between pt-2 border-top mt-1"><span
                                class="fw-bold">Total</span><span class="fw-bold text-primary">L
                                <?= number_format($totales_anio['total'] ?? 0, 2) ?></span></div>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="db-card mb-0 h-100"
                    style="border-color:<?= ($cant_pendientes_pago ?? 0) > 0 ? '#fecaca' : 'var(--border)' ?>">
                    <div class="db-card-header"><span class="db-card-title"><i
                                class="bi bi-hourglass-split me-2 text-danger"></i>Pendientes de pago</span></div>
                    <div class="card-body px-4 py-3">
                        <?php if (($cant_pendientes_pago ?? 0) > 0): ?>
                            <div class="d-flex justify-content-between mb-1"><span class="text-muted small">Facturas</span><span
                                    class="fw-semibold"><?= (int)$cant_pendientes_pago ?></span></div>
                            <div class="d-flex justify-content-between pt-2 border-top mt-1"><span class="fw-bold">Monto
                                    adeudado</span><span class="fw-bold text-danger">L
                                    <?= number_format($monto_pendientes_pago ?? 0, 2) ?></span></div>
                        <?php else: ?>
                            <div class="text-success fw-semibold"><i class="bi bi-check-circle-fill me-1"></i>No hay facturas
                                pendientes de pago.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="db-card mb-0 h-100"
                    style="border-color:<?= ($cant_no_declaradas ?? 0) > 0 ? '#fecaca' : 'var(--border)' ?>">
                    <div class="db-card-header"><span class="db-card-title"><i
                                class="bi bi-exclamation-triangle me-2 text-<?= $color_alerta ?>"></i>Facturas no
                            declaradas</span></div>
                    <div class="card-body px-4 py-3 text-<?= $color_alerta ?>">
                        <?php if (($cant_no_declaradas ?? 0) > 0): ?>
                            <div class="mb-1"><strong><?= (int)$cant_no_declaradas ?></strong> facturas atrasadas</div>
                            <div class="mb-1">ISV pendiente: <strong>L <?= number_format($isv_pendiente ?? 0, 2) ?></strong>
                            </div>
                            <?php if (!empty($texto_meses)): ?><div class="small text-muted"><?= $texto_meses ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="text-success fw-semibold"><i class="bi bi-check-circle-fill me-1"></i>No hay meses
                                pendientes de declaración.</div>
                        <?php endif; ?>
                        <?php if (($cant_mes_actual ?? 0) > 0): ?>
                            <hr class="my-2 opacity-25">
                            <div class="small"><strong>Mes actual:</strong> <?= (int)$cant_mes_actual ?> facturas · ISV est. L
                                <?= number_format($isv_mes_actual ?? 0, 2) ?></div>
                            <div class="small text-muted mt-1"><i class="bi bi-lightbulb me-1"></i>Recuerda declarar antes del
                                30.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Contratos por vencer -->
        <?php if (!empty($contratos_dashboard)): ?>
            <?php if (!empty($contratos_por_vencer)): ?>
                <div class="db-card">
                    <div class="db-card-header">
                        <span class="db-card-title"><i class="bi bi-exclamation-triangle-fill me-2 text-danger"></i>Contratos por
                            Vencer <span class="badge bg-danger ms-1"><?= count($contratos_por_vencer) ?></span></span>
                    </div>
                    <div class="card-body p-3">
                        <div class="row g-3">
                            <?php foreach ($contratos_por_vencer as $cv):
                                $dias = (int)$cv['dias_restantes'];
                                $urg = $dias <= 1;
                                $cls = $urg ? 'urgente' : 'alerta';
                                $ico = $urg ? '🔴' : '🟡';
                                $txt = $dias === 0 ? '¡Vence HOY!' : "Faltan {$dias} día(s)";
                            ?>
                                <div class="col-md-6 col-lg-4">
                                    <div class="contrato-card <?= $cls ?> h-100">
                                        <div class="p-3 border-bottom bg-light d-flex justify-content-between align-items-center">
                                            <span class="fw-semibold small text-<?= $urg ? 'danger' : 'warning' ?>"><?= $ico ?>
                                                <?= $txt ?></span>
                                            <span
                                                class="badge bg-<?= $urg ? 'danger' : 'warning' ?> text-<?= $urg ? 'white' : 'dark' ?>"><?= htmlspecialchars($cv['fecha_fin']) ?></span>
                                        </div>
                                        <div class="p-3">
                                            <div class="fw-bold mb-1"><?= htmlspecialchars($cv['receptor_nombre']) ?></div>
                                            <div class="text-muted small mb-2"><i
                                                    class="bi bi-box me-1"></i><?= htmlspecialchars($cv['servicio_nombre']) ?></div>
                                            <div class="fw-bold">L <?= number_format((float)$cv['monto'], 2) ?> <span
                                                    class="text-muted small fw-normal">/ mes</span></div>
                                        </div>
                                        <div class="p-3 pt-0 d-flex gap-2">
                                            <a href="contratos" class="btn btn-sm btn-outline-secondary flex-fill"><i
                                                    class="fa-solid fa-eye me-1"></i>Ver</a>
                                            <?php if (!empty($cv['factura_pendiente_id'])): ?>
                                                <a href="ver_factura?id=<?= $cv['factura_pendiente_id'] ?>" target="_blank"
                                                    class="btn btn-sm btn-outline-info flex-fill"><i
                                                        class="fa-solid fa-file-invoice me-1"></i>Ver Factura</a>
                                            <?php else: ?>
                                                <?php if (($cv['tipo_contrato'] ?? '') === 'sin_factura'): ?>
                                                    <a href="generar_recibo?contrato_id=<?= $cv['id'] ?>"
                                                        class="btn btn-sm btn-outline-secondary flex-fill">
                                                        <i class="bi bi-receipt me-1"></i>Recibo
                                                    </a>
                                                <?php else: ?>
                                                    <a href="generar_factura?receptor_id=<?= $cv['receptor_id'] ?>&producto_id=<?= $cv['producto_id'] ?>&monto=<?= $cv['monto'] ?>&contrato_id=<?= $cv['id'] ?>"
                                                        class="btn btn-sm btn-success flex-fill"><i
                                                            class="fa-solid fa-file-invoice-dollar me-1"></i>Facturar</a>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Próximas fechas de cobro -->
            <div class="db-card">
                <div class="db-card-header">
                    <span class="db-card-title"><i class="bi bi-calendar-check-fill me-2 text-primary"></i>Próximas Fechas de
                        Cobro</span>
                    <a href="contratos" class="btn btn-sm btn-outline-primary">Ver todos <i
                            class="bi bi-arrow-right ms-1"></i></a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" style="font-size:.855rem">
                            <thead class="table-light">
                                <tr>
                                    <th>Cliente</th>
                                    <th class="d-none d-md-table-cell">Servicio</th>
                                    <th class="text-end">Monto</th>
                                    <th class="text-center">Próximo Cobro</th>
                                    <th class="text-center">Días</th>
                                    <th class="text-center d-none d-sm-table-cell">Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($contratos_proximos_pagos as $p):
                                    $dias = (int)$p['dias_para_pago'];
                                    $bCls = $dias <= 3 ? 'bg-danger' : ($dias <= 7 ? 'bg-warning text-dark' : ($dias <= 15 ? 'bg-info' : 'bg-secondary'));
                                    $ico = $dias <= 3 ? '🔴' : ($dias <= 7 ? '🟡' : ($dias <= 15 ? '🔵' : '⚪'));
                                ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?= htmlspecialchars($p['receptor_nombre']) ?></div>
                                            <?php if ($p['receptor_tel']): ?><small
                                                    class="text-muted"><?= htmlspecialchars($p['receptor_tel']) ?></small><?php endif; ?>
                                            <?php
                                            $tipoCls = ['estandar' => '#d1fae5;color:#065f46', 'periodico' => '#dbeafe;color:#1e40af', 'rotativo' => '#fef3c7;color:#92400e', 'sin_factura' => '#ede9fe;color:#5b21b6'];
                                            $tipoLbl = ['estandar' => 'Estándar', 'periodico' => 'Periódico', 'rotativo' => 'Rotativo', 'sin_factura' => 'Sin factura'];
                                            $tc = $p['tipo_contrato'] ?? 'estandar';
                                            if ($tc !== 'estandar'):
                                            ?>
                                                <span
                                                    style="font-size:.65rem;font-weight:600;background:<?= $tipoCls[$tc] ?? '#f1f5f9;color:#64748b' ?>;padding:1px 6px;border-radius:10px;display:inline-block;margin-top:2px">
                                                    <?= $tipoLbl[$tc] ?? $tc ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-muted small d-none d-md-table-cell">
                                            <?= htmlspecialchars($p['servicio_nombre']) ?></td>
                                        <td class="text-end fw-bold">L <?= number_format((float)$p['monto'], 2) ?></td>
                                        <td class="text-center">
                                            <div class="fw-semibold small"><?= htmlspecialchars($p['proxima_fecha_pago']) ?></div>
                                            <small class="text-muted">Día <?= (int)$p['dia_pago'] ?></small>
                                        </td>
                                        <td class="text-center"><span class="badge <?= $bCls ?>"><?= $ico ?>
                                                <?= $dias === 0 ? '¡Hoy!' : $dias . 'd' ?></span></td>
                                        <td class="text-center d-none d-sm-table-cell">
                                            <?php if (!empty($p['factura_pendiente_id'])): ?><a
                                                    href="ver_factura?id=<?= $p['factura_pendiente_id'] ?>" target="_blank"
                                                    class="btn btn-sm btn-outline-info"><i
                                                        class="fa-solid fa-file-invoice me-1"></i><span class="d-none d-md-inline">Ver
                                                        Factura</span></a>
                                            <?php elseif (($p['tipo_contrato'] ?? '') === 'sin_factura'): ?>
                                                <a href="generar_recibo?contrato_id=<?= $p['id'] ?>"
                                                    class="btn btn-sm btn-outline-secondary">
                                                    <i class="bi bi-receipt"></i>
                                                </a>
                                            <?php else: ?><a
                                                    href="generar_factura?receptor_id=<?= $p['receptor_id'] ?>&producto_id=<?= $p['producto_id'] ?>&monto=<?= $p['monto'] ?>&contrato_id=<?= $p['id'] ?>"
                                                    class="btn btn-sm btn-success"><i
                                                        class="fa-solid fa-file-invoice-dollar"></i></a><?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- CAIs activos -->
        <?php if (!empty($cais_activos)): ?>
            <div class="db-card">
                <div class="db-card-header"><span class="db-card-title"><i class="bi bi-shield-check me-2 text-success"></i>CAIs
                        Activos</span></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0" style="font-size:.855rem">
                            <thead class="table-light">
                                <tr>
                                    <th>CAI</th>
                                    <th>Rango</th>
                                    <th class="text-center">Restantes</th>
                                    <th>Fecha límite</th>
                                    <th class="text-center">Días</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cais_activos as $cai): ?>
                                    <tr>
                                        <td class="font-monospace small"><?= htmlspecialchars($cai['cai']) ?></td>
                                        <td class="small"><?= (int)$cai['rango_inicio'] ?> — <?= (int)$cai['rango_fin'] ?></td>
                                        <td
                                            class="text-center fw-bold <?= (int)$cai['restantes'] < 50 ? 'text-danger' : ((int)$cai['restantes'] < 200 ? 'text-warning' : 'text-success') ?>">
                                            <?= (int)$cai['restantes'] ?></td>
                                        <td class="small"><?= formatFechaLimite($cai['fecha_limite']) ?></td>
                                        <td class="text-center"><span
                                                class="badge <?= (int)$cai['dias_para_vencer'] <= 15 ? 'bg-danger' : ((int)$cai['dias_para_vencer'] <= 30 ? 'bg-warning text-dark' : 'bg-success') ?>"><?= (int)$cai['dias_para_vencer'] ?>d</span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Gráfico ingresos por mes -->
        <div class="db-card">
            <div class="db-card-header"><span class="db-card-title"><i
                        class="bi bi-bar-chart-fill me-2 text-primary"></i>Ingresos por Mes —
                    <?= htmlspecialchars($fecha_inicio) ?> → <?= htmlspecialchars($fecha_fin) ?></span></div>
            <div class="card-body p-4"><canvas id="graficoIngresos" height="110"></canvas></div>
        </div>

        <!-- Resumen por cliente facturado -->
        <div class="db-card">
            <div class="db-card-header">
                <span class="db-card-title"><i class="bi bi-table me-2 text-secondary"></i>Resumen por Cliente
                    Facturado</span>
                <small class="text-muted"><?= htmlspecialchars($fecha_inicio) ?> →
                    <?= htmlspecialchars($fecha_fin) ?></small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover table-sm align-middle mb-0" style="font-size:.855rem">
                        <thead class="table-light">
                            <tr>
                                <th>Cliente</th>
                                <th class="text-center">Servicios</th>
                                <th class="text-end">Subtotal (L)</th>
                                <th class="text-end">ISV (L)</th>
                                <th class="text-end">Total (L)</th>
                                <th class="text-center" style="width:60px">+</th>
                            </tr>
                        </thead>
                        <tbody id="accordionReceptores">
                            <?php $total_subtotal = $total_isv = $total_general = 0;
                            foreach ($resumen_receptores as $r): $rid = (int)$r['receptor_id'];
                                $total_subtotal += (float)$r['subtotal'];
                                $total_isv += (float)$r['isv'];
                                $total_general += (float)$r['total']; ?>
                                <tr>
                                    <td><?= htmlspecialchars($r['receptor_nombre'] ?? 'N/D') ?></td>
                                    <td class="text-center"><?= (int)($r['cantidad_servicios'] ?? 0) ?></td>
                                    <td class="text-end">L <?= number_format((float)$r['subtotal'], 2) ?></td>
                                    <td class="text-end">L <?= number_format((float)$r['isv'], 2) ?></td>
                                    <td class="text-end fw-bold">L <?= number_format((float)$r['total'], 2) ?></td>
                                    <td class="text-center">
                                        <button class="btn btn-outline-primary btn-sm toggle-receptor"
                                            style="width:32px;height:32px;padding:0;display:inline-flex;align-items:center;justify-content:center;"
                                            type="button" data-bs-toggle="collapse" data-bs-target="#det-<?= $rid ?>"><i
                                                class="bi bi-plus-lg"></i></button>
                                    </td>
                                </tr>
                                <tr class="bg-light">
                                    <td colspan="6" class="p-0">
                                        <div id="det-<?= $rid ?>" class="collapse detalle-receptor"
                                            data-bs-parent="#accordionReceptores">
                                            <div class="p-3">
                                                <div class="fw-bold mb-2">
                                                    <?= htmlspecialchars($r['receptor_nombre'] ?? 'Cliente') ?> <span
                                                        class="text-muted fw-normal small">(<?= (int)($r['cantidad_facturas'] ?? 0) ?>
                                                        factura(s))</span></div>
                                                <?php $listaFacturas = $detalle_receptores[$rid] ?? []; ?>
                                                <?php if (!empty($listaFacturas)): ?>
                                                    <div class="accordion accordion-flush" id="acc-facturas-<?= $rid ?>">
                                                        <?php $esAdmin = in_array(($datos['rol'] ?? ''), ['admin', 'superadmin'], true);
                                                        foreach ($listaFacturas as $fx):
                                                            $fid = (int)$fx['id'];
                                                            $isvFactura = (float)($fx['isv_15'] ?? 0) + (float)($fx['isv_18'] ?? 0);
                                                            $items = $fx['items'] ?? [];
                                                        ?>
                                                            <div class="accordion-item">
                                                                <h2 class="accordion-header"><button
                                                                        class="accordion-button collapsed py-2 d-flex align-items-center gap-2 toggle-factura"
                                                                        type="button" data-bs-toggle="collapse"
                                                                        data-bs-target="#c-<?= $rid ?>-<?= $fid ?>"><i
                                                                            class="bi bi-plus-lg icon-plusminus"></i>
                                                                        <div
                                                                            class="w-100 d-flex flex-wrap justify-content-between align-items-center gap-2">
                                                                            <div><span class="fw-semibold">Factura
                                                                                    <?= htmlspecialchars($fx['correlativo'] ?? $fid) ?></span><span
                                                                                    class="text-muted ms-2"><?= htmlspecialchars(substr($fx['fecha_emision'] ?? '', 0, 10)) ?></span>
                                                                            </div>
                                                                            <div class="ms-auto fw-bold">L
                                                                                <?= number_format((float)$fx['total'], 2) ?></div>
                                                                        </div>
                                                                    </button></h2>
                                                                <div id="c-<?= $rid ?>-<?= $fid ?>" class="accordion-collapse collapse"
                                                                    data-bs-parent="#acc-facturas-<?= $rid ?>">
                                                                    <div class="accordion-body pt-2">
                                                                        <div class="row g-2 mb-3">
                                                                            <div class="col-12 col-md-8">
                                                                                <div class="small text-muted">Subtotal: <strong>L
                                                                                        <?= number_format((float)$fx['subtotal'], 2) ?></strong>
                                                                                    · ISV: <strong>L
                                                                                        <?= number_format($isvFactura, 2) ?></strong> ·
                                                                                    Total: <strong>L
                                                                                        <?= number_format((float)$fx['total'], 2) ?></strong>
                                                                                </div>
                                                                            </div>
                                                                            <div class="col-12 col-md-4 text-md-end"><a
                                                                                    class="btn btn-sm btn-outline-secondary"
                                                                                    href="ver_factura?id=<?= $fid ?>" target="_blank"><i
                                                                                        class="bi bi-receipt me-1"></i>Ver
                                                                                    factura</a><?php if ($esAdmin): ?><a
                                                                                        class="btn btn-sm btn-outline-info ms-1"
                                                                                        href="editar_factura?id=<?= $fid ?>"
                                                                                        target="_blank"><i
                                                                                            class="bi bi-pencil-square me-1"></i>Editar</a><?php endif; ?>
                                                                            </div>
                                                                        </div>
                                                                        <?php if (!empty($items)): ?>
                                                                            <div class="table-responsive">
                                                                                <table
                                                                                    class="table table-sm table-bordered mb-0 align-middle"
                                                                                    style="font-size:.83rem">
                                                                                    <thead class="table-light">
                                                                                        <tr>
                                                                                            <th>Servicio / Producto</th>
                                                                                            <th class="text-end">Cant.</th>
                                                                                            <th class="text-end">P. Unit.</th>
                                                                                            <th class="text-end">Subtotal</th>
                                                                                            <th class="text-end">ISV%</th>
                                                                                        </tr>
                                                                                    </thead>
                                                                                    <tbody><?php foreach ($items as $it): ?><tr>
                                                                                                <td>
                                                                                                    <div class="fw-semibold">
                                                                                                        <?= htmlspecialchars($it['nombre_producto'] ?? 'SIN PRODUCTO') ?>
                                                                                                    </div>
                                                                                                    <?php if (!empty($it['descripcion_html'])): ?>
                                                                                                        <div class="text-muted small">
                                                                                                            <?= nl2br(htmlspecialchars($it['descripcion_html'])) ?>
                                                                                                        </div><?php endif; ?>
                                                                                                </td>
                                                                                                <td class="text-end">
                                                                                                    <?= (int)($it['cantidad'] ?? 0) ?></td>
                                                                                                <td class="text-end">L
                                                                                                    <?= number_format((float)($it['precio_unitario'] ?? 0), 2) ?>
                                                                                                </td>
                                                                                                <td class="text-end">L
                                                                                                    <?= number_format((float)($it['subtotal'] ?? 0), 2) ?>
                                                                                                </td>
                                                                                                <td class="text-end">
                                                                                                    <?= number_format((float)($it['isv_aplicado'] ?? 0), 2) ?>
                                                                                                </td>
                                                                                            </tr><?php endforeach; ?></tbody>
                                                                                </table>
                                                                            </div>
                                                                        <?php else: ?><div class="alert alert-warning mb-0 py-2 small">
                                                                                Esta factura no tiene items asociados.</div><?php endif; ?>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php else: ?><div class="alert alert-info mb-0 py-2 small">No hay facturas para
                                                        este cliente en el rango.</div><?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="2" class="text-end">Totales:</th>
                                <th class="text-end">L <?= number_format($total_subtotal, 2) ?></th>
                                <th class="text-end">L <?= number_format($total_isv, 2) ?></th>
                                <th class="text-end"><strong>L <?= number_format($total_general, 2) ?></strong></th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <!-- Ingresos por año -->
        <div class="db-card">
            <div class="db-card-header">
                <span class="db-card-title"><i class="bi bi-calendar-event-fill me-2 text-secondary"></i>Ingresos por
                    Año</span>
                <small class="text-muted">(Usa el mismo filtro Desde/Hasta)</small>
            </div>
            <div class="card-body p-4">
                <canvas id="graficoAnual" height="110"></canvas>
                <?php if (!empty($ingresos_anuales)): ?>
                    <div class="table-responsive mt-3">
                        <table class="table table-sm table-bordered mb-0" style="font-size:.855rem">
                            <thead class="table-light">
                                <tr>
                                    <th>Año</th>
                                    <th class="text-end">Subtotal (L)</th>
                                    <th class="text-end">ISV (L)</th>
                                    <th class="text-end">Total (L)</th>
                                </tr>
                            </thead>
                            <tbody><?php foreach ($ingresos_anuales as $ax): ?><tr>
                                        <td><?= htmlspecialchars($ax['anio']) ?></td>
                                        <td class="text-end">L <?= number_format((float)$ax['subtotal'], 2) ?></td>
                                        <td class="text-end">L <?= number_format((float)$ax['isv'], 2) ?></td>
                                        <td class="text-end fw-bold">L <?= number_format((float)$ax['total'], 2) ?></td>
                                    </tr><?php endforeach; ?></tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════
         REPORTES PERIÓDICOS — Trimestral / Semestral / Anual
         ══════════════════════════════════════════════════════ -->
        <?php
        // Preparar datos para JS
        $trimestres_labels   = [];
        $trimestres_subtotal = [];
        $trimestres_isv      = [];
        $trimestres_total    = [];
        $trimestres_tabla    = [];
        $nombres_trim        = ['Q1 (Ene–Mar)', 'Q2 (Abr–Jun)', 'Q3 (Jul–Sep)', 'Q4 (Oct–Dic)'];
        foreach ($datos_trimestrales as $t) {
            $q = (int)$t['trimestre'] - 1;
            $trimestres_labels[]   = $nombres_trim[$q] ?? "Q{$t['trimestre']}";
            $trimestres_subtotal[] = (float)$t['subtotal'];
            $trimestres_isv[]      = (float)$t['isv'];
            $trimestres_total[]    = (float)$t['total'];
            $trimestres_tabla[]    = ['label' => $nombres_trim[$q] ?? "Q{$t['trimestre']}", 'f' => (int)$t['facturas'], 's' => (float)$t['subtotal'], 'isv' => (float)$t['isv'], 't' => (float)$t['total']];
        }

        $semestres_labels   = [];
        $semestres_subtotal = [];
        $semestres_isv      = [];
        $semestres_total    = [];
        $semestres_tabla    = [];
        $nombres_sem        = ['H1 (Ene–Jun)', 'H2 (Jul–Dic)'];
        foreach ($datos_semestrales as $s) {
            $h = (int)$s['semestre'] - 1;
            $semestres_labels[]   = $nombres_sem[$h] ?? "H{$s['semestre']}";
            $semestres_subtotal[] = (float)$s['subtotal'];
            $semestres_isv[]      = (float)$s['isv'];
            $semestres_total[]    = (float)$s['total'];
            $semestres_tabla[]    = ['label' => $nombres_sem[$h] ?? "H{$s['semestre']}", 'f' => (int)$s['facturas'], 's' => (float)$s['subtotal'], 'isv' => (float)$s['isv'], 't' => (float)$s['total']];
        }

        $anuales_labels   = [];
        $anuales_subtotal = [];
        $anuales_isv      = [];
        $anuales_total    = [];
        foreach ($datos_anuales_reporte as $a) {
            $anuales_labels[]   = (string)$a['anio'];
            $anuales_subtotal[] = (float)$a['subtotal'];
            $anuales_isv[]      = (float)$a['isv'];
            $anuales_total[]    = (float)$a['total'];
        }

        // Total del año seleccionado para la ficha
        $trim_total_anio  = array_sum($trimestres_total);
        $trim_isv_anio    = array_sum($trimestres_isv);
        ?>
        <div class="db-card mt-4" id="seccion-reportes-periodicos">
            <div class="db-card-header" style="flex-wrap:wrap;gap:.5rem">
                <span class="db-card-title">
                    <i class="bi bi-bar-chart-steps me-2 text-secondary"></i>Reportes Periódicos
                </span>
                <!-- Filtros del reporte -->
                <!-- Selector de año (solo para tabs Trimestral / Semestral / Por Año) -->
                <form method="GET" class="d-flex align-items-center gap-2 ms-auto" style="margin:0" id="form-rep">
                    <?php
                    foreach ($_GET as $k => $v):
                        if (in_array($k, ['anio_reporte','active_tab_rep'])) continue;
                    ?>
                        <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
                    <?php endforeach; ?>
                    <input type="hidden" name="active_tab_rep" id="inp-active-tab-rep" value="<?= htmlspecialchars($active_tab_rep) ?>">

                    <label class="form-label mb-0 small fw-semibold" for="sel-anio-reporte">Año:</label>
                    <select id="sel-anio-reporte" name="anio_reporte" class="form-select form-select-sm"
                            style="width:auto" onchange="this.form.submit()">
                        <?php
                        $anio_min = !empty($datos_anuales_reporte) ? (int)$datos_anuales_reporte[0]['anio'] : (int)date('Y') - 3;
                        $anio_max = (int)date('Y');
                        for ($y = $anio_max; $y >= $anio_min; $y--):
                        ?>
                            <option value="<?= $y ?>" <?= $y == $anio_reporte ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </form>
            </div>

            <div class="card-body p-4">
                <!-- Tabs -->
                <ul class="nav nav-tabs mb-3" id="tabsReportes" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link" id="tab-trim-btn" data-bs-toggle="tab" data-bs-target="#tab-trim" type="button">
                            <i class="bi bi-calendar3-range me-1"></i>Trimestral
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="tab-sem-btn" data-bs-toggle="tab" data-bs-target="#tab-sem" type="button">
                            <i class="bi bi-calendar-month me-1"></i>Semestral
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="tab-anual-btn" data-bs-toggle="tab" data-bs-target="#tab-anual" type="button">
                            <i class="bi bi-calendar-event me-1"></i>Por Año
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="tab-fecha-btn" data-bs-toggle="tab" data-bs-target="#tab-fecha" type="button">
                            <i class="bi bi-calendar-range me-1"></i>Por Fecha
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" id="tab-comp-btn" data-bs-toggle="tab" data-bs-target="#tab-comp" type="button">
                            <i class="bi bi-bar-chart-line me-1"></i>Comparativo
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="tabsReportesContent">

                    <!-- ── TRIMESTRAL ── -->
                    <div class="tab-pane fade show active" id="tab-trim" role="tabpanel">
                        <div id="cap-trim" class="reporte-captura" style="background:#fff;padding:1rem;border-radius:12px">
                            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                                <div>
                                    <h6 class="mb-0 fw-bold text-dark">Reporte Trimestral <?= $anio_reporte ?></h6>
                                    <small
                                        class="text-muted"><?= htmlspecialchars($datos['cliente_nombre'] ?? '') ?></small>
                                </div>
                                <?php if ($trim_total_anio > 0): ?>
                                    <div class="d-flex gap-3 flex-wrap">
                                        <span class="badge bg-primary-subtle text-primary-emphasis px-3 py-2">Total año:
                                            <strong>L <?= number_format($trim_total_anio, 2) ?></strong></span>
                                        <span class="badge bg-warning-subtle text-warning-emphasis px-3 py-2">ISV año: <strong>L
                                                <?= number_format($trim_isv_anio, 2) ?></strong></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($datos_trimestrales)): ?>
                                <canvas id="grafTrim" height="100" class="mb-3"></canvas>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered mb-0 align-middle" style="font-size:.855rem">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Trimestre</th>
                                                <th class="text-center">Facturas</th>
                                                <th class="text-end">Subtotal</th>
                                                <th class="text-end">ISV</th>
                                                <th class="text-end fw-bold">Total</th>
                                                <th class="text-end text-muted small">Var. trim. anterior</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $prev_trim_total = null;
                                            foreach ($trimestres_tabla as $row):
                                                $var_trim = null;
                                                if ($prev_trim_total !== null && $prev_trim_total != 0) {
                                                    $var_trim = round((($row['t'] - $prev_trim_total) / $prev_trim_total) * 100, 1);
                                                }
                                            ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($row['label']) ?></td>
                                                    <td class="text-center"><?= $row['f'] ?></td>
                                                    <td class="text-end">L <?= number_format($row['s'], 2) ?></td>
                                                    <td class="text-end">L <?= number_format($row['isv'], 2) ?></td>
                                                    <td class="text-end fw-bold">L <?= number_format($row['t'], 2) ?></td>
                                                    <td class="text-end small">
                                                        <?php if ($var_trim === null): ?>
                                                            <span class="text-muted">—</span>
                                                        <?php elseif ($var_trim > 0): ?>
                                                            <span class="text-success fw-semibold">↑ <?= $var_trim ?>%</span>
                                                        <?php elseif ($var_trim < 0): ?>
                                                            <span class="text-danger fw-semibold">↓ <?= abs($var_trim) ?>%</span>
                                                        <?php else: ?>
                                                            <span class="text-muted">= 0%</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php $prev_trim_total = $row['t']; endforeach; ?>
                                        </tbody>
                                        <tfoot class="table-light">
                                            <tr>
                                                <th>Total <?= $anio_reporte ?></th>
                                                <th class="text-center"><?= array_sum(array_column($trimestres_tabla, 'f')) ?></th>
                                                <th class="text-end">L <?= number_format(array_sum($trimestres_subtotal), 2) ?></th>
                                                <th class="text-end">L <?= number_format(array_sum($trimestres_isv), 2) ?></th>
                                                <th class="text-end">L <?= number_format($trim_total_anio, 2) ?></th>
                                                <th></th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info py-2 small">Sin datos trimestrales para <?= $anio_reporte ?>.</div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($datos_trimestrales)): ?>
                            <div class="text-end mt-3">
                                <button class="btn btn-sm btn-outline-secondary"
                                    onclick="exportarReporte('cap-trim','reporte-trimestral-<?= $anio_reporte ?>')">
                                    <i class="bi bi-download me-1"></i>Exportar JPG
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- ── SEMESTRAL ── -->
                    <div class="tab-pane fade" id="tab-sem" role="tabpanel">
                        <div id="cap-sem" class="reporte-captura" style="background:#fff;padding:1rem;border-radius:12px">
                            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                                <div>
                                    <h6 class="mb-0 fw-bold text-dark">Reporte Semestral <?= $anio_reporte ?></h6>
                                    <small
                                        class="text-muted"><?= htmlspecialchars($datos['cliente_nombre'] ?? '') ?></small>
                                </div>
                            </div>
                            <?php if (!empty($datos_semestrales)): ?>
                                <canvas id="grafSem" height="100" class="mb-3"></canvas>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered mb-0 align-middle" style="font-size:.855rem">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Semestre</th>
                                                <th class="text-center">Facturas</th>
                                                <th class="text-end">Subtotal</th>
                                                <th class="text-end">ISV</th>
                                                <th class="text-end fw-bold">Total</th>
                                                <th class="text-end text-muted small">Var. sem. anterior</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $prev_sem_total = null;
                                            foreach ($semestres_tabla as $row):
                                                $var_sem = null;
                                                if ($prev_sem_total !== null && $prev_sem_total != 0) {
                                                    $var_sem = round((($row['t'] - $prev_sem_total) / $prev_sem_total) * 100, 1);
                                                }
                                            ?>
                                                <tr>
                                                    <td><?= htmlspecialchars($row['label']) ?></td>
                                                    <td class="text-center"><?= $row['f'] ?></td>
                                                    <td class="text-end">L <?= number_format($row['s'], 2) ?></td>
                                                    <td class="text-end">L <?= number_format($row['isv'], 2) ?></td>
                                                    <td class="text-end fw-bold">L <?= number_format($row['t'], 2) ?></td>
                                                    <td class="text-end small">
                                                        <?php if ($var_sem === null): ?>
                                                            <span class="text-muted">—</span>
                                                        <?php elseif ($var_sem > 0): ?>
                                                            <span class="text-success fw-semibold">↑ <?= $var_sem ?>%</span>
                                                        <?php elseif ($var_sem < 0): ?>
                                                            <span class="text-danger fw-semibold">↓ <?= abs($var_sem) ?>%</span>
                                                        <?php else: ?>
                                                            <span class="text-muted">= 0%</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php $prev_sem_total = $row['t']; endforeach; ?>
                                        </tbody>
                                        <tfoot class="table-light">
                                            <tr>
                                                <th>Total <?= $anio_reporte ?></th>
                                                <th class="text-center"><?= array_sum(array_column($semestres_tabla, 'f')) ?></th>
                                                <th class="text-end">L <?= number_format(array_sum($semestres_subtotal), 2) ?></th>
                                                <th class="text-end">L <?= number_format(array_sum($semestres_isv), 2) ?></th>
                                                <th class="text-end">L <?= number_format(array_sum($semestres_total), 2) ?></th>
                                                <th></th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info py-2 small">Sin datos semestrales para <?= $anio_reporte ?>.</div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($datos_semestrales)): ?>
                            <div class="text-end mt-3">
                                <button class="btn btn-sm btn-outline-secondary"
                                    onclick="exportarReporte('cap-sem','reporte-semestral-<?= $anio_reporte ?>')">
                                    <i class="bi bi-download me-1"></i>Exportar JPG
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- ── ANUAL ── -->
                    <div class="tab-pane fade" id="tab-anual" role="tabpanel">
                        <div id="cap-anual" class="reporte-captura" style="background:#fff;padding:1rem;border-radius:12px">
                            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                                <div>
                                    <h6 class="mb-0 fw-bold text-dark">Reporte por Año — Histórico</h6>
                                    <small
                                        class="text-muted"><?= htmlspecialchars($datos['cliente_nombre'] ?? '') ?></small>
                                </div>
                            </div>
                            <?php if (!empty($datos_anuales_reporte)): ?>
                                <canvas id="grafAnualRep" height="100" class="mb-3"></canvas>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered mb-0 align-middle" style="font-size:.855rem">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Año</th>
                                                <th class="text-center">Facturas</th>
                                                <th class="text-end">Subtotal</th>
                                                <th class="text-end">ISV</th>
                                                <th class="text-end fw-bold">Total</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($datos_anuales_reporte as $row): ?>
                                                <tr>
                                                    <td class="fw-semibold"><?= (int)$row['anio'] ?></td>
                                                    <td class="text-center"><?= (int)$row['facturas'] ?></td>
                                                    <td class="text-end">L <?= number_format((float)$row['subtotal'], 2) ?></td>
                                                    <td class="text-end">L <?= number_format((float)$row['isv'], 2) ?></td>
                                                    <td class="text-end fw-bold">L <?= number_format((float)$row['total'], 2) ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot class="table-light">
                                            <tr>
                                                <th>Total histórico</th>
                                                <th class="text-center">
                                                    <?= array_sum(array_column($datos_anuales_reporte, 'facturas')) ?></th>
                                                <th class="text-end">L
                                                    <?= number_format(array_sum(array_column($datos_anuales_reporte, 'subtotal')), 2) ?>
                                                </th>
                                                <th class="text-end">L
                                                    <?= number_format(array_sum(array_column($datos_anuales_reporte, 'isv')), 2) ?>
                                                </th>
                                                <th class="text-end">L
                                                    <?= number_format(array_sum(array_column($datos_anuales_reporte, 'total')), 2) ?>
                                                </th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info py-2 small">Sin datos históricos.</div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($datos_anuales_reporte)): ?>
                            <div class="text-end mt-3">
                                <button class="btn btn-sm btn-outline-secondary"
                                    onclick="exportarReporte('cap-anual','reporte-anual-historico')">
                                    <i class="bi bi-download me-1"></i>Exportar JPG
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>


                <!-- ── POR FECHA ── -->
                <div class="tab-pane fade" id="tab-fecha" role="tabpanel">
                    <form method="POST" id="form-por-fecha" class="mb-4">
                        <!-- Preservar params GET actuales -->
                        <?php foreach ($_GET as $k => $v): ?>
                            <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
                        <?php endforeach; ?>

                        <div class="row g-2 align-items-end">
                            <div class="col-12 col-sm-auto">
                                <label class="form-label small fw-semibold mb-1">Desde</label>
                                <input type="date" name="fecha_desde_rep" class="form-control form-control-sm"
                                       value="<?= htmlspecialchars($fecha_desde_rep ?? '') ?>" required>
                            </div>
                            <div class="col-12 col-sm-auto">
                                <label class="form-label small fw-semibold mb-1">Hasta</label>
                                <input type="date" name="fecha_hasta_rep" class="form-control form-control-sm"
                                       value="<?= htmlspecialchars($fecha_hasta_rep ?? '') ?>" required>
                            </div>
                            <div class="col-12 col-sm-auto">
                                <button class="btn btn-primary btn-sm" type="submit">
                                    <i class="bi bi-funnel-fill me-1"></i>Filtrar
                                </button>
                                <?php if ($usar_rango_rep): ?>
                                <a href="?anio_reporte=<?= $anio_reporte ?>&active_tab_rep=tab-fecha&clear_fecha_rep=1"
                                   class="btn btn-outline-secondary btn-sm ms-1">
                                    <i class="bi bi-x-lg me-1"></i>Limpiar
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>

                    <?php if ($usar_rango_rep): ?>
                    <div class="alert alert-info py-2 small mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        Período: <strong><?= htmlspecialchars($rep_desde) ?></strong> al <strong><?= htmlspecialchars($rep_hasta) ?></strong>
                    </div>

                    <div id="cap-fecha" style="background:#fff;padding:1rem;border-radius:12px">
                        <h6 class="fw-bold text-dark mb-1">Por Fecha — <?= htmlspecialchars($rep_desde) ?> al <?= htmlspecialchars($rep_hasta) ?></h6>
                        <small class="text-muted d-block mb-3"><?= htmlspecialchars($datos['cliente_nombre'] ?? '') ?></small>

                        <!-- Trimestral del rango -->
                        <?php if (!empty($datos_trimestrales)): ?>
                        <p class="fw-semibold small text-secondary mb-1"><i class="bi bi-calendar3-range me-1"></i>Desglose trimestral</p>
                        <div class="table-responsive mb-4">
                            <table class="table table-sm table-bordered mb-0 align-middle" style="font-size:.855rem">
                                <thead class="table-light">
                                    <tr>
                                        <th>Trimestre</th>
                                        <th class="text-center">Facturas</th>
                                        <th class="text-end">Subtotal</th>
                                        <th class="text-end">ISV</th>
                                        <th class="text-end fw-bold">Total</th>
                                        <th class="text-end text-muted small">Var. trim. anterior</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php
                                $prev_tf = null;
                                foreach ($trimestres_tabla as $row):
                                    $vt = ($prev_tf !== null && $prev_tf != 0)
                                        ? round((($row['t'] - $prev_tf) / $prev_tf) * 100, 1) : null;
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['label']) ?></td>
                                    <td class="text-center"><?= $row['f'] ?></td>
                                    <td class="text-end">L <?= number_format($row['s'], 2) ?></td>
                                    <td class="text-end">L <?= number_format($row['isv'], 2) ?></td>
                                    <td class="text-end fw-bold">L <?= number_format($row['t'], 2) ?></td>
                                    <td class="text-end small">
                                        <?php if ($vt === null) echo '<span class="text-muted">—</span>';
                                        elseif ($vt > 0)  echo '<span class="text-success fw-semibold">↑ '.$vt.'%</span>';
                                        elseif ($vt < 0)  echo '<span class="text-danger fw-semibold">↓ '.abs($vt).'%</span>';
                                        else              echo '<span class="text-muted">= 0%</span>'; ?>
                                    </td>
                                </tr>
                                <?php $prev_tf = $row['t']; endforeach; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <th>Total</th>
                                        <th class="text-center"><?= array_sum(array_column($trimestres_tabla, 'f')) ?></th>
                                        <th class="text-end">L <?= number_format(array_sum($trimestres_subtotal), 2) ?></th>
                                        <th class="text-end">L <?= number_format(array_sum($trimestres_isv), 2) ?></th>
                                        <th class="text-end">L <?= number_format($trim_total_anio, 2) ?></th>
                                        <th></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <?php endif; ?>

                        <!-- Semestral del rango -->
                        <?php if (!empty($datos_semestrales)): ?>
                        <p class="fw-semibold small text-secondary mb-1"><i class="bi bi-calendar-month me-1"></i>Desglose semestral</p>
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered mb-0 align-middle" style="font-size:.855rem">
                                <thead class="table-light">
                                    <tr>
                                        <th>Semestre</th>
                                        <th class="text-center">Facturas</th>
                                        <th class="text-end">Subtotal</th>
                                        <th class="text-end">ISV</th>
                                        <th class="text-end fw-bold">Total</th>
                                        <th class="text-end text-muted small">Var. sem. anterior</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php
                                $prev_sf = null;
                                foreach ($semestres_tabla as $row):
                                    $vs = ($prev_sf !== null && $prev_sf != 0)
                                        ? round((($row['t'] - $prev_sf) / $prev_sf) * 100, 1) : null;
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($row['label']) ?></td>
                                    <td class="text-center"><?= $row['f'] ?></td>
                                    <td class="text-end">L <?= number_format($row['s'], 2) ?></td>
                                    <td class="text-end">L <?= number_format($row['isv'], 2) ?></td>
                                    <td class="text-end fw-bold">L <?= number_format($row['t'], 2) ?></td>
                                    <td class="text-end small">
                                        <?php if ($vs === null) echo '<span class="text-muted">—</span>';
                                        elseif ($vs > 0)  echo '<span class="text-success fw-semibold">↑ '.$vs.'%</span>';
                                        elseif ($vs < 0)  echo '<span class="text-danger fw-semibold">↓ '.abs($vs).'%</span>';
                                        else              echo '<span class="text-muted">= 0%</span>'; ?>
                                    </td>
                                </tr>
                                <?php $prev_sf = $row['t']; endforeach; ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <th>Total</th>
                                        <th class="text-center"><?= array_sum(array_column($semestres_tabla, 'f')) ?></th>
                                        <th class="text-end">L <?= number_format(array_sum($semestres_subtotal), 2) ?></th>
                                        <th class="text-end">L <?= number_format(array_sum($semestres_isv), 2) ?></th>
                                        <th class="text-end">L <?= number_format(array_sum($semestres_total), 2) ?></th>
                                        <th></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <?php endif; ?>

                        <?php if (empty($datos_trimestrales) && empty($datos_semestrales)): ?>
                        <div class="alert alert-info py-2 small">Sin facturas en ese rango de fechas.</div>
                        <?php endif; ?>
                    </div>

                    <div class="text-end mt-3">
                        <button class="btn btn-sm btn-outline-secondary" onclick="exportarReporte('cap-fecha','reporte-por-fecha')">
                            <i class="bi bi-download me-1"></i>Exportar JPG
                        </button>
                    </div>

                    <?php else: ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-calendar-range fs-2 d-block mb-2 opacity-50"></i>
                        Selecciona un rango de fechas y presiona <strong>Filtrar</strong> para ver el reporte.
                    </div>
                    <?php endif; ?>
                </div><!-- /tab-fecha -->

                <!-- ── COMPARATIVO ── -->
                <?php
                // Preparar matrices JS para el comparativo
                $comp_trim_by = [];   // [anio][quarter] = total
                foreach ($datos_comp_trim as $r) {
                    $comp_trim_by[(int)$r['anio']][(int)$r['trimestre']] = [
                        'total'    => (float)$r['total'],
                        'subtotal' => (float)$r['subtotal'],
                        'isv'      => (float)$r['isv'],
                        'facturas' => (int)$r['facturas'],
                    ];
                }
                $comp_sem_by = [];    // [anio][semestre] = total
                foreach ($datos_comp_sem as $r) {
                    $comp_sem_by[(int)$r['anio']][(int)$r['semestre']] = [
                        'total'    => (float)$r['total'],
                        'subtotal' => (float)$r['subtotal'],
                        'isv'      => (float)$r['isv'],
                        'facturas' => (int)$r['facturas'],
                    ];
                }

                // Colores por año (hasta 4)
                $colores_comp_bg  = ['rgba(30,64,175,.65)','rgba(5,150,105,.65)','rgba(245,158,11,.65)','rgba(239,68,68,.65)'];
                $colores_comp_brd = ['rgb(30,64,175)','rgb(5,150,105)','rgb(245,158,11)','rgb(239,68,68)'];

                // Función para calcular crecimiento %
                $crecimiento = function(float $prev, float $curr): ?float {
                    if ($prev == 0) return null;
                    return round((($curr - $prev) / $prev) * 100, 1);
                };
                ?>
                <div class="tab-pane fade" id="tab-comp" role="tabpanel">
                    <div id="cap-comp" class="reporte-captura" style="background:#fff;padding:1rem;border-radius:12px">
                        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                            <div>
                                <h6 class="mb-0 fw-bold text-dark">Comparativo por Año</h6>
                                <small class="text-muted"><?= htmlspecialchars($datos['cliente_nombre'] ?? '') ?> · Histórico <?= implode(', ', array_map('strval', $anios_disponibles)) ?></small>
                            </div>
                        </div>

                        <?php if (!empty($anios_disponibles)): ?>

                        <!-- Sub-tabs: Trimestral / Semestral -->
                        <ul class="nav nav-pills nav-sm mb-3 gap-1" id="pillsComp" role="tablist">
                            <li class="nav-item">
                                <button class="nav-link active py-1 px-3" data-bs-toggle="pill" data-bs-target="#comp-trim" type="button" style="font-size:.82rem">
                                    Trimestral
                                </button>
                            </li>
                            <li class="nav-item">
                                <button class="nav-link py-1 px-3" data-bs-toggle="pill" data-bs-target="#comp-sem" type="button" style="font-size:.82rem">
                                    Semestral
                                </button>
                            </li>
                        </ul>

                        <div class="tab-content">

                            <!-- Comparativo trimestral -->
                            <div class="tab-pane fade show active" id="comp-trim">
                                <canvas id="grafCompTrim" height="110" class="mb-3"></canvas>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered mb-0 align-middle" style="font-size:.835rem">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Trimestre</th>
                                                <?php foreach ($anios_disponibles as $ay): ?>
                                                <th class="text-end"><?= $ay ?></th>
                                                <?php endforeach; ?>
                                                <th class="text-end text-muted small">Var. último año</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php
                                        $nombres_trim_comp = ['Q1 Ene–Mar','Q2 Abr–Jun','Q3 Jul–Sep','Q4 Oct–Dic'];
                                        for ($q = 1; $q <= 4; $q++):
                                            $prev_total = null;
                                        ?>
                                        <tr>
                                            <td class="fw-semibold"><?= $nombres_trim_comp[$q-1] ?></td>
                                            <?php foreach ($anios_disponibles as $idx => $ay):
                                                $val = $comp_trim_by[$ay][$q]['total'] ?? null;
                                            ?>
                                            <td class="text-end"><?= $val !== null ? 'L '.number_format($val,2) : '<span class="text-muted">—</span>' ?></td>
                                            <?php $prev_total = $val; endforeach; ?>
                                            <td class="text-end small">
                                                <?php
                                                // Crecimiento: penúltimo → último año
                                                if (count($anios_disponibles) >= 2) {
                                                    $ay_prev = $anios_disponibles[count($anios_disponibles)-2];
                                                    $ay_curr = $anios_disponibles[count($anios_disponibles)-1];
                                                    $vp = $comp_trim_by[$ay_prev][$q]['total'] ?? null;
                                                    $vc = $comp_trim_by[$ay_curr][$q]['total'] ?? null;
                                                    if ($vp !== null && $vc !== null) {
                                                        $pct = $crecimiento((float)$vp, (float)$vc);
                                                        if ($pct === null) {
                                                            echo '<span class="text-muted">—</span>';
                                                        } elseif ($pct > 0) {
                                                            echo '<span class="text-success fw-semibold">↑ '.$pct.'%</span>';
                                                        } elseif ($pct < 0) {
                                                            echo '<span class="text-danger fw-semibold">↓ '.abs($pct).'%</span>';
                                                        } else {
                                                            echo '<span class="text-muted">= 0%</span>';
                                                        }
                                                    } else { echo '<span class="text-muted">—</span>'; }
                                                } else { echo '<span class="text-muted">—</span>'; }
                                                ?>
                                            </td>
                                        </tr>
                                        <?php endfor; ?>
                                        </tbody>
                                        <tfoot class="table-light fw-bold">
                                            <tr>
                                                <th>Total año</th>
                                                <?php foreach ($anios_disponibles as $ay):
                                                    $tot_ay = array_sum(array_map(fn($q) => $comp_trim_by[$ay][$q]['total'] ?? 0, [1,2,3,4]));
                                                ?>
                                                <th class="text-end">L <?= number_format($tot_ay, 2) ?></th>
                                                <?php endforeach; ?>
                                                <th class="text-end small">
                                                <?php if (count($anios_disponibles) >= 2):
                                                    $ay_p = $anios_disponibles[count($anios_disponibles)-2];
                                                    $ay_c = $anios_disponibles[count($anios_disponibles)-1];
                                                    $tp = array_sum(array_map(fn($q) => $comp_trim_by[$ay_p][$q]['total'] ?? 0, [1,2,3,4]));
                                                    $tc = array_sum(array_map(fn($q) => $comp_trim_by[$ay_c][$q]['total'] ?? 0, [1,2,3,4]));
                                                    $pct_tot = $crecimiento($tp, $tc);
                                                    if ($pct_tot > 0) echo '<span class="text-success">↑ '.$pct_tot.'%</span>';
                                                    elseif ($pct_tot < 0) echo '<span class="text-danger">↓ '.abs($pct_tot).'%</span>';
                                                    else echo '= 0%';
                                                endif; ?>
                                                </th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div><!-- /comp-trim -->

                            <!-- Comparativo semestral -->
                            <div class="tab-pane fade" id="comp-sem">
                                <canvas id="grafCompSem" height="110" class="mb-3"></canvas>
                                <div class="table-responsive">
                                    <table class="table table-sm table-bordered mb-0 align-middle" style="font-size:.835rem">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Semestre</th>
                                                <?php foreach ($anios_disponibles as $ay): ?>
                                                <th class="text-end"><?= $ay ?></th>
                                                <?php endforeach; ?>
                                                <th class="text-end text-muted small">Var. último año</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php
                                        $nombres_sem_comp = ['H1 Ene–Jun','H2 Jul–Dic'];
                                        for ($h = 1; $h <= 2; $h++):
                                        ?>
                                        <tr>
                                            <td class="fw-semibold"><?= $nombres_sem_comp[$h-1] ?></td>
                                            <?php foreach ($anios_disponibles as $ay):
                                                $val = $comp_sem_by[$ay][$h]['total'] ?? null;
                                            ?>
                                            <td class="text-end"><?= $val !== null ? 'L '.number_format($val,2) : '<span class="text-muted">—</span>' ?></td>
                                            <?php endforeach; ?>
                                            <td class="text-end small">
                                                <?php
                                                if (count($anios_disponibles) >= 2) {
                                                    $ay_prev = $anios_disponibles[count($anios_disponibles)-2];
                                                    $ay_curr = $anios_disponibles[count($anios_disponibles)-1];
                                                    $vp = $comp_sem_by[$ay_prev][$h]['total'] ?? null;
                                                    $vc = $comp_sem_by[$ay_curr][$h]['total'] ?? null;
                                                    if ($vp !== null && $vc !== null) {
                                                        $pct = $crecimiento((float)$vp, (float)$vc);
                                                        if ($pct === null) echo '<span class="text-muted">—</span>';
                                                        elseif ($pct > 0) echo '<span class="text-success fw-semibold">↑ '.$pct.'%</span>';
                                                        elseif ($pct < 0) echo '<span class="text-danger fw-semibold">↓ '.abs($pct).'%</span>';
                                                        else echo '<span class="text-muted">= 0%</span>';
                                                    } else echo '<span class="text-muted">—</span>';
                                                } else echo '<span class="text-muted">—</span>';
                                                ?>
                                            </td>
                                        </tr>
                                        <?php endfor; ?>
                                        </tbody>
                                        <tfoot class="table-light fw-bold">
                                            <tr>
                                                <th>Total año</th>
                                                <?php foreach ($anios_disponibles as $ay):
                                                    $tot_ay = array_sum(array_map(fn($h) => $comp_sem_by[$ay][$h]['total'] ?? 0, [1,2]));
                                                ?>
                                                <th class="text-end">L <?= number_format($tot_ay, 2) ?></th>
                                                <?php endforeach; ?>
                                                <th class="text-end small">
                                                <?php if (count($anios_disponibles) >= 2):
                                                    $ay_p = $anios_disponibles[count($anios_disponibles)-2];
                                                    $ay_c = $anios_disponibles[count($anios_disponibles)-1];
                                                    $tp = array_sum(array_map(fn($h) => $comp_sem_by[$ay_p][$h]['total'] ?? 0, [1,2]));
                                                    $tc = array_sum(array_map(fn($h) => $comp_sem_by[$ay_c][$h]['total'] ?? 0, [1,2]));
                                                    $pct_tot = $crecimiento($tp, $tc);
                                                    if ($pct_tot > 0) echo '<span class="text-success">↑ '.$pct_tot.'%</span>';
                                                    elseif ($pct_tot < 0) echo '<span class="text-danger">↓ '.abs($pct_tot).'%</span>';
                                                    else echo '= 0%';
                                                endif; ?>
                                                </th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div><!-- /comp-sem -->

                        </div><!-- /sub tab-content -->

                        <?php else: ?>
                        <div class="alert alert-info py-2 small">Sin datos históricos para comparar.</div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($anios_disponibles)): ?>
                    <div class="text-end mt-3">
                        <button class="btn btn-sm btn-outline-secondary" onclick="exportarReporte('cap-comp','reporte-comparativo')">
                            <i class="bi bi-download me-1"></i>Exportar JPG
                        </button>
                    </div>
                    <?php endif; ?>
                </div><!-- /tab-comp -->

                </div><!-- /tab-content -->
            </div>
        </div>
        <!-- ── fin reportes periódicos ── -->

        <?php if (($facturas_restantes ?? 999999) <= (defined('ALERTA_FACTURAS_RESTANTES') ? ALERTA_FACTURAS_RESTANTES : 0) && ($total_facturas ?? 0) > 0): ?>
            <div class="alert alert-warning mt-4 rounded-3"><i class="bi bi-exclamation-triangle-fill me-2"></i>⚠️ ¡Atención!
                Estás por agotar tu rango de facturación.</div>
        <?php endif; ?>

    <?php else: ?>
        <div class="alert alert-info rounded-3"><i class="bi bi-info-circle-fill me-2"></i>No hay datos de ingresos en el
            rango seleccionado.</div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const ctx = document.getElementById('graficoIngresos')?.getContext('2d');
    if (ctx) {
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($ingresos, 'mes')) ?>,
                datasets: [{
                    label: 'Subtotal',
                    backgroundColor: 'rgba(30,64,175,0.55)',
                    data: <?= json_encode(array_map(fn($r) => (float)$r['subtotal'], $ingresos)) ?>
                }, {
                    label: 'ISV',
                    backgroundColor: 'rgba(245,158,11,0.55)',
                    data: <?= json_encode(array_map(fn($r) => (float)$r['isv'], $ingresos)) ?>
                }, {
                    label: 'Total',
                    backgroundColor: 'rgba(5,150,105,0.55)',
                    data: <?= json_encode(array_map(fn($r) => (float)$r['total'], $ingresos)) ?>
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Lempiras'
                        }
                    }
                }
            }
        });
    }
    const ctxAnual = document.getElementById('graficoAnual')?.getContext('2d');
    if (ctxAnual) {
        new Chart(ctxAnual, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_map(fn($x) => (string)$x['anio'], $ingresos_anuales ?? [])) ?>,
                datasets: [{
                    label: 'Subtotal',
                    backgroundColor: 'rgba(30,64,175,0.4)',
                    data: <?= json_encode(array_map(fn($x) => (float)$x['subtotal'], $ingresos_anuales ?? [])) ?>
                }, {
                    label: 'ISV',
                    backgroundColor: 'rgba(245,158,11,0.4)',
                    data: <?= json_encode(array_map(fn($x) => (float)$x['isv'], $ingresos_anuales ?? [])) ?>
                }, {
                    label: 'Total',
                    backgroundColor: 'rgba(5,150,105,0.4)',
                    data: <?= json_encode(array_map(fn($x) => (float)$x['total'], $ingresos_anuales ?? [])) ?>
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    title: {
                        display: true,
                        text: '📊 Ingresos por Año'
                    },
                    legend: {
                        position: 'top'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Lempiras'
                        }
                    }
                }
            }
        });
    }

    (() => {
        document.querySelectorAll('.toggle-receptor').forEach(btn => {
            const target = document.querySelector(btn.getAttribute('data-bs-target'));
            if (!target) return;
            const icon = btn.querySelector('i');
            const setIcon = open => {
                if (!icon) return;
                icon.classList.toggle('bi-plus-lg', !open);
                icon.classList.toggle('bi-dash-lg', open);
            };
            btn.addEventListener('click', e => {
                e.preventDefault();
                e.stopPropagation();
                const inst = bootstrap.Collapse.getOrCreateInstance(target, {
                    toggle: false
                });
                const isOpen = target.classList.contains('show');
                if (isOpen) {
                    inst.hide();
                } else {
                    document.querySelectorAll('.detalle-receptor.show').forEach(el => {
                        if (el !== target) bootstrap.Collapse.getOrCreateInstance(el, {
                            toggle: false
                        }).hide();
                    });
                    inst.show();
                }
            });
            target.addEventListener('shown.bs.collapse', () => setIcon(true));
            target.addEventListener('hidden.bs.collapse', () => {
                target.querySelectorAll('.accordion-collapse.show').forEach(c => bootstrap.Collapse
                    .getOrCreateInstance(c, {
                        toggle: false
                    }).hide());
                target.querySelectorAll('.toggle-factura .icon-plusminus').forEach(ic => {
                    ic.classList.remove('bi-dash-lg');
                    ic.classList.add('bi-plus-lg');
                });
                setIcon(false);
            });
        });
        document.querySelectorAll('.toggle-factura').forEach(btn => {
            const target = document.querySelector(btn.getAttribute('data-bs-target'));
            if (!target) return;
            const icon = btn.querySelector('.icon-plusminus');
            const setIcon = open => {
                if (!icon) return;
                icon.classList.toggle('bi-plus-lg', !open);
                icon.classList.toggle('bi-dash-lg', open);
            };
            btn.addEventListener('click', e => {
                e.preventDefault();
                e.stopPropagation();
                const inst = bootstrap.Collapse.getOrCreateInstance(target, {
                    toggle: false
                });
                target.classList.contains('show') ? inst.hide() : inst.show();
            });
            target.addEventListener('shown.bs.collapse', () => setIcon(true));
            target.addEventListener('hidden.bs.collapse', () => setIcon(false));
        });
    })();

    // ── Gráficos de Reportes Periódicos ─────────────────────────────────────────
    const COLORES_REP = {
        subtotal: 'rgba(30,64,175,0.55)',
        isv: 'rgba(245,158,11,0.55)',
        total: 'rgba(5,150,105,0.55)',
    };
    const OPTS_REP = responsive => ({
        responsive,
        plugins: {
            legend: {
                position: 'top'
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                title: {
                    display: true,
                    text: 'Lempiras'
                }
            }
        }
    });

    // Trimestral
    const ctxTrim = document.getElementById('grafTrim')?.getContext('2d');
    if (ctxTrim) {
        new Chart(ctxTrim, {
            type: 'bar',
            data: {
                labels: <?= json_encode($trimestres_labels) ?>,
                datasets: [{
                        label: 'Subtotal',
                        backgroundColor: COLORES_REP.subtotal,
                        data: <?= json_encode($trimestres_subtotal) ?>
                    },
                    {
                        label: 'ISV',
                        backgroundColor: COLORES_REP.isv,
                        data: <?= json_encode($trimestres_isv) ?>
                    },
                    {
                        label: 'Total',
                        backgroundColor: COLORES_REP.total,
                        data: <?= json_encode($trimestres_total) ?>
                    },
                ]
            },
            options: OPTS_REP(true)
        });
    }

    // Semestral
    const ctxSem = document.getElementById('grafSem')?.getContext('2d');
    if (ctxSem) {
        new Chart(ctxSem, {
            type: 'bar',
            data: {
                labels: <?= json_encode($semestres_labels) ?>,
                datasets: [{
                        label: 'Subtotal',
                        backgroundColor: COLORES_REP.subtotal,
                        data: <?= json_encode($semestres_subtotal) ?>
                    },
                    {
                        label: 'ISV',
                        backgroundColor: COLORES_REP.isv,
                        data: <?= json_encode($semestres_isv) ?>
                    },
                    {
                        label: 'Total',
                        backgroundColor: COLORES_REP.total,
                        data: <?= json_encode($semestres_total) ?>
                    },
                ]
            },
            options: OPTS_REP(true)
        });
    }

    // Anual histórico
    const ctxAnualRep = document.getElementById('grafAnualRep')?.getContext('2d');
    if (ctxAnualRep) {
        new Chart(ctxAnualRep, {
            type: 'bar',
            data: {
                labels: <?= json_encode($anuales_labels) ?>,
                datasets: [{
                        label: 'Subtotal',
                        backgroundColor: COLORES_REP.subtotal,
                        data: <?= json_encode($anuales_subtotal) ?>
                    },
                    {
                        label: 'ISV',
                        backgroundColor: COLORES_REP.isv,
                        data: <?= json_encode($anuales_isv) ?>
                    },
                    {
                        label: 'Total',
                        backgroundColor: COLORES_REP.total,
                        data: <?= json_encode($anuales_total) ?>
                    },
                ]
            },
            options: OPTS_REP(true)
        });
    }

    // ── Gráficos Comparativos (multi-año) ────────────────────────────────────────
    <?php
    // Preparar datos comparativo para JS
    $anios_js = array_values($anios_disponibles);
    $colores_js_bg  = $colores_comp_bg;
    $colores_js_brd = $colores_comp_brd;

    // Comparativo trimestral: datasets = un dataset por año
    $comp_trim_datasets_js = [];
    foreach ($anios_js as $idx => $ay) {
        $data_q = [];
        for ($q = 1; $q <= 4; $q++) {
            $data_q[] = $comp_trim_by[$ay][$q]['total'] ?? 0;
        }
        $comp_trim_datasets_js[] = [
            'label'           => (string)$ay,
            'backgroundColor' => $colores_js_bg[$idx % count($colores_js_bg)],
            'borderColor'     => $colores_js_brd[$idx % count($colores_js_brd)],
            'borderWidth'     => 1,
            'data'            => $data_q,
        ];
    }

    // Comparativo semestral
    $comp_sem_datasets_js = [];
    foreach ($anios_js as $idx => $ay) {
        $data_h = [];
        for ($h = 1; $h <= 2; $h++) {
            $data_h[] = $comp_sem_by[$ay][$h]['total'] ?? 0;
        }
        $comp_sem_datasets_js[] = [
            'label'           => (string)$ay,
            'backgroundColor' => $colores_js_bg[$idx % count($colores_js_bg)],
            'borderColor'     => $colores_js_brd[$idx % count($colores_js_brd)],
            'borderWidth'     => 1,
            'data'            => $data_h,
        ];
    }
    ?>
    const ctxCompTrim = document.getElementById('grafCompTrim')?.getContext('2d');
    if (ctxCompTrim) {
        new Chart(ctxCompTrim, {
            type: 'bar',
            data: {
                labels: ['Q1 Ene–Mar', 'Q2 Abr–Jun', 'Q3 Jul–Sep', 'Q4 Oct–Dic'],
                datasets: <?= json_encode($comp_trim_datasets_js) ?>
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'top' },
                    title: { display: true, text: 'Total facturado por trimestre — comparativo anual' }
                },
                scales: {
                    x: { stacked: false },
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'Lempiras' },
                        ticks: { callback: v => 'L ' + v.toLocaleString() }
                    }
                }
            }
        });
    }

    const ctxCompSem = document.getElementById('grafCompSem')?.getContext('2d');
    if (ctxCompSem) {
        new Chart(ctxCompSem, {
            type: 'bar',
            data: {
                labels: ['H1 Ene–Jun', 'H2 Jul–Dic'],
                datasets: <?= json_encode($comp_sem_datasets_js) ?>
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'top' },
                    title: { display: true, text: 'Total facturado por semestre — comparativo anual' }
                },
                scales: {
                    x: { stacked: false },
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'Lempiras' },
                        ticks: { callback: v => 'L ' + v.toLocaleString() }
                    }
                }
            }
        });
    }

    // ── Activación del tab activo en Reportes Periódicos ─────────────────────────
    (() => {
        const savedTab  = <?= json_encode($active_tab_rep) ?>;
        const inpActive = document.getElementById('inp-active-tab-rep');

        // Activar tab usando manipulación directa del DOM (evita timing issues con Bootstrap)
        function activarTab(tabId) {
            document.querySelectorAll('#tabsReportesContent .tab-pane').forEach(p => {
                p.classList.remove('show', 'active');
            });
            document.querySelectorAll('#tabsReportes .nav-link').forEach(b => {
                b.classList.remove('active');
                b.setAttribute('aria-selected', 'false');
            });
            const pane = document.getElementById(tabId);
            const btn  = document.querySelector(`#tabsReportes [data-bs-target="#${tabId}"]`);
            if (pane) pane.classList.add('show', 'active');
            if (btn)  { btn.classList.add('active'); btn.setAttribute('aria-selected', 'true'); }
            if (inpActive) inpActive.value = tabId;
        }

        // Restaurar tab que PHP indica
        if (savedTab) activarTab(savedTab);

        // Al cambiar tab manualmente → actualizar hidden input del form GET (año)
        document.querySelectorAll('#tabsReportes [data-bs-toggle="tab"]').forEach(btn => {
            btn.addEventListener('click', e => {
                const tabId = btn.getAttribute('data-bs-target').replace('#', '');
                if (inpActive) inpActive.value = tabId;
            });
        });
    })();

    // ── Exportar a JPG con html2canvas ───────────────────────────────────────────
    function exportarReporte(capId, nombreArchivo) {
        const el = document.getElementById(capId);
        if (!el) return;
        const btn = el.closest('.tab-pane')?.querySelector('button[onclick^="exportarReporte"]');
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Generando…';
        }

        html2canvas(el, {
            scale: 2,
            backgroundColor: '#ffffff',
            useCORS: true
        }).then(canvas => {
            const link = document.createElement('a');
            link.download = (nombreArchivo || capId) + '.jpg';
            link.href = canvas.toDataURL('image/jpeg', 0.93);
            link.click();
        }).finally(() => {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-download me-1"></i>Exportar JPG';
            }
        });
    }
</script>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>

<?php require_once '../../includes/templates/footer.php'; ?>