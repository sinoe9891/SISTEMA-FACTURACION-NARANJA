<?php
// Cobros por correo: facturas en PDF + mensaje, enviados ahora o programados (hora de Honduras).
$titulo = 'Cobros por correo';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/cobros.php';

if (!in_array(USUARIO_ROL, ['admin', 'superadmin'], true)) {
    header('Location: dashboard');
    exit;
}
$cid = cliente_actual();
$instalado = cobrosDisponible($pdo);
$cfgFact = $instalado ? correoConfig($pdo, $cid, 'facturacion') : null;
$cuentaLista = $cfgFact && !empty($cfgFact['clave_cifrada']) && (int)$cfgFact['activo'];

$clientes = $pdo->prepare("SELECT id, nombre, email FROM clientes_factura WHERE cliente_id = ? ORDER BY nombre");
$clientes->execute([$cid]);
$clientes = $clientes->fetchAll(PDO::FETCH_ASSOC);
$preRid = (int)($_GET['receptor_id'] ?? 0);

$cobros = [];
if ($instalado) {
    $st = $pdo->prepare("
        SELECT c.*, cf.nombre AS cliente, u.nombre AS usuario,
               (SELECT GROUP_CONCAT(f.correlativo ORDER BY f.correlativo SEPARATOR ', ') FROM cobros_programados_facturas x JOIN facturas f ON f.id = x.factura_id WHERE x.cobro_id = c.id) AS facturas
        FROM cobros_programados c
        JOIN clientes_factura cf ON cf.id = c.receptor_id
        LEFT JOIN usuarios u ON u.id = c.usuario_id
        WHERE c.cliente_id = ?
        ORDER BY c.estado = 'programado' DESC, c.programado_para DESC LIMIT 200");
    $st->execute([$cid]);
    $cobros = $st->fetchAll(PDO::FETCH_ASSOC);
}
$miCorreo = $pdo->prepare("SELECT correo FROM usuarios WHERE id = ?");
$miCorreo->execute([(int)USUARIO_ID]);
$miCorreo = (string)$miCorreo->fetchColumn();
$estados = ['programado' => ['Programado', 'info'], 'enviando' => ['Enviando', 'warning'], 'enviado' => ['Enviado', 'success'], 'error' => ['Error', 'danger'], 'cancelado' => ['Cancelado', 'muted']];
$manana = (new DateTime('tomorrow 08:00'))->format('Y-m-d\TH:i');

require_once '../../includes/templates/header.php';
?>

<div class="app-page-header">
    <div>
        <h1 class="app-page-title"><i class="bi bi-send-check me-2"></i>Cobros por correo</h1>
        <p class="app-page-sub">Envía al cliente sus facturas en PDF con un mensaje de cobro, ahora o programado (hora de Honduras).</p>
    </div>
    <button class="btn btn-primary" id="btnNuevoCobro" <?= $instalado ? '' : 'disabled' ?>><i class="bi bi-plus-lg me-1"></i> Nuevo cobro</button>
</div>

<?php if (!$instalado): ?>
    <div class="alert alert-warning">Falta instalar el módulo: ejecuta <code>sql/migraciones/2026-10-04_cobros_programados.sql</code>.</div>
<?php else: ?>
    <?php if (!$cuentaLista): ?>
        <div class="alert alert-warning d-flex align-items-center gap-2"><i class="bi bi-exclamation-triangle"></i>
            <div>La cuenta de correo <strong>Facturación</strong> no está lista. <a href="configuracion_correo?tab=facturacion" class="alert-link">Configúrala aquí</a> (servidor, usuario y contraseña) para poder enviar.</div></div>
    <?php endif; ?>

    <!-- Nuevo cobro -->
    <div class="app-card mb-3" id="cardNuevo" style="display:none">
        <div class="app-card-header"><span><i class="bi bi-envelope-plus me-1"></i> Nuevo cobro</span>
            <button type="button" class="btn-close" id="btnCerrarNuevo" aria-label="Cerrar"></button></div>
        <form class="app-card-body" id="formCobro" novalidate>
            <input type="hidden" name="accion" value="crear">
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">1. Cliente *</label>
                    <select class="form-select" name="receptor_id" id="cCliente" required>
                        <option value="">— Selecciona —</option>
                        <?php foreach ($clientes as $cl): ?><option value="<?= (int)$cl['id'] ?>" <?= $preRid === (int)$cl['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cl['nombre']) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-md-6"><label class="form-label">Tipo de mensaje</label>
                    <select class="form-select" name="tipo" id="cTipo">
                        <?php foreach (COBRO_TIPOS as $k => $t): ?><option value="<?= $k ?>"><?= $t ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-12">
                    <label class="form-label d-flex justify-content-between align-items-center">2. Facturas a adjuntar (PDF) *
                        <span class="small fw-normal"><a href="#" id="selConSaldo">Con saldo</a> · <a href="#" id="selNinguna">Ninguna</a></span></label>
                    <div class="border rounded" style="max-height:260px;overflow:auto">
                        <table class="table app-table mb-0">
                            <thead><tr><th style="width:1%"></th><th>Factura</th><th>Período</th><th>Emisión</th><th class="app-num">Total</th><th class="app-num">Saldo</th></tr></thead>
                            <tbody id="cFacturas"><tr><td colspan="6" class="text-center text-muted py-3">Selecciona un cliente.</td></tr></tbody>
                        </table>
                    </div>
                    <div class="small mt-1" id="cResumen"></div>
                </div>
                <div class="col-md-6"><label class="form-label">3. Para *</label><input class="form-control" name="para" id="cPara" placeholder="correo@cliente.com (varios separados por coma)" required></div>
                <div class="col-md-6"><label class="form-label">Con copia (CC)</label><input class="form-control" name="cc" placeholder="opcional"></div>
                <div class="col-12"><label class="form-label d-flex justify-content-between">4. Asunto y mensaje *
                    <a href="#" id="btnGenerar" class="small fw-normal"><i class="bi bi-magic"></i> Generar con la plantilla</a></label>
                    <input class="form-control mb-2" name="asunto" id="cAsunto" required>
                    <div id="cMensaje" contenteditable="true" class="form-control" style="min-height:260px;max-height:420px;overflow:auto;white-space:normal"></div>
                    <div class="form-text">Plantillas en <a href="configuracion_mensajes">Mensajes y cuentas de pago</a>. Se agregan el logo, el pie con los correos de respuesta y los PDF.</div></div>
                <div class="col-md-5"><label class="form-label">5. Fecha y hora de envío</label>
                    <input class="form-control" type="datetime-local" name="programado_para" value="<?= $manana ?>">
                    <div class="form-text">Hora de Honduras. Ahora son las <?= date('g:i a') ?>.</div></div>
                <div class="col-md-7 d-flex flex-wrap align-items-end gap-2 justify-content-md-end">
                    <div class="input-group" style="max-width:340px">
                        <input class="form-control" type="email" id="cParaPrueba" value="<?= htmlspecialchars($miCorreo) ?>" placeholder="tu correo">
                        <button type="button" class="btn btn-outline-secondary" data-modo="prueba" <?= $cuentaLista ? '' : 'disabled' ?>><i class="bi bi-eye me-1"></i>Enviar prueba</button>
                    </div>
                    <button type="button" class="btn btn-outline-primary" data-modo="ahora" <?= $cuentaLista ? '' : 'disabled' ?>><i class="bi bi-send me-1"></i>Enviar ahora</button>
                    <button type="button" class="btn btn-primary" data-modo="programar"><i class="bi bi-calendar-check me-1"></i>Programar</button>
                </div>
            </div>
        </form>
    </div>

    <!-- Lista -->
    <div class="app-card">
        <div class="app-card-header"><span><i class="bi bi-list-check me-1"></i> Cobros</span><span class="app-badge"><?= count($cobros) ?></span></div>
        <div class="table-responsive">
            <table class="table app-table mb-0">
                <thead><tr><th class="app-n">#</th><th>Cliente</th><th>Facturas</th><th>Para</th><th>Envío</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
                <tbody>
                    <?php if (!$cobros): ?><tr><td colspan="7" class="text-center text-muted py-4">Aún no hay cobros. Usa «Nuevo cobro».</td></tr><?php endif; ?>
                    <?php foreach ($cobros as $c): [$etq, $col] = $estados[$c['estado']] ?? [$c['estado'], 'muted']; ?>
                        <tr>
                            <td class="app-n"><?= (int)$c['id'] ?></td>
                            <td><a href="estado_cuenta?receptor_id=<?= (int)$c['receptor_id'] ?>"><?= htmlspecialchars($c['cliente']) ?></a>
                                <div class="small text-muted"><?= htmlspecialchars(mb_strimwidth($c['asunto'], 0, 60, '…')) ?></div></td>
                            <td class="small font-monospace"><?= htmlspecialchars($c['facturas'] ?? '') ?></td>
                            <td class="small"><?= htmlspecialchars($c['para']) ?><?= $c['cc'] ? '<div class="text-muted">CC: ' . htmlspecialchars($c['cc']) . '</div>' : '' ?></td>
                            <td class="small text-nowrap"><?= date('d/m/Y g:i a', strtotime($c['programado_para'])) ?>
                                <?= $c['enviado_en'] ? '<div class="text-success">Enviado ' . date('d/m g:i a', strtotime($c['enviado_en'])) . '</div>' : '' ?></td>
                            <td><span class="app-badge app-badge-<?= $col ?>"><?= $etq ?></span><?= (int)$c['prueba'] ? ' <span class="app-badge app-badge-warning">Prueba</span>' : '' ?>
                                <?= $c['error'] && $c['estado'] !== 'enviado' ? '<div class="small text-danger">' . htmlspecialchars(mb_strimwidth($c['error'], 0, 80, '…')) . '</div>' : '' ?></td>
                            <td class="text-end text-nowrap">
                                <?php if (in_array($c['estado'], ['programado', 'error'], true)): ?>
                                    <button class="btn btn-sm btn-outline-primary btn-accion" data-accion="enviar_ya" data-id="<?= (int)$c['id'] ?>" title="Enviar ahora"><i class="bi bi-send"></i></button>
                                    <button class="btn btn-sm btn-outline-secondary btn-reprogramar" data-id="<?= (int)$c['id'] ?>" data-fecha="<?= date('Y-m-d\TH:i', strtotime($c['programado_para'])) ?>" title="Cambiar fecha"><i class="bi bi-calendar-event"></i></button>
                                    <button class="btn btn-sm btn-outline-danger btn-accion" data-accion="cancelar" data-id="<?= (int)$c['id'] ?>" title="Cancelar"><i class="bi bi-x-lg"></i></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<script>
(function () {
    const L = n => 'L ' + Number(n).toLocaleString('es-HN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const meses = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    const card = document.getElementById('cardNuevo'), form = document.getElementById('formCobro');
    const $cli = document.getElementById('cCliente'), $tb = document.getElementById('cFacturas');
    const leer = async r => { const t = await r.text(); let d; try { d = JSON.parse(t); } catch (e) { throw new Error('Respuesta inesperada del servidor (' + r.status + ').'); } if (!d.success) throw new Error(d.error); return d; };

    const abrir = () => { card.style.display = ''; card.scrollIntoView({ behavior: 'smooth' }); };
    document.getElementById('btnNuevoCobro')?.addEventListener('click', abrir);
    document.getElementById('btnCerrarNuevo')?.addEventListener('click', () => card.style.display = 'none');

    const seleccionadas = () => [...$tb.querySelectorAll('input[type=checkbox]:checked')].map(i => i.value);
    function resumen() {
        const filas = [...$tb.querySelectorAll('input[type=checkbox]:checked')];
        const saldo = filas.reduce((s, i) => s + Number(i.dataset.saldo), 0);
        document.getElementById('cResumen').innerHTML = filas.length ? `<strong>${filas.length}</strong> factura(s) · saldo <strong>${L(saldo)}</strong>` : '<span class="text-muted">Ninguna factura seleccionada.</span>';
    }
    $tb.addEventListener('change', resumen);
    function marcar(fn) { $tb.querySelectorAll('input[type=checkbox]').forEach(i => i.checked = fn(i)); resumen(); }
    document.getElementById('selConSaldo')?.addEventListener('click', e => { e.preventDefault(); marcar(i => Number(i.dataset.saldo) > 0); });
    document.getElementById('selNinguna')?.addEventListener('click', e => { e.preventDefault(); marcar(() => false); });

    async function cargarFacturas() {
        if (!$cli.value) return;
        $tb.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">Cargando…</td></tr>';
        try {
            const d = await fetch('cobro_accion.php?facturas=' + $cli.value).then(leer);
            if (d.cliente.email && !document.getElementById('cPara').value) document.getElementById('cPara').value = d.cliente.email;
            $tb.innerHTML = d.facturas.length ? d.facturas.map(f => `<tr>
                <td><input class="form-check-input" type="checkbox" name="factura_ids[]" value="${f.id}" data-saldo="${f.saldo}" ${f.saldo > 0 ? 'checked' : ''}></td>
                <td class="font-monospace small text-nowrap"><a href="ver_factura?id=${f.id}" target="_blank">${esc(f.correlativo)}</a></td>
                <td class="small">${meses[f.pm]} ${f.pa}</td><td class="small text-nowrap">${f.fecha.split('-').reverse().join('/')}</td>
                <td class="app-num">${L(f.total)}</td><td class="app-num ${f.saldo > 0 ? 'text-danger fw-semibold' : 'text-success'}">${f.saldo > 0 ? L(f.saldo) : 'Pagada'}</td></tr>`).join('')
                : '<tr><td colspan="6" class="text-center text-muted py-3">Este cliente no tiene facturas en los últimos 24 meses.</td></tr>';
            resumen();
            if (seleccionadas().length) generar();
        } catch (err) { $tb.innerHTML = `<tr><td colspan="6" class="text-danger py-3">${esc(err.message)}</td></tr>`; }
    }
    $cli?.addEventListener('change', () => { document.getElementById('cPara').value = ''; cargarFacturas(); });

    // Asunto y mensaje con las plantillas de «Mensajes y cuentas de pago»
    async function generar() {
        const ids = seleccionadas().map(Number);
        if (!ids.length) return Swal.fire('Selecciona facturas', 'Marca al menos una factura para generar el mensaje.', 'info');
        const r = await fetch('procesar_accion_factura.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ accion: 'generar_mensaje', factura_ids: ids, tipo: document.getElementById('cTipo').value }) }).then(leer).catch(err => (Swal.fire('Error', err.message, 'error'), null));
        if (!r) return;
        document.getElementById('cAsunto').value = r.asunto || '';
        document.getElementById('cMensaje').innerHTML = (r.mensaje_html || '').replace(/\n/g, '<br>');
    }
    document.getElementById('btnGenerar')?.addEventListener('click', e => { e.preventDefault(); generar(); });
    document.getElementById('cTipo')?.addEventListener('change', () => seleccionadas().length && generar());

    form?.querySelectorAll('[data-modo]').forEach(b => b.addEventListener('click', async () => {
        const modo = b.dataset.modo;
        const fd = new FormData(form);
        fd.append('modo', modo);
        fd.append('mensaje_html', document.getElementById('cMensaje').innerHTML);
        if (modo === 'prueba') fd.append('para_prueba', document.getElementById('cParaPrueba').value);
        if (!fd.get('receptor_id') || !seleccionadas().length) return Swal.fire('Faltan datos', 'Elige el cliente y al menos una factura.', 'info');
        if (modo === 'ahora') {
            const ok = await Swal.fire({ title: '¿Enviar ahora al cliente?', text: 'Se enviará a ' + fd.get('para') + ' con ' + seleccionadas().length + ' factura(s) adjunta(s).', icon: 'question', showCancelButton: true, confirmButtonText: 'Enviar', cancelButtonText: 'Cancelar' });
            if (!ok.isConfirmed) return;
        }
        Swal.fire({ title: modo === 'programar' ? 'Generando PDF y programando…' : 'Generando PDF y enviando…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        fetch('cobro_accion.php', { method: 'POST', body: fd }).then(leer)
            .then(d => Swal.fire({ icon: 'success', title: d.message }).then(() => { if (modo !== 'prueba') location.reload(); }))
            .catch(err => Swal.fire('No se pudo', err.message, 'error'));
    }));

    const accion = (fd, msg) => fetch('cobro_accion.php', { method: 'POST', body: fd }).then(leer)
        .then(d => Swal.fire({ icon: 'success', title: d.message }).then(() => location.reload())).catch(err => Swal.fire('No se pudo', err.message, 'error'));
    document.querySelectorAll('.btn-accion').forEach(b => b.addEventListener('click', async () => {
        const txt = b.dataset.accion === 'cancelar' ? '¿Cancelar este cobro?' : '¿Enviar este cobro ahora?';
        if (!(await Swal.fire({ title: txt, icon: 'question', showCancelButton: true, confirmButtonText: 'Sí', cancelButtonText: 'No' })).isConfirmed) return;
        const fd = new FormData(); fd.append('accion', b.dataset.accion); fd.append('id', b.dataset.id);
        if (b.dataset.accion === 'enviar_ya') Swal.fire({ title: 'Enviando…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        accion(fd);
    }));
    document.querySelectorAll('.btn-reprogramar').forEach(b => b.addEventListener('click', async () => {
        const r = await Swal.fire({ title: 'Nueva fecha y hora', html: `<input type="datetime-local" id="nuevaFecha" class="form-control" value="${b.dataset.fecha}"><div class="small text-muted mt-2">Hora de Honduras</div>`,
            showCancelButton: true, confirmButtonText: 'Reprogramar', cancelButtonText: 'Cancelar', preConfirm: () => document.getElementById('nuevaFecha').value });
        if (!r.isConfirmed) return;
        const fd = new FormData(); fd.append('accion', 'reprogramar'); fd.append('id', b.dataset.id); fd.append('programado_para', r.value);
        accion(fd);
    }));

    if ($cli?.value) { abrir(); cargarFacturas(); }
})();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
