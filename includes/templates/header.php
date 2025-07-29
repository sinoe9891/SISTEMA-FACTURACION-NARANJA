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
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="description" content="Panel principal de facturación de <?= htmlspecialchars($datos['cliente_nombre'] ?? $usuario['cliente_nombre']) ?>">
	<meta property="og:title" content="Dashboard - Sistema de Facturación <?= htmlspecialchars($datos['cliente_nombre'] ?? $usuario['cliente_nombre']) ?>">
	<meta property="og:description" content="Panel de control para visualizar facturación, CAI y métricas importantes">
	<meta property="og:image" content="<?= htmlspecialchars($datos['logo_url'] ?? $usuario['logo_url']) ?>">
	<meta property="og:url" content="<?= $_SERVER['REQUEST_URI'] ?>">
	<title><?= $titulo ?? 'Sistema de Facturación' ?> | <?= htmlspecialchars($datos['cliente_nombre'] ?? $usuario['cliente_nombre']) ?></title>
	<link rel="icon" href="<?= htmlspecialchars($datos['logo_url'] ?? $usuario['logo_url']) ?>" type="image/png">

	<!-- Bootstrap & SweetAlert2 -->
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

	<link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet" />

	<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
	<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
	<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
	<!-- Estilos personalizados opcionales -->
	<!-- <link rel="stylesheet" href="../../../assets/css/custom.css"> -->
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