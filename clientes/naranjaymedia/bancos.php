<?php
$titulo = 'Bancos';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/bancos.php';

$cid = cliente_actual();
$puedeEditar = in_array(USUARIO_ROL, ['admin', 'superadmin'], true);           // cuentas: crear, editar, activar, predeterminar
$puedeMover = $puedeEditar || USUARIO_ROL === 'nomina';                          // movimientos y transferencias
$instalado = bancosDisponible($pdo);
$cuentas = $instalado && $cid ? bancoCuentas($pdo, $cid) : [];
$activas = array_values(array_filter($cuentas, fn($c) => (int)$c['activa']));
require_once '../../includes/tasa_cambio.php';
$tasa = tasaUltima($pdo);
$tasaHoy = $tasa && $tasa['fecha'] === date('Y-m-d');
// Categorías de gastos (para registrar una salida también como gasto)
$catsGasto = [];
if ($puedeMover && $cid) {
    $stCat = $pdo->prepare("SELECT id, nombre FROM categorias_gastos WHERE cliente_id = ? ORDER BY nombre");
    $stCat->execute([$cid]);
    $catsGasto = $stCat->fetchAll(PDO::FETCH_ASSOC);
}

$totales = ['HNL' => 0.0, 'USD' => 0.0];
$comprometido = ['HNL' => 0.0, 'USD' => 0.0];
$porTipoHnl = ['ahorro' => 0.0, 'cheques' => 0.0];   // cuentas en lempiras: de ahorro y de cheques
foreach ($activas as $c) {
    $totales[$c['moneda']] += (float)$c['saldo'];
    if ($c['moneda'] === 'HNL') $porTipoHnl[$c['tipo'] === 'cheques' ? 'cheques' : 'ahorro'] += (float)$c['saldo'];
    $comprometido[$c['moneda']] += (float)$c['comprometido'];
}

$recientes = [];
if ($instalado && $cid) {
    $stmt = $pdo->prepare("
        SELECT m.*, c.banco, c.numero, c.moneda, c.tipo AS cuenta_tipo
        FROM movimientos_bancarios m JOIN cuentas_bancarias c ON c.id = m.cuenta_id
        WHERE m.cliente_id = ? ORDER BY m.fecha DESC, m.id DESC
    ");
    $stmt->execute([$cid]);
    $recientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$etiquetasTipo = ['deposito' => 'Depósito', 'retiro' => 'Retiro', 'transferencia' => 'Transferencia', 'cheque' => 'Cheque',
    'prestamo_socio' => 'Préstamo a socio', 'pago_gasto' => 'Pago de gasto', 'cobro_factura' => 'Cobro de factura', 'comision' => 'Comisión', 'interes' => 'Interés', 'ajuste' => 'Ajuste'];

require_once '../../includes/templates/header.php';
?>

<div class="app-page-header">
    <div>
        <h1 class="app-page-title">Bancos</h1>
        <p class="app-page-sub">Cuentas de ahorro y de cheques, en lempiras o dólares.</p>
    </div>
    <?php if ($instalado && $puedeMover): ?>
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-outline-primary" data-abrir="modalMovimiento" <?= $activas ? '' : 'disabled' ?>><i class="bi bi-plus-slash-minus me-1"></i> Movimiento</button>
            <button class="btn btn-outline-primary" data-abrir="modalTransferencia" <?= count($activas) > 1 ? '' : 'disabled' ?>><i class="bi bi-arrow-left-right me-1"></i> Transferencia</button>
            <?php if ($puedeEditar): ?><button class="btn btn-primary" id="btnNuevaCuenta"><i class="bi bi-bank me-1"></i> Nueva cuenta</button><?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php if (!$instalado): ?>
    <div class="alert alert-warning"><i class="bi bi-tools me-1"></i> El módulo de bancos no está instalado en esta base de datos.
        Ejecuta <code>sql/migraciones/2026-10-03_bancos.sql</code>.</div>
<?php else: ?>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><div class="app-card app-kpi"><div class="app-kpi-label">Saldo en lempiras</div><div class="app-kpi-value"><?= bancoMoneda($totales['HNL'], 'HNL') ?></div>
            <div class="small text-muted">Ahorro <?= bancoMoneda($porTipoHnl['ahorro'], 'HNL') ?> · Cheques <?= bancoMoneda($porTipoHnl['cheques'], 'HNL') ?></div>
            <?php if ($tasa && $totales['USD'] > 0): ?><div class="small fw-semibold mt-1" title="Lempiras más los dólares a la tasa de compra">Todo junto ≈ <?= bancoMoneda($totales['HNL'] + $totales['USD'] * (float)($tasa['compra'] ?: $tasa['referencia']), 'HNL') ?></div><?php endif; ?></div></div>
        <div class="col-6 col-lg-3"><div class="app-card app-kpi"><div class="app-kpi-label">Saldo en dólares</div><div class="app-kpi-value"><?= bancoMoneda($totales['USD'], 'USD') ?></div>
            <?php if ($tasa && $totales['USD'] > 0): ?><div class="small text-muted">≈ <?= bancoMoneda($totales['USD'] * (float)($tasa['compra'] ?: $tasa['referencia']), 'HNL') ?></div><?php endif; ?></div></div>
        <div class="col-6 col-lg-3"><div class="app-card app-kpi"><div class="app-kpi-label">Cheques por cobrar (L)</div><div class="app-kpi-value text-warning"><?= bancoMoneda($comprometido['HNL'], 'HNL') ?></div></div></div>
        <div class="col-6 col-lg-3"><div class="app-card app-kpi"><div class="app-kpi-label d-flex justify-content-between align-items-center">Dólar <?= $tasa ? ($tasa['fuente'] === 'BCH' ? '(BCH)' : '(referencia)') : '' ?>
                <span><?php if ($puedeMover): ?><button type="button" class="btn btn-link btn-sm p-0" id="btnTasa" title="Actualizar la tasa en línea"><i class="bi bi-arrow-clockwise"></i></button><?php endif; ?>
                <?php if ($puedeEditar): ?><button type="button" class="btn btn-link btn-sm p-0 ms-1" id="btnTasaClave" title="Clave de la Web-API del Banco Central de Honduras"><i class="bi bi-key"></i></button><?php endif; ?></span></div>
            <div class="app-kpi-value" style="font-size:1rem"><?php if (!$tasa): ?><span class="text-muted">Sin tasa</span>
                <?php elseif ($tasa['fuente'] === 'BCH'): ?>Compra L <?= number_format((float)$tasa['compra'], 4) ?><br>Venta L <?= number_format((float)$tasa['venta'], 4) ?>
                <?php else: ?>L <?= number_format((float)$tasa['referencia'], 4) ?><?php endif; ?></div>
            <?php if ($tasa): ?><div class="small <?= $tasaHoy ? 'text-muted' : 'text-warning' ?>"><?= $tasaHoy ? 'Hoy' : 'Del ' . date('d/m/Y', strtotime($tasa['fecha'])) ?></div><?php endif; ?></div></div>
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
            <div class="app-card-header flex-wrap gap-2"><span><i class="bi bi-clock-history me-1"></i> Últimos movimientos</span><div class="app-toolbar flex-wrap"><input id="bmBuscar" type="search" class="form-control form-control-sm" style="max-width:260px" placeholder="Buscar movimiento…" aria-label="Buscar movimiento"><select id="bmPagina" class="form-select form-select-sm" style="width:auto" aria-label="Movimientos por página"><?php foreach ([10,25,50,100,200,300] as $n): ?><option value="<?= $n ?>"><?= $n ?>/pág</option><?php endforeach; ?><option value="100000">Todas</option></select><a href="cheques" class="small">Chequera →</a></div></div>
            <div class="app-card-body py-3"><div class="row g-2"><div class="col-12 col-md-8"><label class="form-label small" for="bmCuenta">Cuenta bancaria</label><select id="bmCuenta" class="form-select form-select-sm"><option value="">Todas las cuentas</option><?php foreach ($cuentas as $bc): ?><option value="<?= (int)$bc['id'] ?>"><?= htmlspecialchars($bc['banco'].' · '.$bc['numero'].' · '.$bc['moneda']) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-4"><label class="form-label small" for="bmTipoCuenta">Tipo de cuenta</label><select id="bmTipoCuenta" class="form-select form-select-sm"><option value="">Ahorro y cheques</option><option value="ahorro">Ahorro</option><option value="cheques">Cheques</option></select></div></div></div>
            <div class="table-responsive">
                <table id="bmTabla" data-ordenar class="table app-table">
                    <thead><tr><th class="app-n">#</th><th>Fecha</th><th>Cuenta</th><th>Tipo</th><th>Descripción</th><th class="app-num">Entrada</th><th class="app-num">Salida</th><th class="no-orden">Detalle</th></tr></thead>
                    <tbody>
                        <?php if (!$recientes): ?><tr><td colspan="8" class="text-center text-muted py-4">Sin movimientos.</td></tr><?php endif; ?>
                        <?php foreach ($recientes as $m): ?>
                            <tr data-fila data-cuenta="<?= (int)$m['cuenta_id'] ?>" data-tipo-cuenta="<?= htmlspecialchars($m['cuenta_tipo']) ?>" class="<?= (int)$m['anulado'] ? 'text-decoration-line-through text-muted' : '' ?>">
                                <td class="app-n"></td><td class="text-nowrap" data-sort="<?= htmlspecialchars($m['fecha']) ?>"><?= date('d/m/Y', strtotime($m['fecha'])) ?></td>
                                <td class="text-nowrap small"><?= htmlspecialchars($m['banco']) ?> <span class="font-monospace"><?= htmlspecialchars($m['numero']) ?></span></td>
                                <td><span class="app-badge app-badge-muted"><?= $etiquetasTipo[$m['tipo']] ?? $m['tipo'] ?></span></td>
                                <td><?= htmlspecialchars($m['descripcion']) ?><?= (int)$m['anulado'] ? ' <span class="app-badge app-badge-danger">Anulado</span>' : '' ?></td>
                                <td class="app-num text-success" data-sort="<?= $m['sentido'] === 'entrada' ? (float)$m['monto'] : 0 ?>"><?= $m['sentido'] === 'entrada' ? bancoMoneda((float)$m['monto'], $m['moneda']) : '' ?></td>
                                <td class="app-num text-danger" data-sort="<?= $m['sentido'] === 'salida' ? (float)$m['monto'] : 0 ?>"><?= $m['sentido'] === 'salida' ? bancoMoneda((float)$m['monto'], $m['moneda']) : '' ?></td>
                                <td><button type="button" class="btn btn-sm btn-outline-primary bm-ver" data-id="<?= (int)$m['id'] ?>">Ver</button>
                                <template id="bmDetalle<?= (int)$m['id'] ?>"><div class="text-start"><dl class="row small">
                                <?php foreach (['Fecha'=>date('d/m/Y',strtotime($m['fecha'])),'Cuenta'=>$m['banco'].' · '.$m['numero'],'Tipo'=>$etiquetasTipo[$m['tipo']]??$m['tipo'],'Descripción'=>$m['descripcion'],'Referencia'=>$m['referencia']?:'Sin referencia','Importe'=>($m['sentido']==='entrada'?'Entrada: ':'Salida: ').bancoMoneda((float)$m['monto'],$m['moneda']),'Estado'=>$m['anulado']?'Anulado':($m['conciliado']?'Conciliado':'Sin conciliar')] as $label=>$valor): ?><dt class="col-sm-4"><?= htmlspecialchars($label) ?></dt><dd class="col-sm-8" style="overflow-wrap:anywhere"><?= htmlspecialchars($valor) ?></dd><?php endforeach; ?></dl>
                                <div class="d-flex gap-2 flex-wrap"><a class="btn btn-sm btn-outline-primary" href="banco_cuenta?id=<?= (int)$m['cuenta_id'] ?>&desde=<?= urlencode($m['fecha']) ?>&hasta=<?= urlencode($m['fecha']) ?>&anulados=1">Ver en la cuenta</a>
                                <?php if ($m['gasto_id']): ?><a class="btn btn-sm btn-outline-primary" href="gasto_ver?id=<?= (int)$m['gasto_id'] ?>">Ver gasto</a><?php endif; ?>
                                <?php if ($m['factura_id']): ?><a class="btn btn-sm btn-outline-primary" href="ver_factura?id=<?= (int)$m['factura_id'] ?>">Ver factura</a><?php endif; ?>
                                <?php if (!empty($m['archivo_adjunto'])): ?><a class="btn btn-sm btn-outline-secondary" target="_blank" href="movimiento_archivo?id=<?= (int)$m['id'] ?>">Comprobante</a><?php endif; ?></div></div></template></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div><div class="app-pager" id="bmPie"></div>
        </div>
    <?php endif; ?>

    <?php if ($puedeMover): ?>
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
                        <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="ley_poblacional" value="1" id="cLeyPob" checked style="cursor:pointer">
                            <label class="form-check-label" for="cLeyPob">Cobra Ley Poblacional (L 2 por cada L 1,000 o fracción de cada débito)</label></div>
                            <div class="form-text">Encendido: cada salida de la cuenta registra sola ese cargo y lo suma al gasto «Ley Poblacional» del mes. Solo cuentas en lempiras.</div></div>
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
                        <div class="col-12"><label class="form-label">Comprobante <span class="text-muted small">(opcional: JPG, PNG, WEBP o PDF, máx. 5 MB)</span></label>
                            <input class="form-control" type="file" name="comprobante" accept=".jpg,.jpeg,.png,.webp,.pdf"></div>
                        <!-- Salidas: registrarlas también como gasto (con su categoría) para que cuenten en Gastos y en el Estado de resultados -->
                        <div class="col-12" id="movGastoWrap">
                            <div class="border rounded p-2" style="background:#f8fafc">
                                <div class="form-check"><input class="form-check-input" type="checkbox" name="como_gasto" value="1" id="movComoGasto">
                                    <label class="form-check-label" for="movComoGasto">Registrar también como gasto <span class="text-muted small">(queda pagado, con su categoría y comprobante)</span></label></div>
                                <div class="row g-2 mt-1 d-none" id="movGastoCampos">
                                    <div class="col-sm-6"><label class="form-label small mb-1">Categoría *</label>
                                        <select class="form-select form-select-sm" name="categoria_id" id="movCategoria"><option value="">— Selecciona —</option>
                                            <?php foreach ($catsGasto as $cg): ?><option value="<?= (int)$cg['id'] ?>"><?= htmlspecialchars($cg['nombre']) ?></option><?php endforeach; ?></select></div>
                                    <div class="col-sm-6"><label class="form-label small mb-1">Proveedor</label><input class="form-control form-control-sm" name="proveedor" maxlength="150" placeholder="Opcional"></div>
                                </div>
                            </div>
                        </div>
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
    document.getElementById('btnNuevaCuenta')?.addEventListener('click', () => {
        fCuenta.reset(); fCuenta.elements.id.value = '';
        document.getElementById('tituloCuenta').textContent = 'Nueva cuenta';
        B.modal('modalCuenta').show();
    });
    document.querySelectorAll('.btn-editar-cuenta').forEach(b => b.addEventListener('click', () => {
        const c = JSON.parse(b.dataset.cuenta);
        fCuenta.reset();
        ['id', 'banco', 'numero', 'tipo', 'titular', 'moneda', 'saldo_inicial', 'fecha_saldo_inicial', 'notas'].forEach(k => fCuenta.elements[k].value = c[k] ?? '');
        if (fCuenta.elements.ley_poblacional) fCuenta.elements.ley_poblacional.checked = c.ley_poblacional === undefined ? c.moneda === 'HNL' : +c.ley_poblacional === 1;
        document.getElementById('tituloCuenta').textContent = 'Editar cuenta';
        B.modal('modalCuenta').show();
    }));
    document.querySelectorAll('.btn-estado-cuenta').forEach(b => b.addEventListener('click', () => B.accion({ accion: 'cuenta_estado', id: b.dataset.id })));
    document.querySelectorAll('.btn-predeterminar').forEach(b => b.addEventListener('click', () => B.accion({ accion: 'predeterminar', id: b.dataset.id })));
    document.querySelectorAll('[data-abrir]').forEach(b => b.addEventListener('click', () => B.modal(b.dataset.abrir).show()));
    B.formulario(fCuenta);
    // Tasa del dólar en línea (BCH con clave; si no, referencia pública)
    document.getElementById('btnTasa')?.addEventListener('click', () => { Swal.fire({ title: 'Consultando la tasa…', allowOutsideClick: false, didOpen: () => Swal.showLoading() }); B.accion({ accion: 'tasa_actualizar' }).catch(() => {}); });
    document.getElementById('btnTasaClave')?.addEventListener('click', () => {
        Swal.fire({ title: 'Clave de la Web-API del BCH', html: '<div class="text-start small">Regístrate gratis en <a href="https://bchapi-am.developer.azure-api.net" target="_blank" rel="noopener">bchapi-am.developer.azure-api.net</a>, suscríbete a la API y pega aquí tu clave de suscripción. Con ella el sistema trae cada día la tasa de <strong>compra y venta</strong> del Banco Central. Sin clave usa una tasa de referencia del mercado.</div>',
            input: 'password', inputPlaceholder: 'Clave de suscripción (vacío = quitarla)', showCancelButton: true, confirmButtonText: 'Guardar', cancelButtonText: 'Cancelar' })
            .then(r => { if (r.isConfirmed) B.accion({ accion: 'tasa_clave', clave: r.value || '' }).catch(() => {}); });
    });
    B.formulario(document.getElementById('formMovimiento'));
    B.formulario(document.getElementById('formTransferencia'));

    // «Registrar también como gasto»: solo en salidas (retiro, comisión o ajuste que resta)
    const fMov = document.getElementById('formMovimiento'), chkGasto = document.getElementById('movComoGasto');
    function revisarGasto() {
        const tipo = document.getElementById('movTipo').value;
        const salida = tipo === 'retiro' || tipo === 'comision' || (tipo === 'ajuste' && fMov.elements.sentido.value === 'salida');
        document.getElementById('movGastoWrap').classList.toggle('d-none', !salida);
        if (!salida) chkGasto.checked = false;
        document.getElementById('movGastoCampos').classList.toggle('d-none', !chkGasto.checked);
        document.getElementById('movCategoria').required = chkGasto.checked;
        // Comisión bancaria: sugiere una categoría de comisiones/bancos si existe
        const cat = document.getElementById('movCategoria');
        if (chkGasto.checked && tipo === 'comision' && !cat.value) {
            const o = [...cat.options].find(o => /comisi|banc/i.test(o.text));
            if (o) cat.value = o.value;
        }
    }
    document.getElementById('movTipo').addEventListener('change', function () {
        document.getElementById('movSentidoWrap').classList.toggle('d-none', this.value !== 'ajuste');
        revisarGasto();
    });
    fMov.elements.sentido.addEventListener('change', revisarGasto);
    chkGasto.addEventListener('change', revisarGasto);
    revisarGasto();
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

<script>
document.addEventListener('DOMContentLoaded', () => {
    if (!document.getElementById('bmTabla')) return;
    const cuenta=document.getElementById('bmCuenta'), tipo=document.getElementById('bmTipoCuenta');
    const tabla=AppTabla(document.getElementById('bmTabla'), {buscar:'#bmBuscar',porPagina:'#bmPagina',pie:'#bmPie',vacio:'Sin movimientos con estos filtros.',filtro:tr=>(!cuenta.value||tr.dataset.cuenta===cuenta.value)&&(!tipo.value||tr.dataset.tipoCuenta===tipo.value)});
    cuenta.addEventListener('change',()=>tabla.refrescar()); tipo.addEventListener('change',()=>tabla.refrescar());
});
document.addEventListener('click', e => { const b=e.target.closest('.bm-ver'); if (!b) return; const t=document.getElementById('bmDetalle'+b.dataset.id); if(t) Swal.fire({title:'Movimiento #'+b.dataset.id,html:t.innerHTML,width:640,confirmButtonText:'Cerrar'}); });
</script>
<?php require_once '../../includes/templates/footer.php'; ?>
