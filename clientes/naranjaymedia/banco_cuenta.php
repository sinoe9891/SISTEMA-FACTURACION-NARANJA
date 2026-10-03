<?php
$titulo = 'Bancos';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/bancos.php';

$cid = cliente_actual();
if (!bancosDisponible($pdo)) {
    header('Location: ./bancos');
    exit;
}
try {
    $cuenta = bancoCuenta($pdo, $cid, (int)($_GET['id'] ?? 0));
} catch (Exception $e) {
    header('Location: ./bancos');
    exit;
}
$puedeEditar = in_array(USUARIO_ROL, ['admin', 'superadmin'], true);
$mon = $cuenta['moneda'];

$valida = fn($f) => ($d = DateTime::createFromFormat('Y-m-d', (string)$f)) && $d->format('Y-m-d') === $f;
$desde = $valida($_GET['desde'] ?? '') ? $_GET['desde'] : date('Y-m-01');
$hasta = $valida($_GET['hasta'] ?? '') ? $_GET['hasta'] : date('Y-m-t');
if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];
$verAnulados = !empty($_GET['anulados']);

// Saldo al inicio del período (saldo inicial + movimientos anteriores)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN sentido = 'entrada' THEN monto ELSE -monto END), 0)
    FROM movimientos_bancarios WHERE cuenta_id = ? AND anulado = 0 AND fecha < ?");
$stmt->execute([$cuenta['id'], $desde]);
$saldoAnterior = round((float)$cuenta['saldo_inicial'] + (float)$stmt->fetchColumn(), 2);

$stmt = $pdo->prepare("SELECT * FROM movimientos_bancarios WHERE cuenta_id = ? AND fecha BETWEEN ? AND ?" . ($verAnulados ? "" : " AND anulado = 0") . " ORDER BY fecha, id");
$stmt->execute([$cuenta['id'], $desde, $hasta]);
$movs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$saldo = $saldoAnterior;
$entradas = $salidas = 0.0;
foreach ($movs as &$m) {
    if (!(int)$m['anulado']) {
        $v = (float)$m['monto'];
        $m['sentido'] === 'entrada' ? ($entradas += $v) : ($salidas += $v);
        $saldo += $m['sentido'] === 'entrada' ? $v : -$v;
    }
    $m['saldo'] = round($saldo, 2);
}
unset($m);
$saldoActual = bancoSaldo($pdo, (int)$cuenta['id']);
$etiquetasTipo = ['deposito' => 'Depósito', 'retiro' => 'Retiro', 'transferencia' => 'Transferencia', 'cheque' => 'Cheque',
    'pago_gasto' => 'Pago de gasto', 'cobro_factura' => 'Cobro de factura', 'comision' => 'Comisión', 'interes' => 'Interés', 'ajuste' => 'Ajuste'];

require_once '../../includes/templates/header.php';
?>

<div class="app-page-header">
    <div>
        <a href="bancos" class="small text-muted text-decoration-none"><i class="bi bi-arrow-left"></i> Bancos</a>
        <h1 class="app-page-title"><?= htmlspecialchars($cuenta['banco']) ?> <span class="font-monospace fs-5 text-muted"><?= htmlspecialchars($cuenta['numero']) ?></span></h1>
        <p class="app-page-sub">Cuenta de <?= $cuenta['tipo'] === 'cheques' ? 'cheques' : 'ahorro' ?> en <?= $mon === 'USD' ? 'dólares' : 'lempiras' ?><?= $cuenta['titular'] ? ' · ' . htmlspecialchars($cuenta['titular']) : '' ?></p>
    </div>
    <div class="text-end">
        <div class="app-kpi-label">Saldo actual</div>
        <div class="app-kpi-value <?= $saldoActual < 0 ? 'text-danger' : '' ?>"><?= bancoMoneda($saldoActual, $mon) ?></div>
    </div>
</div>

<form class="app-card app-card-body mb-3 d-flex flex-wrap align-items-end gap-2" method="GET">
    <input type="hidden" name="id" value="<?= (int)$cuenta['id'] ?>">
    <div><label class="form-label mb-1">Desde</label><input type="date" class="form-control" name="desde" value="<?= $desde ?>"></div>
    <div><label class="form-label mb-1">Hasta</label><input type="date" class="form-control" name="hasta" value="<?= $hasta ?>"></div>
    <div class="form-check ms-1 mb-2"><input class="form-check-input" type="checkbox" name="anulados" value="1" id="verAnul" <?= $verAnulados ? 'checked' : '' ?>><label class="form-check-label small" for="verAnul">Ver anulados</label></div>
    <button class="btn btn-primary"><i class="bi bi-funnel"></i> Filtrar</button>
</form>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="app-card app-kpi"><div class="app-kpi-label">Saldo al <?= date('d/m/Y', strtotime($desde)) ?></div><div class="app-kpi-value fs-5"><?= bancoMoneda($saldoAnterior, $mon) ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="app-card app-kpi"><div class="app-kpi-label">Entradas</div><div class="app-kpi-value fs-5 text-success"><?= bancoMoneda($entradas, $mon) ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="app-card app-kpi"><div class="app-kpi-label">Salidas</div><div class="app-kpi-value fs-5 text-danger"><?= bancoMoneda($salidas, $mon) ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="app-card app-kpi"><div class="app-kpi-label">Saldo al <?= date('d/m/Y', strtotime($hasta)) ?></div><div class="app-kpi-value fs-5"><?= bancoMoneda($saldo, $mon) ?></div></div></div>
</div>

<div class="app-card">
    <div class="table-responsive">
        <table class="table app-table">
            <thead><tr><th>Fecha</th><th>Tipo</th><th>Descripción</th><th>Ref.</th><th class="app-num">Entrada</th><th class="app-num">Salida</th><th class="app-num">Saldo</th><th class="text-center" title="Conciliado con el estado de cuenta">Concil.</th><?php if ($puedeEditar): ?><th></th><?php endif; ?></tr></thead>
            <tbody>
                <tr class="table-light"><td colspan="6" class="small text-muted">Saldo anterior</td><td class="app-num fw-semibold"><?= bancoMoneda($saldoAnterior, $mon) ?></td><td colspan="<?= $puedeEditar ? 2 : 1 ?>"></td></tr>
                <?php if (!$movs): ?><tr><td colspan="9" class="text-center text-muted py-4">Sin movimientos en este período.</td></tr><?php endif; ?>
                <?php foreach ($movs as $m): $anul = (int)$m['anulado']; ?>
                    <tr class="<?= $anul ? 'text-muted text-decoration-line-through' : '' ?>">
                        <td class="text-nowrap"><?= date('d/m/Y', strtotime($m['fecha'])) ?></td>
                        <td><span class="app-badge app-badge-muted"><?= $etiquetasTipo[$m['tipo']] ?? $m['tipo'] ?></span></td>
                        <td><?= htmlspecialchars($m['descripcion']) ?><?= $m['tasa_cambio'] ? ' <small class="text-muted">(tasa ' . rtrim(rtrim(number_format((float)$m['tasa_cambio'], 4), '0'), '.') . ')</small>' : '' ?><?= $anul ? ' <span class="app-badge app-badge-danger">Anulado</span>' : '' ?></td>
                        <td class="small font-monospace"><?= htmlspecialchars($m['referencia'] ?? '') ?></td>
                        <td class="app-num text-success"><?= $m['sentido'] === 'entrada' ? bancoMoneda((float)$m['monto'], $mon) : '' ?></td>
                        <td class="app-num text-danger"><?= $m['sentido'] === 'salida' ? bancoMoneda((float)$m['monto'], $mon) : '' ?></td>
                        <td class="app-num"><?= $anul ? '' : bancoMoneda((float)$m['saldo'], $mon) ?></td>
                        <td class="text-center">
                            <?php if (!$anul): ?>
                                <input type="checkbox" class="form-check-input chk-conciliar" data-id="<?= (int)$m['id'] ?>" <?= (int)$m['conciliado'] ? 'checked' : '' ?> <?= $puedeEditar ? '' : 'disabled' ?> aria-label="Conciliado">
                            <?php endif; ?>
                        </td>
                        <?php if ($puedeEditar): ?>
                            <td class="text-end">
                                <?php if (!$anul && !$m['cheque_id'] && !(int)$m['conciliado']): ?>
                                    <button class="btn btn-sm btn-link text-danger p-0 btn-anular-mov" data-id="<?= (int)$m['id'] ?>" data-transf="<?= $m['transferencia_grupo'] ? 1 : 0 ?>" title="Anular"><i class="bi bi-x-circle"></i></button>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="../../clientes/js/bancos.js?v=<?= @filemtime(__DIR__ . '/../js/bancos.js') ?>"></script>
<script>
(function () {
    const B = window.Bancos;
    document.querySelectorAll('.chk-conciliar').forEach(ch => ch.addEventListener('change', () => {
        B.accion({ accion: 'conciliar', id: ch.dataset.id, conciliado: ch.checked ? 1 : 0 }, { sinRecargar: true })
            .catch(() => ch.checked = !ch.checked);
    }));
    document.querySelectorAll('.btn-anular-mov').forEach(b => b.addEventListener('click', () => {
        Swal.fire({
            title: '¿Anular movimiento?',
            text: b.dataset.transf === '1' ? 'Es una transferencia: se anularán la salida y la entrada.' : 'El movimiento queda registrado como anulado.',
            icon: 'warning', showCancelButton: true, confirmButtonText: 'Anular', cancelButtonText: 'Cancelar', confirmButtonColor: '#dc2626'
        }).then(r => { if (r.isConfirmed) B.accion({ accion: 'anular_movimiento', id: b.dataset.id }).catch(() => {}); });
    }));
})();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
