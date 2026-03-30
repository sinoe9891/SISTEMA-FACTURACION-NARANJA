<?php
$titulo = 'Tarjetas Registradas';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';
require_once '../../includes/templates/header.php';

$cliente_id = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);

$stmtTarj = $pdo->prepare("
    SELECT t.*,
           (SELECT COUNT(*) FROM gastos g WHERE g.tarjeta_id=t.id AND g.cliente_id=t.cliente_id) AS usos
    FROM tarjetas t
    WHERE t.cliente_id=?
    ORDER BY t.activa DESC, t.banco ASC
");
$stmtTarj->execute([$cliente_id]);
$tarjetas = $stmtTarj->fetchAll(PDO::FETCH_ASSOC);

$tipoIcon  = ['visa'=>'💳','mastercard'=>'💳','amex'=>'💳','debito'=>'🏦','credito'=>'💰','otro'=>'🔷'];
$tipoLabel = ['visa'=>'Visa','mastercard'=>'Mastercard','amex'=>'Amex','debito'=>'Débito','credito'=>'Crédito','otro'=>'Otro'];
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
:root {
    --brand:#1d4ed8; --brand-lt:#dbeafe; --border:#e2e8f0;
    --surface:#fff; --surface-2:#f8fafc; --text:#1e293b; --muted:#64748b;
    --shadow-sm:0 1px 3px rgba(0,0,0,.06); --shadow-md:0 4px 16px rgba(0,0,0,.08);
    --radius:14px; --radius-sm:8px; --tr:.2s cubic-bezier(.4,0,.2,1);
}
.tj-page  { padding:1.5rem 0 3rem }
.tj-hero  { background:linear-gradient(135deg,#1e40af,#1e3a8a); border-radius:var(--radius); padding:1.6rem 2rem; color:#fff; display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-bottom:1.5rem; box-shadow:var(--shadow-md); position:relative; overflow:hidden }
.tj-hero::before { content:''; position:absolute; top:-40px; right:-40px; width:180px; height:180px; border-radius:50%; background:rgba(255,255,255,.08) }
.tj-card  { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow-sm); overflow:hidden; margin-bottom:1.25rem }
.tj-card-hdr { padding:.9rem 1.4rem; border-bottom:1px solid var(--border); background:var(--surface-2); display:flex; align-items:center; justify-content:space-between }
.tj-table { width:100%; border-collapse:collapse; font-size:.855rem }
.tj-table thead th { padding:.7rem 1rem; background:var(--surface-2); color:var(--muted); font-size:.72rem; font-weight:600; text-transform:uppercase; letter-spacing:.05em; border-bottom:1px solid var(--border) }
.tj-table tbody tr { border-bottom:1px solid var(--border); transition:background var(--tr) }
.tj-table tbody tr:hover { background:#eff6ff }
.tj-table tbody td { padding:.82rem 1rem; vertical-align:middle }
.card-chip { display:inline-flex; align-items:center; gap:.5rem; padding:.4rem .85rem; border-radius:8px; font-weight:700; font-size:.85rem }
.chip-visa { background:#1a1f71; color:#fff }
.chip-mastercard { background:#eb001b; color:#fff }
.chip-amex { background:#007bc1; color:#fff }
.chip-debito { background:#059669; color:#fff }
.chip-credito { background:#7c3aed; color:#fff }
.chip-otro { background:#64748b; color:#fff }
.btn-nuevo-tj { display:inline-flex; align-items:center; gap:.4rem; padding:.55rem 1.1rem; background:#1d4ed8; color:#fff!important; border-radius:var(--radius-sm); font-size:.88rem; font-weight:600; border:none; cursor:pointer; white-space:nowrap; box-shadow:0 2px 8px rgba(29,78,216,.3); transition:background var(--tr) }
.btn-nuevo-tj:hover { background:#1e40af }
.btn-a { display:inline-flex; align-items:center; gap:.3rem; padding:.3rem .65rem; border-radius:var(--radius-sm); font-size:.77rem; font-weight:600; cursor:pointer; border:1.5px solid transparent; transition:all var(--tr); text-decoration:none }
.btn-edit { background:#ede9fe; color:#7c3aed; border-color:rgba(124,58,237,.2) }
.btn-edit:hover { background:#7c3aed; color:#fff }
.btn-tog-on  { background:#d1fae5; color:#059669; border-color:rgba(5,150,105,.2) }
.btn-tog-on:hover  { background:#059669; color:#fff }
.btn-tog-off { background:#fee2e2; color:#dc2626; border-color:rgba(220,38,38,.2) }
.btn-tog-off:hover { background:#dc2626; color:#fff }
.btn-del { background:#fee2e2; color:#dc2626; border-color:rgba(220,38,38,.2) }
.btn-del:hover { background:#dc2626; color:#fff }
.mf-label { font-size:.78rem; font-weight:600; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; margin-bottom:.35rem; display:block }
.mf-input, .mf-select { width:100%; padding:.58rem .85rem; border:1.5px solid var(--border); border-radius:var(--radius-sm); font-size:.88rem; color:var(--text); background:var(--surface); outline:none; transition:border-color var(--tr) }
.mf-input:focus, .mf-select:focus { border-color:#1d4ed8; box-shadow:0 0 0 3px rgba(29,78,216,.1) }
</style>

<div class="tj-page container-xxl">
    <div class="tj-hero">
        <div>
            <h4 style="font-size:1.35rem;font-weight:700;margin:0">💳 Tarjetas Registradas</h4>
            <p style="font-size:.82rem;opacity:.8;margin:.25rem 0 0">Tarjetas de crédito y débito para registrar pagos</p>
        </div>
        <div class="d-flex gap-2">
            <a href="gastos" class="btn btn-sm" style="background:rgba(255,255,255,.18);color:#fff;border:1px solid rgba(255,255,255,.3);font-weight:600">
                <i class="bi bi-arrow-left me-1"></i>Gastos
            </a>
            <button class="btn-nuevo-tj" id="btnNuevaTarjeta"><i class="bi bi-plus-lg"></i> Nueva Tarjeta</button>
        </div>
    </div>

    <?php if (empty($tarjetas)): ?>
        <div class="tj-card">
            <div class="p-5 text-center text-muted">
                <div style="font-size:3rem;opacity:.25;margin-bottom:1rem">💳</div>
                <div class="fw-semibold">No hay tarjetas registradas</div>
                <div class="small mt-1">Agrega tarjetas para seleccionarlas al registrar pagos</div>
                <button class="btn-nuevo-tj mt-3" id="btnNuevaTarjetaEmpty"><i class="bi bi-plus-lg"></i> Registrar primera tarjeta</button>
            </div>
        </div>
    <?php else: ?>
    <div class="tj-card">
        <div class="tj-card-hdr">
            <span style="font-weight:700;font-size:.95rem"><i class="bi bi-credit-card-2-front me-2"></i>Todas las Tarjetas</span>
            <span style="background:#dbeafe;color:#1d4ed8;border-radius:20px;padding:.15rem .65rem;font-size:.78rem;font-weight:600"><?= count($tarjetas) ?> registradas</span>
        </div>
        <div style="overflow-x:auto">
            <table class="tj-table">
                <thead>
                    <tr>
                        <th>Tarjeta</th>
                        <th>Banco</th>
                        <th>Titular</th>
                        <th class="text-center">Usos</th>
                        <th class="text-center">Estado</th>
                        <th style="cursor:default">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($tarjetas as $t):
                        $chipCls = 'chip-' . ($t['tipo'] ?? 'otro');
                    ?>
                    <tr style="<?= !$t['activa'] ? 'opacity:.55' : '' ?>">
                        <td>
                            <span class="card-chip <?= $chipCls ?>">
                                <?= $tipoIcon[$t['tipo']] ?? '💳' ?> ···· <?= htmlspecialchars($t['ultimos_digitos']) ?>
                            </span>
                            <div style="font-size:.72rem;color:var(--muted);margin-top:3px"><?= $tipoLabel[$t['tipo']] ?? $t['tipo'] ?></div>
                        </td>
                        <td class="fw-semibold"><?= htmlspecialchars($t['banco']) ?></td>
                        <td style="font-size:.83rem;color:var(--muted)"><?= $t['nombre_titular'] ? htmlspecialchars($t['nombre_titular']) : '—' ?></td>
                        <td class="text-center">
                            <?php if ($t['usos'] > 0): ?>
                                <span class="badge bg-info text-dark"><?= $t['usos'] ?> gasto(s)</span>
                            <?php else: ?>
                                <span class="text-muted" style="font-size:.8rem">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($t['activa']): ?>
                                <span class="badge" style="background:#d1fae5;color:#059669;border:1px solid #a7f3d0">Activa</span>
                            <?php else: ?>
                                <span class="badge bg-light text-secondary border">Inactiva</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex gap-2">
                                <button class="btn-a btn-edit btn-editar-tarjeta"
                                    data-tj='<?= json_encode($t, JSON_HEX_APOS|JSON_HEX_QUOT) ?>'
                                    title="Editar"><i class="bi bi-pencil-fill"></i></button>
                                <button class="btn-a <?= $t['activa'] ? 'btn-tog-on' : 'btn-tog-off' ?> btn-toggle-tarjeta"
                                    data-id="<?= $t['id'] ?>" data-activa="<?= $t['activa'] ?>"
                                    title="<?= $t['activa'] ? 'Desactivar' : 'Activar' ?>">
                                    <i class="bi bi-<?= $t['activa'] ? 'toggle-on' : 'toggle-off' ?>"></i>
                                </button>
                                <?php if ((int)$t['usos'] === 0): ?>
                                <button class="btn-a btn-del btn-eliminar-tarjeta"
                                    data-id="<?= $t['id'] ?>"
                                    data-label="<?= htmlspecialchars($t['banco'] . ' ···· ' . $t['ultimos_digitos']) ?>"
                                    title="Eliminar"><i class="bi bi-trash3-fill"></i></button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Modal Nueva/Editar Tarjeta -->
<div class="modal fade" id="modalTarjeta" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header py-3" style="background:linear-gradient(135deg,#1e40af,#1e3a8a)">
                <h5 class="modal-title fw-bold text-white" id="modalTarjetaTitulo">
                    <i class="bi bi-credit-card-2-front me-2"></i>Nueva Tarjeta
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form id="formTarjeta">
                    <input type="hidden" name="tarjeta_id" id="tj_id">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="mf-label">Banco / Emisor *</label>
                            <input type="text" name="banco" id="tj_banco" class="mf-input" placeholder="Ej: BAC, Atlántida, Ficohsa…" maxlength="100" required>
                        </div>
                        <div class="col-md-4">
                            <label class="mf-label">Tipo *</label>
                            <select name="tipo" id="tj_tipo" class="mf-select">
                                <option value="visa">💳 Visa</option>
                                <option value="mastercard">💳 Mastercard</option>
                                <option value="amex">💳 Amex</option>
                                <option value="debito">🏦 Débito</option>
                                <option value="credito">💰 Crédito</option>
                                <option value="otro">🔷 Otro</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="mf-label">Últimos 4 dígitos *</label>
                            <input type="text" name="ultimos_digitos" id="tj_dig" class="mf-input text-center fw-bold"
                                placeholder="0000" maxlength="4" pattern="\d{4}" required style="font-size:1.1rem;letter-spacing:.3em">
                        </div>
                        <div class="col-md-8">
                            <label class="mf-label">Titular <span class="text-muted fw-normal" style="text-transform:none;letter-spacing:0">(opcional)</span></label>
                            <input type="text" name="nombre_titular" id="tj_titular" class="mf-input" placeholder="Nombre en la tarjeta" maxlength="150">
                        </div>
                        <div class="col-12">
                            <label class="mf-label">Notas <span class="text-muted fw-normal" style="text-transform:none;letter-spacing:0">(opcional)</span></label>
                            <input type="text" name="notas" id="tj_notas" class="mf-input" placeholder="Ej: tarjeta de gastos operativos" maxlength="300">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer border-top">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" id="btnGuardarTarjeta"
                    style="display:inline-flex;align-items:center;gap:.4rem;padding:.58rem 1.3rem;background:#1d4ed8;color:#fff;border:none;border-radius:var(--radius-sm);font-size:.88rem;font-weight:600;cursor:pointer">
                    <i class="bi bi-floppy-fill me-1"></i> Guardar
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function abrirNueva() {
    document.getElementById('modalTarjetaTitulo').innerHTML = '<i class="bi bi-credit-card-2-front me-2"></i>Nueva Tarjeta';
    document.getElementById('formTarjeta').reset();
    document.getElementById('tj_id').value = '';
    new bootstrap.Modal(document.getElementById('modalTarjeta')).show();
}
document.getElementById('btnNuevaTarjeta').addEventListener('click', abrirNueva);
document.getElementById('btnNuevaTarjetaEmpty')?.addEventListener('click', abrirNueva);

document.querySelectorAll('.btn-editar-tarjeta').forEach(btn => {
    btn.addEventListener('click', () => {
        const t = JSON.parse(btn.dataset.tj);
        document.getElementById('modalTarjetaTitulo').innerHTML = '<i class="bi bi-pencil-square me-2"></i>Editar Tarjeta';
        document.getElementById('tj_id').value      = t.id;
        document.getElementById('tj_banco').value   = t.banco || '';
        document.getElementById('tj_tipo').value    = t.tipo || 'visa';
        document.getElementById('tj_dig').value     = t.ultimos_digitos || '';
        document.getElementById('tj_titular').value = t.nombre_titular || '';
        document.getElementById('tj_notas').value   = t.notas || '';
        new bootstrap.Modal(document.getElementById('modalTarjeta')).show();
    });
});

document.getElementById('btnGuardarTarjeta').addEventListener('click', () => {
    const isEdit = !!document.getElementById('tj_id').value;
    const btn = document.getElementById('btnGuardarTarjeta');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Guardando…';
    const url = isEdit ? 'includes/tarjeta_actualizar.php' : 'includes/tarjeta_guardar.php';
    fetch(url, { method:'POST', body: new FormData(document.getElementById('formTarjeta')) })
    .then(r => r.json()).then(d => {
        if (d.success) Swal.fire({ icon:'success', title:'¡Guardado!', timer:1400, showConfirmButton:false })
            .then(() => location.reload());
        else Swal.fire('Error', d.error, 'error');
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-floppy-fill me-1"></i> Guardar';
    });
});

document.querySelectorAll('.btn-toggle-tarjeta').forEach(btn => {
    btn.addEventListener('click', () => {
        const fd = new FormData();
        fd.append('tarjeta_id', btn.dataset.id);
        fd.append('_toggle_activa', 1);
        fd.append('activa_actual', btn.dataset.activa);
        fetch('includes/tarjeta_actualizar.php', { method:'POST', body:fd })
        .then(r => r.json()).then(d => { if (d.success) location.reload(); else Swal.fire('Error', d.error, 'error'); });
    });
});

document.querySelectorAll('.btn-eliminar-tarjeta').forEach(btn => {
    btn.addEventListener('click', () => {
        Swal.fire({ title:'¿Eliminar tarjeta?', html:`<strong>${btn.dataset.label}</strong>`, icon:'warning',
            showCancelButton:true, confirmButtonColor:'#dc2626', confirmButtonText:'Sí, eliminar', cancelButtonText:'No', reverseButtons:true })
        .then(r => {
            if (!r.isConfirmed) return;
            const fd = new FormData(); fd.append('id', btn.dataset.id);
            fetch('includes/tarjeta_eliminar.php', { method:'POST', body:fd })
            .then(r => r.json()).then(d => {
                if (d.success) location.reload();
                else Swal.fire('No se puede eliminar', d.error, 'warning');
            });
        });
    });
});
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
