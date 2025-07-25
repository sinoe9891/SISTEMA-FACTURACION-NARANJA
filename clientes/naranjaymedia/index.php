<?php
ini_set('session.cookie_path', '/');
session_start();
require_once '../../includes/db.php';

if (isset($_SESSION['usuario_id'])) {
	header('Location: ./dashboard');
	exit;
}

function detectarSubdominio()
{
	if (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false) {
		// Detectar la última carpeta de la URL
		$path = trim($_SERVER['REQUEST_URI'], '/');
		$segments = explode('/', $path);
		return end($segments); // devuelve 'ccic'
	} else {
		// En producción: ccic.facturacion.com
		$host = $_SERVER['HTTP_HOST'];
		$partes = explode('.', $host);
		return $partes[0]; // devuelve 'ccic'
	}
}

$password = 'admin123$$**';
$hash = password_hash($password, PASSWORD_BCRYPT);
// echo $hash;
$cliente_subcarpeta = detectarSubdominio();
$logo_url = null;
$nombre_cliente = null;

if ($cliente_subcarpeta) {
	$stmt = $pdo->prepare("SELECT nombre, logo_url FROM clientes_saas WHERE subdominio = ?");
	$stmt->execute([$cliente_subcarpeta]);
	$cliente = $stmt->fetch();

	if ($cliente) {
		$logo_url = $cliente['logo_url'];
		$nombre_cliente = $cliente['nombre'];
		$_SESSION['subdominio_actual'] = $cliente_subcarpeta;
	}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$correo = $_POST['correo'] ?? '';
	$clave = $_POST['clave'] ?? '';

	$stmt = $pdo->prepare("SELECT * FROM usuarios WHERE correo = ?");
	$stmt->execute([$correo]);
	$usuario = $stmt->fetch();

	if ($usuario && password_verify($clave, $usuario['clave'])) {
		$_SESSION['usuario_id'] = $usuario['id'];

		if ($usuario['rol'] === 'superadmin') {
			header("Location: ./seleccionar_cliente");
			exit;
		}

		// Lógica actual para admin y facturador
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
	}
}

?>

<!DOCTYPE html>
<html lang="es">

<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<meta name="description" content="Inicio de sesión para <?= $nombre_cliente ?: 'Sistema de Facturación SaaS' ?>" />
	<meta property="og:title" content="Accede a tu cuenta - <?= $nombre_cliente ?: 'Sistema de Facturación' ?>" />
	<meta property="og:description" content="Emite y gestiona tus facturas desde la nube" />
	<meta property="og:image" content="<?= $logo_url ?: 'https://www.naranjaymediahn.com/logo.png' ?>" />
	<meta property="og:url" content="<?= $_SERVER['REQUEST_URI'] ?>" />
	<title>Login | <?= $nombre_cliente ?: 'Sistema de Facturación' ?></title>
	<link rel="icon" href="<?= $logo_url ?: 'https://www.naranjaymediahn.com/wp-content/uploads/2023/03/favicon.svg' ?>" type="image/svg+xml" />
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
	<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>

<body class="bg-light">

	<div class="container mt-5">
		<div class="row justify-content-center">
			<div class="col-md-4">
				<?php if ($logo_url): ?>
					<div class="text-center mb-3">
						<img src="<?= htmlspecialchars($logo_url) ?>" alt="<?= htmlspecialchars($nombre_cliente) ?>" style="max-height: 80px;">
					</div>
				<?php endif; ?>
				<h4 class="text-center mb-4"><?= $nombre_cliente ?: 'Sistema de Facturación' ?></h4>
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
				text: '<?= $error ?>'
			});
		</script>
	<?php endif; ?>
</body>

</html>