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
if (!$contrato_id) { header('Location: contratos'); exit; }

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
if (!$contrato) { header('Location: contratos'); exit; }

// ── Facturas de este contrato (por contrato_id) ───────────────────────────────
$stmtF = $pdo->prepare("
    SELECT f.*
    FROM facturas f
    WHERE f.contrato_id = ?
      AND f.cliente_id  = ?
      AND f.estado      = 'emitida'
    ORDER BY f.fecha_emision DESC
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
$fechaRef    = min($fechaRef, new DateTime()); // no más allá de hoy

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

$estadoBadge = [
    'activo'    => '<span class="badge bg-success">Activo</span>',
    'vencido'   => '<span class="badge bg-danger">Vencido</span>',
    'cancelado' => '<span class="badge bg-secondary">Cancelado</span>',
    'pausado'   => '<span class="badge bg-warning text-dark">Pausado</span>',
];

$noIniciado = (new DateTime($contrato['fecha_inicio'])) > new DateTime();
?>

<div class="container-xxl mt-4">

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
        <div class="card-body">
            <div class="row g-3 align-items-center">
                <div class="col-md-6">
                    <div class="d-flex align-items-start gap-3">
                        <div class="rounded-circle bg-primary bg-opacity-10 p-3 flex-shrink-0">
                            <i class="fa-solid fa-file-contract fa-lg text-primary"></i>
                        </div>
                        <div>
                            <h5 class="mb-1 fw-bold"><?= htmlspecialchars($contrato['nombre_contrato']) ?></h5>
                            <div class="text-muted small">
                                <i class="fa-solid fa-user me-1"></i>
                                <strong><?= htmlspecialchars($contrato['receptor_nombre']) ?></strong>
                                <?php if ($contrato['receptor_rtn']): ?>
                                    · RTN: <?= htmlspecialchars($contrato['receptor_rtn']) ?>
                                <?php endif; ?>
                            </div>
                            <?php if ($contrato['receptor_tel']): ?>
                                <div class="text-muted small">
                                    <i class="fa-solid fa-phone me-1"></i><?= htmlspecialchars($contrato['receptor_tel']) ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($contrato['receptor_email']): ?>
                                <div class="text-muted small">
                                    <i class="fa-solid fa-envelope me-1"></i><?= htmlspecialchars($contrato['receptor_email']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="row g-2 text-center">
                        <div class="col-4">
                            <div class="small text-muted">Servicio</div>
                            <div class="fw-semibold small"><?= htmlspecialchars($contrato['producto_nombre']) ?></div>
                        </div>
                        <div class="col-4">
                            <div class="small text-muted">Monto / mes</div>
                            <div class="fw-bold text-primary">L <?= number_format((float)$contrato['monto'], 2) ?></div>
                        </div>
                        <div class="col-4">
                            <div class="small text-muted">Estado</div>
                            <div><?= $estadoBadge[$contrato['estado']] ?? '' ?></div>
                        </div>
                        <div class="col-4">
                            <div class="small text-muted">Inicio</div>
                            <div class="small fw-semibold"><?= htmlspecialchars($contrato['fecha_inicio']) ?></div>
                        </div>
                        <div class="col-4">
                            <div class="small text-muted">Vencimiento</div>
                            <div class="small">
                                <?= $contrato['fecha_fin']
                                    ? htmlspecialchars($contrato['fecha_fin'])
                                    : '<span class="badge bg-info">Indefinido</span>' ?>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="small text-muted">Día de Cobro</div>
                            <div class="small fw-semibold">Día <?= (int)$contrato['dia_pago'] ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── KPIs ─────────────────────────────────────────────────────────────── -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-2 fw-bold text-info"><?= count($facturas) ?></div>
                    <div class="text-muted small">Facturas Emitidas</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-2 fw-bold text-secondary">L <?= number_format($totalSubtotal, 2) ?></div>
                    <div class="text-muted small">Total Subtotal</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-2 fw-bold text-warning">L <?= number_format($totalIsv, 2) ?></div>
                    <div class="text-muted small">Total ISV</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-2 fw-bold text-primary">L <?= number_format($totalFacturado, 2) ?></div>
                    <div class="text-muted small">Total Facturado</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Alerta: contrato no iniciado ─────────────────────────────────────── -->
    <?php if ($noIniciado): ?>
    <div class="alert alert-info mb-4">
        <i class="fa-solid fa-clock me-2"></i>
        Este contrato aún no ha iniciado. Comienza el <strong><?= htmlspecialchars($contrato['fecha_inicio']) ?></strong>.
    </div>
    <?php endif; ?>

    <!-- ── Alerta: meses sin factura ─────────────────────────────────────────── -->
    <?php if (!empty($mesesSinFactura) && !$noIniciado): ?>
    <div class="alert alert-warning d-flex align-items-start gap-2 mb-4">
        <i class="fa-solid fa-triangle-exclamation mt-1 flex-shrink-0"></i>
        <div>
            <strong>Meses sin factura detectados:</strong>
            <div class="mt-1">
            <?php foreach ($mesesSinFactura as $ms):
                $dt = DateTime::createFromFormat('Y-m', $ms);
            ?>
                <span class="badge bg-warning text-dark me-1 mb-1 fs-6">
                    <?= $dt ? $dt->format('F Y') : $ms ?>
                </span>
            <?php endforeach; ?>
            </div>
            <small class="text-muted">No se encontró factura emitida para estos meses.</small>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Historial de facturas ─────────────────────────────────────────────── -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
            <h6 class="mb-0 fw-bold">
                <i class="fa-solid fa-list me-2 text-info"></i>Historial de Facturas
            </h6>
            <span class="badge bg-info"><?= count($facturas) ?> factura(s)</span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($facturas)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fa-solid fa-file-invoice fa-3x mb-3 opacity-25"></i>
                    <p class="mb-3">No hay facturas emitidas para este contrato aún.</p>
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
                            <th>Correlativo</th>
                            <th>Fecha</th>
                            <th class="text-end">Subtotal</th>
                            <th class="text-end">ISV</th>
                            <th class="text-end fw-bold">Total</th>
                            <th class="text-center">Declarada</th>
                            <th class="text-center">Pagada</th>
                            <th class="text-center">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($facturas as $f):
                        $isv        = (float)$f['isv_15'] + (float)$f['isv_18'];
                        $esMesActual = (substr($f['fecha_emision'], 0, 7) === date('Y-m'));
                    ?>
                        <tr <?= $esMesActual ? 'class="table-success"' : '' ?>>
                            <td>
                                <span class="fw-semibold font-monospace"><?= htmlspecialchars($f['correlativo']) ?></span>
                                <?php if ($esMesActual): ?>
                                    <br><span class="badge bg-success small">Este mes</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?= date('d/m/Y', strtotime($f['fecha_emision'])) ?></div>
                                <small class="text-muted"><?= date('H:i', strtotime($f['fecha_emision'])) ?></small>
                            </td>
                            <td class="text-end">L <?= number_format((float)$f['subtotal'], 2) ?></td>
                            <td class="text-end">L <?= number_format($isv, 2) ?></td>
                            <td class="text-end fw-bold text-primary">L <?= number_format((float)$f['total'], 2) ?></td>
                            <td class="text-center">
                                <?php if ((int)$f['estado_declarada']): ?>
                                    <span class="badge bg-success">✅ Declarada</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Pendiente</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ((int)$f['pagada']): ?>
                                    <span class="badge bg-success">✅ Pagada</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">Pendiente</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <a href="ver_factura?id=<?= $f['id'] ?>"
                                   class="btn btn-sm btn-outline-primary"
                                   target="_blank" title="Ver factura">
                                    <i class="fa-solid fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="2" class="text-end">Totales:</td>
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

    <!-- ── Línea de tiempo por mes ───────────────────────────────────────────── -->
    <?php if (!empty($mesesEsperados)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom py-3">
            <h6 class="mb-0 fw-bold">
                <i class="fa-solid fa-calendar-days me-2 text-secondary"></i>
                Resumen por Mes
            </h6>
        </div>
        <div class="card-body">
            <div class="d-flex flex-wrap gap-2">
            <?php foreach (array_reverse($mesesEsperados) as $mes):
                $dt       = DateTime::createFromFormat('Y-m', $mes);
                $label    = $dt ? $dt->format('M Y') : $mes;
                $tieneF   = isset($mesesConFactura[$mes]);
                $esActual = ($mes === date('Y-m'));
            ?>
                <div class="text-center" style="min-width:72px">
                    <div class="rounded-3 p-2 mb-1 <?= $tieneF ? 'bg-success text-white' : ($esActual ? 'bg-warning text-dark' : 'bg-light text-muted border') ?>"
                         style="font-size:.75rem; line-height:1.3;">
                        <div class="fs-5"><?= $tieneF ? '✅' : ($esActual ? '⏳' : '❌') ?></div>
                        <?= $label ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
            <div class="mt-2 small text-muted">
                ✅ Facturado &nbsp;·&nbsp; ⏳ Mes actual &nbsp;·&nbsp; ❌ Sin factura
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<script>
$(function () {
    $('#tablaFacturasContrato').DataTable({
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json' },
        order: [[1, 'desc']],
        pageLength: 25,
        columnDefs: [{ orderable: false, targets: [7] }]
    });
});
</script>

</body>
</html>