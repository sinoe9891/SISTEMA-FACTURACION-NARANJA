<?php
$titulo = 'Facturas del Contrato';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';
require_once '../../includes/cuentas.php';
require_once '../../includes/anticipos.php';
require_once '../../includes/contrato_plan.php';
require_once '../../includes/templates/header.php';

$cliente_id  = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);

$contrato_id = (int)($_GET['contrato_id'] ?? 0);
if (!$contrato_id) {
    header('Location: contratos');
    exit;
}

// ── Datos del contrato ────────────────────────────────────────────────────────
$stmtC = $pdo->prepare("
    SELECT c.*,
           cf.nombre   AS receptor_nombre, cf.rtn AS receptor_rtn,
           cf.email    AS receptor_email,  cf.telefono AS receptor_tel,
           p.nombre    AS producto_nombre
    FROM contratos c
    INNER JOIN clientes_factura   cf ON cf.id=c.receptor_id AND cf.cliente_id=c.cliente_id
    INNER JOIN productos_clientes p  ON p.id=c.producto_id  AND p.cliente_id=c.cliente_id
    WHERE c.id=? AND c.cliente_id=?
");
$stmtC->execute([$contrato_id, $cliente_id]);
$contrato = $stmtC->fetch(PDO::FETCH_ASSOC);
if (!$contrato) {
    header('Location: contratos');
    exit;
}

// ── Facturas ──────────────────────────────────────────────────────────────────
$stmtF = $pdo->prepare("
    SELECT f.*, rf.nombre AS empresa_factura,
           COALESCE(f.periodo_mes,  MONTH(f.fecha_emision)) AS periodo_mes_ef,
           COALESCE(f.periodo_anio, YEAR(f.fecha_emision))  AS periodo_anio_ef
    FROM facturas f LEFT JOIN clientes_factura rf ON rf.id = f.receptor_id
    WHERE f.contrato_id=? AND f.cliente_id=? AND f.estado='emitida'
    ORDER BY f.fecha_emision DESC, f.id DESC
");
$stmtF->execute([$contrato_id, $cliente_id]);
$facturas = $stmtF->fetchAll(PDO::FETCH_ASSOC);
// Contrato con varias empresas (rotativo): a cuál se le facturó cada mes y cuánto a cada una
$porEmpresa = [];
foreach ($facturas as $fx) {
    $porEmpresa[$fx['empresa_factura'] ?? '—'] ??= ['n' => 0, 'total' => 0.0];
    $porEmpresa[$fx['empresa_factura'] ?? '—']['n']++;
    $porEmpresa[$fx['empresa_factura'] ?? '—']['total'] += (float)$fx['total'];
}
$variasEmpresas = count($porEmpresa) > 1;

// ── Abonos (cuentas por cobrar): cuánto se ha pagado de cada factura ─────────
$hayAbonos = cxcDisponible($pdo);
$puedeCobrar = in_array(USUARIO_ROL, ['admin', 'superadmin', 'facturador'], true);
$puedeCorreo = permisoPuede($pdo, 'cobros_programados');   // «Cobros por correo» es solo de administradores
$abonadoPor = [];
if ($hayAbonos && $facturas) {
    $ids = array_map('intval', array_column($facturas, 'id'));
    $stA = $pdo->query("SELECT factura_id, SUM(monto) FROM cobros_factura WHERE anulado = 0 AND factura_id IN (" . implode(',', $ids) . ") GROUP BY factura_id");
    $abonadoPor = $stA->fetchAll(PDO::FETCH_KEY_PAIR);
}
$totalCobrado = 0;
$totalSaldo = 0;
foreach ($facturas as &$f) {
    // Misma regla que cuentas por cobrar: con abonos manda la suma; sin abonos, la marca "pagada"
    $f['abonado'] = round((float)($abonadoPor[$f['id']] ?? 0), 2);
    $f['saldo'] = $f['abonado'] > 0 ? max(0, round((float)$f['total'] - $f['abonado'], 2)) : ((int)$f['pagada'] ? 0.0 : round((float)$f['total'], 2));
    $totalSaldo += $f['saldo'];
    $totalCobrado += (float)$f['total'] - $f['saldo'];
}
unset($f);

// ── Totales ───────────────────────────────────────────────────────────────────
$totalFacturado = 0;
$totalIsv = 0;
$totalSubtotal = 0;
foreach ($facturas as $f) {
    $totalFacturado += (float)$f['total'];
    $totalIsv       += (float)$f['isv_15'] + (float)$f['isv_18'];
    $totalSubtotal  += (float)$f['subtotal'];
}

// ── Calendario de meses ───────────────────────────────────────────────────────
$fechaInicio = new DateTime($contrato['fecha_inicio']);
$fechaRef    = $contrato['fecha_fin'] ? new DateTime($contrato['fecha_fin']) : new DateTime();
$fechaRef    = min($fechaRef, new DateTime());
$tipo_ct     = $contrato['tipo_contrato'] ?? 'estandar';
$frecuencia  = max(1, (int)($contrato['frecuencia_meses'] ?? 1));
$mesIniCiclo = (int)($contrato['mes_inicio_ciclo'] ?? (int)$fechaInicio->format('n'));
$anioIniCiclo = (int)$fechaInicio->format('Y');

$mesesEsperados = [];
$cursor = clone $fechaInicio;
$cursor->modify('first day of this month');
while ($cursor <= $fechaRef) {
    $mes  = (int)$cursor->format('n');
    $anio = (int)$cursor->format('Y');
    $key  = $anio . '-' . $mes;
    $debe = false;
    if ($tipo_ct === 'estandar' || $tipo_ct === 'sin_factura') {
        $debe = true;
    } elseif ($tipo_ct === 'rotativo') {
        // Rotativo: cada mes hay un cobro (diferente cliente del ciclo por turno)
        $debe = true;
    } elseif ($tipo_ct === 'periodico') {
        $offset = ($anio - $anioIniCiclo) * 12 + ($mes - $mesIniCiclo);
        if ($offset >= 0 && ($offset % $frecuencia) === 0) $debe = true;
    }
    if ($debe) $mesesEsperados[] = $key;
    $cursor->modify('+1 month');
}

$mesesConFactura = [];
foreach ($facturas as $f) {
    $pm = (int)($f['periodo_mes_ef']  ?? (int)substr($f['fecha_emision'], 5, 2));
    $pa = (int)($f['periodo_anio_ef'] ?? (int)substr($f['fecha_emision'], 0, 4));
    $mesesConFactura[$pa . '-' . $pm] = true;
}
// Contrato «sin factura»: se cobra con recibo; los meses cobrados son los que tienen recibo
$esRecibo = $tipo_ct === 'sin_factura';
$recibos = [];
$totalRecibos = 0.0;
if ($esRecibo) {
    $stR = $pdo->prepare("SELECT * FROM contratos_recibos WHERE contrato_id = ? AND cliente_id = ? AND estado = 'emitido' ORDER BY fecha_emision DESC, id DESC");
    $stR->execute([$contrato_id, $cliente_id]);
    $recibos = $stR->fetchAll(PDO::FETCH_ASSOC);
    foreach ($recibos as $r) {
        $mesesConFactura[(int)($r['periodo_anio'] ?: substr($r['fecha_emision'], 0, 4)) . '-' . (int)($r['periodo_mes'] ?: substr($r['fecha_emision'], 5, 2))] = true;
        $totalRecibos += (float)$r['monto'];
    }
}
// Plan de pagos acordado (anticipo, cuotas, etapas). Si existe, manda sobre el calendario por meses.
$hayPlan = planDisponible($pdo);
$plan = $hayPlan ? planLineas($pdo, $cliente_id, $contrato_id) : [];
$planRes = planResumen($plan);
$puedePlan = in_array(USUARIO_ROL, ['admin', 'superadmin', 'facturador'], true);
// Recordatorios por correo de cada pago del plan (el último enviado o programado)
$recordados = [];
if ($plan && $pdo->query("SHOW TABLES LIKE 'cobros_programados_plan'")->fetchColumn()) {
    $stRc = $pdo->prepare("SELECT x.plan_id, c.estado, c.programado_para, c.enviado_en FROM cobros_programados_plan x JOIN cobros_programados c ON c.id = x.cobro_id
                           WHERE c.cliente_id = ? AND c.prueba = 0 AND c.estado IN ('programado','enviando','enviado') AND x.plan_id IN (" . implode(',', array_map(fn($l) => (int)$l['id'], $plan)) . ")
                           ORDER BY c.programado_para");
    $stRc->execute([$cliente_id]);
    foreach ($stRc->fetchAll(PDO::FETCH_ASSOC) as $rc) $recordados[(int)$rc['plan_id']] = $rc;
}
$planPendIds = array_map(fn($l) => (int)$l['id'], array_filter($plan, fn($l) => in_array($l['estado'], ['pendiente', 'vencido'], true)));
// El mes actual nunca es "atrasado" — puede que aún no haya vencido el día de cobro
$keyActual       = date('Y') . '-' . (int)date('n');
$mesesSinFactura = array_filter($mesesEsperados, fn($m) => !isset($mesesConFactura[$m]) && $m !== $keyActual);
$totalMeses        = count($mesesEsperados);
$mesesOk           = count(array_intersect($mesesEsperados, array_keys($mesesConFactura)));
$mesesPend         = count($mesesSinFactura);
$pctCumplimiento   = $totalMeses > 0 ? round(($mesesOk / $totalMeses) * 100) : 0;
$noIniciado        = (new DateTime($contrato['fecha_inicio'])) > new DateTime();

// ── Proyecto por etapas: valor total, pagos anticipados (sin factura) y lo que falta ──
$esProyecto = $tipo_ct === 'proyecto';
$valorConIsv = round((float)$contrato['monto'] * 1.15, 2);   // los contratos guardan el monto sin ISV
$hayAnticipos = anticiposDisponible($pdo);
$anticipos = $hayAnticipos ? anticiposContrato($pdo, $cliente_id, $contrato_id) : [];
$antVigentes = array_filter($anticipos, fn($a) => !(int)$a['anulado']);
$antRecibido = round(array_sum(array_column($antVigentes, 'monto')), 2);
$antSinAplicar = round(array_sum(array_map(fn($a) => $a['factura_id'] ? 0 : (float)$a['monto'], $antVigentes)), 2);
// Recibido del cliente = anticipos aún sin factura + lo cobrado de las facturas del contrato
$recibidoTotal = round($antSinAplicar + $totalCobrado, 2);
$pctRecibido = $valorConIsv > 0 ? min(100, round($recibidoTotal / $valorConIsv * 100)) : 0;
$facturasConSaldo = array_values(array_filter($facturas, fn($f) => $f['saldo'] > 0.004));
$isvApartar = $esRecibo ? 0.0 : planIsvPorApartar($pdo, $cliente_id, $contrato_id);

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

// Agrupar por año para el calendario
$mesesPorAnio = [];
foreach (array_reverse($mesesEsperados) as $m) {
    $anio = substr($m, 0, 4);
    $mesesPorAnio[$anio][] = $m;
}

// ── Turnos rotativos (para mostrar qué cliente toca cada mes) ──────────────────
$turnosRotativos = [];
if ($tipo_ct === 'rotativo') {
    $stmtRot = $pdo->prepare("
        SELECT r.orden, r.monto, cf.nombre AS receptor_nombre, r.receptor_id
        FROM contratos_clientes_rotativos r
        INNER JOIN clientes_factura cf ON cf.id = r.receptor_id AND cf.cliente_id = ?
        WHERE r.contrato_id = ? AND r.activo = 1
        ORDER BY r.orden ASC
    ");
    $stmtRot->execute([$cliente_id, $contrato_id]);
    $turnosRotativos = $stmtRot->fetchAll(PDO::FETCH_ASSOC);
}

$tipoCls  = ['estandar' => 'tp-estandar', 'periodico' => 'tp-periodico', 'rotativo' => 'tp-rotativo', 'sin_factura' => 'tp-sin_factura', 'proyecto' => 'tp-periodico'];
$tipoLbl  = ['estandar' => 'Estándar', 'periodico' => 'Periódico', 'rotativo' => 'Rotativo', 'sin_factura' => 'Sin factura', 'proyecto' => 'Proyecto'];
$estadoCls = ['activo' => 'ep-activo', 'pausado' => 'ep-pausado', 'cancelado' => 'ep-cancelado', 'vencido' => 'ep-vencido', 'borrador' => 'ep-cancelado'];
$estadoIco = ['activo' => '✅', 'pausado' => '⏸', 'cancelado' => '❌', 'vencido' => '⌛', 'borrador' => '📝'];
?>

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
        font-family: 'Plus Jakarta Sans', sans-serif;
    }

    .fc-wrap {
        max-width: 1200px;
        margin: 0 auto;
        padding: 1.5rem 0 4rem;
    }

    /* Hero */
    .fc-hero {
        background: linear-gradient(135deg, #0f766e 0%, #1e40af 100%);
        border-radius: var(--radius);
        padding: 1.5rem 2rem;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1.75rem;
        box-shadow: 0 8px 32px rgba(15, 118, 110, .2);
        position: relative;
        overflow: hidden;
    }

    .fc-hero::before {
        content: '';
        position: absolute;
        top: -50px;
        right: -50px;
        width: 200px;
        height: 200px;
        border-radius: 50%;
        background: rgba(255, 255, 255, .07);
        pointer-events: none;
    }

    .fc-hero-title {
        font-size: 1.3rem;
        font-weight: 800;
        margin: 0;
    }

    .fc-hero-sub {
        font-size: .82rem;
        opacity: .78;
        margin: .15rem 0 0;
    }

    /* Cards */
    .fc-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow-sm);
        margin-bottom: 1.25rem;
        overflow: hidden;
    }

    .fc-card-hdr {
        padding: .9rem 1.4rem;
        border-bottom: 1px solid var(--border);
        background: var(--surface-2);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .75rem;
        flex-wrap: wrap;
    }

    .fc-card-title {
        font-size: .92rem;
        font-weight: 700;
        color: var(--text);
        display: flex;
        align-items: center;
        gap: .5rem;
    }

    .fc-card-body {
        padding: 1.25rem 1.4rem;
    }

    /* KPIs */
    .fc-kpis {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(155px, 1fr));
        gap: .85rem;
        margin-bottom: 1.25rem;
    }

    .fc-kpi {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 1rem 1.1rem;
        display: flex;
        align-items: center;
        gap: .8rem;
        box-shadow: var(--shadow-sm);
        transition: box-shadow .18s, transform .18s;
    }

    .fc-kpi:hover {
        box-shadow: var(--shadow-md);
        transform: translateY(-2px);
    }

    .fc-kpi-icon {
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
        background: var(--brand-lt);
        color: var(--brand);
    }

    .ki-green {
        background: #d1fae5;
        color: #059669;
    }

    .ki-amber {
        background: #fef3c7;
        color: #d97706;
    }

    .ki-red {
        background: #fee2e2;
        color: #dc2626;
    }

    .ki-blue {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .ki-purple {
        background: #ede9fe;
        color: #7c3aed;
    }

    .fc-kpi-val {
        font-size: 1rem;
        font-weight: 800;
        color: var(--text);
        line-height: 1.1;
    }

    .fc-kpi-lbl {
        font-size: .68rem;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: .04em;
    }

    /* Contrato info card */
    .cinfo-grid {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 1rem;
    }

    @media(max-width:768px) {
        .cinfo-grid {
            grid-template-columns: 1fr 1fr;
        }
    }

    .cinfo-item {
        text-align: center;
        padding: .6rem;
    }

    .cinfo-lbl {
        font-size: .68rem;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: .04em;
        margin-bottom: 4px;
    }

    .cinfo-val {
        font-size: .9rem;
        font-weight: 700;
        color: var(--text);
    }

    /* Tipo y estado pills */
    .tipo-pill {
        display: inline-flex;
        align-items: center;
        gap: .2rem;
        padding: .12rem .5rem;
        border-radius: 20px;
        font-size: .7rem;
        font-weight: 700;
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

    .estado-pill {
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        padding: .3rem .75rem;
        border-radius: 20px;
        font-size: .78rem;
        font-weight: 700;
    }

    .ep-activo {
        background: #d1fae5;
        color: #065f46;
    }

    .ep-pausado {
        background: #fef3c7;
        color: #92400e;
    }

    .ep-cancelado {
        background: #fee2e2;
        color: #991b1b;
    }

    .ep-vencido {
        background: #f1f5f9;
        color: #475569;
    }

    /* Tabla facturas */
    .fc-table {
        width: 100%;
        border-collapse: collapse;
        font-size: .84rem;
    }

    .fc-table thead th {
        padding: .65rem 1rem;
        background: var(--surface-2);
        color: var(--muted);
        font-size: .7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .04em;
        border-bottom: 1px solid var(--border);
        white-space: nowrap;
    }

    .fc-table tbody tr {
        border-bottom: 1px solid var(--border);
        transition: background .15s;
    }

    .fc-table tbody tr:last-child {
        border-bottom: none;
    }

    .fc-table tbody tr:hover {
        background: #f0fdf9;
    }

    .fc-table tbody td {
        padding: .75rem 1rem;
        vertical-align: middle;
    }

    .fc-table tfoot td {
        padding: .65rem 1rem;
        background: var(--surface-2);
        font-weight: 700;
        font-size: .84rem;
        border-top: 2px solid var(--border);
    }

    .fc-table tbody tr.tr-actual td {
        background: #f0fdf4;
    }

    .fc-table tbody tr.tr-anulada td {
        opacity: .55;
    }

    /* Calendario meses */
    .mes-chip {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 11px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
        white-space: nowrap;
        transition: transform .1s;
        cursor: default;
    }

    .mes-chip:hover {
        transform: scale(1.05);
    }

    .mes-chip.ok {
        background: #d1fae5;
        color: #065f46;
        border: 1px solid #6ee7b7;
    }

    .mes-chip.pend {
        background: #fee2e2;
        color: #991b1b;
        border: 1px solid #fca5a5;
    }

    .mes-chip.actual {
        background: #fef9c3;
        color: #713f12;
        border: 1px solid #fde047;
    }

    .mes-chip.noaplica {
        background: #f1f5f9;
        color: #94a3b8;
        border: 1px solid #e2e8f0;
    }

    /* Progress */
    .prog-wrap {
        height: 8px;
        background: var(--border);
        border-radius: 20px;
        overflow: hidden;
    }

    .prog-fill {
        height: 100%;
        border-radius: 20px;
        transition: width .4s ease;
    }

    .prog-green {
        background: linear-gradient(90deg, #10b981, #059669);
    }

    .prog-amber {
        background: linear-gradient(90deg, #f59e0b, #d97706);
    }

    .prog-red {
        background: linear-gradient(90deg, #ef4444, #dc2626);
    }

    /* Btn */
    .btn-facturar {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        padding: .55rem 1.2rem;
        background: var(--brand);
        color: #fff;
        border: none;
        border-radius: var(--radius-sm);
        font-size: .86rem;
        font-weight: 700;
        text-decoration: none;
        cursor: pointer;
        box-shadow: 0 3px 12px rgba(15, 118, 110, .25);
        transition: background .18s, transform .18s;
    }

    .btn-facturar:hover {
        background: var(--brand-dk);
        transform: translateY(-1px);
        color: #fff;
    }

    .anio-lbl {
        font-size: .7rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .08em;
        color: var(--muted);
        padding-bottom: 6px;
        border-bottom: 2px solid var(--border);
        margin-bottom: 8px;
    }
</style>

<div class="container-xxl fc-wrap">

    <!-- Hero -->
    <div class="fc-hero">
        <div>
            <h4 class="fc-hero-title"><i class="bi bi-receipt me-2"></i><?= $esRecibo ? 'Contrato con recibo' : 'Facturas del Contrato' ?></h4>
            <p class="fc-hero-sub">
                <?= htmlspecialchars($contrato['nombre_contrato']) ?> &nbsp;·&nbsp;
                <?= htmlspecialchars($contrato['receptor_nombre']) ?>
            </p>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <?php if ($esRecibo && $contrato['estado'] === 'activo'): ?>
                <a href="generar_recibo?contrato_id=<?= $contrato['id'] ?>" class="btn-facturar">
                    <i class="bi bi-receipt-cutoff"></i> Nuevo recibo
                </a>
            <?php elseif (!$noIniciado && $contrato['estado'] === 'activo'): ?>
                <a href="generar_factura?receptor_id=<?= $contrato['receptor_id'] ?>&producto_id=<?= $contrato['producto_id'] ?>&monto=<?= $contrato['monto'] ?>&contrato_id=<?= $contrato['id'] ?>"
                    class="btn-facturar">
                    <i class="bi bi-file-earmark-plus"></i> Nueva Factura
                </a>
            <?php endif; ?>
            <?php if ($puedeCorreo && !$esRecibo): ?>
                <a href="nuevo_cobro?receptor_id=<?= (int)$contrato['receptor_id'] ?>&contrato_id=<?= (int)$contrato['id'] ?>" class="btn btn-sm"
                    style="background:rgba(255,255,255,.18);color:#fff;border:1px solid rgba(255,255,255,.3);font-weight:600"
                    title="Enviar o programar por correo el cobro de las facturas con saldo de este contrato">
                    <i class="bi bi-send me-1"></i>Cobrar por correo
                </a>
            <?php endif; ?>
            <a href="contratos" class="btn btn-sm"
                style="background:rgba(255,255,255,.18);color:#fff;border:1px solid rgba(255,255,255,.3);font-weight:600">
                <i class="bi bi-arrow-left me-1"></i>Volver
            </a>
        </div>
    </div>

    <!-- KPIs -->
    <div class="fc-kpis">
        <?php if ($esRecibo): ?>
        <div class="fc-kpi">
            <div class="fc-kpi-icon ki-teal"><i class="bi bi-receipt-cutoff"></i></div>
            <div>
                <div class="fc-kpi-val"><?= count($recibos) ?></div>
                <div class="fc-kpi-lbl">Recibos</div>
            </div>
        </div>
        <div class="fc-kpi">
            <div class="fc-kpi-icon ki-green"><i class="bi bi-wallet2"></i></div>
            <div>
                <div class="fc-kpi-val" style="font-size:.88rem;color:#059669">L <?= number_format($totalRecibos, 2) ?></div>
                <div class="fc-kpi-lbl">Cobrado (sin ISV)</div>
            </div>
        </div>
        <?php else: ?>
        <div class="fc-kpi">
            <div class="fc-kpi-icon ki-teal"><i class="bi bi-file-earmark-text-fill"></i></div>
            <div>
                <div class="fc-kpi-val"><?= count($facturas) ?></div>
                <div class="fc-kpi-lbl">Facturas</div>
            </div>
        </div>
        <div class="fc-kpi">
            <div class="fc-kpi-icon ki-blue"><i class="bi bi-cash-stack"></i></div>
            <div>
                <div class="fc-kpi-val" style="font-size:.88rem">L <?= number_format($totalSubtotal, 0) ?></div>
                <div class="fc-kpi-lbl">Subtotal</div>
            </div>
        </div>
        <div class="fc-kpi">
            <div class="fc-kpi-icon ki-amber"><i class="bi bi-percent"></i></div>
            <div>
                <div class="fc-kpi-val" style="font-size:.88rem">L <?= number_format($totalIsv, 0) ?></div>
                <div class="fc-kpi-lbl">ISV total</div>
            </div>
        </div>
        <div class="fc-kpi">
            <div class="fc-kpi-icon ki-green"><i class="bi bi-coin"></i></div>
            <div>
                <div class="fc-kpi-val" style="font-size:.88rem">L <?= number_format($totalFacturado, 0) ?></div>
                <div class="fc-kpi-lbl">Total facturado</div>
            </div>
        </div>
        <div class="fc-kpi">
            <div class="fc-kpi-icon ki-green"><i class="bi bi-wallet2"></i></div>
            <div>
                <div class="fc-kpi-val" style="font-size:.88rem;color:#059669">L <?= number_format($totalCobrado, 2) ?></div>
                <div class="fc-kpi-lbl">Cobrado</div>
            </div>
        </div>
        <div class="fc-kpi" style="border-color:<?= $totalSaldo > 0 ? '#fecaca' : '#a7f3d0' ?>">
            <div class="fc-kpi-icon <?= $totalSaldo > 0 ? 'ki-red' : 'ki-green' ?>"><i class="bi bi-hourglass-split"></i></div>
            <div>
                <div class="fc-kpi-val" style="font-size:.88rem;color:<?= $totalSaldo > 0 ? '#dc2626' : '#059669' ?>">L <?= number_format($totalSaldo, 2) ?></div>
                <div class="fc-kpi-lbl">Saldo por cobrar</div>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($plan): ?>
        <div class="fc-kpi" style="border-color:<?= $planRes['vencido'] > 0 ? '#fecaca' : '#a7f3d0' ?>">
            <div class="fc-kpi-icon <?= $planRes['vencido'] > 0 ? 'ki-red' : 'ki-blue' ?>"><i class="bi bi-calendar2-check"></i></div>
            <div>
                <div class="fc-kpi-val" style="font-size:.88rem">L <?= number_format($planRes['pendiente'] + $planRes['facturado'], 2) ?></div>
                <div class="fc-kpi-lbl">Falta por cobrar del plan</div>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($esProyecto): ?>
        <div class="fc-kpi" style="border-color:#a7f3d0">
            <div class="fc-kpi-icon ki-green"><i class="bi bi-piggy-bank"></i></div>
            <div>
                <div class="fc-kpi-val" style="font-size:.88rem;color:#059669">L <?= number_format($recibidoTotal, 2) ?></div>
                <div class="fc-kpi-lbl">Recibido (<?= $pctRecibido ?>%)</div>
            </div>
        </div>
        <div class="fc-kpi" style="border-color:<?= $valorConIsv - $recibidoTotal > 0.004 ? '#fecaca' : '#a7f3d0' ?>">
            <div class="fc-kpi-icon <?= $valorConIsv - $recibidoTotal > 0.004 ? 'ki-red' : 'ki-green' ?>"><i class="bi bi-flag"></i></div>
            <div>
                <div class="fc-kpi-val" style="font-size:.88rem;color:<?= $valorConIsv - $recibidoTotal > 0.004 ? '#dc2626' : '#059669' ?>">L <?= number_format(max(0, $valorConIsv - $recibidoTotal), 2) ?></div>
                <div class="fc-kpi-lbl">Falta por recibir</div>
            </div>
        </div>
        <?php elseif (!$plan): ?>
        <div class="fc-kpi" style="border-color:<?= $mesesPend > 0 ? '#fecaca' : '#a7f3d0' ?>">
            <div class="fc-kpi-icon <?= $mesesPend > 0 ? 'ki-red' : 'ki-green' ?>"><i class="bi bi-calendar-check"></i>
            </div>
            <div>
                <div class="fc-kpi-val" style="color:<?= $mesesPend > 0 ? '#dc2626' : '#059669' ?>">
                    <?= $pctCumplimiento ?>%
                </div>
                <div class="fc-kpi-lbl"><?= $mesesOk ?>/<?= $totalMeses ?> meses cobrados</div>
            </div>
        </div>
        <?php if ($mesesPend > 0): ?>
            <div class="fc-kpi" style="border-color:#fecaca">
                <div class="fc-kpi-icon ki-red"><i class="bi bi-clock-history"></i></div>
                <div>
                    <div class="fc-kpi-val" style="color:#dc2626"><?= $mesesPend ?></div>
                    <div class="fc-kpi-lbl">Meses sin cobrar</div>
                </div>
            </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if ($contrato['estado'] === 'borrador'): ?>
        <div class="alert d-flex align-items-center gap-2 mb-3" style="border:1px dashed #94a3b8;background:#f8fafc;color:#334155">
            <i class="bi bi-pencil-square fs-5"></i>
            <div><strong>Contrato en borrador.</strong> No se factura ni se cobra y no cuenta en reportes, proyección ni cuentas por cobrar.
                Cuando esté listo, <a href="editar_contrato?id=<?= (int)$contrato['id'] ?>">ábrelo en Editar</a> y cambia el estado a «Activo».</div>
        </div>
    <?php endif; ?>
    <!-- Info del contrato -->
    <div class="fc-card">
        <div class="fc-card-hdr">
            <span class="fc-card-title">
                <i class="bi bi-file-earmark-check-fill text-primary"></i>
                Datos del Contrato
            </span>
            <div class="d-flex gap-2 align-items-center">
                <span
                    class="tipo-pill <?= $tipoCls[$tipo_ct] ?? 'tp-estandar' ?>"><?= $tipoLbl[$tipo_ct] ?? $tipo_ct ?></span>
                <span class="estado-pill <?= $estadoCls[$contrato['estado']] ?? 'ep-vencido' ?>">
                    <?= ($estadoIco[$contrato['estado']] ?? '') ?> <?= ucfirst($contrato['estado']) ?>
                </span>
                <a href="editar_contrato?id=<?= $contrato['id'] ?>" class="btn btn-sm btn-outline-warning">
                    <i class="bi bi-pencil-fill me-1"></i>Editar
                </a>
            </div>
        </div>
        <div class="fc-card-body">
            <div class="row g-3 align-items-start">
                <div class="col-md-4">
                    <div class="d-flex align-items-start gap-3">
                        <div
                            style="width:46px;height:46px;border-radius:12px;background:linear-gradient(135deg,#dbeafe,#bfdbfe);display:flex;align-items:center;justify-content:center;flex-shrink:0">
                            <i class="bi bi-person-fill text-primary" style="font-size:1.2rem"></i>
                        </div>
                        <div>
                            <div class="fw-bold" style="font-size:.95rem">
                                <?= htmlspecialchars($contrato['receptor_nombre']) ?></div>
                            <?php if ($contrato['receptor_rtn']): ?><small class="text-muted">RTN:
                                    <?= htmlspecialchars($contrato['receptor_rtn']) ?></small><?php endif; ?>
                            <div class="d-flex flex-column gap-0 mt-1">
                                <?php if ($contrato['receptor_tel']): ?><small class="text-muted"><i
                                            class="bi bi-telephone me-1"></i><?= htmlspecialchars($contrato['receptor_tel']) ?></small><?php endif; ?>
                                <?php if ($contrato['receptor_email']): ?><small class="text-muted"><i
                                            class="bi bi-envelope me-1"></i><?= htmlspecialchars($contrato['receptor_email']) ?></small><?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="cinfo-grid">
                        <div class="cinfo-item">
                            <div class="cinfo-lbl">Servicio</div>
                            <div class="cinfo-val" style="font-size:.82rem">
                                <?= htmlspecialchars($contrato['producto_nombre']) ?></div>
                        </div>
                        <div class="cinfo-item">
                            <div class="cinfo-lbl"><?= $esProyecto ? 'Valor del proyecto' : 'Monto mensual' ?></div>
                            <div class="cinfo-val text-primary">L <?= number_format((float)$contrato['monto'], 2) ?>
                                <?php if ($esProyecto): ?><div class="small text-muted fw-normal">+ ISV = L <?= number_format($valorConIsv, 2) ?></div><?php endif; ?>
                            </div>
                        </div>
                        <?php if (!$esProyecto): ?>
                        <div class="cinfo-item">
                            <div class="cinfo-lbl">Día de cobro</div>
                            <div class="cinfo-val">Día <?= (int)$contrato['dia_pago'] ?></div>
                        </div>
                        <?php endif; ?>
                        <div class="cinfo-item">
                            <div class="cinfo-lbl">Inicio</div>
                            <div class="cinfo-val"><?= date('d/m/Y', strtotime($contrato['fecha_inicio'])) ?></div>
                        </div>
                        <div class="cinfo-item">
                            <div class="cinfo-lbl">Vencimiento</div>
                            <div class="cinfo-val">
                                <?= $contrato['fecha_fin']
                                    ? date('d/m/Y', strtotime($contrato['fecha_fin']))
                                    : '<span class="badge" style="background:#dbeafe;color:#1e40af">Indefinido</span>' ?>
                            </div>
                        </div>
                        <?php if ($tipo_ct === 'periodico' || $tipo_ct === 'rotativo'): ?>
                            <div class="cinfo-item">
                                <div class="cinfo-lbl">Frecuencia</div>
                                <div class="cinfo-val">
                                    <?= $frecuencia == 1 ? 'Mensual' : "Cada {$frecuencia} meses" ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($contrato['notas'])): ?>
                        <div class="mt-3 small" style="white-space:pre-line;background:var(--surface-2);border-radius:8px;padding:.6rem .8rem"><strong>Notas:</strong> <?= htmlspecialchars($contrato['notas']) ?></div>
                    <?php endif; ?>
                    <!-- Barra progreso -->
                    <?php if ($plan): $pctPlan = $planRes['total'] > 0 ? min(100, round($planRes['cobrado'] / $planRes['total'] * 100)) : 0; ?>
                    <div class="mt-3">
                        <div class="d-flex justify-content-between mb-1">
                            <small class="text-muted fw-semibold">Cobrado del plan de pagos</small>
                            <small class="fw-bold text-success">L <?= number_format($planRes['cobrado'], 2) ?> de L <?= number_format($planRes['total'], 2) ?> (<?= $pctPlan ?>%)</small>
                        </div>
                        <div class="prog-wrap"><div class="prog-fill prog-green" style="width:<?= $pctPlan ?>%"></div></div>
                    </div>
                    <?php elseif ($esProyecto): ?>
                    <div class="mt-3">
                        <div class="d-flex justify-content-between mb-1">
                            <small class="text-muted fw-semibold">Pagos recibidos</small>
                            <small class="fw-bold text-success">L <?= number_format($recibidoTotal, 2) ?> de L <?= number_format($valorConIsv, 2) ?> (<?= $pctRecibido ?>%)</small>
                        </div>
                        <div class="prog-wrap"><div class="prog-fill prog-green" style="width:<?= $pctRecibido ?>%"></div></div>
                    </div>
                    <?php else: ?>
                    <div class="mt-3">
                        <div class="d-flex justify-content-between mb-1">
                            <small class="text-muted fw-semibold">Cumplimiento de cobro</small>
                            <small
                                class="fw-bold <?= $pctCumplimiento >= 80 ? 'text-success' : ($pctCumplimiento >= 50 ? 'text-warning' : 'text-danger') ?>">
                                <?= $mesesOk ?>/<?= $totalMeses ?> meses (<?= $pctCumplimiento ?>%)
                            </small>
                        </div>
                        <div class="prog-wrap">
                            <div class="prog-fill <?= $pctCumplimiento >= 80 ? 'prog-green' : ($pctCumplimiento >= 50 ? 'prog-amber' : 'prog-red') ?>"
                                style="width:<?= $pctCumplimiento ?>%"></div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Plan de pagos acordado -->
    <?php if ($hayPlan): ?>
        <?php
        $MESC = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
        $fCorta = fn($f) => (int)substr($f, 8, 2) . ' ' . $MESC[(int)substr($f, 5, 2)] . ' ' . substr($f, 0, 4);
        // Semáforo: verde pagado, azul facturado por cobrar, rojo vencido, naranja ≤ 3 días, amarillo ≤ 10 días, gris más adelante
        $semaforo = function (array $l) use ($fCorta) {
            if ($l['estado'] === 'pagado') return ['#dcfce7', '#166534', 'bi-check-circle-fill', 'Pagado' . ($l['fecha_pago'] ? ' ' . $fCorta($l['fecha_pago']) : '')];
            if ($l['estado'] === 'facturado') return ['#dbeafe', '#1e40af', 'bi-receipt', 'Facturado, por cobrar'];
            $d = (int)$l['dias'];
            if ($d > 0) return ['#fee2e2', '#991b1b', 'bi-exclamation-octagon-fill', 'Vencido hace ' . $d . ' día' . ($d > 1 ? 's' : '')];
            if ($d === 0) return ['#ffedd5', '#9a3412', 'bi-alarm-fill', 'Vence hoy'];
            if ($d >= -3) return ['#ffedd5', '#9a3412', 'bi-alarm', 'Vence en ' . -$d . ' día' . ($d < -1 ? 's' : '')];
            if ($d >= -10) return ['#fef9c3', '#854d0e', 'bi-hourglass-split', 'En ' . -$d . ' días'];
            return ['#f1f5f9', '#475569', 'bi-calendar3', 'En ' . -$d . ' días'];
        };
        ?>
        <div class="fc-card" id="planPagos">
            <div class="fc-card-hdr">
                <span class="fc-card-title"><i class="bi bi-calendar2-check text-success"></i> Plan de pagos
                    <?php if ($plan): ?><small class="text-muted fw-normal"><?= $planRes['n_pagadas'] ?> de <?= $planRes['n'] ?> pagados</small><?php endif; ?></span>
                <?php if ($puedeCorreo && $planPendIds): ?>
                    <a class="btn btn-sm btn-outline-secondary me-1" href="nuevo_cobro?receptor_id=<?= (int)$contrato['receptor_id'] ?>&tipo=recordatorio_pago&plan=<?= implode(',', $planPendIds) ?>" title="Enviar o programar por correo un recordatorio de los pagos pendientes"><i class="bi bi-send me-1"></i>Enviar recordatorio</a>
                <?php endif; ?>
                <?php if ($puedePlan): ?>
                    <button class="btn btn-sm btn-outline-primary" id="btnEditarPlan"><i class="bi bi-<?= $plan ? 'pencil' : 'plus-lg' ?> me-1"></i><?= $plan ? 'Editar plan' : 'Crear plan de pagos' ?></button>
                <?php endif; ?>
            </div>
            <?php if (!$plan): ?>
                <div class="fc-card-body text-muted small">
                    Aún no hay plan. Créalo para tener el calendario completo del contrato: anticipo, cuotas mensuales o anuales, o etapas, con el total y lo que falta por cobrar.
                </div>
            <?php else: ?>
                <div class="px-3 pt-3 d-flex flex-wrap gap-2 small">
                    <span class="badge rounded-pill" style="background:#f1f5f9;color:#0f172a">Total acordado <strong>L <?= number_format($planRes['total'], 2) ?></strong><?= $planRes['isv'] > 0 ? ' (ISV L ' . number_format($planRes['isv'], 2) . ')' : ' · sin ISV' ?></span>
                    <span class="badge rounded-pill" style="background:#dcfce7;color:#166534">Cobrado L <?= number_format($planRes['cobrado'], 2) ?></span>
                    <?php if ($planRes['facturado'] > 0): ?><span class="badge rounded-pill" style="background:#dbeafe;color:#1e40af">Facturado por cobrar L <?= number_format($planRes['facturado'], 2) ?></span><?php endif; ?>
                    <?php if ($planRes['vencido'] > 0): ?><span class="badge rounded-pill" style="background:#fee2e2;color:#991b1b">Vencido L <?= number_format($planRes['vencido'], 2) ?></span><?php endif; ?>
                    <span class="badge rounded-pill" style="background:#fef9c3;color:#854d0e">Por cobrar L <?= number_format($planRes['pendiente'] - $planRes['vencido'], 2) ?></span>
                    <?php if ($isvApartar > 0): ?><span class="badge rounded-pill" style="background:#ede9fe;color:#5b21b6" title="ISV incluido en pagos anticipados sin factura: se declara al emitir la factura">ISV por apartar L <?= number_format($isvApartar, 2) ?></span><?php endif; ?>
                </div>
                <div class="table-responsive">
                    <table class="fc-table">
                        <thead><tr><th>#</th><th>Fecha de pago</th><th>Concepto</th><th class="text-end">Monto</th><?php if ($planRes['isv'] > 0): ?><th class="text-end">ISV</th><?php endif; ?><th class="text-end">Total</th><th>Estado</th><th class="text-end"></th></tr></thead>
                        <tbody>
                            <?php foreach ($plan as $i => $l): [$bg, $fg, $ico, $txt] = $semaforo($l); ?>
                                <tr>
                                    <td class="text-muted"><?= $i + 1 ?></td>
                                    <td class="text-nowrap fw-semibold"><?= $fCorta($l['fecha']) ?></td>
                                    <td><?= htmlspecialchars($l['concepto']) ?><?= $l['tipo'] !== 'cuota' && stripos($l['concepto'], PLAN_TIPOS[$l['tipo']]) !== 0 ? ' <span class="badge" style="background:#f1f5f9;color:#475569">' . PLAN_TIPOS[$l['tipo']] . '</span>' : '' ?>
                                        <?php if ($l['cobro']): ?><div class="small text-muted"><?= htmlspecialchars($l['cobro']) ?></div><?php endif; ?></td>
                                    <td class="text-end text-nowrap">L <?= number_format((float)$l['monto'], 2) ?></td>
                                    <?php if ($planRes['isv'] > 0): ?><td class="text-end text-nowrap text-muted">L <?= number_format((float)$l['isv'], 2) ?></td><?php endif; ?>
                                    <td class="text-end text-nowrap fw-bold">L <?= number_format((float)$l['total'], 2) ?></td>
                                    <td class="text-nowrap"><span class="badge" style="background:<?= $bg ?>;color:<?= $fg ?>"><i class="bi <?= $ico ?> me-1"></i><?= $txt ?></span>
                                        <?php if (($rc = $recordados[(int)$l['id']] ?? null) && $l['estado'] !== 'pagado'): ?>
                                            <div class="small text-muted mt-1"><i class="bi bi-envelope<?= $rc['estado'] === 'enviado' ? '-check' : '' ?> me-1"></i><?= $rc['estado'] === 'enviado' ? 'Recordado ' . date('d/m', strtotime($rc['enviado_en'] ?: $rc['programado_para'])) : 'Recordatorio programado ' . date('d/m', strtotime($rc['programado_para'])) ?></div>
                                        <?php endif; ?></td>
                                    <td class="text-end text-nowrap">
                                        <?php if ($puedePlan && !$l['vinculado']): ?>
                                            <div class="btn-group">
                                                <button class="btn btn-sm btn-outline-primary btn-plan-cobrar" data-id="<?= (int)$l['id'] ?>" data-concepto="<?= htmlspecialchars($l['concepto']) ?>" data-total="<?= (float)$l['total'] ?>">
                                                    <i class="bi bi-cash-coin me-1"></i>Registrar cobro</button>
                                                <button class="btn btn-sm btn-outline-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-label="Más opciones"></button>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <?php if (!$esRecibo): ?><li><a class="dropdown-item" href="generar_factura?receptor_id=<?= (int)$contrato['receptor_id'] ?>&producto_id=<?= (int)$contrato['producto_id'] ?>&monto=<?= (float)$l['monto'] ?>&contrato_id=<?= (int)$contrato['id'] ?>&plan_linea=<?= (int)$l['id'] ?>"><i class="bi bi-file-earmark-plus me-2"></i>Emitir su factura (queda ligada)</a></li><?php endif; ?>
                                                    <li><button class="dropdown-item btn-plan-vincular" data-id="<?= (int)$l['id'] ?>" data-concepto="<?= htmlspecialchars($l['concepto']) ?>"><i class="bi bi-link-45deg me-2"></i>Vincular <?= $esRecibo ? 'un recibo' : 'una factura o pago' ?> ya registrado</button></li>
                                                </ul>
                                            </div>
                                        <?php elseif ($puedePlan && $l['vinculado']): ?>
                                            <button class="btn btn-sm btn-link text-muted p-0 btn-plan-desvincular" data-id="<?= (int)$l['id'] ?>" title="Quitar el vínculo con el cobro"><i class="bi bi-link-45deg"></i> Desvincular</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot><tr><td colspan="3" class="text-end text-muted">Total del contrato</td><td class="text-end">L <?= number_format($planRes['monto'], 2) ?></td><?php if ($planRes['isv'] > 0): ?><td class="text-end">L <?= number_format($planRes['isv'], 2) ?></td><?php endif; ?><td class="text-end fw-bold">L <?= number_format($planRes['total'], 2) ?></td><td colspan="2"></td></tr></tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Alerta meses sin factura -->
    <?php if (!empty($mesesSinFactura) && !$noIniciado && !$plan): ?>
        <div class="alert d-flex align-items-start gap-3 mb-4"
            style="background:#fff7ed;border:1px solid #fed7aa;color:#7c2d12;border-radius:12px;padding:1rem 1.25rem">
            <i class="bi bi-clock-history" style="font-size:1.2rem;flex-shrink:0;color:#ea580c;margin-top:1px"></i>
            <div>
                <strong><?= $mesesPend ?> mes(es) sin <?= $esRecibo ? 'recibo' : 'factura' ?>:</strong>
                <div class="mt-2 d-flex flex-wrap gap-1">
                    <?php foreach ($mesesSinFactura as $ms):
                        [$a, $m] = explode('-', $ms);
                    ?>
                        <span class="mes-chip pend">
                            <i class="bi bi-x-circle-fill" style="font-size:9px"></i>
                            <?= $meses_es[(int)$m] ?> <?= $a ?>
                        </span>
                    <?php endforeach; ?>
                </div>
                <?php if ($contrato['estado'] === 'activo'): ?>
                    <div class="mt-2">
                        <a href="contratos" class="btn btn-sm btn-outline-warning" style="font-size:.78rem">
                            <i class="bi bi-clock-history me-1"></i>Regularizar desde contratos
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($noIniciado): ?>
        <div class="alert"
            style="background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;border-radius:12px;padding:1rem 1.25rem">
            <i class="bi bi-clock me-2"></i>
            Este contrato aún no ha iniciado. Comienza el
            <strong><?= date('d/m/Y', strtotime($contrato['fecha_inicio'])) ?></strong>.
        </div>
    <?php endif; ?>

    <!-- Pagos anticipados (recibidos antes de emitir la factura) -->
    <?php if ($hayAnticipos && !$esRecibo && ($esProyecto || $anticipos)): ?>
        <div class="fc-card">
            <div class="fc-card-hdr">
                <span class="fc-card-title"><i class="bi bi-piggy-bank text-success"></i> Pagos anticipados <small class="text-muted fw-normal">(recibidos sin factura)</small></span>
                <div class="d-flex gap-2 align-items-center">
                    <span class="badge" style="background:#d1fae5;color:#065f46">L <?= number_format($antRecibido, 2) ?></span>
                    <?php if ($puedeCobrar): ?>
                        <button class="btn btn-sm btn-primary" id="btnNuevoAnticipo"><i class="bi bi-plus-lg me-1"></i>Registrar pago</button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="table-responsive">
                <table class="fc-table">
                    <thead><tr><th>Fecha</th><th>Concepto</th><th>Método</th><th>Referencia</th><th class="text-end">Monto</th><th class="text-center">Factura</th><th></th></tr></thead>
                    <tbody>
                        <?php if (!$anticipos): ?><tr><td colspan="7" class="text-center text-muted py-3">Aún no hay pagos anticipados.</td></tr><?php endif; ?>
                        <?php foreach ($anticipos as $a): ?>
                            <tr class="<?= (int)$a['anulado'] ? 'text-muted text-decoration-line-through' : '' ?>">
                                <td class="text-nowrap"><?= date('d/m/Y', strtotime($a['fecha'])) ?></td>
                                <td><?= htmlspecialchars($a['concepto'] ?? '') ?: '—' ?><?= (int)$a['anulado'] ? '<div class="small">Anulado: ' . htmlspecialchars($a['motivo_anulacion'] ?? '') . '</div>' : '' ?></td>
                                <td class="small"><?= htmlspecialchars(ucfirst($a['metodo'])) ?><?= $a['banco'] ? '<br><span class="text-muted">' . htmlspecialchars($a['banco'] . ' ' . $a['cuenta_numero']) . '</span>' : '' ?></td>
                                <td class="small"><?= htmlspecialchars($a['referencia'] ?? '') ?: '—' ?></td>
                                <td class="text-end fw-semibold text-nowrap">L <?= number_format((float)$a['monto'], 2) ?></td>
                                <td class="text-center small"><?= $a['factura_id'] ? '<a href="ver_factura?id=' . (int)$a['factura_id'] . '" target="_blank" class="font-monospace">' . htmlspecialchars($a['correlativo']) . '</a>' : ((int)$a['anulado'] ? '' : '<span class="badge" style="background:#fef3c7;color:#92400e">Sin factura</span>') ?></td>
                                <td class="text-end text-nowrap"><?php if (!(int)$a['anulado']): ?><a href="recibo_pdf?anticipo=<?= (int)$a['id'] ?>" target="_blank" class="btn btn-link btn-sm p-0 me-2 text-secondary" title="Recibo de anticipo en PDF"><i class="bi bi-file-earmark-pdf"></i></a><?php if ($puedeCorreo): ?><a href="nuevo_cobro?receptor_id=<?= (int)$contrato['receptor_id'] ?>&tipo=envio_recibo&anticipos=<?= (int)$a['id'] ?>" class="btn btn-link btn-sm p-0 me-2 text-secondary" title="Enviar el recibo por correo (ahora o programado)"><i class="bi bi-send"></i></a><?php endif; ?><?php endif; ?><?php if (!(int)$a['anulado'] && !$a['factura_id'] && in_array(USUARIO_ROL, ['admin', 'superadmin'], true)): ?><button class="btn btn-link btn-sm p-0 text-danger btn-anular-anticipo" data-id="<?= (int)$a['id'] ?>" title="Anular"><i class="bi bi-x-circle"></i></button><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($antSinAplicar > 0): ?>
                <div class="px-3 py-2 small d-flex flex-wrap align-items-center gap-2" style="background:#fffbeb;border-top:1px solid #fde68a">
                    <i class="bi bi-info-circle text-warning"></i>
                    <span>L <?= number_format($antSinAplicar, 2) ?> recibidos aún sin factura<?= $isvApartar > 0 ? ' — <strong>aparta L ' . number_format($isvApartar, 2) . ' de ISV</strong> (se declara al emitir la factura)' : '' ?>. Cuando emitas la factura de este contrato, aplícalos como abonos:</span>
                    <?php if ($facturasConSaldo && $puedeCobrar): ?>
                        <select id="antFactura" class="form-select form-select-sm" style="width:auto">
                            <?php foreach ($facturasConSaldo as $fs): ?><option value="<?= (int)$fs['id'] ?>"><?= htmlspecialchars($fs['correlativo']) ?> · saldo L <?= number_format($fs['saldo'], 2) ?></option><?php endforeach; ?>
                        </select>
                        <button class="btn btn-sm btn-success" id="btnAplicarAnticipos"><i class="bi bi-check2-all me-1"></i>Aplicar a la factura</button>
                    <?php else: ?>
                        <em class="text-muted">(todavía no hay factura emitida en este contrato)</em>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Historial de recibos (contrato sin factura) -->
    <?php if ($esRecibo): ?>
    <div class="fc-card">
        <div class="fc-card-hdr">
            <span class="fc-card-title"><i class="bi bi-receipt-cutoff text-success"></i> Recibos emitidos</span>
            <span style="background:var(--brand-lt);color:var(--brand);border-radius:20px;padding:.15rem .65rem;font-size:.78rem;font-weight:700">
                <?= count($recibos) ?> recibo<?= count($recibos) !== 1 ? 's' : '' ?></span>
        </div>
        <?php if (!$recibos): ?>
            <div class="fc-card-body text-center py-4 text-muted">
                Aún no hay recibos de este contrato.
                <?php if ($contrato['estado'] === 'activo'): ?><div class="mt-2"><a href="generar_recibo?contrato_id=<?= (int)$contrato['id'] ?>" class="btn-facturar"><i class="bi bi-receipt-cutoff"></i> Emitir el primer recibo</a></div><?php endif; ?>
            </div>
        <?php else: ?>
            <div style="overflow-x:auto">
                <table class="fc-table">
                    <thead><tr><th>Recibo</th><th>Fecha</th><th class="text-center">Período</th><th>Concepto</th><th>Método</th><th class="text-end">Monto</th><th class="text-center">PDF</th></tr></thead>
                    <tbody>
                        <?php foreach ($recibos as $r): ?>
                            <tr>
                                <td class="fw-bold font-monospace"><?= str_pad((string)$r['numero_recibo'], 5, '0', STR_PAD_LEFT) ?></td>
                                <td><?= date('d/m/Y', strtotime($r['fecha_emision'])) ?></td>
                                <td class="text-center"><?= $r['periodo_mes'] ? '<span class="badge" style="background:#dbeafe;color:#1e40af">' . $meses_es[(int)$r['periodo_mes']] . ' ' . (int)$r['periodo_anio'] . '</span>' : '—' ?></td>
                                <td class="small"><?= htmlspecialchars($r['concepto'] ?? '') ?></td>
                                <td class="small"><?= htmlspecialchars(ucfirst($r['metodo_pago'])) ?></td>
                                <td class="text-end fw-bold" style="color:var(--brand)">L <?= number_format((float)$r['monto'], 2) ?></td>
                                <td class="text-center text-nowrap"><a href="recibo_pdf?id=<?= (int)$r['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Ver el recibo en PDF"><i class="bi bi-file-earmark-pdf"></i></a>
                                    <?php if ($puedeCorreo): ?><a href="nuevo_cobro?receptor_id=<?= (int)$contrato['receptor_id'] ?>&tipo=envio_recibo&recibos=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Enviar este recibo por correo"><i class="bi bi-send"></i></a><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr><td colspan="5" class="text-end text-muted">Total cobrado con recibo (sin ISV):</td><td class="text-end" style="color:var(--brand)">L <?= number_format($totalRecibos, 2) ?></td><td></td></tr></tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Historial de facturas -->
    <?php if (!$esRecibo || $facturas): ?>
    <div class="fc-card">
        <div class="fc-card-hdr">
            <span class="fc-card-title"><i class="bi bi-receipt text-success"></i> Historial de Facturas</span>
            <span
                style="background:var(--brand-lt);color:var(--brand);border-radius:20px;padding:.15rem .65rem;font-size:.78rem;font-weight:700">
                <?= count($facturas) ?> factura<?= count($facturas) !== 1 ? 's' : '' ?>
            </span>
        </div>
        <?php if (empty($facturas)): ?>
            <div class="fc-card-body text-center py-5">
                <i class="bi bi-file-earmark-x" style="font-size:3rem;opacity:.2;display:block;margin-bottom:.75rem"></i>
                <div class="fw-bold text-muted mb-3">No hay facturas emitidas para este contrato</div>
                <?php if (!$noIniciado && $contrato['estado'] === 'activo'): ?>
                    <a href="generar_factura?receptor_id=<?= $contrato['receptor_id'] ?>&producto_id=<?= $contrato['producto_id'] ?>&monto=<?= $contrato['monto'] ?>&contrato_id=<?= $contrato['id'] ?>"
                        class="btn-facturar">
                        <i class="bi bi-file-earmark-plus"></i> Crear Primera Factura
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div style="overflow-x:auto">
                <?php if ($variasEmpresas): ?>
                    <div class="d-flex flex-wrap gap-2 mb-2 small">
                        <span class="text-muted">Facturado por empresa:</span>
                        <?php foreach ($porEmpresa as $emp => $pe): ?><span class="badge border" style="background:#f8fafc;color:#334155;font-weight:500"><?= htmlspecialchars($emp) ?> · <?= $pe['n'] ?> factura(s) · L <?= number_format($pe['total'], 2) ?></span><?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <table class="fc-table">
                    <thead>
                        <tr>
                            <th>Correlativo</th>
                            <th>Fecha</th>
                            <th class="text-center">Período</th>
                            <th class="text-end">Subtotal</th>
                            <th class="text-end">ISV</th>
                            <th class="text-end">Total</th>
                            <th class="text-center">Declarada</th>
                            <th class="text-center">Cobro</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($facturas as $f):
                            $isv       = (float)$f['isv_15'] + (float)$f['isv_18'];
                            $esMes     = (substr($f['fecha_emision'], 0, 7) === date('Y-m'));
                            $esAnulada = ($f['estado'] ?? 'emitida') === 'anulada';
                        ?>
                            <tr class="<?= $esMes ? 'tr-actual' : ($esAnulada ? 'tr-anulada' : '') ?>">
                                <td>
                                    <span class="fw-bold"
                                        style="font-family:monospace;font-size:.83rem;white-space:nowrap"><?= htmlspecialchars($f['correlativo']) ?></span>
                                    <?php if ($esMes): ?><br><span class="badge"
                                            style="background:#d1fae5;color:#065f46;font-size:.65rem">Este mes</span><?php endif; ?>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?= date('d/m/Y', strtotime($f['fecha_emision'])) ?></div>
                                    <?php if ($variasEmpresas): ?><div class="small text-muted" style="max-width:220px"><?= htmlspecialchars((string)$f['empresa_factura']) ?></div><?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php
                                    $pm_d = (int)($f['periodo_mes_ef'] ?? 0);
                                    $pa_d = (int)($f['periodo_anio_ef'] ?? 0);
                                    $per_lbl = ($pm_d && $pa_d) ? ($meses_es[$pm_d] . ' ' . $pa_d) : '—';
                                    $per_bg  = ($pm_d && $pa_d) ? 'background:#dbeafe;color:#1e40af' : 'background:#fee2e2;color:#991b1b';
                                    ?>
                                    <span class="badge btn-editar-periodo"
                                        style="<?= $per_bg ?>;cursor:pointer;font-size:.68rem" data-factura-id="<?= $f['id'] ?>"
                                        data-contrato-id="<?= $contrato_id ?>" data-periodo-mes="<?= $pm_d ?>"
                                        data-periodo-anio="<?= $pa_d ?>" title="Clic para cambiar período de cobertura">
                                        <?= $per_lbl ?> <i class="bi bi-pencil-fill" style="font-size:8px"></i>
                                    </span>
                                </td>
                                <td class="text-end text-muted">L <?= number_format((float)$f['subtotal'], 2) ?></td>
                                <td class="text-end text-muted">L <?= number_format($isv, 2) ?></td>
                                <td class="text-end fw-bold" style="color:var(--brand)">L
                                    <?= number_format((float)$f['total'], 2) ?></td>
                                <td class="text-center">
                                    <?php if ((int)($f['estado_declarada'] ?? 0)): ?>
                                        <span class="badge" style="background:#d1fae5;color:#065f46"><i
                                                class="bi bi-check-lg me-1"></i>Declarada</span>
                                    <?php else: ?>
                                        <span class="badge"
                                            style="background:var(--surface-2);color:var(--muted);border:1px solid var(--border)">Pendiente</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($f['saldo'] <= 0.004): ?>
                                        <span class="badge" style="background:#d1fae5;color:#065f46"><i
                                                class="bi bi-check-lg me-1"></i>Pagada</span>
                                    <?php elseif ($f['abonado'] > 0): ?>
                                        <span class="badge" style="background:#dbeafe;color:#1e40af">Abonada</span>
                                        <div class="small text-nowrap mt-1" style="font-size:.7rem">Abonado L <?= number_format($f['abonado'], 2) ?><br><strong class="text-danger">Saldo L <?= number_format($f['saldo'], 2) ?></strong></div>
                                    <?php else: ?>
                                        <span class="badge"
                                            style="background:#fef3c7;color:#92400e;border:1px solid #fde68a">Pendiente</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div
                                        style="display:flex;align-items:center;justify-content:center;gap:.35rem;flex-wrap:nowrap">
                                        <a href="ver_factura?id=<?= $f['id'] ?>" class="btn btn-sm btn-outline-success"
                                            target="_blank" title="Ver factura">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <?php if ($hayAbonos): ?>
                                            <button class="btn btn-sm btn-outline-primary btn-abonos" data-id="<?= (int)$f['id'] ?>"
                                                title="<?= $f['saldo'] > 0 ? 'Registrar abono / ver abonos' : 'Ver abonos' ?>">
                                                <i class="bi bi-cash-coin"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($puedeCorreo): ?>
                                            <a href="nuevo_cobro?receptor_id=<?= (int)$contrato['receptor_id'] ?>&facturas=<?= (int)$f['id'] ?>&tipo=<?= $f['saldo'] > 0 ? 'saldo_pendiente' : 'envio_factura' ?>"
                                                class="btn btn-sm btn-outline-secondary" title="Enviar esta factura por correo"><i class="bi bi-send"></i></a>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-outline-danger btn-desvincular"
                                            data-factura-id="<?= $f['id'] ?>"
                                            data-correlativo="<?= htmlspecialchars($f['correlativo']) ?>"
                                            data-contrato-id="<?= $contrato_id ?>" title="Desvincular de este contrato">
                                            <i class="bi bi-link-45deg"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="text-end text-muted">Totales del contrato:</td>
                            <td class="text-end">L <?= number_format($totalSubtotal, 2) ?></td>
                            <td class="text-end">L <?= number_format($totalIsv, 2) ?></td>
                            <td class="text-end" style="color:var(--brand)">L <?= number_format($totalFacturado, 2) ?></td>
                            <td></td>
                            <td class="text-center small text-nowrap">Saldo<br><strong class="<?= $totalSaldo > 0 ? 'text-danger' : 'text-success' ?>">L <?= number_format($totalSaldo, 2) ?></strong></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php endif; ?>

    <!-- Calendario de cobros (si hay plan de pagos, el plan lo reemplaza) -->
    <?php if (!empty($mesesEsperados) && !$plan): ?>
        <div class="fc-card">
            <div class="fc-card-hdr">
                <span class="fc-card-title"><i class="bi bi-calendar2-week text-secondary"></i> Calendario de Cobros</span>
                <div class="d-flex gap-2 flex-wrap">
                    <span class="mes-chip ok"><i class="bi bi-check-circle-fill" style="font-size:9px"></i>Cobrado</span>
                    <span class="mes-chip pend" style="opacity:.85"><i class="bi bi-x-circle-fill"
                            style="font-size:9px"></i>Sin cobrar <small>(clic para regularizar)</small></span>
                    <span class="mes-chip actual"><i class="bi bi-clock-fill" style="font-size:9px"></i>Mes actual</span>
                </div>
            </div>
            <div class="fc-card-body">
                <?php foreach ($mesesPorAnio as $anio => $meses): ?>
                    <div class="mb-4">
                        <div class="anio-lbl">
                            <i class="bi bi-calendar me-1"></i><?= $anio ?>
                            <span class="ms-2 fw-normal text-muted">
                                (<?= count(array_filter($meses, fn($m) => isset($mesesConFactura[$m]))) ?>/<?= count($meses) ?>
                                cobrados)
                            </span>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($meses as $mes):
                                [$a, $m] = explode('-', $mes);
                                $tieneF = isset($mesesConFactura[$mes]);
                                $esAct  = ($mes === date('Y-m'));
                                $cls    = $esAct ? 'actual' : ($tieneF ? 'ok' : 'pend');
                                $ico    = $esAct ? 'bi-clock-fill' : ($tieneF ? 'bi-check-circle-fill' : 'bi-x-circle-fill');
                                // Para rotativo: calcular qué cliente le toca este mes
                                $turnoLabel = '';
                                $turnoFull  = '';
                                if ($tipo_ct === 'rotativo' && !empty($turnosRotativos)) {
                                    $nTurnos    = count($turnosRotativos);
                                    $cicloTotal = $nTurnos * $frecuencia; // ej: 3 clientes × 2 meses = ciclo 6
                                    $idxMes     = ($anio - $anioIniCiclo) * 12 + ((int)$m - $mesIniCiclo);
                                    $posCiclo   = (($idxMes % $cicloTotal) + $cicloTotal) % $cicloTotal;
                                    $turnoIdx   = (int)floor($posCiclo / $frecuencia);
                                    $turnoFull  = $turnosRotativos[$turnoIdx]['receptor_nombre'];
                                    $partes     = explode(' ', $turnoFull);
                                    $turnoLabel = $partes[0];
                                }
                            ?>
                                <?php if ($cls === 'pend'): ?>
                                    <span class="mes-chip pend" style="cursor:pointer" data-mes="<?= (int)$m ?>"
                                        data-anio="<?= (int)$a ?>" data-label="<?= $meses_es[(int)$m] . ' ' . $a ?>"
                                        onclick="abrirRegCalendario(this)"
                                        title="Regularizar <?= $meses_es[(int)$m] . ' ' . $a ?><?= $turnoFull ? ' — ' . $turnoFull : '' ?>">
                                        <i class="bi bi-x-circle-fill" style="font-size:9px"></i>
                                        <?= $meses_es[(int)$m] ?>
                                        <?php if ($turnoLabel): ?><span style="opacity:.7;font-size:9px"> ·
                                                <?= htmlspecialchars($turnoLabel) ?></span><?php endif; ?>
                                        <i class="bi bi-plus-circle-fill" style="font-size:9px;opacity:.7"></i>
                                    </span>
                                <?php else: ?>
                                    <span class="mes-chip <?= $cls ?>">
                                        <i class="bi <?= $ico ?>" style="font-size:9px"></i>
                                        <?= $meses_es[(int)$m] ?>
                                        <?php if ($turnoLabel): ?><span style="opacity:.7;font-size:9px"> ·
                                                <?= htmlspecialchars($turnoLabel) ?></span><?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

</div>

<script>
    const CONTRATO_FC = {
        id: <?= $contrato_id ?>,
        receptor_id: <?= (int)$contrato['receptor_id'] ?>,
        producto_id: <?= (int)$contrato['producto_id'] ?>,
        monto: <?= (float)$contrato['monto'] ?>,
        nombre: <?= json_encode($contrato['nombre_contrato']) ?>,
        receptor: <?= json_encode($contrato['receptor_nombre']) ?>,
    };

    const mesesNombresES = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre',
        'Octubre', 'Noviembre', 'Diciembre'
    ];

    /* ── Editar período de factura vinculada ──────────────────────── */
    document.querySelectorAll('.btn-editar-periodo').forEach(btn => {
        btn.addEventListener('click', () => {
            const fid = btn.dataset.facturaId;
            const cid = btn.dataset.contratoId;
            const mesAct = parseInt(btn.dataset.periodoMes) || new Date().getMonth() + 1;
            const anioAct = parseInt(btn.dataset.periodoAnio) || new Date().getFullYear();

            let mesOpts = '';
            for (let m = 1; m <= 12; m++) {
                mesOpts += `<option value="${m}" ${m===mesAct?'selected':''}>${mesesNombresES[m]}</option>`;
            }
            let anioOpts = '';
            const anioHoy = new Date().getFullYear();
            for (let a = anioHoy - 3; a <= anioHoy + 1; a++) {
                anioOpts += `<option value="${a}" ${a===anioAct?'selected':''}>${a}</option>`;
            }

            Swal.fire({
                title: 'Cambiar período de la factura',
                html: `
                <div class="text-muted small mb-3">Este cambio solo afecta al calendario de cobros.<br>La fecha de emisión no se modifica.</div>
                <div class="d-flex gap-2 justify-content-center">
                    <select id="swalPM" class="form-select form-select-sm" style="flex:1;max-width:160px">${mesOpts}</select>
                    <select id="swalPA" class="form-select form-select-sm" style="width:100px">${anioOpts}</select>
                </div>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#0f766e',
                cancelButtonColor: '#6b7280',
                confirmButtonText: '<i class="bi bi-check-circle me-1"></i>Guardar período',
                cancelButtonText: 'Cancelar',
                preConfirm: () => ({
                    pm: document.getElementById('swalPM').value,
                    pa: document.getElementById('swalPA').value,
                }),
            }).then(r => {
                if (!r.isConfirmed) return;
                // Values captured in preConfirm; use stored refs
                const pmVal = r.value?.pm;
                const paVal = r.value?.pa;
                if (!pmVal || !paVal) return;

                const fd = new FormData();
                fd.append('factura_id', fid);
                fd.append('contrato_id', cid);
                fd.append('periodo_mes', pmVal);
                fd.append('periodo_anio', paVal);

                fetch('includes/contrato_actualizar_periodo.php', {
                        method: 'POST',
                        body: fd
                    })
                    .then(res => res.json())
                    .then(d => {
                        if (d.success) {
                            Swal.fire({
                                icon: 'success',
                                title: '¡Período actualizado!',
                                text: d.message,
                                timer: 1800,
                                showConfirmButton: false
                            }).then(() => location.reload());
                        } else {
                            Swal.fire('Error', d.error || 'No se pudo actualizar.', 'error');
                        }
                    }).catch(() => Swal.fire('Error', 'Error de conexión.', 'error'));
            });
        });
    });

    /* ── Desvincular factura ─────────────────────────────────────────── */
    document.querySelectorAll('.btn-desvincular').forEach(btn => {
        btn.addEventListener('click', () => {
            const fid = btn.dataset.facturaId;
            const cid = btn.dataset.contratoId;
            const corr = btn.dataset.correlativo;
            Swal.fire({
                title: '¿Desvincular factura?',
                html: `La factura <strong>${corr}</strong> quedará libre y podrá vincularse a otro contrato.<br><span class="text-muted small">No se modifica ningún dato de la factura.</span>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                confirmButtonText: '<i class="bi bi-link-45deg me-1"></i>Sí, desvincular',
                cancelButtonText: 'Cancelar',
                reverseButtons: true,
            }).then(r => {
                if (!r.isConfirmed) return;
                const fd = new FormData();
                fd.append('factura_id', fid);
                fd.append('contrato_id', cid);
                fetch('includes/contrato_desvincular_factura.php', {
                        method: 'POST',
                        body: fd
                    })
                    .then(r => r.json())
                    .then(d => {
                        if (d.success) {
                            Swal.fire({
                                    icon: 'success',
                                    title: '¡Desvinculada!',
                                    text: d.message,
                                    timer: 1800,
                                    showConfirmButton: false
                                })
                                .then(() => location.reload());
                        } else {
                            Swal.fire('Error', d.error || 'No se pudo desvincular.', 'error');
                        }
                    }).catch(() => Swal.fire('Error', 'Error de conexión.', 'error'));
            });
        });
    });
</script>

<!-- ══ MODAL: Regularizar desde Calendario ══════════════════════════ -->
<div class="modal fade" id="modalRegCal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow" style="border-radius:16px;overflow:hidden">
            <div class="modal-header" style="background:linear-gradient(135deg,#ea580c,#c2410c);color:#fff;border:none">
                <div>
                    <h5 class="modal-title fw-bold mb-0">
                        <i class="bi bi-calendar2-x me-2"></i>Regularizar Período: <span id="cal-periodo-lbl"></span>
                    </h5>
                    <small style="opacity:.85">Contrato: <?= htmlspecialchars($contrato['nombre_contrato']) ?>
                        &nbsp;·&nbsp; <?= htmlspecialchars($contrato['receptor_nombre']) ?></small>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="alert py-2 mb-3"
                    style="background:#fff7ed;border:1px solid #fed7aa;color:#92400e;font-size:.83rem">
                    <i class="bi bi-info-circle me-1"></i>
                    Puedes <strong>crear una nueva factura</strong> con la fecha del período, o
                    <strong>vincular una factura libre</strong> si el pago ya fue registrado sin contrato.
                </div>
                <div class="d-flex gap-3 flex-wrap mb-4">
                    <a id="cal-btn-crear" href="#" class="btn btn-success">
                        <i class="bi bi-file-earmark-plus me-1"></i>Crear factura para este mes
                    </a>
                    <button type="button" class="btn btn-outline-secondary" onclick="abrirVincularDesdeCalendario()">
                        <i class="bi bi-link-45deg me-1"></i>Vincular factura existente
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══ MODAL: Vincular desde Calendario ═════════════════════════════ -->
<div class="modal fade" id="modalVincCal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow" style="border-radius:16px;overflow:hidden">
            <div class="modal-header border-0 pb-0">
                <div>
                    <h5 class="modal-title fw-bold mb-0">
                        <i class="bi bi-link-45deg me-2 text-success"></i>Vincular Factura Libre
                    </h5>
                    <small class="text-muted" id="vinc-cal-sub"></small>
                </div>
                <button type="button" class="btn-close" id="btnVincCalBack"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-2">
                    <div class="d-flex gap-2 align-items-center p-2 rounded-3 mb-2"
                        style="background:#eff6ff;border:1px solid #bfdbfe;font-size:.82rem;color:#1e40af">
                        <i class="bi bi-calendar2-check-fill"></i>
                        <span>Esta factura cubrirá el período: <strong id="vincCalPeriodoLbl"></strong></span>
                        <a href="#" id="vincCalCambiarPeriodo" class="ms-auto text-info"
                            style="font-size:.78rem">Cambiar período</a>
                    </div>
                    <div id="vincCalCambiarPeriodoWrap" class="d-none mb-2">
                        <label class="form-label small fw-semibold mb-1">Asignar al período:</label>
                        <div class="d-flex gap-2">
                            <select id="vincCalPeriodoMes" class="form-select form-select-sm" style="flex:1">
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
                            <select id="vincCalPeriodoAnio" class="form-select form-select-sm" style="width:110px">
                                <?php for ($y = date('Y') - 3; $y <= date('Y'); $y++): ?>
                                    <option value="<?= $y ?>"><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Buscar factura por número o nombre del cliente</label>
                    <input type="text" id="vincCalBuscar" class="form-control form-control-sm"
                        placeholder="Ej: 001-001-01-00000001 o nombre…">
                </div>
                <div id="vincCalListado" style="max-height:320px;overflow-y:auto"></div>
                <!-- Panel expandido al seleccionar -->
                <div id="vincCalDetalle" class="d-none mt-3 p-3 rounded-3"
                    style="background:#f0fdf4;border:1px solid #a7f3d0">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="fw-bold text-success" id="vincCalDetCorr" style="font-family:monospace"></div>
                            <div class="text-muted small" id="vincCalDetFecha"></div>
                            <div class="mt-1" id="vincCalDetReceptor" style="font-size:.82rem"></div>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold" style="color:#0f766e;font-size:1.1rem" id="vincCalDetTotal"></div>
                            <small class="text-muted" id="vincCalDetSub"></small>
                            <div class="text-muted small" id="vincCalDetIsv"></div>
                        </div>
                    </div>
                    <div class="mt-2 pt-2 border-top" id="vincCalDetNotas" style="font-size:.78rem;color:#64748b"></div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnVincCalBack2">
                    <i class="bi bi-arrow-left me-1"></i>Volver
                </button>
                <button type="button" class="btn btn-success btn-sm" id="btnVincCalConfirmar" disabled>
                    <i class="bi bi-link-45deg me-1"></i>Vincular factura
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    /* ── Estado del calendario modal ──────────────────────────────────── */
    let calMesActual = null;
    let calFactSelId = null;
    let calTodasFacts = [];

    const mesesNombres = ['', 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

    function abrirRegCalendario(chip) {
        calMesActual = {
            mes: parseInt(chip.dataset.mes),
            anio: parseInt(chip.dataset.anio),
            label: chip.dataset.label,
        };
        document.getElementById('cal-periodo-lbl').textContent = calMesActual.label;
        document.getElementById('cal-btn-crear').href =
            `generar_factura?receptor_id=${CONTRATO_FC.receptor_id}` +
            `&producto_id=${CONTRATO_FC.producto_id}&monto=${CONTRATO_FC.monto}` +
            `&contrato_id=${CONTRATO_FC.id}` +
            `&periodo_mes=${calMesActual.mes}&periodo_anio=${calMesActual.anio}`;
        new bootstrap.Modal(document.getElementById('modalRegCal')).show();
    }

    function abrirVincularDesdeCalendario() {
        bootstrap.Modal.getInstance(document.getElementById('modalRegCal'))?.hide();
        document.getElementById('vinc-cal-sub').textContent =
            `Período: ${calMesActual.label} — ${CONTRATO_FC.receptor}`;
        document.getElementById('vincCalBuscar').value = '';
        document.getElementById('vincCalDetalle').classList.add('d-none');
        document.getElementById('btnVincCalConfirmar').disabled = true;
        document.getElementById('vincCalCambiarPeriodoWrap').classList.add('d-none');
        document.getElementById('vincCalPeriodoMes').value = calMesActual.mes;
        document.getElementById('vincCalPeriodoAnio').value = calMesActual.anio;
        document.getElementById('vincCalPeriodoLbl').textContent = calMesActual.label;
        calFactSelId = null;
        setTimeout(() => {
            new bootstrap.Modal(document.getElementById('modalVincCal')).show();
            cargarFacturasCalendario();
        }, 350);
    }

    function cargarFacturasCalendario() {
        const wrap = document.getElementById('vincCalListado');
        wrap.innerHTML =
            '<div class="text-center py-4 text-muted"><div class="spinner-border spinner-border-sm me-2"></div>Cargando…</div>';
        fetch(`includes/facturas_sin_contrato.php?receptor_id=${CONTRATO_FC.receptor_id}&contrato_id=${CONTRATO_FC.id}`)
            .then(r => r.json())
            .then(data => {
                calTodasFacts = data;
                renderFacturasCalendario(data);
            })
            .catch(() => {
                wrap.innerHTML = '<p class="text-danger text-center py-3">Error al cargar facturas.</p>';
            });
    }

    const fmtFecha = d => {
        if (!d) return '';
        const [y, m, dd] = d.split('-');
        return `${dd}/${m}/${y}`;
    };

    function renderFacturasCalendario(lista) {
        const wrap = document.getElementById('vincCalListado');
        if (!lista.length) {
            wrap.innerHTML =
                '<p class="text-muted text-center py-3">No hay facturas libres para este cliente.<br><small>Solo aparecen facturas sin contrato asignado.</small></p>';
            return;
        }
        wrap.innerHTML = '';
        lista.forEach(f => {
            const div = document.createElement('div');
            div.className = 'fact-search-item';
            div.dataset.id = f.id;
            const mesLbl = (mesesNombres[parseInt(f.mes)] || '') + ' ' + f.anio;
            div.innerHTML = `
      <div class="d-flex justify-content-between align-items-center">
        <div>
          <strong style="font-family:monospace;font-size:.82rem">${f.correlativo}</strong>
          <span class="badge ms-2" style="background:#dbeafe;color:#1e40af;font-size:.63rem">${mesLbl}</span>
          <small class="text-muted ms-1">${fmtFecha(f.fecha_emision)}</small>
        </div>
        <div class="text-end">
          <span class="fw-bold text-success">L ${parseFloat(f.total).toLocaleString('es-HN',{minimumFractionDigits:2})}</span>
        </div>
      </div>
      <div class="d-flex justify-content-between mt-1">
        <small class="text-muted">${f.receptor_nombre}</small>
        <small class="text-muted">Sub: L ${parseFloat(f.subtotal).toLocaleString('es-HN',{minimumFractionDigits:2})}</small>
      </div>`;
            div.addEventListener('click', () => {
                wrap.querySelectorAll('.fact-search-item').forEach(i => i.classList.remove('selected'));
                div.classList.add('selected');
                calFactSelId = f.id;
                mostrarDetalleFactura(f);
                document.getElementById('btnVincCalConfirmar').disabled = false;
            });
            wrap.appendChild(div);
        });
    }

    function mostrarDetalleFactura(f) {
        const isv = (parseFloat(f.isv_15 || 0) + parseFloat(f.isv_18 || 0)).toFixed(2);
        const mesLbl = (mesesNombres[parseInt(f.mes)] || '') + ' ' + f.anio;
        document.getElementById('vincCalDetCorr').textContent = f.correlativo;
        document.getElementById('vincCalDetFecha').textContent = `Emitida: ${fmtFecha(f.fecha_emision)} (${mesLbl})`;
        document.getElementById('vincCalDetReceptor').textContent = `Cliente: ${f.receptor_nombre}`;
        document.getElementById('vincCalDetTotal').textContent =
            `L ${parseFloat(f.total).toLocaleString('es-HN',{minimumFractionDigits:2})}`;
        document.getElementById('vincCalDetSub').textContent =
            `Subtotal: L ${parseFloat(f.subtotal).toLocaleString('es-HN',{minimumFractionDigits:2})}`;
        document.getElementById('vincCalDetIsv').textContent = isv > 0 ?
            `ISV: L ${parseFloat(isv).toLocaleString('es-HN',{minimumFractionDigits:2})}` : 'Sin ISV';
        const notasEl = document.getElementById('vincCalDetNotas');
        if (f.notas) {
            notasEl.textContent = 'Notas: ' + f.notas;
            notasEl.classList.remove('d-none');
        } else {
            notasEl.classList.add('d-none');
        }
        document.getElementById('vincCalDetalle').classList.remove('d-none');
    }

    document.getElementById('vincCalCambiarPeriodo').addEventListener('click', e => {
        e.preventDefault();
        const wrap = document.getElementById('vincCalCambiarPeriodoWrap');
        wrap.classList.toggle('d-none');
        if (!wrap.classList.contains('d-none')) {
            const syncLbl = () => {
                const m = document.getElementById('vincCalPeriodoMes').value;
                const a = document.getElementById('vincCalPeriodoAnio').value;
                document.getElementById('vincCalPeriodoLbl').textContent =
                    mesesNombres[parseInt(m)] + ' ' + a;
            };
            document.getElementById('vincCalPeriodoMes').addEventListener('change', syncLbl);
            document.getElementById('vincCalPeriodoAnio').addEventListener('change', syncLbl);
        }
    });

    document.getElementById('vincCalBuscar').addEventListener('input', function() {
        const q = this.value.toLowerCase();
        renderFacturasCalendario(q ?
            calTodasFacts.filter(f => f.correlativo.toLowerCase().includes(q) || f.receptor_nombre.toLowerCase()
                .includes(q)) :
            calTodasFacts);
    });

    ['btnVincCalBack', 'btnVincCalBack2'].forEach(id => {
        document.getElementById(id)?.addEventListener('click', () => {
            bootstrap.Modal.getInstance(document.getElementById('modalVincCal'))?.hide();
            setTimeout(() => {
                if (calMesActual) abrirRegCalendario({
                    dataset: calMesActual
                });
                else new bootstrap.Modal(document.getElementById('modalRegCal')).show();
            }, 350);
        });
    });

    document.getElementById('btnVincCalConfirmar').addEventListener('click', () => {
        if (!calFactSelId) return;
        const btn = document.getElementById('btnVincCalConfirmar');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Vinculando…';
        const fd = new FormData();
        fd.append('factura_id', calFactSelId);
        fd.append('contrato_id', CONTRATO_FC.id);
        fd.append('periodo_mes', document.getElementById('vincCalPeriodoMes').value);
        fd.append('periodo_anio', document.getElementById('vincCalPeriodoAnio').value);
        fetch('includes/contrato_vincular_factura.php', {
                method: 'POST',
                body: fd
            })
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    bootstrap.Modal.getInstance(document.getElementById('modalVincCal'))?.hide();
                    Swal.fire({
                        icon: 'success',
                        title: '¡Factura vinculada!',
                        html: `Vinculada al período <strong>${calMesActual.label}</strong>.<br><small class="text-muted">La fecha de emisión original no fue modificada.</small>`,
                        timer: 2500,
                        showConfirmButton: false
                    }).then(() => location.reload());
                } else {
                    Swal.fire('Error', d.error || 'No se pudo vincular.', 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-link-45deg me-1"></i>Vincular factura';
                }
            });
    });
</script>

<?php if ($hayAbonos) require __DIR__ . '/includes/_modal_abonos.php'; ?>
<?php if ($hayAnticipos && $puedeCobrar):
    $cuentasAnt = bancosDisponible($pdo) ? array_values(array_filter(bancoCuentas($pdo, $cliente_id, true), fn($c) => $c['moneda'] === 'HNL')) : []; ?>
<div class="modal fade" id="modalAnticipo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
        <form class="modal-content" id="formAnticipo" novalidate>
            <div class="modal-header"><h5 class="modal-title"><i class="bi bi-piggy-bank me-1"></i> Registrar pago anticipado</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
            <div class="modal-body row g-2">
                <input type="hidden" name="accion" value="registrar"><input type="hidden" name="contrato_id" value="<?= (int)$contrato_id ?>">
                <div class="col-12"><div class="alert alert-info small py-2 mb-1">Pago recibido <strong>antes</strong> de emitir la factura. Cuando la emitas, lo aplicas como abono.</div></div>
                <div class="col-12"><label class="form-label small">Concepto</label><input class="form-control form-control-sm" name="concepto" maxlength="255" placeholder="Ej.: Etapa 2 (30 %)"></div>
                <div class="col-6"><label class="form-label small">Fecha *</label><input class="form-control form-control-sm" type="date" name="fecha" value="<?= date('Y-m-d') ?>" required></div>
                <div class="col-6"><label class="form-label small">Monto recibido (L) *</label><input class="form-control form-control-sm" type="number" step="0.01" min="0.01" name="monto" required></div>
                <div class="col-6"><label class="form-label small">Método</label><select class="form-select form-select-sm" name="metodo"><option value="transferencia">Transferencia</option><option value="efectivo">Efectivo</option><option value="cheque">Cheque</option><option value="tarjeta">Tarjeta</option><option value="otro">Otro</option></select></div>
                <div class="col-6"><label class="form-label small">Referencia</label><input class="form-control form-control-sm" name="referencia" maxlength="100"></div>
                <?php if ($cuentasAnt): ?>
                    <div class="col-12"><label class="form-label small">Depositado en</label><select class="form-select form-select-sm" name="cuenta_id"><option value="">— No registrar en banco —</option>
                        <?php foreach ($cuentasAnt as $c): ?><option value="<?= (int)$c['id'] ?>"<?= bancoSel($c) ?>><?= htmlspecialchars($c['banco'] . ' ' . $c['numero']) ?></option><?php endforeach; ?></select></div>
                <?php endif; ?>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary btn-sm" type="submit">Registrar pago</button></div>
        </form>
    </div>
</div>
<script>
(function () {
    const URL_ANT = 'includes/anticipo_accion.php';
    // Después de registrar un pago anticipado: el cliente recibe un recibo (no factura), en PDF o por correo
    window.ofrecerRecibo = (id, msg) => Swal.fire({
        icon: 'success', title: msg, text: 'Se generó el recibo de anticipo para el cliente.',
        showConfirmButton: <?= $puedeCorreo ? 'true' : 'false' ?>, confirmButtonText: '<i class="bi bi-send me-1"></i>Enviar por correo',
        showDenyButton: true, denyButtonText: '<i class="bi bi-file-earmark-pdf me-1"></i>Ver recibo', denyButtonColor: '#475569',
        showCancelButton: true, cancelButtonText: 'Listo',
    }).then(r => {
        if (r.isConfirmed) location.href = 'nuevo_cobro?receptor_id=<?= (int)$contrato['receptor_id'] ?>&tipo=envio_recibo&anticipos=' + id;
        else { if (r.isDenied) window.open('recibo_pdf?anticipo=' + id, '_blank'); location.reload(); }
    });
    const enviar = fd => fetch(URL_ANT, { method: 'POST', body: fd }).then(r => r.json()).then(d => { if (!d.success) throw new Error(d.error || 'No se pudo guardar.'); return d; });
    const form = document.getElementById('formAnticipo');
    const modal = new bootstrap.Modal(document.getElementById('modalAnticipo'));
    document.getElementById('btnNuevoAnticipo')?.addEventListener('click', () => { form.reset(); modal.show(); });
    form.addEventListener('submit', e => {
        e.preventDefault();
        if (!form.checkValidity()) { form.classList.add('was-validated'); return; }
        const b = form.querySelector('[type=submit]'); b.disabled = true;
        enviar(new FormData(form)).then(d => { modal.hide(); ofrecerRecibo(d.id, d.message); })
            .catch(err => Swal.fire('Error', err.message, 'error')).finally(() => b.disabled = false);
    });
    document.querySelectorAll('.btn-anular-anticipo').forEach(b => b.addEventListener('click', () => {
        Swal.fire({ title: 'Anular pago anticipado', input: 'text', inputPlaceholder: 'Motivo (obligatorio)', showCancelButton: true, confirmButtonText: 'Anular', confirmButtonColor: '#dc2626', cancelButtonText: 'Cancelar', inputValidator: v => !v.trim() && 'Indica el motivo' })
            .then(r => { if (!r.isConfirmed) return; const fd = new FormData(); fd.append('accion', 'anular'); fd.append('id', b.dataset.id); fd.append('motivo', r.value);
                enviar(fd).then(() => location.reload()).catch(err => Swal.fire('Error', err.message, 'error')); });
    }));
    document.getElementById('btnAplicarAnticipos')?.addEventListener('click', () => {
        const sel = document.getElementById('antFactura');
        Swal.fire({ title: '¿Aplicar los pagos anticipados?', text: 'Se registrarán como abonos de la factura ' + sel.options[sel.selectedIndex].text.split(' · ')[0] + ', con sus fechas originales.', icon: 'question', showCancelButton: true, confirmButtonText: 'Aplicar', cancelButtonText: 'Cancelar' })
            .then(r => { if (!r.isConfirmed) return; const fd = new FormData(); fd.append('accion', 'aplicar'); fd.append('contrato_id', '<?= (int)$contrato_id ?>'); fd.append('factura_id', sel.value);
                enviar(fd).then(d => Swal.fire({ icon: 'success', title: d.message }).then(() => location.reload())).catch(err => Swal.fire('Error', err.message, 'error')); });
    });
})();
</script>
<?php endif; ?>
<?php if ($hayPlan && $puedePlan):
    $cuentasPlan = bancosDisponible($pdo) ? array_values(array_filter(bancoCuentas($pdo, $cliente_id, true), fn($c) => $c['moneda'] === 'HNL')) : []; ?>
<div class="modal fade" id="modalPlan" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-lg-down">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title"><i class="bi bi-calendar2-check me-1"></i> Plan de pagos · <?= htmlspecialchars($contrato['nombre_contrato']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
            <div class="modal-body"><div id="planEditor"><div class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm"></span> Cargando…</div></div></div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary btn-sm" id="btnGuardarPlan"><i class="bi bi-floppy me-1"></i>Guardar plan</button></div>
        </div>
    </div>
</div>
<script src="../../clientes/js/plan-pagos.js?v=<?= @filemtime(__DIR__ . '/../js/plan-pagos.js') ?>"></script>
<script>
(function () {
    const URL_PLAN = 'includes/contrato_plan_accion.php', CONTRATO = <?= (int)$contrato_id ?>, ES_RECIBO = <?= $esRecibo ? 'true' : 'false' ?>;
    const CUENTAS = <?= json_encode(array_map(fn($c) => ['id' => (int)$c['id'], 'txt' => $c['banco'] . ' ' . $c['numero'], 'pred' => !empty($c['predeterminada'])], $cuentasPlan), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const L = v => 'L ' + Number(v).toLocaleString('es-HN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const post = datos => { const fd = new FormData(); Object.entries(datos).forEach(([k, v]) => fd.append(k, v ?? '')); return fetch(URL_PLAN, { method: 'POST', body: fd }).then(r => r.json()).then(d => { if (!d.success) throw new Error(d.error || 'No se pudo guardar.'); return d; }); };
    const listo = d => Swal.fire({ icon: 'success', title: d.message, timer: 1800, showConfirmButton: false }).then(() => location.reload());
    const metodos = '<option value="transferencia">Transferencia</option><option value="efectivo">Efectivo</option><option value="cheque">Cheque</option><option value="tarjeta">Tarjeta</option><option value="otro">Otro</option>';
    let info = null;
    const cargar = () => fetch(URL_PLAN + '?contrato_id=' + CONTRATO).then(r => r.json()).then(d => { if (!d.success) throw new Error(d.error); return info = d; });

    // Crear / editar el plan
    const modal = new bootstrap.Modal(document.getElementById('modalPlan'));
    let editor = null;
    document.getElementById('btnEditarPlan')?.addEventListener('click', () => {
        modal.show();
        cargar().then(d => {
            editor = PlanPagos('#planEditor', { conFactura: d.con_factura, lineas: d.lineas, concepto: <?= json_encode($contrato['concepto_recibo'] ?: $contrato['nombre_contrato']) ?>,
                fecha: <?= json_encode(date('Y-m-') . str_pad((string)min(28, max(1, (int)$contrato['dia_pago'])), 2, '0', STR_PAD_LEFT)) ?>, cuota: <?= (float)$contrato['monto'] ?> });
        }).catch(e => { modal.hide(); Swal.fire('Error', e.message, 'error'); });
    });
    document.getElementById('btnGuardarPlan').addEventListener('click', () => {
        if (!editor) return;
        const b = document.getElementById('btnGuardarPlan'); b.disabled = true;
        post({ accion: 'guardar', contrato_id: CONTRATO, con_isv: editor.conIsv() ? '1' : '', lineas: JSON.stringify(editor.lineas()) })
            .then(d => { modal.hide(); listo(d); }).catch(e => Swal.fire('No se pudo guardar', e.message, 'error')).finally(() => b.disabled = false);
    });

    // Registrar el cobro de una línea: con recibo (sin factura) o como pago anticipado (con factura)
    document.querySelectorAll('.btn-plan-cobrar').forEach(b => b.addEventListener('click', () => {
        const hoy = new Date().toLocaleDateString('sv-SE');
        Swal.fire({
            title: ES_RECIBO ? 'Cobrar con recibo' : 'Registrar cobro',
            html: `<div class="text-start">
                <div class="mb-2"><strong>${esc(b.dataset.concepto)}</strong> · ${L(b.dataset.total)}</div>
                ${ES_RECIBO ? '<div class="alert alert-info small py-2">Se emite el recibo con este monto y la línea queda pagada.</div>'
                            : '<div class="alert alert-info small py-2">Se registra como <strong>pago anticipado</strong> (recibido antes de facturar). Cuando emitas la factura lo aplicas como abono. Si ya tienes la factura, usa «Vincular».</div>'}
                <label class="form-label small">Fecha de pago</label><input type="date" id="pcFecha" class="form-control form-control-sm" value="${hoy}">
                ${ES_RECIBO ? '' : `<label class="form-label small mt-2">Monto recibido</label><input type="number" step="0.01" id="pcMonto" class="form-control form-control-sm" value="${Number(b.dataset.total).toFixed(2)}">`}
                <label class="form-label small mt-2">Método</label><select id="pcMetodo" class="form-select form-select-sm">${metodos}</select>
                ${CUENTAS.length ? `<label class="form-label small mt-2">Depositado en</label><select id="pcCuenta" class="form-select form-select-sm"><option value="">— No registrar en banco —</option>${CUENTAS.map(c => `<option value="${c.id}"${c.pred ? ' selected' : ''}>${esc(c.txt)}</option>`).join('')}</select>` : ''}
                ${ES_RECIBO ? '<label class="form-label small mt-2">Notas</label><input id="pcNotas" class="form-control form-control-sm" maxlength="255">' : '<label class="form-label small mt-2">Referencia</label><input id="pcRef" class="form-control form-control-sm" maxlength="100">'}
            </div>`,
            showCancelButton: true, confirmButtonText: ES_RECIBO ? 'Emitir recibo' : 'Registrar cobro', cancelButtonText: 'Cancelar', focusConfirm: false,
            preConfirm: () => post({
                accion: ES_RECIBO ? 'cobrar_recibo' : 'cobrar_anticipo', id: b.dataset.id,
                fecha: document.getElementById('pcFecha').value, metodo: document.getElementById('pcMetodo').value,
                monto: document.getElementById('pcMonto')?.value, cuenta_id: document.getElementById('pcCuenta')?.value,
                referencia: document.getElementById('pcRef')?.value, notas: document.getElementById('pcNotas')?.value,
            }).catch(e => Swal.showValidationMessage(e.message)),
        }).then(r => { if (r.isConfirmed && r.value) (r.value.anticipo_id && window.ofrecerRecibo ? window.ofrecerRecibo(r.value.anticipo_id, r.value.message) : listo(r.value)); });
    }));

    // Vincular con un cobro ya registrado (factura del contrato, recibo o pago anticipado)
    document.querySelectorAll('.btn-plan-vincular').forEach(b => b.addEventListener('click', () => {
        cargar().then(d => {
            const ops = Object.entries(d.libres).flatMap(([tipo, lista]) => lista.map(x => `<option value="${tipo}:${x.id}">${esc(x.txt)}</option>`));
            if (!ops.length) return Swal.fire('Nada que vincular', ES_RECIBO ? 'No hay recibos de este contrato sin vincular.' : 'No hay facturas ni pagos anticipados de este contrato sin vincular.', 'info');
            Swal.fire({
                title: 'Vincular cobro', html: `<div class="text-start"><div class="mb-2">${esc(b.dataset.concepto)}</div><select id="pvRef" class="form-select form-select-sm">${ops.join('')}</select></div>`,
                showCancelButton: true, confirmButtonText: 'Vincular', cancelButtonText: 'Cancelar',
                preConfirm: () => { const [tipo, ref] = document.getElementById('pvRef').value.split(':'); return post({ accion: 'vincular', id: b.dataset.id, tipo, ref_id: ref }).catch(e => Swal.showValidationMessage(e.message)); },
            }).then(r => { if (r.isConfirmed && r.value) listo(r.value); });
        }).catch(e => Swal.fire('Error', e.message, 'error'));
    }));
    document.querySelectorAll('.btn-plan-desvincular').forEach(b => b.addEventListener('click', () => {
        Swal.fire({ title: '¿Quitar el vínculo?', text: 'La línea vuelve a quedar pendiente. El recibo, la factura o el pago anticipado no se borran.', icon: 'question', showCancelButton: true, confirmButtonText: 'Desvincular', cancelButtonText: 'Cancelar' })
            .then(r => { if (r.isConfirmed) post({ accion: 'desvincular', id: b.dataset.id }).then(listo).catch(e => Swal.fire('Error', e.message, 'error')); });
    }));
})();
</script>
<?php endif; ?>
<?php require_once '../../includes/templates/footer.php'; ?>