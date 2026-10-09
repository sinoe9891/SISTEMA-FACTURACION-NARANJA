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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $instalada) {
    // Se recibe la lista de opciones configurables (pares rol|pagina) y las marcadas
    $pares = array_filter((array)($_POST['pares'] ?? []), fn($p) => preg_match('/^(admin|facturador|lector|nomina)\|[a-z_]{2,60}$/', $p) && permisoConfigurable(explode('|', $p)[1]));
    $marcadas = array_flip((array)($_POST['ver'] ?? []));
    $st = $pdo->prepare("INSERT INTO permisos_menu (rol, pagina, permitido, actualizado_por) VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE permitido = VALUES(permitido), actualizado_por = VALUES(actualizado_por)");
    $pdo->beginTransaction();
    foreach ($pares as $p) {
        [$rol, $pag] = explode('|', $p);
        if (!permisoDisponible($rol, $pag)) continue;
        $st->execute([$rol, $pag, isset($marcadas[$p]) ? 1 : 0, (int)USUARIO_ID]);
    }
    $pdo->commit();
    header('Location: configuracion_permisos?ok=1');
    exit;
}
if (isset($_GET['ok'])) $aviso = 'Permisos guardados. Los cambios aplican al recargar la página de cada usuario.';

require_once '../../includes/templates/header.php';   // define $menuLateral (completo para el superadmin)
require_once '../../includes/templates/config_tabs.php';
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
                                            <div class="form-check form-switch d-inline-block mb-0"><input class="form-check-input" type="checkbox" name="ver[]" value="<?= $par ?>" <?= permisoMenu($pdo, $r, $pag) ? 'checked' : '' ?> aria-label="<?= htmlspecialchars("$t: $txt") ?>"></div>
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
            <button class="btn btn-primary"><i class="bi bi-floppy me-1"></i> Guardar permisos</button>
        </div>
    </form>
<?php endif; ?>

<?php require_once '../../includes/templates/footer.php'; ?>
