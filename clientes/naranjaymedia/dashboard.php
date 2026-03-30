<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';
require_once '../../includes/dashboard.php';
require_once '../../includes/templates/header.php';

/** @var string $fecha_inicio */
/** @var string $fecha_fin */
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

    <!-- Totales mes / año / no declaradas -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
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
        <div class="col-md-4">
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
        <div class="col-md-4">
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
</script>

<?php require_once '../../includes/templates/footer.php'; ?>