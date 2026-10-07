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
// Accesos directos (Historial de facturas, Facturas del contrato, Cuentas por cobrar):
//   ?receptor_id=X&facturas=1,2,3  → marca solo esas facturas · &contrato_id=N → las de ese contrato con saldo · &tipo=envio_factura
$preFacturas = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['facturas'] ?? '')))));
$preContrato = (int)($_GET['contrato_id'] ?? 0);
$preTipo = isset(COBRO_TIPOS[$_GET['tipo'] ?? '']) ? $_GET['tipo'] : '';
// &recibos=1,2 → envío de esos recibos · &plan=5,6 → recordatorio de esos pagos del plan
$preRecibos = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['recibos'] ?? '')))));
$prePlan = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['plan'] ?? '')))));
$preAnticipos = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['anticipos'] ?? '')))));
$extras = $instalado && cobrosExtrasDisponible($pdo);

$cobros = [];
if ($instalado) {
    $st = $pdo->prepare("
        SELECT c.*, cf.nombre AS cliente, u.nombre AS usuario,
               (SELECT GROUP_CONCAT(f.correlativo ORDER BY f.correlativo SEPARATOR ', ') FROM cobros_programados_facturas x JOIN facturas f ON f.id = x.factura_id WHERE x.cobro_id = c.id) AS facturas" . ($extras ? ",
               (SELECT GROUP_CONCAT(CONCAT('Recibo ', LPAD(r.numero_recibo, 5, '0')) ORDER BY r.numero_recibo SEPARATOR ', ') FROM cobros_programados_recibos x JOIN contratos_recibos r ON r.id = x.recibo_id WHERE x.cobro_id = c.id) AS recibos,
               (SELECT COUNT(*) FROM cobros_programados_plan x WHERE x.cobro_id = c.id) AS pagos_plan" : "") . "
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
        <p class="app-page-sub">Envía al cliente sus facturas o recibos en PDF, o un recordatorio de los pagos de su plan, ahora o programado (hora de Honduras).</p>
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
                        <?php foreach (COBRO_TIPOS as $k => $t): if (!$extras && in_array($k, ['recordatorio_pago', 'envio_recibo'], true)) continue; ?><option value="<?= $k ?>" <?= $preTipo === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-12" data-bloque="facturas">
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
                <?php if ($extras): ?>
                <div class="col-12" data-bloque="recibos" hidden>
                    <label class="form-label">2. Recibos a adjuntar (PDF) *</label>
                    <div class="border rounded" style="max-height:260px;overflow:auto">
                        <table class="table app-table mb-0">
                            <thead><tr><th style="width:1%"></th><th>Recibo</th><th>Fecha</th><th>Concepto</th><th class="app-num">Monto</th></tr></thead>
                            <tbody id="cRecibos"><tr><td colspan="5" class="text-center text-muted py-3">Selecciona un cliente.</td></tr></tbody>
                        </table>
                    </div>
                    <div class="form-text">Recibos de los contratos sin factura de este cliente. Se adjuntan en PDF.</div>
                </div>
                <div class="col-12" data-bloque="plan" hidden>
                    <label class="form-label d-flex justify-content-between align-items-center">2. Pagos del plan a recordar *
                        <span class="small fw-normal"><a href="#" id="selPlanVencidos">Vencidos y próximos 30 días</a> · <a href="#" id="selPlanNinguno">Ninguno</a></span></label>
                    <div class="border rounded" style="max-height:260px;overflow:auto">
                        <table class="table app-table mb-0">
                            <thead><tr><th style="width:1%"></th><th>Fecha de pago</th><th>Concepto</th><th>Contrato</th><th class="app-num">Total</th><th>Estado</th></tr></thead>
                            <tbody id="cPlan"><tr><td colspan="6" class="text-center text-muted py-3">Selecciona un cliente.</td></tr></tbody>
                        </table>
                    </div>
                    <div class="form-text">Pagos pendientes del plan de pagos de sus contratos (con recibo o con factura). El recordatorio no lleva adjuntos.</div>
                </div>
                <?php endif; ?>
                <div class="col-md-6"><label class="form-label">3. Para *</label><input class="form-control" name="para" id="cPara" placeholder="correo@cliente.com (varios separados por coma)" required></div>
                <div class="col-md-6"><label class="form-label">Con copia (CC)</label><input class="form-control" name="cc" id="cCc" placeholder="opcional"><div class="form-text" id="cCcInfo">Se llena con los correos de «Responder a» de la cuenta Facturación y los contactos del cliente marcados «Copiar en cobros». Puedes editarlo; varios separados por coma.</div></div>
                <div class="col-12"><label class="form-label d-flex justify-content-between">4. Asunto y mensaje *
                    <a href="#" id="btnGenerar" class="small fw-normal"><i class="bi bi-magic"></i> Generar con la plantilla</a></label>
                    <input class="form-control mb-2" name="asunto" id="cAsunto" required>
                    <textarea id="cMensaje" class="form-control" rows="12"></textarea>
                    <div class="form-text">Plantillas en <a href="configuracion_mensajes">Mensajes y cuentas de pago</a>. Se agregan el logo, el pie con los correos de respuesta y los PDF.</div></div>
                <div class="col-md-5"><label class="form-label">5. Fecha y hora de envío</label>
                    <input class="form-control" type="datetime-local" name="programado_para" value="<?= $manana ?>">
                    <div class="form-text">Hora de Honduras. Ahora son las <?= date('g:i a') ?>.</div></div>
                <div class="col-md-7 d-flex flex-wrap align-items-end gap-2 justify-content-md-end">
                    <button type="button" class="btn btn-outline-dark" id="btnPrevia"><i class="bi bi-window me-1"></i>Vista previa</button>
                    <div class="input-group" style="max-width:340px">
                        <input class="form-control" type="email" id="cParaPrueba" value="<?= htmlspecialchars($miCorreo) ?>" placeholder="tu correo">
                        <button type="button" class="btn btn-outline-secondary" data-modo="prueba" <?= $cuentaLista ? '' : 'disabled' ?>><i class="bi bi-send-check me-1"></i>Enviar prueba</button>
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
            <table data-paginar class="table app-table mb-0">
                <thead><tr><th class="app-n">#</th><th>Cliente</th><th>Documentos</th><th>Para</th><th>Envío</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
                <tbody>
                    <?php if (!$cobros): ?><tr><td colspan="7" class="text-center text-muted py-4">Aún no hay cobros. Usa «Nuevo cobro».</td></tr><?php endif; ?>
                    <?php foreach ($cobros as $c): [$etq, $col] = $estados[$c['estado']] ?? [$c['estado'], 'muted']; ?>
                        <tr>
                            <td class="app-n"><?= (int)$c['id'] ?></td>
                            <td><a href="estado_cuenta?receptor_id=<?= (int)$c['receptor_id'] ?>"><?= htmlspecialchars($c['cliente']) ?></a>
                                <div class="small text-muted"><?= htmlspecialchars(mb_strimwidth($c['asunto'], 0, 60, '…')) ?></div></td>
                            <td class="small"><span class="font-monospace"><?= htmlspecialchars(trim(($c['facturas'] ?? '') . (($c['facturas'] ?? '') && ($c['recibos'] ?? '') ? ', ' : '') . ($c['recibos'] ?? ''))) ?></span>
                                <?php if (!empty($c['pagos_plan'])): ?><div class="text-muted"><i class="bi bi-calendar2-check"></i> Recordatorio de <?= (int)$c['pagos_plan'] ?> pago<?= $c['pagos_plan'] > 1 ? 's' : '' ?> del plan</div><?php endif; ?></td>
                            <td class="small"><?= htmlspecialchars($c['para']) ?><?= $c['cc'] ? '<div class="text-muted">CC: ' . htmlspecialchars($c['cc']) . '</div>' : '' ?></td>
                            <td class="small text-nowrap"><?= date('d/m/Y g:i a', strtotime($c['programado_para'])) ?>
                                <?= $c['enviado_en'] ? '<div class="text-success">Enviado ' . date('d/m g:i a', strtotime($c['enviado_en'])) . '</div>' : '' ?></td>
                            <td><span class="app-badge app-badge-<?= $col ?>"><?= $etq ?></span><?= (int)$c['prueba'] ? ' <span class="app-badge app-badge-warning">Prueba</span>' : '' ?>
                                <?= $c['error'] && $c['estado'] !== 'enviado' ? '<div class="small text-danger">' . htmlspecialchars(mb_strimwidth($c['error'], 0, 80, '…')) . '</div>' : '' ?></td>
                            <td class="text-end text-nowrap">
                                <button class="btn btn-sm btn-outline-secondary btn-ver" data-id="<?= (int)$c['id'] ?>" title="Ver lo que se envió: correo, PDF e intentos"><i class="bi bi-eye"></i></button>
                                <?php if (in_array($c['estado'], ['programado', 'error'], true)): ?>
                                    <button class="btn btn-sm btn-outline-primary btn-accion" data-accion="enviar_ya" data-id="<?= (int)$c['id'] ?>" title="Enviar ahora"><i class="bi bi-send"></i></button>
                                    <button class="btn btn-sm btn-outline-secondary btn-editar" data-id="<?= (int)$c['id'] ?>" title="Editar destinatarios, mensaje y fecha"><i class="bi bi-pencil"></i></button>
                                    <button class="btn btn-sm btn-outline-danger btn-accion" data-accion="cancelar" data-id="<?= (int)$c['id'] ?>" title="Cancelar"><i class="bi bi-x-lg"></i></button>
                                <?php endif; ?>
                                <?php if (in_array($c['estado'], ['enviado', 'cancelado', 'error'], true)): ?>
                                    <button class="btn btn-sm btn-outline-success btn-reenviar" data-id="<?= (int)$c['id'] ?>" data-para="<?= htmlspecialchars($c['para']) ?>" data-cc="<?= htmlspecialchars((string)$c['cc']) ?>" data-prueba="<?= (int)$c['prueba'] ?>" title="Reenviar (mismo mensaje y mismos PDF)"><i class="bi bi-arrow-repeat"></i></button>
                                <?php endif; ?>
                                <?php if (((int)$c['prueba'] && $c['estado'] !== 'enviando') || in_array($c['estado'], ['cancelado', 'error'], true)): ?>
                                    <button class="btn btn-sm btn-outline-danger btn-accion" data-accion="eliminar" data-id="<?= (int)$c['id'] ?>" title="Eliminar"><i class="bi bi-trash"></i></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Vista previa del correo (no envía nada) -->
    <div class="modal fade" id="mPrevia" tabindex="-1" style="z-index:1065"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-window me-1"></i> Vista previa del correo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <dl class="row small mb-2" id="pDatos"></dl>
            <iframe id="pHtml" sandbox="" title="Vista previa" style="width:100%;height:600px;border:1px solid var(--bs-border-color);border-radius:8px;background:#f1f5f9"></iframe>
            <div class="form-text">Así le llega al cliente (con el logo y pie de la cuenta Facturación). Los PDF de las facturas van adjuntos.</div>
        </div>
    </div></div></div>

    <!-- Ver cobro -->
    <div class="modal fade" id="mVer" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-envelope-open me-1"></i> Cobro <span id="vNum"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div class="row g-3">
                <div class="col-lg-4">
                    <dl class="small mb-3" id="vDatos"></dl>
                    <div class="fw-semibold small mb-1"><i class="bi bi-paperclip"></i> PDF adjuntos</div><div id="vAdjuntos" class="mb-3"></div>
                    <div class="fw-semibold small mb-1"><i class="bi bi-clock-history"></i> Intentos de envío</div><div id="vEnvios"></div>
                </div>
                <div class="col-lg-8">
                    <div class="small text-muted mb-1">Así se ve el correo (con el logo y el pie de la cuenta Facturación actuales)</div>
                    <iframe id="vHtml" sandbox="" style="width:100%;height:560px;border:1px solid var(--bs-border-color);border-radius:8px;background:#f1f5f9"></iframe>
                </div>
            </div>
        </div>
    </div></div></div>

    <!-- Editar cobro -->
    <div class="modal fade" id="mEditar" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
        <form id="fEditar">
            <div class="modal-header"><h5 class="modal-title"><i class="bi bi-pencil me-1"></i> Editar cobro <span id="eNum"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="accion" value="editar"><input type="hidden" name="id" id="eId">
                <div class="row g-2">
                    <div class="col-md-6"><label class="form-label">Para *</label><input class="form-control" name="para" id="ePara" required><div class="form-text">Varios separados por coma.</div></div>
                    <div class="col-md-6"><label class="form-label">Con copia (CC)</label><input class="form-control" name="cc" id="eCc"></div>
                    <div class="col-md-8"><label class="form-label">Asunto *</label><input class="form-control" name="asunto" id="eAsunto" maxlength="255" required></div>
                    <div class="col-md-4"><label class="form-label">Envío (hora de Honduras)</label><input class="form-control" type="datetime-local" name="programado_para" id="eFecha" required></div>
                    <div class="col-12"><label class="form-label">Mensaje *</label><textarea id="eMensaje" class="form-control" rows="10"></textarea>
                        <div class="form-text">Los PDF adjuntos no cambian. Para otras facturas, cancela este cobro y crea uno nuevo.</div></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-dark me-auto" id="ePrevia"><i class="bi bi-window me-1"></i> Vista previa</button><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button><button class="btn btn-primary" type="submit"><i class="bi bi-floppy me-1"></i> Guardar cambios</button></div>
        </form>
    </div></div></div>
<?php endif; ?>

<!-- Editor de texto del mensaje (TinyMCE, licencia GPL, servido desde jsDelivr) -->
<script src="https://cdn.jsdelivr.net/npm/tinymce@7.6.0/tinymce.min.js" referrerpolicy="origin"></script>
<script>
    // Solo los formatos que conserva el correo (includes/cobros.php → cobroLimpiarHtml): negrita, cursiva, subrayado y listas.
    tinymce.init({
        selector: '#cMensaje, #eMensaje', license_key: 'gpl', menubar: false, branding: false, promotion: false, statusbar: false,
        language: 'es', language_url: 'https://cdn.jsdelivr.net/npm/tinymce-i18n@24.10.21/langs7/es.js',
        plugins: 'lists', toolbar: 'undo redo | bold italic underline | bullist numlist | removeformat',
        formats: { underline: { inline: 'u' } }, valid_elements: 'p,br,strong/b,em/i,u,ul,ol,li,div,span',
        height: 360, content_style: 'body { font-family: Segoe UI, Helvetica, Arial, sans-serif; font-size: 14px; line-height: 1.6; }',
    });
    // Leer y escribir el mensaje (con o sin el editor ya cargado)
    window.setMensaje = (id, html) => {
        document.getElementById(id).value = html;                 // por si el editor aún no existe (lo toma al iniciar)
        const ed = tinymce.get(id);
        if (ed && ed.initialized) ed.setContent(html); else if (ed) ed.once('init', () => ed.setContent(html));
    };
    window.getMensaje = id => { const ed = tinymce.get(id); return ed ? ed.getContent() : document.getElementById(id).value; };
</script>
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

    let pre = <?= json_encode(['ids' => $preFacturas, 'contrato' => $preContrato, 'recibos' => $preRecibos, 'plan' => $prePlan, 'anticipos' => $preAnticipos]) ?>;   // preselección del acceso directo (solo la primera carga)
    const $tipo = document.getElementById('cTipo'), $rec = document.getElementById('cRecibos'), $plan = document.getElementById('cPlan');
    // Qué lista usa cada tipo: facturas (saldo/envío), recibos o pagos del plan
    const bloque = () => ({ envio_recibo: 'recibos', recordatorio_pago: 'plan' }[$tipo.value] || 'facturas');
    const tablaActiva = () => ({ facturas: $tb, recibos: $rec, plan: $plan }[bloque()]);
    function mostrarBloque() {
        document.querySelectorAll('[data-bloque]').forEach(b => b.hidden = b.dataset.bloque !== bloque());
    }
    const marcarInicial = f => pre.ids.length ? pre.ids.includes(+f.id) : (pre.contrato ? f.contrato_id == pre.contrato && f.saldo > 0 : f.saldo > 0);
    const seleccionadas = () => [...(tablaActiva() || $tb).querySelectorAll('input[type=checkbox]:checked')].map(i => i.value);
    function resumen() {
        const filas = [...$tb.querySelectorAll('input[type=checkbox]:checked')];
        const saldo = filas.reduce((s, i) => s + Number(i.dataset.saldo), 0);
        document.getElementById('cResumen').innerHTML = filas.length ? `<strong>${filas.length}</strong> factura(s) · saldo <strong>${L(saldo)}</strong>` : '<span class="text-muted">Ninguna factura seleccionada.</span>';
    }
    $tb.addEventListener('change', resumen);

    // CC: contactos generales del cliente + los del proyecto/contrato de las facturas marcadas.
    // Si el usuario escribe en el campo, ya no se reemplaza automáticamente.
    // Siempre en copia: los correos de «Responder a» de la cuenta Facturación (Configuración → Correo)
    const ccBase = <?= json_encode(array_values(array_filter(correoLista((string)($cfgFact['responder_a'] ?? ''))))) ?>;
    let contactos = [], ccManual = false;
    const $cc = document.getElementById('cCc');
    $cc.addEventListener('input', () => ccManual = true);
    function llenarCc() {
        if (ccManual) return;
        const ks = new Set([...$tb.querySelectorAll('input[type=checkbox]:checked')].map(i => i.dataset.contrato).filter(Boolean));
        const correos = [...ccBase, ...contactos.filter(c => !c.contrato_id || ks.has(String(c.contrato_id))).map(c => c.email)];
        $cc.value = [...new Set(correos.map(c => c.trim().toLowerCase()).filter(Boolean))].join(', ');
    }
    llenarCc();
    $tb.addEventListener('change', llenarCc);
    function marcar(fn) { $tb.querySelectorAll('input[type=checkbox]').forEach(i => i.checked = fn(i)); resumen(); llenarCc(); }
    document.getElementById('selConSaldo')?.addEventListener('click', e => { e.preventDefault(); marcar(i => Number(i.dataset.saldo) > 0); });
    document.getElementById('selNinguna')?.addEventListener('click', e => { e.preventDefault(); marcar(() => false); });

    async function cargarFacturas() {
        if (!$cli.value) return;
        $tb.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">Cargando…</td></tr>';
        try {
            const d = await fetch('cobro_accion.php?facturas=' + $cli.value).then(leer);
            if (d.cliente.email && !document.getElementById('cPara').value) document.getElementById('cPara').value = d.cliente.email;
            contactos = d.contactos || [];
            ccManual = false;
            $tb.innerHTML = d.facturas.length ? d.facturas.map(f => `<tr>
                <td><input class="form-check-input" type="checkbox" name="factura_ids[]" value="${f.id}" data-saldo="${f.saldo}" data-contrato="${f.contrato_id || ''}" ${marcarInicial(f) ? 'checked' : ''}></td>
                <td class="font-monospace small text-nowrap"><a href="ver_factura?id=${f.id}" target="_blank">${esc(f.correlativo)}</a></td>
                <td class="small">${meses[f.pm]} ${f.pa}</td><td class="small text-nowrap">${f.fecha.split('-').reverse().join('/')}</td>
                <td class="app-num">${L(f.total)}</td><td class="app-num ${f.saldo > 0 ? 'text-danger fw-semibold' : 'text-success'}">${f.saldo > 0 ? L(f.saldo) : 'Pagada'}</td></tr>`).join('')
                : '<tr><td colspan="6" class="text-center text-muted py-3">Este cliente no tiene facturas en los últimos 24 meses.</td></tr>';
            const SEM = { vencido: ['#fee2e2', '#991b1b'], facturado: ['#dbeafe', '#1e40af'] };
            if ($rec) $rec.innerHTML = (d.recibos || []).length ? d.recibos.map(r => `<tr>
                <td><input class="form-check-input" type="checkbox" name="${r.anticipo ? 'anticipo_ids[]' : 'recibo_ids[]'}" value="${r.id}" data-ant="${r.anticipo ? 1 : 0}" ${(r.anticipo ? pre.anticipos : pre.recibos).includes(+r.id) ? 'checked' : ''}></td>
                <td class="font-monospace small"><a href="recibo_pdf?${r.anticipo ? 'anticipo' : 'id'}=${r.id}" target="_blank">${r.anticipo ? r.numero_recibo : String(r.numero_recibo).padStart(5, '0')}</a>${r.anticipo ? '<div class="text-muted" style="font-size:.7rem">Anticipo</div>' : ''}</td>
                <td class="small text-nowrap">${r.fecha.split('-').reverse().join('/')}</td><td class="small">${esc(r.concepto)}</td><td class="app-num">${L(r.monto)}</td></tr>`).join('')
                : '<tr><td colspan="5" class="text-center text-muted py-3">Este cliente no tiene recibos.</td></tr>';
            if ($plan) $plan.innerHTML = (d.plan || []).length ? d.plan.map(l => {
                const dd = -l.dias, txt = l.estado === 'facturado' ? 'Facturado, por cobrar' : (l.dias > 0 ? `Vencido hace ${l.dias} d` : (dd === 0 ? 'Vence hoy' : `En ${dd} d`));
                const [bg, fg] = SEM[l.estado] || (dd <= 3 ? ['#ffedd5', '#9a3412'] : dd <= 7 ? ['#fef9c3', '#854d0e'] : ['#f1f5f9', '#475569']);
                const marcar = pre.plan.length ? pre.plan.includes(l.id) : (pre.recibos.length ? false : l.dias > -30);
                return `<tr><td><input class="form-check-input" type="checkbox" name="plan_ids[]" value="${l.id}" data-dias="${l.dias}" ${marcar ? 'checked' : ''}></td>
                <td class="small text-nowrap">${l.fecha.split('-').reverse().join('/')}</td><td class="small">${esc(l.concepto)}</td>
                <td class="small"><a href="facturas_contrato?contrato_id=${l.contrato_id}" target="_blank">#${l.contrato_id}</a></td>
                <td class="app-num">${L(l.total)}</td><td><span class="badge rounded-pill" style="background:${bg};color:${fg}">${txt}</span></td></tr>`;
            }).join('') : '<tr><td colspan="6" class="text-center text-muted py-3">Este cliente no tiene pagos pendientes en un plan de pagos.</td></tr>';
            pre = { ids: [], contrato: 0, recibos: [], plan: [], anticipos: [] };
            resumen();
            llenarCc();
            if (seleccionadas().length) generar();
        } catch (err) { $tb.innerHTML = `<tr><td colspan="6" class="text-danger py-3">${esc(err.message)}</td></tr>`; }
    }
    $cli?.addEventListener('change', () => { document.getElementById('cPara').value = ''; cargarFacturas(); });

    // Asunto y mensaje con las plantillas de «Mensajes y cuentas de pago»
    async function generar() {
        const ids = seleccionadas().map(Number);
        if (bloque() !== 'facturas') {
            if (!ids.length) return Swal.fire('Falta seleccionar', bloque() === 'recibos' ? 'Marca al menos un recibo.' : 'Marca al menos un pago del plan.', 'info');
            const fd = new FormData();
            fd.append('accion', 'generar_mensaje'); fd.append('receptor_id', $cli.value); fd.append('tipo', $tipo.value);
            [...tablaActiva().querySelectorAll('input[type=checkbox]:checked')].forEach(i => fd.append(i.dataset.ant === '1' ? 'anticipo_ids[]' : 'ids[]', i.value));
            const r = await fetch('cobro_accion.php', { method: 'POST', body: fd }).then(leer).catch(err => (Swal.fire('Error', err.message, 'error'), null));
            if (!r) return;
            document.getElementById('cAsunto').value = r.asunto || '';
            setMensaje('cMensaje', r.mensaje_html || '');
            return;
        }
        if (!ids.length) return Swal.fire('Selecciona facturas', 'Marca al menos una factura para generar el mensaje.', 'info');
        const r = await fetch('procesar_accion_factura.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ accion: 'generar_mensaje', factura_ids: ids, tipo: document.getElementById('cTipo').value }) }).then(leer).catch(err => (Swal.fire('Error', err.message, 'error'), null));
        if (!r) return;
        document.getElementById('cAsunto').value = r.asunto || '';
        // mensaje_html ya trae <br> (nl2br): los saltos de línea que quedan son solo espacio en HTML; no se convierten otra vez
        setMensaje('cMensaje', (r.mensaje_html || '').replace(/\r?\n/g, ''));
    }
    document.getElementById('btnGenerar')?.addEventListener('click', e => { e.preventDefault(); generar(); });
    $tipo?.addEventListener('change', () => { mostrarBloque(); if (seleccionadas().length) generar(); });
    mostrarBloque();
    $rec?.addEventListener('change', () => generar());
    $plan?.addEventListener('change', () => seleccionadas().length && generar());
    const marcarPlan = fn => { $plan.querySelectorAll('input[type=checkbox]').forEach(i => i.checked = fn(+i.dataset.dias)); if (seleccionadas().length) generar(); };
    document.getElementById('selPlanVencidos')?.addEventListener('click', e => { e.preventDefault(); marcarPlan(d => d > -30); });
    document.getElementById('selPlanNinguno')?.addEventListener('click', e => { e.preventDefault(); marcarPlan(() => false); });

    form?.querySelectorAll('[data-modo]').forEach(b => b.addEventListener('click', async () => {
        const modo = b.dataset.modo;
        const fd = new FormData(form);
        fd.append('modo', modo);
        fd.append('mensaje_html', getMensaje('cMensaje'));
        if (modo === 'prueba') fd.append('para_prueba', document.getElementById('cParaPrueba').value);
        if (!fd.get('receptor_id') || !seleccionadas().length) return Swal.fire('Faltan datos', 'Elige el cliente y al menos ' + ({ facturas: 'una factura', recibos: 'un recibo', plan: 'un pago del plan' }[bloque()]) + '.', 'info');
        if (modo === 'ahora') {
            const ok = await Swal.fire({ title: '¿Enviar ahora al cliente?', text: 'Se enviará a ' + fd.get('para') + ' con ' + seleccionadas().length + ' ' + ({ facturas: 'factura(s) adjunta(s)', recibos: 'recibo(s) adjunto(s)', plan: 'pago(s) del plan' }[bloque()]) + '.', icon: 'question', showCancelButton: true, confirmButtonText: 'Enviar', cancelButtonText: 'Cancelar' });
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
        const txt = { cancelar: '¿Cancelar este cobro?', eliminar: '¿Eliminar este cobro y sus PDF?', enviar_ya: '¿Enviar este cobro ahora?' }[b.dataset.accion];
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

    // ── Vista previa del correo (redacción y edición) ──
    async function previa({ para, cc, asunto, html, receptor, ids, recibos, anticipos }) {
        const fd = new FormData();
        fd.append('accion', 'previsualizar'); fd.append('receptor_id', receptor || ''); fd.append('mensaje_html', html); fd.append('asunto', asunto);
        (ids || []).forEach(i => fd.append('factura_ids[]', i));
        (recibos || []).forEach(i => fd.append('recibo_ids[]', i));
        (anticipos || []).forEach(i => fd.append('anticipo_ids[]', i));
        try {
            const d = await fetch('cobro_accion.php', { method: 'POST', body: fd }).then(leer);
            const fila = (k, v) => `<dt class="col-sm-2 text-muted fw-normal">${k}</dt><dd class="col-sm-10 mb-1">${v || '<span class="text-muted">—</span>'}</dd>`;
            document.getElementById('pDatos').innerHTML = fila('Para', esc(para)) + fila('CC', esc(cc)) + fila('Asunto', '<strong>' + esc(asunto) + '</strong>')
                + fila('Adjuntos', d.adjuntos.length ? d.adjuntos.map(n => `<span class="badge text-bg-light border me-1"><i class="bi bi-file-earmark-pdf text-danger"></i> ${esc(n)}</span>`).join('') : '');
            document.getElementById('pHtml').srcdoc = d.html;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('mPrevia')).show();
        } catch (err) { Swal.fire('No se pudo', err.message, 'error'); }
    }
    document.getElementById('btnPrevia')?.addEventListener('click', () => previa({
        para: document.getElementById('cPara').value, cc: document.getElementById('cCc').value, asunto: document.getElementById('cAsunto').value,
        html: getMensaje('cMensaje'), receptor: $cli.value, ids: bloque() === 'facturas' ? seleccionadas() : [],
        recibos: bloque() === 'recibos' ? [...$rec.querySelectorAll('input[data-ant="0"]:checked')].map(i => i.value) : [],
        anticipos: bloque() === 'recibos' ? [...$rec.querySelectorAll('input[data-ant="1"]:checked')].map(i => i.value) : [],
    }));
    let editando = null;   // cobro abierto en «Editar» (para la vista previa)
    document.getElementById('ePrevia')?.addEventListener('click', () => previa({
        para: document.getElementById('ePara').value, cc: document.getElementById('eCc').value, asunto: document.getElementById('eAsunto').value,
        html: getMensaje('eMensaje'), receptor: editando?.receptor_id, ids: editando?.facturas || [], recibos: editando?.recibos || [],
    }));

    // ── Ver lo que se envió ──
    const fmt = d => d ? new Date(d.replace(' ', 'T')).toLocaleString('es-HN', { day: '2-digit', month: '2-digit', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : '—';
    const estadosTxt = <?= json_encode(array_map(fn($e) => $e[0], $estados), JSON_UNESCAPED_UNICODE) ?>;
    const detalle = id => fetch('cobro_accion.php?ver=' + id).then(leer);
    document.querySelectorAll('.btn-ver').forEach(b => b.addEventListener('click', async () => {
        try {
            const d = await detalle(b.dataset.id), c = d.cobro;
            document.getElementById('vNum').textContent = '#' + c.id + (+c.prueba ? ' · prueba' : '');
            const fila = (k, v) => `<dt class="text-muted fw-normal">${k}</dt><dd class="mb-2">${v}</dd>`;
            document.getElementById('vDatos').innerHTML = fila('Cliente', esc(c.cliente)) + fila('Para', esc(c.para)) + (c.cc ? fila('CC', esc(c.cc)) : '')
                + fila('Asunto', esc((+c.prueba ? '[PRUEBA] ' : '') + c.asunto)) + fila('Estado', esc(estadosTxt[c.estado] || c.estado) + (c.error ? `<div class="text-danger">${esc(c.error)}</div>` : ''))
                + fila('Programado', fmt(c.programado_para)) + fila('Enviado', fmt(c.enviado_en)) + fila('Creado', fmt(c.creado_en));
            document.getElementById('vAdjuntos').innerHTML = d.adjuntos.length ? d.adjuntos.map(a => a.existe
                ? `<a class="btn btn-sm btn-outline-secondary me-1 mb-1" target="_blank" href="cobro_accion.php?pdf=${c.id}&${a.recibo_id ? 'recibo=' + a.recibo_id : (a.anticipo_id ? 'anticipo=' + a.anticipo_id : 'factura=' + a.factura_id)}"><i class="bi bi-file-earmark-pdf text-danger"></i> ${esc(a.etiqueta)}</a>`
                : `<span class="badge text-bg-light border me-1">${esc(a.etiqueta)} (no disponible)</span>`).join('') : '<span class="text-muted small">Sin adjuntos.</span>';
            document.getElementById('vEnvios').innerHTML = d.envios.length ? '<ul class="list-unstyled small mb-0">' + d.envios.map(e =>
                `<li class="mb-1"><i class="bi ${e.estado === 'enviado' ? 'bi-check-circle text-success' : 'bi-x-circle text-danger'}"></i> ${fmt(e.creado_en)} → ${esc(e.destinatario)}${e.error ? `<div class="text-danger">${esc(e.error)}</div>` : ''}</li>`).join('') + '</ul>'
                : '<span class="text-muted small">Aún no se ha intentado enviar.</span>';
            document.getElementById('vHtml').srcdoc = d.html;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('mVer')).show();
        } catch (err) { Swal.fire('No se pudo', err.message, 'error'); }
    }));

    // ── Editar (solo los que aún no se envían) ──
    document.querySelectorAll('.btn-editar').forEach(b => b.addEventListener('click', async () => {
        try {
            const det = await detalle(b.dataset.id), c = det.cobro;
            editando = { receptor_id: c.receptor_id, facturas: det.adjuntos.filter(x => x.factura_id).map(x => x.factura_id), recibos: det.adjuntos.filter(x => x.recibo_id).map(x => x.recibo_id) };
            document.getElementById('eNum').textContent = '#' + c.id;
            document.getElementById('eId').value = c.id;
            document.getElementById('ePara').value = c.para;
            document.getElementById('eCc').value = c.cc || '';
            document.getElementById('eAsunto').value = c.asunto;
            document.getElementById('eFecha').value = c.programado_para.slice(0, 16).replace(' ', 'T');
            setMensaje('eMensaje', c.mensaje_html.replace(/\r?\n/g, ''));
            bootstrap.Modal.getOrCreateInstance(document.getElementById('mEditar')).show();
        } catch (err) { Swal.fire('No se pudo', err.message, 'error'); }
    }));
    document.getElementById('fEditar')?.addEventListener('submit', e => {
        e.preventDefault();
        const fd = new FormData(e.target);
        fd.append('mensaje_html', getMensaje('eMensaje'));
        accion(fd);
    });

    // ── Reenviar: copia con el mismo mensaje y los mismos PDF ──
    document.querySelectorAll('.btn-reenviar').forEach(b => b.addEventListener('click', async () => {
        const r = await Swal.fire({
            title: 'Reenviar cobro #' + b.dataset.id,
            html: `<div class="text-start small">
                <label class="form-label mb-1">Para</label><input id="rPara" class="form-control mb-2" value="${esc(b.dataset.para)}">
                <label class="form-label mb-1">Con copia (CC)</label><input id="rCc" class="form-control mb-2" value="${esc(b.dataset.cc)}">
                <div class="form-check"><input class="form-check-input" type="checkbox" id="rPrueba" ${+b.dataset.prueba ? 'checked' : ''}>
                <label class="form-check-label" for="rPrueba">Enviar como prueba (asunto con [PRUEBA], sin CC)</label></div>
                <div class="text-muted mt-2">Se envía ahora mismo, con el mismo mensaje y los mismos PDF. Queda como un cobro nuevo en la lista.</div></div>`,
            showCancelButton: true, confirmButtonText: 'Reenviar', cancelButtonText: 'Cancelar', focusConfirm: false,
            preConfirm: () => ({ para: document.getElementById('rPara').value.trim(), cc: document.getElementById('rCc').value.trim(), prueba: document.getElementById('rPrueba').checked })
        });
        if (!r.isConfirmed) return;
        if (!r.value.para) return Swal.fire('Falta el destinatario', 'Escribe al menos un correo en «Para».', 'info');
        const fd = new FormData();
        fd.append('accion', 'reenviar'); fd.append('id', b.dataset.id); fd.append('para', r.value.para); fd.append('cc', r.value.cc);
        if (r.value.prueba) fd.append('prueba', '1');
        Swal.fire({ title: 'Enviando…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        accion(fd);
    }));

    if ($cli?.value) { abrir(); cargarFacturas(); }
})();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
