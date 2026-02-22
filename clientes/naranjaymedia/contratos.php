<?php
$titulo = 'Contratos';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';
require_once '../../includes/templates/header.php';

$cliente_id = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);

// ── Actualizar estados automáticamente ───────────────────────────────────────
$pdo->prepare("
    UPDATE contratos
       SET estado = 'vencido'
     WHERE cliente_id = ?
       AND estado     = 'activo'
       AND fecha_fin IS NOT NULL
       AND fecha_fin < CURDATE()
")->execute([$cliente_id]);

// ── KPIs ─────────────────────────────────────────────────────────────────────
$stmtKpi = $pdo->prepare("
    SELECT
        SUM(estado = 'activo')                                                                          AS activos,
        SUM(estado = 'activo' AND fecha_fin BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)) AS proximos_vencer,
        SUM(estado = 'vencido')                                                                         AS vencidos,
        SUM(CASE WHEN estado = 'activo' THEN monto ELSE 0 END)                                          AS monto_activo
    FROM contratos
    WHERE cliente_id = ?
");
$stmtKpi->execute([$cliente_id]);
$kpi = $stmtKpi->fetch(PDO::FETCH_ASSOC);

// ── Lista completa ────────────────────────────────────────────────────────────
// La lógica de próxima fecha considera:
//   - Si el contrato aún no inició (fecha_inicio > HOY) → primer pago = dia_pago del mes de fecha_inicio
//   - Si ya inició → pago normal basado en HOY
$stmtLista = $pdo->prepare("
    SELECT c.*,
           cf.nombre   AS receptor_nombre,
           cf.rtn      AS receptor_rtn,
           cf.email    AS receptor_email,
           cf.telefono AS receptor_tel,
           p.nombre    AS producto_nombre,

           -- ¿Ya hay factura EMITIDA este mes para este contrato?
           (
               SELECT COUNT(*)
               FROM facturas f
               WHERE f.contrato_id      = c.id
                 AND f.cliente_id       = c.cliente_id
                 AND f.estado           = 'emitida'
                 AND MONTH(f.fecha_emision) = MONTH(CURDATE())
                 AND YEAR(f.fecha_emision)  = YEAR(CURDATE())
           ) AS facturado_este_mes,

           -- Fecha de la última factura emitida de este contrato
           (
               SELECT DATE(f2.fecha_emision)
               FROM facturas f2
               WHERE f2.contrato_id = c.id
                 AND f2.cliente_id  = c.cliente_id
                 AND f2.estado      = 'emitida'
               ORDER BY f2.fecha_emision DESC
               LIMIT 1
           ) AS ultima_factura_fecha,

           -- Total facturas emitidas de este contrato
           (
               SELECT COUNT(*)
               FROM facturas f3
               WHERE f3.contrato_id = c.id
                 AND f3.cliente_id  = c.cliente_id
                 AND f3.estado      = 'emitida'
           ) AS total_facturas_contrato,

           -- Total facturado de este contrato
           (
               SELECT COALESCE(SUM(f4.total), 0)
               FROM facturas f4
               WHERE f4.contrato_id = c.id
                 AND f4.cliente_id  = c.cliente_id
                 AND f4.estado      = 'emitida'
           ) AS total_monto_contrato,

           -- ── Próxima fecha de pago ─────────────────────────────────────────
           -- Si el contrato no ha iniciado → usar fecha_inicio como referencia
           -- Si ya inició → usar CURDATE() como referencia
           CASE
               -- Contrato aún no empieza
               WHEN c.fecha_inicio > CURDATE()
                   THEN DATE(CONCAT(
                       YEAR(c.fecha_inicio), '-',
                       LPAD(MONTH(c.fecha_inicio), 2, '0'), '-',
                       LPAD(LEAST(c.dia_pago, DAY(LAST_DAY(c.fecha_inicio))), 2, '0')
                   ))
               -- Contrato activo: el día de pago de este mes aún no llegó
               WHEN DAY(CURDATE()) <= c.dia_pago
                   THEN DATE(CONCAT(
                       YEAR(CURDATE()), '-',
                       LPAD(MONTH(CURDATE()), 2, '0'), '-',
                       LPAD(LEAST(c.dia_pago, DAY(LAST_DAY(CURDATE()))), 2, '0')
                   ))
               -- Ya pasó el día de pago de este mes → siguiente mes
               ELSE
                   DATE(CONCAT(
                       YEAR(DATE_ADD(CURDATE(), INTERVAL 1 MONTH)), '-',
                       LPAD(MONTH(DATE_ADD(CURDATE(), INTERVAL 1 MONTH)), 2, '0'), '-',
                       LPAD(LEAST(c.dia_pago, DAY(LAST_DAY(DATE_ADD(CURDATE(), INTERVAL 1 MONTH)))), 2, '0')
                   ))
           END AS proxima_fecha_pago,

           -- ── Días hasta próximo pago ───────────────────────────────────────
           CASE
               WHEN c.fecha_inicio > CURDATE()
                   THEN DATEDIFF(
                       DATE(CONCAT(
                           YEAR(c.fecha_inicio), '-',
                           LPAD(MONTH(c.fecha_inicio), 2, '0'), '-',
                           LPAD(LEAST(c.dia_pago, DAY(LAST_DAY(c.fecha_inicio))), 2, '0')
                       )),
                       CURDATE()
                   )
               WHEN DAY(CURDATE()) <= c.dia_pago
                   THEN DATEDIFF(
                       DATE(CONCAT(YEAR(CURDATE()), '-', LPAD(MONTH(CURDATE()), 2, '0'), '-',
                           LPAD(LEAST(c.dia_pago, DAY(LAST_DAY(CURDATE()))), 2, '0'))),
                       CURDATE()
                   )
               ELSE
                   DATEDIFF(
                       DATE(CONCAT(
                           YEAR(DATE_ADD(CURDATE(), INTERVAL 1 MONTH)), '-',
                           LPAD(MONTH(DATE_ADD(CURDATE(), INTERVAL 1 MONTH)), 2, '0'), '-',
                           LPAD(LEAST(c.dia_pago, DAY(LAST_DAY(DATE_ADD(CURDATE(), INTERVAL 1 MONTH)))), 2, '0')
                       )),
                       CURDATE()
                   )
           END AS dias_para_pago,

           -- ── Alerta por vencimiento ────────────────────────────────────────
           CASE
               WHEN c.fecha_fin IS NULL                                                          THEN 'indefinido'
               WHEN c.fecha_fin < CURDATE()                                                      THEN 'vencido'
               WHEN c.fecha_fin BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)        THEN 'critico'
               WHEN c.fecha_fin BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)       THEN 'proximo'
               ELSE 'activo'
           END AS alerta,
           DATEDIFF(c.fecha_fin, CURDATE()) AS dias_restantes,

           -- ¿El contrato aún no ha iniciado?
           (c.fecha_inicio > CURDATE()) AS no_iniciado

    FROM contratos c
    INNER JOIN clientes_factura   cf ON cf.id = c.receptor_id AND cf.cliente_id = c.cliente_id
    INNER JOIN productos_clientes p  ON p.id  = c.producto_id AND p.cliente_id  = c.cliente_id
    WHERE c.cliente_id = ?
    ORDER BY c.estado ASC, dias_para_pago ASC, c.fecha_fin ASC
");
$stmtLista->execute([$cliente_id]);
$contratos = $stmtLista->fetchAll(PDO::FETCH_ASSOC);

// ── KPI: pendientes de facturar este mes (solo contratos ya iniciados) ────────
$pendientes_mes  = 0;
$monto_pendiente = 0;
foreach ($contratos as $c) {
    if ($c['estado'] === 'activo' && !(int)$c['no_iniciado'] && !(int)$c['facturado_este_mes']) {
        $pendientes_mes++;
        $monto_pendiente += (float)$c['monto'];
    }
}

$estadoBadge = [
    'activo'    => '<span class="badge bg-success">Activo</span>',
    'vencido'   => '<span class="badge bg-danger">Vencido</span>',
    'cancelado' => '<span class="badge bg-secondary">Cancelado</span>',
    'pausado'   => '<span class="badge bg-warning text-dark">Pausado</span>',
];
?>

<div class="container-xxl mt-4">

    <!-- ── Cabecera ─────────────────────────────────────────────────────────── -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h4 class="mb-0"><i class="fa-solid fa-file-contract me-2 text-primary"></i>Contratos</h4>
            <small class="text-muted">Gestión de contratos de servicio con clientes</small>
        </div>
        <a href="crear_contrato" class="btn btn-primary">
            <i class="fa-solid fa-plus me-1"></i> Nuevo Contrato
        </a>
    </div>

    <!-- ── KPIs ─────────────────────────────────────────────────────────────── -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-1 fw-bold text-success"><?= (int)($kpi['activos'] ?? 0) ?></div>
                    <div class="text-muted small">Contratos Activos</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-1 fw-bold text-warning"><?= (int)($kpi['proximos_vencer'] ?? 0) ?></div>
                    <div class="text-muted small">Por Vencer (30 días)</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-1 fw-bold text-danger"><?= (int)($kpi['vencidos'] ?? 0) ?></div>
                    <div class="text-muted small">Contratos Vencidos</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-6 col-lg">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-1 fw-bold text-primary">L <?= number_format((float)($kpi['monto_activo'] ?? 0), 2) ?></div>
                    <div class="text-muted small">Ingresos Recurrentes / mes</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6 col-lg">
            <div class="card border-0 shadow-sm h-100 <?= $pendientes_mes > 0 ? 'border border-warning' : 'border border-success' ?>">
                <div class="card-body text-center py-3">
                    <div class="fs-1 fw-bold <?= $pendientes_mes > 0 ? 'text-warning' : 'text-success' ?>">
                        <?= $pendientes_mes ?>
                    </div>
                    <div class="text-muted small">Sin facturar este mes</div>
                    <?php if ($pendientes_mes > 0): ?>
                        <div class="text-warning small fw-semibold">L <?= number_format($monto_pendiente, 2) ?> pendiente</div>
                    <?php else: ?>
                        <div class="text-success small fw-semibold">✅ Todo facturado</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Panel: Próximas Fechas de Cobro ──────────────────────────────────── -->
    <?php
    $proximos_pagos = array_filter($contratos, fn($c) => $c['estado'] === 'activo');
    usort($proximos_pagos, fn($a, $b) => (int)$a['dias_para_pago'] - (int)$b['dias_para_pago']);
    $proximos_pagos = array_slice($proximos_pagos, 0, 10);
    ?>
    <?php if (!empty($proximos_pagos)): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
            <h6 class="mb-0 fw-bold">
                <i class="fa-solid fa-calendar-check me-2 text-primary"></i>
                Próximas Fechas de Cobro — <?= date('F Y') ?>
            </h6>
            <span class="badge bg-primary"><?= count($proximos_pagos) ?> contratos activos</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Cliente</th>
                            <th>Servicio</th>
                            <th class="text-end">Monto</th>
                            <th class="text-center">Próximo Cobro</th>
                            <th class="text-center">Días</th>
                            <th class="text-center">Este Mes</th>
                            <th class="text-center">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($proximos_pagos as $p):
                        $dias       = (int)$p['dias_para_pago'];
                        $facturado  = (int)$p['facturado_este_mes'] > 0;
                        $noIniciado = (int)$p['no_iniciado'];

                        if ($noIniciado)        { $badgeClass = 'bg-secondary';          $label = "En {$dias}d"; }
                        elseif ($dias === 0)    { $badgeClass = 'bg-danger';             $label = '¡Hoy!'; }
                        elseif ($dias <= 3)     { $badgeClass = 'bg-danger';             $label = "{$dias}d"; }
                        elseif ($dias <= 7)     { $badgeClass = 'bg-warning text-dark';  $label = "{$dias}d"; }
                        elseif ($dias <= 15)    { $badgeClass = 'bg-info';               $label = "{$dias}d"; }
                        else                    { $badgeClass = 'bg-secondary';           $label = "{$dias}d"; }
                    ?>
                        <tr <?= $facturado ? 'class="table-success"' : '' ?>>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($p['receptor_nombre']) ?></div>
                                <?php if ($p['receptor_tel']): ?>
                                    <small class="text-muted">
                                        <i class="fa-solid fa-phone fa-xs me-1"></i><?= htmlspecialchars($p['receptor_tel']) ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted"><?= htmlspecialchars($p['producto_nombre']) ?></td>
                            <td class="text-end fw-bold">L <?= number_format((float)$p['monto'], 2) ?></td>
                            <td class="text-center">
                                <div class="fw-semibold small"><?= htmlspecialchars($p['proxima_fecha_pago']) ?></div>
                                <small class="text-muted">Día <?= (int)$p['dia_pago'] ?> c/mes</small>
                                <?php if ($noIniciado): ?>
                                    <br><span class="badge bg-secondary small">Inicia <?= htmlspecialchars($p['fecha_inicio']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($facturado): ?>
                                    <span class="badge bg-success px-3">✅</span>
                                <?php else: ?>
                                    <span class="badge <?= $badgeClass ?> px-3"><?= $label ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($noIniciado): ?>
                                    <span class="badge bg-secondary">No iniciado</span>
                                <?php elseif ($facturado): ?>
                                    <span class="text-success small fw-semibold">
                                        <i class="fa-solid fa-check me-1"></i>Facturado
                                        <?php if ($p['ultima_factura_fecha']): ?>
                                            <br><small class="text-muted fw-normal"><?= htmlspecialchars($p['ultima_factura_fecha']) ?></small>
                                        <?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-warning small fw-semibold">
                                        <i class="fa-solid fa-clock me-1"></i>Pendiente
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($facturado): ?>
                                    <a href="facturas_contrato?contrato_id=<?= $p['id'] ?>"
                                       class="btn btn-sm btn-outline-success">
                                        <i class="fa-solid fa-receipt me-1"></i> Ver Facturas
                                    </a>
                                <?php elseif ($noIniciado): ?>
                                    <button class="btn btn-sm btn-outline-secondary" disabled
                                            title="El contrato inicia el <?= htmlspecialchars($p['fecha_inicio']) ?>">
                                        <i class="fa-solid fa-clock"></i> No iniciado
                                    </button>
                                <?php else: ?>
                                    <a href="generar_factura?receptor_id=<?= $p['receptor_id'] ?>&producto_id=<?= $p['producto_id'] ?>&monto=<?= $p['monto'] ?>&contrato_id=<?= $p['id'] ?>"
                                       class="btn btn-sm btn-success">
                                        <i class="fa-solid fa-file-invoice-dollar me-1"></i> Facturar
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Tabla completa ────────────────────────────────────────────────────── -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom py-3">
            <h6 class="mb-0 fw-bold">
                <i class="fa-solid fa-list me-2 text-secondary"></i>
                Todos los Contratos
            </h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="tablaContratos" class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Cliente</th>
                            <th>Servicio</th>
                            <th class="text-end">Monto</th>
                            <th class="text-center">Este Mes</th>
                            <th>Próximo Cobro</th>
                            <th>Fecha Fin</th>
                            <th>Estado</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($contratos as $c):
                        switch ($c['alerta'] ?? '') {
                            case 'critico': $rowClass = 'table-danger';    break;
                            case 'proximo': $rowClass = 'table-warning';   break;
                            case 'vencido': $rowClass = 'table-secondary'; break;
                            default:        $rowClass = '';
                        }
                        $diasPago   = isset($c['dias_para_pago']) ? (int)$c['dias_para_pago'] : null;
                        $facturado  = (int)($c['facturado_este_mes'] ?? 0) > 0;
                        $noIniciado = (int)($c['no_iniciado'] ?? 0);
                        $nFacturas  = (int)($c['total_facturas_contrato'] ?? 0);
                    ?>
                        <tr class="<?= $rowClass ?>">
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($c['receptor_nombre']) ?></div>
                                <?php if ($c['receptor_rtn']): ?>
                                    <small class="text-muted">RTN: <?= htmlspecialchars($c['receptor_rtn']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?= htmlspecialchars($c['producto_nombre']) ?></div>
                                <?php if ($nFacturas > 0): ?>
                                    <small class="text-muted">
                                        <i class="fa-solid fa-receipt fa-xs me-1"></i>
                                        <?= $nFacturas ?> factura(s) · L <?= number_format((float)$c['total_monto_contrato'], 2) ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td class="text-end fw-semibold">L <?= number_format((float)$c['monto'], 2) ?></td>

                            <td class="text-center">
                                <?php if ($c['estado'] !== 'activo'): ?>
                                    <span class="text-muted">—</span>
                                <?php elseif ($noIniciado): ?>
                                    <span class="badge bg-secondary">No iniciado</span>
                                    <br><small class="text-muted">Inicia <?= htmlspecialchars($c['fecha_inicio']) ?></small>
                                <?php elseif ($facturado): ?>
                                    <span class="badge bg-success" title="Última factura: <?= htmlspecialchars($c['ultima_factura_fecha'] ?? '') ?>">
                                        ✅ Facturado
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">⏳ Pendiente</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($c['estado'] === 'activo' && $diasPago !== null): ?>
                                    <div class="fw-semibold small"><?= htmlspecialchars($c['proxima_fecha_pago']) ?></div>
                                    <?php if ($noIniciado): ?>
                                        <small class="text-muted">Primer cobro</small>
                                    <?php else:
                                        if ($diasPago === 0)     { $txt = '¡Hoy!';              $cls = 'text-danger fw-bold'; }
                                        elseif ($diasPago <= 3)  { $txt = "En {$diasPago}d ⚠️"; $cls = 'text-danger'; }
                                        elseif ($diasPago <= 7)  { $txt = "En {$diasPago}d";    $cls = 'text-warning fw-semibold'; }
                                        else                     { $txt = "En {$diasPago} días"; $cls = 'text-muted'; }
                                    ?>
                                        <small class="<?= $cls ?>"><?= $txt ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($c['fecha_fin']): ?>
                                    <?= htmlspecialchars($c['fecha_fin']) ?>
                                    <?php if (in_array($c['alerta'], ['critico','proximo']) && $c['dias_restantes'] >= 0): ?>
                                        <br><small class="<?= $c['alerta'] === 'critico' ? 'text-danger fw-bold' : 'text-warning fw-semibold' ?>">
                                            ⏰ <?= (int)$c['dias_restantes'] ?> día(s)
                                        </small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-info">Indefinido</span>
                                <?php endif; ?>
                            </td>

                            <td><?= $estadoBadge[$c['estado']] ?? '' ?></td>

                            <td class="text-center">
                                <div class="d-flex gap-1 justify-content-center flex-wrap">
                                    <?php if ($nFacturas > 0): ?>
                                    <a href="facturas_contrato?contrato_id=<?= $c['id'] ?>"
                                       class="btn btn-sm btn-outline-info"
                                       title="Ver <?= $nFacturas ?> factura(s)">
                                        <i class="fa-solid fa-receipt"></i>
                                        <span class="badge bg-info ms-1"><?= $nFacturas ?></span>
                                    </a>
                                    <?php endif; ?>

                                    <a href="editar_contrato?id=<?= $c['id'] ?>"
                                       class="btn btn-sm btn-outline-primary" title="Editar">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </a>

                                    <?php if ($c['estado'] === 'activo'): ?>
                                        <?php if ($noIniciado || $facturado): ?>
                                            <button class="btn btn-sm btn-outline-secondary" disabled
                                                    title="<?= $noIniciado ? 'Contrato no iniciado aún' : 'Ya facturado este mes' ?>">
                                                <i class="fa-solid fa-file-invoice-dollar"></i>
                                            </button>
                                        <?php else: ?>
                                            <a href="generar_factura?receptor_id=<?= $c['receptor_id'] ?>&producto_id=<?= $c['producto_id'] ?>&monto=<?= $c['monto'] ?>&contrato_id=<?= $c['id'] ?>"
                                               class="btn btn-sm btn-outline-success" title="Crear Factura">
                                                <i class="fa-solid fa-file-invoice-dollar"></i>
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php if (!in_array($c['estado'], ['cancelado','vencido'])): ?>
                                        <button class="btn btn-sm btn-outline-danger btn-cancelar"
                                                data-id="<?= $c['id'] ?>"
                                                data-nombre="<?= htmlspecialchars($c['receptor_nombre']) ?>"
                                                title="Cancelar contrato">
                                            <i class="fa-solid fa-ban"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<script>
$(function () {
    $('#tablaContratos').DataTable({
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json' },
        order: [[4, 'asc']],
        pageLength: 25,
        columnDefs: [{ orderable: false, targets: [7] }]
    });

    $(document).on('click', '.btn-cancelar', function () {
        const id     = $(this).data('id');
        const nombre = $(this).data('nombre');
        Swal.fire({
            title: '¿Cancelar contrato?',
            html: `Se cancelará el contrato de <strong>${nombre}</strong>.<br>Esta acción no se puede deshacer.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor:  '#6c757d',
            confirmButtonText: 'Sí, cancelar',
            cancelButtonText:  'No, volver',
        }).then(result => {
            if (result.isConfirmed) {
                $.post('includes/contrato_cancelar.php', { id }, function (res) {
                    if (res.ok) {
                        Swal.fire('Cancelado', 'El contrato fue cancelado.', 'success')
                            .then(() => location.reload());
                    } else {
                        Swal.fire('Error', res.msg || 'No se pudo cancelar.', 'error');
                    }
                }, 'json');
            }
        });
    });
});
</script>

</body>
</html>