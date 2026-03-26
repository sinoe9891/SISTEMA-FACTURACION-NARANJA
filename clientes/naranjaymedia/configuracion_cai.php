<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

$usuario_id             = $_SESSION['usuario_id'];
$establecimiento_activo = $_SESSION['establecimiento_activo'] ?? null;
$es_superadmin          = (USUARIO_ROL === 'superadmin');

$stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->execute([$usuario_id]);
$usuario    = $stmt->fetch();
$rol_usuario = $usuario['rol'];
$cliente_id  = $usuario['cliente_id'] ?? null;

if ($rol_usuario !== 'superadmin' && $cliente_id) {
    $stmt = $pdo->prepare("SELECT nombre, logo_url FROM clientes_saas WHERE id = ?");
    $stmt->execute([$cliente_id]);
    $cliente = $stmt->fetch();
    $cliente_nombre = $cliente['nombre'];
    $logo_url       = $cliente['logo_url'] ?? '';
} else {
    $cliente_nombre = 'Todos los clientes';
    $logo_url       = '';
}

if (!in_array($rol_usuario, ['admin', 'superadmin'])) {
    echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
    <script>Swal.fire('Acceso denegado','Solo administradores pueden acceder.','error').then(()=>window.location.href='./dashboard');</script>";
    exit;
}

$nombre_establecimiento = 'No asignado';
if ($establecimiento_activo) {
    $stmt = $pdo->prepare("SELECT nombre FROM establecimientos WHERE establecimiento_id = ?");
    $stmt->execute([$establecimiento_activo]);
    $nombre_establecimiento = $stmt->fetchColumn() ?: 'No asignado';
}

if ($rol_usuario === 'superadmin') {
    $stmt = $pdo->query("SELECT cr.*, e.nombre AS establecimiento_nombre, c.nombre AS cliente_nombre
                         FROM cai_rangos cr
                         INNER JOIN establecimientos e ON cr.establecimiento_id = e.establecimiento_id
                         INNER JOIN clientes_saas c ON e.cliente_id = c.id
                         ORDER BY cr.id DESC");
} else {
    $stmt = $pdo->prepare("SELECT cr.*, e.nombre AS establecimiento_nombre
                           FROM cai_rangos cr
                           INNER JOIN establecimientos e ON cr.establecimiento_id = e.establecimiento_id
                           WHERE cr.cliente_id = ? AND cr.establecimiento_id = ?
                           ORDER BY cr.id DESC");
    $stmt->execute([$cliente_id, $establecimiento_activo]);
}
$cais = $stmt->fetchAll();

$total_cais    = count($cais);
$activos       = count(array_filter($cais, fn($c) => strtotime($c['fecha_limite']) >= time()));
$vencidos      = $total_cais - $activos;
$hoy           = date('Y-m-d');

require_once '../../includes/templates/header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>
    :root {
        --brand: #4f46e5;
        --brand-light: #eef2ff;
        --brand-dark: #3730a3;
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

    .cc-page {
        padding: 1.5rem 0 3rem;
    }

    /* Header */
    .cc-header {
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

    .cc-header::before {
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

    .cc-header::after {
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

    .cc-header-title {
        font-size: 1.35rem;
        font-weight: 700;
        margin: 0;
    }

    .cc-header-sub {
        font-size: .82rem;
        opacity: .8;
        margin: .25rem 0 0;
    }

    .cc-header-logo {
        max-height: 52px;
        border-radius: 8px;
        background: rgba(255, 255, 255, .15);
        padding: 4px;
    }

    /* Stats */
    .cc-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(155px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .cc-stat {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 1.1rem 1.25rem;
        display: flex;
        align-items: center;
        gap: .9rem;
        box-shadow: var(--shadow-sm);
        transition: box-shadow var(--tr), transform var(--tr);
    }

    .cc-stat:hover {
        box-shadow: var(--shadow-md);
        transform: translateY(-2px);
    }

    .cc-stat-icon {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        flex-shrink: 0;
    }

    .cc-stat-icon.purple {
        background: #ede9fe;
        color: #7c3aed;
    }

    .cc-stat-icon.green {
        background: var(--success-bg);
        color: var(--success);
    }

    .cc-stat-icon.red {
        background: var(--danger-bg);
        color: var(--danger);
    }

    .cc-stat-val {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--text-main);
        line-height: 1;
    }

    .cc-stat-lbl {
        font-size: .74rem;
        color: var(--text-muted);
        margin-top: 2px;
    }

    /* Toolbar */
    .cc-toolbar {
        display: flex;
        align-items: center;
        gap: .75rem;
        flex-wrap: wrap;
        margin-bottom: 1.25rem;
    }

    .cc-search-wrap {
        position: relative;
        flex: 1 1 220px;
        min-width: 200px;
    }

    .cc-search-wrap>i {
        position: absolute;
        left: .85rem;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-muted);
        font-size: .95rem;
        pointer-events: none;
    }

    .cc-search {
        width: 100%;
        padding: .55rem .85rem .55rem 2.4rem;
        border: 1.5px solid var(--border);
        border-radius: var(--radius-sm);
        font-size: .9rem;
        background: var(--surface);
        color: var(--text-main);
        transition: border-color var(--tr), box-shadow var(--tr);
        outline: none;
    }

    .cc-search:focus {
        border-color: #7c3aed;
        box-shadow: 0 0 0 3px rgba(124, 58, 237, .12);
    }

    .cc-search::placeholder {
        color: #94a3b8;
    }

    .cc-clear-btn {
        position: absolute;
        right: .65rem;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        color: var(--text-muted);
        font-size: 1rem;
        cursor: pointer;
        padding: 0;
        display: none;
    }

    .cc-clear-btn.visible {
        display: block;
    }

    .btn-new-cai {
        display: inline-flex;
        align-items: center;
        gap: .45rem;
        padding: .55rem 1.1rem;
        background: #7c3aed;
        color: #fff !important;
        border-radius: var(--radius-sm);
        font-size: .88rem;
        font-weight: 600;
        text-decoration: none;
        border: none;
        cursor: pointer;
        white-space: nowrap;
        box-shadow: 0 2px 8px rgba(124, 58, 237, .25);
        transition: background var(--tr), transform var(--tr);
    }

    .btn-new-cai:hover {
        background: #5b21b6;
        transform: translateY(-1px);
    }

    .cc-per-page {
        padding: .5rem .7rem;
        border: 1.5px solid var(--border);
        border-radius: var(--radius-sm);
        font-size: .85rem;
        background: var(--surface);
        color: var(--text-main);
        cursor: pointer;
        outline: none;
    }

    /* Card / Table */
    .cc-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow-sm);
        overflow: hidden;
    }

    .cc-card-header {
        padding: 1rem 1.5rem;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: var(--surface-2);
        gap: 1rem;
        flex-wrap: wrap;
    }

    .cc-card-title {
        font-weight: 700;
        font-size: .95rem;
        color: var(--text-main);
        display: flex;
        align-items: center;
        gap: .5rem;
    }

    .cc-result-badge {
        display: inline-flex;
        align-items: center;
        background: #ede9fe;
        color: #7c3aed;
        border-radius: 20px;
        padding: .15rem .65rem;
        font-size: .78rem;
        font-weight: 600;
    }

    .cc-table-wrap {
        overflow-x: auto;
    }

    .cc-table {
        width: 100%;
        border-collapse: collapse;
        font-size: .855rem;
    }

    .cc-table thead th {
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

    .cc-table thead th:last-child {
        cursor: default;
    }

    .cc-table thead th:hover:not(:last-child) {
        background: #ede9fe;
        color: #7c3aed;
    }

    .cc-table thead th.sort-asc,
    .cc-table thead th.sort-desc {
        color: #7c3aed;
        background: #ede9fe;
    }

    .sort-icon {
        margin-left: .35rem;
        font-size: .68rem;
        opacity: .3;
        display: inline-block;
        transition: opacity .15s, transform .15s;
    }

    .cc-table thead th:hover:not(:last-child) .sort-icon {
        opacity: .7;
    }

    .cc-table thead th.sort-asc .sort-icon,
    .cc-table thead th.sort-desc .sort-icon {
        opacity: 1;
    }

    .cc-table thead th.sort-desc .sort-icon {
        transform: rotate(180deg);
    }

    .cc-table tbody tr {
        border-bottom: 1px solid var(--border);
        transition: background var(--tr);
    }

    .cc-table tbody tr:last-child {
        border-bottom: none;
    }

    .cc-table tbody tr:hover {
        background: #f5f3ff;
    }

    .cc-table tbody td {
        padding: .82rem 1rem;
        color: var(--text-main);
        vertical-align: middle;
    }

    .cai-code {
        font-family: 'Courier New', monospace;
        font-size: .78rem;
        font-weight: 600;
        background: #f1f5f9;
        border: 1px solid var(--border);
        border-radius: 6px;
        padding: .15rem .5rem;
        color: #334155;
        letter-spacing: .02em;
    }

    .cc-highlight {
        background: #fef08a;
        border-radius: 3px;
        padding: 0 2px;
    }

    .status-pill {
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        padding: .2rem .6rem;
        border-radius: 20px;
        font-size: .73rem;
        font-weight: 600;
    }

    .status-activo {
        background: var(--success-bg);
        color: var(--success);
    }

    .status-vencido {
        background: var(--danger-bg);
        color: var(--danger);
    }

    /* Progress bar */
    .mini-progress {
        height: 5px;
        background: #e2e8f0;
        border-radius: 3px;
        overflow: hidden;
        margin-top: 4px;
    }

    .mini-progress-bar {
        height: 100%;
        border-radius: 3px;
    }

    /* Actions */
    .cc-actions {
        display: flex;
        gap: .35rem;
        align-items: center;
    }

    .btn-act {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: .3rem;
        padding: .34rem .7rem;
        border-radius: var(--radius-sm);
        font-size: .78rem;
        font-weight: 600;
        cursor: pointer;
        border: 1.5px solid transparent;
        transition: all var(--tr);
        text-decoration: none;
        white-space: nowrap;
    }

    .btn-act-edit {
        background: #ede9fe;
        color: #7c3aed;
        border-color: rgba(124, 58, 237, .2);
    }

    .btn-act-edit:hover {
        background: #7c3aed;
        color: #fff;
        transform: translateY(-1px);
    }

    .btn-act-del {
        background: var(--danger-bg);
        color: var(--danger);
        border-color: rgba(239, 68, 68, .2);
    }

    .btn-act-del:hover {
        background: var(--danger);
        color: #fff;
        transform: translateY(-1px);
    }

    /* Pagination */
    .cc-pagination {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: .85rem 1.25rem;
        border-top: 1px solid var(--border);
        background: var(--surface-2);
        gap: .75rem;
        flex-wrap: wrap;
    }

    .cc-page-info {
        font-size: .78rem;
        color: var(--text-muted);
    }

    .cc-page-btns {
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
        border-color: #7c3aed;
        color: #7c3aed;
        background: #ede9fe;
    }

    .page-btn.active {
        background: #7c3aed;
        border-color: #7c3aed;
        color: #fff;
        box-shadow: 0 2px 8px rgba(124, 58, 237, .3);
    }

    .page-btn.disabled {
        opacity: .35;
        cursor: not-allowed;
        pointer-events: none;
    }

    /* Empty */
    .cc-empty {
        text-align: center;
        padding: 3rem 1rem;
        color: var(--text-muted);
    }

    .cc-empty-icon {
        font-size: 2.8rem;
        margin-bottom: .7rem;
        opacity: .3;
    }

    @media(max-width:640px) {
        .cc-header {
            padding: 1.1rem 1.25rem;
        }

        .cc-table thead th:nth-child(3),
        .cc-table tbody td:nth-child(3),
        .cc-table thead th:nth-child(6),
        .cc-table tbody td:nth-child(6) {
            display: none;
        }
    }
</style>

<div class="cc-page container">

    <!-- Header -->
    <div class="cc-header">
        <div>
            <h4 class="cc-header-title">🔐 Configuración de Rangos CAI</h4>
            <p class="cc-header-sub">
                Sucursal: <?= htmlspecialchars($nombre_establecimiento) ?> &nbsp;·&nbsp;
                Rol: <?= htmlspecialchars(ucfirst($rol_usuario)) ?> &nbsp;·&nbsp;
                <?= htmlspecialchars($cliente_nombre) ?>
            </p>
        </div>
        <?php if ($logo_url): ?>
            <img src="<?= htmlspecialchars($logo_url) ?>" alt="Logo" class="cc-header-logo">
        <?php endif; ?>
    </div>

    <!-- Stats -->
    <div class="cc-stats">
        <div class="cc-stat">
            <div class="cc-stat-icon purple"><i class="bi bi-key-fill"></i></div>
            <div>
                <div class="cc-stat-val" id="stat-total"><?= $total_cais ?></div>
                <div class="cc-stat-lbl">Total CAI</div>
            </div>
        </div>
        <div class="cc-stat">
            <div class="cc-stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
            <div>
                <div class="cc-stat-val"><?= $activos ?></div>
                <div class="cc-stat-lbl">Activos</div>
            </div>
        </div>
        <div class="cc-stat">
            <div class="cc-stat-icon red"><i class="bi bi-x-circle-fill"></i></div>
            <div>
                <div class="cc-stat-val"><?= $vencidos ?></div>
                <div class="cc-stat-lbl">Vencidos</div>
            </div>
        </div>
        <div class="cc-stat">
            <div class="cc-stat-icon purple"><i class="bi bi-funnel-fill"></i></div>
            <div>
                <div class="cc-stat-val" id="stat-filtered"><?= $total_cais ?></div>
                <div class="cc-stat-lbl">Resultados</div>
            </div>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="cc-toolbar">
        <a href="crear_cai" class="btn-new-cai"><i class="bi bi-plus-lg"></i> Nuevo Rango CAI</a>
        <div class="cc-search-wrap">
            <i class="bi bi-search"></i>
            <input type="text" id="ccSearch" class="cc-search" placeholder="Buscar por CAI, establecimiento, rango…"
                autocomplete="off">
            <button class="cc-clear-btn" id="ccClear"><i class="bi bi-x-lg"></i></button>
        </div>
        <select class="cc-per-page" id="ccPerPage">
            <option value="10" selected>10/pág</option>
            <option value="25">25/pág</option>
            <option value="50">50/pág</option>
        </select>
    </div>

    <!-- Table card -->
    <div class="cc-card">
        <div class="cc-card-header">
            <span class="cc-card-title"><i class="bi bi-table"></i> Rangos CAI registrados</span>
            <span class="cc-result-badge" id="ccBadge"><?= $total_cais ?> registros</span>
        </div>

        <div class="cc-table-wrap">
            <table class="cc-table" id="ccTable">
                <thead>
                    <tr>
                        <th data-col="0"><i class="bi bi-key me-1"></i>CAI<i class="bi bi-arrow-up sort-icon"></i></th>
                        <th data-col="1"><i class="bi bi-building me-1"></i>Establecimiento<i
                                class="bi bi-arrow-up sort-icon"></i></th>
                        <?php if ($rol_usuario === 'superadmin'): ?>
                            <th data-col="2"><i class="bi bi-person-badge me-1"></i>Cliente<i
                                    class="bi bi-arrow-up sort-icon"></i></th>
                        <?php endif; ?>
                        <th data-col="3">Inicio<i class="bi bi-arrow-up sort-icon"></i></th>
                        <th data-col="4">Fin<i class="bi bi-arrow-up sort-icon"></i></th>
                        <th data-col="5">Correlativo<i class="bi bi-arrow-up sort-icon"></i></th>
                        <th data-col="6">Uso<i class="bi bi-arrow-up sort-icon"></i></th>
                        <th data-col="7"><i class="bi bi-calendar3 me-1"></i>Vencimiento<i
                                class="bi bi-arrow-up sort-icon"></i></th>
                        <th data-col="8">Estado<i class="bi bi-arrow-up sort-icon"></i></th>
                        <th><i class="bi bi-gear me-1"></i>Acciones</th>
                    </tr>
                </thead>
                <tbody id="ccBody">
                    <?php foreach ($cais as $c):
                        $activo   = strtotime($c['fecha_limite']) >= time();
                        $total_r  = max(1, (int)$c['rango_fin'] - (int)$c['rango_inicio'] + 1);
                        $usados   = (int)$c['correlativo_actual'];
                        $pct      = min(100, round($usados / $total_r * 100));
                        $barColor = $pct >= 90 ? '#ef4444' : ($pct >= 70 ? '#f59e0b' : '#10b981');
                        $searchStr = strtolower($c['cai'] . ' ' . $c['establecimiento_nombre'] . ($rol_usuario === 'superadmin' ? ' ' . $c['cliente_nombre'] : '') . ' ' . $c['rango_inicio'] . ' ' . $c['rango_fin'] . ' ' . ($activo ? 'activo' : 'vencido'));
                    ?>
                        <tr data-search="<?= htmlspecialchars($searchStr) ?>">
                            <td><span class="cai-code" data-col="cai"><?= htmlspecialchars($c['cai']) ?></span></td>
                            <td data-col="estab"><?= htmlspecialchars($c['establecimiento_nombre']) ?></td>
                            <?php if ($rol_usuario === 'superadmin'): ?>
                                <td data-col="cliente"><?= htmlspecialchars($c['cliente_nombre']) ?></td>
                            <?php endif; ?>
                            <td data-col="inicio"><?= htmlspecialchars($c['rango_inicio']) ?></td>
                            <td data-col="fin"><?= htmlspecialchars($c['rango_fin']) ?></td>
                            <td data-col="corr"><strong><?= htmlspecialchars($c['correlativo_actual']) ?></strong></td>
                            <td>
                                <div style="font-size:.78rem;font-weight:600;"><?= $pct ?>%</div>
                                <div class="mini-progress">
                                    <div class="mini-progress-bar" style="width:<?= $pct ?>%;background:<?= $barColor ?>;">
                                    </div>
                                </div>
                            </td>
                            <td data-col="vence"><?= htmlspecialchars($c['fecha_limite']) ?></td>
                            <td>
                                <span class="status-pill <?= $activo ? 'status-activo' : 'status-vencido' ?>">
                                    <?= $activo ? '<i class="bi bi-check-circle-fill"></i> Activo' : '<i class="bi bi-x-circle-fill"></i> Vencido' ?>
                                </span>
                            </td>
                            <td>
                                <div class="cc-actions">
                                    <a href="editar_cai?id=<?= $c['id'] ?>" class="btn-act btn-act-edit">
                                        <i class="bi bi-pencil-fill"></i>
                                        <span class="d-none d-md-inline">Editar</span>
                                    </a>
                                    <form method="POST" action="eliminar_cai" style="display:inline;"
                                        onsubmit="return ccConfirmDel(event,this);">
                                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                        <button type="submit" class="btn-act btn-act-del">
                                            <i class="bi bi-trash3-fill"></i>
                                            <span class="d-none d-md-inline">Eliminar</span>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="cc-empty" id="ccEmpty" style="display:none;">
                <div class="cc-empty-icon"><i class="bi bi-key"></i></div>
                <div style="font-weight:600;">Sin resultados</div>
                <div id="ccEmptySub" style="font-size:.85rem;margin-top:.3rem;"></div>
            </div>
        </div>

        <div class="cc-pagination">
            <span class="cc-page-info" id="ccPageInfo"></span>
            <div class="cc-page-btns" id="ccPageBtns"></div>
        </div>
    </div>
</div>

<script>
    (() => {
        let query = '',
            page = 1,
            perPage = 10,
            sortCol = -1,
            sortDir = 'asc';
        const allRows = Array.from(document.querySelectorAll('#ccBody tr'));
        const $s = document.getElementById('ccSearch'),
            $cl = document.getElementById('ccClear'),
            $pp = document.getElementById('ccPerPage');
        const $empty = document.getElementById('ccEmpty'),
            $sub = document.getElementById('ccEmptySub');
        const $info = document.getElementById('ccPageInfo'),
            $btns = document.getElementById('ccPageBtns');
        const $badge = document.getElementById('ccBadge'),
            $statF = document.getElementById('stat-filtered');
        const headers = document.querySelectorAll('#ccTable thead th[data-col]');

        function hl(t, q) {
            if (!q) return t;
            return t.replace(new RegExp(`(${q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')})`, `gi`),
                '<mark class="cc-highlight">$1</mark>');
        }

        function colTxt(r, i) {
            const td = r.querySelectorAll('td')[i];
            return td ? (td.dataset.original || td.textContent).trim().toLowerCase() : '';
        }

        function filtered() {
            const base = !query ? allRows : allRows.filter(r => r.dataset.search.includes(query.toLowerCase()));
            if (sortCol < 0) return base;
            return [...base].sort((a, b) => {
                const va = colTxt(a, sortCol),
                    vb = colTxt(b, sortCol);
                return sortDir === 'asc' ? va.localeCompare(vb, 'es') : vb.localeCompare(va, 'es');
            });
        }

        function updIcons() {
            headers.forEach(th => {
                const i = parseInt(th.dataset.col);
                th.classList.remove('sort-asc', 'sort-desc');
                if (i === sortCol) th.classList.add(sortDir === 'asc' ? 'sort-asc' : 'sort-desc');
            });
        }

        function render() {
            const rows = filtered(),
                total = rows.length,
                totPg = Math.max(1, Math.ceil(total / perPage));
            if (page > totPg) page = totPg;
            const s = (page - 1) * perPage,
                e = Math.min(s + perPage, total);
            allRows.forEach(r => r.style.display = 'none');
            if (total === 0) {
                $empty.style.display = 'block';
                $sub.textContent = query ? `Sin resultados para "${query}".` : 'No hay rangos CAI registrados.';
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
            $badge.textContent = `${total} registro${total!==1?'s':''}`;
            if ($statF) $statF.textContent = total;
            $info.textContent = total === 0 ? 'Sin resultados' : `Mostrando ${s+1}–${e} de ${total}`;
            buildPg(page, totPg);
        }

        function buildPg(cur, tot) {
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
        }

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

    window.ccConfirmDel = (e, form) => {
        e.preventDefault();
        Swal.fire({
                title: '¿Eliminar CAI?',
                text: 'Esta acción eliminará el rango CAI permanentemente.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                confirmButtonText: '<i class="bi bi-trash3-fill me-1"></i> Sí, eliminar',
                cancelButtonText: 'Cancelar',
                reverseButtons: true
            })
            .then(r => {
                if (r.isConfirmed) form.submit();
            });
        return false;
    };
</script>

<?php require_once '../../includes/templates/footer.php'; ?>