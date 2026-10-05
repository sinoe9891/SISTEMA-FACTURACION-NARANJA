<?php
// Configuración del correo saliente (SMTP) de la empresa y bitácora de envíos.
$titulo = 'Correo (SMTP)';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/correo.php';

if (!in_array(USUARIO_ROL, ['admin', 'superadmin'], true)) {
    header('Location: dashboard');
    exit;
}
$cid = cliente_actual();
$instalado = correoDisponible($pdo);
$cfg = $instalado ? correoConfig($pdo, $cid) : null;
$log = [];
if ($instalado) {
    $st = $pdo->prepare("SELECT e.*, u.nombre AS usuario FROM correos_enviados e LEFT JOIN usuarios u ON u.id = e.usuario_id
                         WHERE e.cliente_id = ? ORDER BY e.id DESC LIMIT 50");
    $st->execute([$cid]);
    $log = $st->fetchAll(PDO::FETCH_ASSOC);
}
$v = fn($k, $d = '') => htmlspecialchars((string)($cfg[$k] ?? $d));
$tipos = ['prueba' => 'Prueba', 'pago_colaborador' => 'Aviso de pago'];

require_once '../../includes/templates/header.php';
?>

<div class="app-page-header">
    <div>
        <h1 class="app-page-title"><i class="bi bi-envelope-at me-2"></i>Correo (SMTP)</h1>
        <p class="app-page-sub">Cuenta de correo con la que el sistema envía avisos (por ejemplo, la notificación de pago a colaboradores).</p>
    </div>
    <?php if ($cfg): ?>
        <span class="app-badge <?= (int)$cfg['activo'] ? 'app-badge-success' : 'app-badge-muted' ?>"><i class="bi bi-<?= (int)$cfg['activo'] ? 'check-circle' : 'pause-circle' ?>"></i> <?= (int)$cfg['activo'] ? 'Envío activo' : 'Envío desactivado' ?></span>
    <?php endif; ?>
</div>

<?php if (!$instalado): ?>
    <div class="alert alert-warning">Falta instalar el módulo: ejecuta <code>sql/migraciones/2026-10-04_correo.sql</code>.</div>
<?php else: ?>
<div class="row g-3">
    <div class="col-lg-7">
        <form class="app-card" id="formCorreo" novalidate autocomplete="off">
            <div class="app-card-header"><span><i class="bi bi-gear me-1"></i> Servidor de salida</span></div>
            <div class="app-card-body row g-3">
                <input type="hidden" name="accion" value="guardar">
                <div class="col-md-6"><label class="form-label">Servidor SMTP *</label><input class="form-control" name="host" value="<?= $v('host') ?>" placeholder="mail.tudominio.com" required></div>
                <div class="col-6 col-md-3"><label class="form-label">Puerto *</label><input class="form-control" type="number" name="puerto" value="<?= $v('puerto', '587') ?>" required></div>
                <div class="col-6 col-md-3"><label class="form-label">Seguridad</label>
                    <select class="form-select" name="seguridad">
                        <?php foreach (['tls' => 'TLS (587)', 'ssl' => 'SSL (465)', 'ninguna' => 'Ninguna'] as $k => $t): ?>
                            <option value="<?= $k ?>" <?= ($cfg['seguridad'] ?? 'tls') === $k ? 'selected' : '' ?>><?= $t ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="col-md-6"><label class="form-label">Usuario *</label><input class="form-control" name="usuario" value="<?= $v('usuario') ?>" placeholder="avisos@tudominio.com" autocomplete="off" required></div>
                <div class="col-md-6"><label class="form-label">Contraseña <?= $cfg ? '' : '*' ?></label>
                    <div class="input-group">
                        <input class="form-control" type="password" name="clave" id="smtpClave" autocomplete="new-password" placeholder="<?= $cfg ? '•••••••• (déjala en blanco para conservarla)' : '' ?>">
                        <button class="btn btn-outline-secondary" type="button" id="verClave" title="Mostrar u ocultar"><i class="bi bi-eye"></i></button>
                    </div>
                    <div class="form-text">Se guarda cifrada y no se vuelve a mostrar.</div></div>
                <div class="col-md-6"><label class="form-label">Correo del remitente *</label><input class="form-control" type="email" name="remitente_email" value="<?= $v('remitente_email') ?>" placeholder="avisos@tudominio.com" required></div>
                <div class="col-md-6"><label class="form-label">Nombre del remitente</label><input class="form-control" name="remitente_nombre" value="<?= $v('remitente_nombre') ?>" placeholder="Administración · Mi empresa"></div>
                <div class="col-md-6"><label class="form-label">Responder a</label><input class="form-control" type="email" name="responder_a" value="<?= $v('responder_a') ?>" placeholder="administracion@tudominio.com">
                    <div class="form-text">Adonde llegan las respuestas de los colaboradores.</div></div>
                <div class="col-md-6"><label class="form-label">Copia oculta (archivo)</label><input class="form-control" type="email" name="copia_oculta" value="<?= $v('copia_oculta') ?>" placeholder="opcional">
                    <div class="form-text">Recibe una copia de cada aviso enviado.</div></div>
                <?php $urlBase = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'facturacion.naranjaymediahn.com'); ?>
                <div class="col-md-6"><label class="form-label">Logo para los correos (PNG o JPG)</label><input class="form-control" name="logo_url" value="<?= $v('logo_url', $cfg ? '' : $urlBase . '/clientes/css/logo-correo.png') ?>" placeholder="https://…/logo.png">
                    <div class="form-text">Gmail y Outlook no muestran logos SVG.</div></div>
                <div class="col-md-6"><label class="form-label">Enlace del logo y del pie</label><input class="form-control" name="enlace_url" value="<?= $v('enlace_url', $cfg ? '' : $urlBase . '/') ?>" placeholder="https://…"></div>
                <div class="col-12">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" name="aviso_pago_auto" id="smtpAuto" value="1" <?= !$cfg || !isset($cfg['aviso_pago_auto']) || (int)$cfg['aviso_pago_auto'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="smtpAuto">Avisar automáticamente a los colaboradores el día del pago, a las</label></div>
                        <select class="form-select form-select-sm" name="aviso_pago_hora" style="width:auto">
                            <?php for ($h = 5; $h <= 20; $h++): ?><option value="<?= $h ?>" <?= (int)($cfg['aviso_pago_hora'] ?? 7) === $h ? 'selected' : '' ?>><?= date('g:i a', mktime($h, 0)) ?></option><?php endfor; ?>
                        </select>
                        <span class="small text-muted">(hora de Honduras)</span>
                    </div>
                    <div class="form-text">Solo pagos con fecha de ese día que aún no tengan aviso. Si ya lo enviaste manualmente, no se repite. Requiere el trabajo de cron (abajo).</div>
                </div>
                <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="verificar_ssl" id="smtpSsl" value="1" <?= !empty($cfg['verificar_ssl']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="smtpSsl">Verificar el certificado SSL del servidor</label>
                    <div class="form-text">Déjalo desactivado en hosting compartido (cPanel) si el certificado está a nombre del servidor y no del dominio del correo.</div></div></div>
                <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="activo" id="smtpActivo" value="1" <?= !$cfg || (int)$cfg['activo'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="smtpActivo">Envío de correos activo</label></div></div>
            </div>
            <div class="app-card-header border-top border-bottom-0 justify-content-end">
                <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i> Guardar</button>
            </div>
        </form>
    </div>
    <div class="col-lg-5">
        <div class="app-card mb-3">
            <div class="app-card-header"><span><i class="bi bi-send-check me-1"></i> Probar el envío</span></div>
            <div class="app-card-body">
                <p class="small text-muted mb-2">Guarda primero la configuración y envía un correo de prueba.</p>
                <div class="input-group">
                    <input class="form-control" type="email" id="paraPrueba" placeholder="tu-correo@dominio.com">
                    <button class="btn btn-outline-primary" id="btnProbar" <?= $cfg ? '' : 'disabled' ?>><i class="bi bi-send me-1"></i> Enviar prueba</button>
                </div>
            </div>
        </div>
        <div class="app-card mb-3">
            <div class="app-card-header"><span><i class="bi bi-clock me-1"></i> Envío automático (cron)</span></div>
            <div class="app-card-body small">
                <p class="mb-2">En cPanel → <strong>Trabajos de cron</strong>, agrega uno <strong>cada 30 minutos</strong> (<code>*/30 * * * *</code>) con este comando:</p>
                <pre class="bg-light border rounded p-2 mb-2" style="white-space:pre-wrap;font-size:11.5px">/usr/local/bin/php <?= htmlspecialchars(realpath(__DIR__ . '/../../cron/avisos_pago.php') ?: dirname(__DIR__, 2) . '/cron/avisos_pago.php') ?> &gt;/dev/null 2&gt;&amp;1</pre>
                <p class="mb-0 text-muted">Hora actual del sistema: <strong><?= date('d/m/Y g:i a') ?></strong> (Honduras).</p>
            </div>
        </div>
        <div class="app-card">
            <div class="app-card-header"><span><i class="bi bi-info-circle me-1"></i> Datos comunes</span></div>
            <div class="app-card-body small">
                <p class="mb-2"><strong>Correo del hosting (cPanel):</strong> servidor <code>mail.tudominio.com</code>, puerto 465 con SSL (o 587 con TLS), usuario = el correo completo.</p>
                <p class="mb-2"><strong>Google Workspace / Gmail:</strong> <code>smtp.gmail.com</code>, 587 TLS. Usa una «contraseña de aplicación», no la contraseña normal.</p>
                <p class="mb-0"><strong>Microsoft 365:</strong> <code>smtp.office365.com</code>, 587 TLS.</p>
            </div>
        </div>
    </div>
</div>

<div class="app-card mt-3">
    <div class="app-card-header"><span><i class="bi bi-clock-history me-1"></i> Últimos envíos</span><span class="app-badge"><?= count($log) ?></span></div>
    <div class="table-responsive">
        <table class="table app-table mb-0">
            <thead><tr><th class="app-n">#</th><th>Fecha</th><th>Tipo</th><th>Destinatario</th><th>Asunto</th><th>Estado</th><th>Usuario</th></tr></thead>
            <tbody>
                <?php if (!$log): ?><tr><td colspan="7" class="text-center text-muted py-4">Aún no se han enviado correos.</td></tr><?php endif; ?>
                <?php $nL = count($log); foreach ($log as $l): ?>
                    <tr>
                        <td class="app-n"><?= $nL-- ?></td>
                        <td class="text-nowrap small"><?= date('d/m/Y H:i', strtotime($l['creado_en'])) ?></td>
                        <td class="small"><?= htmlspecialchars($tipos[$l['tipo']] ?? $l['tipo']) ?></td>
                        <td class="small"><?= htmlspecialchars($l['destinatario']) ?></td>
                        <td class="small"><?= htmlspecialchars($l['asunto']) ?></td>
                        <td><?= $l['estado'] === 'enviado' ? '<span class="app-badge app-badge-success">Enviado</span>' : '<span class="app-badge app-badge-danger" title="' . htmlspecialchars($l['error'] ?? '') . '">Error</span><div class="small text-danger">' . htmlspecialchars(mb_strimwidth($l['error'] ?? '', 0, 90, '…')) . '</div>' ?></td>
                        <td class="small text-muted"><?= htmlspecialchars($l['usuario'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
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
    document.getElementById('verClave')?.addEventListener('click', () => {
        const i = document.getElementById('smtpClave');
        i.type = i.type === 'password' ? 'text' : 'password';
    });
    document.getElementById('formCorreo')?.addEventListener('submit', e => {
        e.preventDefault();
        const f = e.target;
        if (!f.checkValidity()) { f.classList.add('was-validated'); return; }
        const b = f.querySelector('[type=submit]'); b.disabled = true;
        post(new FormData(f)).then(d => Swal.fire({ icon: 'success', title: d.message }).then(() => location.reload()))
            .catch(err => Swal.fire('No se pudo guardar', err.message, 'error')).finally(() => b.disabled = false);
    });
    document.getElementById('btnProbar')?.addEventListener('click', () => {
        const para = document.getElementById('paraPrueba').value.trim();
        if (!para) return Swal.fire('Indica un correo', 'Escribe a qué correo enviar la prueba.', 'info');
        const b = document.getElementById('btnProbar'); b.disabled = true;
        const fd = new FormData(); fd.append('accion', 'probar'); fd.append('para', para);
        Swal.fire({ title: 'Enviando…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        post(fd).then(d => Swal.fire({ icon: 'success', title: d.message }).then(() => location.reload()))
            .catch(err => Swal.fire('No se pudo enviar', err.message, 'error').then(() => location.reload())).finally(() => b.disabled = false);
    });
})();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
