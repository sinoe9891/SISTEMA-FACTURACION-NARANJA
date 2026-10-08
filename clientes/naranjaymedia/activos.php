<?php
// Activos y préstamos: activos fijos (depreciación en línea recta) y préstamos recibidos (deuda en el Balance).
$titulo = 'Activos y préstamos';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/activos.php';
require_once '../../includes/bancos.php';

$cid = (int)cliente_actual();
$instalado = activosDisponible($pdo);
$puedeEditar = in_array(USUARIO_ROL, ['admin', 'superadmin'], true);
$hoy = date('Y-m-d');
$activos = $instalado ? activosLista($pdo, $cid) : [];
$prestamos = $instalado ? prestamosRecibidos($pdo, $cid) : [];
$saldosPrest = [];
foreach ($instalado ? prestamosBalance($pdo, $cid, '9999-12-31') : [] as $p) $saldosPrest[$p['id']] = $p;
$cuentasHnl = bancosDisponible($pdo) ? array_values(array_filter(bancoCuentas($pdo, $cid, true), fn($c) => $c['moneda'] === 'HNL')) : [];
$stCat = $pdo->prepare("SELECT id, nombre FROM categorias_gastos WHERE cliente_id = ? ORDER BY nombre");
$stCat->execute([$cid]);
$categorias = $stCat->fetchAll(PDO::FETCH_ASSOC);
// Series de gastos (p. ej. cuotas mensuales ya registradas) que se pueden ligar a un préstamo
$series = [];
if ($instalado) {
    $st = $pdo->prepare("SELECT gasto_grupo_id g, MIN(descripcion) d, COUNT(*) n, SUM(monto) t, MIN(fecha) desde, MAX(fecha) hasta FROM gastos
                         WHERE cliente_id = ? AND gasto_grupo_id IS NOT NULL AND estado <> 'anulado' AND naturaleza = 'gasto' AND descripcion NOT LIKE 'Sueldo %'
                         GROUP BY gasto_grupo_id HAVING n > 1 ORDER BY MAX(fecha) DESC LIMIT 60");
    $st->execute([$cid]);
    $series = $st->fetchAll(PDO::FETCH_ASSOC);
}
$L = fn($v) => 'L ' . number_format((float)$v, 2);
$totCosto = array_sum(array_map(fn($a) => empty($a['fecha_baja']) ? (float)$a['costo'] : 0, $activos));
$depOn = $instalado && depreciacionActiva($pdo, $cid);
$totAcum = $depOn ? array_sum(array_map(fn($a) => empty($a['fecha_baja']) ? activoAcumulada($a, $hoy) : 0, $activos)) : 0;
$totDeuda = array_sum(array_column($saldosPrest, 'saldo'));
$depMes = activosDepreciacionPeriodo($pdo, $cid, date('Y-m-01'), date('Y-m-t'));
$antProv = $instalado ? anticiposProveedorSaldo($pdo, $cid, $hoy) : 0;

require_once '../../includes/templates/header.php';
?>

<div class="app-page-header">
    <div>
        <h1 class="app-page-title">Activos y préstamos</h1>
        <p class="app-page-sub">Equipo, mobiliario y otros activos (con su depreciación) y los préstamos que recibe la empresa. Ambos aparecen en el Balance general.</p>
    </div>
    <?php if ($instalado && $puedeEditar): ?>
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-outline-primary" id="btnNuevoPrestamo"><i class="bi bi-cash-stack me-1"></i> Préstamo recibido</button>
            <button class="btn btn-primary" id="btnNuevoActivo"><i class="bi bi-pc-display me-1"></i> Nuevo activo</button>
        </div>
    <?php endif; ?>
</div>

<?php if (!$instalado): ?>
    <div class="alert alert-warning">Falta instalar <code>sql/migraciones/2026-10-08_activos_prestamos.sql</code>.</div>
<?php else: ?>
    <div class="app-card mb-3"><div class="app-card-body d-flex flex-wrap align-items-center gap-3 py-2">
        <div class="form-check form-switch m-0">
            <input class="form-check-input" type="checkbox" role="switch" id="swDep" <?= $depOn ? 'checked' : '' ?> <?= $puedeEditar ? '' : 'disabled' ?>>
            <label class="form-check-label fw-semibold" for="swDep">Calcular depreciación</label>
        </div>
        <span class="small text-muted"><?= $depOn
            ? 'Se resta cada mes en el Estado de resultados y baja el valor de los activos en el Balance.'
            : 'Apagada: no se resta en los resultados y los activos se muestran a su costo. Al encenderla se recalcula todo, también los meses anteriores.' ?></span>
    </div></div>
    <div class="app-stats">
        <div class="app-stat"><div class="app-stat-icon"><i class="bi bi-pc-display"></i></div>
            <div><div class="app-stat-val"><?= $L($totCosto - $totAcum) ?></div><div class="app-stat-lbl">Valor en libros de los activos</div></div></div>
        <div class="app-stat"><div class="app-stat-icon gray"><i class="bi bi-graph-down"></i></div>
            <div><div class="app-stat-val"><?= $L($depMes) ?></div><div class="app-stat-lbl">Depreciación de este mes (gasto)</div></div></div>
        <div class="app-stat"><div class="app-stat-icon <?= $totDeuda > 0 ? 'amber' : 'gray' ?>"><i class="bi bi-bank"></i></div>
            <div><div class="app-stat-val"><?= $L($totDeuda) ?></div><div class="app-stat-lbl">Deuda de préstamos</div></div></div>
        <div class="app-stat"><div class="app-stat-icon teal"><i class="bi bi-arrow-up-right-circle"></i></div>
            <div><div class="app-stat-val"><?= $L($antProv) ?></div><div class="app-stat-lbl">Anticipos a proveedores (a favor)</div></div></div>
    </div>

    <div class="app-card mb-3">
        <div class="app-card-header"><span><i class="bi bi-pc-display me-1"></i> Activos fijos</span><small class="text-muted fw-normal">Depreciación en línea recta, desde el mes siguiente a la compra</small></div>
        <div class="table-responsive">
            <table class="table app-table">
                <thead><tr><th>Activo</th><th>Compra</th><th class="app-num">Costo</th><th class="app-num">Vida útil</th><th class="app-num">Depreciación mensual</th><th class="app-num">Acumulada</th><th class="app-num">Valor en libros</th><th>Estado</th><?php if ($puedeEditar): ?><th></th><?php endif; ?></tr></thead>
                <tbody>
                    <?php if (!$activos): ?><tr><td colspan="9" class="text-center text-muted py-4">Aún no hay activos. Registra el equipo de cómputo, mobiliario o vehículos de la empresa.</td></tr><?php endif; ?>
                    <?php foreach ($activos as $a): $acum = activoAcumulada($a, $hoy); [$ini, $fin] = activoRango($a); $mesFin = sprintf('%04d-%02d', intdiv($fin, 12), $fin % 12 + 1); ?>
                        <tr class="<?= $a['fecha_baja'] ? 'text-muted' : '' ?>">
                            <td><strong><?= htmlspecialchars($a['nombre']) ?></strong><div class="small text-muted"><?= ACTIVO_CATEGORIAS[$a['categoria']][0] ?? $a['categoria'] ?> · <?= ['compra' => 'Compra', 'donacion' => 'Donación', 'prestamo' => 'Financiado con préstamo', 'mixto' => 'Donación + préstamo'][$a['origen']] ?><?= $a['proveedor'] ? ' · ' . htmlspecialchars($a['proveedor']) : '' ?></div></td>
                            <td class="text-nowrap"><?= date('d/m/Y', strtotime($a['fecha_compra'])) ?></td>
                            <td class="app-num"><?= number_format((float)$a['costo'], 2) ?></td>
                            <td class="app-num text-nowrap"><?= (int)$a['vida_util_meses'] ?> meses<div class="small text-muted">hasta <?= $mesFin ?></div></td>
                            <td class="app-num"><?= number_format(activoCuotaMensual($a), 2) ?></td>
                            <td class="app-num"><?= number_format($acum, 2) ?></td>
                            <td class="app-num fw-semibold"><?= number_format((float)$a['costo'] - $acum, 2) ?></td>
                            <td><?= $a['fecha_baja'] ? '<span class="app-badge app-badge-muted">Baja ' . date('d/m/Y', strtotime($a['fecha_baja'])) . '</span>' : ($acum >= (float)$a['costo'] - (float)$a['valor_residual'] - 0.004 ? '<span class="app-badge app-badge-muted">Depreciado</span>' : '<span class="app-badge app-badge-success">En uso</span>') ?></td>
                            <?php if ($puedeEditar): ?><td class="text-end text-nowrap">
                                <button class="btn btn-sm btn-link p-0 me-2 btn-editar-activo" data-activo='<?= json_encode($a, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>'>Editar</button>
                                <?php if (!$a['fecha_baja']): ?><button class="btn btn-sm btn-link p-0 me-2 btn-baja-activo" data-id="<?= (int)$a['id'] ?>">Dar de baja</button><?php endif; ?>
                                <button class="btn btn-sm btn-link p-0 text-danger btn-eliminar-activo" data-id="<?= (int)$a['id'] ?>" title="Eliminar"><i class="bi bi-trash"></i></button>
                            </td><?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="app-card">
        <div class="app-card-header"><span><i class="bi bi-cash-stack me-1"></i> Préstamos recibidos</span><small class="text-muted fw-normal">Las cuotas se pagan desde Cuentas por pagar; el capital baja la deuda y solo los intereses son gasto</small></div>
        <div class="table-responsive">
            <table class="table app-table">
                <thead><tr><th>Acreedor</th><th>Fecha</th><th class="app-num">Monto</th><th class="app-num">Cuotas</th><th class="app-num">Tasa anual</th><th class="app-num">Capital pagado</th><th class="app-num">Saldo</th><?php if ($puedeEditar): ?><th></th><?php endif; ?></tr></thead>
                <tbody>
                    <?php if (!$prestamos): ?><tr><td colspan="8" class="text-center text-muted py-4">No hay préstamos registrados.</td></tr><?php endif; ?>
                    <?php foreach ($prestamos as $p): $sp = $saldosPrest[(int)$p['id']] ?? ['pagado' => 0, 'saldo' => (float)$p['monto']]; ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($p['acreedor']) ?></strong><?= $p['descripcion'] ? '<div class="small text-muted">' . htmlspecialchars($p['descripcion']) . '</div>' : '' ?></td>
                            <td class="text-nowrap"><?= date('d/m/Y', strtotime($p['fecha'])) ?></td>
                            <td class="app-num"><?= number_format((float)$p['monto'], 2) ?></td>
                            <td class="app-num"><?= (int)$p['num_cuotas'] ?></td>
                            <td class="app-num"><?= (float)$p['tasa_anual'] > 0 ? rtrim(rtrim(number_format((float)$p['tasa_anual'], 2), '0'), '.') . ' %' : 'Sin intereses' ?></td>
                            <td class="app-num text-success"><?= number_format($sp['pagado'], 2) ?></td>
                            <td class="app-num fw-semibold <?= $sp['saldo'] > 0 ? 'text-danger' : 'text-success' ?>"><?= number_format($sp['saldo'], 2) ?></td>
                            <?php if ($puedeEditar): ?><td class="text-end"><button class="btn btn-sm btn-link p-0 text-danger btn-anular-prestamo" data-id="<?= (int)$p['id'] ?>" title="Anular"><i class="bi bi-x-circle"></i></button></td><?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($puedeEditar): ?>
    <div class="modal fade" id="modalActivo" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-fullscreen-sm-down">
            <form class="modal-content" id="formActivo" novalidate>
                <div class="modal-header"><h5 class="modal-title" id="tituloActivo">Nuevo activo</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
                <div class="modal-body row g-3">
                    <input type="hidden" name="accion" value="activo_guardar"><input type="hidden" name="id">
                    <div class="col-md-8"><label class="form-label">Nombre *</label><input class="form-control" name="nombre" maxlength="200" required placeholder="Ej.: Laptops y monitores del equipo"></div>
                    <div class="col-md-4"><label class="form-label">Tipo</label><select class="form-select" name="categoria" id="actCategoria">
                        <?php foreach (ACTIVO_CATEGORIAS as $k => [$t, $m]): ?><option value="<?= $k ?>" data-vida="<?= $m ?>"><?= $t ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-4"><label class="form-label">Fecha de compra *</label><input class="form-control" type="date" name="fecha_compra" required value="<?= $hoy ?>"></div>
                    <div class="col-md-4"><label class="form-label">Costo (L) *</label><input class="form-control" type="number" step="0.01" min="0.01" name="costo" required></div>
                    <div class="col-md-4"><label class="form-label">Valor residual (L)</label><input class="form-control" type="number" step="0.01" min="0" name="valor_residual" value="0"><div class="form-text">Lo que valdrá al final (normalmente 0).</div></div>
                    <div class="col-md-4"><label class="form-label">Vida útil (meses) *</label><input class="form-control" type="number" min="1" max="600" name="vida_util_meses" id="actVida" required value="36"><div class="form-text" id="actCuota"></div></div>
                    <div class="col-md-4"><label class="form-label">Origen</label><select class="form-select" name="origen"><option value="compra">Compra</option><option value="donacion">Donación</option><option value="prestamo">Financiado con préstamo</option><option value="mixto">Donación + préstamo</option></select></div>
                    <div class="col-md-4"><label class="form-label">Proveedor</label><input class="form-control" name="proveedor" maxlength="150"></div>
                    <div class="col-12"><label class="form-label">Notas</label><input class="form-control" name="notas"></div>
                    <div class="col-12" id="actCompraWrap">
                        <div class="border rounded p-2" style="background:#f8fafc">
                            <div class="form-check"><input class="form-check-input" type="checkbox" name="registrar_compra" value="1" id="actRegistrarCompra">
                                <label class="form-check-label" for="actRegistrarCompra">Registrar el pago de la compra en Gastos <span class="text-muted small">(queda como «Compra de activo»: no resta en resultados, se reconoce con la depreciación)</span></label></div>
                            <div class="row g-2 mt-1 d-none" id="actCompraCampos">
                                <div class="col-sm-6"><label class="form-label small mb-1">Categoría</label><select class="form-select form-select-sm" name="categoria_id"><option value="">—</option><?php foreach ($categorias as $cg): ?><option value="<?= (int)$cg['id'] ?>"><?= htmlspecialchars($cg['nombre']) ?></option><?php endforeach; ?></select></div>
                                <div class="col-sm-6"><label class="form-label small mb-1">Sale de la cuenta</label><select class="form-select form-select-sm" name="cuenta_id"><option value="">— No registrar en banco —</option><?php foreach ($cuentasHnl as $cb): ?><option value="<?= (int)$cb['id'] ?>"<?= bancoSel($cb) ?>><?= htmlspecialchars($cb['banco'] . ' ' . $cb['numero']) ?></option><?php endforeach; ?></select></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Guardar</button></div>
            </form>
        </div>
    </div>

    <div class="modal fade" id="modalPrestamo" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-fullscreen-sm-down">
            <form class="modal-content" id="formPrestamo" novalidate>
                <div class="modal-header"><h5 class="modal-title">Préstamo recibido</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
                <div class="modal-body row g-3">
                    <input type="hidden" name="accion" value="prestamo_guardar">
                    <div class="col-md-6"><label class="form-label">Acreedor *</label><input class="form-control" name="acreedor" maxlength="150" required placeholder="Banco, fundación, socio…"></div>
                    <div class="col-md-6"><label class="form-label">Descripción</label><input class="form-control" name="descripcion" maxlength="255"></div>
                    <div class="col-md-4"><label class="form-label">Fecha *</label><input class="form-control" type="date" name="fecha" required value="<?= $hoy ?>"></div>
                    <div class="col-md-4"><label class="form-label">Monto a devolver (L) *</label><input class="form-control" type="number" step="0.01" min="0.01" name="monto" required></div>
                    <div class="col-md-2"><label class="form-label">Cuotas</label><input class="form-control" type="number" min="1" max="360" name="num_cuotas" value="12"></div>
                    <div class="col-md-2"><label class="form-label">Tasa anual %</label><input class="form-control" type="number" step="0.01" min="0" name="tasa_anual" value="0"></div>
                    <div class="col-md-6"><label class="form-label">Entró a la cuenta</label><select class="form-select" name="cuenta_id"><option value="">— No entró dinero (en especie) —</option><?php foreach ($cuentasHnl as $cb): ?><option value="<?= (int)$cb['id'] ?>"><?= htmlspecialchars($cb['banco'] . ' ' . $cb['numero']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-6"><label class="form-label">Activo que financió</label><select class="form-select" name="activo_id"><option value="">—</option><?php foreach ($activos as $a): ?><option value="<?= (int)$a['id'] ?>"><?= htmlspecialchars($a['nombre']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-12">
                        <label class="form-label">Cuotas</label>
                        <div class="form-check"><input class="form-check-input" type="radio" name="modo_cuotas" value="serie" id="pmSerie" <?= $series ? 'checked' : 'disabled' ?>>
                            <label class="form-check-label" for="pmSerie">Ya están registradas en Gastos (pasan a ser abonos a capital)</label></div>
                        <select class="form-select form-select-sm mt-1 mb-2" name="gasto_grupo_id" id="pmSerieSel">
                            <?php foreach ($series as $se): ?><option value="<?= (int)$se['g'] ?>"><?= htmlspecialchars(mb_strimwidth($se['d'], 0, 60, '…')) ?> · <?= (int)$se['n'] ?> cuotas · L <?= number_format((float)$se['t'], 2) ?> (<?= date('m/Y', strtotime($se['desde'])) ?>–<?= date('m/Y', strtotime($se['hasta'])) ?>)</option><?php endforeach; ?></select>
                        <div class="form-check"><input class="form-check-input" type="radio" name="modo_cuotas" value="generar" id="pmGenerar" <?= $series ? '' : 'checked' ?>>
                            <label class="form-check-label" for="pmGenerar">Programar las cuotas en Cuentas por pagar</label></div>
                        <div class="row g-2 mt-1" id="pmGenerarCampos">
                            <div class="col-sm-6"><label class="form-label small mb-1">Primera cuota</label><input class="form-control form-control-sm" type="date" name="fecha_primera_cuota" value="<?= date('Y-m-d', strtotime('first day of next month')) ?>"></div>
                            <div class="col-sm-6"><label class="form-label small mb-1">Categoría</label><select class="form-select form-select-sm" name="categoria_id"><option value="">—</option><?php foreach ($categorias as $cg): ?><option value="<?= (int)$cg['id'] ?>"><?= htmlspecialchars($cg['nombre']) ?></option><?php endforeach; ?></select></div>
                        </div>
                    </div>
                    <input type="hidden" name="generar_cuotas" id="pmGenerarFlag">
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Registrar préstamo</button></div>
            </form>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<script>
(function () {
    const URL_A = 'includes/activo_accion.php';
    const enviar = fd => fetch(URL_A, { method: 'POST', body: fd }).then(r => r.json()).then(d => { if (!d.success) throw new Error(d.error || 'No se pudo guardar.'); return d; });
    const listo = d => Swal.fire({ icon: 'success', title: d.message }).then(() => location.reload());
    const error = e => Swal.fire('No se pudo', e.message, 'error');
    // Interruptor de la depreciación (se recalcula todo al encenderla)
    document.getElementById('swDep')?.addEventListener('change', e => {
        const fd = new FormData(); fd.append('accion', 'depreciacion'); fd.append('activa', e.target.checked ? '1' : '');
        enviar(fd).then(listo).catch(err => { e.target.checked = !e.target.checked; error(err); });
    });
    const fA = document.getElementById('formActivo'), fP = document.getElementById('formPrestamo');
    if (!fA) return;
    const mA = new bootstrap.Modal(document.getElementById('modalActivo')), mP = new bootstrap.Modal(document.getElementById('modalPrestamo'));
    const cuota = () => {
        const c = parseFloat(fA.elements.costo.value) || 0, r = parseFloat(fA.elements.valor_residual.value) || 0, v = parseInt(fA.elements.vida_util_meses.value, 10) || 0;
        document.getElementById('actCuota').textContent = c > 0 && v > 0 ? 'Depreciación: L ' + ((c - r) / v).toLocaleString('es-HN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' al mes' : '';
    };
    ['costo', 'valor_residual', 'vida_util_meses'].forEach(n => fA.elements[n].addEventListener('input', cuota));
    document.getElementById('actCategoria').addEventListener('change', e => { if (!fA.elements.id.value) { fA.elements.vida_util_meses.value = e.target.selectedOptions[0].dataset.vida; cuota(); } });
    document.getElementById('actRegistrarCompra').addEventListener('change', e => document.getElementById('actCompraCampos').classList.toggle('d-none', !e.target.checked));
    document.getElementById('btnNuevoActivo').addEventListener('click', () => {
        fA.reset(); fA.elements.id.value = ''; document.getElementById('tituloActivo').textContent = 'Nuevo activo';
        document.getElementById('actCompraWrap').classList.remove('d-none'); document.getElementById('actCompraCampos').classList.add('d-none'); cuota(); mA.show();
    });
    document.querySelectorAll('.btn-editar-activo').forEach(b => b.addEventListener('click', () => {
        const a = JSON.parse(b.dataset.activo);
        fA.reset();
        ['id', 'nombre', 'categoria', 'fecha_compra', 'costo', 'valor_residual', 'vida_util_meses', 'origen', 'proveedor', 'notas'].forEach(k => fA.elements[k].value = a[k] ?? '');
        document.getElementById('tituloActivo').textContent = 'Editar activo';
        document.getElementById('actCompraWrap').classList.add('d-none'); cuota(); mA.show();
    }));
    fA.addEventListener('submit', e => {
        e.preventDefault();
        if (!fA.checkValidity()) { fA.classList.add('was-validated'); return; }
        enviar(new FormData(fA)).then(d => { mA.hide(); listo(d); }).catch(error);
    });
    document.querySelectorAll('.btn-baja-activo').forEach(b => b.addEventListener('click', () => {
        Swal.fire({ title: 'Dar de baja el activo', html: '<input type="date" id="fBaja" class="form-control" value="' + new Date().toLocaleDateString('sv-SE') + '"><div class="small text-muted mt-2">Vendido, dañado o fuera de uso: deja de depreciarse y sale del Balance.</div>',
            showCancelButton: true, confirmButtonText: 'Dar de baja', cancelButtonText: 'Cancelar', preConfirm: () => document.getElementById('fBaja').value })
            .then(r => { if (!r.isConfirmed) return; const fd = new FormData(); fd.append('accion', 'activo_baja'); fd.append('id', b.dataset.id); fd.append('fecha_baja', r.value); enviar(fd).then(listo).catch(error); });
    }));
    document.querySelectorAll('.btn-eliminar-activo').forEach(b => b.addEventListener('click', () => {
        Swal.fire({ title: '¿Eliminar el activo?', text: 'Solo si se registró por error.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Eliminar', confirmButtonColor: '#dc2626', cancelButtonText: 'Cancelar' })
            .then(r => { if (!r.isConfirmed) return; const fd = new FormData(); fd.append('accion', 'activo_eliminar'); fd.append('id', b.dataset.id); enviar(fd).then(listo).catch(error); });
    }));
    // Préstamos
    const modo = () => {
        const serie = document.getElementById('pmSerie').checked;
        document.getElementById('pmSerieSel').disabled = !serie;
        document.getElementById('pmGenerarCampos').classList.toggle('d-none', serie);
        document.getElementById('pmGenerarFlag').value = serie ? '' : '1';
    };
    fP.querySelectorAll('[name=modo_cuotas]').forEach(r => r.addEventListener('change', modo));
    document.getElementById('btnNuevoPrestamo').addEventListener('click', () => { fP.reset(); modo(); mP.show(); });
    fP.addEventListener('submit', e => {
        e.preventDefault();
        if (!fP.checkValidity()) { fP.classList.add('was-validated'); return; }
        modo();
        const fd = new FormData(fP);
        if (document.getElementById('pmGenerar').checked) fd.delete('gasto_grupo_id');
        enviar(fd).then(d => { mP.hide(); listo(d); }).catch(error);
    });
    document.querySelectorAll('.btn-anular-prestamo').forEach(b => b.addEventListener('click', () => {
        Swal.fire({ title: '¿Anular el préstamo?', text: 'Sus cuotas ya pagadas vuelven a contar como gastos normales y las pendientes programadas se anulan.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Anular', confirmButtonColor: '#dc2626', cancelButtonText: 'Cancelar' })
            .then(r => { if (!r.isConfirmed) return; const fd = new FormData(); fd.append('accion', 'prestamo_anular'); fd.append('id', b.dataset.id); enviar(fd).then(listo).catch(error); });
    }));
})();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
