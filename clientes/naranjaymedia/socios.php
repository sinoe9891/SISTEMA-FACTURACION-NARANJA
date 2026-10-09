<?php
// Socios: aportes (dinero que un socio pone en la empresa) y retiros de socio (gastos personales pagados por la empresa).
$titulo = 'Socios: aportes y retiros';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/socios.php';
require_once '../../includes/bancos.php';

if (!permisoPuede($pdo, 'socios')) {
    header('Location: dashboard');
    exit;
}
$cid = (int)cliente_actual();
$instalado = sociosDisponible($pdo);
$anio = preg_match('/^\d{4}$/', (string)($_GET['anio'] ?? '')) ? (int)$_GET['anio'] : 0;   // 0 = todos los años
$enAnio = fn($fecha) => !$anio || (int)substr((string)$fecha, 0, 4) === $anio;
$aportes = array_values(array_filter($instalado ? sociosAportes($pdo, $cid) : [], fn($a) => $enAnio($a['fecha'])));
$retiros = array_values(array_filter(sociosRetiros($pdo, $cid), fn($r) => $enAnio($r['fecha'])));
$socios = sociosNombres($pdo, $cid);
$registrados = sociosRegistrados($pdo, $cid);
foreach ($retiros as $i => $r) $retiros[$i]['socio'] = socioDeRetiro($r, $registrados);

// Resumen por socio
$resumen = [];
foreach ($socios as $s) $resumen[$s] = ['aportes' => 0.0, 'retiros' => 0.0];
foreach ($aportes as $a) { $resumen[$a['socio']] ??= ['aportes' => 0.0, 'retiros' => 0.0]; $resumen[$a['socio']]['aportes'] += (float)$a['monto']; }
foreach ($retiros as $r) { $resumen[$r['socio']] ??= ['aportes' => 0.0, 'retiros' => 0.0]; $resumen[$r['socio']]['retiros'] += (float)$r['monto']; }
$totAp = array_sum(array_column($aportes, 'monto'));
$totRe = array_sum(array_column($retiros, 'monto'));

$anios = [];
foreach (array_merge($instalado ? sociosAportes($pdo, $cid) : [], sociosRetiros($pdo, $cid)) as $x) $anios[(int)substr($x['fecha'], 0, 4)] = true;
krsort($anios);
$cuentasHnl = bancosDisponible($pdo) ? array_values(array_filter(bancoCuentas($pdo, $cid, true), fn($c) => $c['moneda'] === 'HNL')) : [];
$L = fn($v) => 'L ' . number_format((float)$v, 2);
$metodos = ['transferencia' => 'Transferencia', 'deposito' => 'Depósito', 'efectivo' => 'Efectivo', 'cheque' => 'Cheque', 'otro' => 'Otro'];

require_once '../../includes/templates/header.php';
?>

<div class="app-page-header">
    <div>
        <h1 class="app-page-title"><i class="bi bi-person-badge me-2"></i>Socios: aportes y retiros</h1>
        <p class="app-page-sub">Aportes: dinero que un socio pone en la empresa (capital). Retiros: gastos personales de un socio que pagó la empresa; se registran en Gastos con naturaleza «Retiro de socio». Ninguno de los dos cuenta en el Estado de resultados.</p>
    </div>
    <?php if ($instalado): ?>
    <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-outline-secondary" id="btnSocio"><i class="bi bi-person-plus me-1"></i> Agregar socio</button>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAporte"><i class="bi bi-plus-circle me-1"></i> Registrar aporte</button>
    </div>
    <?php endif; ?>
</div>

<?php if (!$instalado): ?>
    <div class="alert alert-warning">Falta instalar el registro de socios (migración 2026-10-09_socios_aportes.sql).</div>
<?php else: ?>

<form class="app-card app-card-body mb-3" method="get">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-auto" style="min-width:9rem"><label class="form-label small">Año</label>
            <select class="form-select form-select-sm" name="anio" onchange="this.form.submit()">
                <option value="">Todos</option>
                <?php foreach (array_keys($anios) as $y): ?><option value="<?= $y ?>" <?= $anio === $y ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?>
            </select></div>
    </div>
</form>

<div class="app-stats">
    <div class="app-stat"><div class="app-stat-icon green"><i class="bi bi-box-arrow-in-down"></i></div><div><div class="app-stat-val"><?= $L($totAp) ?></div><div class="app-stat-lbl">Aportes · <?= count($aportes) ?></div></div></div>
    <div class="app-stat"><div class="app-stat-icon amber"><i class="bi bi-box-arrow-up"></i></div><div><div class="app-stat-val"><?= $L($totRe) ?></div><div class="app-stat-lbl">Retiros · <?= count($retiros) ?></div></div></div>
    <div class="app-stat"><div class="app-stat-icon <?= $totAp - $totRe >= 0 ? 'teal' : 'purple' ?>"><i class="bi bi-arrow-left-right"></i></div><div><div class="app-stat-val"><?= $L($totAp - $totRe) ?></div><div class="app-stat-lbl">Neto (aportes − retiros)</div></div></div>
</div>

<div class="app-card mb-3">
    <div class="app-card-header"><span><i class="bi bi-people me-1"></i> Por socio</span></div>
    <div class="table-responsive">
        <table class="table app-table mb-0">
            <thead><tr><th>Socio</th><th class="app-num">Aportes</th><th class="app-num">Retiros</th><th class="app-num">Neto</th></tr></thead>
            <tbody>
                <?php foreach ($resumen as $s => $r): if (!$r['aportes'] && !$r['retiros'] && $s === 'Sin asignar') continue; ?>
                    <tr><td><?= htmlspecialchars($s) ?><?= $s === 'Sin asignar' ? ' <span class="text-muted small">(retiros que no dicen de qué socio son)</span>' : '' ?></td>
                        <td class="app-num"><?= $L($r['aportes']) ?></td><td class="app-num"><?= $L($r['retiros']) ?></td>
                        <td class="app-num fw-semibold <?= $r['aportes'] - $r['retiros'] < 0 ? 'text-danger' : '' ?>"><?= $L($r['aportes'] - $r['retiros']) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tAportes" type="button"><i class="bi bi-box-arrow-in-down me-1"></i>Aportes <span class="badge bg-secondary ms-1"><?= count($aportes) ?></span></button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tRetiros" type="button"><i class="bi bi-box-arrow-up me-1"></i>Retiros <span class="badge bg-secondary ms-1"><?= count($retiros) ?></span></button></li>
</ul>
<div class="tab-content">
    <div class="tab-pane fade show active" id="tAportes">
        <div class="app-card"><div class="table-responsive">
            <table class="table app-table mb-0">
                <thead><tr><th>Fecha</th><th>Socio</th><th>Método / ref.</th><th>Notas</th><th class="app-num">Monto</th><th class="text-end">Acciones</th></tr></thead>
                <tbody>
                    <?php if (!$aportes): ?><tr><td colspan="6" class="text-center text-muted py-4">Sin aportes registrados<?= $anio ? " en $anio" : '' ?>.</td></tr><?php endif; ?>
                    <?php foreach ($aportes as $a): ?>
                        <tr><td class="text-nowrap"><?= date('d/m/Y', strtotime($a['fecha'])) ?></td><td><?= htmlspecialchars($a['socio']) ?></td>
                            <td class="small"><?= htmlspecialchars($metodos[$a['metodo']] ?? $a['metodo']) ?><?= $a['referencia'] ? ' · ' . htmlspecialchars($a['referencia']) : '' ?></td>
                            <td class="small text-muted"><?= htmlspecialchars((string)$a['notas']) ?></td>
                            <td class="app-num fw-semibold"><?= $L($a['monto']) ?></td>
                            <td class="text-end text-nowrap">
                                <?php if ($a['archivo_adjunto']): ?><a class="btn btn-sm btn-outline-secondary py-0" href="socio_archivo?id=<?= (int)$a['id'] ?>" target="_blank" title="Comprobante"><i class="bi bi-paperclip"></i></a><?php endif; ?>
                                <button class="btn btn-sm btn-outline-danger py-0 btn-anular" data-id="<?= (int)$a['id'] ?>" title="Anular"><i class="bi bi-slash-circle"></i></button></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div></div>
    </div>
    <div class="tab-pane fade" id="tRetiros">
        <div class="app-card"><div class="table-responsive">
            <table class="table app-table mb-0">
                <thead><tr><th>Fecha</th><th>Socio</th><th>Concepto</th><th class="app-num">Monto</th><th class="text-end">Gasto</th></tr></thead>
                <tbody>
                    <?php if (!$retiros): ?><tr><td colspan="5" class="text-center text-muted py-4">Sin retiros<?= $anio ? " en $anio" : '' ?>. Se registran en Gastos con naturaleza «Retiro de socio».</td></tr><?php endif; ?>
                    <?php foreach ($retiros as $r): ?>
                        <tr><td class="text-nowrap"><?= date('d/m/Y', strtotime($r['fecha'])) ?></td><td><?= htmlspecialchars($r['socio']) ?></td>
                            <td><?= htmlspecialchars($r['descripcion']) ?><?= $r['proveedor'] ? '<div class="small text-muted">' . htmlspecialchars($r['proveedor']) . '</div>' : '' ?></td>
                            <td class="app-num fw-semibold"><?= $L($r['monto']) ?></td>
                            <td class="text-end"><a class="btn btn-sm btn-outline-secondary py-0" href="gasto_ver?id=<?= (int)$r['id'] ?>" title="Ver gasto">#<?= (int)$r['id'] ?></a></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div></div>
    </div>
</div>

<div class="modal fade" id="modalAporte" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-box-arrow-in-down me-1"></i> Registrar aporte de socio</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <form id="formAporte" enctype="multipart/form-data">
                <input type="hidden" name="accion" value="aporte">
                <div class="row g-3">
                    <div class="col-12"><label class="form-label small">Socio <span class="text-danger">*</span></label>
                        <input class="form-control" name="socio" list="listaSocios" required maxlength="150" placeholder="Elige o escribe el nombre">
                        <datalist id="listaSocios"><?php foreach ($socios as $s): ?><option value="<?= htmlspecialchars($s) ?>"><?php endforeach; ?></datalist></div>
                    <div class="col-6"><label class="form-label small">Fecha <span class="text-danger">*</span></label><input type="date" class="form-control" name="fecha" value="<?= date('Y-m-d') ?>" required></div>
                    <div class="col-6"><label class="form-label small">Monto (L) <span class="text-danger">*</span></label><input type="number" class="form-control" name="monto" step="0.01" min="0.01" required></div>
                    <div class="col-6"><label class="form-label small">Método</label>
                        <select class="form-select" name="metodo"><?php foreach ($metodos as $k => $t): ?><option value="<?= $k ?>"><?= $t ?></option><?php endforeach; ?></select></div>
                    <div class="col-6"><label class="form-label small">Referencia</label><input class="form-control" name="referencia" maxlength="80"></div>
                    <?php if ($cuentasHnl): ?>
                    <div class="col-12"><label class="form-label small">Entra a la cuenta</label>
                        <select class="form-select" name="cuenta_id"><option value="">— No registrar en banco —</option>
                            <?php foreach ($cuentasHnl as $cb): ?><option value="<?= (int)$cb['id'] ?>"><?= htmlspecialchars($cb['banco'] . ' ' . $cb['numero']) ?></option><?php endforeach; ?></select>
                        <div class="form-text">Si la fecha es anterior al saldo inicial de la cuenta, no se crea el depósito (ya está en ese saldo).</div></div>
                    <?php endif; ?>
                    <div class="col-12"><label class="form-label small">Notas</label><textarea class="form-control" name="notas" rows="2"></textarea></div>
                    <div class="col-12"><label class="form-label small">Comprobante <span class="text-muted">(opcional · JPG, PNG, PDF · máx 5 MB)</span></label><input type="file" class="form-control form-control-sm" name="comprobante" accept=".jpg,.jpeg,.png,.webp,.pdf"></div>
                </div>
            </form>
        </div>
        <div class="modal-footer"><button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" id="btnGuardarAporte"><i class="bi bi-check-circle me-1"></i>Registrar aporte</button></div>
    </div></div>
</div>
<?php endif; ?>

<script>
(function () {
    const enviar = async fd => {
        const r = await fetch('includes/socio_accion.php', { method: 'POST', body: fd });
        const d = await r.json().catch(() => ({ success: false, error: 'Respuesta inesperada del servidor (' + r.status + ').' }));
        if (!d.success) throw new Error(d.error);
        return d;
    };
    document.getElementById('btnGuardarAporte')?.addEventListener('click', async e => {
        const f = document.getElementById('formAporte');
        if (!f.reportValidity()) return;
        e.currentTarget.disabled = true;
        try { const d = await enviar(new FormData(f)); await Swal.fire({ icon: 'success', title: 'Listo', text: d.message }); location.reload(); }
        catch (err) { Swal.fire('No se pudo registrar', err.message, 'error'); e.currentTarget.disabled = false; }
    });
    document.getElementById('btnSocio')?.addEventListener('click', async () => {
        const { value } = await Swal.fire({ title: 'Agregar socio', input: 'text', inputLabel: 'Nombre completo', showCancelButton: true, confirmButtonText: 'Agregar', cancelButtonText: 'Cancelar', inputValidator: v => !v.trim() && 'Escribe el nombre' });
        if (!value) return;
        const fd = new FormData(); fd.append('accion', 'socio'); fd.append('nombre', value);
        try { await enviar(fd); location.reload(); } catch (err) { Swal.fire('No se pudo agregar', err.message, 'error'); }
    });
    document.querySelectorAll('.btn-anular').forEach(b => b.addEventListener('click', async () => {
        const { value } = await Swal.fire({ title: 'Anular aporte', input: 'text', inputLabel: 'Motivo', icon: 'warning', showCancelButton: true, confirmButtonText: 'Anular', cancelButtonText: 'Cancelar', confirmButtonColor: '#dc2626', inputValidator: v => !v.trim() && 'Escribe el motivo' });
        if (!value) return;
        const fd = new FormData(); fd.append('accion', 'anular'); fd.append('id', b.dataset.id); fd.append('motivo', value);
        try { await enviar(fd); location.reload(); } catch (err) { Swal.fire('No se pudo anular', err.message, 'error'); }
    }));
})();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
