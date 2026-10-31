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
$puedeEditar = in_array(USUARIO_ROL, ['admin', 'superadmin'], true);   // anular movimientos
$puedeConciliar = $puedeEditar || USUARIO_ROL === 'nomina';
$mon = $cuenta['moneda'];

$valida = fn($f) => ($d = DateTime::createFromFormat('Y-m-d', (string)$f)) && $d->format('Y-m-d') === $f;
$desde = $valida($_GET['desde'] ?? '') ? $_GET['desde'] : date('Y-m-01');
$hasta = $valida($_GET['hasta'] ?? '') ? $_GET['hasta'] : date('Y-m-t');
if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];
$verAnulados = !empty($_GET['anulados']);
$fSentido = in_array($_GET['sentido'] ?? '', ['entrada', 'salida'], true) ? $_GET['sentido'] : '';
$fTipo = (string)($_GET['tipo'] ?? '');
$fConcil = in_array($_GET['conciliado'] ?? '', ['si', 'no'], true) ? $_GET['conciliado'] : '';
$fComp = in_array($_GET['comprobante'] ?? '', ['con', 'sin'], true) ? $_GET['comprobante'] : '';

// Saldo al inicio del período (saldo inicial + movimientos anteriores)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN sentido = 'entrada' THEN monto ELSE -monto END), 0)
    FROM movimientos_bancarios WHERE cuenta_id = ? AND anulado = 0 AND fecha < ?");
$stmt->execute([$cuenta['id'], $desde]);
$saldoAnterior = round((float)$cuenta['saldo_inicial'] + (float)$stmt->fetchColumn(), 2);

// El pago de un gasto muestra el comprobante del gasto si el movimiento no tiene uno propio
$stmt = $pdo->prepare("SELECT m.*, IF(m.tipo = 'comision', NULL, g.archivo_adjunto) AS gasto_archivo FROM movimientos_bancarios m LEFT JOIN gastos g ON g.id = m.gasto_id
                       WHERE m.cuenta_id = ? AND m.fecha BETWEEN ? AND ?" . ($verAnulados ? "" : " AND m.anulado = 0") . " ORDER BY m.fecha, m.id");
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
// Más reciente primero (el saldo de cada fila se calculó en orden de fecha) y filtros de la vista
$movs = array_reverse($movs);
$tiposEnPeriodo = array_values(array_unique(array_column($movs, 'tipo')));
$movs = array_values(array_filter($movs, fn($m) => (!$fSentido || $m['sentido'] === $fSentido) && (!$fTipo || $m['tipo'] === $fTipo)
    && (!$fConcil || ($fConcil === 'si') === (bool)(int)$m['conciliado']) && (!$fComp || ($fComp === 'con') === (!empty($m['archivo_adjunto']) || !empty($m['gasto_archivo'])))));
$hoyD = new DateTime('today');
$rangos = [
    'Este mes' => [$hoyD->format('Y-m-01'), $hoyD->format('Y-m-t')],
    'Mes anterior' => [(new DateTime('first day of last month'))->format('Y-m-d'), (new DateTime('last day of last month'))->format('Y-m-d')],
    'Últimos 3 meses' => [date('Y-m-01', strtotime('-2 months')), $hoyD->format('Y-m-t')],
    'Este año' => [$hoyD->format('Y-01-01'), $hoyD->format('Y-12-31')],
];
$saldoActual = bancoSaldo($pdo, (int)$cuenta['id']);
$etiquetasTipo = ['deposito' => 'Depósito', 'retiro' => 'Retiro', 'transferencia' => 'Transferencia', 'cheque' => 'Cheque',
    'pago_gasto' => 'Pago de gasto', 'cobro_factura' => 'Cobro de factura', 'comision' => 'Comisión bancaria', 'interes' => 'Interés ganado', 'ajuste' => 'Ajuste',
    'prestamo_colaborador' => 'Préstamo a colaborador', 'prestamo_recibido' => 'Préstamo recibido', 'prestamo_socio' => 'Préstamo a socio'];

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

<form id="bcFiltros" class="app-card app-card-body mb-3" method="GET">
    <input type="hidden" name="id" value="<?= (int)$cuenta['id'] ?>">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2"><label class="form-label small">Desde</label><input type="date" class="form-control form-control-sm" name="desde" value="<?= $desde ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small">Hasta</label><input type="date" class="form-control form-control-sm" name="hasta" value="<?= $hasta ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small">Entrada / salida</label><select class="form-select form-select-sm" name="sentido"><option value="">Todas</option>
            <option value="entrada" <?= $fSentido === 'entrada' ? 'selected' : '' ?>>Entradas</option><option value="salida" <?= $fSentido === 'salida' ? 'selected' : '' ?>>Salidas</option></select></div>
        <div class="col-6 col-md-2"><label class="form-label small">Tipo</label><select class="form-select form-select-sm" name="tipo"><option value="">Todos</option>
            <?php foreach ($etiquetasTipo as $k => $t): if (!in_array($k, $tiposEnPeriodo, true) && $fTipo !== $k) continue; ?><option value="<?= $k ?>" <?= $fTipo === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small">Conciliado</label><select class="form-select form-select-sm" name="conciliado"><option value="">Todos</option>
            <option value="si" <?= $fConcil === 'si' ? 'selected' : '' ?>>Conciliados</option><option value="no" <?= $fConcil === 'no' ? 'selected' : '' ?>>Sin conciliar</option></select></div>
        <div class="col-6 col-md-2"><label class="form-label small">Comprobante</label><select class="form-select form-select-sm" name="comprobante"><option value="">Todos</option>
            <option value="con" <?= $fComp === 'con' ? 'selected' : '' ?>>Con comprobante</option><option value="sin" <?= $fComp === 'sin' ? 'selected' : '' ?>>Sin comprobante</option></select></div>
        <div class="col-12 d-flex flex-wrap gap-1 align-items-center">
            <?php foreach ($rangos as $txt => [$d, $h]): ?><a class="btn btn-sm <?= $d === $desde && $h === $hasta ? 'btn-secondary' : 'btn-outline-secondary' ?> bc-rango" href="?id=<?= (int)$cuenta['id'] ?>&desde=<?= $d ?>&hasta=<?= $h ?>" data-desde="<?= $d ?>" data-hasta="<?= $h ?>"><?= $txt ?></a><?php endforeach; ?>
            <div class="form-check form-switch ms-md-3"><input class="form-check-input" type="checkbox" role="switch" name="anulados" value="1" id="verAnul" <?= $verAnulados ? 'checked' : '' ?> style="cursor:pointer"><label class="form-check-label small" for="verAnul">Ver anulados</label></div>
        </div>
    </div>
</form>

<div id="bcZona">
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="app-card app-kpi"><div class="app-kpi-label">Saldo al <?= date('d/m/Y', strtotime($desde)) ?></div><div class="app-kpi-value fs-5"><?= bancoMoneda($saldoAnterior, $mon) ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="app-card app-kpi"><div class="app-kpi-label">Entradas</div><div class="app-kpi-value fs-5 text-success"><?= bancoMoneda($entradas, $mon) ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="app-card app-kpi"><div class="app-kpi-label">Salidas</div><div class="app-kpi-value fs-5 text-danger"><?= bancoMoneda($salidas, $mon) ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="app-card app-kpi"><div class="app-kpi-label">Saldo al <?= date('d/m/Y', strtotime($hasta)) ?></div><div class="app-kpi-value fs-5"><?= bancoMoneda($saldo, $mon) ?></div></div></div>
</div>

<div class="app-card">
    <div class="app-card-header flex-wrap gap-2">
        <span><i class="bi bi-list-ul me-1"></i> Movimientos del <?= date('d/m/Y', strtotime($desde)) ?> al <?= date('d/m/Y', strtotime($hasta)) ?> <small class="text-muted fw-normal">· el más reciente primero</small></span>
        <div class="app-toolbar flex-grow-1 justify-content-end">
            <div class="app-search" style="max-width:300px"><i class="bi bi-search"></i><input type="search" class="form-control form-control-sm" id="bcBuscar" placeholder="Buscar descripción, referencia, monto…"></div>
            <select class="form-select form-select-sm" id="bcPorPagina" style="width:auto"><option value="25">25/pág</option><option value="50">50/pág</option><option value="100">100/pág</option><option value="100000">Todas</option></select>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table app-table" id="tMovs" data-ordenar>
            <thead><tr><th class="app-n">#</th><th>Fecha</th><th>Tipo</th><th>Descripción</th><th>Ref.</th><th class="app-num">Entrada</th><th class="app-num">Salida</th><th class="app-num">Saldo</th><th class="text-center no-orden" title="Conciliado con el estado de cuenta">Concil.</th><?php if ($puedeEditar): ?><th class="no-orden"></th><?php endif; ?></tr></thead>
            <tbody>
                <?php foreach ($movs as $m): $anul = (int)$m['anulado']; ?>
                    <tr data-fila class="<?= $anul ? 'text-muted text-decoration-line-through' : '' ?>" data-buscar="<?= htmlspecialchars(mb_strtolower(($etiquetasTipo[$m['tipo']] ?? $m['tipo']) . ' ' . $m['descripcion'] . ' ' . $m['referencia'] . ' ' . number_format((float)$m['monto'], 2) . ' ' . $m['monto'] . ' ' . date('d/m/Y', strtotime($m['fecha'])))) ?>">
                        <td class="app-n"></td>
                        <td class="text-nowrap" data-sort="<?= $m['fecha'] ?> <?= str_pad((string)$m['id'], 9, '0', STR_PAD_LEFT) ?>"><?= date('d/m/Y', strtotime($m['fecha'])) ?></td>
                        <td><span class="app-badge app-badge-muted"><?= $etiquetasTipo[$m['tipo']] ?? $m['tipo'] ?></span></td>
                        <td><?= htmlspecialchars($m['descripcion']) ?>
                            <?php if (!empty($m['archivo_adjunto'])): ?> <a href="movimiento_archivo?id=<?= (int)$m['id'] ?>" target="_blank" class="text-secondary" title="Ver comprobante"><i class="bi bi-paperclip"></i></a>
                            <?php elseif (!empty($m['gasto_archivo'])): ?> <a href="gasto_archivo?id=<?= (int)$m['gasto_id'] ?>" target="_blank" class="text-secondary" title="Ver comprobante del gasto"><i class="bi bi-paperclip"></i></a><?php endif; ?>
                            <?php if (!empty($m['gasto_id'])): ?> <a href="gasto_ver?id=<?= (int)$m['gasto_id'] ?>" class="app-badge app-badge-muted text-decoration-none" title="Ver el gasto">Gasto #<?= (int)$m['gasto_id'] ?></a><?php endif; ?><?= $m['tasa_cambio'] ? ' <small class="text-muted">(tasa ' . rtrim(rtrim(number_format((float)$m['tasa_cambio'], 4), '0'), '.') . ')</small>' : '' ?><?= $anul ? ' <span class="app-badge app-badge-danger">Anulado</span>' : '' ?></td>
                        <td class="small font-monospace"><?= htmlspecialchars($m['referencia'] ?? '') ?></td>
                        <td class="app-num text-success" data-sort="<?= $m['sentido'] === 'entrada' ? (float)$m['monto'] : 0 ?>"><?= $m['sentido'] === 'entrada' ? bancoMoneda((float)$m['monto'], $mon) : '' ?></td>
                        <td class="app-num text-danger" data-sort="<?= $m['sentido'] === 'salida' ? (float)$m['monto'] : 0 ?>"><?= $m['sentido'] === 'salida' ? bancoMoneda((float)$m['monto'], $mon) : '' ?></td>
                        <td class="app-num" data-sort="<?= (float)$m['saldo'] ?>"><?= $anul ? '' : bancoMoneda((float)$m['saldo'], $mon) ?></td>
                        <td class="text-center">
                            <?php if (!$anul): ?>
                                <input type="checkbox" class="form-check-input chk-conciliar" data-id="<?= (int)$m['id'] ?>" <?= (int)$m['conciliado'] ? 'checked' : '' ?> <?= $puedeConciliar ? '' : 'disabled' ?> aria-label="Conciliado">
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
                <?php if (!$movs): ?><tr><td colspan="10" class="text-center text-muted py-4">Sin movimientos con estos filtros.</td></tr><?php endif; ?>
            </tbody>
            <tfoot><tr class="table-light"><td colspan="7" class="small text-muted">Saldo al inicio del período (<?= date('d/m/Y', strtotime($desde)) ?>)</td><td class="app-num fw-semibold"><?= bancoMoneda($saldoAnterior, $mon) ?></td><td colspan="<?= $puedeEditar ? 2 : 1 ?>"></td></tr></tfoot>
        </table>
    </div>
    <div class="app-pager" id="bcPie"></div>
</div>
</div>

<script src="../../clientes/js/bancos.js?v=<?= @filemtime(__DIR__ . '/../js/bancos.js') ?>"></script>
<script src="../../clientes/js/app-tabla.js?v=<?= @filemtime(__DIR__ . '/../js/app-tabla.js') ?>"></script>
<script>
(function () {
    const B = window.Bancos;
    // Tabla: búsqueda, paginación y orden por columnas (se vuelve a armar al filtrar sin recargar)
    const iniciarTabla = () => { AppTabla(document.getElementById('tMovs'), { buscar: '#bcBuscar', porPagina: '#bcPorPagina', pie: '#bcPie', vacio: 'Sin resultados.' }); window.AppOrdenarIniciar?.(); };
    iniciarTabla();
    const f = document.getElementById('bcFiltros');
    let pedido = 0;
    const cargar = async url => {
        const yo = ++pedido, z = document.getElementById('bcZona');
        z.style.opacity = '.5';
        try {
            const r = await fetch(url, { credentials: 'same-origin' });
            if (!r.ok) throw new Error();
            const doc = new DOMParser().parseFromString(await r.text(), 'text/html');
            if (yo !== pedido) return;
            z.replaceWith(doc.getElementById('bcZona'));
            f.querySelector('[name=tipo]').innerHTML = doc.querySelector('#bcFiltros [name=tipo]').innerHTML;
            document.querySelectorAll('.bc-rango').forEach(a => { const on = a.dataset.desde === f.desde.value && a.dataset.hasta === f.hasta.value; a.classList.toggle('btn-secondary', on); a.classList.toggle('btn-outline-secondary', !on); });
            history.replaceState(null, '', url);
            iniciarTabla();
        } catch (e) { location.href = url; }
        finally { document.getElementById('bcZona').style.removeProperty('opacity'); }
    };
    const urlForm = () => location.pathname + '?' + new URLSearchParams(new FormData(f)).toString();
    f.addEventListener('change', () => cargar(urlForm()));
    f.addEventListener('submit', e => { e.preventDefault(); cargar(urlForm()); });
    document.addEventListener('click', e => { const a = e.target.closest('.bc-rango'); if (a) { e.preventDefault(); f.desde.value = a.dataset.desde; f.hasta.value = a.dataset.hasta; cargar(urlForm()); } });
    document.addEventListener('change', e => {
        const ch = e.target.closest('.chk-conciliar');
        if (!ch) return;
        B.accion({ accion: 'conciliar', id: ch.dataset.id, conciliado: ch.checked ? 1 : 0 }, { sinRecargar: true })
            .catch(() => ch.checked = !ch.checked);
    });
    document.addEventListener('click', e => {
        const b = e.target.closest('.btn-anular-mov');
        if (!b) return;
        Swal.fire({
            title: '¿Anular movimiento?',
            text: b.dataset.transf === '1' ? 'Es una transferencia: se anularán la salida y la entrada.' : 'El movimiento queda registrado como anulado.',
            icon: 'warning', showCancelButton: true, confirmButtonText: 'Anular', cancelButtonText: 'Cancelar', confirmButtonColor: '#dc2626'
        }).then(r => { if (r.isConfirmed) B.accion({ accion: 'anular_movimiento', id: b.dataset.id }).catch(() => {}); });
    });
})();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
