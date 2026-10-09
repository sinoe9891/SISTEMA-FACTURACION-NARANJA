<?php
// Configuración → Permisos por rol (solo superadmin): qué opciones del menú lateral ve cada rol.
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/permisos.php';

if (USUARIO_ROL !== 'superadmin') {
    header('Location: dashboard');
    exit;
}
$instalada = (bool)$pdo->query("SHOW TABLES LIKE 'permisos_menu'")->fetchColumn();
$aviso = null;
// Un interruptor (al confirmarlo en la página): se guarda al momento y queda en la bitácora
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $instalada && ($_POST['accion'] ?? '') === 'cambiar') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $rol = (string)($_POST['rol'] ?? '');
        $pag = (string)($_POST['pagina'] ?? '');
        if (!preg_match('/^[a-z_]{2,60}$/', $pag)) throw new Exception("Opción no válida.");
        $cambio = permisoGuardar($pdo, $rol, $pag, !empty($_POST['permitido']), (int)USUARIO_ID);
        echo json_encode(['success' => true, 'cambio' => $cambio], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e instanceof PDOException ? 'Error de base de datos.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
// Todos a la vez (lista de pares rol|pagina y los marcados)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $instalada) {
    $pares = array_filter((array)($_POST['pares'] ?? []), fn($p) => preg_match('/^(admin|facturador|lector|nomina)\|[a-z_]{2,60}$/', $p) && permisoConfigurable(explode('|', $p)[1]));
    $marcadas = array_flip((array)($_POST['ver'] ?? []));
    $pdo->beginTransaction();
    foreach ($pares as $p) {
        [$rol, $pag] = explode('|', $p);
        permisoGuardar($pdo, $rol, $pag, isset($marcadas[$p]), (int)USUARIO_ID);
    }
    $pdo->commit();
    header('Location: configuracion_permisos?ok=1');
    exit;
}
if (isset($_GET['ok'])) $aviso = 'Permisos guardados. Los cambios aplican al recargar la página de cada usuario.';

require_once '../../includes/templates/header.php';   // define $menuLateral (completo para el superadmin)
require_once '../../includes/templates/config_tabs.php';
$bitacora = permisosBitacora($pdo, 100);
$nombresOpcion = [];
foreach ($menuLateral as $__its) foreach ($__its as $__it) $nombresOpcion[$__it[0]] = $__it[2];
foreach (PERMISOS_CONFIG as $__p => [, $__t]) $nombresOpcion[$__p] = 'Configuración · ' . $__t;
$usuariosPorRol = $pdo->query("SELECT rol, COUNT(*) FROM usuarios WHERE estado = 'activo' GROUP BY rol")->fetchAll(PDO::FETCH_KEY_PAIR);
?>

<div class="app-page-header">
    <div>
        <h1 class="app-page-title"><i class="bi bi-shield-lock me-2"></i>Permisos por rol</h1>
        <p class="app-page-sub">Encendido = el rol ve esa opción en el menú, abre su página y usa sus acciones. Apagado = no la ve ni la puede abrir. El superadmin siempre ve todo.</p>
    </div>
</div>

<?php if (!$instalada): ?>
    <div class="alert alert-warning">Falta instalar <code>sql/migraciones/2026-10-06_permisos_menu.sql</code>.</div>
<?php else: ?>
    <?php if ($aviso): ?><div class="alert alert-success"><i class="bi bi-check-circle me-1"></i> <?= htmlspecialchars($aviso) ?></div><?php endif; ?>
    <form method="post" class="app-card">
        <div class="table-responsive">
            <table class="table app-table mb-0 align-middle">
                <thead><tr><th>Opción del menú</th>
                    <?php foreach (PERMISOS_ROLES as $r => $t): ?><th class="text-center"><?= $t ?><div class="small text-muted fw-normal"><?= (int)($usuariosPorRol[$r] ?? 0) ?> usuario(s)</div></th><?php endforeach; ?>
                </tr></thead>
                <tbody>
                    <?php foreach ($menuLateral as $seccion => $items): if (!$items || $seccion === 'Plataforma') continue; ?>
                        <tr class="table-light"><td colspan="<?= count(PERMISOS_ROLES) + 1 ?>" class="small fw-semibold text-uppercase text-muted"><?= htmlspecialchars($seccion ?: 'General') ?></td></tr>
                        <?php
                        // «Configuración» se controla por pestaña
                        $filas = [];
                        foreach ($items as $it) {
                            if ($it[0] === 'configuracion') {
                                foreach (PERMISOS_CONFIG as $p => [$ic, $tx]) $filas[] = [$p, $ic, 'Configuración · ' . $tx];
                                $filas[] = ['respaldos', 'bi-database-check', 'Configuración · Respaldos'];
                            } else $filas[] = [$it[0], $it[1], $it[2]];
                        }
                        foreach ($filas as [$pag, $ico, $txt]): ?>
                            <tr>
                                <td><i class="bi <?= htmlspecialchars($ico) ?> me-2 text-muted"></i><?= htmlspecialchars($txt) ?></td>
                                <?php foreach (PERMISOS_ROLES as $r => $t): $par = "$r|$pag"; ?>
                                    <td class="text-center">
                                        <?php if ($pag === 'dashboard'): ?><span class="small text-muted" title="Inicio siempre se ve"><?= $r === 'nomina' ? 'Entra a Colaboradores' : 'Siempre' ?></span>
                                        <?php elseif (!permisoConfigurable($pag)): ?>
                                            <div class="form-check form-switch d-inline-block mb-0" title="<?= $pag === 'respaldos' ? 'Respaldos guarda la base de datos completa: solo el superadmin y el administrador de la empresa dueña.' : 'Solo el superadmin.' ?>"><input class="form-check-input" type="checkbox" disabled <?= $pag === 'respaldos' && $r === 'admin' ? 'checked' : '' ?> aria-label="<?= htmlspecialchars("$t: $txt (fijo)") ?>"></div><i class="bi bi-lock-fill text-muted small ms-1"></i>
                                        <?php else: ?>
                                            <input type="hidden" name="pares[]" value="<?= $par ?>">
                                            <div class="form-check form-switch d-inline-block mb-0"><input class="form-check-input perm-toggle" type="checkbox" name="ver[]" value="<?= $par ?>" data-rol="<?= $r ?>" data-rol-nombre="<?= htmlspecialchars($t) ?>" data-pagina="<?= htmlspecialchars($pag) ?>" data-opcion="<?= htmlspecialchars($txt) ?>" <?= permisoMenu($pdo, $r, $pag) ? 'checked' : '' ?> aria-label="<?= htmlspecialchars("$t: $txt") ?>"></div>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="app-card-body d-flex flex-wrap gap-2 justify-content-between align-items-center border-top">
            <span class="small text-muted"><i class="bi bi-lock-fill"></i> = fijo. Dentro de cada página, eliminar y anular siguen siendo de administradores. Usuarios: un rol que no es admin no crea, edita ni elimina administradores.</span>
            <span class="small text-success"><i class="bi bi-check2-circle me-1"></i>Cada cambio se guarda al confirmarlo y queda en la bitácora.</span>
        </div>
    </form>

    <div class="app-card mt-3" id="perm-bitacora">
        <div class="app-card-header"><span><i class="bi bi-clock-history me-1"></i> Bitácora de permisos</span><span class="app-badge"><?= count($bitacora) ?></span></div>
        <div class="table-responsive">
            <table data-paginar class="table app-table mb-0 align-middle">
                <thead><tr><th>Fecha</th><th>Quién</th><th>Rol</th><th>Opción</th><th>Cambio</th><th>IP</th></tr></thead>
                <tbody>
                    <?php if (!$bitacora): ?><tr><td colspan="6" class="text-center text-muted py-4">Aún no hay cambios registrados.</td></tr><?php endif; ?>
                    <?php foreach ($bitacora as $b): ?>
                        <tr>
                            <td class="small text-nowrap"><?= date('d/m/Y g:i a', strtotime($b['creado_en'])) ?></td>
                            <td class="small"><?= htmlspecialchars($b['usuario'] ?? '—') ?></td>
                            <td class="small"><?= htmlspecialchars(PERMISOS_ROLES[$b['rol']] ?? $b['rol']) ?></td>
                            <td class="small"><?= htmlspecialchars($nombresOpcion[$b['pagina']] ?? $b['pagina']) ?></td>
                            <td><?= (int)$b['despues'] ? '<span class="app-badge app-badge-success">Encendido</span>' : '<span class="app-badge app-badge-danger">Apagado</span>' ?></td>
                            <td class="small text-muted"><?= htmlspecialchars($b['ip'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<script>
// Cada interruptor pide confirmación y se guarda al momento (queda en la bitácora). Si se cancela, vuelve como estaba.
document.querySelectorAll('.perm-toggle').forEach(t => t.addEventListener('change', async () => {
    const on = t.checked, d = t.dataset;
    const r = await Swal.fire({
        icon: on ? 'question' : 'warning',
        title: on ? '¿Darle acceso?' : '¿Quitarle acceso?',
        html: `${on ? 'El rol' : 'El rol'} <strong>${d.rolNombre}</strong> ${on ? 'podrá ver y usar' : 'dejará de ver y usar'} <strong>${d.opcion}</strong>.`,
        showCancelButton: true, confirmButtonText: on ? 'Sí, dar acceso' : 'Sí, quitar acceso', cancelButtonText: 'Cancelar',
        confirmButtonColor: on ? '#16a34a' : '#dc2626',
    });
    if (!r.isConfirmed) { t.checked = !on; return; }
    t.disabled = true;
    try {
        const fd = new FormData();
        fd.append('accion', 'cambiar'); fd.append('rol', d.rol); fd.append('pagina', d.pagina); fd.append('permitido', on ? 1 : 0);
        const res = await fetch(location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' });
        const j = await res.json().catch(() => ({ success: false, error: 'Respuesta inesperada del servidor.' }));
        if (!j.success) throw new Error(j.error);
        Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: `${d.rolNombre}: ${d.opcion} ${on ? 'encendido' : 'apagado'}`, showConfirmButton: false, timer: 2200 });
        // La bitácora se actualiza con el cambio nuevo
        const nueva = new DOMParser().parseFromString(await (await fetch(location.pathname, { credentials: 'same-origin' })).text(), 'text/html').getElementById('perm-bitacora');
        const vieja = document.getElementById('perm-bitacora');
        if (nueva && vieja) vieja.replaceWith(nueva);
    } catch (e) {
        t.checked = !on;
        Swal.fire('No se pudo guardar', e.message, 'error');
    } finally {
        t.disabled = false;
    }
}));
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
