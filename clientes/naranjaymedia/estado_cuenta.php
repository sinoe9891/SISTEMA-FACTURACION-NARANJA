<?php
// Estado de cuenta de un cliente: cuánto debe, sus facturas (con abonado y saldo) y los abonos que ha hecho.
$titulo = 'Estado de cuenta';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/cuentas.php';

$cid = cliente_actual();
$rid = (int)($_GET['receptor_id'] ?? 0);
$instalado = cxcDisponible($pdo);

$st = $pdo->prepare("SELECT * FROM clientes_factura WHERE id = ? AND cliente_id = ?");
$st->execute([$rid, $cid]);
$cliente = $st->fetch(PDO::FETCH_ASSOC);
if (!$cliente) {
    header('Location: cuentas_cobrar');
    exit;
}

// Facturas del cliente con lo abonado (sin abonos, una factura marcada "pagada" no tiene saldo)
$st = $pdo->prepare("
    SELECT f.id, f.correlativo, f.fecha_emision, f.condicion_pago, f.estado, f.total, f.pagada,
           COALESCE(f.periodo_mes, MONTH(f.fecha_emision)) AS periodo_mes, COALESCE(f.periodo_anio, YEAR(f.fecha_emision)) AS periodo_anio,
           f.contrato_id, ct.nombre_contrato,
           COALESCE(ab.abonado, 0) AS abonado, ab.ultimo_abono
    FROM facturas f
    LEFT JOIN contratos ct ON ct.id = f.contrato_id
    LEFT JOIN (SELECT factura_id, SUM(monto) AS abonado, MAX(fecha) AS ultimo_abono
               FROM cobros_factura WHERE anulado = 0 GROUP BY factura_id) ab ON ab.factura_id = f.id
    WHERE f.cliente_id = ? AND f.receptor_id = ?
    ORDER BY f.fecha_emision DESC, f.id DESC
");
$st->execute([$cid, $rid]);
$facturas = $st->fetchAll(PDO::FETCH_ASSOC);

$facturado = $cobrado = $saldo = 0.0;
$pendientes = 0;
$diasMayor = 0;
foreach ($facturas as &$f) {
    $f['abonado'] = round((float)$f['abonado'], 2);
    if ($f['estado'] !== 'emitida') { $f['saldo'] = 0.0; continue; }
    $f['saldo'] = $f['abonado'] > 0 ? max(0, round((float)$f['total'] - $f['abonado'], 2)) : ((int)$f['pagada'] ? 0.0 : round((float)$f['total'], 2));
    $facturado += (float)$f['total'];
    $saldo += $f['saldo'];
    if ($f['saldo'] > 0.004) {
        $pendientes++;
        $diasMayor = max($diasMayor, (int)((time() - strtotime(substr($f['fecha_emision'], 0, 10))) / 86400));
    }
}
unset($f);
$cobrado = $facturado - $saldo;

// Historial de abonos del cliente
$abonos = [];
if ($instalado) {
    $st = $pdo->prepare("
        SELECT c.id, c.fecha, c.monto, c.metodo, c.referencia, c.notas, c.anulado, c.motivo_anulacion,
               f.id AS factura_id, f.correlativo, b.banco, b.numero AS cuenta_numero
        FROM cobros_factura c
        JOIN facturas f ON f.id = c.factura_id
        LEFT JOIN cuentas_bancarias b ON b.id = c.cuenta_id
        WHERE c.cliente_id = ? AND f.receptor_id = ?
        ORDER BY c.fecha DESC, c.id DESC
    ");
    $st->execute([$cid, $rid]);
    $abonos = $st->fetchAll(PDO::FETCH_ASSOC);
}
$totalAbonos = array_sum(array_map(fn($a) => (int)$a['anulado'] ? 0 : (float)$a['monto'], $abonos));

$L = fn($v) => 'L ' . number_format((float)$v, 2);
$condicion = ['contado' => 'Contado', 'credito' => 'Crédito', 'credito/contado' => 'Crédito / Contado'];
$mesCorto = [1 => 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
$metodos = ['transferencia' => 'Transferencia', 'efectivo' => 'Efectivo', 'cheque' => 'Cheque', 'tarjeta' => 'Tarjeta', 'otro' => 'Otro'];

require_once '../../includes/templates/header.php';
?>

<div class="app-page-header">
    <div>
        <a href="cuentas_cobrar" class="small text-muted text-decoration-none no-print"><i class="bi bi-arrow-left"></i> Cuentas por cobrar</a>
        <h1 class="app-page-title"><?= htmlspecialchars($cliente['nombre']) ?></h1>
        <p class="app-page-sub">
            Estado de cuenta al <?= date('d/m/Y') ?>
            <?= $cliente['rtn'] ? ' · RTN ' . htmlspecialchars($cliente['rtn']) : '' ?>
            <?= $cliente['telefono'] ? ' · ' . htmlspecialchars($cliente['telefono']) : '' ?>
            <?= $cliente['email'] ? ' · ' . htmlspecialchars($cliente['email']) : '' ?>
        </p>
    </div>
    <div class="d-flex gap-2 no-print">
        <?php if (in_array(USUARIO_ROL, ['admin', 'superadmin'], true)): ?>
            <a href="cobros_programados?receptor_id=<?= (int)$rid ?>" class="btn btn-primary"><i class="bi bi-send-check me-1"></i> Enviar cobro por correo</a>
        <?php endif; ?>
        <button class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i> Imprimir</button>
    </div>
</div>

<?php if (!$instalado): ?>
    <div class="alert alert-warning">Falta instalar el módulo de cuentas por cobrar (<code>sql/migraciones/2026-10-03_cuentas_cobrar.sql</code>).</div>
<?php endif; ?>

<div class="app-stats">
    <div class="app-stat"><div class="app-stat-icon <?= $saldo > 0 ? 'red' : 'green' ?>"><i class="bi bi-hourglass-split"></i></div>
        <div><div class="app-stat-val <?= $saldo > 0 ? 'text-danger' : 'text-success' ?>"><?= $L($saldo) ?></div><div class="app-stat-lbl">Saldo pendiente · <?= $pendientes ?> factura(s)</div></div></div>
    <div class="app-stat"><div class="app-stat-icon"><i class="bi bi-receipt"></i></div>
        <div><div class="app-stat-val"><?= $L($facturado) ?></div><div class="app-stat-lbl">Total facturado · <?= count(array_filter($facturas, fn($f) => $f['estado'] === 'emitida')) ?> factura(s)</div></div></div>
    <div class="app-stat"><div class="app-stat-icon green"><i class="bi bi-wallet2"></i></div>
        <div><div class="app-stat-val text-success"><?= $L($cobrado) ?></div><div class="app-stat-lbl">Cobrado · <?= $L($totalAbonos) ?> en abonos</div></div></div>
    <div class="app-stat"><div class="app-stat-icon <?= $diasMayor > 60 ? 'red' : ($diasMayor > 30 ? 'amber' : 'gray') ?>"><i class="bi bi-calendar-x"></i></div>
        <div><div class="app-stat-val"><?= $pendientes ? $diasMayor . ' días' : '—' ?></div><div class="app-stat-lbl">Deuda más antigua (desde la emisión)</div></div></div>
</div>

<div class="app-card mb-3">
    <div class="app-card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <span><i class="bi bi-receipt me-1"></i> Facturas</span>
        <div class="app-toolbar flex-grow-1 justify-content-end no-print">
        <div class="app-search" style="max-width:240px"><i class="bi bi-search"></i><input type="search" class="form-control form-control-sm" id="buscarFactura" placeholder="Buscar factura…"></div>
        <div class="btn-group btn-group-sm" role="group" aria-label="Filtro">
            <button type="button" class="btn btn-outline-secondary <?= $pendientes ? 'active' : '' ?>" data-filtro="pendientes">Con saldo (<?= $pendientes ?>)</button>
            <button type="button" class="btn btn-outline-secondary <?= $pendientes ? '' : 'active' ?>" data-filtro="todas">Todas (<?= count($facturas) ?>)</button>
        </div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table app-table mb-0" id="tablaFacturas">
            <thead>
                <tr>
                    <th class="app-n">#</th><th>Factura</th><th>Período</th><th>Emisión</th><th>Condición</th><th>Contrato</th>
                    <th class="app-num">Total</th><th class="app-num">Abonado</th><th class="app-num">Saldo</th>
                    <th class="text-center">Estado</th><th class="text-end no-print">Abonos</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($facturas as $f):
                    $anulada = $f['estado'] !== 'emitida';
                    $conSaldo = $f['saldo'] > 0.004;
                ?>
                    <tr data-fila data-saldo="<?= $conSaldo ? 1 : 0 ?>" class="<?= $anulada ? 'text-muted' : '' ?>">
                        <td class="app-n"></td>
                        <td class="font-monospace small text-nowrap"><a href="ver_factura?id=<?= (int)$f['id'] ?>" target="_blank"><?= htmlspecialchars($f['correlativo']) ?></a></td>
                        <td class="text-nowrap small"><?= $mesCorto[(int)$f['periodo_mes']] . ' ' . (int)$f['periodo_anio'] ?></td>
                        <td class="text-nowrap"><?= date('d/m/Y', strtotime($f['fecha_emision'])) ?></td>
                        <td class="small"><?= $condicion[strtolower((string)$f['condicion_pago'])] ?? htmlspecialchars((string)$f['condicion_pago']) ?></td>
                        <td class="small"><?= $f['contrato_id'] ? '<a href="facturas_contrato?contrato_id=' . (int)$f['contrato_id'] . '">#' . (int)$f['contrato_id'] . '</a> ' . htmlspecialchars(mb_strimwidth((string)$f['nombre_contrato'], 0, 34, '…')) : '<span class="text-muted">—</span>' ?></td>
                        <td class="app-num"><?= $anulada ? '<s>' . number_format((float)$f['total'], 2) . '</s>' : number_format((float)$f['total'], 2) ?></td>
                        <td class="app-num text-success"><?= $f['abonado'] > 0 ? number_format($f['abonado'], 2) : '—' ?></td>
                        <td class="app-num fw-semibold <?= $conSaldo ? 'text-danger' : '' ?>"><?= $anulada ? '—' : number_format($f['saldo'], 2) ?></td>
                        <td class="text-center">
                            <?php if ($anulada): ?><span class="app-badge">Anulada</span>
                            <?php elseif (!$conSaldo): ?><span class="app-badge app-badge-success">Pagada</span>
                            <?php elseif ($f['abonado'] > 0): ?><span class="app-badge app-badge-info">Abonada</span>
                            <?php else: ?><span class="app-badge app-badge-warning">Pendiente</span><?php endif; ?>
                        </td>
                        <td class="text-end no-print">
                            <?php if (!$anulada && $instalado): ?>
                                <button class="btn btn-sm <?= $conSaldo ? 'btn-outline-primary' : 'btn-outline-secondary' ?> btn-abonos" data-id="<?= (int)$f['id'] ?>"
                                    title="<?= $conSaldo ? 'Registrar abono / ver abonos' : 'Ver abonos' ?>"><i class="bi bi-cash-coin"></i><?= $conSaldo ? ' Abonar' : '' ?></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$facturas): ?><tr><td colspan="11" class="text-center text-muted py-4">Este cliente no tiene facturas.</td></tr><?php endif; ?>
            </tbody>
            <?php if ($facturas): ?>
                <tfoot>
                    <tr class="fw-semibold">
                        <td colspan="6" class="text-end">Totales (sin anuladas)</td>
                        <td class="app-num"><?= number_format($facturado, 2) ?></td>
                        <td class="app-num text-success"><?= number_format($cobrado, 2) ?></td>
                        <td class="app-num <?= $saldo > 0 ? 'text-danger' : '' ?>"><?= number_format($saldo, 2) ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
    <div class="app-pager" id="facturasPie"></div>
</div>

<div class="app-card">
    <div class="app-card-header"><span><i class="bi bi-clock-history me-1"></i> Abonos realizados</span><span class="app-badge"><?= count($abonos) ?></span></div>
    <div class="table-responsive">
        <table class="table app-table mb-0">
            <thead><tr><th class="app-n">#</th><th>Fecha</th><th>Factura</th><th>Método</th><th>Referencia</th><th>Notas</th><th class="app-num">Monto</th></tr></thead>
            <tbody>
                <?php $nAb = count($abonos); foreach ($abonos as $a): ?>
                    <tr class="<?= (int)$a['anulado'] ? 'text-muted text-decoration-line-through' : '' ?>">
                        <td class="app-n"><?= $nAb-- ?></td>
                        <td class="text-nowrap"><?= date('d/m/Y', strtotime($a['fecha'])) ?></td>
                        <td class="font-monospace small text-nowrap"><a href="ver_factura?id=<?= (int)$a['factura_id'] ?>" target="_blank"><?= htmlspecialchars($a['correlativo']) ?></a></td>
                        <td class="small"><?= $metodos[$a['metodo']] ?? htmlspecialchars($a['metodo']) ?><?= $a['banco'] ? '<br><span class="text-muted">' . htmlspecialchars($a['banco'] . ' ' . $a['cuenta_numero']) . '</span>' : '' ?></td>
                        <td class="small"><?= htmlspecialchars($a['referencia'] ?? '') ?: '—' ?></td>
                        <td class="small"><?= (int)$a['anulado'] ? 'Anulado: ' . htmlspecialchars($a['motivo_anulacion'] ?? '') : htmlspecialchars($a['notas'] ?? '') ?></td>
                        <td class="app-num fw-semibold"><?= $L($a['monto']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$abonos): ?><tr><td colspan="7" class="text-center text-muted py-4">Aún no hay abonos registrados para este cliente.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($instalado) require __DIR__ . '/includes/_modal_abonos.php'; ?>

<script src="../../clientes/js/app-tabla.js?v=<?= @filemtime(__DIR__ . '/../js/app-tabla.js') ?>"></script>
<script>
(function () {
    // Filtro: solo facturas con saldo / todas, con búsqueda y paginación
    let filtro = document.querySelector('[data-filtro].active')?.dataset.filtro || 'todas';
    const tabla = AppTabla('#tablaFacturas', { buscar: '#buscarFactura', pie: '#facturasPie', porPaginaInicial: 15,
        filtro: tr => filtro === 'todas' || tr.dataset.saldo === '1', vacio: 'Sin facturas pendientes.' });
    document.querySelectorAll('[data-filtro]').forEach(b => b.addEventListener('click', () => {
        filtro = b.dataset.filtro;
        document.querySelectorAll('[data-filtro]').forEach(x => x.classList.toggle('active', x === b));
        tabla.refrescar();
    }));
})();
</script>
<style>@media print { .no-print { display: none !important; } #tablaFacturas tbody tr[data-fila] { display: table-row !important; } }</style>

<?php require_once '../../includes/templates/footer.php'; ?>
