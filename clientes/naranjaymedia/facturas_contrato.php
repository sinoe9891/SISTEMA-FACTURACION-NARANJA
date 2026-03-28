<?php
$titulo = 'Facturas del Contrato';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';
require_once '../../includes/templates/header.php';

$cliente_id  = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);

$contrato_id = (int)($_GET['contrato_id'] ?? 0);
if (!$contrato_id) {
    header('Location: contratos');
    exit;
}

// ── Datos del contrato ────────────────────────────────────────────────────────
$stmtC = $pdo->prepare("
    SELECT c.*,
           cf.nombre   AS receptor_nombre, cf.rtn AS receptor_rtn,
           cf.email    AS receptor_email,  cf.telefono AS receptor_tel,
           p.nombre    AS producto_nombre
    FROM contratos c
    INNER JOIN clientes_factura   cf ON cf.id=c.receptor_id AND cf.cliente_id=c.cliente_id
    INNER JOIN productos_clientes p  ON p.id=c.producto_id  AND p.cliente_id=c.cliente_id
    WHERE c.id=? AND c.cliente_id=?
");
$stmtC->execute([$contrato_id, $cliente_id]);
$contrato = $stmtC->fetch(PDO::FETCH_ASSOC);
if (!$contrato) {
    header('Location: contratos');
    exit;
}

// ── Facturas ──────────────────────────────────────────────────────────────────
$stmtF = $pdo->prepare("
    SELECT f.*,
           COALESCE(f.periodo_mes,  MONTH(f.fecha_emision)) AS periodo_mes_ef,
           COALESCE(f.periodo_anio, YEAR(f.fecha_emision))  AS periodo_anio_ef
    FROM facturas f
    WHERE f.contrato_id=? AND f.cliente_id=? AND f.estado='emitida'
    ORDER BY f.fecha_emision DESC, f.id DESC
");
$stmtF->execute([$contrato_id, $cliente_id]);
$facturas = $stmtF->fetchAll(PDO::FETCH_ASSOC);

// ── Totales ───────────────────────────────────────────────────────────────────
$totalFacturado = 0;
$totalIsv = 0;
$totalSubtotal = 0;
foreach ($facturas as $f) {
    $totalFacturado += (float)$f['total'];
    $totalIsv       += (float)$f['isv_15'] + (float)$f['isv_18'];
    $totalSubtotal  += (float)$f['subtotal'];
}

// ── Calendario de meses ───────────────────────────────────────────────────────
$fechaInicio = new DateTime($contrato['fecha_inicio']);
$fechaRef    = $contrato['fecha_fin'] ? new DateTime($contrato['fecha_fin']) : new DateTime();
$fechaRef    = min($fechaRef, new DateTime());
$tipo_ct     = $contrato['tipo_contrato'] ?? 'estandar';
$frecuencia  = max(1, (int)($contrato['frecuencia_meses'] ?? 1));
$mesIniCiclo = (int)($contrato['mes_inicio_ciclo'] ?? (int)$fechaInicio->format('n'));
$anioIniCiclo = (int)$fechaInicio->format('Y');

$mesesEsperados = [];
$cursor = clone $fechaInicio;
$cursor->modify('first day of this month');
while ($cursor <= $fechaRef) {
    $mes  = (int)$cursor->format('n');
    $anio = (int)$cursor->format('Y');
    $key  = $anio . '-' . $mes;
    $debe = false;
    if ($tipo_ct === 'estandar' || $tipo_ct === 'sin_factura') {
        $debe = true;
    } elseif ($tipo_ct === 'rotativo') {
        // Rotativo: cada mes hay un cobro (diferente cliente del ciclo por turno)
        $debe = true;
    } elseif ($tipo_ct === 'periodico') {
        $offset = ($anio - $anioIniCiclo) * 12 + ($mes - $mesIniCiclo);
        if ($offset >= 0 && ($offset % $frecuencia) === 0) $debe = true;
    }
    if ($debe) $mesesEsperados[] = $key;
    $cursor->modify('+1 month');
}

$mesesConFactura = [];
foreach ($facturas as $f) {
    $pm = (int)($f['periodo_mes_ef']  ?? (int)substr($f['fecha_emision'], 5, 2));
    $pa = (int)($f['periodo_anio_ef'] ?? (int)substr($f['fecha_emision'], 0, 4));
    $mesesConFactura[$pa . '-' . $pm] = true;
}
// El mes actual nunca es "atrasado" — puede que aún no haya vencido el día de cobro
$keyActual       = date('Y') . '-' . (int)date('n');
$mesesSinFactura = array_filter($mesesEsperados, fn($m) => !isset($mesesConFactura[$m]) && $m !== $keyActual);
$totalMeses        = count($mesesEsperados);
$mesesOk           = count(array_intersect($mesesEsperados, array_keys($mesesConFactura)));
$mesesPend         = count($mesesSinFactura);
$pctCumplimiento   = $totalMeses > 0 ? round(($mesesOk / $totalMeses) * 100) : 0;
$noIniciado        = (new DateTime($contrato['fecha_inicio'])) > new DateTime();

$meses_es = [
    '',
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

// Agrupar por año para el calendario
$mesesPorAnio = [];
foreach (array_reverse($mesesEsperados) as $m) {
    $anio = substr($m, 0, 4);
    $mesesPorAnio[$anio][] = $m;
}

// ── Turnos rotativos (para mostrar qué cliente toca cada mes) ──────────────────
$turnosRotativos = [];
if ($tipo_ct === 'rotativo') {
    $stmtRot = $pdo->prepare("
        SELECT r.orden, r.monto, cf.nombre AS receptor_nombre, r.receptor_id
        FROM contratos_clientes_rotativos r
        INNER JOIN clientes_factura cf ON cf.id = r.receptor_id AND cf.cliente_id = ?
        WHERE r.contrato_id = ? AND r.activo = 1
        ORDER BY r.orden ASC
    ");
    $stmtRot->execute([$cliente_id, $contrato_id]);
    $turnosRotativos = $stmtRot->fetchAll(PDO::FETCH_ASSOC);
}

$tipoCls  = ['estandar' => 'tp-estandar', 'periodico' => 'tp-periodico', 'rotativo' => 'tp-rotativo', 'sin_factura' => 'tp-sin_factura'];
$tipoLbl  = ['estandar' => 'Estándar', 'periodico' => 'Periódico', 'rotativo' => 'Rotativo', 'sin_factura' => 'Sin factura'];
$estadoCls = ['activo' => 'ep-activo', 'pausado' => 'ep-pausado', 'cancelado' => 'ep-cancelado', 'vencido' => 'ep-vencido'];
$estadoIco = ['activo' => '✅', 'pausado' => '⏸', 'cancelado' => '❌', 'vencido' => '⌛'];
?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
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
        font-family: 'Plus Jakarta Sans', sans-serif;
    }

    .fc-wrap {
        max-width: 1200px;
        margin: 0 auto;
        padding: 1.5rem 0 4rem;
    }

    /* Hero */
    .fc-hero {
        background: linear-gradient(135deg, #0f766e 0%, #1e40af 100%);
        border-radius: var(--radius);
        padding: 1.5rem 2rem;
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

    .fc-hero::before {
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

    .fc-hero-title {
        font-size: 1.3rem;
        font-weight: 800;
        margin: 0;
    }

    .fc-hero-sub {
        font-size: .82rem;
        opacity: .78;
        margin: .15rem 0 0;
    }

    /* Cards */
    .fc-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow-sm);
        margin-bottom: 1.25rem;
        overflow: hidden;
    }

    .fc-card-hdr {
        padding: .9rem 1.4rem;
        border-bottom: 1px solid var(--border);
        background: var(--surface-2);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .75rem;
        flex-wrap: wrap;
    }

    .fc-card-title {
        font-size: .92rem;
        font-weight: 700;
        color: var(--text);
        display: flex;
        align-items: center;
        gap: .5rem;
    }

    .fc-card-body {
        padding: 1.25rem 1.4rem;
    }

    /* KPIs */
    .fc-kpis {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(155px, 1fr));
        gap: .85rem;
        margin-bottom: 1.25rem;
    }

    .fc-kpi {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 1rem 1.1rem;
        display: flex;
        align-items: center;
        gap: .8rem;
        box-shadow: var(--shadow-sm);
        transition: box-shadow .18s, transform .18s;
    }

    .fc-kpi:hover {
        box-shadow: var(--shadow-md);
        transform: translateY(-2px);
    }

    .fc-kpi-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        flex-shrink: 0;
    }

    .ki-teal {
        background: var(--brand-lt);
        color: var(--brand);
    }

    .ki-green {
        background: #d1fae5;
        color: #059669;
    }

    .ki-amber {
        background: #fef3c7;
        color: #d97706;
    }

    .ki-red {
        background: #fee2e2;
        color: #dc2626;
    }

    .ki-blue {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .ki-purple {
        background: #ede9fe;
        color: #7c3aed;
    }

    .fc-kpi-val {
        font-size: 1rem;
        font-weight: 800;
        color: var(--text);
        line-height: 1.1;
    }

    .fc-kpi-lbl {
        font-size: .68rem;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: .04em;
    }

    /* Contrato info card */
    .cinfo-grid {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 1rem;
    }

    @media(max-width:768px) {
        .cinfo-grid {
            grid-template-columns: 1fr 1fr;
        }
    }

    .cinfo-item {
        text-align: center;
        padding: .6rem;
    }

    .cinfo-lbl {
        font-size: .68rem;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: .04em;
        margin-bottom: 4px;
    }

    .cinfo-val {
        font-size: .9rem;
        font-weight: 700;
        color: var(--text);
    }

    /* Tipo y estado pills */
    .tipo-pill {
        display: inline-flex;
        align-items: center;
        gap: .2rem;
        padding: .12rem .5rem;
        border-radius: 20px;
        font-size: .7rem;
        font-weight: 700;
    }

    .tp-estandar {
        background: #d1fae5;
        color: #065f46;
    }

    .tp-periodico {
        background: #dbeafe;
        color: #1e40af;
    }

    .tp-rotativo {
        background: #fef3c7;
        color: #92400e;
    }

    .tp-sin_factura {
        background: #ede9fe;
        color: #5b21b6;
    }

    .estado-pill {
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        padding: .3rem .75rem;
        border-radius: 20px;
        font-size: .78rem;
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

    /* Tabla facturas */
    .fc-table {
        width: 100%;
        border-collapse: collapse;
        font-size: .84rem;
    }

    .fc-table thead th {
        padding: .65rem 1rem;
        background: var(--surface-2);
        color: var(--muted);
        font-size: .7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .04em;
        border-bottom: 1px solid var(--border);
        white-space: nowrap;
    }

    .fc-table tbody tr {
        border-bottom: 1px solid var(--border);
        transition: background .15s;
    }

    .fc-table tbody tr:last-child {
        border-bottom: none;
    }

    .fc-table tbody tr:hover {
        background: #f0fdf9;
    }

    .fc-table tbody td {
        padding: .75rem 1rem;
        vertical-align: middle;
    }

    .fc-table tfoot td {
        padding: .65rem 1rem;
        background: var(--surface-2);
        font-weight: 700;
        font-size: .84rem;
        border-top: 2px solid var(--border);
    }

    .fc-table tbody tr.tr-actual td {
        background: #f0fdf4;
    }

    .fc-table tbody tr.tr-anulada td {
        opacity: .55;
    }

    /* Calendario meses */
    .mes-chip {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 11px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
        white-space: nowrap;
        transition: transform .1s;
        cursor: default;
    }

    .mes-chip:hover {
        transform: scale(1.05);
    }

    .mes-chip.ok {
        background: #d1fae5;
        color: #065f46;
        border: 1px solid #6ee7b7;
    }

    .mes-chip.pend {
        background: #fee2e2;
        color: #991b1b;
        border: 1px solid #fca5a5;
    }

    .mes-chip.actual {
        background: #fef9c3;
        color: #713f12;
        border: 1px solid #fde047;
    }

    .mes-chip.noaplica {
        background: #f1f5f9;
        color: #94a3b8;
        border: 1px solid #e2e8f0;
    }

    /* Progress */
    .prog-wrap {
        height: 8px;
        background: var(--border);
        border-radius: 20px;
        overflow: hidden;
    }

    .prog-fill {
        height: 100%;
        border-radius: 20px;
        transition: width .4s ease;
    }

    .prog-green {
        background: linear-gradient(90deg, #10b981, #059669);
    }

    .prog-amber {
        background: linear-gradient(90deg, #f59e0b, #d97706);
    }

    .prog-red {
        background: linear-gradient(90deg, #ef4444, #dc2626);
    }

    /* Btn */
    .btn-facturar {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        padding: .55rem 1.2rem;
        background: var(--brand);
        color: #fff;
        border: none;
        border-radius: var(--radius-sm);
        font-size: .86rem;
        font-weight: 700;
        text-decoration: none;
        cursor: pointer;
        box-shadow: 0 3px 12px rgba(15, 118, 110, .25);
        transition: background .18s, transform .18s;
    }

    .btn-facturar:hover {
        background: var(--brand-dk);
        transform: translateY(-1px);
        color: #fff;
    }

    .anio-lbl {
        font-size: .7rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .08em;
        color: var(--muted);
        padding-bottom: 6px;
        border-bottom: 2px solid var(--border);
        margin-bottom: 8px;
    }
</style>

<div class="container-xxl fc-wrap">

    <!-- Hero -->
    <div class="fc-hero">
        <div>
            <h4 class="fc-hero-title">🧾 Facturas del Contrato</h4>
            <p class="fc-hero-sub">
                <?= htmlspecialchars($contrato['nombre_contrato']) ?> &nbsp;·&nbsp;
                <?= htmlspecialchars($contrato['receptor_nombre']) ?>
            </p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <?php if (!$noIniciado && $contrato['estado'] === 'activo'): ?>
                <a href="generar_factura?receptor_id=<?= $contrato['receptor_id'] ?>&producto_id=<?= $contrato['producto_id'] ?>&monto=<?= $contrato['monto'] ?>&contrato_id=<?= $contrato['id'] ?>"
                    class="btn-facturar">
                    <i class="bi bi-file-earmark-plus"></i> Nueva Factura
                </a>
            <?php endif; ?>
            <a href="contratos" class="btn btn-sm"
                style="background:rgba(255,255,255,.18);color:#fff;border:1px solid rgba(255,255,255,.3);font-weight:600">
                <i class="bi bi-arrow-left me-1"></i>Volver
            </a>
        </div>
    </div>

    <!-- KPIs -->
    <div class="fc-kpis">
        <div class="fc-kpi">
            <div class="fc-kpi-icon ki-teal"><i class="bi bi-file-earmark-text-fill"></i></div>
            <div>
                <div class="fc-kpi-val"><?= count($facturas) ?></div>
                <div class="fc-kpi-lbl">Facturas</div>
            </div>
        </div>
        <div class="fc-kpi">
            <div class="fc-kpi-icon ki-blue"><i class="bi bi-cash-stack"></i></div>
            <div>
                <div class="fc-kpi-val" style="font-size:.88rem">L <?= number_format($totalSubtotal, 0) ?></div>
                <div class="fc-kpi-lbl">Subtotal</div>
            </div>
        </div>
        <div class="fc-kpi">
            <div class="fc-kpi-icon ki-amber"><i class="bi bi-percent"></i></div>
            <div>
                <div class="fc-kpi-val" style="font-size:.88rem">L <?= number_format($totalIsv, 0) ?></div>
                <div class="fc-kpi-lbl">ISV total</div>
            </div>
        </div>
        <div class="fc-kpi">
            <div class="fc-kpi-icon ki-green"><i class="bi bi-coin"></i></div>
            <div>
                <div class="fc-kpi-val" style="font-size:.88rem">L <?= number_format($totalFacturado, 0) ?></div>
                <div class="fc-kpi-lbl">Total facturado</div>
            </div>
        </div>
        <div class="fc-kpi" style="border-color:<?= $mesesPend > 0 ? '#fecaca' : '#a7f3d0' ?>">
            <div class="fc-kpi-icon <?= $mesesPend > 0 ? 'ki-red' : 'ki-green' ?>"><i class="bi bi-calendar-check"></i>
            </div>
            <div>
                <div class="fc-kpi-val" style="color:<?= $mesesPend > 0 ? '#dc2626' : '#059669' ?>">
                    <?= $pctCumplimiento ?>%
                </div>
                <div class="fc-kpi-lbl"><?= $mesesOk ?>/<?= $totalMeses ?> meses cobrados</div>
            </div>
        </div>
        <?php if ($mesesPend > 0): ?>
            <div class="fc-kpi" style="border-color:#fecaca">
                <div class="fc-kpi-icon ki-red"><i class="bi bi-clock-history"></i></div>
                <div>
                    <div class="fc-kpi-val" style="color:#dc2626"><?= $mesesPend ?></div>
                    <div class="fc-kpi-lbl">Meses sin cobrar</div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Info del contrato -->
    <div class="fc-card">
        <div class="fc-card-hdr">
            <span class="fc-card-title">
                <i class="bi bi-file-earmark-check-fill text-primary"></i>
                Datos del Contrato
            </span>
            <div class="d-flex gap-2 align-items-center">
                <span
                    class="tipo-pill <?= $tipoCls[$tipo_ct] ?? 'tp-estandar' ?>"><?= $tipoLbl[$tipo_ct] ?? $tipo_ct ?></span>
                <span class="estado-pill <?= $estadoCls[$contrato['estado']] ?? 'ep-vencido' ?>">
                    <?= ($estadoIco[$contrato['estado']] ?? '') ?> <?= ucfirst($contrato['estado']) ?>
                </span>
                <a href="editar_contrato?id=<?= $contrato['id'] ?>" class="btn btn-sm btn-outline-warning">
                    <i class="bi bi-pencil-fill me-1"></i>Editar
                </a>
            </div>
        </div>
        <div class="fc-card-body">
            <div class="row g-3 align-items-start">
                <div class="col-md-4">
                    <div class="d-flex align-items-start gap-3">
                        <div
                            style="width:46px;height:46px;border-radius:12px;background:linear-gradient(135deg,#dbeafe,#bfdbfe);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                            <i class="bi bi-person-fill text-primary" style="font-size:1.2rem"></i>
                        </div>
                        <div>
                            <div class="fw-bold" style="font-size:.95rem">
                                <?= htmlspecialchars($contrato['receptor_nombre']) ?></div>
                            <?php if ($contrato['receptor_rtn']): ?><small class="text-muted">RTN:
                                    <?= htmlspecialchars($contrato['receptor_rtn']) ?></small><?php endif; ?>
                            <div class="d-flex flex-column gap-0 mt-1">
                                <?php if ($contrato['receptor_tel']): ?><small class="text-muted"><i
                                            class="bi bi-telephone me-1"></i><?= htmlspecialchars($contrato['receptor_tel']) ?></small><?php endif; ?>
                                <?php if ($contrato['receptor_email']): ?><small class="text-muted"><i
                                            class="bi bi-envelope me-1"></i><?= htmlspecialchars($contrato['receptor_email']) ?></small><?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="cinfo-grid">
                        <div class="cinfo-item">
                            <div class="cinfo-lbl">Servicio</div>
                            <div class="cinfo-val" style="font-size:.82rem">
                                <?= htmlspecialchars($contrato['producto_nombre']) ?></div>
                        </div>
                        <div class="cinfo-item">
                            <div class="cinfo-lbl">Monto mensual</div>
                            <div class="cinfo-val text-primary">L <?= number_format((float)$contrato['monto'], 2) ?>
                            </div>
                        </div>
                        <div class="cinfo-item">
                            <div class="cinfo-lbl">Día de cobro</div>
                            <div class="cinfo-val">Día <?= (int)$contrato['dia_pago'] ?></div>
                        </div>
                        <div class="cinfo-item">
                            <div class="cinfo-lbl">Inicio</div>
                            <div class="cinfo-val"><?= date('d/m/Y', strtotime($contrato['fecha_inicio'])) ?></div>
                        </div>
                        <div class="cinfo-item">
                            <div class="cinfo-lbl">Vencimiento</div>
                            <div class="cinfo-val">
                                <?= $contrato['fecha_fin']
                                    ? date('d/m/Y', strtotime($contrato['fecha_fin']))
                                    : '<span class="badge" style="background:#dbeafe;color:#1e40af">Indefinido</span>' ?>
                            </div>
                        </div>
                        <?php if ($tipo_ct === 'periodico' || $tipo_ct === 'rotativo'): ?>
                            <div class="cinfo-item">
                                <div class="cinfo-lbl">Frecuencia</div>
                                <div class="cinfo-val">
                                    <?= $frecuencia == 1 ? 'Mensual' : "Cada {$frecuencia} meses" ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <!-- Barra progreso -->
                    <div class="mt-3">
                        <div class="d-flex justify-content-between mb-1">
                            <small class="text-muted fw-semibold">Cumplimiento de cobro</small>
                            <small
                                class="fw-bold <?= $pctCumplimiento >= 80 ? 'text-success' : ($pctCumplimiento >= 50 ? 'text-warning' : 'text-danger') ?>">
                                <?= $mesesOk ?>/<?= $totalMeses ?> meses (<?= $pctCumplimiento ?>%)
                            </small>
                        </div>
                        <div class="prog-wrap">
                            <div class="prog-fill <?= $pctCumplimiento >= 80 ? 'prog-green' : ($pctCumplimiento >= 50 ? 'prog-amber' : 'prog-red') ?>"
                                style="width:<?= $pctCumplimiento ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Alerta meses sin factura -->
    <?php if (!empty($mesesSinFactura) && !$noIniciado): ?>
        <div class="alert d-flex align-items-start gap-3 mb-4"
            style="background:#fff7ed;border:1px solid #fed7aa;color:#7c2d12;border-radius:12px;padding:1rem 1.25rem">
            <i class="bi bi-clock-history" style="font-size:1.2rem;flex-shrink:0;color:#ea580c;margin-top:1px"></i>
            <div>
                <strong><?= $mesesPend ?> período(s) sin cobrar:</strong>
                <div class="mt-2 d-flex flex-wrap gap-1">
                    <?php foreach ($mesesSinFactura as $ms):
                        [$a, $m] = explode('-', $ms);
                    ?>
                        <span class="mes-chip pend">
                            <i class="bi bi-x-circle-fill" style="font-size:9px"></i>
                            <?= $meses_es[(int)$m] ?> <?= $a ?>
                        </span>
                    <?php endforeach; ?>
                </div>
                <?php if ($contrato['estado'] === 'activo'): ?>
                    <div class="mt-2">
                        <a href="contratos" class="btn btn-sm btn-outline-warning" style="font-size:.78rem">
                            <i class="bi bi-clock-history me-1"></i>Regularizar desde contratos
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($noIniciado): ?>
        <div class="alert"
            style="background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;border-radius:12px;padding:1rem 1.25rem">
            <i class="bi bi-clock me-2"></i>
            Este contrato aún no ha iniciado. Comienza el
            <strong><?= date('d/m/Y', strtotime($contrato['fecha_inicio'])) ?></strong>.
        </div>
    <?php endif; ?>

    <!-- Historial de facturas -->
    <div class="fc-card">
        <div class="fc-card-hdr">
            <span class="fc-card-title"><i class="bi bi-receipt text-success"></i> Historial de Facturas</span>
            <span
                style="background:var(--brand-lt);color:var(--brand);border-radius:20px;padding:.15rem .65rem;font-size:.78rem;font-weight:700">
                <?= count($facturas) ?> factura<?= count($facturas) !== 1 ? 's' : '' ?>
            </span>
        </div>
        <?php if (empty($facturas)): ?>
            <div class="fc-card-body text-center py-5">
                <i class="bi bi-file-earmark-x" style="font-size:3rem;opacity:.2;display:block;margin-bottom:.75rem"></i>
                <div class="fw-bold text-muted mb-3">No hay facturas emitidas para este contrato</div>
                <?php if (!$noIniciado && $contrato['estado'] === 'activo'): ?>
                    <a href="generar_factura?receptor_id=<?= $contrato['receptor_id'] ?>&producto_id=<?= $contrato['producto_id'] ?>&monto=<?= $contrato['monto'] ?>&contrato_id=<?= $contrato['id'] ?>"
                        class="btn-facturar">
                        <i class="bi bi-file-earmark-plus"></i> Crear Primera Factura
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div style="overflow-x:auto">
                <table class="fc-table">
                    <thead>
                        <tr>
                            <th>Correlativo</th>
                            <th>Fecha</th>
                            <th class="text-center">Período</th>
                            <th class="text-end">Subtotal</th>
                            <th class="text-end">ISV</th>
                            <th class="text-end">Total</th>
                            <th class="text-center">Declarada</th>
                            <th class="text-center">Pagada</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($facturas as $f):
                            $isv       = (float)$f['isv_15'] + (float)$f['isv_18'];
                            $esMes     = (substr($f['fecha_emision'], 0, 7) === date('Y-m'));
                            $esAnulada = ($f['estado'] ?? 'emitida') === 'anulada';
                        ?>
                            <tr class="<?= $esMes ? 'tr-actual' : ($esAnulada ? 'tr-anulada' : '') ?>">
                                <td>
                                    <span class="fw-bold"
                                        style="font-family:monospace;font-size:.83rem"><?= htmlspecialchars($f['correlativo']) ?></span>
                                    <?php if ($esMes): ?><br><span class="badge"
                                            style="background:#d1fae5;color:#065f46;font-size:.65rem">Este mes</span><?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?= date('d/m/Y', strtotime($f['fecha_emision'])) ?></div>
                                </td>
                                <td class="text-center">
                                    <?php
                                    $pm_d = (int)($f['periodo_mes_ef'] ?? 0);
                                    $pa_d = (int)($f['periodo_anio_ef'] ?? 0);
                                    $per_lbl = ($pm_d && $pa_d) ? ($meses_es[$pm_d] . ' ' . $pa_d) : '—';
                                    $per_bg  = ($pm_d && $pa_d) ? 'background:#dbeafe;color:#1e40af' : 'background:#fee2e2;color:#991b1b';
                                    ?>
                                    <span class="badge btn-editar-periodo"
                                        style="<?= $per_bg ?>;cursor:pointer;font-size:.68rem" data-factura-id="<?= $f['id'] ?>"
                                        data-contrato-id="<?= $contrato_id ?>" data-periodo-mes="<?= $pm_d ?>"
                                        data-periodo-anio="<?= $pa_d ?>" title="Clic para cambiar período de cobertura">
                                        <?= $per_lbl ?> <i class="bi bi-pencil-fill" style="font-size:8px"></i>
                                    </span>
                                </td>
                                <td class="text-end text-muted">L <?= number_format((float)$f['subtotal'], 2) ?></td>
                                <td class="text-end text-muted">L <?= number_format($isv, 2) ?></td>
                                <td class="text-end fw-bold" style="color:var(--brand)">L
                                    <?= number_format((float)$f['total'], 2) ?></td>
                                <td class="text-center">
                                    <?php if ((int)($f['estado_declarada'] ?? 0)): ?>
                                        <span class="badge" style="background:#d1fae5;color:#065f46"><i
                                                class="bi bi-check-lg me-1"></i>Declarada</span>
                                    <?php else: ?>
                                        <span class="badge"
                                            style="background:var(--surface-2);color:var(--muted);border:1px solid var(--border)">Pendiente</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ((int)($f['pagada'] ?? 0)): ?>
                                        <span class="badge" style="background:#d1fae5;color:#065f46"><i
                                                class="bi bi-check-lg me-1"></i>Pagada</span>
                                    <?php else: ?>
                                        <span class="badge"
                                            style="background:#fef3c7;color:#92400e;border:1px solid #fde68a">Pendiente</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div
                                        style="display:flex;align-items:center;justify-content:center;gap:.35rem;flex-wrap:wrap">
                                        <a href="ver_factura?id=<?= $f['id'] ?>" class="btn btn-sm btn-outline-success"
                                            target="_blank" title="Ver factura">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <button class="btn btn-sm btn-outline-danger btn-desvincular"
                                            data-factura-id="<?= $f['id'] ?>"
                                            data-correlativo="<?= htmlspecialchars($f['correlativo']) ?>"
                                            data-contrato-id="<?= $contrato_id ?>" title="Desvincular de este contrato">
                                            <i class="bi bi-link-45deg"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="text-end text-muted">Totales del contrato:</td>
                            <td class="text-end">L <?= number_format($totalSubtotal, 2) ?></td>
                            <td class="text-end">L <?= number_format($totalIsv, 2) ?></td>
                            <td class="text-end" style="color:var(--brand)">L <?= number_format($totalFacturado, 2) ?></td>
                            <td colspan="3"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Calendario de cobros -->
    <?php if (!empty($mesesEsperados)): ?>
        <div class="fc-card">
            <div class="fc-card-hdr">
                <span class="fc-card-title"><i class="bi bi-calendar2-week text-secondary"></i> Calendario de Cobros</span>
                <div class="d-flex gap-2 flex-wrap">
                    <span class="mes-chip ok"><i class="bi bi-check-circle-fill" style="font-size:9px"></i>Cobrado</span>
                    <span class="mes-chip pend" style="opacity:.85"><i class="bi bi-x-circle-fill"
                            style="font-size:9px"></i>Sin cobrar <small>(clic para regularizar)</small></span>
                    <span class="mes-chip actual"><i class="bi bi-clock-fill" style="font-size:9px"></i>Mes actual</span>
                </div>
            </div>
            <div class="fc-card-body">
                <?php foreach ($mesesPorAnio as $anio => $meses): ?>
                    <div class="mb-4">
                        <div class="anio-lbl">
                            <i class="bi bi-calendar me-1"></i><?= $anio ?>
                            <span class="ms-2 fw-normal text-muted">
                                (<?= count(array_filter($meses, fn($m) => isset($mesesConFactura[$m]))) ?>/<?= count($meses) ?>
                                cobrados)
                            </span>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($meses as $mes):
                                [$a, $m] = explode('-', $mes);
                                $tieneF = isset($mesesConFactura[$mes]);
                                $esAct  = ($mes === date('Y-m'));
                                $cls    = $esAct ? 'actual' : ($tieneF ? 'ok' : 'pend');
                                $ico    = $esAct ? 'bi-clock-fill' : ($tieneF ? 'bi-check-circle-fill' : 'bi-x-circle-fill');
                                // Para rotativo: calcular qué cliente le toca este mes
                                $turnoLabel = '';
                                $turnoFull  = '';
                                if ($tipo_ct === 'rotativo' && !empty($turnosRotativos)) {
                                    $nTurnos    = count($turnosRotativos);
                                    $cicloTotal = $nTurnos * $frecuencia; // ej: 3 clientes × 2 meses = ciclo 6
                                    $idxMes     = ($anio - $anioIniCiclo) * 12 + ((int)$m - $mesIniCiclo);
                                    $posCiclo   = (($idxMes % $cicloTotal) + $cicloTotal) % $cicloTotal;
                                    $turnoIdx   = (int)floor($posCiclo / $frecuencia);
                                    $turnoFull  = $turnosRotativos[$turnoIdx]['receptor_nombre'];
                                    $partes     = explode(' ', $turnoFull);
                                    $turnoLabel = $partes[0];
                                }
                            ?>
                                <?php if ($cls === 'pend'): ?>
                                    <span class="mes-chip pend" style="cursor:pointer" data-mes="<?= (int)$m ?>"
                                        data-anio="<?= (int)$a ?>" data-label="<?= $meses_es[(int)$m] . ' ' . $a ?>"
                                        onclick="abrirRegCalendario(this)"
                                        title="Regularizar <?= $meses_es[(int)$m] . ' ' . $a ?><?= $turnoFull ? ' — ' . $turnoFull : '' ?>">
                                        <i class="bi bi-x-circle-fill" style="font-size:9px"></i>
                                        <?= $meses_es[(int)$m] ?>
                                        <?php if ($turnoLabel): ?><span style="opacity:.7;font-size:9px"> ·
                                                <?= htmlspecialchars($turnoLabel) ?></span><?php endif; ?>
                                        <i class="bi bi-plus-circle-fill" style="font-size:9px;opacity:.7"></i>
                                    </span>
                                <?php else: ?>
                                    <span class="mes-chip <?= $cls ?>">
                                        <i class="bi <?= $ico ?>" style="font-size:9px"></i>
                                        <?= $meses_es[(int)$m] ?>
                                        <?php if ($turnoLabel): ?><span style="opacity:.7;font-size:9px"> ·
                                                <?= htmlspecialchars($turnoLabel) ?></span><?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

</div>

<script>
    const CONTRATO_FC = {
        id: <?= $contrato_id ?>,
        receptor_id: <?= (int)$contrato['receptor_id'] ?>,
        producto_id: <?= (int)$contrato['producto_id'] ?>,
        monto: <?= (float)$contrato['monto'] ?>,
        nombre: <?= json_encode($contrato['nombre_contrato']) ?>,
        receptor: <?= json_encode($contrato['receptor_nombre']) ?>,
    };

    const mesesNombresES = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre',
        'Octubre', 'Noviembre', 'Diciembre'
    ];

    /* ── Editar período de factura vinculada ──────────────────────── */
    document.querySelectorAll('.btn-editar-periodo').forEach(btn => {
        btn.addEventListener('click', () => {
            const fid = btn.dataset.facturaId;
            const cid = btn.dataset.contratoId;
            const mesAct = parseInt(btn.dataset.periodoMes) || new Date().getMonth() + 1;
            const anioAct = parseInt(btn.dataset.periodoAnio) || new Date().getFullYear();

            let mesOpts = '';
            for (let m = 1; m <= 12; m++) {
                mesOpts += `<option value="${m}" ${m===mesAct?'selected':''}>${mesesNombresES[m]}</option>`;
            }
            let anioOpts = '';
            const anioHoy = new Date().getFullYear();
            for (let a = anioHoy - 3; a <= anioHoy + 1; a++) {
                anioOpts += `<option value="${a}" ${a===anioAct?'selected':''}>${a}</option>`;
            }

            Swal.fire({
                title: 'Cambiar período de la factura',
                html: `
                <div class="text-muted small mb-3">Este cambio solo afecta al calendario de cobros.<br>La fecha de emisión no se modifica.</div>
                <div class="d-flex gap-2 justify-content-center">
                    <select id="swalPM" class="form-select form-select-sm" style="flex:1;max-width:160px">${mesOpts}</select>
                    <select id="swalPA" class="form-select form-select-sm" style="width:100px">${anioOpts}</select>
                </div>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#0f766e',
                cancelButtonColor: '#6b7280',
                confirmButtonText: '<i class="bi bi-check-circle me-1"></i>Guardar período',
                cancelButtonText: 'Cancelar',
                preConfirm: () => ({
                    pm: document.getElementById('swalPM').value,
                    pa: document.getElementById('swalPA').value,
                }),
            }).then(r => {
                if (!r.isConfirmed) return;
                // Values captured in preConfirm; use stored refs
                const pmVal = r.value?.pm;
                const paVal = r.value?.pa;
                if (!pmVal || !paVal) return;

                const fd = new FormData();
                fd.append('factura_id', fid);
                fd.append('contrato_id', cid);
                fd.append('periodo_mes', pmVal);
                fd.append('periodo_anio', paVal);

                fetch('includes/contrato_actualizar_periodo.php', {
                        method: 'POST',
                        body: fd
                    })
                    .then(res => res.json())
                    .then(d => {
                        if (d.success) {
                            Swal.fire({
                                icon: 'success',
                                title: '¡Período actualizado!',
                                text: d.message,
                                timer: 1800,
                                showConfirmButton: false
                            }).then(() => location.reload());
                        } else {
                            Swal.fire('Error', d.error || 'No se pudo actualizar.', 'error');
                        }
                    }).catch(() => Swal.fire('Error', 'Error de conexión.', 'error'));
            });
        });
    });

    /* ── Desvincular factura ─────────────────────────────────────────── */
    document.querySelectorAll('.btn-desvincular').forEach(btn => {
        btn.addEventListener('click', () => {
            const fid = btn.dataset.facturaId;
            const cid = btn.dataset.contratoId;
            const corr = btn.dataset.correlativo;
            Swal.fire({
                title: '¿Desvincular factura?',
                html: `La factura <strong>${corr}</strong> quedará libre y podrá vincularse a otro contrato.<br><span class="text-muted small">No se modifica ningún dato de la factura.</span>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                confirmButtonText: '<i class="bi bi-link-45deg me-1"></i>Sí, desvincular',
                cancelButtonText: 'Cancelar',
                reverseButtons: true,
            }).then(r => {
                if (!r.isConfirmed) return;
                const fd = new FormData();
                fd.append('factura_id', fid);
                fd.append('contrato_id', cid);
                fetch('includes/contrato_desvincular_factura.php', {
                        method: 'POST',
                        body: fd
                    })
                    .then(r => r.json())
                    .then(d => {
                        if (d.success) {
                            Swal.fire({
                                    icon: 'success',
                                    title: '¡Desvinculada!',
                                    text: d.message,
                                    timer: 1800,
                                    showConfirmButton: false
                                })
                                .then(() => location.reload());
                        } else {
                            Swal.fire('Error', d.error || 'No se pudo desvincular.', 'error');
                        }
                    }).catch(() => Swal.fire('Error', 'Error de conexión.', 'error'));
            });
        });
    });
</script>

<!-- ══ MODAL: Regularizar desde Calendario ══════════════════════════ -->
<div class="modal fade" id="modalRegCal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow" style="border-radius:16px;overflow:hidden">
            <div class="modal-header" style="background:linear-gradient(135deg,#ea580c,#c2410c);color:#fff;border:none">
                <div>
                    <h5 class="modal-title fw-bold mb-0">
                        <i class="bi bi-calendar2-x me-2"></i>Regularizar Período: <span id="cal-periodo-lbl"></span>
                    </h5>
                    <small style="opacity:.85">Contrato: <?= htmlspecialchars($contrato['nombre_contrato']) ?>
                        &nbsp;·&nbsp; <?= htmlspecialchars($contrato['receptor_nombre']) ?></small>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="alert py-2 mb-3"
                    style="background:#fff7ed;border:1px solid #fed7aa;color:#92400e;font-size:.83rem">
                    <i class="bi bi-info-circle me-1"></i>
                    Puedes <strong>crear una nueva factura</strong> con la fecha del período, o
                    <strong>vincular una factura libre</strong> si el pago ya fue registrado sin contrato.
                </div>
                <div class="d-flex gap-3 flex-wrap mb-4">
                    <a id="cal-btn-crear" href="#" class="btn btn-success">
                        <i class="bi bi-file-earmark-plus me-1"></i>Crear factura para este mes
                    </a>
                    <button type="button" class="btn btn-outline-secondary" onclick="abrirVincularDesdeCalendario()">
                        <i class="bi bi-link-45deg me-1"></i>Vincular factura existente
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══ MODAL: Vincular desde Calendario ═════════════════════════════ -->
<div class="modal fade" id="modalVincCal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow" style="border-radius:16px;overflow:hidden">
            <div class="modal-header border-0 pb-0">
                <div>
                    <h5 class="modal-title fw-bold mb-0">
                        <i class="bi bi-link-45deg me-2 text-success"></i>Vincular Factura Libre
                    </h5>
                    <small class="text-muted" id="vinc-cal-sub"></small>
                </div>
                <button type="button" class="btn-close" id="btnVincCalBack"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-2">
                    <div class="d-flex gap-2 align-items-center p-2 rounded-3 mb-2"
                        style="background:#eff6ff;border:1px solid #bfdbfe;font-size:.82rem;color:#1e40af">
                        <i class="bi bi-calendar2-check-fill"></i>
                        <span>Esta factura cubrirá el período: <strong id="vincCalPeriodoLbl"></strong></span>
                        <a href="#" id="vincCalCambiarPeriodo" class="ms-auto text-info"
                            style="font-size:.78rem">Cambiar período</a>
                    </div>
                    <div id="vincCalCambiarPeriodoWrap" class="d-none mb-2">
                        <label class="form-label small fw-semibold mb-1">Asignar al período:</label>
                        <div class="d-flex gap-2">
                            <select id="vincCalPeriodoMes" class="form-select form-select-sm" style="flex:1">
                                <option value="1">Enero</option>
                                <option value="2">Febrero</option>
                                <option value="3">Marzo</option>
                                <option value="4">Abril</option>
                                <option value="5">Mayo</option>
                                <option value="6">Junio</option>
                                <option value="7">Julio</option>
                                <option value="8">Agosto</option>
                                <option value="9">Septiembre</option>
                                <option value="10">Octubre</option>
                                <option value="11">Noviembre</option>
                                <option value="12">Diciembre</option>
                            </select>
                            <select id="vincCalPeriodoAnio" class="form-select form-select-sm" style="width:110px">
                                <?php for ($y = date('Y') - 3; $y <= date('Y'); $y++): ?>
                                    <option value="<?= $y ?>"><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Buscar factura por número o nombre del cliente</label>
                    <input type="text" id="vincCalBuscar" class="form-control form-control-sm"
                        placeholder="Ej: 001-001-01-00000001 o nombre…">
                </div>
                <div id="vincCalListado" style="max-height:320px;overflow-y:auto"></div>
                <!-- Panel expandido al seleccionar -->
                <div id="vincCalDetalle" class="d-none mt-3 p-3 rounded-3"
                    style="background:#f0fdf4;border:1px solid #a7f3d0">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="fw-bold text-success" id="vincCalDetCorr" style="font-family:monospace"></div>
                            <div class="text-muted small" id="vincCalDetFecha"></div>
                            <div class="mt-1" id="vincCalDetReceptor" style="font-size:.82rem"></div>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold" style="color:#0f766e;font-size:1.1rem" id="vincCalDetTotal"></div>
                            <small class="text-muted" id="vincCalDetSub"></small>
                            <div class="text-muted small" id="vincCalDetIsv"></div>
                        </div>
                    </div>
                    <div class="mt-2 pt-2 border-top" id="vincCalDetNotas" style="font-size:.78rem;color:#64748b"></div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnVincCalBack2">
                    <i class="bi bi-arrow-left me-1"></i>Volver
                </button>
                <button type="button" class="btn btn-success btn-sm" id="btnVincCalConfirmar" disabled>
                    <i class="bi bi-link-45deg me-1"></i>Vincular factura
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    /* ── Estado del calendario modal ──────────────────────────────────── */
    let calMesActual = null;
    let calFactSelId = null;
    let calTodasFacts = [];

    const mesesNombres = ['', 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

    function abrirRegCalendario(chip) {
        calMesActual = {
            mes: parseInt(chip.dataset.mes),
            anio: parseInt(chip.dataset.anio),
            label: chip.dataset.label,
        };
        document.getElementById('cal-periodo-lbl').textContent = calMesActual.label;
        document.getElementById('cal-btn-crear').href =
            `generar_factura?receptor_id=${CONTRATO_FC.receptor_id}` +
            `&producto_id=${CONTRATO_FC.producto_id}&monto=${CONTRATO_FC.monto}` +
            `&contrato_id=${CONTRATO_FC.id}` +
            `&periodo_mes=${calMesActual.mes}&periodo_anio=${calMesActual.anio}`;
        new bootstrap.Modal(document.getElementById('modalRegCal')).show();
    }

    function abrirVincularDesdeCalendario() {
        bootstrap.Modal.getInstance(document.getElementById('modalRegCal'))?.hide();
        document.getElementById('vinc-cal-sub').textContent =
            `Período: ${calMesActual.label} — ${CONTRATO_FC.receptor}`;
        document.getElementById('vincCalBuscar').value = '';
        document.getElementById('vincCalDetalle').classList.add('d-none');
        document.getElementById('btnVincCalConfirmar').disabled = true;
        document.getElementById('vincCalCambiarPeriodoWrap').classList.add('d-none');
        document.getElementById('vincCalPeriodoMes').value = calMesActual.mes;
        document.getElementById('vincCalPeriodoAnio').value = calMesActual.anio;
        document.getElementById('vincCalPeriodoLbl').textContent = calMesActual.label;
        calFactSelId = null;
        setTimeout(() => {
            new bootstrap.Modal(document.getElementById('modalVincCal')).show();
            cargarFacturasCalendario();
        }, 350);
    }

    function cargarFacturasCalendario() {
        const wrap = document.getElementById('vincCalListado');
        wrap.innerHTML =
            '<div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm me-2"></div>Cargando…</div>';
        fetch(`includes/facturas_sin_contrato.php?receptor_id=${CONTRATO_FC.receptor_id}&contrato_id=${CONTRATO_FC.id}`)
            .then(r => r.json())
            .then(data => {
                calTodasFacts = data;
                renderFacturasCalendario(data);
            })
            .catch(() => {
                wrap.innerHTML = '<p class="text-danger text-center py-3">Error al cargar facturas.</p>';
            });
    }

    const fmtFecha = d => {
        if (!d) return '';
        const [y, m, dd] = d.split('-');
        return `${dd}/${m}/${y}`;
    };

    function renderFacturasCalendario(lista) {
        const wrap = document.getElementById('vincCalListado');
        if (!lista.length) {
            wrap.innerHTML =
                '<p class="text-muted text-center py-3">No hay facturas libres para este cliente.<br><small>Solo aparecen facturas sin contrato asignado.</small></p>';
            return;
        }
        wrap.innerHTML = '';
        lista.forEach(f => {
            const div = document.createElement('div');
            div.className = 'fact-search-item';
            div.dataset.id = f.id;
            const mesLbl = (mesesNombres[parseInt(f.mes)] || '') + ' ' + f.anio;
            div.innerHTML = `
      <div class="d-flex justify-content-between align-items-center">
        <div>
          <strong style="font-family:monospace;font-size:.82rem">${f.correlativo}</strong>
          <span class="badge ms-2" style="background:#dbeafe;color:#1e40af;font-size:.63rem">${mesLbl}</span>
          <small class="text-muted ms-1">${fmtFecha(f.fecha_emision)}</small>
        </div>
        <div class="text-end">
          <span class="fw-bold text-success">L ${parseFloat(f.total).toLocaleString('es-HN',{minimumFractionDigits:2})}</span>
        </div>
      </div>
      <div class="d-flex justify-content-between mt-1">
        <small class="text-muted">${f.receptor_nombre}</small>
        <small class="text-muted">Sub: L ${parseFloat(f.subtotal).toLocaleString('es-HN',{minimumFractionDigits:2})}</small>
      </div>`;
            div.addEventListener('click', () => {
                wrap.querySelectorAll('.fact-search-item').forEach(i => i.classList.remove('selected'));
                div.classList.add('selected');
                calFactSelId = f.id;
                mostrarDetalleFactura(f);
                document.getElementById('btnVincCalConfirmar').disabled = false;
            });
            wrap.appendChild(div);
        });
    }

    function mostrarDetalleFactura(f) {
        const isv = (parseFloat(f.isv_15 || 0) + parseFloat(f.isv_18 || 0)).toFixed(2);
        const mesLbl = (mesesNombres[parseInt(f.mes)] || '') + ' ' + f.anio;
        document.getElementById('vincCalDetCorr').textContent = f.correlativo;
        document.getElementById('vincCalDetFecha').textContent = `Emitida: ${fmtFecha(f.fecha_emision)} (${mesLbl})`;
        document.getElementById('vincCalDetReceptor').textContent = `Cliente: ${f.receptor_nombre}`;
        document.getElementById('vincCalDetTotal').textContent =
            `L ${parseFloat(f.total).toLocaleString('es-HN',{minimumFractionDigits:2})}`;
        document.getElementById('vincCalDetSub').textContent =
            `Subtotal: L ${parseFloat(f.subtotal).toLocaleString('es-HN',{minimumFractionDigits:2})}`;
        document.getElementById('vincCalDetIsv').textContent = isv > 0 ?
            `ISV: L ${parseFloat(isv).toLocaleString('es-HN',{minimumFractionDigits:2})}` : 'Sin ISV';
        const notasEl = document.getElementById('vincCalDetNotas');
        if (f.notas) {
            notasEl.textContent = 'Notas: ' + f.notas;
            notasEl.classList.remove('d-none');
        } else {
            notasEl.classList.add('d-none');
        }
        document.getElementById('vincCalDetalle').classList.remove('d-none');
    }

    document.getElementById('vincCalCambiarPeriodo').addEventListener('click', e => {
        e.preventDefault();
        const wrap = document.getElementById('vincCalCambiarPeriodoWrap');
        wrap.classList.toggle('d-none');
        if (!wrap.classList.contains('d-none')) {
            const syncLbl = () => {
                const m = document.getElementById('vincCalPeriodoMes').value;
                const a = document.getElementById('vincCalPeriodoAnio').value;
                document.getElementById('vincCalPeriodoLbl').textContent =
                    mesesNombres[parseInt(m)] + ' ' + a;
            };
            document.getElementById('vincCalPeriodoMes').addEventListener('change', syncLbl);
            document.getElementById('vincCalPeriodoAnio').addEventListener('change', syncLbl);
        }
    });

    document.getElementById('vincCalBuscar').addEventListener('input', function() {
        const q = this.value.toLowerCase();
        renderFacturasCalendario(q ?
            calTodasFacts.filter(f => f.correlativo.toLowerCase().includes(q) || f.receptor_nombre.toLowerCase()
                .includes(q)) :
            calTodasFacts);
    });

    ['btnVincCalBack', 'btnVincCalBack2'].forEach(id => {
        document.getElementById(id)?.addEventListener('click', () => {
            bootstrap.Modal.getInstance(document.getElementById('modalVincCal'))?.hide();
            setTimeout(() => {
                if (calMesActual) abrirRegCalendario({
                    dataset: calMesActual
                });
                else new bootstrap.Modal(document.getElementById('modalRegCal')).show();
            }, 350);
        });
    });

    document.getElementById('btnVincCalConfirmar').addEventListener('click', () => {
        if (!calFactSelId) return;
        const btn = document.getElementById('btnVincCalConfirmar');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Vinculando…';
        const fd = new FormData();
        fd.append('factura_id', calFactSelId);
        fd.append('contrato_id', CONTRATO_FC.id);
        fd.append('periodo_mes', document.getElementById('vincCalPeriodoMes').value);
        fd.append('periodo_anio', document.getElementById('vincCalPeriodoAnio').value);
        fetch('includes/contrato_vincular_factura.php', {
                method: 'POST',
                body: fd
            })
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    bootstrap.Modal.getInstance(document.getElementById('modalVincCal'))?.hide();
                    Swal.fire({
                        icon: 'success',
                        title: '¡Factura vinculada!',
                        html: `Vinculada al período <strong>${calMesActual.label}</strong>.<br><small class="text-muted">La fecha de emisión original no fue modificada.</small>`,
                        timer: 2500,
                        showConfirmButton: false
                    }).then(() => location.reload());
                } else {
                    Swal.fire('Error', d.error || 'No se pudo vincular.', 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-link-45deg me-1"></i>Vincular factura';
                }
            });
    });
</script>

<?php require_once '../../includes/templates/footer.php'; ?>