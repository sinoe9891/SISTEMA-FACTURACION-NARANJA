<?php
$titulo = 'Cheques';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/bancos.php';

$cid = cliente_actual();
$instalado = bancosDisponible($pdo);
$puedeEditar = in_array(USUARIO_ROL, ['admin', 'superadmin'], true);
$estado = in_array($_GET['estado'] ?? '', ['emitido', 'cobrado', 'anulado'], true) ? $_GET['estado'] : '';

$cuentasCheques = [];
$cheques = [];
$resumen = ['emitido' => [0, 0.0], 'cobrado' => [0, 0.0], 'anulado' => [0, 0.0]];
if ($instalado && $cid) {
    $cuentasCheques = array_values(array_filter(bancoCuentas($pdo, $cid, true), fn($c) => $c['tipo'] === 'cheques'));
    $stmt = $pdo->prepare("
        SELECT ch.*, c.banco, c.numero AS cuenta_numero, c.moneda
        FROM cheques ch JOIN cuentas_bancarias c ON c.id = ch.cuenta_id
        WHERE ch.cliente_id = ?" . ($estado ? " AND ch.estado = ?" : "") . "
        ORDER BY ch.fecha_emision DESC, ch.id DESC LIMIT 300
    ");
    $stmt->execute($estado ? [$cid, $estado] : [$cid]);
    $cheques = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $st = $pdo->prepare("SELECT estado, COUNT(*), SUM(monto) FROM cheques WHERE cliente_id = ? GROUP BY estado");
    $st->execute([$cid]);
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$e, $n, $s]) $resumen[$e] = [(int)$n, (float)$s];
    // Siguiente número sugerido por cuenta
    $sig = $pdo->prepare("SELECT cuenta_id, MAX(CAST(numero AS UNSIGNED)) FROM cheques WHERE cliente_id = ? GROUP BY cuenta_id");
    $sig->execute([$cid]);
    $siguientes = array_map(fn($n) => (int)$n + 1, array_column($sig->fetchAll(PDO::FETCH_NUM), 1, 0));
}
$badge = ['emitido' => 'app-badge-warning', 'cobrado' => 'app-badge-success', 'anulado' => 'app-badge-danger'];

require_once '../../includes/templates/header.php';
?>

<div class="app-page-header">
    <div>
        <h1 class="app-page-title">Cheques</h1>
        <p class="app-page-sub">Chequera: un cheque emitido no descuenta del banco hasta que se marca como cobrado.</p>
    </div>
    <?php if ($instalado && $puedeEditar): ?>
        <button class="btn btn-primary" id="btnEmitir" <?= $cuentasCheques ? '' : 'disabled title="Primero crea una cuenta de cheques en Bancos"' ?>><i class="bi bi-pencil-square me-1"></i> Emitir cheque</button>
    <?php endif; ?>
</div>

<?php if (!$instalado): ?>
    <div class="alert alert-warning">El módulo de bancos no está instalado. Ejecuta <code>sql/migraciones/2026-10-03_bancos.sql</code>.</div>
<?php else: ?>
    <div class="row g-3 mb-3">
        <div class="col-4"><a href="?estado=emitido" class="text-decoration-none"><div class="app-card app-kpi"><div class="app-kpi-label">En circulación</div><div class="app-kpi-value text-warning"><?= $resumen['emitido'][0] ?></div><small class="text-muted"><?= number_format($resumen['emitido'][1], 2) ?></small></div></a></div>
        <div class="col-4"><a href="?estado=cobrado" class="text-decoration-none"><div class="app-card app-kpi"><div class="app-kpi-label">Cobrados</div><div class="app-kpi-value text-success"><?= $resumen['cobrado'][0] ?></div><small class="text-muted"><?= number_format($resumen['cobrado'][1], 2) ?></small></div></a></div>
        <div class="col-4"><a href="?estado=anulado" class="text-decoration-none"><div class="app-card app-kpi"><div class="app-kpi-label">Anulados</div><div class="app-kpi-value text-danger"><?= $resumen['anulado'][0] ?></div><small class="text-muted"><?= number_format($resumen['anulado'][1], 2) ?></small></div></a></div>
    </div>

    <div class="app-card">
        <div class="app-card-header">
            <span><i class="bi bi-journal-check me-1"></i> <?= $estado ? 'Cheques: ' . $estado . 's' : 'Todos los cheques' ?></span>
            <?php if ($estado): ?><a href="cheques" class="small">Ver todos</a><?php endif; ?>
        </div>
        <div class="table-responsive">
            <table data-paginar class="table app-table">
                <thead><tr><th>N.°</th><th>Fecha</th><th>Cuenta</th><th>Beneficiario</th><th>Concepto</th><th class="app-num">Monto</th><th>Estado</th><?php if ($puedeEditar): ?><th class="text-end">Acciones</th><?php endif; ?></tr></thead>
                <tbody>
                    <?php if (!$cheques): ?><tr><td colspan="8" class="text-center text-muted py-4">No hay cheques.</td></tr><?php endif; ?>
                    <?php foreach ($cheques as $ch): ?>
                        <tr>
                            <td class="font-monospace fw-semibold"><?= htmlspecialchars($ch['numero']) ?></td>
                            <td class="text-nowrap"><?= date('d/m/Y', strtotime($ch['fecha_emision'])) ?></td>
                            <td class="small text-nowrap"><?= htmlspecialchars($ch['banco']) ?> <span class="font-monospace"><?= htmlspecialchars($ch['cuenta_numero']) ?></span></td>
                            <td><?= htmlspecialchars($ch['beneficiario']) ?></td>
                            <td class="small"><?= htmlspecialchars($ch['concepto'] ?? '') ?><?= $ch['estado'] === 'anulado' && $ch['motivo_anulacion'] ? '<div class="text-danger">Anulado: ' . htmlspecialchars($ch['motivo_anulacion']) . '</div>' : '' ?></td>
                            <td class="app-num"><?= bancoMoneda((float)$ch['monto'], $ch['moneda']) ?></td>
                            <td><span class="app-badge <?= $badge[$ch['estado']] ?>"><?= ucfirst($ch['estado']) ?></span><?= $ch['fecha_cobro'] ? '<div class="small text-muted">' . date('d/m/Y', strtotime($ch['fecha_cobro'])) . '</div>' : '' ?></td>
                            <?php if ($puedeEditar): ?>
                                <td class="text-end text-nowrap">
                                    <?php if ($ch['estado'] === 'emitido'): ?>
                                        <button class="btn btn-sm btn-outline-success btn-cobrar" data-id="<?= (int)$ch['id'] ?>" data-num="<?= htmlspecialchars($ch['numero']) ?>" data-fecha="<?= $ch['fecha_emision'] ?>">Cobrado</button>
                                    <?php endif; ?>
                                    <?php if ($ch['estado'] !== 'anulado'): ?>
                                        <button class="btn btn-sm btn-outline-danger btn-anular" data-id="<?= (int)$ch['id'] ?>" data-num="<?= htmlspecialchars($ch['numero']) ?>" data-estado="<?= $ch['estado'] ?>" title="Anular"><i class="bi bi-x-lg"></i></button>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($puedeEditar && $cuentasCheques): ?>
        <div class="modal fade" id="modalCheque" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-fullscreen-sm-down">
                <form class="modal-content" id="formCheque" novalidate>
                    <div class="modal-header"><h5 class="modal-title">Emitir cheque</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
                    <div class="modal-body row g-3">
                        <input type="hidden" name="accion" value="cheque_emitir">
                        <div class="col-12"><label class="form-label">Cuenta de cheques *</label>
                            <select class="form-select" name="cuenta_id" id="chCuenta">
                                <?php foreach ($cuentasCheques as $c): ?>
                                    <option value="<?= (int)$c['id'] ?>" data-siguiente="<?= $siguientes[$c['id']] ?? 1 ?>" data-disponible="<?= round((float)$c['saldo'] - (float)$c['comprometido'], 2) ?>" data-moneda="<?= $c['moneda'] ?>">
                                        <?= htmlspecialchars($c['banco'] . ' ' . $c['numero'] . ' (' . $c['moneda'] . ')') ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text" id="chDisponible"></div></div>
                        <div class="col-5"><label class="form-label">N.° de cheque *</label><input class="form-control font-monospace" name="numero" id="chNumero" required maxlength="20"></div>
                        <div class="col-7"><label class="form-label">Fecha *</label><input class="form-control" type="date" name="fecha_emision" required value="<?= date('Y-m-d') ?>"></div>
                        <div class="col-12"><label class="form-label">Páguese a la orden de *</label><input class="form-control" name="beneficiario" required maxlength="150"></div>
                        <div class="col-6"><label class="form-label">Monto *</label><input class="form-control" type="number" step="0.01" min="0.01" name="monto" id="chMonto" required></div>
                        <div class="col-12"><label class="form-label">Concepto</label><input class="form-control" name="concepto" maxlength="255"></div>
                        <div class="col-12 d-none" id="chAviso"><div class="alert alert-warning py-2 mb-0 small"><i class="bi bi-exclamation-triangle"></i> El monto supera el disponible de la cuenta (saldo menos cheques sin cobrar).</div></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Registrar cheque</button></div>
                </form>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<script src="../../clientes/js/bancos.js?v=<?= @filemtime(__DIR__ . '/../js/bancos.js') ?>"></script>
<script>
(function () {
    const B = window.Bancos;
    const f = document.getElementById('formCheque');
    if (f) {
        const sel = document.getElementById('chCuenta');
        const actualizar = () => {
            const o = sel.selectedOptions[0];
            document.getElementById('chNumero').value = o.dataset.siguiente;
            const disp = parseFloat(o.dataset.disponible), m = parseFloat(document.getElementById('chMonto').value) || 0;
            document.getElementById('chDisponible').textContent = 'Disponible: ' + (o.dataset.moneda === 'USD' ? '$ ' : 'L ') + disp.toLocaleString('es-HN', { minimumFractionDigits: 2 });
            document.getElementById('chAviso').classList.toggle('d-none', !(m > disp));
        };
        sel.addEventListener('change', actualizar);
        document.getElementById('chMonto').addEventListener('input', () => {
            const o = sel.selectedOptions[0];
            document.getElementById('chAviso').classList.toggle('d-none', !((parseFloat(document.getElementById('chMonto').value) || 0) > parseFloat(o.dataset.disponible)));
        });
        document.getElementById('btnEmitir').addEventListener('click', () => { f.reset(); actualizar(); B.modal('modalCheque').show(); });
        B.formulario(f);
    }
    document.querySelectorAll('.btn-cobrar').forEach(b => b.addEventListener('click', () => {
        Swal.fire({
            title: 'Cheque N.° ' + b.dataset.num + ' cobrado',
            html: '<label class="form-label">Fecha en que el banco lo pagó</label><input type="date" id="fCobro" class="form-control" value="' + new Date().toLocaleDateString('sv-SE') + '" min="' + b.dataset.fecha + '">',
            showCancelButton: true, confirmButtonText: 'Marcar cobrado', cancelButtonText: 'Cancelar',
            preConfirm: () => document.getElementById('fCobro').value || Swal.showValidationMessage('Indica la fecha')
        }).then(r => { if (r.isConfirmed) B.accion({ accion: 'cheque_cobrar', id: b.dataset.id, fecha: r.value }).catch(() => {}); });
    }));
    document.querySelectorAll('.btn-anular').forEach(b => b.addEventListener('click', () => {
        Swal.fire({
            title: 'Anular cheque N.° ' + b.dataset.num,
            text: b.dataset.estado === 'cobrado' ? 'Ya estaba cobrado: también se anulará su salida del banco.' : 'Quedará registrado como anulado.',
            input: 'text', inputPlaceholder: 'Motivo (obligatorio)', icon: 'warning',
            showCancelButton: true, confirmButtonText: 'Anular', cancelButtonText: 'Cancelar', confirmButtonColor: '#dc2626',
            inputValidator: v => !v.trim() && 'Indica el motivo'
        }).then(r => { if (r.isConfirmed) B.accion({ accion: 'cheque_anular', id: b.dataset.id, motivo: r.value }).catch(() => {}); });
    }));
})();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
