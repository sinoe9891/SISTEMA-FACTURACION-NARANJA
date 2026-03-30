<?php
$titulo = 'Contratos';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';
require_once '../../includes/templates/header.php';

$cliente_id = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);

// Auto-update estados
$pdo->prepare("UPDATE contratos SET estado='vencido' WHERE cliente_id=? AND estado='activo' AND fecha_fin IS NOT NULL AND fecha_fin < CURDATE()")->execute([$cliente_id]);

// KPIs
$stmtKpi = $pdo->prepare("
    SELECT
        SUM(estado='activo')  AS activos,
        SUM(estado='activo' AND fecha_fin BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)) AS proximos_vencer,
        SUM(estado='vencido') AS vencidos,
        SUM(CASE WHEN estado='activo' THEN monto ELSE 0 END) AS monto_activo
    FROM contratos WHERE cliente_id=?
");
$stmtKpi->execute([$cliente_id]);
$kpi = $stmtKpi->fetch(PDO::FETCH_ASSOC);

// Lista completa
$stmtLista = $pdo->prepare("
    SELECT c.*,
           cf.nombre AS receptor_nombre, cf.rtn AS receptor_rtn,
           cf.email AS receptor_email, cf.telefono AS receptor_tel,
           p.nombre AS producto_nombre,
           (CASE
               WHEN c.tipo_contrato = 'sin_factura'
               THEN (SELECT COUNT(*) FROM contratos_recibos r WHERE r.contrato_id=c.id AND r.cliente_id=c.cliente_id AND r.estado='emitido' AND MONTH(r.fecha_emision)=MONTH(CURDATE()) AND YEAR(r.fecha_emision)=YEAR(CURDATE()))
               ELSE (SELECT COUNT(*) FROM facturas f WHERE f.contrato_id=c.id AND f.cliente_id=c.cliente_id AND f.estado='emitida' AND MONTH(f.fecha_emision)=MONTH(CURDATE()) AND YEAR(f.fecha_emision)=YEAR(CURDATE()))
           END) AS facturado_este_mes,
           (SELECT DATE(f2.fecha_emision) FROM facturas f2 WHERE f2.contrato_id=c.id AND f2.cliente_id=c.cliente_id AND f2.estado='emitida' ORDER BY f2.fecha_emision DESC LIMIT 1) AS ultima_factura_fecha,
           (SELECT COUNT(*) FROM facturas f3 WHERE f3.contrato_id=c.id AND f3.cliente_id=c.cliente_id AND f3.estado='emitida') AS total_facturas_contrato,
           (SELECT COALESCE(SUM(f4.total),0) FROM facturas f4 WHERE f4.contrato_id=c.id AND f4.cliente_id=c.cliente_id AND f4.estado='emitida') AS total_monto_contrato,
           CASE
               WHEN c.fecha_inicio > CURDATE() THEN DATE(CONCAT(YEAR(c.fecha_inicio),'-',LPAD(MONTH(c.fecha_inicio),2,'0'),'-',LPAD(LEAST(c.dia_pago,DAY(LAST_DAY(c.fecha_inicio))),2,'0')))
               WHEN DAY(CURDATE()) <= c.dia_pago THEN DATE(CONCAT(YEAR(CURDATE()),'-',LPAD(MONTH(CURDATE()),2,'0'),'-',LPAD(LEAST(c.dia_pago,DAY(LAST_DAY(CURDATE()))),2,'0')))
               ELSE DATE(CONCAT(YEAR(DATE_ADD(CURDATE(),INTERVAL 1 MONTH)),'-',LPAD(MONTH(DATE_ADD(CURDATE(),INTERVAL 1 MONTH)),2,'0'),'-',LPAD(LEAST(c.dia_pago,DAY(LAST_DAY(DATE_ADD(CURDATE(),INTERVAL 1 MONTH)))),2,'0')))
           END AS proxima_fecha_pago,
           CASE
               WHEN c.fecha_inicio > CURDATE() THEN DATEDIFF(DATE(CONCAT(YEAR(c.fecha_inicio),'-',LPAD(MONTH(c.fecha_inicio),2,'0'),'-',LPAD(LEAST(c.dia_pago,DAY(LAST_DAY(c.fecha_inicio))),2,'0'))),CURDATE())
               WHEN DAY(CURDATE()) <= c.dia_pago THEN DATEDIFF(DATE(CONCAT(YEAR(CURDATE()),'-',LPAD(MONTH(CURDATE()),2,'0'),'-',LPAD(LEAST(c.dia_pago,DAY(LAST_DAY(CURDATE()))),2,'0'))),CURDATE())
               ELSE DATEDIFF(DATE(CONCAT(YEAR(DATE_ADD(CURDATE(),INTERVAL 1 MONTH)),'-',LPAD(MONTH(DATE_ADD(CURDATE(),INTERVAL 1 MONTH)),2,'0'),'-',LPAD(LEAST(c.dia_pago,DAY(LAST_DAY(DATE_ADD(CURDATE(),INTERVAL 1 MONTH)))),2,'0'))),CURDATE())
           END AS dias_para_pago,
           CASE
               WHEN c.fecha_fin IS NULL THEN 'indefinido'
               WHEN c.fecha_fin < CURDATE() THEN 'vencido'
               WHEN c.fecha_fin BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 3 DAY) THEN 'critico'
               WHEN c.fecha_fin BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY) THEN 'proximo'
               ELSE 'activo'
           END AS alerta,
           DATEDIFF(c.fecha_fin, CURDATE()) AS dias_restantes,
           (c.fecha_inicio > CURDATE()) AS no_iniciado
    FROM contratos c
    INNER JOIN clientes_factura   cf ON cf.id=c.receptor_id AND cf.cliente_id=c.cliente_id
    INNER JOIN productos_clientes p  ON p.id=c.producto_id  AND p.cliente_id=c.cliente_id
    WHERE c.cliente_id=?
    ORDER BY c.estado ASC, dias_para_pago ASC, c.fecha_fin ASC
");
$stmtLista->execute([$cliente_id]);
$contratos = $stmtLista->fetchAll(PDO::FETCH_ASSOC);

$pendientes_mes = 0;
$monto_pendiente = 0;
foreach ($contratos as $c) {
    if ($c['estado'] === 'activo' && !(int)$c['no_iniciado'] && !(int)$c['facturado_este_mes']) {
        $pendientes_mes++;
        $monto_pendiente += (float)$c['monto'];
    }
}
$total_contratos = count($contratos);
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>
    :root {
        --brand: #0f766e;
        --brand-light: #ccfbf1;
        --brand-dark: #0d5c56;
        --success: #10b981;
        --success-bg: #ecfdf5;
        --danger: #ef4444;
        --danger-bg: #fef2f2;
        --warning: #f59e0b;
        --warning-bg: #fffbeb;
        --info: #0ea5e9;
        --info-bg: #f0f9ff;
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

    .ct-page {
        padding: 1.5rem 0 3rem;
    }

    .ct-header {
        background: linear-gradient(135deg, #0f766e 0%, #0d5c56 100%);
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

    .ct-header::before {
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

    .ct-header::after {
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

    .ct-header-title {
        font-size: 1.35rem;
        font-weight: 700;
        margin: 0;
    }

    .ct-header-sub {
        font-size: .82rem;
        opacity: .8;
        margin: .25rem 0 0;
    }

    /* Stats */
    .ct-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .ct-stat {
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

    .ct-stat:hover {
        box-shadow: var(--shadow-md);
        transform: translateY(-2px);
    }

    .ct-stat-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        flex-shrink: 0;
    }

    .ct-stat-icon.teal {
        background: var(--brand-light);
        color: var(--brand);
    }

    .ct-stat-icon.green {
        background: var(--success-bg);
        color: var(--success);
    }

    .ct-stat-icon.red {
        background: var(--danger-bg);
        color: var(--danger);
    }

    .ct-stat-icon.amber {
        background: var(--warning-bg);
        color: var(--warning);
    }

    .ct-stat-icon.blue {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .ct-stat-val {
        font-size: 1.35rem;
        font-weight: 700;
        color: var(--text-main);
        line-height: 1;
    }

    .ct-stat-lbl {
        font-size: .72rem;
        color: var(--text-muted);
        margin-top: 2px;
    }

    /* Toolbar */
    .ct-toolbar {
        display: flex;
        align-items: center;
        gap: .75rem;
        flex-wrap: wrap;
        margin-bottom: 1.25rem;
    }

    .ct-search-wrap {
        position: relative;
        flex: 1 1 200px;
        min-width: 180px;
    }

    .ct-search-wrap>i {
        position: absolute;
        left: .8rem;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-muted);
        font-size: .9rem;
        pointer-events: none;
    }

    .ct-search {
        width: 100%;
        padding: .52rem .8rem .52rem 2.2rem;
        border: 1.5px solid var(--border);
        border-radius: var(--radius-sm);
        font-size: .875rem;
        background: var(--surface);
        color: var(--text-main);
        outline: none;
        transition: border-color var(--tr), box-shadow var(--tr);
    }

    .ct-search:focus {
        border-color: var(--brand);
        box-shadow: 0 0 0 3px rgba(15, 118, 110, .12);
    }

    .ct-search::placeholder {
        color: #94a3b8;
    }

    .ct-clear-btn {
        position: absolute;
        right: .6rem;
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

    .ct-clear-btn.visible {
        display: block;
    }

    .btn-new-ct {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        padding: .52rem 1.05rem;
        background: var(--brand);
        color: #fff !important;
        border-radius: var(--radius-sm);
        font-size: .86rem;
        font-weight: 600;
        text-decoration: none;
        border: none;
        cursor: pointer;
        white-space: nowrap;
        box-shadow: 0 2px 8px rgba(15, 118, 110, .25);
        transition: background var(--tr), transform var(--tr);
    }

    .btn-new-ct:hover {
        background: var(--brand-dark);
        transform: translateY(-1px);
    }

    .ct-per-page {
        padding: .48rem .65rem;
        border: 1.5px solid var(--border);
        border-radius: var(--radius-sm);
        font-size: .82rem;
        background: var(--surface);
        color: var(--text-main);
        cursor: pointer;
        outline: none;
    }

    /* Card */
    .ct-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow-sm);
        overflow: hidden;
        margin-bottom: 1.5rem;
    }

    .ct-card-header {
        padding: 1rem 1.5rem;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: var(--surface-2);
        gap: 1rem;
        flex-wrap: wrap;
    }

    .ct-card-title {
        font-weight: 700;
        font-size: .95rem;
        color: var(--text-main);
        display: flex;
        align-items: center;
        gap: .5rem;
    }

    .ct-result-badge {
        display: inline-flex;
        align-items: center;
        background: var(--brand-light);
        color: var(--brand);
        border-radius: 20px;
        padding: .15rem .65rem;
        font-size: .78rem;
        font-weight: 600;
    }

    /* Table */
    .ct-table-wrap {
        overflow-x: auto;
    }

    .ct-table {
        width: 100%;
        border-collapse: collapse;
        font-size: .845rem;
    }

    .ct-table thead th {
        padding: .7rem 1rem;
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

    .ct-table thead th:last-child {
        cursor: default;
    }

    .ct-table thead th:hover:not(:last-child) {
        background: var(--brand-light);
        color: var(--brand);
    }

    .ct-table thead th.sort-asc,
    .ct-table thead th.sort-desc {
        color: var(--brand);
        background: var(--brand-light);
    }

    .sort-icon {
        margin-left: .35rem;
        font-size: .68rem;
        opacity: .3;
        display: inline-block;
        transition: opacity .15s, transform .15s;
    }

    .ct-table thead th:hover:not(:last-child) .sort-icon {
        opacity: .7;
    }

    .ct-table thead th.sort-asc .sort-icon,
    .ct-table thead th.sort-desc .sort-icon {
        opacity: 1;
    }

    .ct-table thead th.sort-desc .sort-icon {
        transform: rotate(180deg);
    }

    .ct-table tbody tr {
        border-bottom: 1px solid var(--border);
        transition: background var(--tr);
    }

    .ct-table tbody tr:last-child {
        border-bottom: none;
    }

    .ct-table tbody tr:hover {
        background: #f0fdf9;
    }

    .ct-table tbody td {
        padding: .8rem 1rem;
        color: var(--text-main);
        vertical-align: middle;
    }

    .ct-table tbody tr.row-critico td {
        background: #fef2f2;
    }

    .ct-table tbody tr.row-proximo td {
        background: #fffbeb;
    }

    .ct-table tbody tr.row-vencido td {
        background: #f8fafc;
        opacity: .75;
    }

    /* Badges */
    .st-pill {
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        padding: .2rem .6rem;
        border-radius: 20px;
        font-size: .73rem;
        font-weight: 600;
    }

    .st-activo {
        background: var(--success-bg);
        color: var(--success);
    }

    .st-vencido {
        background: var(--danger-bg);
        color: var(--danger);
    }

    .st-cancelado {
        background: #f1f5f9;
        color: var(--text-muted);
    }

    .st-pausado {
        background: var(--warning-bg);
        color: #92400e;
    }

    .fact-pill {
        display: inline-flex;
        align-items: center;
        gap: .25rem;
        padding: .18rem .55rem;
        border-radius: 20px;
        font-size: .72rem;
        font-weight: 600;
    }

    .fact-si {
        background: var(--success-bg);
        color: var(--success);
        border: 1px solid #a7f3d0;
    }

    .fact-no {
        background: var(--warning-bg);
        color: #92400e;
        border: 1px solid #fde68a;
    }

    /* Actions */
    .ct-actions {
        display: flex;
        gap: .35rem;
        align-items: center;
        flex-wrap: wrap;
    }

    .btn-fa {
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        padding: .3rem .6rem;
        border-radius: var(--radius-sm);
        font-size: .76rem;
        font-weight: 600;
        cursor: pointer;
        border: 1.5px solid transparent;
        transition: all var(--tr);
        text-decoration: none;
        white-space: nowrap;
    }

    .btn-fa-edit {
        background: var(--info-bg);
        color: var(--info);
        border-color: rgba(14, 165, 233, .2);
    }

    .btn-fa-edit:hover {
        background: var(--info);
        color: #fff;
    }

    .btn-fa-facturar {
        background: var(--success-bg);
        color: var(--success);
        border-color: rgba(16, 185, 129, .2);
    }

    .btn-fa-facturar:hover {
        background: var(--success);
        color: #fff;
    }

    .btn-fa-receipt {
        background: #dbeafe;
        color: #1d4ed8;
        border-color: rgba(29, 78, 216, .2);
    }

    .btn-fa-receipt:hover {
        background: #1d4ed8;
        color: #fff;
    }

    .btn-fa-cancel {
        background: var(--danger-bg);
        color: var(--danger);
        border-color: rgba(239, 68, 68, .2);
    }

    .btn-fa-cancel:hover {
        background: var(--danger);
        color: #fff;
    }

    .btn-fa-dis {
        opacity: .4;
        cursor: not-allowed;
        pointer-events: none;
        background: #f1f5f9;
        color: var(--text-muted);
        border-color: var(--border);
    }

    .ct-highlight {
        background: #fef08a;
        border-radius: 3px;
        padding: 0 2px;
    }

    /* Pagination */
    .ct-pagination {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: .85rem 1.25rem;
        border-top: 1px solid var(--border);
        background: var(--surface-2);
        gap: .75rem;
        flex-wrap: wrap;
    }

    .ct-page-info {
        font-size: .78rem;
        color: var(--text-muted);
    }

    .ct-page-btns {
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
        border-color: var(--brand);
        color: var(--brand);
        background: var(--brand-light);
    }

    .page-btn.active {
        background: var(--brand);
        border-color: var(--brand);
        color: #fff;
        box-shadow: 0 2px 8px rgba(15, 118, 110, .3);
    }

    .page-btn.disabled {
        opacity: .35;
        cursor: not-allowed;
        pointer-events: none;
    }

    .ct-empty {
        text-align: center;
        padding: 3rem 1rem;
        color: var(--text-muted);
    }

    /* Proximos cobro table */
    .cobro-days {
        display: inline-flex;
        align-items: center;
        gap: .25rem;
        padding: .18rem .55rem;
        border-radius: 20px;
        font-size: .73rem;
        font-weight: 700;
    }

    @media(max-width:768px) {

        .ct-table thead th:nth-child(5),
        .ct-table tbody td:nth-child(5),
        .ct-table thead th:nth-child(6),
        .ct-table tbody td:nth-child(6) {
            display: none;
        }
    }
</style>

<div class="ct-page container-xxl">

    <!-- Header -->
    <div class="ct-header">
        <div>
            <h4 class="ct-header-title">📄 Contratos</h4>
            <p class="ct-header-sub">Gestión de contratos de servicio &nbsp;·&nbsp; <?= date('F Y') ?></p>
        </div>
        <a href="crear_contrato" class="btn-new-ct">
            <i class="bi bi-plus-lg"></i> Nuevo Contrato
        </a>
    </div>

    <!-- Stats -->
    <div class="ct-stats">
        <div class="ct-stat">
            <div class="ct-stat-icon teal"><i class="bi bi-file-earmark-text-fill"></i></div>
            <div>
                <div class="ct-stat-val"><?= (int)($kpi['activos'] ?? 0) ?></div>
                <div class="ct-stat-lbl">Activos</div>
            </div>
        </div>
        <div class="ct-stat">
            <div class="ct-stat-icon amber"><i class="bi bi-exclamation-triangle-fill"></i></div>
            <div>
                <div class="ct-stat-val"><?= (int)($kpi['proximos_vencer'] ?? 0) ?></div>
                <div class="ct-stat-lbl">Por vencer 30d</div>
            </div>
        </div>
        <div class="ct-stat">
            <div class="ct-stat-icon red"><i class="bi bi-x-circle-fill"></i></div>
            <div>
                <div class="ct-stat-val"><?= (int)($kpi['vencidos'] ?? 0) ?></div>
                <div class="ct-stat-lbl">Vencidos</div>
            </div>
        </div>
        <div class="ct-stat">
            <div class="ct-stat-icon green"><i class="bi bi-cash-coin"></i></div>
            <div>
                <div class="ct-stat-val" style="font-size:1.05rem;">L
                    <?= number_format((float)($kpi['monto_activo'] ?? 0), 0) ?></div>
                <div class="ct-stat-lbl">MRR</div>
            </div>
        </div>
        <div class="ct-stat" style="<?= $pendientes_mes > 0 ? 'border-color:#fde68a;' : '' ?>">
            <div class="ct-stat-icon <?= $pendientes_mes > 0 ? 'amber' : 'green' ?>"><i
                    class="bi bi-<?= $pendientes_mes > 0 ? 'clock-fill' : 'check-circle-fill' ?>"></i></div>
            <div>
                <div class="ct-stat-val" style="color:<?= $pendientes_mes > 0 ? '#d97706' : '#059669' ?>;">
                    <?= $pendientes_mes ?></div>
                <div class="ct-stat-lbl">Sin facturar este mes</div>
                <?php if ($pendientes_mes > 0): ?><div style="font-size:.7rem;color:#d97706;">L
                        <?= number_format($monto_pendiente, 2) ?></div><?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Próximos cobros (top 10 activos) -->
    <?php
    $proximos = array_filter($contratos, fn($c) => $c['estado'] === 'activo');
    usort($proximos, fn($a, $b) => (int)$a['dias_para_pago'] - (int)$b['dias_para_pago']);
    $proximos = array_slice($proximos, 0, 10);
    ?>
    <?php if (!empty($proximos)): ?>
        <div class="ct-card">
            <div class="ct-card-header">
                <span class="ct-card-title"><i class="bi bi-calendar-check-fill"></i> Próximas Fechas de Cobro —
                    <?= date('F Y') ?></span>
                <span class="ct-result-badge"><?= count($proximos) ?> activos</span>
            </div>
            <div class="ct-table-wrap">
                <table class="ct-table">
                    <thead>
                        <tr>
                            <th>Cliente</th>
                            <th>Servicio</th>
                            <th class="text-end">Monto</th>
                            <th class="text-center">Próximo Cobro</th>
                            <th class="text-center">Días</th>
                            <th class="text-center">Este Mes</th>
                            <th style="cursor:default;text-align:center;">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($proximos as $p):
                            $dias       = (int)$p['dias_para_pago'];
                            $facturado  = (int)$p['facturado_este_mes'] > 0;
                            $noIniciado = (int)$p['no_iniciado'];
                            if ($noIniciado) {
                                $dCls = 'bg-secondary text-white';
                                $dTxt = "En {$dias}d";
                            } elseif ($dias === 0) {
                                $dCls = 'bg-danger text-white';
                                $dTxt = '¡Hoy!';
                            } elseif ($dias <= 3) {
                                $dCls = 'bg-danger text-white';
                                $dTxt = "{$dias}d";
                            } elseif ($dias <= 7) {
                                $dCls = 'bg-warning text-dark';
                                $dTxt = "{$dias}d";
                            } elseif ($dias <= 15) {
                                $dCls = 'bg-info text-white';
                                $dTxt = "{$dias}d";
                            } else {
                                $dCls = 'bg-secondary text-white';
                                $dTxt = "{$dias}d";
                            }
                        ?>
                            <tr <?= $facturado ? 'class="table-success"' : '' ?>>
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($p['receptor_nombre']) ?></div>
                                    <?php if ($p['receptor_tel']): ?><small
                                            class="text-muted"><?= htmlspecialchars($p['receptor_tel']) ?></small><?php endif; ?>
                                </td>
                                <td class="small text-muted"><?= htmlspecialchars($p['producto_nombre']) ?></td>
                                <td class="text-end fw-bold">L <?= number_format((float)$p['monto'], 2) ?></td>
                                <td class="text-center">
                                    <div class="fw-semibold small"><?= htmlspecialchars($p['proxima_fecha_pago']) ?></div>
                                    <small class="text-muted">Día <?= (int)$p['dia_pago'] ?> c/mes</small>
                                    <?php if ($noIniciado): ?><br><span class="badge bg-secondary small">Inicia
                                            <?= $p['fecha_inicio'] ?></span><?php endif; ?>
                                </td>
                                <td class="text-center"><span class="badge <?= $dCls ?>"><?= $dTxt ?></span></td>
                                <td class="text-center">
                                    <?php if ($noIniciado): ?>
                                        <span class="badge bg-secondary small">No iniciado</span>
                                    <?php elseif ($facturado): ?>
                                        <span class="fact-pill fact-si"><i class="bi bi-check-circle-fill"></i> Facturado</span>
                                    <?php else: ?>
                                        <span class="fact-pill fact-no"><i class="bi bi-clock-fill"></i> Pendiente</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($facturado): ?>
                                        <a href="facturas_contrato?contrato_id=<?= $p['id'] ?>" class="btn-fa btn-fa-receipt"><i
                                                class="bi bi-receipt"></i> Ver</a>
                                    <?php elseif ($noIniciado): ?>
                                        <span class="btn-fa btn-fa-dis"><i class="bi bi-lock-fill"></i></span>
                                    <?php else: ?>
                                        <?php if (($p['tipo_contrato'] ?? '') === 'sin_factura'): ?>
                                            <a href="generar_recibo?contrato_id=<?= $p['id'] ?>" class="btn-fa btn-fa-receipt">
                                                <i class="bi bi-receipt"></i> Recibo
                                            </a>
                                        <?php else: ?>
                                            <a href="generar_factura?receptor_id=<?= $p['receptor_id'] ?>&producto_id=<?= $p['producto_id'] ?>&monto=<?= $p['monto'] ?>&contrato_id=<?= $p['id'] ?>"
                                                class="btn-fa btn-fa-facturar">
                                                <i class="bi bi-file-earmark-plus"></i> Facturar
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- Todos los contratos -->
    <div class="ct-toolbar">
        <div class="ct-search-wrap">
            <i class="bi bi-search"></i>
            <input type="text" id="ctSearch" class="ct-search" placeholder="Buscar por cliente, servicio, estado…"
                autocomplete="off">
            <button class="ct-clear-btn" id="ctClear"><i class="bi bi-x-lg"></i></button>
        </div>
        <select class="ct-per-page" id="ctPerPage">
            <option value="10" selected>10/pág</option>
            <option value="25">25/pág</option>
            <option value="50">50/pág</option>
        </select>
        <span class="ct-result-badge" id="ctBadge"><?= $total_contratos ?> contratos</span>
    </div>

    <div class="ct-card">
        <div class="ct-card-header">
            <span class="ct-card-title"><i class="bi bi-table"></i> Todos los Contratos</span>
        </div>
        <div class="ct-table-wrap">
            <table class="ct-table" id="ctTable">
                <thead>
                    <tr>
                        <th data-col="0"><i class="bi bi-person me-1"></i>Cliente<i
                                class="bi bi-arrow-up sort-icon"></i></th>
                        <th data-col="1"><i class="bi bi-box me-1"></i>Servicio<i class="bi bi-arrow-up sort-icon"></i>
                        </th>
                        <th data-col="2"><i class="bi bi-cash me-1"></i>Monto<i class="bi bi-arrow-up sort-icon"></i>
                        </th>
                        <th data-col="3" class="text-center">Este Mes</th>
                        <th data-col="4"><i class="bi bi-calendar3 me-1"></i>Próx. Cobro<i
                                class="bi bi-arrow-up sort-icon"></i></th>
                        <th data-col="5">Fecha Fin<i class="bi bi-arrow-up sort-icon"></i></th>
                        <th data-col="6">Estado<i class="bi bi-arrow-up sort-icon"></i></th>
                        <th style="cursor:default;">Acciones</th>
                    </tr>
                </thead>
                <tbody id="ctBody">
                    <?php foreach ($contratos as $c):
                        $rowCls = match ($c['alerta'] ?? '') {
                            'critico' => 'row-critico',
                            'proximo' => 'row-proximo',
                            'vencido' => 'row-vencido',
                            default => ''
                        };
                        $facturado  = (int)($c['facturado_este_mes'] ?? 0) > 0;
                        $noIniciado = (int)($c['no_iniciado'] ?? 0);
                        $nFact      = (int)($c['total_facturas_contrato'] ?? 0);
                        $stateCls   = ['activo' => 'st-activo', 'vencido' => 'st-vencido', 'cancelado' => 'st-cancelado', 'pausado' => 'st-pausado'];
                        $diasPago   = isset($c['dias_para_pago']) ? (int)$c['dias_para_pago'] : null;
                        $searchStr  = strtolower($c['receptor_nombre'] . ' ' . $c['producto_nombre'] . ' ' . $c['estado']);
                    ?>
                        <tr class="<?= $rowCls ?>" data-search="<?= htmlspecialchars($searchStr) ?>">
                            <td>
                                <div class="fw-semibold" data-col="cliente"><?= htmlspecialchars($c['receptor_nombre']) ?>
                                </div>
                                <?php if ($c['receptor_rtn']): ?><small class="text-muted">RTN:
                                        <?= htmlspecialchars($c['receptor_rtn']) ?></small><?php endif; ?>
                            </td>
                            <td data-col="servicio">
                                <div><?= htmlspecialchars($c['producto_nombre']) ?></div>
                                <?php if ($nFact > 0): ?><small class="text-muted"><?= $nFact ?> factura(s) · L
                                        <?= number_format((float)$c['total_monto_contrato'], 2) ?></small><?php endif; ?>
                            </td>
                            <td data-sort-val="<?= $c['monto'] ?>"><strong>L
                                    <?= number_format((float)$c['monto'], 2) ?></strong></td>
                            <td class="text-center">
                                <?php if ($c['estado'] !== 'activo'): ?>
                                    <span class="text-muted">—</span>
                                <?php elseif ($noIniciado): ?>
                                    <span class="badge bg-secondary small">No iniciado</span>
                                <?php elseif ($facturado): ?>
                                    <span class="fact-pill fact-si"><i class="bi bi-check-circle-fill"></i> Facturado</span>
                                <?php else: ?>
                                    <span class="fact-pill fact-no"><i class="bi bi-clock-fill"></i> Pendiente</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($c['estado'] === 'activo' && $diasPago !== null): ?>
                                    <div class="fw-semibold small"><?= htmlspecialchars($c['proxima_fecha_pago']) ?></div>
                                    <?php if ($noIniciado): ?>
                                        <small class="text-muted">Primer cobro</small>
                                    <?php else:
                                        if ($diasPago === 0) {
                                            $t = '¡Hoy!';
                                            $cls = 'text-danger fw-bold';
                                        } elseif ($diasPago <= 3) {
                                            $t = "En {$diasPago}d ⚠";
                                            $cls = 'text-danger';
                                        } elseif ($diasPago <= 7) {
                                            $t = "En {$diasPago}d";
                                            $cls = 'text-warning fw-semibold';
                                        } else {
                                            $t = "En {$diasPago}d";
                                            $cls = 'text-muted';
                                        }
                                    ?><small class="<?= $cls ?>"><?= $t ?></small><?php endif; ?>
                                <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($c['fecha_fin']): ?>
                                    <?= htmlspecialchars($c['fecha_fin']) ?>
                                    <?php if (in_array($c['alerta'], ['critico', 'proximo']) && $c['dias_restantes'] >= 0): ?>
                                        <br><small
                                            class="<?= $c['alerta'] === 'critico' ? 'text-danger fw-bold' : 'text-warning fw-semibold' ?>">⏰
                                            <?= (int)$c['dias_restantes'] ?> día(s)</small>
                                    <?php endif; ?>
                                <?php else: ?><span class="badge bg-info text-white">Indefinido</span><?php endif; ?>
                            </td>
                            <td><span
                                    class="st-pill <?= $stateCls[$c['estado']] ?? 'st-cancelado' ?>"><?= ucfirst($c['estado']) ?></span>
                            </td>
                            <td>
                                <div class="ct-actions">
                                    <?php if ($nFact > 0): ?>
                                        <a href="facturas_contrato?contrato_id=<?= $c['id'] ?>" class="btn-fa btn-fa-receipt"
                                            title="<?= $nFact ?> factura(s)">
                                            <i class="bi bi-receipt"></i>
                                            <span class="badge bg-info ms-1" style="font-size:.68rem;"><?= $nFact ?></span>
                                        </a>
                                    <?php endif; ?>
                                    <a href="editar_contrato?id=<?= $c['id'] ?>" class="btn-fa btn-fa-edit" title="Editar">
                                        <i class="bi bi-pencil-fill"></i>
                                    </a>
                                    <?php if ($c['estado'] === 'activo'): ?>
                                        <?php if ($noIniciado || $facturado): ?>
                                            <span class="btn-fa btn-fa-dis"
                                                title="<?= $noIniciado ? 'No iniciado' : 'Ya facturado este mes' ?>"><i
                                                    class="bi bi-file-earmark-plus"></i></span>
                                        <?php else: ?>
                                            <?php if ($tipo_ct === 'sin_factura'): ?>
                                                <a href="generar_recibo?contrato_id=<?= $c['id'] ?>" class="btn-fa btn-fa-receipt"
                                                    title="Crear Recibo">
                                                    <i class="bi bi-receipt"></i>
                                                </a>
                                            <?php else: ?>
                                                <a href="generar_factura?receptor_id=<?= $c['receptor_id'] ?>&producto_id=<?= $c['producto_id'] ?>&monto=<?= $c['monto'] ?>&contrato_id=<?= $c['id'] ?>"
                                                    class="btn-fa btn-fa-facturar" title="Crear Factura">
                                                    <i class="bi bi-file-earmark-plus"></i>
                                                </a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if (!in_array($c['estado'], ['cancelado', 'vencido'])): ?>
                                        <button class="btn-fa btn-fa-cancel btn-cancelar" data-id="<?= $c['id'] ?>"
                                            data-nombre="<?= htmlspecialchars($c['receptor_nombre']) ?>" title="Cancelar">
                                            <i class="bi bi-ban"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="ct-empty" id="ctEmpty" style="display:none;">
                <div style="font-size:2.5rem;opacity:.3;margin-bottom:.7rem;"><i class="bi bi-file-earmark-x"></i></div>
                <div style="font-weight:600;">Sin contratos</div>
                <div id="ctEmptySub" style="font-size:.85rem;margin-top:.3rem;"></div>
            </div>
        </div>
        <div class="ct-pagination">
            <span class="ct-page-info" id="ctPageInfo"></span>
            <div class="ct-page-btns" id="ctPageBtns"></div>
        </div>
    </div>
</div>

<script>
    /* ── Table engine ── */
    (() => {
        let query = '',
            page = 1,
            perPage = 10,
            sortCol = -1,
            sortDir = 'asc';
        const allRows = Array.from(document.querySelectorAll('#ctBody tr'));
        const $s = document.getElementById('ctSearch'),
            $cl = document.getElementById('ctClear'),
            $pp = document.getElementById('ctPerPage');
        const $empty = document.getElementById('ctEmpty'),
            $sub = document.getElementById('ctEmptySub');
        const $info = document.getElementById('ctPageInfo'),
            $btns = document.getElementById('ctPageBtns'),
            $badge = document.getElementById('ctBadge');
        const headers = document.querySelectorAll('#ctTable thead th[data-col]');

        function hl(t, q) {
            if (!q) return t;
            return t.replace(new RegExp(`(${q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')})`, `gi`),
                '<mark class="ct-highlight">$1</mark>');
        }

        function colTxt(r, i) {
            const td = r.querySelectorAll('td')[i];
            return td ? (td.dataset.original || td.getAttribute('data-sort-val') || td.textContent).trim()
                .toLowerCase() : '';
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
                $sub.textContent = query ? `Sin resultados para "${query}".` : 'No hay contratos.';
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
            $badge.textContent = `${total} contrato${total!==1?'s':''}`;
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

    /* ── Cancelar contrato ── */
    document.querySelectorAll('.btn-cancelar').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id,
                nombre = btn.dataset.nombre;
            Swal.fire({
                title: '¿Cancelar contrato?',
                html: `Se cancelará el contrato de <strong>${nombre}</strong>.<br><span class="text-danger small">Esta acción no se puede deshacer.</span>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                confirmButtonText: '<i class="bi bi-ban me-1"></i>Sí, cancelar',
                cancelButtonText: 'No, volver',
                reverseButtons: true
            }).then(r => {
                if (!r.isConfirmed) return;
                const fd = new FormData();
                fd.append('id', id);
                fetch('includes/contrato_cancelar.php', {
                        method: 'POST',
                        body: fd
                    })
                    .then(res => res.json())
                    .then(d => {
                        if (d.ok) Swal.fire({
                            icon: 'success',
                            title: 'Cancelado',
                            timer: 1400,
                            showConfirmButton: false
                        }).then(() => location.reload());
                        else Swal.fire('Error', d.msg || 'No se pudo cancelar.', 'error');
                    });
            });
        });
    });
</script>

<?php require_once '../../includes/templates/footer.php'; ?>