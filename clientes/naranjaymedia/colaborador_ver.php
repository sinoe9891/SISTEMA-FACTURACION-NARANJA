<?php
// clientes/naranjaymedia/colaborador_ver.php
$titulo = 'Ver Colaborador';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';
require_once '../../includes/templates/header.php';

$cliente_id = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);

define('IHSS_EMP_V',  0.035);
define('IHSS_PAT_V',  0.07);
define('RAP_EMP_V',   0.015);
define('RAP_PAT_V',   0.015);
define('IHSS_TOPE_V', 10294.10);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    header('Location: colaboradores');
    exit;
}

$stmtC = $pdo->prepare("SELECT c.*, cg.nombre AS cat_nombre, cg.color AS cat_color, cg.icono AS cat_icono
    FROM colaboradores c LEFT JOIN categorias_gastos cg ON cg.id=c.categoria_gasto_id
    WHERE c.id=? AND c.cliente_id=?");
$stmtC->execute([$id, $cliente_id]);
$col = $stmtC->fetch(PDO::FETCH_ASSOC);
if (!$col) {
    header('Location: colaboradores');
    exit;
}

$filtro_tipo = trim($_GET['tipo']  ?? '');
$filtro_mes  = (int)($_GET['mes']  ?? date('n'));
$filtro_anio = (int)($_GET['anio'] ?? date('Y'));
$filtro_todo = isset($_GET['todo']);

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

$salario     = (float)$col['salario_base'];
$aplica_ihss = (int)$col['aplica_ihss'];
$aplica_rap  = (int)$col['aplica_rap'];
$tipo_pago   = $col['tipo_pago'];

$base_ihss = min($salario, IHSS_TOPE_V);
$ihss_emp  = $aplica_ihss ? round($base_ihss * IHSS_EMP_V, 2) : 0;
$rap_emp   = $aplica_rap  ? round($salario   * RAP_EMP_V,  2) : 0;
$ihss_pat  = $aplica_ihss ? round($base_ihss * IHSS_PAT_V, 2) : 0;
$rap_pat   = $aplica_rap  ? round($salario   * RAP_PAT_V,  2) : 0;
$neto_mes  = $salario - $ihss_emp - $rap_emp;
$costo_emp = $neto_mes + $ihss_pat + $rap_pat;
$div       = $tipo_pago === 'quincenal' ? 2 : 1;
$nombreCompleto = $col['nombre'] . ' ' . $col['apellido'];

/* ── Pagos ─────────────────────────────────────────────────────────────── */
$sqlPagos = "SELECT g.*, cg.nombre AS cat_nombre, cg.color AS cat_color, cg.icono AS cat_icono
    FROM gastos g LEFT JOIN categorias_gastos cg ON cg.id=g.categoria_id
    WHERE g.cliente_id=? AND (
        g.descripcion LIKE ? OR g.descripcion LIKE ? OR
        g.descripcion LIKE ? OR g.descripcion LIKE ?
    )";
$paramsPagos = [
    $cliente_id,
    'Sueldo ' . $nombreCompleto . '%',
    'Bono: %' . $nombreCompleto,
    'Viático: %' . $nombreCompleto,
    'Pago adicional - ' . $nombreCompleto . '%',
];
if (!$filtro_todo) {
    $sqlPagos .= " AND YEAR(g.fecha)=? AND MONTH(g.fecha)=?";
    $paramsPagos[] = $filtro_anio;
    $paramsPagos[] = $filtro_mes;
}
if ($filtro_tipo === '1')           $sqlPagos .= " AND g.quincena_num=1";
elseif ($filtro_tipo === '2')       $sqlPagos .= " AND g.quincena_num=2";
elseif ($filtro_tipo === 'mensual') $sqlPagos .= " AND g.quincena_num IS NULL";
$sqlPagos .= " ORDER BY g.fecha DESC, g.id DESC";
$stmtP = $pdo->prepare($sqlPagos);
$stmtP->execute($paramsPagos);
$pagos = $stmtP->fetchAll(PDO::FETCH_ASSOC);

$total_pagado = $total_pend = $count_pagado = $count_pend = 0;
foreach ($pagos as $p) {
    if ($p['estado'] === 'pagado') {
        $total_pagado += (float)$p['monto'];
        $count_pagado++;
    }
    if ($p['estado'] === 'pendiente') {
        $total_pend   += (float)$p['monto'];
        $count_pend++;
    }
}

$diasTotal = (int)((time() - strtotime($col['fecha_ingreso'])) / 86400);
$anios     = floor($diasTotal / 365);
$mesesAnt  = floor(($diasTotal % 365) / 30);

/* ── Categorías ─────────────────────────────────────────────────────────── */
$stmtCats = $pdo->prepare("SELECT id,nombre,color,icono FROM categorias_gastos WHERE cliente_id=? AND activa=1 ORDER BY nombre");
$stmtCats->execute([$cliente_id]);
$categorias = $stmtCats->fetchAll(PDO::FETCH_ASSOC);

/* ── Préstamos ──────────────────────────────────────────────────────────── */
$stmtPrest = $pdo->prepare("SELECT p.* FROM colaborador_prestamos p WHERE p.colaborador_id=? AND p.cliente_id=? ORDER BY p.fecha DESC, p.id DESC");
$stmtPrest->execute([$id, $cliente_id]);
$prestamos = $stmtPrest->fetchAll(PDO::FETCH_ASSOC);

$prestamo_ids = array_column(array_filter($prestamos, fn($p) => $p['estado'] === 'activo'), 'id');
$cuotas_por_prestamo = [];
if (!empty($prestamo_ids)) {
    $ph = implode(',', array_fill(0, count($prestamo_ids), '?'));
    $stmtCuotas = $pdo->prepare("SELECT * FROM colaborador_prestamo_cuotas WHERE prestamo_id IN ($ph) ORDER BY prestamo_id ASC, numero_cuota ASC");
    $stmtCuotas->execute($prestamo_ids);
    foreach ($stmtCuotas->fetchAll(PDO::FETCH_ASSOC) as $cuota)
        $cuotas_por_prestamo[$cuota['prestamo_id']][] = $cuota;
}

$total_deuda_activa = array_sum(array_column(array_filter($prestamos, fn($p) => $p['estado'] === 'activo'), 'saldo_pendiente'));

// Separar por tipo
$solo_prestamos = array_filter($prestamos, fn($p) => in_array($p['tipo'], ['prestamo', 'adelanto', 'multa']));
$solo_bonos     = array_filter($prestamos, fn($p) => $p['tipo'] === 'bono');
$solo_viaticos  = array_filter($prestamos, fn($p) => $p['tipo'] === 'viatico');

$bonos_pendientes   = array_filter($solo_bonos,    fn($p) => $p['estado'] === 'activo');
$viaticos_pendientes = array_filter($solo_viaticos, fn($p) => $p['estado'] === 'activo');
$total_bonos_pend   = array_sum(array_column($bonos_pendientes, 'monto_total'));
$total_viaticos_pend = array_sum(array_column($viaticos_pendientes, 'monto_total'));

/* ── Cuotas auto-descuento ──────────────────────────────────────────────── */
$neto_quincena = round($neto_mes / $div, 2);
$stmtCuotasAuto = $pdo->prepare("
    SELECT c.id AS cuota_id, c.monto AS cuota_monto, c.numero_cuota, c.fecha_esperada,
           p.id AS prestamo_id, p.descripcion AS prest_desc, p.tipo
    FROM colaborador_prestamo_cuotas c
    JOIN colaborador_prestamos p ON p.id=c.prestamo_id
    WHERE p.colaborador_id=? AND p.cliente_id=?
      AND p.estado='activo' AND p.descuento_auto=1 AND c.estado='pendiente'
      AND c.id=(SELECT c2.id FROM colaborador_prestamo_cuotas c2 WHERE c2.prestamo_id=p.id AND c2.estado='pendiente' ORDER BY c2.numero_cuota ASC LIMIT 1)
    ORDER BY p.id ASC");
$stmtCuotasAuto->execute([$id, $cliente_id]);
$cuotas_auto_pendientes = $stmtCuotasAuto->fetchAll(PDO::FETCH_ASSOC);

$total_descuento_auto = 0;
$cuotas_aplicables = [];
foreach ($cuotas_auto_pendientes as $ca) {
    $cm = (float)$ca['cuota_monto'];
    if (($total_descuento_auto + $cm) <= $neto_quincena) {
        $total_descuento_auto += $cm;
        $cuotas_aplicables[] = $ca;
    } else {
        $restante = $neto_quincena - $total_descuento_auto;
        if ($restante > 0) {
            $cap = $ca;
            $cap['cuota_monto'] = $restante;
            $cap['parcial'] = true;
            $cap['monto_original'] = $cm;
            $cuotas_aplicables[] = $cap;
            $total_descuento_auto += $restante;
        }
        break;
    }
}
$neto_a_pagar_real = max(0, round($neto_quincena - $total_descuento_auto, 2));

/* ── Quincenas pagadas ──────────────────────────────────────────────────── */
$q1_pagada = $q2_pagada = false;
if ($tipo_pago === 'quincenal') {
    $stmtQP = $pdo->prepare("SELECT quincena_num FROM gastos WHERE cliente_id=? AND descripcion LIKE ? AND YEAR(fecha)=YEAR(CURDATE()) AND MONTH(fecha)=MONTH(CURDATE()) AND estado!='anulado' AND quincena_num IN(1,2)");
    $stmtQP->execute([$cliente_id, 'Sueldo ' . $nombreCompleto . '%']);
    foreach ($stmtQP->fetchAll(PDO::FETCH_COLUMN) as $qn) {
        if ((int)$qn === 1) $q1_pagada = true;
        if ((int)$qn === 2) $q2_pagada = true;
    }
}
$quincena_sugerida = (!$q1_pagada) ? 1 : ((!$q2_pagada) ? 2 : 1);

/* ── Alertas de nómina vencida (mes actual) ─────────────────────────────── */
$nomina_vencida = [];
$hoy_obj   = new DateTime('today');
$anio_hoy  = (int)$hoy_obj->format('Y');
$mes_hoy   = (int)$hoy_obj->format('n');
$dias_mes  = (int)$hoy_obj->format('t');

if ($col['activo']) {
    if ($tipo_pago === 'quincenal') {
        $checks_q = [
            1 => ['dia' => (int)$col['dia_pago'],  'pagada' => $q1_pagada, 'label' => '1ª Quincena'],
            2 => ['dia' => (int)$col['dia_pago_2'], 'pagada' => $q2_pagada, 'label' => '2ª Quincena'],
        ];
        foreach ($checks_q as $q_num => $info) {
            if ($info['pagada']) continue;
            $dia_r = min($info['dia'], $dias_mes);
            $fp    = new DateTime(sprintf('%04d-%02d-%02d', $anio_hoy, $mes_hoy, $dia_r));
            $fi    = new DateTime($col['fecha_ingreso']);
            if ($fp < $fi) continue;
            if ($fp <= $hoy_obj) {
                $nomina_vencida[] = [
                    'quincena'    => $q_num,
                    'label'       => $info['label'],
                    'fecha'       => $fp->format('Y-m-d'),
                    'dias_atraso' => (int)$hoy_obj->diff($fp)->days,
                    'neto'        => $neto_quincena,
                ];
            }
        }
    } else {
        $stmtMP = $pdo->prepare("SELECT COUNT(*) FROM gastos WHERE cliente_id=? AND descripcion LIKE ? AND YEAR(fecha)=? AND MONTH(fecha)=? AND estado!='anulado'");
        $stmtMP->execute([$cliente_id, 'Sueldo ' . $nombreCompleto . '%', $anio_hoy, $mes_hoy]);
        if ((int)$stmtMP->fetchColumn() === 0) {
            $dia_r = min((int)$col['dia_pago'], $dias_mes);
            $fp    = new DateTime(sprintf('%04d-%02d-%02d', $anio_hoy, $mes_hoy, $dia_r));
            $fi    = new DateTime($col['fecha_ingreso']);
            if ($fp >= $fi && $fp <= $hoy_obj) {
                $nomina_vencida[] = [
                    'quincena'    => 0,
                    'label'       => 'Mensual',
                    'fecha'       => $fp->format('Y-m-d'),
                    'dias_atraso' => (int)$hoy_obj->diff($fp)->days,
                    'neto'        => $neto_quincena,
                ];
            }
        }
    }
}

$tipos_btn_p = [
    ['val' => 'prestamo', 'label' => 'Préstamo',       'icon' => 'fa-hand-holding-dollar', 'color' => 'danger',   'desc' => 'Con cuotas'],
    ['val' => 'adelanto', 'label' => 'Adelanto',         'icon' => 'fa-bolt',              'color' => 'warning',  'desc' => 'Descuento único'],
    ['val' => 'bono',    'label' => 'Bono',             'icon' => 'fa-gift',              'color' => 'success',  'desc' => 'Sin descuento'],
    ['val' => 'viatico', 'label' => 'Viático',          'icon' => 'fa-plane-departure',   'color' => 'info',     'desc' => 'Gasto de viaje'],
    ['val' => 'multa',   'label' => 'Multa/Descuento',  'icon' => 'fa-ban',               'color' => 'secondary', 'desc' => 'Descuento único'],
];
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
    rel="stylesheet">

<style>
    :root {
        --brand: #0f766e;
        --brand-dark: #065f46;
        --brand-lt: #ccfbf1;
        --accent: #6366f1;
        --surface: #ffffff;
        --surface-2: #f8fafc;
        --border: #e2e8f0;
        --text: #0f172a;
        --muted: #64748b;
        --radius: 16px;
        --radius-sm: 10px;
        --shadow-sm: 0 1px 3px rgba(0, 0, 0, .06);
        --shadow-md: 0 6px 24px rgba(0, 0, 0, .08);
        --tr: .18s cubic-bezier(.4, 0, .2, 1);
        font-family: 'Plus Jakarta Sans', sans-serif;
    }

    /* ── Toolbar ───────────────────────────────────────────────────────────── */
    .cv-toolbar {
        position: sticky;
        top: 0;
        z-index: 80;
        background: rgba(255, 255, 255, .92);
        backdrop-filter: blur(12px);
        border-bottom: 1px solid var(--border);
        padding: .65rem 0;
        box-shadow: var(--shadow-sm);
    }

    /* ── Hero header ───────────────────────────────────────────────────────── */
    .cv-hero {
        background: linear-gradient(135deg, #0f766e 0%, #4f46e5 100%);
        border-radius: var(--radius);
        padding: 2rem 2.25rem;
        color: #fff;
        display: flex;
        align-items: center;
        gap: 1.5rem;
        flex-wrap: wrap;
        margin-bottom: 1.5rem;
        box-shadow: 0 8px 32px rgba(15, 118, 110, .25);
        position: relative;
        overflow: hidden;
    }

    .cv-hero::before {
        content: '';
        position: absolute;
        top: -60px;
        right: -60px;
        width: 220px;
        height: 220px;
        border-radius: 50%;
        background: rgba(255, 255, 255, .07);
        pointer-events: none;
    }

    .cv-hero::after {
        content: '';
        position: absolute;
        bottom: -80px;
        left: 30%;
        width: 300px;
        height: 160px;
        border-radius: 50%;
        background: rgba(255, 255, 255, .04);
        pointer-events: none;
    }

    .cv-avatar-wrap {
        position: relative;
        flex-shrink: 0;
    }

    .cv-avatar {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        background: rgba(255, 255, 255, .2);
        border: 3px solid rgba(255, 255, 255, .5);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 28px;
        font-weight: 800;
        color: #fff;
        box-shadow: 0 4px 20px rgba(0, 0, 0, .2);
    }

    .cv-status-dot {
        position: absolute;
        bottom: 3px;
        right: 3px;
        width: 16px;
        height: 16px;
        border-radius: 50%;
        border: 2.5px solid #fff;
    }

    .cv-status-dot.on {
        background: #22c55e;
    }

    .cv-status-dot.off {
        background: #94a3b8;
    }

    .cv-hero-info {
        flex: 1;
        min-width: 0;
    }

    .cv-hero-name {
        font-size: 1.45rem;
        font-weight: 800;
        line-height: 1.2;
        margin-bottom: .3rem;
    }

    .cv-hero-meta {
        font-size: .84rem;
        opacity: .82;
        display: flex;
        flex-wrap: wrap;
        gap: .75rem;
        align-items: center;
    }

    .cv-hero-meta i {
        opacity: .75;
    }

    .cv-hero-pill {
        background: rgba(255, 255, 255, .18);
        border: 1px solid rgba(255, 255, 255, .25);
        border-radius: 20px;
        padding: .18rem .75rem;
        font-size: .78rem;
        font-weight: 600;
    }

    .cv-tenure {
        text-align: center;
        background: rgba(255, 255, 255, .15);
        border: 1px solid rgba(255, 255, 255, .2);
        border-radius: 12px;
        padding: .9rem 1.1rem;
        min-width: 90px;
        display: none;
    }

    @media(min-width:768px) {
        .cv-tenure {
            display: block;
        }
    }

    .cv-tenure-num {
        font-size: 2rem;
        font-weight: 800;
        line-height: 1;
    }

    .cv-tenure-lbl {
        font-size: .65rem;
        opacity: .7;
        text-transform: uppercase;
        letter-spacing: .05em;
    }

    /* ── KPI strip ─────────────────────────────────────────────────────────── */
    .cv-kpis {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
        gap: .85rem;
        margin-bottom: 1.5rem;
    }

    .cv-kpi {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: .95rem 1.1rem;
        display: flex;
        align-items: center;
        gap: .8rem;
        box-shadow: var(--shadow-sm);
        transition: box-shadow var(--tr), transform var(--tr);
    }

    .cv-kpi:hover {
        box-shadow: var(--shadow-md);
        transform: translateY(-2px);
    }

    .cv-kpi-icon {
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
        background: #ccfbf1;
        color: #0f766e;
    }

    .ki-green {
        background: #d1fae5;
        color: #059669;
    }

    .ki-red {
        background: #fee2e2;
        color: #dc2626;
    }

    .ki-amber {
        background: #fef3c7;
        color: #d97706;
    }

    .ki-purple {
        background: #ede9fe;
        color: #7c3aed;
    }

    .ki-blue {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .ki-indigo {
        background: #e0e7ff;
        color: #4338ca;
    }

    .ki-sky {
        background: #e0f2fe;
        color: #0369a1;
    }

    .cv-kpi-val {
        font-size: 1.1rem;
        font-weight: 800;
        color: var(--text);
        line-height: 1.1;
    }

    .cv-kpi-lbl {
        font-size: .68rem;
        color: var(--muted);
        margin-top: 2px;
        text-transform: uppercase;
        letter-spacing: .04em;
    }

    /* ── Alert banners ─────────────────────────────────────────────────────── */
    .cv-alert-banner {
        border-radius: var(--radius-sm);
        padding: .75rem 1.1rem;
        display: flex;
        align-items: center;
        gap: .75rem;
        font-size: .85rem;
        font-weight: 600;
        margin-bottom: 1rem;
    }

    .cv-alert-banner.warn {
        background: #fffbeb;
        border: 1px solid #fde68a;
        color: #92400e;
    }

    .cv-alert-banner.success {
        background: #f0fdf4;
        border: 1px solid #a7f3d0;
        color: #065f46;
    }

    /* ── Layout columns ────────────────────────────────────────────────────── */
    .cv-layout {
        display: grid;
        grid-template-columns: 340px 1fr;
        gap: 1.25rem;
        align-items: start;
    }

    @media(max-width:991px) {
        .cv-layout {
            grid-template-columns: 1fr;
        }
    }

    /* ── Cards ─────────────────────────────────────────────────────────────── */
    .cv-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow-sm);
        overflow: hidden;
        margin-bottom: 1rem;
    }

    .cv-card-hdr {
        padding: .85rem 1.25rem;
        border-bottom: 1px solid var(--border);
        background: var(--surface-2);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .5rem;
        flex-wrap: wrap;
    }

    .cv-card-hdr-title {
        font-size: .88rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: .5rem;
        color: var(--text);
    }

    .cv-card-body {
        padding: 1.1rem 1.25rem;
    }

    /* ── Info rows ─────────────────────────────────────────────────────────── */
    .info-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: .45rem 0;
        border-bottom: 1px solid #f4f4f4;
        font-size: .83rem;
    }

    .info-row:last-child {
        border-bottom: none;
    }

    .info-lbl {
        color: var(--muted);
        font-size: .72rem;
        text-transform: uppercase;
        letter-spacing: .04em;
    }

    .info-val {
        font-weight: 600;
        color: var(--text);
        font-size: .83rem;
    }

    /* ── Desglose salarial ─────────────────────────────────────────────────── */
    .salary-breakdown {
        background: var(--surface-2);
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        overflow: hidden;
    }

    .sb-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: .55rem 1rem;
        font-size: .82rem;
        border-bottom: 1px solid var(--border);
    }

    .sb-row:last-child {
        border-bottom: none;
    }

    .sb-row.total {
        background: #f0fdf4;
        font-weight: 700;
        border-top: 2px solid #a7f3d0;
    }

    .sb-row.patronal {
        background: #fffbeb;
    }

    /* ── Tabs ──────────────────────────────────────────────────────────────── */
    .cv-tabs {
        display: flex;
        gap: .25rem;
        background: var(--surface-2);
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        padding: .3rem;
        margin-bottom: 1.1rem;
        flex-wrap: wrap;
    }

    .cv-tab {
        flex: 1;
        min-width: 80px;
        padding: .5rem .75rem;
        border-radius: 8px;
        border: none;
        background: transparent;
        font-size: .8rem;
        font-weight: 600;
        color: var(--muted);
        cursor: pointer;
        transition: all var(--tr);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: .4rem;
        white-space: nowrap;
    }

    .cv-tab:hover {
        background: #fff;
        color: var(--text);
    }

    .cv-tab.active {
        background: var(--brand);
        color: #fff;
        box-shadow: 0 2px 8px rgba(15, 118, 110, .3);
    }

    .cv-tab .tab-badge {
        background: rgba(255, 255, 255, .25);
        border-radius: 20px;
        padding: .05rem .4rem;
        font-size: .7rem;
    }

    .cv-tab:not(.active) .tab-badge {
        background: var(--border);
        color: var(--muted);
    }

    .tab-pane {
        display: none;
    }

    .tab-pane.active {
        display: block;
    }

    /* ── Payment table ─────────────────────────────────────────────────────── */
    .pay-table {
        width: 100%;
        border-collapse: collapse;
        font-size: .82rem;
    }

    .pay-table thead th {
        padding: .6rem 1rem;
        background: var(--surface-2);
        color: var(--muted);
        font-weight: 600;
        font-size: .7rem;
        text-transform: uppercase;
        letter-spacing: .04em;
        border-bottom: 1px solid var(--border);
        white-space: nowrap;
    }

    .pay-table tbody tr {
        border-bottom: 1px solid var(--border);
        transition: background var(--tr);
    }

    .pay-table tbody tr:last-child {
        border-bottom: none;
    }

    .pay-table tbody tr:hover {
        background: #f0fdfa;
    }

    .pay-table tbody td {
        padding: .7rem 1rem;
        vertical-align: middle;
    }

    /* ── Concept badges ────────────────────────────────────────────────────── */
    .concept-badge {
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        padding: .15rem .55rem;
        border-radius: 20px;
        font-size: .68rem;
        font-weight: 600;
        margin-right: .25rem;
    }

    .cb-nomina {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .cb-bono {
        background: #d1fae5;
        color: #059669;
    }

    .cb-viatico {
        background: #e0f2fe;
        color: #0369a1;
    }

    /* ── Status badges ─────────────────────────────────────────────────────── */
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        padding: .2rem .6rem;
        border-radius: 20px;
        font-size: .72rem;
        font-weight: 600;
    }

    .sb-pagado {
        background: #d1fae5;
        color: #059669;
    }

    .sb-pendiente {
        background: #fef3c7;
        color: #92400e;
    }

    .sb-anulado {
        background: #f1f5f9;
        color: #94a3b8;
    }

    /* ── Quick action cards (bonos y viáticos) ─────────────────────────────── */
    .quick-cards {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: .85rem;
        margin-bottom: 1rem;
    }

    .quick-card {
        border-radius: var(--radius-sm);
        padding: 1rem 1.1rem;
        display: flex;
        align-items: flex-start;
        gap: .75rem;
        cursor: pointer;
        transition: all var(--tr);
        border: 1.5px solid transparent;
        position: relative;
        overflow: hidden;
    }

    .quick-card::before {
        content: '';
        position: absolute;
        top: 0;
        right: 0;
        width: 80px;
        height: 80px;
        border-radius: 50%;
        opacity: .08;
        transform: translate(20px, -20px);
    }

    .qc-bono {
        background: #f0fdf4;
        border-color: #a7f3d0;
    }

    .qc-bono::before {
        background: #059669;
    }

    .qc-bono:hover {
        background: #dcfce7;
        border-color: #059669;
        box-shadow: 0 4px 16px rgba(5, 150, 105, .15);
        transform: translateY(-1px);
    }

    .qc-viatico {
        background: #f0f9ff;
        border-color: #7dd3fc;
    }

    .qc-viatico::before {
        background: #0369a1;
    }

    .qc-viatico:hover {
        background: #e0f2fe;
        border-color: #0369a1;
        box-shadow: 0 4px 16px rgba(3, 105, 161, .15);
        transform: translateY(-1px);
    }

    .qc-icon {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        flex-shrink: 0;
    }

    .qc-bono .qc-icon {
        background: #059669;
        color: #fff;
    }

    .qc-viatico .qc-icon {
        background: #0369a1;
        color: #fff;
    }

    .qc-title {
        font-weight: 700;
        font-size: .85rem;
        color: var(--text);
    }

    .qc-sub {
        font-size: .72rem;
        color: var(--muted);
        margin-top: 1px;
    }

    .qc-amount {
        margin-top: .4rem;
        font-weight: 800;
        font-size: .92rem;
    }

    .qc-bono .qc-amount {
        color: #059669;
    }

    .qc-viatico .qc-amount {
        color: #0369a1;
    }

    /* ── Loan accordion ────────────────────────────────────────────────────── */
    .loan-item {
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        overflow: hidden;
        margin-bottom: .6rem;
        transition: box-shadow var(--tr);
    }

    .loan-item:hover {
        box-shadow: var(--shadow-md);
    }

    .loan-header {
        padding: .85rem 1.1rem;
        background: var(--surface-2);
        display: flex;
        align-items: center;
        gap: .75rem;
        cursor: pointer;
        transition: background var(--tr);
        flex-wrap: wrap;
    }

    .loan-header:hover {
        background: #f0fdfa;
    }

    .loan-header.open {
        background: #f0fdfa;
        border-bottom: 1px solid var(--border);
    }

    .loan-body {
        padding: 1rem 1.1rem;
        background: #fafafa;
        display: none;
    }

    .loan-body.open {
        display: block;
    }

    .loan-progress {
        height: 6px;
        background: var(--border);
        border-radius: 3px;
        overflow: hidden;
        margin: .5rem 0;
    }

    .loan-progress-fill {
        height: 100%;
        background: #059669;
        border-radius: 3px;
        transition: width .6s;
    }

    .cuota-table {
        width: 100%;
        border-collapse: collapse;
        font-size: .78rem;
    }

    .cuota-table th {
        padding: .4rem .75rem;
        background: #f1f5f9;
        color: var(--muted);
        font-size: .67rem;
        text-transform: uppercase;
        letter-spacing: .04em;
        border-bottom: 1px solid var(--border);
    }

    .cuota-table td {
        padding: .5rem .75rem;
        border-bottom: 1px solid #f4f4f4;
        vertical-align: middle;
    }

    .cuota-table tr:last-child td {
        border-bottom: none;
    }

    .cuota-table tr:hover td {
        background: #f8fafc;
    }

    /* ── Period summary boxes ──────────────────────────────────────────────── */
    .period-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: .75rem;
        margin-bottom: 1rem;
    }

    .period-box {
        border-radius: var(--radius-sm);
        padding: .85rem 1rem;
        text-align: center;
    }

    .pb-paid {
        background: #f0fdf4;
        border: 1px solid #a7f3d0;
    }

    .pb-pending {
        background: #fffbeb;
        border: 1px solid #fde68a;
    }

    .period-box-val {
        font-size: 1.1rem;
        font-weight: 800;
    }

    .pb-paid .period-box-val {
        color: #059669;
    }

    .pb-pending .period-box-val {
        color: #d97706;
    }

    .period-box-lbl {
        font-size: .7rem;
        color: var(--muted);
        margin-top: 2px;
        text-transform: uppercase;
        letter-spacing: .04em;
    }

    /* ── Quincena pills ────────────────────────────────────────────────────── */
    .q-pill {
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        padding: .18rem .55rem;
        border-radius: 20px;
        font-size: .7rem;
        font-weight: 700;
    }

    .q1 {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .q2 {
        background: #e0f2fe;
        color: #0369a1;
    }

    .qm {
        background: #ede9fe;
        color: #7c3aed;
    }

    /* ── Buttons ───────────────────────────────────────────────────────────── */
    .btn-icon {
        display: inline-flex;
        align-items: center;
        gap: .35rem;
        padding: .4rem .85rem;
        border-radius: var(--radius-sm);
        font-size: .8rem;
        font-weight: 600;
        cursor: pointer;
        border: 1.5px solid transparent;
        transition: all var(--tr);
        white-space: nowrap;
    }

    .bi-teal {
        background: var(--brand-lt);
        color: var(--brand);
        border-color: rgba(15, 118, 110, .2);
    }

    .bi-teal:hover {
        background: var(--brand);
        color: #fff;
    }

    .bi-red {
        background: #fee2e2;
        color: #dc2626;
        border-color: rgba(220, 38, 38, .2);
    }

    .bi-red:hover {
        background: #dc2626;
        color: #fff;
    }

    .bi-green {
        background: #d1fae5;
        color: #059669;
        border-color: rgba(5, 150, 105, .2);
    }

    .bi-green:hover {
        background: #059669;
        color: #fff;
    }

    /* ── Modals ────────────────────────────────────────────────────────────── */
    .mf-label {
        font-size: .75rem;
        font-weight: 700;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: .04em;
        margin-bottom: .3rem;
        display: block;
    }

    .mf-input,
    .mf-select {
        width: 100%;
        padding: .6rem .85rem;
        border: 1.5px solid var(--border);
        border-radius: var(--radius-sm);
        font-size: .88rem;
        color: var(--text);
        background: var(--surface);
        outline: none;
        transition: border-color var(--tr);
        font-family: inherit;
    }

    .mf-input:focus,
    .mf-select:focus {
        border-color: var(--brand);
        box-shadow: 0 0 0 3px rgba(15, 118, 110, .1);
    }

    /* ── Desglose boxes ────────────────────────────────────────────────────── */
    .dsg-strip {
        display: flex;
        flex-wrap: wrap;
        gap: .5rem;
        background: var(--surface-2);
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        padding: .85rem;
        margin-bottom: .85rem;
    }

    .dsg-item {
        flex: 1 1 70px;
        text-align: center;
    }

    .dsg-val {
        font-size: .85rem;
        font-weight: 800;
        color: var(--text);
    }

    .dsg-lbl {
        font-size: .65rem;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: .03em;
        margin-top: 2px;
    }

    .dsg-sep {
        display: flex;
        align-items: center;
        color: #cbd5e1;
        font-size: 1rem;
        padding-top: 8px;
    }

    .box-deductions {
        background: #fff5f5;
        border: 1px solid #fecaca;
        border-radius: var(--radius-sm);
        padding: .75rem;
        margin-bottom: .6rem;
        font-size: .81rem;
    }

    .box-bonos {
        background: #f0fdf4;
        border: 1px solid #a7f3d0;
        border-radius: var(--radius-sm);
        padding: .75rem;
        margin-bottom: .6rem;
        font-size: .81rem;
    }

    .box-viaticos {
        background: #f0f9ff;
        border: 1px solid #7dd3fc;
        border-radius: var(--radius-sm);
        padding: .75rem;
        margin-bottom: .6rem;
        font-size: .81rem;
    }

    /* ── Tipo selector cards ────────────────────────────────────────────────── */
    .tipo-selector {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: .55rem;
    }

    @media(max-width:576px) {
        .tipo-selector {
            grid-template-columns: repeat(3, 1fr);
        }
    }

    .tipo-card {
        position: relative;
        border: 2px solid var(--border);
        border-radius: 12px;
        padding: .85rem .4rem .7rem;
        text-align: center;
        cursor: pointer;
        transition: border-color .18s, background .18s, box-shadow .18s, transform .15s;
        background: var(--surface);
        user-select: none;
        overflow: hidden;
    }

    .tipo-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 3px;
        border-radius: 12px 12px 0 0;
        transition: opacity .18s;
        opacity: 0;
    }

    .tipo-card:hover:not(.tc-selected) {
        border-color: #cbd5e1;
        background: #f8fafc;
        transform: translateY(-1px);
    }

    .tipo-card.tc-selected {
        border-width: 2px;
        box-shadow: 0 4px 16px rgba(0, 0, 0, .1);
        transform: translateY(-2px);
    }

    .tipo-card.tc-selected::before {
        opacity: 1;
    }

    .tipo-radio {
        position: absolute;
        opacity: 0;
        width: 0;
        height: 0;
        pointer-events: none;
    }

    .tc-icon-wrap {
        width: 42px;
        height: 42px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.15rem;
        margin: 0 auto .5rem;
        transition: transform .18s;
    }

    .tipo-card.tc-selected .tc-icon-wrap {
        transform: scale(1.08);
    }

    .tc-label {
        font-weight: 700;
        font-size: .76rem;
        color: var(--text);
        line-height: 1.2;
        margin-bottom: 2px;
        transition: color .18s;
    }

    .tc-desc {
        font-size: .63rem;
        color: var(--muted);
    }

    .tc-check {
        position: absolute;
        top: 6px;
        right: 7px;
        font-size: .78rem;
        opacity: 0;
        transition: opacity .18s, transform .18s;
        transform: scale(.5);
    }

    .tipo-card.tc-selected .tc-check {
        opacity: 1;
        transform: scale(1);
    }

    /* Per-type colors */
    .tc-prestamo {
        --tc-bg: #fff1f2;
        --tc-border: #fecaca;
        --tc-color: #dc2626;
        --tc-icon-bg: #fee2e2;
    }

    .tc-adelanto {
        --tc-bg: #fffbeb;
        --tc-border: #fde68a;
        --tc-color: #d97706;
        --tc-icon-bg: #fef3c7;
    }

    .tc-bono {
        --tc-bg: #f0fdf4;
        --tc-border: #a7f3d0;
        --tc-color: #059669;
        --tc-icon-bg: #d1fae5;
    }

    .tc-viatico {
        --tc-bg: #f0f9ff;
        --tc-border: #7dd3fc;
        --tc-color: #0369a1;
        --tc-icon-bg: #e0f2fe;
    }

    .tc-multa {
        --tc-bg: #f8fafc;
        --tc-border: #cbd5e1;
        --tc-color: #475569;
        --tc-icon-bg: #f1f5f9;
    }

    .tipo-card.tc-selected {
        background: var(--tc-bg);
        border-color: var(--tc-border);
    }

    .tipo-card.tc-selected::before {
        background: var(--tc-color);
    }

    .tipo-card.tc-selected .tc-label {
        color: var(--tc-color);
    }

    .tipo-card.tc-selected .tc-check {
        color: var(--tc-color);
    }

    .tc-icon-wrap {
        background: var(--tc-icon-bg);
        color: var(--tc-color);
    }

    .chk-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: .22rem 0;
        gap: .5rem;
    }

    .chk-row+.chk-row {
        border-top: 1px dotted #e2e8f0;
    }

    .total-pagar-box {
        background: linear-gradient(135deg, #059669, #047857);
        color: #fff;
        border-radius: var(--radius-sm);
        padding: .7rem 1rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: .6rem;
        font-weight: 700;
    }

    /* ── Upload zone ───────────────────────────────────────────────────────── */
    .upload-zone {
        border: 2px dashed var(--border);
        border-radius: var(--radius-sm);
        padding: 1.1rem;
        text-align: center;
        cursor: pointer;
        transition: all var(--tr);
    }

    .upload-zone:hover {
        border-color: var(--brand);
        background: #f0fdfa;
    }

    /* ── Empty state ───────────────────────────────────────────────────────── */
    .empty-state {
        text-align: center;
        padding: 2.5rem 1rem;
        color: var(--muted);
    }

    .empty-state-icon {
        font-size: 2.5rem;
        opacity: .2;
        margin-bottom: .75rem;
        display: block;
    }

    /* ── Print ─────────────────────────────────────────────────────────────── */
    @media print {

        .cv-toolbar,
        .no-print,
        .btn,
        .cv-tabs {
            display: none !important;
        }

        .tab-pane {
            display: block !important;
        }
    }
</style>

<!-- Toolbar -->
<div class="cv-toolbar no-print">
    <div class="container-xxl d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <div class="d-flex align-items-center gap-2">
            <a href="colaboradores" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Volver
            </a>
            <nav aria-label="breadcrumb" class="d-none d-md-block">
                <ol class="breadcrumb mb-0" style="font-size:.82rem">
                    <li class="breadcrumb-item">
                        <a href="colaboradores" class="text-muted text-decoration-none">
                            <i class="bi bi-people me-1"></i>Colaboradores
                        </a>
                    </li>
                    <li class="breadcrumb-item active fw-semibold"><?= htmlspecialchars($nombreCompleto) ?></li>
                </ol>
            </nav>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <?php if ($total_bonos_pend > 0 || $total_viaticos_pend > 0): ?>
                <span class="badge"
                    style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;font-size:.75rem;padding:.35rem .7rem;">
                    <i class="bi bi-exclamation-circle me-1"></i>
                    Pendientes por liquidar
                </span>
            <?php endif; ?>
            <a href="colaborador_reporte.php?id=<?= $id ?>&mes=<?= $filtro_mes ?>&anio=<?= $filtro_anio ?>"
                target="_blank" class="btn btn-sm btn-outline-danger no-print">
                <i class="bi bi-file-pdf me-1"></i>PDF
            </a>
            <?php if ($col['activo']): ?>
                <button class="btn btn-sm btn-success btn-pagar-directo no-print">
                    <i class="bi bi-cash-coin me-1"></i>Registrar Pago
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="container-xxl py-4">

    <!-- ── Hero ─────────────────────────────────────────────────────────── -->
    <div class="cv-hero">
        <div class="cv-avatar-wrap">
            <div class="cv-avatar">
                <?= strtoupper(mb_substr($col['nombre'], 0, 1) . mb_substr($col['apellido'], 0, 1)) ?>
            </div>
            <span class="cv-status-dot <?= $col['activo'] ? 'on' : 'off' ?>"></span>
        </div>
        <div class="cv-hero-info">
            <div class="cv-hero-name"><?= htmlspecialchars($nombreCompleto) ?></div>
            <div class="cv-hero-meta">
                <span><i class="bi bi-briefcase me-1"></i><?= htmlspecialchars($col['puesto']) ?></span>
                <?php if ($col['departamento']): ?>
                    <span><i class="bi bi-building me-1"></i><?= htmlspecialchars($col['departamento']) ?></span>
                <?php endif; ?>
                <?php if ($col['telefono']): ?>
                    <span><i class="bi bi-phone me-1"></i><?= htmlspecialchars($col['telefono']) ?></span>
                <?php endif; ?>
            </div>
            <div class="d-flex flex-wrap gap-2 mt-2">
                <span class="cv-hero-pill">
                    <?= $tipo_pago === 'quincenal' ? '🔄 Quincenal' : '📅 Mensual' ?>
                </span>
                <span class="cv-hero-pill">
                    📅 Ingreso: <?= date('d/m/Y', strtotime($col['fecha_ingreso'])) ?>
                </span>
                <?php if ($total_deuda_activa > 0): ?>
                    <span class="cv-hero-pill" style="background:rgba(239,68,68,.2);border-color:rgba(239,68,68,.3)">
                        ⚠️ Deuda: L <?= number_format($total_deuda_activa, 2) ?>
                    </span>
                <?php endif; ?>
                <?php if ($col['cat_nombre']): ?>
                    <span class="cv-hero-pill">
                        <?= htmlspecialchars($col['cat_nombre']) ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <div class="cv-tenure">
            <div class="cv-tenure-num"><?= $anios > 0 ? $anios : $mesesAnt ?></div>
            <div class="cv-tenure-lbl"><?= $anios > 0 ? ($anios === 1 ? 'año' : 'años') : 'mes(es)' ?></div>
            <div style="font-size:.6rem;opacity:.55;margin-top:2px">antigüedad</div>
        </div>
    </div>

    <!-- ── KPIs ──────────────────────────────────────────────────────────── -->
    <div class="cv-kpis">
        <div class="cv-kpi">
            <div class="cv-kpi-icon ki-blue"><i class="bi bi-cash-stack"></i></div>
            <div>
                <div class="cv-kpi-val">L <?= number_format($salario, 0) ?></div>
                <div class="cv-kpi-lbl">Salario bruto</div>
            </div>
        </div>
        <div class="cv-kpi">
            <div class="cv-kpi-icon ki-green"><i class="bi bi-wallet2"></i></div>
            <div>
                <div class="cv-kpi-val">L <?= number_format($neto_mes / $div, 0) ?></div>
                <div class="cv-kpi-lbl">Neto/<?= $div === 2 ? 'quincena' : 'mes' ?></div>
            </div>
        </div>
        <div class="cv-kpi">
            <div class="cv-kpi-icon ki-red"><i class="bi bi-arrow-down-circle"></i></div>
            <div>
                <div class="cv-kpi-val">-L <?= number_format(($ihss_emp + $rap_emp) / $div, 0) ?></div>
                <div class="cv-kpi-lbl">Deducciones</div>
            </div>
        </div>
        <div class="cv-kpi">
            <div class="cv-kpi-icon ki-amber"><i class="bi bi-building"></i></div>
            <div>
                <div class="cv-kpi-val">L <?= number_format(($ihss_pat + $rap_pat) / $div, 0) ?></div>
                <div class="cv-kpi-lbl">Carga patronal</div>
            </div>
        </div>
        <div class="cv-kpi">
            <div class="cv-kpi-icon ki-purple"><i class="bi bi-graph-up"></i></div>
            <div>
                <div class="cv-kpi-val">L <?= number_format($costo_emp / $div, 0) ?></div>
                <div class="cv-kpi-lbl">Costo empresa</div>
            </div>
        </div>
        <?php if ($total_bonos_pend > 0): ?>
            <div class="cv-kpi" style="border-color:#a7f3d0;cursor:pointer" onclick="setTab('bonos')">
                <div class="cv-kpi-icon ki-green"><i class="bi bi-gift"></i></div>
                <div>
                    <div class="cv-kpi-val" style="color:#059669">L <?= number_format($total_bonos_pend, 0) ?></div>
                    <div class="cv-kpi-lbl">Bonos pendientes</div>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($total_viaticos_pend > 0): ?>
            <div class="cv-kpi" style="border-color:#7dd3fc;cursor:pointer" onclick="setTab('viaticos')">
                <div class="cv-kpi-icon ki-sky"><i class="bi bi-airplane"></i></div>
                <div>
                    <div class="cv-kpi-val" style="color:#0369a1">L <?= number_format($total_viaticos_pend, 0) ?></div>
                    <div class="cv-kpi-lbl">Viáticos pendientes</div>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($total_deuda_activa > 0): ?>
            <div class="cv-kpi" style="border-color:#fecaca;cursor:pointer" onclick="setTab('prestamos')">
                <div class="cv-kpi-icon ki-red"><i class="bi bi-credit-card-2-back"></i></div>
                <div>
                    <div class="cv-kpi-val" style="font-size:.9rem;">L <?= number_format($total_deuda_activa, 0) ?></div>
                    <div class="cv-kpi-lbl">Deuda activa</div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ── Alert banners ─────────────────────────────────────────────────── -->
    <?php if (!empty($nomina_vencida) && $col['activo']): ?>
        <div class="cv-alert-banner" style="background:#fff1f2;border:1px solid #fecaca;color:#7f1d1d;margin-bottom:.75rem">
            <i class="bi bi-exclamation-triangle-fill" style="font-size:1.15rem;color:#dc2626;flex-shrink:0"></i>
            <div class="flex-grow-1">
                <strong style="color:#dc2626">Nómina sin pagar este mes:</strong>
                <div class="d-flex flex-wrap gap-2 mt-1">
                    <?php foreach ($nomina_vencida as $nv): ?>
                        <span class="badge"
                            style="background:#fee2e2;color:#dc2626;border:1px solid #fecaca;font-size:.78rem;padding:.3rem .65rem;font-weight:600">
                            <i class="bi bi-calendar-x me-1"></i><?= $nv['label'] ?>
                            <span class="ms-1 opacity-75">·</span>
                            L <?= number_format($nv['neto'], 2) ?>
                            <?php if ($nv['dias_atraso'] > 0): ?>
                                <span class="ms-1"
                                    style="background:rgba(220,38,38,.15);border-radius:10px;padding:.05rem .4rem;font-size:.7rem"><?= $nv['dias_atraso'] ?>
                                    día(s)</span>
                            <?php else: ?>
                                <span class="ms-1"
                                    style="background:rgba(220,38,38,.15);border-radius:10px;padding:.05rem .4rem;font-size:.7rem">Hoy</span>
                            <?php endif; ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <button class="btn btn-sm btn-danger ms-auto btn-pagar-directo no-print"
                style="white-space:nowrap;flex-shrink:0" data-quincena-preset="<?= $nomina_vencida[0]['quincena'] ?? 0 ?>">
                <i class="bi bi-cash-coin me-1"></i>Pagar ahora
            </button>
        </div>
    <?php endif; ?>

    <?php if (($total_bonos_pend + $total_viaticos_pend) > 0): ?>
        <div class="cv-alert-banner warn">
            <i class="bi bi-clock-history" style="font-size:1.1rem"></i>
            <div>
                <strong>Pagos pendientes de liquidar:</strong>
                <?php if ($total_bonos_pend > 0): ?>
                    Bonos <span
                        class="badge bg-success bg-opacity-20 text-success border border-success border-opacity-25 ms-1">L
                        <?= number_format($total_bonos_pend, 2) ?></span>
                <?php endif; ?>
                <?php if ($total_viaticos_pend > 0): ?>
                    Viáticos <span class="badge ms-1" style="background:#e0f2fe;color:#0369a1;border:1px solid #7dd3fc80">L
                        <?= number_format($total_viaticos_pend, 2) ?></span>
                <?php endif; ?>
                — se incluirán al registrar la próxima nómina.
            </div>
            <?php if ($col['activo']): ?>
                <button class="btn btn-sm btn-success ms-auto btn-pagar-directo no-print" style="white-space:nowrap">
                    <i class="bi bi-cash-coin me-1"></i>Pagar ahora
                </button>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- ── Main layout ───────────────────────────────────────────────────── -->
    <div class="cv-layout">

        <!-- ── LEFT column ─────────────────────────────────────────────────── -->
        <div>

            <!-- Datos personales -->
            <div class="cv-card">
                <div class="cv-card-hdr">
                    <span class="cv-card-hdr-title">
                        <i class="bi bi-person-vcard text-primary"></i>Datos Personales
                    </span>
                    <?php if ($col['activo']): ?>
                        <button class="btn-icon bi-teal btn-editar-colab no-print"
                            style="font-size:.75rem;padding:.3rem .7rem"
                            data-col='<?= json_encode($col, JSON_HEX_APOS | JSON_HEX_QUOT) ?>'>
                            <i class="bi bi-pencil-fill"></i> Editar
                        </button>
                    <?php endif; ?>
                </div>
                <div class="cv-card-body">
                    <?php if ($col['dpi']): ?>
                        <div class="info-row">
                            <span class="info-lbl">DPI</span>
                            <span class="info-val"><?= htmlspecialchars($col['dpi']) ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($col['email']): ?>
                        <div class="info-row">
                            <span class="info-lbl">Email</span>
                            <span class="info-val" style="font-size:.77rem"><?= htmlspecialchars($col['email']) ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <span class="info-lbl">Ingreso</span>
                        <span class="info-val"><?= date('d/m/Y', strtotime($col['fecha_ingreso'])) ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-lbl">Antigüedad</span>
                        <span class="info-val">
                            <?php
                            if ($anios > 0)      echo $anios . ' año(s) ' . $mesesAnt . ' mes(es)';
                            elseif ($mesesAnt)  echo $mesesAnt . ' mes(es)';
                            else                echo $diasTotal . ' día(s)';
                            ?>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-lbl">Estado</span>
                        <span class="info-val">
                            <?php if ($col['activo']): ?>
                                <span class="status-badge sb-pagado"><i class="bi bi-circle-fill"
                                        style="font-size:7px"></i>Activo</span>
                            <?php else: ?>
                                <span class="status-badge sb-anulado"><i class="bi bi-circle-fill"
                                        style="font-size:7px"></i>Inactivo</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php if ($col['notas']): ?>
                        <div class="mt-2 p-2 rounded-2"
                            style="background:#f8fafc;font-size:.8rem;color:#555;border:1px solid var(--border)">
                            <i class="bi bi-sticky me-1 text-secondary"></i><?= nl2br(htmlspecialchars($col['notas'])) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Desglose salarial — MOVED BELOW -->

            <!-- Filter -->
            <div class="cv-card no-print">
                <div class="cv-card-hdr">
                    <span class="cv-card-hdr-title"><i class="bi bi-funnel text-muted"></i>Filtrar Período</span>
                </div>
                <div class="cv-card-body" style="padding:.85rem 1.1rem">
                    <form method="GET" class="d-flex flex-column gap-2">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="mf-label">Mes</label>
                                <select name="mes" class="mf-select">
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?= $m ?>" <?= $m == $filtro_mes ? 'selected' : '' ?>>
                                            <?= $meses_nombres[$m - 1] ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="mf-label">Año</label>
                                <select name="anio" class="mf-select">
                                    <?php for ($a = date('Y'); $a >= date('Y') - 4; $a--): ?>
                                        <option value="<?= $a ?>" <?= $a == $filtro_anio ? 'selected' : '' ?>><?= $a ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <?php if ($tipo_pago === 'quincenal'): ?>
                                <div class="col-12">
                                    <label class="mf-label">Quincena</label>
                                    <select name="tipo" class="mf-select">
                                        <option value="" <?= $filtro_tipo === '' ? 'selected' : '' ?>>Ambas</option>
                                        <option value="1" <?= $filtro_tipo === '1' ? 'selected' : '' ?>>1ª Quincena</option>
                                        <option value="2" <?= $filtro_tipo === '2' ? 'selected' : '' ?>>2ª Quincena</option>
                                    </select>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary btn-sm flex-fill">
                                <i class="bi bi-filter me-1"></i>Filtrar
                            </button>
                            <a href="?id=<?= $id ?>&todo=1" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-list me-1"></i>Todo
                            </a>
                            <a href="?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">Hoy</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Resumen período -->
            <div class="cv-card">
                <div class="cv-card-hdr">
                    <span class="cv-card-hdr-title"><i class="bi bi-bar-chart text-secondary"></i>Resumen del
                        Período</span>
                    <small class="text-muted" style="font-size:.75rem">
                        <?= $filtro_todo ? 'Todo' : ($filtro_mes ? $meses_nombres[$filtro_mes - 1] : '') . ($filtro_anio ? ' ' . $filtro_anio : '') ?>
                    </small>
                </div>
                <div class="cv-card-body">
                    <div class="period-grid">
                        <div class="period-box pb-paid">
                            <div class="period-box-val">L <?= number_format($total_pagado, 0) ?></div>
                            <div class="period-box-lbl">Pagado (<?= $count_pagado ?>)</div>
                        </div>
                        <div class="period-box pb-pending">
                            <div class="period-box-val">L <?= number_format($total_pend, 0) ?></div>
                            <div class="period-box-lbl">Pendiente (<?= $count_pend ?>)</div>
                        </div>
                    </div>
                    <div class="text-center p-2 rounded-2" style="background:#f0f9ff;border:1px solid #bae6fd">
                        <div class="fw-bold" style="color:#1d4ed8;font-size:1.1rem">L
                            <?= number_format($total_pagado + $total_pend, 2) ?></div>
                        <div class="text-muted" style="font-size:.72rem">TOTAL <?= count($pagos) ?> registros</div>
                    </div>
                </div>
            </div>
            <div class="cv-card-body" style="padding:.75rem">
                <?php $lbl = $div === 2 ? 'quincena' : 'mes'; ?>
                <div class="salary-breakdown">
                    <div class="sb-row">
                        <span class="info-lbl">Salario bruto/mes</span>
                        <span class="info-val">L <?= number_format($salario, 2) ?></span>
                    </div>
                    <div class="sb-row">
                        <span class="info-lbl" style="color:#dc2626">− IHSS <?= $aplica_ihss ? '(3.5%)' : '' ?></span>
                        <span class="info-val" style="color:#dc2626">
                            <?= $aplica_ihss ? '-L ' . number_format($ihss_emp, 2) : '<span style="color:#94a3b8">No aplica</span>' ?>
                        </span>
                    </div>
                    <div class="sb-row">
                        <span class="info-lbl" style="color:#dc2626">− RAP <?= $aplica_rap ? '(1.5%)' : '' ?></span>
                        <span class="info-val" style="color:#dc2626">
                            <?= $aplica_rap ? '-L ' . number_format($rap_emp, 2) : '<span style="color:#94a3b8">No aplica</span>' ?>
                        </span>
                    </div>
                    <div class="sb-row total">
                        <span style="font-weight:800;color:#059669">= Neto/<?= $lbl ?></span>
                        <span style="font-size:1rem;font-weight:800;color:#059669">L
                            <?= number_format($neto_mes / $div, 2) ?></span>
                    </div>
                    <div class="sb-row patronal">
                        <span class="info-lbl" style="color:#d97706">+ IHSS patronal (7%)</span>
                        <span class="info-val" style="color:#d97706">
                            <?= $aplica_ihss ? 'L ' . number_format($ihss_pat / $div, 2) : '<span style="color:#94a3b8">—</span>' ?>
                        </span>
                    </div>
                    <div class="sb-row patronal">
                        <span class="info-lbl" style="color:#d97706">+ RAP patronal (1.5%)</span>
                        <span class="info-val" style="color:#d97706">
                            <?= $aplica_rap ? 'L ' . number_format($rap_pat / $div, 2) : '<span style="color:#94a3b8">—</span>' ?>
                        </span>
                    </div>
                    <div class="sb-row" style="background:#ede9fe">
                        <span style="font-weight:800;color:#6d28d9;font-size:.78rem">= Costo empresa/<?= $lbl ?></span>
                        <span style="font-weight:800;color:#6d28d9">L <?= number_format($costo_emp / $div, 2) ?></span>
                    </div>
                </div>
                <div class="mt-2 p-2 rounded-2"
                    style="background:#eff6ff;border:1px solid #bfdbfe;font-size:.78rem;color:#1e40af">
                    <i class="bi bi-info-circle me-1"></i>
                    <?php if ($tipo_pago === 'quincenal'): ?>
                        Días de pago: <strong><?= (int)$col['dia_pago'] ?></strong> y
                        <strong><?= (int)$col['dia_pago_2'] ?></strong> de cada mes
                    <?php else: ?>
                        Día de pago: <strong><?= (int)$col['dia_pago'] ?></strong> de cada mes
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Desglose salarial -->
        <div class="cv-card">
            <div class="cv-card-hdr">
                <span class="cv-card-hdr-title"><i class="bi bi-calculator text-success"></i>Desglose Salarial</span>
            </div>
            <div class="cv-card-body" style="padding:.75rem">
                <?php $lbl = $div === 2 ? 'quincena' : 'mes'; ?>
                <div class="salary-breakdown">
                    <div class="sb-row"><span class="info-lbl">Salario bruto/mes</span><span class="info-val">L
                            <?= number_format($salario, 2) ?></span></div>
                    <div class="sb-row"><span class="info-lbl" style="color:#dc2626">− IHSS
                            <?= $aplica_ihss ? '(3.5%)' : '' ?></span><span class="info-val"
                            style="color:#dc2626"><?= $aplica_ihss ? '-L ' . number_format($ihss_emp, 2) : '<span style="color:#94a3b8">No aplica</span>' ?></span>
                    </div>
                    <div class="sb-row"><span class="info-lbl" style="color:#dc2626">− RAP
                            <?= $aplica_rap ? '(1.5%)' : '' ?></span><span class="info-val"
                            style="color:#dc2626"><?= $aplica_rap ? '-L ' . number_format($rap_emp, 2) : '<span style="color:#94a3b8">No aplica</span>' ?></span>
                    </div>
                    <div class="sb-row total"><span style="font-weight:800;color:#059669">= Neto/<?= $lbl ?></span><span
                            style="font-size:1rem;font-weight:800;color:#059669">L
                            <?= number_format($neto_mes / $div, 2) ?></span></div>
                    <div class="sb-row patronal"><span class="info-lbl" style="color:#d97706">+ IHSS patronal
                            (7%)</span><span class="info-val"
                            style="color:#d97706"><?= $aplica_ihss ? 'L ' . number_format($ihss_pat / $div, 2) : '<span style="color:#94a3b8">—</span>' ?></span>
                    </div>
                    <div class="sb-row patronal"><span class="info-lbl" style="color:#d97706">+ RAP patronal
                            (1.5%)</span><span class="info-val"
                            style="color:#d97706"><?= $aplica_rap ? 'L ' . number_format($rap_pat / $div, 2) : '<span style="color:#94a3b8">—</span>' ?></span>
                    </div>
                    <div class="sb-row" style="background:#ede9fe"><span
                            style="font-weight:800;color:#6d28d9;font-size:.78rem">= Costo
                            empresa/<?= $lbl ?></span><span style="font-weight:800;color:#6d28d9">L
                            <?= number_format($costo_emp / $div, 2) ?></span></div>
                </div>
                <div class="mt-2 p-2 rounded-2"
                    style="background:#eff6ff;border:1px solid #bfdbfe;font-size:.78rem;color:#1e40af">
                    <i class="bi bi-info-circle me-1"></i>
                    <?php if ($tipo_pago === 'quincenal'): ?>
                        Días de pago: <strong><?= (int)$col['dia_pago'] ?></strong> y
                        <strong><?= (int)$col['dia_pago_2'] ?></strong> de cada mes
                    <?php else: ?>
                        Día de pago: <strong><?= (int)$col['dia_pago'] ?></strong> de cada mes
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div><!-- /left -->

    <!-- ── RIGHT column ────────────────────────────────────────────────── -->
    <div>

        <!-- Tabs -->
        <div class="cv-tabs no-print" id="mainTabs">
            <button class="cv-tab active" data-tab="pagos">
                <i class="bi bi-receipt"></i> Pagos
                <span class="tab-badge"><?= count($pagos) ?></span>
            </button>
            <button class="cv-tab" data-tab="prestamos">
                <i class="bi bi-hand-holding"></i> Préstamos
                <span class="tab-badge"><?= count($solo_prestamos) ?></span>
            </button>
            <button class="cv-tab" data-tab="bonos">
                <i class="bi bi-gift"></i> Bonos
                <span class="tab-badge"><?= count($solo_bonos) ?></span>
            </button>
            <button class="cv-tab" data-tab="viaticos">
                <i class="bi bi-airplane"></i> Viáticos
                <span class="tab-badge"><?= count($solo_viaticos) ?></span>
            </button>
        </div>

        <!-- ── TAB: Pagos ─────────────────────────────────────────────── -->
        <div class="tab-pane active" id="tab-pagos">
            <div class="cv-card">
                <div class="cv-card-hdr">
                    <span class="cv-card-hdr-title">
                        <i class="bi bi-receipt text-secondary"></i>
                        Historial de Pagos
                        <?php if ($filtro_todo): ?>
                            <small class="fw-normal text-muted" style="font-size:.75rem">· Todo el historial</small>
                        <?php else: ?>
                            <small class="fw-normal text-muted" style="font-size:.75rem">
                                · <?= $meses_nombres[$filtro_mes - 1] . ' ' . $filtro_anio ?>
                                <?= $filtro_tipo === '1' ? ' · 1ª Q' : ($filtro_tipo === '2' ? ' · 2ª Q' : '') ?>
                            </small>
                        <?php endif; ?>
                    </span>
                    <?php if ($col['activo']): ?>
                        <button class="btn-icon bi-teal btn-pagar-directo no-print"
                            style="font-size:.75rem;padding:.3rem .85rem">
                            <i class="bi bi-plus-lg"></i> Nuevo Pago
                        </button>
                    <?php endif; ?>
                </div>

                <?php if (empty($pagos)): ?>
                    <div class="empty-state">
                        <i class="bi bi-inbox empty-state-icon"></i>
                        <div class="fw-semibold">Sin pagos en este período</div>
                        <div style="font-size:.83rem;margin-top:.3rem">Selecciona otro mes o usa "Todo" para ver el
                            historial completo.</div>
                        <?php if ($col['activo']): ?>
                            <button class="btn btn-sm btn-success mt-3 btn-pagar-directo no-print">
                                <i class="bi bi-plus me-1"></i>Registrar primer pago
                            </button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div style="overflow-x:auto">
                        <table class="pay-table">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Concepto</th>
                                    <th class="text-center">Q</th>
                                    <th class="text-end">Monto</th>
                                    <th class="text-center">Método</th>
                                    <th class="text-center">Estado</th>
                                    <th class="text-center no-print" style="width:70px">Docs</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $metIco = ['efectivo' => '💵', 'transferencia' => '🏦', 'cheque' => '📝', 'tarjeta' => '💳', 'otro' => '🔷'];
                                foreach ($pagos as $p):
                                    $esNomina = strpos($p['descripcion'], 'Sueldo ') === 0;
                                    $esViat   = strpos($p['descripcion'], 'Viático: ') === 0;
                                    $esBono   = strpos($p['descripcion'], 'Bono: ') === 0;
                                ?>
                                    <tr class="<?= $p['estado'] === 'anulado' ? 'opacity-50' : '' ?>">
                                        <td>
                                            <div class="fw-semibold text-nowrap" style="font-size:.8rem">
                                                <?= date('d/m/Y', strtotime($p['fecha'])) ?></div>
                                            <div class="text-muted" style="font-size:.7rem">
                                                <?= date('D', strtotime($p['fecha'])) ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold <?= $p['estado'] === 'anulado' ? 'text-decoration-line-through text-muted' : '' ?>"
                                                style="font-size:.82rem">
                                                <?php if ($esNomina): ?><span class="concept-badge cb-nomina"><i
                                                            class="bi bi-cash me-1"></i>Nómina</span><?php endif; ?>
                                                <?php if ($esBono):   ?><span class="concept-badge cb-bono"><i
                                                            class="bi bi-gift me-1"></i>Bono</span><?php endif; ?>
                                                <?php if ($esViat):   ?><span class="concept-badge cb-viatico"><i
                                                            class="bi bi-airplane me-1"></i>Viático</span><?php endif; ?>
                                                <?= htmlspecialchars($p['descripcion']) ?>
                                            </div>
                                            <?php if ($p['notas']): ?>
                                                <div class="text-muted" style="font-size:.72rem"><i
                                                        class="bi bi-sticky me-1"></i><?= htmlspecialchars(mb_substr($p['notas'], 0, 55)) ?><?= strlen($p['notas']) > 55 ? '…' : '' ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php
                                            $qn = (int)($p['quincena_num'] ?? 0);
                                            if ($qn === 1)     echo '<span class="q-pill q1">1ª</span>';
                                            elseif ($qn === 2) echo '<span class="q-pill q2">2ª</span>';
                                            else             echo '<span class="q-pill qm">M</span>';
                                            ?>
                                        </td>
                                        <td class="text-end fw-bold <?= $p['estado'] === 'anulado' ? 'text-muted text-decoration-line-through' : ($p['monto'] > 0 ? '' : 'text-danger') ?>"
                                            style="color:<?= $p['estado'] !== 'anulado' && $p['monto'] > 0 ? '#059669' : '' ?>">
                                            L <?= number_format((float)$p['monto'], 2) ?>
                                        </td>
                                        <td class="text-center" style="font-size:1.1rem">
                                            <?= $metIco[$p['metodo_pago']] ?? '•' ?></td>
                                        <td class="text-center">
                                            <?php if ($p['estado'] === 'pagado'): ?>
                                                <span class="status-badge sb-pagado">Pagado</span>
                                            <?php elseif ($p['estado'] === 'pendiente'): ?>
                                                <span class="status-badge sb-pendiente">Pendiente</span>
                                            <?php else: ?>
                                                <span class="status-badge sb-anulado">Anulado</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center no-print">
                                            <div class="d-flex gap-1 justify-content-center">
                                                <?php if (!empty($p['archivo_adjunto'])): ?>
                                                    <a href="gasto_ver.php?id=<?= $p['id'] ?>" target="_blank"
                                                        class="btn btn-xs btn-outline-secondary"
                                                        style="font-size:10px;padding:2px 7px">
                                                        <i class="bi bi-paperclip"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <a href="colaborador_recibo_pdf.php?gasto_id=<?= $p['id'] ?>&vista=1"
                                                    target="_blank" class="btn btn-xs btn-outline-danger"
                                                    style="font-size:10px;padding:2px 7px">
                                                    <i class="bi bi-file-pdf"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="background:#f8fafc;font-size:.82rem">
                                    <td colspan="3" class="text-end fw-bold" style="padding:.65rem 1rem">TOTAL:</td>
                                    <td class="text-end fw-bold text-primary" style="padding:.65rem 1rem">L
                                        <?= number_format($total_pagado + $total_pend, 2) ?></td>
                                    <td colspan="3"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── TAB: Préstamos ─────────────────────────────────────────── -->
        <div class="tab-pane" id="tab-prestamos">
            <div class="cv-card">
                <div class="cv-card-hdr">
                    <span class="cv-card-hdr-title">
                        <i class="bi bi-hand-holding text-danger"></i> Préstamos & Adelantos
                        <?php if ($total_deuda_activa > 0): ?>
                            <span class="badge ms-1"
                                style="background:#fee2e2;color:#dc2626;border:1px solid #fecaca;font-size:.72rem">
                                Deuda: L <?= number_format($total_deuda_activa, 2) ?>
                            </span>
                        <?php endif; ?>
                    </span>
                    <?php if ($col['activo']): ?>
                        <button class="btn-icon bi-red no-print" id="btnNuevoPrestamo"
                            style="font-size:.75rem;padding:.3rem .85rem">
                            <i class="bi bi-plus-lg"></i> Registrar
                        </button>
                    <?php endif; ?>
                </div>

                <?php if (empty($solo_prestamos)): ?>
                    <div class="empty-state">
                        <i class="bi bi-coin empty-state-icon"></i>
                        <div class="fw-semibold">Sin préstamos registrados</div>
                        <?php if ($col['activo']): ?>
                            <button class="btn btn-sm btn-outline-danger mt-3 no-print" id="btnNuevoPrestamo2">
                                <i class="bi bi-plus me-1"></i>Registrar préstamo
                            </button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="p-2">
                        <?php foreach ($solo_prestamos as $pr):
                            $cuotas = $cuotas_por_prestamo[$pr['id']] ?? [];
                            $cuotas_pagadas = count(array_filter($cuotas, fn($c) => $c['estado'] === 'pagado'));
                            $proxima_cuota = null;
                            foreach ($cuotas as $c) {
                                if ($c['estado'] === 'pendiente') {
                                    $proxima_cuota = $c;
                                    break;
                                }
                            }
                            $pct = ($pr['monto_total'] > 0) ? min(100, round((($pr['monto_total'] - (float)$pr['saldo_pendiente']) / $pr['monto_total']) * 100)) : 100;
                            $tipo_cfg = [
                                'prestamo' => ['label' => 'Préstamo', 'color' => 'danger', 'icon' => 'bi-hand-holding'],
                                'adelanto' => ['label' => 'Adelanto', 'color' => 'warning', 'icon' => 'bi-lightning-fill'],
                                'multa'   => ['label' => 'Multa', 'color' => 'secondary', 'icon' => 'bi-slash-circle'],
                            ];
                            $tc = $tipo_cfg[$pr['tipo']] ?? $tipo_cfg['prestamo'];
                            $est_cfg = ['activo' => ['color' => 'primary', 'label' => 'Activo'], 'pagado' => ['color' => 'success', 'label' => 'Pagado'], 'cancelado' => ['color' => 'secondary', 'label' => 'Cancelado']];
                            $ec = $est_cfg[$pr['estado']] ?? $est_cfg['activo'];
                        ?>
                            <div class="loan-item" data-id="<?= $pr['id'] ?>">
                                <div class="loan-header" onclick="toggleLoan(<?= $pr['id'] ?>)">
                                    <span class="badge bg-<?= $tc['color'] ?> px-2" style="font-size:.7rem">
                                        <i class="bi <?= $tc['icon'] ?> me-1"></i><?= $tc['label'] ?>
                                    </span>
                                    <div class="fw-semibold flex-grow-1" style="font-size:.83rem;min-width:0">
                                        <?= htmlspecialchars($pr['descripcion']) ?>
                                        <small
                                            class="text-muted fw-normal ms-1"><?= date('d/m/Y', strtotime($pr['fecha'])) ?></small>
                                    </div>
                                    <div class="d-flex gap-3 align-items-center flex-shrink-0">
                                        <div class="text-end">
                                            <div class="text-muted" style="font-size:.65rem;text-transform:uppercase">SALDO
                                            </div>
                                            <div class="fw-bold text-danger" style="font-size:.85rem">L
                                                <?= number_format((float)$pr['saldo_pendiente'], 2) ?></div>
                                        </div>
                                        <div class="text-end">
                                            <div class="text-muted" style="font-size:.65rem;text-transform:uppercase">TOTAL
                                            </div>
                                            <div class="fw-bold" style="font-size:.85rem">L
                                                <?= number_format((float)$pr['monto_total'], 2) ?></div>
                                        </div>
                                        <span class="badge bg-<?= $ec['color'] ?>"
                                            style="font-size:.7rem"><?= $ec['label'] ?></span>
                                        <i class="bi bi-chevron-down text-muted loan-chevron" id="chev-<?= $pr['id'] ?>"
                                            style="transition:transform .2s"></i>
                                    </div>
                                </div>
                                <div class="loan-body" id="loan-body-<?= $pr['id'] ?>">
                                    <!-- Progress -->
                                    <?php if ((int)$pr['num_cuotas'] > 1): ?>
                                        <div class="d-flex align-items-center justify-content-between mb-1"
                                            style="font-size:.75rem;color:var(--muted)">
                                            <span><?= $cuotas_pagadas ?>/<?= $pr['num_cuotas'] ?> cuotas ·
                                                <?= ucfirst($pr['frecuencia_cuota'] ?? '') ?> · L
                                                <?= number_format((float)$pr['monto_cuota'], 2) ?>/cuota
                                                <?= $pr['descuento_auto'] ? '<span class="badge" style="background:#e0f2fe;color:#0369a1;font-size:.65rem">🔄 Auto</span>' : '' ?>
                                            </span>
                                            <span class="fw-bold"><?= $pct ?>%</span>
                                        </div>
                                        <div class="loan-progress">
                                            <div class="loan-progress-fill" style="width:<?= $pct ?>%"></div>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Próxima cuota -->
                                    <?php if ($proxima_cuota): ?>
                                        <div class="p-2 rounded-2 border d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2"
                                            style="background:#fff;font-size:.8rem">
                                            <div>
                                                <i class="bi bi-calendar-event me-1 text-warning"></i>
                                                <strong>Próxima cuota:</strong>
                                                <?= date('d/m/Y', strtotime($proxima_cuota['fecha_esperada'])) ?> · L
                                                <?= number_format((float)$proxima_cuota['monto'], 2) ?>
                                            </div>
                                            <button class="btn btn-success btn-pagar-cuota no-print"
                                                style="font-size:.72rem;padding:.2rem .65rem"
                                                data-cuota-id="<?= $proxima_cuota['id'] ?>"
                                                data-cuota-num="<?= $proxima_cuota['numero_cuota'] ?>"
                                                data-monto="<?= number_format((float)$proxima_cuota['monto'], 2) ?>">
                                                <i class="bi bi-check me-1"></i>Pagar
                                            </button>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Cuotas table -->
                                    <?php if (!empty($cuotas)): ?>
                                        <div style="overflow-x:auto;margin-bottom:.75rem">
                                            <table class="cuota-table">
                                                <thead>
                                                    <tr>
                                                        <th class="text-center">#</th>
                                                        <th>Fecha esp.</th>
                                                        <th class="text-end">Monto</th>
                                                        <th class="text-center">Estado</th>
                                                        <th>Pago</th>
                                                        <th class="no-print text-center">Acc.</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php
                                                    $metIco2 = ['efectivo' => '💵', 'transferencia' => '🏦', 'cheque' => '📝', 'tarjeta' => '💳', 'descuento_nomina' => '🔄', 'otro' => '🔷'];
                                                    foreach ($cuotas as $c):
                                                        $hoy_c = date('Y-m-d');
                                                    ?>
                                                        <tr class="<?= $c['estado'] === 'cancelado' ? 'opacity-50' : '' ?>">
                                                            <td class="text-center fw-bold"><?= $c['numero_cuota'] ?></td>
                                                            <td class="text-nowrap" style="font-size:.77rem">
                                                                <?= date('d/m/Y', strtotime($c['fecha_esperada'])) ?>
                                                                <?php if ($c['estado'] === 'pendiente' && $c['fecha_esperada'] < $hoy_c): ?>
                                                                    <span class="badge bg-danger ms-1"
                                                                        style="font-size:.62rem">Vencida</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="text-end fw-bold">L <?= number_format((float)$c['monto'], 2) ?>
                                                            </td>
                                                            <td class="text-center">
                                                                <?php if ($c['estado'] === 'pagado'): ?><span
                                                                        style="color:#059669;font-size:.85rem">✓</span>
                                                                <?php elseif ($c['estado'] === 'pendiente'): ?><span
                                                                        class="badge bg-warning text-dark"
                                                                        style="font-size:.65rem">Pend</span>
                                                                <?php else: ?><span class="badge bg-secondary"
                                                                        style="font-size:.65rem">Canc.</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td style="font-size:.75rem;color:var(--muted)">
                                                                <?= $c['fecha_pago'] ? date('d/m/Y', strtotime($c['fecha_pago'])) . '  ' . ($metIco2[$c['metodo_pago']] ?? '') : '—' ?>
                                                            </td>
                                                            <td class="text-center no-print">
                                                                <?php if ($c['estado'] === 'pendiente'): ?>
                                                                    <div class="d-flex gap-1 justify-content-center">
                                                                        <button class="btn btn-success btn-pagar-cuota"
                                                                            style="font-size:.65rem;padding:2px 8px"
                                                                            data-cuota-id="<?= $c['id'] ?>"
                                                                            data-cuota-num="<?= $c['numero_cuota'] ?>"
                                                                            data-monto="<?= number_format((float)$c['monto'], 2) ?>"><i
                                                                                class="bi bi-check"></i></button>
                                                                        <button class="btn btn-outline-secondary btn-editar-cuota"
                                                                            style="font-size:.65rem;padding:2px 8px"
                                                                            data-cuota-id="<?= $c['id'] ?>"
                                                                            data-cuota-num="<?= $c['numero_cuota'] ?>"
                                                                            data-monto="<?= number_format((float)$c['monto'], 2) ?>"
                                                                            data-fecha-esperada="<?= $c['fecha_esperada'] ?>"
                                                                            data-fecha-pago="<?= $c['fecha_pago'] ?? '' ?>"
                                                                            data-metodo="<?= $c['metodo_pago'] ?? '' ?>"
                                                                            data-estado="<?= $c['estado'] ?>"
                                                                            data-notas="<?= htmlspecialchars($c['notas'] ?? '') ?>"><i
                                                                                class="bi bi-pen"></i></button>
                                                                    </div>
                                                                <?php elseif ($c['estado'] === 'pagado'): ?>
                                                                    <button class="btn btn-outline-warning btn-editar-cuota"
                                                                        style="font-size:.65rem;padding:2px 8px"
                                                                        data-cuota-id="<?= $c['id'] ?>"
                                                                        data-cuota-num="<?= $c['numero_cuota'] ?>"
                                                                        data-monto="<?= number_format((float)$c['monto'], 2) ?>"
                                                                        data-fecha-esperada="<?= $c['fecha_esperada'] ?>"
                                                                        data-fecha-pago="<?= $c['fecha_pago'] ?? '' ?>"
                                                                        data-metodo="<?= $c['metodo_pago'] ?? '' ?>"
                                                                        data-estado="<?= $c['estado'] ?>"
                                                                        data-notas="<?= htmlspecialchars($c['notas'] ?? '') ?>"><i
                                                                            class="bi bi-pen"></i></button>
                                                                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Acciones -->
                                    <?php if ($pr['estado'] === 'activo'): ?>
                                        <div class="d-flex gap-2 flex-wrap no-print">
                                            <button class="btn btn-sm btn-outline-primary btn-editar-prestamo"
                                                data-prestamo-id="<?= $pr['id'] ?>" data-tipo="<?= $pr['tipo'] ?>"
                                                data-descripcion="<?= htmlspecialchars($pr['descripcion']) ?>"
                                                data-fecha="<?= $pr['fecha'] ?>"
                                                data-notas="<?= htmlspecialchars($pr['notas'] ?? '') ?>"
                                                data-descuento-auto="<?= (int)$pr['descuento_auto'] ?>"
                                                data-estado="<?= $pr['estado'] ?>">
                                                <i class="bi bi-pencil me-1"></i>Editar
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger btn-cancelar-prestamo"
                                                data-prestamo-id="<?= $pr['id'] ?>"
                                                data-desc="<?= htmlspecialchars($pr['descripcion']) ?>">
                                                <i class="bi bi-x-circle me-1"></i>Cancelar
                                            </button>
                                            <button class="btn btn-sm btn-danger btn-eliminar-prestamo"
                                                data-prestamo-id="<?= $pr['id'] ?>"
                                                data-desc="<?= htmlspecialchars($pr['descripcion']) ?>">
                                                <i class="bi bi-trash me-1"></i>Eliminar
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($pr['notas']): ?>
                                        <div class="mt-2 p-2 rounded-2"
                                            style="background:#f8fafc;font-size:.78rem;color:#555;border:1px solid var(--border)">
                                            <i
                                                class="bi bi-sticky me-1 text-secondary"></i><?= nl2br(htmlspecialchars($pr['notas'])) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── TAB: Bonos ─────────────────────────────────────────────── -->
        <div class="tab-pane" id="tab-bonos">
            <!-- Quick register card -->
            <?php if ($col['activo']): ?>
                <div class="cv-card" style="border-color:#a7f3d0">
                    <div class="cv-card-body" style="padding:.85rem 1.1rem">
                        <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
                            <div class="d-flex align-items-center gap-2">
                                <div
                                    style="width:36px;height:36px;border-radius:10px;background:#059669;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0">
                                    <i class="bi bi-gift"></i>
                                </div>
                                <div>
                                    <div class="fw-bold" style="font-size:.88rem">Registrar Bono / Gratificación</div>
                                    <div class="text-muted" style="font-size:.75rem">Se pagará junto con la próxima nómina
                                    </div>
                                </div>
                            </div>
                            <button class="btn btn-success btn-sm btn-nuevo-bono no-print px-3">
                                <i class="bi bi-plus-lg me-1"></i>Nuevo Bono
                            </button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="cv-card">
                <div class="cv-card-hdr">
                    <span class="cv-card-hdr-title"><i class="bi bi-gift text-success"></i>Bonos &
                        Gratificaciones</span>
                    <?php if ($total_bonos_pend > 0): ?>
                        <span class="badge"
                            style="background:#f0fdf4;color:#059669;border:1px solid #a7f3d0;font-size:.75rem;padding:.3rem .7rem">
                            Pendientes: L <?= number_format($total_bonos_pend, 2) ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php if (empty($solo_bonos)): ?>
                    <div class="empty-state">
                        <i class="bi bi-gift empty-state-icon"></i>
                        <div class="fw-semibold">Sin bonos registrados</div>
                        <?php if ($col['activo']): ?>
                            <button class="btn btn-sm btn-success mt-3 btn-nuevo-bono no-print">
                                <i class="bi bi-plus me-1"></i>Registrar bono
                            </button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="p-2">
                        <?php foreach ($solo_bonos as $pr):
                            $est_cfg2 = ['activo' => ['color' => 'warning text-dark', 'label' => 'Pendiente'], 'pagado' => ['color' => 'success', 'label' => 'Pagado'], 'cancelado' => ['color' => 'secondary', 'label' => 'Cancelado']];
                            $ec2 = $est_cfg2[$pr['estado']] ?? $est_cfg2['activo'];
                        ?>
                            <div class="p-3 rounded-2 border mb-2 d-flex align-items-center gap-3 flex-wrap"
                                style="background:<?= $pr['estado'] === 'pagado' ? '#f0fdf4' : '#fff' ?>;border-color:<?= $pr['estado'] === 'activo' ? '#a7f3d0' : 'var(--border)' ?>!important">
                                <div class="d-flex align-items-center gap-2 flex-grow-1">
                                    <div
                                        style="width:34px;height:34px;border-radius:9px;background:<?= $pr['estado'] === 'pagado' ? '#d1fae5' : '#f0fdf4' ?>;color:#059669;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0">
                                        <i class="bi bi-gift<?= $pr['estado'] === 'pagado' ? '-fill' : '' ?>"></i>
                                    </div>
                                    <div>
                                        <div class="fw-semibold" style="font-size:.85rem">
                                            <?= htmlspecialchars($pr['descripcion']) ?></div>
                                        <div class="text-muted" style="font-size:.73rem"><i
                                                class="bi bi-calendar3 me-1"></i><?= date('d/m/Y', strtotime($pr['fecha'])) ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <div class="fw-bold text-success" style="font-size:1rem">L
                                        <?= number_format((float)$pr['monto_total'], 2) ?></div>
                                    <span class="badge bg-<?= $ec2['color'] ?>"
                                        style="font-size:.7rem"><?= $ec2['label'] ?></span>
                                </div>
                                <?php if ($pr['estado'] === 'activo'): ?>
                                    <div class="d-flex gap-1 no-print">
                                        <button class="btn btn-sm btn-outline-danger btn-cancelar-prestamo"
                                            data-prestamo-id="<?= $pr['id'] ?>"
                                            data-desc="<?= htmlspecialchars($pr['descripcion']) ?>">
                                            <i class="bi bi-x"></i>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── TAB: Viáticos ──────────────────────────────────────────── -->
        <div class="tab-pane" id="tab-viaticos">
            <!-- Quick register card -->
            <?php if ($col['activo']): ?>
                <div class="cv-card" style="border-color:#7dd3fc">
                    <div class="cv-card-body" style="padding:.85rem 1.1rem">
                        <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
                            <div class="d-flex align-items-center gap-2">
                                <div
                                    style="width:36px;height:36px;border-radius:10px;background:#0369a1;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0">
                                    <i class="bi bi-airplane"></i>
                                </div>
                                <div>
                                    <div class="fw-bold" style="font-size:.88rem">Registrar Viático</div>
                                    <div class="text-muted" style="font-size:.75rem">Se liquidará junto con la próxima
                                        nómina</div>
                                </div>
                            </div>
                            <button class="btn btn-sm btn-info text-white btn-nuevo-viatico no-print px-3">
                                <i class="bi bi-plus-lg me-1"></i>Nuevo Viático
                            </button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="cv-card">
                <div class="cv-card-hdr">
                    <span class="cv-card-hdr-title"><i class="bi bi-airplane text-info"></i>Viáticos</span>
                    <?php if ($total_viaticos_pend > 0): ?>
                        <span class="badge"
                            style="background:#f0f9ff;color:#0369a1;border:1px solid #7dd3fc;font-size:.75rem;padding:.3rem .7rem">
                            Pendientes: L <?= number_format($total_viaticos_pend, 2) ?>
                        </span>
                    <?php endif; ?>
                </div>

                <?php if (empty($solo_viaticos)): ?>
                    <div class="empty-state">
                        <i class="bi bi-airplane empty-state-icon"></i>
                        <div class="fw-semibold">Sin viáticos registrados</div>
                        <?php if ($col['activo']): ?>
                            <button class="btn btn-sm btn-outline-info mt-3 btn-nuevo-viatico no-print">
                                <i class="bi bi-plus me-1"></i>Registrar viático
                            </button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="p-2">
                        <?php foreach ($solo_viaticos as $pr):
                            $est_cfg3 = ['activo' => ['label' => 'Pendiente', 'bg' => '#e0f2fe', 'color' => '#0369a1', 'border' => '#7dd3fc'], 'pagado' => ['label' => 'Liquidado', 'bg' => '#d1fae5', 'color' => '#059669', 'border' => '#a7f3d0'], 'cancelado' => ['label' => 'Cancelado', 'bg' => '#f1f5f9', 'color' => '#94a3b8', 'border' => '#e2e8f0']];
                            $ec3 = $est_cfg3[$pr['estado']] ?? $est_cfg3['activo'];
                        ?>
                            <div class="p-3 rounded-2 border mb-2 d-flex align-items-center gap-3 flex-wrap"
                                style="background:<?= $pr['estado'] === 'pagado' ? '#f0fdf4' : '#fff' ?>;border-color:<?= $pr['estado'] === 'activo' ? '#7dd3fc' : 'var(--border)' ?>!important">
                                <div class="d-flex align-items-center gap-2 flex-grow-1">
                                    <div
                                        style="width:34px;height:34px;border-radius:9px;background:<?= $pr['estado'] === 'pagado' ? '#d1fae5' : '#e0f2fe' ?>;color:<?= $pr['estado'] === 'pagado' ? '#059669' : '#0369a1' ?>;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0">
                                        <i class="bi bi-airplane<?= $pr['estado'] === 'pagado' ? '-fill' : '' ?>"></i>
                                    </div>
                                    <div>
                                        <div class="fw-semibold" style="font-size:.85rem">
                                            <?= htmlspecialchars($pr['descripcion']) ?></div>
                                        <div class="text-muted" style="font-size:.73rem">
                                            <i class="bi bi-calendar3 me-1"></i><?= date('d/m/Y', strtotime($pr['fecha'])) ?>
                                            <?php if ($pr['estado'] === 'activo'): ?>
                                                <span class="ms-2" style="color:#0369a1"><i class="bi bi-arrow-right me-1"></i>Suma
                                                    a la próxima nómina</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <div class="fw-bold" style="font-size:1rem;color:#0369a1">L
                                        <?= number_format((float)$pr['monto_total'], 2) ?></div>
                                    <span class="badge"
                                        style="background:<?= $ec3['bg'] ?>;color:<?= $ec3['color'] ?>;border:1px solid <?= $ec3['border'] ?>;font-size:.7rem"><?= $ec3['label'] ?></span>
                                </div>
                                <?php if ($pr['estado'] === 'activo'): ?>
                                    <div class="d-flex gap-1 no-print">
                                        <button class="btn btn-sm btn-outline-danger btn-cancelar-prestamo"
                                            data-prestamo-id="<?= $pr['id'] ?>"
                                            data-desc="<?= htmlspecialchars($pr['descripcion']) ?>">
                                            <i class="bi bi-x"></i>
                                        </button>
                                    </div>
                                <?php endif; ?>
                                <?php if ($pr['notas']): ?>
                                    <div class="w-100 text-muted"
                                        style="font-size:.75rem;border-top:1px dashed var(--border);padding-top:.4rem;margin-top:.25rem">
                                        <i class="bi bi-sticky me-1"></i><?= htmlspecialchars($pr['notas']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /right -->
</div><!-- /layout -->
</div>

<!-- ══ MODAL: Registrar Pago de Nómina ════════════════════════════════ -->
<div class="modal fade" id="modalPago" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header py-3" style="background:linear-gradient(135deg,#059669,#065f46)">
                <h5 class="modal-title fw-bold text-white"><i class="bi bi-cash-coin me-2"></i>Registrar Pago de Nómina
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form id="formPago" enctype="multipart/form-data">
                    <input type="hidden" name="colaborador_id" value="<?= $id ?>">

                    <!-- Desglose -->
                    <div class="dsg-strip mb-3">
                        <div class="dsg-item">
                            <div class="dsg-val">L <?= number_format($salario / $div, 2) ?></div>
                            <div class="dsg-lbl">Bruto</div>
                        </div>
                        <div class="dsg-sep">−</div>
                        <div class="dsg-item">
                            <div class="dsg-val text-danger">L <?= number_format($ihss_emp / $div, 2) ?></div>
                            <div class="dsg-lbl">IHSS</div>
                        </div>
                        <div class="dsg-sep">−</div>
                        <div class="dsg-item">
                            <div class="dsg-val text-danger">L <?= number_format($rap_emp / $div, 2) ?></div>
                            <div class="dsg-lbl">RAP</div>
                        </div>
                        <div class="dsg-sep">=</div>
                        <div class="dsg-item" id="dsg_neto_col">
                            <div class="dsg-val text-success" id="lblNetoModal">L <?= number_format($neto_mes / $div, 2) ?>
                            </div>
                            <div class="dsg-lbl fw-bold" id="lblNetoLbl">✓ Neto</div>
                        </div>
                        <div class="dsg-sep">+</div>
                        <div class="dsg-item">
                            <div class="dsg-val text-warning">L <?= number_format(($ihss_pat + $rap_pat) / $div, 2) ?></div>
                            <div class="dsg-lbl">Carga pat.</div>
                        </div>
                    </div>

                    <div id="pagoLoadingCuotas" class="text-center py-2 d-none">
                        <span class="spinner-border spinner-border-sm text-secondary me-2"></span>
                        <small class="text-muted">Verificando préstamos, bonos y viáticos…</small>
                    </div>

                    <!-- Cuotas -->
                    <?php if (!empty($cuotas_auto_pendientes)): ?>
                        <div class="box-deductions" id="boxCuotasDescuento">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <strong style="color:#dc2626;font-size:.81rem"><i
                                        class="bi bi-arrow-down-circle me-1"></i>Descuentos de préstamos</strong>
                                <small class="text-muted">Marca los que aplican</small>
                            </div>
                            <?php foreach ($cuotas_auto_pendientes as $ca):
                                $preChecked = in_array($ca['cuota_id'], array_column($cuotas_aplicables, 'cuota_id'));
                            ?>
                                <div class="chk-row">
                                    <div class="form-check mb-0 d-flex align-items-center gap-2 flex-grow-1">
                                        <input class="form-check-input cuota-chk mt-0" type="checkbox" name="cuotas_ids[]"
                                            id="chk_c_<?= $ca['cuota_id'] ?>" value="<?= $ca['cuota_id'] ?>"
                                            data-monto="<?= number_format((float)$ca['cuota_monto'], 4, '.', '') ?>"
                                            <?= $preChecked ? 'checked' : '' ?>>
                                        <label class="form-check-label text-muted" for="chk_c_<?= $ca['cuota_id'] ?>"
                                            style="font-size:.78rem;cursor:pointer">
                                            <?= htmlspecialchars(mb_substr($ca['prest_desc'], 0, 36)) ?>
                                            <span class="badge bg-secondary ms-1" style="font-size:.62rem">cuota
                                                #<?= $ca['numero_cuota'] ?></span>
                                        </label>
                                    </div>
                                    <span class="fw-bold text-danger text-nowrap" style="font-size:.78rem">-L
                                        <?= number_format((float)$ca['cuota_monto'], 2) ?></span>
                                </div>
                            <?php endforeach; ?>
                            <div class="d-flex justify-content-between mt-2 pt-1"
                                style="border-top:1px dashed #fecaca;font-size:.78rem">
                                <span class="fw-bold text-danger">Total descuento:</span>
                                <span class="fw-bold text-danger" id="lblTotalDescuento">-L
                                    <?= number_format($total_descuento_auto, 2) ?></span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Bonos AJAX -->
                    <div id="boxBonosAplicar" class="box-bonos d-none">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <strong style="color:#059669;font-size:.81rem"><i class="bi bi-gift me-1"></i>Bonos
                                pendientes</strong>
                            <small class="text-muted">Marca los que se pagan ahora</small>
                        </div>
                        <div id="listaBonosChk"></div>
                        <div class="d-flex justify-content-between mt-2 pt-1"
                            style="border-top:1px dashed #a7f3d0;font-size:.78rem">
                            <span class="fw-bold text-success">Total bonos:</span>
                            <span class="fw-bold text-success" id="lblTotalBonosPago">+L 0.00</span>
                        </div>
                    </div>

                    <!-- Viáticos AJAX -->
                    <div id="boxViaticosAplicar" class="box-viaticos d-none">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <strong style="color:#0369a1;font-size:.81rem"><i class="bi bi-airplane me-1"></i>Viáticos
                                pendientes de liquidar</strong>
                            <small class="text-muted">Marca los que se liquidan ahora</small>
                        </div>
                        <div id="listaViaticosChk"></div>
                        <div class="d-flex justify-content-between mt-2 pt-1"
                            style="border-top:1px dashed #7dd3fc;font-size:.78rem">
                            <span class="fw-bold" style="color:#0369a1">Total viáticos:</span>
                            <span class="fw-bold" id="lblTotalViaticos" style="color:#0369a1">+L 0.00</span>
                        </div>
                    </div>

                    <!-- Total -->
                    <div class="total-pagar-box" id="totalPagarBox">
                        <span style="font-size:.88rem;opacity:.9"><i class="bi bi-check-circle me-1"></i>Total a
                            pagar:</span>
                        <span id="lblTotalAPagar" style="font-size:1.1rem">L
                            <?= number_format($neto_a_pagar_real, 2) ?></span>
                    </div>

                    <!-- Quincena -->
                    <?php if ($tipo_pago === 'quincenal'): ?>
                        <div class="mt-3 mb-2">
                            <label class="mf-label">¿Qué pago es?</label>
                            <div class="d-flex gap-3 flex-wrap">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="quincena" id="pq1" value="1"
                                        <?= $quincena_sugerida === 1 ? 'checked' : '' ?> <?= $q1_pagada ? 'disabled' : '' ?>>
                                    <label class="form-check-label <?= $q1_pagada ? 'opacity-50' : '' ?>" for="pq1">
                                        <span class="badge bg-primary">1ª Quincena</span>
                                        <small class="text-muted ms-1">día <?= (int)$col['dia_pago'] ?></small>
                                        <?php if ($q1_pagada): ?><span class="badge bg-success ms-1"
                                                style="font-size:.65rem"><i class="bi bi-check"></i>
                                                Pagada</span><?php endif; ?>
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="quincena" id="pq2" value="2"
                                        <?= $quincena_sugerida === 2 ? 'checked' : '' ?> <?= $q2_pagada ? 'disabled' : '' ?>>
                                    <label class="form-check-label <?= $q2_pagada ? 'opacity-50' : '' ?>" for="pq2">
                                        <span class="badge bg-info text-dark">2ª Quincena</span>
                                        <small class="text-muted ms-1">día <?= (int)$col['dia_pago_2'] ?></small>
                                        <?php if ($q2_pagada): ?><span class="badge bg-success ms-1"
                                                style="font-size:.65rem"><i class="bi bi-check"></i>
                                                Pagada</span><?php endif; ?>
                                    </label>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="quincena" value="0">
                    <?php endif; ?>

                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <label class="mf-label">Fecha del pago</label>
                            <input type="date" name="fecha" id="pago_fecha" class="mf-input"
                                value="<?= date('Y-m-d') ?>">
                            <div id="estadoPagoFecha" class="mt-1" style="font-size:.75rem"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="mf-label">Método de pago</label>
                            <select name="metodo_pago" class="mf-select">
                                <option value="transferencia">🏦 Transferencia</option>
                                <option value="efectivo">💵 Efectivo</option>
                                <option value="cheque">📝 Cheque</option>
                                <option value="tarjeta">💳 Tarjeta</option>
                                <option value="otro">🔷 Otro</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="mf-label">Notas <span class="text-muted fw-normal"
                                    style="text-transform:none;letter-spacing:0">(opcional)</span></label>
                            <textarea name="notas" id="pago_notas" class="mf-input" rows="2"
                                style="height:auto;resize:vertical"
                                placeholder="N° transferencia, banco, referencia…"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="mf-label"><i class="bi bi-paperclip me-1 text-secondary"></i>Comprobante <span
                                    class="text-muted fw-normal" style="text-transform:none;letter-spacing:0">(opcional
                                    · JPG, PNG, PDF · máx 5 MB)</span></label>
                            <div id="zonaComprobante" class="upload-zone"
                                ondragover="event.preventDefault();this.style.borderColor='#059669';this.style.background='#f0fff4'"
                                ondragleave="this.style.borderColor='';this.style.background=''"
                                ondrop="handleDrop(event)">
                                <i class="bi bi-cloud-upload text-secondary opacity-50 d-block mb-1"
                                    style="font-size:1.8rem"></i>
                                <div class="small text-muted">Arrastra aquí o <span class="text-success fw-semibold"
                                        style="cursor:pointer"
                                        onclick="document.getElementById('pago_comprobante').click()">selecciona</span>
                                </div>
                                <input type="file" id="pago_comprobante" name="comprobante"
                                    accept=".jpg,.jpeg,.png,.webp,.pdf" class="d-none">
                            </div>
                            <div id="previewComprobante" class="mt-2 d-none">
                                <div class="d-flex align-items-center gap-2 p-2 rounded-2 border"
                                    style="background:#f8f9fa">
                                    <div id="prevIcono" style="font-size:20px"></div>
                                    <div class="flex-grow-1 overflow-hidden">
                                        <div id="prevNombre" class="small fw-semibold text-truncate"></div>
                                        <div id="prevTamaño" class="text-muted" style="font-size:.72rem"></div>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                        onclick="limpiarComprobante()"><i class="bi bi-x-lg"></i></button>
                                </div>
                                <div id="prevImagen" class="mt-1 d-none"><img id="prevImg" src="" alt=""
                                        class="rounded-2 border"
                                        style="max-height:100px;max-width:100%;object-fit:cover"></div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-top">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success px-4" id="btnConfirmarPago">
                    <i class="bi bi-check-circle me-1"></i>Confirmar Pago
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: Editar Colaborador -->
<div class="modal fade" id="modalEditarColab" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom py-3" style="background:linear-gradient(135deg,#0d6efd,#6610f2)">
                <h5 class="modal-title fw-bold text-white"><i class="bi bi-person-gear me-2"></i>Editar Colaborador</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form id="formEditarColab">
                    <input type="hidden" name="colaborador_id" value="<?= $id ?>">
                    <div class="row g-3">
                        <div class="col-md-5"><label class="mf-label">Nombre *</label><input type="text" name="nombre"
                                class="mf-input" value="<?= htmlspecialchars($col['nombre']) ?>" required></div>
                        <div class="col-md-5"><label class="mf-label">Apellido *</label><input type="text"
                                name="apellido" class="mf-input" value="<?= htmlspecialchars($col['apellido']) ?>"
                                required></div>
                        <div class="col-md-2"><label class="mf-label">DPI</label><input type="text" name="dpi"
                                class="mf-input" value="<?= htmlspecialchars($col['dpi'] ?? '') ?>"></div>
                        <div class="col-md-4"><label class="mf-label">Teléfono</label><input type="text" name="telefono"
                                class="mf-input" value="<?= htmlspecialchars($col['telefono'] ?? '') ?>"></div>
                        <div class="col-md-5"><label class="mf-label">Email</label><input type="email" name="email"
                                class="mf-input" value="<?= htmlspecialchars($col['email'] ?? '') ?>"></div>
                        <div class="col-md-3"><label class="mf-label">Fecha Ingreso *</label><input type="date"
                                name="fecha_ingreso" class="mf-input" value="<?= $col['fecha_ingreso'] ?>" required>
                        </div>
                        <div class="col-md-5"><label class="mf-label">Puesto *</label><input type="text" name="puesto"
                                class="mf-input" value="<?= htmlspecialchars($col['puesto']) ?>" required></div>
                        <div class="col-md-4"><label class="mf-label">Departamento</label><input type="text"
                                name="departamento" class="mf-input"
                                value="<?= htmlspecialchars($col['departamento'] ?? '') ?>"></div>
                        <div class="col-md-3"><label class="mf-label">Categoría</label>
                            <select name="categoria_gasto_id" class="mf-select">
                                <option value="">— Sin categoría —</option>
                                <?php foreach ($categorias as $cat): ?>
                                    <option value="<?= $cat['id'] ?>"
                                        <?= $cat['id'] == ($col['categoria_gasto_id'] ?? 0) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cat['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4"><label class="mf-label">Salario Bruto *</label>
                            <div class="input-group"><span class="input-group-text">L</span><input type="number"
                                    name="salario_base" class="form-control" min="1" step="0.01"
                                    value="<?= number_format($salario, 2, '.', '') ?>" required></div>
                        </div>
                        <div class="col-md-4"><label class="mf-label">Tipo de Pago</label>
                            <select name="tipo_pago" id="edit_tipo_pago" class="mf-select">
                                <option value="quincenal" <?= $tipo_pago === 'quincenal' ? 'selected' : '' ?>>🔄 Quincenal
                                </option>
                                <option value="mensual" <?= $tipo_pago === 'mensual' ? 'selected' : ''   ?>>📅 Mensual
                                </option>
                            </select>
                        </div>
                        <div class="col-md-2"><label class="mf-label">1er Día</label><input type="number"
                                name="dia_pago" class="mf-input" min="1" max="31" value="<?= (int)$col['dia_pago'] ?>">
                        </div>
                        <div class="col-md-2" id="grp_dia2_edit"
                            <?= $tipo_pago !== 'quincenal' ? 'style="display:none"' : '' ?>>
                            <label class="mf-label">2° Día</label><input type="number" name="dia_pago_2"
                                class="mf-input" min="1" max="31" value="<?= (int)$col['dia_pago_2'] ?>">
                        </div>
                        <div class="col-12">
                            <div class="d-flex gap-3">
                                <div class="form-check"><input class="form-check-input" type="checkbox"
                                        name="aplica_ihss" id="edit_ihss" value="1"
                                        <?= $aplica_ihss ? 'checked' : '' ?>><label class="form-check-label"
                                        for="edit_ihss"><span class="badge bg-warning text-dark">IHSS</span></label>
                                </div>
                                <div class="form-check"><input class="form-check-input" type="checkbox"
                                        name="aplica_rap" id="edit_rap" value="1" <?= $aplica_rap ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="edit_rap"><span
                                            class="badge bg-info text-dark">RAP</span></label>
                                </div>
                            </div>
                        </div>
                        <div class="col-12"><label class="mf-label">Notas</label><textarea name="notas" class="mf-input"
                                rows="2" maxlength="500"><?= htmlspecialchars($col['notas'] ?? '') ?></textarea></div>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-top">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary px-4" id="btnGuardarEdicion"><i
                        class="bi bi-floppy me-1"></i>Guardar Cambios</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: Nuevo Movimiento (préstamo/adelanto/bono/viático/multa) -->
<div class="modal fade" id="modalPrestamo" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header py-3" id="modalPrestHdr"
                style="background:linear-gradient(135deg,#dc3545,#b02a37)">
                <h5 class="modal-title fw-bold text-white" id="modalPrestTitulo"><i
                        class="bi bi-hand-holding me-2"></i>Registrar Movimiento</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form id="formPrestamo">
                    <input type="hidden" name="colaborador_id" value="<?= $id ?>">
                    <div class="mb-3">
                        <label class="mf-label">Tipo de Movimiento *</label>
                        <?php
                        $tc_map = [
                            'prestamo' => ['ib' => 'bi-hand-index-thumb-fill', 'cls' => 'tc-prestamo'],
                            'adelanto' => ['ib' => 'bi-lightning-charge-fill', 'cls' => 'tc-adelanto'],
                            'bono'    => ['ib' => 'bi-gift-fill',             'cls' => 'tc-bono'],
                            'viatico' => ['ib' => 'bi-airplane-fill',         'cls' => 'tc-viatico'],
                            'multa'   => ['ib' => 'bi-slash-circle-fill',     'cls' => 'tc-multa'],
                        ];
                        ?>
                        <div class="tipo-selector" id="tipoSelectorNew">
                            <?php foreach ($tipos_btn_p as $tb):
                                $tm = $tc_map[$tb['val']] ?? ['ib' => 'bi-circle', 'cls' => 'tc-multa'];
                                $sel = $tb['val'] === 'prestamo';
                            ?>
                                <div class="tipo-card <?= $tm['cls'] ?> <?= $sel ? 'tc-selected' : '' ?>"
                                    data-val="<?= $tb['val'] ?>" data-group="new">
                                    <input type="radio" class="tipo-radio" name="tipo" id="tipo_<?= $tb['val'] ?>"
                                        value="<?= $tb['val'] ?>" <?= $sel ? 'checked' : '' ?>>
                                    <div class="tc-icon-wrap"><i class="bi <?= $tm['ib'] ?>"></i></div>
                                    <div class="tc-label"><?= $tb['label'] ?></div>
                                    <div class="tc-desc"><?= $tb['desc'] ?></div>
                                    <div class="tc-check"><i class="bi bi-check-circle-fill"></i></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-8"><label class="mf-label">Descripción *</label><input type="text"
                                name="descripcion" id="prest_desc" class="mf-input" maxlength="300" required
                                placeholder="Ej: Viático para visita cliente SPS"></div>
                        <div class="col-md-4"><label class="mf-label">Fecha *</label><input type="date" name="fecha"
                                class="mf-input" value="<?= date('Y-m-d') ?>"></div>
                        <div class="col-md-4" id="grp_fecha_primera_cuota"><label class="mf-label">Fecha 1ª cuota
                                *</label><input type="date" name="fecha_primera_cuota" id="prest_fecha1cuota"
                                class="mf-input" value="<?= date('Y-m-d') ?>"><small class="text-muted"
                                style="font-size:.72rem">Desde aquí se calculan las cuotas</small></div>
                        <div class="col-md-5"><label class="mf-label">Monto Total *</label>
                            <div class="input-group"><span class="input-group-text">L</span><input type="number"
                                    name="monto_total" id="prest_monto" class="form-control" min="1" step="0.01"
                                    placeholder="0.00" required></div>
                        </div>
                        <div class="col-md-3" id="grp_num_cuotas"><label class="mf-label">N° Cuotas</label><input
                                type="number" name="num_cuotas" id="prest_cuotas" class="mf-input" min="1" max="120"
                                value="1"></div>
                        <div class="col-md-4" id="grp_frecuencia"><label class="mf-label">Frecuencia</label>
                            <select name="frecuencia_cuota" class="mf-select">
                                <option value="mensual">📅 Mensual</option>
                                <option value="quincenal">🔄 Quincenal</option>
                            </select>
                        </div>
                        <div class="col-12" id="grp_preview_cuota">
                            <div class="p-2 rounded-2 border" style="background:#eff6ff;font-size:.82rem"><i
                                    class="bi bi-calculator me-1 text-primary"></i>Cuota aproximada: <strong
                                    id="valorCuota">—</strong></div>
                        </div>
                        <div class="col-12" id="grp_auto">
                            <div class="form-check form-switch"><input class="form-check-input" type="checkbox"
                                    name="descuento_auto" id="prest_auto" value="1">
                                <label class="form-check-label" for="prest_auto"><i
                                        class="bi bi-arrow-repeat me-1 text-info"></i><strong>Descontar
                                        automáticamente</strong> al registrar nómina<small
                                        class="text-muted d-block ms-4" style="font-size:.75rem">La cuota se restará del
                                        neto al procesar nómina.</small></label>
                            </div>
                        </div>
                        <!-- Info especial para viático -->
                        <div class="col-12 d-none" id="info_viatico">
                            <div class="p-2 rounded-2"
                                style="background:#f0f9ff;border:1px solid #7dd3fc;font-size:.82rem;color:#0369a1">
                                <i class="bi bi-info-circle me-1"></i><strong>Viático:</strong> El monto se sumará
                                automáticamente al próximo pago de nómina de este colaborador. No genera cuotas de
                                descuento.
                            </div>
                        </div>
                        <!-- Info especial para bono -->
                        <div class="col-12 d-none" id="info_bono">
                            <div class="p-2 rounded-2"
                                style="background:#f0fdf4;border:1px solid #a7f3d0;font-size:.82rem;color:#059669">
                                <i class="bi bi-info-circle me-1"></i><strong>Bono/Gratificación:</strong> Se pagará
                                junto con la próxima nómina. No genera cuotas de descuento.
                            </div>
                        </div>
                        <div class="col-12"><label class="mf-label">Notas <span class="text-muted fw-normal"
                                    style="text-transform:none;letter-spacing:0">(opcional)</span></label><textarea
                                name="notas" class="mf-input" rows="2" maxlength="500"
                                placeholder="Motivo, condiciones, destino del viático…"
                                style="height:auto;resize:vertical"></textarea></div>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-top">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger px-4" id="btnGuardarPrestamo"><i
                        class="bi bi-check-circle me-1"></i>Guardar</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: Pagar cuota -->
<div class="modal fade" id="modalPagarCuota" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content border-0 shadow">
            <div class="modal-header py-3" style="background:#f0fdf4;border-bottom:1px solid #a7f3d0">
                <h6 class="modal-title fw-bold text-success"><i class="bi bi-check-circle me-2"></i>Pagar Cuota</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <p class="mb-3 text-muted small">Cuota <strong id="pagarCuotaNum"></strong> — <strong
                        class="text-success" id="pagarCuotaMonto"></strong></p>
                <form id="formPagarCuota">
                    <input type="hidden" name="cuota_id" id="pagarCuotaId">
                    <div class="mb-2"><label class="mf-label">Fecha de Pago</label><input type="date" name="fecha_pago"
                            class="mf-input" value="<?= date('Y-m-d') ?>"></div>
                    <div class="mb-2"><label class="mf-label">Método</label>
                        <select name="metodo_pago" class="mf-select">
                            <option value="efectivo">💵 Efectivo</option>
                            <option value="transferencia">🏦 Transferencia</option>
                            <option value="descuento_nomina" selected>🔄 Descuento nómina</option>
                            <option value="cheque">📝 Cheque</option>
                            <option value="otro">🔷 Otro</option>
                        </select>
                    </div>
                    <div><label class="mf-label">Notas</label><input type="text" name="notas" class="mf-input"
                            placeholder="Referencia…"></div>
                </form>
            </div>
            <div class="modal-footer border-top py-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success btn-sm px-3" id="btnConfirmarCuota"><i
                        class="bi bi-check me-1"></i>Confirmar</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: Editar Préstamo -->
<div class="modal fade" id="modalEditarPrestamo" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header py-3" style="background:linear-gradient(135deg,#dc3545,#b02a37)">
                <h5 class="modal-title fw-bold text-white"><i class="bi bi-pencil-square me-2"></i>Editar Movimiento
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form id="formEditarPrestamo">
                    <input type="hidden" name="prestamo_id" id="editPrestId">
                    <div class="mb-3"><label class="mf-label">Tipo</label>
                        <div class="tipo-selector" id="tipoSelectorEdit">
                            <?php foreach ($tipos_btn_p as $tb):
                                $tm2 = $tc_map[$tb['val']] ?? ['ib' => 'bi-circle', 'cls' => 'tc-multa'];
                            ?>
                                <div class="tipo-card <?= $tm2['cls'] ?>" data-val="<?= $tb['val'] ?>" data-group="edit">
                                    <input type="radio" class="tipo-radio" name="tipo" id="edit_tipo_<?= $tb['val'] ?>"
                                        value="<?= $tb['val'] ?>">
                                    <div class="tc-icon-wrap"><i class="bi <?= $tm2['ib'] ?>"></i></div>
                                    <div class="tc-label"><?= $tb['label'] ?></div>
                                    <div class="tc-check"><i class="bi bi-check-circle-fill"></i></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="mb-3"><label class="mf-label">Descripción *</label><input type="text" name="descripcion"
                            id="editPrestDesc" class="mf-input" maxlength="300" required></div>
                    <div class="row g-3">
                        <div class="col-md-5"><label class="mf-label">Fecha</label><input type="date" name="fecha"
                                id="editPrestFecha" class="mf-input"></div>
                        <div class="col-md-7"><label class="mf-label">Estado</label>
                            <select name="estado" id="editPrestEstado" class="mf-select">
                                <option value="activo">✅ Activo</option>
                                <option value="pagado">💚 Pagado (cierre manual)</option>
                                <option value="cancelado">❌ Cancelado</option>
                            </select>
                            <div id="avisoEstado" class="mt-1 p-2 rounded-2 d-none" style="font-size:.78rem"></div>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch"><input class="form-check-input" type="checkbox"
                                    name="descuento_auto" id="editPrestAuto" value="1"><label class="form-check-label"
                                    for="editPrestAuto"><i
                                        class="bi bi-arrow-repeat me-1 text-info"></i><strong>Descontar
                                        automáticamente</strong> al registrar nómina</label></div>
                        </div>
                        <div class="col-12"><label class="mf-label">Notas</label><textarea name="notas"
                                id="editPrestNotas" class="mf-input" rows="2" maxlength="500"
                                style="height:auto;resize:vertical"></textarea></div>
                    </div>
                    <div class="mt-2 p-2 rounded-2 border" style="background:#fff8f0;font-size:.78rem;color:#856404"><i
                            class="bi bi-exclamation-triangle me-1"></i>El monto total y cuotas no son editables.</div>
                </form>
            </div>
            <div class="modal-footer border-top">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary px-4" id="btnGuardarEditPrest"><i
                        class="bi bi-floppy me-1"></i>Guardar Cambios</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: Editar Cuota -->
<div class="modal fade" id="modalEditarCuota" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content border-0 shadow">
            <div class="modal-header py-3" style="background:linear-gradient(135deg,#6f42c1,#0d6efd)">
                <h6 class="modal-title fw-bold text-white"><i class="bi bi-pencil-square me-2"></i>Editar Cuota <span
                        id="editCuotaTitulo" class="opacity-75"></span></h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div id="alertaReversion" class="alert alert-warning py-2 d-none" style="font-size:.78rem"><i
                        class="bi bi-exclamation-triangle me-1"></i><strong>Reversión:</strong> aumentará el saldo del
                    préstamo.</div>
                <form id="formEditarCuota">
                    <input type="hidden" name="cuota_id" id="editCuotaId">
                    <div class="mb-2"><label class="mf-label">Estado</label><select name="estado" id="editCuotaEstado"
                            class="mf-select">
                            <option value="pendiente">⏳ Pendiente</option>
                            <option value="pagado">✅ Pagado</option>
                            <option value="cancelado">❌ Cancelado</option>
                        </select></div>
                    <div class="mb-2"><label class="mf-label">Fecha esperada</label><input type="date"
                            name="fecha_esperada" id="editCuotaFechaEsp" class="mf-input"></div>
                    <div id="grpPagoEdit">
                        <div class="mb-2"><label class="mf-label">Fecha de pago</label><input type="date"
                                name="fecha_pago" id="editCuotaFechaPago" class="mf-input"></div>
                        <div class="mb-2"><label class="mf-label">Método</label><select name="metodo_pago"
                                id="editCuotaMetodo" class="mf-select">
                                <option value="efectivo">💵 Efectivo</option>
                                <option value="transferencia">🏦 Transferencia</option>
                                <option value="descuento_nomina">🔄 Descuento nómina</option>
                                <option value="cheque">📝 Cheque</option>
                                <option value="otro">🔷 Otro</option>
                            </select></div>
                    </div>
                    <div><label class="mf-label">Notas</label><input type="text" name="notas" id="editCuotaNotas"
                            class="mf-input"></div>
                </form>
            </div>
            <div class="modal-footer border-top py-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary btn-sm px-3" id="btnGuardarEditCuota"><i
                        class="bi bi-floppy me-1"></i>Guardar</button>
            </div>
        </div>
    </div>
</div>

<script>
    /* ══ TAB SYSTEM ════════════════════════════════════════════════════════════ */
    function setTab(name) {
        document.querySelectorAll('.cv-tab').forEach(t => {
            t.classList.toggle('active', t.dataset.tab === name);
        });
        document.querySelectorAll('.tab-pane').forEach(p => {
            p.classList.toggle('active', p.id === 'tab-' + name);
        });
    }
    document.querySelectorAll('.cv-tab').forEach(btn => {
        btn.addEventListener('click', () => setTab(btn.dataset.tab));
    });

    /* ══ LOAN ACCORDION ════════════════════════════════════════════════════════ */
    function toggleLoan(id) {
        const body = document.getElementById('loan-body-' + id);
        const hdr = body.previousElementSibling;
        const chev = document.getElementById('chev-' + id);
        const open = body.classList.toggle('open');
        hdr.classList.toggle('open', open);
        if (chev) chev.style.transform = open ? 'rotate(180deg)' : '';
    }

    /* ══ HELPERS ═══════════════════════════════════════════════════════════════ */
    const _netoPago = <?= number_format($neto_quincena, 4, '.', '') ?>;
    const _diaPago1 = <?= (int)$col['dia_pago'] ?>;
    const _diaPago2 = <?= (int)($col['dia_pago_2'] ?? 0) ?>;
    const _tipoPago = '<?= $col['tipo_pago'] ?>';

    function nFmt(n) {
        return 'L ' + parseFloat(n).toLocaleString('es-HN', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    /* ══ RECALCULAR PAGO ═══════════════════════════════════════════════════════ */
    function recalcular() {
        let desc = 0,
            bonos = 0,
            viats = 0;
        document.querySelectorAll('.cuota-chk:checked').forEach(c => desc += parseFloat(c.dataset.monto) || 0);
        desc = Math.min(desc, _netoPago);
        document.querySelectorAll('#listaBonosChk .bono-chk:checked').forEach(b => bonos += parseFloat(b.dataset.monto) ||
            0);
        document.querySelectorAll('#listaViaticosChk .viatico-chk:checked').forEach(v => viats += parseFloat(v.dataset
            .monto) || 0);
        const aPagar = Math.max(0, _netoPago - desc + bonos + viats);
        const elDesc = document.getElementById('lblTotalDescuento');
        if (elDesc) elDesc.textContent = '-L ' + nFmt(desc).replace('L ', '');
        document.getElementById('lblTotalBonosPago').textContent = '+' + nFmt(bonos);
        document.getElementById('lblTotalViaticos').textContent = '+' + nFmt(viats);
        document.getElementById('lblTotalAPagar').textContent = nFmt(aPagar);
        const lblN = document.getElementById('lblNetoModal');
        const lblL = document.getElementById('lblNetoLbl');
        if (desc > 0 || bonos > 0 || viats > 0) {
            lblN.classList.add('text-muted', 'text-decoration-line-through');
            lblN.classList.remove('text-success');
            lblL.textContent = 'Neto base';
        } else {
            lblN.classList.remove('text-muted', 'text-decoration-line-through');
            lblN.classList.add('text-success');
            lblL.textContent = '✓ Neto';
        }
    }
    document.addEventListener('change', e => {
        if (e.target.classList.contains('cuota-chk') || e.target.classList.contains('bono-chk') || e.target
            .classList.contains('viatico-chk'))
            recalcular();
    });

    /* ══ FECHA PAGO ════════════════════════════════════════════════════════════ */
    function verificarVencimientoPago() {
        const fv = document.getElementById('pago_fecha').value;
        if (!fv) {
            document.getElementById('estadoPagoFecha').innerHTML = '';
            return;
        }
        const f = new Date(fv + 'T00:00:00');
        const q = _tipoPago === 'quincenal' ? parseInt(document.querySelector('[name=quincena]:checked')?.value || 1) : 0;
        const diaProg = _tipoPago === 'quincenal' ? (q === 2 ? _diaPago2 : _diaPago1) : _diaPago1;
        const diasMes = new Date(f.getFullYear(), f.getMonth() + 1, 0).getDate();
        const fProg = new Date(f.getFullYear(), f.getMonth(), Math.min(diaProg, diasMes));
        const diff = Math.round((f - fProg) / 86400000);
        const el = document.getElementById('estadoPagoFecha');
        if (diff > 0) el.innerHTML =
            `<span class="badge" style="background:#fee2e2;color:#dc2626;border:1px solid #fecaca"><i class="bi bi-clock-history me-1"></i>Vencido <strong>${diff} día(s)</strong></span>`;
        else if (diff === 0) el.innerHTML =
            `<span class="badge" style="background:#d1fae5;color:#059669;border:1px solid #a7f3d0"><i class="bi bi-check-circle me-1"></i>En fecha programada</span>`;
        else el.innerHTML =
            `<span class="badge" style="background:#dbeafe;color:#1d4ed8;border:1px solid #bfdbfe"><i class="bi bi-calendar-check me-1"></i>Adelantado ${Math.abs(diff)} día(s)</span>`;
    }
    document.getElementById('pago_fecha')?.addEventListener('change', verificarVencimientoPago);
    document.addEventListener('change', e => {
        if (e.target.name === 'quincena') verificarVencimientoPago();
    });

    /* ══ ABRIR MODAL PAGO ══════════════════════════════════════════════════════ */
    function abrirModalPago(quincenaPreset) {
        quincenaPreset = parseInt(quincenaPreset) || 0;
        limpiarComprobante();
        document.getElementById('pago_notas').value = '';
        document.getElementById('estadoPagoFecha').innerHTML = '';
        document.getElementById('listaBonosChk').innerHTML = '';
        document.getElementById('listaViaticosChk').innerHTML = '';
        document.getElementById('boxBonosAplicar').classList.add('d-none');
        document.getElementById('boxViaticosAplicar').classList.add('d-none');
        document.getElementById('lblNetoModal').classList.remove('text-muted', 'text-decoration-line-through');
        document.getElementById('lblNetoModal').classList.add('text-success');
        document.getElementById('lblNetoLbl').textContent = '✓ Neto';
        // Pre-select quincena if provided
        if (quincenaPreset === 1 || quincenaPreset === 2) {
            const r = document.getElementById('pq' + quincenaPreset);
            if (r && !r.disabled) r.checked = true;
        }
        recalcular();
        new bootstrap.Modal(document.getElementById('modalPago')).show();
        setTimeout(verificarVencimientoPago, 120);

        const fechaRef = new Date().toISOString().slice(0, 10);
        document.getElementById('pagoLoadingCuotas').classList.remove('d-none');
        fetch(`includes/colaborador_cuotas_info.php?colab_id=<?= $id ?>&fecha_ref=${fechaRef}`)
            .then(r => r.json()).then(data => {
                document.getElementById('pagoLoadingCuotas').classList.add('d-none');
                if (!data.success) return;
                if (_tipoPago === 'quincenal') {
                    if (data.q1_pagada) {
                        document.getElementById('pq1').disabled = true;
                        document.querySelector('#pq1+label').style.opacity = '.45';
                    }
                    if (data.q2_pagada) {
                        document.getElementById('pq2').disabled = true;
                        document.querySelector('#pq2+label').style.opacity = '.45';
                    }
                    // Re-apply preset after AJAX (in case radios were reset)
                    if (quincenaPreset === 1 || quincenaPreset === 2) {
                        const r = document.getElementById('pq' + quincenaPreset);
                        if (r && !r.disabled) r.checked = true;
                    }
                    verificarVencimientoPago();
                }
                // Bonos
                if (data.bonos?.length) {
                    let html = '';
                    data.bonos.forEach(b => {
                        html +=
                            `<div class="chk-row"><div class="form-check mb-0 d-flex align-items-center gap-2 flex-grow-1"><input class="form-check-input bono-chk mt-0" type="checkbox" name="bonos_ids[]" value="${b.id}" data-monto="${b.monto_total}" checked><label class="form-check-label text-muted" style="font-size:.78rem;cursor:pointer"><i class="bi bi-gift me-1 text-success"></i>${b.descripcion.substring(0,36)}</label></div><span class="fw-bold text-success text-nowrap" style="font-size:.78rem">+L ${parseFloat(b.monto_total).toLocaleString('es-HN',{minimumFractionDigits:2})}</span></div>`;
                    });
                    document.getElementById('listaBonosChk').innerHTML = html;
                    document.getElementById('boxBonosAplicar').classList.remove('d-none');
                }
                // Viáticos
                if (data.viaticos?.length) {
                    let html = '';
                    data.viaticos.forEach(v => {
                        html +=
                            `<div class="chk-row"><div class="form-check mb-0 d-flex align-items-center gap-2 flex-grow-1"><input class="form-check-input viatico-chk mt-0" type="checkbox" name="viaticos_ids[]" value="${v.id}" data-monto="${v.monto_total}" checked><label class="form-check-label text-muted" style="font-size:.78rem;cursor:pointer"><i class="bi bi-airplane me-1" style="color:#0369a1"></i>${v.descripcion.substring(0,34)}</label></div><span class="fw-bold text-nowrap" style="font-size:.78rem;color:#0369a1">+L ${parseFloat(v.monto_total).toLocaleString('es-HN',{minimumFractionDigits:2})}</span></div>`;
                    });
                    document.getElementById('listaViaticosChk').innerHTML = html;
                    document.getElementById('boxViaticosAplicar').classList.remove('d-none');
                }
                recalcular();
            }).catch(() => {
                document.getElementById('pagoLoadingCuotas').classList.add('d-none');
            });
    }
    document.querySelectorAll('.btn-pagar-directo').forEach(b => {
        b.addEventListener('click', function() {
            abrirModalPago(parseInt(this.dataset.quincenaPreset) || 0);
        });
    });

    /* ══ CONFIRMAR PAGO ════════════════════════════════════════════════════════ */
    document.getElementById('btnConfirmarPago').addEventListener('click', function() {
        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Registrando…';
        const fd = new FormData(document.getElementById('formPago'));
        const arch = document.getElementById('pago_comprobante').files[0];
        if (arch) fd.set('comprobante', arch);
        fetch('includes/colaborador_pago_guardar.php', {
                method: 'POST',
                body: fd
            })
            .then(r => r.json()).then(d => {
                if (d.success) {
                    bootstrap.Modal.getInstance(document.getElementById('modalPago'))?.hide();
                    let extras = '';
                    if (d.cuotas_pagadas > 0) extras +=
                        `<br><small class="text-warning"><i class="bi bi-arrow-down-circle me-1"></i>${d.cuotas_pagadas} cuota(s) descontada(s)</small>`;
                    if (d.bonos_aplicados > 0) extras +=
                        `<br><small class="text-success"><i class="bi bi-gift me-1"></i>${d.bonos_aplicados} bono(s) aplicado(s)</small>`;
                    if (d.viaticos_aplicados > 0) extras +=
                        `<br><small style="color:#0369a1"><i class="bi bi-airplane me-1"></i>${d.viaticos_aplicados} viático(s) liquidado(s)</small>`;
                    Swal.fire({
                        icon: 'success',
                        title: '¡Pago registrado!',
                        html: d.message + extras +
                            `<br><br><a href="${d.recibo_url||'#'}" target="_blank" class="btn btn-sm btn-outline-danger mt-1"><i class="bi bi-file-pdf me-1"></i>Ver Recibo</a>`,
                        showConfirmButton: true,
                        confirmButtonText: 'Cerrar',
                        confirmButtonColor: '#6c757d'
                    }).then(() => location.reload());
                } else {
                    Swal.fire('Error', d.error, 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Confirmar Pago';
                }
            }).catch(() => {
                Swal.fire('Error', 'Error inesperado.', 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Confirmar Pago';
            });
    });

    /* ══ EDITAR COLABORADOR ════════════════════════════════════════════════════ */
    document.getElementById('edit_tipo_pago').addEventListener('change', function() {
        document.getElementById('grp_dia2_edit').style.display = this.value === 'quincenal' ? '' : 'none';
    });
    document.querySelectorAll('.btn-editar-colab').forEach(btn => {
        btn.addEventListener('click', () => {
            new bootstrap.Modal(document.getElementById('modalEditarColab')).show();
        });
    });
    document.getElementById('btnGuardarEdicion').addEventListener('click', function() {
        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Guardando…';
        fetch('includes/colaborador_actualizar.php', {
                method: 'POST',
                body: new FormData(document.getElementById('formEditarColab'))
            })
            .then(r => r.json()).then(d => {
                if (d.success) Swal.fire({
                    icon: 'success',
                    title: '¡Listo!',
                    text: d.message,
                    timer: 1600,
                    showConfirmButton: false
                }).then(() => location.reload());
                else Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: d.error
                });
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-floppy me-1"></i>Guardar Cambios';
            }).catch(() => {
                Swal.fire({
                    icon: 'error',
                    title: 'Error de conexión'
                });
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-floppy me-1"></i>Guardar Cambios';
            });
    });

    /* ══ TIPO CARD SELECTOR ════════════════════════════════════════════════════ */
    function selectTipoCard(group, val) {
        const selector = group === 'new' ? '#tipoSelectorNew' : '#tipoSelectorEdit';
        document.querySelectorAll(`${selector} .tipo-card`).forEach(c => {
            c.classList.toggle('tc-selected', c.dataset.val === val);
        });
        const radio = document.getElementById((group === 'new' ? 'tipo_' : 'edit_tipo_') + val);
        if (radio) radio.checked = true;
    }
    document.addEventListener('click', e => {
        const card = e.target.closest('.tipo-card');
        if (!card) return;
        const val = card.dataset.val;
        const group = card.dataset.group;
        selectTipoCard(group, val);
        if (group === 'new') actualizarTipoUI(val);
    });

    /* ══ NUEVO PRÉSTAMO / BONO / VIÁTICO ═══════════════════════════════════════ */
    function abrirModalRegistrar(tipoDefault) {
        document.getElementById('formPrestamo').reset();
        selectTipoCard('new', tipoDefault);
        actualizarTipoUI(tipoDefault);
        calcularCuota();
        new bootstrap.Modal(document.getElementById('modalPrestamo')).show();
    }

    document.getElementById('btnNuevoPrestamo')?.addEventListener('click', () => abrirModalRegistrar('prestamo'));
    document.getElementById('btnNuevoPrestamo2')?.addEventListener('click', () => abrirModalRegistrar('prestamo'));
    document.querySelectorAll('.btn-nuevo-bono').forEach(b => b.addEventListener('click', () => {
        setTab('bonos');
        abrirModalRegistrar('bono');
    }));
    document.querySelectorAll('.btn-nuevo-viatico').forEach(b => b.addEventListener('click', () => {
        setTab('viaticos');
        abrirModalRegistrar('viatico');
    }));

    function actualizarTipoUI(t) {
        document.getElementById('grp_num_cuotas').style.display = t === 'prestamo' ? '' : 'none';
        document.getElementById('grp_frecuencia').style.display = t === 'prestamo' ? '' : 'none';
        document.getElementById('grp_preview_cuota').style.display = t === 'prestamo' ? '' : 'none';
        document.getElementById('grp_fecha_primera_cuota').style.display = ['prestamo', 'adelanto', 'multa'].includes(t) ?
            '' : 'none';
        document.getElementById('grp_auto').style.display = ['bono', 'viatico'].includes(t) ? 'none' : '';
        document.getElementById('info_viatico').classList.toggle('d-none', t !== 'viatico');
        document.getElementById('info_bono').classList.toggle('d-none', t !== 'bono');
        if (t !== 'prestamo') document.getElementById('prest_cuotas').value = 1;
        const sugs = {
            prestamo: 'Préstamo a colaborador',
            adelanto: 'Adelanto de sueldo',
            bono: 'Bono/Gratificación especial',
            viatico: 'Viático — destino o motivo del viaje',
            multa: 'Multa/Descuento'
        };
        const desc = document.getElementById('prest_desc');
        if (!desc.value) desc.placeholder = sugs[t] || '';
        // Color del modal header
        const hdrColors = {
            prestamo: '#dc3545,#b02a37',
            adelanto: '#d97706,#92400e',
            bono: '#059669,#065f46',
            viatico: '#0369a1,#075985',
            multa: '#6b7280,#374151'
        };
        document.getElementById('modalPrestHdr').style.background =
            `linear-gradient(135deg,${hdrColors[t]||'#dc3545,#b02a37'})`;
    }

    // Radio changes handled by tipo-card click listener above

    function calcularCuota() {
        const m = parseFloat(document.getElementById('prest_monto').value) || 0;
        const c = parseInt(document.getElementById('prest_cuotas').value) || 1;
        document.getElementById('valorCuota').textContent = m > 0 ? 'L ' + (m / c).toFixed(2).replace(
            /\B(?=(\d{3})+(?!\d))/g, ',') : '—';
    }
    document.getElementById('prest_monto').addEventListener('input', calcularCuota);
    document.getElementById('prest_cuotas').addEventListener('input', calcularCuota);

    document.getElementById('btnGuardarPrestamo').addEventListener('click', function() {
        const btn = this;
        if (!document.getElementById('prest_monto').value || !document.getElementById('prest_desc').value) {
            Swal.fire({
                icon: 'warning',
                title: 'Datos incompletos',
                text: 'Completa descripción y monto.',
                timer: 2000,
                showConfirmButton: false
            });
            return;
        }
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Guardando…';
        fetch('includes/prestamo_guardar.php', {
                method: 'POST',
                body: new URLSearchParams(new FormData(document.getElementById('formPrestamo')))
            })
            .then(r => r.json()).then(d => {
                if (d.success) {
                    bootstrap.Modal.getInstance(document.getElementById('modalPrestamo'))?.hide();
                    Swal.fire({
                        icon: 'success',
                        title: '¡Listo!',
                        html: d.message,
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => location.reload());
                } else Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: d.error
                });
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Guardar';
            }).catch(() => {
                Swal.fire({
                    icon: 'error',
                    title: 'Error de conexión'
                });
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Guardar';
            });
    });

    /* ══ PAGAR CUOTA ═══════════════════════════════════════════════════════════ */
    document.addEventListener('click', e => {
        const btn = e.target.closest('.btn-pagar-cuota');
        if (!btn) return;
        document.getElementById('pagarCuotaId').value = btn.dataset.cuotaId;
        document.getElementById('pagarCuotaNum').textContent = '#' + btn.dataset.cuotaNum;
        document.getElementById('pagarCuotaMonto').textContent = 'L ' + btn.dataset.monto;
        document.getElementById('formPagarCuota').querySelector('[name=notas]').value = '';
        new bootstrap.Modal(document.getElementById('modalPagarCuota')).show();
    });
    document.getElementById('btnConfirmarCuota').addEventListener('click', function() {
        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        fetch('includes/prestamo_cuota_pagar.php', {
                method: 'POST',
                body: new URLSearchParams(new FormData(document.getElementById('formPagarCuota')))
            })
            .then(r => r.json()).then(d => {
                if (d.success) {
                    bootstrap.Modal.getInstance(document.getElementById('modalPagarCuota'))?.hide();
                    Swal.fire({
                        icon: 'success',
                        title: '¡Pagado!',
                        html: d.message + (d.prestamo_pagado ?
                            '<br><small class="text-success">🎉 Préstamo completamente pagado!</small>' :
                            ''),
                        timer: 2200,
                        showConfirmButton: false
                    }).then(() => location.reload());
                } else Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: d.error
                });
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check me-1"></i>Confirmar';
            }).catch(() => {
                Swal.fire({
                    icon: 'error',
                    title: 'Error de conexión'
                });
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check me-1"></i>Confirmar';
            });
    });

    /* ══ CANCELAR / ELIMINAR PRÉSTAMO ══════════════════════════════════════════ */
    document.addEventListener('click', e => {
        const btnCanc = e.target.closest('.btn-cancelar-prestamo');
        if (btnCanc) {
            Swal.fire({
                    icon: 'warning',
                    title: '¿Cancelar?',
                    html: `<strong>${btnCanc.dataset.desc}</strong><br><small class="text-muted">Las cuotas pendientes quedarán canceladas.</small>`,
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    confirmButtonText: 'Sí, cancelar',
                    cancelButtonText: 'No'
                })
                .then(r => {
                    if (r.isConfirmed) fetch('includes/prestamo_cancelar.php', {
                        method: 'POST',
                        body: new URLSearchParams({
                            prestamo_id: btnCanc.dataset.prestamoId
                        })
                    }).then(r => r.json()).then(d => {
                        if (d.success) Swal.fire({
                            icon: 'success',
                            title: 'Cancelado',
                            text: d.message,
                            timer: 1800,
                            showConfirmButton: false
                        }).then(() => location.reload());
                        else Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: d.error
                        });
                    });
                });
        }
        const btnElim = e.target.closest('.btn-eliminar-prestamo');
        if (btnElim) {
            Swal.fire({
                    icon: 'error',
                    title: '¿Eliminar definitivamente?',
                    html: `<strong>${btnElim.dataset.desc}</strong><br><small class="text-danger">Se eliminarán el préstamo y todas sus cuotas. <u>No se puede deshacer</u>.</small>`,
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    confirmButtonText: 'Sí, eliminar',
                    cancelButtonText: 'No'
                })
                .then(r => {
                    if (r.isConfirmed) fetch('includes/prestamo_eliminar.php', {
                        method: 'POST',
                        body: new URLSearchParams({
                            prestamo_id: btnElim.dataset.prestamoId
                        })
                    }).then(r => r.json()).then(d => {
                        if (d.success) Swal.fire({
                            icon: 'success',
                            title: 'Eliminado',
                            text: d.message,
                            timer: 1800,
                            showConfirmButton: false
                        }).then(() => location.reload());
                        else Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: d.error
                        });
                    });
                });
        }
    });

    /* ══ EDITAR PRÉSTAMO ═══════════════════════════════════════════════════════ */
    const avisos_estado = {
        pagado: {
            bg: '#d1e7dd',
            color: '#0a3622',
            ico: 'bi-check-circle',
            txt: 'Las cuotas pendientes se cerrarán y el saldo quedará en 0.'
        },
        cancelado: {
            bg: '#f8d7da',
            color: '#58151c',
            ico: 'bi-slash-circle',
            txt: 'Las cuotas pendientes quedarán canceladas.'
        },
        activo: null
    };
    document.addEventListener('click', e => {
        const btn = e.target.closest('.btn-editar-prestamo');
        if (!btn) return;
        document.getElementById('editPrestId').value = btn.dataset.prestamoId;
        document.getElementById('editPrestDesc').value = btn.dataset.descripcion;
        document.getElementById('editPrestFecha').value = btn.dataset.fecha;
        document.getElementById('editPrestNotas').value = btn.dataset.notas;
        document.getElementById('editPrestAuto').checked = btn.dataset.descuentoAuto == '1';
        document.getElementById('editPrestEstado').value = btn.dataset.estado;
        document.getElementById('editPrestEstado').dispatchEvent(new Event('change'));
        selectTipoCard('edit', btn.dataset.tipo);
        new bootstrap.Modal(document.getElementById('modalEditarPrestamo')).show();
    });
    document.getElementById('editPrestEstado').addEventListener('change', function() {
        const av = avisos_estado[this.value];
        const el = document.getElementById('avisoEstado');
        if (av) {
            el.innerHTML = `<i class="bi ${av.ico} me-1"></i>${av.txt}`;
            el.style.cssText =
                `background:${av.bg};color:${av.color};border:1px solid ${av.color}40;padding:.5rem .75rem;border-radius:8px`;
            el.classList.remove('d-none');
        } else el.classList.add('d-none');
    });
    document.getElementById('btnGuardarEditPrest').addEventListener('click', function() {
        const btn = this,
            estado = document.getElementById('editPrestEstado').value;
        const guardar = () => {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Guardando…';
            fetch('includes/prestamo_editar.php', {
                    method: 'POST',
                    body: new URLSearchParams(new FormData(document.getElementById('formEditarPrestamo')))
                })
                .then(r => r.json()).then(d => {
                    if (d.success) {
                        bootstrap.Modal.getInstance(document.getElementById('modalEditarPrestamo'))?.hide();
                        Swal.fire({
                            icon: 'success',
                            title: '¡Listo!',
                            text: d.message,
                            timer: 2000,
                            showConfirmButton: false
                        }).then(() => location.reload());
                    } else Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: d.error
                    });
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-floppy me-1"></i>Guardar Cambios';
                }).catch(() => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error de conexión'
                    });
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-floppy me-1"></i>Guardar Cambios';
                });
        };
        if (['cancelado', 'pagado'].includes(estado)) Swal.fire({
            icon: 'warning',
            title: estado === 'cancelado' ? '¿Cancelar?' : '¿Marcar como pagado?',
            html: estado === 'cancelado' ? 'Las cuotas pendientes quedarán <strong>canceladas</strong>.' : 'Las cuotas pendientes se cerrarán.',
            showCancelButton: true,
            confirmButtonColor: estado === 'cancelado' ? '#dc3545' : '#198754',
            confirmButtonText: 'Sí, continuar',
            cancelButtonText: 'No'
        }).then(r => {
            if (r.isConfirmed) guardar();
        });
        else guardar();
    });

    /* ══ EDITAR CUOTA ══════════════════════════════════════════════════════════ */
    document.addEventListener('click', e => {
        const btn = e.target.closest('.btn-editar-cuota');
        if (!btn) return;
        document.getElementById('editCuotaId').value = btn.dataset.cuotaId;
        document.getElementById('editCuotaTitulo').textContent = '#' + btn.dataset.cuotaNum + ' — L ' + btn.dataset
            .monto;
        document.getElementById('editCuotaEstado').value = btn.dataset.estado;
        document.getElementById('editCuotaFechaEsp').value = btn.dataset.fechaEsperada;
        document.getElementById('editCuotaFechaPago').value = btn.dataset.fechaPago || '';
        document.getElementById('editCuotaMetodo').value = btn.dataset.metodo || 'descuento_nomina';
        document.getElementById('editCuotaNotas').value = btn.dataset.notas || '';
        document.getElementById('alertaReversion').classList.add('d-none');
        document.getElementById('editCuotaEstado').dispatchEvent(new Event('change'));
        new bootstrap.Modal(document.getElementById('modalEditarCuota')).show();
    });
    document.getElementById('editCuotaEstado').addEventListener('change', function() {
        document.getElementById('alertaReversion').classList.toggle('d-none', this.value !== 'pendiente');
        document.getElementById('grpPagoEdit').style.display = this.value === 'pagado' ? '' : 'none';
        if (this.value === 'pagado' && !document.getElementById('editCuotaFechaPago').value) document
            .getElementById('editCuotaFechaPago').value = new Date().toISOString().slice(0, 10);
    });
    document.getElementById('btnGuardarEditCuota').addEventListener('click', function() {
        const btn = this,
            estado = document.getElementById('editCuotaEstado').value;
        const guardar = () => {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            fetch('includes/prestamo_cuota_editar.php', {
                    method: 'POST',
                    body: new URLSearchParams(new FormData(document.getElementById('formEditarCuota')))
                })
                .then(r => r.json()).then(d => {
                    if (d.success) {
                        bootstrap.Modal.getInstance(document.getElementById('modalEditarCuota'))?.hide();
                        Swal.fire({
                            icon: d.pago_revertido ? 'info' : 'success',
                            title: '¡Listo!',
                            html: d.message,
                            timer: 2200,
                            showConfirmButton: false
                        }).then(() => location.reload());
                    } else Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: d.error
                    });
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-floppy me-1"></i>Guardar';
                }).catch(() => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error de conexión'
                    });
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-floppy me-1"></i>Guardar';
                });
        };
        if (estado === 'pendiente') Swal.fire({
            icon: 'warning',
            title: '¿Revertir este pago?',
            html: 'El saldo del préstamo aumentará.',
            showCancelButton: true,
            confirmButtonColor: '#fd7e14',
            confirmButtonText: 'Sí, revertir',
            cancelButtonText: 'No'
        }).then(r => {
            if (r.isConfirmed) guardar();
        });
        else guardar();
    });

    /* ══ COMPROBANTE ═══════════════════════════════════════════════════════════ */
    document.getElementById('pago_comprobante').addEventListener('change', function() {
        if (this.files && this.files[0]) mostrarPreview(this.files[0]);
    });

    function mostrarPreview(file) {
        const esPdf = file.type === 'application/pdf';
        const tam = file.size < 1048576 ? (file.size / 1024).toFixed(1) + ' KB' : (file.size / 1048576).toFixed(2) + ' MB';
        document.getElementById('prevIcono').innerHTML = esPdf ?
            '<i class="bi bi-file-pdf text-danger" style="font-size:1.4rem"></i>' :
            '<i class="bi bi-image text-primary" style="font-size:1.4rem"></i>';
        document.getElementById('prevNombre').textContent = file.name;
        document.getElementById('prevTamaño').textContent = tam + ' · ' + (esPdf ? 'PDF' : 'Imagen');
        if (!esPdf) {
            const r = new FileReader();
            r.onload = e => {
                document.getElementById('prevImg').src = e.target.result;
                document.getElementById('prevImagen').classList.remove('d-none');
            };
            r.readAsDataURL(file);
        } else document.getElementById('prevImagen').classList.add('d-none');
        document.getElementById('previewComprobante').classList.remove('d-none');
        document.getElementById('zonaComprobante').style.borderColor = '#059669';
        document.getElementById('zonaComprobante').style.background = '#f0fff4';
    }

    function limpiarComprobante() {
        document.getElementById('pago_comprobante').value = '';
        document.getElementById('previewComprobante').classList.add('d-none');
        document.getElementById('prevImagen').classList.add('d-none');
        document.getElementById('prevImg').src = '';
        document.getElementById('zonaComprobante').style.borderColor = '';
        document.getElementById('zonaComprobante').style.background = '';
    }

    function handleDrop(event) {
        event.preventDefault();
        document.getElementById('zonaComprobante').style.borderColor = '';
        document.getElementById('zonaComprobante').style.background = '';
        const file = event.dataTransfer.files[0];
        if (!file) return;
        if (!['image/jpeg', 'image/png', 'image/webp', 'application/pdf'].includes(file.type)) {
            Swal.fire({
                icon: 'warning',
                title: 'Tipo no permitido',
                text: 'Solo JPG, PNG, WEBP o PDF.',
                timer: 2000,
                showConfirmButton: false
            });
            return;
        }
        if (file.size > 5242880) {
            Swal.fire({
                icon: 'warning',
                title: 'Archivo muy grande',
                text: 'Máximo 5 MB.',
                timer: 2000,
                showConfirmButton: false
            });
            return;
        }
        const dt = new DataTransfer();
        dt.items.add(file);
        document.getElementById('pago_comprobante').files = dt.files;
        mostrarPreview(file);
    }

    /* ══ INIT ══════════════════════════════════════════════════════════════════ */
    selectTipoCard('new', 'prestamo');
    actualizarTipoUI('prestamo');
    recalcular();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>