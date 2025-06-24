<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

$usuario_id = $_SESSION['usuario_id'];

// Obtener info de usuario y cliente
$stmt = $pdo->prepare("
	SELECT u.rol, c.id AS cliente_id, c.nombre AS cliente_nombre, c.logo_url
	FROM usuarios u
	INNER JOIN clientes_saas c ON u.cliente_id = c.id
	WHERE u.id = ?
");
$stmt->execute([$usuario_id]);
$datos = $stmt->fetch();
$cliente_id = $datos['cliente_id'];
$_SESSION['usuario_rol'] = $datos['rol'];

require_once '../../includes/templates/header.php';

// Validar permisos
if (!in_array($datos['rol'], ['admin', 'superadmin'])) {
	echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
	<script>
	Swal.fire('Acceso denegado', 'Solo administradores pueden editar clientes.', 'error')
	.then(() => window.location.href = 'clientes');
	</script>";
	exit;
}

// Validar ID recibido
if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) {
	echo "<script>
	Swal.fire('ID inválido', 'No se pudo cargar el cliente.', 'error')
	.then(() => window.location.href = 'clientes');
	</script>";
	exit;
}

$cliente_id_factura = (int) $_GET['id'];

$stmt = $pdo->prepare("SELECT * FROM clientes_factura WHERE id = ? AND cliente_id = ?");
$stmt->execute([$cliente_id_factura, $cliente_id]);
$cliente = $stmt->fetch();

if (!$cliente) {
	echo "<script>
	Swal.fire('No encontrado', 'El cliente no existe o no pertenece a tu cuenta.', 'error')
	.then(() => window.location.href = 'clientes');
	</script>";
	exit;
}

// Obtener nombre del establecimiento
$establecimiento_activo = $_SESSION['establecimiento_activo'] ?? null;
$nombre_establecimiento = 'No asignado';
if ($establecimiento_activo) {
	$stmt = $pdo->prepare("SELECT nombre FROM establecimientos WHERE establecimiento_id = ?");
	$stmt->execute([$establecimiento_activo]);
	$nombre_establecimiento = $stmt->fetchColumn() ?: 'No asignado';
}
?>

<div class="container mt-4">
	<div class="d-flex justify-content-between align-items-center mb-3">
		<div>
			<h4>✏️ Editar Cliente Factura</h4>
			<h6 class="text-muted">
				Sucursal: <?= htmlspecialchars($nombre_establecimiento) ?> |
				Rol: <?= htmlspecialchars(ucfirst($datos['rol'])) ?> |
				Cliente: <?= htmlspecialchars($datos['cliente_nombre']) ?>
			</h6>
		</div>
		<?php if (!empty($datos['logo_url'])): ?>
			<img src="<?= htmlspecialchars($datos['logo_url']) ?>" alt="Logo cliente" style="max-height: 60px;">
		<?php endif; ?>
	</div>

	<form method="POST" action="actualizar_cliente">
		<input type="hidden" name="id" value="<?= $cliente['id'] ?>">
		<div class="mb-3">
			<label>Nombre</label>
			<input type="text" name="nombre" class="form-control" value="<?= htmlspecialchars($cliente['nombre']) ?>" required>
		</div>
		<div class="mb-3">
			<label>RTN</label>
			<input type="text" name="rtn" class="form-control" value="<?= htmlspecialchars($cliente['rtn']) ?>" required>
		</div>
		<div class="mb-3">
			<label>Dirección</label>
			<textarea name="direccion" class="form-control" required><?= htmlspecialchars($cliente['direccion']) ?></textarea>
		</div>
		<div class="mb-3">
			<label>Teléfono</label>
			<input type="text" name="telefono" class="form-control" value="<?= htmlspecialchars($cliente['telefono']) ?>">
		</div>
		<div class="mb-3">
			<label>Email</label>
			<input type="email" name="email" class="form-control" value="<?= htmlspecialchars($cliente['email']) ?>">
		</div>
		<button type="submit" class="btn btn-primary">💾 Actualizar Cliente</button>
		<a href="clientes" class="btn btn-secondary">Cancelar</a>
	</form>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<?php require_once '../../includes/templates/footer.php'; ?>
