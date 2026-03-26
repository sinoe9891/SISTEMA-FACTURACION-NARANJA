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

$stmtC = $pdo->prepare("
    SELECT c.*, cg.nombre AS cat_nombre, cg.color AS cat_color, cg.icono AS cat_icono
    FROM colaboradores c
    LEFT JOIN categorias_gastos cg ON cg.id = c.categoria_gasto_id
    WHERE c.id = ? AND c.cliente_id = ?
");
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

$meses = [
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

// Pagos del colaborador
$sqlPagos = "SELECT g.*, cg.nombre AS cat_nombre, cg.color AS cat_color, cg.icono AS cat_icono
    FROM gastos g LEFT JOIN categorias_gastos cg ON cg.id=g.categoria_id
    WHERE g.cliente_id=? AND (g.descripcion LIKE ? OR g.descripcion LIKE ? OR g.descripcion LIKE ? OR g.descripcion LIKE ?)";
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
        $total_pend  += (float)$p['monto'];
        $count_pend++;
    }
}

$diasTotal = (int)((time() - strtotime($col['fecha_ingreso'])) / 86400);
$anios     = floor($diasTotal / 365);
$mesesAnt  = floor(($diasTotal % 365) / 30);

$stmtCats = $pdo->prepare("SELECT id,nombre,color,icono FROM categorias_gastos WHERE cliente_id=? AND activa=1 ORDER BY nombre");
$stmtCats->execute([$cliente_id]);
$categorias = $stmtCats->fetchAll(PDO::FETCH_ASSOC);

// Préstamos
$stmtPrest = $pdo->prepare("SELECT p.* FROM colaborador_prestamos p WHERE p.colaborador_id=? AND p.cliente_id=? ORDER BY p.fecha DESC, p.id DESC");
$stmtPrest->execute([$id, $cliente_id]);
$prestamos = $stmtPrest->fetchAll(PDO::FETCH_ASSOC);

$prestamo_ids = array_column(array_filter($prestamos, fn($p) => $p['estado'] === 'activo'), 'id');
$cuotas_por_prestamo = [];
if (!empty($prestamo_ids)) {
    $ph = implode(',', array_fill(0, count($prestamo_ids), '?'));
    $stmtCuotas = $pdo->prepare("SELECT * FROM colaborador_prestamo_cuotas WHERE prestamo_id IN ($ph) ORDER BY prestamo_id ASC, numero_cuota ASC");
    $stmtCuotas->execute($prestamo_ids);
    foreach ($stmtCuotas->fetchAll(PDO::FETCH_ASSOC) as $cuota) {
        $cuotas_por_prestamo[$cuota['prestamo_id']][] = $cuota;
    }
}
$total_deuda_activa = array_sum(array_column(array_filter($prestamos, fn($p) => $p['estado'] === 'activo'), 'saldo_pendiente'));

// Cuotas auto-descuento
$stmtCuotasAuto = $pdo->prepare("
    SELECT c.id AS cuota_id, c.monto AS cuota_monto, c.numero_cuota, c.fecha_esperada,
           p.id AS prestamo_id, p.descripcion AS prest_desc, p.tipo
    FROM colaborador_prestamo_cuotas c
    JOIN colaborador_prestamos p ON p.id=c.prestamo_id
    WHERE p.colaborador_id=? AND p.cliente_id=?
      AND p.estado='activo' AND p.descuento_auto=1 AND c.estado='pendiente'
      AND c.id=(SELECT c2.id FROM colaborador_prestamo_cuotas c2 WHERE c2.prestamo_id=p.id AND c2.estado='pendiente' ORDER BY c2.numero_cuota ASC LIMIT 1)
    ORDER BY p.id ASC
");
$stmtCuotasAuto->execute([$id, $cliente_id]);
$cuotas_auto_pendientes = $stmtCuotasAuto->fetchAll(PDO::FETCH_ASSOC);

$neto_quincena = round($neto_mes / $div, 2);
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

/* ── Tipos de movimiento — incluye ahora VIÁTICO ─────────────────────── */
$tipos_btn_p = [
    ['val' => 'prestamo', 'label' => 'Préstamo',          'icon' => 'fa-hand-holding-dollar', 'color' => 'danger',  'desc' => 'Con cuotas'],
    ['val' => 'adelanto', 'label' => 'Adelanto',          'icon' => 'fa-bolt',               'color' => 'warning', 'desc' => 'Descuento único'],
    ['val' => 'bono',    'label' => 'Bono/Gratificación', 'icon' => 'fa-gift',               'color' => 'success', 'desc' => 'Sin descuento'],
    ['val' => 'viatico', 'label' => 'Viático',           'icon' => 'fa-plane-departure',    'color' => 'info',    'desc' => 'Gasto de viaje'],
    ['val' => 'multa',   'label' => 'Multa/Descuento',  'icon' => 'fa-ban',                'color' => 'secondary', 'desc' => 'Descuento único'],
];
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>
    .avatar-xl {
        width: 80px;
        height: 80px;
        font-size: 28px;
        font-weight: 800;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #0d6efd 0%, #6610f2 100%);
        color: #fff;
        box-shadow: 0 4px 16px rgba(13, 110, 253, .35);
        flex-shrink: 0;
    }

    .stat-pill {
        background: #f8f9fa;
        border: 1px solid #e9ecef;
        border-radius: 10px;
        padding: 10px 16px;
        text-align: center;
    }

    .stat-pill .stat-val {
        font-size: 18px;
        font-weight: 700;
    }

    .stat-pill .stat-lbl {
        font-size: 11px;
        color: #888;
        text-transform: uppercase;
        letter-spacing: .5px;
    }

    .badge-q1 {
        background: #0d6efd;
        color: #fff;
    }

    .badge-q2 {
        background: #0dcaf0;
        color: #000;
    }

    .badge-mensual {
        background: #6f42c1;
        color: #fff;
    }

    .toolbar-colab {
        position: sticky;
        top: 0;
        z-index: 50;
        background: #fff;
        border-bottom: 1px solid #e9ecef;
        padding: 10px 0;
        margin-bottom: 20px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, .05);
    }

    .filter-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #e8f0fe;
        color: #0d6efd;
        border: 1px solid #bbd0fb;
        border-radius: 20px;
        padding: 3px 12px;
        font-size: 13px;
        font-weight: 600;
    }

    @media print {

        .toolbar-colab,
        .no-print,
        .btn {
            display: none !important;
        }

        .card {
            box-shadow: none !important;
            border: 1px solid #dee2e6 !important;
        }
    }

    .info-row {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        padding: 7px 0;
        border-bottom: 1px solid #f4f4f4;
        font-size: 13.5px;
    }

    .info-row:last-child {
        border-bottom: none;
    }

    .info-lbl {
        color: #888;
        font-size: 11.5px;
        text-transform: uppercase;
        letter-spacing: .4px;
    }

    .info-val {
        font-weight: 600;
        color: #212529;
        text-align: right;
    }
</style>

<!-- Toolbar sticky -->
<div class="toolbar-colab no-print">
    <div class="container-xxl d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <div class="d-flex align-items-center gap-2">
            <a href="colaboradores" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i>
                Volver</a>
            <span class="text-muted small"><i class="fa-solid fa-users me-1"></i> Colaboradores <i
                    class="fa-solid fa-chevron-right fa-xs mx-1 text-muted"></i><strong><?= htmlspecialchars($nombreCompleto) ?></strong></span>
        </div>
        <div class="d-flex gap-2">
            <a href="colaborador_reporte.php?id=<?= $id ?>&mes=<?= $filtro_mes ?>&anio=<?= $filtro_anio ?>"
                target="_blank" class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-file-pdf me-1"></i> Reporte
                PDF</a>
            <?php if ($col['activo']): ?><button class="btn btn-sm btn-success btn-pagar-directo"><i
                        class="fa-solid fa-hand-holding-dollar me-1"></i> Registrar Pago</button><?php endif; ?>
        </div>
    </div>
</div>

<div class="container-xxl">

    <!-- Perfil -->
    <div class="card border-0 shadow-sm mb-4 overflow-hidden">
        <div class="p-4" style="background:linear-gradient(135deg,#0d6efd 0%,#6610f2 100%)">
            <div class="d-flex flex-wrap align-items-center gap-3">
                <div class="avatar-xl">
                    <?= strtoupper(mb_substr($col['nombre'], 0, 1) . mb_substr($col['apellido'], 0, 1)) ?>
                </div>
                <div class="text-white flex-grow-1">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <h4 class="mb-0 fw-bold text-white"><?= htmlspecialchars($nombreCompleto) ?></h4>
                        <span class="badge <?= $col['activo'] ? 'bg-success' : 'bg-secondary' ?> px-2"><i
                                class="fa-solid fa-circle fa-xs me-1"></i><?= $col['activo'] ? 'Activo' : 'Inactivo' ?></span>
                        <?php if ($total_deuda_activa > 0): ?><span class="badge bg-danger px-2"
                                style="font-size:11px"><i class="fa-solid fa-hand-holding-dollar fa-xs me-1"></i>Deuda: L
                                <?= number_format($total_deuda_activa, 2) ?></span><?php endif; ?>
                    </div>
                    <div class="mt-1 opacity-85" style="font-size:14px"><i
                            class="fa-solid fa-briefcase me-1"></i><?= htmlspecialchars($col['puesto']) ?><?php if ($col['departamento']): ?>&nbsp;·&nbsp;<i
                            class="fa-solid fa-building me-1"></i><?= htmlspecialchars($col['departamento']) ?><?php endif; ?>
                    </div>
                    <div class="mt-1 opacity-75" style="font-size:13px">
                        <?php if ($col['telefono']): ?><i
                                class="fa-solid fa-phone me-1"></i><?= htmlspecialchars($col['telefono']) ?>&nbsp;&nbsp;<?php endif; ?>
                            <?php if ($col['email']): ?><i
                                    class="fa-solid fa-envelope me-1"></i><?= htmlspecialchars($col['email']) ?><?php endif; ?>
                                <?php if ($col['dpi']): ?>&nbsp;&nbsp;<i
                                    class="fa-solid fa-id-card me-1"></i><?= htmlspecialchars($col['dpi']) ?><?php endif; ?>
                    </div>
                </div>
                <div class="text-center text-white p-3 rounded-3"
                    style="background:rgba(255,255,255,.15);min-width:110px">
                    <div style="font-size:26px;font-weight:800;line-height:1"><?= $anios > 0 ? $anios : $mesesAnt ?>
                    </div>
                    <div style="font-size:11px;opacity:.8;text-transform:uppercase;letter-spacing:.5px">
                        <?= $anios > 0 ? ($anios === 1 ? 'año' : 'años') : 'mes(es)' ?></div>
                    <div style="font-size:10px;opacity:.65">de antigüedad</div>
                </div>
            </div>
        </div>
        <div class="card-body p-3">
            <div class="row g-2">
                <div class="col-6 col-md col-lg">
                    <div class="stat-pill">
                        <div class="stat-val text-dark">L <?= number_format($salario, 0) ?></div>
                        <div class="stat-lbl">Salario Bruto</div>
                    </div>
                </div>
                <div class="col-6 col-md col-lg">
                    <div class="stat-pill">
                        <div class="stat-val text-success">L <?= number_format($neto_mes / $div, 0) ?></div>
                        <div class="stat-lbl">Neto <?= $tipo_pago === 'quincenal' ? '/quincena' : '/mes' ?></div>
                    </div>
                </div>
                <div class="col-6 col-md col-lg">
                    <div class="stat-pill">
                        <div class="stat-val text-danger">-L <?= number_format(($ihss_emp + $rap_emp) / $div, 0) ?>
                        </div>
                        <div class="stat-lbl">Deducciones</div>
                    </div>
                </div>
                <div class="col-6 col-md col-lg">
                    <div class="stat-pill">
                        <div class="stat-val text-warning">L <?= number_format(($ihss_pat + $rap_pat) / $div, 0) ?>
                        </div>
                        <div class="stat-lbl">Carga Patronal</div>
                    </div>
                </div>
                <div class="col-6 col-md col-lg">
                    <div class="stat-pill">
                        <div class="stat-val" style="color:#6f42c1">L <?= number_format($costo_emp / $div, 0) ?></div>
                        <div class="stat-lbl">Costo Empresa</div>
                    </div>
                </div>
                <div class="col-6 col-md col-lg">
                    <div class="stat-pill">
                        <div class="stat-val text-info">
                            <?= $tipo_pago === 'quincenal' ? '🔄 Quincenal' : '📅 Mensual' ?>
                        </div>
                        <div class="stat-lbl">Tipo de Pago</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">

        <!-- Col izquierda -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="mb-0 fw-bold"><i class="fa-solid fa-person me-2 text-primary"></i>Datos Personales</h6>
                </div>
                <div class="card-body p-3">
                    <div class="info-row"><span class="info-lbl">Nombre completo</span><span
                            class="info-val"><?= htmlspecialchars($nombreCompleto) ?></span></div>
                    <?php if ($col['dpi']): ?><div class="info-row"><span class="info-lbl">DPI</span><span
                                class="info-val"><?= htmlspecialchars($col['dpi']) ?></span></div><?php endif; ?>
                    <?php if ($col['telefono']): ?><div class="info-row"><span class="info-lbl">Teléfono</span><span
                                class="info-val"><?= htmlspecialchars($col['telefono']) ?></span></div><?php endif; ?>
                    <?php if ($col['email']): ?><div class="info-row"><span class="info-lbl">Email</span><span
                                class="info-val" style="font-size:12px"><?= htmlspecialchars($col['email']) ?></span></div>
                    <?php endif; ?>
                    <div class="info-row"><span class="info-lbl">Fecha de ingreso</span><span
                            class="info-val"><?= date('d/m/Y', strtotime($col['fecha_ingreso'])) ?></span></div>
                    <div class="info-row"><span class="info-lbl">Antigüedad</span><span
                            class="info-val"><?php if ($anios > 0) echo $anios . ' año(s) y ' . $mesesAnt . ' mes(es)';
                                                elseif ($mesesAnt > 0) echo $mesesAnt . ' mes(es)';
                                                else echo $diasTotal . ' día(s)'; ?></span>
                    </div>
                    <?php if ($col['cat_nombre']): ?><div class="info-row"><span class="info-lbl">Categoría</span><span
                                class="info-val"><span class="badge rounded-pill px-2"
                                    style="background:<?= $col['cat_color'] ?>18;color:<?= $col['cat_color'] ?>;border:1px solid <?= $col['cat_color'] ?>40"><i
                                        class="fa-solid <?= $col['cat_icono'] ?> me-1"></i><?= htmlspecialchars($col['cat_nombre']) ?></span></span>
                        </div><?php endif; ?>
                    <?php if ($col['notas']): ?><div class="mt-2 p-2 rounded-2"
                            style="background:#f8f9fa;font-size:12px;color:#555"><i
                                class="fa-solid fa-note-sticky me-1 text-secondary"></i><?= nl2br(htmlspecialchars($col['notas'])) ?>
                        </div><?php endif; ?>
                </div>
                <?php if ($col['activo']): ?><div class="card-footer bg-white border-top py-2 no-print"><button
                            class="btn btn-sm btn-outline-primary w-100 btn-editar-colab"
                            data-col='<?= json_encode($col, JSON_HEX_APOS | JSON_HEX_QUOT) ?>'><i
                                class="fa-solid fa-pen-to-square me-1"></i> Editar datos</button></div><?php endif; ?>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="mb-0 fw-bold"><i class="fa-solid fa-calculator me-2 text-success"></i>Desglose Salarial
                    </h6>
                </div>
                <div class="card-body p-3">
                    <?php $lbl = $tipo_pago === 'quincenal' ? 'quincena' : 'mes'; ?>
                    <div class="info-row"><span class="info-lbl">Salario bruto / mes</span><span class="info-val">L
                            <?= number_format($salario, 2) ?></span></div>
                    <div class="info-row"><span class="info-lbl text-danger">- IHSS empleado
                            <?= $aplica_ihss ? '(3.5%)' : '' ?></span><span
                            class="info-val text-danger"><?= $aplica_ihss ? '-L ' . number_format($ihss_emp, 2) : '<span class="text-muted">No aplica</span>' ?></span>
                    </div>
                    <div class="info-row"><span class="info-lbl text-danger">- RAP empleado
                            <?= $aplica_rap ? '(1.5%)' : '' ?></span><span
                            class="info-val text-danger"><?= $aplica_rap ? '-L ' . number_format($rap_emp, 2) : '<span class="text-muted">No aplica</span>' ?></span>
                    </div>
                    <div class="info-row" style="border-top:2px solid #dee2e6;margin-top:4px;padding-top:10px"><span
                            class="info-lbl fw-bold text-success">= Neto / <?= $lbl ?></span><span
                            class="info-val text-success fs-6">L <?= number_format($neto_mes / $div, 2) ?></span></div>
                    <div class="mt-3 pt-2 border-top">
                        <div class="info-row"><span class="info-lbl text-warning">+ IHSS patronal (7%)</span><span
                                class="info-val text-warning"><?= $aplica_ihss ? 'L ' . number_format($ihss_pat / $div, 2) : '<span class="text-muted">—</span>' ?></span>
                        </div>
                        <div class="info-row"><span class="info-lbl text-warning">+ RAP patronal (1.5%)</span><span
                                class="info-val text-warning"><?= $aplica_rap ? 'L ' . number_format($rap_pat / $div, 2) : '<span class="text-muted">—</span>' ?></span>
                        </div>
                        <div class="info-row"><span class="info-lbl fw-bold" style="color:#6f42c1">= Costo empresa /
                                <?= $lbl ?></span><span class="info-val fw-bold" style="color:#6f42c1">L
                                <?= number_format($costo_emp / $div, 2) ?></span></div>
                    </div>
                    <div class="mt-2 p-2 rounded-2" style="background:#f0f7ff;font-size:11.5px;color:#555"><i
                            class="fa-solid fa-circle-info me-1 text-primary"></i><?php if ($tipo_pago === 'quincenal'): ?>Días
                        de pago: <strong><?= (int)$col['dia_pago'] ?></strong> y
                        <strong><?= (int)$col['dia_pago_2'] ?></strong> de cada mes<?php else: ?>Día de pago:
                        <strong><?= (int)$col['dia_pago'] ?></strong> de cada mes<?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="mb-0 fw-bold"><i class="fa-solid fa-chart-bar me-2 text-secondary"></i>Resumen del
                        Período <small
                            class="text-muted fw-normal"><?= $filtro_todo ? 'Todo el historial' : $meses[$filtro_mes - 1] . ' ' . $filtro_anio ?></small>
                    </h6>
                </div>
                <div class="card-body p-3">
                    <div class="d-flex justify-content-between mb-2">
                        <div class="text-center flex-grow-1">
                            <div class="fs-5 fw-bold text-success">L <?= number_format($total_pagado, 2) ?></div>
                            <div class="text-muted" style="font-size:11px">PAGADO (<?= $count_pagado ?>)</div>
                        </div>
                        <div class="vr mx-2"></div>
                        <div class="text-center flex-grow-1">
                            <div class="fs-5 fw-bold text-warning">L <?= number_format($total_pend, 2) ?></div>
                            <div class="text-muted" style="font-size:11px">PENDIENTE (<?= $count_pend ?>)</div>
                        </div>
                    </div>
                    <hr class="my-2 opacity-25">
                    <div class="text-center">
                        <div class="fs-5 fw-bold text-primary">L <?= number_format($total_pagado + $total_pend, 2) ?>
                        </div>
                        <div class="text-muted" style="font-size:11px">TOTAL (<?= count($pagos) ?> registros)</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Col derecha -->
        <div class="col-lg-8">

            <!-- Filtros -->
            <div class="card border-0 shadow-sm mb-3 no-print">
                <div class="card-body py-3">
                    <form method="GET" class="row g-2 align-items-end">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <div class="col-auto"><label class="form-label small fw-semibold mb-1">Mes</label><select
                                name="mes" class="form-select form-select-sm"><?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?= $m ?>" <?= $m == $filtro_mes ? 'selected' : '' ?>>
                                        <?= $meses[$m - 1] ?></option>
                                <?php endfor; ?>
                            </select></div>
                        <div class="col-auto"><label class="form-label small fw-semibold mb-1">Año</label><select
                                name="anio"
                                class="form-select form-select-sm"><?php for ($a = date('Y'); $a >= date('Y') - 4; $a--): ?>
                                    <option value="<?= $a ?>" <?= $a == $filtro_anio ? 'selected' : '' ?>><?= $a ?></option>
                                <?php endfor; ?>
                            </select></div>
                        <?php if ($tipo_pago === 'quincenal'): ?><div class="col-auto"><label
                                    class="form-label small fw-semibold mb-1">Quincena</label><select name="tipo"
                                    class="form-select form-select-sm">
                                    <option value="" <?= $filtro_tipo === '' ? 'selected' : '' ?>>Ambas</option>
                                    <option value="1" <?= $filtro_tipo === '1' ? 'selected' : '' ?>>1ª</option>
                                    <option value="2" <?= $filtro_tipo === '2' ? 'selected' : '' ?>>2ª</option>
                                </select></div><?php endif; ?>
                        <div class="col-auto"><button type="submit" class="btn btn-primary btn-sm"><i
                                    class="fa-solid fa-filter me-1"></i>Filtrar</button></div>
                        <div class="col-auto"><a href="?id=<?= $id ?>&todo=1"
                                class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-list me-1"></i>Todo</a>
                        </div>
                        <div class="col-auto"><a href="?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">Mes
                                actual</a></div>
                    </form>
                </div>
            </div>

            <div class="mb-2">
                <?php if ($filtro_todo): ?>
                    <span class="filter-chip"><i class="fa-solid fa-layer-group fa-xs"></i>Historial completo <a
                            href="?id=<?= $id ?>" class="text-muted ms-1" style="text-decoration:none">×</a></span>
                <?php else: ?>
                    <span class="filter-chip"><i class="fa-solid fa-calendar fa-xs"></i><?= $meses[$filtro_mes - 1] ?>
                        <?= $filtro_anio ?><?php if ($filtro_tipo === '1'): ?> · 1ª
                        Quincena<?php endif; ?><?php if ($filtro_tipo === '2'): ?> · 2ª Quincena<?php endif; ?></span>
                <?php endif; ?>
            </div>

            <!-- Historial pagos -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold"><i class="fa-solid fa-receipt me-2 text-secondary"></i>Historial de Pagos
                        <span class="badge bg-light text-secondary border ms-1"><?= count($pagos) ?></span>
                    </h6>
                    <?php if ($col['activo']): ?><button class="btn btn-sm btn-success no-print btn-pagar-directo"><i
                                class="fa-solid fa-plus me-1"></i>Nuevo Pago</button><?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table id="tablaPagos" class="table table-hover align-middle mb-0" style="font-size:13.5px">
                            <thead class="table-light">
                                <tr>
                                    <th>Fecha</th>
                                    <th>Concepto</th>
                                    <th class="text-center">Q</th>
                                    <th class="text-end">Monto</th>
                                    <th class="text-center">Método</th>
                                    <th class="text-center">Estado</th>
                                    <th class="text-center no-print" style="width:80px">Comp.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($pagos)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-5"><i
                                                class="fa-solid fa-inbox fa-2x mb-2 d-block opacity-25"></i>No hay pagos
                                            registrados.<?php if ($col['activo']): ?><br><button
                                                class="btn btn-sm btn-success mt-2 btn-pagar-directo no-print"><i
                                                    class="fa-solid fa-plus me-1"></i>Registrar primer
                                                pago</button><?php endif; ?></td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($pagos as $p):
                                        $metIco = ['efectivo' => '💵', 'transferencia' => '🏦', 'cheque' => '📝', 'tarjeta' => '💳', 'otro' => '🔷'];
                                        $esNomina = (strpos($p['descripcion'], 'Sueldo ') === 0);
                                        $esViat  = (strpos($p['descripcion'], 'Viático: ') === 0);
                                        $esBono  = (strpos($p['descripcion'], 'Bono: ') === 0);
                                        $esPrest = (strpos($p['descripcion'], 'Prestamo') === 0 || strpos($p['descripcion'], 'Préstamo') === 0);
                                    ?>
                                        <tr class="<?= $p['estado'] === 'anulado' ? 'opacity-50' : '' ?>">
                                            <td class="fw-semibold text-nowrap">
                                                <?= date('d/m/Y', strtotime($p['fecha'])) ?><br><small
                                                    class="text-muted fw-normal"><?= date('D', strtotime($p['fecha'])) ?></small>
                                            </td>
                                            <td>
                                                <div
                                                    class="fw-semibold <?= $p['estado'] === 'anulado' ? 'text-decoration-line-through text-muted' : '' ?>">
                                                    <?php if ($esNomina): ?><span
                                                            class="badge bg-primary bg-opacity-10 text-primary me-1"
                                                            style="font-size:10px"><i
                                                                class="fa-solid fa-money-bill fa-xs me-1"></i>Nómina</span><?php endif; ?>
                                                    <?php if ($esBono): ?><span
                                                            class="badge bg-success bg-opacity-10 text-success me-1"
                                                            style="font-size:10px"><i
                                                                class="fa-solid fa-gift fa-xs me-1"></i>Bono</span><?php endif; ?>
                                                    <?php if ($esViat): ?><span class="badge me-1"
                                                            style="font-size:10px;background:#e0f2fe;color:#0369a1"><i
                                                                class="fa-solid fa-plane-departure fa-xs me-1"></i>Viático</span><?php endif; ?>
                                                    <?php if ($esPrest): ?><span
                                                            class="badge bg-danger bg-opacity-10 text-danger me-1"
                                                            style="font-size:10px"><i
                                                                class="fa-solid fa-hand-holding-dollar fa-xs me-1"></i>Préstamo</span><?php endif; ?>
                                                    <?= htmlspecialchars($p['descripcion']) ?>
                                                </div>
                                                <?php if ($p['notas']): ?><small class="text-muted d-block"
                                                        style="font-size:11px"><i
                                                            class="fa-solid fa-note-sticky fa-xs me-1"></i><?= htmlspecialchars(mb_substr($p['notas'], 0, 60)) ?><?= strlen($p['notas']) > 60 ? '...' : '' ?></small><?php endif; ?>
                                            </td>
                                            <td class="text-center"><?php if ((int)$p['quincena_num'] === 1): ?><span
                                                        class="badge badge-q1">1ª</span><?php elseif ((int)$p['quincena_num'] === 2): ?><span
                                                        class="badge badge-q2">2ª</span><?php else: ?><span
                                                        class="badge badge-mensual">M</span><?php endif; ?></td>
                                            <td
                                                class="text-end fw-bold <?= $p['estado'] === 'anulado' ? 'text-muted text-decoration-line-through' : ($p['monto'] > 0 ? 'text-success' : 'text-danger') ?>">
                                                L <?= number_format((float)$p['monto'], 2) ?></td>
                                            <td class="text-center small text-muted"><?= $metIco[$p['metodo_pago']] ?? '•' ?>
                                            </td>
                                            <td class="text-center"><?php if ($p['estado'] === 'pagado'): ?><span
                                                        class="badge bg-success">Pagado</span><?php elseif ($p['estado'] === 'pendiente'): ?><span
                                                        class="badge bg-warning text-dark">Pendiente</span><?php else: ?><span
                                                        class="badge bg-secondary">Anulado</span><?php endif; ?></td>
                                            <td class="text-center no-print">
                                                <?php if (!empty($p['archivo_adjunto'])): ?><a
                                                        href="gasto_ver.php?id=<?= $p['id'] ?>" target="_blank"
                                                        class="btn btn-sm btn-outline-secondary"><i
                                                            class="fa-solid fa-paperclip"></i></a><?php endif; ?>
                                                <a href="colaborador_recibo_pdf.php?gasto_id=<?= $p['id'] ?>&vista=1"
                                                    target="_blank" class="btn btn-sm btn-outline-danger"><i
                                                        class="fa-solid fa-file-pdf"></i></a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <?php if (!empty($pagos)): ?>
                                <tfoot class="table-light">
                                    <tr>
                                        <td colspan="3" class="text-end fw-bold small">TOTAL PERÍODO:</td>
                                        <td class="text-end fw-bold text-primary">L
                                            <?= number_format($total_pagado + $total_pend, 2) ?></td>
                                        <td colspan="3"></td>
                                    </tr>
                                </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Préstamos / Adelantos / Bonos / Viáticos -->
            <div class="card border-0 shadow-sm mb-4" style="border-left:4px solid #dc3545!important">
                <div
                    class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-2">
                        <h6 class="mb-0 fw-bold"><i
                                class="fa-solid fa-hand-holding-dollar me-2 text-danger"></i>Préstamos, Adelantos, Bonos
                            y Viáticos<?php if (!empty($prestamos)): ?><span
                                class="badge bg-light text-secondary border ms-1"><?= count($prestamos) ?></span><?php endif; ?>
                        </h6>
                        <?php if ($total_deuda_activa > 0): ?><span
                                class="badge bg-danger bg-opacity-15 border border-danger border-opacity-25"
                                style="font-size:11px">Deuda activa: L
                                <?= number_format($total_deuda_activa, 2) ?></span><?php endif; ?>
                    </div>
                    <?php if ($col['activo']): ?><button class="btn btn-sm btn-outline-danger no-print"
                            id="btnNuevoPrestamo"><i class="fa-solid fa-plus me-1"></i>Registrar</button><?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($prestamos)): ?>
                        <div class="text-center py-5 text-muted"><i
                                class="fa-solid fa-coins fa-2x mb-2 d-block opacity-20"></i>
                            <div style="font-size:13px">No hay movimientos registrados.</div>
                            <?php if ($col['activo']): ?><button class="btn btn-sm btn-outline-danger mt-2 no-print"
                                    id="btnNuevoPrestamo2"><i class="fa-solid fa-plus me-1"></i>Registrar
                                    primero</button><?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="accordion accordion-flush" id="accordionPrestamos">
                            <?php foreach ($prestamos as $pr):
                                $cuotas         = $cuotas_por_prestamo[$pr['id']] ?? [];
                                $cuotas_pagadas = count(array_filter($cuotas, fn($c) => $c['estado'] === 'pagado'));
                                $proxima_cuota  = null;
                                foreach ($cuotas as $c) {
                                    if ($c['estado'] === 'pendiente') {
                                        $proxima_cuota = $c;
                                        break;
                                    }
                                }
                                $tipo_config = [
                                    'prestamo' => ['label' => 'Préstamo',        'color' => 'danger',  'icon' => 'fa-hand-holding-dollar'],
                                    'adelanto' => ['label' => 'Adelanto',        'color' => 'warning', 'icon' => 'fa-bolt'],
                                    'bono'    => ['label' => 'Bono',            'color' => 'success', 'icon' => 'fa-gift'],
                                    'viatico' => ['label' => 'Viático',         'color' => 'info',    'icon' => 'fa-plane-departure'],
                                    'multa'   => ['label' => 'Multa/Descuento', 'color' => 'secondary', 'icon' => 'fa-ban'],
                                ];
                                $tc = $tipo_config[$pr['tipo']] ?? $tipo_config['prestamo'];
                                $badgeClass = "bg-{$tc['color']} bg-opacity-15 text-{$tc['color']} border border-{$tc['color']} border-opacity-25";
                                if ($pr['tipo'] === 'prestamo') $badgeClass = "bg-danger text-white border border-danger";
                                $estado_config = ['activo' => ['color' => 'primary', 'label' => 'Activo', 'solid' => true], 'pagado' => ['color' => 'success', 'label' => 'Pagado', 'solid' => true], 'cancelado' => ['color' => 'secondary', 'label' => 'Cancelado', 'solid' => false]];
                                $ec = $estado_config[$pr['estado']] ?? $estado_config['activo'];
                                $pct = ($pr['monto_total'] > 0 && $pr['tipo'] !== 'bono' && $pr['tipo'] !== 'viatico') ? min(100, round((($pr['monto_total'] - $pr['saldo_pendiente']) / $pr['monto_total']) * 100)) : 100;
                            ?>
                                <div class="accordion-item border-0 border-bottom">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button collapsed py-3 px-4" type="button"
                                            data-bs-toggle="collapse" data-bs-target="#prest-<?= $pr['id'] ?>">
                                            <div class="d-flex align-items-center gap-3 w-100 me-3 flex-wrap">
                                                <span class="badge <?= $badgeClass ?> px-2" style="font-size:11px"><i
                                                        class="fa-solid <?= $tc['icon'] ?> me-1"></i><?= $tc['label'] ?></span>
                                                <div class="fw-semibold flex-grow-1" style="font-size:13.5px">
                                                    <?= htmlspecialchars($pr['descripcion']) ?><small
                                                        class="text-muted fw-normal ms-2"><?= date('d/m/Y', strtotime($pr['fecha'])) ?></small>
                                                </div>
                                                <div class="d-flex gap-3 align-items-center text-end">
                                                    <?php if ($pr['tipo'] !== 'bono' && $pr['tipo'] !== 'viatico'): ?><div>
                                                            <div class="text-muted" style="font-size:10px">SALDO</div>
                                                            <div class="fw-bold text-danger" style="font-size:13px">L
                                                                <?= number_format((float)$pr['saldo_pendiente'], 2) ?></div>
                                                        </div><?php endif; ?>
                                                    <div>
                                                        <div class="text-muted" style="font-size:10px">TOTAL</div>
                                                        <div class="fw-bold" style="font-size:13px">L
                                                            <?= number_format((float)$pr['monto_total'], 2) ?></div>
                                                    </div>
                                                    <span
                                                        class="badge bg-<?= $ec['color'] ?> <?= ($ec['solid'] ?? false) ? 'text-white' : 'bg-opacity-15 text-' . $ec['color'] ?> border border-<?= $ec['color'] ?>"
                                                        style="font-size:10px"><?= $ec['label'] ?></span>
                                                </div>
                                            </div>
                                        </button>
                                    </h2>
                                    <div id="prest-<?= $pr['id'] ?>" class="accordion-collapse collapse">
                                        <div class="accordion-body px-4 pt-2 pb-3" style="background:#fafafa">
                                            <div class="row g-3 mb-3">
                                                <div class="col-md-8">
                                                    <div class="d-flex flex-wrap gap-3 text-center mb-2">
                                                        <div class="stat-pill flex-grow-1">
                                                            <div class="stat-val">L
                                                                <?= number_format((float)$pr['monto_total'], 2) ?></div>
                                                            <div class="stat-lbl">Monto Original</div>
                                                        </div>
                                                        <?php if ($pr['tipo'] !== 'bono' && $pr['tipo'] !== 'viatico'): ?>
                                                            <div class="stat-pill flex-grow-1">
                                                                <div class="stat-val text-danger">L
                                                                    <?= number_format((float)$pr['saldo_pendiente'], 2) ?></div>
                                                                <div class="stat-lbl">Saldo Pendiente</div>
                                                            </div>
                                                            <div class="stat-pill flex-grow-1">
                                                                <div class="stat-val text-success">L
                                                                    <?= number_format((float)$pr['monto_total'] - (float)$pr['saldo_pendiente'], 2) ?>
                                                                </div>
                                                                <div class="stat-lbl">Ya Pagado</div>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($pr['tipo'] !== 'bono' && $pr['tipo'] !== 'viatico' && (int)$pr['num_cuotas'] > 1): ?>
                                                        <div class="mb-1" style="font-size:11px;color:#888">Progreso:
                                                            <?= $cuotas_pagadas ?>/<?= $pr['num_cuotas'] ?> cuotas ·
                                                            <?= ucfirst($pr['frecuencia_cuota']) ?> · L
                                                            <?= number_format((float)$pr['monto_cuota'], 2) ?>/cuota<?= $pr['descuento_auto'] ? ' · <span class="badge bg-info text-dark" style="font-size:9px">Auto nómina</span>' : '' ?>
                                                        </div>
                                                        <div class="progress" style="height:6px">
                                                            <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if ($proxima_cuota): ?>
                                                        <div class="mt-2 p-2 rounded-2 border"
                                                            style="background:#fff;font-size:12px"><i
                                                                class="fa-solid fa-calendar-day me-1 text-warning"></i><strong>Próxima
                                                                cuota:</strong>
                                                            <?= date('d/m/Y', strtotime($proxima_cuota['fecha_esperada'])) ?> · L
                                                            <?= number_format((float)$proxima_cuota['monto'], 2) ?> <button
                                                                class="btn btn-xs btn-outline-success ms-2 no-print btn-pagar-cuota"
                                                                style="font-size:10px;padding:1px 8px"
                                                                data-cuota-id="<?= $proxima_cuota['id'] ?>"
                                                                data-cuota-num="<?= $proxima_cuota['numero_cuota'] ?>"
                                                                data-monto="<?= number_format((float)$proxima_cuota['monto'], 2) ?>"><i
                                                                    class="fa-solid fa-check fa-xs"></i>Pagar</button></div>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($pr['estado'] === 'activo'): ?>
                                                    <div class="col-md-4 d-flex flex-column gap-2 justify-content-start no-print">
                                                        <button class="btn btn-sm btn-outline-primary btn-editar-prestamo"
                                                            data-prestamo-id="<?= $pr['id'] ?>" data-tipo="<?= $pr['tipo'] ?>"
                                                            data-descripcion="<?= htmlspecialchars($pr['descripcion']) ?>"
                                                            data-fecha="<?= $pr['fecha'] ?>"
                                                            data-notas="<?= htmlspecialchars($pr['notas'] ?? '') ?>"
                                                            data-descuento-auto="<?= (int)$pr['descuento_auto'] ?>"
                                                            data-estado="<?= $pr['estado'] ?>"><i
                                                                class="fa-solid fa-pen-to-square me-1"></i>Editar</button>
                                                        <button class="btn btn-sm btn-outline-danger btn-cancelar-prestamo"
                                                            data-prestamo-id="<?= $pr['id'] ?>"
                                                            data-desc="<?= htmlspecialchars($pr['descripcion']) ?>"><i
                                                                class="fa-solid fa-xmark me-1"></i>Cancelar</button>
                                                        <button class="btn btn-sm btn-danger btn-eliminar-prestamo"
                                                            data-prestamo-id="<?= $pr['id'] ?>"
                                                            data-desc="<?= htmlspecialchars($pr['descripcion']) ?>"><i
                                                                class="fa-solid fa-trash me-1"></i>Eliminar</button>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <?php if (!empty($cuotas)): ?>
                                                <div class="table-responsive">
                                                    <table class="table table-sm table-hover align-middle mb-0"
                                                        style="font-size:12px">
                                                        <thead class="table-light">
                                                            <tr>
                                                                <th class="text-center">#</th>
                                                                <th>Fecha esperada</th>
                                                                <th class="text-end">Monto</th>
                                                                <th class="text-center">Estado</th>
                                                                <th>Fecha pago</th>
                                                                <th>Método</th>
                                                                <th class="no-print text-center">Acción</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($cuotas as $c):
                                                                $hoy_c = date('Y-m-d');
                                                                $metodos_c = ['efectivo' => '💵', 'transferencia' => '🏦', 'cheque' => '📝', 'tarjeta' => '💳', 'descuento_nomina' => '🔄', 'otro' => '🔷'];
                                                            ?>
                                                                <tr class="<?= $c['estado'] === 'cancelado' ? 'opacity-50' : '' ?>">
                                                                    <td class="text-center fw-bold"><?= $c['numero_cuota'] ?></td>
                                                                    <td class="text-nowrap">
                                                                        <?= date('d/m/Y', strtotime($c['fecha_esperada'])) ?><?php if ($c['estado'] === 'pendiente' && $c['fecha_esperada'] < $hoy_c): ?><span
                                                                            class="badge bg-danger ms-1"
                                                                            style="font-size:9px">Vencida</span><?php endif; ?></td>
                                                                    <td class="text-end fw-bold">L
                                                                        <?= number_format((float)$c['monto'], 2) ?></td>
                                                                    <td class="text-center">
                                                                        <?php if ($c['estado'] === 'pagado'): ?><span
                                                                                class="badge bg-success" style="font-size:10px">✓
                                                                                Pagado</span><?php elseif ($c['estado'] === 'pendiente'): ?><span
                                                                                class="badge bg-warning text-dark"
                                                                                style="font-size:10px">Pendiente</span><?php else: ?><span
                                                                                class="badge bg-secondary"
                                                                                style="font-size:10px">Cancelado</span><?php endif; ?></td>
                                                                    <td class="text-muted text-nowrap">
                                                                        <?= $c['fecha_pago'] ? date('d/m/Y', strtotime($c['fecha_pago'])) : '—' ?>
                                                                    </td>
                                                                    <td class="text-muted" style="font-size:11px">
                                                                        <?= $c['metodo_pago'] ? ($metodos_c[$c['metodo_pago']] ?? $c['metodo_pago']) : '—' ?>
                                                                    </td>
                                                                    <td class="text-center no-print">
                                                                        <?php if ($c['estado'] === 'pendiente'): ?>
                                                                            <div class="d-flex gap-1 justify-content-center">
                                                                                <button class="btn btn-xs btn-success btn-pagar-cuota"
                                                                                    style="font-size:10px;padding:2px 8px"
                                                                                    data-cuota-id="<?= $c['id'] ?>"
                                                                                    data-cuota-num="<?= $c['numero_cuota'] ?>"
                                                                                    data-monto="<?= number_format((float)$c['monto'], 2) ?>"><i
                                                                                        class="fa-solid fa-check fa-xs"></i></button>
                                                                                <button
                                                                                    class="btn btn-xs btn-outline-secondary btn-editar-cuota"
                                                                                    style="font-size:10px;padding:2px 8px"
                                                                                    data-cuota-id="<?= $c['id'] ?>"
                                                                                    data-cuota-num="<?= $c['numero_cuota'] ?>"
                                                                                    data-monto="<?= number_format((float)$c['monto'], 2) ?>"
                                                                                    data-fecha-esperada="<?= $c['fecha_esperada'] ?>"
                                                                                    data-fecha-pago="<?= $c['fecha_pago'] ?? '' ?>"
                                                                                    data-metodo="<?= $c['metodo_pago'] ?? '' ?>"
                                                                                    data-estado="<?= $c['estado'] ?>"
                                                                                    data-notas="<?= htmlspecialchars($c['notas'] ?? '') ?>"><i
                                                                                        class="fa-solid fa-pen fa-xs"></i></button>
                                                                            </div>
                                                                        <?php elseif ($c['estado'] === 'pagado'): ?>
                                                                            <button class="btn btn-xs btn-outline-warning btn-editar-cuota"
                                                                                style="font-size:10px;padding:2px 8px"
                                                                                data-cuota-id="<?= $c['id'] ?>"
                                                                                data-cuota-num="<?= $c['numero_cuota'] ?>"
                                                                                data-monto="<?= number_format((float)$c['monto'], 2) ?>"
                                                                                data-fecha-esperada="<?= $c['fecha_esperada'] ?>"
                                                                                data-fecha-pago="<?= $c['fecha_pago'] ?? '' ?>"
                                                                                data-metodo="<?= $c['metodo_pago'] ?? '' ?>"
                                                                                data-estado="<?= $c['estado'] ?>"
                                                                                data-notas="<?= htmlspecialchars($c['notas'] ?? '') ?>"><i
                                                                                    class="fa-solid fa-pen fa-xs"></i></button>
                                                                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                                                    </td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            <?php elseif ($pr['tipo'] === 'bono'): ?>
                                                <div class="text-muted small"><i class="fa-solid fa-gift me-1 text-success"></i>Bono
                                                    / Gratificación — no genera cuotas.</div>
                                            <?php elseif ($pr['tipo'] === 'viatico'): ?>
                                                <div class="text-muted small" style="color:#0369a1"><i
                                                        class="fa-solid fa-plane-departure me-1"></i>Viático registrado — se
                                                    liquidará al procesar nómina.</div>
                                            <?php endif; ?>
                                            <?php if ($pr['notas']): ?><div class="mt-2 p-2 rounded-2"
                                                    style="background:#f8f9fa;font-size:11.5px;color:#555"><i
                                                        class="fa-solid fa-note-sticky me-1 text-secondary"></i><?= nl2br(htmlspecialchars($pr['notas'])) ?>
                                                </div><?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- ══ MODAL: Registrar Pago de Nómina ══════════════════════════════ -->
<div class="modal fade" id="modalPago" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header py-3 border-bottom"
                style="background:linear-gradient(135deg,#059669 0%,#065f46 100%)">
                <h5 class="modal-title fw-bold text-white"><i class="fa-solid fa-hand-holding-dollar me-2"></i>Registrar
                    Pago de Nómina</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form id="formPago" enctype="multipart/form-data">
                    <input type="hidden" name="colaborador_id" value="<?= $id ?>">
                    <div class="rounded-3 p-3 mb-3 border" style="background:#f8f9fa">
                        <div class="fw-bold mb-2 d-flex align-items-center gap-2">
                            <?= htmlspecialchars($nombreCompleto) ?><span
                                class="badge bg-primary bg-opacity-10 text-primary"
                                style="font-size:10px"><?= $tipo_pago === 'quincenal' ? '🔄 Quincenal' : '📅 Mensual' ?></span>
                        </div>
                        <div class="row g-1 text-center" style="font-size:12px">
                            <div class="col">
                                <div class="text-muted">Bruto</div>
                                <div class="fw-bold">L <?= number_format($salario / $div, 2) ?></div>
                            </div>
                            <div class="col">
                                <div class="text-muted">- IHSS</div>
                                <div class="fw-bold text-danger">L <?= number_format($ihss_emp / $div, 2) ?></div>
                            </div>
                            <div class="col">
                                <div class="text-muted">- RAP</div>
                                <div class="fw-bold text-danger">L <?= number_format($rap_emp / $div, 2) ?></div>
                            </div>
                            <div class="col" id="colNetoPuro">
                                <div
                                    class="text-muted <?= $total_descuento_auto > 0 ? 'fw-normal' : 'fw-bold text-success' ?>">
                                    Neto</div>
                                <div class="fw-bold <?= $total_descuento_auto > 0 ? 'text-muted text-decoration-line-through' : 'text-success' ?>"
                                    id="lblNetoModal">L <?= number_format($neto_mes / $div, 2) ?></div>
                            </div>
                            <div class="col d-none" id="colDescModal">
                                <div class="text-muted">- Préstamo</div>
                                <div class="fw-bold text-danger" id="lblDescModal">L 0.00</div>
                            </div>
                            <div class="col d-none" id="colBonoModal">
                                <div class="text-muted">+ Bono</div>
                                <div class="fw-bold text-success" id="lblBonoModal">L 0.00</div>
                            </div>
                            <div class="col d-none" id="colViaModal">
                                <div class="text-muted">+ Viáticos</div>
                                <div class="fw-bold" style="color:#0369a1" id="lblViaModal">L 0.00</div>
                            </div>
                            <div class="col d-none" id="colApagarModal">
                                <div class="fw-bold text-success">✓ A pagar</div>
                                <div class="fw-bold text-success fs-6" id="lblApagarModal">L
                                    <?= number_format($neto_a_pagar_real, 2) ?></div>
                            </div>
                            <?php if ($total_descuento_auto <= 0): ?><div class="col" id="colApagarSolo">
                                    <div class="fw-bold text-success">✓ A pagar</div>
                                    <div class="fw-bold text-success" id="lblApagarSoloVal">L
                                        <?= number_format($neto_mes / $div, 2) ?></div>
                                </div><?php endif; ?>
                            <div class="col">
                                <div class="text-muted">+ Pat.</div>
                                <div class="fw-bold text-warning">L
                                    <?= number_format(($ihss_pat + $rap_pat) / $div, 2) ?>
                                </div>
                            </div>
                        </div>
                        <div id="pagoLoadingCuotas" class="text-center py-2 d-none"><i
                                class="fa-solid fa-spinner fa-spin text-secondary me-1"></i><small
                                class="text-muted">Verificando préstamos y viáticos...</small></div>
                        <?php if (!empty($cuotas_auto_pendientes)): ?>
                            <div class="mt-2 p-2 rounded-2 border border-danger border-opacity-25" id="boxCuotasDescuento"
                                style="background:#fff5f5;font-size:11.5px">
                                <div class="d-flex align-items-center justify-content-between mb-1"><span><i
                                            class="fa-solid fa-rotate me-1 text-danger"></i><strong>Descuentos de
                                            préstamos:</strong></span><span class="text-muted" style="font-size:10px">Marca
                                        los que aplican</span></div>
                                <?php foreach ($cuotas_auto_pendientes as $ca):
                                    $preChecked = false;
                                    foreach ($cuotas_aplicables as $ap) {
                                        if ($ap['cuota_id'] === $ca['cuota_id']) {
                                            $preChecked = true;
                                            break;
                                        }
                                    } ?>
                                    <div class="d-flex justify-content-between align-items-center mt-1 gap-2">
                                        <div class="form-check mb-0 d-flex align-items-center gap-2 flex-grow-1"><input
                                                class="form-check-input cuota-chk mt-0" type="checkbox"
                                                id="chk_cuota_<?= $ca['cuota_id'] ?>" name="cuotas_ids[]"
                                                value="<?= $ca['cuota_id'] ?>"
                                                data-monto="<?= number_format((float)$ca['cuota_monto'], 4, '.', '') ?>"
                                                <?= $preChecked ? 'checked' : '' ?>><label class="form-check-label text-muted"
                                                for="chk_cuota_<?= $ca['cuota_id'] ?>"
                                                style="cursor:pointer"><?= htmlspecialchars(mb_substr($ca['prest_desc'], 0, 34)) ?>
                                                <span class="badge bg-secondary ms-1" style="font-size:9px">cuota
                                                    #<?= $ca['numero_cuota'] ?></span></label></div>
                                        <span class="fw-bold text-danger text-nowrap">-L
                                            <?= number_format((float)$ca['cuota_monto'], 2) ?></span>
                                    </div>
                                <?php endforeach; ?>
                                <div
                                    class="d-flex justify-content-between mt-2 pt-1 border-top border-danger border-opacity-25">
                                    <span class="fw-bold text-danger">Total descuento:</span><span
                                        class="fw-bold text-danger" id="lblTotalDescuento">-L
                                        <?= number_format($total_descuento_auto, 2) ?></span>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div id="boxBonosAplicar"
                            class="d-none mt-2 p-2 rounded-2 border border-success border-opacity-25"
                            style="background:#f0fff4;font-size:11.5px">
                            <div class="d-flex align-items-center justify-content-between mb-1"><span><i
                                        class="fa-solid fa-gift me-1 text-success"></i><strong>Bonos
                                        pendientes:</strong></span><span class="text-muted" style="font-size:10px">Marca
                                    los que se pagan ahora</span></div>
                            <div id="listaBonosChk"></div>
                            <div
                                class="d-flex justify-content-between mt-2 pt-1 border-top border-success border-opacity-25">
                                <span class="fw-bold text-success">Total bonos:</span><span class="fw-bold text-success"
                                    id="lblTotalBonosPago">+L 0.00</span>
                            </div>
                        </div>
                        <div id="boxViaticosAplicar" class="d-none mt-2 p-2 rounded-2 border border-opacity-25"
                            style="background:#e0f2fe;font-size:11.5px;border-color:#0369a1!important">
                            <div class="d-flex align-items-center justify-content-between mb-1"><span><i
                                        class="fa-solid fa-plane-departure me-1" style="color:#0369a1"></i><strong
                                        style="color:#0369a1">Viáticos pendientes:</strong></span><span
                                    class="text-muted" style="font-size:10px">Marca los que se pagan ahora</span></div>
                            <div id="listaViaticosChk"></div>
                            <div class="d-flex justify-content-between mt-2 pt-1 border-top"
                                style="border-color:#0369a140!important"><span class="fw-bold"
                                    style="color:#0369a1">Total viáticos:</span><span class="fw-bold"
                                    style="color:#0369a1" id="lblTotalViaticos">+L 0.00</span></div>
                        </div>
                        <div id="resumenFinalPago" class="d-none mt-2 pt-2 border-top">
                            <div class="d-flex justify-content-between align-items-center"><span
                                    class="fw-bold text-success"><i class="fa-solid fa-circle-check me-1"></i>Total a
                                    pagar:</span><span class="fw-bold text-success fs-6" id="lblResumenFinal">L
                                    0.00</span></div>
                        </div>
                    </div>

                    <?php if ($tipo_pago === 'quincenal'): ?>
                        <div class="mb-3"><label class="form-label fw-semibold">¿Qué pago es?</label>
                            <div class="d-flex gap-3 flex-wrap">
                                <div class="form-check"><input class="form-check-input" type="radio" name="quincena" id="q1"
                                        value="1" <?= $quincena_sugerida === 1 ? 'checked' : '' ?>
                                        <?= $q1_pagada ? 'disabled' : '' ?>><label
                                        class="form-check-label <?= $q1_pagada ? 'opacity-50' : '' ?>" for="q1"><span
                                            class="badge bg-primary">1ª Quincena</span> <small class="text-muted">día
                                            <?= (int)$col['dia_pago'] ?></small><?php if ($q1_pagada): ?><span
                                                class="badge bg-success ms-1" style="font-size:9px"><i
                                                    class="fa-solid fa-check fa-xs"></i>Pagada</span><?php endif; ?></label>
                                </div>
                                <div class="form-check"><input class="form-check-input" type="radio" name="quincena" id="q2"
                                        value="2" <?= $quincena_sugerida === 2 ? 'checked' : '' ?>
                                        <?= $q2_pagada ? 'disabled' : '' ?>><label
                                        class="form-check-label <?= $q2_pagada ? 'opacity-50' : '' ?>" for="q2"><span
                                            class="badge bg-info text-dark">2ª Quincena</span> <small class="text-muted">día
                                            <?= (int)$col['dia_pago_2'] ?></small><?php if ($q2_pagada): ?><span
                                                class="badge bg-success ms-1" style="font-size:9px"><i
                                                    class="fa-solid fa-check fa-xs"></i>Pagada</span><?php endif; ?></label>
                                </div>
                            </div><?php if ($q1_pagada && $q2_pagada): ?><div class="alert alert-warning py-1 mt-2 mb-0"
                                    style="font-size:12px"><i class="fa-solid fa-triangle-exclamation me-1"></i>Ambas quincenas
                                    de este mes ya están registradas.</div><?php endif; ?>
                        </div>
                    <?php else: ?><input type="hidden" name="quincena" value="0"><?php endif; ?>

                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label fw-semibold">Fecha del Pago</label><input
                                type="date" name="fecha" id="pago_fecha" class="form-control"
                                value="<?= date('Y-m-d') ?>">
                            <div id="estadoPagoFecha" class="mt-1" style="font-size:12px"></div>
                        </div>
                        <div class="col-md-6"><label class="form-label fw-semibold">Método de Pago</label><select
                                name="metodo_pago" class="form-select">
                                <option value="transferencia">🏦 Transferencia</option>
                                <option value="efectivo">💵 Efectivo</option>
                                <option value="cheque">📝 Cheque</option>
                                <option value="tarjeta">💳 Tarjeta</option>
                                <option value="otro">🔷 Otro</option>
                            </select></div>
                        <div class="col-12"><label class="form-label fw-semibold">Notas <span
                                    class="text-muted small fw-normal">(opcional)</span></label><textarea name="notas"
                                class="form-control" rows="2"
                                placeholder="N° transferencia, banco, referencia..."></textarea></div>
                        <div class="col-12">
                            <label class="form-label fw-semibold"><i
                                    class="fa-solid fa-paperclip me-1 text-secondary"></i>Comprobante <span
                                    class="text-muted small fw-normal">(opcional · JPG, PNG, WEBP, PDF · máx 5
                                    MB)</span></label>
                            <div id="zonaComprobante" class="border border-2 rounded-3 text-center p-3"
                                style="cursor:pointer;border-style:dashed!important;border-color:#dee2e6!important;transition:all .2s"
                                ondragover="event.preventDefault();this.style.borderColor='#0d6efd';this.style.background='#f0f7ff'"
                                ondragleave="this.style.borderColor='';this.style.background=''"
                                ondrop="handleDrop(event)">
                                <i class="fa-solid fa-cloud-arrow-up fa-2x text-secondary opacity-50 mb-1 d-block"></i>
                                <div class="small text-muted">Arrastra aquí o <span class="text-primary fw-semibold"
                                        style="cursor:pointer"
                                        onclick="document.getElementById('pago_comprobante').click()">selecciona un
                                        archivo</span></div>
                                <input type="file" id="pago_comprobante" name="comprobante"
                                    accept=".jpg,.jpeg,.png,.webp,.pdf" class="d-none">
                            </div>
                            <div id="previewComprobante" class="mt-2 d-none">
                                <div class="d-flex align-items-center gap-2 p-2 rounded-2 border"
                                    style="background:#f8f9fa">
                                    <div id="prevIcono" style="font-size:22px"></div>
                                    <div class="flex-grow-1 overflow-hidden">
                                        <div id="prevNombre" class="small fw-semibold text-truncate"></div>
                                        <div id="prevTamaño" class="text-muted" style="font-size:11px"></div>
                                    </div><button type="button" class="btn btn-sm btn-outline-danger"
                                        onclick="limpiarComprobante()"><i class="fa-solid fa-xmark fa-xs"></i></button>
                                </div>
                                <div id="prevImagen" class="mt-1 d-none"><img id="prevImg" src="" alt="Preview"
                                        class="rounded-2 border"
                                        style="max-height:100px;max-width:100%;object-fit:cover"></div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-top"><button type="button" class="btn btn-outline-secondary"
                    data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-success px-4"
                    id="btnConfirmarPago"><i class="fa-solid fa-circle-check me-1"></i>Confirmar Pago</button></div>
        </div>
    </div>
</div>

<!-- MODAL: Editar Colaborador -->
<div class="modal fade" id="modalEditarColab" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom py-3">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square me-2 text-primary"></i>Editar
                    Colaborador</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form id="formEditarColab"><input type="hidden" name="colaborador_id" value="<?= $id ?>">
                    <div class="row g-3">
                        <div class="col-md-5"><label class="form-label fw-semibold">Nombre <span
                                    class="text-danger">*</span></label><input type="text" name="nombre"
                                class="form-control" value="<?= htmlspecialchars($col['nombre']) ?>" maxlength="100"
                                required></div>
                        <div class="col-md-5"><label class="form-label fw-semibold">Apellido <span
                                    class="text-danger">*</span></label><input type="text" name="apellido"
                                class="form-control" value="<?= htmlspecialchars($col['apellido']) ?>" maxlength="100"
                                required></div>
                        <div class="col-md-2"><label class="form-label fw-semibold">DPI</label><input type="text"
                                name="dpi" class="form-control" value="<?= htmlspecialchars($col['dpi'] ?? '') ?>"
                                maxlength="20"></div>
                        <div class="col-md-4"><label class="form-label fw-semibold">Teléfono</label><input type="text"
                                name="telefono" class="form-control"
                                value="<?= htmlspecialchars($col['telefono'] ?? '') ?>" maxlength="20"></div>
                        <div class="col-md-5"><label class="form-label fw-semibold">Email</label><input type="email"
                                name="email" class="form-control" value="<?= htmlspecialchars($col['email'] ?? '') ?>"
                                maxlength="150"></div>
                        <div class="col-md-3"><label class="form-label fw-semibold">Fecha Ingreso <span
                                    class="text-danger">*</span></label><input type="date" name="fecha_ingreso"
                                class="form-control" value="<?= $col['fecha_ingreso'] ?>" required></div>
                        <div class="col-md-5"><label class="form-label fw-semibold">Puesto <span
                                    class="text-danger">*</span></label><input type="text" name="puesto"
                                class="form-control" value="<?= htmlspecialchars($col['puesto']) ?>" maxlength="150"
                                required></div>
                        <div class="col-md-4"><label class="form-label fw-semibold">Departamento</label><input
                                type="text" name="departamento" class="form-control"
                                value="<?= htmlspecialchars($col['departamento'] ?? '') ?>" maxlength="100"></div>
                        <div class="col-md-3"><label class="form-label fw-semibold">Categoría Gasto</label><select
                                name="categoria_gasto_id" class="form-select">
                                <option value="">— Sin categoría —</option><?php foreach ($categorias as $cat): ?>
                                    <option value="<?= $cat['id'] ?>"
                                        <?= $cat['id'] == ($col['categoria_gasto_id'] ?? 0) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cat['nombre']) ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="col-md-4"><label class="form-label fw-semibold">Salario Bruto <span
                                    class="text-danger">*</span></label>
                            <div class="input-group"><span class="input-group-text">L</span><input type="number"
                                    name="salario_base" class="form-control" min="1" step="0.01"
                                    value="<?= number_format($salario, 2, '.', '') ?>" required></div>
                        </div>
                        <div class="col-md-4"><label class="form-label fw-semibold">Tipo de Pago</label><select
                                name="tipo_pago" id="edit_tipo_pago" class="form-select">
                                <option value="quincenal" <?= $tipo_pago === 'quincenal' ? 'selected' : '' ?>>🔄
                                    Quincenal
                                </option>
                                <option value="mensual" <?= $tipo_pago === 'mensual' ? 'selected' : '' ?>>📅 Mensual
                                </option>
                            </select></div>
                        <div class="col-md-2"><label class="form-label fw-semibold">1er Día</label><input type="number"
                                name="dia_pago" class="form-control" min="1" max="31"
                                value="<?= (int)$col['dia_pago'] ?>"></div>
                        <div class="col-md-2" id="grp_dia2_edit"
                            <?= $tipo_pago !== 'quincenal' ? 'style="display:none"' : '' ?>><label
                                class="form-label fw-semibold">2° Día</label><input type="number" name="dia_pago_2"
                                class="form-control" min="1" max="31" value="<?= (int)$col['dia_pago_2'] ?>"></div>
                        <div class="col-12">
                            <div class="d-flex gap-3">
                                <div class="form-check"><input class="form-check-input" type="checkbox"
                                        name="aplica_ihss" id="edit_ihss" value="1"
                                        <?= $aplica_ihss ? 'checked' : '' ?>><label class="form-check-label"
                                        for="edit_ihss"><span class="badge bg-warning text-dark">IHSS</span></label>
                                </div>
                                <div class="form-check"><input class="form-check-input" type="checkbox"
                                        name="aplica_rap" id="edit_rap" value="1"
                                        <?= $aplica_rap ? 'checked' : '' ?>><label class="form-check-label"
                                        for="edit_rap"><span class="badge bg-info text-dark">RAP</span></label></div>
                            </div>
                        </div>
                        <div class="col-12"><label class="form-label fw-semibold">Notas</label><textarea name="notas"
                                class="form-control" rows="2"
                                maxlength="500"><?= htmlspecialchars($col['notas'] ?? '') ?></textarea></div>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-top"><button type="button" class="btn btn-outline-secondary"
                    data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary px-4"
                    id="btnGuardarEdicion"><i class="fa-solid fa-floppy-disk me-1"></i>Guardar Cambios</button></div>
        </div>
    </div>
</div>

<!-- ══ MODAL: Nuevo Préstamo / Adelanto / Bono / Viático ════════════ -->
<div class="modal fade" id="modalPrestamo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom py-3" style="background:linear-gradient(135deg,#dc3545,#b02a37)">
                <h5 class="modal-title fw-bold text-white"><i class="fa-solid fa-hand-holding-dollar me-2"></i>Registrar
                    Préstamo / Adelanto / Bono / Viático</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form id="formPrestamo">
                    <input type="hidden" name="colaborador_id" value="<?= $id ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Tipo de Movimiento <span
                                class="text-danger">*</span></label>
                        <div class="row g-2">
                            <?php foreach ($tipos_btn_p as $tb): ?>
                                <div class="col-6 col-md">
                                    <input type="radio" class="btn-check" name="tipo" id="tipo_<?= $tb['val'] ?>"
                                        value="<?= $tb['val'] ?>" <?= $tb['val'] === 'prestamo' ? 'checked' : '' ?>>
                                    <label
                                        class="btn btn-outline-<?= $tb['color'] ?> w-100 py-2 h-100 d-flex flex-column align-items-center justify-content-center gap-1"
                                        for="tipo_<?= $tb['val'] ?>">
                                        <i class="fa-solid <?= $tb['icon'] ?> fa-lg"></i>
                                        <span class="fw-bold" style="font-size:11px"><?= $tb['label'] ?></span>
                                        <small class="opacity-75" style="font-size:9px"><?= $tb['desc'] ?></small>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-8"><label class="form-label fw-semibold">Descripción <span
                                    class="text-danger">*</span></label><input type="text" name="descripcion"
                                id="prest_desc" class="form-control" placeholder="Ej: Viático para visita cliente SPS"
                                maxlength="300" required></div>
                        <div class="col-md-4"><label class="form-label fw-semibold">Fecha otorgamiento <span
                                    class="text-danger">*</span></label><input type="date" name="fecha"
                                class="form-control" value="<?= date('Y-m-d') ?>"></div>
                        <div class="col-md-4" id="grp_fecha_primera_cuota"><label class="form-label fw-semibold">Fecha
                                1ª cuota <span class="text-danger">*</span></label><input type="date"
                                name="fecha_primera_cuota" id="prest_fecha1cuota" class="form-control"
                                value="<?= date('Y-m-d') ?>"><small class="text-muted" style="font-size:11px">Desde aquí
                                se calculan las demás cuotas</small></div>
                        <div class="col-md-5"><label class="form-label fw-semibold">Monto Total <span
                                    class="text-danger">*</span></label>
                            <div class="input-group"><span class="input-group-text">L</span><input type="number"
                                    name="monto_total" id="prest_monto" class="form-control" min="1" step="0.01"
                                    placeholder="0.00" required></div>
                        </div>
                        <div class="col-md-3" id="grp_num_cuotas"><label class="form-label fw-semibold">N°
                                Cuotas</label><input type="number" name="num_cuotas" id="prest_cuotas"
                                class="form-control" min="1" max="120" value="1"></div>
                        <div class="col-md-4" id="grp_frecuencia"><label
                                class="form-label fw-semibold">Frecuencia</label><select name="frecuencia_cuota"
                                class="form-select">
                                <option value="mensual">📅 Mensual</option>
                                <option value="quincenal">🔄 Quincenal</option>
                            </select></div>
                        <div class="col-12" id="grp_preview_cuota">
                            <div class="p-2 rounded-2 border" style="background:#f0f7ff;font-size:12.5px"><i
                                    class="fa-solid fa-calculator me-1 text-primary"></i>Cuota aproximada: <strong
                                    id="valorCuota">—</strong></div>
                        </div>
                        <div class="col-12" id="grp_auto">
                            <div class="form-check form-switch"><input class="form-check-input" type="checkbox"
                                    name="descuento_auto" id="prest_auto" value="1"><label class="form-check-label"
                                    for="prest_auto"><i class="fa-solid fa-rotate me-1 text-info"></i><strong>Descontar
                                        automáticamente</strong> al registrar nómina<small class="text-muted d-block"
                                        style="margin-left:28px">La cuota pendiente se restará del neto al procesar
                                        nómina.</small></label></div>
                        </div>
                        <div class="col-12"><label class="form-label fw-semibold">Notas <span
                                    class="text-muted small fw-normal">(opcional)</span></label><textarea name="notas"
                                class="form-control" rows="2" maxlength="500"
                                placeholder="Motivo, condiciones, destino del viático..."></textarea></div>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-top"><button type="button" class="btn btn-outline-secondary"
                    data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-danger px-4"
                    id="btnGuardarPrestamo"><i class="fa-solid fa-circle-check me-1"></i>Guardar</button></div>
        </div>
    </div>
</div>

<!-- MODAL: Pagar cuota -->
<div class="modal fade" id="modalPagarCuota" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-success bg-opacity-10 border-bottom py-3">
                <h6 class="modal-title fw-bold text-success"><i class="fa-solid fa-circle-check me-2"></i>Pagar Cuota
                </h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <p class="mb-3 text-muted small">Cuota <strong id="pagarCuotaNum"></strong> — Monto: <strong
                        class="text-success" id="pagarCuotaMonto"></strong></p>
                <form id="formPagarCuota"><input type="hidden" name="cuota_id" id="pagarCuotaId">
                    <div class="mb-2"><label class="form-label small fw-semibold">Fecha de Pago</label><input
                            type="date" name="fecha_pago" class="form-control form-control-sm"
                            value="<?= date('Y-m-d') ?>"></div>
                    <div class="mb-2"><label class="form-label small fw-semibold">Método</label><select
                            name="metodo_pago" class="form-select form-select-sm">
                            <option value="efectivo">💵 Efectivo</option>
                            <option value="transferencia">🏦 Transferencia</option>
                            <option value="descuento_nomina" selected>🔄 Descuento nómina</option>
                            <option value="cheque">📝 Cheque</option>
                            <option value="otro">🔷 Otro</option>
                        </select></div>
                    <div><label class="form-label small fw-semibold">Notas</label><input type="text" name="notas"
                            class="form-control form-control-sm" placeholder="Referencia..."></div>
                </form>
            </div>
            <div class="modal-footer border-top py-2"><button type="button" class="btn btn-outline-secondary btn-sm"
                    data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-success btn-sm px-3"
                    id="btnConfirmarCuota"><i class="fa-solid fa-check me-1"></i>Confirmar</button></div>
        </div>
    </div>
</div>

<!-- MODAL: Editar Préstamo -->
<div class="modal fade" id="modalEditarPrestamo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom py-3" style="background:linear-gradient(135deg,#dc3545,#b02a37)">
                <h5 class="modal-title fw-bold text-white"><i class="fa-solid fa-pen-to-square me-2"></i>Editar Préstamo
                    / Adelanto / Bono / Viático</h5><button type="button" class="btn-close btn-close-white"
                    data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form id="formEditarPrestamo"><input type="hidden" name="prestamo_id" id="editPrestId">
                    <div class="mb-3"><label class="form-label fw-semibold">Tipo de Movimiento</label>
                        <div class="row g-2"><?php foreach ($tipos_btn_p as $tb): ?><div class="col-6 col-md"><input
                                        type="radio" class="btn-check" name="tipo" id="edit_tipo_<?= $tb['val'] ?>"
                                        value="<?= $tb['val'] ?>"><label
                                        class="btn btn-outline-<?= $tb['color'] ?> w-100 py-2 h-100 d-flex flex-column align-items-center justify-content-center gap-1"
                                        for="edit_tipo_<?= $tb['val'] ?>"><i
                                            class="fa-solid <?= $tb['icon'] ?> fa-lg"></i><span class="fw-bold"
                                            style="font-size:11px"><?= $tb['label'] ?></span><small class="opacity-75"
                                            style="font-size:9px"><?= $tb['desc'] ?></small></label></div>
                            <?php endforeach; ?></div>
                    </div>
                    <div class="mb-3"><label class="form-label fw-semibold">Descripción <span
                                class="text-danger">*</span></label><input type="text" name="descripcion"
                            id="editPrestDesc" class="form-control" maxlength="300" required></div>
                    <div class="row g-3">
                        <div class="col-md-5"><label class="form-label fw-semibold">Fecha de otorgamiento</label><input
                                type="date" name="fecha" id="editPrestFecha" class="form-control"></div>
                        <div class="col-md-7"><label class="form-label fw-semibold">Estado</label><select name="estado"
                                id="editPrestEstado" class="form-select">
                                <option value="activo">✅ Activo</option>
                                <option value="pagado">💚 Pagado (cierre manual)</option>
                                <option value="cancelado">❌ Cancelado</option>
                            </select>
                            <div id="avisoEstado" class="mt-1 p-2 rounded-2 d-none" style="font-size:11.5px"></div>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch"><input class="form-check-input" type="checkbox"
                                    name="descuento_auto" id="editPrestAuto" value="1"><label class="form-check-label"
                                    for="editPrestAuto"><i
                                        class="fa-solid fa-rotate me-1 text-info"></i><strong>Descontar
                                        automáticamente</strong> al registrar nómina</label></div>
                        </div>
                        <div class="col-12"><label class="form-label fw-semibold">Notas</label><textarea name="notas"
                                id="editPrestNotas" class="form-control" rows="2" maxlength="500"></textarea></div>
                    </div>
                    <div class="mt-3 p-2 rounded-2 border" style="background:#fff8f0;font-size:11.5px;color:#856404"><i
                            class="fa-solid fa-triangle-exclamation me-1"></i>El monto total y cuotas no son editables.
                    </div>
                </form>
            </div>
            <div class="modal-footer border-top"><button type="button" class="btn btn-outline-secondary"
                    data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary px-4"
                    id="btnGuardarEditPrest"><i class="fa-solid fa-floppy-disk me-1"></i>Guardar Cambios</button></div>
        </div>
    </div>
</div>

<!-- MODAL: Editar Cuota -->
<div class="modal fade" id="modalEditarCuota" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom py-3" style="background:linear-gradient(135deg,#6f42c1,#0d6efd)">
                <h6 class="modal-title fw-bold text-white"><i class="fa-solid fa-pen-to-square me-2"></i>Editar Cuota
                    <span id="editCuotaTitulo" class="opacity-75"></span>
                </h6><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div id="alertaReversion" class="alert alert-warning py-2 d-none" style="font-size:12px"><i
                        class="fa-solid fa-triangle-exclamation me-1"></i><strong>Reversión:</strong> cambiar a
                    Pendiente aumentará el saldo del préstamo.</div>
                <form id="formEditarCuota"><input type="hidden" name="cuota_id" id="editCuotaId">
                    <div class="mb-2"><label class="form-label small fw-semibold">Estado</label><select name="estado"
                            id="editCuotaEstado" class="form-select form-select-sm">
                            <option value="pendiente">⏳ Pendiente</option>
                            <option value="pagado">✅ Pagado</option>
                            <option value="cancelado">❌ Cancelado</option>
                        </select></div>
                    <div class="mb-2"><label class="form-label small fw-semibold">Fecha esperada</label><input
                            type="date" name="fecha_esperada" id="editCuotaFechaEsp"
                            class="form-control form-control-sm"></div>
                    <div id="grpPagoEdit">
                        <div class="mb-2"><label class="form-label small fw-semibold">Fecha de pago</label><input
                                type="date" name="fecha_pago" id="editCuotaFechaPago"
                                class="form-control form-control-sm"></div>
                        <div class="mb-2"><label class="form-label small fw-semibold">Método</label><select
                                name="metodo_pago" id="editCuotaMetodo" class="form-select form-select-sm">
                                <option value="efectivo">💵 Efectivo</option>
                                <option value="transferencia">🏦 Transferencia</option>
                                <option value="descuento_nomina">🔄 Descuento nómina</option>
                                <option value="cheque">📝 Cheque</option>
                                <option value="otro">🔷 Otro</option>
                            </select></div>
                    </div>
                    <div><label class="form-label small fw-semibold">Notas</label><input type="text" name="notas"
                            id="editCuotaNotas" class="form-control form-control-sm"></div>
                </form>
            </div>
            <div class="modal-footer border-top py-2"><button type="button" class="btn btn-outline-secondary btn-sm"
                    data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary btn-sm px-3"
                    id="btnGuardarEditCuota"><i class="fa-solid fa-floppy-disk me-1"></i>Guardar</button></div>
        </div>
    </div>
</div>

<script>
    $(function() {

        <?php if (!empty($pagos)): ?>
            $('#tablaPagos').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json'
                },
                order: [
                    [0, 'desc']
                ],
                pageLength: 25,
                columnDefs: [{
                    orderable: false,
                    targets: [6]
                }]
            });
        <?php endif; ?>

        $('#edit_tipo_pago').on('change', function() {
            $('#grp_dia2_edit').toggle($(this).val() === 'quincenal');
        });
        $(document).on('click', '.btn-editar-colab', function() {
            $('#modalEditarColab').modal('show');
        });
        $('#btnGuardarEdicion').on('click', function() {
            var btn = $(this);
            btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-1"></i>Guardando...');
            $.post('includes/colaborador_actualizar.php', $('#formEditarColab').serialize()).done(function(
                d) {
                if (d.success) {
                    Swal.fire({
                        icon: 'success',
                        title: '¡Listo!',
                        text: d.message,
                        timer: 1600,
                        showConfirmButton: false
                    }).then(() => location.reload());
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: d.error
                    });
                    btn.prop('disabled', false).html(
                        '<i class="fa-solid fa-floppy-disk me-1"></i>Guardar Cambios');
                }
            }).fail(function() {
                Swal.fire({
                    icon: 'error',
                    title: 'Error de conexión'
                });
                btn.prop('disabled', false).html(
                    '<i class="fa-solid fa-floppy-disk me-1"></i>Guardar Cambios');
            });
        });

        var _netoPago = <?= number_format($neto_quincena, 4, '.', '') ?>,
            _diaPago1 = <?= (int)$col['dia_pago'] ?>,
            _diaPago2 = <?= (int)($col['dia_pago_2'] ?? 0) ?>,
            _tipoPago = '<?= $col['tipo_pago'] ?>';

        function numberFmt(n) {
            return parseFloat(n).toLocaleString('es-HN', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function recalcular() {
            var desc = 0,
                bonos = 0,
                viats = 0;
            $('.cuota-chk:checked').each(function() {
                desc += parseFloat($(this).data('monto')) || 0;
            });
            desc = Math.min(desc, _netoPago);
            $('#listaBonosChk .bono-chk:checked').each(function() {
                bonos += parseFloat($(this).data('monto')) || 0;
            });
            $('#listaViaticosChk .viatico-chk:checked').each(function() {
                viats += parseFloat($(this).data('monto')) || 0;
            });
            var aPagar = Math.max(0, _netoPago - desc + bonos + viats);
            $('#lblTotalDescuento').text('-L ' + numberFmt(desc));
            $('#lblTotalBonosPago').text('+L ' + numberFmt(bonos));
            $('#lblTotalViaticos').text('+L ' + numberFmt(viats));
            $('#lblApagarModal').text('L ' + numberFmt(aPagar));
            $('#lblResumenFinal').text('L ' + numberFmt(aPagar));
            var hayMov = desc > 0 || bonos > 0 || viats > 0;
            if (desc > 0) {
                $('#lblNetoModal').addClass('text-muted text-decoration-line-through').removeClass('text-success');
                $('#colDescModal').show().find('#lblDescModal').text('L ' + numberFmt(desc));
            } else {
                $('#lblNetoModal').removeClass('text-muted text-decoration-line-through').addClass('text-success');
                $('#colDescModal').addClass('d-none');
            }
            if (bonos > 0) {
                $('#colBonoModal').show().find('#lblBonoModal').text('L ' + numberFmt(bonos));
            } else {
                $('#colBonoModal').addClass('d-none');
            }
            if (viats > 0) {
                $('#colViaModal').show().find('#lblViaModal').text('L ' + numberFmt(viats));
            } else {
                $('#colViaModal').addClass('d-none');
            }
            if (hayMov) {
                $('#colApagarSolo').hide();
                $('#colApagarModal,#resumenFinalPago').removeClass('d-none').show();
            } else {
                $('#colApagarSolo').show();
                $('#colApagarModal,#resumenFinalPago').addClass('d-none');
            }
        }
        $(document).on('change', '.cuota-chk', recalcular);
        $(document).on('change', '.bono-chk', recalcular);
        $(document).on('change', '.viatico-chk', recalcular);

        function verificarVencimientoPago() {
            var fechaVal = $('#pago_fecha').val();
            if (!fechaVal) {
                $('#estadoPagoFecha').html('');
                return;
            }
            var fecha = new Date(fechaVal + 'T00:00:00'),
                anio = fecha.getFullYear(),
                mes = fecha.getMonth();
            var diaProg = _tipoPago === 'quincenal' ? ($('[name=quincena]:checked').val() == 2 ? _diaPago2 :
                _diaPago1) : _diaPago1;
            var diasMes = new Date(anio, mes + 1, 0).getDate(),
                diaAjust = Math.min(diaProg, diasMes);
            var fechaProg = new Date(anio, mes, diaAjust),
                diff = Math.round((fecha - fechaProg) / 86400000);
            if (diff > 0) $('#estadoPagoFecha').html(
                '<span class="badge bg-danger bg-opacity-15 border border-danger border-opacity-25"><i class="fa-solid fa-clock-rotate-left me-1"></i>Vencido <strong>' +
                diff + ' día(s)</strong> — programado día ' + diaAjust + '</span>');
            else if (diff === 0) $('#estadoPagoFecha').html(
                '<span class="badge bg-success bg-opacity-15 text-success border border-success border-opacity-25"><i class="fa-solid fa-circle-check me-1"></i>En fecha programada</span>'
            );
            else $('#estadoPagoFecha').html(
                '<span class="badge bg-info bg-opacity-15 text-info border border-info border-opacity-25"><i class="fa-solid fa-calendar-check me-1"></i>Adelantado ' +
                Math.abs(diff) + ' día(s)</span>');
        }
        $('#pago_fecha,[name=quincena]').on('change', verificarVencimientoPago);

        $(document).on('click', '.btn-pagar-directo', function() {
            limpiarComprobante();
            $('#estadoPagoFecha').html('');
            $('#pago_fecha').val(new Date().toISOString().slice(0, 10));
            $('#listaBonosChk,#listaViaticosChk').html('');
            $('#boxBonosAplicar,#boxViaticosAplicar').addClass('d-none');
            $('#colDescModal,#colBonoModal,#colViaModal,#colApagarModal,#resumenFinalPago').addClass(
                'd-none');
            $('#colApagarSolo').show();
            recalcular();
            $('#modalPago').modal('show');
            setTimeout(verificarVencimientoPago, 150);
            $('#pagoLoadingCuotas').removeClass('d-none');
            $.getJSON('includes/colaborador_cuotas_info.php', {
                colab_id: <?= $id ?>
            }).done(function(data) {
                $('#pagoLoadingCuotas').addClass('d-none');
                if (!data.success) return;
                if (data.bonos && data.bonos.length > 0) {
                    var html = '';
                    $.each(data.bonos, function(_, b) {
                        var m = parseFloat(b.monto_total);
                        html +=
                            '<div class="d-flex justify-content-between align-items-center mt-1 gap-2"><div class="form-check mb-0 d-flex align-items-center gap-2 flex-grow-1"><input class="form-check-input bono-chk mt-0" type="checkbox" name="bonos_ids[]" value="' +
                            b.id + '" data-monto="' + m +
                            '" checked><label class="form-check-label text-muted" style="cursor:pointer;font-size:11px">' +
                            b.descripcion.substring(0, 38) +
                            '</label></div><span class="fw-bold text-success text-nowrap" style="font-size:11px">+L ' +
                            m.toLocaleString('es-HN', {
                                minimumFractionDigits: 2
                            }) + '</span></div>';
                    });
                    $('#listaBonosChk').html(html);
                    $('#boxBonosAplicar').removeClass('d-none');
                    recalcular();
                }
                if (data.viaticos && data.viaticos.length > 0) {
                    var htmlV = '';
                    $.each(data.viaticos, function(_, v) {
                        var m = parseFloat(v.monto_total);
                        htmlV +=
                            '<div class="d-flex justify-content-between align-items-center mt-1 gap-2"><div class="form-check mb-0 d-flex align-items-center gap-2 flex-grow-1"><input class="form-check-input viatico-chk mt-0" type="checkbox" name="viaticos_ids[]" value="' +
                            v.id + '" data-monto="' + m +
                            '" checked><label class="form-check-label text-muted" style="cursor:pointer;font-size:11px"><i class="fa-solid fa-plane-departure fa-xs me-1" style="color:#0369a1"></i>' +
                            v.descripcion.substring(0, 34) +
                            '</label></div><span class="fw-bold text-nowrap" style="font-size:11px;color:#0369a1">+L ' +
                            m.toLocaleString('es-HN', {
                                minimumFractionDigits: 2
                            }) + '</span></div>';
                    });
                    $('#listaViaticosChk').html(htmlV);
                    $('#boxViaticosAplicar').removeClass('d-none');
                    recalcular();
                }
            }).fail(function() {
                $('#pagoLoadingCuotas').addClass('d-none');
            });
        });

        $('#btnConfirmarPago').on('click', function() {
            var btn = $(this);
            btn.prop('disabled', true).html(
                '<i class="fa-solid fa-spinner fa-spin me-1"></i>Registrando...');
            var fd = new FormData(document.getElementById('formPago'));
            var archivo = document.getElementById('pago_comprobante').files[0];
            if (archivo) fd.set('comprobante', archivo);
            $.ajax({
                url: 'includes/colaborador_pago_guardar.php',
                type: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                dataType: 'json'
            }).done(function(d) {
                if (d.success) {
                    $('#modalPago').modal('hide');
                    var extras = '';
                    if (d.cuotas_pagadas > 0) extras += '<br><small class="text-warning">✂️ ' + d
                        .cuotas_pagadas + ' cuota(s) descontada(s)</small>';
                    if (d.bonos_aplicados > 0) extras += '<br><small class="text-success">🎁 ' + d
                        .bonos_aplicados + ' bono(s) aplicado(s)</small>';
                    if (d.viaticos_aplicados > 0) extras += '<br><small style="color:#0369a1">✈️ ' +
                        d.viaticos_aplicados + ' viático(s) aplicado(s)</small>';
                    Swal.fire({
                        icon: 'success',
                        title: '¡Pago registrado!',
                        html: d.message + extras + '<br><br><a href="' + (d.recibo_url ||
                                '#') +
                            '" target="_blank" class="btn btn-sm btn-outline-danger mt-1"><i class="fa-solid fa-file-pdf me-1"></i>Ver / Imprimir Recibo</a>',
                        showConfirmButton: true,
                        confirmButtonText: 'Cerrar',
                        confirmButtonColor: '#6c757d'
                    }).then(() => location.reload());
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: d.error
                    });
                    btn.prop('disabled', false).html(
                        '<i class="fa-solid fa-circle-check me-1"></i>Confirmar Pago');
                }
            }).fail(function() {
                Swal.fire({
                    icon: 'error',
                    title: 'Error de conexión'
                });
                btn.prop('disabled', false).html(
                    '<i class="fa-solid fa-circle-check me-1"></i>Confirmar Pago');
            });
        });
        $('#pago_comprobante').on('change', function() {
            if (this.files && this.files[0]) mostrarPreviewComprobante(this.files[0]);
        });

        // Prestamos
        $('#btnNuevoPrestamo,#btnNuevoPrestamo2').on('click', function() {
            $('#formPrestamo')[0].reset();
            $('[name=tipo][value=prestamo]').prop('checked', true).trigger('change');
            calcularCuota();
            $('#modalPrestamo').modal('show');
        });
        $('[name=tipo]').on('change', function() {
            var tipo = $(this).val();
            // Prestamo: cuotas, auto-descuento, fecha 1ra cuota
            $('#grp_num_cuotas,#grp_frecuencia,#grp_preview_cuota').toggle(tipo === 'prestamo');
            // Sin cuotas: adelanto, bono, viatico, multa
            $('#grp_fecha_primera_cuota').toggle(tipo === 'prestamo' || tipo === 'adelanto' || tipo ===
                'multa');
            // Auto descuento solo para préstamo, adelanto, multa
            $('#grp_auto').toggle(tipo !== 'bono' && tipo !== 'viatico');
            if (tipo !== 'prestamo') $('#prest_cuotas').val(1);
            var sugs = {
                prestamo: 'Préstamo a colaborador',
                adelanto: 'Adelanto de sueldo',
                bono: 'Bono/Gratificación',
                viatico: 'Viático — destino o motivo del viaje',
                multa: 'Multa/Descuento'
            };
            if (!$('#prest_desc').val()) $('#prest_desc').attr('placeholder', sugs[tipo] || '');
            calcularCuota();
        });
        $('#prest_monto,#prest_cuotas').on('input', calcularCuota);

        function calcularCuota() {
            var m = parseFloat($('#prest_monto').val()) || 0,
                c = parseInt($('#prest_cuotas').val()) || 1;
            $('#valorCuota').text(m > 0 ? 'L ' + (m / c).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',') : '—');
        }
        $('#btnGuardarPrestamo').on('click', function() {
            var btn = $(this);
            if (!$('#prest_monto').val() || !$('#prest_desc').val()) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Datos incompletos',
                    text: 'Completa descripción y monto.',
                    timer: 2000,
                    showConfirmButton: false
                });
                return;
            }
            btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-1"></i>Guardando...');
            $.post('includes/prestamo_guardar.php', $('#formPrestamo').serialize()).done(function(d) {
                if (d.success) {
                    $('#modalPrestamo').modal('hide');
                    Swal.fire({
                        icon: 'success',
                        title: '¡Listo!',
                        html: d.message,
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => location.reload());
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: d.error
                    });
                    btn.prop('disabled', false).html(
                        '<i class="fa-solid fa-circle-check me-1"></i>Guardar');
                }
            }).fail(function() {
                Swal.fire({
                    icon: 'error',
                    title: 'Error de conexión'
                });
                btn.prop('disabled', false).html(
                    '<i class="fa-solid fa-circle-check me-1"></i>Guardar');
            });
        });

        // Pagar cuota
        $(document).on('click', '.btn-pagar-cuota', function() {
            $('#pagarCuotaId').val($(this).data('cuota-id'));
            $('#pagarCuotaNum').text('#' + $(this).data('cuota-num'));
            $('#pagarCuotaMonto').text('L ' + $(this).data('monto'));
            $('#formPagarCuota input[name=notas]').val('');
            $('#modalPagarCuota').modal('show');
        });
        $('#btnConfirmarCuota').on('click', function() {
            var btn = $(this);
            btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-1"></i>');
            $.post('includes/prestamo_cuota_pagar.php', $('#formPagarCuota').serialize()).done(function(d) {
                if (d.success) {
                    $('#modalPagarCuota').modal('hide');
                    Swal.fire({
                        icon: 'success',
                        title: '¡Pagado!',
                        html: d.message + (d.prestamo_pagado ?
                            '<br><small class="text-success">🎉 ¡Préstamo completamente pagado!</small>' :
                            ''),
                        timer: 2200,
                        showConfirmButton: false
                    }).then(() => location.reload());
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: d.error
                    });
                    btn.prop('disabled', false).html(
                        '<i class="fa-solid fa-check me-1"></i>Confirmar');
                }
            }).fail(function() {
                Swal.fire({
                    icon: 'error',
                    title: 'Error de conexión'
                });
                btn.prop('disabled', false).html('<i class="fa-solid fa-check me-1"></i>Confirmar');
            });
        });

        // Cancelar / Eliminar préstamo
        $(document).on('click', '.btn-cancelar-prestamo', function() {
            var pid = $(this).data('prestamo-id'),
                desc = $(this).data('desc');
            Swal.fire({
                icon: 'warning',
                title: '¿Cancelar?',
                html: '<strong>' + desc +
                    '</strong><br><small class="text-muted">Las cuotas pendientes quedarán canceladas.</small>',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Sí, cancelar',
                cancelButtonText: 'No'
            }).then(function(r) {
                if (r.isConfirmed) $.post('includes/prestamo_cancelar.php', {
                    prestamo_id: pid
                }).done(function(d) {
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
        });
        $(document).on('click', '.btn-eliminar-prestamo', function() {
            var pid = $(this).data('prestamo-id'),
                desc = $(this).data('desc');
            Swal.fire({
                icon: 'error',
                title: '¿Eliminar definitivamente?',
                html: '<strong>' + desc +
                    '</strong><br><small class="text-danger">Se eliminarán el préstamo y todas sus cuotas. <u>No se puede deshacer</u>.</small>',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'No'
            }).then(function(r) {
                if (r.isConfirmed) $.post('includes/prestamo_eliminar.php', {
                    prestamo_id: pid
                }).done(function(d) {
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
        });

        $('[name=tipo][value=prestamo]').trigger('change');

        // Editar Préstamo
        var avisos_estado = {
            pagado: {
                bg: '#d1e7dd',
                color: '#0a3622',
                ico: 'fa-circle-check',
                txt: 'Las cuotas pendientes se marcarán pagadas y el saldo quedará en 0.'
            },
            cancelado: {
                bg: '#f8d7da',
                color: '#58151c',
                ico: 'fa-ban',
                txt: 'Las cuotas pendientes quedarán canceladas.'
            },
            activo: null
        };
        $(document).on('click', '.btn-editar-prestamo', function() {
            var btn = $(this);
            $('#editPrestId').val(btn.data('prestamo-id'));
            $('#editPrestDesc').val(btn.data('descripcion'));
            $('#editPrestFecha').val(btn.data('fecha'));
            $('#editPrestNotas').val(btn.data('notas'));
            $('#editPrestAuto').prop('checked', btn.data('descuento-auto') == 1);
            $('#editPrestEstado').val(btn.data('estado')).trigger('change');
            $('#modalEditarPrestamo').modal('show');
        });
        $('#editPrestEstado').on('change', function() {
            var est = $(this).val(),
                av = avisos_estado[est];
            if (av) {
                $('#avisoEstado').html('<i class="fa-solid ' + av.ico + ' me-1"></i>' + av.txt).css({
                    background: av.bg,
                    color: av.color,
                    border: '1px solid ' + av.color + '40'
                }).removeClass('d-none');
            } else {
                $('#avisoEstado').addClass('d-none');
            }
        });
        $('#btnGuardarEditPrest').on('click', function() {
            var btn = $(this),
                estado = $('#editPrestEstado').val();
            if (estado === 'cancelado' || estado === 'pagado') {
                Swal.fire({
                    icon: 'warning',
                    title: estado === 'cancelado' ? '¿Cancelar?' : '¿Marcar como pagado?',
                    html: estado === 'cancelado' ?
                        'Las cuotas pendientes quedarán <strong>canceladas</strong>.' : 'Las cuotas pendientes se cerrarán.',
                    showCancelButton: true,
                    confirmButtonColor: estado === 'cancelado' ? '#dc3545' : '#198754',
                    confirmButtonText: 'Sí, continuar',
                    cancelButtonText: 'No'
                }).then(function(r) {
                    if (r.isConfirmed) guardarEditPrest(btn);
                });
            } else guardarEditPrest(btn);
        });

        function guardarEditPrest(btn) {
            btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-1"></i>Guardando...');
            $.post('includes/prestamo_editar.php', $('#formEditarPrestamo').serialize()).done(function(d) {
                if (d.success) {
                    $('#modalEditarPrestamo').modal('hide');
                    Swal.fire({
                        icon: 'success',
                        title: '¡Listo!',
                        text: d.message,
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => location.reload());
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: d.error
                    });
                    btn.prop('disabled', false).html(
                        '<i class="fa-solid fa-floppy-disk me-1"></i>Guardar Cambios');
                }
            }).fail(function() {
                Swal.fire({
                    icon: 'error',
                    title: 'Error de conexión'
                });
                btn.prop('disabled', false).html(
                    '<i class="fa-solid fa-floppy-disk me-1"></i>Guardar Cambios');
            });
        }

        // Editar Cuota
        $(document).on('click', '.btn-editar-cuota', function() {
            var btn = $(this);
            $('#editCuotaId').val(btn.data('cuota-id'));
            $('#editCuotaTitulo').text('#' + btn.data('cuota-num') + ' — L ' + btn.data('monto'));
            $('#editCuotaEstado').val(btn.data('estado')).trigger('change');
            $('#editCuotaFechaEsp').val(btn.data('fecha-esperada'));
            $('#editCuotaFechaPago').val(btn.data('fecha-pago') || '');
            $('#editCuotaMetodo').val(btn.data('metodo') || 'descuento_nomina');
            $('#editCuotaNotas').val(btn.data('notas') || '');
            $('#alertaReversion').addClass('d-none');
            $('#modalEditarCuota').modal('show');
        });
        $('#editCuotaEstado').on('change', function() {
            var est = $(this).val();
            if (est === 'pendiente') $('#alertaReversion').removeClass('d-none');
            else $('#alertaReversion').addClass('d-none');
            $('#grpPagoEdit').toggle(est === 'pagado');
            if (est === 'pagado' && !$('#editCuotaFechaPago').val()) $('#editCuotaFechaPago').val(new Date()
                .toISOString().slice(0, 10));
        });
        $('#btnGuardarEditCuota').on('click', function() {
            var btn = $(this),
                estado = $('#editCuotaEstado').val();
            if (estado === 'pendiente') {
                Swal.fire({
                    icon: 'warning',
                    title: '¿Revertir este pago?',
                    html: 'El saldo del préstamo aumentará en el monto de esta cuota.',
                    showCancelButton: true,
                    confirmButtonColor: '#fd7e14',
                    confirmButtonText: 'Sí, revertir',
                    cancelButtonText: 'No'
                }).then(function(r) {
                    if (r.isConfirmed) guardarEditCuota(btn);
                });
            } else guardarEditCuota(btn);
        });

        function guardarEditCuota(btn) {
            btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-1"></i>');
            $.post('includes/prestamo_cuota_editar.php', $('#formEditarCuota').serialize()).done(function(d) {
                if (d.success) {
                    $('#modalEditarCuota').modal('hide');
                    Swal.fire({
                        icon: d.pago_revertido ? 'info' : 'success',
                        title: '¡Listo!',
                        html: d.message,
                        timer: 2200,
                        showConfirmButton: false
                    }).then(() => location.reload());
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: d.error
                    });
                    btn.prop('disabled', false).html('<i class="fa-solid fa-floppy-disk me-1"></i>Guardar');
                }
            }).fail(function() {
                Swal.fire({
                    icon: 'error',
                    title: 'Error de conexión'
                });
                btn.prop('disabled', false).html('<i class="fa-solid fa-floppy-disk me-1"></i>Guardar');
            });
        }
        $('#modalEditarCuota').on('show.bs.modal', function() {
            $('#editCuotaEstado').trigger('change');
        });
    });

    function mostrarPreviewComprobante(file) {
        var esPdf = file.type === 'application/pdf';
        var tam = file.size < 1048576 ? (file.size / 1024).toFixed(1) + ' KB' : (file.size / 1048576).toFixed(2) + ' MB';
        $('#prevIcono').html(esPdf ? '<i class="fa-solid fa-file-pdf fa-xl text-danger"></i>' :
            '<i class="fa-solid fa-image fa-xl text-primary"></i>');
        $('#prevNombre').text(file.name);
        $('#prevTamaño').text(tam + ' · ' + (esPdf ? 'PDF' : 'Imagen'));
        if (!esPdf) {
            var reader = new FileReader();
            reader.onload = function(e) {
                $('#prevImg').attr('src', e.target.result);
                $('#prevImagen').removeClass('d-none');
            };
            reader.readAsDataURL(file);
        } else {
            $('#prevImagen').addClass('d-none');
        }
        $('#previewComprobante').removeClass('d-none');
        $('#zonaComprobante').css({
            borderColor: '#198754',
            background: '#f0fff4'
        });
    }

    function limpiarComprobante() {
        document.getElementById('pago_comprobante').value = '';
        $('#previewComprobante').addClass('d-none');
        $('#prevImagen').addClass('d-none');
        $('#prevImg').attr('src', '');
        $('#zonaComprobante').css({
            borderColor: '',
            background: ''
        });
    }

    function handleDrop(event) {
        event.preventDefault();
        document.getElementById('zonaComprobante').style.borderColor = '';
        document.getElementById('zonaComprobante').style.background = '';
        var file = event.dataTransfer.files[0];
        if (!file) return;
        if (['image/jpeg', 'image/png', 'image/webp', 'application/pdf'].indexOf(file.type) === -1) {
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
        var dt = new DataTransfer();
        dt.items.add(file);
        document.getElementById('pago_comprobante').files = dt.files;
        mostrarPreviewComprobante(file);
    }
</script>

<?php require_once '../../includes/templates/footer.php'; ?>