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
           (SELECT COUNT(*) FROM facturas f WHERE f.contrato_id=c.id AND f.cliente_id=c.cliente_id AND f.estado='emitida' AND MONTH(f.fecha_emision)=MONTH(CURDATE()) AND YEAR(f.fecha_emision)=YEAR(CURDATE())) AS facturado_este_mes,
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

// ── Meses ya facturados por contrato (para detectar atrasos) ──────────────────
$stmtBilled = $pdo->prepare("
    SELECT contrato_id,
           COALESCE(periodo_mes,  MONTH(fecha_emision)) AS mes,
           COALESCE(periodo_anio, YEAR(fecha_emision))  AS anio,
           id          AS factura_id,
           correlativo
    FROM facturas
    WHERE cliente_id = ? AND estado = 'emitida' AND contrato_id IS NOT NULL
    ORDER BY fecha_emision ASC
");
$stmtBilled->execute([$cliente_id]);
$meses_facturados = []; // [contrato_id => ['2026-3' => true, ...]]
foreach ($stmtBilled->fetchAll(PDO::FETCH_ASSOC) as $b) {
    $meses_facturados[(int)$b['contrato_id']][$b['anio'] . '-' . $b['mes']] = true;
}

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

// ── Calcular períodos atrasados por contrato ──────────────────────────────────
foreach ($contratos as &$c) {
    $c['periodos_pendientes'] = [];
    if ($c['estado'] !== 'activo' || (int)$c['no_iniciado']) continue;
    // sin_factura usa recibos, no facturas → no aplica esta lógica
    if (($c['tipo_contrato'] ?? 'estandar') === 'sin_factura') continue;

    $fecha_inicio = new DateTime($c['fecha_inicio']);
    $tipo         = $c['tipo_contrato'] ?? 'estandar';
    $frecuencia   = max(1, (int)($c['frecuencia_meses'] ?? 1));

    // Mes inicio de ciclo (para periódico/rotativo)
    $mes_ciclo_inicio  = (int)($c['mes_inicio_ciclo'] ?? (int)$fecha_inicio->format('n'));
    $anio_ciclo_inicio = (int)$fecha_inicio->format('Y');

    // Recorrer desde el mes de inicio hasta el mes ANTERIOR al actual (ya cerrados)
    $cursor = clone $fecha_inicio;
    $cursor->modify('first day of this month');
    $limite = new DateTime('first day of this month');
    $limite->setTime(0, 0, 0); // forzar medianoche — evita que la hora actual haga entrar el mes actual

    $facturados = $meses_facturados[(int)$c['id']] ?? [];

    while ($cursor < $limite) {
        $mes  = (int)$cursor->format('n');
        $anio = (int)$cursor->format('Y');
        $key  = $anio . '-' . $mes;

        // ¿Debía cobrarse este mes según el tipo?
        $debe = false;
        if ($tipo === 'estandar') {
            $debe = true;
        } elseif ($tipo === 'rotativo') {
            // Rotativo: cada mes hay un cobro (le toca a un cliente distinto del ciclo)
            // frecuencia_meses indica cada cuántos meses le vuelve a tocar al MISMO cliente,
            // pero el contrato en sí factura TODOS los meses
            $debe = true;
        } elseif ($tipo === 'periodico') {
            $offset = ($anio - $anio_ciclo_inicio) * 12 + ($mes - $mes_ciclo_inicio);
            if ($offset >= 0 && ($offset % $frecuencia) === 0) $debe = true;
        }

        if ($debe && !isset($facturados[$key])) {
            $c['periodos_pendientes'][] = [
                'mes'   => $mes,
                'anio'  => $anio,
                'label' => $meses_es[$mes] . ' ' . $anio,
                'key'   => $key,
            ];
        }
        $cursor->modify('+1 month');
    }
}
unset($c);

// ── KPIs extra ────────────────────────────────────────────────────────────────
$pendientes_mes    = 0;
$monto_pendiente   = 0;
$total_atrasados   = 0;
foreach ($contratos as $c) {
    if ($c['estado'] === 'activo' && !(int)$c['no_iniciado'] && !(int)$c['facturado_este_mes']) {
        $pendientes_mes++;
        $monto_pendiente += (float)$c['monto'];
    }
    $total_atrasados += count($c['periodos_pendientes'] ?? []);
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

    .ct-stat-icon.orange {
        background: #fff7ed;
        color: #ea580c;
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

    .ct-table tbody tr.row-atrasado td {
        background: #fff7ed;
    }

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

    .tipo-pill {
        display: inline-flex;
        align-items: center;
        gap: .2rem;
        padding: .12rem .45rem;
        border-radius: 20px;
        font-size: .65rem;
        font-weight: 600;
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

    .fact-atrasado {
        background: #fff7ed;
        color: #ea580c;
        border: 1px solid #fed7aa;
        cursor: pointer;
    }

    .fact-atrasado:hover {
        background: #ea580c;
        color: #fff;
        border-color: #ea580c;
    }

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

    .btn-fa-regularizar {
        background: #fff7ed;
        color: #ea580c;
        border-color: rgba(234, 88, 12, .25);
    }

    .btn-fa-regularizar:hover {
        background: #ea580c;
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

    /* Modal de regularización */
    .reg-periodo-item {
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: .85rem 1rem;
        margin-bottom: .6rem;
        background: #f8fafc;
        transition: border-color .15s;
    }

    .reg-periodo-item:hover {
        border-color: #94a3b8;
    }

    .reg-periodo-label {
        font-weight: 700;
        color: #1e293b;
        font-size: .88rem;
    }

    .reg-periodo-actions {
        display: flex;
        gap: .5rem;
        flex-wrap: wrap;
        margin-top: .5rem;
    }

    /* Submodal facturas */
    .fact-search-item {
        padding: .5rem .75rem;
        border-radius: 8px;
        cursor: pointer;
        border: 1.5px solid #e2e8f0;
        margin-bottom: .4rem;
        transition: all .15s;
        font-size: .82rem;
    }

    .fact-search-item:hover {
        border-color: #0f766e;
        background: #f0fdf4;
    }

    .fact-search-item.selected {
        border-color: #0f766e;
        background: #ccfbf1;
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
            <div class="ct-stat-icon <?= $pendientes_mes > 0 ? 'amber' : 'green' ?>">
                <i class="bi bi-<?= $pendientes_mes > 0 ? 'clock-fill' : 'check-circle-fill' ?>"></i>
            </div>
            <div>
                <div class="ct-stat-val" style="color:<?= $pendientes_mes > 0 ? '#d97706' : '#059669' ?>;">
                    <?= $pendientes_mes ?></div>
                <div class="ct-stat-lbl">Sin facturar este mes</div>
                <?php if ($pendientes_mes > 0): ?><div style="font-size:.7rem;color:#d97706;">L
                        <?= number_format($monto_pendiente, 2) ?></div><?php endif; ?>
            </div>
        </div>
        <?php if ($total_atrasados > 0): ?>
            <div class="ct-stat" style="border-color:#fed7aa">
                <div class="ct-stat-icon orange"><i class="bi bi-clock-history"></i></div>
                <div>
                    <div class="ct-stat-val" style="color:#ea580c;"><?= $total_atrasados ?></div>
                    <div class="ct-stat-lbl">Períodos atrasados</div>
                    <div style="font-size:.7rem;color:#ea580c;">Meses sin cobrar</div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Alerta global de atrasos -->
    <?php if ($total_atrasados > 0):
        $contratos_atrasados = array_filter($contratos, fn($c) => !empty($c['periodos_pendientes']));
    ?>
        <div class="alert d-flex align-items-center gap-3 mb-4"
            style="background:#fff7ed;border:1px solid #fed7aa;color:#7c2d12;border-radius:12px;padding:1rem 1.25rem">
            <i class="bi bi-clock-history" style="font-size:1.3rem;flex-shrink:0;color:#ea580c"></i>
            <div>
                <strong><?= count($contratos_atrasados) ?> contrato(s) tienen <?= $total_atrasados ?> período(s) sin
                    cobrar.</strong>
                Usa el botón <span
                    style="background:#fff7ed;border:1px solid #fed7aa;color:#ea580c;padding:1px 8px;border-radius:6px;font-size:.8rem;font-weight:600"><i
                        class="bi bi-clock-history me-1"></i>Regularizar</span>
                en cada contrato para crear las facturas atrasadas o vincular pagos ya realizados.
            </div>
        </div>
    <?php endif; ?>

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
                            <th class="text-center">Estado Cobro</th>
                            <th style="cursor:default;text-align:center;">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($proximos as $p):
                            $dias       = (int)$p['dias_para_pago'];
                            $facturado  = (int)$p['facturado_este_mes'] > 0;
                            $noIniciado = (int)$p['no_iniciado'];
                            $atrasados  = count($p['periodos_pendientes'] ?? []);
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
                            <tr <?= ($atrasados > 0) ? 'class="table-warning"' : ($facturado ? 'class="table-success"' : '') ?>>
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
                                    <?php if ($atrasados > 0): ?>
                                        <span class="fact-pill fact-atrasado btn-abrir-regularizar"
                                            data-contrato='<?= htmlspecialchars(json_encode([
                                                                'id'          => $p['id'],
                                                                'nombre'      => $p['nombre_contrato'],
                                                                'receptor'    => $p['receptor_nombre'],
                                                                'receptor_id' => $p['receptor_id'],
                                                                'producto_id' => $p['producto_id'],
                                                                'monto'       => $p['monto'],
                                                                'periodos'    => $p['periodos_pendientes'],
                                                            ])) ?>'>
                                            <i class="bi bi-clock-history"></i> <?= $atrasados ?> atrasado(s)
                                        </span>
                                    <?php elseif ($noIniciado): ?>
                                        <span class="badge bg-secondary small">No iniciado</span>
                                    <?php elseif ($facturado): ?>
                                        <span class="fact-pill fact-si"><i class="bi bi-check-circle-fill"></i> Facturado</span>
                                    <?php else: ?>
                                        <span class="fact-pill fact-no"><i class="bi bi-clock-fill"></i> Pendiente</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($atrasados > 0): ?>
                                        <button class="btn-fa btn-fa-regularizar btn-abrir-regularizar"
                                            data-contrato='<?= htmlspecialchars(json_encode([
                                                                'id'          => $p['id'],
                                                                'nombre'      => $p['nombre_contrato'],
                                                                'receptor'    => $p['receptor_nombre'],
                                                                'receptor_id' => $p['receptor_id'],
                                                                'producto_id' => $p['producto_id'],
                                                                'monto'       => $p['monto'],
                                                                'periodos'    => $p['periodos_pendientes'],
                                                            ])) ?>'>
                                            <i class="bi bi-clock-history"></i> Regularizar
                                        </button>
                                    <?php elseif ($facturado): ?>
                                        <a href="facturas_contrato?contrato_id=<?= $p['id'] ?>" class="btn-fa btn-fa-receipt"><i
                                                class="bi bi-receipt"></i> Ver</a>
                                    <?php elseif ($noIniciado): ?>
                                        <span class="btn-fa btn-fa-dis"><i class="bi bi-lock-fill"></i></span>
                                    <?php else: ?>
                                        <a href="generar_factura?receptor_id=<?= $p['receptor_id'] ?>&producto_id=<?= $p['producto_id'] ?>&monto=<?= $p['monto'] ?>&contrato_id=<?= $p['id'] ?>"
                                            class="btn-fa btn-fa-facturar">
                                            <i class="bi bi-file-earmark-plus"></i> Facturar
                                        </a>
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
                        <th data-col="3" class="text-center">Estado Cobro</th>
                        <th data-col="4"><i class="bi bi-calendar3 me-1"></i>Próx. Cobro<i
                                class="bi bi-arrow-up sort-icon"></i></th>
                        <th data-col="5">Fecha Fin<i class="bi bi-arrow-up sort-icon"></i></th>
                        <th data-col="6">Estado<i class="bi bi-arrow-up sort-icon"></i></th>
                        <th style="cursor:default;">Acciones</th>
                    </tr>
                </thead>
                <tbody id="ctBody">
                    <?php foreach ($contratos as $c):
                        $atrasados  = count($c['periodos_pendientes'] ?? []);
                        $rowCls = $atrasados > 0 ? 'row-atrasado' : match ($c['alerta'] ?? '') {
                            'critico' => 'row-critico',
                            'proximo' => 'row-proximo',
                            'vencido' => 'row-vencido',
                            default   => ''
                        };
                        $facturado  = (int)($c['facturado_este_mes'] ?? 0) > 0;
                        $noIniciado = (int)($c['no_iniciado'] ?? 0);
                        $nFact      = (int)($c['total_facturas_contrato'] ?? 0);
                        $tipo_ct    = $c['tipo_contrato'] ?? 'estandar';
                        $stateCls   = ['activo' => 'st-activo', 'vencido' => 'st-vencido', 'cancelado' => 'st-cancelado', 'pausado' => 'st-pausado'];
                        $tipoCls    = ['estandar' => 'tp-estandar', 'periodico' => 'tp-periodico', 'rotativo' => 'tp-rotativo', 'sin_factura' => 'tp-sin_factura'];
                        $tipoLabel  = ['estandar' => 'Estándar', 'periodico' => 'Periódico', 'rotativo' => 'Rotativo', 'sin_factura' => 'Sin factura'];
                        $diasPago   = isset($c['dias_para_pago']) ? (int)$c['dias_para_pago'] : null;
                        $searchStr  = strtolower($c['receptor_nombre'] . ' ' . $c['producto_nombre'] . ' ' . $c['estado'] . ' ' . $tipo_ct);
                        $contratoData = json_encode([
                            'id'          => $c['id'],
                            'nombre'      => $c['nombre_contrato'],
                            'receptor'    => $c['receptor_nombre'],
                            'receptor_id' => $c['receptor_id'],
                            'producto_id' => $c['producto_id'],
                            'monto'       => $c['monto'],
                            'periodos'    => $c['periodos_pendientes'],
                        ]);
                    ?>
                        <tr class="<?= $rowCls ?>" data-search="<?= htmlspecialchars($searchStr) ?>">
                            <td>
                                <div class="fw-semibold" data-col="cliente"><?= htmlspecialchars($c['receptor_nombre']) ?>
                                </div>
                                <?php if ($c['receptor_rtn']): ?><small class="text-muted">RTN:
                                        <?= htmlspecialchars($c['receptor_rtn']) ?></small><?php endif; ?>
                                <br><span
                                    class="tipo-pill <?= $tipoCls[$tipo_ct] ?? 'tp-estandar' ?>"><?= $tipoLabel[$tipo_ct] ?? $tipo_ct ?></span>
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
                                <?php elseif ($atrasados > 0): ?>
                                    <span class="fact-pill fact-atrasado btn-abrir-regularizar" style="cursor:pointer"
                                        data-contrato='<?= htmlspecialchars($contratoData) ?>'>
                                        <i class="bi bi-clock-history"></i> <?= $atrasados ?>
                                        mes<?= $atrasados > 1 ? 'es' : '' ?> atrasado<?= $atrasados > 1 ? 's' : '' ?>
                                    </span>
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
                                        <?php if ($atrasados > 0): ?>
                                            <button class="btn-fa btn-fa-regularizar btn-abrir-regularizar"
                                                data-contrato='<?= htmlspecialchars($contratoData) ?>'
                                                title="<?= $atrasados ?> período(s) sin facturar">
                                                <i class="bi bi-clock-history"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($noIniciado || $facturado): ?>
                                            <span class="btn-fa btn-fa-dis"
                                                title="<?= $noIniciado ? 'No iniciado' : 'Ya facturado este mes' ?>">
                                                <i class="bi bi-file-earmark-plus"></i>
                                            </span>
                                        <?php else: ?>
                                            <a href="generar_factura?receptor_id=<?= $c['receptor_id'] ?>&producto_id=<?= $c['producto_id'] ?>&monto=<?= $c['monto'] ?>&contrato_id=<?= $c['id'] ?>"
                                                class="btn-fa btn-fa-facturar" title="Crear Factura">
                                                <i class="bi bi-file-earmark-plus"></i>
                                            </a>
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

<!-- ══════════════════════════════════════════════════════════════════════
     MODAL: Regularizar períodos atrasados
══════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalRegularizar" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow" style="border-radius:16px;overflow:hidden">
            <div class="modal-header" style="background:linear-gradient(135deg,#ea580c,#c2410c);color:#fff;border:none">
                <div>
                    <h5 class="modal-title fw-bold mb-0">
                        <i class="bi bi-clock-history me-2"></i>Regularizar Períodos Atrasados
                    </h5>
                    <small id="reg-sub" style="opacity:.85"></small>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="alert alert-warning py-2 mb-3" style="font-size:.83rem">
                    <i class="bi bi-info-circle me-1"></i>
                    Por cada período sin cobrar tienes dos opciones:
                    <strong>Crear factura nueva</strong> con la fecha del mes correspondiente, o
                    <strong>Vincular factura libre</strong> si el pago ya fue emitido pero sin contrato asignado.
                    <span class="d-block mt-1 text-muted" style="font-size:.78rem">
                        <i class="bi bi-info-circle me-1"></i>Al vincular no se modifica la fecha de la factura.
                        La factura debe pertenecer solo a este receptor y no estar ya vinculada a otro contrato.
                    </span>
                </div>
                <div id="reg-periodos-wrap">
                    <!-- Se llena dinámicamente -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     MODAL: Buscar y vincular factura existente
══════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalVincular" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow" style="border-radius:16px;overflow:hidden">
            <div class="modal-header border-0 pb-0">
                <div>
                    <h5 class="modal-title fw-bold mb-0">
                        <i class="bi bi-link-45deg me-2 text-success"></i>Vincular Factura Existente
                    </h5>
                    <small class="text-muted" id="vinc-sub"></small>
                </div>
                <button type="button" class="btn-close" id="btnVincularBack" title="Volver"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-2">
                    <div class="d-flex gap-2 align-items-center p-2 rounded-3 mb-2"
                        style="background:#eff6ff;border:1px solid #bfdbfe;font-size:.82rem;color:#1e40af">
                        <i class="bi bi-calendar2-check-fill"></i>
                        <span>Esta factura cubrirá el período: <strong id="vincPeriodoLbl"></strong></span>
                        <a href="#" id="vincCambiarPeriodo" class="ms-auto text-info" style="font-size:.78rem">Cambiar
                            período</a>
                    </div>
                    <div id="vincCambiarPeriodoWrap" class="d-none mb-2">
                        <label class="form-label small fw-semibold mb-1">Asignar al período:</label>
                        <div class="d-flex gap-2">
                            <select id="vincPeriodoMes" class="form-select form-select-sm" style="flex:1">
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
                            <select id="vincPeriodoAnio" class="form-select form-select-sm" style="width:110px">
                                <?php for ($y = date('Y') - 3; $y <= date('Y'); $y++): ?>
                                    <option value="<?= $y ?>"><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Buscar por número de factura o nombre</label>
                    <input type="text" id="vincBuscar" class="form-control form-control-sm"
                        placeholder="Ej: 001-001-01-00000001 o nombre del cliente…">
                </div>
                <div id="vincListado" style="max-height:300px;overflow-y:auto">
                    <div class="text-center py-4 text-muted">
                        <div class="spinner-border spinner-border-sm me-2"></div> Cargando facturas…
                    </div>
                </div>
                <div id="vincSeleccionada" class="d-none mt-3 p-3 rounded-3"
                    style="background:#f0fdf4;border:1px solid #a7f3d0">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <i class="bi bi-check-circle-fill text-success"></i>
                                <span id="vincDetCorr" class="fw-bold text-success"
                                    style="font-family:monospace;font-size:.83rem"></span>
                                <span id="vincDetPeriodo" class="badge"
                                    style="background:#dbeafe;color:#1e40af;font-size:.63rem"></span>
                            </div>
                            <div class="text-muted small" id="vincDetFecha"></div>
                            <div id="vincDetReceptor" style="font-size:.82rem"></div>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold" style="color:#0f766e;font-size:1.05rem" id="vincDetTotal"></div>
                            <small class="text-muted d-block" id="vincDetSub"></small>
                            <small class="text-muted d-block" id="vincDetIsv"></small>
                        </div>
                    </div>
                    <div class="mt-2 pt-2 border-top d-none" id="vincDetNotas" style="font-size:.77rem;color:#64748b">
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btnVincularBack2">
                        <i class="bi bi-arrow-left me-1"></i>Volver
                    </button>
                    <button type="button" class="btn btn-success btn-sm" id="btnVincularConfirmar" disabled>
                        <i class="bi bi-link-45deg me-1"></i>Vincular a este período
                    </button>
                </div>
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

        /* ══ MODAL REGULARIZAR ═══════════════════════════════════════════ */
        let contratoActual = null;
        let periodoActual = null;
        let facturaSelId = null;

        // Datos del contrato activo en el modal de vínculo
        function abrirRegularizar(data) {
            contratoActual = data;
            document.getElementById('reg-sub').textContent =
                `${data.nombre} — ${data.receptor}`;

            const wrap = document.getElementById('reg-periodos-wrap');
            wrap.innerHTML = '';

            if (!data.periodos || data.periodos.length === 0) {
                wrap.innerHTML = '<p class="text-muted text-center py-3">No hay períodos atrasados.</p>';
                return;
            }

            data.periodos.forEach((p, i) => {
                const div = document.createElement('div');
                div.className = 'reg-periodo-item';
                div.innerHTML = `
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="reg-periodo-label">
                            <i class="bi bi-calendar2-x me-1 text-warning"></i>${p.label}
                        </span>
                        <small class="text-muted ms-2">Monto esperado: <strong>L ${parseFloat(data.monto).toLocaleString('es-HN',{minimumFractionDigits:2})}</strong></small>
                    </div>
                    <span class="badge" style="background:#fff7ed;color:#ea580c;font-size:.7rem">Período sin cobrar</span>
                </div>
                <div class="reg-periodo-actions">
                    <a href="generar_factura?receptor_id=${data.receptor_id}&producto_id=${data.producto_id}&monto=${data.monto}&contrato_id=${data.id}&periodo_mes=${p.mes}&periodo_anio=${p.anio}"
                       class="btn btn-sm btn-success">
                        <i class="bi bi-file-earmark-plus me-1"></i>Crear factura para ${p.label}
                    </a>
                    <button class="btn btn-sm btn-outline-secondary btn-vincular-periodo"
                            data-idx="${i}"
                            data-label="${p.label}" data-mes="${p.mes}" data-anio="${p.anio}">
                        <i class="bi bi-link-45deg me-1"></i>Vincular a factura existente
                    </button>
                </div>`;
                wrap.appendChild(div);
            });

            // Eventos de vincular
            wrap.querySelectorAll('.btn-vincular-periodo').forEach(btn => {
                btn.addEventListener('click', () => {
                    periodoActual = {
                        idx: parseInt(btn.dataset.idx),
                        label: btn.dataset.label,
                        mes: parseInt(btn.dataset.mes),
                        anio: parseInt(btn.dataset.anio),
                    };
                    abrirVincular();
                });
            });

            const modal = new bootstrap.Modal(document.getElementById('modalRegularizar'));
            modal.show();
        }

        document.querySelectorAll('.btn-abrir-regularizar').forEach(btn => {
            btn.addEventListener('click', () => {
                try {
                    const data = JSON.parse(btn.dataset.contrato);
                    abrirRegularizar(data);
                } catch (e) {
                    console.error(e);
                }
            });
        });

        /* ══ MODAL VINCULAR FACTURA ══════════════════════════════════════ */
        let todasFacturas = [];

        function abrirVincular() {
            document.getElementById('vinc-sub').textContent =
                `Período: ${periodoActual.label} — ${contratoActual.receptor}`;
            document.getElementById('vincBuscar').value = '';
            document.getElementById('vincSeleccionada').classList.add('d-none');
            document.getElementById('btnVincularConfirmar').disabled = true;
            document.getElementById('vincCambiarPeriodoWrap').classList.add('d-none');
            document.getElementById('vincPeriodoMes').value = periodoActual.mes;
            document.getElementById('vincPeriodoAnio').value = periodoActual.anio;
            document.getElementById('vincPeriodoLbl').textContent = periodoActual.label;
            facturaSelId = null;

            // Cerrar modal regularizar, abrir vincular
            bootstrap.Modal.getInstance(document.getElementById('modalRegularizar'))?.hide();
            setTimeout(() => {
                const modal = new bootstrap.Modal(document.getElementById('modalVincular'));
                modal.show();
                cargarFacturasDisponibles();
            }, 350);
        }

        function cargarFacturasDisponibles() {
            const wrap = document.getElementById('vincListado');
            wrap.innerHTML =
                '<div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm me-2"></div> Cargando…</div>';

            fetch(
                    `includes/facturas_sin_contrato.php?receptor_id=${contratoActual.receptor_id}&contrato_id=${contratoActual.id}`
                )
                .then(r => r.json())
                .then(data => {
                    todasFacturas = data;
                    renderFacturas(data);
                })
                .catch(() => {
                    wrap.innerHTML = '<p class="text-danger text-center py-3">Error al cargar facturas.</p>';
                });
        }

        const mesesNombres = ['', 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

        function renderFacturas(lista) {
            const wrap = document.getElementById('vincListado');
            if (lista.length === 0) {
                wrap.innerHTML =
                    '<p class="text-muted text-center py-3">No se encontraron facturas disponibles para este cliente.<br><small>Solo se muestran facturas sin contrato asignado o de este contrato.</small></p>';
                return;
            }
            wrap.innerHTML = '';
            const mesesNombres = ['', 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
            lista.forEach(f => {
                const div = document.createElement('div');
                div.className = 'fact-search-item';
                div.dataset.id = f.id;
                const mesFact = parseInt(f.mes);
                const anioFact = parseInt(f.anio);
                const mesLbl = (mesesNombres[mesFact] || '') + ' ' + anioFact;
                div.innerHTML = `
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <strong>${f.correlativo}</strong>
                        <span class="badge ms-2" style="background:#dbeafe;color:#1e40af;font-size:.65rem">${mesLbl}</span>
                        <small class="text-muted ms-1">${fmtFecha(f.fecha_emision)}</small>
                    </div>
                    <span class="fw-bold text-success">L ${parseFloat(f.total).toLocaleString('es-HN',{minimumFractionDigits:2})}</span>
                </div>
                <small class="text-muted">${f.receptor_nombre}</small>`;
                div.addEventListener('click', () => {
                    wrap.querySelectorAll('.fact-search-item').forEach(i => i.classList.remove('selected'));
                    div.classList.add('selected');
                    facturaSelId = f.id;
                    mostrarDetalleVinc(f);
                    document.getElementById('vincSeleccionada').classList.remove('d-none');
                    document.getElementById('btnVincularConfirmar').disabled = false;
                });
                wrap.appendChild(div);
            });
        }

        const fmtFecha = d => {
            if (!d) return '';
            const [y, m, dd] = d.split('-');
            return `${dd}/${m}/${y}`;
        };

        function mostrarDetalleVinc(f) {
            const isv = (parseFloat(f.isv_15 || 0) + parseFloat(f.isv_18 || 0)).toFixed(2);
            const mesLbl = (mesesNombres[parseInt(f.mes)] || '') + ' ' + f.anio;
            document.getElementById('vincDetCorr').textContent = f.correlativo;
            document.getElementById('vincDetPeriodo').textContent = mesLbl;
            document.getElementById('vincDetFecha').textContent = `Emitida: ${fmtFecha(f.fecha_emision)}`;
            document.getElementById('vincDetReceptor').textContent = `Cliente: ${f.receptor_nombre}`;
            document.getElementById('vincDetTotal').textContent =
                `L ${parseFloat(f.total).toLocaleString('es-HN',{minimumFractionDigits:2})}`;
            document.getElementById('vincDetSub').textContent =
                `Subtotal: L ${parseFloat(f.subtotal).toLocaleString('es-HN',{minimumFractionDigits:2})}`;
            document.getElementById('vincDetIsv').textContent = parseFloat(isv) > 0 ?
                `ISV: L ${parseFloat(isv).toLocaleString('es-HN',{minimumFractionDigits:2})}` : '';
            const notasEl = document.getElementById('vincDetNotas');
            if (f.notas) {
                notasEl.textContent = 'Notas: ' + f.notas;
                notasEl.classList.remove('d-none');
            } else notasEl.classList.add('d-none');
        }

        // Toggle cambiar período
        document.getElementById('vincCambiarPeriodo').addEventListener('click', e => {
            e.preventDefault();
            const wrap = document.getElementById('vincCambiarPeriodoWrap');
            wrap.classList.toggle('d-none');
            if (!wrap.classList.contains('d-none')) {
                // Sync label on change
                const syncLbl = () => {
                    const m = document.getElementById('vincPeriodoMes').value;
                    const a = document.getElementById('vincPeriodoAnio').value;
                    document.getElementById('vincPeriodoLbl').textContent =
                        mesesNombres[parseInt(m)] + ' ' + a;
                };
                document.getElementById('vincPeriodoMes').addEventListener('change', syncLbl);
                document.getElementById('vincPeriodoAnio').addEventListener('change', syncLbl);
            }
        });

        // Búsqueda en tiempo real
        document.getElementById('vincBuscar').addEventListener('input', function() {
            const q = this.value.toLowerCase();
            const filtradas = q ?
                todasFacturas.filter(f =>
                    f.correlativo.toLowerCase().includes(q) ||
                    f.receptor_nombre.toLowerCase().includes(q)) :
                todasFacturas;
            renderFacturas(filtradas);
        });

        // Volver al modal de regularizar
        ['btnVincularBack', 'btnVincularBack2'].forEach(id => {
            document.getElementById(id)?.addEventListener('click', () => {
                bootstrap.Modal.getInstance(document.getElementById('modalVincular'))?.hide();
                setTimeout(() => abrirRegularizar(contratoActual), 350);
            });
        });

        // Confirmar vinculación
        document.getElementById('btnVincularConfirmar').addEventListener('click', () => {
            if (!facturaSelId || !periodoActual || !contratoActual) return;
            const btn = document.getElementById('btnVincularConfirmar');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Vinculando…';

            const fd = new FormData();
            fd.append('factura_id', facturaSelId);
            fd.append('contrato_id', contratoActual.id);
            fd.append('periodo_mes', document.getElementById('vincPeriodoMes').value);
            fd.append('periodo_anio', document.getElementById('vincPeriodoAnio').value);

            fetch('includes/contrato_vincular_factura.php', {
                    method: 'POST',
                    body: fd
                })
                .then(r => r.json())
                .then(d => {
                    if (d.success) {
                        bootstrap.Modal.getInstance(document.getElementById('modalVincular'))?.hide();
                        Swal.fire({
                            icon: 'success',
                            title: '¡Factura vinculada!',
                            text: `La factura fue vinculada al período ${periodoActual.label}.`,
                            timer: 2000,
                            showConfirmButton: false
                        }).then(() => location.reload());
                    } else {
                        Swal.fire('Error', d.error || 'No se pudo vincular.', 'error');
                        btn.disabled = false;
                        btn.innerHTML = '<i class="bi bi-link-45deg me-1"></i>Vincular a este período';
                    }
                });
        });
    </script>

    <?php require_once '../../includes/templates/footer.php'; ?>