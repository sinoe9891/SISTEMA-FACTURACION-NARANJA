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

require_once '../../includes/cobros_listado.php';
$listado = $instalado ? cobrosListado($pdo, $cid, $_GET) : ['cobros' => [], 'total' => 0, 'pagina' => 1, 'paginas' => 1, 'por_pagina' => 25];
$cobros = $listado['cobros'];
$miCorreo = $pdo->prepare("SELECT correo FROM usuarios WHERE id = ?");
$miCorreo->execute([(int)USUARIO_ID]);
$miCorreo = (string)$miCorreo->fetchColumn();
$estados = ['programado' => ['Programado', 'info'], 'enviando' => ['Enviando', 'warning'], 'enviado' => ['Enviado', 'success'], 'error' => ['Error', 'danger'], 'cancelado' => ['Cancelado', 'muted']];
$manana = (new DateTime('tomorrow 08:00'))->format('Y-m-d\TH:i');

if (isset($_GET['ajax'])) {
    ob_start();
    require __DIR__ . '/includes/cobros_filas.php';
    $listado['html'] = ob_get_clean();
    unset($listado['cobros']);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true] + $listado, JSON_UNESCAPED_UNICODE);
    exit;
}

require_once '../../includes/templates/header.php';
?>

<style>
    /* El formulario debe ser el contenedor flex del modal para que solo el cuerpo se desplace. */
    #mEditar .modal-dialog, #mPrevia .modal-dialog {
        height: calc(100vh - 1.5rem);
        height: calc(100dvh - 1.5rem);
        margin-top: .75rem;
        margin-bottom: .75rem;
    }
    #mEditar .modal-body, #mPrevia .modal-body { min-height: 0; overflow-y: auto; }
    #mEditar .modal-header, #mEditar .modal-footer { flex-shrink: 0; }
    #mEditar .tox-tinymce { height: clamp(180px, 32vh, 360px) !important; }
    #mEditar .modal-footer { gap: .4rem; }
    #eDocs label { max-width: 100%; overflow-wrap: anywhere; }
    #pDatos dd { overflow-wrap: anywhere; }
    #pDatos .badge { white-space: normal; text-align: left; }
    #pHtml { height: clamp(220px, 55vh, 600px); }
    @media (max-width: 575.98px) {
        #mEditar .modal-dialog, #mPrevia .modal-dialog { height: 100vh; height: 100dvh; margin: 0; }
        #mEditar .modal-footer .btn { flex: 1 1 auto; margin: 0 !important; }
    }
</style>

<div class="app-page-header">
    <div>
        <h1 class="app-page-title"><i class="bi bi-send-check me-2"></i>Cobros por correo</h1>
        <p class="app-page-sub">Envía al cliente sus facturas o recibos en PDF (con los documentos de la empresa que quieras, como la Constancia del SAR), un cobro del saldo pendiente o un recordatorio de los pagos de su plan, ahora o programado (hora de Honduras).</p>
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
                    <select class="form-select" name="receptor_id" id="cCliente" required data-buscar>
                        <option value="">— Selecciona —</option>
                        <?php foreach ($clientes as $cl): ?><option value="<?= (int)$cl['id'] ?>" <?= $preRid === (int)$cl['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cl['nombre']) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-md-6"><label class="form-label">Tipo de mensaje</label>
                    <select class="form-select d-none" name="tipo" id="cTipo">
                        <?php foreach (COBRO_TIPOS as $k => $t): if (!$extras && in_array($k, ['recordatorio_pago', 'envio_recibo'], true)) continue; ?><option value="<?= $k ?>" <?= $preTipo === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?>
                    </select>
                    <div class="small text-muted" id="cTipoAyuda"></div></div>
                <!-- Tipo de mensaje en pestañas: el asunto y el mensaje dicen si es una factura, un cobro o la factura con el saldo pendiente -->
                <div class="col-12">
                    <ul class="nav nav-pills flex-wrap gap-1 small" id="cTipoTabs">
                        <?php $iconos = ['envio_factura' => 'bi-receipt', 'saldo_pendiente' => 'bi-cash-coin', 'factura_y_saldo' => 'bi-receipt-cutoff', 'recordatorio_pago' => 'bi-calendar2-check', 'envio_recibo' => 'bi-file-earmark-text'];
                        foreach (COBRO_TIPOS as $k => $t): if (!$extras && in_array($k, ['recordatorio_pago', 'envio_recibo'], true)) continue; ?>
                            <li class="nav-item"><button type="button" class="nav-link py-1 px-3 border" data-tipo="<?= $k ?>"><i class="bi <?= $iconos[$k] ?? 'bi-envelope' ?> me-1"></i><?= $t ?></button></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
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
                <div class="col-12" data-bloque-docs hidden>
                    <label class="form-label mb-1"><i class="bi bi-paperclip"></i> Documentos de la empresa a adjuntar <span class="small fw-normal text-muted">(<a href="configuracion_documentos" target="_blank">administrar</a>)</span></label>
                    <div id="cDocs" class="d-flex flex-wrap gap-2"></div><div class="form-text">El correo mencionará automáticamente los documentos seleccionados en un párrafo de respaldo administrativo y tributario (excepto en envío de recibos). Puedes revisarlo en Vista previa.</div>
                </div>
                <div class="col-md-6"><label class="form-label">3. Para *</label><input class="form-control" name="para" id="cPara" placeholder="correo@cliente.com, otro@cliente.com" required><div class="form-text">Puedes agregar o cambiar destinatarios. Separa varios correos con coma; se guardan para este envío programado.</div></div>
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
        <div class="app-card-header"><span><i class="bi bi-list-check me-1"></i> Cobros</span><span class="app-badge" id="cbTotal"><?= $listado['total'] ?></span></div>
        <div class="app-card-body border-bottom">
            <div class="row g-2 align-items-end">
                <div class="col-md-4"><label class="form-label" for="cbBuscar">Buscar</label><input type="search" class="form-control" id="cbBuscar" placeholder="Cliente, asunto, correo o factura…" value="<?= htmlspecialchars((string)($_GET['q'] ?? '')) ?>"></div>
                <div class="col-md-4"><label class="form-label" for="cbCliente">Cliente</label><select class="form-select" id="cbCliente" data-buscar><option value="">Todos los clientes</option>
                <?php foreach ($clientes as $cl): ?><option value="<?= (int)$cl['id'] ?>" <?= (int)($_GET['cliente'] ?? 0) === (int)$cl['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cl['nombre']) ?></option><?php endforeach; ?>
                </select></div>
                <div class="col-md-2"><label class="form-label" for="cbEstado">Estado</label><select class="form-select" id="cbEstado"><option value="">Todos</option>
                <?php foreach ($estados as $valor => [$etiqueta]): ?><option value="<?= $valor ?>" <?= ($_GET['estado'] ?? '') === $valor ? 'selected' : '' ?>><?= $etiqueta ?></option><?php endforeach; ?>
                </select></div>
                <div class="col-md-2"><label class="form-label" for="cbPorPagina">Por página</label><select class="form-select" id="cbPorPagina"><?php foreach ([10,25,50,100,200,300] as $n): ?><option value="<?= $n ?>" <?= $listado['por_pagina'] === $n ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="d-flex gap-2 align-items-center flex-wrap mt-3"><button type="button" class="btn btn-outline-danger btn-sm" id="cbEliminar" disabled><i class="bi bi-trash me-1"></i>Eliminar seleccionados</button><span id="cbSeleccionInfo" class="small text-muted" aria-live="polite">0 seleccionados</span><button type="button" class="btn btn-link btn-sm ms-auto" id="cbLimpiar">Limpiar filtros</button></div>
            <div id="cbError" class="text-danger small mt-2" role="alert"></div>
        </div>
        <div class="table-responsive" id="cbLista">
            <table class="table app-table mb-0" id="cbTabla">
                <thead><tr><th><input type="checkbox" class="form-check-input" id="cbTodos" aria-label="Seleccionar los correos eliminables de esta página"></th><th>#</th><th>Cliente</th><th>Documentos</th><th>Para</th><th>Envío</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
                <tbody id="cbFilas">
                    <?php require __DIR__ . '/includes/cobros_filas.php'; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="app-pager mb-3" id="cbPie" aria-live="polite"></div>

    <!-- Vista previa del correo (no envía nada) -->
    <div class="modal fade" id="mPrevia" tabindex="-1" style="z-index:1065"><div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-window me-1"></i> Vista previa del correo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <dl class="row small mb-2" id="pDatos"></dl>
            <iframe id="pHtml" sandbox="" title="Vista previa" style="width:100%;border:1px solid var(--bs-border-color);border-radius:8px;background:#f1f5f9"></iframe>
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
    <div class="modal fade" id="mEditar" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-sm-down">
        <form id="fEditar" class="modal-content">
            <div class="modal-header"><h5 class="modal-title"><i class="bi bi-pencil me-1"></i> Editar cobro <span id="eNum"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="accion" value="editar"><input type="hidden" name="id" id="eId">
                <div class="row g-2">
                    <div class="col-md-6"><label class="form-label">Para *</label><input class="form-control" name="para" id="ePara" required><div class="form-text">Varios separados por coma.</div></div>
                    <div class="col-md-6"><label class="form-label">Con copia (CC)</label><input class="form-control" name="cc" id="eCc"></div>
                    <div class="col-md-8"><label class="form-label">Asunto *</label><input class="form-control" name="asunto" id="eAsunto" maxlength="255" required></div>
                    <div class="col-md-4"><label class="form-label">Envío (hora de Honduras)</label><input class="form-control" type="datetime-local" name="programado_para" id="eFecha" required></div>
            <div class="col-12 border-top border-bottom py-2 my-2">
                <input type="hidden" name="actualizar_documentos" value="1">
                <label class="form-label"><i class="bi bi-paperclip"></i> Documentos de la empresa a adjuntar</label>
                <div id="eDocs" class="d-flex flex-wrap gap-2"></div>
                <div class="form-text">Marca la constancia de pago a cuenta u otros documentos y guarda los cambios para incluirlos en este correo programado. El correo mencionará los documentos seleccionados automáticamente (excepto en envío de recibos); revísalo en Vista previa. <a href="configuracion_documentos" target="_blank">Administrar documentos</a></div>
            </div>
                    <div class="col-12"><label class="form-label">Mensaje *</label><textarea id="eMensaje" class="form-control" rows="10"></textarea>
                        <div class="form-text">Las facturas y recibos adjuntos se conservan. Puedes agregar o quitar documentos de la empresa en la sección de adjuntos.</div></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-dark me-auto" id="ePrevia"><i class="bi bi-window me-1"></i> Vista previa</button><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button><button class="btn btn-primary" type="submit"><i class="bi bi-floppy me-1"></i> Guardar cambios</button></div>
        </form>
    </div></div>
<?php endif; ?>

<!-- Editor de texto del mensaje (TinyMCE, licencia GPL, servido desde jsDelivr) -->
<script src="https://cdn.jsdelivr.net/npm/tinymce@7.6.0/tinymce.min.js" referrerpolicy="origin"></script>
<script>
    // Solo los formatos que conserva el correo (includes/cobros.php → cobroLimpiarHtml): negrita, cursiva, subrayado y listas.
    tinymce.init({
        selector: '#cMensaje, #eMensaje', entity_encoding: 'raw', license_key: 'gpl', menubar: false, branding: false, promotion: false, statusbar: false,
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

    // Filtros remotos y paginación: seleccionar todos afecta solamente a la página visible.
    const $filas = document.getElementById('cbFilas'), $todos = document.getElementById('cbTodos'), $eliminar = document.getElementById('cbEliminar');
    let paginaLista = <?= (int)$listado['pagina'] ?>, cargandoLista = false, peticionLista = 0, timerLista;
    const checksLista = () => [...document.querySelectorAll('#cbFilas .cb-seleccion')];
    function seleccionLista() {
        const checks = checksLista(), n = checks.filter(c => c.checked).length;
        if (!$todos) return;
        $todos.checked = checks.length > 0 && n === checks.length;
        $todos.indeterminate = n > 0 && n < checks.length;
        $todos.disabled = cargandoLista || !checks.length;
        $eliminar.disabled = cargandoLista || !n;
        document.getElementById('cbSeleccionInfo').textContent = `${n} seleccionado(s) de esta página`;
    }
    function pintarPagina(d) {
        paginaLista = d.pagina;
        document.getElementById('cbTotal').textContent = d.total;
        const ini = (d.pagina - 1) * d.por_pagina + 1, fin = Math.min(d.total, d.pagina * d.por_pagina);
        const boton = (txt, p, disabled, activo = false) => `<button type="button" class="app-page-btn ${activo ? 'active' : ''}" data-pagina="${p}" ${disabled ? 'disabled' : ''}>${txt}</button>`;
        let botones = boton('«', 1, d.pagina === 1) + boton('‹', d.pagina - 1, d.pagina === 1);
        let desde = Math.max(1, d.pagina - 2), hasta = Math.min(d.paginas, desde + 4);
        desde = Math.max(1, hasta - 4);
        for (let p = desde; p <= hasta; p++) botones += boton(p, p, p === d.pagina, p === d.pagina);
        botones += boton('›', d.pagina + 1, d.pagina === d.paginas) + boton('»', d.paginas, d.pagina === d.paginas);
        document.getElementById('cbPie').innerHTML = `<span class="app-pager-info">${d.total ? `Mostrando ${ini}–${fin} de ${d.total} · Página ${d.pagina} de ${d.paginas}` : 'Sin resultados'}</span><div class="app-pager-btns">${botones}</div>`;
    }
    async function recargarLista(pagina = paginaLista) {
        if (!$filas) return;
        clearTimeout(timerLista);
        const numero = ++peticionLista;
        cargandoLista = true; seleccionLista();
        document.getElementById('cbLista').setAttribute('aria-busy', 'true');
        document.getElementById('cbError').textContent = '';
        const params = new URLSearchParams({ ajax: '1', pagina, por_pagina: document.getElementById('cbPorPagina').value,
            q: document.getElementById('cbBuscar').value, cliente: document.getElementById('cbCliente').value, estado: document.getElementById('cbEstado').value });
        try {
            const d = await fetch('cobros_programados.php?' + params).then(leer);
            if (numero !== peticionLista) return;
            $filas.innerHTML = d.html;
            pintarPagina(d);
        } catch (err) {
            if (numero === peticionLista) document.getElementById('cbError').textContent = 'No se pudo actualizar la lista: ' + err.message;
        } finally {
            if (numero === peticionLista) { cargandoLista = false; document.getElementById('cbLista').setAttribute('aria-busy', 'false'); seleccionLista(); }
        }
    }
    document.getElementById('cbBuscar')?.addEventListener('input', () => {
        ++peticionLista; clearTimeout(timerLista);
        cargandoLista = true; seleccionLista();
        timerLista = setTimeout(() => recargarLista(1), 250);
    });
    ['cbCliente', 'cbEstado', 'cbPorPagina'].forEach(id => document.getElementById(id)?.addEventListener('change', () => recargarLista(1)));
    document.getElementById('cbLimpiar')?.addEventListener('click', () => { ['cbCliente', 'cbEstado', 'cbBuscar'].forEach(id => document.getElementById(id).value = ''); recargarLista(1); });
    document.getElementById('cbPie')?.addEventListener('click', e => { const b = e.target.closest('[data-pagina]'); if (b && !b.disabled) recargarLista(+b.dataset.pagina); });
    $todos?.addEventListener('change', () => { checksLista().forEach(c => c.checked = $todos.checked); seleccionLista(); });
    $filas?.addEventListener('change', seleccionLista);
    $eliminar?.addEventListener('click', async () => {
        const ids = checksLista().filter(c => c.checked).map(c => c.value);
        if (!ids.length || cargandoLista) return;
        const r = await Swal.fire({ title: `¿Eliminar ${ids.length} correo(s)?`, text: 'Se eliminarán los correos seleccionados y sus copias adjuntas. Los programados ya no se enviarán. Las facturas, recibos y documentos originales se conservan.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Eliminar seleccionados', cancelButtonText: 'Cancelar' });
        if (!r.isConfirmed) return;
        const fd = new FormData(); fd.append('accion', 'eliminar_lote'); ids.forEach(id => fd.append('ids[]', id));
        $eliminar.disabled = true;
        await accion(fd); seleccionLista();
    });
    if ($filas) { pintarPagina(<?= json_encode(array_diff_key($listado, ['cobros' => true])) ?>); seleccionLista(); }

    const abrir = () => { card.style.display = ''; card.scrollIntoView({ behavior: 'smooth' }); };
    document.getElementById('btnNuevoCobro')?.addEventListener('click', abrir);
    document.getElementById('btnCerrarNuevo')?.addEventListener('click', () => card.style.display = 'none');

    let pre = <?= json_encode(['ids' => $preFacturas, 'contrato' => $preContrato, 'recibos' => $preRecibos, 'plan' => $prePlan, 'anticipos' => $preAnticipos]) ?>;   // preselección del acceso directo (solo la primera carga)
    const $tipo = document.getElementById('cTipo'), $rec = document.getElementById('cRecibos'), $plan = document.getElementById('cPlan');
    // Qué lista usa cada tipo: facturas (saldo/envío), recibos o pagos del plan
    const bloque = () => ({ envio_recibo: 'recibos', recordatorio_pago: 'plan' }[$tipo.value] || 'facturas');
    const tablaActiva = () => ({ facturas: $tb, recibos: $rec, plan: $plan }[bloque()]);
    const AYUDA = { envio_factura: 'Factura nueva: el asunto y el mensaje la presentan con sus conceptos.',
        saldo_pendiente: 'Cobro: lista las facturas con saldo, lo abonado y el total pendiente.',
        factura_y_saldo: 'La factura nueva (aún no enviada) con sus conceptos y, además, las otras facturas con saldo y sus abonos, con el total.',
        recordatorio_pago: 'Recordatorio de los pagos del plan de pagos (sin adjuntos de facturas).', envio_recibo: 'Recibos de pago en PDF (contratos sin factura).' };
    function mostrarBloque() {
        document.querySelectorAll('[data-bloque]').forEach(b => b.hidden = b.dataset.bloque !== bloque());
        document.querySelectorAll('#cTipoTabs [data-tipo]').forEach(t => { const on = t.dataset.tipo === $tipo.value; t.classList.toggle('active', on); t.classList.toggle('border-primary', on); });
        document.getElementById('cTipoAyuda').textContent = AYUDA[$tipo.value] || '';
    }
    document.querySelectorAll('#cTipoTabs [data-tipo]').forEach(t => t.addEventListener('click', () => {
        if ($tipo.value === t.dataset.tipo) return;
        $tipo.value = t.dataset.tipo;
        // Factura + saldo: además de las marcadas, todas las que tienen saldo
        if ($tipo.value === 'factura_y_saldo') $tb.querySelectorAll('input[type=checkbox]').forEach(i => { if (Number(i.dataset.saldo) > 0) i.checked = true; });
        if ($tipo.value === 'saldo_pendiente') $tb.querySelectorAll('input[type=checkbox]').forEach(i => { if (!(Number(i.dataset.saldo) > 0)) i.checked = false; });
        resumen(); llenarCc(); pintarDocs(false);
        $tipo.dispatchEvent(new Event('change'));
    }));

    function mensajeConDocumentos(html, nombres, tipo) {
        let auto = String.raw`Para facilitar su gestión administrativa y tributaria, adjuntamos la documentación de respaldo: [\s\S]*?Quedamos a su disposición para cualquier consulta sobre esta documentación\.`;
        // Compatibilidad con mensajes guardados por el editor usando entidades HTML.
        auto = auto.replace(/ó/g, '(?:ó|&oacute;|&#0*243;|&#x0*f3;)')
            .replace(/á/g, '(?:á|&aacute;|&#0*225;|&#x0*e1;)')
            .replace(/ /g, String.raw`(?:\s|\u00a0|&nbsp;|&#0*160;|&#x0*a0;)+`);
        html = html.replace(new RegExp(String.raw`<p\b[^>]*>\s*${auto}\s*</p>|${auto}(?:\s*<br\s*/?>){0,2}`, 'giu'), '');
        if (!nombres.length || tipo === 'envio_recibo') return html;
        const etiquetas = [...new Set(nombres)].map(n => '<strong>' + esc(n) + '</strong>');
        const ultima = etiquetas.pop();
        const lista = etiquetas.length ? etiquetas.join(', ') + ' y ' + ultima : ultima;
        const texto = 'Para facilitar su gestión administrativa y tributaria, adjuntamos la documentación de respaldo: ' + lista + '. Quedamos a su disposición para cualquier consulta sobre esta documentación.';
        const desde = Math.max(0, html.toLowerCase().indexOf('formas de pago'));
        const cierre = /(?:^|<br\s*\/?>|<p\b[^>]*>|<div\b[^>]*>)\s*(?=(?:<(?:strong|b|span)\b[^>]*>\s*)*(?:Quedo atent[oa]|Quedamos atent[oa]s|Agradecemos|Saludos|Atentamente|Cordialmente)\b)/iu;
        const m = cierre.exec(html.slice(desde));
        if (m) { const pos = desde + m.index + m[0].length; return html.slice(0, pos) + texto + '<br><br>' + html.slice(pos); }
        return html + '<p>' + texto + '</p>';
    }
    function actualizarParrafoDocumentos(editor, contenedor, tipo) {
        const nombres = [...document.querySelectorAll('#' + contenedor + ' input:checked')].map(i => i.closest('label').querySelector('span').textContent);
        const ed = tinymce.get(editor);
        if (ed && !ed.initialized) { ed.once('init', () => actualizarParrafoDocumentos(editor, contenedor, tipo)); return; }
        const anterior = getMensaje(editor), nuevo = mensajeConDocumentos(anterior, nombres, tipo);
        if (nuevo !== anterior) setMensaje(editor, nuevo);
    }

    // Documentos de la empresa (Constancia del SAR…): los marcados «por defecto» se marcan solos en los envíos de facturas
    let docs = [];
    function pintarDocs(inicial) {
        const cont = document.getElementById('cDocs'), blq = document.querySelector('[data-bloque-docs]');
        if (!cont) return;
        blq.hidden = !docs.length;
        const marcados = new Set([...cont.querySelectorAll('input:checked')].map(i => +i.value));
        const conFactura = bloque() === 'facturas';
        cont.innerHTML = docs.map(d => {
            const on = inicial ? (conFactura && d.defecto && d.estado !== 'vencido') : marcados.has(d.id);
            return `<label class="border rounded px-2 py-1 small d-flex align-items-center gap-2" style="cursor:pointer">
                <input class="form-check-input m-0" type="checkbox" name="documento_ids[]" value="${d.id}" ${on ? 'checked' : ''}>
                <span>${esc(d.nombre)}</span><span class="badge rounded-pill" style="background:${d.bg};color:${d.fg}">${esc(d.txt)}</span></label>`;
        }).join('');
        actualizarParrafoDocumentos('cMensaje', 'cDocs', $tipo.value);
    }
    document.getElementById('cDocs')?.addEventListener('change', e => {
        actualizarParrafoDocumentos('cMensaje', 'cDocs', $tipo.value);
        const d = docs.find(x => x.id === +e.target.value);
        if (e.target.checked && d && d.estado === 'vencido') Swal.fire('Documento vencido', '«' + d.nombre + '» está vencido. Súbelo renovado en Documentos de la empresa o desmárcalo.', 'warning');
    });
    const docsMarcados = () => [...document.querySelectorAll('#cDocs input:checked')].map(i => i.value);
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
                <td class="font-monospace small text-nowrap"><a href="ver_factura?id=${f.id}" target="_blank">${esc(f.correlativo)}</a>
                    <div style="font-size:.68rem">${+f.enviada ? '<span class="text-muted">Ya enviada</span>' : '<span class="badge rounded-pill" style="background:#dbeafe;color:#1e40af">Nueva</span>'}</div></td>
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
            docs = d.documentos || [];
            pintarDocs(true);
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
            actualizarParrafoDocumentos('cMensaje', 'cDocs', $tipo.value);
            return;
        }
        if (!ids.length) return Swal.fire('Selecciona facturas', 'Marca al menos una factura para generar el mensaje.', 'info');
        const r = await fetch('procesar_accion_factura.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ accion: 'generar_mensaje', factura_ids: ids, tipo: document.getElementById('cTipo').value }) }).then(leer).catch(err => (Swal.fire('Error', err.message, 'error'), null));
        if (!r) return;
        if (r.tipo && r.tipo !== $tipo.value) document.getElementById('cTipoAyuda').textContent = 'No hay otras facturas con saldo entre las marcadas: el mensaje se generó como envío de factura.';
        document.getElementById('cAsunto').value = r.asunto || '';
        // mensaje_html ya trae <br> (nl2br): los saltos de línea que quedan son solo espacio en HTML; no se convierten otra vez
        setMensaje('cMensaje', (r.mensaje_html || '').replace(/\r?\n/g, ''));
        actualizarParrafoDocumentos('cMensaje', 'cDocs', $tipo.value);
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
            const nd = docsMarcados().length;
            const ok = await Swal.fire({ title: '¿Enviar ahora al cliente?', text: 'Se enviará a ' + fd.get('para') + ' con ' + seleccionadas().length + ' ' + ({ facturas: 'factura(s) adjunta(s)', recibos: 'recibo(s) adjunto(s)', plan: 'pago(s) del plan' }[bloque()]) + (nd ? ' y ' + nd + ' documento(s) de la empresa' : '') + '.', icon: 'question', showCancelButton: true, confirmButtonText: 'Enviar', cancelButtonText: 'Cancelar' });
            if (!ok.isConfirmed) return;
        }
        Swal.fire({ title: modo === 'programar' ? 'Generando PDF y programando…' : 'Generando PDF y enviando…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        fetch('cobro_accion.php', { method: 'POST', body: fd }).then(leer)
            .then(d => Swal.fire({ icon: 'success', title: d.message }).then(() => { if (modo !== 'prueba') location.reload(); }))
            .catch(err => Swal.fire('No se pudo', err.message, 'error'));
    }));

    const accion = (fd, msg) => fetch('cobro_accion.php', { method: 'POST', body: fd }).then(leer)
        .then(async d => { bootstrap.Modal.getInstance(document.getElementById('mEditar'))?.hide(); await recargarLista(); await Swal.fire({ icon: 'success', title: d.message }); }).catch(err => Swal.fire('No se pudo', err.message, 'error'));
    document.addEventListener('click', async ev => {
        const b = ev.target.closest('.btn-accion');
        if (!b) return;
        const txt = { cancelar: '¿Cancelar este cobro?', eliminar: '¿Eliminar este cobro y sus PDF?', enviar_ya: '¿Enviar este cobro ahora?' }[b.dataset.accion];
        if (!(await Swal.fire({ title: txt, icon: 'question', showCancelButton: true, confirmButtonText: 'Sí', cancelButtonText: 'No' })).isConfirmed) return;
        const fd = new FormData(); fd.append('accion', b.dataset.accion); fd.append('id', b.dataset.id);
        if (b.dataset.accion === 'enviar_ya') Swal.fire({ title: 'Enviando…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        accion(fd);
    });
    document.addEventListener('click', async ev => {
        const b = ev.target.closest('.btn-reprogramar');
        if (!b) return;
        const r = await Swal.fire({ title: 'Nueva fecha y hora', html: `<input type="datetime-local" id="nuevaFecha" class="form-control" value="${b.dataset.fecha}"><div class="small text-muted mt-2">Hora de Honduras</div>`,
            showCancelButton: true, confirmButtonText: 'Reprogramar', cancelButtonText: 'Cancelar', preConfirm: () => document.getElementById('nuevaFecha').value });
        if (!r.isConfirmed) return;
        const fd = new FormData(); fd.append('accion', 'reprogramar'); fd.append('id', b.dataset.id); fd.append('programado_para', r.value);
        accion(fd);
    });

    // ── Vista previa del correo (redacción y edición) ──
    async function previa({ para, cc, asunto, html, receptor, ids, recibos, anticipos, documentos, tipo, cobroId }) {
        const fd = new FormData();
        fd.append('tipo', tipo || '');
        if (cobroId) fd.append('cobro_id', cobroId);
        fd.append('accion', 'previsualizar'); fd.append('receptor_id', receptor || ''); fd.append('mensaje_html', html); fd.append('asunto', asunto);
        (ids || []).forEach(i => fd.append('factura_ids[]', i));
        (recibos || []).forEach(i => fd.append('recibo_ids[]', i));
        (anticipos || []).forEach(i => fd.append('anticipo_ids[]', i));
        (documentos || []).forEach(i => fd.append('documento_ids[]', i));
        try {
            const d = await fetch('cobro_accion.php', { method: 'POST', body: fd }).then(leer);
            const fila = (k, v) => `<dt class="col-sm-2 text-muted fw-normal">${k}</dt><dd class="col-sm-10 mb-1">${v || '<span class="text-muted">—</span>'}</dd>`;
            document.getElementById('pDatos').innerHTML = fila('Para', esc(para)) + fila('CC', esc(cc)) + fila('Asunto', '<strong>' + esc(asunto) + '</strong>')
                + fila('Adjuntos', d.adjuntos.length ? d.adjuntos.map(n => `<span class="badge text-bg-light border me-1"><i class="bi ${/^(factura|recibo) /.test(n) ? 'bi-file-earmark-pdf text-danger' : 'bi-paperclip text-primary'}"></i> ${esc(n)}</span>`).join('') : '');
            document.getElementById('pHtml').srcdoc = d.html;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('mPrevia')).show();
        } catch (err) { Swal.fire('No se pudo', err.message, 'error'); }
    }
    document.getElementById('btnPrevia')?.addEventListener('click', () => previa({
        para: document.getElementById('cPara').value, cc: document.getElementById('cCc').value, asunto: document.getElementById('cAsunto').value,
        tipo: $tipo.value, html: getMensaje('cMensaje'), receptor: $cli.value, ids: bloque() === 'facturas' ? seleccionadas() : [], documentos: docsMarcados(),
        recibos: bloque() === 'recibos' ? [...$rec.querySelectorAll('input[data-ant="0"]:checked')].map(i => i.value) : [],
        anticipos: bloque() === 'recibos' ? [...$rec.querySelectorAll('input[data-ant="1"]:checked')].map(i => i.value) : [],
    }));
    let editando = null;   // cobro abierto en «Editar» (para la vista previa)
    document.getElementById('ePrevia')?.addEventListener('click', () => previa({
        para: document.getElementById('ePara').value, cc: document.getElementById('eCc').value, asunto: document.getElementById('eAsunto').value,
        cobroId: document.getElementById('eId').value, html: getMensaje('eMensaje'), receptor: editando?.receptor_id, ids: editando?.facturas || [], recibos: editando?.recibos || [], documentos: [...document.querySelectorAll('#eDocs input:checked')].map(i => i.value), anticipos: editando?.anticipos || [],
    }));

    // ── Ver lo que se envió ──
    const fmt = d => d ? new Date(d.replace(' ', 'T')).toLocaleString('es-HN', { day: '2-digit', month: '2-digit', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : '—';
    const estadosTxt = <?= json_encode(array_map(fn($e) => $e[0], $estados), JSON_UNESCAPED_UNICODE) ?>;
    const detalle = id => fetch('cobro_accion.php?ver=' + id).then(leer);
    document.addEventListener('click', async ev => {
        const b = ev.target.closest('.btn-ver');
        if (!b) return;
        try {
            const d = await detalle(b.dataset.id), c = d.cobro;
            document.getElementById('vNum').textContent = '#' + c.id + (+c.prueba ? ' · prueba' : '');
            const fila = (k, v) => `<dt class="text-muted fw-normal">${k}</dt><dd class="mb-2">${v}</dd>`;
            document.getElementById('vDatos').innerHTML = fila('Cliente', esc(c.cliente)) + fila('Para', esc(c.para)) + (c.cc ? fila('CC', esc(c.cc)) : '')
                + fila('Asunto', esc((+c.prueba ? '[PRUEBA] ' : '') + c.asunto)) + fila('Estado', esc(estadosTxt[c.estado] || c.estado) + (c.error ? `<div class="text-danger">${esc(c.error)}</div>` : ''))
                + fila('Programado', fmt(c.programado_para)) + fila('Enviado', fmt(c.enviado_en)) + fila('Creado', fmt(c.creado_en));
            document.getElementById('vAdjuntos').innerHTML = d.adjuntos.length ? d.adjuntos.map(a => a.existe
                ? `<a class="btn btn-sm btn-outline-secondary me-1 mb-1" target="_blank" href="cobro_accion.php?pdf=${c.id}&${a.recibo_id ? 'recibo=' + a.recibo_id : (a.anticipo_id ? 'anticipo=' + a.anticipo_id : (a.documento_id ? 'documento=' + a.documento_id : 'factura=' + a.factura_id))}"><i class="bi ${a.documento_id ? 'bi-paperclip text-primary' : 'bi-file-earmark-pdf text-danger'}"></i> ${esc(a.etiqueta)}</a>`
                : `<span class="badge text-bg-light border me-1">${esc(a.etiqueta)} (no disponible)</span>`).join('') : '<span class="text-muted small">Sin adjuntos.</span>';
            document.getElementById('vEnvios').innerHTML = d.envios.length ? '<ul class="list-unstyled small mb-0">' + d.envios.map(e =>
                `<li class="mb-1"><i class="bi ${e.estado === 'enviado' ? 'bi-check-circle text-success' : 'bi-x-circle text-danger'}"></i> ${fmt(e.creado_en)} → ${esc(e.destinatario)}${e.error ? `<div class="text-danger">${esc(e.error)}</div>` : ''}</li>`).join('') + '</ul>'
                : '<span class="text-muted small">Aún no se ha intentado enviar.</span>';
            document.getElementById('vHtml').srcdoc = d.html;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('mVer')).show();
        } catch (err) { Swal.fire('No se pudo', err.message, 'error'); }
    });

    // ── Editar (solo los que aún no se envían) ──
    document.addEventListener('click', async ev => {
        const b = ev.target.closest('.btn-editar');
        if (!b) return;
        try {
            const det = await detalle(b.dataset.id), c = det.cobro;
            editando = { tipo: c.tipo, anticipos: det.adjuntos.filter(x => x.anticipo_id).map(x => x.anticipo_id), receptor_id: c.receptor_id, facturas: det.adjuntos.filter(x => x.factura_id).map(x => x.factura_id), recibos: det.adjuntos.filter(x => x.recibo_id).map(x => x.recibo_id), documentos: det.adjuntos.filter(x => x.documento_id).map(x => x.documento_id) };
            const actuales = new Map(det.adjuntos.filter(x => x.documento_id).map(x => [+x.documento_id, x]));
            const disponibles = [...(det.documentos || [])];
            for (const [id, a] of actuales) if (!disponibles.some(d => +d.id === id)) disponibles.push({ id, nombre: a.etiqueta, txt: 'Copia adjunta', estado: '' });
            document.getElementById('eDocs').innerHTML = disponibles.length ? disponibles.map(d => `<label class="border rounded px-2 py-1 small d-flex align-items-center gap-2">
                <input class="form-check-input m-0" type="checkbox" name="documento_ids[]" value="${d.id}" data-vencido="${d.estado === 'vencido' ? '1' : '0'}" ${actuales.has(+d.id) ? 'checked' : ''}>
                <span>${esc(d.nombre)}</span><span class="badge text-bg-light">${esc(d.txt)}</span></label>`).join('') : '<span class="text-muted small">No hay documentos disponibles. Agrégalos en Documentos de la empresa.</span>';
            document.getElementById('eNum').textContent = '#' + c.id;
            document.getElementById('eId').value = c.id;
            document.getElementById('ePara').value = c.para;
            document.getElementById('eCc').value = c.cc || '';
            document.getElementById('eAsunto').value = c.asunto;
            document.getElementById('eFecha').value = c.programado_para.slice(0, 16).replace(' ', 'T');
            setMensaje('eMensaje', c.mensaje_html.replace(/\r?\n/g, ''));
            actualizarParrafoDocumentos('eMensaje', 'eDocs', c.tipo);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('mEditar')).show();
        } catch (err) { Swal.fire('No se pudo', err.message, 'error'); }
    });
    document.getElementById('eDocs')?.addEventListener('change', e => { actualizarParrafoDocumentos('eMensaje', 'eDocs', editando?.tipo); if (e.target.checked && e.target.dataset.vencido === '1') Swal.fire('Documento vencido', 'Este documento está vencido. Puedes renovarlo en Documentos de la empresa antes de adjuntarlo.', 'warning'); });
    document.getElementById('fEditar')?.addEventListener('submit', e => {
        e.preventDefault();
        const fd = new FormData(e.target);
        fd.append('mensaje_html', getMensaje('eMensaje'));
        accion(fd);
    });

    // ── Reenviar: copia con el mismo mensaje y los mismos PDF ──
    document.addEventListener('click', async ev => {
        const b = ev.target.closest('.btn-reenviar');
        if (!b) return;
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
    });

    if ($cli?.value) { abrir(); cargarFacturas(); }
})();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
