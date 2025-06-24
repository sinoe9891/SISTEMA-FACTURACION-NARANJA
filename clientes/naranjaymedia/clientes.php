<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';
$usuario_id = $_SESSION['usuario_id'];
$establecimiento_activo = $_SESSION['establecimiento_activo'] ?? null;
$nombre_establecimiento = 'No asignado';

if ($establecimiento_activo) {
	$stmt = $pdo->prepare("SELECT nombre FROM establecimientos WHERE establecimiento_id = ?");
	$stmt->execute([$establecimiento_activo]);
	$nombre_establecimiento = $stmt->fetchColumn() ?: 'No asignado';
}

// Obtener datos del usuario y cliente
$stmt = $pdo->prepare("
SELECT u.nombre AS usuario_nombre, u.rol, c.id AS cliente_id, c.logo_url, c.nombre AS cliente_nombre
FROM usuarios u
INNER JOIN clientes_saas c ON u.cliente_id = c.id
WHERE u.id = ?
");
$stmt->execute([$usuario_id]);
$datos = $stmt->fetch();
$_SESSION['usuario_rol'] = $datos['rol'];
require_once '../../includes/templates/header.php';

if (!in_array($datos['rol'], ['admin', 'superadmin'])) {
	echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
    <script>
        Swal.fire('Acceso denegado', 'Solo los administradores pueden ver esta sección.', 'error')
        .then(() => window.location.href = './dashboard');
    </script>";
	exit;
}

$cliente_id = $datos['cliente_id'];

// Obtener lista de clientes_factura
$stmtClientes = $pdo->prepare("SELECT * FROM clientes_factura WHERE cliente_id = ?");
$stmtClientes->execute([$cliente_id]);
$clientes = $stmtClientes->fetchAll();


?>
<?php if (isset($_GET['created'])): ?>
	<script>
		Swal.fire('Cliente creado', 'El cliente se guardó correctamente.', 'success')
			.then(() => {
				window.history.replaceState(null, '', 'clientes');
				window.location.reload();
			});
	</script>
<?php elseif (isset($_GET['deleted'])): ?>
	<script>
		Swal.fire('Cliente eliminado', 'El cliente ha sido eliminado correctamente.', 'success')
			.then(() => {
				window.history.replaceState(null, '', 'clientes');
				window.location.reload();
			});
	</script>
<?php elseif (isset($_GET['error'])): ?>
	<script>
		Swal.fire('Error', decodeURIComponent("<?= $_GET['error'] ?>"), 'error')
			.then(() => {
				window.history.replaceState(null, '', 'clientes');
				window.location.reload();
			});
	</script>
<?php endif; ?>


<div class="container mt-4">
	<div class="d-flex justify-content-between align-items-center mb-3">
		<div>
			<h4>📄 Lista de Clientes Factura</h4>
			<h6 class="text-muted">
				Sucursal: <?= htmlspecialchars($nombre_establecimiento) ?> |
				Rol: <?= htmlspecialchars(ucfirst($datos['rol'])) ?> |
				Cliente: <?= htmlspecialchars($datos['cliente_nombre']) ?>
			</h6>
		</div>
		<div>
			<?php if (!empty($datos['logo_url'])): ?>
				<img src="<?= htmlspecialchars($datos['logo_url']) ?>" alt="Logo cliente" style="max-height: 60px;">
			<?php endif; ?>
		</div>
	</div>

	<a href="crear_cliente" class="btn btn-success mb-3">➕ Nuevo Cliente</a>

	<table class="table table-bordered table-striped">
		<thead class="table-light">
			<tr>
				<th>Nombre</th>
				<th>RTN</th>
				<th>Dirección</th>
				<th>Teléfono</th>
				<th>Email</th>
				<th>Acciones</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ($clientes as $cliente): ?>
				<tr>
					<td><?= htmlspecialchars($cliente['nombre']) ?></td>
					<td><?= htmlspecialchars($cliente['rtn']) ?></td>
					<td><?= htmlspecialchars($cliente['direccion']) ?></td>
					<td><?= htmlspecialchars($cliente['telefono'] ?? '-') ?></td>
					<td><?= htmlspecialchars($cliente['email'] ?? '-') ?></td>
					<td>
						<a href="editar_cliente?id=<?= $cliente['id'] ?>" class="btn btn-sm btn-primary">✏️ Editar</a>
						<form method="POST" action="eliminar_cliente" style="display:inline;" onsubmit="return confirmarEliminacion(event, this);">
							<input type="hidden" name="id" value="<?= $cliente['id'] ?>">
							<button type="submit" class="btn btn-sm btn-danger">🗑 Eliminar</button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>

<!-- SweetAlert CDN -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<!-- Mensaje de éxito si viene de eliminación -->
<?php if (isset($_GET['deleted'])): ?>
	<script>
		Swal.fire('Eliminado', 'El cliente ha sido eliminado correctamente.', 'success');
	</script>
<?php endif; ?>

<!-- Confirmación de eliminación -->
<script>
	function confirmarEliminacion(e, form) {
		e.preventDefault();
		Swal.fire({
			title: '¿Está seguro?',
			text: 'Esta acción no se puede deshacer.',
			icon: 'warning',
			showCancelButton: true,
			confirmButtonColor: '#d33',
			cancelButtonColor: '#3085d6',
			confirmButtonText: 'Sí, eliminar'
		}).then((result) => {
			if (result.isConfirmed) {
				form.submit();
			}
		});
		return false;
	}
</script>

<?php require_once '../../includes/templates/footer.php'; ?>