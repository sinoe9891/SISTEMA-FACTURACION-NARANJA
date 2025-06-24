<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';

// Obtener cliente_id desde la sesión del usuario
$usuario_id = $_SESSION['usuario_id'];
$stmt = $pdo->prepare("
    SELECT u.nombre AS usuario_nombre, u.rol, c.id AS cliente_id, c.logo_url, c.nombre AS cliente_nombre
    FROM usuarios u
    INNER JOIN clientes_saas c ON u.cliente_id = c.id
    WHERE u.id = ?
");
$stmt->execute([$usuario_id]);
$datos = $stmt->fetch();
$cliente_id = $datos['cliente_id'];

// Obtener lista de clientes a facturar (los "receptores" de la factura)
$stmtClientes = $pdo->prepare("SELECT id, nombre FROM clientes_factura WHERE cliente_id = ?");
$stmtClientes->execute([$cliente_id]);
$clientes = $stmtClientes->fetchAll();

// Obtener productos con posible precio especial según cliente actual (cliente_id)
$stmtProductos = $pdo->prepare("
    SELECT p.id, p.nombre, p.precio AS precio_base, p.tipo_isv,
           (
               SELECT precio_especial 
               FROM precios_especiales 
               WHERE producto_id = p.id AND cliente_id = ?
               LIMIT 1
           ) AS precio_especial
    FROM productos p
    WHERE p.cliente_id = ?
");
$stmtProductos->execute([$cliente_id, $cliente_id]);
$productos = $stmtProductos->fetchAll();
if (empty($productos)) {
	echo "<div class='alert alert-warning'>⚠️ No hay productos disponibles para este cliente.</div>";
}

require_once '../../includes/templates/header.php';
?>


<div class="container mt-4">
	<h4 class="mb-3">🧾 Generar nueva factura</h4>

	<form action="guardar_factura" method="POST">
		<div class="mb-3">
			<label for="cai_rango_id" class="form-label">CAI (Clave de autorización de impresión)</label>
			<select name="cai_rango_id" id="cai_rango_id" class="form-select" required>
				<option value="">Seleccione un CAI</option>
				<?php
				$stmtCaiRangos = $pdo->prepare("
            SELECT id, cai, rango_inicio, rango_fin, fecha_limite, fecha_creacion 
            FROM cai_rangos 
            WHERE cliente_id = ? AND fecha_limite >= CURDATE()
            ORDER BY fecha_creacion DESC
        ");
				$stmtCaiRangos->execute([$cliente_id]);
				foreach ($stmtCaiRangos->fetchAll() as $cai) {
					echo "<option value='{$cai['id']}'>
                CAI: {$cai['cai']} | Rango: {$cai['rango_inicio']} - {$cai['rango_fin']} | Válido hasta: {$cai['fecha_limite']}
            </option>";
				}
				?>
			</select>
		</div>


		<div class="mb-3">
			<label for="receptor_id" class="form-label">Cliente (Receptor)</label>
			<select name="receptor_id" id="receptor_id" class="form-select" required>
				<option value="">Seleccione un cliente...</option>
				<?php foreach ($clientes as $cliente): ?>
					<option value="<?= $cliente['id'] ?>"><?= htmlspecialchars($cliente['nombre']) ?></option>
				<?php endforeach; ?>
			</select>
		</div>

		<hr>

		<h5>🧮 Detalle de productos</h5>
		<div id="productos-container">
			<div class="producto-item row g-2 align-items-end mb-2">
				<div class="col-md-5">
					<label>Producto</label>
					<select name="productos[0][id]" class="form-select" required>
						<option value="">Seleccione producto</option>
						<?php foreach ($productos as $prod): ?>
							<?php
							$precio = $prod['precio_especial'] !== null ? $prod['precio_especial'] : $prod['precio_base'];
							?>
							<option value="<?= $prod['id'] ?>"
								data-precio="<?= $precio ?>"
								data-precio-base="<?= $prod['precio_base'] ?>"
								data-isv="<?= $prod['tipo_isv'] ?>">
								<?= htmlspecialchars($prod['nombre']) ?> - L<?= number_format($precio, 2) ?>
							</option>

						<?php endforeach; ?>
					</select>

				</div>
				<div class="col-md-3">
					<label>Cantidad</label>
					<input type="number" name="productos[0][cantidad]" class="form-control" min="1" value="1" required>
				</div>
				<div class="col-md-2">
					<label>Precio</label>
					<input type="number" step="0.01" name="productos[0][precio]" class="form-control" required>
				</div>
				<div class="col-md-2">
					<button type="button" class="btn btn-danger btn-sm remove-producto">Eliminar</button>
				</div>
				<small class="text-muted">Precio sugerido: L<?= number_format($precio, 2) ?></small>
			</div>
		</div>

		<button type="button" class="btn btn-secondary mb-3" id="agregar-producto">➕ Agregar producto</button>

		<div class="mb-3">
			<label for="condicion_pago" class="form-label">Condición de pago</label>
			<select name="condicion_pago" id="condicion_pago" class="form-select" required>
				<option value="Contado">Contado</option>
				<option value="Credito">Crédito</option>
			</select>
		</div>
		<div class="mb-3">
			<div class="form-check">
				<input class="form-check-input" type="checkbox" name="exonerado" id="exonerado">
				<label class="form-check-label" for="exonerado">
					¿Factura exonerada?
				</label>
			</div>
		</div>
		<input type="hidden" name="establecimiento_id" value="<?= htmlspecialchars($_SESSION['establecimiento_activo']) ?>">
		<input type="hidden" name="estado" value="emitida">
		<input type="hidden" name="fecha_emision" value="<?= date('Y-m-d H:i:s') ?>">

		<div id="campos-exoneracion" style="display: none;">
			<div class="mb-3">
				<label for="orden_compra_exenta" class="form-label">Orden de compra exenta</label>
				<input type="text" name="orden_compra_exenta" class="form-control">
			</div>
			<div class="mb-3">
				<label for="constancia_exoneracion" class="form-label">Constancia de exoneración</label>
				<input type="text" name="constancia_exoneracion" class="form-control">
			</div>
			<div class="mb-3">
				<label for="registro_sag" class="form-label">Registro SAG</label>
				<input type="text" name="registro_sag" class="form-control">
			</div>
		</div>
		<div class="mb-3">
			<label>Subtotal:</label>
			<input type="text" id="subtotal" class="form-control" readonly>
		</div>
		<div class="mb-3">
			<label>Importe Gravado 15%</label>
			<input type="text" name="importe_gravado_15" id="importe_gravado_15" class="form-control" value="" readonly>
		</div>
		<div class="mb-3">
			<label>Importe Gravado 18%</label>
			<input type="text" name="importe_gravado_18" id="importe_gravado_18" class="form-control" value="" readonly>
		</div>
		<div class="mb-3">
			<label>ISV (15%):</label>
			<input type="text" id="isv_15" class="form-control" readonly>
		</div>
		<div class="mb-3">
			<label>ISV (18%):</label>
			<input type="text" id="isv_18" class="form-control" readonly>
		</div>
		<div class="mb-3">
			<label>Total:</label>
			<input type="text" id="total_final" class="form-control" readonly>
		</div>
		<h4 id="total_letras_texto" class="text-primary mt-4"></h4>

		<div class="mb-3">
			<label>En letras:</label>
			<input type="text" id="total_letras" class="form-control" readonly>
		</div>
		<div class="d-grid">
			<button type="submit" class="btn btn-primary">💾 Guardar y generar factura</button>
		</div>
	</form>
</div>
<script>
	let productoIndex = 1;
	document.getElementById('agregar-producto').addEventListener('click', function() {
		const contenedor = document.getElementById('productos-container');
		const nuevo = contenedor.children[0].cloneNode(true);
		nuevo.querySelectorAll('input, select').forEach(el => {
			if (el.name.includes('productos')) {
				const nuevoNombre = el.name.replace(/\[\d+\]/, `[${productoIndex}]`);
				el.name = nuevoNombre;
			}
			if (el.tagName === 'INPUT') el.value = el.name.includes('cantidad') ? 1 : '';
			if (el.tagName === 'SELECT') el.selectedIndex = 0;
		});
		contenedor.appendChild(nuevo);
		productoIndex++;
	});

	document.getElementById('productos-container').addEventListener('click', function(e) {
		if (e.target.classList.contains('remove-producto')) {
			const items = document.querySelectorAll('.producto-item');
			if (items.length > 1) {
				e.target.closest('.producto-item').remove();
			}
		}
	});

	// Autocompletar precio basado en selección
	document.getElementById('productos-container').addEventListener('change', function(e) {
		if (e.target.tagName === 'SELECT' && e.target.name.includes('[id]')) {
			const selected = e.target.selectedOptions[0];
			const precio = selected.getAttribute('data-precio');
			const precioBase = selected.getAttribute('data-precio-base');
			const isv = selected.getAttribute('data-isv');
			const item = e.target.closest('.producto-item');

			const precioInput = item.querySelector('input[name$="[precio]"]');
			const cantidadInput = item.querySelector('input[name$="[cantidad]"]');

			// Guardar precio real para cálculo en un atributo personalizado
			cantidadInput.setAttribute('data-precio-unitario', precio);

			// Mostrar valor visual en input precio
			precioInput.value = (parseFloat(precio) * parseFloat(cantidadInput.value)).toFixed(2);
		}
	});

	document.getElementById('productos-container').addEventListener('input', function(e) {
		const item = e.target.closest('.producto-item');
		if (!item) return;

		const cantidadInput = item.querySelector('input[name$="[cantidad]"]');
		const precioInput = item.querySelector('input[name$="[precio]"]');
		const precioUnitario = parseFloat(cantidadInput.getAttribute('data-precio-unitario')) || 0;
		const cantidad = parseFloat(cantidadInput.value) || 0;

		// Actualizar solo el campo visual de precio (no afecta el cálculo)
		precioInput.value = (cantidad * precioUnitario).toFixed(2);

		calcularTotalYLetra(); // sigue llamándose
	});


	document.getElementById('exonerado').addEventListener('change', function() {
		const campos = document.getElementById('campos-exoneracion');
		campos.style.display = this.checked ? 'block' : 'none';

		// Hacer campos requeridos solo si está marcado
		document.querySelector('[name="orden_compra_exenta"]').required = this.checked;
		document.querySelector('[name="constancia_exoneracion"]').required = this.checked;
		document.querySelector('[name="registro_sag"]').required = this.checked;

		calcularTotalYLetra();
	});


	function calcularTotalYLetra() {
		let subtotal = 0;
		let importeGravado15 = 0;
		let importeGravado18 = 0;
		let isv15 = 0;
		let isv18 = 0;
		const exonerado = document.getElementById('exonerado').checked;

		document.querySelectorAll('.producto-item').forEach(item => {
			const cantidadInput = item.querySelector('input[name$="[cantidad]"]');
			const cantidad = parseFloat(cantidadInput.value) || 0;
			const precioUnitario = parseFloat(cantidadInput.getAttribute('data-precio-unitario')) || 0;
			const tipoISV = parseInt(item.querySelector('select[name$="[id]"]').selectedOptions[0].getAttribute('data-isv')) || 0;

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
			minimumFractionDigits: 2,
			maximumFractionDigits: 2
		});
		document.getElementById('isv_15').value = 'L ' + isv15.toLocaleString('es-HN', {
			minimumFractionDigits: 2,
			maximumFractionDigits: 2
		});
		document.getElementById('isv_18').value = 'L ' + isv18.toLocaleString('es-HN', {
			minimumFractionDigits: 2,
			maximumFractionDigits: 2
		});
		document.getElementById('total_final').value = 'L ' + total.toLocaleString('es-HN', {
			minimumFractionDigits: 2,
			maximumFractionDigits: 2
		});
		document.getElementById('importe_gravado_15').value = 'L ' + importeGravado15.toLocaleString('es-HN', {
			minimumFractionDigits: 2
		});
		document.getElementById('importe_gravado_18').value = 'L ' + importeGravado18.toLocaleString('es-HN', {
			minimumFractionDigits: 2
		});

		const letras = numeroALetras(total);
		document.getElementById('total_letras').value = letras;
		document.getElementById('total_letras_texto').innerText = `En letras: ${letras}`;
	}



	document.getElementById('exonerado').addEventListener('change', function() {
		const campos = document.getElementById('campos-exoneracion');
		campos.style.display = this.checked ? 'block' : 'none';
		calcularTotalYLetra();
	});

	// Observadores de cambios
	document.getElementById('productos-container').addEventListener('change', calcularTotalYLetra);
	document.getElementById('productos-container').addEventListener('input', calcularTotalYLetra);

	// Conversor básico a letras en español (simplificado)
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

			if (millones > 0) {
				resultado += seccion(millones, "millón", "millones") + " ";
			}
			if (miles > 0) {
				resultado += seccion(miles, "mil", "mil") + " ";
			}
			if (resto > 0) {
				resultado += convertirGrupo(resto);
			}

			return resultado.trim();
		}

		// Parte entera y decimal
		const partes = num.toFixed(2).split(".");
		const lempiras = parseInt(partes[0]);
		const centavos = parseInt(partes[1]);

		const letras = `${numeroToWords(lempiras)} lempiras`;
		const centavosTexto = centavos > 0 ? ` con ${centavos}/100 centavos` : " exactos";

		// Capitalizar primera letra
		return letras.charAt(0).toUpperCase() + letras.slice(1) + centavosTexto;
	}

	document.querySelector('form').addEventListener('submit', function(e) {
		e.preventDefault(); // Detener envío tradicional

		const form = e.target;
		const formData = new FormData(form);

		fetch('guardar_factura', {
				method: 'POST',
				body: formData
			})
			.then(response => response.json())
			.then(data => {
				if (data.success) {
					Swal.fire({
						title: '¡Éxito!',
						text: data.message,
						icon: 'success',
						showCancelButton: true,
						confirmButtonText: 'Ver Factura',
						cancelButtonText: 'OK'
					}).then((result) => {
						const facturaId = data.factura_id || data.id || null;

						if (result.isConfirmed && facturaId) {
							window.open(`ver_factura?id=${facturaId}`, '_blank');
							// Recargar página para limpiar el formulario
							location.reload();
						} else {
							// Redirige a la lista de facturas
							window.location.href = 'lista_facturas';
						}
					});

				} else {
					Swal.fire('Error', data.error, 'error');
				}
			})
			.catch(error => {
				Swal.fire('Error', 'Ocurrió un error inesperado.', 'error');
			});
	});
</script>