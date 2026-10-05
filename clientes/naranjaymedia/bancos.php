<?php
$titulo = 'Bancos';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/bancos.php';

$cid = cliente_actual();
$puedeEditar = in_array(USUARIO_ROL, ['admin', 'superadmin'], true);
$instalado = bancosDisponible($pdo);
$cuentas = $instalado && $cid ? bancoCuentas($pdo, $cid) : [];
$activas = array_values(array_filter($cuentas, fn($c) => (int)$c['activa']));

$totales = ['HNL' => 0.0, 'USD' => 0.0];
$comprometido = ['HNL' => 0.0, 'USD' => 0.0];
foreach ($activas as $c) {
    $totales[$c['moneda']] += (float)$c['saldo'];
    $comprometido[$c['moneda']] += (float)$c['comprometido'];
}

$recientes = [];
if ($instalado && $cid) {
    $stmt = $pdo->prepare("
        SELECT m.*, c.banco, c.numero, c.moneda
        FROM movimientos_bancarios m JOIN cuentas_bancarias c ON c.id = m.cuenta_id
        WHERE m.cliente_id = ? ORDER BY m.fecha DESC, m.id DESC LIMIT 15
    ");
    $stmt->execute([$cid]);
    $recientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$etiquetasTipo = ['deposito' => 'Depósito', 'retiro' => 'Retiro', 'transferencia' => 'Transferencia', 'cheque' => 'Cheque',
    'pago_gasto' => 'Pago de gasto', 'cobro_factura' => 'Cobro de factura', 'comision' => 'Comisión', 'interes' => 'Interés', 'ajuste' => 'Ajuste'];

require_once '../../includes/templates/header.php';
?>

<div class="app-page-header">
    <div>
        <h1 class="app-page-title">Bancos</h1>
        <p class="app-page-sub">Cuentas de ahorro y de cheques, en lempiras o dólares.</p>
    </div>
    <?php if ($instalado && $puedeEditar): ?>
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-outline-primary" data-abrir="modalMovimiento" <?= $activas ? '' : 'disabled' ?>><i class="bi bi-plus-slash-minus me-1"></i> Movimiento</button>
            <button class="btn btn-outline-primary" data-abrir="modalTransferencia" <?= count($activas) > 1 ? '' : 'disabled' ?>><i class="bi bi-arrow-left-right me-1"></i> Transferencia</button>
            <button class="btn btn-primary" id="btnNuevaCuenta"><i class="bi bi-bank me-1"></i> Nueva cuenta</button>
        </div>
    <?php endif; ?>
</div>

<?php if (!$instalado): ?>
    <div class="alert alert-warning"><i class="bi bi-tools me-1"></i> El módulo de bancos no está instalado en esta base de datos.
        Ejecuta <code>sql/migraciones/2026-10-03_bancos.sql</code>.</div>
<?php else: ?>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><div class="app-card app-kpi"><div class="app-kpi-label">Saldo en lempiras</div><div class="app-kpi-value"><?= bancoMoneda($totales['HNL'], 'HNL') ?></div></div></div>
        <div class="col-6 col-lg-3"><div class="app-card app-kpi"><div class="app-kpi-label">Saldo en dólares</div><div class="app-kpi-value"><?= bancoMoneda($totales['USD'], 'USD') ?></div></div></div>
        <div class="col-6 col-lg-3"><div class="app-card app-kpi"><div class="app-kpi-label">Cheques por cobrar (L)</div><div class="app-kpi-value text-warning"><?= bancoMoneda($comprometido['HNL'], 'HNL') ?></div></div></div>
        <div class="col-6 col-lg-3"><div class="app-card app-kpi"><div class="app-kpi-label">Cuentas activas</div><div class="app-kpi-value"><?= count($activas) ?></div></div></div>
    </div>

    <?php if (!$cuentas): ?>
        <div class="app-card app-card-body text-center text-muted py-5">
            <i class="bi bi-bank fs-1 d-block mb-2"></i>
            Aún no hay cuentas bancarias. <?= $puedeEditar ? 'Crea la primera con «Nueva cuenta».' : '' ?>
        </div>
    <?php else: ?>
        <div class="row g-3 mb-4">
            <?php foreach ($cuentas as $c):
                $disp = (float)$c['saldo'] - (float)$c['comprometido']; ?>
                <div class="col-md-6 col-xl-4">
                    <div class="app-card h-100 <?= (int)$c['activa'] ? '' : 'opacity-50' ?>">
                        <div class="app-card-body">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div style="min-width:0">
                                    <div class="fw-bold text-truncate"><?= htmlspecialchars($c['banco']) ?></div>
                                    <div class="small text-muted font-monospace"><?= htmlspecialchars($c['numero']) ?></div>
                                </div>
                                <div class="d-flex gap-1 flex-shrink-0">
                                    <span class="app-badge app-badge-info"><?= $c['tipo'] === 'cheques' ? 'Cheques' : 'Ahorro' ?></span>
                                    <span class="app-badge app-badge-muted"><?= htmlspecialchars($c['moneda']) ?></span>
                                    <?php if (!(int)$c['activa']): ?><span class="app-badge app-badge-danger">Inactiva</span><?php endif; ?>
                                    <?php if (!empty($c['predeterminada'])): ?><span class="app-badge app-badge-warning" title="Se elige por defecto al registrar cobros y pagos"><i class="bi bi-star-fill"></i> Predeterminada</span><?php endif; ?>
                                </div>
                            </div>
                            <div class="mt-3">
                                <div class="app-kpi-label">Saldo</div>
                                <div class="app-kpi-value <?= (float)$c['saldo'] < 0 ? 'text-danger' : '' ?>"><?= bancoMoneda((float)$c['saldo'], $c['moneda']) ?></div>
                                <?php if ((float)$c['comprometido'] > 0): ?>
                                    <div class="small text-muted">Cheques sin cobrar: <?= bancoMoneda((float)$c['comprometido'], $c['moneda']) ?> · Disponible: <strong class="<?= $disp < 0 ? 'text-danger' : '' ?>"><?= bancoMoneda($disp, $c['moneda']) ?></strong></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="app-card-header border-top border-bottom-0 py-2">
                            <a href="banco_cuenta?id=<?= (int)$c['id'] ?>" class="small"><i class="bi bi-journal-text me-1"></i>Movimientos</a>
                            <?php if ($puedeEditar): ?>
                                <span class="d-flex gap-1">
                                    <button class="btn btn-sm btn-link p-0 btn-editar-cuenta" data-cuenta='<?= json_encode($c, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>'>Editar</button>
                                    <span class="text-muted">·</span>
                                    <button class="btn btn-sm btn-link p-0 btn-estado-cuenta" data-id="<?= (int)$c['id'] ?>"><?= (int)$c['activa'] ? 'Desactivar' : 'Activar' ?></button>
                                    <?php if ((int)$c['activa'] && empty($c['predeterminada'])): ?>
                                        <span class="text-muted">·</span>
                                        <button class="btn btn-sm btn-link p-0 btn-predeterminar" data-id="<?= (int)$c['id'] ?>" title="Elegirla por defecto en cobros y pagos"><i class="bi bi-star"></i> Predeterminar</button>
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="app-card">
            <div class="app-card-header"><span><i class="bi bi-clock-history me-1"></i> Últimos movimientos</span><a href="cheques" class="small">Chequera →</a></div>
            <div class="table-responsive">
                <table data-paginar class="table app-table">
                    <thead><tr><th>Fecha</th><th>Cuenta</th><th>Tipo</th><th>Descripción</th><th class="app-num">Entrada</th><th class="app-num">Salida</th></tr></thead>
                    <tbody>
                        <?php if (!$recientes): ?><tr><td colspan="6" class="text-center text-muted py-4">Sin movimientos.</td></tr><?php endif; ?>
                        <?php foreach ($recientes as $m): ?>
                            <tr class="<?= (int)$m['anulado'] ? 'text-decoration-line-through text-muted' : '' ?>">
                                <td class="text-nowrap"><?= date('d/m/Y', strtotime($m['fecha'])) ?></td>
                                <td class="text-nowrap small"><?= htmlspecialchars($m['banco']) ?> <span class="font-monospace"><?= htmlspecialchars($m['numero']) ?></span></td>
                                <td><span class="app-badge app-badge-muted"><?= $etiquetasTipo[$m['tipo']] ?? $m['tipo'] ?></span></td>
                                <td><?= htmlspecialchars($m['descripcion']) ?><?= (int)$m['anulado'] ? ' <span class="app-badge app-badge-danger">Anulado</span>' : '' ?></td>
                                <td class="app-num text-success"><?= $m['sentido'] === 'entrada' ? bancoMoneda((float)$m['monto'], $m['moneda']) : '' ?></td>
                                <td class="app-num text-danger"><?= $m['sentido'] === 'salida' ? bancoMoneda((float)$m['monto'], $m['moneda']) : '' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($puedeEditar): ?>
        <!-- Modal cuenta -->
        <div class="modal fade" id="modalCuenta" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-fullscreen-sm-down">
                <form class="modal-content" id="formCuenta" novalidate>
                    <div class="modal-header"><h5 class="modal-title" id="tituloCuenta">Nueva cuenta</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
                    <div class="modal-body row g-3">
                        <input type="hidden" name="accion" value="cuenta_guardar"><input type="hidden" name="id">
                        <div class="col-12"><label class="form-label">Banco *</label><input class="form-control" name="banco" required maxlength="100" list="listaBancos" placeholder="BAC, Banpaís, Atlántida…">
                            <datalist id="listaBancos"><option>BAC Credomatic</option><option>Banco Atlántida</option><option>Banpaís</option><option>Banco Ficohsa</option><option>Banco de Occidente</option><option>Davivienda</option><option>Banrural</option><option>Banco Promérica</option><option>Lafise</option><option>Banco Azteca</option></datalist></div>
                        <div class="col-7"><label class="form-label">Número de cuenta *</label><input class="form-control font-monospace" name="numero" required maxlength="40"></div>
                        <div class="col-5"><label class="form-label">Tipo</label><select class="form-select" name="tipo"><option value="ahorro">Ahorro</option><option value="cheques">Cheques</option></select></div>
                        <div class="col-7"><label class="form-label">Titular</label><input class="form-control" name="titular" maxlength="150"></div>
                        <div class="col-5"><label class="form-label">Moneda</label><select class="form-select" name="moneda"><option value="HNL">Lempiras (L)</option><option value="USD">Dólares ($)</option></select></div>
                        <div class="col-6"><label class="form-label">Saldo inicial</label><input class="form-control" type="number" step="0.01" name="saldo_inicial" value="0"></div>
                        <div class="col-6"><label class="form-label">Saldo a la fecha</label><input class="form-control" type="date" name="fecha_saldo_inicial" value="<?= date('Y-m-d') ?>"></div>
                        <div class="col-12"><label class="form-label">Notas</label><input class="form-control" name="notas" maxlength="255"></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Guardar</button></div>
                </form>
            </div>
        </div>

        <!-- Modal movimiento -->
        <div class="modal fade" id="modalMovimiento" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-fullscreen-sm-down">
                <form class="modal-content" id="formMovimiento" novalidate>
                    <div class="modal-header"><h5 class="modal-title">Registrar movimiento</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
                    <div class="modal-body row g-3">
                        <input type="hidden" name="accion" value="movimiento">
                        <div class="col-12"><label class="form-label">Cuenta *</label>
                            <select class="form-select" name="cuenta_id" required><?php foreach ($activas as $c): ?><option value="<?= (int)$c['id'] ?>"<?= bancoSel($c) ?>><?= htmlspecialchars($c['banco'] . ' ' . $c['numero'] . ' (' . $c['moneda'] . ')') ?></option><?php endforeach; ?></select></div>
                        <div class="col-6"><label class="form-label">Tipo *</label>
                            <select class="form-select" name="tipo" id="movTipo"><option value="deposito">Depósito (+)</option><option value="retiro">Retiro (−)</option><option value="comision">Comisión bancaria (−)</option><option value="interes">Interés ganado (+)</option><option value="ajuste">Ajuste (±)</option></select></div>
                        <div class="col-6 d-none" id="movSentidoWrap"><label class="form-label">El ajuste…</label>
                            <select class="form-select" name="sentido"><option value="entrada">Suma</option><option value="salida">Resta</option></select></div>
                        <div class="col-6"><label class="form-label">Fecha *</label><input class="form-control" type="date" name="fecha" required value="<?= date('Y-m-d') ?>"></div>
                        <div class="col-6"><label class="form-label">Monto *</label><input class="form-control" type="number" step="0.01" min="0.01" name="monto" required></div>
                        <div class="col-6"><label class="form-label">Referencia</label><input class="form-control" name="referencia" maxlength="100" placeholder="N.° de boleta, etc."></div>
                        <div class="col-12"><label class="form-label">Descripción *</label><input class="form-control" name="descripcion" required maxlength="255"></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Registrar</button></div>
                </form>
            </div>
        </div>

        <!-- Modal transferencia -->
        <div class="modal fade" id="modalTransferencia" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-fullscreen-sm-down">
                <form class="modal-content" id="formTransferencia" novalidate>
                    <div class="modal-header"><h5 class="modal-title">Transferencia entre cuentas</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
                    <div class="modal-body row g-3">
                        <input type="hidden" name="accion" value="transferencia">
                        <?php $opts = implode('', array_map(fn($c) => '<option value="' . (int)$c['id'] . '" data-moneda="' . $c['moneda'] . '">' . htmlspecialchars($c['banco'] . ' ' . $c['numero'] . ' (' . $c['moneda'] . ')') . '</option>', $activas)); ?>
                        <div class="col-12"><label class="form-label">Desde *</label><select class="form-select" name="origen_id" id="trOrigen"><?= $opts ?></select></div>
                        <div class="col-12"><label class="form-label">Hacia *</label><select class="form-select" name="destino_id" id="trDestino"><?= $opts ?></select></div>
                        <div class="col-6"><label class="form-label">Monto (moneda de origen) *</label><input class="form-control" type="number" step="0.01" min="0.01" name="monto" id="trMonto" required></div>
                        <div class="col-6"><label class="form-label">Fecha *</label><input class="form-control" type="date" name="fecha" required value="<?= date('Y-m-d') ?>"></div>
                        <div class="col-12 d-none" id="trTasaWrap">
                            <label class="form-label">Tasa de cambio (L por $1) *</label><input class="form-control" type="number" step="0.0001" min="0.0001" name="tasa_cambio" id="trTasa">
                            <div class="form-text" id="trConversion"></div>
                        </div>
                        <div class="col-12"><label class="form-label">Nota</label><input class="form-control" name="descripcion" maxlength="200"></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Transferir</button></div>
                </form>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<script src="../../clientes/js/bancos.js?v=<?= @filemtime(__DIR__ . '/../js/bancos.js') ?>"></script>
<script>
(function () {
    const B = window.Bancos;
    if (!B || !document.getElementById('modalCuenta')) return;
    const fCuenta = document.getElementById('formCuenta');
    document.getElementById('btnNuevaCuenta').addEventListener('click', () => {
        fCuenta.reset(); fCuenta.elements.id.value = '';
        document.getElementById('tituloCuenta').textContent = 'Nueva cuenta';
        B.modal('modalCuenta').show();
    });
    document.querySelectorAll('.btn-editar-cuenta').forEach(b => b.addEventListener('click', () => {
        const c = JSON.parse(b.dataset.cuenta);
        fCuenta.reset();
        ['id', 'banco', 'numero', 'tipo', 'titular', 'moneda', 'saldo_inicial', 'fecha_saldo_inicial', 'notas'].forEach(k => fCuenta.elements[k].value = c[k] ?? '');
        document.getElementById('tituloCuenta').textContent = 'Editar cuenta';
        B.modal('modalCuenta').show();
    }));
    document.querySelectorAll('.btn-estado-cuenta').forEach(b => b.addEventListener('click', () => B.accion({ accion: 'cuenta_estado', id: b.dataset.id })));
    document.querySelectorAll('.btn-predeterminar').forEach(b => b.addEventListener('click', () => B.accion({ accion: 'predeterminar', id: b.dataset.id })));
    document.querySelectorAll('[data-abrir]').forEach(b => b.addEventListener('click', () => B.modal(b.dataset.abrir).show()));
    B.formulario(fCuenta);
    B.formulario(document.getElementById('formMovimiento'));
    B.formulario(document.getElementById('formTransferencia'));

    document.getElementById('movTipo').addEventListener('change', function () {
        document.getElementById('movSentidoWrap').classList.toggle('d-none', this.value !== 'ajuste');
    });
    // Transferencia: tasa solo si las monedas son distintas
    const o = document.getElementById('trOrigen'), d = document.getElementById('trDestino');
    if (d.options.length > 1) d.selectedIndex = 1;
    function revisarTasa() {
        const mo = o.selectedOptions[0]?.dataset.moneda, md = d.selectedOptions[0]?.dataset.moneda;
        const distinta = mo && md && mo !== md;
        document.getElementById('trTasaWrap').classList.toggle('d-none', !distinta);
        document.getElementById('trTasa').required = distinta;
        const m = parseFloat(document.getElementById('trMonto').value), t = parseFloat(document.getElementById('trTasa').value);
        document.getElementById('trConversion').textContent = distinta && m > 0 && t > 0
            ? 'Llegarán ' + (mo === 'USD' ? 'L ' + (m * t).toFixed(2) : '$ ' + (m / t).toFixed(2)) : '';
    }
    [o, d, document.getElementById('trMonto'), document.getElementById('trTasa')].forEach(e => e.addEventListener('input', revisarTasa));
    revisarTasa();
})();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
