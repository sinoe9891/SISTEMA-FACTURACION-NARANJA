<?php
// Página pública a la que se llega al cerrar la sesión (a mano o por inactividad): mismo diseño y logo del inicio de sesión
//   ?m=inactividad → cerrada por 5 minutos sin usar el sistema · ?m=salida → la persona cerró sesión · sin m → mensaje general
require_once '../../includes/sesion_inicio.php';
require_once '../../includes/db.php';
require_once '../../includes/version.php';

// Empresa de la URL (/clientes/<empresa>/…) para su nombre y logo, igual que el inicio de sesión
$segs = array_values(array_filter(explode('/', trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/'))));
$empresa = null;
foreach ($segs as $i => $seg) if ($seg === 'clientes') $empresa = strtolower($segs[$i + 1] ?? '') ?: null;
$nombreCliente = null;
$logoUi = 'https://www.naranjaymediahn.com/logo.png';
$favicon = 'https://www.naranjaymediahn.com/wp-content/uploads/2024/07/cropped-Logo-Naranja-y-Media-23-32x32-1.ico#3700';
if ($empresa) {
    $st = $pdo->prepare("SELECT nombre, logo_url, favicon_url FROM clientes_saas WHERE subdominio = ? LIMIT 1");
    $st->execute([$empresa]);
    if ($c = $st->fetch(PDO::FETCH_ASSOC)) {
        $nombreCliente = $c['nombre'] ?: null;
        if (!empty($c['logo_url'])) $logoUi = $c['logo_url'];
        if (!empty($c['favicon_url'])) $favicon = $c['favicon_url'];
    }
}

$m = $_GET['m'] ?? '';
[$icono, $titulo, $texto] = match ($m) {
    'inactividad' => ['bi-clock-history', 'Tu sesión ha sido cerrada', 'Por seguridad cerramos tu sesión después de ' . ($__min = max(1, (int)round(SESION_INACTIVIDAD_SEG / 60))) . ($__min === 1 ? ' minuto' : ' minutos') . ' sin usar el sistema. No se perdió nada de lo que ya estaba guardado.'],
    'salida'      => ['bi-check-circle', 'Cerraste tu sesión', 'Gracias por usar el sistema. ¡Hasta pronto!'],
    default       => ['bi-shield-lock', 'Tu sesión ha sido cerrada', 'Inicia sesión de nuevo para continuar.'],
};
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="robots" content="noindex" />
    <title>Sesión cerrada | <?= htmlspecialchars($nombreCliente ?: 'Sistema de Facturación') ?></title>
    <link rel="shortcut icon" href="<?= htmlspecialchars($favicon) ?>" type="image/x-icon" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
    <link href="../../clientes/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?>" rel="stylesheet" />
</head>
<body class="app-login">
    <main class="app-login-wrap">
        <div class="app-login-card text-center">
            <img src="<?= htmlspecialchars($logoUi) ?>" alt="<?= htmlspecialchars($nombreCliente ?: 'Sistema') ?>" class="app-login-logo">
            <h1 class="app-login-title mb-4"><?= htmlspecialchars($nombreCliente ?: 'Sistema de Facturación') ?></h1>
            <div class="mb-3" style="font-size:2.6rem;line-height:1;color:var(--bs-primary,#1e40af)"><i class="bi <?= $icono ?>"></i></div>
            <h2 class="h5 fw-bold mb-2"><?= htmlspecialchars($titulo) ?></h2>
            <p class="text-muted mb-4"><?= htmlspecialchars($texto) ?></p>
            <a href="./" class="btn btn-primary w-100 py-2"><i class="bi bi-box-arrow-in-right me-1"></i> Iniciar sesión de nuevo</a>
        </div>
        <p class="app-login-pie">© <?= date('Y') ?> · <?= APP_NOMBRE ?> v<?= APP_VERSION ?> · Naranja &amp; Media</p>
    </main>
</body>
</html>
