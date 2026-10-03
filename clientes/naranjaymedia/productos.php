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
$stmt = $pdo->prepare("SELECT * FROM productos WHERE cliente_id = ? ORDER BY nombre");
$stmt->execute([$cliente_id]);
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="app-page-header">
	<div>
		<h1 class="app-page-title">Productos y servicios</h1>
		<p class="app-page-sub">Catálogo base con precio e ISV para facturar.</p>
	</div>
	<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAgregarProducto"><i class="bi bi-plus-lg me-1"></i> Agregar producto</button>
</div>

<div class="app-card">
	<div class="app-card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
		<span><i class="bi bi-box-seam me-1"></i> <?= count($productos) ?> producto<?= count($productos) === 1 ? '' : 's' ?></span>
		<input type="search" id="buscarProducto" class="form-control form-control-sm" style="max-width:280px" placeholder="Buscar por nombre o descripción…">
	</div>
	<div class="table-responsive">
		<table class="table app-table mb-0" id="tabla-productos">
			<thead>
				<tr>
					<th>Nombre</th>
					<th>Descripción</th>
					<th class="app-num">Precio</th>
					<th class="text-center">ISV</th>
					<th class="text-end">Acciones</th>
				</tr>
			</thead>
			<tbody>
				<?php if (!$productos): ?>
					<tr><td colspan="5" class="text-center text-muted py-4">Aún no hay productos. Usa «Agregar producto».</td></tr>
				<?php endif; ?>
				<?php foreach ($productos as $p): ?>
					<tr>
						<td class="fw-semibold"><?= htmlspecialchars($p['nombre'] ?? '') ?></td>
						<td class="small text-muted"><?= htmlspecialchars($p['descripcion'] ?? '') ?></td>
						<td class="app-num">L <?= number_format($p['precio'], 2) ?></td>
						<td class="text-center"><span class="app-badge"><?= (int)$p['tipo_isv'] ?>%</span></td>
						<td class="text-end text-nowrap">
							<button class="btn btn-sm btn-outline-secondary" title="Editar" onclick="editarProducto(<?= htmlspecialchars(json_encode($p)) ?>)"><i class="bi bi-pencil"></i></button>
							<button class="btn btn-sm btn-outline-danger" title="Eliminar" onclick="eliminarProducto(<?= (int)$p['id'] ?>)"><i class="bi bi-trash"></i></button>
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
					<label class="form-label">Nombre</label>
					<input type="text" name="nombre" class="form-control" required>
				</div>
				<div class="mb-2">
					<label class="form-label">Descripción</label>
					<textarea name="descripcion" class="form-control" required></textarea>
				</div>
				<div class="mb-2">
					<label class="form-label">Precio</label>
					<input type="number" step="0.01" name="precio" class="form-control" required>
				</div>
				<div class="mb-2">
					<label class="form-label">ISV</label>
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
					<label class="form-label">Nombre</label>
					<input type="text" name="nombre" id="editar_nombre" class="form-control" required>
				</div>
				<div class="mb-2">
					<label class="form-label">Descripción</label>
					<textarea name="descripcion" id="editar_descripcion" class="form-control" required></textarea>
				</div>
				<div class="mb-2">
					<label class="form-label">Precio</label>
					<input type="number" step="0.01" name="precio" id="editar_precio" class="form-control" required>
				</div>
				<div class="mb-2">
					<label class="form-label">ISV</label>
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
				const form = document.createElement('form');
				form.method = 'POST';
				form.action = 'includes/productos_borrar.php';
				const input = document.createElement('input');
				input.type = 'hidden';
				input.name = 'id';
				input.value = id;
				form.appendChild(input);
				document.body.appendChild(form);
				form.submit();
			}
		});
	}

	// Búsqueda rápida en la tabla
	document.getElementById('buscarProducto').addEventListener('input', function () {
		const q = this.value.trim().toLowerCase();
		document.querySelectorAll('#tabla-productos tbody tr').forEach(tr => {
			tr.style.display = !q || tr.textContent.toLowerCase().includes(q) ? '' : 'none';
		});
	});
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
