<?php
if (!defined('USUARIO_NOMBRE')) session_start();

// Establecer zona horaria de Tegucigalpa (GMT-6)
date_default_timezone_set('America/Tegucigalpa');

$hora = date('H');
$emoji = '👋';
$saludo = 'Hola';

if ($hora >= 5 && $hora < 12) {
	$saludo = '¡Buenos días';
	$emoji = '🌅';
} elseif ($hora >= 12 && $hora < 18) {
	$saludo = '¡Buenas tardes';
	$emoji = '🌞';
} else {
	$saludo = '¡Buenas noches';
	$emoji = '🌙';
}
$usuario_id = $_SESSION['usuario_id'];
$establecimiento_activo = $_SESSION['establecimiento_activo'] ?? null;
$es_superadmin = (USUARIO_ROL === 'superadmin');

if (!$establecimiento_activo && !$es_superadmin) {
	header("Location: ./seleccionar_establecimiento");
	exit;
}

$datos = [];

if (!$es_superadmin) {
	$stmt = $pdo->prepare("
		SELECT u.nombre AS usuario_nombre, u.rol, c.nombre AS cliente_nombre, c.logo_url, c.id AS cliente_id
		FROM usuarios u
		INNER JOIN clientes_saas c ON u.cliente_id = c.id
		WHERE u.id = ?
	");
	$stmt->execute([$usuario_id]);
	$datos = $stmt->fetch();

	if (!$datos) {
		die("Error: no se encontró información del usuario.");
	}

	$cliente_id = $datos['cliente_id'];
} else {
	$cliente_id = $_SESSION['cliente_seleccionado'] ?? null;

	if ($cliente_id) {
		$stmtCliente = $pdo->prepare("SELECT nombre, logo_url FROM clientes_saas WHERE id = ?");
		$stmtCliente->execute([$cliente_id]);
		$cliente = $stmtCliente->fetch(PDO::FETCH_ASSOC);

		$datos['cliente_nombre'] = $cliente ? $cliente['nombre'] : 'Cliente no asignado';
		$datos['logo_url'] = $cliente['logo_url'] ?? '';
	} else {
		$datos['cliente_nombre'] = 'Cliente no asignado';
		$datos['logo_url'] = '';
	}

	$datos['usuario_nombre'] = USUARIO_NOMBRE;
	$datos['rol'] = USUARIO_ROL;
}

// Obtener nombre del establecimiento
$stmtEstab = $pdo->prepare("SELECT nombre FROM establecimientos WHERE establecimiento_id = ?");
$stmtEstab->execute([$establecimiento_activo]);
$establecimiento = $stmtEstab->fetch(PDO::FETCH_ASSOC);
$nombre_establecimiento = $establecimiento ? $establecimiento['nombre'] : 'No asignado';
?>
<!DOCTYPE html>
<html lang="es">

<head>
	<?php
	$clienteNombre = $datos['cliente_nombre'] ?? 'Sistema de Facturación';
	$clienteLogo   = $datos['logo_url'] ?? '';

	// URL completa
	$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
	$fullUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');

	// Defaults
	$defaultOgImage = 'https://www.naranjaymediahn.com/wp-content/uploads/2023/03/Naranja-y-Media-General-ppt.jpg';
	$defaultFavicon = 'https://www.naranjaymediahn.com/wp-content/uploads/2024/07/cropped-Logo-Naranja-y-Media-23-32x32-1.ico#3700';
	$defaultApple   = 'https://www.naranjaymediahn.com/wp-content/uploads/2024/07/cropped-Logo-Naranja-y-Media-23-192x192-1.ico#3699';
	$defaultNavbarLogo = 'https://www.naranjaymediahn.com/logo.png';

	// OG image por defecto: logo del cliente si existe, si no default
	$ogImage = $clienteLogo ?: $defaultOgImage;

	// Cliente desde sesión (si existe)
	$clienteSubdominio = $_SESSION['subdominio_actual'] ?? null;

	// Favicons por cliente (solo naranjaymedia por ahora)
	$favicon   = $defaultFavicon;
	$appleIcon = $defaultApple;

	if ($clienteSubdominio === 'naranjaymedia') {
		$ogImage   = 'https://www.naranjaymediahn.com/wp-content/uploads/2023/03/Naranja-y-Media-General-ppt.jpg';
		$favicon   = 'https://www.naranjaymediahn.com/wp-content/uploads/2024/07/cropped-Logo-Naranja-y-Media-23-32x32-1.ico#3700';
		$appleIcon = 'https://www.naranjaymediahn.com/wp-content/uploads/2024/07/cropped-Logo-Naranja-y-Media-23-192x192-1.ico#3699';
	}

	$pageTitle = ($titulo ?? 'Dashboard') . " | Sistema de Facturación";
	$ogTitle   = "Sistema de Facturación | " . $clienteNombre;
	$ogDesc    = "Panel de control del Sistema de Facturación de {$clienteNombre}. Visualiza facturación, CAI y métricas importantes.";

	// Logo para navbar (preferí logo del cliente si existe, si no uno default liviano)
	$navbarLogo = $clienteLogo ?: $defaultNavbarLogo;
	?>

	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">

	<meta name="description" content="<?= htmlspecialchars($ogDesc) ?>">
	<link rel="canonical" href="<?= htmlspecialchars($fullUrl) ?>">

	<meta property="og:type" content="website">
	<meta property="og:site_name" content="Sistema de Facturación">
	<meta property="og:title" content="<?= htmlspecialchars($ogTitle) ?>">
	<meta property="og:description" content="<?= htmlspecialchars($ogDesc) ?>">
	<meta property="og:image" content="<?= htmlspecialchars($ogImage) ?>">
	<meta property="og:image:alt" content="Sistema de Facturación | <?= htmlspecialchars($clienteNombre) ?>">
	<meta property="og:url" content="<?= htmlspecialchars($fullUrl) ?>">

	<meta name="twitter:card" content="summary_large_image">
	<meta name="twitter:title" content="<?= htmlspecialchars($ogTitle) ?>">
	<meta name="twitter:description" content="<?= htmlspecialchars($ogDesc) ?>">
	<meta name="twitter:image" content="<?= htmlspecialchars($ogImage) ?>">

	<link rel="shortcut icon" href="<?= htmlspecialchars($favicon) ?>" type="image/x-icon">
	<link rel="apple-touch-icon" href="<?= htmlspecialchars($appleIcon) ?>">

	<title><?= htmlspecialchars($pageTitle) ?> | <?= htmlspecialchars($clienteNombre) ?></title>

	<!-- Bootstrap & SweetAlert2 -->
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

	<link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet" />
	<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
	<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
	<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

	<link rel="stylesheet" href="../../clientes/css/global.css">
</head>


<body class="bg-light">

	<!-- Navbar -->
	<nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom px-4 py-2 shadow-sm">
		<a class="navbar-brand d-flex align-items-center" href="dashboard">
			<img src="<?= htmlspecialchars($datos['logo_url'] ?? $usuario['logo_url']) ?>" alt="Logo Cliente" height="40" class="me-2">
			<strong><?= htmlspecialchars($datos['cliente_nombre'] ?? 'Sistema') ?></strong>
		</a>

		<button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSaas"
			aria-controls="navbarSaas" aria-expanded="false" aria-label="Toggle navigation">
			<span class="navbar-toggler-icon"></span>
		</button>

		<div class="collapse navbar-collapse" id="navbarSaas">
			<ul class="navbar-nav ms-auto">
				<li class="nav-item">
					<a class="nav-link" href="./dashboard" role="button" aria-expanded="false">
						Inicio
					</a>
				</li>
				<!-- Facturación -->
				<li class="nav-item dropdown">
					<a class="nav-link dropdown-toggle" href="#" id="menuFacturacion" role="button" data-bs-toggle="dropdown" aria-expanded="false">
						Facturación
					</a>
					<ul class="dropdown-menu" aria-labelledby="menuFacturacion">
						<li><a class="dropdown-item" href="generar_factura">Nueva Factura</a></li>
						<li><a class="dropdown-item" href="lista_facturas">Historial de Facturas</a></li>
						<li><a class="dropdown-item" href="configuracion_cai">Configuración CAI</a></li>
					</ul>
				</li>

				<!-- Gestión -->
				<li class="nav-item dropdown">
					<a class="nav-link dropdown-toggle" href="#" id="menuGestion" role="button" data-bs-toggle="dropdown" aria-expanded="false">
						Gestión
					</a>
					<ul class="dropdown-menu" aria-labelledby="menuGestion">
						<li><a class="dropdown-item" href="productos">Productos / Servicios</a></li>
						<li><a class="dropdown-item" href="clientes">Clientes</a></li>
						<li><a class="dropdown-item" href="productos_clientes">Productos Clientes</a></li>
						<?php if (USUARIO_ROL === 'admin'): ?>
							<li><a class="dropdown-item" href="usuarios">Usuarios</a></li>
						<?php endif; ?>
					</ul>
				</li>
				<li class="nav-item dropdown">
					<a class="nav-link dropdown-toggle" href="#" id="menuUsuario" role="button" data-bs-toggle="dropdown" aria-expanded="false">
						<?= htmlspecialchars(USUARIO_NOMBRE) ?>
					</a>
					<ul class="dropdown-menu dropdown-menu-end" aria-labelledby="menuUsuario">
						<li><a class="dropdown-item text-danger" href="logout">Cerrar sesión</a></li>
					</ul>
				</li>

			</ul>
		</div>
	</nav>