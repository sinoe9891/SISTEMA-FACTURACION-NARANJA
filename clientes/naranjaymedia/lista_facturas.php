<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';

define('ALERTA_FACTURAS_RESTANTES', 20);
define('ALERTA_CAI_DIAS', 30);



require_once '../../includes/templates/header.php';


// Obtener nombre del establecimiento
$stmtEstab = $pdo->prepare("SELECT nombre FROM establecimientos WHERE establecimiento_id = ?");
$stmtEstab->execute([$establecimiento_activo]);
$establecimiento = $stmtEstab->fetch(PDO::FETCH_ASSOC);
$nombre_establecimiento = $establecimiento ? $establecimiento['nombre'] : 'No asignado';

// Obtener todos los CAI activos con facturas restantes para este establecimiento
$stmtCAIs = $pdo->prepare("
    SELECT *, 
           CASE WHEN CURDATE() <= fecha_limite THEN 1 ELSE 0 END AS activo
    FROM cai_rangos
    WHERE cliente_id = ? AND establecimiento_id = ?
    ORDER BY fecha_recepcion DESC
");
$stmtCAIs->execute([$cliente_id, $establecimiento_activo]);
$cais = $stmtCAIs->fetchAll();

// Determinar CAI seleccionado para filtrar facturas
$caix = null;
if (isset($_GET['cai_id']) && ctype_digit($_GET['cai_id'])) {
	$caix = intval($_GET['cai_id']);
	// Validar que el CAI esté dentro de los activos
	$caix_valid = false;
	foreach ($cais as $cai) {
		if ($cai['id'] == $caix) {
			$caix_valid = true;
			break;
		}
	}
	if (!$caix_valid) {
		$caix = null; // Ignorar filtro inválido
	}
}

// Si solo hay un CAI, forzar filtro a ese CAI
if (count($cais) === 1) {
	$caix = $cais[0]['id'];
}

// Si no se seleccionó ningún CAI manualmente, preseleccionar el CAI activo por defecto
if ($caix === null && !isset($_GET['cai_id'])) {
	foreach ($cais as $cai) {
		if ($cai['activo'] == 1) {
			$caix = $cai['id'];
			break; // Tomar el primero activo que encuentre
		}
	}
}
$ultimoCorrelativoCAI = null;

// Capturar y sanitizar fechas
$fecha_desde = !empty($_GET['fecha_desde']) ? $_GET['fecha_desde'] : null;
$fecha_hasta = !empty($_GET['fecha_hasta']) ? $_GET['fecha_hasta'] : null;

// Validar formato fecha
if ($fecha_desde && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_desde)) $fecha_desde = null;
if ($fecha_hasta && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_hasta)) $fecha_hasta = null;

// Construir condiciones dinámicas
$params = [$cliente_id, $establecimiento_activo];
$whereExtra = "";

if ($caix) {
	$whereExtra .= " AND f.cai_id = ?";
	$params[] = $caix;
}
if ($fecha_desde) {
	$whereExtra .= " AND f.fecha_emision >= ?";
	$params[] = $fecha_desde;
}
if ($fecha_hasta) {
	$whereExtra .= " AND f.fecha_emision <= ?";
	$params[] = $fecha_hasta;
}

$stmtFacturas = $pdo->prepare("
    SELECT f.id, f.correlativo, f.fecha_emision, f.total, f.monto_letras, 
           f.estado, f.pagada, f.enviada_receptor, cf.nombre AS receptor 
    FROM facturas f
    INNER JOIN clientes_factura cf ON f.receptor_id = cf.id
    WHERE f.cliente_id = ? AND f.establecimiento_id = ?
    $whereExtra
    ORDER BY f.fecha_emision DESC
");
$stmtFacturas->execute($params);
$facturas = $stmtFacturas->fetchAll();

// require_once '../../includes/templates/header.php';
?>
<script>
	$(document).ready(function() {
		$('#tabla-facturas').DataTable({
			pageLength: 10,
			language: {
				url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json'
			},
			lengthChange: false, // Oculta selector de cantidad de filas, si quieres lo puedes quitar
			ordering: true,
			order: [
				[0, 'desc']
			], // Ordena por fecha descendente por defecto
			responsive: true
		});
	});
</script>
<div class="container mt-4">
	<div class="d-flex justify-content-between align-items-center mb-3">
		<div>
			<h4>📜 Historial de facturas - <?= htmlspecialchars($datos['cliente_nombre']) ?></h4>
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

	<?php if (count($cais) >= 1): ?>
		<form method="GET" class="mb-4">
			<div class="row g-3 align-items-end">

				<!-- Selector CAI -->
				<div class="col-md-5">
					<label for="cai_id" class="form-label">Filtrar por CAI:</label>
					<select id="cai_id" name="cai_id" class="form-select" <?= (count($cais) === 1) ? 'disabled' : '' ?>>
						<option value="">-- Todos los CAI --</option>
						<?php foreach ($cais as $cai): ?>
							<option value="<?= $cai['id'] ?>" <?= ($caix == $cai['id']) ? 'selected' : '' ?>>
								<?= htmlspecialchars($cai['cai']) ?>
								<?= $cai['activo'] ? '✅' : '⛔ Vencido' ?>
								| Rango: <?= $cai['rango_inicio'] ?> - <?= $cai['rango_fin'] ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<!-- Fecha desde -->
				<div class="col-md-2">
					<label class="form-label">Desde:</label>
					<input type="date" name="fecha_desde" class="form-control"
						value="<?= htmlspecialchars($_GET['fecha_desde'] ?? '') ?>">
				</div>

				<!-- Fecha hasta -->
				<div class="col-md-2">
					<label class="form-label">Hasta:</label>
					<input type="date" name="fecha_hasta" class="form-control"
						value="<?= htmlspecialchars($_GET['fecha_hasta'] ?? '') ?>">
				</div>

				<!-- Botones -->
				<div class="col-md-3 d-flex gap-2">
					<button type="submit" class="btn btn-primary w-100">🔍 Filtrar</button>
					<a href="?" class="btn btn-outline-secondary w-100">🔄 Limpiar</a>
				</div>
			</div>

			<?php if (count($cais) === 1): ?>
				<input type="hidden" name="cai_id" value="<?= $cais[0]['id'] ?>">
			<?php endif; ?>
		</form>
	<?php endif; ?>

	<?php if (count($facturas) > 0): ?>
		<div class="table-responsive">
			<table id="tabla-facturas" class="table table-striped table-bordered">
				<thead class="table-dark">
					<tr>
						<th>Correlativo</th>
						<th>Fecha</th>
						<th>Cliente</th>
						<th>Total</th>
						<th>Estado</th>
						<th>Enviada</th>
						<th>Pagada</th>
						<th>Acciones</th>
						<th>PDF</th>
					</tr>
				</thead>
				<tbody>

					<?php
					$maxCorrelativo = null;
					$maxFacturaId = null;
					if ($caix) {
						$stmtUltimo = $pdo->prepare("SELECT ultimo_correlativo FROM cai_rangos WHERE id = ?");
						$stmtUltimo->execute([$caix]);
						$ultimoCorrelativoCAI = $stmtUltimo->fetchColumn();
					}

					?>

					<!-- En el loop de facturas: -->
					<?php foreach ($facturas as $f): ?>
						<tr>
							<td><?= htmlspecialchars($f['correlativo']) ?></td>
							<td><?= date('d/m/Y', strtotime($f['fecha_emision'])) ?></td>
							<td><?= htmlspecialchars($f['receptor']) ?></td>
							<td>L<?= number_format($f['total'], 2) ?></td>
							<td><?= ucfirst($f['estado']) ?></td>
							<td><?php

								if ($f['enviada_receptor'] == 1): ?>
									<button class="btn btn-sm btn-success">Sí</button>
								<?php elseif ($f['enviada_receptor'] == 0): ?>
									<button class="btn btn-sm btn-danger">No</button>
								<?php endif; ?>
							</td>
							<td><?php

								if ($f['pagada'] == 1): ?>
									<button class="btn btn-sm btn-success">Sí</button>
								<?php elseif ($f['pagada'] == 0): ?>
									<button class="btn btn-sm btn-danger">No</button>
								<?php endif; ?>
							</td>

							<td>
								<?php
								$correlativoFactura = trim((string)$f['correlativo']);
								$correlativoUltimo = trim((string)($ultimoCorrelativoCAI ?? ''));
								$puedeEliminar = ($correlativoFactura === $correlativoUltimo);
								$esAdmin = in_array($datos['rol'], ['admin', 'superadmin']);
								?>

								<?php if ($f['estado'] === 'emitida'): ?>
									<button onclick="accionFactura(<?= $f['id'] ?>, 'anular')"
										class="btn btn-sm btn-warning">Anular</button>

									<?php if ($puedeEliminar || $esAdmin): ?>
										<a href="editar_factura?id=<?= $f['id'] ?>" class="btn btn-sm btn-info">Editar</a>
										<button onclick="accionFactura(<?= $f['id'] ?>, 'eliminar')"
											class="btn btn-sm btn-danger">Eliminar</button>
									<?php endif; ?>

								<?php elseif ($f['estado'] === 'anulada'): ?>
									<button class="btn btn-sm btn-secondary" disabled>Anulada</button>
									<button onclick="accionFactura(<?= $f['id'] ?>, 'restaurar')"
										class="btn btn-sm btn-success">Reactivar</button>

								<?php elseif ($f['estado'] === 'borrador'): ?>
									<button onclick="accionFactura(<?= $f['id'] ?>, 'restaurar')"
										class="btn btn-sm btn-success">Reactivar</button>
								<?php endif; ?>

								<?php if ($esAdmin && !$puedeEliminar): ?>
									<div style="font-size: 11px; color: #dc3545; margin-top: 5px;">
										<small><strong>Advertencia:</strong> Solo admin puede eliminar facturas que no son las
											últimas del CAI.</small>
									</div>
								<?php endif; ?>

								<!-- Opcional: Debug de correlativos -->
								<!--
	<div style="font-size: 11px; color: #555; margin-top: 5px;">
		<small>Factura: <?= $correlativoFactura ?></small><br>
		<small>Último CAI: <?= $correlativoUltimo ?></small>
	</div>
	-->
							</td>


							<td>
								<a href="ver_factura?id=<?= $f['id'] ?>" class="btn btn-sm btn-primary" target="_blank">Ver /
									Imprimir</a>
							</td>
						</tr>
					<?php endforeach; ?>


				</tbody>
			</table>

		</div>
	<?php elseif ($caix): ?>
		<div class="alert alert-info">No se han generado facturas aún para el CAI seleccionado.</div>
	<?php else: ?>
		<div class="alert alert-warning">Por favor, seleccione un CAI para visualizar las facturas.</div>
	<?php endif; ?>

</div>
<?php
require_once '../../includes/templates/footer.php';
?>

<script>
	function accionFactura(facturaId, accion) {
		Swal.fire({
			title: `¿Estás seguro de ${accion} esta factura?`,
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
				fetch('procesar_accion_factura', {
						method: 'POST',
						headers: {
							'Content-Type': 'application/json'
						},
						body: JSON.stringify({
							factura_id: facturaId,
							accion: accion,
							motivo: result.value.motivo,
							usuario_autoriza: result.value.usuario,
							clave_autoriza: result.value.clave
						})
					})
					.then(r => r.json())
					.then(data => {
						if (data.success) {
							Swal.fire('Correcto', data.message, 'success').then(() => location.reload());
						} else {
							Swal.fire('Error', data.error, 'error');
						}
					});
			}
		});
	}
</script>