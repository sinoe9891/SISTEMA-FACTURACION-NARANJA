<?php
$titulo = 'Gastos';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';
require_once '../../includes/templates/header.php';

$cliente_id = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);

/* ── Filtros ─────────────────────────────────────────────────────────────── */
$vista       = in_array($_GET['vista'] ?? '', ['anual', 'mensual']) ? $_GET['vista'] : 'mensual';
$mes_filtro  = (int)($_GET['mes']  ?? date('n'));
$anio_filtro = (int)($_GET['anio'] ?? date('Y'));
$cat_filtro  = (int)($_GET['cat']  ?? 0);
$tipo_filtro = trim($_GET['tipo']  ?? '');

$meses_nombres = [
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

if ($vista === 'mensual') {
    $fecha_ini = sprintf('%04d-%02d-01', $anio_filtro, $mes_filtro);
    $fecha_fin = date('Y-m-t', strtotime($fecha_ini));
    $periodo   = $meses_nombres[$mes_filtro - 1] . ' ' . $anio_filtro;
} else {
    $fecha_ini = "$anio_filtro-01-01";
    $fecha_fin = "$anio_filtro-12-31";
    $periodo   = "Año $anio_filtro";
}

/* ── KPIs ─────────────────────────────────────────────────────────────────── */
$stmtKpi = $pdo->prepare("SELECT
    COALESCE(SUM(CASE WHEN estado!='anulado' THEN monto END),0)                         AS total_mes,
    COALESCE(SUM(CASE WHEN tipo='fijo'           AND estado!='anulado' THEN monto END),0) AS fijos,
    COALESCE(SUM(CASE WHEN tipo='variable'       AND estado!='anulado' THEN monto END),0) AS variables,
    COALESCE(SUM(CASE WHEN tipo='extraordinario' AND estado!='anulado' THEN monto END),0) AS extraordinarios,
    COALESCE(SUM(CASE WHEN tipo='viaticos'       AND estado!='anulado' THEN monto END),0) AS viaticos,
    COUNT(CASE WHEN estado='pendiente' THEN 1 END) AS pendientes,
    COUNT(CASE WHEN estado!='anulado'  THEN 1 END) AS total_registros
FROM gastos WHERE cliente_id=? AND fecha BETWEEN ? AND ?");
$stmtKpi->execute([$cliente_id, $fecha_ini, $fecha_fin]);
$kpi = $stmtKpi->fetch(PDO::FETCH_ASSOC);

/* ── Categorías ──────────────────────────────────────────────────────────── */
$stmtCats = $pdo->prepare("SELECT id,nombre,color,icono FROM categorias_gastos WHERE cliente_id=? AND activa=1 ORDER BY nombre");
$stmtCats->execute([$cliente_id]);
$categorias = $stmtCats->fetchAll(PDO::FETCH_ASSOC);

/* ── Gastos con filtros ───────────────────────────────────────────────────── */
$sql = "SELECT g.*, cg.nombre AS cat_nombre, cg.color AS cat_color, cg.icono AS cat_icono
        FROM gastos g LEFT JOIN categorias_gastos cg ON cg.id=g.categoria_id
        WHERE g.cliente_id=? AND g.fecha BETWEEN ? AND ?";
$params = [$cliente_id, $fecha_ini, $fecha_fin];
if ($cat_filtro) {
    $sql .= " AND g.categoria_id=?";
    $params[] = $cat_filtro;
}
if ($tipo_filtro) {
    $sql .= " AND g.tipo=?";
    $params[] = $tipo_filtro;
}
$sql .= " ORDER BY g.fecha DESC, g.id DESC";
$stmtG = $pdo->prepare($sql);
$stmtG->execute($params);
$gastos = $stmtG->fetchAll(PDO::FETCH_ASSOC);

$total = count($gastos);
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    :root {
        --amber: #d97706;
        --amber-dark: #b45309;
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

    .gs-page {
        padding: 1.5rem 0 3rem;
    }

    .gs-header {
        background: linear-gradient(135deg, #d97706 0%, #92400e 100%);
        border-radius: var(--radius);
        padding: 1.6rem 2rem;
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

    .gs-header::before {
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

    .gs-header::after {
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

    /* Stats */
    .gs-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .gs-stat {
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

    .gs-stat:hover {
        box-shadow: var(--shadow-md);
        transform: translateY(-2px);
    }

    .gs-stat-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        flex-shrink: 0;
    }

    .si-amb {
        background: #fef3c7;
        color: #d97706;
    }

    .si-grn {
        background: #d1fae5;
        color: #059669;
    }

    .si-yel {
        background: #fef9c3;
        color: #ca8a04;
    }

    .si-red {
        background: #fee2e2;
        color: #dc2626;
    }

    .si-blu {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .si-sky {
        background: #e0f2fe;
        color: #0369a1;
    }

    .gs-stat-val {
        font-size: 1.3rem;
        font-weight: 700;
        color: var(--text-main);
        line-height: 1;
    }

    .gs-stat-lbl {
        font-size: .72rem;
        color: var(--text-muted);
        margin-top: 2px;
    }

    /* Toolbar */
    .gs-toolbar {
        display: flex;
        align-items: center;
        gap: .75rem;
        flex-wrap: wrap;
        margin-bottom: 1.25rem;
    }

    .gs-search-wrap {
        position: relative;
        flex: 1 1 220px;
        min-width: 190px;
    }

    .gs-search-wrap>i {
        position: absolute;
        left: .85rem;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-muted);
        font-size: .9rem;
        pointer-events: none;
    }

    .gs-search {
        width: 100%;
        padding: .55rem .85rem .55rem 2.4rem;
        border: 1.5px solid var(--border);
        border-radius: var(--radius-sm);
        font-size: .9rem;
        background: var(--surface);
        color: var(--text-main);
        outline: none;
        transition: border-color var(--tr);
    }

    .gs-search:focus {
        border-color: var(--amber);
        box-shadow: 0 0 0 3px rgba(217, 119, 6, .12);
    }

    .gs-search::placeholder {
        color: #94a3b8;
    }

    .gs-clear-btn {
        position: absolute;
        right: .65rem;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        color: var(--text-muted);
        font-size: .95rem;
        cursor: pointer;
        padding: 0;
        display: none;
    }

    .gs-clear-btn.visible {
        display: block;
    }

    .btn-nuevo-gs {
        display: inline-flex;
        align-items: center;
        gap: .45rem;
        padding: .55rem 1.1rem;
        background: var(--amber);
        color: #fff !important;
        border-radius: var(--radius-sm);
        font-size: .88rem;
        font-weight: 600;
        border: none;
        cursor: pointer;
        white-space: nowrap;
        box-shadow: 0 2px 8px rgba(217, 119, 6, .3);
        transition: background var(--tr), transform var(--tr);
    }

    .btn-nuevo-gs:hover {
        background: var(--amber-dark);
        transform: translateY(-1px);
    }

    .gs-select {
        padding: .5rem .7rem;
        border: 1.5px solid var(--border);
        border-radius: var(--radius-sm);
        font-size: .85rem;
        background: var(--surface);
        cursor: pointer;
        outline: none;
    }

    /* Card tabla */
    .gs-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow-sm);
        overflow: hidden;
    }

    .gs-card-header {
        padding: 1rem 1.5rem;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: var(--surface-2);
        gap: 1rem;
        flex-wrap: wrap;
    }

    .gs-badge {
        display: inline-flex;
        align-items: center;
        background: #fef3c7;
        color: #d97706;
        border-radius: 20px;
        padding: .15rem .65rem;
        font-size: .78rem;
        font-weight: 600;
    }

    .gs-table-wrap {
        overflow-x: auto;
    }

    .gs-table {
        width: 100%;
        border-collapse: collapse;
        font-size: .855rem;
    }

    .gs-table thead th {
        padding: .75rem 1rem;
        background: var(--surface-2);
        color: var(--text-muted);
        font-weight: 600;
        font-size: .72rem;
        text-transform: uppercase;
        letter-spacing: .05em;
        border-bottom: 1px solid var(--border);
        white-space: nowrap;
        cursor: pointer;
        user-select: none;
        transition: background var(--tr), color var(--tr);
    }

    .gs-table thead th:last-child {
        cursor: default;
    }

    .gs-table thead th:hover:not(:last-child) {
        background: #fef3c7;
        color: #d97706;
    }

    .gs-table thead th.sort-asc,
    .gs-table thead th.sort-desc {
        color: #d97706;
        background: #fef3c7;
    }

    .sort-icon {
        margin-left: .35rem;
        font-size: .68rem;
        opacity: .3;
        transition: opacity .15s, transform .15s;
    }

    .gs-table thead th:hover:not(:last-child) .sort-icon,
    .gs-table thead th.sort-asc .sort-icon,
    .gs-table thead th.sort-desc .sort-icon {
        opacity: 1;
    }

    .gs-table thead th.sort-desc .sort-icon {
        transform: rotate(180deg);
    }

    .gs-table tbody tr {
        border-bottom: 1px solid var(--border);
        transition: background var(--tr);
    }

    .gs-table tbody tr:last-child {
        border-bottom: none;
    }

    .gs-table tbody tr:hover {
        background: #fffbeb;
    }

    .gs-table tbody td {
        padding: .82rem 1rem;
        color: var(--text-main);
        vertical-align: middle;
    }

    .gs-highlight {
        background: #fef08a;
        border-radius: 3px;
        padding: 0 2px;
    }

    /* Tipo/estado badges */
    .tipo-badge {
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        padding: .2rem .55rem;
        border-radius: 20px;
        font-size: .73rem;
        font-weight: 600;
    }

    .tb-var {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .tb-fij {
        background: #ede9fe;
        color: #7c3aed;
    }

    .tb-ext {
        background: #d1fae5;
        color: #059669;
    }

    .tb-via {
        background: #e0f2fe;
        color: #0369a1;
    }

    .estado-pg {
        background: #d1fae5;
        color: #059669;
        border: 1px solid #a7f3d0;
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        padding: .18rem .55rem;
        border-radius: 20px;
        font-size: .73rem;
        font-weight: 600;
    }

    .estado-pd {
        background: #fef3c7;
        color: #d97706;
        border: 1px solid #fde68a;
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        padding: .18rem .55rem;
        border-radius: 20px;
        font-size: .73rem;
        font-weight: 600;
    }

    .estado-an {
        background: #f1f5f9;
        color: #64748b;
        border: 1px solid #e2e8f0;
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        padding: .18rem .55rem;
        border-radius: 20px;
        font-size: .73rem;
        font-weight: 600;
    }

    /* Acciones */
    .gs-actions {
        display: flex;
        gap: .35rem;
        align-items: center;
    }

    .btn-a {
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        padding: .32rem .65rem;
        border-radius: var(--radius-sm);
        font-size: .77rem;
        font-weight: 600;
        cursor: pointer;
        border: 1.5px solid transparent;
        transition: all var(--tr);
        text-decoration: none;
        white-space: nowrap;
    }

    .btn-edit {
        background: #ede9fe;
        color: #7c3aed;
        border-color: rgba(124, 58, 237, .2);
    }

    .btn-edit:hover {
        background: #7c3aed;
        color: #fff;
        transform: translateY(-1px);
    }

    .btn-pay {
        background: #d1fae5;
        color: #059669;
        border-color: rgba(5, 150, 105, .2);
    }

    .btn-pay:hover {
        background: #059669;
        color: #fff;
    }

    .btn-anu {
        background: #fef3c7;
        color: #d97706;
        border-color: rgba(217, 119, 6, .2);
    }

    .btn-anu:hover {
        background: #d97706;
        color: #fff;
    }

    .btn-del {
        background: #fee2e2;
        color: #dc2626;
        border-color: rgba(220, 38, 38, .2);
    }

    .btn-del:hover {
        background: #dc2626;
        color: #fff;
    }

    /* Paginación */
    .gs-pagination {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: .85rem 1.25rem;
        border-top: 1px solid var(--border);
        background: var(--surface-2);
        gap: .75rem;
        flex-wrap: wrap;
    }

    .gs-page-info {
        font-size: .78rem;
        color: var(--text-muted);
    }

    .gs-page-btns {
        display: flex;
        gap: .3rem;
        flex-wrap: wrap;
    }

    .page-btn {
        min-width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: var(--radius-sm);
        border: 1.5px solid var(--border);
        background: var(--surface);
        color: var(--text-muted);
        font-size: .8rem;
        font-weight: 600;
        cursor: pointer;
        transition: all var(--tr);
        padding: 0 .45rem;
        user-select: none;
    }

    .page-btn:hover:not(.disabled):not(.active) {
        border-color: #d97706;
        color: #d97706;
        background: #fffbeb;
    }

    .page-btn.active {
        background: #d97706;
        border-color: #d97706;
        color: #fff;
        box-shadow: 0 2px 8px rgba(217, 119, 6, .3);
    }

    .page-btn.disabled {
        opacity: .35;
        cursor: not-allowed;
        pointer-events: none;
    }

    .gs-empty {
        text-align: center;
        padding: 3rem 1rem;
        color: var(--text-muted);
    }

    /* Modal */
    .mf-label {
        font-size: .78rem;
        font-weight: 600;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: .04em;
        margin-bottom: .35rem;
        display: block;
    }

    .mf-input,
    .mf-select {
        width: 100%;
        padding: .58rem .85rem;
        border: 1.5px solid var(--border);
        border-radius: var(--radius-sm);
        font-size: .88rem;
        color: var(--text-main);
        background: var(--surface);
        outline: none;
        transition: border-color var(--tr);
    }

    .mf-input:focus,
    .mf-select:focus {
        border-color: #d97706;
        box-shadow: 0 0 0 3px rgba(217, 119, 6, .1);
    }

    .mb-mf {
        margin-bottom: .9rem;
    }
</style>

<div class="gs-page container-xxl">

    <!-- Header -->
    <div class="gs-header">
        <div>
            <h4 style="font-size:1.35rem;font-weight:700;margin:0">💸 Gestión de Gastos</h4>
            <p style="font-size:.82rem;opacity:.8;margin:.25rem 0 0">
                <?= $periodo ?> — egresos, viáticos y gastos recurrentes
            </p>
        </div>
        <div style="font-size:3rem;opacity:.2;font-weight:900;line-height:1">💸</div>
    </div>

    <!-- Stats -->
    <div class="gs-stats">
        <div class="gs-stat">
            <div class="gs-stat-icon si-amb"><i class="bi bi-receipt-cutoff"></i></div>
            <div>
                <div class="gs-stat-val" style="font-size:1rem;">L <?= number_format((float)$kpi['total_mes'], 0) ?>
                </div>
                <div class="gs-stat-lbl">Total (<?= (int)$kpi['total_registros'] ?> reg.)</div>
            </div>
        </div>
        <div class="gs-stat">
            <div class="gs-stat-icon si-grn"><i class="bi bi-lock-fill"></i></div>
            <div>
                <div class="gs-stat-val" style="font-size:1rem;">L <?= number_format((float)$kpi['fijos'], 0) ?></div>
                <div class="gs-stat-lbl">Fijos</div>
            </div>
        </div>
        <div class="gs-stat">
            <div class="gs-stat-icon si-blu"><i class="bi bi-graph-up"></i></div>
            <div>
                <div class="gs-stat-val" style="font-size:1rem;">L <?= number_format((float)$kpi['variables'], 0) ?>
                </div>
                <div class="gs-stat-lbl">Variables</div>
            </div>
        </div>
        <div class="gs-stat">
            <div class="gs-stat-icon si-sky"><i class="bi bi-airplane-fill"></i></div>
            <div>
                <div class="gs-stat-val" style="font-size:1rem;">L <?= number_format((float)$kpi['viaticos'], 0) ?>
                </div>
                <div class="gs-stat-lbl">Viáticos</div>
            </div>
        </div>
        <div class="gs-stat">
            <div class="gs-stat-icon si-red"><i class="bi bi-hourglass-split"></i></div>
            <div>
                <div class="gs-stat-val"><?= (int)$kpi['pendientes'] ?></div>
                <div class="gs-stat-lbl">Pendientes</div>
            </div>
        </div>
        <div class="gs-stat">
            <div class="gs-stat-icon si-yel"><i class="bi bi-funnel-fill"></i></div>
            <div>
                <div class="gs-stat-val" id="statFiltered"><?= $total ?></div>
                <div class="gs-stat-lbl">Resultados</div>
            </div>
        </div>
    </div>

    <!-- Filtros GET -->
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-3">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-auto">
                    <label class="form-label small fw-semibold mb-1">Vista</label>
                    <select name="vista" id="sVista" class="form-select form-select-sm">
                        <option value="mensual" <?= $vista === 'mensual' ? 'selected' : '' ?>>🗓️ Mensual</option>
                        <option value="anual" <?= $vista === 'anual'  ? 'selected' : '' ?>>📅 Anual</option>
                    </select>
                </div>
                <div class="col-auto" id="grpMesFiltro" <?= $vista === 'anual' ? 'style="display:none"' : '' ?>>
                    <label class="form-label small fw-semibold mb-1">Mes</label>
                    <select name="mes" class="form-select form-select-sm">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $m == $mes_filtro ? 'selected' : '' ?>><?= $meses_nombres[$m - 1] ?>
                            </option>
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
                    <label class="form-label small fw-semibold mb-1">Categoría</label>
                    <select name="cat" class="form-select form-select-sm">
                        <option value="">Todas</option>
                        <?php foreach ($categorias as $cc): ?>
                            <option value="<?= $cc['id'] ?>" <?= $cc['id'] == $cat_filtro ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cc['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label small fw-semibold mb-1">Tipo</label>
                    <select name="tipo" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <option value="fijo" <?= $tipo_filtro === 'fijo'          ? 'selected' : '' ?>>🔒 Fijo</option>
                        <option value="variable" <?= $tipo_filtro === 'variable'      ? 'selected' : '' ?>>📊 Variable
                        </option>
                        <option value="extraordinario" <?= $tipo_filtro === 'extraordinario' ? 'selected' : '' ?>>⭐
                            Extraordinario</option>
                        <option value="viaticos" <?= $tipo_filtro === 'viaticos'      ? 'selected' : '' ?>>✈️ Viáticos
                        </option>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fa-solid fa-filter me-1"></i>Filtrar
                    </button>
                    <a href="gastos" class="btn btn-outline-secondary btn-sm ms-1">Limpiar</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Toolbar JS (búsqueda + filtros en tabla) -->
    <div class="gs-toolbar">
        <button class="btn-nuevo-gs" id="btnNuevoGasto">
            <i class="bi bi-plus-circle-fill"></i> Nuevo Gasto
        </button>
        <div class="gs-search-wrap">
            <i class="bi bi-search"></i>
            <input type="text" id="gsSearch" class="gs-search" placeholder="Buscar descripción, proveedor, destino…"
                autocomplete="off">
            <button class="gs-clear-btn" id="gsClear"><i class="bi bi-x-lg"></i></button>
        </div>
        <select id="gsFiltroTipo" class="gs-select">
            <option value="">Todos los tipos</option>
            <option value="fijo">🔒 Fijo</option>
            <option value="variable">📊 Variable</option>
            <option value="extraordinario">⭐ Extraordinario</option>
            <option value="viaticos">✈️ Viáticos</option>
        </select>
        <select id="gsFiltroEstado" class="gs-select">
            <option value="">Todos los estados</option>
            <option value="pendiente">⏳ Pendiente</option>
            <option value="pagado">✅ Pagado</option>
            <option value="anulado">❌ Anulado</option>
        </select>
        <select class="gs-select" id="gsPerPage">
            <option value="10" selected>10/pág</option>
            <option value="25">25/pág</option>
            <option value="50">50/pág</option>
        </select>
    </div>

    <!-- Tabla -->
    <div class="gs-card">
        <div class="gs-card-header">
            <span style="font-weight:700;font-size:.95rem;display:flex;align-items:center;gap:.5rem;">
                <i class="bi bi-table"></i> Gastos — <?= $periodo ?>
            </span>
            <span class="gs-badge" id="gsBadge"><?= $total ?> gastos</span>
        </div>
        <div class="gs-table-wrap">
            <table class="gs-table" id="gsTable">
                <thead>
                    <tr>
                        <th data-col="0"><i class="bi bi-calendar3 me-1"></i>Fecha<i
                                class="bi bi-arrow-up sort-icon"></i></th>
                        <th data-col="1"><i class="bi bi-file-text me-1"></i>Descripción<i
                                class="bi bi-arrow-up sort-icon"></i></th>
                        <th data-col="2"><i class="bi bi-tag me-1"></i>Tipo<i class="bi bi-arrow-up sort-icon"></i></th>
                        <th data-col="3"><i class="bi bi-folder me-1"></i>Categoría<i
                                class="bi bi-arrow-up sort-icon"></i></th>
                        <th data-col="4"><i class="bi bi-cash me-1"></i>Monto<i class="bi bi-arrow-up sort-icon"></i>
                        </th>
                        <th data-col="5"><i class="bi bi-circle me-1"></i>Estado<i class="bi bi-arrow-up sort-icon"></i>
                        </th>
                        <th style="cursor:default;"><i class="bi bi-gear me-1"></i>Acciones</th>
                    </tr>
                </thead>
                <tbody id="gsBody">
                    <?php
                    $tipoClasses = ['variable' => 'tb-var', 'fijo' => 'tb-fij', 'extraordinario' => 'tb-ext', 'viaticos' => 'tb-via'];
                    $tipoLabels  = ['variable' => '📊 Variable', 'fijo' => '🔒 Fijo', 'extraordinario' => '⭐ Extraordinario', 'viaticos' => '✈️ Viáticos'];
                    foreach ($gastos as $g):
                        $tCls  = $tipoClasses[$g['tipo'] ?? ''] ?? 'tb-var';
                        $tLbl  = $tipoLabels[$g['tipo'] ?? '']  ?? ucfirst($g['tipo'] ?? '');
                        $est   = $g['estado'] ?? 'pendiente';
                        $src   = strtolower(($g['descripcion'] ?? '') . ' ' . ($g['tipo'] ?? '') . ' ' . ($g['estado'] ?? '') . ' ' . ($g['cat_nombre'] ?? '') . ' ' . ($g['proveedor'] ?? '') . ' ' . (($g['tipo'] === 'viaticos' && !empty($g['viatico_destino'])) ? $g['viatico_destino'] : ''));
                    ?>
                        <tr data-search="<?= htmlspecialchars($src) ?>" data-tipo="<?= htmlspecialchars($g['tipo'] ?? '') ?>"
                            data-estado="<?= htmlspecialchars($est) ?>">
                            <td data-col="fecha" style="white-space:nowrap;font-size:.83rem;color:#64748b;font-weight:600;">
                                <?= date('d/m/Y', strtotime($g['fecha'])) ?>
                            </td>
                            <td>
                                <div class="fw-semibold" data-col="desc"
                                    style="<?= $est === 'anulado' ? 'text-decoration:line-through;opacity:.5' : '' ?>">
                                    <?= htmlspecialchars(mb_substr($g['descripcion'] ?? '', 0, 60)) ?><?= mb_strlen($g['descripcion'] ?? '') > 60 ? '…' : '' ?>
                                </div>
                                <?php if (!empty($g['proveedor'])): ?>
                                    <small class="text-muted"><i
                                            class="bi bi-shop me-1"></i><?= htmlspecialchars($g['proveedor']) ?></small>
                                <?php endif; ?>
                                <?php if (($g['tipo'] ?? '') === 'viaticos' && !empty($g['viatico_destino'])): ?>
                                    <div style="font-size:.75rem;color:#0369a1;">
                                        <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($g['viatico_destino']) ?>
                                        <?php if (!empty($g['viatico_colaborador'])): ?>
                                            — <?= htmlspecialchars($g['viatico_colaborador']) ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><span class="tipo-badge <?= $tCls ?>"><?= $tLbl ?></span></td>
                            <td data-col="cat" style="font-size:.83rem;">
                                <?php if (!empty($g['cat_nombre'])): ?>
                                    <span class="badge rounded-pill px-2" style="background:<?= $g['cat_color'] ?>18;color:<?= $g['cat_color'] ?>;
                                                 border:1px solid <?= $g['cat_color'] ?>40;font-size:.73rem;">
                                        <i
                                            class="fa-solid <?= $g['cat_icono'] ?> me-1"></i><?= htmlspecialchars($g['cat_nombre']) ?>
                                    </span>
                                <?php else: ?>
                                    <span style="opacity:.4;font-size:.8rem;">—</span>
                                <?php endif; ?>
                            </td>
                            <td
                                style="font-weight:700;white-space:nowrap;<?= $est === 'anulado' ? 'text-decoration:line-through;opacity:.5' : '' ?>">
                                L <?= number_format((float)($g['monto'] ?? 0), 2) ?>
                            </td>
                            <td>
                                <?php if ($est === 'pagado'): ?>
                                    <span class="estado-pg"><i class="bi bi-check-circle-fill"></i>Pagado</span>
                                <?php elseif ($est === 'pendiente'): ?>
                                    <span class="estado-pd"><i class="bi bi-hourglass-split"></i>Pendiente</span>
                                <?php else: ?>
                                    <span class="estado-an"><i class="bi bi-x-circle-fill"></i>Anulado</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="gs-actions">
                                    <button class="btn-a btn-edit btn-editar-gasto"
                                        data-gasto='<?= json_encode($g, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>'
                                        title="Editar">
                                        <i class="bi bi-pencil-fill"></i>
                                    </button>
                                    <?php if ($est === 'pendiente'): ?>
                                        <button class="btn-a btn-pay btn-pagar-gasto" data-id="<?= $g['id'] ?>"
                                            title="Marcar pagado">
                                            <i class="bi bi-check-lg"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($est !== 'anulado'): ?>
                                        <button class="btn-a btn-anu btn-anular-gasto" data-id="<?= $g['id'] ?>"
                                            data-desc="<?= htmlspecialchars(mb_substr($g['descripcion'] ?? '', 0, 40)) ?>"
                                            title="Anular">
                                            <i class="bi bi-slash-circle"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if (in_array(USUARIO_ROL, ['admin', 'superadmin'])): ?>
                                        <button class="btn-a btn-del btn-eliminar-gasto" data-id="<?= $g['id'] ?>"
                                            data-desc="<?= htmlspecialchars(mb_substr($g['descripcion'] ?? '', 0, 40)) ?>"
                                            title="Eliminar">
                                            <i class="bi bi-trash3-fill"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php if ($total > 0): ?>
                    <tfoot class="table-light">
                        <tr>
                            <td colspan="4" class="text-end fw-bold small pe-3">TOTAL:</td>
                            <td class="fw-bold" style="color:#d97706;">L <?= number_format((float)$kpi['total_mes'], 2) ?>
                            </td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
            <div class="gs-empty" id="gsEmpty" style="display:none;">
                <div style="font-size:2.5rem;margin-bottom:.7rem;opacity:.3;"><i class="bi bi-receipt"></i></div>
                <div style="font-weight:600;">Sin resultados</div>
                <div id="gsEmptySub" style="font-size:.85rem;margin-top:.3rem;"></div>
            </div>
        </div>
        <div class="gs-pagination">
            <span class="gs-page-info" id="gsPageInfo"></span>
            <div class="gs-page-btns" id="gsPageBtns"></div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     MODAL: Nuevo / Editar Gasto (con Viáticos)
══════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalGasto" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header py-3" style="background:linear-gradient(135deg,#d97706,#92400e)">
                <h5 class="modal-title fw-bold text-white" id="modalGastoTitulo">
                    <i class="bi bi-plus-circle-fill me-2"></i>Nuevo Gasto
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form id="formGasto">
                    <input type="hidden" name="gasto_id" id="g_id">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="mf-label">Descripción <span style="color:#ef4444">*</span></label>
                            <input type="text" name="descripcion" id="g_desc" class="mf-input"
                                placeholder="Descripción del gasto" maxlength="300" required>
                        </div>
                        <div class="col-md-6">
                            <label class="mf-label">Tipo <span style="color:#ef4444">*</span></label>
                            <select name="tipo" id="g_tipo" class="mf-select" required>
                                <option value="variable">📊 Variable</option>
                                <option value="fijo">🔒 Fijo (recurrente)</option>
                                <option value="extraordinario">⭐ Extraordinario</option>
                                <option value="viaticos">✈️ Viáticos</option>
                            </select>
                        </div>
                        <div class="col-md-6" id="grpFrecuencia">
                            <label class="mf-label">Frecuencia</label>
                            <select name="frecuencia" id="g_frec" class="mf-select">
                                <option value="unico">📌 Único</option>
                                <option value="mensual">📅 Mensual</option>
                                <option value="quincenal">🔄 Quincenal</option>
                                <option value="anual">📆 Anual</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="mf-label">Monto (L) <span style="color:#ef4444">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">L</span>
                                <input type="number" name="monto" id="g_monto" class="mf-input" min="0.01" step="0.01"
                                    placeholder="0.00" required
                                    style="border-radius:0 var(--radius-sm) var(--radius-sm) 0">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="mf-label">Fecha <span style="color:#ef4444">*</span></label>
                            <input type="date" name="fecha" id="g_fecha" class="mf-input" required>
                        </div>
                        <div class="col-md-4">
                            <label class="mf-label">Estado</label>
                            <select name="estado" id="g_estado" class="mf-select">
                                <option value="pendiente">⏳ Pendiente</option>
                                <option value="pagado">✅ Pagado</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="mf-label">Categoría</label>
                            <select name="categoria_id" id="g_cat" class="mf-select">
                                <option value="">— Sin categoría —</option>
                                <?php foreach ($categorias as $cc): ?>
                                    <option value="<?= $cc['id'] ?>"><?= htmlspecialchars($cc['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="mf-label">Proveedor / Beneficiario</label>
                            <input type="text" name="proveedor" id="g_prov" class="mf-input"
                                placeholder="Nombre del proveedor" maxlength="200">
                        </div>
                        <div class="col-md-3" id="grpDia1" style="display:none">
                            <label class="mf-label">Día de pago</label>
                            <input type="number" name="dia_pago" id="g_dia1" class="mf-input" min="1" max="31">
                        </div>
                        <div class="col-md-3" id="grpDia2G" style="display:none">
                            <label class="mf-label">2° Día quincenal</label>
                            <input type="number" name="dia_pago_2" id="g_dia2" class="mf-input" min="1" max="31">
                        </div>
                        <div class="col-md-6" id="grpFechaVenc" style="display:none">
                            <label class="mf-label">Fecha vencimiento</label>
                            <input type="date" name="fecha_vencimiento" id="g_venc" class="mf-input">
                            <div class="form-text">Para recurrentes: hasta cuándo aplica.</div>
                        </div>

                        <!-- ── Panel Viáticos ──────────────────────────────── -->
                        <div class="col-12" id="panelViaticos" style="display:none">
                            <div class="rounded-3 p-3 border-0" style="background:#e0f2fe;border:1.5px solid #bae6fd">
                                <div class="fw-semibold small mb-2" style="color:#0369a1">
                                    <i class="bi bi-airplane me-1"></i> Datos del Viático
                                </div>
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label class="mf-label">Destino / Ciudad</label>
                                        <input type="text" name="viatico_destino" id="g_vdest" class="mf-input"
                                            placeholder="Ej: San Pedro Sula" maxlength="150">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="mf-label">Colaborador</label>
                                        <input type="text" name="viatico_colaborador" id="g_vcolab" class="mf-input"
                                            placeholder="Nombre del empleado" maxlength="150">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="mf-label">Motivo</label>
                                        <select name="viatico_motivo" id="g_vmotivo" class="mf-select">
                                            <option value="">— Seleccione —</option>
                                            <option value="reunion">Reunión de negocios</option>
                                            <option value="capacitacion">Capacitación</option>
                                            <option value="visita_cliente">Visita a cliente</option>
                                            <option value="entrega">Entrega / Instalación</option>
                                            <option value="otro">Otro</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="mf-label">Fecha salida</label>
                                        <input type="date" name="viatico_fecha_salida" id="g_vsalida" class="mf-input">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="mf-label">Fecha regreso</label>
                                        <input type="date" name="viatico_fecha_regreso" id="g_vregreso"
                                            class="mf-input">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="mf-label">Notas</label>
                            <textarea name="notas" id="g_notas" class="mf-input" rows="2"
                                style="height:auto;resize:vertical;" maxlength="500"></textarea>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-top">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" id="btnGuardarGasto" style="display:inline-flex;align-items:center;gap:.4rem;padding:.58rem 1.3rem;
                               background:#d97706;color:#fff;border:none;border-radius:var(--radius-sm);
                               font-size:.88rem;font-weight:600;cursor:pointer;">
                    <i class="bi bi-floppy-fill me-1"></i> Guardar Gasto
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    document.getElementById('sVista').addEventListener('change', function() {
        document.getElementById('grpMesFiltro').style.display = this.value === 'mensual' ? '' : 'none';
    });

    /* ── Table engine ──────────────────────────────────────────────────────────── */
    (() => {
        let query = '',
            filtroTipo = '',
            filtroEstado = '',
            page = 1,
            perPage = 10,
            sortCol = -1,
            sortDir = 'asc';
        const allRows = Array.from(document.querySelectorAll('#gsBody tr'));
        const $s = document.getElementById('gsSearch'),
            $cl = document.getElementById('gsClear');
        const $pp = document.getElementById('gsPerPage');
        const $ft = document.getElementById('gsFiltroTipo'),
            $fe = document.getElementById('gsFiltroEstado');
        const $empty = document.getElementById('gsEmpty'),
            $sub = document.getElementById('gsEmptySub');
        const $info = document.getElementById('gsPageInfo'),
            $btns = document.getElementById('gsPageBtns');
        const $badge = document.getElementById('gsBadge'),
            $sf = document.getElementById('statFiltered');
        const headers = document.querySelectorAll('#gsTable thead th[data-col]');
        const hl = (t, q) => !q ? t : t.replace(new RegExp(`(${q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')})`, `gi`),
            '<mark class="gs-highlight">$1</mark>');
        const colTxt = (r, i) => {
            const td = r.querySelectorAll('td')[i];
            return td ? (td.dataset.original || td.textContent).trim().toLowerCase() : '';
        };
        const filtered = () => allRows.filter(r => {
            const s = !query || r.dataset.search.includes(query.toLowerCase());
            const t = !filtroTipo || r.dataset.tipo === filtroTipo;
            const e = !filtroEstado || r.dataset.estado === filtroEstado;
            return s && t && e;
        }).sort((a, b) => {
            if (sortCol < 0) return 0;
            const va = colTxt(a, sortCol),
                vb = colTxt(b, sortCol);
            return sortDir === 'asc' ? va.localeCompare(vb, 'es') : vb.localeCompare(va, 'es');
        });
        const updIcons = () => headers.forEach(th => {
            const i = parseInt(th.dataset.col);
            th.classList.remove('sort-asc', 'sort-desc');
            if (i === sortCol) th.classList.add(sortDir === 'asc' ? 'sort-asc' : 'sort-desc');
        });
        const render = () => {
            const rows = filtered(),
                total = rows.length,
                totPg = Math.max(1, Math.ceil(total / perPage));
            if (page > totPg) page = totPg;
            const s = (page - 1) * perPage,
                e = Math.min(s + perPage, total);
            allRows.forEach(r => r.style.display = 'none');
            if (!total) {
                $empty.style.display = 'block';
                $sub.textContent = query ? `Sin resultados para "${query}".` : 'Sin gastos.';
            } else {
                $empty.style.display = 'none';
                rows.slice(s, e).forEach(r => {
                    r.style.display = '';
                    r.querySelectorAll('[data-col]').forEach(c => {
                        const o = c.dataset.original ?? c.textContent;
                        c.dataset.original = o;
                        c.innerHTML = hl(o, query);
                    });
                });
            }
            $badge.textContent = `${total} gasto${total!==1?'s':''}`;
            if ($sf) $sf.textContent = total;
            $info.textContent = total === 0 ? 'Sin resultados' : `Mostrando ${s+1}–${e} de ${total}`;
            buildPg(page, totPg);
        };
        const buildPg = (cur, tot) => {
            $btns.innerHTML = '';
            if (tot <= 1) return;
            const mk = (html, p, cls = '') => {
                const b = document.createElement('button');
                b.className = `page-btn ${cls}`;
                b.innerHTML = html;
                if (!cls.includes('disabled') && !cls.includes('active')) b.addEventListener('click', () => {
                    page = p;
                    render();
                });
                $btns.appendChild(b);
            };
            mk('<i class="bi bi-chevron-double-left"></i>', 1, cur === 1 ? 'disabled' : '');
            mk('<i class="bi bi-chevron-left"></i>', cur - 1, cur === 1 ? 'disabled' : '');
            let pages = new Set([1, tot]);
            for (let i = Math.max(2, cur - 2); i <= Math.min(tot - 1, cur + 2); i++) pages.add(i);
            pages = [...pages].sort((a, b) => a - b);
            let prev = 0;
            pages.forEach(pg => {
                if (pg - prev > 1) {
                    const d = document.createElement('button');
                    d.className = 'page-btn disabled';
                    d.textContent = '…';
                    $btns.appendChild(d);
                }
                mk(pg, pg, pg === cur ? 'active' : '');
                prev = pg;
            });
            mk('<i class="bi bi-chevron-right"></i>', cur + 1, cur === tot ? 'disabled' : '');
            mk('<i class="bi bi-chevron-double-right"></i>', tot, cur === tot ? 'disabled' : '');
        };
        headers.forEach(th => th.addEventListener('click', () => {
            const i = parseInt(th.dataset.col);
            sortDir = (sortCol === i && sortDir === 'asc') ? 'desc' : 'asc';
            sortCol = i;
            page = 1;
            updIcons();
            render();
        }));
        let deb;
        $s.addEventListener('input', () => {
            clearTimeout(deb);
            deb = setTimeout(() => {
                query = $s.value.trim();
                page = 1;
                $cl.classList.toggle('visible', query.length > 0);
                render();
            }, 180);
        });
        $cl.addEventListener('click', () => {
            $s.value = '';
            query = '';
            page = 1;
            $cl.classList.remove('visible');
            render();
            $s.focus();
        });
        $pp.addEventListener('change', () => {
            perPage = parseInt($pp.value);
            page = 1;
            render();
        });
        $ft.addEventListener('change', () => {
            filtroTipo = $ft.value;
            page = 1;
            render();
        });
        $fe.addEventListener('change', () => {
            filtroEstado = $fe.value;
            page = 1;
            render();
        });
        updIcons();
        render();
    })();

    /* ── Modal lógica ────────────────────────────────────────────────────────── */
    function toggleCamposGasto() {
        const t = document.getElementById('g_tipo').value;
        const f = document.getElementById('g_frec').value;
        document.getElementById('panelViaticos').style.display = (t === 'viaticos') ? '' : 'none';
        document.getElementById('grpFrecuencia').style.display = (t !== 'viaticos') ? '' : 'none';
        const fijo = t === 'fijo';
        document.getElementById('grpDia1').style.display = fijo ? '' : 'none';
        const quin = fijo && f === 'quincenal';
        document.getElementById('grpDia2G').style.display = quin ? '' : 'none';
        document.getElementById('grpFechaVenc').style.display = fijo ? '' : 'none';
    }
    document.getElementById('g_tipo').addEventListener('change', toggleCamposGasto);
    document.getElementById('g_frec').addEventListener('change', toggleCamposGasto);

    function limpiarModalGasto() {
        document.getElementById('formGasto').reset();
        document.getElementById('g_id').value = '';
        document.getElementById('g_fecha').value = new Date().toISOString().slice(0, 10);
        toggleCamposGasto();
    }

    document.getElementById('btnNuevoGasto').addEventListener('click', () => {
        document.getElementById('modalGastoTitulo').innerHTML =
            '<i class="bi bi-plus-circle-fill me-2"></i>Nuevo Gasto';
        limpiarModalGasto();
        new bootstrap.Modal(document.getElementById('modalGasto')).show();
    });

    document.querySelectorAll('.btn-editar-gasto').forEach(btn => {
        btn.addEventListener('click', () => {
            const g = JSON.parse(btn.dataset.gasto);
            document.getElementById('modalGastoTitulo').innerHTML =
                '<i class="bi bi-pencil-square me-2"></i>Editar Gasto';
            limpiarModalGasto();
            document.getElementById('g_id').value = g.id;
            document.getElementById('g_desc').value = g.descripcion || '';
            document.getElementById('g_tipo').value = g.tipo || 'variable';
            document.getElementById('g_tipo').dispatchEvent(new Event('change'));
            document.getElementById('g_frec').value = g.frecuencia || 'unico';
            document.getElementById('g_frec').dispatchEvent(new Event('change'));
            document.getElementById('g_monto').value = g.monto || '';
            document.getElementById('g_fecha').value = g.fecha || '';
            document.getElementById('g_estado').value = g.estado || 'pendiente';
            document.getElementById('g_cat').value = g.categoria_id || '';
            document.getElementById('g_prov').value = g.proveedor || '';
            document.getElementById('g_notas').value = g.notas || '';
            if (g.dia_pago) document.getElementById('g_dia1').value = g.dia_pago;
            if (g.dia_pago_2) document.getElementById('g_dia2').value = g.dia_pago_2;
            if (g.fecha_vencimiento) document.getElementById('g_venc').value = g.fecha_vencimiento;
            if (g.tipo === 'viaticos') {
                document.getElementById('g_vdest').value = g.viatico_destino || '';
                document.getElementById('g_vcolab').value = g.viatico_colaborador || '';
                document.getElementById('g_vmotivo').value = g.viatico_motivo || '';
                document.getElementById('g_vsalida').value = g.viatico_fecha_salida || '';
                document.getElementById('g_vregreso').value = g.viatico_fecha_regreso || '';
            }
            new bootstrap.Modal(document.getElementById('modalGasto')).show();
        });
    });

    /* ── Guardar ─────────────────────────────────────────────────────────────── */
    document.getElementById('btnGuardarGasto').addEventListener('click', () => {
        const isEdit = !!document.getElementById('g_id').value;
        const btn = document.getElementById('btnGuardarGasto');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Guardando…';
        const url = isEdit ? 'includes/gasto_actualizar.php' : 'includes/gasto_guardar.php';
        fetch(url, {
                method: 'POST',
                body: new FormData(document.getElementById('formGasto'))
            })
            .then(r => r.json()).then(d => {
                if (d.success) {
                    Swal.fire({
                            icon: 'success',
                            title: '¡Guardado!',
                            timer: 1500,
                            showConfirmButton: false
                        })
                        .then(() => location.reload());
                } else {
                    Swal.fire('Error', d.error || 'No se pudo guardar.', 'error');
                }
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-floppy-fill me-1"></i> Guardar Gasto';
            }).catch(() => {
                Swal.fire('Error', 'Error inesperado.', 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-floppy-fill me-1"></i> Guardar Gasto';
            });
    });

    /* ── Acciones tabla ──────────────────────────────────────────────────────── */
    document.querySelectorAll('.btn-pagar-gasto').forEach(btn => {
        btn.addEventListener('click', () => {
            Swal.fire({
                    title: '¿Marcar como pagado?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#059669',
                    confirmButtonText: 'Sí, pagado',
                    cancelButtonText: 'No'
                })
                .then(r => {
                    if (!r.isConfirmed) return;
                    const fd = new FormData();
                    fd.append('gasto_id', btn.dataset.id);
                    fd.append('_solo_estado', 1);
                    fd.append('estado', 'pagado');
                    fetch('includes/gasto_actualizar.php', {
                            method: 'POST',
                            body: fd
                        })
                        .then(r => r.json()).then(d => {
                            if (d.success) location.reload();
                            else Swal.fire('Error', d.error, 'error');
                        });
                });
        });
    });

    document.querySelectorAll('.btn-anular-gasto').forEach(btn => {
        btn.addEventListener('click', () => {
            Swal.fire({
                    title: '¿Anular este gasto?',
                    html: `<strong>${btn.dataset.desc}</strong>`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc2626',
                    confirmButtonText: 'Sí, anular',
                    cancelButtonText: 'No',
                    reverseButtons: true
                })
                .then(r => {
                    if (!r.isConfirmed) return;
                    const fd = new FormData();
                    fd.append('id', btn.dataset.id);
                    fd.append('accion', 'anular');
                    fetch('includes/gasto_eliminar.php', {
                            method: 'POST',
                            body: fd
                        })
                        .then(r => r.json()).then(d => {
                            if (d.success) location.reload();
                            else Swal.fire('Error', d.error, 'error');
                        });
                });
        });
    });

    document.querySelectorAll('.btn-eliminar-gasto').forEach(btn => {
        btn.addEventListener('click', () => {
            Swal.fire({
                    title: '¿Eliminar definitivamente?',
                    html: `<strong>${btn.dataset.desc}</strong>`,
                    icon: 'error',
                    showCancelButton: true,
                    confirmButtonColor: '#dc2626',
                    confirmButtonText: 'Sí, eliminar',
                    cancelButtonText: 'No',
                    reverseButtons: true
                })
                .then(r => {
                    if (!r.isConfirmed) return;
                    const fd = new FormData();
                    fd.append('id', btn.dataset.id);
                    fd.append('accion', 'eliminar');
                    fetch('includes/gasto_eliminar.php', {
                            method: 'POST',
                            body: fd
                        })
                        .then(r => r.json()).then(d => {
                            if (d.success) location.reload();
                            else Swal.fire('Error', d.error, 'error');
                        });
                });
        });
    });
</script>

<?php require_once '../../includes/templates/footer.php'; ?>