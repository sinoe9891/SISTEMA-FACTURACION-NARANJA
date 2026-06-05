<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) die("ID inválido");
$factura_id = (int)$_GET['id'];
$usuario_id = $_SESSION['usuario_id'];

$establecimiento_activo = $_SESSION['establecimiento_activo'] ?? null;
$nombre_establecimiento = 'No asignado';
if ($establecimiento_activo) {
	$st = $pdo->prepare("SELECT nombre FROM establecimientos WHERE establecimiento_id = ?");
	$st->execute([$establecimiento_activo]);
	$nombre_establecimiento = $st->fetchColumn() ?: 'No asignado';
}

$stmt = $pdo->prepare("SELECT u.nombre AS usuario_nombre, u.rol, c.id AS cliente_id, c.logo_url, c.nombre AS cliente_nombre
    FROM usuarios u INNER JOIN clientes_saas c ON u.cliente_id=c.id WHERE u.id=?");
$stmt->execute([$usuario_id]);
$datos = $stmt->fetch();
$_SESSION['usuario_rol'] = $datos['rol'];

$stmt = $pdo->prepare("SELECT rol, cliente_id FROM usuarios WHERE id=?");
$stmt->execute([$usuario_id]);
$user      = $stmt->fetch();
$es_admin  = in_array($user['rol'], ['admin', 'superadmin']);
$cliente_id = $user['cliente_id'];

$stmt = $pdo->prepare("SELECT * FROM facturas WHERE id=?");
$stmt->execute([$factura_id]);
$factura = $stmt->fetch();
if (!$factura || (!$es_admin && $factura['cliente_id'] != $cliente_id)) die("Acceso no autorizado");

$stmtClientes = $pdo->prepare("SELECT id, nombre FROM clientes_factura WHERE cliente_id=?");
$stmtClientes->execute([$cliente_id]);
$clientes = $stmtClientes->fetchAll();

$stmtProductos = $pdo->prepare("SELECT p.id, p.nombre, p.precio AS precio_base, p.tipo_isv,
    (SELECT precio_especial FROM precios_especiales WHERE producto_id=p.id AND cliente_id=? LIMIT 1) AS precio_especial
    FROM productos_clientes p WHERE p.cliente_id=?");
$stmtProductos->execute([$cliente_id, $cliente_id]);
$productos = $stmtProductos->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM factura_items_receptor WHERE factura_id=?");
$stmt->execute([$factura_id]);
$items = $stmt->fetchAll();

require_once '../../includes/templates/header.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
	:root {
		--navy: #1e40af;
		--navy-dark: #1e3a8a;
		--border: #e2e8f0;
		--surface: #fff;
		--surface-2: #f8fafc;
		--text-main: #1e293b;
		--text-muted: #64748b;
		--shadow-sm: 0 1px 3px rgba(0, 0, 0, .06);
		--shadow-md: 0 4px 16px rgba(0, 0, 0, .08);
		--radius: 14px;
		--radius-sm: 8px;
		--tr: .2s cubic-bezier(.4, 0, .2, 1);
	}

	.fe-page {
		padding: 1.5rem 0 3rem;
	}

	.fe-header {
		background: linear-gradient(135deg, #1e40af 0%, #1e3a8a 100%);
		border-radius: var(--radius);
		padding: 1.6rem 2rem;
		color: #fff;
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 1rem;
		margin-bottom: 1.5rem;
		box-shadow: var(--shadow-md);
		position: relative;
		overflow: hidden;
	}

	.fe-header::before {
		content: '';
		position: absolute;
		top: -40px;
		right: -40px;
		width: 180px;
		height: 180px;
		border-radius: 50%;
		background: rgba(255, 255, 255, .08);
		pointer-events: none;
	}

	.fe-header::after {
		content: '';
		position: absolute;
		bottom: -60px;
		left: 30%;
		width: 260px;
		height: 140px;
		border-radius: 50%;
		background: rgba(255, 255, 255, .05);
		pointer-events: none;
	}

	.fe-card {
		background: var(--surface);
		border: 1px solid var(--border);
		border-radius: var(--radius);
		box-shadow: var(--shadow-sm);
		margin-bottom: 1.25rem;
		overflow: hidden;
	}

	.fe-card-header {
		padding: .9rem 1.4rem;
		border-bottom: 1px solid var(--border);
		background: var(--surface-2);
		display: flex;
		align-items: center;
		gap: .6rem;
		font-weight: 700;
		font-size: .92rem;
	}

	.fe-card-body {
		padding: 1.25rem 1.4rem;
	}

	.producto-item {
		background: var(--surface-2);
		border: 1px solid var(--border);
		border-radius: var(--radius-sm);
		padding: 1rem;
		margin-bottom: .85rem;
	}

	.producto-item:hover {
		box-shadow: var(--shadow-sm);
	}

	.totales-row {
		display: flex;
		justify-content: space-between;
		align-items: center;
		padding: .45rem 0;
		border-bottom: 1px solid var(--border);
		font-size: .88rem;
	}

	.totales-row:last-child {
		border-bottom: none;
	}

	.totales-row.total-final {
		font-size: 1.05rem;
		font-weight: 700;
		color: var(--navy);
		padding-top: .7rem;
		margin-top: .3rem;
		border-top: 2px solid var(--navy);
	}

	.totales-label {
		color: var(--text-muted);
		font-weight: 500;
	}

	.totales-val {
		font-weight: 700;
		color: var(--text-main);
	}

	.btn-guardar-edit {
		display: inline-flex;
		align-items: center;
		gap: .5rem;
		padding: .7rem 2rem;
		background: var(--navy);
		color: #fff;
		border: none;
		border-radius: var(--radius-sm);
		font-size: .95rem;
		font-weight: 700;
		cursor: pointer;
		box-shadow: 0 2px 10px rgba(30, 64, 175, .35);
		transition: background var(--tr), transform var(--tr);
	}

	.btn-guardar-edit:hover {
		background: var(--navy-dark);
		transform: translateY(-1px);
	}

	.fe-form-label {
		font-size: .78rem;
		font-weight: 600;
		color: var(--text-muted);
		text-transform: uppercase;
		letter-spacing: .04em;
		margin-bottom: .35rem;
		display: block;
	}

	.precio-sugerido {
		font-size: .75rem;
		color: #64748b;
		margin-top: 3px;
	}

	.estado-badge {
		display: inline-flex;
		align-items: center;
		gap: .35rem;
		padding: .3rem .8rem;
		border-radius: 20px;
		font-size: .8rem;
		font-weight: 600;
	}

	.est-emitida {
		background: #dbeafe;
		color: #1e40af;
	}

	.est-anulada {
		background: #fee2e2;
		color: #dc2626;
	}

	.est-borrador {
		background: #fef9c3;
		color: #ca8a04;
	}

	.exo-panel {
		background: #fffbeb;
		border: 1.5px solid #fde68a;
		border-radius: var(--radius-sm);
		padding: 1rem;
		margin-top: .5rem;
	}
</style>

<div class="fe-page container-xxl">

	<!-- Header -->
	<div class="fe-header">
		<div>
			<div class="d-flex align-items-center gap-2 mb-1">
				<a href="lista_facturas" class="btn btn-sm"
					style="color:#fff;border-color:rgba(255,255,255,.4);background:rgba(255,255,255,.12)">
					<i class="bi bi-arrow-left me-1"></i>Volver
				</a>
			</div>
			<h4 style="font-size:1.35rem;font-weight:700;margin:0">
				<i class="bi bi-pencil-square me-2"></i>Editar Factura #<?= htmlspecialchars($factura['correlativo']) ?>
			</h4>
			<p style="font-size:.82rem;opacity:.8;margin:.25rem 0 0">
				<?= htmlspecialchars($nombre_establecimiento) ?> &nbsp;·&nbsp;
				<?= htmlspecialchars(ucfirst($datos['rol'])) ?> &nbsp;·&nbsp;
				<?= htmlspecialchars($datos['cliente_nombre']) ?>
			</p>
		</div>
		<div class="d-flex align-items-center gap-3">
			<?php if (!empty($datos['logo_url'])): ?>
				<img src="<?= htmlspecialchars($datos['logo_url']) ?>" alt="Logo"
					style="max-height:48px;border-radius:8px;background:#fff;padding:4px;">
			<?php endif; ?>
			<div style="font-size:2.8rem;opacity:.2;font-weight:900;line-height:1">✏️</div>
		</div>
	</div>

	<form id="formEditar" action="guardar_factura_editada" method="POST">
		<input type="hidden" name="factura_id" value="<?= $factura_id ?>">

		<div class="row g-4">
			<div class="col-lg-8">

				<!-- Cliente -->
				<div class="fe-card">
					<div class="fe-card-header"><i class="bi bi-person-fill text-primary"></i>Cliente (Receptor)</div>
					<div class="fe-card-body">
						<select name="receptor_id" class="form-select" disabled>
							<?php foreach ($clientes as $cl): ?>
								<option value="<?= $cl['id'] ?>" <?= $cl['id'] == $factura['receptor_id'] ? 'selected' : '' ?>>
									<?= htmlspecialchars($cl['nombre']) ?></option>
							<?php endforeach; ?>
						</select>
						<small class="text-muted"><i class="bi bi-lock me-1"></i>El receptor no se puede cambiar al
							editar una factura.</small>
					</div>
				</div>

				<!-- Fecha y condición -->
				<div class="fe-card">
					<div class="fe-card-header"><i class="bi bi-calendar3 text-primary"></i>Datos de Emisión</div>
					<div class="fe-card-body">
						<div class="row g-3">
							<div class="col-md-6">
								<label class="fe-form-label">Fecha de emisión</label>
								<input type="datetime-local" name="fecha_emision" class="form-control"
									value="<?= date('Y-m-d\TH:i', strtotime($factura['fecha_emision'])) ?>"
									<?= !$es_admin ? 'readonly' : '' ?>>
							</div>
							<div class="col-md-6">
								<label class="fe-form-label">Condición de pago</label>
								<select name="condicion_pago" class="form-select">
									<option value="Contado" <?= $factura['condicion_pago'] === 'Contado' ? 'selected' : '' ?>>
										Contado</option>
									<option value="Credito" <?= $factura['condicion_pago'] === 'Credito' ? 'selected' : '' ?>>
										Crédito</option>
								</select>
							</div>
						</div>
					</div>
				</div>

				<!-- Exoneración -->
				<div class="fe-card">
					<div class="fe-card-header"><i class="bi bi-shield-check text-warning"></i>Exoneración</div>
					<div class="fe-card-body">
						<div class="form-check mb-2">
							<input type="checkbox" class="form-check-input" name="exonerado" id="exonerado"
								<?= $factura['exonerado'] ? 'checked' : '' ?>>
							<label class="form-check-label fw-semibold" for="exonerado">Factura exonerada (ISV =
								0)</label>
						</div>
						<div id="campos-exoneracion" class="exo-panel"
							style="<?= $factura['exonerado'] ? 'display:block' : 'display:none' ?>">
							<div class="row g-3">
								<div class="col-md-4"><label class="fe-form-label">Orden de compra exenta</label><input
										type="text" name="orden_compra_exenta" class="form-control"
										value="<?= htmlspecialchars($factura['orden_compra_exenta']) ?>"></div>
								<div class="col-md-4"><label class="fe-form-label">Constancia de
										exoneración</label><input type="text" name="constancia_exoneracion"
										class="form-control"
										value="<?= htmlspecialchars($factura['constancia_exoneracion']) ?>"></div>
								<div class="col-md-4"><label class="fe-form-label">Registro SAG</label><input
										type="text" name="registro_sag" class="form-control"
										value="<?= htmlspecialchars($factura['registro_sag']) ?>"></div>
							</div>
						</div>
					</div>
				</div>

				<!-- Productos -->
				<div class="fe-card">
					<div class="fe-card-header d-flex justify-content-between align-items-center">
						<span><i class="bi bi-cart3 text-primary me-2"></i>Productos / Servicios</span>
						<button type="button" class="btn btn-sm btn-outline-primary" id="agregar-producto">
							<i class="bi bi-plus-circle me-1"></i>Agregar línea
						</button>
					</div>
					<div class="fe-card-body">
						<!-- Template oculto -->
						<div id="producto-template" style="display:none">
							<div class="producto-item">
								<div class="row g-2 align-items-end">
									<div class="col-md-5"><label class="fe-form-label">Producto</label>
										<select name="productos[0][id]" class="form-select">
											<option value="">Seleccione producto</option>
											<?php foreach ($productos as $prod):
												$precio = $prod['precio_especial'] !== null ? $prod['precio_especial'] : $prod['precio_base'];
											?><option value="<?= $prod['id'] ?>" data-precio="<?= $precio ?>"
													data-precio-base="<?= $prod['precio_base'] ?>"
													data-isv="<?= $prod['tipo_isv'] ?>">
													<?= htmlspecialchars($prod['nombre']) ?> -
													L<?= number_format($prod['precio_base'], 2) ?></option>
											<?php endforeach; ?>
										</select>
									</div>
									<div class="col-md-2"><label class="fe-form-label">Cantidad</label><input
											type="number" name="productos[0][cantidad]" class="form-control" min="1"
											value="1"></div>
									<div class="col-md-2"><label class="fe-form-label">Precio Unit.</label><input
											type="number" step="0.01" name="productos[0][precio_unitario]"
											class="form-control precio-unitario" value="0.00"></div>
									<div class="col-md-2"><label class="fe-form-label">Subtotal</label><input
											type="number" step="0.01" name="productos[0][precio]"
											class="form-control subtotal-producto" value="0.00"></div>
									<div class="col-md-1 d-flex align-items-end"><button type="button"
											class="btn btn-outline-danger btn-sm w-100 remove-producto"><i
												class="bi bi-trash3"></i></button></div>
									<div class="col-12 mt-1"><textarea name="productos[0][descripcion_html]"
											class="form-control form-control-sm" rows="1"
											placeholder="Detalles / Descripción"></textarea></div>
									<div class="col-12"><small class="precio-sugerido text-muted"></small></div>
								</div>
							</div>
						</div>

						<!-- Ítems existentes -->
						<div id="productos-container">
							<?php foreach ($items as $index => $item): ?>
								<div class="producto-item">
									<div class="row g-2 align-items-end">
										<div class="col-md-5"><label class="fe-form-label">Producto</label>
											<select name="productos[<?= $index ?>][id]" class="form-select" required>
												<option value="">Seleccione producto</option>
												<?php foreach ($productos as $prod):
													$precio = $prod['precio_especial'] !== null ? $prod['precio_especial'] : $prod['precio_base'];
												?><option value="<?= $prod['id'] ?>" data-precio="<?= $precio ?>"
														data-precio-base="<?= $prod['precio_base'] ?>"
														data-isv="<?= $prod['tipo_isv'] ?>"
														<?= $prod['id'] == $item['producto_id'] ? 'selected' : '' ?>>
														<?= htmlspecialchars($prod['nombre']) ?> - Estándar:
														L<?= number_format($prod['precio_base'], 2) ?><?php if ($prod['precio_especial'] !== null): ?>
														| Especial:
														L<?= number_format($prod['precio_especial'], 2) ?><?php endif; ?>
													</option><?php endforeach; ?>
											</select>
										</div>
										<div class="col-md-2"><label class="fe-form-label">Cantidad</label><input
												type="number" name="productos[<?= $index ?>][cantidad]" class="form-control"
												min="1" value="<?= $item['cantidad'] ?>" required></div>
										<div class="col-md-2"><label class="fe-form-label">Precio Unit.</label><input
												type="number" step="0.01" name="productos[<?= $index ?>][precio_unitario]"
												class="form-control precio-unitario"
												value="<?= $item['precio_unitario'] ?>"></div>
										<div class="col-md-2"><label class="fe-form-label">Subtotal</label><input
												type="number" step="0.01" name="productos[<?= $index ?>][precio]"
												class="form-control subtotal-producto"
												value="<?= $item['cantidad'] * $item['precio_unitario'] ?>"></div>
										<div class="col-md-1 d-flex align-items-end"><button type="button"
												class="btn btn-outline-danger btn-sm w-100 remove-producto"><i
													class="bi bi-trash3"></i></button></div>
										<div class="col-12 mt-1"><textarea name="productos[<?= $index ?>][descripcion_html]"
												class="form-control form-control-sm" rows="1"
												placeholder="Detalles / Descripción"><?= htmlspecialchars($item['descripcion_html'] ?? '') ?></textarea>
										</div>
										<div class="col-12"><small class="precio-sugerido text-muted">Precio sugerido:
												L<?= number_format($item['cantidad'] * $item['precio_unitario'], 2) ?></small>
										</div>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				</div>

				<!-- Estado -->
				<div class="fe-card">
					<div class="fe-card-header"><i class="bi bi-toggles text-secondary"></i>Estado de la Factura</div>
					<div class="fe-card-body">
						<div class="row g-3 align-items-center">
							<div class="col-md-4">
								<label class="fe-form-label">Estado</label>
								<select name="estado" id="estado" class="form-select">
									<?php foreach (['emitida', 'anulada', 'borrador'] as $e): ?>
										<option value="<?= $e ?>" <?= $factura['estado'] === $e ? 'selected' : '' ?>>
											<?= ucfirst($e) ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="col-md-2 d-flex align-items-end pb-1">
								<div class="form-check">
									<input class="form-check-input" type="checkbox" id="estado_declarada"
										name="estado_declarada" <?= $factura['estado_declarada'] ? 'checked' : '' ?>>
									<label class="form-check-label" for="estado_declarada">Declarada</label>
								</div>
							</div>
							<div class="col-md-2 d-flex align-items-end pb-1">
								<div class="form-check">
									<input class="form-check-input" type="checkbox" id="pagada" name="pagada"
										<?= $factura['pagada'] ? 'checked' : '' ?>>
									<label class="form-check-label" for="pagada">Pagada</label>
								</div>
							</div>
							<div class="col-md-3 d-flex align-items-end pb-1">
								<div class="form-check">
									<input class="form-check-input" type="checkbox" id="enviada_receptor"
										name="enviada_receptor" <?= $factura['enviada_receptor'] ? 'checked' : '' ?>>
									<label class="form-check-label" for="enviada_receptor">Enviada al cliente</label>
								</div>
							</div>
						</div>
					</div>
				</div>

			</div><!-- /col-lg-8 -->

			<!-- Totales sidebar -->
			<div class="col-lg-4">
				<div class="fe-card" style="position:sticky;top:1rem;">
					<div class="fe-card-header"><i class="bi bi-receipt text-success me-1"></i>Resumen de Totales</div>
					<div class="fe-card-body">
						<div class="totales-row"><span class="totales-label">Subtotal</span><span class="totales-val"
								id="disp_subtotal">L <?= $factura['subtotal'] ?></span></div>
						<div class="totales-row"><span class="totales-label">Importe Gravado 15%</span><span
								class="totales-val" id="disp_ig15">L <?= $factura['importe_gravado_15'] ?></span></div>
						<div class="totales-row"><span class="totales-label">Importe Gravado 18%</span><span
								class="totales-val" id="disp_ig18">L <?= $factura['importe_gravado_18'] ?></span></div>
						<div class="totales-row"><span class="totales-label">ISV 15%</span><span class="totales-val"
								id="disp_isv15">L <?= $factura['isv_15'] ?></span></div>
						<div class="totales-row"><span class="totales-label">ISV 18%</span><span class="totales-val"
								id="disp_isv18">L 0.00</span></div>
						<div class="totales-row total-final"><span class="totales-label"
								style="color:var(--navy)">TOTAL</span><span class="totales-val fs-5" id="disp_total"
								style="color:var(--navy)">L <?= $factura['total'] ?></span></div>
						<div class="mt-3 p-2 rounded-2"
							style="background:#eff6ff;border:1px solid #bfdbfe;font-size:.78rem;color:#1e40af;font-style:italic;"
							id="disp_letras"><?= htmlspecialchars($factura['monto_letras']) ?></div>

						<!-- Hidden para POST -->
						<input type="hidden" name="subtotal" id="h_subtotal" value="<?= $factura['subtotal'] ?>">
						<input type="hidden" name="importe_gravado_15" id="h_ig15"
							value="<?= $factura['importe_gravado_15'] ?>">
						<input type="hidden" name="importe_gravado_18" id="h_ig18"
							value="<?= $factura['importe_gravado_18'] ?>">
						<input type="hidden" name="isv_15" id="h_isv15" value="<?= $factura['isv_15'] ?>">
						<input type="hidden" name="isv_18" id="h_isv18" value="0">
						<input type="hidden" name="total" id="h_total" value="<?= $factura['total'] ?>">
						<input type="hidden" name="monto_letras" id="h_letras"
							value="<?= htmlspecialchars($factura['monto_letras']) ?>">
					</div>
					<div class="p-3 border-top d-flex gap-2">
						<button type="submit" class="btn-guardar-edit flex-grow-1" id="btnGuardar">
							<i class="bi bi-floppy-fill me-1"></i>Guardar Cambios
						</button>
						<a href="lista_facturas" class="btn btn-outline-secondary">Cancelar</a>
					</div>
				</div>
			</div>

		</div><!-- /row -->
	</form>
</div>

<script>
	/* ── Exoneración ─────────────────────────────────────────────────────────── */
	document.getElementById('exonerado').addEventListener('change', function() {
		document.getElementById('campos-exoneracion').style.display = this.checked ? 'block' : 'none';
		calcularTotalYLetra();
	});

	/* ── Agregar producto (desde template) ───────────────────────────────────── */
	document.getElementById('agregar-producto').addEventListener('click', function() {
		const contenedor = document.getElementById('productos-container');
		const template = document.getElementById('producto-template');
		let baseItem = contenedor.querySelector('.producto-item');
		if (!baseItem) baseItem = template.querySelector('.producto-item');
		if (!baseItem) return;
		const nuevo = baseItem.cloneNode(true);
		const index = contenedor.children.length;
		nuevo.querySelectorAll('input,select,textarea').forEach(el => {
			if (el.name?.includes('[cantidad]')) el.value = 1;
			else if (el.name?.includes('[precio_unitario]') || el.name?.includes('[precio]')) el.value =
				'0.00';
			else if (el.tagName === 'SELECT') el.selectedIndex = 0;
			else if (el.tagName === 'TEXTAREA') el.value = '';
			if (el.name) el.name = el.name.replace(/\[\d+\]/, `[${index}]`);
		});
		const sp = nuevo.querySelector('.precio-sugerido');
		if (sp) sp.textContent = '';
		contenedor.appendChild(nuevo);
		calcularTotalYLetra();
	});

	/* ── Eliminar producto ───────────────────────────────────────────────────── */
	document.getElementById('productos-container').addEventListener('click', function(e) {
		if (e.target.closest('.remove-producto')) {
			if (document.querySelectorAll('#productos-container .producto-item').length > 1) {
				e.target.closest('.producto-item').remove();
				calcularTotalYLetra();
			}
		}
	});

	/* ── Cambio de producto → prellenar precio ───────────────────────────────── */
	document.getElementById('productos-container').addEventListener('change', function(e) {
		if (e.target.tagName === 'SELECT' && e.target.name.includes('[id]')) {
			const sel = e.target.selectedOptions[0];
			const item = e.target.closest('.producto-item');
			const precio = parseFloat(sel.getAttribute('data-precio')) || 0;
			const precioBase = parseFloat(sel.getAttribute('data-precio-base')) || 0;
			const cantidad = parseFloat(item.querySelector('input[name$="[cantidad]"]').value) || 1;
			const puInput = item.querySelector('input[name$="[precio_unitario]"]');
			const stInput = item.querySelector('input[name$="[precio]"]');
			const spEl = item.querySelector('.precio-sugerido');
			if (puInput) puInput.value = precio.toFixed(2);
			if (stInput) stInput.value = (precio * cantidad).toFixed(2);
			if (spEl) spEl.textContent = precio > 0 ? `Precio sugerido: L${(precioBase*cantidad).toFixed(2)}` : '';
			calcularTotalYLetra();
		}
	});

	document.getElementById('productos-container').addEventListener('input', function(e) {
		const item = e.target.closest('.producto-item');
		if (!item) return;

		const cantInp = item.querySelector('input[name$="[cantidad]"]');
		const puInp   = item.querySelector('input.precio-unitario');
		const stInp   = item.querySelector('input.subtotal-producto');
		const cantidad = parseFloat(cantInp?.value) || 0;

		// Cambió cantidad o precio unitario → recalcular subtotal de la línea
		if (e.target === cantInp || e.target === puInp) {
			const pu = parseFloat(puInp?.value) || 0;
			if (stInp) stInp.value = (cantidad * pu).toFixed(2);
		}
		// El usuario editó el subtotal directamente → recalcular precio unitario
		else if (e.target === stInp) {
			const sub = parseFloat(stInp.value) || 0;
			if (puInp && cantidad > 0) puInp.value = (sub / cantidad).toFixed(2);
		}

		calcularTotalYLetra();
	});

	/* ── Calcular totales ────────────────────────────────────────────────────── */
	function calcularTotalYLetra() {
		let sub = 0,
			g15 = 0,
			g18 = 0,
			i15 = 0,
			i18 = 0;
		const exo = document.getElementById('exonerado').checked;
		document.querySelectorAll('#productos-container .producto-item').forEach(item => {
			const cantidad = parseFloat(item.querySelector('input[name$="[cantidad]"]').value) || 0;
			const puInput = item.querySelector('input[name$="[precio_unitario]"]');
			const stInput = item.querySelector('input[name$="[precio]"]');
			// Usar precio_unitario si está disponible, si no usar precio/cantidad
			let pu = puInput ? (parseFloat(puInput.value) || 0) : 0;
			if (!pu && stInput && cantidad) pu = (parseFloat(stInput.value) || 0) / cantidad;
			const t = cantidad * pu;
			sub += t;
			if (!exo) {
				const sel = item.querySelector('select[name$="[id]"]');
				const v = parseInt(sel?.selectedOptions[0]?.getAttribute('data-isv')) || 0;
				if (v === 15) {
					i15 += t * .15;
					g15 += t;
				} else if (v === 18) {
					i18 += t * .18;
					g18 += t;
				}
			}
		});
		const total = sub + i15 + i18;
		const fmt = n => 'L ' + n.toLocaleString('es-HN', {
			minimumFractionDigits: 2,
			maximumFractionDigits: 2
		});
		document.getElementById('disp_subtotal').textContent = fmt(sub);
		document.getElementById('disp_ig15').textContent = fmt(g15);
		document.getElementById('disp_ig18').textContent = fmt(g18);
		document.getElementById('disp_isv15').textContent = fmt(i15);
		document.getElementById('disp_isv18').textContent = fmt(i18);
		document.getElementById('disp_total').textContent = fmt(total);
		const letras = numeroALetras(total);
		document.getElementById('disp_letras').textContent = letras;
		// Ocultos
		document.getElementById('h_subtotal').value = sub.toFixed(2);
		document.getElementById('h_ig15').value = g15.toFixed(2);
		document.getElementById('h_ig18').value = g18.toFixed(2);
		document.getElementById('h_isv15').value = i15.toFixed(2);
		document.getElementById('h_isv18').value = i18.toFixed(2);
		document.getElementById('h_total').value = total.toFixed(2);
		document.getElementById('h_letras').value = letras;
	}

	/* ── Submit con confirmación (motivo + credenciales) ─────────────────────── */
	document.getElementById('formEditar').addEventListener('submit', function(e) {
		e.preventDefault();
		Swal.fire({
			title: '¿Guardar cambios en la factura?',
			html: `<input type="text"     id="motivo"  class="swal2-input" placeholder="Motivo (obligatorio)">
              <input type="text"     id="usuario" class="swal2-input" placeholder="Usuario admin/superadmin">
              <input type="password" id="clave"   class="swal2-input" placeholder="Contraseña">`,
			focusConfirm: false,
			showCancelButton: true,
			confirmButtonText: 'Confirmar',
			confirmButtonColor: '#1e40af',
			preConfirm: () => {
				const m = document.getElementById('motivo').value.trim();
				const u = document.getElementById('usuario').value.trim();
				const c = document.getElementById('clave').value.trim();
				if (!m || !u || !c) {
					Swal.showValidationMessage('Todos los campos son obligatorios.');
					return false;
				}
				return {
					motivo: m,
					usuario: u,
					clave: c
				};
			}
		}).then(result => {
			if (!result.isConfirmed) return;
			const form = document.getElementById('formEditar');
			[{
				name: 'motivo',
				val: result.value.motivo
			}, {
				name: 'usuario_autoriza',
				val: result.value.usuario
			}, {
				name: 'clave_autoriza',
				val: result.value.clave
			}]
			.forEach(({
				name,
				val
			}) => {
				const i = document.createElement('input');
				i.type = 'hidden';
				i.name = name;
				i.value = val;
				form.appendChild(i);
			});
			const btn = document.getElementById('btnGuardar');
			btn.disabled = true;
			btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Guardando...';
			fetch('guardar_factura_editada', {
					method: 'POST',
					body: new FormData(form)
				})
				.then(r => r.text()).then(resp => {
					const errores = ['Usuario o contraseña incorrecta', 'Solo un admin o superadmin',
						'Todos los campos de autorización son obligatorios',
						'Factura no encontrada', 'Acceso no autorizado', 'Error al guardar cambios'
					];
					const hit = errores.find(e => resp.includes(e));
					if (hit) {
						Swal.fire('Error', hit, 'error');
					} else {
						Swal.fire({
							icon: 'success',
							title: '¡Éxito!',
							text: 'Factura editada correctamente.',
							timer: 1800,
							showConfirmButton: false
						}).then(() => window.location.href = 'lista_facturas?success=1');
					}
					btn.disabled = false;
					btn.innerHTML = '<i class="bi bi-floppy-fill me-1"></i>Guardar Cambios';
				}).catch(err => {
					Swal.fire('Error', 'Error en la petición: ' + err.message, 'error');
					btn.disabled = false;
					btn.innerHTML = '<i class="bi bi-floppy-fill me-1"></i>Guardar Cambios';
				});
		});
	});

	/* ── Cargar productos del receptor vía AJAX (al abrir) ───────────────────── */
	document.addEventListener('DOMContentLoaded', () => {
		calcularTotalYLetra();
		const receptorId = <?= json_encode($factura['receptor_id']) ?>;
		if (!receptorId) return;
		fetch(`../../includes/api/productos_por_receptor.php?receptor_id=${receptorId}`)
			.then(r => r.json()).then(prods => {
				if (!Array.isArray(prods) || !prods.length) return;
				document.querySelectorAll('select[name$="[id]"]').forEach(sel => {
					const selVal = sel.value;
					const frag = document.createDocumentFragment();
					const opt0 = new Option('Seleccione producto', '');
					frag.appendChild(opt0);
					prods.forEach(prod => {
						const o = new Option(`${prod.nombre} - L${(+prod.precio).toFixed(2)}`,
							prod.id);
						o.dataset.precio = prod.precio;
						o.dataset.precioBase = prod.precio;
						o.dataset.isv = prod.tipo_isv;
						if (+prod.id === +selVal) o.selected = true;
						frag.appendChild(o);
					});
					sel.replaceChildren(frag);
				});
			}).catch(err => console.error('Error cargando productos por receptor:', err));
	});

	/* ── Número a letras ─────────────────────────────────────────────────────── */
	function numeroALetras(num) {
		const U = ["", "uno", "dos", "tres", "cuatro", "cinco", "seis", "siete", "ocho", "nueve"];
		const D = ["", "", "veinte", "treinta", "cuarenta", "cincuenta", "sesenta", "setenta", "ochenta", "noventa"];
		const T = ["diez", "once", "doce", "trece", "catorce", "quince", "dieciséis", "diecisiete", "dieciocho",
			"diecinueve"
		];
		const C = ["", "ciento", "doscientos", "trescientos", "cuatrocientos", "quinientos", "seiscientos", "setecientos",
			"ochocientos", "novecientos"
		];

		function g(n) {
			let o = "";
			if (n == 100) return "cien";
			if (n > 99) {
				o += C[Math.floor(n / 100)] + " ";
				n %= 100;
			}
			if (n >= 20) {
				o += D[Math.floor(n / 10)];
				if (n % 10) o += " y " + U[n % 10];
			} else if (n >= 10) o += T[n - 10];
			else if (n > 0) o += U[n];
			return o.trim();
		}

		function sec(n, s, p) {
			return n === 0 ? "" : (n === 1 ? `un ${s}` : `${words(n)} ${p}`);
		}

		function words(n) {
			const M = Math.floor(n / 1e6),
				K = Math.floor((n - M * 1e6) / 1e3),
				R = n % 1e3;
			return ((M ? sec(M, "millón", "millones") + " " : "") + (K ? sec(K, "mil", "mil") + " " : "") + (R ? g(R) : ""))
				.trim();
		}
		const p = num.toFixed(2).split(".");
		const l = parseInt(p[0]),
			c = parseInt(p[1]);
		const t = words(l) + " lempiras" + (c > 0 ? ` con ${c}/100 centavos` : " exactos");
		return t.charAt(0).toUpperCase() + t.slice(1);
	}
</script>

<?php require_once '../../includes/templates/footer.php'; ?>