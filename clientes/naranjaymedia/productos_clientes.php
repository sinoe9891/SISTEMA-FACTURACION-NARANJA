<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';

// Seguridad y contexto
$usuario_id = $_SESSION['usuario_id'];
$establecimiento_activo = $_SESSION['establecimiento_activo'] ?? null;
$es_superadmin = (USUARIO_ROL === 'superadmin');

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

// Obtener productos generales y receptores
$stmt = $pdo->prepare("SELECT * FROM productos WHERE cliente_id = ?");
$stmt->execute([$cliente_id]);
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT * FROM clientes_factura WHERE cliente_id = ?");
$stmt->execute([$cliente_id]);
$receptores = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener productos_clientes
$stmt = $pdo->prepare("SELECT pc.*, r.nombre AS receptor_nombre FROM productos_clientes pc INNER JOIN clientes_factura r ON pc.receptores_id = r.id WHERE pc.cliente_id = ?");
$stmt->execute([$cliente_id]);
$productos_clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container mt-4">
	<div class="d-flex justify-content-between align-items-center mb-3">
		<h4>🌍 Productos por Cliente - <?= htmlspecialchars($cliente['nombre']) ?></h4>
		<?php if ($cliente['logo_url']): ?>
			<img src="<?= $cliente['logo_url'] ?>" alt="Logo Cliente" style="max-height: 50px;">
		<?php endif; ?>
	</div>


	<!-- Botones Agregar -->
	<div class="mb-3 d-flex gap-2">
		<button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalAsignarProductoExistente">➕ Asignar Producto Existente</button>
		<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCrearProductoNuevo">🆕 Crear Producto Nuevo</button>
	</div>


	<!-- Tabla -->
	<div class="table-responsive">
		<table class="table table-bordered" id="tabla-productos-clientes">
			<thead class="table-dark">
				<tr>
					<th>ID</th>
					<th>Receptor</th>
					<th>Nombre</th>
					<th>Descripción</th>
					<th>Precio</th>
					<th>ISV</th>
					<th>Fijo</th>
					<th>Acciones</th>
				</tr>
			</thead>
			<tbody>
				<?php $i = 1;
				foreach ($productos_clientes as $p): ?>
					<tr>
						<td><?= $i++ ?></td>
						<td><?= htmlspecialchars($p['receptor_nombre']) ?></td>
						<td><?= htmlspecialchars($p['nombre']) ?></td>
						<td><?= htmlspecialchars($p['descripcion']) ?></td>
						<td>L <?= number_format($p['precio'], 2) ?></td>
						<td><?= $p['tipo_isv'] ?>%</td>
						<td><?= $p['precio_fijo'] ? 'Sí' : 'No' ?></td>
						<td>
							<button class="btn btn-sm btn-info" onclick='editarProducto(<?= json_encode($p) ?>)'>✏️</button>
							<button class="btn btn-sm btn-danger" onclick="confirmarEliminar(<?= $p['id'] ?>)">🗑️</button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>

<!-- MODAL AGREGAR PRODUCTO CLIENTE -->
<div class="modal fade" id="modalAsignarProductoExistente" tabindex="-1">
	<div class="modal-dialog">
		<form method="POST" action="includes/productos_clientes_agregar.php" class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title">Asignar Producto Existente</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
			</div>
			<div class="modal-body">
				<input type="hidden" name="cliente_id" value="<?= $cliente_id ?>">

				<div class="mb-2">
					<label>Receptor</label>
					<select name="receptores_id" class="form-select" required>
						<option value="">Seleccione receptor</option>
						<?php foreach ($receptores as $r): ?>
							<option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['nombre']) ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="mb-2">
					<label>Producto</label>
					<select name="producto_id" class="form-select" onchange="autocompletarProducto(this)" required>
						<option value="">Seleccione producto</option>
						<?php foreach ($productos as $p): ?>
							<option value='<?= json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT) ?>'>
								<?= htmlspecialchars($p['nombre']) ?> - L <?= number_format($p['precio'], 2) ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<!-- Campos ocultos que se llenan automáticamente -->
				<input type="hidden" name="nombre" id="nombreNuevo">
				<input type="hidden" name="descripcion" id="descripcionNuevo">
				<input type="hidden" name="precio" id="precioNuevo">
				<input type="hidden" name="tipo_isv" id="tipoISVNuevo">
				<input type="hidden" name="precio_fijo" value="1"> <!-- Por defecto fijo -->
			</div>

			<div class="modal-footer">
				<button type="submit" class="btn btn-success">Asignar</button>
			</div>
		</form>
	</div>
</div>

<div class="modal fade" id="modalCrearProductoNuevo" tabindex="-1">
	<div class="modal-dialog">
		<form method="POST" action="includes/productos_clientes_agregar.php" class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title">Crear Producto Nuevo</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
			</div>
			<div class="modal-body">
				<input type="hidden" name="cliente_id" value="<?= $cliente_id ?>">

				<div class="mb-2">
					<label>Receptor</label>
					<select name="receptores_id" class="form-select" required>
						<option value="">Seleccione receptor</option>
						<?php foreach ($receptores as $r): ?>
							<option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['nombre']) ?></option>
						<?php endforeach; ?>
					</select>
				</div>

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
					<input type="number" name="precio" step="0.01" class="form-control" required>
				</div>

				<div class="mb-2">
					<label>ISV</label>
					<select name="tipo_isv" class="form-select" required>
						<option value="15">15%</option>
						<option value="18">18%</option>
						<option value="0">0%</option>
					</select>
				</div>

				<div class="form-check">
					<input class="form-check-input" type="checkbox" name="precio_fijo" value="1" id="nuevo_precio_fijo" checked>
					<label class="form-check-label" for="nuevo_precio_fijo">Precio fijo</label>
				</div>
			</div>
			<div class="modal-footer">
				<button class="btn btn-primary" type="submit">Crear</button>
			</div>
		</form>
	</div>
</div>

<!-- MODAL EDITAR PRODUCTO CLIENTE -->
<div class="modal fade" id="modalEditarProductoCliente" tabindex="-1">
	<div class="modal-dialog">
		<form method="POST" action="includes/productos_clientes_editar.php" class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title">Editar Producto del Cliente</h5>
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
				<div class="form-check">
					<input class="form-check-input" type="checkbox" name="precio_fijo" id="editar_precio_fijo" value="1">
					<label class="form-check-label" for="editar_precio_fijo">Precio fijo</label>
				</div>
			</div>
			<div class="modal-footer">
				<button class="btn btn-primary" type="submit">Actualizar</button>
			</div>
		</form>
	</div>
</div>

<script>
	function autocompletarProducto(select) {
		if (!select.value) return;
		const data = JSON.parse(select.value);
		document.getElementById('nombreNuevo').value = data.nombre;
		document.getElementById('descripcionNuevo').value = data.descripcion;
		document.getElementById('precioNuevo').value = data.precio;
		document.getElementById('tipoISVNuevo').value = data.tipo_isv;
	}

	function editarProducto(p) {
		document.getElementById('editar_id').value = p.id;
		document.getElementById('editar_nombre').value = p.nombre;
		document.getElementById('editar_descripcion').value = p.descripcion;
		document.getElementById('editar_precio').value = p.precio;
		document.getElementById('editar_tipo_isv').value = p.tipo_isv;
		document.getElementById('editar_precio_fijo').checked = (p.precio_fijo == 1);
		new bootstrap.Modal(document.getElementById('modalEditarProductoCliente')).show();
	}

	$(document).ready(function() {
		$('#tabla-productos-clientes').DataTable({
			language: {
				url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json'
			},
			responsive: true
		});
	});

	function confirmarEliminar(id) {
		Swal.fire({
			title: '¿Estás seguro?',
			text: 'Esto eliminará el producto del cliente.',
			icon: 'warning',
			showCancelButton: true,
			confirmButtonText: 'Sí, eliminar',
			cancelButtonText: 'Cancelar',
			confirmButtonColor: '#d33'
		}).then((result) => {
			if (result.isConfirmed) {
				fetch('includes/productos_clientes_eliminar.php', {
						method: 'POST',
						headers: {
							'Content-Type': 'application/x-www-form-urlencoded'
						},
						body: 'id=' + encodeURIComponent(id)
					})
					.then(res => {
						if (!res.ok) throw new Error('Error al eliminar');
						return res.json();
					})
					.then(data => {
						if (data.status === 'ok') {
							Swal.fire('¡Eliminado!', 'El producto ha sido eliminado.', 'success')
								.then(() => location.reload());
						} else {
							throw new Error(data.message);
						}
					})
					.catch(err => {
						console.error(err);
						Swal.fire('Error', 'Hubo un problema al eliminar.', 'error');
					});
			}
		});
	}
</script>