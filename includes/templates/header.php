<?php
if (!defined('USUARIO_NOMBRE')) session_start();

date_default_timezone_set('America/Tegucigalpa');

$hora = date('H');
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

$usuario_id             = $_SESSION['usuario_id'];
$establecimiento_activo = $_SESSION['establecimiento_activo'] ?? null;
$es_superadmin          = (USUARIO_ROL === 'superadmin');

if (!$establecimiento_activo && !$es_superadmin) {
	header("Location: ./seleccionar_establecimiento");
	exit;
}

$datos = [];

if (!$es_superadmin) {
	$stmt = $pdo->prepare("
        SELECT u.nombre AS usuario_nombre, u.rol,
               c.nombre AS cliente_nombre, c.alias AS cliente_alias, c.logo_url,
               c.og_image_url, c.favicon_url, c.apple_touch_icon_url, c.id AS cliente_id
        FROM usuarios u
        INNER JOIN clientes_saas c ON u.cliente_id = c.id
        WHERE u.id = ?
    ");
	$stmt->execute([$usuario_id]);
	$datos = $stmt->fetch();
	if (!$datos) die("Error: no se encontró información del usuario.");
	$cliente_id = $datos['cliente_id'];
} else {
	$cliente_id = $_SESSION['cliente_seleccionado'] ?? null;
	if ($cliente_id) {
		$stmtCliente = $pdo->prepare("SELECT nombre, alias, logo_url, og_image_url, favicon_url, apple_touch_icon_url FROM clientes_saas WHERE id = ?");
		$stmtCliente->execute([$cliente_id]);
		$cliente = $stmtCliente->fetch(PDO::FETCH_ASSOC);
		$datos['cliente_nombre']       = $cliente['nombre']               ?? 'Cliente no asignado';
		$datos['cliente_alias']       = $cliente['alias']               ?? 'Cliente no asignado';
		$datos['logo_url']             = $cliente['logo_url']             ?? '';
		$datos['og_image_url']         = $cliente['og_image_url']         ?? '';
		$datos['favicon_url']          = $cliente['favicon_url']          ?? '';
		$datos['apple_touch_icon_url'] = $cliente['apple_touch_icon_url'] ?? '';
	} else {
		$datos['cliente_nombre'] = 'Cliente no asignado';
		$datos['logo_url']       = '';
	}
	$datos['usuario_nombre'] = USUARIO_NOMBRE;
	$datos['rol']            = USUARIO_ROL;
}

$stmtEstab = $pdo->prepare("SELECT nombre FROM establecimientos WHERE establecimiento_id = ?");
$stmtEstab->execute([$establecimiento_activo]);
$establecimiento        = $stmtEstab->fetch(PDO::FETCH_ASSOC);
$nombre_establecimiento = $establecimiento ? $establecimiento['nombre'] : 'No asignado';
$alias = $datos['cliente_alias'] ?? $datos['cliente_nombre'] ?? 'Sistema';
?>
<!DOCTYPE html>
<html lang="es">

<head>
	<?php
	$clienteNombre = $datos['cliente_nombre'] ?? 'Sistema de Facturación';
	$clienteLogo   = $datos['logo_url']       ?? '';

	$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
	$fullUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');

	$defaultOgImage    = 'https://www.naranjaymediahn.com/wp-content/uploads/2023/03/Naranja-y-Media-General-ppt.jpg';
	$defaultFavicon    = 'https://www.naranjaymediahn.com/wp-content/uploads/2024/07/cropped-Logo-Naranja-y-Media-23-32x32-1.ico#3700';
	$defaultApple      = 'https://www.naranjaymediahn.com/wp-content/uploads/2024/07/cropped-Logo-Naranja-y-Media-23-192x192-1.ico#3699';
	$defaultNavbarLogo = 'https://www.naranjaymediahn.com/logo.png';

	$ogImage    = !empty($datos['og_image_url'])         ? $datos['og_image_url']         : $defaultOgImage;
	$favicon    = !empty($datos['favicon_url'])           ? $datos['favicon_url']           : $defaultFavicon;
	$appleIcon  = !empty($datos['apple_touch_icon_url'])  ? $datos['apple_touch_icon_url']  : $defaultApple;
	$navbarLogo = $clienteLogo ?: $defaultNavbarLogo;

	$pageTitle = ($titulo ?? 'Dashboard') . ' | Sistema de Facturación';
	$ogTitle   = "Sistema de Facturación | {$clienteNombre}";
	$ogDesc    = "Panel de control del Sistema de Facturación de {$clienteNombre}.";
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

	<!-- Bootstrap 5 -->
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

	<!-- SweetAlert2 -->
	<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

	<!-- DataTables + Bootstrap5 -->
	<link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
	<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
	<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
	<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>

	<!-- Font Awesome -->
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

	<!-- CSS global -->
	<link rel="stylesheet" href="../../clientes/css/global.css">

	<style>
		/* ─── Tokens ─────────────────────────────────── */
		:root {
			--nb-accent: #2563eb;
			/* azul principal              */
			--nb-accent-h: #1d4ed8;
			/* hover del acento            */
			--nb-accent-lt: #eff6ff;
			/* fondo suave del acento      */
			--nb-text: #1e293b;
			/* texto principal             */
			--nb-muted: #64748b;
			/* texto secundario            */
			--nb-border: #e2e8f0;
			/* bordes                      */
			--nb-bg: #ffffff;
			/* fondo navbar                */
			--nb-bg-hover: #f8fafc;
			/* hover de items              */
			--nb-danger: #ef4444;
			--nb-success: #22c55e;
			--nb-radius: 10px;
			--nb-h: 64px;
		}

		/* ─── Navbar ─────────────────────────────────── */
		.navbar {
			background: var(--nb-bg) !important;
			border-bottom: 1px solid var(--nb-border) !important;
			box-shadow: 0 1px 12px rgba(0, 0, 0, .06) !important;
			min-height: var(--nb-h);
			padding: 0 20px !important;
		}

		/* ─── Brand ──────────────────────────────────── */
		.navbar-brand {
			display: flex;
			align-items: center;
			gap: 10px;
			padding: 0 !important;
			margin-right: 24px;
			text-decoration: none;
		}

		.navbar-brand img {
			height: 34px;
			width: auto;
			object-fit: contain;
		}

		.nb-brand-text {
			display: flex;
			flex-direction: column;
			line-height: 1.15;
		}

		.nb-brand-name {
			font-size: 14px;
			font-weight: 700;
			color: var(--nb-text);
			white-space: nowrap;
		}

		.nb-brand-sub {
			font-size: 10.5px;
			color: var(--nb-muted);
			font-weight: 400;
		}

		/* ─── Toggler móvil ──────────────────────────── */
		.navbar-toggler {
			border: 1px solid var(--nb-border) !important;
			border-radius: 8px !important;
			padding: 6px 10px !important;
			color: var(--nb-muted) !important;
			background: var(--nb-bg-hover) !important;
		}

		.navbar-toggler:focus {
			box-shadow: none !important;
		}

		.navbar-toggler-icon {
			background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='%2364748b' stroke-width='2.2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e") !important;
		}

		/* ─── Saludo ─────────────────────────────────── */
		.nb-greeting {
			display: flex;
			align-items: center;
			gap: 6px;
			font-size: 13px;
			color: var(--nb-muted);
			white-space: nowrap;
			margin-right: 8px;
		}

		.nb-greeting strong {
			color: var(--nb-text);
			font-weight: 600;
		}

		/* ─── Chip establecimiento ───────────────────── */
		.nb-estab {
			display: inline-flex;
			align-items: center;
			gap: 5px;
			font-size: 12px;
			font-weight: 500;
			color: var(--nb-accent);
			background: var(--nb-accent-lt);
			border: 1px solid #bfdbfe;
			border-radius: 20px;
			padding: 4px 11px;
			white-space: nowrap;
			margin-right: 8px;
		}

		.nb-estab i {
			font-size: 10px;
		}

		/* ─── Nav links ──────────────────────────────── */
		.navbar-nav .nav-link {
			display: flex;
			align-items: center;
			gap: 6px;
			font-size: 13.5px;
			font-weight: 500;
			color: var(--nb-muted) !important;
			padding: 8px 13px !important;
			border-radius: 8px;
			transition: color .15s, background .15s;
		}

		.navbar-nav .nav-link:hover {
			color: var(--nb-text) !important;
			background: var(--nb-bg-hover);
		}

		.navbar-nav .nav-link.nb-active {
			color: var(--nb-accent) !important;
			background: var(--nb-accent-lt);
		}

		.navbar-nav .nav-link i {
			font-size: 13px;
		}

		/* Arrow del dropdown */
		.navbar-nav .dropdown-toggle::after {
			border: none !important;
			font-family: 'Font Awesome 6 Free';
			font-weight: 900;
			content: "\f078";
			font-size: 9px;
			margin-left: 3px;
			opacity: .45;
		}

		/* ─── Dropdown menu ──────────────────────────── */
		.dropdown-menu {
			background: #ffffff !important;
			border: 1px solid var(--nb-border) !important;
			border-radius: var(--nb-radius) !important;
			box-shadow: 0 8px 28px rgba(0, 0, 0, .1), 0 2px 6px rgba(0, 0, 0, .06) !important;
			padding: 6px !important;
			margin-top: 8px !important;
			min-width: 210px;
		}

		.dropdown-section-label {
			font-size: 10px;
			font-weight: 600;
			letter-spacing: .7px;
			text-transform: uppercase;
			color: var(--nb-muted);
			opacity: .6;
			padding: 8px 12px 3px;
			pointer-events: none;
			display: block;
		}

		.dropdown-item {
			display: flex;
			align-items: center;
			gap: 9px;
			font-size: 13.5px;
			font-weight: 400;
			color: var(--nb-text) !important;
			padding: 8px 12px !important;
			border-radius: 7px;
			transition: background .13s;
		}

		.dropdown-item:hover,
		.dropdown-item:focus {
			background: var(--nb-bg-hover) !important;
			color: var(--nb-accent) !important;
		}

		.dropdown-item i {
			width: 16px;
			text-align: center;
			font-size: 12.5px;
			color: var(--nb-accent);
			opacity: .85;
		}

		.dropdown-item.dd-danger {
			color: var(--nb-danger) !important;
		}

		.dropdown-item.dd-danger i {
			color: var(--nb-danger) !important;
		}

		.dropdown-item.dd-danger:hover {
			background: #fef2f2 !important;
		}

		.dropdown-divider {
			border-color: var(--nb-border) !important;
			margin: 5px 4px !important;
		}

		/* ─── Avatar + Usuario ───────────────────────── */
		.nb-user-btn {
			display: flex !important;
			align-items: center;
			gap: 8px;
		}

		.nb-avatar {
			width: 32px;
			height: 32px;
			border-radius: 50%;
			background: linear-gradient(135deg, var(--nb-accent) 0%, #7c3aed 100%);
			display: flex;
			align-items: center;
			justify-content: center;
			font-size: 12.5px;
			font-weight: 700;
			color: #fff;
			flex-shrink: 0;
			text-transform: uppercase;
		}

		.nb-user-label {
			font-size: 13px;
			font-weight: 600;
			color: var(--nb-text) !important;
			max-width: 130px;
			overflow: hidden;
			text-overflow: ellipsis;
			white-space: nowrap;
		}

		/* Info card dentro del dropdown */
		.nb-user-card {
			padding: 10px 12px 10px;
			border-bottom: 1px solid var(--nb-border);
			margin-bottom: 4px;
		}

		.nb-user-card .nb-uc-name {
			font-size: 13.5px;
			font-weight: 600;
			color: var(--nb-text);
		}

		.nb-user-card .nb-uc-role {
			font-size: 11px;
			color: var(--nb-muted);
			margin-top: 1px;
		}

		.nb-role-badge {
			display: inline-block;
			font-size: 10px;
			font-weight: 600;
			padding: 1px 7px;
			border-radius: 20px;
			background: var(--nb-accent-lt);
			color: var(--nb-accent);
			border: 1px solid #bfdbfe;
			letter-spacing: .3px;
		}

		/* ─── Separador vertical ─────────────────────── */
		.nb-sep {
			width: 1px;
			height: 20px;
			background: var(--nb-border);
			margin: 0 4px;
		}

		/* ─── Status dot ─────────────────────────────── */
		.nb-dot {
			width: 8px;
			height: 8px;
			border-radius: 50%;
			background: var(--nb-success);
			box-shadow: 0 0 0 2px #dcfce7;
			display: inline-block;
		}

		/* ─── Responsive ─────────────────────────────── */
		@media (max-width: 991px) {
			.navbar-collapse {
				background: #fff;
				border: 1px solid var(--nb-border);
				border-radius: var(--nb-radius);
				padding: 10px;
				margin-top: 8px;
				box-shadow: 0 4px 20px rgba(0, 0, 0, .08);
			}

			.nb-greeting,
			.nb-estab {
				display: none !important;
			}

			.nb-sep,
			.nb-dot {
				display: none !important;
			}

			.navbar-nav {
				gap: 2px;
			}

			.dropdown-menu {
				box-shadow: none !important;
				border-color: transparent !important;
				background: var(--nb-bg-hover) !important;
			}

			.dropdown-menu .dropdown-item {
				padding-left: 22px !important;
			}
		}

		@media (max-width: 480px) {
			.navbar {
				padding: 0 14px !important;
			}

			.nb-brand-text {
				display: none;
			}
		}
	</style>
</head>

<body class="bg-light">

	<!-- ══════════════════════════════════════════
     NAVBAR
══════════════════════════════════════════ -->
	<nav class="navbar navbar-expand-lg">

		<!-- Brand -->
		<a class="navbar-brand" href="./dashboard">
			<img src="<?= htmlspecialchars($navbarLogo) ?>" alt="Logo">
			<div class="nb-brand-text">
				<span class="nb-brand-name"><?= htmlspecialchars($alias) ?></span>
				<span class="nb-brand-sub">Sistema de Facturación</span>
			</div>
		</a>

		<!-- Toggler móvil -->
		<button class="navbar-toggler ms-auto" type="button"
			data-bs-toggle="collapse" data-bs-target="#navbarSaas"
			aria-controls="navbarSaas" aria-expanded="false" aria-label="Menú">
			<span class="navbar-toggler-icon"></span>
		</button>

		<div class="collapse navbar-collapse" id="navbarSaas">

			<!-- ── Saludo + establecimiento (izquierda) ── -->
			<div class="d-flex align-items-center me-auto ms-2 flex-wrap gap-1">
				<span class="nb-greeting">
					<?= $saludo ?> <?= $emoji ?>,&nbsp;<strong><?= htmlspecialchars(explode(' ', USUARIO_NOMBRE)[0]) ?>!</strong>
				</span>
				<span class="nb-estab">
					<i class="fa-solid fa-location-dot"></i>
					<?= htmlspecialchars($nombre_establecimiento) ?>
				</span>
			</div>

			<!-- ── Links (derecha) ── -->
			<ul class="navbar-nav align-items-lg-center gap-1 mt-2 mt-lg-0">

				<!-- Inicio -->
				<li class="nav-item">
					<a class="nav-link" href="./dashboard">
						<i class="fa-solid fa-house-chimney"></i> Inicio
					</a>
				</li>

				<!-- Facturación -->
				<li class="nav-item dropdown">
					<a class="nav-link dropdown-toggle" href="#"
						data-bs-toggle="dropdown" aria-expanded="false">
						<i class="fa-solid fa-file-invoice-dollar"></i> Facturación
					</a>
					<ul class="dropdown-menu">
						<li><span class="dropdown-section-label">Documentos</span></li>
						<li>
							<a class="dropdown-item" href="generar_factura">
								<i class="fa-solid fa-plus"></i> Nueva Factura
							</a>
						</li>
						<li>
							<a class="dropdown-item" href="lista_facturas">
								<i class="fa-solid fa-clock-rotate-left"></i> Historial de Facturas
							</a>
						</li>
						<li>
							<hr class="dropdown-divider">
						</li>
						<li><span class="dropdown-section-label">Configuración</span></li>
						<li>
							<a class="dropdown-item" href="configuracion_cai">
								<i class="fa-solid fa-key"></i> Configuración CAI
							</a>
						</li>
					</ul>
				</li>
				<!-- Contratos -->
				<li class="nav-item dropdown">
					<a class="nav-link dropdown-toggle" href="#"
						data-bs-toggle="dropdown" aria-expanded="false">
						<i class="fa-solid fa-file-contract"></i> Contratos
					</a>
					<ul class="dropdown-menu">
						<li><span class="dropdown-section-label">Gestión</span></li>
						<li>
							<a class="dropdown-item" href="contratos">
								<i class="fa-solid fa-list"></i> Lista de Contratos
							</a>
						</li>
						<li>
							<a class="dropdown-item" href="crear_contrato">
								<i class="fa-solid fa-plus"></i> Nuevo Contrato
							</a>
						</li>
						<li>
							<hr class="dropdown-divider">
						</li>
						<li><span class="dropdown-section-label">Finanzas</span></li>
						<li>
							<a class="dropdown-item" href="gastos">
								<i class="fa-solid fa-wallet"></i> Gastos
							</a>
						</li>
						<li>
							<a class="dropdown-item" href="categorias_gastos">
								<i class="fa-solid fa-tags"></i> Categorías de Gastos
							</a>
						</li>
						<li>
							<a class="dropdown-item" href="financiero">
								<i class="fa-solid fa-chart-line"></i> Estado de Resultados
							</a>
						</li>
						<li>
							<hr class="dropdown-divider">
						</li>
						<li><span class="dropdown-section-label">Personal</span></li>
						<li>
							<a class="dropdown-item" href="colaboradores">
								<i class="fa-solid fa-users"></i> Colaboradores
							</a>
						</li>
					</ul>
				</li>
				<!-- Gestión -->
				<li class="nav-item dropdown">
					<a class="nav-link dropdown-toggle" href="#"
						data-bs-toggle="dropdown" aria-expanded="false">
						<i class="fa-solid fa-layer-group"></i> Gestión
					</a>
					<ul class="dropdown-menu">
						<li><span class="dropdown-section-label">Catálogo</span></li>
						<li>
							<a class="dropdown-item" href="productos">
								<i class="fa-solid fa-box"></i> Productos / Servicios
							</a>
						</li>
						<li>
							<a class="dropdown-item" href="clientes">
								<i class="fa-solid fa-users"></i> Clientes
							</a>
						</li>
						<li>
							<a class="dropdown-item" href="productos_clientes">
								<i class="fa-solid fa-tags"></i> Productos Clientes
							</a>
						</li>
						<?php if (in_array(USUARIO_ROL, ['admin', 'superadmin'])): ?>
							<li>
								<hr class="dropdown-divider">
							</li>
							<li><span class="dropdown-section-label">Administración</span></li>
							<li>
								<a class="dropdown-item" href="usuarios">
									<i class="fa-solid fa-user-shield"></i> Usuarios
								</a>
							</li>
						<?php endif; ?>
					</ul>
				</li>

				<!-- Separadores visuales (solo desktop) -->
				<li class="nav-item d-none d-lg-flex align-items-center"><span class="nb-sep"></span></li>
				<li class="nav-item d-none d-lg-flex align-items-center px-1" title="Sistema activo">
					<span class="nb-dot"></span>
				</li>
				<li class="nav-item d-none d-lg-flex align-items-center"><span class="nb-sep"></span></li>

				<!-- Usuario -->
				<li class="nav-item dropdown">
					<a class="nav-link dropdown-toggle nb-user-btn" href="#"
						data-bs-toggle="dropdown" aria-expanded="false">
						<span class="nb-avatar"><?= mb_strtoupper(mb_substr(USUARIO_NOMBRE, 0, 1)) ?></span>
						<span class="nb-user-label"><?= htmlspecialchars(explode(' ', USUARIO_NOMBRE)[0]) ?></span>
					</a>
					<ul class="dropdown-menu dropdown-menu-end">
						<li>
							<div class="nb-user-card">
								<div class="nb-uc-name"><?= htmlspecialchars(USUARIO_NOMBRE) ?></div>
								<div class="nb-uc-role mt-1">
									<span class="nb-role-badge"><?= htmlspecialchars(USUARIO_ROL) ?></span>
								</div>
							</div>
						</li>
						<li>
							<a class="dropdown-item dd-danger" href="logout">
								<i class="fa-solid fa-arrow-right-from-bracket"></i> Cerrar sesión
							</a>
						</li>
					</ul>
				</li>

			</ul>
		</div>
	</nav>

	<!-- Tu contenido aquí -->