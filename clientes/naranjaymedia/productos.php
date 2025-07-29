<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';

// Seguridad y contexto
$usuario_id = $_SESSION['usuario_id'];
$establecimiento_activo = $_SESSION['establecimiento_activo'] ?? null;
$es_superadmin = (USUARIO_ROL === 'superadmin');

// Validación de establecimiento activo
if (!$establecimiento_activo && !$es_superadmin) {
	header("Location: ./seleccionar_establecimiento");
	exit;
}

// Obtener cliente_id
if (!$es_superadmin) {
	$stmt = $pdo->prepare("SELECT c.id, c.nombre, c.logo_url FROM usuarios u INNER JOIN clientes_saas c ON u.cliente_id = c.id WHERE u.id = ?");
	$stmt->execute([$usuario_id]);
	$cliente = $stmt->fetch();
	$cliente_id = $cliente['id'];
} else {
	$cliente_id = $_SESSION['cliente_seleccionado'] ?? null;
	if (!$cliente_id) die("Cliente no seleccionado.");
	$stmt = $pdo->prepare("SELECT nombre, logo_url FROM clientes_saas WHERE id = ?");
	$stmt->execute([$cliente_id]);
	$cliente = $stmt->fetch();
}

require_once '../../includes/templates/header.php';

// Obtener productos
$stmt = $pdo->prepare("SELECT * FROM productos WHERE cliente_id = ?");
$stmt->execute([$cliente_id]);
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container mt-4">
	<div class="d-flex justify-content-between align-items-center mb-3">
		<h4>🛍️ Productos de <?= htmlspecialchars($cliente['nombre']) ?></h4>
		<?php if ($cliente['logo_url']): ?>
			<img src="<?= $cliente['logo_url'] ?>" alt="Logo Cliente" style="max-height: 50px;">
		<?php endif; ?>
	</div>

	<!-- Botón agregar -->
	<div class="mb-3">
		<button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalAgregarProducto">➕ Agregar Producto</button>
	</div>

	<!-- Tabla -->
	<div class="table-responsive">
		<table class="table table-bordered" id="tabla-productos">
			<thead class="table-dark">
				<tr>
					<th>ID</th>
					<th>Nombre</th>
					<th>Descripción</th>
					<th>Precio</th>
					<th>ISV (%)</th>
					<th>Acciones</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($productos as $p): ?>
					<tr>
						<td><?= $p['id'] ?></td>
						<td><?= htmlspecialchars($p['nombre']) ?></td>
						<td><?= htmlspecialchars($p['descripcion']) ?></td>
						<td>L <?= number_format($p['precio'], 2) ?></td>
						<td><?= $p['tipo_isv'] ?>%</td>
						<td>
							<button class="btn btn-sm btn-info" onclick="editarProducto(<?= htmlspecialchars(json_encode($p)) ?>)">✏️</button>
							<button class="btn btn-sm btn-danger" onclick="eliminarProducto(<?= $p['id'] ?>)">🗑️</button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>

<!-- MODAL AGREGAR -->
<div class="modal fade" id="modalAgregarProducto" tabindex="-1">
	<div class="modal-dialog">
		<form method="POST" action="includes/prod_guardar.php" class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title">Agregar Producto</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
			</div>
			<div class="modal-body">
				<input type="hidden" name="cliente_id" value="<?= $cliente_id ?>">
				<div class="mb-2">
					<label>Nombre</label>
					<input type="text" name="nombre" class="form-control" required>
				</div>
				<div class="mb-2">
					<label>Descripción</label>
					<textarea name="descripcion" class="form-control" required></textarea>
				</div>
				<div class="mb-2">
					<label>Precio</label>
					<input type="number" step="0.01" name="precio" class="form-control" required>
				</div>
				<div class="mb-2">
					<label>ISV</label>
					<select name="tipo_isv" class="form-select" required>
						<option value="15">15%</option>
						<option value="18">18%</option>
						<option value="0">0%</option>
					</select>
				</div>
			</div>
			<div class="modal-footer">
				<button class="btn btn-primary" type="submit">Guardar</button>
			</div>
		</form>
	</div>
</div>

<!-- MODAL EDITAR -->
<div class="modal fade" id="modalEditarProducto" tabindex="-1">
	<div class="modal-dialog">
		<form method="POST" action="includes/productos_editar.php" class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title">Editar Producto</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
			</div>
			<div class="modal-body">
				<input type="hidden" name="id" id="editar_id">
				<input type="hidden" name="cliente_id" value="<?= $cliente_id ?>">
				<div class="mb-2">
					<label>Nombre</label>
					<input type="text" name="nombre" id="editar_nombre" class="form-control" required>
				</div>
				<div class="mb-2">
					<label>Descripción</label>
					<textarea name="descripcion" id="editar_descripcion" class="form-control" required></textarea>
				</div>
				<div class="mb-2">
					<label>Precio</label>
					<input type="number" step="0.01" name="precio" id="editar_precio" class="form-control" required>
				</div>
				<div class="mb-2">
					<label>ISV</label>
					<select name="tipo_isv" id="editar_tipo_isv" class="form-select" required>
						<option value="15">15%</option>
						<option value="18">18%</option>
						<option value="0">0%</option>
					</select>
				</div>
			</div>
			<div class="modal-footer">
				<button class="btn btn-primary" type="submit">Actualizar</button>
			</div>
		</form>
	</div>
</div>

<script>
	function editarProducto(p) {
		document.getElementById('editar_id').value = p.id;
		document.getElementById('editar_nombre').value = p.nombre;
		document.getElementById('editar_descripcion').value = p.descripcion;
		document.getElementById('editar_precio').value = p.precio;
		document.getElementById('editar_tipo_isv').value = p.tipo_isv;
		new bootstrap.Modal(document.getElementById('modalEditarProducto')).show();
	}

	function eliminarProducto(id) {
		Swal.fire({
			title: '¿Eliminar producto?',
			text: 'Esta acción no se puede deshacer.',
			icon: 'warning',
			showCancelButton: true,
			confirmButtonText: 'Sí, eliminar',
		}).then((result) => {
			if (result.isConfirmed) {
				window.location.href = 'includes/productos_borrar.php?id=' + id;
			}
		});
	}

	// Activar DataTable
	$(document).ready(function () {
		$('#tabla-productos').DataTable({
			language: {
				url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json'
			},
			responsive: true
		});
	});
</script>
