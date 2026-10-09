<?php
if (!defined('USUARIO_NOMBRE')) {
	require_once __DIR__ . '/../sesion_inicio.php';
	iniciarSesionSegura();
}

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
	// Usuario + marca del cliente: ya los trajo session.php (sin consulta extra)
	$datos = $__usuarioSesion ?? null;
	if (!$datos) die("Error: no se encontró información del usuario.");
	$datos['usuario_nombre'] = $datos['nombre'];
	$cliente_id = $datos['cliente_id'];
} else {
	$cliente_id = $_SESSION['cliente_seleccionado'] ?? null;
	if ($cliente_id) {
		// Marca del cliente seleccionado: se guarda en sesión por 10 min (cambia muy poco)
		$cacheCli = $_SESSION['__cache_cliente'] ?? null;
		if (!$cacheCli || $cacheCli['id'] != $cliente_id || $cacheCli['t'] < time() - 600) {
			$stmtCliente = $pdo->prepare("SELECT nombre, alias, logo_url, og_image_url, favicon_url, apple_touch_icon_url FROM clientes_saas WHERE id = ?");
			$stmtCliente->execute([$cliente_id]);
			$cacheCli = ['id' => $cliente_id, 't' => time(), 'd' => $stmtCliente->fetch(PDO::FETCH_ASSOC) ?: []];
			$_SESSION['__cache_cliente'] = $cacheCli;
		}
		$cliente = $cacheCli['d'];
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

// Nombre del establecimiento activo: en sesión por 10 min
$cacheEst = $_SESSION['__cache_establecimiento'] ?? null;
if (!$cacheEst || $cacheEst['id'] != $establecimiento_activo || $cacheEst['t'] < time() - 600) {
	$stmtEstab = $pdo->prepare("SELECT nombre FROM establecimientos WHERE establecimiento_id = ?");
	$stmtEstab->execute([$establecimiento_activo]);
	$cacheEst = ['id' => $establecimiento_activo, 't' => time(), 'nombre' => $stmtEstab->fetchColumn() ?: null];
	$_SESSION['__cache_establecimiento'] = $cacheEst;
}
$nombre_establecimiento = $cacheEst['nombre'] ?: 'No asignado';
$alias = $datos['cliente_alias'] ?? $datos['cliente_nombre'] ?? 'Sistema';

// ── Menú lateral (lista única en menu.php) ──────────────────────────────────
require __DIR__ . '/menu.php';
$primerNombre = explode(' ', USUARIO_NOMBRE)[0];
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

	$pageTitle = $tituloActual . ' | Sistema de Facturación';
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

	<!-- Token CSRF: se agrega solo a formularios y llamadas fetch/AJAX (ver includes/csrf.php) -->
	<?= csrf_script() ?>

	<!-- Conexiones anticipadas a los CDN (ahorra tiempo en la primera visita) -->
	<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
	<link rel="preconnect" href="https://cdn.datatables.net" crossorigin>
	<link rel="preconnect" href="https://code.jquery.com" crossorigin>
	<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>

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

	<!-- Iconos: Bootstrap Icons es el set estándar; Font Awesome se mantiene mientras se migran las páginas -->
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

	<!-- CSS global + layout/componentes del sistema -->
	<?php $__cssDir = __DIR__ . '/../../clientes/css/'; // ?v= cambia al modificar el archivo (permite caché larga) ?>
	<link rel="stylesheet" href="../../clientes/css/global.css?v=<?= @filemtime($__cssDir . 'global.css') ?>">
	<link rel="stylesheet" href="../../clientes/css/app.css?v=<?= @filemtime($__cssDir . 'app.css') ?>">
	<script>
		window.APP_BASE = "<?= rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') ?>";
	</script>
</head>


<body class="app-body">
	<script>
		// Restaurar el estado del sidebar en escritorio antes de pintar (evita parpadeo)
		try {
			if (localStorage.getItem('app_sb_collapsed') === '1') document.body.classList.add('app-sb-collapsed');
		} catch (e) {}
	</script>

	<?php require __DIR__ . '/sidebar.php'; ?>

	<!-- ══════════════ BARRA SUPERIOR ══════════════ -->
	<header class="app-topbar">
		<!-- Móvil: abre el menú lateral -->
		<button class="app-burger d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#appSidebar"
			aria-controls="appSidebar" aria-label="Abrir menú">
			<i class="bi bi-list"></i>
		</button>
		<!-- Escritorio: oculta/muestra el menú lateral -->
		<button class="app-burger d-none d-lg-inline-flex" type="button" id="appSidebarToggle"
			aria-label="Mostrar u ocultar menú">
			<i class="bi bi-list"></i>
		</button>

		<div class="app-topbar-title"><?= htmlspecialchars($tituloActual) ?></div>

		<div class="ms-auto d-flex align-items-center gap-3">
			<?php // Tasa del dólar del día (BCH: compra y venta; si no, referencia). Clic → Configuración → Tasa del dólar
			$__tasaTop = null;
			try { require_once __DIR__ . '/../tasa_cambio.php'; $__tasaTop = tasaUltima($pdo); } catch (Throwable $e) { $__tasaTop = null; }
			if ($__tasaTop):
				$__esBch = $__tasaTop['fuente'] === 'BCH';
				$__txt = $__esBch ? 'C ' . number_format((float)$__tasaTop['compra'], 2) . ' · V ' . number_format((float)$__tasaTop['venta'], 2) : 'L ' . number_format((float)$__tasaTop['referencia'], 2);
				$__tit = ($__esBch ? 'Dólar oficial del BCH' : 'Dólar: tasa de referencia del mercado (sin clave del BCH)') . ' al ' . date('d/m/Y', strtotime($__tasaTop['fecha']));
				$__tag = in_array(USUARIO_ROL, ['admin', 'superadmin'], true) ? 'a href="configuracion_tasa"' : 'span'; ?>
				<?php $__corto = 'L ' . number_format((float)($__esBch ? $__tasaTop['venta'] : $__tasaTop['referencia']), 2); ?>
				<<?= $__tag ?> class="app-topbar-tasa" title="<?= htmlspecialchars($__tit) ?>"><i class="bi bi-currency-dollar"></i><span class="d-none d-md-inline"><?= $__txt ?></span><span class="d-md-none"><?= $__corto ?></span><small class="d-none d-sm-inline"><?= $__esBch ? 'BCH' : 'ref.' ?></small></<?= explode(' ', $__tag)[0] ?>>
			<?php endif; unset($__tasaTop, $__esBch, $__txt, $__tit, $__tag, $__corto); ?>
			<span class="app-topbar-greet"><?= $saludo ?> <?= $emoji ?>, <strong><?= htmlspecialchars($primerNombre) ?></strong>!</span>
			<span class="app-avatar d-lg-none" title="<?= htmlspecialchars(USUARIO_NOMBRE) ?>"><?= htmlspecialchars(mb_strtoupper(mb_substr(USUARIO_NOMBRE, 0, 1))) ?></span>
		</div>
	</header>

	<script>
		document.getElementById('appSidebarToggle').addEventListener('click', function () {
			var colapsado = document.body.classList.toggle('app-sb-collapsed');
			try { localStorage.setItem('app_sb_collapsed', colapsado ? '1' : '0'); } catch (e) {}
		});
	</script>

	<!-- Contenido de la página -->
