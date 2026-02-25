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
<?php /* ── Chart.js (solo en esta página) ─────────────────────────────── */ ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>

<style>
    .kpi-utilidad-positiva {
        background: linear-gradient(135deg, #d1fae5, #a7f3d0);
        border-color: #10b981 !important;
    }

    .kpi-utilidad-negativa {
        background: linear-gradient(135deg, #fee2e2, #fecaca);
        border-color: #ef4444 !important;
    }

    .er-row-ingreso td {
        background: #f0fdf4;
    }

    .er-row-egreso td {
        background: #fff7ed;
    }

    .er-row-utilidad td {
        background: #eff6ff;
        font-weight: 700;
    }

    .er-row-subtotal td {
        font-weight: 600;
        border-top: 2px solid #dee2e6 !important;
    }

    .mes-col-header {
        font-size: 11px;
        white-space: nowrap;
    }

    .badge-margen-pos {
        background: #d1fae5;
        color: #065f46;
        border: 1px solid #6ee7b7;
    }

    .badge-margen-neg {
        background: #fee2e2;
        color: #991b1b;
        border: 1px solid #fca5a5;
    }
</style>

<div class="container-xxl mt-4">

    <!-- ── Cabecera ─────────────────────────────────────────────────────────── -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h4 class="mb-0">
                <i class="fa-solid fa-chart-line me-2 text-success"></i>Estado de Resultados
            </h4>
            <small class="text-muted">
                Ingresos vs. Egresos — Utilidad operativa
                <span class="ms-2 badge bg-light text-secondary border">
                    <?= $vista === 'anual'
                        ? "Año $anio_filtro completo"
                        : $meses_es[$mes_filtro] . " $anio_filtro" ?>
                </span>
            </small>
        </div>
        <div class="d-flex gap-2">
            <a href="gastos" class="btn btn-sm btn-outline-danger">
                <i class="fa-solid fa-wallet me-1"></i> Gastos
            </a>
            <a href="contratos" class="btn btn-sm btn-outline-primary">
                <i class="fa-solid fa-file-contract me-1"></i> Contratos
            </a>
        </div>
    </div>

    <!-- ── Filtros ──────────────────────────────────────────────────────────── -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body py-3">
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
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fa-solid fa-filter me-1"></i> Ver
                    </button>
                    <a href="financiero" class="btn btn-outline-secondary btn-sm ms-1">Hoy</a>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Alerta gastos pendientes ──────────────────────────────────────────── -->
    <?php if ((int)$pendientes['qty'] > 0): ?>
        <div class="alert alert-warning d-flex align-items-center gap-3 mb-4 shadow-sm" role="alert">
            <i class="fa-solid fa-triangle-exclamation fa-lg flex-shrink-0"></i>
            <div>
                <strong><?= (int)$pendientes['qty'] ?> gasto(s) pendiente(s) de pago</strong>
                por un total de <strong>L <?= number_format((float)$pendientes['monto'], 2) ?></strong>
                en el período — estos <u>ya están incluidos</u> en los egresos del Estado de Resultados.
                <a href="gastos?mes=<?= $mes_filtro ?>&anio=<?= $anio_filtro ?>" class="ms-2 alert-link small">Ver gastos →</a>
            </div>
        </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════════════════════════
         KPIs PRINCIPALES
    ══════════════════════════════════════════════════════════════════════ -->
    <div class="row g-3 mb-4">

        <!-- Ingresos -->
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-success">
                <div class="card-body py-3 px-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase mb-1">Ingresos Netos</div>
                            <div class="fs-3 fw-bold text-success">L <?= number_format($ingresos_netos, 2) ?></div>
                            <div class="text-muted" style="font-size:11px"><?= (int)$ing['qty_facturas'] ?> facturas · sin ISV</div>
                        </div>
                        <div class="rounded-circle bg-success bg-opacity-10 d-flex align-items-center justify-content-center"
                            style="width:46px;height:46px;flex-shrink:0">
                            <i class="fa-solid fa-arrow-trend-up text-success"></i>
                        </div>
                    </div>
                    <?php if ($vista === 'mensual' && $ing_ant > 0):
                        $var_ing = round((($ingresos_netos - $ing_ant) / $ing_ant) * 100, 1); ?>
                        <div class="mt-2 small <?= $var_ing >= 0 ? 'text-success' : 'text-danger' ?>">
                            <i class="fa-solid fa-arrow-<?= $var_ing >= 0 ? 'up' : 'down' ?> me-1"></i>
                            <?= abs($var_ing) ?>% vs mes anterior
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Egresos -->
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-danger">
                <div class="card-body py-3 px-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase mb-1">Egresos Totales</div>
                            <div class="fs-3 fw-bold text-danger">L <?= number_format($egresos_totales, 2) ?></div>
                            <div class="text-muted" style="font-size:11px"><?= (int)$egr['qty_gastos'] ?> registros</div>
                        </div>
                        <div class="rounded-circle bg-danger bg-opacity-10 d-flex align-items-center justify-content-center"
                            style="width:46px;height:46px;flex-shrink:0">
                            <i class="fa-solid fa-arrow-trend-down text-danger"></i>
                        </div>
                    </div>
                    <?php if ($vista === 'mensual' && $egr_ant > 0):
                        $var_egr = round((($egresos_totales - $egr_ant) / $egr_ant) * 100, 1); ?>
                        <div class="mt-2 small <?= $var_egr <= 0 ? 'text-success' : 'text-danger' ?>">
                            <i class="fa-solid fa-arrow-<?= $var_egr >= 0 ? 'up' : 'down' ?> me-1"></i>
                            <?= abs($var_egr) ?>% vs mes anterior
                        </div>
                    <?php endif; ?>
                    <div class="mt-1 d-flex gap-2 flex-wrap" style="font-size:10px">
                        <span class="text-primary">Fijos: L<?= number_format((float)$egr['fijos'], 0) ?></span>
                        <span class="text-info">Var: L<?= number_format((float)$egr['variables'], 0) ?></span>
                        <span class="text-warning">Ext: L<?= number_format((float)$egr['extraordinarios'], 0) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Utilidad -->
        <div class="col-6 col-lg-3">
            <div class="card border-2 shadow-sm h-100 <?= $utilidad_neta >= 0 ? 'kpi-utilidad-positiva border-success' : 'kpi-utilidad-negativa border-danger' ?>">
                <div class="card-body py-3 px-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase mb-1">Utilidad Neta</div>
                            <div class="fs-3 fw-bold <?= $utilidad_neta >= 0 ? 'text-success' : 'text-danger' ?>">
                                <?= $utilidad_neta < 0 ? '-' : '' ?>L <?= number_format(abs($utilidad_neta), 2) ?>
                            </div>
                            <div class="text-muted" style="font-size:11px">
                                <?= $utilidad_neta >= 0 ? 'Operación con ganancia' : '⚠️ Operación en pérdida' ?>
                            </div>
                        </div>
                        <div class="rounded-circle d-flex align-items-center justify-content-center"
                            style="width:46px;height:46px;flex-shrink:0;background:<?= $utilidad_neta >= 0 ? '#d1fae5' : '#fee2e2' ?>">
                            <i class="fa-solid fa-scale-balanced <?= $utilidad_neta >= 0 ? 'text-success' : 'text-danger' ?>"></i>
                        </div>
                    </div>
                    <?php if ($margen_pct !== null): ?>
                        <div class="mt-2">
                            <span class="badge rounded-pill px-3 <?= $margen_pct >= 0 ? 'badge-margen-pos' : 'badge-margen-neg' ?>" style="font-size:12px">
                                Margen <?= $margen_pct ?>%
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Contratos activos -->
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-primary">
                <div class="card-body py-3 px-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase mb-1">Contratos Activos</div>
                            <div class="fs-3 fw-bold text-primary"><?= (int)$contratos_kpi['qty_contratos'] ?></div>
                            <div class="text-muted" style="font-size:11px">Contratos vigentes</div>
                        </div>
                        <div class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center"
                            style="width:46px;height:46px;flex-shrink:0">
                            <i class="fa-solid fa-file-contract text-primary"></i>
                        </div>
                    </div>
                    <div class="mt-2 small text-muted">
                        Ingreso recurrente mensual:
                        <span class="fw-bold text-primary">L <?= number_format((float)$contratos_kpi['monto_mensual'], 2) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Nómina -->
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 border-start border-4 border-warning">
                <div class="card-body py-3 px-4">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-muted small fw-semibold text-uppercase mb-1">Nómina Mensual</div>
                            <div class="fs-5 fw-bold text-warning">L <?= number_format($costo_nomina_mensual, 2) ?></div>
                            <div class="text-muted" style="font-size:11px"><?= (int)$nomina_kpi['qty_colab'] ?> colaborador(es)</div>
                        </div>
                        <div class="rounded-circle bg-warning bg-opacity-10 d-flex align-items-center justify-content-center"
                            style="width:46px;height:46px;flex-shrink:0">
                            <i class="fa-solid fa-users text-warning"></i>
                        </div>
                    </div>
                    <div class="mt-1 d-flex flex-column" style="font-size:10px;gap:1px">
                        <span class="text-muted">Bruto: <strong class="text-dark">L <?= number_format((float)$nomina_kpi['masa_bruta'], 2) ?></strong></span>
                        <span class="text-muted">Patronal IHSS+RAP: <strong class="text-danger">L <?= number_format((float)$nomina_kpi['ihss_patronal'] + (float)$nomina_kpi['rap_patronal'], 2) ?></strong></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════════════
         GRÁFICO: Ingresos vs Egresos por mes
    ══════════════════════════════════════════════════════════════════════ -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-bottom py-3">
                <h6 class="mb-0 fw-bold">
                    <i class="fa-solid fa-chart-bar me-2 text-secondary"></i>
                    Ingresos vs. Egresos — <?= $anio_filtro ?>
                </h6>
            </div>
            <div class="card-body p-3">
                <div style="height:280px">
                    <canvas id="chartFinanciero"></canvas>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════════════
         ESTADO DE RESULTADOS — Tabla mes a mes
    ══════════════════════════════════════════════════════════════════════ -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">
                    <i class="fa-solid fa-table me-2 text-secondary"></i>
                    Estado de Resultados — <?= $anio_filtro ?> (mensual)
                </h6>
                <small class="text-muted">Valores en Lempiras (L) · Sin ISV</small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle mb-0" style="font-size:13px">
                        <thead class="table-dark">
                            <tr>
                                <th style="width:160px">Concepto</th>
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <th class="text-center mes-col-header <?= ($vista === 'mensual' && $m == $mes_filtro) ? 'table-warning text-dark' : '' ?>">
                                        <?= substr($meses_es[$m], 0, 3) ?>
                                    </th>
                                <?php endfor; ?>
                                <th class="text-center" style="background:#1e3a5f">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- INGRESOS -->
                            <tr>
                                <td class="fw-bold small text-success">
                                    <i class="fa-solid fa-plus-circle me-1"></i>INGRESOS
                                </td>
                                <?php $total_ing_anual = 0;
                                for ($m = 1; $m <= 12; $m++):
                                    $v = (float)($ing_por_mes[$m]['subtotal'] ?? 0);
                                    $total_ing_anual += $v; ?>
                                    <td class="text-end small er-row-ingreso <?= ($vista === 'mensual' && $m == $mes_filtro) ? 'fw-bold' : '' ?>">
                                        <?= $v > 0 ? number_format($v, 0) : '<span class="text-muted">—</span>' ?>
                                    </td>
                                <?php endfor; ?>
                                <td class="text-end fw-bold small er-row-ingreso text-success">L <?= number_format($total_ing_anual, 0) ?></td>
                            </tr>

                            <!-- Detalle: facturas -->
                            <tr style="font-size:11px">
                                <td class="ps-4 text-muted">Facturación emitida</td>
                                <?php for ($m = 1; $m <= 12; $m++):
                                    $v = (float)($ing_por_mes[$m]['subtotal'] ?? 0); ?>
                                    <td class="text-end text-muted er-row-ingreso">
                                        <?= $v > 0 ? number_format($v, 0) : '' ?>
                                    </td>
                                <?php endfor; ?>
                                <td class="text-end text-muted small er-row-ingreso">L <?= number_format($total_ing_anual, 0) ?></td>
                            </tr>

                            <!-- EGRESOS encabezado -->
                            <tr>
                                <td class="fw-bold small text-danger">
                                    <i class="fa-solid fa-minus-circle me-1"></i>EGRESOS
                                </td>
                                <?php $total_egr_anual = 0;
                                for ($m = 1; $m <= 12; $m++):
                                    $v = (float)($egr_por_mes[$m]['total'] ?? 0);
                                    $total_egr_anual += $v; ?>
                                    <td class="text-end small er-row-egreso <?= ($vista === 'mensual' && $m == $mes_filtro) ? 'fw-bold' : '' ?>">
                                        <?= $v > 0 ? number_format($v, 0) : '<span class="text-muted">—</span>' ?>
                                    </td>
                                <?php endfor; ?>
                                <td class="text-end fw-bold small er-row-egreso text-danger">L <?= number_format($total_egr_anual, 0) ?></td>
                            </tr>

                            <!-- Detalle: fijos -->
                            <tr style="font-size:11px">
                                <td class="ps-4 text-muted">🔒 Gastos fijos</td>
                                <?php $tot_fijos = 0;
                                for ($m = 1; $m <= 12; $m++):
                                    $v = (float)($egr_por_mes[$m]['fijos'] ?? 0);
                                    $tot_fijos += $v; ?>
                                    <td class="text-end text-muted er-row-egreso"><?= $v > 0 ? number_format($v, 0) : '' ?></td>
                                <?php endfor; ?>
                                <td class="text-end text-muted small er-row-egreso">L <?= number_format($tot_fijos, 0) ?></td>
                            </tr>

                            <!-- Detalle: variables -->
                            <tr style="font-size:11px">
                                <td class="ps-4 text-muted">📊 Gastos variables</td>
                                <?php $tot_var = 0;
                                for ($m = 1; $m <= 12; $m++):
                                    $v = (float)($egr_por_mes[$m]['variables'] ?? 0);
                                    $tot_var += $v; ?>
                                    <td class="text-end text-muted er-row-egreso"><?= $v > 0 ? number_format($v, 0) : '' ?></td>
                                <?php endfor; ?>
                                <td class="text-end text-muted small er-row-egreso">L <?= number_format($tot_var, 0) ?></td>
                            </tr>

                            <!-- Detalle: extraordinarios -->
                            <tr style="font-size:11px">
                                <td class="ps-4 text-muted">⭐ Extraordinarios</td>
                                <?php $tot_ext = 0;
                                for ($m = 1; $m <= 12; $m++):
                                    $v = (float)($egr_por_mes[$m]['extraordinarios'] ?? 0);
                                    $tot_ext += $v; ?>
                                    <td class="text-end text-muted er-row-egreso"><?= $v > 0 ? number_format($v, 0) : '' ?></td>
                                <?php endfor; ?>
                                <td class="text-end text-muted small er-row-egreso">L <?= number_format($tot_ext, 0) ?></td>
                            </tr>

                            <!-- Detalle: nómina proyectada -->
                            <tr style="font-size:11px;background:#fffbeb">
                                <td class="ps-4 text-warning fw-semibold">
                                    <i class="fa-solid fa-users fa-xs me-1"></i>Nómina proyectada/mes
                                </td>
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <td class="text-end text-warning">
                                        <?= number_format($costo_nomina_mensual, 0) ?>
                                    </td>
                                <?php endfor; ?>
                                <td class="text-end text-warning fw-bold small">
                                    L <?= number_format($costo_nomina_mensual * 12, 0) ?>
                                </td>
                            </tr>
                            
                            <!-- UTILIDAD NETA -->
                            <tr class="er-row-utilidad">
                                <td class="fw-bold small">
                                    <i class="fa-solid fa-equals me-1 text-primary"></i>UTILIDAD NETA
                                </td>
                                <?php $tot_util_anual = 0;
                                for ($m = 1; $m <= 12; $m++):
                                    $util = (float)($ing_por_mes[$m]['subtotal'] ?? 0) - (float)($egr_por_mes[$m]['total'] ?? 0);
                                    $tot_util_anual += $util; ?>
                                    <td class="text-end fw-bold <?= ($vista === 'mensual' && $m == $mes_filtro) ? 'fs-6' : '' ?> <?= $util >= 0 ? 'text-success' : 'text-danger' ?>">
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

                            <!-- MARGEN % -->
                            <tr style="font-size:11px">
                                <td class="ps-4 text-muted">Margen %</td>
                                <?php for ($m = 1; $m <= 12; $m++):
                                    $ing_m = (float)($ing_por_mes[$m]['subtotal'] ?? 0);
                                    $egr_m = (float)($egr_por_mes[$m]['total'] ?? 0);
                                    $mgn   = $ing_m > 0 ? round((($ing_m - $egr_m) / $ing_m) * 100, 1) : null;
                                ?>
                                    <td class="text-center <?= $mgn === null ? '' : ($mgn >= 0 ? 'text-success' : 'text-danger') ?>">
                                        <?= $mgn !== null ? $mgn . '%' : '' ?>
                                    </td>
                                <?php endfor; ?>
                                <td class="text-center <?= $margen_pct !== null ? ($margen_pct >= 0 ? 'text-success' : 'text-danger') : '' ?>">
                                    <?= $margen_pct !== null ? $margen_pct . '%' : '—' ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <!-- Nota al pie: ISV informativo -->
            <div class="card-footer bg-light border-top py-2 px-3">
                <small class="text-muted">
                    <i class="fa-solid fa-circle-info me-1 text-info"></i>
                    <strong>Nota:</strong> El ISV no forma parte del Estado de Resultados — es un impuesto recaudado a nombre del SAR.
                    <?php if ((float)$ing['isv'] > 0): ?>
                        ISV recaudado en el período: <strong>L <?= number_format((float)$ing['isv'], 2) ?></strong>
                        (no incluido en ingresos ni egresos).
                    <?php endif; ?>
                </small>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════════════
         FILA: Top Clientes | Gastos por Categoría
    ══════════════════════════════════════════════════════════════════════ -->
        <div class="row g-4 mb-5">

            <!-- Top clientes -->
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-bottom py-3">
                        <h6 class="mb-0 fw-bold">
                            <i class="fa-solid fa-users me-2 text-success"></i>
                            Top Clientes — Ingresos del período
                        </h6>
                    </div>
                    <div class="card-body p-3">
                        <?php if (empty($top_clientes)): ?>
                            <p class="text-muted text-center py-4">Sin facturas en el período.</p>
                            <?php else:
                            $max_top = max(array_column($top_clientes, 'subtotal'));
                            foreach ($top_clientes as $tc):
                                $pct = $max_top > 0 ? round(($tc['subtotal'] / $max_top) * 100, 0) : 0;
                            ?>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span class="fw-semibold small text-truncate" style="max-width:200px"><?= htmlspecialchars($tc['cliente_nombre']) ?></span>
                                        <span class="small fw-bold text-success ms-2 text-nowrap">L <?= number_format((float)$tc['subtotal'], 2) ?></span>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="progress flex-grow-1" style="height:6px">
                                            <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
                                        </div>
                                        <small class="text-muted" style="width:40px;text-align:right"><?= $tc['qty'] ?> fact.</small>
                                    </div>
                                </div>
                        <?php endforeach;
                        endif; ?>
                    </div>
                </div>
            </div>

            <!-- Gastos por categoría -->
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-white border-bottom py-3">
                        <h6 class="mb-0 fw-bold">
                            <i class="fa-solid fa-chart-pie me-2 text-danger"></i>
                            Egresos por Categoría — período
                        </h6>
                    </div>
                    <div class="card-body p-3">
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
                                            style="width:28px;height:28px;background:<?= $cat['color'] ?>20;border:1px solid <?= $cat['color'] ?>50">
                                            <i class="fa-solid <?= htmlspecialchars($cat['icono']) ?>" style="font-size:11px;color:<?= $cat['color'] ?>"></i>
                                        </div>
                                        <span class="fw-semibold small text-truncate flex-grow-1"><?= htmlspecialchars($cat['nombre']) ?></span>
                                        <span class="text-muted small"><?= $pct_cat ?>%</span>
                                        <span class="fw-bold small text-danger text-nowrap">L <?= number_format((float)$cat['total'], 2) ?></span>
                                    </div>
                                    <div class="progress ms-4" style="height:4px">
                                        <div class="progress-bar" style="width:<?= $pct_cat ?>%;background:<?= $cat['color'] ?>"></div>
                                    </div>
                                </div>
                        <?php endforeach;
                        endif; ?>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /container -->

    <script>
        // ── Vista filtro: mostrar/ocultar campo mes ───────────────────────────────────
        $('#selectVista').on('change', function() {
            $('#grpMes').toggle($(this).val() === 'mensual');
        });

        // ── Chart.js: Ingresos vs Egresos vs Utilidad ─────────────────────────────────
        (function() {
            const ctx = document.getElementById('chartFinanciero').getContext('2d');
            const labels = <?= json_encode($chart_labels) ?>;
            const ingresos = <?= json_encode($chart_ingresos) ?>;
            const egresos = <?= json_encode($chart_egresos) ?>;
            const utilidad = <?= json_encode($chart_utilidad) ?>;

            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{
                            label: 'Ingresos',
                            data: ingresos,
                            backgroundColor: 'rgba(16,185,129,0.7)',
                            borderColor: '#10b981',
                            borderWidth: 1,
                            borderRadius: 4,
                            order: 2
                        },
                        {
                            label: 'Egresos',
                            data: egresos,
                            backgroundColor: 'rgba(239,68,68,0.7)',
                            borderColor: '#ef4444',
                            borderWidth: 1,
                            borderRadius: 4,
                            order: 2
                        },
                        {
                            label: 'Utilidad',
                            data: utilidad,
                            type: 'line',
                            borderColor: '#3b82f6',
                            backgroundColor: 'rgba(59,130,246,0.1)',
                            borderWidth: 2.5,
                            pointBackgroundColor: utilidad.map(v => v >= 0 ? '#3b82f6' : '#ef4444'),
                            pointRadius: 4,
                            fill: false,
                            tension: 0.3,
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
                            position: 'bottom',
                            labels: {
                                usePointStyle: true,
                                padding: 20
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: ctx => ' L ' + ctx.parsed.y.toLocaleString('es-HN', {
                                    minimumFractionDigits: 2
                                })
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0,0,0,0.05)'
                            },
                            ticks: {
                                callback: v => 'L ' + (v >= 1000 ? (v / 1000).toFixed(0) + 'k' : v)
                            }
                        },
                        x: {
                            grid: {
                                display: false
                            }
                        }
                    }
                }
            });
        })();
    </script>

    </body>

    </html>