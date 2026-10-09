<?php
// Configuración → Tasa del dólar (BCH): clave de la Web-API del Banco Central y la tasa del día (compra y venta).
$titulo = 'Tasa del dólar (BCH)';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/correo.php';
require_once '../../includes/tasa_cambio.php';

if (!in_array(USUARIO_ROL, ['admin', 'superadmin'], true)) {
    header('Location: dashboard');
    exit;
}
$cid = (int)cliente_actual();
$instalado = tasaDisponible($pdo);
$claveActual = $instalado ? tasaClaveBch($pdo, $cid) : '';
$tieneClave = $claveActual !== '';
// Para identificar qué llave se pegó: primeros 4 y últimos 4 caracteres
$claveMascara = $tieneClave ? (strlen($claveActual) > 10 ? substr($claveActual, 0, 4) . '••••••••' . substr($claveActual, -4) : '••••••••') : '';
unset($claveActual);
$tasa = $instalado ? tasaUltima($pdo) : null;

require_once '../../includes/templates/header.php';
require_once '../../includes/templates/config_tabs.php';
?>
<div class="app-page-header">
    <div>
        <h1 class="app-page-title"><i class="bi bi-currency-exchange me-2"></i>Tasa del dólar (BCH)</h1>
        <p class="app-page-sub">El sistema usa la tasa del dólar para convertir a lempiras las cuentas en dólares (Bancos y Balance) y las licencias que se pagan en dólares. Con la clave de la Web-API del Banco Central usa la tasa oficial de <strong>compra y venta</strong>; sin clave, una tasa de referencia del mercado.</p>
    </div>
</div>

<?php if (!$instalado): ?>
    <div class="alert alert-warning">Falta instalar el módulo de tasas de cambio.</div>
<?php else: ?>
<div class="row g-3">
    <div class="col-lg-6">
        <div class="app-card app-card-body h-100">
            <h6 class="fw-semibold mb-2">Tasa del día</h6>
            <?php if ($tasa): $twTasa = $tasa; $twId = 'tasaCfg'; require __DIR__ . '/../../includes/templates/tasa_widget.php'; unset($twTasa, $twId); ?>
            <?php else: ?>
                <p class="text-muted">Aún no hay tasa guardada.</p>
            <?php endif; ?>
            <button class="btn btn-outline-primary btn-sm mt-3" id="btnActualizarTasa"><i class="bi bi-arrow-clockwise me-1"></i>Actualizar ahora</button>
            <div class="form-text">Se actualiza sola una vez al día, desde las 12:00 de la noche. Si el BCH no responde, reintenta cada 3 horas hasta las 3 pm y mientras tanto usa la tasa de referencia.</div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="app-card app-card-body h-100">
            <h6 class="fw-semibold mb-2">Clave de la Web-API del BCH</h6>
            <p class="small mb-2">Estado: <?= $tieneClave ? '<span class="text-success fw-semibold"><i class="bi bi-check-circle me-1"></i>Clave guardada</span> <code class="ms-1">' . htmlspecialchars($claveMascara) . '</code>' : '<span class="text-muted">Sin clave: se usa la tasa de referencia</span>' ?></p>
            <ol class="small text-muted ps-3 mb-3">
                <li>Entra a <a href="https://bchapi-am.developer.azure-api.net" target="_blank" rel="noopener">bchapi-am.developer.azure-api.net</a> → <strong>Perfil</strong>.</li>
                <li>En <strong>Suscripciones</strong>, copia la <strong>Llave principal</strong> (dale «Mostrar»).</li>
                <li>Pégala aquí y guarda. Luego dale «Actualizar ahora».</li>
            </ol>
            <form id="formClave" autocomplete="off">
                <div class="input-group">
                    <input type="password" class="form-control" id="claveBch" placeholder="<?= $tieneClave ? 'Pega una clave nueva para cambiarla' : 'Pega aquí la llave principal' ?>" autocomplete="new-password" spellcheck="false">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg me-1"></i>Guardar</button>
                </div>
                <?php if ($tieneClave): ?><button type="button" class="btn btn-link btn-sm text-danger px-0 mt-1" id="btnQuitarClave">Quitar la clave</button><?php endif; ?>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    const accion = async datos => {
        const fd = new FormData(); Object.entries(datos).forEach(([k, v]) => fd.append(k, v));
        const r = await fetch('includes/banco_accion.php', { method: 'POST', body: fd });
        const d = await r.json().catch(() => ({ success: false, error: 'Respuesta inesperada del servidor (' + r.status + ').' }));
        if (!d.success) throw new Error(d.error || d.message);
        return d;
    };
    document.getElementById('formClave')?.addEventListener('submit', async e => {
        e.preventDefault();
        const v = document.getElementById('claveBch').value.trim();
        if (!v) return Swal.fire('Falta la clave', 'Pega la llave principal del portal del BCH.', 'info');
        try { const d = await accion({ accion: 'tasa_clave', clave: v }); await Swal.fire({ icon: 'success', title: 'Listo', text: (d.message || 'Clave guardada.') + ' Ahora dale «Actualizar ahora».' }); location.reload(); }
        catch (err) { Swal.fire('No se pudo guardar', err.message, 'error'); }
    });
    document.getElementById('btnQuitarClave')?.addEventListener('click', async () => {
        if (!(await Swal.fire({ title: 'Quitar la clave del BCH', text: 'Se usará la tasa de referencia del mercado.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Quitar', cancelButtonText: 'Cancelar' })).isConfirmed) return;
        try { await accion({ accion: 'tasa_clave', clave: '' }); location.reload(); } catch (err) { Swal.fire('No se pudo', err.message, 'error'); }
    });
    document.getElementById('btnActualizarTasa')?.addEventListener('click', async () => {
        Swal.fire({ title: 'Consultando la tasa…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
        try { const d = await accion({ accion: 'tasa_actualizar' }); await Swal.fire({ icon: d.aviso ? 'warning' : 'success', title: 'Tasa actualizada', text: d.aviso || d.message || '' }); location.reload(); }
        catch (err) { Swal.fire('No se pudo actualizar', err.message, 'error'); }
    });
})();
</script>
<?php require_once '../../includes/templates/footer.php'; ?>
