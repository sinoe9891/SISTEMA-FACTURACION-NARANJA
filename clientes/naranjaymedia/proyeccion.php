<?php
$titulo = 'Proyección de Flujo de Caja';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';
require_once '../../includes/contrato_plan.php';
require_once '../../includes/proyeccion_gastos.php';
require_once '../../includes/cuentas.php';
require_once '../../includes/templates/header.php';

$cliente_id = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);

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

$hoy_año = (int)date('Y');
$hoy_mes = (int)date('n');

// 1. Contratos activos
$stmtCt = $pdo->prepare("
    SELECT c.id, c.nombre_contrato, c.tipo_contrato, c.monto, c.dia_pago,
           c.frecuencia_meses, c.mes_inicio_ciclo, c.fecha_inicio, c.fecha_fin,
           cf.nombre AS receptor_nombre
    FROM contratos c
    LEFT JOIN clientes_factura cf ON cf.id=c.receptor_id AND cf.cliente_id=c.cliente_id
    WHERE c.cliente_id = ? AND c.estado = 'activo'
");
$stmtCt->execute([$cliente_id]);
$contratos = $stmtCt->fetchAll(PDO::FETCH_ASSOC);

// 2. Nómina mensual
$stmtN = $pdo->prepare("
    SELECT COALESCE(SUM(salario_base),0) AS bruto,
           COALESCE(SUM(CASE WHEN aplica_ihss=1 THEN LEAST(salario_base,10294.10)*0.07 ELSE 0 END),0) AS ihss_pat,
           COALESCE(SUM(CASE WHEN aplica_rap=1  THEN salario_base*0.015 ELSE 0 END),0) AS rap_pat
    FROM colaboradores WHERE cliente_id=? AND activo=1
");
$stmtN->execute([$cliente_id]);
$nom = $stmtN->fetch(PDO::FETCH_ASSOC);
$nomina_mensual = (float)$nom['bruto'] + (float)$nom['ihss_pat'] + (float)$nom['rap_pat'];

// Excluir categorías nómina del promedio de gastos (evita doble conteo)
$stmtCatNom = $pdo->prepare("
    SELECT id FROM categorias_gastos
    WHERE cliente_id=? AND (nombre LIKE '%Nómin%' OR nombre LIKE '%Sueldo%' OR nombre LIKE '%Nomina%')
");
$stmtCatNom->execute([$cliente_id]);
$cats_nom = $stmtCatNom->fetchAll(PDO::FETCH_COLUMN);
$excl_sql = !empty($cats_nom) ? implode(',', array_map('intval', $cats_nom)) : '0';

// Calendario completo: incluye pagos registrados y respeta el fin de cada recurrencia.
$stmtGastos = $pdo->prepare("SELECT g.*, COALESCE(cg.nombre, 'Sin categoría') AS categoria
    FROM gastos g LEFT JOIN categorias_gastos cg ON cg.id=g.categoria_id AND cg.cliente_id=g.cliente_id
    WHERE g.cliente_id=? AND g.estado!='anulado'
      AND (g.categoria_id IS NULL OR g.categoria_id NOT IN ($excl_sql))
      AND (g.descripcion IS NULL OR g.descripcion NOT LIKE 'Sueldo %')");
$stmtGastos->execute([$cliente_id]);
$gastosCalendario = $stmtGastos->fetchAll(PDO::FETCH_ASSOC);

// ── Desglose nómina por colaborador (para panel detalle) ─────────────────────
$stmtColabs = $pdo->prepare("
    SELECT nombre, salario_base,
           CASE WHEN aplica_ihss=1 THEN LEAST(salario_base,10294.10)*0.07 ELSE 0 END AS ihss_pat,
           CASE WHEN aplica_rap=1  THEN salario_base*0.015 ELSE 0 END AS rap_pat
    FROM colaboradores WHERE cliente_id=? AND activo=1 ORDER BY salario_base DESC
");
$stmtColabs->execute([$cliente_id]);
$colabs_desglose = $stmtColabs->fetchAll(PDO::FETCH_ASSOC);

$colabs_json = json_encode($colabs_desglose, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);

// 5. Proyección 12 meses
// Turnos de contratos rotativos: se cargan una sola vez (antes: 1 consulta por contrato y por mes)
$turnosRotativos = [];
$idsRotativos = array_column(array_filter($contratos, fn($c) => $c['tipo_contrato'] === 'rotativo'), 'id');
if ($idsRotativos) {
    $ph = implode(',', array_fill(0, count($idsRotativos), '?'));
    $stRot = $pdo->prepare("SELECT r.contrato_id, r.monto, r.orden, cf.nombre AS receptor_nombre FROM contratos_clientes_rotativos r JOIN contratos c ON c.id=r.contrato_id LEFT JOIN clientes_factura cf ON cf.id=r.receptor_id AND cf.cliente_id=c.cliente_id WHERE r.contrato_id IN ($ph) AND r.activo=1 ORDER BY r.contrato_id, r.orden ASC");
    $stRot->execute(array_values($idsRotativos));
    foreach ($stRot->fetchAll(PDO::FETCH_ASSOC) as $t) $turnosRotativos[$t['contrato_id']][] = $t;
}

// Contratos con plan de pagos: se proyectan con las fechas y montos del plan (lo no cobrado);
// lo vencido sin cobrar se espera en el mes actual.
$tipoContrato = array_column($contratos, 'tipo_contrato', 'id');
$conPlan = array_flip(planContratosConPlan($pdo, $cliente_id));
$planPorMes = [];
$planDetalle = [];
$contratosPorId = array_column($contratos, null, 'id');
foreach (planPendientesPorMes($pdo, $cliente_id) as $k => $porContrato) {
    [$ka, $km] = array_map('intval', explode('-', $k));
    if ($ka * 12 + $km < $hoy_año * 12 + $hoy_mes) $k = $hoy_año . '-' . $hoy_mes;
    foreach ($porContrato as $ctId => $monto) {
        if (!isset($tipoContrato[$ctId])) continue;   // solo contratos activos
        $planDetalle[$k][] = ['cliente' => $contratosPorId[$ctId]['receptor_nombre'] ?: 'Cliente sin nombre', 'nombre' => $contratosPorId[$ctId]['nombre_contrato'] ?: "Contrato #$ctId", 'tipo' => $tipoContrato[$ctId], 'monto' => (float)$monto, 'regla' => 'Plan de pagos pendiente (incluye vencidos en el mes actual)'];
        $planPorMes[$k][$tipoContrato[$ctId] === 'sin_factura' ? 'recibo' : 'estandar'] = ($planPorMes[$k][$tipoContrato[$ctId] === 'sin_factura' ? 'recibo' : 'estandar'] ?? 0) + $monto;
    }
}

// Facturas emitidas sin cobrar (Cuentas por cobrar): se esperan en el mes actual. Para no contar dos veces:
// - las de un contrato activo con plan de pagos que no están ligadas a una línea del plan ya vienen en esa línea pendiente;
// - las de este mes de un contrato activo sin plan ya vienen en la proyección mensual del contrato.
$cxcMes = 0.0;
$cxcDetalle = [];
$mesActualIni = date('Y-m-01');
$ligadasPlan = [];
try {
    $ligadasPlan = array_flip(array_map('intval', $pdo->query("SELECT factura_id FROM contratos_plan WHERE factura_id IS NOT NULL AND cliente_id = " . (int)$cliente_id)->fetchAll(PDO::FETCH_COLUMN)));
} catch (Throwable $e) { $ligadasPlan = []; }
$ctDeFactura = [];
$st = $pdo->prepare("SELECT id, contrato_id FROM facturas WHERE cliente_id = ? AND estado = 'emitida' AND contrato_id IS NOT NULL");
$st->execute([$cliente_id]);
$ctDeFactura = $st->fetchAll(PDO::FETCH_KEY_PAIR);
foreach (cxcFacturasPendientes($pdo, (int)$cliente_id) as $fx) {
    $ctF = (int)($ctDeFactura[$fx['id']] ?? 0);
    if ($ctF && isset($tipoContrato[$ctF])) {   // contrato activo
        if (isset($conPlan[$ctF]) && !isset($ligadasPlan[(int)$fx['id']])) continue;
        if (!isset($conPlan[$ctF]) && substr($fx['fecha_emision'], 0, 10) >= $mesActualIni) continue;
    }
    $cxcMes += (float)$fx['saldo'];
    $cxcDetalle[] = ['cliente' => $fx['receptor'], 'nombre' => 'Factura ' . $fx['correlativo'] . ' · ' . date('d/m/Y', strtotime($fx['fecha_emision'])), 'tipo' => 'cxc', 'monto' => round((float)$fx['saldo'], 2),
                     'regla' => 'Factura emitida sin cobrar (' . (int)$fx['dias'] . ' días)' . ((float)$fx['abonado'] > 0 ? ' · saldo después de abonos' : '')];
}

$proyeccion = [];
for ($offset = 0; $offset < 12; $offset++) {
    $mes  = (($hoy_mes - 1 + $offset) % 12) + 1;
    $anio = $hoy_año + intdiv($hoy_mes - 1 + $offset, 12);
    $ing_estandar = $ing_periodico = $ing_recibo = 0;

    $ing_estandar += $planPorMes["$anio-$mes"]['estandar'] ?? 0;
    $ing_recibo += $planPorMes["$anio-$mes"]['recibo'] ?? 0;
    $ing_detalle = $planDetalle["$anio-$mes"] ?? [];
    foreach ($contratos as $ct) {
        $antes = $ing_estandar + $ing_periodico + $ing_recibo;
        if (isset($conPlan[(int)$ct['id']])) continue;   // ya proyectado con su plan
        $fi = new DateTime($ct['fecha_inicio']);
        $ff = $ct['fecha_fin'] ? new DateTime($ct['fecha_fin']) : null;
        if ($fi > new DateTime(date('Y-m-t', strtotime("$anio-$mes-01")))) continue;
        if ($ff && $ff < new DateTime("$anio-$mes-01")) continue;
        $monto = (float)$ct['monto'];
        $clienteIngreso = $ct['receptor_nombre'] ?: 'Cliente sin nombre';
        switch ($ct['tipo_contrato']) {
            case 'estandar':
                $ing_estandar += $monto;
                break;
            case 'periodico':
                $freq = max(1, (int)($ct['frecuencia_meses'] ?? 1));
                $mesI = (int)($ct['mes_inicio_ciclo'] ?? (int)$fi->format('n'));
                $anioI = (int)$fi->format('Y');
                $off2 = ($anio - $anioI) * 12 + ($mes - $mesI);
                if ($off2 >= 0 && ($off2 % $freq) === 0) $ing_periodico += $monto;
                break;
            case 'rotativo':
                $mesI_r = (int)$fi->format('n');
                $anioI_r = (int)$fi->format('Y');
                $od = ($anio - $anioI_r) * 12 + ($mes - $mesI_r);
                if ($od >= 0) {
                    $turnos = $turnosRotativos[$ct['id']] ?? [];
                    if (!empty($turnos)) {
                        $fr = max(1, (int)($ct['frecuencia_meses'] ?? 1));
                        $ct2 = count($turnos) * $fr;
                        $pc = (($od % $ct2) + $ct2) % $ct2;
                        $turno = $turnos[(int)floor($pc / $fr)];
                        $ing_estandar += (float)$turno['monto'];
                        $clienteIngreso = $turno['receptor_nombre'] ?: $clienteIngreso;
                    }
                }
                break;
            case 'sin_factura':
                $ing_recibo += $monto;
                break;
        }
        $aporte = $ing_estandar + $ing_periodico + $ing_recibo - $antes;
        if ($aporte != 0) $ing_detalle[] = ['cliente' => $clienteIngreso, 'nombre' => $ct['nombre_contrato'] ?: 'Contrato #' . $ct['id'], 'tipo' => $ct['tipo_contrato'], 'monto' => round($aporte, 2), 'regla' => 'Contrato vigente · ' . $ct['tipo_contrato']];
    }

    $ing_real = $egr_real = null;

    $ing_cxc = $offset === 0 ? $cxcMes : 0.0;   // lo pendiente de cobro se espera en el mes actual
    if ($offset === 0) $ing_detalle = array_merge($ing_detalle, $cxcDetalle);
    $ing_total = $ing_estandar + $ing_periodico + $ing_recibo + $ing_cxc;
    $gastosMes = proyeccionGastosMes($gastosCalendario, $anio, $mes);
    $fijosMes = $variablesMes = 0;
    foreach ($gastosMes as $g) {
        if ($g['tipo'] === 'fijo') $fijosMes += $g['total'];
        else $variablesMes += $g['total'];
    }
    $egr_total = round($nomina_mensual + $fijosMes + $variablesMes, 2);
    $flujo = $ing_total - $egr_total;
    $alerta = $flujo < 0 ? 'critico' : ($flujo < $egr_total * 0.15 ? 'atencion' : 'ok');
    if ($alerta === 'critico')
        $rec = "Flujo negativo de L " . number_format(abs($flujo), 2) . ". Revisar gastos fijos o añadir contratos.";
    elseif ($alerta === 'atencion')
        $rec = "Margen ajustado (" . round(($flujo / max($ing_total, 1)) * 100, 1) . "%). Evita gastos extraordinarios.";
    else
        $rec = "Flujo saludable. Margen del " . round(($flujo / max($ing_total, 1)) * 100, 1) . "%.";

    $proyeccion[] = [
        'mes' => $mes,
        'anio' => $anio,
        'mes_nombre' => $meses_es[$mes],
        'ing_estandar' => $ing_estandar,
        'ing_periodico' => $ing_periodico,
        'ing_recibo' => $ing_recibo,
        'ing_cxc' => $ing_cxc,
        'ing_total' => $ing_total,
        'egr_nomina' => $nomina_mensual,
        'egr_fijos' => $fijosMes,
        'gastos_detalle' => $gastosMes,
        'ing_detalle' => $ing_detalle,
        'egr_variables' => $variablesMes,
        'egr_total' => $egr_total,
        'flujo' => $flujo,
        'alerta' => $alerta,
        'recomendacion' => $rec,
        'ing_real' => $ing_real,
        'egr_real' => $egr_real,
        'es_pasado' => false, // Todas las filas son proyecciones; el mes actual aún no está cerrado.
        'es_actual' => ($anio == $hoy_año && $mes == $hoy_mes),
    ];
}

// (Se eliminó la escritura a proyecciones_cache en cada visita: ninguna parte del
// sistema lee esa tabla y costaba una escritura por mes proyectado en cada carga.)

$prom_fijos = array_sum(array_column($proyeccion, 'egr_fijos')) / 12;
$prom_variables = array_sum(array_column($proyeccion, 'egr_variables')) / 12;
$total_ing_proy = array_sum(array_column($proyeccion, 'ing_total'));
$total_egr_proy = array_sum(array_column($proyeccion, 'egr_total'));
$total_flujo    = array_sum(array_column($proyeccion, 'flujo'));
$meses_criticos = count(array_filter($proyeccion, fn($p) => $p['alerta'] === 'critico'));
$meses_atencion = count(array_filter($proyeccion, fn($p) => $p['alerta'] === 'atencion'));
$chart_labels = array_map(fn($p) => substr($p['mes_nombre'], 0, 3) . ' ' . substr($p['anio'], 2, 2), $proyeccion);
$chart_ing    = array_map(fn($p) => round($p['ing_total'], 2), $proyeccion);
$chart_egr    = array_map(fn($p) => round($p['egr_total'], 2), $proyeccion);
$chart_flujo  = array_map(fn($p) => round($p['flujo'], 2), $proyeccion);
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
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
        --tr: .18s cubic-bezier(.4, 0, .2, 1);
        font-family: 'Plus Jakarta Sans', sans-serif;
    }

    .pj-wrap {
        max-width: 1200px;
        margin: 0 auto;
        padding: 1.5rem 0 4rem
    }

    .pj-hero {
        background: linear-gradient(135deg, #0f766e 0%, #065f46 100%);
        border-radius: var(--radius);
        padding: 1.6rem 2rem;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1.75rem;
        box-shadow: 0 8px 32px rgba(15, 118, 110, .2);
        position: relative;
        overflow: hidden
    }

    .pj-hero::before {
        content: '';
        position: absolute;
        top: -50px;
        right: -50px;
        width: 200px;
        height: 200px;
        border-radius: 50%;
        background: rgba(255, 255, 255, .07);
        pointer-events: none
    }

    .pj-kpis {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
        gap: .85rem;
        margin-bottom: 1.5rem
    }

    .pj-kpi {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 1rem 1.1rem;
        display: flex;
        align-items: center;
        gap: .8rem;
        box-shadow: var(--shadow-sm);
        transition: box-shadow var(--tr), transform var(--tr)
    }

    .pj-kpi:hover {
        box-shadow: var(--shadow-md);
        transform: translateY(-2px)
    }

    .pj-kpi-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        flex-shrink: 0
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

    .pj-kpi-val {
        font-size: 1.05rem;
        font-weight: 800;
        color: var(--text);
        line-height: 1.1
    }

    .pj-kpi-lbl {
        font-size: .68rem;
        color: var(--muted);
        margin-top: 2px;
        text-transform: uppercase;
        letter-spacing: .04em
    }

    .pj-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow-sm);
        overflow: hidden;
        margin-bottom: 1.25rem
    }

    .pj-card-hdr {
        padding: .9rem 1.4rem;
        border-bottom: 1px solid var(--border);
        background: var(--surface-2);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .5rem
    }

    .pj-card-title {
        font-size: .9rem;
        font-weight: 700;
        color: var(--text);
        display: flex;
        align-items: center;
        gap: .5rem
    }

    .pj-table {
        width: 100%;
        border-collapse: collapse;
        font-size: .83rem
    }

    .pj-table thead th {
        padding: .65rem 1rem;
        background: var(--surface-2);
        color: var(--muted);
        font-size: .7rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .04em;
        border-bottom: 1px solid var(--border);
        white-space: nowrap
    }

    .pj-table tbody tr {
        border-bottom: 1px solid var(--border);
        transition: background var(--tr)
    }

    .pj-table tbody tr:last-child {
        border-bottom: none
    }

    .pj-table tbody tr:hover {
        background: #f0fdf9
    }

    .pj-table tbody td {
        padding: .75rem 1rem;
        vertical-align: middle
    }

    .pj-table tbody tr.row-actual td {
        background: #eff6ff;
        font-weight: 700
    }

    .pj-table tbody tr.row-critico td {
        background: #fff1f2
    }

    .pj-table tbody tr.row-atencion td {
        background: #fffbeb
    }

    .pj-table tbody tr.row-pasado td {
        opacity: .7
    }

    .flujo-badge {
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        padding: .22rem .65rem;
        border-radius: 20px;
        font-size: .75rem;
        font-weight: 700
    }

    .fb-ok {
        background: #d1fae5;
        color: #065f46
    }

    .fb-at {
        background: #fef3c7;
        color: #92400e
    }

    .fb-crit {
        background: #fee2e2;
        color: #991b1b
    }

    .al-badge {
        display: inline-flex;
        align-items: center;
        gap: .25rem;
        padding: .18rem .55rem;
        border-radius: 20px;
        font-size: .7rem;
        font-weight: 600
    }

    .al-ok {
        background: #d1fae5;
        color: #059669
    }

    .al-at {
        background: #fef3c7;
        color: #d97706
    }

    .al-crit {
        background: #fee2e2;
        color: #dc2626
    }

    /* Fila de desglose */
    .det-row {
        border-bottom: 1px solid var(--border)
    }

    .det-row td {
        padding: .5rem 1.4rem 1.1rem;
        background: var(--surface-2)
    }

    .det-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
        margin-top: .5rem
    }

    @media(max-width:640px) {
        .det-grid {
            grid-template-columns: 1fr
        }
    }

    .det-section-title {
        font-size: .72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .06em;
        color: var(--muted);
        margin-bottom: .5rem;
        display: flex;
        align-items: center;
        gap: .35rem
    }

    .det-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: .3rem .5rem;
        border-radius: 6px;
        font-size: .8rem;
        margin-bottom: 2px
    }

    .det-item:hover {
        background: rgba(0, 0, 0, .03)
    }

    .det-item-name {
        color: var(--text);
        flex: 1;
        min-width: 0;
        white-space: normal;
        overflow-wrap: anywhere
    }

    .det-item-amt {
        font-weight: 700;
        white-space: nowrap
    }

    .det-total {
        border-top: 1.5px solid var(--border);
        margin-top: .4rem;
        padding-top: .4rem;
        display: flex;
        justify-content: space-between;
        font-size: .82rem;
        font-weight: 700
    }

    .det-nota {
        font-size: .72rem;
        color: var(--muted);
        margin-top: .5rem;
        padding: .4rem .75rem;
        background: #fffbeb;
        border: 1px solid #fde68a;
        border-radius: 6px
    }

    .chart-legend {
        display: flex;
        gap: 1.25rem;
        flex-wrap: wrap;
        justify-content: center;
        margin-top: .75rem
    }

    .cl-item {
        display: flex;
        align-items: center;
        gap: .4rem;
        font-size: .78rem;
        color: var(--muted)
    }

    .cl-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        flex-shrink: 0
    }

    .cl-dash {
        width: 16px;
        height: 3px;
        border-top: 2px dashed currentColor;
        flex-shrink: 0
    }

    /* ── Print ─────────────────────────────────────────────────────────────── */
    /* ── Print ─────────────────────────────────────────────────────────────── */
    @media print {
        body {
            background: #fff !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact
        }

        /* Ocultar chrome de la app */
        nav.navbar,
        .navbar,
        footer,
        #footer {
            display: none !important
        }

        .no-print {
            display: none !important
        }

        /* Layout */
        .pj-hero {
            box-shadow: none !important;
            border-radius: 8px;
            margin-top: 0
        }

        .pj-card,
        .pj-kpi {
            box-shadow: none !important;
            break-inside: avoid
        }

        .pj-kpis {
            grid-template-columns: repeat(6, 1fr) !important;
            gap: .4rem
        }

        /* Tabla compacta */
        .pj-table {
            font-size: .7rem
        }

        .pj-table thead th,
        .pj-table tbody td {
            padding: .3rem .5rem
        }

        /* Ocultar elementos interactivos */
        .det-row,
        .btn-desglose {
            display: none !important
        }

        /* Supuestos compactos */
        .pj-card:last-child .p-4 {
            padding: .75rem !important
        }

        .pj-wrap {
            padding: .5rem 0 1rem
        }

        a[href]:after {
            content: '' !important
        }

        /* Alertas sin sombra */
        .alert {
            box-shadow: none !important
        }
    }
</style>

<div class="container-xxl pj-wrap">
    <div class="pj-hero">
        <div>
            <h4 style="font-size:1.35rem;font-weight:800;margin:0"><i class="bi bi-graph-up-arrow me-2"></i>Proyección de Flujo de Caja</h4>
            <p style="font-size:.82rem;opacity:.78;margin:.2rem 0 0">12 meses adelante · Basada en contratos activos y
                promedios de gastos reales</p>
        </div>
        <div class="d-flex gap-2 flex-wrap no-print">
            <a href="financiero" class="btn btn-sm"
                style="background:rgba(255,255,255,.18);color:#fff;border:1px solid rgba(255,255,255,.3);font-weight:600"><i
                    class="bi bi-graph-up me-1"></i>Estado de Resultados</a>
            <a href="contratos" class="btn btn-sm"
                style="background:rgba(255,255,255,.18);color:#fff;border:1px solid rgba(255,255,255,.3);font-weight:600"><i
                    class="bi bi-file-earmark-text me-1"></i>Contratos</a>
            <button onclick="window.print()" class="btn btn-sm"
                style="background:rgba(255,255,255,.28);color:#fff;border:1px solid rgba(255,255,255,.5);font-weight:700"><i
                    class="bi bi-printer-fill me-1"></i>Imprimir / PDF</button>
        </div>
    </div>

    <?php if ($meses_criticos > 0): ?>
        <div class="alert d-flex align-items-center gap-3 mb-4"
            style="background:#fff1f2;border:1px solid #fecaca;color:#7f1d1d;border-radius:12px">
            <i class="bi bi-exclamation-octagon-fill" style="font-size:1.3rem;flex-shrink:0;color:#dc2626"></i>
            <div><strong><?= $meses_criticos ?> mes(es) con flujo negativo</strong> en los próximos 12 meses.</div>
        </div>
    <?php elseif ($meses_atencion > 0): ?>
        <div class="alert d-flex align-items-center gap-3 mb-4"
            style="background:#fffbeb;border:1px solid #fde68a;color:#78350f;border-radius:12px">
            <i class="bi bi-exclamation-triangle-fill" style="font-size:1.3rem;flex-shrink:0;color:#d97706"></i>
            <div><strong><?= $meses_atencion ?> mes(es) con margen ajustado.</strong></div>
        </div>
    <?php else: ?>
        <div class="alert d-flex align-items-center gap-3 mb-4"
            style="background:#f0fdf4;border:1px solid #a7f3d0;color:#065f46;border-radius:12px">
            <i class="bi bi-check-circle-fill" style="font-size:1.3rem;flex-shrink:0;color:#059669"></i>
            <div><strong>Proyección saludable</strong> para los próximos 12 meses.</div>
        </div>
    <?php endif; ?>

    <div class="pj-kpis">
        <div class="pj-kpi">
            <div class="pj-kpi-icon ki-g"><i class="bi bi-arrow-up-circle-fill"></i></div>
            <div>
                <div class="pj-kpi-val" style="color:#059669">L <?= number_format($total_ing_proy, 0) ?></div>
                <div class="pj-kpi-lbl">Ingresos 12m</div>
            </div>
        </div>
        <div class="pj-kpi">
            <div class="pj-kpi-icon ki-r"><i class="bi bi-arrow-down-circle-fill"></i></div>
            <div>
                <div class="pj-kpi-val" style="color:#dc2626">L <?= number_format($total_egr_proy, 0) ?></div>
                <div class="pj-kpi-lbl">Egresos 12m</div>
            </div>
        </div>
        <div class="pj-kpi" style="border-color:<?= $total_flujo >= 0 ? '#a7f3d0' : '#fecaca' ?>">
            <div class="pj-kpi-icon <?= $total_flujo >= 0 ? 'ki-g' : 'ki-r' ?>"><i
                    class="bi bi-<?= $total_flujo >= 0 ? 'trending-up' : 'trending-down' ?>"></i></div>
            <div>
                <div class="pj-kpi-val" style="color:<?= $total_flujo >= 0 ? '#059669' : '#dc2626' ?>">
                    <?= $total_flujo < 0 ? '-' : '' ?>L <?= number_format(abs($total_flujo), 0) ?></div>
                <div class="pj-kpi-lbl">Flujo neto 12m</div>
            </div>
        </div>
        <div class="pj-kpi">
            <div class="pj-kpi-icon ki-t"><i class="bi bi-file-earmark-check-fill"></i></div>
            <div>
                <div class="pj-kpi-val"><?= count($contratos) ?></div>
                <div class="pj-kpi-lbl">Contratos activos</div>
            </div>
        </div>
        <div class="pj-kpi">
            <div class="pj-kpi-icon ki-y"><i class="bi bi-people-fill"></i></div>
            <div>
                <div class="pj-kpi-val" style="font-size:.9rem">L <?= number_format($nomina_mensual, 0) ?></div>
                <div class="pj-kpi-lbl">Nómina/mes</div>
            </div>
        </div>
        <?php if ($meses_criticos > 0): ?><div class="pj-kpi" style="border-color:#fecaca">
                <div class="pj-kpi-icon ki-r"><i class="bi bi-exclamation-triangle-fill"></i></div>
                <div>
                    <div class="pj-kpi-val" style="color:#dc2626"><?= $meses_criticos ?></div>
                    <div class="pj-kpi-lbl">Meses críticos</div>
                </div>
            </div><?php endif; ?>
    </div>

    <!-- Gráfico -->
    <div class="pj-card">
        <div class="pj-card-hdr">
            <span class="pj-card-title"><i class="bi bi-bar-chart-line-fill text-success"></i> Flujo de Caja — 12 meses
                proyectados</span>
            <small class="text-muted" style="font-size:.75rem">Egresos según calendario y vencimientos · Mes actual también proyectado</small>
        </div>
        <div class="p-3" style="height:280px"><canvas id="chartProy"></canvas></div>
        <div class="chart-legend pb-3">
            <div class="cl-item">
                <div class="cl-dot" style="background:rgba(16,185,129,.7)"></div>Ingresos
            </div>
            <div class="cl-item">
                <div class="cl-dot" style="background:rgba(239,68,68,.7)"></div>Egresos totales
            </div>
            <div class="cl-item">
                <div class="cl-dot" style="background:#3b82f6"></div>Flujo neto
            </div>
            <div class="cl-item" style="color:#f59e0b">
                <div class="cl-dash" style="color:#f59e0b"></div>Nómina
            </div>
        </div>
    </div>

    <!-- Tabla -->
    <div class="pj-card">
        <div class="pj-card-hdr">
            <span class="pj-card-title"><i class="bi bi-table text-secondary"></i> Detalle Mes a Mes</span>
            <div class="d-flex gap-2">
                <span class="al-badge al-ok"><i class="bi bi-circle-fill" style="font-size:7px"></i>OK</span>
                <span class="al-badge al-at"><i class="bi bi-circle-fill" style="font-size:7px"></i>Atención</span>
                <span class="al-badge al-crit"><i class="bi bi-circle-fill" style="font-size:7px"></i>Crítico</span>
            </div>
        </div>
        <div style="overflow-x:auto">
            <table class="pj-table">
                <thead>
                    <tr>
                        <th>Mes</th>
                        <th class="text-end">Ing. Contratos</th>
                        <th class="text-end">Ing. Recibos</th>
                        <th class="text-end" title="Facturas emitidas que siguen sin pagar: se esperan en el mes actual">Por cobrar</th>
                        <th class="text-end">Total Ing.</th>
                        <th class="text-end">Nómina</th>
                        <th class="text-end">Gastos fijos/var</th>
                        <th class="text-end">Total Egr.</th>
                        <th class="text-end">Flujo Neto</th>
                        <th class="text-center">Estado</th>
                        <th class="text-center no-print">Desglose</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($proyeccion as $i => $p): ?>
                        <tr
                            class="<?= $p['es_actual'] ? 'row-actual' : ($p['alerta'] === 'critico' ? 'row-critico' : ($p['alerta'] === 'atencion' ? 'row-atencion' : ($p['es_pasado'] ? 'row-pasado' : ''))) ?>">
                            <td>
                                <div class="fw-semibold"><?= $p['mes_nombre'] ?></div>
                                <small class="text-muted"><?= $p['anio'] ?></small>
                                <?php if ($p['es_actual']): ?><span class="badge ms-1"
                                        style="background:#dbeafe;color:#1d4ed8;font-size:.65rem">Actual</span>
                                <?php elseif ($p['es_pasado']): ?><span class="badge ms-1"
                                        style="background:#f1f5f9;color:#64748b;font-size:.65rem">Real</span><?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($p['es_pasado'] && $p['ing_real'] !== null): ?><span class="text-muted"
                                        style="font-size:.75rem">Real:</span> <span class="fw-bold">L
                                        <?= number_format($p['ing_real'], 0) ?></span>
                                <?php else: ?><span class="text-success">L
                                        <?= number_format($p['ing_estandar'] + $p['ing_periodico'], 0) ?></span><?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?= $p['ing_recibo'] > 0 ? "<span style='color:#7c3aed'>L " . number_format($p['ing_recibo'], 0) . "</span>" : "<span class='text-muted'>—</span>" ?>
                            </td>
                            <td class="text-end">
                                <?= $p['ing_cxc'] > 0 ? "<span style='color:#0e7490'>L " . number_format($p['ing_cxc'], 0) . "</span>" : "<span class='text-muted'>—</span>" ?>
                            </td>
                            <td class="text-end fw-bold">L
                                <?= number_format($p['es_pasado'] && $p['ing_real'] !== null ? $p['ing_real'] : $p['ing_total'], 0) ?>
                            </td>
                            <td class="text-end text-warning">-L <?= number_format($p['egr_nomina'], 0) ?></td>
                            <td class="text-end text-danger">
                                <?php if ($p['es_pasado'] && $p['egr_real'] !== null): ?>-L
                                <?= number_format($p['egr_real'] - $p['egr_nomina'], 0) ?>
                                <?php else: ?>-L
                                <?= number_format($p['egr_fijos'] + $p['egr_variables'], 0) ?><?php endif; ?>
                            </td>
                            <td class="text-end fw-bold text-danger">
                                <?php if ($p['es_pasado'] && $p['egr_real'] !== null): ?>-L
                                <?= number_format($p['egr_real'], 0) ?>
                                <?php else: ?>-L <?= number_format($p['egr_total'], 0) ?><?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php
                                if ($p['es_pasado'] && $p['ing_real'] !== null && $p['egr_real'] !== null) {
                                    $fr = $p['ing_real'] - $p['egr_real'];
                                    $cl = $fr >= 0 ? 'fb-ok' : 'fb-crit';
                                    echo "<span class='flujo-badge $cl'>" . ($fr < 0 ? '-' : '') . 'L ' . number_format(abs($fr), 0) . "</span>";
                                } else {
                                    $cl = $p['alerta'] === 'critico' ? 'fb-crit' : ($p['alerta'] === 'atencion' ? 'fb-at' : 'fb-ok');
                                    echo "<span class='flujo-badge $cl'>" . ($p['flujo'] < 0 ? '-' : '') . 'L ' . number_format(abs($p['flujo']), 0) . "</span>";
                                }
                                ?>
                            </td>
                            <td class="text-center">
                                <?php if ($p['es_pasado']): ?><span class="al-badge"
                                        style="background:#f1f5f9;color:#64748b">Cerrado</span>
                                <?php elseif ($p['alerta'] === 'critico'): ?><span class="al-badge al-crit"><i
                                            class="bi bi-exclamation-triangle-fill me-1"
                                            style="font-size:9px"></i>Crítico</span>
                                <?php elseif ($p['alerta'] === 'atencion'): ?><span class="al-badge al-at"><i
                                            class="bi bi-exclamation-circle-fill me-1" style="font-size:9px"></i>Atención</span>
                                <?php else: ?><span class="al-badge al-ok"><i class="bi bi-check-circle-fill me-1"
                                            style="font-size:9px"></i>OK</span><?php endif; ?>
                            </td>
                            <td class="text-center">
                                <button class="btn btn-xs btn-outline-secondary btn-desglose"
                                    style="font-size:.7rem;padding:2px 9px" data-idx="<?= $i ?>"
                                    data-pasado="<?= $p['es_pasado'] ? 1 : 0 ?>" data-anio="<?= $p['anio'] ?>"
                                    data-mes="<?= $p['mes'] ?>">
                                    <i class="bi bi-receipt"></i>
                                </button>
                            </td>
                        </tr>
                        <!-- FILA DESGLOSE (oculta) -->
                        <tr class="det-row d-none" id="det-<?= $i ?>">
                            <td colspan="11" class="det-row-cell"></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#f8fafc;font-weight:700;font-size:.83rem">
                        <td>TOTAL 12 MESES</td>
                        <td class="text-end text-success">L <?= number_format(array_sum(array_column($proyeccion, 'ing_estandar')) + array_sum(array_column($proyeccion, 'ing_periodico')), 0) ?></td>
                        <td class="text-end">L <?= number_format(array_sum(array_column($proyeccion, 'ing_recibo')), 0) ?></td>
                        <td class="text-end">L <?= number_format(array_sum(array_column($proyeccion, 'ing_cxc')), 0) ?></td>
                        <td class="text-end text-success">L <?= number_format($total_ing_proy, 0) ?></td>
                        <td class="text-end text-warning">-L <?= number_format($nomina_mensual * 12, 0) ?></td>
                        <td class="text-end text-danger">-L
                            <?= number_format(($prom_fijos + $prom_variables) * 12, 0) ?>
                        </td>
                        <td class="text-end text-danger">-L <?= number_format($total_egr_proy, 0) ?></td>
                        <td class="text-end <?= $total_flujo >= 0 ? 'text-success' : 'text-danger' ?>">
                            <?= $total_flujo < 0 ? '-' : '' ?>L <?= number_format(abs($total_flujo), 0) ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- Supuestos -->
    <div class="pj-card">
        <div class="pj-card-hdr"><span class="pj-card-title"><i class="bi bi-info-circle text-secondary"></i> Supuestos
                del Modelo</span></div>
        <div class="p-4" style="font-size:.83rem">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="fw-bold mb-1 text-success"><i class="bi bi-arrow-up-circle me-1"></i>Ingresos</div>
                    <ul class="text-muted ps-3 mb-0" style="line-height:1.9">
                        <li>Contratos estándar: cobro todos los meses</li>
                        <li>Contratos periódicos: solo en meses de ciclo</li>
                        <li>Contratos rotativos: según turno del mes</li>
                        <li>Contratos sin factura: todos los meses</li>
                    </ul>
                </div>
                <div class="col-md-4">
                    <div class="fw-bold mb-1 text-danger"><i class="bi bi-arrow-down-circle me-1"></i>Egresos</div>
                    <ul class="text-muted ps-3 mb-0" style="line-height:1.9">
                        <li>Nómina: salario bruto + cargas patronales IHSS/RAP</li>
                        <li>Gastos fijos: cuotas programadas hasta su fecha de vencimiento</li>
                        <li>Gastos variables: importes registrados según su frecuencia</li>
                        <li><strong>Excluye sueldos del promedio</strong> (ya están en nómina)</li>
                        <li>Pagos futuros registrados: incluidos en su mes correspondiente</li>
                        <li>Pagos únicos: solo en el mes de su fecha, sin repetirse</li>
                        <li>Gastos anuales: importe completo en el mes de pago</li>
                    </ul>
                </div>
                <div class="col-md-4">
                    <div class="fw-bold mb-1 text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Alertas</div>
                    <ul class="text-muted ps-3 mb-0" style="line-height:1.9">
                        <li><span class="al-badge al-crit">Crítico</span> Flujo negativo</li>
                        <li><span class="al-badge al-at">Atención</span> Margen &lt;15%</li>
                        <li><span class="al-badge al-ok">OK</span> Flujo positivo saludable</li>
                        <li>Actualizado en cada visita</li>
                    </ul>
                </div>
            </div>
            <div class="mt-3 pt-3 border-top text-muted" style="font-size:.77rem">
                <i class="bi bi-clock-history me-1"></i>
                Generado el <?= date('d/m/Y H:i') ?> ·
                <?= count($contratos) ?> contrato(s) activo(s) ·
                Nómina mensual: L <?= number_format($nomina_mensual, 2) ?> ·
                Gastos fijos promedio proyectado: L <?= number_format($prom_fijos, 2) ?> ·
                Gastos variables promedio proyectado: L <?= number_format($prom_variables, 2) ?>
            </div>
        </div>
    </div>
</div>


<script>
    /* ── Datos para desglose ─────────────────────────────────────────────────── */
    const COLABS = <?= $colabs_json ?>;
    const PROYECCION = <?= json_encode($proyeccion, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const NOMINA_M = <?= round($nomina_mensual, 2) ?>;
    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    const fmtL = v => 'L ' + parseFloat(v).toLocaleString('es-HN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });

    /* ── Render desglose proyectado ──────────────────────────────────────────── */
    function renderDesgloseProyectado(cell, idx) {
        const p = PROYECCION[idx];
        const GASTOS_M = p.egr_fijos + p.egr_variables;
        // Nómina breakdown
        let nomHtml = COLABS.map(c => {
            const bruto = parseFloat(c.salario_base);
            const ihss = parseFloat(c.ihss_pat);
            const rap = parseFloat(c.rap_pat);
            const costo = bruto + ihss + rap;
            return `<div class="det-item">
            <span class="det-item-name"><i class="bi bi-person me-1 text-muted"></i>${esc(c.nombre)}</span>
            <span class="det-item-amt text-warning">${fmtL(costo)}</span>
        </div>
        <div style="font-size:.7rem;color:#94a3b8;padding:0 .5rem .25rem 1.5rem">
            Bruto ${fmtL(bruto)}${ihss>0?' · IHSS '+fmtL(ihss):''}${rap>0?' · RAP '+fmtL(rap):''}
        </div>`;
        }).join('');

        const gastHtml = p.gastos_detalle.map(g => `<div class="det-item">
            <span class="det-item-name">${esc(g.descripcion || g.categoria)}<br>
            <small class="text-muted">${esc(g.categoria)} · ${esc(g.tipo)} · ${esc(g.frecuencia)}<br>
            ${g.fechas.map(esc).join(', ')} · ${g.fechas.length} pago(s) × ${fmtL(g.monto)}<br>
            Vencimiento: ${esc(g.fecha_vencimiento || 'Sin fecha de fin')}</small></span>
            <span class="det-item-amt text-danger">${fmtL(g.total)}</span></div>`).join('') || '<p class="text-muted">Sin gastos programados para este mes.</p>';
        const ingresosHtml = p.ing_detalle.map(i => `<div class="det-item" style="gap:1rem;align-items:flex-start"><span class="det-item-name"><strong>${esc(i.cliente)}</strong><br><small>${esc(i.nombre)}</small><br><small class="text-muted">${esc(i.regla)}</small></span><span class="det-item-amt text-success">${fmtL(i.monto)}</span></div>`).join('') || '<p class="text-muted">Sin ingresos previstos.</p>';

        cell.innerHTML = `
    <div class="mb-3"><div class="det-section-title">Ingresos proyectados · ${esc(p.mes_nombre)} ${p.anio}</div>${ingresosHtml}
        <div class="det-total">Contratos: ${fmtL(p.ing_estandar + p.ing_periodico)} + Recibos: ${fmtL(p.ing_recibo)}${p.ing_cxc > 0 ? ' + Por cobrar: ' + fmtL(p.ing_cxc) : ''} = Total ingresos: ${fmtL(p.ing_total)}</div></div>
    <div class="det-grid">
        <div>
            <div class="det-section-title"><i class="bi bi-people-fill" style="color:#f59e0b"></i> Nómina proyectada</div>
            ${nomHtml}
            <div class="det-total"><span>Total nómina/mes</span><span class="text-warning">${fmtL(NOMINA_M)}</span></div>
        </div>
        <div>
            <div class="det-section-title"><i class="bi bi-receipt" style="color:#ef4444"></i> Gastos programados del mes</div>
            ${gastHtml}
            <div class="det-total"><span>Total gastos del mes</span><span class="text-danger">${fmtL(GASTOS_M)}</span></div>
        </div>
    </div>
    <div class="det-total mt-3" style="border-top:2px solid var(--border);padding-top:.6rem">
        <span style="font-size:.85rem">💸 Total egresos proyectados/mes</span>
        <span style="font-size:1rem;color:#dc2626">${fmtL(p.egr_total)}</span>
    </div>
    <div class="det-nota">Gastos = fijos ${fmtL(p.egr_fijos)} + variables/otros ${fmtL(p.egr_variables)}.
    Egresos = nómina ${fmtL(p.egr_nomina)} + gastos ${fmtL(GASTOS_M)}.<br>
    Flujo neto = ingresos ${fmtL(p.ing_total)} − egresos ${fmtL(p.egr_total)} = <strong>${fmtL(p.flujo)}</strong>.<br>
    Estado: ${esc(p.alerta)}. ${esc(p.recomendacion)} El mes actual muestra la previsión completa, no un cierre real.</div>`;
    }

    /* ── Render desglose REAL (mes cerrado) ──────────────────────────────────── */
    async function renderDesgloseReal(cell, anio, mes) {
        cell.innerHTML =
            '<div class="py-3 text-center text-muted"><div class="spinner-border spinner-border-sm me-2"></div>Cargando gastos reales…</div>';
        try {
            const r = await fetch(`includes/proyeccion_desglose_real.php?anio=${anio}&mes=${mes}`);
            const d = await r.json();
            if (d.error) {
                cell.innerHTML = `<p class="text-danger small p-2">${d.error}</p>`;
                return;
            }

            let rowsGast = d.gastos.length ? d.gastos.map(g =>
                `<div class="det-item"><span class="det-item-name">${g.tipo==='fijo'?'🔒':'📊'} ${g.descripcion}</span>
             <span class="det-item-amt text-danger">${fmtL(g.monto)}</span></div>`
            ).join('') : '<div class="text-muted small ps-2">Sin gastos registrados.</div>';

            cell.innerHTML = `
        <div class="det-grid">
            <div>
                <div class="det-section-title"><i class="bi bi-cash-stack" style="color:#10b981"></i>Ingresos reales</div>
                <div class="det-item"><span>Facturas emitidas</span><span class="det-item-amt text-success">${fmtL(d.ing_total)}</span></div>
            </div>
            <div>
                <div class="det-section-title"><i class="bi bi-receipt" style="color:#ef4444"></i>Gastos reales del mes</div>
                ${rowsGast}
                <div class="det-total"><span>Total gastos</span><span class="text-danger">${fmtL(d.egr_total)}</span></div>
            </div>
        </div>
        <div class="det-total mt-3" style="border-top:2px solid var(--border);padding-top:.6rem">
            <span>💸 Flujo neto real</span>
            <span style="color:${d.flujo>=0?'#059669':'#dc2626'};font-size:1rem">
                ${d.flujo<0?'-':''}${fmtL(Math.abs(d.flujo))}
            </span>
        </div>`;
        } catch (e) {
            cell.innerHTML = '<p class="text-danger small p-2">Error de conexión.</p>';
        }
    }

    /* ── Toggle botón desglose ───────────────────────────────────────────────── */
    document.querySelectorAll('.btn-desglose').forEach(btn => {
        btn.addEventListener('click', function() {
            const idx = this.dataset.idx;
            const esPasado = this.dataset.pasado === '1';
            const anio = this.dataset.anio;
            const mes = this.dataset.mes;
            const detRow = document.getElementById('det-' + idx);
            const cell = detRow.querySelector('.det-row-cell');
            const isOpen = !detRow.classList.contains('d-none');

            // Cerrar todos los demás
            document.querySelectorAll('[id^="det-"]').forEach(r => r.classList.add('d-none'));
            document.querySelectorAll('.btn-desglose').forEach(b => {
                b.innerHTML = '<i class="bi bi-receipt"></i>';
                b.classList.remove('btn-secondary');
            });

            if (isOpen) return; // ya estaba abierto → solo cerrar

            detRow.classList.remove('d-none');
            this.innerHTML = '<i class="bi bi-x-lg"></i>';

            if (esPasado) {
                renderDesgloseReal(cell, anio, mes);
            } else {
                renderDesgloseProyectado(cell, idx);
            }
        });
    });

    /* ── Chart.js ────────────────────────────────────────────────────────────── */
    (function() {
        const ctx = document.getElementById('chartProy').getContext('2d');
        const labels = <?= json_encode($chart_labels) ?>;
        const ing = <?= json_encode($chart_ing) ?>;
        const egr = <?= json_encode($chart_egr) ?>;
        const flujo = <?= json_encode($chart_flujo) ?>;
        const nomina = Array(12).fill(<?= round($nomina_mensual, 2) ?>);
        const flujoColors = flujo.map(v => v >= 0 ? 'rgba(16,185,129,.85)' : 'rgba(239,68,68,.85)');

        new Chart(ctx, {
            data: {
                labels,
                datasets: [{
                        type: 'bar',
                        label: 'Ingresos proyectados',
                        data: ing,
                        backgroundColor: 'rgba(16,185,129,.65)',
                        borderColor: '#10b981',
                        borderWidth: 1,
                        borderRadius: 4,
                        order: 2
                    },
                    {
                        type: 'bar',
                        label: 'Egresos estimados',
                        data: egr,
                        backgroundColor: 'rgba(239,68,68,.55)',
                        borderColor: '#ef4444',
                        borderWidth: 1,
                        borderRadius: 4,
                        order: 2
                    },
                    {
                        type: 'line',
                        label: 'Flujo neto',
                        data: flujo,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59,130,246,.08)',
                        borderWidth: 2.5,
                        pointBackgroundColor: flujoColors,
                        pointRadius: 5,
                        fill: false,
                        tension: 0.3,
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
                        fill: false,
                        tension: 0,
                        order: 1
                    },
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
                            color: 'rgba(0,0,0,.04)'
                        },
                        ticks: {
                            callback: v => 'L' + (v >= 1000 ? (v / 1000).toFixed(0) + 'k' : v)
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

<?php require_once '../../includes/templates/footer.php'; ?>