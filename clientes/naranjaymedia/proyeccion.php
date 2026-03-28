<?php
$titulo = 'Proyección de Flujo de Caja';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';
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
    SELECT id, tipo_contrato, monto, dia_pago,
           frecuencia_meses, mes_inicio_ciclo, fecha_inicio, fecha_fin
    FROM contratos WHERE cliente_id = ? AND estado = 'activo'
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

// 3. Promedio gastos fijos mensuales/quincenales (excluye únicos y anuales del avg)
//    Gastos 'unico' → se excluyen (no se repiten)
//    Gastos 'anual'  → se amortiza /12 y se suma aparte
$stmtFij = $pdo->prepare("
    SELECT COALESCE(AVG(tot),0) FROM (
        SELECT SUM(monto) tot FROM gastos
        WHERE cliente_id=? AND tipo='fijo' AND estado!='anulado'
          AND frecuencia IN ('mensual','quincenal')
          AND fecha BETWEEN DATE_SUB(CURDATE(), INTERVAL 3 MONTH) AND CURDATE()
          AND categoria_id NOT IN ($excl_sql)
        GROUP BY YEAR(fecha), MONTH(fecha)
    ) t
");
$stmtFij->execute([$cliente_id]);
$prom_fijos = (float)$stmtFij->fetchColumn();

// Amortización de gastos anuales fijos (÷ 12 cada uno)
$stmtAnual = $pdo->prepare("
    SELECT COALESCE(SUM(monto)/12, 0)
    FROM gastos
    WHERE cliente_id=? AND tipo='fijo' AND estado!='anulado'
      AND frecuencia='anual'
      AND fecha BETWEEN DATE_SUB(CURDATE(), INTERVAL 12 MONTH) AND DATE_ADD(CURDATE(), INTERVAL 1 MONTH)
      AND categoria_id NOT IN ($excl_sql)
");
$stmtAnual->execute([$cliente_id]);
$prom_fijos += (float)$stmtAnual->fetchColumn();

// 4. Promedio gastos variables recurrentes (excluye 'unico' → no se proyectan)
//    Gastos 'unico' son eventos pasados, no se esperan en el futuro
$stmtVar = $pdo->prepare("
    SELECT COALESCE(AVG(tot),0) FROM (
        SELECT SUM(monto) tot FROM gastos
        WHERE cliente_id=? AND tipo='variable' AND estado!='anulado'
          AND frecuencia IN ('mensual','quincenal')
          AND fecha BETWEEN DATE_SUB(CURDATE(), INTERVAL 3 MONTH) AND CURDATE()
          AND categoria_id NOT IN ($excl_sql)
        GROUP BY YEAR(fecha), MONTH(fecha)
    ) t
");
$stmtVar->execute([$cliente_id]);
$prom_variables = (float)$stmtVar->fetchColumn();

$egreso_mensual_base = $nomina_mensual + $prom_fijos + $prom_variables;

// ── Desglose nómina por colaborador (para panel detalle) ─────────────────────
$stmtColabs = $pdo->prepare("
    SELECT nombre, salario_base,
           CASE WHEN aplica_ihss=1 THEN LEAST(salario_base,10294.10)*0.07 ELSE 0 END AS ihss_pat,
           CASE WHEN aplica_rap=1  THEN salario_base*0.015 ELSE 0 END AS rap_pat
    FROM colaboradores WHERE cliente_id=? AND activo=1 ORDER BY salario_base DESC
");
$stmtColabs->execute([$cliente_id]);
$colabs_desglose = $stmtColabs->fetchAll(PDO::FETCH_ASSOC);

// ── Desglose gastos recurrentes promedio (excluye nómina + futuros) ───────────
$stmtGastosDesg = $pdo->prepare("
    SELECT cg.nombre AS categoria, cg.color, g.tipo, g.frecuencia,
           COALESCE(g.descripcion, cg.nombre) AS descripcion,
           SUM(g.monto)  AS total_3m,
           COUNT(DISTINCT CONCAT(YEAR(g.fecha),'-',MONTH(g.fecha))) AS meses_n
    FROM gastos g
    INNER JOIN categorias_gastos cg ON cg.id = g.categoria_id
    WHERE g.cliente_id = ? AND g.estado != 'anulado'
      AND g.fecha BETWEEN DATE_SUB(CURDATE(), INTERVAL 3 MONTH) AND CURDATE()
      AND g.categoria_id NOT IN ($excl_sql)
      AND g.frecuencia IN ('mensual','quincenal','anual')
    GROUP BY g.descripcion, g.categoria_id, g.frecuencia
    ORDER BY total_3m DESC LIMIT 30
");
$stmtGastosDesg->execute([$cliente_id]);
$gastos_desglose = $stmtGastosDesg->fetchAll(PDO::FETCH_ASSOC);
foreach ($gastos_desglose as &$gd) {
    // Para anuales: prom = monto/12 (amortizado); para únicos no llegan aquí ya
    if ($gd['frecuencia'] === 'anual') {
        $gd['prom']  = round((float)$gd['total_3m'] / 12, 2);
        $gd['tag']   = 'anual';
    } else {
        $gd['prom']  = round((float)$gd['total_3m'] / max((int)$gd['meses_n'], 1), 2);
        $gd['tag']   = $gd['frecuencia'];
    }
}
unset($gd);
$colabs_json = json_encode($colabs_desglose, JSON_UNESCAPED_UNICODE);
$gastos_json = json_encode($gastos_desglose, JSON_UNESCAPED_UNICODE);

// 5. Proyección 12 meses
$proyeccion = [];
for ($offset = 0; $offset < 12; $offset++) {
    $mes  = (($hoy_mes - 1 + $offset) % 12) + 1;
    $anio = $hoy_año + intdiv($hoy_mes - 1 + $offset, 12);
    $ing_estandar = $ing_periodico = $ing_recibo = 0;

    foreach ($contratos as $ct) {
        $fi = new DateTime($ct['fecha_inicio']);
        $ff = $ct['fecha_fin'] ? new DateTime($ct['fecha_fin']) : null;
        if ($fi > new DateTime("$anio-$mes-28")) continue;
        if ($ff && $ff < new DateTime("$anio-$mes-01")) continue;
        $monto = (float)$ct['monto'];
        switch ($ct['tipo_contrato']) {
            case 'estandar':
                $ing_estandar += $monto;
                break;
            case 'periodico':
                $freq = (int)($ct['frecuencia_meses'] ?? 1);
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
                    $stRot = $pdo->prepare("SELECT monto,orden FROM contratos_clientes_rotativos WHERE contrato_id=? AND activo=1 ORDER BY orden ASC");
                    $stRot->execute([$ct['id']]);
                    $turnos = $stRot->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($turnos)) {
                        $fr = max(1, (int)($ct['frecuencia_meses'] ?? 1));
                        $ct2 = count($turnos) * $fr;
                        $pc = (($od % $ct2) + $ct2) % $ct2;
                        $ing_estandar += (float)$turnos[(int)floor($pc / $fr)]['monto'];
                    }
                }
                break;
            case 'sin_factura':
                $ing_recibo += $monto;
                break;
        }
    }

    $ing_real = $egr_real = null;
    if ($anio < $hoy_año || ($anio == $hoy_año && $mes <= $hoy_mes)) {
        $stR = $pdo->prepare("SELECT COALESCE(SUM(subtotal),0) FROM facturas WHERE cliente_id=? AND estado='emitida' AND YEAR(fecha_emision)=? AND MONTH(fecha_emision)=?");
        $stR->execute([$cliente_id, $anio, $mes]);
        $ing_real = (float)$stR->fetchColumn();
        $stE = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM gastos WHERE cliente_id=? AND estado!='anulado' AND YEAR(fecha)=? AND MONTH(fecha)=?");
        $stE->execute([$cliente_id, $anio, $mes]);
        $egr_real = (float)$stE->fetchColumn();
    }

    $ing_total = $ing_estandar + $ing_periodico + $ing_recibo;
    $egr_total = $egreso_mensual_base;
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
        'ing_total' => $ing_total,
        'egr_nomina' => $nomina_mensual,
        'egr_fijos' => $prom_fijos,
        'egr_variables' => $prom_variables,
        'egr_total' => $egr_total,
        'flujo' => $flujo,
        'alerta' => $alerta,
        'recomendacion' => $rec,
        'ing_real' => $ing_real,
        'egr_real' => $egr_real,
        'es_pasado' => ($ing_real !== null),
        'es_actual' => ($anio == $hoy_año && $mes == $hoy_mes),
    ];
}

// Cache
$stCache = $pdo->prepare("INSERT INTO proyecciones_cache (cliente_id,anio,mes,ing_contratos_estandar,ing_contratos_periodicos,ing_contratos_recibo,ing_total_proyectado,egr_nomina,egr_gastos_fijos_prom,egr_gastos_var_prom,egr_total_proyectado,flujo_neto,alerta_nivel,recomendacion) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE ing_contratos_estandar=VALUES(ing_contratos_estandar),ing_contratos_periodicos=VALUES(ing_contratos_periodicos),ing_contratos_recibo=VALUES(ing_contratos_recibo),ing_total_proyectado=VALUES(ing_total_proyectado),egr_nomina=VALUES(egr_nomina),egr_gastos_fijos_prom=VALUES(egr_gastos_fijos_prom),egr_gastos_var_prom=VALUES(egr_gastos_var_prom),egr_total_proyectado=VALUES(egr_total_proyectado),flujo_neto=VALUES(flujo_neto),alerta_nivel=VALUES(alerta_nivel),recomendacion=VALUES(recomendacion),generado_en=CURRENT_TIMESTAMP");
foreach ($proyeccion as $p) {
    $stCache->execute([$cliente_id, $p['anio'], $p['mes'], $p['ing_estandar'], $p['ing_periodico'], $p['ing_recibo'], $p['ing_total'], $p['egr_nomina'], $p['egr_fijos'], $p['egr_variables'], $p['egr_total'], $p['flujo'], $p['alerta'], $p['recomendacion']]);
}

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
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
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
        max-width: 220px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap
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
    @media print {
        body {
            background: #fff !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact
        }

        .no-print {
            display: none !important
        }

        .pj-hero {
            box-shadow: none !important
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

        .pj-table {
            font-size: .72rem
        }

        .pj-table thead th,
        .pj-table tbody td {
            padding: .35rem .5rem
        }

        .det-row,
        .btn-desglose {
            display: none !important
        }

        .pj-wrap {
            padding: .5rem 0 1rem
        }

        a[href]:after {
            content: '' !important
        }
    }
</style>

<div class="container-xxl pj-wrap">
    <div class="pj-hero">
        <div>
            <h4 style="font-size:1.35rem;font-weight:800;margin:0">📈 Proyección de Flujo de Caja</h4>
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
            <small class="text-muted" style="font-size:.75rem">Egresos = promedio real últimos 3 meses · Sin sueldos
                dobles</small>
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
                            <td colspan="10" class="det-row-cell"></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#f8fafc;font-weight:700;font-size:.83rem">
                        <td>TOTAL 12 MESES</td>
                        <td class="text-end text-success">L <?= number_format($total_ing_proy, 0) ?></td>
                        <td></td>
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
                        <li>Gastos fijos: promedio real últimos 3 meses</li>
                        <li>Gastos variables: promedio real últimos 3 meses</li>
                        <li><strong>Excluye sueldos del promedio</strong> (ya están en nómina)</li>
                        <li><strong>Excluye gastos extraordinarios y futuros</strong></li>
                        <li><strong>Excluye pagos únicos</strong> (no se repiten)</li>
                        <li>Gastos anuales: amortizados ÷ 12/mes</li>
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
                Gastos fijos prom: L <?= number_format($prom_fijos, 2) ?> ·
                Gastos variables prom: L <?= number_format($prom_variables, 2) ?>
            </div>
        </div>
    </div>
</div>


<script>
    /* ── Datos para desglose ─────────────────────────────────────────────────── */
    const COLABS = <?= $colabs_json ?>;
    const GASTOS_DG = <?= $gastos_json ?>;
    const NOMINA_M = <?= round($nomina_mensual, 2) ?>;
    const GASTOS_M = <?= round($prom_fijos + $prom_variables, 2) ?>;

    const fmtL = v => 'L ' + parseFloat(v).toLocaleString('es-HN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });

    /* ── Render desglose proyectado ──────────────────────────────────────────── */
    function renderDesgloseProyectado(cell) {
        // Nómina breakdown
        let nomHtml = COLABS.map(c => {
            const bruto = parseFloat(c.salario_base);
            const ihss = parseFloat(c.ihss_pat);
            const rap = parseFloat(c.rap_pat);
            const costo = bruto + ihss + rap;
            return `<div class="det-item">
            <span class="det-item-name"><i class="bi bi-person me-1 text-muted"></i>${c.nombre}</span>
            <span class="det-item-amt text-warning">${fmtL(costo)}</span>
        </div>
        <div style="font-size:.7rem;color:#94a3b8;padding:0 .5rem .25rem 1.5rem">
            Bruto ${fmtL(bruto)}${ihss>0?' · IHSS '+fmtL(ihss):''}${rap>0?' · RAP '+fmtL(rap):''}
        </div>`;
        }).join('');

        // Gastos recurrentes breakdown
        let gastHtml = '';
        if (GASTOS_DG.length === 0) {
            gastHtml = '<div class="text-muted small ps-2">Sin datos de los últimos 3 meses.</div>';
        } else {
            GASTOS_DG.forEach(g => {
                const tipo = g.tipo === 'fijo' ? '🔒' : '📊';
                const tagMap = {
                    anual: 'anual ÷12',
                    mensual: 'mensual',
                    quincenal: 'quincenal'
                };
                const tagClr = {
                    anual: '#dbeafe;color:#1d4ed8',
                    mensual: '#d1fae5;color:#065f46',
                    quincenal: '#ede9fe;color:#7c3aed'
                };
                const tagTxt = g.tag && tagMap[g.tag] ?
                    `<span style="font-size:.62rem;background:${tagClr[g.tag]};padding:1px 5px;border-radius:4px;margin-left:4px;font-weight:600">${tagMap[g.tag]}</span>` :
                    '';
                gastHtml += `<div class="det-item">
                <span class="det-item-name" title="${g.descripcion}">${tipo} ${g.descripcion}${tagTxt}</span>
                <span class="det-item-amt text-danger">~${fmtL(g.prom)}<small class="text-muted fw-normal">/mes</small></span>
            </div>`;
            });
            gastHtml += `<div class="det-nota">
            <i class="bi bi-info-circle me-1"></i>
            Solo recurrentes (mensual/quincenal). Anuales = monto÷12. Pagos únicos y extraordinarios <strong>excluidos</strong>.
        </div>`;
        }

        cell.innerHTML = `
    <div class="det-grid">
        <div>
            <div class="det-section-title"><i class="bi bi-people-fill" style="color:#f59e0b"></i> Nómina proyectada</div>
            ${nomHtml}
            <div class="det-total"><span>Total nómina/mes</span><span class="text-warning">${fmtL(NOMINA_M)}</span></div>
        </div>
        <div>
            <div class="det-section-title"><i class="bi bi-receipt" style="color:#ef4444"></i> Gastos fijos/variables recurrentes</div>
            ${gastHtml}
            <div class="det-total"><span>Total gastos/mes (prom.)</span><span class="text-danger">${fmtL(GASTOS_M)}</span></div>
        </div>
    </div>
    <div class="det-total mt-3" style="border-top:2px solid var(--border);padding-top:.6rem">
        <span style="font-size:.85rem">💸 Total egresos proyectados/mes</span>
        <span style="font-size:1rem;color:#dc2626">${fmtL(NOMINA_M + GASTOS_M)}</span>
    </div>`;
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
                renderDesgloseProyectado(cell);
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