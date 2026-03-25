<?php
$titulo = 'Facturas del Contrato';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';
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
           cf.nombre   AS receptor_nombre,
           cf.rtn      AS receptor_rtn,
           cf.email    AS receptor_email,
           cf.telefono AS receptor_tel,
           p.nombre    AS producto_nombre
    FROM contratos c
    INNER JOIN clientes_factura   cf ON cf.id = c.receptor_id AND cf.cliente_id = c.cliente_id
    INNER JOIN productos_clientes p  ON p.id  = c.producto_id AND p.cliente_id  = c.cliente_id
    WHERE c.id = ? AND c.cliente_id = ?
");
$stmtC->execute([$contrato_id, $cliente_id]);
$contrato = $stmtC->fetch(PDO::FETCH_ASSOC);
if (!$contrato) {
    header('Location: contratos');
    exit;
}

// ── Facturas de este contrato ─────────────────────────────────────────────────
$stmtF = $pdo->prepare("
    SELECT f.*
    FROM facturas f
    WHERE f.contrato_id = ?
      AND f.cliente_id  = ?
      AND f.estado      = 'emitida'
    ORDER BY f.id DESC
");
$stmtF->execute([$contrato_id, $cliente_id]);
$facturas = $stmtF->fetchAll(PDO::FETCH_ASSOC);

// ── Totales ───────────────────────────────────────────────────────────────────
$totalFacturado = 0;
$totalIsv       = 0;
$totalSubtotal  = 0;
foreach ($facturas as $f) {
    $totalFacturado += (float)$f['total'];
    $totalIsv       += (float)$f['isv_15'] + (float)$f['isv_18'];
    $totalSubtotal  += (float)$f['subtotal'];
}

// ── Meses esperados vs facturados ─────────────────────────────────────────────
$fechaInicio = new DateTime($contrato['fecha_inicio']);
$fechaRef    = $contrato['fecha_fin'] ? new DateTime($contrato['fecha_fin']) : new DateTime();
$fechaRef    = min($fechaRef, new DateTime());

$mesesEsperados = [];
$cursor = clone $fechaInicio;
$cursor->modify('first day of this month');
while ($cursor <= $fechaRef) {
    $mesesEsperados[] = $cursor->format('Y-m');
    $cursor->modify('+1 month');
}

$mesesConFactura = [];
foreach ($facturas as $f) {
    $mes = substr($f['fecha_emision'], 0, 7);
    $mesesConFactura[$mes] = true;
}
$mesesSinFactura = array_filter($mesesEsperados, fn($m) => !isset($mesesConFactura[$m]));

$totalMeses    = count($mesesEsperados);
$mesesOk       = count($mesesConFactura);
$mesesPend     = count($mesesSinFactura);
$pctCumplimiento = $totalMeses > 0 ? round(($mesesOk / $totalMeses) * 100) : 0;

$estadoBadge = [
    'activo'    => '<span class="badge bg-success px-3 py-2">Activo</span>',
    'vencido'   => '<span class="badge bg-danger px-3 py-2">Vencido</span>',
    'cancelado' => '<span class="badge bg-secondary px-3 py-2">Cancelado</span>',
    'pausado'   => '<span class="badge bg-warning text-dark px-3 py-2">Pausado</span>',
];

$noIniciado = (new DateTime($contrato['fecha_inicio'])) > new DateTime();

// ── Agrupar meses por año para la línea de tiempo ─────────────────────────────
$mesesPorAnio = [];
foreach (array_reverse($mesesEsperados) as $mes) {
    $anio = substr($mes, 0, 4);
    $mesesPorAnio[$anio][] = $mes;
}
?>

<style>
    /* ── Tarjeta contrato ─────────────────────────────────────────────────────── */
    .contrato-avatar {
        width: 52px;
        height: 52px;
        border-radius: 14px;
        background: linear-gradient(135deg, #0d6efd20, #0d6efd40);
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    /* ── KPIs ────────────────────────────────────────────────────────────────── */
    .kpi-card {
        border-radius: 12px;
        border: none;
        transition: transform .15s;
    }

    .kpi-card:hover {
        transform: translateY(-2px);
    }

    .kpi-value {
        font-size: 1.6rem;
        font-weight: 700;
        line-height: 1;
    }

    .kpi-label {
        font-size: .72rem;
        text-transform: uppercase;
        letter-spacing: .04em;
    }

    /* ── Resumen por mes ─────────────────────────────────────────────────────── */
    .mes-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        white-space: nowrap;
        transition: transform .1s;
    }

    .mes-pill:hover {
        transform: scale(1.04);
    }

    .mes-pill.ok {
        background: #d1fae5;
        color: #065f46;
        border: 1px solid #6ee7b7;
    }

    .mes-pill.pend {
        background: #fee2e2;
        color: #991b1b;
        border: 1px solid #fca5a5;
    }

    .mes-pill.actual {
        background: #fef9c3;
        color: #713f12;
        border: 1px solid #fde047;
    }

    .anio-header {
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .08em;
        color: #6b7280;
        margin-bottom: 8px;
        padding-bottom: 4px;
        border-bottom: 2px solid #e5e7eb;
    }

    /* ── Barra de progreso cumplimiento ─────────────────────────────────────── */
    .progress-lg {
        height: 10px;
        border-radius: 99px;
    }

    /* ── Tabla ───────────────────────────────────────────────────────────────── */
    .table th {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .04em;
    }
</style>

<div class="container-xxl mt-4 mb-5">

    <!-- ── Cabecera ─────────────────────────────────────────────────────────── -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <a href="contratos" class="btn btn-sm btn-outline-secondary me-2">
                <i class="fa-solid fa-arrow-left me-1"></i> Volver
            </a>
            <h4 class="d-inline-block mb-0">
                <i class="fa-solid fa-receipt me-2 text-info"></i>Facturas del Contrato
            </h4>
        </div>
        <?php if (!$noIniciado && $contrato['estado'] === 'activo'): ?>
            <a href="generar_factura?receptor_id=<?= $contrato['receptor_id'] ?>&producto_id=<?= $contrato['producto_id'] ?>&monto=<?= $contrato['monto'] ?>&contrato_id=<?= $contrato['id'] ?>"
                class="btn btn-success">
                <i class="fa-solid fa-file-invoice-dollar me-1"></i> Nueva Factura
            </a>
        <?php endif; ?>
    </div>

    <!-- ── Tarjeta del contrato ──────────────────────────────────────────────── -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-4">
            <div class="row g-4 align-items-center">

                <!-- Info del cliente -->
                <div class="col-lg-5">
                    <div class="d-flex align-items-start gap-3">
                        <div class="contrato-avatar">
                            <i class="fa-solid fa-file-contract fa-lg text-primary"></i>
                        </div>
                        <div>
                            <h6 class="mb-1 fw-bold lh-sm"><?= htmlspecialchars($contrato['nombre_contrato']) ?></h6>
                            <div class="fw-semibold text-dark mb-1">
                                <i class="fa-solid fa-building text-muted me-1"></i>
                                <?= htmlspecialchars($contrato['receptor_nombre']) ?>
                                <?php if ($contrato['receptor_rtn']): ?>
                                    <span class="text-muted fw-normal small ms-1">· RTN:
                                        <?= htmlspecialchars($contrato['receptor_rtn']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex flex-wrap gap-3 mt-2">
                                <?php if ($contrato['receptor_tel']): ?>
                                    <span class="text-muted small">
                                        <i
                                            class="fa-solid fa-phone me-1"></i><?= htmlspecialchars($contrato['receptor_tel']) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($contrato['receptor_email']): ?>
                                    <span class="text-muted small">
                                        <i
                                            class="fa-solid fa-envelope me-1"></i><?= htmlspecialchars($contrato['receptor_email']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Detalles del contrato -->
                <div class="col-lg-7">
                    <div class="row g-3">
                        <div class="col-sm-4 text-center">
                            <div class="text-muted small mb-1">Monto mensual</div>
                            <div class="fw-bold fs-5 text-primary">L <?= number_format((float)$contrato['monto'], 2) ?>
                            </div>
                        </div>
                        <div class="col-sm-4 text-center">
                            <div class="text-muted small mb-1">Estado</div>
                            <div><?= $estadoBadge[$contrato['estado']] ?? '' ?></div>
                        </div>
                        <div class="col-sm-4 text-center">
                            <div class="text-muted small mb-1">Día de cobro</div>
                            <div class="fw-bold">Día <?= (int)$contrato['dia_pago'] ?></div>
                        </div>
                        <div class="col-sm-4 text-center">
                            <div class="text-muted small mb-1">Inicio</div>
                            <div class="small fw-semibold"><?= date('d/m/Y', strtotime($contrato['fecha_inicio'])) ?>
                            </div>
                        </div>
                        <div class="col-sm-4 text-center">
                            <div class="text-muted small mb-1">Vencimiento</div>
                            <div class="small fw-semibold">
                                <?= $contrato['fecha_fin']
                                    ? date('d/m/Y', strtotime($contrato['fecha_fin']))
                                    : '<span class="badge bg-info">Indefinido</span>' ?>
                            </div>
                        </div>
                        <div class="col-sm-4 text-center">
                            <div class="text-muted small mb-1">Servicio</div>
                            <div class="small fw-semibold text-truncate"
                                title="<?= htmlspecialchars($contrato['producto_nombre']) ?>">
                                <?= htmlspecialchars($contrato['producto_nombre']) ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- ── KPIs ─────────────────────────────────────────────────────────────── -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card kpi-card shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-3 p-3">
                    <div class="rounded-3 p-2 bg-info bg-opacity-10 flex-shrink-0">
                        <i class="fa-solid fa-file-invoice fa-lg text-info"></i>
                    </div>
                    <div>
                        <div class="kpi-value text-info"><?= count($facturas) ?></div>
                        <div class="kpi-label text-muted">Facturas</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card kpi-card shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-3 p-3">
                    <div class="rounded-3 p-2 bg-secondary bg-opacity-10 flex-shrink-0">
                        <i class="fa-solid fa-calculator fa-lg text-secondary"></i>
                    </div>
                    <div>
                        <div class="kpi-value text-secondary" style="font-size:1.15rem">L
                            <?= number_format($totalSubtotal, 2) ?></div>
                        <div class="kpi-label text-muted">Subtotal</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card kpi-card shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-3 p-3">
                    <div class="rounded-3 p-2 bg-warning bg-opacity-10 flex-shrink-0">
                        <i class="fa-solid fa-percent fa-lg text-warning"></i>
                    </div>
                    <div>
                        <div class="kpi-value text-warning" style="font-size:1.15rem">L
                            <?= number_format($totalIsv, 2) ?></div>
                        <div class="kpi-label text-muted">Total ISV</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card kpi-card shadow-sm h-100">
                <div class="card-body d-flex align-items-center gap-3 p-3">
                    <div class="rounded-3 p-2 bg-primary bg-opacity-10 flex-shrink-0">
                        <i class="fa-solid fa-circle-dollar-to-slot fa-lg text-primary"></i>
                    </div>
                    <div>
                        <div class="kpi-value text-primary" style="font-size:1.15rem">L
                            <?= number_format($totalFacturado, 2) ?></div>
                        <div class="kpi-label text-muted">Total facturado</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Alerta contrato no iniciado ──────────────────────────────────────── -->
    <?php if ($noIniciado): ?>
        <div class="alert alert-info mb-4">
            <i class="fa-solid fa-clock me-2"></i>
            Este contrato aún no ha iniciado. Comienza el
            <strong><?= date('d/m/Y', strtotime($contrato['fecha_inicio'])) ?></strong>.
        </div>
    <?php endif; ?>

    <!-- ── Alerta meses sin factura ──────────────────────────────────────────── -->
    <?php if (!empty($mesesSinFactura) && !$noIniciado): ?>
        <div class="alert alert-warning d-flex align-items-start gap-2 mb-4">
            <i class="fa-solid fa-triangle-exclamation mt-1 flex-shrink-0"></i>
            <div>
                <strong><?= $mesesPend ?> mes(es) sin factura:</strong>
                <div class="mt-2 d-flex flex-wrap gap-1">
                    <?php foreach ($mesesSinFactura as $ms):
                        $dt = DateTime::createFromFormat('Y-m', $ms);
                    ?>
                        <span class="badge bg-warning text-dark">
                            <?= $dt ? $dt->format('M Y') : $ms ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- ── Historial de facturas ─────────────────────────────────────────────── -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
            <h6 class="mb-0 fw-bold">
                <i class="fa-solid fa-list me-2 text-info"></i>Historial de Facturas
            </h6>
            <span class="badge bg-info bg-opacity-15 text-info border border-info border-opacity-25 px-3 py-2">
                <?= count($facturas) ?> factura(s)
            </span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($facturas)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fa-solid fa-file-invoice fa-3x mb-3 opacity-25"></i>
                    <p class="mb-3">No hay facturas emitidas para este contrato.</p>
                    <?php if (!$noIniciado && $contrato['estado'] === 'activo'): ?>
                        <a href="generar_factura?receptor_id=<?= $contrato['receptor_id'] ?>&producto_id=<?= $contrato['producto_id'] ?>&monto=<?= $contrato['monto'] ?>&contrato_id=<?= $contrato['id'] ?>"
                            class="btn btn-success">
                            <i class="fa-solid fa-file-invoice-dollar me-1"></i> Crear Primera Factura
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table id="tablaFacturasContrato" class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Correlativo</th>
                                <th>Fecha</th>
                                <th class="text-end">Subtotal</th>
                                <th class="text-end">ISV</th>
                                <th class="text-end">Total</th>
                                <th class="text-center">Declarada</th>
                                <th class="text-center">Pagada</th>
                                <th class="text-center">Ver</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($facturas as $f):
                                $isv         = (float)$f['isv_15'] + (float)$f['isv_18'];
                                $esMesActual = (substr($f['fecha_emision'], 0, 7) === date('Y-m'));
                            ?>
                                <tr <?= $esMesActual ? 'class="table-success"' : '' ?>>
                                    <td class="text-muted small"><?= $f['id'] ?></td>
                                    <td>
                                        <span
                                            class="fw-semibold font-monospace small"><?= htmlspecialchars($f['correlativo']) ?></span>
                                        <?php if ($esMesActual): ?>
                                            <br><span class="badge bg-success text-white small">Este mes</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?= date('d/m/Y', strtotime($f['fecha_emision'])) ?></div>
                                        <small class="text-muted"><?= date('H:i', strtotime($f['fecha_emision'])) ?></small>
                                    </td>
                                    <td class="text-end text-muted small">L <?= number_format((float)$f['subtotal'], 2) ?></td>
                                    <td class="text-end text-muted small">L <?= number_format($isv, 2) ?></td>
                                    <td class="text-end fw-bold text-primary">L <?= number_format((float)$f['total'], 2) ?></td>
                                    <td class="text-center">
                                        <?php if ((int)$f['estado_declarada']): ?>
                                            <span class="badge bg-success text-white">✓ Declarada</span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-dark border">Pendiente</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ((int)$f['pagada']): ?>
                                            <span class="badge bg-success text-white">✓ Pagada</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Pendiente</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <a href="ver_factura?id=<?= $f['id'] ?>" class="btn btn-sm btn-outline-primary"
                                            target="_blank" title="Ver factura">
                                            <i class="fa-solid fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr class="fw-bold">
                                <td colspan="3" class="text-end text-muted small">Totales:</td>
                                <td class="text-end">L <?= number_format($totalSubtotal, 2) ?></td>
                                <td class="text-end">L <?= number_format($totalIsv, 2) ?></td>
                                <td class="text-end text-primary">L <?= number_format($totalFacturado, 2) ?></td>
                                <td colspan="3"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Resumen por mes ───────────────────────────────────────────────────── -->
    <?php if (!empty($mesesEsperados)): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom py-3">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h6 class="mb-0 fw-bold">
                        <i class="fa-solid fa-calendar-days me-2 text-secondary"></i>Resumen por Mes
                    </h6>
                    <!-- Barra de cumplimiento -->
                    <div class="d-flex align-items-center gap-3" style="min-width:260px">
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between mb-1">
                                <small class="text-muted">Cumplimiento</small>
                                <small
                                    class="fw-bold <?= $pctCumplimiento >= 80 ? 'text-success' : ($pctCumplimiento >= 50 ? 'text-warning' : 'text-danger') ?>">
                                    <?= $mesesOk ?>/<?= $totalMeses ?> meses (<?= $pctCumplimiento ?>%)
                                </small>
                            </div>
                            <div class="progress progress-lg">
                                <div class="progress-bar <?= $pctCumplimiento >= 80 ? 'bg-success' : ($pctCumplimiento >= 50 ? 'bg-warning' : 'bg-danger') ?>"
                                    style="width:<?= $pctCumplimiento ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-body p-4">

                <!-- Leyenda -->
                <div class="d-flex gap-3 mb-4">
                    <span class="mes-pill ok"><i class="fa-solid fa-check fa-xs"></i> Facturado</span>
                    <span class="mes-pill pend"><i class="fa-solid fa-xmark fa-xs"></i> Sin factura</span>
                    <span class="mes-pill actual"><i class="fa-solid fa-clock fa-xs"></i> Mes actual</span>
                </div>

                <!-- Meses agrupados por año -->
                <?php foreach ($mesesPorAnio as $anio => $meses): ?>
                    <div class="mb-4">
                        <div class="anio-header">
                            <i class="fa-solid fa-calendar me-1"></i><?= $anio ?>
                            <span class="ms-2 fw-normal text-muted">
                                (<?= count(array_filter($meses, fn($m) => isset($mesesConFactura[$m]))) ?>/<?= count($meses) ?>
                                facturados)
                            </span>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($meses as $mes):
                                $dt      = DateTime::createFromFormat('Y-m', $mes);
                                $label   = $dt ? $dt->format('M') : $mes;
                                $tieneF  = isset($mesesConFactura[$mes]);
                                $esAct   = ($mes === date('Y-m'));
                                $clase   = $esAct ? 'actual' : ($tieneF ? 'ok' : 'pend');
                                $icono   = $esAct ? 'fa-clock' : ($tieneF ? 'fa-check' : 'fa-xmark');
                            ?>
                                <span class="mes-pill <?= $clase ?>">
                                    <i class="fa-solid <?= $icono ?> fa-xs"></i>
                                    <?= $label ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

            </div>
        </div>
    <?php endif; ?>

</div>

<script>
    $(function() {
        $('#tablaFacturasContrato').DataTable({
            language: {
                url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json'
            },
            order: [
                [1, 'desc']
            ],
            pageLength: 10,
            lengthMenu: [10, 25, 50, 100],
            columnDefs: [{
                orderable: false,
                targets: [7]
            }]
        });
    });
</script>

</body>

</html>