<?php
$titulo = 'Estado de Resultados';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';
require_once '../../includes/templates/header.php';

$cliente_id          = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);
$establecimiento_id  = $_SESSION['establecimiento_activo'] ?? 0;

// ── Filtros ───────────────────────────────────────────────────────────────────
$anio_filtro = (int)($_GET['anio'] ?? date('Y'));
$vista       = trim($_GET['vista'] ?? 'anual');       // 'anual' | 'mensual'
$mes_filtro  = (int)($_GET['mes']  ?? date('n'));

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

// ── Rango según vista ─────────────────────────────────────────────────────────
if ($vista === 'mensual') {
    $fecha_ini = sprintf('%04d-%02d-01', $anio_filtro, $mes_filtro);
    $fecha_fin = date('Y-m-t', strtotime($fecha_ini));
} else {
    $fecha_ini = "$anio_filtro-01-01";
    $fecha_fin = "$anio_filtro-12-31";
}

// ══════════════════════════════════════════════════════════════════════════════
// INGRESOS — facturas emitidas (subtotal sin ISV)
// ══════════════════════════════════════════════════════════════════════════════

// Total del período
$stmtIng = $pdo->prepare("
    SELECT
        COALESCE(SUM(subtotal), 0)            AS subtotal,
        COALESCE(SUM(isv_15 + isv_18), 0)     AS isv,
        COALESCE(SUM(total), 0)               AS total_con_isv,
        COUNT(*)                              AS qty_facturas
    FROM facturas
    WHERE cliente_id = ?
      AND establecimiento_id = ?
      AND estado = 'emitida'
      AND DATE(fecha_emision) BETWEEN ? AND ?
");
$stmtIng->execute([$cliente_id, $establecimiento_id, $fecha_ini, $fecha_fin]);
$ing = $stmtIng->fetch(PDO::FETCH_ASSOC);

// Ingresos mes a mes (para tabla y chart)
$stmtIngMes = $pdo->prepare("
    SELECT
        MONTH(fecha_emision)               AS mes_num,
        COALESCE(SUM(subtotal), 0)         AS subtotal,
        COALESCE(SUM(isv_15 + isv_18), 0)  AS isv,
        COALESCE(SUM(total), 0)            AS total_con_isv,
        COUNT(*)                           AS qty
    FROM facturas
    WHERE cliente_id = ?
      AND establecimiento_id = ?
      AND estado = 'emitida'
      AND YEAR(fecha_emision) = ?
    GROUP BY MONTH(fecha_emision)
    ORDER BY mes_num
");
$stmtIngMes->execute([$cliente_id, $establecimiento_id, $anio_filtro]);
$ing_por_mes_raw = $stmtIngMes->fetchAll(PDO::FETCH_ASSOC);
$ing_por_mes = [];
foreach ($ing_por_mes_raw as $r) $ing_por_mes[(int)$r['mes_num']] = $r;

// Top 5 clientes del período
$stmtTopCli = $pdo->prepare("
    SELECT cf.nombre AS cliente_nombre,
           COUNT(f.id)            AS qty,
           COALESCE(SUM(f.subtotal),0) AS subtotal
    FROM facturas f
    JOIN clientes_factura cf ON cf.id = f.receptor_id
    WHERE f.cliente_id = ?
      AND f.establecimiento_id = ?
      AND f.estado = 'emitida'
      AND DATE(f.fecha_emision) BETWEEN ? AND ?
    GROUP BY f.receptor_id
    ORDER BY subtotal DESC
    LIMIT 5
");
$stmtTopCli->execute([$cliente_id, $establecimiento_id, $fecha_ini, $fecha_fin]);
$top_clientes = $stmtTopCli->fetchAll(PDO::FETCH_ASSOC);

// ── Contratos activos (ingreso proyectado recurrente) ─────────────────────────
$stmtContratos = $pdo->prepare("
    SELECT COUNT(*) AS qty_contratos, COALESCE(SUM(monto), 0) AS monto_mensual
    FROM contratos
    WHERE cliente_id = ? AND estado = 'activo'
");
$stmtContratos->execute([$cliente_id]);
$contratos_kpi = $stmtContratos->fetch(PDO::FETCH_ASSOC);

// ── Nómina: masa salarial de colaboradores activos ────────────────────────────
$stmtNomina = $pdo->prepare("
    SELECT
        COUNT(*)                                       AS qty_colab,
        COALESCE(SUM(salario_base), 0)                AS masa_bruta,
        COALESCE(SUM(
            CASE WHEN aplica_ihss=1
                 THEN LEAST(salario_base, 10294.10) * 0.07
                 ELSE 0 END
        ), 0)                                         AS ihss_patronal,
        COALESCE(SUM(
            CASE WHEN aplica_rap=1
                 THEN salario_base * 0.015
                 ELSE 0 END
        ), 0)                                         AS rap_patronal
    FROM colaboradores
    WHERE cliente_id=? AND activo=1
");
$stmtNomina->execute([$cliente_id]);
$nomina_kpi = $stmtNomina->fetch(PDO::FETCH_ASSOC);
$costo_nomina_mensual = (float)$nomina_kpi['masa_bruta']
    + (float)$nomina_kpi['ihss_patronal']
    + (float)$nomina_kpi['rap_patronal'];


// ══════════════════════════════════════════════════════════════════════════════
// EGRESOS — gastos (excluye anulados)
// ══════════════════════════════════════════════════════════════════════════════

$stmtEgr = $pdo->prepare("
    SELECT
        COALESCE(SUM(monto), 0)                                               AS total_gastos,
        COALESCE(SUM(CASE WHEN tipo='fijo'          THEN monto END), 0)       AS fijos,
        COALESCE(SUM(CASE WHEN tipo='variable'      THEN monto END), 0)       AS variables,
        COALESCE(SUM(CASE WHEN tipo='extraordinario' THEN monto END), 0)      AS extraordinarios,
        COUNT(*)                                                               AS qty_gastos
    FROM gastos
    WHERE cliente_id = ?
      AND estado != 'anulado'
      AND fecha BETWEEN ? AND ?
");
$stmtEgr->execute([$cliente_id, $fecha_ini, $fecha_fin]);
$egr = $stmtEgr->fetch(PDO::FETCH_ASSOC);

// Gastos mes a mes
$stmtEgrMes = $pdo->prepare("
    SELECT
        MONTH(fecha)                                                           AS mes_num,
        COALESCE(SUM(monto), 0)                                               AS total,
        COALESCE(SUM(CASE WHEN tipo='fijo'           THEN monto END), 0)      AS fijos,
        COALESCE(SUM(CASE WHEN tipo='variable'       THEN monto END), 0)      AS variables,
        COALESCE(SUM(CASE WHEN tipo='extraordinario' THEN monto END), 0)      AS extraordinarios
    FROM gastos
    WHERE cliente_id = ?
      AND estado != 'anulado'
      AND YEAR(fecha) = ?
    GROUP BY MONTH(fecha)
    ORDER BY mes_num
");
$stmtEgrMes->execute([$cliente_id, $anio_filtro]);
$egr_por_mes_raw = $stmtEgrMes->fetchAll(PDO::FETCH_ASSOC);
$egr_por_mes = [];
foreach ($egr_por_mes_raw as $r) $egr_por_mes[(int)$r['mes_num']] = $r;

// Gastos por categoría del período
$stmtEgrCat = $pdo->prepare("
    SELECT cg.nombre, cg.color, cg.icono,
           COALESCE(SUM(g.monto), 0) AS total,
           COUNT(g.id)               AS qty
    FROM categorias_gastos cg
    LEFT JOIN gastos g ON g.categoria_id = cg.id
        AND g.cliente_id = cg.cliente_id
        AND g.estado != 'anulado'
        AND g.fecha BETWEEN ? AND ?
    WHERE cg.cliente_id = ? AND cg.activa = 1
    GROUP BY cg.id
    HAVING total > 0
    ORDER BY total DESC
");
$stmtEgrCat->execute([$fecha_ini, $fecha_fin, $cliente_id]);
$egr_categorias = $stmtEgrCat->fetchAll(PDO::FETCH_ASSOC);

// Gastos pendientes de pago
$stmtPend = $pdo->prepare("
    SELECT COUNT(*) AS qty, COALESCE(SUM(monto), 0) AS monto
    FROM gastos
    WHERE cliente_id = ? AND estado = 'pendiente'
      AND fecha BETWEEN ? AND ?
");
$stmtPend->execute([$cliente_id, $fecha_ini, $fecha_fin]);
$pendientes = $stmtPend->fetch(PDO::FETCH_ASSOC);

// ══════════════════════════════════════════════════════════════════════════════
// UTILIDAD y CÁLCULOS
// ══════════════════════════════════════════════════════════════════════════════

$ingresos_netos  = (float)$ing['subtotal'];       // sin ISV
$egresos_totales = (float)$egr['total_gastos'];
$utilidad_neta   = $ingresos_netos - $egresos_totales;
$margen_pct      = $ingresos_netos > 0
    ? round(($utilidad_neta / $ingresos_netos) * 100, 1)
    : null;

// Mes anterior para comparativas
$dtAnt = new \DateTime("$anio_filtro-$mes_filtro-01");
$dtAnt->modify('-1 month');
$stmtIngAnt = $pdo->prepare("SELECT COALESCE(SUM(subtotal),0) FROM facturas WHERE cliente_id=? AND establecimiento_id=? AND estado='emitida' AND MONTH(fecha_emision)=? AND YEAR(fecha_emision)=?");
$stmtIngAnt->execute([$cliente_id, $establecimiento_id, (int)$dtAnt->format('n'), (int)$dtAnt->format('Y')]);
$ing_ant = (float)$stmtIngAnt->fetchColumn();

$stmtEgrAnt = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM gastos WHERE cliente_id=? AND estado!='anulado' AND MONTH(fecha)=? AND YEAR(fecha)=?");
$stmtEgrAnt->execute([$cliente_id, (int)$dtAnt->format('n'), (int)$dtAnt->format('Y')]);
$egr_ant = (float)$stmtEgrAnt->fetchColumn();

// Preparar datos para Chart.js (12 meses)
$chart_labels   = [];
$chart_ingresos = [];
$chart_egresos  = [];
$chart_utilidad = [];
for ($m = 1; $m <= 12; $m++) {
    $chart_labels[]   = substr($meses_es[$m], 0, 3);
    $chart_ingresos[] = round((float)($ing_por_mes[$m]['subtotal'] ?? 0), 2);
    $chart_egresos[]  = round((float)($egr_por_mes[$m]['total']    ?? 0), 2);
    $chart_utilidad[] = round(
        (float)($ing_por_mes[$m]['subtotal'] ?? 0) - (float)($egr_por_mes[$m]['total'] ?? 0),
        2
    );
}
?>
<?php /* ── Assets ── */ ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
    rel="stylesheet">

<style>
    :root {
        --brand-fin: #1e40af;
        --brand-fin-dk: #1e3a5f;
        --brand-fin-lt: #dbeafe;
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

    .fin-wrap {
        max-width: 1200px;
        margin: 0 auto;
        padding: 1.5rem 0 4rem
    }

    /* Hero */
    .fin-hero {
        background: linear-gradient(135deg, #1e3a5f 0%, #1e40af 100%);
        border-radius: var(--radius);
        padding: 1.6rem 2rem;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1.75rem;
        box-shadow: 0 8px 32px rgba(30, 64, 175, .25);
        position: relative;
        overflow: hidden;
    }

    .fin-hero::before {
        content: '';
        position: absolute;
        top: -50px;
        right: -50px;
        width: 200px;
        height: 200px;
        border-radius: 50%;
        background: rgba(255, 255, 255, .06);
        pointer-events: none;
    }

    .fin-hero::after {
        content: '';
        position: absolute;
        bottom: -60px;
        left: 25%;
        width: 280px;
        height: 140px;
        border-radius: 50%;
        background: rgba(255, 255, 255, .04);
        pointer-events: none;
    }

    /* Filtros card */
    .fin-filters {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 1rem 1.4rem;
        margin-bottom: 1.25rem;
        box-shadow: var(--shadow-sm);
    }

    /* KPIs */
    .fin-kpis {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: .85rem;
        margin-bottom: 1.5rem;
    }

    .fin-kpi {
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

    .fin-kpi:hover {
        box-shadow: var(--shadow-md);
        transform: translateY(-2px)
    }

    .fin-kpi-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        flex-shrink: 0;
    }

    .ki-g {
        background: #d1fae5;
        color: #059669
    }

    .ki-r {
        background: #fee2e2;
        color: #dc2626
    }

    .ki-b {
        background: #dbeafe;
        color: #1d4ed8
    }

    .ki-y {
        background: #fef3c7;
        color: #d97706
    }

    .ki-t {
        background: #ccfbf1;
        color: #0f766e
    }

    .ki-p {
        background: #ede9fe;
        color: #7c3aed
    }

    .fin-kpi-val {
        font-size: 1.05rem;
        font-weight: 800;
        color: var(--text);
        line-height: 1.1
    }

    .fin-kpi-lbl {
        font-size: .68rem;
        color: var(--muted);
        margin-top: 2px;
        text-transform: uppercase;
        letter-spacing: .04em
    }

    .fin-kpi-sub {
        font-size: .7rem;
        color: var(--muted);
        margin-top: 3px
    }

    /* Cards */
    .fin-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow-sm);
        overflow: hidden;
        margin-bottom: 1.25rem;
    }

    .fin-card-hdr {
        padding: .9rem 1.4rem;
        border-bottom: 1px solid var(--border);
        background: var(--surface-2);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .5rem;
    }

    .fin-card-title {
        font-size: .9rem;
        font-weight: 700;
        color: var(--text);
        display: flex;
        align-items: center;
        gap: .5rem;
    }

    /* Tabla ER */
    .fin-table {
        width: 100%;
        border-collapse: collapse;
        font-size: .82rem
    }

    .fin-table thead th {
        padding: .6rem .85rem;
        background: #1e3a5f;
        color: #e2e8f0;
        font-size: .68rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .04em;
        white-space: nowrap;
    }

    .fin-table tbody tr {
        border-bottom: 1px solid var(--border);
        transition: background var(--tr)
    }

    .fin-table tbody tr:hover {
        background: #f0f9ff
    }

    .fin-table tbody td {
        padding: .6rem .85rem;
        vertical-align: middle
    }

    .fin-table tfoot td {
        padding: .6rem .85rem;
        font-weight: 700;
        background: #f8fafc;
        border-top: 2px solid var(--border)
    }

    .tr-ing td {
        background: #f0fdf4
    }

    .tr-egr td {
        background: #fff7ed
    }

    .tr-util td {
        background: #eff6ff;
        font-weight: 700
    }

    .tr-nom td {
        background: #fffbeb
    }

    .tr-sub td {
        font-size: .74rem;
        color: var(--muted)
    }

    .tr-mgn td {
        font-size: .72rem
    }

    .tr-cur td {
        font-weight: 700
    }

    /* Delta badges */
    .delta-pos {
        color: #059669;
        font-size: .75rem;
        font-weight: 600
    }

    .delta-neg {
        color: #dc2626;
        font-size: .75rem;
        font-weight: 600
    }

    /* Margen badge */
    .mgn-pos {
        background: #d1fae5;
        color: #065f46;
        border: 1px solid #a7f3d0
    }

    .mgn-neg {
        background: #fee2e2;
        color: #991b1b;
        border: 1px solid #fca5a5
    }

    /* Progress bars */
    .fin-bar {
        height: 5px;
        border-radius: 3px;
        overflow: hidden;
        background: var(--border);
        margin-top: 4px
    }

    .fin-bar-fill {
        height: 100%;
        border-radius: 3px
    }

    /* Chart legend */
    .chart-legend {
        display: flex;
        gap: 1.25rem;
        flex-wrap: wrap;
        justify-content: center;
        padding: .7rem 0 .25rem;
        font-size: .78rem;
        color: var(--muted)
    }

    .cl-item {
        display: flex;
        align-items: center;
        gap: .4rem
    }

    .cl-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        flex-shrink: 0
    }
</style>

<div class="container-xxl fin-wrap">

    <!-- Hero -->
    <div class="fin-hero">
        <div>
            <h4 style="font-size:1.35rem;font-weight:800;margin:0">
                <i class="bi bi-bar-chart-line me-2"></i>Estado de Resultados
            </h4>
            <p style="font-size:.82rem;opacity:.78;margin:.2rem 0 0">
                Ingresos vs. Egresos · Utilidad operativa ·
                <span style="background:rgba(255,255,255,.15);padding:2px 10px;border-radius:20px;font-size:.78rem">
                    <?= $vista === 'anual' ? "Año $anio_filtro completo" : $meses_es[$mes_filtro] . " $anio_filtro" ?>
                </span>
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="gastos" class="btn btn-sm"
                style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);font-weight:600">
                <i class="bi bi-wallet2 me-1"></i>Gastos
            </a>
            <a href="contratos" class="btn btn-sm"
                style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);font-weight:600">
                <i class="bi bi-file-earmark-text me-1"></i>Contratos
            </a>
            <a href="proyeccion" class="btn btn-sm"
                style="background:rgba(16,185,129,.25);color:#fff;border:1px solid rgba(16,185,129,.5);font-weight:600">
                <i class="bi bi-graph-up-arrow me-1"></i>Proyección →
            </a>
        </div>
    </div>

    <!-- Filtros -->
    <div class="fin-filters">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label small fw-semibold mb-1">Vista</label>
                <select name="vista" id="selectVista" class="form-select form-select-sm">
                    <option value="anual" <?= $vista === 'anual'   ? 'selected' : '' ?>>📅 Año completo</option>
                    <option value="mensual" <?= $vista === 'mensual' ? 'selected' : '' ?>>🗓️ Mes específico</option>
                </select>
            </div>
            <div class="col-auto" id="grpMes" <?= $vista !== 'mensual' ? 'style="display:none"' : '' ?>>
                <label class="form-label small fw-semibold mb-1">Mes</label>
                <select name="mes" class="form-select form-select-sm">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m == $mes_filtro ? 'selected' : '' ?>><?= $meses_es[$m] ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label small fw-semibold mb-1">Año</label>
                <select name="anio" class="form-select form-select-sm">
                    <?php for ($a = date('Y'); $a >= date('Y') - 4; $a--): ?>
                        <option value="<?= $a ?>" <?= $a == $anio_filtro ? 'selected' : '' ?>><?= $a ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i>Ver</button>
                <a href="financiero" class="btn btn-outline-secondary btn-sm ms-1"><i
                        class="bi bi-arrow-counterclockwise me-1"></i>Hoy</a>
            </div>
        </form>
    </div>

    <!-- Alerta pendientes -->
    <?php if ((int)$pendientes['qty'] > 0): ?>
        <div class="alert d-flex align-items-center gap-3 mb-4 shadow-sm"
            style="background:#fffbeb;border:1px solid #fde68a;color:#78350f;border-radius:var(--radius)">
            <i class="bi bi-exclamation-triangle-fill" style="font-size:1.3rem;flex-shrink:0;color:#d97706"></i>
            <div>
                <strong><?= (int)$pendientes['qty'] ?> gasto(s) pendiente(s)</strong> por
                <strong>L <?= number_format((float)$pendientes['monto'], 2) ?></strong> — ya incluidos en egresos.
                <a href="gastos?mes=<?= $mes_filtro ?>&anio=<?= $anio_filtro ?>" class="ms-2 fw-semibold"
                    style="color:#92400e">Ver →</a>
            </div>
        </div>
    <?php endif; ?>

    <!-- KPIs -->
    <div class="fin-kpis">
        <!-- Ingresos -->
        <div class="fin-kpi" style="border-color:#a7f3d0">
            <div class="fin-kpi-icon ki-g"><i class="bi bi-arrow-up-circle-fill"></i></div>
            <div style="min-width:0">
                <div class="fin-kpi-val" style="color:#059669">L <?= number_format($ingresos_netos, 0) ?></div>
                <div class="fin-kpi-lbl">Ingresos Netos</div>
                <div class="fin-kpi-sub"><?= (int)$ing['qty_facturas'] ?> fact. · sin ISV
                    <?php if ($vista === 'mensual' && $ing_ant > 0): $vi = round((($ingresos_netos - $ing_ant) / $ing_ant) * 100, 1); ?>
                        <span class="<?= $vi >= 0 ? 'delta-pos' : 'delta-neg' ?>">
                            <i class="bi bi-arrow-<?= $vi >= 0 ? 'up' : 'down' ?>"></i><?= abs($vi) ?>%
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Egresos -->
        <div class="fin-kpi" style="border-color:#fca5a5">
            <div class="fin-kpi-icon ki-r"><i class="bi bi-arrow-down-circle-fill"></i></div>
            <div style="min-width:0">
                <div class="fin-kpi-val" style="color:#dc2626">L <?= number_format($egresos_totales, 0) ?></div>
                <div class="fin-kpi-lbl">Egresos Totales</div>
                <div class="fin-kpi-sub"><?= (int)$egr['qty_gastos'] ?> registros
                    <?php if ($vista === 'mensual' && $egr_ant > 0): $ve = round((($egresos_totales - $egr_ant) / $egr_ant) * 100, 1); ?>
                        <span class="<?= $ve <= 0 ? 'delta-pos' : 'delta-neg' ?>">
                            <i class="bi bi-arrow-<?= $ve >= 0 ? 'up' : 'down' ?>"></i><?= abs($ve) ?>%
                        </span>
                    <?php endif; ?>
                </div>
                <div style="font-size:.68rem;color:var(--muted);margin-top:2px">
                    <span style="color:#7c3aed">F:L<?= number_format((float)$egr['fijos'], 0) ?></span> ·
                    <span style="color:#1d4ed8">V:L<?= number_format((float)$egr['variables'], 0) ?></span> ·
                    <span style="color:#d97706">E:L<?= number_format((float)$egr['extraordinarios'], 0) ?></span>
                </div>
            </div>
        </div>

        <!-- Utilidad -->
        <?php $util_ok = $utilidad_neta >= 0; ?>
        <div class="fin-kpi"
            style="border-color:<?= $util_ok ? '#a7f3d0' : '#fca5a5' ?>;background:<?= $util_ok ? 'linear-gradient(135deg,#f0fdf4,#d1fae5)' : 'linear-gradient(135deg,#fff1f2,#fee2e2)' ?>">
            <div class="fin-kpi-icon <?= $util_ok ? 'ki-g' : 'ki-r' ?>">
                <i class="bi bi-<?= $util_ok ? 'graph-up-arrow' : 'graph-down-arrow' ?>"></i>
            </div>
            <div>
                <div class="fin-kpi-val" style="color:<?= $util_ok ? '#059669' : '#dc2626' ?>">
                    <?= $utilidad_neta < 0 ? '-' : '' ?>L <?= number_format(abs($utilidad_neta), 0) ?>
                </div>
                <div class="fin-kpi-lbl">Utilidad Neta</div>
                <?php if ($margen_pct !== null): ?>
                    <div class="mt-1">
                        <span class="badge rounded-pill px-2 <?= $margen_pct >= 0 ? 'mgn-pos' : 'mgn-neg' ?>"
                            style="font-size:.7rem">
                            Margen <?= $margen_pct ?>%
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Contratos -->
        <div class="fin-kpi">
            <div class="fin-kpi-icon ki-b"><i class="bi bi-file-earmark-check-fill"></i></div>
            <div>
                <div class="fin-kpi-val" style="color:#1d4ed8"><?= (int)$contratos_kpi['qty_contratos'] ?></div>
                <div class="fin-kpi-lbl">Contratos activos</div>
                <div class="fin-kpi-sub">L <?= number_format((float)$contratos_kpi['monto_mensual'], 0) ?>/mes</div>
            </div>
        </div>

        <!-- Nómina -->
        <div class="fin-kpi">
            <div class="fin-kpi-icon ki-y"><i class="bi bi-people-fill"></i></div>
            <div>
                <div class="fin-kpi-val" style="font-size:.9rem;color:#d97706">L
                    <?= number_format($costo_nomina_mensual, 0) ?></div>
                <div class="fin-kpi-lbl">Nómina/mes</div>
                <div class="fin-kpi-sub"><?= (int)$nomina_kpi['qty_colab'] ?> colab. · Bruto
                    L<?= number_format((float)$nomina_kpi['masa_bruta'], 0) ?></div>
            </div>
        </div>
    </div>

    <!-- Gráfico -->
    <div class="fin-card">
        <div class="fin-card-hdr">
            <span class="fin-card-title">
                <i class="bi bi-bar-chart-line-fill" style="color:#1d4ed8"></i>
                Ingresos vs. Egresos — <?= $anio_filtro ?>
            </span>
            <small class="text-muted" style="font-size:.75rem">Valores reales del período · Sin ISV</small>
        </div>
        <div class="p-3" style="height:280px">
            <canvas id="chartFinanciero"></canvas>
        </div>
        <div class="chart-legend pb-2">
            <div class="cl-item">
                <div class="cl-dot" style="background:rgba(16,185,129,.7)"></div>Ingresos
            </div>
            <div class="cl-item">
                <div class="cl-dot" style="background:rgba(239,68,68,.7)"></div>Egresos
            </div>
            <div class="cl-item">
                <div class="cl-dot" style="background:#3b82f6"></div>Utilidad neta
            </div>
            <div class="cl-item" style="color:#f59e0b">
                <span
                    style="width:18px;height:0;border-top:2px dashed #f59e0b;flex-shrink:0;display:inline-block"></span>
                Nómina mensual
            </div>
        </div>
    </div>

    <!-- Tabla Estado de Resultados -->
    <div class="fin-card">
        <div class="fin-card-hdr">
            <span class="fin-card-title">
                <i class="bi bi-table text-secondary"></i>
                Estado de Resultados — <?= $anio_filtro ?> (mes a mes)
            </span>
            <small class="text-muted" style="font-size:.75rem">En Lempiras · Sin ISV</small>
        </div>
        <div style="overflow-x:auto">
            <table class="fin-table">
                <thead>
                    <tr>
                        <th style="width:155px">Concepto</th>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <th class="text-center <?= ($vista === 'mensual' && $m == $mes_filtro) ? 'tr-cur' : '' ?>"
                                style="<?= ($vista === 'mensual' && $m == $mes_filtro) ? 'background:#1d4ed8!important' : '' ?>">
                                <?= substr($meses_es[$m], 0, 3) ?>
                            </th>
                        <?php endfor; ?>
                        <th class="text-center" style="background:#0f172a">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- INGRESOS -->
                    <tr class="tr-ing">
                        <td class="fw-bold small" style="color:#059669">
                            <i class="bi bi-plus-circle-fill me-1"></i>INGRESOS
                        </td>
                        <?php $total_ing_anual = 0;
                        for ($m = 1; $m <= 12; $m++):
                            $v = (float)($ing_por_mes[$m]['subtotal'] ?? 0);
                            $total_ing_anual += $v; ?>
                            <td class="text-end small <?= ($vista === 'mensual' && $m == $mes_filtro) ? 'fw-bold' : '' ?>"
                                style="<?= ($vista === 'mensual' && $m == $mes_filtro) ? 'background:#dbeafe' : '' ?>">
                                <?= $v > 0 ? number_format($v, 0) : '<span class="text-muted">—</span>' ?>
                            </td>
                        <?php endfor; ?>
                        <td class="text-end fw-bold small" style="color:#059669">L
                            <?= number_format($total_ing_anual, 0) ?></td>
                    </tr>
                    <tr class="tr-sub tr-ing">
                        <td class="ps-4 text-muted">↳ Facturación emitida</td>
                        <?php for ($m = 1; $m <= 12; $m++): $v = (float)($ing_por_mes[$m]['subtotal'] ?? 0); ?>
                            <td class="text-end text-muted"><?= $v > 0 ? number_format($v, 0) : '' ?></td>
                        <?php endfor; ?>
                        <td class="text-end text-muted small">L <?= number_format($total_ing_anual, 0) ?></td>
                    </tr>

                    <!-- EGRESOS -->
                    <tr class="tr-egr">
                        <td class="fw-bold small" style="color:#dc2626">
                            <i class="bi bi-dash-circle-fill me-1"></i>EGRESOS
                        </td>
                        <?php $total_egr_anual = 0;
                        for ($m = 1; $m <= 12; $m++):
                            $v = (float)($egr_por_mes[$m]['total'] ?? 0);
                            $total_egr_anual += $v; ?>
                            <td class="text-end small <?= ($vista === 'mensual' && $m == $mes_filtro) ? 'fw-bold' : '' ?>"
                                style="<?= ($vista === 'mensual' && $m == $mes_filtro) ? 'background:#fee2e2' : '' ?>">
                                <?= $v > 0 ? number_format($v, 0) : '<span class="text-muted">—</span>' ?>
                            </td>
                        <?php endfor; ?>
                        <td class="text-end fw-bold small" style="color:#dc2626">L
                            <?= number_format($total_egr_anual, 0) ?></td>
                    </tr>
                    <tr class="tr-sub tr-egr">
                        <td class="ps-4 text-muted">↳ 🔒 Fijos</td>
                        <?php $tot_fijos = 0;
                        for ($m = 1; $m <= 12; $m++): $v = (float)($egr_por_mes[$m]['fijos'] ?? 0);
                            $tot_fijos += $v; ?>
                            <td class="text-end text-muted"><?= $v > 0 ? number_format($v, 0) : '' ?></td>
                        <?php endfor; ?>
                        <td class="text-end text-muted small">L <?= number_format($tot_fijos, 0) ?></td>
                    </tr>
                    <tr class="tr-sub tr-egr">
                        <td class="ps-4 text-muted">↳ 📊 Variables</td>
                        <?php $tot_var = 0;
                        for ($m = 1; $m <= 12; $m++): $v = (float)($egr_por_mes[$m]['variables'] ?? 0);
                            $tot_var += $v; ?>
                            <td class="text-end text-muted"><?= $v > 0 ? number_format($v, 0) : '' ?></td>
                        <?php endfor; ?>
                        <td class="text-end text-muted small">L <?= number_format($tot_var, 0) ?></td>
                    </tr>
                    <tr class="tr-sub tr-egr">
                        <td class="ps-4 text-muted">↳ ⭐ Extraordinarios</td>
                        <?php $tot_ext = 0;
                        for ($m = 1; $m <= 12; $m++): $v = (float)($egr_por_mes[$m]['extraordinarios'] ?? 0);
                            $tot_ext += $v; ?>
                            <td class="text-end text-muted"><?= $v > 0 ? number_format($v, 0) : '' ?></td>
                        <?php endfor; ?>
                        <td class="text-end text-muted small">L <?= number_format($tot_ext, 0) ?></td>
                    </tr>
                    <tr class="tr-nom" style="font-size:.74rem">
                        <td class="ps-4 fw-semibold" style="color:#d97706">
                            <i class="bi bi-people-fill me-1"></i>Nómina ref./mes
                        </td>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <td class="text-end" style="color:#d97706"><?= number_format($costo_nomina_mensual, 0) ?></td>
                        <?php endfor; ?>
                        <td class="text-end fw-bold" style="color:#d97706">L
                            <?= number_format($costo_nomina_mensual * 12, 0) ?></td>
                    </tr>

                    <!-- UTILIDAD -->
                    <tr class="tr-util">
                        <td class="fw-bold small">
                            <i class="bi bi-equals me-1 text-primary"></i>UTILIDAD NETA
                        </td>
                        <?php $tot_util_anual = 0;
                        for ($m = 1; $m <= 12; $m++):
                            $util = (float)($ing_por_mes[$m]['subtotal'] ?? 0) - (float)($egr_por_mes[$m]['total'] ?? 0);
                            $tot_util_anual += $util; ?>
                            <td class="text-end fw-bold <?= $util >= 0 ? 'text-success' : 'text-danger' ?>"
                                style="<?= ($vista === 'mensual' && $m == $mes_filtro) ? 'background:#bfdbfe;font-size:.95rem' : '' ?>">
                                <?php if ($util == 0 && !isset($ing_por_mes[$m]) && !isset($egr_por_mes[$m])): ?>
                                    <span class="text-muted">—</span>
                                <?php else: ?>
                                    <?= number_format($util, 0) ?>
                                <?php endif; ?>
                            </td>
                        <?php endfor; ?>
                        <td class="text-end fw-bold <?= $tot_util_anual >= 0 ? 'text-success' : 'text-danger' ?>">
                            L <?= number_format($tot_util_anual, 0) ?>
                        </td>
                    </tr>

                    <!-- MARGEN -->
                    <tr class="tr-mgn" style="background:#f8fafc">
                        <td class="ps-4 text-muted" style="font-size:.72rem">Margen %</td>
                        <?php for ($m = 1; $m <= 12; $m++):
                            $im = (float)($ing_por_mes[$m]['subtotal'] ?? 0);
                            $em = (float)($egr_por_mes[$m]['total'] ?? 0);
                            $mgn = $im > 0 ? round((($im - $em) / $im) * 100, 1) : null; ?>
                            <td
                                class="text-center <?= $mgn === null ? '' : ($mgn >= 0 ? 'text-success' : 'text-danger') ?>">
                                <?= $mgn !== null ? $mgn . '%' : '' ?>
                            </td>
                        <?php endfor; ?>
                        <td
                            class="text-center <?= $margen_pct !== null ? ($margen_pct >= 0 ? 'text-success' : 'text-danger') : '' ?>">
                            <?= $margen_pct !== null ? $margen_pct . '%' : '—' ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div
            style="padding:.6rem 1.2rem;background:var(--surface-2);border-top:1px solid var(--border);font-size:.75rem;color:var(--muted)">
            <i class="bi bi-info-circle me-1 text-info"></i>
            <strong>Nota:</strong> El ISV no forma parte del Estado de Resultados.
            <?php if ((float)$ing['isv'] > 0): ?>
                ISV recaudado: <strong>L <?= number_format((float)$ing['isv'], 2) ?></strong> (excluido de ingresos y
                egresos).
            <?php endif; ?>
        </div>
    </div>

    <!-- Bottom row: Top Clientes + Gastos por Categoría -->
    <div class="row g-3 mb-4">
        <!-- Top Clientes -->
        <div class="col-lg-5">
            <div class="fin-card h-100" style="margin-bottom:0">
                <div class="fin-card-hdr">
                    <span class="fin-card-title">
                        <i class="bi bi-people-fill" style="color:#059669"></i>
                        Top Clientes —
                        <?= $vista === 'anual' ? "Año $anio_filtro" : $meses_es[$mes_filtro] . " $anio_filtro" ?>
                    </span>
                </div>
                <div class="p-3">
                    <?php if (empty($top_clientes)): ?>
                        <p class="text-muted text-center py-4">Sin facturas en el período.</p>
                        <?php else:
                        $max_top = max(array_column($top_clientes, 'subtotal'));
                        foreach ($top_clientes as $i => $tc):
                            $pct = $max_top > 0 ? round(($tc['subtotal'] / $max_top) * 100, 0) : 0;
                        ?>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between mb-1 align-items-center">
                                    <span class="fw-semibold"
                                        style="font-size:.83rem;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                        <?= htmlspecialchars($tc['cliente_nombre']) ?>
                                    </span>
                                    <span class="fw-bold text-success ms-2" style="font-size:.83rem;white-space:nowrap">
                                        L <?= number_format((float)$tc['subtotal'], 0) ?>
                                    </span>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="fin-bar flex-grow-1">
                                        <div class="fin-bar-fill" style="width:<?= $pct ?>%;background:#10b981"></div>
                                    </div>
                                    <small class="text-muted" style="width:40px;text-align:right"><?= $tc['qty'] ?> f.</small>
                                </div>
                            </div>
                    <?php endforeach;
                    endif; ?>
                </div>
            </div>
        </div>

        <!-- Gastos por Categoría -->
        <div class="col-lg-7">
            <div class="fin-card h-100" style="margin-bottom:0">
                <div class="fin-card-hdr">
                    <span class="fin-card-title">
                        <i class="bi bi-pie-chart-fill" style="color:#dc2626"></i>
                        Egresos por Categoría — período
                    </span>
                </div>
                <div class="p-3">
                    <?php if (empty($egr_categorias)): ?>
                        <p class="text-muted text-center py-4">Sin gastos en el período.</p>
                        <?php else:
                        $max_cat = max(array_column($egr_categorias, 'total'));
                        $tot_cat = array_sum(array_column($egr_categorias, 'total'));
                        foreach ($egr_categorias as $cat):
                            $pct_cat = $tot_cat > 0 ? round(($cat['total'] / $tot_cat) * 100, 1) : 0;
                        ?>
                            <div class="mb-2">
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                                        style="width:28px;height:28px;background:<?= $cat['color'] ?>18;border:1px solid <?= $cat['color'] ?>50">
                                        <i class="fa-solid <?= htmlspecialchars($cat['icono']) ?>"
                                            style="font-size:11px;color:<?= $cat['color'] ?>"></i>
                                    </div>
                                    <span class="fw-semibold flex-grow-1"
                                        style="font-size:.82rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                        <?= htmlspecialchars($cat['nombre']) ?>
                                    </span>
                                    <span class="text-muted" style="font-size:.75rem"><?= $pct_cat ?>%</span>
                                    <span class="fw-bold text-danger text-nowrap" style="font-size:.82rem">
                                        L <?= number_format((float)$cat['total'], 0) ?>
                                    </span>
                                </div>
                                <div class="fin-bar ms-4">
                                    <div class="fin-bar-fill" style="width:<?= $pct_cat ?>%;background:<?= $cat['color'] ?>">
                                    </div>
                                </div>
                            </div>
                    <?php endforeach;
                    endif; ?>
                </div>
            </div>
        </div>
    </div>

</div><!-- /fin-wrap -->

<script>
    document.getElementById('selectVista').addEventListener('change', function() {
        document.getElementById('grpMes').style.display = this.value === 'mensual' ? '' : 'none';
    });

    (function() {
        const ctx = document.getElementById('chartFinanciero').getContext('2d');
        const labels = <?= json_encode($chart_labels) ?>;
        const ingresos = <?= json_encode($chart_ingresos) ?>;
        const egresos = <?= json_encode($chart_egresos) ?>;
        const utilidad = <?= json_encode($chart_utilidad) ?>;
        const nomina = Array(12).fill(<?= round($costo_nomina_mensual, 2) ?>);

        new Chart(ctx, {
            data: {
                labels,
                datasets: [{
                        type: 'bar',
                        label: 'Ingresos',
                        data: ingresos,
                        backgroundColor: 'rgba(16,185,129,.65)',
                        borderColor: '#10b981',
                        borderWidth: 1,
                        borderRadius: 5,
                        order: 2
                    },
                    {
                        type: 'bar',
                        label: 'Egresos',
                        data: egresos,
                        backgroundColor: 'rgba(239,68,68,.55)',
                        borderColor: '#ef4444',
                        borderWidth: 1,
                        borderRadius: 5,
                        order: 2
                    },
                    {
                        type: 'line',
                        label: 'Utilidad',
                        data: utilidad,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59,130,246,.08)',
                        borderWidth: 2.5,
                        pointBackgroundColor: utilidad.map(v => v >= 0 ? '#3b82f6' : '#ef4444'),
                        pointBorderColor: utilidad.map(v => v >= 0 ? '#3b82f6' : '#ef4444'),
                        pointRadius: 5,
                        pointHoverRadius: 7,
                        fill: false,
                        tension: 0.35,
                        order: 1
                    },
                    {
                        type: 'line',
                        label: 'Nómina',
                        data: nomina,
                        borderColor: '#f59e0b',
                        backgroundColor: 'transparent',
                        borderWidth: 1.5,
                        borderDash: [5, 4],
                        pointRadius: 0,
                        pointHoverRadius: 4,
                        fill: false,
                        tension: 0,
                        order: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(15,23,42,.92)',
                        titleColor: '#f8fafc',
                        bodyColor: '#cbd5e1',
                        padding: 12,
                        cornerRadius: 10,
                        callbacks: {
                            label: ctx => '  ' + ctx.dataset.label + ': L ' +
                                ctx.parsed.y.toLocaleString('es-HN', {
                                    minimumFractionDigits: 2
                                })
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0,0,0,.04)'
                        },
                        border: {
                            dash: [3, 3]
                        },
                        ticks: {
                            font: {
                                size: 11
                            },
                            color: '#94a3b8',
                            callback: v => 'L' + (v >= 1000 ? (v / 1000).toFixed(0) + 'k' : v)
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                size: 11
                            },
                            color: '#94a3b8'
                        }
                    }
                }
            }
        });
    })();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>