<?php
ini_set('session.cookie_path', '/');
session_start();
require_once '../../includes/db.php';

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

error_log("HOST=" . ($_SERVER['HTTP_HOST'] ?? '') . " URI=" . ($_SERVER['REQUEST_URI'] ?? ''));

$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$fullUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');

// Defaults globales
$defaultOgImage = 'https://www.naranjaymediahn.com/wp-content/uploads/2023/03/Naranja-y-Media-General-ppt.jpg';
$defaultFavicon = 'https://www.naranjaymediahn.com/wp-content/uploads/2024/07/cropped-Logo-Naranja-y-Media-23-32x32-1.ico#3700';
$defaultApple   = 'https://www.naranjaymediahn.com/wp-content/uploads/2024/07/cropped-Logo-Naranja-y-Media-23-192x192-1.ico#3699';
$defaultLogoUI  = 'https://www.naranjaymediahn.com/logo.png'; // logo fallback para UI (si no hay logo_url)

$cliente_subcarpeta = strtolower(trim(detectarCliente() ?? ''));
error_log("cliente_detectado=" . $cliente_subcarpeta);

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $correo = $_POST['correo'] ?? '';
    $clave  = $_POST['clave'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE correo = ?");
    $stmt->execute([$correo]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($usuario && password_verify($clave, $usuario['clave'])) {
        $_SESSION['usuario_id'] = $usuario['id'];

        if (($usuario['rol'] ?? '') === 'superadmin') {
            header("Location: ./seleccionar_cliente");
            exit;
        }

        $stmtEstab = $pdo->prepare("SELECT establecimiento_id FROM usuario_establecimientos WHERE usuario_id = ?");
        $stmtEstab->execute([$usuario['id']]);
        $establecimientos = $stmtEstab->fetchAll(PDO::FETCH_COLUMN);

        if (count($establecimientos) === 1) {
            $_SESSION['establecimiento_activo'] = $establecimientos[0];
            header("Location: ./dashboard");
            exit;
        } elseif (count($establecimientos) > 1) {
            $_SESSION['establecimientos'] = $establecimientos;
            header("Location: ./seleccionar_establecimiento");
            exit;
        } else {
            $error = "No tiene establecimientos asignados.";
        }
    } else {
        $error = "Credenciales inválidas.";
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
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>

<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-4">

                <?php if (!empty($logoUi)): ?>
                    <div class="text-center mb-3">
                        <img src="<?= htmlspecialchars($logoUi) ?>" alt="<?= htmlspecialchars($nombre_cliente ?: 'Sistema') ?>" style="max-height: 80px;">
                    </div>
                <?php endif; ?>

                <h4 class="text-center mb-4"><?= htmlspecialchars($nombre_cliente ?: 'Sistema de Facturación') ?></h4>

                <div class="card shadow">
                    <div class="card-body">
                        <form method="POST">
                            <div class="mb-3">
                                <label for="correo" class="form-label">Correo</label>
                                <input type="email" class="form-control" name="correo" required>
                            </div>
                            <div class="mb-3">
                                <label for="clave" class="form-label">Contraseña</label>
                                <input type="password" class="form-control" name="clave" required>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Iniciar sesión</button>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <?php if (!empty($error)): ?>
        <script>
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: '<?= addslashes($error) ?>'
            });
        </script>
    <?php endif; ?>
</body>
</html>
