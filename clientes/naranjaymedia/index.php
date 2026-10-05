<?php
require_once '../../includes/sesion_inicio.php';
iniciarSesionSegura();
require_once '../../includes/db.php';
require_once '../../includes/intentos.php';

if (isset($_SESSION['usuario_id'])) {
    header('Location: ./dashboard');
    exit;
}

function detectarCliente()
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    $segments = array_values(array_filter(explode('/', trim($uriPath, '/'))));

    // 1) Por carpeta: buscar el ÚLTIMO "clientes" y tomar el siguiente segmento
    $posCliente = null;
    foreach ($segments as $i => $seg) {
        if ($seg === 'clientes') $posCliente = $i;
    }
    if ($posCliente !== null && !empty($segments[$posCliente + 1])) {
        return strtolower(trim($segments[$posCliente + 1]));
    }

    // 2) Por subdominio: <cliente>.facturacion.tld
    $partes = explode('.', $host);
    if (count($partes) >= 3 && $partes[0] !== 'www' && $partes[0] !== 'facturacion') {
        return strtolower(trim($partes[0]));
    }

    return null;
}

$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$fullUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');

// Defaults globales
$defaultOgImage = 'https://www.naranjaymediahn.com/wp-content/uploads/2023/03/Naranja-y-Media-General-ppt.jpg';
$defaultFavicon = 'https://www.naranjaymediahn.com/wp-content/uploads/2024/07/cropped-Logo-Naranja-y-Media-23-32x32-1.ico#3700';
$defaultApple   = 'https://www.naranjaymediahn.com/wp-content/uploads/2024/07/cropped-Logo-Naranja-y-Media-23-192x192-1.ico#3699';
$defaultLogoUI  = 'https://www.naranjaymediahn.com/logo.png'; // logo fallback para UI (si no hay logo_url)

$cliente_subcarpeta = strtolower(trim(detectarCliente() ?? ''));

$logo_url = null;
$nombre_cliente = null;

$og_image_url = null;
$favicon_url = null;
$apple_touch_icon_url = null;

if ($cliente_subcarpeta) {
    // ✅ traemos branding completo
    $stmt = $pdo->prepare("
        SELECT nombre, logo_url, og_image_url, favicon_url, apple_touch_icon_url 
        FROM clientes_saas 
        WHERE subdominio = ?
        LIMIT 1
    ");
    $stmt->execute([$cliente_subcarpeta]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($cliente) {
        $nombre_cliente = $cliente['nombre'] ?? null;
        $logo_url = $cliente['logo_url'] ?? null;

        $og_image_url = $cliente['og_image_url'] ?? null;
        $favicon_url = $cliente['favicon_url'] ?? null;
        $apple_touch_icon_url = $cliente['apple_touch_icon_url'] ?? null;

        $_SESSION['subdominio_actual'] = $cliente_subcarpeta;
    }
}

// OG y favicons (✅ NO usamos logo_url para OG)
$ogImage = !empty($og_image_url) ? $og_image_url : $defaultOgImage;
$favicon = !empty($favicon_url) ? $favicon_url : $defaultFavicon;
$appleIcon = !empty($apple_touch_icon_url) ? $apple_touch_icon_url : $defaultApple;

// Logo para mostrar en pantalla
$logoUi = !empty($logo_url) ? $logo_url : $defaultLogoUI;

$error = null;
$correoPrevio = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correo = trim((string)($_POST['correo'] ?? ''));
    $clave  = (string)($_POST['clave'] ?? '');
    $correoPrevio = $correo;

    $minutosBloqueo = intentosBloqueado($pdo, 'login', $correo);
    if ($minutosBloqueo) {
        $error = "Demasiados intentos fallidos. Intenta de nuevo en $minutosBloqueo minuto(s).";
    } else {
        $stmt = $pdo->prepare("
            SELECT u.*, c.subdominio, c.estado AS cliente_estado
            FROM usuarios u
            LEFT JOIN clientes_saas c ON c.id = u.cliente_id
            WHERE u.correo = ?
        ");
        $stmt->execute([$correo]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        // Válido solo si: la clave es correcta, el usuario está activo y pertenece a la
        // empresa de esta URL (el superadmin puede entrar por cualquiera).
        $valido = $usuario
            && password_verify($clave, $usuario['clave'])
            && ($usuario['estado'] ?? 'activo') === 'activo'
            && (($usuario['rol'] ?? '') === 'superadmin' || !$cliente_subcarpeta || $usuario['subdominio'] === $cliente_subcarpeta);

        if (!$valido) {
            intentosRegistrarFallo($pdo, 'login', $correo);
            $error = "Credenciales inválidas.";
        } elseif (($usuario['rol'] ?? '') !== 'superadmin' && ($usuario['cliente_estado'] ?? 'activo') === 'inactivo') {
            $error = "Esta empresa está desactivada. Contacta al administrador del sistema.";
        } else {
            intentosLimpiar($pdo, 'login', $correo);

            // Nueva sesión: evita fijación de sesión; el token CSRF se genera de nuevo
            session_regenerate_id(true);
            unset($_SESSION['csrf_token'], $_SESSION['__cache_cliente'], $_SESSION['__cache_establecimiento']);
            $_SESSION['usuario_id'] = $usuario['id'];

            // Actualizar el hash si PHP recomienda un algoritmo/costo más nuevo
            if (password_needs_rehash($usuario['clave'], PASSWORD_DEFAULT)) {
                $pdo->prepare("UPDATE usuarios SET clave = ? WHERE id = ?")->execute([password_hash($clave, PASSWORD_DEFAULT), $usuario['id']]);
            }

            if (($usuario['rol'] ?? '') === 'superadmin') {
                header("Location: ./seleccionar_cliente");
                exit;
            }

            $stmtEstab = $pdo->prepare("SELECT establecimiento_id FROM usuario_establecimientos WHERE usuario_id = ?");
            $stmtEstab->execute([$usuario['id']]);
            $establecimientos = $stmtEstab->fetchAll(PDO::FETCH_COLUMN);

            // ── Fallback: si el usuario no tiene sucursales asignadas, usar las del cliente ──
            if (empty($establecimientos) && !empty($usuario['cliente_id'])) {
                $stmtFallback = $pdo->prepare("SELECT establecimiento_id FROM establecimientos WHERE cliente_id = ?");
                $stmtFallback->execute([$usuario['cliente_id']]);
                $establecimientos = $stmtFallback->fetchAll(PDO::FETCH_COLUMN);
            }

            if (count($establecimientos) === 1) {
                $_SESSION['establecimiento_activo'] = (int)$establecimientos[0];
                header("Location: ./dashboard");
                exit;
            } elseif (count($establecimientos) > 1) {
                $_SESSION['establecimientos'] = $establecimientos;
                header("Location: ./seleccionar_establecimiento");
                exit;
            } else {
                $error = "No hay establecimientos disponibles para tu cliente. Contacta al administrador para crear al menos uno.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />

    <?php
    $tituloOg = "Accede a tu cuenta - " . ($nombre_cliente ?: "Sistema de Facturación");
    $descOg   = "Emite y gestiona tus facturas desde la nube.";
    ?>

    <meta name="description" content="Inicio de sesión para <?= htmlspecialchars($nombre_cliente ?: 'Sistema de Facturación SaaS') ?>" />

    <meta property="og:type" content="website" />
    <meta property="og:site_name" content="Sistema de Facturación" />
    <meta property="og:title" content="<?= htmlspecialchars($tituloOg) ?>" />
    <meta property="og:description" content="<?= htmlspecialchars($descOg) ?>" />
    <meta property="og:image" content="<?= htmlspecialchars($ogImage) ?>" />
    <meta property="og:image:alt" content="Sistema de Facturación | <?= htmlspecialchars($nombre_cliente ?: 'SaaS') ?>" />
    <meta property="og:url" content="<?= htmlspecialchars($fullUrl) ?>" />

    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:title" content="<?= htmlspecialchars($tituloOg) ?>" />
    <meta name="twitter:description" content="<?= htmlspecialchars($descOg) ?>" />
    <meta name="twitter:image" content="<?= htmlspecialchars($ogImage) ?>" />

    <link rel="canonical" href="<?= htmlspecialchars($fullUrl) ?>" />
    <link rel="shortcut icon" href="<?= htmlspecialchars($favicon) ?>" type="image/x-icon" />
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($appleIcon) ?>" />

    <title>Login | <?= htmlspecialchars($nombre_cliente ?: 'Sistema de Facturación') ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
    <link href="../../clientes/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?>" rel="stylesheet" />
</head>

<body class="app-login">
    <main class="app-login-wrap">
        <div class="app-login-card">
            <div class="text-center mb-4">
                <?php if (!empty($logoUi)): ?>
                    <img src="<?= htmlspecialchars($logoUi) ?>" alt="<?= htmlspecialchars($nombre_cliente ?: 'Sistema') ?>" class="app-login-logo">
                <?php endif; ?>
                <h1 class="app-login-title"><?= htmlspecialchars($nombre_cliente ?: 'Sistema de Facturación') ?></h1>
                <p class="app-login-sub">Inicia sesión para continuar</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger d-flex align-items-start gap-2 py-2" role="alert">
                    <i class="bi bi-exclamation-circle-fill mt-1"></i><span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" id="formLogin" novalidate>
                <div class="mb-3">
                    <label for="correo" class="form-label">Correo</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="email" class="form-control" id="correo" name="correo" required autocomplete="username"
                            autofocus value="<?= htmlspecialchars($correoPrevio) ?>" placeholder="tu@correo.com">
                    </div>
                </div>
                <div class="mb-4">
                    <label for="clave" class="form-label">Contraseña</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" class="form-control" id="clave" name="clave" required autocomplete="current-password" placeholder="••••••••">
                        <button class="btn btn-outline-secondary" type="button" id="verClave" aria-label="Mostrar contraseña" aria-pressed="false" title="Mostrar contraseña">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <div class="form-text d-none" id="avisoMayus"><i class="bi bi-capslock"></i> Bloq Mayús está activado</div>
                    <div class="text-end mt-2"><a href="recuperar_clave" class="small">¿Olvidaste tu contraseña?</a></div>
                </div>
                <button type="submit" class="btn btn-primary w-100 py-2" id="btnEntrar">
                    <span class="btn-texto"><i class="bi bi-box-arrow-in-right me-1"></i> Iniciar sesión</span>
                    <span class="btn-cargando d-none"><span class="spinner-border spinner-border-sm me-1"></span> Entrando…</span>
                </button>
            </form>
        </div>
        <p class="app-login-pie">© <?= date('Y') ?> · Sistema de Facturación · Naranja &amp; Media</p>
    </main>

    <script>
        (function () {
            var clave = document.getElementById('clave');
            var btnVer = document.getElementById('verClave');
            btnVer.addEventListener('click', function () {
                var mostrar = clave.type === 'password';
                clave.type = mostrar ? 'text' : 'password';
                btnVer.innerHTML = mostrar ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
                btnVer.setAttribute('aria-pressed', mostrar ? 'true' : 'false');
                btnVer.setAttribute('aria-label', mostrar ? 'Ocultar contraseña' : 'Mostrar contraseña');
                btnVer.title = mostrar ? 'Ocultar contraseña' : 'Mostrar contraseña';
                clave.focus();
            });
            // Aviso de Bloq Mayús
            clave.addEventListener('keyup', function (e) {
                document.getElementById('avisoMayus').classList.toggle('d-none', !(e.getModifierState && e.getModifierState('CapsLock')));
            });
            // Validación simple y botón "Entrando…" (evita doble envío)
            document.getElementById('formLogin').addEventListener('submit', function (e) {
                if (!this.checkValidity()) {
                    e.preventDefault();
                    this.classList.add('was-validated');
                    return;
                }
                var b = document.getElementById('btnEntrar');
                b.disabled = true;
                b.querySelector('.btn-texto').classList.add('d-none');
                b.querySelector('.btn-cargando').classList.remove('d-none');
            });
        })();
    </script>
</body>
</html>
