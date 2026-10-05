<?php
// Crear contraseña nueva desde el enlace del correo (?token=…). Ver includes/clave_reset.php.
require_once '../../includes/sesion_inicio.php';
iniciarSesionSegura();
require_once '../../includes/db.php';
require_once '../../includes/clave_reset.php';
require_once __DIR__ . '/includes/_login_marca.php';
header('Referrer-Policy: no-referrer');   // que el token no salga en el encabezado Referer

$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$usuario = claveResetValidar($pdo, $token);
$listo = false;
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $usuario) {
    if (!hash_equals($_SESSION['csrf_publico'], (string)($_POST['_csrf'] ?? ''))) {
        $error = 'La página venció. Vuelve a intentarlo.';
    } else {
        $error = claveResetAplicar($pdo, $token, (string)($_POST['clave'] ?? ''), (string)($_POST['clave2'] ?? ''));
        $listo = $error === null;
    }
}
$e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex"><meta name="referrer" content="no-referrer">
    <title>Nueva contraseña | <?= $e($loginEmpresa['nombre']) ?></title>
    <?php if ($loginEmpresa['favicon_url']): ?><link rel="shortcut icon" href="<?= $e($loginEmpresa['favicon_url']) ?>"><?php endif; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../../clientes/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?>" rel="stylesheet">
</head>
<body class="app-login">
    <main class="app-login-wrap">
        <div class="app-login-card">
            <div class="text-center mb-4">
                <img src="<?= $e($loginLogo) ?>" alt="<?= $e($loginEmpresa['nombre']) ?>" class="app-login-logo">
                <h1 class="app-login-title">Nueva contraseña</h1>
                <?php if ($usuario && !$listo): ?><p class="app-login-sub">Para <?= $e($usuario['correo']) ?></p><?php endif; ?>
            </div>
            <?php if ($listo): ?>
                <div class="alert alert-success d-flex gap-2 py-2" role="status"><i class="bi bi-check-circle-fill mt-1"></i><span>Listo: tu contraseña cambió. Ya puedes iniciar sesión con la nueva.</span></div>
                <a href="./" class="btn btn-primary w-100"><i class="bi bi-box-arrow-in-right me-1"></i> Iniciar sesión</a>
            <?php elseif (!$usuario): ?>
                <div class="alert alert-warning d-flex gap-2 py-2" role="alert"><i class="bi bi-hourglass-bottom mt-1"></i><span>Este enlace no es válido, ya se usó o venció (dura <?= CLAVE_RESET_MINUTOS ?> minutos).</span></div>
                <a href="recuperar_clave" class="btn btn-primary w-100"><i class="bi bi-envelope me-1"></i> Pedir un enlace nuevo</a>
            <?php else: ?>
                <?php if ($error): ?><div class="alert alert-danger d-flex gap-2 py-2" role="alert"><i class="bi bi-exclamation-circle-fill mt-1"></i><span><?= $e($error) ?></span></div><?php endif; ?>
                <form method="POST" novalidate autocomplete="off">
                    <input type="hidden" name="_csrf" value="<?= $e($_SESSION['csrf_publico']) ?>">
                    <input type="hidden" name="token" value="<?= $e($token) ?>">
                    <input type="email" class="d-none" name="usuario" value="<?= $e($usuario['correo']) ?>" autocomplete="username" tabindex="-1" aria-hidden="true">
                    <div class="mb-3">
                        <label for="clave" class="form-label">Contraseña nueva</label>
                        <div class="input-group"><span class="input-group-text"><i class="bi bi-lock"></i></span>
                            <input type="password" class="form-control" id="clave" name="clave" required autofocus autocomplete="new-password" minlength="10"></div>
                        <div class="form-text">Mínimo 10 caracteres, con mayúsculas, minúsculas y números.</div>
                    </div>
                    <div class="mb-4">
                        <label for="clave2" class="form-label">Repítela</label>
                        <div class="input-group"><span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                            <input type="password" class="form-control" id="clave2" name="clave2" required autocomplete="new-password" minlength="10"></div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 py-2"><i class="bi bi-check2-circle me-1"></i> Guardar contraseña</button>
                </form>
            <?php endif; ?>
        </div>
        <p class="app-login-pie">© <?= date('Y') ?> · Sistema de Facturación · Naranja &amp; Media</p>
    </main>
</body>
</html>
