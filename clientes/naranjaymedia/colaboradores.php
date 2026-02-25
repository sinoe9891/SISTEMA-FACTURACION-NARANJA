<?php
$titulo = 'Colaboradores';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';
require_once '../../includes/templates/header.php';

$cliente_id = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);

// Tasas Honduras
define('IHSS_EMP', 0.035);
define('IHSS_PAT', 0.07);
define('RAP_EMP',  0.015);
define('RAP_PAT',  0.015);
define('IHSS_TOPE', 10294.10);

$filtro_estado = $_GET['estado'] ?? 'activo';

// ── KPIs ──────────────────────────────────────────────────────────────────────
$stmtKpi = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN activo=1 THEN 1 ELSE 0 END) AS activos,
        SUM(CASE WHEN activo=0 THEN 1 ELSE 0 END) AS inactivos,
        SUM(CASE WHEN activo=1 THEN salario_base ELSE 0 END) AS masa_salarial,
        SUM(CASE WHEN activo=1 AND tipo_pago='quincenal' THEN 1 ELSE 0 END) AS quincenales,
        SUM(CASE WHEN activo=1 AND tipo_pago='mensual'   THEN 1 ELSE 0 END) AS mensuales
    FROM colaboradores WHERE cliente_id=?
");
$stmtKpi->execute([$cliente_id]);
$kpi = $stmtKpi->fetch(PDO::FETCH_ASSOC);

// Calcular costo total patronal mensual
$stmtPat = $pdo->prepare("
    SELECT salario_base, aplica_ihss, aplica_rap
    FROM colaboradores WHERE cliente_id=? AND activo=1
");
$stmtPat->execute([$cliente_id]);
$costo_patronal = 0;
foreach ($stmtPat->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $base = min((float)$row['salario_base'], IHSS_TOPE);
    if ($row['aplica_ihss']) $costo_patronal += round($base * IHSS_PAT, 2);
    if ($row['aplica_rap'])  $costo_patronal += round((float)$row['salario_base'] * RAP_PAT, 2);
}

// ── Lista de colaboradores ────────────────────────────────────────────────────
$where_estado = ($filtro_estado === 'inactivo') ? 'c.activo=0' : 'c.activo=1';
$stmtC = $pdo->prepare("
    SELECT c.*, cg.nombre AS cat_nombre, cg.color AS cat_color, cg.icono AS cat_icono
    FROM colaboradores c
    LEFT JOIN categorias_gastos cg ON cg.id = c.categoria_gasto_id
    WHERE c.cliente_id=? AND $where_estado
    ORDER BY c.nombre ASC, c.apellido ASC
");
$stmtC->execute([$cliente_id]);
$colaboradores = $stmtC->fetchAll(PDO::FETCH_ASSOC);

$stmtPagos = $pdo->prepare("
    SELECT descripcion, estado, quincena_num
    FROM gastos
    WHERE cliente_id=?
      AND fecha BETWEEN DATE_FORMAT(CURDATE(),'%Y-%m-01') AND LAST_DAY(CURDATE())
      AND descripcion LIKE 'Sueldo %'
");
$stmtPagos->execute([$cliente_id]);
$pagos_mes = $stmtPagos->fetchAll(PDO::FETCH_ASSOC);

// ── Pagos de nómina vencidos (programados pero no pagados aún) ───────────────
// Colaboradores quincenales: si hoy > día de pago y no hay gasto 'pagado' ese mes
$hoy_dia = (int)date('j');
$hoy_mes = date('Y-m');

$vencidos_nomina = [];
$proximos_nomina = [];

foreach ($colaboradores as $col) {
    $nc = $col['nombre'] . ' ' . $col['apellido'];

    if ($col['tipo_pago'] === 'quincenal') {
        foreach (
            [
                1 => ['dia' => (int)$col['dia_pago'],   'label' => '1ª Quincena'],
                2 => ['dia' => (int)$col['dia_pago_2'],  'label' => '2ª Quincena'],
            ] as $q => $info
        ) {
            $estado = estadoPago($pagos_mes, $nc, $q);
            $dias_diff = $hoy_dia - $info['dia'];

            if ($estado === null) { // No hay registro este mes
                if ($dias_diff > 0) {
                    $vencidos_nomina[] = array_merge($col, [
                        'quincena_num'  => $q,
                        'quincena_label' => $info['label'],
                        'dia_programado' => $info['dia'],
                        'dias_atraso'   => $dias_diff,
                        'monto_pago'    => calcNeto((float)$col['salario_base'], (int)$col['aplica_ihss'], (int)$col['aplica_rap'], 'quincenal')['neto_pago'],
                    ]);
                } elseif ($dias_diff >= -7) { // Próximos 7 días
                    $proximos_nomina[] = array_merge($col, [
                        'quincena_num'   => $q,
                        'quincena_label' => $info['label'],
                        'dia_programado' => $info['dia'],
                        'dias_restantes' => abs($dias_diff),
                        'monto_pago'     => calcNeto((float)$col['salario_base'], (int)$col['aplica_ihss'], (int)$col['aplica_rap'], 'quincenal')['neto_pago'],
                    ]);
                }
            }
        }
    } else { // mensual
        $estado    = estadoPago($pagos_mes, $nc, 0);
        $dia_pago  = (int)$col['dia_pago'];
        $dias_diff = $hoy_dia - $dia_pago;

        if ($estado === null) {
            if ($dias_diff > 0) {
                $vencidos_nomina[] = array_merge($col, [
                    'quincena_num'   => 0,
                    'quincena_label' => 'Mensual',
                    'dia_programado' => $dia_pago,
                    'dias_atraso'    => $dias_diff,
                    'monto_pago'     => calcNeto((float)$col['salario_base'], (int)$col['aplica_ihss'], (int)$col['aplica_rap'], 'mensual')['neto_pago'],
                ]);
            } elseif ($dias_diff >= -7) {
                $proximos_nomina[] = array_merge($col, [
                    'quincena_num'   => 0,
                    'quincena_label' => 'Mensual',
                    'dia_programado' => $dia_pago,
                    'dias_restantes' => abs($dias_diff),
                    'monto_pago'     => calcNeto((float)$col['salario_base'], (int)$col['aplica_ihss'], (int)$col['aplica_rap'], 'mensual')['neto_pago'],
                ]);
            }
        }
    }
}

// Ordenar vencidos por días de atraso DESC
usort($vencidos_nomina, fn($a, $b) => $b['dias_atraso'] - $a['dias_atraso']);
usort($proximos_nomina, fn($a, $b) => $a['dias_restantes'] - $b['dias_restantes']);




function estadoPago(array $pagos, string $nombre, int $q = 0): ?string
{
    $base = 'Sueldo ' . $nombre;
    foreach ($pagos as $p) {
        if ($q === 0 && strpos($p['descripcion'], $base) === 0) return $p['estado'];
        if ($q === 1 && $p['descripcion'] === $base . ' — 1ª Quincena') return $p['estado'];
        if ($q === 2 && $p['descripcion'] === $base . ' — 2ª Quincena') return $p['estado'];
    }
    return null;
}



// ── Categorías para el select ─────────────────────────────────────────────────
$stmtCats = $pdo->prepare("SELECT id, nombre, color, icono FROM categorias_gastos WHERE cliente_id=? AND activa=1 ORDER BY nombre");
$stmtCats->execute([$cliente_id]);
$categorias = $stmtCats->fetchAll(PDO::FETCH_ASSOC);

// Función de cálculo neto
function calcNeto(float $salario, int $ihss, int $rap, string $tipo): array
{
    $base_ihss   = min($salario, IHSS_TOPE);
    $ihss_emp    = $ihss ? round($base_ihss * IHSS_EMP, 2) : 0;
    $rap_emp     = $rap  ? round($salario   * RAP_EMP,  2) : 0;
    $neto_mes    = $salario - $ihss_emp - $rap_emp;
    $ihss_pat    = $ihss ? round($base_ihss * IHSS_PAT, 2) : 0;
    $rap_pat     = $rap  ? round($salario   * RAP_PAT,  2) : 0;
    $div         = ($tipo === 'quincenal') ? 2 : 1;
    return [
        'bruto_pago'   => round($salario   / $div, 2),
        'ihss_emp'     => round($ihss_emp  / $div, 2),
        'rap_emp'      => round($rap_emp   / $div, 2),
        'neto_pago'    => round($neto_mes  / $div, 2),
        'ihss_pat'     => round($ihss_pat  / $div, 2),
        'rap_pat'      => round($rap_pat   / $div, 2),
        'costo_total'  => round(($neto_mes + $ihss_pat + $rap_pat) / $div, 2),
    ];
}

?>
<div class="container-xxl mt-4">

    <!-- Encabezado -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h4 class="mb-0"><i class="fa-solid fa-users me-2 text-primary"></i>Colaboradores</h4>
            <small class="text-muted">Gestión de nómina y pagos de personal</small>
        </div>
        <button class="btn btn-primary" id="btnNuevoColab">
            <i class="fa-solid fa-user-plus me-1"></i> Nuevo Colaborador
        </button>
    </div>
    <!-- 🔴 Nóminas Vencidas -->
    <?php if (!empty($vencidos_nomina)): ?>
        <div class="card border-danger border-2 shadow-sm mb-4">
            <div class="card-header bg-danger bg-opacity-10 border-bottom border-danger py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold text-danger">
                    <i class="fa-solid fa-circle-exclamation me-2"></i>Nóminas Vencidas — Sin Registrar
                </h6>
                <span class="badge bg-danger"><?= count($vencidos_nomina) ?></span>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Colaborador</th>
                            <th class="text-center">Pago</th>
                            <th class="text-center">Día programado</th>
                            <th class="text-center">Días atraso</th>
                            <th class="text-end">Monto neto</th>
                            <th class="text-center">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($vencidos_nomina as $vn): ?>
                            <tr class="table-danger">
                                <td class="fw-semibold"><?= htmlspecialchars($vn['nombre'] . ' ' . $vn['apellido']) ?></td>
                                <td class="text-center"><span class="badge bg-danger"><?= $vn['quincena_label'] ?></span></td>
                                <td class="text-center">Día <?= $vn['dia_programado'] ?></td>
                                <td class="text-center fw-bold text-danger"><?= $vn['dias_atraso'] ?> día(s)</td>
                                <td class="text-end fw-bold">L <?= number_format($vn['monto_pago'], 2) ?></td>
                                <td class="text-center">
                                    <?php if ($vn['activo']):
                                        $n = calcNeto((float)$vn['salario_base'], (int)$vn['aplica_ihss'], (int)$vn['aplica_rap'], $vn['tipo_pago']);
                                    ?>
                                        <button class="btn btn-sm btn-success btn-pagar"
                                            data-col='<?= json_encode([
                                                            'id' => $vn['id'],
                                                            'nombre' => $vn['nombre'] . ' ' . $vn['apellido'],
                                                            'tipo_pago' => $vn['tipo_pago'],
                                                            'dia_pago' => $vn['dia_pago'],
                                                            'dia_pago_2' => $vn['dia_pago_2'],
                                                            'salario' => $vn['salario_base'],
                                                            'neto_pago' => $n['neto_pago'],
                                                            'ihss_emp' => $n['ihss_emp'],
                                                            'rap_emp' => $n['rap_emp'],
                                                            'ihss_pat' => $n['ihss_pat'],
                                                            'rap_pat' => $n['rap_pat'],
                                                            'costo_total' => $n['costo_total'],
                                                        ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>'>
                                            <i class="fa-solid fa-check me-1"></i> Pagar
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- 🟡 Próximos Pagos de Nómina -->
    <?php if (!empty($proximos_nomina)): ?>
        <div class="card border-warning border-2 shadow-sm mb-4">
            <div class="card-header bg-warning bg-opacity-10 border-bottom border-warning py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold text-warning">
                    <i class="fa-solid fa-clock me-2"></i>Próximos Pagos de Nómina — próximos 7 días
                </h6>
                <span class="badge bg-warning text-dark"><?= count($proximos_nomina) ?></span>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Colaborador</th>
                            <th class="text-center">Pago</th>
                            <th class="text-center">Vence día</th>
                            <th class="text-center">Días restantes</th>
                            <th class="text-end">Monto neto</th>
                            <th class="text-center">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($proximos_nomina as $pn): ?>
                            <?php $dr = $pn['dias_restantes']; ?>
                            <tr class="<?= $dr === 0 ? 'table-warning' : '' ?>">
                                <td class="fw-semibold"><?= htmlspecialchars($pn['nombre'] . ' ' . $pn['apellido']) ?></td>
                                <td class="text-center"><span class="badge bg-info text-dark"><?= $pn['quincena_label'] ?></span></td>
                                <td class="text-center">Día <?= $pn['dia_programado'] ?></td>
                                <td class="text-center fw-bold <?= $dr === 0 ? 'text-danger' : 'text-warning' ?>">
                                    <?= $dr === 0 ? '¡Hoy!' : $dr . ' día(s)' ?>
                                </td>
                                <td class="text-end fw-bold">L <?= number_format($pn['monto_pago'], 2) ?></td>
                                <td class="text-center">
                                    <?php if ($pn['activo']):
                                        $n = calcNeto((float)$pn['salario_base'], (int)$pn['aplica_ihss'], (int)$pn['aplica_rap'], $pn['tipo_pago']);
                                    ?>
                                        <button class="btn btn-sm btn-outline-success btn-pagar"
                                            data-col='<?= json_encode([
                                                            'id' => $pn['id'],
                                                            'nombre' => $pn['nombre'] . ' ' . $pn['apellido'],
                                                            'tipo_pago' => $pn['tipo_pago'],
                                                            'dia_pago' => $pn['dia_pago'],
                                                            'dia_pago_2' => $pn['dia_pago_2'],
                                                            'salario' => $pn['salario_base'],
                                                            'neto_pago' => $n['neto_pago'],
                                                            'ihss_emp' => $n['ihss_emp'],
                                                            'rap_emp' => $n['rap_emp'],
                                                            'ihss_pat' => $n['ihss_pat'],
                                                            'rap_pat' => $n['rap_pat'],
                                                            'costo_total' => $n['costo_total'],
                                                        ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>'>
                                            <i class="fa-solid fa-hand-holding-dollar me-1"></i> Pagar
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
    <!-- KPIs -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small mb-1"><i class="fa-solid fa-users text-primary me-1"></i>Activos</div>
                    <div class="fs-3 fw-bold text-primary"><?= (int)$kpi['activos'] ?></div>
                    <div class="text-muted small">colaboradores</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small mb-1"><i class="fa-solid fa-money-bill-wave text-success me-1"></i>Masa Salarial</div>
                    <div class="fs-5 fw-bold text-success">L <?= number_format((float)$kpi['masa_salarial'], 2) ?></div>
                    <div class="text-muted small">bruto mensual</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small mb-1"><i class="fa-solid fa-building-columns text-warning me-1"></i>Carga Patronal</div>
                    <div class="fs-5 fw-bold text-warning">L <?= number_format($costo_patronal, 2) ?></div>
                    <div class="text-muted small">IHSS + RAP / mes</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small mb-1"><i class="fa-solid fa-sack-dollar text-danger me-1"></i>Costo Total</div>
                    <div class="fs-5 fw-bold text-danger">L <?= number_format((float)$kpi['masa_salarial'] + $costo_patronal, 2) ?></div>
                    <div class="text-muted small">empresa / mes</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filtro activo/inactivo -->
    <div class="d-flex gap-2 mb-3">
        <a href="?estado=activo" class="btn btn-sm <?= $filtro_estado !== 'inactivo' ? 'btn-primary' : 'btn-outline-primary' ?>">
            <i class="fa-solid fa-user-check me-1"></i> Activos <span class="badge bg-white text-primary ms-1"><?= (int)$kpi['activos'] ?></span>
        </a>
        <a href="?estado=inactivo" class="btn btn-sm <?= $filtro_estado === 'inactivo' ? 'btn-secondary' : 'btn-outline-secondary' ?>">
            <i class="fa-solid fa-user-slash me-1"></i> Inactivos <span class="badge bg-white text-secondary ms-1"><?= (int)$kpi['inactivos'] ?></span>
        </a>
    </div>

    <!-- Tabla de colaboradores -->
    <div class="card border-0 shadow-sm mb-5">
        <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
            <h6 class="mb-0 fw-bold">
                <i class="fa-solid fa-list me-2 text-secondary"></i>
                <?= $filtro_estado === 'inactivo' ? 'Colaboradores Inactivos' : 'Colaboradores Activos' ?>
                <span class="badge bg-light text-secondary border ms-1"><?= count($colaboradores) ?></span>
            </h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="tablaColaboradores" class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Nombre</th>
                            <th>Puesto / Departamento</th>
                            <th class="text-center">Tipo Pago</th>
                            <th class="text-end">Salario Bruto</th>
                            <th class="text-end">Neto por Pago</th>
                            <th class="text-center">Deducciones</th>
                            <th class="text-center">Ingreso</th>
                            <th class="text-center">Pago <?= date('M Y') ?></th>
                            <th class="text-center" style="width:130px">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($colaboradores)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-5">
                                    <i class="fa-solid fa-users-slash fa-2x mb-2 d-block opacity-25"></i>
                                    No hay colaboradores <?= $filtro_estado === 'inactivo' ? 'inactivos' : 'registrados' ?>.
                                    <?php if ($filtro_estado !== 'inactivo'): ?>
                                        <br><button class="btn btn-sm btn-primary mt-2" id="btnNuevoColab2">
                                            <i class="fa-solid fa-user-plus me-1"></i> Registrar primer colaborador
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($colaboradores as $col):
                                $n = calcNeto((float)$col['salario_base'], (int)$col['aplica_ihss'], (int)$col['aplica_rap'], $col['tipo_pago']);
                                $qLabel = $col['tipo_pago'] === 'quincenal'
                                    ? '🔄 Quincenal · días ' . (int)$col['dia_pago'] . ' y ' . (int)$col['dia_pago_2']
                                    : '📅 Mensual · día ' . (int)$col['dia_pago'];
                            ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center flex-shrink-0"
                                                style="width:36px;height:36px;font-size:13px;font-weight:700;color:#0d6efd">
                                                <?= strtoupper(mb_substr($col['nombre'], 0, 1) . mb_substr($col['apellido'], 0, 1)) ?>
                                            </div>
                                            <div>
                                                <div class="fw-semibold"><?= htmlspecialchars($col['nombre'] . ' ' . $col['apellido']) ?></div>
                                                <?php if ($col['telefono']): ?>
                                                    <small class="text-muted"><i class="fa-solid fa-phone fa-xs me-1"></i><?= htmlspecialchars($col['telefono']) ?></small>
                                                <?php endif; ?>
                                                <?php if ($col['dpi']): ?>
                                                    <small class="text-muted ms-2"><i class="fa-solid fa-id-card fa-xs me-1"></i><?= htmlspecialchars($col['dpi']) ?></small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold small"><?= htmlspecialchars($col['puesto']) ?></div>
                                        <?php if ($col['departamento']): ?>
                                            <small class="text-muted"><?= htmlspecialchars($col['departamento']) ?></small>
                                        <?php endif; ?>
                                        <?php if ($col['cat_nombre']): ?>
                                            <br><span class="badge rounded-pill px-2 mt-1" style="background:<?= $col['cat_color'] ?>18;color:<?= $col['cat_color'] ?>;border:1px solid <?= $col['cat_color'] ?>40;font-size:10px">
                                                <i class="fa-solid <?= $col['cat_icono'] ?> me-1"></i><?= htmlspecialchars($col['cat_nombre']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-info text-dark"><?= $col['tipo_pago'] === 'quincenal' ? '🔄 Quincenal' : '📅 Mensual' ?></span>
                                        <br><small class="text-muted" style="font-size:10px">
                                            <?php if ($col['tipo_pago'] === 'quincenal'): ?>
                                                Días <?= (int)$col['dia_pago'] ?> y <?= (int)$col['dia_pago_2'] ?>
                                            <?php else: ?>
                                                Día <?= (int)$col['dia_pago'] ?>
                                            <?php endif; ?>
                                        </small>
                                    </td>
                                    <td class="text-end">
                                        <div class="fw-bold text-dark">L <?= number_format((float)$col['salario_base'], 2) ?></div>
                                        <small class="text-muted">mensual</small>
                                    </td>
                                    <td class="text-end">
                                        <div class="fw-bold text-success">L <?= number_format($n['neto_pago'], 2) ?></div>
                                        <small class="text-muted">por <?= $col['tipo_pago'] === 'quincenal' ? 'quincena' : 'mes' ?></small>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($col['aplica_ihss']): ?>
                                            <span class="badge bg-warning text-dark" title="IHSS empleado L <?= number_format($n['ihss_emp'], 2) ?>">IHSS</span>
                                        <?php endif; ?>
                                        <?php if ($col['aplica_rap']): ?>
                                            <span class="badge bg-info text-dark ms-1" title="RAP empleado L <?= number_format($n['rap_emp'], 2) ?>">RAP</span>
                                        <?php endif; ?>
                                        <?php if (!$col['aplica_ihss'] && !$col['aplica_rap']): ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                        <br><small class="text-danger" style="font-size:10px">
                                            -L <?= number_format($n['ihss_emp'] + $n['rap_emp'], 2) ?>
                                        </small>
                                    </td>
                                    <td class="text-center small text-muted">
                                        <?= date('d/m/Y', strtotime($col['fecha_ingreso'])) ?>
                                        <?php
                                        $dias = (int)((time() - strtotime($col['fecha_ingreso'])) / 86400);
                                        $anios = floor($dias / 365);
                                        $meses = floor(($dias % 365) / 30);
                                        if ($anios > 0) echo "<br><small class='text-muted'>{$anios}a {$meses}m</small>";
                                        elseif ($meses > 0) echo "<br><small class='text-muted'>{$meses} mes(es)</small>";
                                        ?>
                                    </td>
                                    <td class="text-center">
                                        <?php
                                        $nc = $col['nombre'] . ' ' . $col['apellido'];
                                        if ($col['tipo_pago'] === 'quincenal'):
                                            foreach ([1 => '1ª', 2 => '2ª'] as $q => $lbl):
                                                $e = estadoPago($pagos_mes, $nc, $q);
                                                if ($e === 'pagado') {
                                                    $cls = 'bg-success';
                                                    $txt = $lbl . ' ✓';
                                                } elseif ($e === 'pendiente') {
                                                    $cls = 'bg-warning text-dark';
                                                    $txt = $lbl . ' ⏳';
                                                } else {
                                                    $cls = 'bg-light text-danger border border-danger';
                                                    $txt = $lbl . ' —';
                                                }
                                                echo "<div class='mb-1'><span class='badge $cls'>$txt</span></div>";
                                            endforeach;
                                        else:
                                            $e = estadoPago($pagos_mes, $nc, 0);
                                            if ($e === 'pagado') {
                                                $cls = 'bg-success';
                                                $txt = '✓ Pagado';
                                            } elseif ($e === 'pendiente') {
                                                $cls = 'bg-warning text-dark';
                                                $txt = '⏳ Pendiente';
                                            } else {
                                                $cls = 'bg-light text-danger border border-danger';
                                                $txt = '— Sin pagar';
                                            }
                                            echo "<span class='badge $cls'>$txt</span>";
                                        endif;
                                        ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="d-flex gap-1 justify-content-center">
                                            <?php if ($col['activo']): ?>
                                                <button class="btn btn-sm btn-success btn-pagar"
                                                    title="Registrar pago"
                                                    data-col='<?= json_encode([
                                                                    'id'          => $col['id'],
                                                                    'nombre'      => $col['nombre'] . ' ' . $col['apellido'],
                                                                    'tipo_pago'   => $col['tipo_pago'],
                                                                    'dia_pago'    => $col['dia_pago'],
                                                                    'dia_pago_2'  => $col['dia_pago_2'],
                                                                    'salario'     => $col['salario_base'],
                                                                    'neto_pago'   => $n['neto_pago'],
                                                                    'ihss_emp'    => $n['ihss_emp'],
                                                                    'rap_emp'     => $n['rap_emp'],
                                                                    'ihss_pat'    => $n['ihss_pat'],
                                                                    'rap_pat'     => $n['rap_pat'],
                                                                    'costo_total' => $n['costo_total'],
                                                                ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>'>
                                                    <i class="fa-solid fa-hand-holding-dollar"></i>
                                                </button>
                                            <?php endif; ?>
                                            <a href="colaborador_ver.php?id=<?= $col['id'] ?>"
                                                class="btn btn-sm btn-outline-info" title="Ver perfil completo">
                                                <i class="fa-solid fa-eye"></i>
                                            </a>
                                            <button class="btn btn-sm btn-outline-primary btn-editar"
                                                title="Editar"
                                                data-col='<?= json_encode($col, JSON_HEX_APOS | JSON_HEX_QUOT) ?>'>
                                                <i class="fa-solid fa-pen-to-square"></i>
                                            </button>
                                            <button class="btn btn-sm <?= $col['activo'] ? 'btn-outline-warning' : 'btn-outline-success' ?> btn-estado"
                                                title="<?= $col['activo'] ? 'Dar de baja' : 'Reactivar' ?>"
                                                data-id="<?= $col['id'] ?>"
                                                data-nombre="<?= htmlspecialchars($col['nombre'] . ' ' . $col['apellido']) ?>"
                                                data-activo="<?= $col['activo'] ?>">
                                                <i class="fa-solid <?= $col['activo'] ? 'fa-user-slash' : 'fa-user-check' ?>"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($colaboradores)): ?>
                        <tfoot class="table-light">
                            <tr>
                                <td colspan="3" class="text-end fw-bold small">TOTALES:</td>
                                <td class="text-end fw-bold">L <?= number_format((float)$kpi['masa_salarial'], 2) ?></td>
                                <td class="text-end fw-bold text-success">
                                    L <?= number_format(array_sum(array_map(fn($c) => calcNeto((float)$c['salario_base'], (int)$c['aplica_ihss'], (int)$c['aplica_rap'], $c['tipo_pago'])['neto_pago'], $colaboradores)), 2) ?>
                                </td>
                                <td colspan="3"></td>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <!-- Tabla de tasas Honduras (referencia) -->
    <div class="card border-0 shadow-sm mb-5 border-start border-4 border-info">
        <div class="card-body py-3">
            <h6 class="fw-bold mb-3"><i class="fa-solid fa-circle-info me-2 text-info"></i>Tasas Honduras vigentes (referencia)</h6>
            <div class="row g-3">
                <div class="col-md-6">
                    <table class="table table-sm table-bordered mb-0" style="font-size:13px">
                        <thead class="table-warning">
                            <tr>
                                <th colspan="3">IHSS</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Empleado</td>
                                <td class="text-end">3.5%</td>
                                <td class="text-muted small">Tope base L <?= number_format(IHSS_TOPE, 2) ?>/mes</td>
                            </tr>
                            <tr>
                                <td>Patronal</td>
                                <td class="text-end">7.0%</td>
                                <td class="text-muted small">Mismo tope</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm table-bordered mb-0" style="font-size:13px">
                        <thead class="table-info">
                            <tr>
                                <th colspan="3">RAP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Empleado</td>
                                <td class="text-end">1.5%</td>
                                <td class="text-muted small">Sin tope</td>
                            </tr>
                            <tr>
                                <td>Patronal</td>
                                <td class="text-end">1.5%</td>
                                <td class="text-muted small">Sin tope</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- MODAL: Nuevo / Editar Colaborador                                         -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalColab" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom py-3">
                <h5 class="modal-title fw-bold" id="modalColabTitulo">
                    <i class="fa-solid fa-user-plus me-2 text-primary"></i>Nuevo Colaborador
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form id="formColab">
                    <input type="hidden" name="colaborador_id" id="colaborador_id">
                    <div class="row g-3">
                        <!-- Datos personales -->
                        <div class="col-12">
                            <div class="fw-semibold text-muted small text-uppercase mb-2">
                                <i class="fa-solid fa-person me-1"></i> Datos Personales
                            </div>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">Nombre <span class="text-danger">*</span></label>
                            <input type="text" name="nombre" id="colab_nombre" class="form-control" maxlength="100" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">Apellido <span class="text-danger">*</span></label>
                            <input type="text" name="apellido" id="colab_apellido" class="form-control" maxlength="100" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold">DPI</label>
                            <input type="text" name="dpi" id="colab_dpi" class="form-control" maxlength="20" placeholder="0801...">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Teléfono</label>
                            <input type="text" name="telefono" id="colab_telefono" class="form-control" maxlength="20" placeholder="9999-9999">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">Email</label>
                            <input type="email" name="email" id="colab_email" class="form-control" maxlength="150">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Fecha Ingreso <span class="text-danger">*</span></label>
                            <input type="date" name="fecha_ingreso" id="colab_fecha_ingreso" class="form-control" required>
                        </div>

                        <!-- Cargo -->
                        <div class="col-12">
                            <hr class="my-1 opacity-25">
                        </div>
                        <div class="col-12">
                            <div class="fw-semibold text-muted small text-uppercase mb-2">
                                <i class="fa-solid fa-briefcase me-1"></i> Cargo y Categoría
                            </div>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">Puesto <span class="text-danger">*</span></label>
                            <input type="text" name="puesto" id="colab_puesto" class="form-control" maxlength="150" required placeholder="Ej: Diseñador Web">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Departamento</label>
                            <input type="text" name="departamento" id="colab_departamento" class="form-control" maxlength="100" placeholder="Ej: Diseño">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Categoría Gasto</label>
                            <select name="categoria_gasto_id" id="colab_cat" class="form-select">
                                <option value="">— Sin categoría —</option>
                                <?php foreach ($categorias as $cat): ?>
                                    <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Salario y pago -->
                        <div class="col-12">
                            <hr class="my-1 opacity-25">
                        </div>
                        <div class="col-12">
                            <div class="fw-semibold text-muted small text-uppercase mb-2">
                                <i class="fa-solid fa-money-bill me-1"></i> Salario y Forma de Pago
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Salario Bruto Mensual <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">L</span>
                                <input type="number" name="salario_base" id="colab_salario" class="form-control"
                                    min="1" step="0.01" placeholder="0.00" required>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Tipo de Pago</label>
                            <select name="tipo_pago" id="colab_tipo_pago" class="form-select">
                                <option value="quincenal">🔄 Quincenal</option>
                                <option value="mensual">📅 Mensual</option>
                            </select>
                        </div>
                        <div class="col-md-2" id="grp_dia1">
                            <label class="form-label fw-semibold">1er Día <span class="text-danger">*</span></label>
                            <input type="number" name="dia_pago" id="colab_dia1" class="form-control"
                                min="1" max="31" placeholder="15">
                        </div>
                        <div class="col-md-2" id="grp_dia2">
                            <label class="form-label fw-semibold">2° Día <span class="text-danger">*</span></label>
                            <input type="number" name="dia_pago_2" id="colab_dia2" class="form-control"
                                min="1" max="31" placeholder="30">
                        </div>
                        <div class="col-md-1 d-flex align-items-end pb-1">
                            <span class="text-muted small">del mes</span>
                        </div>

                        <!-- Deducciones -->
                        <div class="col-12">
                            <label class="form-label fw-semibold">Aplica Deducciones</label>
                            <div class="d-flex gap-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="aplica_ihss" id="colab_ihss" value="1" checked>
                                    <label class="form-check-label" for="colab_ihss">
                                        <span class="badge bg-warning text-dark">IHSS</span> <small class="text-muted">(3.5% empleado, máx L <?= number_format(IHSS_TOPE * IHSS_EMP, 2) ?>)</small>
                                    </label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="aplica_rap" id="colab_rap" value="1" checked>
                                    <label class="form-check-label" for="colab_rap">
                                        <span class="badge bg-info text-dark">RAP</span> <small class="text-muted">(1.5% empleado, sin tope)</small>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Preview de cálculo -->
                        <div class="col-12" id="previewCalculo" style="display:none">
                            <div class="rounded-3 p-3" style="background:#f0f7ff;border:1px solid #c0d8f0">
                                <div class="fw-semibold small text-primary mb-2"><i class="fa-solid fa-calculator me-1"></i>Preview por pago</div>
                                <div class="row g-2 text-center" style="font-size:13px">
                                    <div class="col">
                                        <div class="text-muted">Bruto</div>
                                        <div class="fw-bold" id="prev_bruto">L 0.00</div>
                                    </div>
                                    <div class="col text-danger">
                                        <div class="text-muted">- IHSS emp.</div>
                                        <div class="fw-bold" id="prev_ihss">L 0.00</div>
                                    </div>
                                    <div class="col text-danger">
                                        <div class="text-muted">- RAP emp.</div>
                                        <div class="fw-bold" id="prev_rap">L 0.00</div>
                                    </div>
                                    <div class="col-auto d-flex align-items-center text-muted">=</div>
                                    <div class="col text-success">
                                        <div class="text-muted">Neto a pagar</div>
                                        <div class="fw-bold fs-6" id="prev_neto">L 0.00</div>
                                    </div>
                                    <div class="col text-warning">
                                        <div class="text-muted">+ Patronal</div>
                                        <div class="fw-bold" id="prev_patronal">L 0.00</div>
                                    </div>
                                    <div class="col text-danger">
                                        <div class="text-muted">Costo empresa</div>
                                        <div class="fw-bold" id="prev_costo">L 0.00</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Notas <span class="text-muted small fw-normal">(opcional)</span></label>
                            <textarea name="notas" id="colab_notas" class="form-control" rows="2" maxlength="500"></textarea>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-top">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary px-4" id="btnGuardarColab">
                    <i class="fa-solid fa-floppy-disk me-1"></i> Guardar
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- MODAL: Registrar Pago de Nómina                                           -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="modalPago" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-success bg-opacity-10 border-bottom py-3">
                <h5 class="modal-title fw-bold text-success">
                    <i class="fa-solid fa-hand-holding-dollar me-2"></i>Registrar Pago de Nómina
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form id="formPago">
                    <input type="hidden" name="colaborador_id" id="pago_colab_id">

                    <!-- Desglose -->
                    <div class="rounded-3 p-3 mb-3 border" id="pagoDesglose" style="background:#f8f9fa">
                        <div class="fw-bold mb-1" id="pago_nombre">—</div>
                        <div class="row g-1 text-center" style="font-size:12px" id="pago_desglose_rows">
                        </div>
                    </div>

                    <!-- Quincena (solo si quincenal) -->
                    <div class="mb-3" id="grp_quincena">
                        <label class="form-label fw-semibold">¿Qué pago es?</label>
                        <div class="d-flex gap-2">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="quincena" id="q1" value="1" checked>
                                <label class="form-check-label" for="q1">
                                    <span class="badge bg-primary">1ª Quincena</span>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="quincena" id="q2" value="2">
                                <label class="form-check-label" for="q2">
                                    <span class="badge bg-info text-dark">2ª Quincena</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Fecha del Pago</label>
                            <input type="date" name="fecha" id="pago_fecha" class="form-control">
                            <div id="estadoPagoFecha" class="mt-1" style="font-size:12px"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Método de Pago</label>
                            <select name="metodo_pago" id="pago_metodo" class="form-select">
                                <option value="transferencia">🏦 Transferencia</option>
                                <option value="efectivo">💵 Efectivo</option>
                                <option value="cheque">📝 Cheque</option>
                                <option value="tarjeta">💳 Tarjeta</option>
                                <option value="otro">🔷 Otro</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Notas <span class="text-muted small fw-normal">(opcional)</span></label>
                            <textarea name="notas" id="pago_notas" class="form-control" rows="2"
                                placeholder="N° transferencia, banco, referencia..."></textarea>
                        </div>

                        <!-- Comprobante -->
                        <div class="col-12">
                            <label class="form-label fw-semibold">
                                <i class="fa-solid fa-paperclip me-1 text-secondary"></i>
                                Comprobante <span class="text-muted small fw-normal">(opcional · JPG, PNG, WEBP o PDF · máx 5 MB)</span>
                            </label>
                            <div id="zonaComprobante" class="border border-2 border-dashed rounded-3 text-center p-3"
                                style="cursor:pointer;border-color:#dee2e6!important;transition:border-color .2s,background .2s"
                                ondragover="event.preventDefault();this.style.borderColor='#0d6efd';this.style.background='#f0f7ff'"
                                ondragleave="this.style.borderColor='';this.style.background=''"
                                ondrop="handleDrop(event)">
                                <i class="fa-solid fa-cloud-arrow-up fa-2x text-secondary opacity-50 mb-1 d-block"></i>
                                <div class="small text-muted">Arrastra aquí o <span class="text-primary fw-semibold" onclick="$('#pago_comprobante').click()">selecciona un archivo</span></div>
                                <input type="file" id="pago_comprobante" name="comprobante"
                                    accept=".jpg,.jpeg,.png,.webp,.pdf"
                                    class="d-none">
                            </div>
                            <!-- Preview del archivo seleccionado -->
                            <div id="previewComprobante" class="mt-2 d-none">
                                <div class="d-flex align-items-center gap-2 p-2 rounded-2 border" style="background:#f8f9fa">
                                    <div id="prevIcono" class="text-secondary" style="font-size:22px"></div>
                                    <div class="flex-grow-1 overflow-hidden">
                                        <div id="prevNombre" class="small fw-semibold text-truncate"></div>
                                        <div id="prevTamaño" class="text-muted" style="font-size:11px"></div>
                                    </div>
                                    <div>
                                        <a id="prevLink" href="#" target="_blank" class="btn btn-sm btn-outline-primary d-none">
                                            <i class="fa-solid fa-eye fa-xs"></i>
                                        </a>
                                        <button type="button" class="btn btn-sm btn-outline-danger ms-1" onclick="limpiarComprobante()">
                                            <i class="fa-solid fa-xmark fa-xs"></i>
                                        </button>
                                    </div>
                                </div>
                                <!-- Miniatura imagen -->
                                <div id="prevImagen" class="mt-1 d-none">
                                    <img id="prevImg" src="" alt="Preview" class="rounded-2 border"
                                        style="max-height:120px;max-width:100%;object-fit:cover">
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-top">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success px-4" id="btnConfirmarPago">
                    <i class="fa-solid fa-circle-check me-1"></i> Confirmar Pago
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    $(function() {
        // ── DataTable ──────────────────────────────────────────────────────────────
        <?php if (!empty($colaboradores)): ?>
            $('#tablaColaboradores').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json'
                },
                order: [
                    [0, 'asc']
                ],
                pageLength: 25,
                columnDefs: [{
                    orderable: false,
                    targets: [7]
                }]
            });
        <?php endif; ?>

        // ── Tasas Honduras ─────────────────────────────────────────────────────────
        const IHSS_EMP = 0.035,
            IHSS_PAT = 0.07;
        const RAP_EMP = 0.015,
            RAP_PAT = 0.015;
        const IHSS_TOPE = 10294.10;

        function calcularNeto(salario, ihss, rap, tipo) {
            const base_ihss = Math.min(salario, IHSS_TOPE);
            const ihss_emp = ihss ? Math.round(base_ihss * IHSS_EMP * 100) / 100 : 0;
            const rap_emp = rap ? Math.round(salario * RAP_EMP * 100) / 100 : 0;
            const neto_mes = salario - ihss_emp - rap_emp;
            const ihss_pat = ihss ? Math.round(base_ihss * IHSS_PAT * 100) / 100 : 0;
            const rap_pat = rap ? Math.round(salario * RAP_PAT * 100) / 100 : 0;
            const div = tipo === 'quincenal' ? 2 : 1;
            const r = n => Math.round(n / div * 100) / 100;
            return {
                bruto_pago: r(salario),
                ihss_emp: r(ihss_emp),
                rap_emp: r(rap_emp),
                neto_pago: r(neto_mes),
                ihss_pat: r(ihss_pat),
                rap_pat: r(rap_pat),
                costo_total: r(neto_mes + ihss_pat + rap_pat),
            };
        }

        function fmt(n) {
            return 'L ' + parseFloat(n).toLocaleString('es-HN', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        // ── Preview cálculo en modal ───────────────────────────────────────────────
        function actualizarPreview() {
            const salario = parseFloat($('#colab_salario').val()) || 0;
            const tipo = $('#colab_tipo_pago').val();
            const ihss = $('#colab_ihss').is(':checked') ? 1 : 0;
            const rap = $('#colab_rap').is(':checked') ? 1 : 0;
            if (salario <= 0) {
                $('#previewCalculo').hide();
                return;
            }
            const n = calcularNeto(salario, ihss, rap, tipo);
            const lbl = tipo === 'quincenal' ? 'quincena' : 'mes';
            $('#prev_bruto').text(fmt(n.bruto_pago));
            $('#prev_ihss').text(ihss ? '-' + fmt(n.ihss_emp) : 'L 0.00');
            $('#prev_rap').text(rap ? '-' + fmt(n.rap_emp) : 'L 0.00');
            $('#prev_neto').text(fmt(n.neto_pago));
            $('#prev_patronal').text(fmt(n.ihss_pat + n.rap_pat));
            $('#prev_costo').text(fmt(n.costo_total));
            $('#previewCalculo').show();
        }

        $('#colab_salario, #colab_tipo_pago, #colab_ihss, #colab_rap').on('input change', actualizarPreview);

        // ── Toggle días de pago ───────────────────────────────────────────────────
        function toggleDias() {
            const q = $('#colab_tipo_pago').val() === 'quincenal';
            $('#grp_dia2').toggle(q);
            $('#colab_dia2').prop('required', q);
        }
        $('#colab_tipo_pago').on('change', toggleDias);

        // ── Abrir modal Nuevo ─────────────────────────────────────────────────────
        function abrirNuevo() {
            $('#modalColabTitulo').html('<i class="fa-solid fa-user-plus me-2 text-primary"></i>Nuevo Colaborador');
            $('#formColab')[0].reset();
            $('#colaborador_id').val('');
            $('#colab_fecha_ingreso').val(new Date().toISOString().slice(0, 10));
            $('#colab_ihss, #colab_rap').prop('checked', true);
            toggleDias();
            $('#previewCalculo').hide();
            $('#modalColab').modal('show');
        }
        $('#btnNuevoColab, #btnNuevoColab2').on('click', abrirNuevo);

        // ── Abrir modal Editar ────────────────────────────────────────────────────
        $(document).on('click', '.btn-editar', function() {
            const c = $(this).data('col');
            $('#modalColabTitulo').html('<i class="fa-solid fa-pen-to-square me-2 text-primary"></i>Editar Colaborador');
            $('#colaborador_id').val(c.id);
            $('#colab_nombre').val(c.nombre);
            $('#colab_apellido').val(c.apellido);
            $('#colab_dpi').val(c.dpi || '');
            $('#colab_telefono').val(c.telefono || '');
            $('#colab_email').val(c.email || '');
            $('#colab_fecha_ingreso').val(c.fecha_ingreso);
            $('#colab_puesto').val(c.puesto);
            $('#colab_departamento').val(c.departamento || '');
            $('#colab_cat').val(c.categoria_gasto_id || '');
            $('#colab_salario').val(parseFloat(c.salario_base).toFixed(2));
            $('#colab_tipo_pago').val(c.tipo_pago);
            $('#colab_dia1').val(c.dia_pago || '');
            $('#colab_dia2').val(c.dia_pago_2 || '');
            $('#colab_ihss').prop('checked', parseInt(c.aplica_ihss) === 1);
            $('#colab_rap').prop('checked', parseInt(c.aplica_rap) === 1);
            $('#colab_notas').val(c.notas || '');
            toggleDias();
            actualizarPreview();
            $('#modalColab').modal('show');
        });

        // ── Guardar colaborador ────────────────────────────────────────────────────
        $('#btnGuardarColab').on('click', function() {
            const esEditar = !!$('#colaborador_id').val();
            const url = esEditar ? 'includes/colaborador_actualizar.php' : 'includes/colaborador_guardar.php';
            const btn = $(this);
            btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-1"></i> Guardando...');
            $.post(url, $('#formColab').serialize())
                .done(d => {
                    if (d.success) {
                        Swal.fire({
                                icon: 'success',
                                title: '¡Listo!',
                                text: d.message,
                                timer: 1800,
                                showConfirmButton: false
                            })
                            .then(() => location.reload());
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: d.error
                        });
                        btn.prop('disabled', false).html('<i class="fa-solid fa-floppy-disk me-1"></i> Guardar');
                    }
                })
                .fail(() => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error de conexión'
                    });
                    btn.prop('disabled', false).html('<i class="fa-solid fa-floppy-disk me-1"></i> Guardar');
                });
        });

        // ── Dar de baja / reactivar ───────────────────────────────────────────────
        $(document).on('click', '.btn-estado', function() {
            const id = $(this).data('id');
            const nombre = $(this).data('nombre');
            const activo = parseInt($(this).data('activo'));
            const accion = activo ? 'dar de baja' : 'reactivar';
            Swal.fire({
                title: `¿${activo ? 'Dar de baja' : 'Reactivar'} a ${nombre}?`,
                icon: activo ? 'warning' : 'question',
                showCancelButton: true,
                confirmButtonColor: activo ? '#ffc107' : '#198754',
                confirmButtonText: `Sí, ${accion}`,
                cancelButtonText: 'Cancelar'
            }).then(r => {
                if (!r.isConfirmed) return;
                $.post('includes/colaborador_actualizar.php', {
                    colaborador_id: id,
                    _cambiar_estado: 1,
                    activo: activo ? 0 : 1
                }, d => {
                    if (d.success) Swal.fire({
                            icon: 'success',
                            title: d.message,
                            timer: 1400,
                            showConfirmButton: false
                        })
                        .then(() => location.reload());
                    else Swal.fire('Error', d.error, 'error');
                }, 'json');
            });
        });

        // ── Abrir modal pago ──────────────────────────────────────────────────────
        // ── Abrir modal pago ──────────────────────────────────────────────────────
        var _pagoActualDia1 = 0,
            _pagoActualDia2 = 0,
            _pagoActualTipo = 'mensual';

        function verificarVencimientoPago() {
            var fechaVal = $('#pago_fecha').val();
            if (!fechaVal || !_pagoActualDia1) {
                $('#estadoPagoFecha').html('');
                return;
            }

            var fecha = new Date(fechaVal + 'T00:00:00');
            var anio = fecha.getFullYear();
            var mes = fecha.getMonth();

            var diaProg;
            if (_pagoActualTipo === 'quincenal') {
                var q = $('[name=quincena]:checked').val();
                diaProg = (q == 2) ? _pagoActualDia2 : _pagoActualDia1;
            } else {
                diaProg = _pagoActualDia1;
            }

            var diasEnMes = new Date(anio, mes + 1, 0).getDate();
            var diaAjustado = Math.min(diaProg, diasEnMes);
            var fechaProg = new Date(anio, mes, diaAjustado);
            var diffDias = Math.round((fecha - fechaProg) / 86400000);

            if (diffDias > 0) {
                $('#estadoPagoFecha').html(
                    '<span class="badge bg-danger bg-opacity-15 border border-danger border-opacity-25">' +
                    '<i class="fa-solid fa-clock-rotate-left me-1"></i>' +
                    'Vencido <strong>' + diffDias + ' día(s)</strong> — programado día ' + diaAjustado +
                    '</span>'
                );
            } else if (diffDias === 0) {
                $('#estadoPagoFecha').html(
                    '<span class="badge bg-success bg-opacity-15 text-success border border-success border-opacity-25">' +
                    '<i class="fa-solid fa-circle-check me-1"></i>En fecha programada' +
                    '</span>'
                );
            } else {
                $('#estadoPagoFecha').html(
                    '<span class="badge bg-info bg-opacity-15 text-info border border-info border-opacity-25">' +
                    '<i class="fa-solid fa-calendar-check me-1"></i>' +
                    'Adelantado ' + Math.abs(diffDias) + ' día(s) al programado' +
                    '</span>'
                );
            }
        }

        $('#pago_fecha').on('change', verificarVencimientoPago);
        $('[name=quincena]').on('change', verificarVencimientoPago);

        $(document).on('click', '.btn-pagar', function() {
            const c = $(this).data('col');

            // Guardar datos del colaborador actual para el cálculo de vencimiento
            _pagoActualDia1 = parseInt(c.dia_pago) || 0;
            _pagoActualDia2 = parseInt(c.dia_pago_2) || 0;
            _pagoActualTipo = c.tipo_pago || 'mensual';

            $('#pago_colab_id').val(c.id);
            $('#pago_nombre').text(c.nombre);
            $('#pago_fecha').val(new Date().toISOString().slice(0, 10));
            $('#estadoPagoFecha').html('');
            $('#pago_notas').val('');
            $('#pago_metodo').val('transferencia');
            limpiarComprobante();

            const tipo = c.tipo_pago;
            const lbl = tipo === 'quincenal' ? 'por quincena' : 'mensual';
            $('#grp_quincena').toggle(tipo === 'quincenal');
            $('#q1').prop('checked', true);

            const rows = `
            <div class="col-4 col-md">
                <div class="text-muted">Bruto ${lbl}</div>
                <div class="fw-bold text-dark">L ${parseFloat(c.neto_pago + c.ihss_emp + c.rap_emp).toLocaleString('es-HN',{minimumFractionDigits:2})}</div>
            </div>
            <div class="col-4 col-md">
                <div class="text-muted">- IHSS emp.</div>
                <div class="fw-bold text-danger">L ${parseFloat(c.ihss_emp).toLocaleString('es-HN',{minimumFractionDigits:2})}</div>
            </div>
            <div class="col-4 col-md">
                <div class="text-muted">- RAP emp.</div>
                <div class="fw-bold text-danger">L ${parseFloat(c.rap_emp).toLocaleString('es-HN',{minimumFractionDigits:2})}</div>
            </div>
            <div class="col-4 col-md">
                <div class="text-muted fw-bold text-success">✓ Neto a pagar</div>
                <div class="fw-bold fs-6 text-success">L ${parseFloat(c.neto_pago).toLocaleString('es-HN',{minimumFractionDigits:2})}</div>
            </div>
            <div class="col-4 col-md">
                <div class="text-muted">+ Patronal</div>
                <div class="fw-bold text-warning">L ${parseFloat(c.ihss_pat + c.rap_pat).toLocaleString('es-HN',{minimumFractionDigits:2})}</div>
            </div>`;
            $('#pago_desglose_rows').html(rows);
            $('#modalPago').modal('show');
            setTimeout(verificarVencimientoPago, 150);
        });

        // ── Comprobante: selección por click + drag & drop ─────────────────────────
        $('#pago_comprobante').on('change', function() {
            if (this.files && this.files[0]) mostrarPreviewComprobante(this.files[0]);
        });

        $('#zonaComprobante').on('click', function(e) {
            if (!$(e.target).is('span.text-primary')) return; // el span lo maneja el onclick
        });

        // ── Confirmar pago (usa FormData para enviar el archivo) ──────────────────
        $('#btnConfirmarPago').on('click', function() {
            const btn = $(this);
            btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-1"></i> Registrando...');

            const fd = new FormData($('#formPago')[0]);
            const archivo = document.getElementById('pago_comprobante').files[0];
            if (archivo) fd.set('comprobante', archivo);

            $.ajax({
                    url: 'includes/colaborador_pago_guardar.php',
                    type: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    dataType: 'json'
                })
                .done(d => {
                    if (d.success) {
                        $('#modalPago').modal('hide');
                        const extra = d.comprobante_url ?
                            '<br><small class="text-muted"><i class="fa-solid fa-paperclip me-1"></i>Comprobante adjunto</small>' :
                            '';
                        Swal.fire({
                            icon: 'success',
                            title: '¡Pago registrado!',
                            html: d.message + extra,
                            timer: 2200,
                            showConfirmButton: false
                        }).then(() => location.reload());
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: d.error
                        });
                        btn.prop('disabled', false).html('<i class="fa-solid fa-circle-check me-1"></i> Confirmar Pago');
                    }
                })
                .fail(() => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error de conexión'
                    });
                    btn.prop('disabled', false).html('<i class="fa-solid fa-circle-check me-1"></i> Confirmar Pago');
                });
        });
    });

    // ── Helpers comprobante (globales para drag & drop) ───────────────────────────
    function mostrarPreviewComprobante(file) {
        const esPdf = file.type === 'application/pdf';
        const tamaño = file.size < 1024 * 1024 ?
            (file.size / 1024).toFixed(1) + ' KB' :
            (file.size / 1024 / 1024).toFixed(2) + ' MB';

        $('#prevIcono').html(esPdf ?
            '<i class="fa-solid fa-file-pdf fa-xl text-danger"></i>' :
            '<i class="fa-solid fa-image fa-xl text-primary"></i>');
        $('#prevNombre').text(file.name);
        $('#prevTamaño').text(tamaño + ' · ' + (esPdf ? 'PDF' : 'Imagen'));

        if (!esPdf) {
            const reader = new FileReader();
            reader.onload = e => {
                $('#prevImg').attr('src', e.target.result);
                $('#prevImagen').removeClass('d-none');
            };
            reader.readAsDataURL(file);
            $('#prevLink').addClass('d-none');
        } else {
            $('#prevImagen').addClass('d-none');
            $('#prevLink').addClass('d-none'); // PDF no tiene preview nativo
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
        const file = event.dataTransfer.files[0];
        if (!file) return;
        const allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
        if (!allowed.includes(file.type)) {
            Swal.fire({
                icon: 'warning',
                title: 'Tipo no permitido',
                text: 'Solo JPG, PNG, WEBP o PDF.',
                timer: 2000,
                showConfirmButton: false
            });
            return;
        }
        if (file.size > 5 * 1024 * 1024) {
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
        mostrarPreviewComprobante(file);
    }
</script>
</body>

</html>