<?php
// Configuración del correo saliente (SMTP) por cuenta (Nómina / Facturación) y bitácora de envíos.
$titulo = 'Correo (SMTP)';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/correo.php';
require_once '../../includes/aviso_pendientes.php';

if (!in_array(USUARIO_ROL, ['admin', 'superadmin'], true)) {
    header('Location: dashboard');
    exit;
}
$cid = cliente_actual();
$instalado = correoDisponible($pdo);
$conPerfiles = $instalado && correoTienePerfiles($pdo);
$tabActiva = in_array($_GET['tab'] ?? '', ['nomina', 'facturacion', 'bitacora'], true) ? $_GET['tab'] : 'nomina';
$urlBase = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'facturacion.naranjaymediahn.com');

// Valores sugeridos para una cuenta que aún no existe (la contraseña nunca se sugiere)
$sugeridos = [
    'nomina' => ['host' => 'nomina.naranjaymediahn.com', 'puerto' => 465, 'seguridad' => 'ssl', 'usuario' => 'nomina@nomina.naranjaymediahn.com',
        'remitente_email' => 'nomina@nomina.naranjaymediahn.com', 'remitente_nombre' => 'Naranja & Media'],
    'facturacion' => ['host' => 'facturacion.naranjaymediahn.com', 'puerto' => 465, 'seguridad' => 'ssl', 'usuario' => 'facturacion@facturacion.naranjaymediahn.com',
        'remitente_email' => 'facturacion@facturacion.naranjaymediahn.com', 'remitente_nombre' => 'Naranja & Media · Facturación'],
];
$cuentas = [];
foreach (array_keys(CORREO_PERFILES) as $p) {
    $cfg = $instalado ? correoConfig($pdo, $cid, $p) : null;
    $base = $cfg ?? ($sugeridos[$p] + ['responder_a' => 'gerencia@naranjaymediahn.com, administracion@naranjaymediahn.com',
        'logo_url' => $urlBase . '/clientes/css/logo-correo.png', 'enlace_url' => $urlBase . '/', 'activo' => 1, 'aviso_pago_auto' => 1, 'aviso_pago_hora' => 7]);
    $cuentas[$p] = ['existe' => (bool)$cfg, 'tieneClave' => !empty($cfg['clave_cifrada']), 'v' => $base];
}
$log = [];
if ($instalado) {
    $st = $pdo->prepare("SELECT e.*, u.nombre AS usuario FROM correos_enviados e LEFT JOIN usuarios u ON u.id = e.usuario_id
                         WHERE e.cliente_id = ? ORDER BY e.id DESC LIMIT 100");
    $st->execute([$cid]);
    $log = $st->fetchAll(PDO::FETCH_ASSOC);
}
$tipos = ['prueba' => 'Prueba', 'pago_colaborador' => 'Aviso de pago', 'cobro' => 'Cobro', 'cobro_prueba' => 'Cobro (prueba)',
          'resumen_pendientes' => 'Resumen a gerencia', 'resumen_pendientes_prueba' => 'Resumen a gerencia (prueba)', 'documento_vencido' => 'Documento vencido', 'documento_por_vencer' => 'Documento por vencer'];
$hayResumen = $instalado && avisoPendientesDisponible($pdo);
$rutaCron = realpath(__DIR__ . '/../../cron/tareas.php') ?: dirname(__DIR__, 2) . '/cron/tareas.php';

require_once '../../includes/templates/header.php';
require_once '../../includes/templates/config_tabs.php';
?>

<div class="app-page-header">
    <div>
        <h1 class="app-page-title"><i class="bi bi-envelope-at me-2"></i>Correo (SMTP)</h1>
        <p class="app-page-sub">Cuentas de correo con las que el sistema envía avisos de pago (Nómina) y cobros con facturas (Facturación).</p>
    </div>
</div>

<?php if (!$instalado): ?>
    <div class="alert alert-warning">Falta instalar el módulo: ejecuta <code>sql/migraciones/2026-10-04_correo.sql</code>.</div>
<?php else: ?>

<ul class="nav nav-tabs mb-3" role="tablist" id="tabsCorreo">
    <?php foreach (CORREO_PERFILES as $p => $nombre):
        if (!$conPerfiles && $p !== 'nomina') continue;
        $c = $cuentas[$p]; ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $tabActiva === $p ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tab-<?= $p ?>" type="button" role="tab" data-tab="<?= $p ?>">
                <i class="bi bi-<?= $p === 'nomina' ? 'people' : 'receipt' ?> me-1"></i><?= $nombre ?>
                <?php if ($c['tieneClave'] && (int)$c['v']['activo']): ?><span class="badge bg-success ms-1" title="Configurada y activa">✓</span>
                <?php elseif (!$c['tieneClave']): ?><span class="badge bg-warning text-dark ms-1" title="Falta la contraseña">!</span><?php endif; ?>
            </button>
        </li>
    <?php endforeach; ?>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?= $tabActiva === 'bitacora' ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tab-bitacora" type="button" role="tab" data-tab="bitacora">
            <i class="bi bi-clock-history me-1"></i>Bitácora <span class="badge bg-secondary ms-1"><?= count($log) ?></span>
        </button>
    </li>
</ul>

<div class="tab-content">
<?php foreach (CORREO_PERFILES as $p => $nombre):
    if (!$conPerfiles && $p !== 'nomina') continue;
    $c = $cuentas[$p];
    $v = fn($k, $d = '') => htmlspecialchars((string)($c['v'][$k] ?? $d)); ?>
    <div class="tab-pane fade <?= $tabActiva === $p ? 'show active' : '' ?>" id="tab-<?= $p ?>" role="tabpanel">
        <?php if (!$c['existe']): ?>
            <div class="alert alert-info py-2 small"><i class="bi bi-info-circle me-1"></i>Esta cuenta aún no está guardada: los datos son una sugerencia. Escribe la <strong>contraseña</strong> y dale «Guardar».</div>
        <?php endif; ?>
        <div class="row g-3">
            <div class="col-lg-7">
                <form class="app-card form-correo" novalidate autocomplete="off">
                    <div class="app-card-header"><span><i class="bi bi-gear me-1"></i> Cuenta de <?= $nombre ?></span>
                        <span class="app-badge <?= (int)($c['v']['activo'] ?? 1) ? 'app-badge-success' : 'app-badge-muted' ?>"><?= (int)($c['v']['activo'] ?? 1) ? 'Envío activo' : 'Envío desactivado' ?></span></div>
                    <div class="app-card-body row g-3">
                        <input type="hidden" name="accion" value="guardar">
                        <input type="hidden" name="perfil" value="<?= $p ?>">
                        <div class="col-md-6"><label class="form-label">Servidor SMTP *</label><input class="form-control" name="host" value="<?= $v('host') ?>" required></div>
                        <div class="col-6 col-md-3"><label class="form-label">Puerto *</label><input class="form-control" type="number" name="puerto" value="<?= $v('puerto', '465') ?>" required></div>
                        <div class="col-6 col-md-3"><label class="form-label">Seguridad</label>
                            <select class="form-select" name="seguridad">
                                <?php foreach (['ssl' => 'SSL (465)', 'tls' => 'TLS (587)', 'ninguna' => 'Ninguna'] as $k => $t): ?>
                                    <option value="<?= $k ?>" <?= ($c['v']['seguridad'] ?? 'ssl') === $k ? 'selected' : '' ?>><?= $t ?></option>
                                <?php endforeach; ?>
                            </select></div>
                        <div class="col-md-6"><label class="form-label">Usuario *</label><input class="form-control" name="usuario" value="<?= $v('usuario') ?>" autocomplete="off" required></div>
                        <div class="col-md-6"><label class="form-label">Contraseña <?= $c['tieneClave'] ? '' : '*' ?></label>
                            <div class="input-group">
                                <input class="form-control campo-clave" type="password" name="clave" autocomplete="new-password" placeholder="<?= $c['tieneClave'] ? '•••••••• (déjala en blanco para conservarla)' : 'Escribe la contraseña del buzón' ?>">
                                <button class="btn btn-outline-secondary btn-ver-clave" type="button" title="Mostrar u ocultar"><i class="bi bi-eye"></i></button>
                            </div>
                            <div class="form-text">Se guarda cifrada y no se vuelve a mostrar.</div></div>
                        <div class="col-md-6"><label class="form-label">Correo del remitente *</label><input class="form-control" type="email" name="remitente_email" value="<?= $v('remitente_email') ?>" required></div>
                        <div class="col-md-6"><label class="form-label">Nombre del remitente</label><input class="form-control" name="remitente_nombre" value="<?= $v('remitente_nombre') ?>"></div>
                        <div class="col-md-6"><label class="form-label">Responder a</label><input class="form-control" type="text" inputmode="email" name="responder_a" value="<?= $v('responder_a') ?>" placeholder="gerencia@…, administracion@…">
                            <div class="form-text">Adonde llegan las respuestas; se muestran en el correo. Varios separados por coma.</div></div>
                        <div class="col-md-6"><label class="form-label">Copia oculta (archivo)</label><input class="form-control" type="text" inputmode="email" name="copia_oculta" value="<?= $v('copia_oculta') ?>" placeholder="opcional (varios separados por coma)">
                            <div class="form-text">Recibe una copia de cada envío.</div></div>
                        <div class="col-md-6"><label class="form-label">Logo para los correos (PNG o JPG)</label><input class="form-control" name="logo_url" value="<?= $v('logo_url') ?>" placeholder="https://…/logo.png">
                            <div class="form-text">Gmail y Outlook no muestran logos SVG.</div></div>
                        <div class="col-md-6"><label class="form-label">Enlace del logo y del pie</label><input class="form-control" name="enlace_url" value="<?= $v('enlace_url') ?>" placeholder="https://…"></div>
                        <?php if ($p === 'nomina'): ?>
                            <div class="col-12">
                                <div class="d-flex flex-wrap align-items-center gap-2">
                                    <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" name="aviso_pago_auto" id="auto-<?= $p ?>" value="1" <?= (int)($c['v']['aviso_pago_auto'] ?? 1) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="auto-<?= $p ?>">Avisar automáticamente a los colaboradores el día del pago, a las</label></div>
                                    <select class="form-select form-select-sm" name="aviso_pago_hora" style="width:auto">
                                        <?php for ($h = 5; $h <= 20; $h++): ?><option value="<?= $h ?>" <?= (int)($c['v']['aviso_pago_hora'] ?? 7) === $h ? 'selected' : '' ?>><?= date('g:i a', mktime($h, 0)) ?></option><?php endfor; ?>
                                    </select>
                                    <span class="small text-muted">(hora de Honduras)</span>
                                </div>
                                <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                                    <label class="small" for="desde-<?= $p ?>">Enviar avisos pendientes de pagos desde el</label>
                                    <input type="date" class="form-control form-control-sm" style="width:auto" name="aviso_pago_desde" id="desde-<?= $p ?>" value="<?= $v('aviso_pago_desde') ?>">
                                </div>
                                <div class="form-text">El sistema revisa cada 5 minutos y envía los avisos que falten de los pagos con fecha desde ese día hasta hoy (máximo 45 días atrás). Si registras un pago por adelantado, el aviso sale el día de la fecha del pago. Los pagos anteriores a esa fecha no se avisan solos (puedes enviarlos a mano). Si ya enviaste un aviso manualmente, no se repite. Vacío = solo los pagos con fecha del mismo día.</div>
                            </div>
                        <?php endif; ?>
                        <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="verificar_ssl" id="ssl-<?= $p ?>" value="1" <?= !empty($c['v']['verificar_ssl']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="ssl-<?= $p ?>">Verificar el certificado SSL</label>
                            <div class="form-text">Desactivado en hosting compartido (cPanel).</div></div></div>
                        <div class="col-md-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="activo" id="act-<?= $p ?>" value="1" <?= (int)($c['v']['activo'] ?? 1) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="act-<?= $p ?>">Envío de correos activo</label></div></div>
                    </div>
                    <div class="app-card-header border-top border-bottom-0 justify-content-end">
                        <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i> Guardar</button>
                    </div>
                </form>
            </div>
            <div class="col-lg-5">
                <div class="app-card mb-3">
                    <div class="app-card-header"><span><i class="bi bi-send-check me-1"></i> Probar esta cuenta</span></div>
                    <div class="app-card-body">
                        <p class="small text-muted mb-2">Guarda primero y envía un correo de prueba.</p>
                        <div class="input-group">
                            <input class="form-control para-prueba" type="email" placeholder="tu-correo@dominio.com">
                            <button class="btn btn-outline-primary btn-probar" data-perfil="<?= $p ?>" <?= $c['tieneClave'] ? '' : 'disabled' ?>><i class="bi bi-send me-1"></i> Enviar prueba</button>
                        </div>
                    </div>
                </div>
                <?php if ($p === 'facturacion' && $hayResumen):
                    $rv = $c['v']; ?>
                    <form class="app-card mb-3 form-resumen" novalidate>
                        <div class="app-card-header"><span><i class="bi bi-clipboard-check me-1"></i> Resumen diario a gerencia</span>
                            <span class="app-badge <?= (int)($rv['aviso_pendientes_auto'] ?? 1) ? 'app-badge-success' : 'app-badge-muted' ?>"><?= (int)($rv['aviso_pendientes_auto'] ?? 1) ? 'Activo' : 'Apagado' ?></span></div>
                        <div class="app-card-body small">
                            <input type="hidden" name="accion" value="pendientes_guardar">
                            <p class="mb-2">Un correo interno con lo que ya tocaba y está pendiente: <strong>contratos sin facturar</strong> (pasó el día de pago), <strong>facturas sin enviar</strong> al cliente, <strong>facturas vencidas</strong> sin pagar y <strong>gastos por cobrar</strong> al cliente. Solo se envía si hay algo pendiente.</p>
                            <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="aviso_pendientes_auto" id="res-auto" value="1" <?= (int)($rv['aviso_pendientes_auto'] ?? 1) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="res-auto">Enviar automáticamente</label></div>
                            <div class="d-flex flex-wrap gap-2 mb-2">
                                <select class="form-select form-select-sm" name="aviso_pendientes_dias" style="width:auto" aria-label="Frecuencia">
                                    <?php foreach (AVISO_PENDIENTES_DIAS as $k => $t): ?><option value="<?= $k ?>" <?= ($rv['aviso_pendientes_dias'] ?? 'habiles') === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?>
                                </select>
                                <select class="form-select form-select-sm" name="aviso_pendientes_hora" style="width:auto" aria-label="Hora">
                                    <?php for ($h = 5; $h <= 20; $h++): ?><option value="<?= $h ?>" <?= (int)($rv['aviso_pendientes_hora'] ?? 8) === $h ? 'selected' : '' ?>>a las <?= date('g:i a', mktime($h, 0)) ?></option><?php endfor; ?>
                                </select>
                            </div>
                            <label class="form-label mb-1" for="res-para">Enviar a</label>
                            <input class="form-control form-control-sm" id="res-para" name="aviso_pendientes_para" value="<?= $v('aviso_pendientes_para') ?>" placeholder="<?= $v('responder_a') ?: 'gerencia@…, administracion@…' ?>">
                            <div class="form-text mb-2">Vacío = los de «Responder a». Varios separados por coma.</div>
                            <div class="d-flex flex-wrap gap-2">
                                <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-save me-1"></i> Guardar</button>
                                <button class="btn btn-sm btn-outline-secondary btn-resumen-ver" type="button"><i class="bi bi-eye me-1"></i> Vista previa</button>
                                <button class="btn btn-sm btn-outline-primary btn-resumen-probar" type="button" <?= $c['tieneClave'] ? '' : 'disabled' ?>><i class="bi bi-send me-1"></i> Enviar ahora</button>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>
                <?php if ($p === 'facturacion'): ?>
                    <div class="app-card mb-3">
                        <div class="app-card-header"><span><i class="bi bi-calendar-check me-1"></i> Cobros por correo</span></div>
                        <div class="app-card-body small">
                            <p class="mb-2">Con esta cuenta se envían los cobros con las facturas en PDF, programados para una fecha y hora.</p>
                            <a href="cobros_programados" class="btn btn-sm btn-primary"><i class="bi bi-send-plus me-1"></i> Ir a cobros programados</a>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="app-card">
                    <div class="app-card-header"><span><i class="bi bi-clock me-1"></i> Envíos automáticos (cron)</span></div>
                    <div class="app-card-body small">
                        <p class="mb-2">Un solo trabajo de cron, <strong>cada 5 minutos</strong> (<code>*/5 * * * *</code>), envía los avisos de pago, los cobros programados y el resumen a gerencia:</p>
                        <pre class="bg-light border rounded p-2 mb-2" style="white-space:pre-wrap;font-size:11.5px">/usr/local/bin/php <?= htmlspecialchars($rutaCron) ?> &gt;&gt; <?= htmlspecialchars(dirname(dirname(dirname($rutaCron)))) ?>/tareas.log 2&gt;&amp;1</pre>
                        <p class="mb-0 text-muted">Hora del sistema: <strong><?= date('d/m/Y g:i a') ?></strong> (Honduras).</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>

    <div class="tab-pane fade <?= $tabActiva === 'bitacora' ? 'show active' : '' ?>" id="tab-bitacora" role="tabpanel">
        <div class="app-card">
            <div class="app-card-header"><span><i class="bi bi-clock-history me-1"></i> Últimos envíos</span><span class="app-badge"><?= count($log) ?></span></div>
            <div class="table-responsive">
                <table data-paginar class="table app-table mb-0">
                    <thead><tr><th class="app-n">#</th><th>Fecha</th><th>Tipo</th><th>Destinatario</th><th>Asunto</th><th>Estado</th><th>Usuario</th></tr></thead>
                    <tbody>
                        <?php if (!$log): ?><tr><td colspan="7" class="text-center text-muted py-4">Aún no se han enviado correos.</td></tr><?php endif; ?>
                        <?php $nL = count($log); foreach ($log as $l): ?>
                            <tr>
                                <td class="app-n"><?= $nL-- ?></td>
                                <td class="text-nowrap small"><?= date('d/m/Y g:i a', strtotime($l['creado_en'])) ?></td>
                                <td class="small"><?= htmlspecialchars($tipos[$l['tipo']] ?? $l['tipo']) ?></td>
                                <td class="small"><?= htmlspecialchars($l['destinatario']) ?></td>
                                <td class="small"><?= htmlspecialchars($l['asunto']) ?></td>
                                <td><?= $l['estado'] === 'enviado' ? '<span class="app-badge app-badge-success">Enviado</span>' : '<span class="app-badge app-badge-danger">Error</span><div class="small text-danger">' . htmlspecialchars(mb_strimwidth($l['error'] ?? '', 0, 90, '…')) . '</div>' ?></td>
                                <td class="small text-muted"><?= htmlspecialchars($l['usuario'] ?? 'Automático') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    // Si el servidor no responde JSON (archivo faltante, error de PHP), se muestra un mensaje claro
    const post = fd => fetch('includes/correo_accion.php', { method: 'POST', body: fd })
        .then(async r => {
            const t = await r.text();
            let d;
            try { d = JSON.parse(t); } catch (e) {
                throw new Error(r.status === 404 ? 'Falta el archivo includes/correo_accion.php en el servidor.' : 'Respuesta inesperada del servidor (' + r.status + '): ' + t.replace(/<[^>]+>/g, ' ').trim().slice(0, 160));
            }
            if (!d.success) throw new Error(d.error);
            return d;
        });
    const recargarEn = tab => location.assign(location.pathname + '?tab=' + tab);
    // Recordar la pestaña en la URL sin recargar
    document.querySelectorAll('#tabsCorreo [data-tab]').forEach(b => b.addEventListener('shown.bs.tab', () => {
        try { history.replaceState(null, '', '?tab=' + b.dataset.tab); } catch (e) {}
    }));
    document.querySelectorAll('.btn-ver-clave').forEach(b => b.addEventListener('click', () => {
        const i = b.parentElement.querySelector('.campo-clave');
        i.type = i.type === 'password' ? 'text' : 'password';
    }));
    document.querySelectorAll('.form-correo').forEach(f => f.addEventListener('submit', e => {
        e.preventDefault();
        if (!f.checkValidity()) { f.classList.add('was-validated'); return; }
        const b = f.querySelector('[type=submit]'); b.disabled = true;
        post(new FormData(f)).then(d => Swal.fire({ icon: 'success', title: d.message }).then(() => recargarEn(f.elements.perfil.value)))
            .catch(err => Swal.fire('No se pudo guardar', err.message, 'error')).finally(() => b.disabled = false);
    }));
    document.querySelectorAll('.form-resumen').forEach(f => {
        f.addEventListener('submit', e => {
            e.preventDefault();
            const b = f.querySelector('[type=submit]'); b.disabled = true;
            post(new FormData(f)).then(d => Swal.fire({ icon: 'success', title: d.message }).then(() => recargarEn('facturacion')))
                .catch(err => Swal.fire('No se pudo guardar', err.message, 'error')).finally(() => b.disabled = false);
        });
        f.querySelector('.btn-resumen-ver').addEventListener('click', () => {
            const fd = new FormData(); fd.append('accion', 'pendientes_vista');
            Swal.fire({ title: 'Armando el resumen…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
            post(fd).then(d => {
                const fr = document.createElement('iframe');
                fr.style.cssText = 'width:100%;height:65vh;border:1px solid #e2e8f0;border-radius:8px;background:#f1f5f9';
                fr.srcdoc = d.html;
                Swal.fire({ title: d.total ? d.total + ' pendiente' + (d.total === 1 ? '' : 's') : 'Todo al día', width: 760, html: fr, confirmButtonText: 'Cerrar',
                    footer: '<span class="small text-muted">Asunto: ' + d.asunto.replace(/</g, '&lt;') + '<br>Para: ' + (d.para || '(sin destinatarios)').replace(/</g, '&lt;') + (d.total ? '' : '<br>Sin pendientes no se envía automáticamente.') + '</span>' });
            }).catch(err => Swal.fire('No se pudo armar', err.message, 'error'));
        });
        f.querySelector('.btn-resumen-probar').addEventListener('click', () => {
            Swal.fire({ title: '¿Enviar el resumen ahora?', text: 'Se envía a los destinatarios guardados, marcado como prueba (no cuenta como el envío del día).', icon: 'question', showCancelButton: true, confirmButtonText: 'Enviar', cancelButtonText: 'Cancelar' })
                .then(r => {
                    if (!r.isConfirmed) return;
                    const fd = new FormData(); fd.append('accion', 'pendientes_probar');
                    Swal.fire({ title: 'Enviando…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                    post(fd).then(d => Swal.fire({ icon: 'success', title: d.message })).catch(err => Swal.fire('No se pudo enviar', err.message, 'error'));
                });
        });
    });
    document.querySelectorAll('.btn-probar').forEach(b => b.addEventListener('click', () => {
        const para = b.parentElement.querySelector('.para-prueba').value.trim();
        if (!para) return Swal.fire('Indica un correo', 'Escribe a qué correo enviar la prueba.', 'info');
        const fd = new FormData(); fd.append('accion', 'probar'); fd.append('para', para); fd.append('perfil', b.dataset.perfil);
        Swal.fire({ title: 'Enviando…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        post(fd).then(d => Swal.fire({ icon: 'success', title: d.message }))
            .catch(err => Swal.fire('No se pudo enviar', err.message, 'error'));
    }));
})();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
