<?php
// Licencias y suscripciones: las de la empresa (gasto) y las de clientes (se pagan y se les cobran con comisión).
$titulo = 'Licencias';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/licencias.php';

if (!in_array(USUARIO_ROL, ['admin', 'superadmin'], true)) {
    header('Location: dashboard');
    exit;
}
$cid = (int)cliente_actual();
$instalado = licenciasDisponible($pdo);
if ($instalado) {
    try { $pdo->beginTransaction(); licenciasSincronizar($pdo, $cid, (int)USUARIO_ID); $pdo->commit(); }
    catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('licencias.php: ' . $e->getMessage()); }
}
$lista = $instalado ? licenciasLista($pdo, $cid) : [];
$tasa = $instalado ? licenciaTasa($pdo, $cid) : 0;
$st = $pdo->prepare("SELECT id, nombre FROM clientes_factura WHERE cliente_id = ? ORDER BY nombre");
$st->execute([$cid]);
$clientes = $st->fetchAll(PDO::FETCH_KEY_PAIR);
$st = $pdo->prepare("SELECT id, nombre FROM categorias_gastos WHERE cliente_id = ? AND activa = 1 ORDER BY nombre");
$st->execute([$cid]);
$categorias = $st->fetchAll(PDO::FETCH_KEY_PAIR);
$catDefecto = (int)(array_search('Licencias Digitales', $categorias, true) ?: 0);

// Totales al año (mensual × 12) de las activas
$anual = fn($l) => licenciaCostoHnl($pdo, $cid, $l) * licenciaVecesAnio($l['frecuencia']);
$costoPropias = $costoClientes = $ventaClientes = 0.0;
foreach ($lista as $l) {
    if (!(int)$l['activa']) continue;
    if ($l['receptor_id']) { $costoClientes += $anual($l); $ventaClientes += licenciaPrecio($anual($l), (float)$l['comision_pct']); }
    else $costoPropias += $anual($l);
}
$L = fn($v) => 'L ' . number_format((float)$v, 2);
$fmtPct = fn($p) => rtrim(rtrim(number_format((float)$p, 2), '0'), '.') . ' %';

require_once '../../includes/templates/header.php';
?>
<style>
    .sem-rojo { background: #fee2e2; color: #b91c1c; }
    .sem-amarillo { background: #fef3c7; color: #b45309; }
    .sem-gris { background: #f1f5f9; color: #475569; }
    .sem-pill { display: inline-block; border-radius: 999px; padding: .1rem .55rem; font-size: .72rem; font-weight: 600; white-space: nowrap; }
</style>

<div class="app-page-header">
    <div>
        <h1 class="app-page-title"><i class="bi bi-key me-2"></i>Licencias</h1>
        <p class="app-page-sub">Suscripciones y renovaciones (Adobe, Dropbox, hosting, dominios, correos…). Cada renovación queda como gasto pendiente en Cuentas por pagar y en la Proyección. Las de un cliente salen como aviso en su factura, con la comisión.</p>
    </div>
    <?php if ($instalado): ?><div><button class="btn btn-primary" id="btnNueva"><i class="bi bi-plus-circle me-1"></i> Nueva licencia</button></div><?php endif; ?>
</div>

<?php if (!$instalado): ?>
    <div class="alert alert-warning">Falta instalar el módulo de licencias (migración 2026-10-09_licencias.sql).</div>
<?php else: ?>

<div class="app-stats">
    <div class="app-stat"><div class="app-stat-icon amber"><i class="bi bi-building"></i></div><div><div class="app-stat-val"><?= $L($costoPropias) ?></div><div class="app-stat-lbl">Costo al año · licencias de la empresa</div></div></div>
    <div class="app-stat"><div class="app-stat-icon"><i class="bi bi-people"></i></div><div><div class="app-stat-val"><?= $L($ventaClientes) ?></div><div class="app-stat-lbl">Se cobra al año a clientes (costo <?= $L($costoClientes) ?>)</div></div></div>
    <div class="app-stat"><div class="app-stat-icon green"><i class="bi bi-graph-up-arrow"></i></div><div><div class="app-stat-val"><?= $L($ventaClientes - $costoClientes) ?></div><div class="app-stat-lbl">Ganancia al año por comisiones</div></div></div>
</div>

<div class="app-card">
    <div class="table-responsive">
        <table class="table app-table mb-0 align-middle">
            <thead><tr><th>Licencia</th><th>De quién</th><th>Frecuencia</th><th class="app-num">Costo</th><th class="app-num">Se cobra</th><th>Próxima renovación</th><th class="text-end">Acciones</th></tr></thead>
            <tbody>
                <?php if (!$lista): ?><tr><td colspan="7" class="text-center text-muted py-4">Sin licencias. Agrega las de la empresa (Adobe, Dropbox, correos…) y las de clientes (hosting, dominios).</td></tr><?php endif; ?>
                <?php foreach ($lista as $l): $act = (int)$l['activa']; [$dias, $sem, $txt] = licenciaSemaforo($l['proxima_renovacion']); $costoL = licenciaCostoHnl($pdo, $cid, $l); ?>
                    <tr class="<?= $act ? '' : 'text-muted' ?>">
                        <td><div class="fw-semibold"><?= htmlspecialchars($l['nombre']) ?><?= $act ? '' : ' <span class="badge bg-light text-secondary border">Inactiva</span>' ?></div>
                            <div class="small text-muted"><?= htmlspecialchars(trim(($l['proveedor'] ?? '') . ($l['categoria'] ? ' · ' . $l['categoria'] : ''), ' ·')) ?></div></td>
                        <td><?= $l['receptor_id'] ? '<i class="bi bi-person me-1"></i>' . htmlspecialchars((string)$l['cliente_nombre']) . ' <span class="small text-muted">(+' . $fmtPct($l['comision_pct']) . ')</span>' : '<span class="text-muted">Empresa</span>' ?></td>
                        <td><?= LICENCIA_FRECUENCIAS[$l['frecuencia']] ?? $l['frecuencia'] ?></td>
                        <td class="app-num"><?= $l['moneda'] === 'USD' ? 'USD ' . number_format((float)$l['costo'], 2) . '<div class="small text-muted">' . $L($costoL) . '</div>' : $L($l['costo']) ?></td>
                        <td class="app-num"><?= $l['receptor_id'] ? '<span class="fw-semibold">' . $L(licenciaPrecio($costoL, (float)$l['comision_pct'])) . '</span>' : '<span class="text-muted">—</span>' ?></td>
                        <td class="text-nowrap"><?= date('d/m/Y', strtotime($l['proxima_renovacion'])) ?><?php if ($act): ?> <span class="sem-pill sem-<?= $sem ?>"><?= $txt ?></span><?php endif; ?></td>
                        <td class="text-end text-nowrap">
                            <button class="btn btn-sm btn-outline-secondary py-0 btn-editar" data-l='<?= htmlspecialchars(json_encode($l, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>' title="Editar"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm <?= $act ? 'btn-outline-danger' : 'btn-outline-success' ?> py-0 btn-activa" data-id="<?= (int)$l['id'] ?>" data-activa="<?= $act ? 0 : 1 ?>" title="<?= $act ? 'Desactivar (ya no se renueva)' : 'Activar' ?>"><i class="bi <?= $act ? 'bi-pause-circle' : 'bi-play-circle' ?>"></i></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<p class="small text-muted mt-2"><i class="bi bi-info-circle me-1"></i>Al pagar la renovación en Gastos se programa la siguiente. Las licencias en dólares se convierten a la tasa del día<?= $tasa > 0 ? ' (L ' . number_format($tasa, 4) . ')' : '' ?>. Comisión por defecto: <?= $fmtPct(LICENCIA_COMISION_DEFECTO) ?>.</p>

<div class="modal fade" id="modalLic" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="licTitulo"><i class="bi bi-key me-1"></i> Nueva licencia</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <form id="formLic">
                <input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" id="licId" value="0">
                <div class="row g-3">
                    <div class="col-md-7"><label class="form-label small">Nombre <span class="text-danger">*</span></label><input class="form-control" name="nombre" required maxlength="150" placeholder="Ej.: Adobe Creative Cloud, Hosting sigurban.com"></div>
                    <div class="col-md-5"><label class="form-label small">Proveedor</label><input class="form-control" name="proveedor" maxlength="150" placeholder="Adobe, GoDaddy, Google…"></div>
                    <div class="col-md-4"><label class="form-label small">Frecuencia</label><select class="form-select" name="frecuencia"><?php foreach (LICENCIA_FRECUENCIAS as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-3"><label class="form-label small">Moneda</label><select class="form-select" name="moneda"><option value="HNL">Lempiras</option><option value="USD">Dólares</option></select></div>
                    <div class="col-md-5"><label class="form-label small">Costo por renovación <span class="text-danger">*</span></label><input type="number" class="form-control" name="costo" step="0.01" min="0.01" required></div>
                    <div class="col-md-4"><label class="form-label small">Próxima renovación <span class="text-danger">*</span></label><input type="date" class="form-control" name="proxima_renovacion" required></div>
                    <div class="col-md-4"><label class="form-label small">Se paga con</label><select class="form-select" name="metodo_pago"><option value="tarjeta">Tarjeta</option><option value="transferencia">Transferencia</option><option value="efectivo">Efectivo</option><option value="cheque">Cheque</option><option value="otro">Otro</option></select></div>
                    <div class="col-md-4"><label class="form-label small">Categoría de gasto</label><select class="form-select" name="categoria_id"><option value="">—</option><?php foreach ($categorias as $k => $v): ?><option value="<?= (int)$k ?>"><?= htmlspecialchars($v) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-8"><label class="form-label small">¿Se le cobra a un cliente?</label>
                        <select class="form-select" name="receptor_id" id="licCliente"><option value="">No, es de la empresa</option><?php foreach ($clientes as $k => $v): ?><option value="<?= (int)$k ?>"><?= htmlspecialchars($v) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-4"><label class="form-label small">Comisión (%)</label><input type="number" class="form-control" name="comision_pct" step="0.01" min="0" max="500"></div>
                    <div class="col-12"><div class="small text-muted" id="licPrecio"></div></div>
                    <div class="col-12"><label class="form-label small">Notas</label><textarea class="form-control" name="notas" rows="2" placeholder="Cuenta, usuario, dominio, para qué se usa…"></textarea></div>
                </div>
            </form>
        </div>
        <div class="modal-footer"><button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" id="btnGuardarLic"><i class="bi bi-check-circle me-1"></i>Guardar</button></div>
    </div></div>
</div>
<?php endif; ?>

<script>
(function () {
    const modalEl = document.getElementById('modalLic'); if (!modalEl) return;
    const f = document.getElementById('formLic'), modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    const DEF = { comision_pct: <?= json_encode(LICENCIA_COMISION_DEFECTO) ?>, categoria_id: <?= json_encode($catDefecto ?: '') ?>, frecuencia: 'anual', moneda: 'HNL', metodo_pago: 'tarjeta' };
    const TASA = <?= json_encode($tasa) ?>;
    const fmt = n => 'L ' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const precio = () => {
        const c = +f.costo.value || 0, p = +f.comision_pct.value || 0, cl = f.receptor_id.value, usd = f.moneda.value === 'USD';
        const cL = usd ? c * TASA : c;
        f.comision_pct.disabled = !cl;
        document.getElementById('licPrecio').innerHTML = !c ? '' : (usd ? `Costo en lempiras: <strong>${fmt(cL)}</strong> (tasa ${TASA ? TASA.toFixed(4) : 'sin registrar'}). ` : '')
            + (cl ? `Se le cobra al cliente: <strong>${fmt(cL * (1 + p / 100))}</strong> (costo + ${p} %), en su factura del mes de la renovación.` : 'Es gasto de la empresa.');
    };
    ['costo', 'comision_pct', 'receptor_id', 'moneda'].forEach(n => f[n].addEventListener('input', precio));
    f.receptor_id.addEventListener('change', precio);
    const abrir = l => {
        f.reset();
        const d = Object.assign({}, DEF, l || {});
        document.getElementById('licId').value = l ? l.id : 0;
        document.getElementById('licTitulo').innerHTML = '<i class="bi bi-key me-1"></i> ' + (l ? 'Editar licencia' : 'Nueva licencia');
        ['nombre', 'proveedor', 'frecuencia', 'moneda', 'costo', 'proxima_renovacion', 'metodo_pago', 'categoria_id', 'receptor_id', 'comision_pct', 'notas'].forEach(k => { if (f[k]) f[k].value = d[k] ?? ''; });
        precio(); modal.show();
    };
    document.getElementById('btnNueva').addEventListener('click', () => abrir(null));
    document.querySelectorAll('.btn-editar').forEach(b => b.addEventListener('click', () => abrir(JSON.parse(b.dataset.l))));
    const enviar = async fd => {
        const r = await fetch('includes/licencia_accion.php', { method: 'POST', body: fd });
        const d = await r.json().catch(() => ({ success: false, error: 'Respuesta inesperada del servidor (' + r.status + ').' }));
        if (!d.success) throw new Error(d.error);
        return d;
    };
    document.getElementById('btnGuardarLic').addEventListener('click', async e => {
        if (!f.reportValidity()) return;
        const fd = new FormData(f); if (!f.receptor_id.value) fd.set('comision_pct', f.comision_pct.value || DEF.comision_pct);
        e.currentTarget.disabled = true;
        try { const d = await enviar(fd); await Swal.fire({ icon: 'success', title: 'Listo', text: d.message }); location.reload(); }
        catch (err) { Swal.fire('No se pudo guardar', err.message, 'error'); e.currentTarget.disabled = false; }
    });
    document.querySelectorAll('.btn-activa').forEach(b => b.addEventListener('click', async () => {
        const act = b.dataset.activa === '1';
        if (!(await Swal.fire({ title: act ? 'Activar licencia' : 'Desactivar licencia', text: act ? 'Se programa su próxima renovación.' : 'Se anula su renovación pendiente y deja de renovarse.', icon: 'question', showCancelButton: true, confirmButtonText: act ? 'Activar' : 'Desactivar', cancelButtonText: 'Cancelar' })).isConfirmed) return;
        const fd = new FormData(); fd.append('accion', 'activa'); fd.append('id', b.dataset.id); fd.append('activa', act ? 1 : 0);
        try { const d = await enviar(fd); await Swal.fire({ icon: 'success', title: 'Listo', text: d.message }); location.reload(); }
        catch (err) { Swal.fire('No se pudo', err.message, 'error'); }
    }));
})();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
