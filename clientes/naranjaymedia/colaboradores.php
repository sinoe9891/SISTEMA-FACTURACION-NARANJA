<?php
$titulo = 'Colaboradores';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';
require_once '../../includes/templates/header.php';

$cliente_id = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);

// Compatibilidad USUARIO_ID
if (!defined('USUARIO_ID')) define('USUARIO_ID', $_SESSION['usuario_id'] ?? 0);

define('IHSS_EMP', 0.035);
define('IHSS_PAT', 0.07);
define('RAP_EMP',  0.015);
define('RAP_PAT',  0.015);
define('IHSS_TOPE', 10294.10);

function calcNeto(float $s, int $ihss, int $rap, string $tipo): array
{
    $base  = min($s, IHSS_TOPE);
    $ie    = $ihss ? round($base * IHSS_EMP, 2) : 0;
    $re    = $rap  ? round($s    * RAP_EMP,  2) : 0;
    $nm    = $s - $ie - $re;
    $ip    = $ihss ? round($base * IHSS_PAT, 2) : 0;
    $rp    = $rap  ? round($s    * RAP_PAT,  2) : 0;
    $div   = $tipo === 'quincenal' ? 2 : 1;
    return [
        'bruto_pago'  => round($s / $div, 2),
        'ihss_emp'    => round($ie / $div, 2),
        'rap_emp'     => round($re / $div, 2),
        'neto_pago'   => round($nm / $div, 2),
        'ihss_pat'    => round($ip / $div, 2),
        'rap_pat'     => round($rp / $div, 2),
        'costo_total' => round(($nm + $ip + $rp) / $div, 2),
    ];
}

$filtro_estado = $_GET['estado'] ?? 'activo';

// ── KPIs ─────────────────────────────────────────────────────────────────────
$stmtKpi = $pdo->prepare("SELECT
    COUNT(*) total,
    SUM(activo=1) activos, SUM(activo=0) inactivos,
    SUM(CASE WHEN activo=1 THEN salario_base ELSE 0 END) masa_salarial,
    SUM(CASE WHEN activo=1 AND tipo_pago='quincenal' THEN 1 ELSE 0 END) quincenales,
    SUM(CASE WHEN activo=1 AND tipo_pago='mensual'   THEN 1 ELSE 0 END) mensuales
FROM colaboradores WHERE cliente_id=?");
$stmtKpi->execute([$cliente_id]);
$kpi = $stmtKpi->fetch(PDO::FETCH_ASSOC);

// Costo patronal mensual
$stmtPat = $pdo->prepare("SELECT salario_base,aplica_ihss,aplica_rap FROM colaboradores WHERE cliente_id=? AND activo=1");
$stmtPat->execute([$cliente_id]);
$costo_patronal = 0;
foreach ($stmtPat->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $b = min((float)$r['salario_base'], IHSS_TOPE);
    if ($r['aplica_ihss']) $costo_patronal += round($b * IHSS_PAT, 2);
    if ($r['aplica_rap'])  $costo_patronal += round((float)$r['salario_base'] * RAP_PAT, 2);
}

// ── Lista ─────────────────────────────────────────────────────────────────────
$where = ($filtro_estado === 'inactivo') ? 'c.activo=0' : 'c.activo=1';
$stmtC = $pdo->prepare("SELECT c.*, cg.nombre AS cat_nombre, cg.color AS cat_color, cg.icono AS cat_icono
    FROM colaboradores c LEFT JOIN categorias_gastos cg ON cg.id=c.categoria_gasto_id
    WHERE c.cliente_id=? AND $where ORDER BY c.nombre ASC, c.apellido ASC");
$stmtC->execute([$cliente_id]);
$colaboradores = $stmtC->fetchAll(PDO::FETCH_ASSOC);

// Pagos del mes actual para el badge
$stmtPagos = $pdo->prepare("SELECT descripcion, quincena_num FROM gastos
    WHERE cliente_id=? AND descripcion LIKE 'Sueldo %' AND estado!='anulado'
      AND YEAR(fecha)=YEAR(CURDATE()) AND MONTH(fecha)=MONTH(CURDATE())");
$stmtPagos->execute([$cliente_id]);
$pagos_mes = $stmtPagos->fetchAll(PDO::FETCH_ASSOC);

function estadoPago(array $pagos, string $nombre, int $q = 0): ?string
{
    $base = 'Sueldo ' . $nombre;
    foreach ($pagos as $p) {
        if ($q === 0 && strpos($p['descripcion'], $base) === 0) return 'pagado';
        if ($q === 1 && $p['descripcion'] === $base . ' — 1ª Quincena') return 'pagado';
        if ($q === 2 && $p['descripcion'] === $base . ' — 2ª Quincena') return 'pagado';
    }
    return null;
}

// Categorías
$stmtCats = $pdo->prepare("SELECT id,nombre,color,icono FROM categorias_gastos WHERE cliente_id=? AND activa=1 ORDER BY nombre");
$stmtCats->execute([$cliente_id]);
$categorias = $stmtCats->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    :root {
        --teal: #0f766e;
        --teal-dark: #065f46;
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

    .cl-page {
        padding: 1.5rem 0 3rem;
    }

    /* Header */
    .cl-header {
        background: linear-gradient(135deg, #0f766e 0%, #065f46 100%);
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

    .cl-header::before,
    .cl-header::after {
        content: '';
        position: absolute;
        border-radius: 50%;
        pointer-events: none;
    }

    .cl-header::before {
        top: -40px;
        right: -40px;
        width: 180px;
        height: 180px;
        background: rgba(255, 255, 255, .08);
    }

    .cl-header::after {
        bottom: -60px;
        left: 30%;
        width: 260px;
        height: 140px;
        background: rgba(255, 255, 255, .05);
    }

    /* Stats */
    .cl-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .cl-stat {
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

    .cl-stat:hover {
        box-shadow: var(--shadow-md);
        transform: translateY(-2px);
    }

    .cl-stat-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        flex-shrink: 0;
    }

    .si-teal {
        background: #ccfbf1;
        color: #0f766e;
    }

    .si-green {
        background: #d1fae5;
        color: #059669;
    }

    .si-gray {
        background: #f1f5f9;
        color: #64748b;
    }

    .si-amber {
        background: #fef3c7;
        color: #d97706;
    }

    .si-blue {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .cl-stat-val {
        font-size: 1.35rem;
        font-weight: 700;
        color: var(--text-main);
        line-height: 1;
    }

    .cl-stat-lbl {
        font-size: .72rem;
        color: var(--text-muted);
        margin-top: 2px;
    }

    /* Toolbar */
    .cl-toolbar {
        display: flex;
        align-items: center;
        gap: .75rem;
        flex-wrap: wrap;
        margin-bottom: 1.25rem;
    }

    .cl-search-wrap {
        position: relative;
        flex: 1 1 220px;
        min-width: 190px;
    }

    .cl-search-wrap>i {
        position: absolute;
        left: .85rem;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-muted);
        font-size: .9rem;
        pointer-events: none;
    }

    .cl-search {
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

    .cl-search:focus {
        border-color: var(--teal);
        box-shadow: 0 0 0 3px rgba(15, 118, 110, .12);
    }

    .cl-search::placeholder {
        color: #94a3b8;
    }

    .cl-clear-btn {
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

    .cl-clear-btn.visible {
        display: block;
    }

    .btn-nuevo-colab {
        display: inline-flex;
        align-items: center;
        gap: .45rem;
        padding: .55rem 1.1rem;
        background: var(--teal);
        color: #fff !important;
        border-radius: var(--radius-sm);
        font-size: .88rem;
        font-weight: 600;
        border: none;
        cursor: pointer;
        white-space: nowrap;
        box-shadow: 0 2px 8px rgba(15, 118, 110, .3);
        transition: background var(--tr), transform var(--tr);
        text-decoration: none;
    }

    .btn-nuevo-colab:hover {
        background: var(--teal-dark);
        transform: translateY(-1px);
    }

    .cl-per-page {
        padding: .5rem .7rem;
        border: 1.5px solid var(--border);
        border-radius: var(--radius-sm);
        font-size: .85rem;
        background: var(--surface);
        cursor: pointer;
        outline: none;
    }

    /* Tabla */
    .cl-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow-sm);
        overflow: hidden;
    }

    .cl-card-header {
        padding: 1rem 1.5rem;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: var(--surface-2);
        gap: 1rem;
        flex-wrap: wrap;
    }

    .cl-badge-teal {
        display: inline-flex;
        align-items: center;
        background: #ccfbf1;
        color: #0f766e;
        border-radius: 20px;
        padding: .15rem .65rem;
        font-size: .78rem;
        font-weight: 600;
    }

    .cl-table-wrap {
        overflow-x: auto;
    }

    .cl-table {
        width: 100%;
        border-collapse: collapse;
        font-size: .855rem;
    }

    .cl-table thead th {
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

    .cl-table thead th:last-child {
        cursor: default;
    }

    .cl-table thead th:hover:not(:last-child) {
        background: #ccfbf1;
        color: #0f766e;
    }

    .cl-table thead th.sort-asc,
    .cl-table thead th.sort-desc {
        color: #0f766e;
        background: #ccfbf1;
    }

    .sort-icon {
        margin-left: .35rem;
        font-size: .68rem;
        opacity: .3;
        transition: opacity .15s, transform .15s;
    }

    .cl-table thead th:hover:not(:last-child) .sort-icon {
        opacity: .7;
    }

    .cl-table thead th.sort-asc .sort-icon,
    .cl-table thead th.sort-desc .sort-icon {
        opacity: 1;
    }

    .cl-table thead th.sort-desc .sort-icon {
        transform: rotate(180deg);
    }

    .cl-table tbody tr {
        border-bottom: 1px solid var(--border);
        transition: background var(--tr);
    }

    .cl-table tbody tr:last-child {
        border-bottom: none;
    }

    .cl-table tbody tr:hover {
        background: #f0fdfa;
    }

    .cl-table tbody td {
        padding: .82rem 1rem;
        color: var(--text-main);
        vertical-align: middle;
    }

    .cl-highlight {
        background: #fef08a;
        border-radius: 3px;
        padding: 0 2px;
    }

    .cl-avatar {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: .9rem;
        font-weight: 700;
        color: #fff;
        flex-shrink: 0;
        text-transform: uppercase;
    }

    /* Acciones */
    .cl-actions {
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

    .btn-a-pay {
        background: #d1fae5;
        color: #059669;
        border-color: rgba(5, 150, 105, .2);
    }

    .btn-a-pay:hover {
        background: #059669;
        color: #fff;
        transform: translateY(-1px);
    }

    .btn-a-ver {
        background: #ccfbf1;
        color: #0f766e;
        border-color: rgba(15, 118, 110, .2);
    }

    .btn-a-ver:hover {
        background: #0f766e;
        color: #fff;
        transform: translateY(-1px);
    }

    .btn-a-edit {
        background: #ede9fe;
        color: #7c3aed;
        border-color: rgba(124, 58, 237, .2);
    }

    .btn-a-edit:hover {
        background: #7c3aed;
        color: #fff;
        transform: translateY(-1px);
    }

    .btn-a-tog {
        background: #d1fae5;
        color: #059669;
        border-color: rgba(5, 150, 105, .2);
    }

    .btn-a-tog:hover {
        background: #059669;
        color: #fff;
    }

    .btn-a-tog.off {
        background: #fee2e2;
        color: #dc2626;
        border-color: rgba(220, 38, 38, .2);
    }

    .btn-a-tog.off:hover {
        background: #dc2626;
        color: #fff;
    }

    .badge-status-on {
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

    .badge-status-off {
        background: #fee2e2;
        color: #dc2626;
        border: 1px solid #fecaca;
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        padding: .18rem .55rem;
        border-radius: 20px;
        font-size: .73rem;
        font-weight: 600;
    }

    /* Paginación */
    .cl-pagination {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: .85rem 1.25rem;
        border-top: 1px solid var(--border);
        background: var(--surface-2);
        gap: .75rem;
        flex-wrap: wrap;
    }

    .cl-page-info {
        font-size: .78rem;
        color: var(--text-muted);
    }

    .cl-page-btns {
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
        border-color: #0f766e;
        color: #0f766e;
        background: #f0fdfa;
    }

    .page-btn.active {
        background: #0f766e;
        border-color: #0f766e;
        color: #fff;
        box-shadow: 0 2px 8px rgba(15, 118, 110, .3);
    }

    .page-btn.disabled {
        opacity: .35;
        cursor: not-allowed;
        pointer-events: none;
    }

    .cl-empty {
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
        border-color: #0f766e;
        box-shadow: 0 0 0 3px rgba(15, 118, 110, .1);
    }

    .mb-mf {
        margin-bottom: .9rem;
    }
</style>

<div class="cl-page container-xxl">

    <!-- Header -->
    <div class="cl-header">
        <div>
            <h4 style="font-size:1.35rem;font-weight:700;margin:0">👥 Gestión de Colaboradores</h4>
            <p style="font-size:.82rem;opacity:.8;margin:.25rem 0 0">
                Nómina, pagos, préstamos y viáticos del equipo
            </p>
        </div>
        <div style="font-size:3rem;opacity:.2;font-weight:900;line-height:1">👥</div>
    </div>

    <!-- Stats -->
    <div class="cl-stats">
        <div class="cl-stat">
            <div class="cl-stat-icon si-teal"><i class="bi bi-people-fill"></i></div>
            <div>
                <div class="cl-stat-val"><?= (int)$kpi['total'] ?></div>
                <div class="cl-stat-lbl">Total</div>
            </div>
        </div>
        <div class="cl-stat">
            <div class="cl-stat-icon si-green"><i class="bi bi-person-check-fill"></i></div>
            <div>
                <div class="cl-stat-val"><?= (int)$kpi['activos'] ?></div>
                <div class="cl-stat-lbl">Activos</div>
            </div>
        </div>
        <div class="cl-stat">
            <div class="cl-stat-icon si-gray"><i class="bi bi-person-dash-fill"></i></div>
            <div>
                <div class="cl-stat-val"><?= (int)$kpi['inactivos'] ?></div>
                <div class="cl-stat-lbl">Inactivos</div>
            </div>
        </div>
        <div class="cl-stat">
            <div class="cl-stat-icon si-amber"><i class="bi bi-cash-coin"></i></div>
            <div>
                <div class="cl-stat-val" style="font-size:1rem;">L <?= number_format((float)$kpi['masa_salarial'], 0) ?>
                </div>
                <div class="cl-stat-lbl">Nómina/mes</div>
            </div>
        </div>
        <div class="cl-stat">
            <div class="cl-stat-icon si-blue"><i class="bi bi-funnel-fill"></i></div>
            <div>
                <div class="cl-stat-val" id="statFiltered"><?= count($colaboradores) ?></div>
                <div class="cl-stat-lbl">Resultados</div>
            </div>
        </div>
    </div>

    <!-- Tabs activo/inactivo + toolbar -->
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
        <div class="d-flex gap-2">
            <a href="?estado=activo"
                class="btn btn-sm <?= $filtro_estado !== 'inactivo' ? 'btn-success' : 'btn-outline-success' ?>">
                <i class="bi bi-person-check me-1"></i>Activos
                <span class="badge bg-white text-success ms-1"><?= (int)$kpi['activos'] ?></span>
            </a>
            <a href="?estado=inactivo"
                class="btn btn-sm <?= $filtro_estado === 'inactivo' ? 'btn-secondary' : 'btn-outline-secondary' ?>">
                <i class="bi bi-person-slash me-1"></i>Inactivos
                <span class="badge bg-white text-secondary ms-1"><?= (int)$kpi['inactivos'] ?></span>
            </a>
        </div>
    </div>

    <div class="cl-toolbar">
        <button class="btn-nuevo-colab" id="btnNuevoColab">
            <i class="bi bi-person-plus-fill"></i> Nuevo Colaborador
        </button>
        <div class="cl-search-wrap">
            <i class="bi bi-search"></i>
            <input type="text" id="clSearch" class="cl-search" placeholder="Buscar nombre, puesto, departamento…"
                autocomplete="off">
            <button class="cl-clear-btn" id="clClear"><i class="bi bi-x-lg"></i></button>
        </div>
        <select class="cl-per-page" id="clPerPage">
            <option value="10" selected>10/pág</option>
            <option value="25">25/pág</option>
            <option value="50">50/pág</option>
        </select>
    </div>

    <!-- Tabla -->
    <div class="cl-card">
        <div class="cl-card-header">
            <span style="font-weight:700;font-size:.95rem;display:flex;align-items:center;gap:.5rem;">
                <i class="bi bi-table"></i>
                <?= $filtro_estado === 'inactivo' ? 'Colaboradores Inactivos' : 'Colaboradores Activos' ?>
            </span>
            <span class="cl-badge-teal" id="clBadge"><?= count($colaboradores) ?> colaboradores</span>
        </div>
        <div class="cl-table-wrap">
            <table class="cl-table" id="clTable">
                <thead>
                    <tr>
                        <th data-col="0"><i class="bi bi-person me-1"></i>Nombre<i class="bi bi-arrow-up sort-icon"></i>
                        </th>
                        <th data-col="1"><i class="bi bi-briefcase me-1"></i>Puesto<i
                                class="bi bi-arrow-up sort-icon"></i></th>
                        <th data-col="2"><i class="bi bi-building me-1"></i>Depto.<i
                                class="bi bi-arrow-up sort-icon"></i></th>
                        <th data-col="3"><i class="bi bi-cash me-1"></i>Salario<i class="bi bi-arrow-up sort-icon"></i>
                        </th>
                        <th data-col="4"><i class="bi bi-calendar3 me-1"></i>Ingreso<i
                                class="bi bi-arrow-up sort-icon"></i></th>
                        <th class="text-center">Pago <?= date('M Y') ?></th>
                        <th style="cursor:default;"><i class="bi bi-gear me-1"></i>Acciones</th>
                    </tr>
                </thead>
                <tbody id="clBody">
                    <?php
                    $colors = ['#0f766e', '#7c3aed', '#1d4ed8', '#d97706', '#dc2626', '#059669'];
                    $ci = 0;
                    foreach ($colaboradores as $c):
                        $nc  = $c['nombre'] . ' ' . $c['apellido'];
                        $n   = calcNeto((float)$c['salario_base'], (int)$c['aplica_ihss'], (int)$c['aplica_rap'], $c['tipo_pago']);
                        $src = strtolower($nc . ' ' . $c['puesto'] . ' ' . ($c['departamento'] ?? ''));
                        $ac  = $colors[$ci % count($colors)];
                        $ci++;
                    ?>
                        <tr data-search="<?= htmlspecialchars($src) ?>">
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="cl-avatar" style="background:<?= $ac ?>">
                                        <?= mb_strtoupper(mb_substr($c['nombre'], 0, 1) . mb_substr($c['apellido'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="fw-semibold" data-col="nombre">
                                            <?= htmlspecialchars($nc) ?>
                                        </div>
                                        <?php if ($c['telefono']): ?>
                                            <small class="text-muted"><?= htmlspecialchars($c['telefono']) ?></small>
                                        <?php endif; ?>
                                        <?php if ($c['activo']): ?>
                                            <span class="badge-status-on ms-1"><i class="bi bi-circle-fill"
                                                    style="font-size:6px"></i>Activo</span>
                                        <?php else: ?>
                                            <span class="badge-status-off ms-1"><i class="bi bi-circle-fill"
                                                    style="font-size:6px"></i>Inactivo</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td data-col="puesto">
                                <div class="fw-semibold small"><?= htmlspecialchars($c['puesto']) ?></div>
                                <?php if ($c['cat_nombre']): ?>
                                    <span class="badge rounded-pill px-2 mt-1"
                                        style="background:<?= $c['cat_color'] ?>18;color:<?= $c['cat_color'] ?>;border:1px solid <?= $c['cat_color'] ?>40;font-size:10px">
                                        <i
                                            class="fa-solid <?= $c['cat_icono'] ?> me-1"></i><?= htmlspecialchars($c['cat_nombre']) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td data-col="depto" style="color:#64748b;font-size:.83rem;">
                                <?= $c['departamento'] ? htmlspecialchars($c['departamento']) : '—' ?>
                            </td>
                            <td>
                                <div class="fw-bold" style="color:#0f766e;">
                                    L <?= number_format((float)$c['salario_base'], 2) ?>
                                </div>
                                <small class="text-muted">
                                    <?= $c['tipo_pago'] === 'quincenal' ? '🔄 Neto quincenal: L ' . number_format($n['neto_pago'], 0) : '📅 Neto mensual: L ' . number_format($n['neto_pago'], 0) ?>
                                </small>
                            </td>
                            <td data-col="ingreso" style="font-size:.82rem;color:#64748b;">
                                <?= date('d/m/Y', strtotime($c['fecha_ingreso'])) ?>
                            </td>
                            <td class="text-center" style="min-width:110px">
                                <?php
                                if ($c['tipo_pago'] === 'quincenal') {
                                    foreach ([1 => '1ª', 2 => '2ª'] as $q => $lbl) {
                                        $e = estadoPago($pagos_mes, $nc, $q);
                                        $cls = $e === 'pagado' ? 'bg-success' : 'bg-light text-danger border border-danger';
                                        echo "<span class='badge {$cls} d-block mb-1'>{$lbl} " . ($e === 'pagado' ? '✓' : '—') . "</span>";
                                    }
                                } else {
                                    $e = estadoPago($pagos_mes, $nc, 0);
                                    $cls = $e === 'pagado' ? 'bg-success' : 'bg-light text-danger border border-danger';
                                    echo "<span class='badge {$cls}'>" . ($e === 'pagado' ? '✓ Pagado' : '— Sin pagar') . "</span>";
                                }
                                ?>
                            </td>
                            <td>
                                <div class="cl-actions">
                                    <?php if ($c['activo']): ?>
                                        <button class="btn-a btn-a-pay btn-pagar-directo-tabla" title="Registrar pago"
                                            data-colab-id="<?= $c['id'] ?>"
                                            onclick="window.location.href='colaborador_ver?id=<?= $c['id'] ?>'">
                                            <i class="bi bi-hand-index-thumb-fill"></i>
                                        </button>
                                    <?php endif; ?>
                                    <a href="colaborador_ver?id=<?= $c['id'] ?>" class="btn-a btn-a-ver" title="Ver perfil">
                                        <i class="bi bi-eye-fill"></i>
                                        <span class="d-none d-lg-inline">Ver</span>
                                    </a>
                                    <button class="btn-a btn-a-edit btn-editar-colab"
                                        data-col='<?= json_encode($c, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>'
                                        title="Editar">
                                        <i class="bi bi-pencil-fill"></i>
                                    </button>
                                    <button class="btn-a <?= $c['activo'] ? 'btn-a-tog' : 'btn-a-tog off' ?> btn-toggle"
                                        data-id="<?= $c['id'] ?>" data-activo="<?= $c['activo'] ?>"
                                        data-nombre="<?= htmlspecialchars($nc) ?>"
                                        title="<?= $c['activo'] ? 'Dar de baja' : 'Reactivar' ?>">
                                        <i class="bi <?= $c['activo'] ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="cl-empty" id="clEmpty" style="display:none;">
                <div style="font-size:2.5rem;margin-bottom:.7rem;opacity:.3;"><i class="bi bi-people"></i></div>
                <div style="font-weight:600;">Sin resultados</div>
                <div id="clEmptySub" style="font-size:.85rem;margin-top:.3rem;"></div>
            </div>
        </div>
        <div class="cl-pagination">
            <span class="cl-page-info" id="clPageInfo"></span>
            <div class="cl-page-btns" id="clPageBtns"></div>
        </div>
    </div>
</div>

<!-- Modal Crear/Editar Colaborador -->
<div class="modal fade" id="modalColab" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header py-3" style="background:linear-gradient(135deg,#0f766e,#065f46)">
                <h5 class="modal-title fw-bold text-white" id="modalColabTitulo">
                    <i class="bi bi-person-plus-fill me-2"></i>Nuevo Colaborador
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form id="formColab">
                    <input type="hidden" name="colaborador_id" id="colab_id">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="mf-label">Nombre <span style="color:#ef4444">*</span></label>
                            <input type="text" name="nombre" id="c_nombre" class="mf-input" maxlength="100" required>
                        </div>
                        <div class="col-md-5">
                            <label class="mf-label">Apellido <span style="color:#ef4444">*</span></label>
                            <input type="text" name="apellido" id="c_apellido" class="mf-input" maxlength="100"
                                required>
                        </div>
                        <div class="col-md-2">
                            <label class="mf-label">DPI</label>
                            <input type="text" name="dpi" id="c_dpi" class="mf-input" maxlength="20">
                        </div>
                        <div class="col-md-4">
                            <label class="mf-label">Teléfono</label>
                            <input type="text" name="telefono" id="c_tel" class="mf-input" maxlength="20">
                        </div>
                        <div class="col-md-5">
                            <label class="mf-label">Email</label>
                            <input type="email" name="email" id="c_email" class="mf-input" maxlength="150">
                        </div>
                        <div class="col-md-3">
                            <label class="mf-label">Fecha Ingreso <span style="color:#ef4444">*</span></label>
                            <input type="date" name="fecha_ingreso" id="c_ingreso" class="mf-input" required>
                        </div>
                        <div class="col-md-5">
                            <label class="mf-label">Puesto <span style="color:#ef4444">*</span></label>
                            <input type="text" name="puesto" id="c_puesto" class="mf-input" maxlength="150" required>
                        </div>
                        <div class="col-md-4">
                            <label class="mf-label">Departamento</label>
                            <input type="text" name="departamento" id="c_depto" class="mf-input" maxlength="100">
                        </div>
                        <div class="col-md-3">
                            <label class="mf-label">Categoría Gasto</label>
                            <select name="categoria_gasto_id" id="c_cat" class="mf-select">
                                <option value="">— Sin categoría —</option>
                                <?php foreach ($categorias as $cat): ?>
                                    <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="mf-label">Salario Bruto <span style="color:#ef4444">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">L</span>
                                <input type="number" name="salario_base" id="c_salario" class="mf-input" min="1"
                                    step="0.01" required style="border-radius:0 var(--radius-sm) var(--radius-sm) 0">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="mf-label">Tipo Pago</label>
                            <select name="tipo_pago" id="c_tipo" class="mf-select">
                                <option value="quincenal">🔄 Quincenal</option>
                                <option value="mensual">📅 Mensual</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="mf-label">1er Día</label>
                            <input type="number" name="dia_pago" id="c_dia1" class="mf-input" min="1" max="31"
                                value="15">
                        </div>
                        <div class="col-md-2" id="grpDia2">
                            <label class="mf-label">2° Día</label>
                            <input type="number" name="dia_pago_2" id="c_dia2" class="mf-input" min="1" max="31"
                                value="30">
                        </div>
                        <div class="col-12">
                            <div class="d-flex gap-4 flex-wrap">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="aplica_ihss" id="c_ihss"
                                        value="1" checked>
                                    <label class="form-check-label" for="c_ihss">
                                        <span class="badge bg-warning text-dark">IHSS</span>
                                        <small class="text-muted ms-1">3.5% emp / 7% pat</small>
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="aplica_rap" id="c_rap"
                                        value="1" checked>
                                    <label class="form-check-label" for="c_rap">
                                        <span class="badge bg-info text-dark">RAP</span>
                                        <small class="text-muted ms-1">1.5% emp / 1.5% pat</small>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="mf-label">Notas</label>
                            <textarea name="notas" id="c_notas" class="mf-input" rows="2" maxlength="500"
                                style="height:auto;resize:vertical;"></textarea>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-top">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" id="btnGuardarColab" style="display:inline-flex;align-items:center;gap:.4rem;padding:.58rem 1.3rem;
                               background:#0f766e;color:#fff;border:none;border-radius:var(--radius-sm);
                               font-size:.88rem;font-weight:600;cursor:pointer;">
                    <i class="bi bi-floppy-fill me-1"></i> Guardar
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    /* ── Table engine ──────────────────────────────────────────────────────────── */
    (() => {
        let query = '',
            page = 1,
            perPage = 10,
            sortCol = -1,
            sortDir = 'asc';
        const allRows = Array.from(document.querySelectorAll('#clBody tr'));
        const $s = document.getElementById('clSearch'),
            $cl = document.getElementById('clClear');
        const $pp = document.getElementById('clPerPage');
        const $empty = document.getElementById('clEmpty'),
            $sub = document.getElementById('clEmptySub');
        const $info = document.getElementById('clPageInfo'),
            $btns = document.getElementById('clPageBtns');
        const $badge = document.getElementById('clBadge'),
            $sf = document.getElementById('statFiltered');
        const headers = document.querySelectorAll('#clTable thead th[data-col]');
        const hl = (t, q) => !q ? t : t.replace(new RegExp(`(${q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')})`, `gi`),
            '<mark class="cl-highlight">$1</mark>');
        const colTxt = (r, i) => {
            const td = r.querySelectorAll('td')[i];
            return td ? (td.dataset.original || td.textContent).trim().toLowerCase() : '';
        };
        const filtered = () => {
            const base = !query ? allRows : allRows.filter(r => r.dataset.search.includes(query.toLowerCase()));
            if (sortCol < 0) return base;
            return [...base].sort((a, b) => {
                const va = colTxt(a, sortCol),
                    vb = colTxt(b, sortCol);
                return sortDir === 'asc' ? va.localeCompare(vb, 'es') : vb.localeCompare(va, 'es');
            });
        };
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
                $sub.textContent = query ? `Sin resultados para "${query}".` : 'No hay colaboradores.';
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
            $badge.textContent = `${total} colaborador${total!==1?'es':''}`;
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
        updIcons();
        render();
    })();

    /* ── Modal colaborador ─────────────────────────────────────────────────────── */
    document.getElementById('c_tipo').addEventListener('change', function() {
        document.getElementById('grpDia2').style.display = this.value === 'quincenal' ? '' : 'none';
    });
    document.getElementById('btnNuevoColab').addEventListener('click', () => {
        document.getElementById('modalColabTitulo').innerHTML =
            '<i class="bi bi-person-plus-fill me-2"></i>Nuevo Colaborador';
        document.getElementById('formColab').reset();
        document.getElementById('colab_id').value = '';
        document.getElementById('grpDia2').style.display = '';
        document.getElementById('c_ingreso').value = new Date().toISOString().slice(0, 10);
        ['c_ihss', 'c_rap'].forEach(id => document.getElementById(id).checked = true);
        new bootstrap.Modal(document.getElementById('modalColab')).show();
    });
    document.querySelectorAll('.btn-editar-colab').forEach(btn => {
        btn.addEventListener('click', () => {
            const c = JSON.parse(btn.dataset.col);
            document.getElementById('modalColabTitulo').innerHTML =
                '<i class="bi bi-pencil-square me-2"></i>Editar Colaborador';
            ['colab_id', 'c_nombre', 'c_apellido', 'c_dpi', 'c_tel', 'c_email', 'c_ingreso', 'c_puesto',
                'c_depto', 'c_salario', 'c_notas'
            ]
            .forEach((id, k) => {
                const fields = ['id', 'nombre', 'apellido', 'dpi', 'telefono', 'email',
                    'fecha_ingreso', 'puesto', 'departamento', 'salario_base', 'notas'
                ];
                document.getElementById(id).value = c[fields[k]] || '';
            });
            document.getElementById('c_tipo').value = c.tipo_pago;
            document.getElementById('c_dia1').value = c.dia_pago || '';
            document.getElementById('c_dia2').value = c.dia_pago_2 || '';
            document.getElementById('c_cat').value = c.categoria_gasto_id || '';
            document.getElementById('c_ihss').checked = !!parseInt(c.aplica_ihss);
            document.getElementById('c_rap').checked = !!parseInt(c.aplica_rap);
            document.getElementById('grpDia2').style.display = c.tipo_pago === 'quincenal' ? '' : 'none';
            new bootstrap.Modal(document.getElementById('modalColab')).show();
        });
    });
    document.getElementById('btnGuardarColab').addEventListener('click', () => {
        const isEdit = !!document.getElementById('colab_id').value;
        const btn = document.getElementById('btnGuardarColab');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Guardando…';
        const url = isEdit ? 'includes/colaborador_actualizar.php' : 'includes/colaborador_guardar.php';
        fetch(url, {
                method: 'POST',
                body: new FormData(document.getElementById('formColab'))
            })
            .then(r => r.json()).then(d => {
                if (d.success) Swal.fire({
                    icon: 'success',
                    title: '¡Guardado!',
                    timer: 1500,
                    showConfirmButton: false
                }).then(() => location.reload());
                else Swal.fire('Error', d.error || 'No se pudo guardar.', 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-floppy-fill me-1"></i> Guardar';
            }).catch(() => {
                Swal.fire('Error', 'Error inesperado.', 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-floppy-fill me-1"></i> Guardar';
            });
    });

    /* ── Toggle activo/inactivo ──────────────────────────────────────────────── */
    document.querySelectorAll('.btn-toggle').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id,
                activo = parseInt(btn.dataset.activo),
                nombre = btn.dataset.nombre;
            Swal.fire({
                    title: `¿${activo?'Dar de baja':'Reactivar'} a ${nombre}?`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: activo ? '#f59e0b' : '#0f766e',
                    confirmButtonText: 'Sí',
                    cancelButtonText: 'No'
                })
                .then(r => {
                    if (!r.isConfirmed) return;
                    const fd = new FormData();
                    fd.append('colaborador_id', id);
                    fd.append('activo', activo ? 0 : 1);
                    fd.append('_cambiar_estado', 1);
                    fetch('includes/colaborador_actualizar.php', {
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