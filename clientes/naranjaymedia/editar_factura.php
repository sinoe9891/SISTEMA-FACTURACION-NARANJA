<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) {
	die("ID inválido");
}
$factura_id = (int) $_GET['id'];
$usuario_id = $_SESSION['usuario_id'];

$establecimiento_activo = $_SESSION['establecimiento_activo'] ?? null;
$nombre_establecimiento = 'No asignado';

if ($establecimiento_activo) {
	$stmt = $pdo->prepare("SELECT nombre FROM establecimientos WHERE establecimiento_id = ?");
	$stmt->execute([$establecimiento_activo]);
	$nombre_establecimiento = $stmt->fetchColumn() ?: 'No asignado';
}
// Traer nombre del cliente, logo y rol
$stmt = $pdo->prepare("
    SELECT u.nombre AS usuario_nombre, u.rol, c.id AS cliente_id, c.logo_url, c.nombre AS cliente_nombre
    FROM usuarios u
    INNER JOIN clientes_saas c ON u.cliente_id = c.id
    WHERE u.id = ?
");
$stmt->execute([$usuario_id]);
$datos = $stmt->fetch();
$_SESSION['usuario_rol'] = $datos['rol'];


$stmt = $pdo->prepare("SELECT rol, cliente_id FROM usuarios WHERE id = ?");
$stmt->execute([$usuario_id]);
$user = $stmt->fetch();
$es_admin = in_array($user['rol'], ['admin', 'superadmin']);
$cliente_id = $user['cliente_id'];

$stmt = $pdo->prepare("SELECT * FROM facturas WHERE id = ?");
$stmt->execute([$factura_id]);
$factura = $stmt->fetch();

if (!$factura || (!$es_admin && $factura['cliente_id'] != $cliente_id)) {
	die("Acceso no autorizado");
}

$stmtClientes = $pdo->prepare("SELECT id, nombre FROM clientes_factura WHERE cliente_id = ?");
$stmtClientes->execute([$cliente_id]);
$clientes = $stmtClientes->fetchAll();

$stmtProductos = $pdo->prepare("
    SELECT p.id, p.nombre, p.precio AS precio_base, p.tipo_isv,
           (SELECT precio_especial FROM precios_especiales WHERE producto_id = p.id AND cliente_id = ? LIMIT 1) AS precio_especial
    FROM productos p
    WHERE p.cliente_id = ?
");
$stmtProductos->execute([$cliente_id, $cliente_id]);
$productos = $stmtProductos->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM factura_items WHERE factura_id = ?");
$stmt->execute([$factura_id]);
$items = $stmt->fetchAll();

require_once '../../includes/templates/header.php';
?>

<div class="container mt-4">
	<div class="d-flex justify-content-between align-items-center mb-3">
		<div>
			<h4>✏️ Editar factura #<?= htmlspecialchars($factura['correlativo']) ?></h4>
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
	<form action="guardar_factura_editada" method="POST">
		<input type="hidden" name="factura_id" value="<?= $factura_id ?>">

		<div class="mb-3">
			<label for="receptor_id" class="form-label">Cliente (Receptor)</label>
			<select name="receptor_id" class="form-select" disabled>
				<?php foreach ($clientes as $cliente): ?>
					<option value="<?= $cliente['id'] ?>" <?= $cliente['id'] == $factura['receptor_id'] ? 'selected' : '' ?>>
						<?= htmlspecialchars($cliente['nombre']) ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="mb-3">
			<label for="fecha_emision">Fecha de emisión</label>
			<input type="datetime-local" name="fecha_emision" class="form-control" value="<?= date('Y-m-d\TH:i', strtotime($factura['fecha_emision'])) ?>" <?= !$es_admin ? 'readonly' : '' ?>>
		</div>

		<div class="mb-3">
			<label for="condicion_pago">Condición de pago</label>
			<select name="condicion_pago" class="form-select">
				<option value="Contado" <?= $factura['condicion_pago'] == 'Contado' ? 'selected' : '' ?>>Contado</option>
				<option value="Credito" <?= $factura['condicion_pago'] == 'Credito' ? 'selected' : '' ?>>Crédito</option>
			</select>
		</div>

		<div class="mb-3 form-check">
			<input type="checkbox" class="form-check-input" name="exonerado" id="exonerado" <?= $factura['exonerado'] ? 'checked' : '' ?>>
			<label class="form-check-label" for="exonerado">Factura exonerada</label>
		</div>

		<div id="campos-exoneracion" style="<?= $factura['exonerado'] ? 'display: block;' : 'display: none;' ?>">
			<div class="mb-3">
				<label>Orden de compra exenta</label>
				<input type="text" name="orden_compra_exenta" class="form-control" value="<?= htmlspecialchars($factura['orden_compra_exenta']) ?>">
			</div>
			<div class="mb-3">
				<label>Constancia de exoneración</label>
				<input type="text" name="constancia_exoneracion" class="form-control" value="<?= htmlspecialchars($factura['constancia_exoneracion']) ?>">
			</div>
			<div class="mb-3">
				<label>Registro SAG</label>
				<input type="text" name="registro_sag" class="form-control" value="<?= htmlspecialchars($factura['registro_sag']) ?>">
			</div>
		</div>

		<h5>🛒 Productos</h5>
		<div id="productos-container">
			<?php foreach ($items as $index => $item): ?>
				<div class="row g-2 producto-item mb-2 align-items-end">
					<div class="col-md-5">
						<label>Producto</label>
						<select name="productos[<?= $index ?>][id]" class="form-select" required>
							<option value="">Seleccione producto</option>
							<?php foreach ($productos as $prod):
								$precio = $prod['precio_especial'] !== null ? $prod['precio_especial'] : $prod['precio_base'];
							?>
								<option
									value="<?= $prod['id'] ?>"
									data-precio="<?= $precio ?>"
									data-precio-base="<?= $prod['precio_base'] ?>"
									data-isv="<?= $prod['tipo_isv'] ?>"
									<?= isset($item) && $prod['id'] == $item['producto_id'] ? 'selected' : '' ?>>
									<?= htmlspecialchars($prod['nombre']) ?>
									- Estándar: L<?= number_format($prod['precio_base'], 2) ?>
									<?php if ($prod['precio_especial'] !== null): ?>
										| Especial: L<?= number_format($prod['precio_especial'], 2) ?>
									<?php endif; ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="col-md-2">
						<label>Cantidad</label>
						<input type="number"
							name="productos[<?= $index ?>][cantidad]"
							class="form-control"
							min="1"
							value="<?= $item['cantidad'] ?>"
							required>
					</div>
					<div class="col-md-2">
						<label>Precio Unitario</label>
						<input type="number" step="0.01" name="productos[<?= $index ?>][precio_unitario]" class="form-control precio-unitario" value="<?= $item['precio_unitario'] ?>" readonly>

					</div>
					<div class="col-md-2">
						<label>Subtotal</label>
						<input type="number" step="0.01" name="productos[<?= $index ?>][precio]" class="form-control subtotal-producto" value="<?= $item['cantidad'] * $item['precio_unitario'] ?>" readonly>
					</div>

					<div class="col-md-2">
						<button type="button" class="btn btn-danger btn-sm remove-producto">Eliminar</button>
					</div>
					<small class="text-muted precio-sugerido">Precio sugerido: L<?= number_format($item['cantidad'] * $item['precio_unitario'], 2) ?></small>
				</div>
			<?php endforeach; ?>
		</div>

		<button type="button" class="btn btn-secondary mb-3" id="agregar-producto">➕ Agregar producto</button>

		<div class="mb-3">
			<label>Subtotal</label>
			<input type="text" id="subtotal" name="subtotal" class="form-control" value="<?= $factura['subtotal'] ?>" readonly>
		</div>
		<div class="mb-3">
			<label>Importe Gravado 15%</label>
			<input type="text" name="importe_gravado_15" id="importe_gravado_15" class="form-control" value="<?= $factura['importe_gravado_15'] ?>" readonly>
		</div>
		<div class="mb-3">
			<label>Importe Gravado 18%</label>
			<input type="text" name="importe_gravado_18" id="importe_gravado_18" class="form-control" value="<?= $factura['importe_gravado_18'] ?>" readonly>
		</div>
		<div class="mb-3">
			<label>ISV 15%</label>
			<input type="text" name="isv_15" class="form-control" value="<?= $factura['isv_15'] ?>" readonly>
		</div>
		<div class="mb-3">
			<label>ISV (18%):</label>
			<input type="text" id="isv_18" class="form-control" readonly>
		</div>
		<div class="mb-3">
			<label>Total</label>
			<input type="text" name="total" class="form-control" value="<?= $factura['total'] ?>" readonly>
		</div>
		<div class="mb-3">
			<label>Monto en letras</label>
			<input type="text" name="monto_letras" class="form-control" value="<?= $factura['monto_letras'] ?>" readonly>
		</div>

		<button type="submit" class="btn btn-primary">💾 Guardar cambios</button>
		<a href="lista_facturas" class="btn btn-secondary">Cancelar</a>
	</form>
</div>

<script>
	document.getElementById('exonerado').addEventListener('change', function() {
		document.getElementById('campos-exoneracion').style.display = this.checked ? 'block' : 'none';
		calcularTotalYLetra();

	});

	document.getElementById('agregar-producto').addEventListener('click', function() {
		const contenedor = document.getElementById('productos-container');
		const baseItem = contenedor.children[0];
		const nuevo = baseItem.cloneNode(true);
		const index = contenedor.children.length;

		nuevo.classList.remove('is-valid', 'is-invalid'); // limpia clases si las tuviera

		nuevo.querySelectorAll('input, select').forEach(el => {
			// Resetear los valores de cada input
			if (el.name.includes('[cantidad]')) {
				el.value = 1;
			} else if (el.name.includes('[precio_unitario]') || el.name.includes('[precio]')) {
				el.value = '0.00';
			} else if (el.tagName === 'SELECT') {
				el.selectedIndex = 0;
			}

			// Actualizar el nombre con nuevo índice
			el.name = el.name.replace(/\[\d+\]/, `[${index}]`);
		});

		// Limpiar texto de precio sugerido
		const sugerido = nuevo.querySelector('.precio-sugerido');
		if (sugerido) sugerido.textContent = '';

		contenedor.appendChild(nuevo);

		calcularTotalYLetra(); // recalcula para que el total no se altere
	});




	document.getElementById('productos-container').addEventListener('click', function(e) {
		if (e.target.classList.contains('remove-producto')) {
			const items = document.querySelectorAll('.producto-item');
			if (items.length > 1) {
				e.target.closest('.producto-item').remove();
				calcularTotalYLetra();

			}
		}
	});
	document.getElementById('productos-container').addEventListener('change', function(e) {
		if (e.target.tagName === 'SELECT' && e.target.name.includes('[id]')) {
			const selected = e.target.selectedOptions[0];
			const item = e.target.closest('.producto-item');

			const precio = parseFloat(selected.getAttribute('data-precio')) || 0;
			const precioBase = parseFloat(selected.getAttribute('data-precio-base')) || 0;

			const cantidad = parseFloat(item.querySelector('input[name$="[cantidad]"]').value) || 1;

			const precioInput = item.querySelector('input[name$="[precio]"]');
			const precioUnitarioInput = item.querySelector('input[name$="[precio_unitario]"]');
			const precioSugerido = item.querySelector('.precio-sugerido');

			if (precioUnitarioInput) {
				precioUnitarioInput.value = precio.toFixed(2); // 👈 Esta es la línea clave
			}

			if (precioInput) {
				precioInput.value = (precio * cantidad).toFixed(2);
				precioInput.setAttribute('data-precio-unitario', precio.toFixed(2));
			}

			if (precioSugerido) {
				precioSugerido.textContent = 'Precio sugerido: L' + (precioBase * cantidad).toFixed(2);
			}

			calcularTotalYLetra();
		}
	});



	document.getElementById('productos-container').addEventListener('input', function(e) {
		if (e.target.name.includes('[cantidad]')) {
			const item = e.target.closest('.producto-item');
			const cantidad = parseFloat(e.target.value) || 1;
			const select = item.querySelector('select[name$="[id]"]');

			if (!select || !select.selectedOptions[0]) return;

			const precio = parseFloat(select.selectedOptions[0].getAttribute('data-precio')) || 0;
			const precioBase = parseFloat(select.selectedOptions[0].getAttribute('data-precio-base')) || 0;

			const precioInput = item.querySelector('input[name$="[precio]"]');
			const precioSugerido = item.querySelector('.precio-sugerido');

			if (precioSugerido) {
				precioSugerido.textContent = 'Precio sugerido: L' + (precioBase * cantidad).toFixed(2);
			}

			calcularTotalYLetra();
		}
	});
	document.getElementById('productos-container').addEventListener('input', function(e) {
		if (e.target.name.includes('[cantidad]')) {
			const item = e.target.closest('.producto-item');
			const cantidad = parseFloat(e.target.value) || 1;
			const select = item.querySelector('select[name$="[id]"]');
			if (!select) return;

			const precio = parseFloat(select.selectedOptions[0]?.getAttribute('data-precio')) || 0;
			const precioInput = item.querySelector('input[name$="[precio]"]');

			// Solo actualiza el campo 'precio' sin recalcular totales
			if (precioInput) {
				precioInput.value = (cantidad * precio).toFixed(2);
			}
		}
	});
</script>
<script>
	document.querySelector('form').addEventListener('submit', function(e) {
		e.preventDefault(); // Detener el envío inicial del formulario

		Swal.fire({
			title: '¿Deseas guardar los cambios?',
			html: `
			<input type="text" id="motivo" class="swal2-input" placeholder="Motivo (obligatorio)">
			<input type="text" id="usuario" class="swal2-input" placeholder="Usuario admin/superadmin">
			<input type="password" id="clave" class="swal2-input" placeholder="Contraseña">
		`,
			focusConfirm: false,
			showCancelButton: true,
			confirmButtonText: 'Confirmar',
			preConfirm: () => {
				const motivo = document.getElementById('motivo').value.trim();
				const usuario = document.getElementById('usuario').value.trim();
				const clave = document.getElementById('clave').value.trim();

				if (!motivo || !usuario || !clave) {
					Swal.showValidationMessage('Todos los campos son obligatorios.');
					return false;
				}
				return {
					motivo,
					usuario,
					clave
				};
			}
		}).then((result) => {
			if (result.isConfirmed) {
				const form = document.querySelector('form');

				['motivo', 'usuario_autoriza', 'clave_autoriza'].forEach(name => {
					let input = document.createElement('input');
					input.type = 'hidden';
					input.name = name;
					if (name === 'motivo') input.value = result.value.motivo;
					if (name === 'usuario_autoriza') input.value = result.value.usuario;
					if (name === 'clave_autoriza') input.value = result.value.clave;
					form.appendChild(input);
				});

				// ⬇️ ESTE BLOQUE ES EL NUEVO QUE REEMPLAZA AL form.submit();
				const formData = new FormData(form);

				fetch('guardar_factura_editada', {
						method: 'POST',
						body: formData
					})
					.then(res => res.text())
					.then(response => {
						if (response.includes("Usuario o contraseña incorrecta")) {
							Swal.fire('Error', 'Usuario o contraseña incorrecta.', 'error');
						} else if (response.includes("Solo un admin o superadmin")) {
							Swal.fire('Error', 'Solo un admin o superadmin puede autorizar cambios.', 'error');
						} else if (response.includes("Todos los campos de autorización son obligatorios")) {
							Swal.fire('Error', 'Debes llenar todos los campos de autorización.', 'error');
						} else if (response.includes("Factura no encontrada")) {
							Swal.fire('Error', 'La factura no fue encontrada.', 'error');
						} else if (response.includes("Acceso no autorizado")) {
							Swal.fire('Error', 'No tienes permisos para editar esta factura.', 'error');
						} else if (response.includes("Error al guardar cambios")) {
							Swal.fire('Error', 'Hubo un error al guardar los cambios.', 'error');
						} else {
							Swal.fire('¡Éxito!', 'Factura editada correctamente.', 'success')
								.then(() => window.location.href = 'lista_facturas?success=1');
						}
					})
					.catch(error => {
						Swal.fire('Error', 'Error en la petición: ' + error.message, 'error');
					});
			}
		});

	});

	function calcularTotalYLetra() {
		let subtotal = 0;
		let importeGravado15 = 0;
		let importeGravado18 = 0;
		let isv15 = 0;
		let isv18 = 0;
		const exonerado = document.getElementById('exonerado').checked;

		document.querySelectorAll('.producto-item').forEach(item => {
			const cantidad = parseFloat(item.querySelector('input[name$="[cantidad]"]').value) || 0;
			const precioUnitario = parseFloat(item.querySelector('input[name$="[precio_unitario]"]').value) || 0;

			const select = item.querySelector('select[name$="[id]"]');
			const tipoISV = select && select.selectedOptions.length ?
				parseInt(select.selectedOptions[0].getAttribute('data-isv')) || 0 :
				0;

			const totalProducto = cantidad * precioUnitario;
			subtotal += totalProducto;

			if (!exonerado) {
				if (tipoISV === 15) {
					isv15 += totalProducto * 0.15;
					importeGravado15 += totalProducto;
				} else if (tipoISV === 18) {
					isv18 += totalProducto * 0.18;
					importeGravado18 += totalProducto;
				}
			}
		});

		const total = subtotal + isv15 + isv18;

		document.getElementById('subtotal').value = 'L ' + subtotal.toLocaleString('es-HN', {
			minimumFractionDigits: 2
		});
		document.getElementsByName('isv_15')[0].value = 'L ' + isv15.toLocaleString('es-HN', {
			minimumFractionDigits: 2
		});
		document.getElementById('isv_18').value = 'L ' + isv18.toLocaleString('es-HN', {
			minimumFractionDigits: 2
		});
		document.querySelector('input[name="total"]').value = 'L ' + total.toLocaleString('es-HN', {
			minimumFractionDigits: 2
		});
		document.getElementById('importe_gravado_15').value = 'L ' + importeGravado15.toLocaleString('es-HN', {
			minimumFractionDigits: 2
		});
		document.getElementById('importe_gravado_18').value = 'L ' + importeGravado18.toLocaleString('es-HN', {
			minimumFractionDigits: 2
		});
		const letras = numeroALetras(total);
		document.querySelector('input[name="monto_letras"]').value = letras;
	}


	function numeroALetras(num) {
		const UNIDADES = ["", "uno", "dos", "tres", "cuatro", "cinco", "seis", "siete", "ocho", "nueve"];
		const DECENAS = ["", "", "veinte", "treinta", "cuarenta", "cincuenta", "sesenta", "setenta", "ochenta", "noventa"];
		const DIEZ_A_DIECINUEVE = ["diez", "once", "doce", "trece", "catorce", "quince", "dieciséis", "diecisiete", "dieciocho", "diecinueve"];
		const CENTENAS = ["", "ciento", "doscientos", "trescientos", "cuatrocientos", "quinientos", "seiscientos", "setecientos", "ochocientos", "novecientos"];

		function convertirGrupo(n) {
			let output = "";
			if (n == 100) return "cien";
			if (n > 99) {
				output += CENTENAS[Math.floor(n / 100)] + " ";
				n %= 100;
			}
			if (n >= 20) {
				output += DECENAS[Math.floor(n / 10)];
				if (n % 10 !== 0) {
					output += " y " + UNIDADES[n % 10];
				}
			} else if (n >= 10) {
				output += DIEZ_A_DIECINUEVE[n - 10];
			} else if (n > 0) {
				output += UNIDADES[n];
			}
			return output.trim();
		}

		function seccion(n, singular, plural) {
			if (n === 0) return "";
			else if (n === 1) return `un ${singular}`;
			else return `${numeroToWords(n)} ${plural}`;
		}

		function numeroToWords(n) {
			let millones = Math.floor(n / 1000000);
			let miles = Math.floor((n - millones * 1000000) / 1000);
			let resto = n % 1000;

			let resultado = "";
			if (millones > 0) resultado += seccion(millones, "millón", "millones") + " ";
			if (miles > 0) resultado += seccion(miles, "mil", "mil") + " ";
			if (resto > 0) resultado += convertirGrupo(resto);

			return resultado.trim();
		}

		const partes = num.toFixed(2).split(".");
		const lempiras = parseInt(partes[0]);
		const centavos = parseInt(partes[1]);

		const letras = `${numeroToWords(lempiras)} lempiras`;
		const centavosTexto = centavos > 0 ? ` con ${centavos}/100 centavos` : " exactos";
		return letras.charAt(0).toUpperCase() + letras.slice(1) + centavosTexto;
	}

	// Eventos para recalcular automáticamente
	document.getElementById('productos-container').addEventListener('change', calcularTotalYLetra);
	document.getElementById('productos-container').addEventListener('input', calcularTotalYLetra);
	document.getElementById('exonerado').addEventListener('change', calcularTotalYLetra);

	document.addEventListener('DOMContentLoaded', calcularTotalYLetra);
</script>

<?php require_once '../../includes/templates/footer.php'; ?>