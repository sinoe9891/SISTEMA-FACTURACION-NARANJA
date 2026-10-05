<?php
// Pagos de nómina: todos los pagos a colaboradores con filtros, resúmenes y exportación (PDF / XLSX).
$titulo = 'Pagos de nómina';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/nomina_pagos.php';
require_once '../../includes/correo.php';

if (!puedeNomina()) {
    header('Location: dashboard');
    exit;
}
$cid = cliente_actual();
$f = nominaFiltros($_GET);
$pagos = nominaPagos($pdo, $cid, $f);
$res = nominaResumen($pagos);
$cfgNomina = correoDisponible($pdo) ? correoConfig($pdo, $cid, 'nomina') : null;
$correoActivo = $cfgNomina && !empty($cfgNomina['clave_cifrada']) && (int)$cfgNomina['activo'];

$colabs = $pdo->prepare("SELECT id, CONCAT(nombre, ' ', apellido) AS nombre, activo FROM colaboradores WHERE cliente_id = ? ORDER BY activo DESC, nombre");
$colabs->execute([$cid]);
$colabs = $colabs->fetchAll(PDO::FETCH_ASSOC);
$qs = http_build_query(array_filter($f, fn($v) => $v !== '' && $v !== 0));
$L = fn($v) => 'L ' . number_format((float)$v, 2);
$atajos = [
    'Este mes' => [date('Y-m-01'), date('Y-m-d')],
    'Mes anterior' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
    'Este año' => [date('Y-01-01'), date('Y-m-d')],
    'Año anterior' => [(date('Y') - 1) . '-01-01', (date('Y') - 1) . '-12-31'],
];

require_once '../../includes/templates/header.php';
?>

<div class="app-page-header">
    <div>
        <h1 class="app-page-title"><i class="bi bi-cash-stack me-2"></i>Pagos de nómina</h1>
        <p class="app-page-sub">Sueldos, bonos y otros pagos a colaboradores del <?= date('d/m/Y', strtotime($f['desde'])) ?> al <?= date('d/m/Y', strtotime($f['hasta'])) ?>.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="pagos_nomina_exportar.php?formato=xlsx&<?= htmlspecialchars($qs) ?>" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i> XLSX</a>
        <a href="pagos_nomina_exportar.php?formato=pdf&<?= htmlspecialchars($qs) ?>" target="_blank" class="btn btn-outline-danger"><i class="bi bi-file-earmark-pdf me-1"></i> PDF</a>
        <a href="colaboradores?registrar=1" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i> Registrar pago o movimiento</a>
        <a href="colaboradores" class="btn btn-primary"><i class="bi bi-people me-1"></i> Colaboradores</a>
    </div>
</div>

<!-- Filtros -->
<form class="app-card app-card-body mb-3" method="get" id="formFiltros">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2"><label class="form-label small">Desde</label><input type="date" class="form-control form-control-sm" name="desde" value="<?= $f['desde'] ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small">Hasta</label><input type="date" class="form-control form-control-sm" name="hasta" value="<?= $f['hasta'] ?>"></div>
        <div class="col-md-2"><label class="form-label small">Colaborador</label>
            <select class="form-select form-select-sm" name="colaborador"><option value="">Todos</option>
                <?php foreach ($colabs as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $f['colaborador'] === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['nombre']) ?><?= (int)$c['activo'] ? '' : ' (inactivo)' ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-6 col-md-1"><label class="form-label small">Tipo</label>
            <select class="form-select form-select-sm" name="tipo"><option value="">Todos</option>
                <?php foreach (NOMINA_TIPOS as $k => $t): ?><option value="<?= $k ?>" <?= $f['tipo'] === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-6 col-md-1"><label class="form-label small">Quincena</label>
            <select class="form-select form-select-sm" name="quincena"><option value="">Todas</option><option value="1" <?= $f['quincena'] === '1' ? 'selected' : '' ?>>1ª</option><option value="2" <?= $f['quincena'] === '2' ? 'selected' : '' ?>>2ª</option><option value="mensual" <?= $f['quincena'] === 'mensual' ? 'selected' : '' ?>>Mensual</option></select></div>
        <div class="col-6 col-md-2"><label class="form-label small">Comprobante</label>
            <select class="form-select form-select-sm" name="comprobante"><option value="">Todos</option><option value="con" <?= $f['comprobante'] === 'con' ? 'selected' : '' ?>>Con</option><option value="sin" <?= $f['comprobante'] === 'sin' ? 'selected' : '' ?>>Sin</option></select></div>
        <div class="col-6 col-md-1"><label class="form-label small">Aviso</label>
            <select class="form-select form-select-sm" name="aviso"><option value="">Todos</option><option value="si" <?= $f['aviso'] === 'si' ? 'selected' : '' ?>>Enviado</option><option value="no" <?= $f['aviso'] === 'no' ? 'selected' : '' ?>>Sin enviar</option></select></div>
        <div class="col-md-1 d-grid"><a href="pagos_nomina" class="btn btn-sm btn-outline-secondary" title="Quitar filtros"><i class="bi bi-x-lg"></i></a></div>
    </div>
    <div class="d-flex flex-wrap gap-1 mt-2">
        <?php foreach ($atajos as $txt => [$d, $h]): ?>
            <a class="btn btn-sm <?= $f['desde'] === $d && $f['hasta'] === $h ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?<?= htmlspecialchars(http_build_query(['desde' => $d, 'hasta' => $h] + array_filter($f, fn($v, $k) => !in_array($k, ['desde', 'hasta'], true) && $v !== '' && $v !== 0, ARRAY_FILTER_USE_BOTH))) ?>"><?= $txt ?></a>
        <?php endforeach; ?>
    </div>
</form>

<div class="app-stats">
    <div class="app-stat"><div class="app-stat-icon green"><i class="bi bi-cash-stack"></i></div><div><div class="app-stat-val"><?= $L($res['total']) ?></div><div class="app-stat-lbl">Total pagado</div></div></div>
    <div class="app-stat"><div class="app-stat-icon"><i class="bi bi-receipt"></i></div><div><div class="app-stat-val"><?= $res['n'] ?></div><div class="app-stat-lbl">Pagos · <?= count($res['por_colaborador']) ?> colaborador(es)</div></div></div>
    <div class="app-stat"><div class="app-stat-icon <?= $res['n'] && $res['comprobantes'] < $res['n'] ? 'amber' : 'teal' ?>"><i class="bi bi-paperclip"></i></div><div><div class="app-stat-val"><?= $res['comprobantes'] ?>/<?= $res['n'] ?></div><div class="app-stat-lbl">Con comprobante</div></div></div>
    <div class="app-stat"><div class="app-stat-icon purple"><i class="bi bi-envelope-check"></i></div><div><div class="app-stat-val"><?= $res['avisos'] ?></div><div class="app-stat-lbl">Avisos enviados por correo</div></div></div>
</div>

<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tDetalle" type="button"><i class="bi bi-list-ul me-1"></i>Detalle <span class="badge bg-secondary ms-1"><?= $res['n'] ?></span></button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tColab" type="button"><i class="bi bi-people me-1"></i>Por colaborador</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tMes" type="button"><i class="bi bi-calendar3 me-1"></i>Por mes</button></li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="tDetalle">
        <div class="app-card">
            <div class="app-card-header flex-wrap">
                <span><i class="bi bi-list-ul me-1"></i> Pagos</span>
                <div class="app-toolbar flex-grow-1 justify-content-end">
                    <div class="app-search" style="max-width:280px"><i class="bi bi-search"></i><input type="search" class="form-control form-control-sm" id="buscarPago" placeholder="Buscar colaborador, período, referencia…"></div>
                    <select class="form-select form-select-sm" id="porPagina" style="width:auto"><option value="10">10/pág</option><option value="25">25/pág</option><option value="50">50/pág</option><option value="100">100/pág</option></select>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table app-table mb-0" id="tablaPagos">
                    <thead><tr><th class="app-n">#</th><th>Fecha</th><th>Colaborador</th><th>Concepto</th><th>Período</th><th>Método / ref.</th><th class="app-num">Monto</th><th class="text-center">Comprobante</th><th class="text-center">Aviso</th><th class="text-end">Acciones</th></tr></thead>
                    <tbody>
                        <?php foreach ($pagos as $p): ?>
                            <tr data-fila>
                                <td class="app-n"></td>
                                <td class="text-nowrap"><?= date('d/m/Y', strtotime($p['fecha'])) ?></td>
                                <td><a href="colaborador_ver?id=<?= $p['colaborador_id'] ?>&todo=1"><?= htmlspecialchars($p['colaborador']) ?></a><?= $p['colaborador_activo'] ? '' : ' <span class="app-badge app-badge-muted">Inactivo</span>' ?></td>
                                <td><span class="app-badge app-badge-<?= $p['tipo'] === 'sueldo' ? 'info' : 'warning' ?>"><?= $p['tipo_txt'] ?></span><?= $p['tipo'] !== 'sueldo' ? '<div class="small text-muted">' . htmlspecialchars(mb_strimwidth($p['descripcion'], 0, 50, '…')) . '</div>' : '' ?></td>
                                <td class="small"><?= htmlspecialchars($p['periodo']) ?></td>
                                <td class="small"><?= htmlspecialchars(ucfirst((string)$p['metodo_pago'])) ?><?= $p['referencia'] ? '<div class="text-muted font-monospace">' . htmlspecialchars($p['referencia']) . '</div>' : '' ?></td>
                                <td class="app-num fw-semibold"><?= number_format((float)$p['monto'], 2) ?></td>
                                <td class="text-center"><?= $p['archivo_adjunto'] ? '<a href="gasto_archivo?id=' . (int)$p['id'] . '" target="_blank" class="btn btn-sm btn-outline-secondary py-0" title="Ver comprobante"><i class="bi bi-paperclip"></i></a>' : '<span class="text-muted small">—</span>' ?></td>
                                <td class="text-center text-nowrap">
                                    <?php if ($p['tipo'] !== 'sueldo'): ?><span class="text-muted small">—</span>
                                    <?php elseif ($p['aviso_enviado']): ?><span class="app-badge app-badge-success" title="Enviado el <?= date('d/m/Y g:i a', strtotime($p['aviso_enviado'])) ?>"><i class="bi bi-envelope-check"></i> Enviado</span>
                                    <?php elseif ($correoActivo && $p['tiene_email']): ?><button class="btn btn-sm btn-outline-primary py-0 btn-aviso" data-gasto="<?= (int)$p['id'] ?>" title="Enviar aviso de pago"><i class="bi bi-envelope"></i> Enviar</button>
                                    <?php else: ?><span class="text-muted small" title="<?= $p['tiene_email'] ? 'La cuenta de correo Nómina no está activa' : 'El colaborador no tiene correo' ?>"><?= $p['tiene_email'] ? '—' : 'Sin correo' ?></span><?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap"><?php if ($p['tipo'] === 'sueldo'): ?>
                                    <button class="btn btn-sm btn-outline-secondary py-0" data-nomina-accion="editar" data-id="<?= (int)$p['id'] ?>" title="Editar fecha, método, notas o comprobante"><i class="bi bi-pencil"></i></button>
                                    <button class="btn btn-sm btn-outline-danger py-0" data-nomina-accion="anular" data-id="<?= (int)$p['id'] ?>" title="Anular (deshace descuentos y libera la quincena)"><i class="bi bi-slash-circle"></i></button>
                                <?php else: ?><a class="btn btn-sm btn-outline-secondary py-0" href="gasto_ver?id=<?= (int)$p['id'] ?>" title="Ver en Gastos"><i class="bi bi-eye"></i></a><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <?php if ($pagos): ?><tfoot><tr class="fw-semibold"><td colspan="6" class="text-end">Total (filtros aplicados)</td><td class="app-num"><?= number_format($res['total'], 2) ?></td><td colspan="3"></td></tr></tfoot><?php endif; ?>
                </table>
            </div>
            <div class="app-pager" id="pagosPie"></div>
        </div>
    </div>

    <div class="tab-pane fade" id="tColab">
        <div class="app-card">
            <div class="table-responsive">
                <table class="table app-table mb-0">
                    <thead><tr><th class="app-n">#</th><th>Colaborador</th><th class="app-num">Pagos</th><th class="app-num">Sueldos</th><th class="app-num">Bonos y otros</th><th class="app-num">Total</th><th class="text-center">Comprobantes</th><th>Último pago</th></tr></thead>
                    <tbody>
                        <?php if (!$res['por_colaborador']): ?><tr><td colspan="8" class="text-center text-muted py-4">Sin pagos con estos filtros.</td></tr><?php endif; ?>
                        <?php $n = 0; foreach ($res['por_colaborador'] as $id => $c): ?>
                            <tr>
                                <td class="app-n"><?= ++$n ?></td>
                                <td><a href="?<?= htmlspecialchars(http_build_query(['colaborador' => $id] + array_filter($f, fn($v, $k) => $k !== 'colaborador' && $v !== '' && $v !== 0, ARRAY_FILTER_USE_BOTH))) ?>"><?= htmlspecialchars($c['nombre']) ?></a></td>
                                <td class="app-num"><?= $c['n'] ?></td>
                                <td class="app-num"><?= number_format($c['sueldos'], 2) ?></td>
                                <td class="app-num"><?= $c['otros'] > 0 ? number_format($c['otros'], 2) : '—' ?></td>
                                <td class="app-num fw-semibold"><?= number_format($c['total'], 2) ?></td>
                                <td class="text-center"><span class="app-badge <?= $c['comprobantes'] === $c['n'] ? 'app-badge-success' : 'app-badge-muted' ?>"><?= $c['comprobantes'] ?>/<?= $c['n'] ?></span></td>
                                <td class="text-nowrap"><?= date('d/m/Y', strtotime($c['ultimo'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <?php if ($res['por_colaborador']): ?><tfoot><tr class="fw-semibold"><td colspan="5" class="text-end">Total</td><td class="app-num"><?= number_format($res['total'], 2) ?></td><td colspan="2"></td></tr></tfoot><?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="tMes">
        <?php $nombresCol = array_column($res['por_colaborador'], 'nombre'); ?>
        <div class="app-card">
            <div class="table-responsive">
                <table class="table app-table mb-0">
                    <thead><tr><th>Mes</th><?php foreach ($nombresCol as $nc): ?><th class="app-num"><?= htmlspecialchars(explode(' ', $nc)[0]) ?></th><?php endforeach; ?><th class="app-num">Pagos</th><th class="app-num">Total del mes</th></tr></thead>
                    <tbody>
                        <?php if (!$res['por_mes']): ?><tr><td colspan="<?= count($nombresCol) + 3 ?>" class="text-center text-muted py-4">Sin pagos con estos filtros.</td></tr><?php endif; ?>
                        <?php foreach ($res['por_mes'] as $ym => $m): ?>
                            <tr>
                                <td class="text-nowrap fw-semibold"><?= nominaMesTxt($ym) ?></td>
                                <?php foreach ($nombresCol as $nc): ?><td class="app-num"><?= isset($m['colabs'][$nc]) ? number_format($m['colabs'][$nc], 2) : '<span class="text-muted">—</span>' ?></td><?php endforeach; ?>
                                <td class="app-num"><?= $m['n'] ?></td>
                                <td class="app-num fw-semibold"><?= number_format($m['total'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <?php if ($res['por_mes']): ?><tfoot><tr class="fw-semibold"><td>Total</td><?php foreach ($nombresCol as $nc): ?><td class="app-num"><?= number_format(array_sum(array_map(fn($m) => $m['colabs'][$nc] ?? 0, $res['por_mes'])), 2) ?></td><?php endforeach; ?><td class="app-num"><?= $res['n'] ?></td><td class="app-num"><?= number_format($res['total'], 2) ?></td></tr></tfoot><?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="../../clientes/js/app-tabla.js?v=<?= @filemtime(__DIR__ . '/../js/app-tabla.js') ?>"></script>
<script src="../../clientes/js/nomina-pago.js?v=<?= @filemtime(__DIR__ . '/../js/nomina-pago.js') ?>"></script>
<script>
(function () {
    AppTabla('#tablaPagos', { buscar: '#buscarPago', porPagina: '#porPagina', pie: '#pagosPie', porPaginaInicial: 10, vacio: 'Sin pagos con estos filtros.' });
    // Los filtros se aplican al cambiar (las pestañas de abajo no recargan)
    document.querySelectorAll('#formFiltros select, #formFiltros input[type=date]').forEach(el => el.addEventListener('change', () => document.getElementById('formFiltros').submit()));
    document.querySelectorAll('.btn-aviso').forEach(b => b.addEventListener('click', async () => {
        if (!(await Swal.fire({ title: 'Enviar aviso de pago', text: 'Se enviará al colaborador con el comprobante adjunto.', icon: 'question', showCancelButton: true, confirmButtonText: 'Enviar', cancelButtonText: 'Cancelar' })).isConfirmed) return;
        const fd = new FormData(); fd.append('accion', 'enviar_pago'); fd.append('gasto_id', b.dataset.gasto);
        Swal.fire({ title: 'Enviando…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        fetch('includes/correo_accion.php', { method: 'POST', body: fd }).then(r => r.json())
            .then(d => { if (!d.success) throw new Error(d.error); Swal.fire({ icon: 'success', title: d.message }).then(() => location.reload()); })
            .catch(err => Swal.fire('No se pudo enviar', err.message, 'error'));
    }));
})();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
