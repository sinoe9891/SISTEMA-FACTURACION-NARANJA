<?php
$titulo = 'Gastos';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';
require_once '../../includes/templates/header.php';

$cliente_id = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);

$vista       = in_array($_GET['vista'] ?? '', ['anual', 'mensual']) ? $_GET['vista'] : 'mensual';
$mes_filtro  = (int)($_GET['mes']  ?? date('n'));
$anio_filtro = (int)($_GET['anio'] ?? date('Y'));
$cat_filtro  = (int)($_GET['cat']  ?? 0);
$tipo_filtro = trim($_GET['tipo']  ?? '');
$dias_alerta = isset($_GET['alerta']) ? max(1, min((int)$_GET['alerta'], 60)) : 10;

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

if ($vista === 'mensual') {
    $fecha_ini     = sprintf('%04d-%02d-01', $anio_filtro, $mes_filtro);
    $fecha_fin     = date('Y-m-t', strtotime($fecha_ini));
    $periodo_label = $meses[$mes_filtro - 1] . ' ' . $anio_filtro;
} else {
    $fecha_ini     = "$anio_filtro-01-01";
    $fecha_fin     = "$anio_filtro-12-31";
    $periodo_label = "Año $anio_filtro";
}

$stmtKpi = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN estado != 'anulado' THEN monto END), 0)                         AS total_mes,
        COALESCE(SUM(CASE WHEN tipo='fijo'           AND estado!='anulado' THEN monto END), 0)  AS fijos,
        COALESCE(SUM(CASE WHEN tipo='variable'       AND estado!='anulado' THEN monto END), 0)  AS variables,
        COALESCE(SUM(CASE WHEN tipo='extraordinario' AND estado!='anulado' THEN monto END), 0)  AS extraordinarios,
        COUNT(CASE WHEN estado='pendiente' THEN 1 END)                                          AS pendientes,
        COUNT(CASE WHEN estado!='anulado'  THEN 1 END)                                         AS total_registros
    FROM gastos WHERE cliente_id = ? AND fecha BETWEEN ? AND ?
");
$stmtKpi->execute([$cliente_id, $fecha_ini, $fecha_fin]);
$kpi = $stmtKpi->fetch(PDO::FETCH_ASSOC);

$variacion = null;
$total_anterior = 0;
if ($vista === 'mensual') {
    $dtAnt = new \DateTime($fecha_ini);
    $dtAnt->modify('-1 month');
    $stmtAnt = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM gastos WHERE cliente_id=? AND estado!='anulado' AND fecha BETWEEN ? AND ?");
    $stmtAnt->execute([$cliente_id, $dtAnt->format('Y-m-01'), $dtAnt->format('Y-m-t')]);
    $total_anterior = (float)$stmtAnt->fetchColumn();
    $variacion = $total_anterior > 0
        ? round((((float)$kpi['total_mes'] - $total_anterior) / $total_anterior) * 100, 1)
        : null;
}

$stmtAnual = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM gastos WHERE cliente_id=? AND estado!='anulado' AND YEAR(fecha)=?");
$stmtAnual->execute([$cliente_id, $anio_filtro]);
$total_anual = (float)$stmtAnual->fetchColumn();

$stmtRec = $pdo->prepare("
    SELECT g.*, cg.nombre AS cat_nombre, cg.color AS cat_color, cg.icono AS cat_icono
    FROM gastos g LEFT JOIN categorias_gastos cg ON cg.id = g.categoria_id
    WHERE g.cliente_id=? AND g.estado='pendiente'
      AND g.frecuencia IN ('mensual','quincenal')
      AND g.fecha BETWEEN ? AND ?
      AND g.fecha >= CURDATE()
      AND (g.fecha_vencimiento IS NULL OR g.fecha_vencimiento >= CURDATE())
    ORDER BY g.fecha ASC, g.dia_pago ASC, g.monto DESC
");
$stmtRec->execute([$cliente_id, $fecha_ini, $fecha_fin]);
$recurrentes_pendientes = $stmtRec->fetchAll(PDO::FETCH_ASSOC);

$stmtVencidos = $pdo->prepare("
    SELECT g.*, cg.nombre AS cat_nombre, cg.color AS cat_color, cg.icono AS cat_icono,
           CASE
               WHEN g.frecuencia = 'unico' THEN DATEDIFF(CURDATE(), g.fecha_vencimiento)
               ELSE DATEDIFF(CURDATE(), g.fecha)
           END AS dias_vencido,
           CASE
               WHEN g.frecuencia = 'unico' THEN g.fecha_vencimiento
               ELSE g.fecha
           END AS fecha_alerta
    FROM gastos g LEFT JOIN categorias_gastos cg ON cg.id = g.categoria_id
    WHERE g.cliente_id=? AND g.estado='pendiente'
      AND (
          (g.frecuencia = 'unico' AND g.fecha_vencimiento IS NOT NULL AND g.fecha_vencimiento < CURDATE())
          OR
          (g.frecuencia != 'unico' AND g.fecha < CURDATE())
      )
    ORDER BY fecha_alerta ASC LIMIT 20
");
$stmtVencidos->execute([$cliente_id]);
$gastos_vencidos = $stmtVencidos->fetchAll(PDO::FETCH_ASSOC);

$stmtProximos = $pdo->prepare("
    SELECT g.*, cg.nombre AS cat_nombre, cg.color AS cat_color, cg.icono AS cat_icono,
           DATEDIFF(g.fecha_vencimiento, CURDATE()) AS dias_restantes
    FROM gastos g LEFT JOIN categorias_gastos cg ON cg.id = g.categoria_id
    WHERE g.cliente_id=? AND g.estado='pendiente'
      AND g.frecuencia = 'unico'
      AND g.fecha_vencimiento IS NOT NULL
      AND g.fecha_vencimiento >= CURDATE()
      AND g.fecha_vencimiento <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
    ORDER BY g.fecha_vencimiento ASC
");
$stmtProximos->execute([$cliente_id, $dias_alerta]);
$proximos_vencer = $stmtProximos->fetchAll(PDO::FETCH_ASSOC);

$stmtCatRes = $pdo->prepare("
    SELECT cg.nombre, cg.color, cg.icono, COALESCE(SUM(g.monto),0) AS total, COUNT(g.id) AS cantidad
    FROM categorias_gastos cg
    LEFT JOIN gastos g ON g.categoria_id=cg.id AND g.cliente_id=cg.cliente_id
        AND g.estado!='anulado' AND g.fecha BETWEEN ? AND ?
    WHERE cg.cliente_id=? AND cg.activa=1
    GROUP BY cg.id HAVING total>0 ORDER BY total DESC
");
$stmtCatRes->execute([$fecha_ini, $fecha_fin, $cliente_id]);
$cats_resumen = $stmtCatRes->fetchAll(PDO::FETCH_ASSOC);

$stmtCats = $pdo->prepare("SELECT id, nombre, color, icono FROM categorias_gastos WHERE cliente_id=? AND activa=1 ORDER BY nombre");
$stmtCats->execute([$cliente_id]);
$categorias = $stmtCats->fetchAll(PDO::FETCH_ASSOC);

$sql = "SELECT g.*, cg.nombre AS cat_nombre, cg.color AS cat_color, cg.icono AS cat_icono
        FROM gastos g LEFT JOIN categorias_gastos cg ON cg.id=g.categoria_id
        WHERE g.cliente_id=? AND g.fecha BETWEEN ? AND ?";
$params = [$cliente_id, $fecha_ini, $fecha_fin];
if ($cat_filtro) {
    $sql .= " AND g.categoria_id=?";
    $params[] = $cat_filtro;
}
if ($tipo_filtro) {
    $sql .= " AND g.tipo=?";
    $params[] = $tipo_filtro;
}
$sql .= " ORDER BY g.fecha DESC, g.id DESC";
$stmtG = $pdo->prepare($sql);
$stmtG->execute($params);
$gastos = $stmtG->fetchAll(PDO::FETCH_ASSOC);

// Helper: devuelve config visual según días vencido
function nivelVencido(int $dias): array
{
    if ($dias <= 3) return [
        'badge'   => 'bg-warning text-dark',
        'row'     => 'table-warning',
        'icono'   => 'fa-clock',
        'label'   => "Vence hoy" . ($dias > 0 ? " hace {$dias}d" : ''),
        'color'   => '#ffc107',
    ];
    if ($dias <= 7) return [
        'badge'   => 'bg-orange text-white',   // clase custom abajo
        'row'     => 'row-vencido-medio',
        'icono'   => 'fa-triangle-exclamation',
        'label'   => "Vencido {$dias}d",
        'color'   => '#fd7e14',
    ];
    if ($dias <= 30) return [
        'badge'   => 'bg-danger',
        'row'     => 'table-danger',
        'icono'   => 'fa-circle-exclamation',
        'label'   => "Vencido {$dias}d",
        'color'   => '#dc3545',
    ];
    // Más de 30 días: crítico
    return [
        'badge'   => 'bg-dark',
        'row'     => 'row-vencido-critico',
        'icono'   => 'fa-skull',
        'label'   => "Crítico {$dias}d",
        'color'   => '#212529',
    ];
}
?>
<style>
    /* Nivel naranja (4-7 días) */
    .bg-orange {
        background-color: #fd7e14 !important;
    }

    .row-vencido-medio td {
        background-color: #fff3e0 !important;
    }

    .row-vencido-medio:hover td {
        background-color: #ffe0b2 !important;
    }

    /* Nivel crítico (+30 días) */
    .row-vencido-critico td {
        background-color: #f8d7da !important;
        border-left: 3px solid #212529 !important;
    }

    .row-vencido-critico:hover td {
        background-color: #f1aeb5 !important;
    }

    /* Pulso en badge crítico */
    @keyframes pulso {

        0%,
        100% {
            opacity: 1;
        }

        50% {
            opacity: .55;
        }
    }

    .badge-critico {
        animation: pulso 1.6s ease-in-out infinite;
    }
</style>
<div class="container-xxl mt-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h4 class="mb-0"><i class="fa-solid fa-wallet me-2 text-danger"></i>Gastos</h4>
            <small class="text-muted">Control de egresos — <?= $periodo_label ?></small>
        </div>
        <div class="d-flex gap-2">
            <a href="categorias_gastos" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-tags me-1"></i> Categorías</a>
            <button class="btn btn-danger" id="btnNuevoGasto"><i class="fa-solid fa-plus me-1"></i> Nuevo Gasto</button>
        </div>
    </div>

    <!-- Filtros -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body py-3">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-auto">
                    <label class="form-label small fw-semibold mb-1">Vista</label>
                    <select name="vista" id="selectVista" class="form-select form-select-sm">
                        <option value="mensual" <?= $vista === 'mensual' ? 'selected' : '' ?>>🗓️ Mes específico</option>
                        <option value="anual" <?= $vista === 'anual'  ? 'selected' : '' ?>>📅 Año completo</option>
                    </select>
                </div>
                <div class="col-auto" id="grpMes" <?= $vista === 'anual' ? 'style="display:none"' : '' ?>>
                    <label class="form-label small fw-semibold mb-1">Mes</label>
                    <select name="mes" class="form-select form-select-sm">
                        <?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= $m == $mes_filtro ? 'selected' : '' ?>><?= $meses[$m - 1] ?></option><?php endfor; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label small fw-semibold mb-1">Año</label>
                    <select name="anio" class="form-select form-select-sm">
                        <?php for ($a = date('Y'); $a >= date('Y') - 4; $a--): ?><option value="<?= $a ?>" <?= $a == $anio_filtro ? 'selected' : '' ?>><?= $a ?></option><?php endfor; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label small fw-semibold mb-1">Categoría</label>
                    <select name="cat" class="form-select form-select-sm">
                        <option value="">Todas</option>
                        <?php foreach ($categorias as $c): ?><option value="<?= $c['id'] ?>" <?= $c['id'] == $cat_filtro ? 'selected' : '' ?>><?= htmlspecialchars($c['nombre']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label small fw-semibold mb-1">Tipo</label>
                    <select name="tipo" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <option value="fijo" <?= $tipo_filtro === 'fijo'          ? 'selected' : '' ?>>Fijo</option>
                        <option value="variable" <?= $tipo_filtro === 'variable'      ? 'selected' : '' ?>>Variable</option>
                        <option value="extraordinario" <?= $tipo_filtro === 'extraordinario' ? 'selected' : '' ?>>Extraordinario</option>
                    </select>
                </div>
                <div class="col-auto">
                    <label class="form-label small fw-semibold mb-1" title="Días anticipación alerta naranja">⏰ Alerta</label>
                    <select name="alerta" class="form-select form-select-sm">
                        <option value="5" <?= $dias_alerta == 5 ? 'selected' : '' ?>>5 días</option>
                        <option value="10" <?= $dias_alerta == 10 ? 'selected' : '' ?>>10 días</option>
                        <option value="15" <?= $dias_alerta == 15 ? 'selected' : '' ?>>15 días</option>
                        <option value="30" <?= $dias_alerta == 30 ? 'selected' : '' ?>>30 días</option>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter me-1"></i> Filtrar</button>
                    <a href="gastos" class="btn btn-outline-secondary btn-sm ms-1">Limpiar</a>
                </div>
            </form>
        </div>
    </div>

    <!-- 🔴 Gastos Vencidos Sin Pagar -->
    <?php if (!empty($gastos_vencidos)): ?>
        <div class="card border-danger border-2 shadow-sm mb-4">
            <div class="card-header bg-danger bg-opacity-10 border-bottom border-danger py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h6 class="mb-0 fw-bold text-danger">
                    <i class="fa-solid fa-circle-exclamation me-2"></i>Gastos Vencidos — Sin Pagar
                    <span class="fw-normal small ms-1 text-muted">(fecha_vencimiento ya pasó)</span>
                </h6>
                <span class="badge bg-danger"><?= count($gastos_vencidos) ?> vencido(s)</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Descripción</th>
                                <th class="d-none d-md-table-cell">Categoría</th>
                                <th class="text-center">Venció el</th>
                                <th class="text-center">Días vencido</th>
                                <th class="text-end">Monto</th>
                                <th class="text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($gastos_vencidos as $gv):
                                $dias_v = (int)($gv['dias_vencido'] ?? 0);
                                $nv     = nivelVencido($dias_v);
                            ?>
                                <tr class="<?= $nv['row'] ?>">
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars($gv['descripcion']) ?></div>
                                        <?php if ($gv['proveedor']): ?><small class="text-muted"><?= htmlspecialchars($gv['proveedor']) ?></small><?php endif; ?>
                                        <?php if ($gv['frecuencia'] !== 'unico'): ?>
                                            <small class="text-muted d-block">
                                                <i class="fa-solid fa-rotate fa-xs me-1"></i>
                                                <?= $gv['frecuencia'] === 'mensual' ? 'Mensual' : 'Quincenal' ?>
                                                <?php if ($gv['quincena_num']): ?>
                                                    — <?= (int)$gv['quincena_num'] ?>ª Quincena
                                                <?php endif; ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="d-none d-md-table-cell">
                                        <?php if ($gv['cat_nombre']): ?>
                                            <span class="badge rounded-pill px-2" style="background:<?= $gv['cat_color'] ?>18;color:<?= $gv['cat_color'] ?>;border:1px solid <?= $gv['cat_color'] ?>40;font-size:11px">
                                                <i class="fa-solid <?= $gv['cat_icono'] ?> me-1"></i><?= htmlspecialchars($gv['cat_nombre']) ?>
                                            </span>
                                        <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge <?= $nv['badge'] ?> <?= $dias_v > 30 ? 'badge-critico' : '' ?>"
                                            title="<?= $nv['label'] ?>">
                                            <i class="fa-solid <?= $nv['icono'] ?> me-1"></i>
                                            <?= date('d/m/Y', strtotime($gv['fecha_alerta'])) ?>
                                        </span>
                                        <br><small style="font-size:10px;color:<?= $nv['color'] ?>;font-weight:600">
                                            <?= $nv['label'] ?>
                                        </small>
                                        <?php if ($gv['frecuencia'] !== 'unico'): ?>
                                            <small class="text-muted d-block" style="font-size:10px">fecha programada</small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="fw-bold" style="color:<?= $nv['color'] ?>">
                                            <?= $dias_v ?> día(s)
                                        </span>
                                    </td>
                                    <td class="text-end fw-bold text-danger">L <?= number_format((float)$gv['monto'], 2) ?></td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-success btn-registrar-pago"
                                            data-id="<?= $gv['id'] ?>" data-desc="<?= htmlspecialchars($gv['descripcion']) ?>"
                                            data-monto="<?= number_format((float)$gv['monto'], 2) ?>"
                                            data-quincena="<?= (int)$gv['quincena_num'] ?>"
                                            data-fecha="<?= $gv['fecha'] ?>" data-dia="" data-archivo="0">
                                            <i class="fa-solid fa-check me-1"></i> Pagar
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- 🟡 Pagos Recurrentes Pendientes del período -->
    <?php if (!empty($recurrentes_pendientes)): ?>
        <div class="card border-warning border-2 shadow-sm mb-4">
            <div class="card-header bg-warning bg-opacity-10 border-bottom border-warning py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h6 class="mb-0 fw-bold text-warning">
                    <i class="fa-solid fa-clock me-2"></i>Pagos Recurrentes Pendientes — <?= $periodo_label ?>
                </h6>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-warning text-dark"><?= count($recurrentes_pendientes) ?> pendiente(s)</span>
                    <span class="fw-bold text-danger small">L <?= number_format(array_sum(array_column($recurrentes_pendientes, 'monto')), 2) ?></span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Descripción</th>
                                <th class="d-none d-md-table-cell">Categoría</th>
                                <th class="text-center">Fecha</th>
                                <th class="text-center">Frecuencia</th>
                                <th class="text-center">Día</th>
                                <th class="text-end">Monto</th>
                                <th class="text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recurrentes_pendientes as $rp):
                                if ($rp['frecuencia'] === 'mensual') {
                                    $diasTexto = 'Día ' . (int)$rp['dia_pago'];
                                    $quincLabel = '';
                                    $btnLabel = '<i class="fa-solid fa-check me-1"></i> Pagar';
                                    $btnColor = 'btn-success';
                                } elseif ((int)$rp['quincena_num'] === 1) {
                                    $diasTexto = 'Día ' . (int)$rp['dia_pago'];
                                    $quincLabel = '<span class="badge bg-primary ms-1" style="font-size:10px">1ª</span>';
                                    $btnLabel = '<i class="fa-solid fa-check me-1"></i> Pagar 1ª';
                                    $btnColor = 'btn-success';
                                } elseif ((int)$rp['quincena_num'] === 2) {
                                    $diasTexto = 'Día ' . (int)$rp['dia_pago_2'];
                                    $quincLabel = '<span class="badge bg-info text-dark ms-1" style="font-size:10px">2ª</span>';
                                    $btnLabel = '<i class="fa-solid fa-check me-1"></i> Pagar 2ª';
                                    $btnColor = 'btn-outline-success';
                                } else {
                                    $diasTexto = 'Días ' . (int)$rp['dia_pago'] . ' y ' . (int)$rp['dia_pago_2'];
                                    $quincLabel = '';
                                    $btnLabel = '<i class="fa-solid fa-check me-1"></i> Pagar';
                                    $btnColor = 'btn-success';
                                }
                                $descConf = htmlspecialchars($rp['descripcion'])
                                    . ((int)$rp['quincena_num'] === 1 ? ' — 1ª Quincena' : '')
                                    . ((int)$rp['quincena_num'] === 2 ? ' — 2ª Quincena' : '');
                            ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars($rp['descripcion']) ?> <?= $quincLabel ?></div>
                                        <?php if ($rp['proveedor']): ?><small class="text-muted"><?= htmlspecialchars($rp['proveedor']) ?></small><?php endif; ?>
                                        <?php if ($rp['fecha_vencimiento']): ?>
                                            <small class="text-success d-block"><i class="fa-solid fa-calendar-check me-1"></i>Vence: <?= date('d/m/Y', strtotime($rp['fecha_vencimiento'])) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="d-none d-md-table-cell">
                                        <?php if ($rp['cat_nombre']): ?>
                                            <span class="badge rounded-pill px-2" style="background:<?= $rp['cat_color'] ?>18;color:<?= $rp['cat_color'] ?>;border:1px solid <?= $rp['cat_color'] ?>40;font-size:11px">
                                                <i class="fa-solid <?= $rp['cat_icono'] ?> me-1"></i><?= htmlspecialchars($rp['cat_nombre']) ?>
                                            </span>
                                        <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
                                    </td>
                                    <td class="text-center small text-muted"><?= date('d/m/Y', strtotime($rp['fecha'])) ?></td>
                                    <td class="text-center"><span class="badge bg-info text-dark"><?= $rp['frecuencia'] === 'mensual' ? '📅 Mensual' : '🔄 Quincenal' ?></span></td>
                                    <td class="text-center"><span class="badge bg-light text-dark border small"><?= $diasTexto ?></span></td>
                                    <td class="text-end fw-bold text-danger">L <?= number_format((float)$rp['monto'], 2) ?></td>
                                    <td class="text-center">
                                        <button class="btn btn-sm <?= $btnColor ?> btn-registrar-pago"
                                            data-id="<?= $rp['id'] ?>" data-desc="<?= $descConf ?>"
                                            data-dia="<?= htmlspecialchars($diasTexto) ?>" data-fecha="<?= htmlspecialchars($rp['fecha']) ?>"
                                            data-quincena="<?= (int)$rp['quincena_num'] ?>"
                                            data-monto="<?= number_format((float)$rp['monto'], 2) ?>" data-archivo="0">
                                            <?= $btnLabel ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <td colspan="5" class="text-end fw-bold small">Total pendiente:</td>
                                <td class="text-end fw-bold text-danger">L <?= number_format(array_sum(array_column($recurrentes_pendientes, 'monto')), 2) ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- 🟠 Próximos a Vencer -->
    <?php if (!empty($proximos_vencer)): ?>
        <div class="card border-2 shadow-sm mb-4" style="border-color:#fd7e14!important">
            <div class="card-header border-bottom py-3 d-flex justify-content-between align-items-center flex-wrap gap-2"
                style="background:rgba(253,126,20,0.08);border-color:#fd7e14!important">
                <h6 class="mb-0 fw-bold" style="color:#d45f00">
                    <i class="fa-solid fa-hourglass-half me-2"></i>Próximos a Vencer — en los próximos <?= $dias_alerta ?> días
                </h6>
                <span class="badge text-white" style="background:#fd7e14"><?= count($proximos_vencer) ?> gasto(s)</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Descripción</th>
                                <th class="d-none d-md-table-cell">Categoría</th>
                                <th class="text-center">Vence el</th>
                                <th class="text-center">Días restantes</th>
                                <th class="text-end">Monto</th>
                                <th class="text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($proximos_vencer as $pv):
                                $dr = $pv['dias_restantes'];
                                $uC = $dr <= 3 ? 'text-danger fw-bold' : ($dr <= 7 ? 'text-warning fw-bold' : 'text-muted');
                                $uB = $dr <= 3 ? 'bg-danger' : ($dr <= 7 ? 'bg-warning text-dark' : 'bg-secondary');
                            ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars($pv['descripcion']) ?></div>
                                        <?php if ($pv['proveedor']): ?><small class="text-muted"><?= htmlspecialchars($pv['proveedor']) ?></small><?php endif; ?>
                                        <?php if ($pv['frecuencia'] !== 'unico'): ?>
                                            <small class="text-info d-block"><i class="fa-solid fa-rotate me-1"></i><?= $pv['frecuencia'] === 'mensual' ? 'Mensual' : 'Quincenal' ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="d-none d-md-table-cell">
                                        <?php if ($pv['cat_nombre']): ?>
                                            <span class="badge rounded-pill px-2" style="background:<?= $pv['cat_color'] ?>18;color:<?= $pv['cat_color'] ?>;border:1px solid <?= $pv['cat_color'] ?>40;font-size:11px">
                                                <i class="fa-solid <?= $pv['cat_icono'] ?> me-1"></i><?= htmlspecialchars($pv['cat_nombre']) ?>
                                            </span>
                                        <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
                                    </td>
                                    <td class="text-center"><span class="badge <?= $uB ?>"><i class="fa-solid fa-calendar-day me-1"></i><?= date('d/m/Y', strtotime($pv['fecha_vencimiento'])) ?></span></td>
                                    <td class="text-center"><span class="<?= $uC ?>"><?= $dr === 0 ? '¡Hoy!' : ($dr === 1 ? 'Mañana' : $dr . ' días') ?></span></td>
                                    <td class="text-end fw-bold text-danger">L <?= number_format((float)$pv['monto'], 2) ?></td>
                                    <td class="text-center">
                                        <button class="btn btn-sm btn-warning text-dark btn-registrar-pago"
                                            data-id="<?= $pv['id'] ?>" data-desc="<?= htmlspecialchars($pv['descripcion']) ?>"
                                            data-monto="<?= number_format((float)$pv['monto'], 2) ?>"
                                            data-quincena="0" data-fecha="<?= $pv['fecha'] ?>" data-dia=""
                                            data-archivo="<?= (int)!empty($pv['archivo_adjunto']) ?>">
                                            <i class="fa-solid fa-check me-1"></i> Pagar
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- KPIs -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small mb-1"><?= $periodo_label ?></div>
                    <div class="fs-3 fw-bold text-danger">L <?= number_format((float)$kpi['total_mes'], 2) ?></div>
                    <div class="text-muted small">Total Egresos</div>
                    <?php if ($variacion !== null): ?>
                        <div class="mt-1 small <?= $variacion > 0 ? 'text-danger' : 'text-success' ?>"><i class="fa-solid fa-arrow-<?= $variacion > 0 ? 'up' : 'down' ?> me-1"></i><?= abs($variacion) ?>% vs mes anterior</div>
                    <?php elseif ($vista === 'anual'): ?>
                        <div class="mt-1 small text-muted"><?= count($gastos) ?> registros</div>
                    <?php else: ?><div class="mt-1 small text-muted">Sin período anterior</div><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small mb-1"><i class="fa-solid fa-lock text-primary me-1"></i>Fijos</div>
                    <div class="fs-3 fw-bold text-primary">L <?= number_format((float)$kpi['fijos'], 2) ?></div>
                    <div class="text-muted small">Recurrentes</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small mb-1"><i class="fa-solid fa-chart-line text-info me-1"></i>Variables</div>
                    <div class="fs-3 fw-bold text-info">L <?= number_format((float)$kpi['variables'], 2) ?></div>
                    <div class="text-muted small">Fluctúan</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small mb-1"><i class="fa-solid fa-star text-warning me-1"></i>Extraordinarios</div>
                    <div class="fs-3 fw-bold text-warning">L <?= number_format((float)$kpi['extraordinarios'], 2) ?></div>
                    <div class="text-muted small">No recurrentes</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg">
            <div class="card border-0 shadow-sm h-100 <?= (int)$kpi['pendientes'] > 0 ? 'border border-warning' : '' ?>">
                <div class="card-body text-center py-3">
                    <?php if ((int)$kpi['pendientes'] > 0): ?>
                        <div class="text-muted small mb-1"><i class="fa-solid fa-clock text-warning me-1"></i>Pendientes</div>
                        <div class="fs-3 fw-bold text-warning"><?= (int)$kpi['pendientes'] ?></div>
                        <div class="text-muted small">Sin pagar</div>
                    <?php else: ?>
                        <div class="text-muted small mb-1"><i class="fa-solid fa-calendar text-secondary me-1"></i>Acumulado <?= $anio_filtro ?></div>
                        <div class="fs-4 fw-bold text-dark">L <?= number_format($total_anual, 2) ?></div>
                        <div class="text-muted small">Todo el año</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Por Categoría -->
    <?php if (!empty($cats_resumen)): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-bottom py-3">
                <h6 class="mb-0 fw-bold"><i class="fa-solid fa-chart-pie me-2 text-secondary"></i>Por Categoría — <?= $periodo_label ?></h6>
            </div>
            <div class="card-body p-3">
                <div class="row g-2">
                    <?php $totalCats = array_sum(array_column($cats_resumen, 'total'));
                    foreach ($cats_resumen as $cat):
                        $pct = $totalCats > 0 ? round(($cat['total'] / $totalCats) * 100, 1) : 0; ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="d-flex align-items-center gap-3 p-2 rounded-3 bg-light">
                                <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                                    style="width:38px;height:38px;background:<?= $cat['color'] ?>20;border:1.5px solid <?= $cat['color'] ?>50">
                                    <i class="fa-solid <?= htmlspecialchars($cat['icono']) ?> small" style="color:<?= $cat['color'] ?>"></i>
                                </div>
                                <div class="flex-grow-1 overflow-hidden">
                                    <div class="fw-semibold small text-truncate"><?= htmlspecialchars($cat['nombre']) ?></div>
                                    <div class="d-flex justify-content-between">
                                        <span class="text-muted" style="font-size:11px"><?= $cat['cantidad'] ?> reg.</span>
                                        <span class="fw-bold small text-danger">L <?= number_format((float)$cat['total'], 2) ?></span>
                                    </div>
                                    <div class="progress mt-1" style="height:3px">
                                        <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $cat['color'] ?>"></div>
                                    </div>
                                </div>
                                <small class="text-muted flex-shrink-0"><?= $pct ?>%</small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Tabla de gastos -->
    <div class="card border-0 shadow-sm mb-5">
        <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
            <h6 class="mb-0 fw-bold">
                <i class="fa-solid fa-list me-2 text-secondary"></i>Registros — <?= $periodo_label ?>
                <span class="badge bg-light text-secondary border ms-1"><?= count($gastos) ?></span>
            </h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="tablaGastos" class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Fecha</th>
                            <th>Descripción</th>
                            <th>Categoría</th>
                            <th>Tipo</th>
                            <th class="text-center">Frecuencia</th>
                            <th>Método</th>
                            <th class="text-end">Monto</th>
                            <th class="text-center">Estado</th>
                            <th class="text-center" style="width:120px">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($gastos)): ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted py-5">
                                    <i class="fa-solid fa-inbox fa-2x mb-2 d-block opacity-25"></i>No hay gastos registrados para este período.
                                    <br><button class="btn btn-sm btn-danger mt-2" id="btnNuevoGasto2"><i class="fa-solid fa-plus me-1"></i> Registrar primer gasto</button>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($gastos as $g):
                                $frecIco = ['unico' => '1️⃣', 'mensual' => '📅', 'quincenal' => '🔄'];
                                $frecLabel = ['unico' => 'Único', 'mensual' => 'Mensual', 'quincenal' => 'Quincenal'];
                                $frecColor = ['unico' => 'secondary', 'mensual' => 'primary', 'quincenal' => 'info'];
                            ?>
                                <tr class="<?= $g['estado'] === 'anulado' ? 'opacity-60' : '' ?>">
                                    <td class="small fw-semibold text-nowrap" data-sort="<?= $g['fecha'] ?>"><?= date('d/m/Y', strtotime($g['fecha'])) ?></td>
                                    <td>
                                        <div class="fw-semibold <?= $g['estado'] === 'anulado' ? 'text-decoration-line-through text-muted' : '' ?>"><?= htmlspecialchars($g['descripcion']) ?></div>
                                        <?php if ($g['proveedor']): ?><small class="text-muted"><i class="fa-solid fa-building fa-xs me-1"></i><?= htmlspecialchars($g['proveedor']) ?></small><?php endif; ?>
                                        <?php if ($g['factura_ref']): ?><small class="text-muted ms-1"><i class="fa-solid fa-hashtag fa-xs"></i><?= htmlspecialchars($g['factura_ref']) ?></small><?php endif; ?>
                                        <?php if ($g['fecha_vencimiento']):
                                            $dv = (int)((strtotime($g['fecha_vencimiento']) - strtotime('today')) / 86400);
                                            if ($dv < 0) echo '<small class="text-danger d-block"><i class="fa-solid fa-calendar-xmark me-1"></i>Venció: ' . date('d/m/Y', strtotime($g['fecha_vencimiento'])) . '</small>';
                                            elseif ($dv <= 7) echo '<small class="text-warning d-block"><i class="fa-solid fa-hourglass-half me-1"></i>Vence: ' . date('d/m/Y', strtotime($g['fecha_vencimiento'])) . ' (' . $dv . ' días)</small>';
                                            else echo '<small class="text-muted d-block"><i class="fa-solid fa-calendar-check me-1"></i>Vence: ' . date('d/m/Y', strtotime($g['fecha_vencimiento'])) . '</small>';
                                        endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($g['cat_nombre']): ?>
                                            <span class="badge rounded-pill px-2 py-1" style="background:<?= $g['cat_color'] ?>18;color:<?= $g['cat_color'] ?>;border:1px solid <?= $g['cat_color'] ?>40;font-size:11px">
                                                <i class="fa-solid <?= $g['cat_icono'] ?> me-1"></i><?= htmlspecialchars($g['cat_nombre']) ?>
                                            </span>
                                        <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
                                    </td>
                                    <td>
                                        <?php $tipoCss = ['fijo' => 'primary', 'variable' => 'info', 'extraordinario' => 'warning'];
                                        $tipoLabel = ['fijo' => 'Fijo', 'variable' => 'Variable', 'extraordinario' => 'Extraord.']; ?>
                                        <span class="badge bg-<?= $tipoCss[$g['tipo']] ?? 'secondary' ?> <?= in_array($g['tipo'], ['variable', 'extraordinario']) ? 'text-dark' : '' ?>">
                                            <?= $tipoLabel[$g['tipo']] ?? $g['tipo'] ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-<?= $frecColor[$g['frecuencia']] ?? 'secondary' ?> <?= $g['frecuencia'] === 'quincenal' ? 'text-dark' : '' ?>">
                                            <?= ($frecIco[$g['frecuencia']] ?? '') . ' ' . ($frecLabel[$g['frecuencia']] ?? $g['frecuencia']) ?>
                                        </span>
                                        <?php
                                        if ($g['frecuencia'] === 'quincenal' && $g['quincena_num']) {
                                            echo (int)$g['quincena_num'] === 1
                                                ? '<br><small class="text-primary fw-semibold">1ª · Día ' . (int)$g['dia_pago'] . '</small>'
                                                : '<br><small class="text-info fw-semibold">2ª · Día ' . (int)$g['dia_pago_2'] . '</small>';
                                        } elseif ($g['frecuencia'] === 'mensual' && $g['dia_pago']) {
                                            echo '<br><small class="text-muted">Día ' . (int)$g['dia_pago'] . '</small>';
                                        }
                                        ?>
                                    </td>
                                    <td class="small text-muted">
                                        <?php $metIco = ['efectivo' => '💵', 'transferencia' => '🏦', 'cheque' => '📝', 'tarjeta' => '💳', 'otro' => '🔷'];
                                        echo ($metIco[$g['metodo_pago']] ?? '•') . ' ' . ucfirst($g['metodo_pago']); ?>
                                    </td>
                                    <td class="text-end fw-bold <?= $g['estado'] === 'anulado' ? 'text-decoration-line-through text-muted' : 'text-danger' ?>">
                                        L <?= number_format((float)$g['monto'], 2) ?>
                                    </td>
                                    <td class="text-center">
                                        <?php $stCss = ['pagado' => 'success', 'pendiente' => 'warning text-dark', 'anulado' => 'secondary'];
                                        $stLabel = ['pagado' => 'Pagado', 'pendiente' => 'Pendiente', 'anulado' => 'Anulado']; ?>
                                        <?php if ($g['estado'] === 'pendiente'): ?>
                                            <button class="btn btn-sm badge bg-warning text-dark border-0 btn-registrar-pago"
                                                style="cursor:pointer;font-size:12px;padding:4px 10px" title="Clic para registrar pago"
                                                data-id="<?= $g['id'] ?>" data-desc="<?= htmlspecialchars($g['descripcion']) ?>"
                                                data-monto="<?= number_format((float)$g['monto'], 2) ?>"
                                                data-quincena="<?= (int)$g['quincena_num'] ?>"
                                                data-fecha="<?= htmlspecialchars($g['fecha']) ?>"
                                                data-dia="<?= ($g['frecuencia'] === 'quincenal' && $g['quincena_num'])
                                                                ? 'Día ' . ((int)$g['quincena_num'] === 1 ? (int)$g['dia_pago'] : (int)$g['dia_pago_2']) : '' ?>"
                                                data-archivo="<?= (int)!empty($g['archivo_adjunto']) ?>">
                                                ⏳ Pendiente <i class="fa-solid fa-pen fa-xs ms-1 opacity-50"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="badge bg-<?= $stCss[$g['estado']] ?? 'secondary' ?>"><?= $stLabel[$g['estado']] ?? $g['estado'] ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="d-flex gap-1 justify-content-center">
                                            <a href="gasto_ver.php?id=<?= $g['id'] ?>" target="_blank"
                                                class="btn btn-sm btn-outline-secondary" title="Ver detalle / Comprobante">
                                                <i class="fa-solid fa-eye"></i>
                                            </a>
                                            <?php if ($g['estado'] !== 'anulado'): ?>
                                                <button class="btn btn-sm btn-outline-primary btn-editar" title="Editar"
                                                    data-gasto='<?= json_encode($g, JSON_HEX_APOS | JSON_HEX_QUOT) ?>'>
                                                    <i class="fa-solid fa-pen-to-square"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-warning btn-anular" title="Anular"
                                                    data-id="<?= $g['id'] ?>" data-desc="<?= htmlspecialchars($g['descripcion']) ?>">
                                                    <i class="fa-solid fa-ban"></i>
                                                </button>
                                            <?php endif; ?>
                                            <?php if (in_array(USUARIO_ROL, ['admin', 'superadmin'])): ?>
                                                <button class="btn btn-sm btn-outline-danger btn-eliminar" title="Eliminar"
                                                    data-id="<?= $g['id'] ?>" data-desc="<?= htmlspecialchars($g['descripcion']) ?>">
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($gastos)): ?>
                        <tfoot class="table-light">
                            <tr>
                                <td colspan="6" class="text-end fw-bold small">TOTAL:</td>
                                <td class="text-end fw-bold text-danger">L <?= number_format((float)$kpi['total_mes'], 2) ?></td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: Nuevo / Editar Gasto -->
<div class="modal fade" id="modalGasto" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom py-3">
                <h5 class="modal-title fw-bold" id="modalTitulo"><i class="fa-solid fa-wallet me-2 text-danger"></i>Nuevo Gasto</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form id="formGasto">
                    <input type="hidden" name="gasto_id" id="gasto_id">
                    <input type="hidden" name="gasto_grupo_id" id="gasto_grupo_id">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Descripción <span class="text-danger">*</span></label>
                            <input type="text" name="descripcion" id="descripcion" class="form-control" placeholder="Ej: Pago alquiler oficina" maxlength="300" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Monto (L) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">L</span>
                                <input type="number" name="monto" id="monto" class="form-control" min="0.01" step="0.01" placeholder="0.00" required>
                            </div>
                            <div id="helpMontoUnico" class="form-text text-muted d-none">Monto total del gasto.</div>
                            <div id="helpMontoMensual" class="form-text text-muted d-none">Monto mensual completo.</div>
                            <div id="helpMontoQuincenal" class="form-text text-info d-none">
                                <i class="fa-solid fa-circle-info me-1"></i>Monto <strong>por cada pago</strong>. Ej: L 15,000/mes → ingresar <strong>L 7,500</strong>.
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Fecha <span class="text-danger">*</span> <span class="text-muted small fw-normal" id="labelFechaHelp"></span></label>
                            <input type="date" name="fecha" id="fecha" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Estado</label>
                            <select name="estado" id="estado" class="form-select">
                                <option value="pendiente">⏳ Pendiente</option>
                                <option value="pagado">✅ Pagado</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Frecuencia de Pago</label>
                            <select name="frecuencia" id="frecuencia" class="form-select">
                                <option value="unico">1️⃣ Único / Eventual</option>
                                <option value="mensual">📅 Mensual</option>
                                <option value="quincenal">🔄 Quincenal</option>
                            </select>
                        </div>
                        <div class="col-md-4 d-none" id="grp_dia_pago">
                            <label class="form-label fw-semibold">Día de Pago <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa-solid fa-calendar-day"></i></span>
                                <input type="number" name="dia_pago" id="dia_pago" class="form-control" min="1" max="31" placeholder="Ej: 5">
                                <span class="input-group-text text-muted small">del mes</span>
                            </div>
                        </div>
                        <div class="col-md-4 d-none" id="grp_dia_pago_2">
                            <label class="form-label fw-semibold">2° Día de Pago <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa-solid fa-calendar-day"></i></span>
                                <input type="number" name="dia_pago_2" id="dia_pago_2" class="form-control" min="1" max="31" placeholder="Ej: 20">
                                <span class="input-group-text text-muted small">del mes</span>
                            </div>
                            <div class="form-text">Ej: días 1 y 15, ó 5 y 20</div>
                        </div>
                        <div class="col-md-8 d-none" id="grp_spacer"></div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Categoría</label>
                            <select name="categoria_id" id="categoria_id" class="form-select">
                                <option value="">— Sin categoría —</option>
                                <?php foreach ($categorias as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Tipo</label>
                            <select name="tipo" id="tipo" class="form-select">
                                <option value="variable">Variable</option>
                                <option value="fijo">🔒 Fijo (recurrente)</option>
                                <option value="extraordinario">⭐ Extraordinario</option>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">Proveedor / Beneficiario</label>
                            <input type="text" name="proveedor" id="proveedor" class="form-control" placeholder="Nombre del proveedor" maxlength="200">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">N° Factura / Recibo</label>
                            <input type="text" name="factura_ref" id="factura_ref" class="form-control" placeholder="000-001-01-..." maxlength="100">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Método Pago</label>
                            <select name="metodo_pago" id="metodo_pago" class="form-select">
                                <option value="efectivo">💵 Efectivo</option>
                                <option value="transferencia">🏦 Transferencia</option>
                                <option value="tarjeta">💳 Tarjeta</option>
                                <option value="cheque">📝 Cheque</option>
                                <option value="otro">🔷 Otro</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Notas <span class="text-muted small">(opcional)</span></label>
                            <textarea name="notas" id="notas" class="form-control" rows="2" placeholder="Observaciones adicionales..." maxlength="500"></textarea>
                        </div>
                        <div class="col-12">
                            <hr class="my-1 opacity-25">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">
                                <i class="fa-solid fa-calendar-xmark me-1 text-warning"></i>
                                Fecha de Vencimiento <span class="text-muted small fw-normal">(opcional)</span>
                            </label>
                            <input type="date" name="fecha_vencimiento" id="fecha_vencimiento" class="form-control">
                            <div class="form-text">
                                Para recurrentes: hasta cuándo se generan los pagos.
                                El sistema crea <strong>una fila por cada ocurrencia</strong> desde la fecha inicio hasta aquí.
                            </div>
                        </div>
                        <div class="col-md-6 d-flex align-items-end pb-1">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="btnLimpiarVencimiento">
                                <i class="fa-solid fa-xmark me-1"></i> Sin fecha de vencimiento
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-top">
                <div id="grpActualizarAmbas" class="me-auto d-none">
                    <div class="form-check mb-1">
                        <input class="form-check-input" type="checkbox" id="chkActualizarAmbas" name="actualizar_grupo" value="1">
                        <label class="form-check-label small text-muted" for="chkActualizarAmbas">
                            <i class="fa-solid fa-link me-1"></i> Actualizar también la otra quincena del grupo
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="chkActualizarFuturos" name="actualizar_futuros" value="1">
                        <label class="form-check-label small text-muted" for="chkActualizarFuturos">
                            <i class="fa-solid fa-forward me-1"></i> Actualizar todos los pagos futuros del grupo
                        </label>
                    </div>
                </div>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-danger px-4" id="btnGuardarGasto">
                    <i class="fa-solid fa-floppy-disk me-1"></i> Guardar Gasto
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL: Registrar Pago -->
<div class="modal fade" id="modalRegistrarPago" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-success bg-opacity-10 border-bottom py-3">
                <h5 class="modal-title fw-bold text-success"><i class="fa-solid fa-circle-check me-2"></i>Registrar Pago</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form id="formRegistrarPago" enctype="multipart/form-data">
                    <input type="hidden" name="gasto_id" id="rp_gasto_id">
                    <input type="hidden" name="_solo_estado" value="1">
                    <input type="hidden" name="estado" value="pagado">
                    <div class="alert alert-light border mb-3 py-2 px-3">
                        <div class="fw-bold" id="rp_desc">—</div>
                        <div class="text-muted small" id="rp_detalle" style="display:none"></div>
                        <div class="text-success fw-bold fs-5 mt-1" id="rp_monto">L 0.00</div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><i class="fa-solid fa-calendar-day me-1 text-success"></i>Fecha del Pago</label>
                            <input type="date" name="fecha_pago_real" id="rp_fecha" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><i class="fa-solid fa-credit-card me-1 text-success"></i>Método de Pago</label>
                            <select name="metodo_pago_reg" id="rp_metodo" class="form-select">
                                <option value="">— Sin cambiar —</option>
                                <option value="efectivo">💵 Efectivo</option>
                                <option value="transferencia">🏦 Transferencia</option>
                                <option value="tarjeta">💳 Tarjeta</option>
                                <option value="cheque">📝 Cheque</option>
                                <option value="otro">🔷 Otro</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Notas <span class="text-muted small fw-normal">(opcional)</span></label>
                            <textarea name="notas_pago" id="rp_notas" class="form-control" rows="2" placeholder="N° transferencia, referencia, banco..."></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">
                                <i class="fa-solid fa-paperclip me-1 text-secondary"></i>
                                Adjuntar Comprobante <span class="text-muted small fw-normal">(JPG, PNG, PDF · máx 5 MB)</span>
                            </label>
                            <div id="rpZonaArchivo" class="border border-2 border-dashed rounded-3 p-3 text-center position-relative"
                                style="cursor:pointer;transition:border-color .2s">
                                <input type="file" name="archivo_adjunto" id="rp_archivo" accept=".jpg,.jpeg,.png,.webp,.pdf"
                                    class="position-absolute top-0 start-0 w-100 h-100 opacity-0" style="cursor:pointer;z-index:2">
                                <div id="rpZonaTexto">
                                    <i class="fa-solid fa-cloud-arrow-up fa-2x text-muted mb-1 d-block"></i>
                                    <span class="text-muted small">Clic o arrastra el comprobante aquí</span>
                                </div>
                                <div id="rpZonaPreview" class="d-none">
                                    <i class="fa-solid fa-check-circle text-success me-1"></i>
                                    <span id="rpArchNombre" class="fw-semibold small"></span>
                                    <button type="button" id="rpBtnQuitar" class="btn btn-sm btn-link text-danger p-0 ms-2" style="font-size:12px">
                                        <i class="fa-solid fa-xmark"></i> Quitar
                                    </button>
                                </div>
                            </div>
                            <div id="rpArchivoExistente" class="d-none mt-2">
                                <small class="text-success"><i class="fa-solid fa-check me-1"></i>Ya tiene comprobante — uno nuevo lo reemplazará.</small>
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

        $('#selectVista').on('change', function() {
            $('#grpMes').toggle($(this).val() === 'mensual');
        });

        <?php if (!empty($gastos)): ?>
            $('#tablaGastos').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json'
                },
                order: [
                    [0, 'desc']
                ],
                pageLength: 10,
                lengthMenu: [
                    [10, 25, 50, -1],
                    ['10 filas', '25 filas', '50 filas', 'Todos']
                ],
                columnDefs: [{
                        orderable: false,
                        targets: [8]
                    },
                    {
                        orderData: [0],
                        type: 'string',
                        targets: [0]
                    }
                ]
            });
        <?php endif; ?>

        function actualizarCamposDias() {
            const frec = $('#frecuencia').val();
            $('#grp_dia_pago, #grp_dia_pago_2, #grp_spacer').addClass('d-none');
            $('#dia_pago, #dia_pago_2').prop('required', false).val('');
            $('#helpMontoUnico, #helpMontoMensual, #helpMontoQuincenal').addClass('d-none');
            $('#labelFechaHelp').text('');
            if (frec === 'mensual') {
                $('#grp_dia_pago, #grp_spacer').removeClass('d-none');
                $('#dia_pago').prop('required', true);
                $('#helpMontoMensual').removeClass('d-none');
                $('#labelFechaHelp').text('(inicio del recurrente)');
            } else if (frec === 'quincenal') {
                $('#grp_dia_pago, #grp_dia_pago_2').removeClass('d-none');
                $('#dia_pago, #dia_pago_2').prop('required', true);
                $('#helpMontoQuincenal').removeClass('d-none');
                $('#labelFechaHelp').text('(inicio del recurrente)');
            }
        }
        $('#frecuencia').on('change', actualizarCamposDias);

        function abrirModalNuevo() {
            $('#modalTitulo').html('<i class="fa-solid fa-wallet me-2 text-danger"></i>Nuevo Gasto');
            $('#formGasto')[0].reset();
            $('#gasto_id, #gasto_grupo_id').val('');
            $('#grpActualizarAmbas').addClass('d-none');
            $('#fecha').val(new Date().toISOString().slice(0, 10));
            actualizarCamposDias();
            $('#modalGasto').modal('show');
        }
        $('#btnNuevoGasto, #btnNuevoGasto2').on('click', abrirModalNuevo);

        $(document).on('click', '.btn-editar', function() {
            const g = $(this).data('gasto');
            $('#modalTitulo').html('<i class="fa-solid fa-pen-to-square me-2 text-primary"></i>Editar Gasto');
            $('#gasto_id').val(g.id);
            $('#descripcion').val(g.descripcion);
            $('#monto').val(parseFloat(g.monto).toFixed(2));
            $('#fecha').val(g.fecha);
            $('#estado').val(g.estado);
            $('#frecuencia').val(g.frecuencia || 'unico');
            actualizarCamposDias();
            $('#dia_pago').val(g.dia_pago || '');
            $('#dia_pago_2').val(g.dia_pago_2 || '');
            $('#categoria_id').val(g.categoria_id || '');
            $('#tipo').val(g.tipo);
            $('#proveedor').val(g.proveedor || '');
            $('#factura_ref').val(g.factura_ref || '');
            $('#metodo_pago').val(g.metodo_pago);
            $('#notas').val(g.notas || '');
            $('#fecha_vencimiento').val(g.fecha_vencimiento || '');
            $('#gasto_grupo_id').val(g.gasto_grupo_id || '');
            $('#chkActualizarAmbas, #chkActualizarFuturos').prop('checked', false);

            if (g.gasto_grupo_id) {
                const qnom = parseInt(g.quincena_num) === 1 ? '1ª' : (parseInt(g.quincena_num) === 2 ? '2ª' : '');
                $('label[for="chkActualizarAmbas"]').html(
                    qnom ? `<i class="fa-solid fa-link me-1"></i> Editando la <strong>${qnom} Quincena</strong> — también actualizar la otra` :
                    '<i class="fa-solid fa-link me-1"></i> Actualizar también la otra quincena del grupo'
                );
                $('#grpActualizarAmbas').removeClass('d-none');
            } else {
                $('#grpActualizarAmbas').addClass('d-none');
            }
            $('#modalGasto').modal('show');
        });

        $('#btnGuardarGasto').on('click', function() {
            const desc = $('#descripcion').val().trim();
            const monto = parseFloat($('#monto').val()) || 0;
            const fecha = $('#fecha').val();
            const frec = $('#frecuencia').val();
            const dia1 = parseInt($('#dia_pago').val()) || 0;
            const dia2 = parseInt($('#dia_pago_2').val()) || 0;

            if (!desc) return Swal.fire({
                icon: 'warning',
                title: 'Campo requerido',
                text: 'La descripción es obligatoria.'
            });
            if (monto <= 0) return Swal.fire({
                icon: 'warning',
                title: 'Monto inválido',
                text: 'El monto debe ser mayor a 0.'
            });
            if (!fecha) return Swal.fire({
                icon: 'warning',
                title: 'Campo requerido',
                text: 'La fecha es obligatoria.'
            });
            if (frec === 'mensual' && (!dia1 || dia1 < 1 || dia1 > 31))
                return Swal.fire({
                    icon: 'warning',
                    title: 'Día de pago',
                    text: 'Ingresa el día del mes (1-31).'
                });
            if (frec === 'quincenal') {
                if (!dia1 || !dia2) return Swal.fire({
                    icon: 'warning',
                    title: 'Días de pago',
                    text: 'Ingresa ambos días.'
                });
                if (dia1 >= dia2) return Swal.fire({
                    icon: 'warning',
                    title: 'Días de pago',
                    text: 'El primer día debe ser menor al segundo.'
                });
            }

            const esEditar = !!$('#gasto_id').val();
            const url = esEditar ? 'includes/gasto_actualizar.php' : 'includes/gasto_guardar.php';
            const btn = $(this);
            btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-1"></i> Guardando...');
            $.post(url, $('#formGasto').serialize())
                .done(data => {
                    if (data.success) {
                        Swal.fire({
                                icon: 'success',
                                title: '¡Listo!',
                                text: data.message,
                                timer: 1800,
                                showConfirmButton: false
                            })
                            .then(() => location.reload());
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: data.error
                        });
                        btn.prop('disabled', false).html('<i class="fa-solid fa-floppy-disk me-1"></i> Guardar Gasto');
                    }
                })
                .fail(() => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error de conexión'
                    });
                    btn.prop('disabled', false).html('<i class="fa-solid fa-floppy-disk me-1"></i> Guardar Gasto');
                });
        });

        function fmtDMY(iso) {
            if (!iso) return '';
            const [y, m, d] = iso.split('-');
            return `${d}/${m}/${y}`;
        }

        function abrirModalPago(id, desc, monto, tieneArchivo, quincenaNum, fechaProg, diaTexto) {
            $('#rp_gasto_id').val(id);
            $('#rp_desc').text(desc);
            let det = '';
            const q = parseInt(quincenaNum || 0);
            if (q === 1) det = '1ª Quincena';
            else if (q === 2) det = '2ª Quincena';
            if (diaTexto) det += (det ? ' · ' : '') + diaTexto;
            if (fechaProg) det += (det ? ' · ' : '') + 'Programado: ' + fmtDMY(fechaProg);
            det ? $('#rp_detalle').text(det).show() : $('#rp_detalle').hide().text('');
            $('#rp_monto').text('L ' + monto);
            $('#rp_fecha').val(fechaProg || new Date().toISOString().slice(0, 10));
            $('#rp_metodo').val('');
            $('#rp_notas').val('');
            $('#rp_archivo').val('');
            $('#rpZonaPreview').addClass('d-none');
            $('#rpZonaTexto').removeClass('d-none');
            $('#rpZonaArchivo').css('border-color', '');
            $('#rpArchivoExistente').toggleClass('d-none', !tieneArchivo);
            $('#modalRegistrarPago').modal('show');
        }

        $(document).on('click', '.btn-registrar-pago', function() {
            abrirModalPago($(this).data('id'), $(this).data('desc'), $(this).data('monto'),
                parseInt($(this).data('archivo')) === 1,
                $(this).data('quincena'), $(this).data('fecha'), $(this).data('dia'));
        });

        $('#rp_archivo').on('change', function() {
            const file = this.files[0];
            if (file) {
                $('#rpZonaTexto').addClass('d-none');
                $('#rpArchNombre').text(file.name + ' (' + (file.size / 1024 / 1024).toFixed(2) + ' MB)');
                $('#rpZonaPreview').removeClass('d-none');
                $('#rpZonaArchivo').css('border-color', '#198754');
            }
        });
        $('#rpBtnQuitar').on('click', function(e) {
            e.stopPropagation();
            $('#rp_archivo').val('');
            $('#rpZonaPreview').addClass('d-none');
            $('#rpZonaTexto').removeClass('d-none');
            $('#rpZonaArchivo').css('border-color', '');
        });

        $('#btnConfirmarPago').on('click', function() {
            const btn = $(this);
            btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-1"></i> Guardando...');
            const fd = new FormData(document.getElementById('formRegistrarPago'));
            $.ajax({
                    url: 'includes/gasto_actualizar.php',
                    type: 'POST',
                    data: fd,
                    processData: false,
                    contentType: false,
                    dataType: 'json'
                })
                .done(d => {
                    if (d.success) {
                        $('#modalRegistrarPago').modal('hide');
                        Swal.fire({
                            icon: 'success',
                            title: '¡Pago registrado!',
                            timer: 1500,
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

        $('#btnLimpiarVencimiento').on('click', function() {
            $('#fecha_vencimiento').val('');
        });

        $(document).on('click', '.btn-anular', function() {
            const id = $(this).data('id'),
                desc = $(this).data('desc');
            Swal.fire({
                title: '¿Anular este gasto?',
                html: `<strong>${desc}</strong><br><small class="text-muted">No contará en totales pero quedará en historial.</small>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ffc107',
                confirmButtonText: 'Sí, anular',
                cancelButtonText: 'Cancelar'
            }).then(r => {
                if (!r.isConfirmed) return;
                $.post('includes/gasto_eliminar.php', {
                    id,
                    accion: 'anular'
                }, d => {
                    if (d.success) Swal.fire({
                        icon: 'success',
                        title: 'Anulado',
                        timer: 1400,
                        showConfirmButton: false
                    }).then(() => location.reload());
                    else Swal.fire('Error', d.error, 'error');
                }, 'json');
            });
        });

        $(document).on('click', '.btn-eliminar', function() {
            const id = $(this).data('id'),
                desc = $(this).data('desc');
            Swal.fire({
                title: '¿Eliminar definitivamente?',
                html: `<strong>${desc}</strong><br><span class="text-danger small">Esta acción no se puede deshacer.</span>`,
                icon: 'error',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar'
            }).then(r => {
                if (!r.isConfirmed) return;
                $.post('includes/gasto_eliminar.php', {
                    id,
                    accion: 'eliminar'
                }, d => {
                    if (d.success) Swal.fire({
                        icon: 'success',
                        title: 'Eliminado',
                        timer: 1400,
                        showConfirmButton: false
                    }).then(() => location.reload());
                    else Swal.fire('Error', d.error, 'error');
                }, 'json');
            });
        });

    });
</script>

</body>

</html>