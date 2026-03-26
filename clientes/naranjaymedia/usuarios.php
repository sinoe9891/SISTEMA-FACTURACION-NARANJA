<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

$usuario_id  = $_SESSION['usuario_id'];
$rol_actual  = USUARIO_ROL;   // rol del usuario logueado
$es_superadmin = ($rol_actual === 'superadmin');
$es_admin      = ($rol_actual === 'admin');

// Solo admin y superadmin pueden ver esta página
if (!in_array($rol_actual, ['admin', 'superadmin'])) {
    require_once '../../includes/templates/header.php';
    echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
    <script>document.addEventListener('DOMContentLoaded',()=>Swal.fire('Acceso denegado','Solo administradores pueden ver esta sección.','error').then(()=>window.location.href='dashboard'));</script>";
    exit;
}

// Datos del usuario logueado + cliente
$stmt = $pdo->prepare("SELECT u.*, c.nombre AS cliente_nombre, c.logo_url, c.id AS cliente_id_real
    FROM usuarios u LEFT JOIN clientes_saas c ON u.cliente_id = c.id WHERE u.id = ?");
$stmt->execute([$usuario_id]);
$mi_usuario = $stmt->fetch();
$mi_cliente_id = (int)($mi_usuario['cliente_id'] ?? 0);

// ── Listado de usuarios ───────────────────────────────────────────────────────
// superadmin ve TODOS · admin ve solo los de su cliente
if ($es_superadmin) {
    $stmtUsers = $pdo->query("
        SELECT u.*, c.nombre AS cliente_nombre
        FROM usuarios u LEFT JOIN clientes_saas c ON u.cliente_id = c.id
        ORDER BY u.cliente_id ASC, u.rol ASC, u.nombre ASC
    ");
} else {
    // Admin: solo usuarios de su mismo cliente_id
    $stmtUsers = $pdo->prepare("
        SELECT u.*, c.nombre AS cliente_nombre
        FROM usuarios u LEFT JOIN clientes_saas c ON u.cliente_id = c.id
        WHERE u.cliente_id = ?
        ORDER BY u.rol ASC, u.nombre ASC
    ");
    $stmtUsers->execute([$mi_cliente_id]);
}
$todos_usuarios = $stmtUsers->fetchAll();
$total_usuarios = count($todos_usuarios);

// ── Clientes para el select del modal ────────────────────────────────────────
if ($es_superadmin) {
    $stmtClientes = $pdo->query("SELECT id, nombre FROM clientes_saas ORDER BY nombre ASC");
} else {
    // Admin solo puede crear usuarios en su propio cliente
    $stmtClientes = $pdo->prepare("SELECT id, nombre FROM clientes_saas WHERE id = ?");
    $stmtClientes->execute([$mi_cliente_id]);
}
$clientes_lista = $stmtClientes->fetchAll();

// ── Establecimientos para asignación ─────────────────────────────────────────
if ($es_superadmin) {
    $stmtEstabs = $pdo->query("SELECT e.*, c.nombre AS cliente_nombre FROM establecimientos e
        INNER JOIN clientes_saas c ON e.cliente_id = c.id ORDER BY c.nombre, e.nombre");
} else {
    $stmtEstabs = $pdo->prepare("SELECT e.*, c.nombre AS cliente_nombre FROM establecimientos e
        INNER JOIN clientes_saas c ON e.cliente_id = c.id WHERE e.cliente_id = ? ORDER BY e.nombre");
    $stmtEstabs->execute([$mi_cliente_id]);
}
$establecimientos_todos = $stmtEstabs->fetchAll();

// ── KPIs ──────────────────────────────────────────────────────────────────────
$por_rol  = [];
foreach ($todos_usuarios as $u) $por_rol[$u['rol']] = ($por_rol[$u['rol']] ?? 0) + 1;
$activos  = count(array_filter($todos_usuarios, fn($u) => $u['estado'] === 'activo'));
$inactivos = $total_usuarios - $activos;

require_once '../../includes/templates/header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>
    :root {
        --brand: #dc2626;
        --brand-light: #fef2f2;
        --brand-dark: #b91c1c;
        --success: #10b981;
        --success-bg: #ecfdf5;
        --danger: #ef4444;
        --danger-bg: #fef2f2;
        --warning: #f59e0b;
        --warning-bg: #fffbeb;
        --purple: #7c3aed;
        --purple-bg: #ede9fe;
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

    .ug-page {
        padding: 1.5rem 0 3rem;
    }

    .ug-header {
        background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);
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

    .ug-header::before {
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

    .ug-header::after {
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

    .ug-header-title {
        font-size: 1.35rem;
        font-weight: 700;
        margin: 0;
    }

    .ug-header-sub {
        font-size: .82rem;
        opacity: .8;
        margin: .25rem 0 0;
    }

    .ug-header-logo {
        max-height: 52px;
        border-radius: 8px;
        background: rgba(255, 255, 255, .15);
        padding: 4px;
    }

    /* Stats */
    .ug-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .ug-stat {
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

    .ug-stat:hover {
        box-shadow: var(--shadow-md);
        transform: translateY(-2px);
    }

    .ug-stat-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        flex-shrink: 0;
    }

    .ug-stat-icon.red {
        background: #fee2e2;
        color: #dc2626;
    }

    .ug-stat-icon.green {
        background: var(--success-bg);
        color: var(--success);
    }

    .ug-stat-icon.gray {
        background: #f1f5f9;
        color: var(--text-muted);
    }

    .ug-stat-icon.purple {
        background: var(--purple-bg);
        color: var(--purple);
    }

    .ug-stat-icon.blue {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .ug-stat-val {
        font-size: 1.4rem;
        font-weight: 700;
        color: var(--text-main);
        line-height: 1;
    }

    .ug-stat-lbl {
        font-size: .72rem;
        color: var(--text-muted);
        margin-top: 2px;
    }

    /* Toolbar */
    .ug-toolbar {
        display: flex;
        align-items: center;
        gap: .75rem;
        flex-wrap: wrap;
        margin-bottom: 1.25rem;
    }

    .ug-search-wrap {
        position: relative;
        flex: 1 1 220px;
        min-width: 190px;
    }

    .ug-search-wrap>i {
        position: absolute;
        left: .85rem;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-muted);
        font-size: .9rem;
        pointer-events: none;
    }

    .ug-search {
        width: 100%;
        padding: .55rem .85rem .55rem 2.4rem;
        border: 1.5px solid var(--border);
        border-radius: var(--radius-sm);
        font-size: .9rem;
        background: var(--surface);
        color: var(--text-main);
        outline: none;
        transition: border-color var(--tr), box-shadow var(--tr);
    }

    .ug-search:focus {
        border-color: var(--brand);
        box-shadow: 0 0 0 3px rgba(220, 38, 38, .12);
    }

    .ug-search::placeholder {
        color: #94a3b8;
    }

    .ug-clear-btn {
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

    .ug-clear-btn.visible {
        display: block;
    }

    .btn-new-user {
        display: inline-flex;
        align-items: center;
        gap: .45rem;
        padding: .55rem 1.1rem;
        background: var(--brand);
        color: #fff !important;
        border-radius: var(--radius-sm);
        font-size: .88rem;
        font-weight: 600;
        border: none;
        cursor: pointer;
        white-space: nowrap;
        box-shadow: 0 2px 8px rgba(220, 38, 38, .25);
        transition: background var(--tr), transform var(--tr);
        text-decoration: none;
    }

    .btn-new-user:hover {
        background: var(--brand-dark);
        transform: translateY(-1px);
    }

    .ug-per-page {
        padding: .5rem .7rem;
        border: 1.5px solid var(--border);
        border-radius: var(--radius-sm);
        font-size: .85rem;
        background: var(--surface);
        color: var(--text-main);
        cursor: pointer;
        outline: none;
    }

    .ug-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow-sm);
        overflow: hidden;
    }

    .ug-card-header {
        padding: 1rem 1.5rem;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: var(--surface-2);
        gap: 1rem;
        flex-wrap: wrap;
    }

    .ug-card-title {
        font-weight: 700;
        font-size: .95rem;
        color: var(--text-main);
        display: flex;
        align-items: center;
        gap: .5rem;
    }

    .ug-result-badge {
        display: inline-flex;
        align-items: center;
        background: #fee2e2;
        color: #dc2626;
        border-radius: 20px;
        padding: .15rem .65rem;
        font-size: .78rem;
        font-weight: 600;
    }

    .ug-table-wrap {
        overflow-x: auto;
    }

    .ug-table {
        width: 100%;
        border-collapse: collapse;
        font-size: .855rem;
    }

    .ug-table thead th {
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

    .ug-table thead th:last-child {
        cursor: default;
    }

    .ug-table thead th:hover:not(:last-child) {
        background: #fee2e2;
        color: #dc2626;
    }

    .ug-table thead th.sort-asc,
    .ug-table thead th.sort-desc {
        color: #dc2626;
        background: #fee2e2;
    }

    .sort-icon {
        margin-left: .35rem;
        font-size: .68rem;
        opacity: .3;
        display: inline-block;
        transition: opacity .15s, transform .15s;
    }

    .ug-table thead th:hover:not(:last-child) .sort-icon {
        opacity: .7;
    }

    .ug-table thead th.sort-asc .sort-icon,
    .ug-table thead th.sort-desc .sort-icon {
        opacity: 1;
    }

    .ug-table thead th.sort-desc .sort-icon {
        transform: rotate(180deg);
    }

    .ug-table tbody tr {
        border-bottom: 1px solid var(--border);
        transition: background var(--tr);
    }

    .ug-table tbody tr:last-child {
        border-bottom: none;
    }

    .ug-table tbody tr:hover {
        background: #fef2f2;
    }

    .ug-table tbody td {
        padding: .82rem 1rem;
        color: var(--text-main);
        vertical-align: middle;
    }

    .role-badge {
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        padding: .2rem .6rem;
        border-radius: 20px;
        font-size: .73rem;
        font-weight: 600;
    }

    .role-superadmin {
        background: #1e1b4b;
        color: #c7d2fe;
    }

    .role-admin {
        background: var(--purple-bg);
        color: var(--purple);
    }

    .role-facturador {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .role-lector {
        background: #f1f5f9;
        color: var(--text-muted);
    }

    .role-asesor {
        background: #d1fae5;
        color: #065f46;
    }

    .status-activo {
        background: var(--success-bg);
        color: var(--success);
        border: 1px solid #a7f3d0;
    }

    .status-inactivo {
        background: var(--danger-bg);
        color: var(--danger);
        border: 1px solid #fecaca;
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        padding: .18rem .55rem;
        border-radius: 20px;
        font-size: .73rem;
        font-weight: 600;
    }

    .ug-highlight {
        background: #fef08a;
        border-radius: 3px;
        padding: 0 2px;
    }

    .ug-avatar {
        width: 34px;
        height: 34px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: .85rem;
        font-weight: 700;
        color: #fff;
        flex-shrink: 0;
        text-transform: uppercase;
    }

    .ug-actions {
        display: flex;
        gap: .35rem;
        align-items: center;
    }

    .btn-act {
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

    .btn-act-edit {
        background: var(--purple-bg);
        color: var(--purple);
        border-color: rgba(124, 58, 237, .2);
    }

    .btn-act-edit:hover {
        background: var(--purple);
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

    .btn-act-estab {
        background: #dbeafe;
        color: #1d4ed8;
        border-color: rgba(29, 78, 216, .2);
    }

    .btn-act-estab:hover {
        background: #1d4ed8;
        color: #fff;
        transform: translateY(-1px);
    }

    .btn-act-pw {
        background: var(--warning-bg);
        color: #92400e;
        border-color: rgba(245, 158, 11, .2);
    }

    .btn-act-pw:hover {
        background: var(--warning);
        color: #fff;
        transform: translateY(-1px);
    }

    .btn-act-dis {
        opacity: .4;
        cursor: not-allowed;
        pointer-events: none;
    }

    .ug-pagination {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: .85rem 1.25rem;
        border-top: 1px solid var(--border);
        background: var(--surface-2);
        gap: .75rem;
        flex-wrap: wrap;
    }

    .ug-page-info {
        font-size: .78rem;
        color: var(--text-muted);
    }

    .ug-page-btns {
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
        border-color: #dc2626;
        color: #dc2626;
        background: #fef2f2;
    }

    .page-btn.active {
        background: #dc2626;
        border-color: #dc2626;
        color: #fff;
        box-shadow: 0 2px 8px rgba(220, 38, 38, .3);
    }

    .page-btn.disabled {
        opacity: .35;
        cursor: not-allowed;
        pointer-events: none;
    }

    .ug-empty {
        text-align: center;
        padding: 3rem 1rem;
        color: var(--text-muted);
    }

    .lock-indicator {
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        font-size: .72rem;
        color: #92400e;
        background: #fff3e0;
        border: 1px solid #f5c07a;
        border-radius: 20px;
        padding: .15rem .55rem;
    }

    .modal-content {
        border: none;
        border-radius: var(--radius);
        box-shadow: 0 20px 60px rgba(0, 0, 0, .15);
    }

    .modal-header {
        border-bottom: 1px solid var(--border);
        padding: 1.1rem 1.5rem;
    }

    .modal-body {
        padding: 1.5rem;
    }

    .modal-footer {
        border-top: 1px solid var(--border);
        padding: .9rem 1.5rem;
    }

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
        transition: border-color var(--tr), box-shadow var(--tr);
    }

    .mf-input:focus,
    .mf-select:focus {
        border-color: #dc2626;
        box-shadow: 0 0 0 3px rgba(220, 38, 38, .1);
    }

    .mf-input.is-invalid {
        border-color: var(--danger);
    }

    .mb-mf {
        margin-bottom: .9rem;
    }

    .btn-modal-save {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        padding: .58rem 1.3rem;
        background: #dc2626;
        color: #fff;
        border: none;
        border-radius: var(--radius-sm);
        font-size: .88rem;
        font-weight: 600;
        cursor: pointer;
        transition: background var(--tr);
    }

    .btn-modal-save:hover {
        background: #b91c1c;
    }

    /* Admin: no puede ver botón crear superadmin */
    .role-sa-only {
        display: <?= $es_superadmin ? 'block' : 'none' ?>;
    }

    @media(max-width:640px) {
        .ug-header {
            padding: 1.1rem 1.25rem;
        }

        .ug-table thead th:nth-child(3),
        .ug-table tbody td:nth-child(3),
        .ug-table thead th:nth-child(5),
        .ug-table tbody td:nth-child(5) {
            display: none;
        }
    }
</style>

<div class="ug-page container">

    <!-- Header -->
    <div class="ug-header">
        <div>
            <h4 class="ug-header-title">👥 Gestión de Usuarios</h4>
            <p class="ug-header-sub">
                <?= $es_superadmin ? 'Vista global · Todos los clientes' : 'Cliente: ' . htmlspecialchars($mi_usuario['cliente_nombre'] ?? '') ?>
                &nbsp;·&nbsp; Acceso: <?= ucfirst($rol_actual) ?>
            </p>
        </div>
        <?php if (!empty($mi_usuario['logo_url'])): ?>
            <img src="<?= htmlspecialchars($mi_usuario['logo_url']) ?>" alt="Logo" class="ug-header-logo">
        <?php endif; ?>
    </div>

    <!-- Stats -->
    <div class="ug-stats">
        <div class="ug-stat">
            <div class="ug-stat-icon red"><i class="bi bi-people-fill"></i></div>
            <div>
                <div class="ug-stat-val" id="stat-total"><?= $total_usuarios ?></div>
                <div class="ug-stat-lbl">Total</div>
            </div>
        </div>
        <div class="ug-stat">
            <div class="ug-stat-icon green"><i class="bi bi-person-check-fill"></i></div>
            <div>
                <div class="ug-stat-val"><?= $activos ?></div>
                <div class="ug-stat-lbl">Activos</div>
            </div>
        </div>
        <div class="ug-stat">
            <div class="ug-stat-icon gray"><i class="bi bi-person-dash-fill"></i></div>
            <div>
                <div class="ug-stat-val"><?= $inactivos ?></div>
                <div class="ug-stat-lbl">Inactivos</div>
            </div>
        </div>
        <div class="ug-stat">
            <div class="ug-stat-icon purple"><i class="bi bi-shield-fill-check"></i></div>
            <div>
                <div class="ug-stat-val"><?= $por_rol['admin'] ?? 0 ?></div>
                <div class="ug-stat-lbl">Admins</div>
            </div>
        </div>
        <div class="ug-stat">
            <div class="ug-stat-icon blue"><i class="bi bi-funnel-fill"></i></div>
            <div>
                <div class="ug-stat-val" id="stat-filtered"><?= $total_usuarios ?></div>
                <div class="ug-stat-lbl">Resultados</div>
            </div>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="ug-toolbar">
        <button class="btn-new-user" id="btnNuevoUser">
            <i class="bi bi-person-plus-fill"></i> Nuevo Usuario
        </button>
        <div class="ug-search-wrap">
            <i class="bi bi-search"></i>
            <input type="text" id="ugSearch" class="ug-search" placeholder="Buscar por nombre, correo, rol…"
                autocomplete="off">
            <button class="ug-clear-btn" id="ugClear"><i class="bi bi-x-lg"></i></button>
        </div>
        <select class="ug-per-page" id="ugPerPage">
            <option value="10" selected>10/pág</option>
            <option value="25">25/pág</option>
            <option value="50">50/pág</option>
        </select>
    </div>

    <!-- Table card -->
    <div class="ug-card">
        <div class="ug-card-header">
            <span class="ug-card-title"><i class="bi bi-table"></i> Listado de Usuarios</span>
            <span class="ug-result-badge" id="ugBadge"><?= $total_usuarios ?> usuarios</span>
        </div>
        <div class="ug-table-wrap">
            <table class="ug-table" id="ugTable">
                <thead>
                    <tr>
                        <th data-col="0"><i class="bi bi-person me-1"></i>Usuario<i
                                class="bi bi-arrow-up sort-icon"></i></th>
                        <th data-col="1"><i class="bi bi-envelope me-1"></i>Correo<i
                                class="bi bi-arrow-up sort-icon"></i></th>
                        <th data-col="2"><i class="bi bi-building me-1"></i>Cliente<i
                                class="bi bi-arrow-up sort-icon"></i></th>
                        <th data-col="3"><i class="bi bi-shield me-1"></i>Rol<i class="bi bi-arrow-up sort-icon"></i>
                        </th>
                        <th data-col="4"><i class="bi bi-circle me-1"></i>Estado<i class="bi bi-arrow-up sort-icon"></i>
                        </th>
                        <th data-col="5"><i class="bi bi-calendar3 me-1"></i>Creado<i
                                class="bi bi-arrow-up sort-icon"></i></th>
                        <th style="cursor:default;"><i class="bi bi-gear me-1"></i>Acciones</th>
                    </tr>
                </thead>
                <tbody id="ugBody">
                    <?php
                    $avatarColors = ['#dc2626', '#7c3aed', '#0f766e', '#1d4ed8', '#d97706', '#db2777'];
                    $ci = 0;
                    foreach ($todos_usuarios as $u):
                        $isSelf       = ((int)$u['id'] === (int)$usuario_id);
                        $isSuperAdmin = ($u['rol'] === 'superadmin');
                        $isAdmin      = ($u['rol'] === 'admin');

                        /*
                     * Reglas de eliminación:
                     * - superadmin NUNCA se puede eliminar (por nadie)
                     * - Uno mismo no puede eliminarse
                     * - admin logueado: puede eliminar facturador/lector/asesor de su cliente, NO puede eliminar admin ni superadmin
                     * - superadmin logueado: puede eliminar cualquier no-superadmin (incluido admin)
                     */
                        if ($isSuperAdmin || $isSelf) {
                            $canDelete = false;
                            $lockReason = $isSelf ? 'No puedes eliminarte a ti mismo' : 'El superadmin no puede eliminarse';
                        } elseif ($es_admin && $isAdmin) {
                            $canDelete  = false;
                            $lockReason = 'Solo superadmin puede eliminar administradores';
                        } else {
                            $canDelete = true;
                            $lockReason = '';
                        }

                        $avatarColor = $avatarColors[$ci % count($avatarColors)];
                        $ci++;
                        $searchStr   = strtolower($u['nombre'] . ' ' . $u['correo'] . ' ' . $u['rol'] . ' ' . ($u['cliente_nombre'] ?? '') . ' ' . $u['estado']);
                    ?>
                        <tr data-search="<?= htmlspecialchars($searchStr) ?>">
                            <td>
                                <div style="display:flex;align-items:center;gap:.65rem;">
                                    <div class="ug-avatar" style="background:<?= $avatarColor ?>;">
                                        <?= mb_strtoupper(mb_substr($u['nombre'], 0, 1)) ?></div>
                                    <div>
                                        <div class="fw-semibold" data-col="nombre"><?= htmlspecialchars($u['nombre']) ?>
                                        </div>
                                        <?php if ($isSelf): ?><span
                                                style="font-size:.7rem;color:#64748b;">(Tú)</span><?php endif; ?>
                                        <?php if ($isSuperAdmin): ?><span class="lock-indicator"><i
                                                    class="bi bi-lock-fill"></i> Protegido</span><?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td data-col="correo" style="font-size:.82rem;"><?= htmlspecialchars($u['correo']) ?></td>
                            <td data-col="cliente"><?= htmlspecialchars($u['cliente_nombre'] ?? '—') ?></td>
                            <td>
                                <?php $roleMap = [
                                    'superadmin' => ['label' => 'Superadmin', 'class' => 'role-superadmin', 'icon' => 'bi-shield-fill-exclamation'],
                                    'admin'      => ['label' => 'Admin',     'class' => 'role-admin',      'icon' => 'bi-shield-fill-check'],
                                    'facturador' => ['label' => 'Facturador', 'class' => 'role-facturador', 'icon' => 'bi-file-earmark-text-fill'],
                                    'lector'     => ['label' => 'Lector',    'class' => 'role-lector',     'icon' => 'bi-eye-fill'],
                                    'asesor'     => ['label' => 'Asesor',    'class' => 'role-asesor',     'icon' => 'bi-person-badge-fill'],
                                ];
                                $rm = $roleMap[$u['rol']] ?? ['label' => ucfirst($u['rol']), 'class' => 'role-lector', 'icon' => 'bi-person']; ?>
                                <span class="role-badge <?= $rm['class'] ?>"><i class="bi <?= $rm['icon'] ?>"></i>
                                    <?= $rm['label'] ?></span>
                            </td>
                            <td>
                                <span
                                    class="status-badge <?= $u['estado'] === 'activo' ? 'status-activo' : 'status-inactivo' ?>">
                                    <?= $u['estado'] === 'activo' ? '<i class="bi bi-check-circle-fill"></i> Activo' : '<i class="bi bi-x-circle-fill"></i> Inactivo' ?>
                                </span>
                            </td>
                            <td style="font-size:.78rem;color:var(--text-muted);">
                                <?= date('d/m/Y', strtotime($u['creado_en'])) ?></td>
                            <td>
                                <div class="ug-actions">
                                    <button class="btn-act btn-act-edit btn-editar-user"
                                        data-user='<?= json_encode($u, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>'
                                        title="Editar">
                                        <i class="bi bi-pencil-fill"></i>
                                        <span class="d-none d-lg-inline">Editar</span>
                                    </button>
                                    <button class="btn-act btn-act-estab btn-asignar-estab" data-id="<?= $u['id'] ?>"
                                        data-nombre="<?= htmlspecialchars($u['nombre']) ?>"
                                        data-cliente="<?= (int)$u['cliente_id'] ?>" title="Sucursales">
                                        <i class="bi bi-building-fill-gear"></i>
                                        <span class="d-none d-lg-inline">Sucursales</span>
                                    </button>
                                    <button class="btn-act btn-act-pw btn-reset-pw" data-id="<?= $u['id'] ?>"
                                        data-nombre="<?= htmlspecialchars($u['nombre']) ?>" title="Cambiar contraseña"><i
                                            class="bi bi-key-fill"></i></button>
                                    <?php if ($canDelete): ?>
                                        <button class="btn-act btn-act-del btn-eliminar-user" data-id="<?= $u['id'] ?>"
                                            data-nombre="<?= htmlspecialchars($u['nombre']) ?>"
                                            data-rol="<?= htmlspecialchars($u['rol']) ?>" title="Eliminar"><i
                                                class="bi bi-trash3-fill"></i></button>
                                    <?php else: ?>
                                        <button class="btn-act btn-act-del btn-act-dis"
                                            title="<?= htmlspecialchars($lockReason) ?>">
                                            <i class="bi bi-lock-fill"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="ug-empty" id="ugEmpty" style="display:none;">
                <div style="font-size:2.5rem;margin-bottom:.7rem;opacity:.3;"><i class="bi bi-person-x"></i></div>
                <div style="font-weight:600;">Sin resultados</div>
                <div id="ugEmptySub" style="font-size:.85rem;margin-top:.3rem;"></div>
            </div>
        </div>
        <div class="ug-pagination">
            <span class="ug-page-info" id="ugPageInfo"></span>
            <div class="ug-page-btns" id="ugPageBtns"></div>
        </div>
    </div>
</div>

<!-- Modal Crear/Editar -->
<div class="modal fade" id="modalUsuario" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalUserTitulo"><i
                        class="bi bi-person-plus-fill me-2 text-danger"></i>Nuevo Usuario</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formUsuario">
                    <input type="hidden" name="user_id" id="user_id">
                    <div class="mb-mf">
                        <label class="mf-label">Nombre completo <span style="color:#ef4444;">*</span></label>
                        <input type="text" name="nombre" id="u_nombre" class="mf-input" placeholder="Ej: Juan Pérez"
                            required maxlength="120">
                    </div>
                    <div class="mb-mf">
                        <label class="mf-label">Correo electrónico <span style="color:#ef4444;">*</span></label>
                        <input type="email" name="correo" id="u_correo" class="mf-input"
                            placeholder="correo@empresa.com" required maxlength="160">
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;" class="mb-mf">
                        <div>
                            <label class="mf-label">Rol <span style="color:#ef4444;">*</span></label>
                            <select name="rol" id="u_rol" class="mf-select" required>
                                <option value="">— Seleccione —</option>
                                <option value="admin">Admin</option>
                                <option value="facturador">Facturador</option>
                                <option value="lector">Lector</option>
                                <option value="asesor">Asesor</option>
                                <!-- superadmin solo lo puede crear el superadmin -->
                            </select>
                        </div>
                        <div>
                            <label class="mf-label">Estado</label>
                            <select name="estado" id="u_estado" class="mf-select">
                                <option value="activo">Activo</option>
                                <option value="inactivo">Inactivo</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-mf">
                        <label class="mf-label">Cliente <span style="color:#ef4444;">*</span></label>
                        <select name="cliente_id" id="u_cliente" class="mf-select" required
                            <?= !$es_superadmin ? 'disabled' : '' ?>>
                            <option value="">— Seleccione —</option>
                            <?php foreach ($clientes_lista as $cl): ?>
                                <option value="<?= $cl['id'] ?>"><?= htmlspecialchars($cl['nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$es_superadmin): ?>
                            <!-- Admin: siempre asigna a su propio cliente -->
                            <input type="hidden" name="cliente_id" value="<?= $mi_cliente_id ?>">
                        <?php endif; ?>
                    </div>
                    <div class="mb-mf">
                        <label class="mf-label">Contraseña <span style="color:#ef4444;" id="pwRequired">*</span>
                            <span class="text-muted" style="font-size:.75rem;" id="pwHint"></span></label>
                        <input type="password" name="clave" id="u_clave" class="mf-input"
                            placeholder="Mínimo 6 caracteres" minlength="6" autocomplete="new-password">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn-modal-save" id="btnGuardarUser"><i class="bi bi-floppy-fill me-1"></i>
                    Guardar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Establecimientos -->
<div class="modal fade" id="modalEstablecimientos" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-building-fill-gear me-2 text-primary"></i>Establecimientos —
                    <span id="estabUserNombre"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="estab_user_id">
                <input type="hidden" id="estab_cliente_id">
                <div class="alert alert-info py-2 mb-3" style="font-size:.85rem;"><i
                        class="bi bi-info-circle-fill me-1"></i> Selecciona las sucursales a las que este usuario puede
                    acceder.</div>
                <div id="listaEstablecimientos" class="row g-2"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="btnGuardarEstabs"><i
                        class="bi bi-floppy-fill me-1"></i> Guardar Asignaciones</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Contraseña -->
<div class="modal fade" id="modalPassword" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-key-fill me-2 text-warning"></i>Nueva Contraseña</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="pw_user_id">
                <p class="text-muted mb-3" style="font-size:.85rem;">Cambiando contraseña de: <strong
                        id="pw_nombre"></strong></p>
                <div class="mb-mf">
                    <label class="mf-label">Nueva contraseña <span style="color:#ef4444;">*</span></label>
                    <input type="password" id="pw_nueva" class="mf-input" placeholder="Mínimo 6 caracteres"
                        minlength="6">
                </div>
                <div class="mb-mf">
                    <label class="mf-label">Confirmar</label>
                    <input type="password" id="pw_confirmar" class="mf-input" placeholder="Repite la contraseña">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-warning text-dark fw-bold" id="btnGuardarPw"><i
                        class="bi bi-key-fill me-1"></i> Cambiar</button>
            </div>
        </div>
    </div>
</div>

<script>
    const ES_SUPERADMIN = <?= $es_superadmin ? 'true' : 'false' ?>;
    const MI_CLIENTE_ID = <?= json_encode($mi_cliente_id) ?>;

    /* ── Table engine ── */
    (() => {
        let query = '',
            page = 1,
            perPage = 10,
            sortCol = -1,
            sortDir = 'asc';
        const allRows = Array.from(document.querySelectorAll('#ugBody tr'));
        const $s = document.getElementById('ugSearch'),
            $cl = document.getElementById('ugClear'),
            $pp = document.getElementById('ugPerPage');
        const $empty = document.getElementById('ugEmpty'),
            $sub = document.getElementById('ugEmptySub');
        const $info = document.getElementById('ugPageInfo'),
            $btns = document.getElementById('ugPageBtns');
        const $badge = document.getElementById('ugBadge'),
            $statF = document.getElementById('stat-filtered');
        const headers = document.querySelectorAll('#ugTable thead th[data-col]');

        function hl(t, q) {
            if (!q) return t;
            return t.replace(new RegExp(`(${q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')})`, `gi`),
                '<mark class="ug-highlight">$1</mark>');
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
                $sub.textContent = query ? `Sin resultados para "${query}".` : 'No hay usuarios.';
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
            $badge.textContent = `${total} usuario${total!==1?'s':''}`;
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

    /* ── Nuevo usuario ── */
    document.getElementById('btnNuevoUser').addEventListener('click', () => {
        document.getElementById('modalUserTitulo').innerHTML =
            '<i class="bi bi-person-plus-fill me-2 text-danger"></i>Nuevo Usuario';
        document.getElementById('formUsuario').reset();
        document.getElementById('user_id').value = '';
        document.getElementById('pwRequired').style.display = '';
        document.getElementById('pwHint').textContent = '';
        document.getElementById('u_clave').required = true;
        // Pre-seleccionar cliente si es admin
        if (!ES_SUPERADMIN) {
            const sel = document.getElementById('u_cliente');
            if (sel) sel.value = MI_CLIENTE_ID;
        }
        new bootstrap.Modal(document.getElementById('modalUsuario')).show();
    });

    /* ── Editar usuario ── */
    document.querySelectorAll('.btn-editar-user').forEach(btn => {
        btn.addEventListener('click', () => {
            const u = JSON.parse(btn.dataset.user);
            document.getElementById('modalUserTitulo').innerHTML =
                '<i class="bi bi-pencil-square me-2" style="color:#7c3aed"></i>Editar Usuario';
            document.getElementById('user_id').value = u.id;
            document.getElementById('u_nombre').value = u.nombre;
            document.getElementById('u_correo').value = u.correo;
            document.getElementById('u_rol').value = u.rol;
            document.getElementById('u_estado').value = u.estado;
            const selCliente = document.getElementById('u_cliente');
            if (selCliente) selCliente.value = u.cliente_id || '';
            document.getElementById('u_clave').value = '';
            document.getElementById('u_clave').required = false;
            document.getElementById('pwRequired').style.display = 'none';
            document.getElementById('pwHint').textContent = '(dejar vacío para no cambiar)';
            new bootstrap.Modal(document.getElementById('modalUsuario')).show();
        });
    });

    /* ── Guardar usuario ── */
    document.getElementById('btnGuardarUser').addEventListener('click', () => {
        const isEdit = !!document.getElementById('user_id').value;
        let valid = true;
        ['u_nombre', 'u_correo', 'u_rol'].forEach(id => {
            const el = document.getElementById(id);
            el.classList.remove('is-invalid');
            if (!el.value.trim()) {
                el.classList.add('is-invalid');
                valid = false;
            }
        });
        if (!isEdit && !document.getElementById('u_clave').value.trim()) {
            document.getElementById('u_clave').classList.add('is-invalid');
            valid = false;
        }
        if (!valid) return Swal.fire({
            icon: 'warning',
            title: 'Campos requeridos',
            text: 'Completa todos los campos obligatorios.',
            confirmButtonColor: '#dc2626'
        });

        const btn = document.getElementById('btnGuardarUser');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Guardando…';
        const url = isEdit ? 'includes/usuario_actualizar.php' : 'includes/usuario_guardar.php';

        // Si admin, agregar cliente_id manualmente al form
        const fd = new FormData(document.getElementById('formUsuario'));
        if (!ES_SUPERADMIN) fd.set('cliente_id', MI_CLIENTE_ID);

        fetch(url, {
                method: 'POST',
                body: fd
            })
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    Swal.fire({
                        icon: 'success',
                        title: '¡Guardado!',
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => location.reload());
                } else {
                    Swal.fire('Error', d.error || 'No se pudo guardar.', 'error');
                }
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-floppy-fill me-1"></i> Guardar';
            })
            .catch(() => {
                Swal.fire('Error', 'Error inesperado.', 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-floppy-fill me-1"></i> Guardar';
            });
    });

    /* ── Asignar establecimientos ── */
    const allEstabs = <?= json_encode($establecimientos_todos, JSON_HEX_TAG) ?>;
    document.querySelectorAll('.btn-asignar-estab').forEach(btn => {
        btn.addEventListener('click', async () => {
            const uid = btn.dataset.id,
                nombre = btn.dataset.nombre,
                clienteId = parseInt(btn.dataset.cliente);
            document.getElementById('estab_user_id').value = uid;
            document.getElementById('estab_cliente_id').value = clienteId;
            document.getElementById('estabUserNombre').textContent = nombre;
            const res = await fetch(`includes/usuario_establecimientos_get.php?usuario_id=${uid}`);
            const asignados = await res.json();
            const asigSet = new Set(asignados.map(String));
            const filtrados = allEstabs.filter(e => !clienteId || e.cliente_id == clienteId);
            const lista = document.getElementById('listaEstablecimientos');
            lista.innerHTML = '';
            if (!filtrados.length) {
                lista.innerHTML =
                    '<div class="col-12"><div class="alert alert-warning">No hay establecimientos para este cliente.</div></div>';
            } else {
                filtrados.forEach(e => {
                    const checked = asigSet.has(String(e.establecimiento_id)) ? 'checked' : '';
                    lista.innerHTML +=
                        `<div class="col-md-6"><div class="border rounded-3 p-3 d-flex align-items-center gap-3 ${checked?'bg-light border-primary':''}"><input type="checkbox" class="form-check-input estab-check" id="estab_${e.establecimiento_id}" value="${e.establecimiento_id}" ${checked} style="width:18px;height:18px;"><label for="estab_${e.establecimiento_id}" style="cursor:pointer;margin:0;"><div class="fw-semibold small">${e.nombre}</div><div class="text-muted" style="font-size:.75rem;">${e.cliente_nombre}</div></label></div></div>`;
                });
            }
            new bootstrap.Modal(document.getElementById('modalEstablecimientos')).show();
        });
    });
    document.getElementById('btnGuardarEstabs').addEventListener('click', () => {
        const uid = document.getElementById('estab_user_id').value;
        const ids = [...document.querySelectorAll('.estab-check:checked')].map(c => c.value);
        const btn = document.getElementById('btnGuardarEstabs');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Guardando…';
        fetch('includes/usuario_establecimientos_guardar.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    usuario_id: uid,
                    establecimientos: ids
                })
            })
            .then(r => r.json()).then(d => {
                if (d.success) {
                    Swal.fire({
                        icon: 'success',
                        title: '¡Listo!',
                        timer: 1400,
                        showConfirmButton: false
                    }).then(() => location.reload());
                } else {
                    Swal.fire('Error', d.error, 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-floppy-fill me-1"></i> Guardar Asignaciones';
                }
            });
    });

    /* ── Cambiar contraseña ── */
    document.querySelectorAll('.btn-reset-pw').forEach(btn => {
        btn.addEventListener('click', () => {
            document.getElementById('pw_user_id').value = btn.dataset.id;
            document.getElementById('pw_nombre').textContent = btn.dataset.nombre;
            document.getElementById('pw_nueva').value = '';
            document.getElementById('pw_confirmar').value = '';
            new bootstrap.Modal(document.getElementById('modalPassword')).show();
        });
    });
    document.getElementById('btnGuardarPw').addEventListener('click', () => {
        const nueva = document.getElementById('pw_nueva').value,
            conf = document.getElementById('pw_confirmar').value;
        if (!nueva || nueva.length < 6) return Swal.fire({
            icon: 'warning',
            title: 'Contraseña muy corta',
            text: 'Mínimo 6 caracteres.',
            confirmButtonColor: '#f59e0b'
        });
        if (nueva !== conf) return Swal.fire({
            icon: 'warning',
            title: 'No coinciden',
            text: 'Las contraseñas no son iguales.',
            confirmButtonColor: '#f59e0b'
        });
        const btn = document.getElementById('btnGuardarPw');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Cambiando…';
        const fd = new FormData();
        fd.append('user_id', document.getElementById('pw_user_id').value);
        fd.append('nueva_clave', nueva);
        fetch('includes/usuario_cambiar_clave.php', {
            method: 'POST',
            body: fd
        }).then(r => r.json()).then(d => {
            if (d.success) {
                Swal.fire({
                    icon: 'success',
                    title: '¡Contraseña cambiada!',
                    timer: 1400,
                    showConfirmButton: false
                }).then(() => bootstrap.Modal.getInstance(document.getElementById('modalPassword'))
                    .hide());
            } else Swal.fire('Error', d.error, 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-key-fill me-1"></i> Cambiar';
        });
    });

    /* ── Eliminar usuario ── */
    document.querySelectorAll('.btn-eliminar-user').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id,
                nombre = btn.dataset.nombre,
                rol = btn.dataset.rol;
            const extra = rol === 'admin' ?
                '<br><small class="text-danger">⚠️ Estás eliminando un <strong>administrador</strong>.</small>' :
                '';
            Swal.fire({
                    title: '¿Eliminar usuario?',
                    html: `<strong>${nombre}</strong> será eliminado permanentemente.${extra}`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#ef4444',
                    cancelButtonColor: '#6b7280',
                    confirmButtonText: '<i class="bi bi-trash3-fill me-1"></i> Sí, eliminar',
                    cancelButtonText: 'Cancelar',
                    reverseButtons: true
                })
                .then(r => {
                    if (!r.isConfirmed) return;
                    const fd = new FormData();
                    fd.append('id', id);
                    fetch('includes/usuario_eliminar.php', {
                        method: 'POST',
                        body: fd
                    }).then(res => res.json()).then(d => {
                        if (d.success) Swal.fire({
                            icon: 'success',
                            title: 'Eliminado',
                            timer: 1400,
                            showConfirmButton: false
                        }).then(() => location.reload());
                        else Swal.fire('Error', d.error, 'error');
                    });
                });
        });
    });
</script>

<?php require_once '../../includes/templates/footer.php'; ?>