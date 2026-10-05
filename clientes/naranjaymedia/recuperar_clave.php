<?php
// ¿Olvidaste tu contraseña? — pide el correo y envía el enlace para crear una nueva (ver includes/clave_reset.php).
require_once '../../includes/sesion_inicio.php';
iniciarSesionSegura();
require_once '../../includes/db.php';
require_once '../../includes/clave_reset.php';
require_once __DIR__ . '/includes/_login_marca.php';

$enviado = false;
$error = null;
$correo = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correo = trim((string)($_POST['correo'] ?? ''));
    if (!hash_equals($_SESSION['csrf_publico'], (string)($_POST['_csrf'] ?? ''))) {
        $error = 'La página venció. Vuelve a intentarlo.';
    } elseif (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $error = 'Escribe un correo válido.';
    } elseif ($min = intentosBloqueado($pdo, 'reset', $correo)) {
        $error = "Demasiadas solicitudes. Intenta de nuevo en $min minuto(s).";
    } else {
        try {
            claveResetSolicitar($pdo, $correo, (int)$loginEmpresa['id'], $loginSubcarpeta, $loginBase);
        } catch (Throwable $e) {
            error_log('recuperar_clave: ' . $e->getMessage());   // no se muestra: la respuesta es siempre la misma
        }
        $enviado = true;
    }
}
$e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex">
    <title>Recuperar contraseña | <?= $e($loginEmpresa['nombre']) ?></title>
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
                <h1 class="app-login-title">Recuperar contraseña</h1>
                <p class="app-login-sub">Te enviaremos un enlace para crear una nueva.</p>
            </div>
            <?php if ($enviado): ?>
                <div class="alert alert-success d-flex gap-2 py-2" role="status"><i class="bi bi-envelope-check mt-1"></i>
                    <span>Si <strong><?= $e($correo) ?></strong> tiene una cuenta activa, en unos minutos recibirá un correo con el enlace. Vence en <?= CLAVE_RESET_MINUTOS ?> minutos. Revisa también la carpeta de spam.</span></div>
                <a href="./" class="btn btn-outline-secondary w-100"><i class="bi bi-arrow-left me-1"></i> Volver a iniciar sesión</a>
            <?php else: ?>
                <?php if ($error): ?><div class="alert alert-danger d-flex gap-2 py-2" role="alert"><i class="bi bi-exclamation-circle-fill mt-1"></i><span><?= $e($error) ?></span></div><?php endif; ?>
                <form method="POST" novalidate>
                    <input type="hidden" name="_csrf" value="<?= $e($_SESSION['csrf_publico']) ?>">
                    <div class="mb-3">
                        <label for="correo" class="form-label">Correo de tu cuenta</label>
                        <div class="input-group"><span class="input-group-text"><i class="bi bi-envelope"></i></span>
                            <input type="email" class="form-control" id="correo" name="correo" required autofocus autocomplete="username" value="<?= $e($correo) ?>" placeholder="tu@correo.com"></div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 py-2"><i class="bi bi-send me-1"></i> Enviar enlace</button>
                    <a href="./" class="btn btn-link w-100 mt-2">Volver a iniciar sesión</a>
                </form>
            <?php endif; ?>
        </div>
        <p class="app-login-pie">© <?= date('Y') ?> · Sistema de Facturación · Naranja &amp; Media</p>
    </main>
</body>
</html>
