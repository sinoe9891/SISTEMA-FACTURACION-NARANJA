<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';
require_once '../../includes/dashboard.php';
// Usar header común
require_once '../../includes/templates/header.php';
?>

<div class="container mt-4">
	<div class="d-flex justify-content-between align-items-center mb-3">
		<div>
			<h4> <?= $emoji ?> <?= $saludo ?>, <?= htmlspecialchars(USUARIO_NOMBRE) ?></h4>
			<h6 class="text-muted"> Sucursal: <?= htmlspecialchars($nombre_establecimiento) ?> | Rol: <?= htmlspecialchars(ucfirst($datos['rol'])) ?> | Cliente: <?= htmlspecialchars($datos['cliente_nombre']) ?></h6>
		</div>
		<div>
			<img src="<?= htmlspecialchars($datos['logo_url']) ?>" alt="Logo cliente" style="max-height: 60px;">
		</div>
	</div>
	<form method="GET" class="row g-3 align-items-end mb-4">
		<div class="col-auto">
			<label>Desde:</label>
			<input type="date" name="fecha_inicio" class="form-control" value="<?= htmlspecialchars($fecha_inicio) ?>">
		</div>
		<div class="col-auto">
			<label>Hasta:</label>
			<input type="date" name="fecha_fin" class="form-control" value="<?= htmlspecialchars($fecha_fin) ?>">
		</div>
		<div class="col-auto">
			<button class="btn btn-primary" type="submit">📊 Filtrar</button>
		</div>
	</form>

	<div class="row">
		<div class="col-md-4">
			<div class="card border-primary mb-3">
				<div class="card-header">Facturas emitidas</div>
				<div class="card-body text-primary">
					<h5 class="card-title"><?= $total_facturas ?></h5>
				</div>
			</div>
		</div>

		<div class="col-md-4">
			<div class="card border-success mb-3">
				<div class="card-header">Facturas restantes</div>
				<div class="card-body text-success">
					<h5 class="card-title"><?= $facturas_restantes ?></h5>
				</div>
			</div>
		</div>

		<div class="col-md-4">
			<div class="card border-warning mb-3">
				<div class="card-header">Fecha límite CAI</div>
				<div class="card-body text-warning">
					<h5 class="card-title">
						<h5 class="card-title"><?= formatFechaLimite($fecha_limite) ?></h5>
					</h5>
				</div>
			</div>
		</div>
	</div>
	<?php if ($ingresos): ?>
		<div class="row mb-4">
			<div class="col-md-4">
				<div class="card border-info h-100">
					<div class="card-header bg-info text-white">💰 Totales del mes actual (<?= date('F Y') ?>)</div>
					<div class="card-body">
						<p><strong>Subtotal:</strong> L <?= number_format($totales_mes['subtotal'], 2) ?></p>
						<p><strong>ISV:</strong> L <?= number_format($totales_mes['isv'], 2) ?></p>
						<p><strong>Total:</strong> L <?= number_format($totales_mes['total'], 2) ?></p>
					</div>
				</div>
			</div>
			<div class="col-md-4">
				<div class="card border-secondary h-100">
					<div class="card-header bg-secondary text-white">📅 Totales del año a la fecha (<?= date('Y') ?>)</div>
					<div class="card-body">
						<p><strong>Subtotal:</strong> L <?= number_format($totales_anio['subtotal'], 2) ?></p>
						<p><strong>ISV:</strong> L <?= number_format($totales_anio['isv'], 2) ?></p>
						<p><strong>Total:</strong> L <?= number_format($totales_anio['total'], 2) ?></p>
					</div>
				</div>
			</div>
			<div class="col-md-4">
				<div class="card border-<?= $color_alerta ?> h-100">
					<div class="card-header text-<?= $color_alerta ?>">🚨 Facturas no declaradas</div>
					<div class="card-body text-<?= $color_alerta ?>">
						<?php if ($cant_no_declaradas > 0): ?>
							<p><strong>Cantidad atrasadas:</strong> <?= $cant_no_declaradas ?> facturas</p>
							<p><strong>ISV pendiente:</strong> L <?= number_format($isv_pendiente, 2) ?></p>
							<?php if (count($lista_meses) > 1): ?>
								<p><strong>Meses pendientes:</strong> <?= $texto_meses ?></p>
							<?php elseif (count($lista_meses) === 1): ?>
								<p><strong>Mes pendiente:</strong> <?= $texto_meses ?></p>
							<?php endif; ?>
						<?php else: ?>
							<p><strong>No hay meses pendientes de declaración.</strong></p>
						<?php endif; ?>
						<?php
						switch ($color_alerta) {
							case 'success':
								$emoji_mes_actual = '🟢';
								break;
							case 'warning':
								$emoji_mes_actual = '🟡';
								break;
							case 'orange':
								$emoji_mes_actual = '🟠';
								break;
							case 'ocre':
								$emoji_mes_actual = '🟤';
								break;
							case 'danger':
								$emoji_mes_actual = '🔴';
								break;
							default:
								$emoji_mes_actual = '📌';
						}

						if ($cant_mes_actual > 0): ?>
							<hr>
							<p><strong><?= $emoji_mes_actual ?> Facturas del mes actual:</strong> <?= $cant_mes_actual ?> facturas</p>
							<p><strong>ISV estimado:</strong> L <?= number_format($isv_mes_actual, 2) ?></p>
							<p><small>💡 Recuerda declarar antes del 30 de este mes.</small></p>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>

		<canvas id="graficoIngresos" height="100"></canvas>

		<!-- <canvas id="graficoProductosFacturas" height="100" class="mt-5"></canvas>
		<canvas id="graficoTopProductos" height="100" class="mt-5"></canvas> -->
		<h4 class="mt-5">📋 Resumen por Cliente Facturado</h4>
		<table class="table table-bordered table-hover table-sm mt-2">
			<thead class="thead-light">
				<tr>
					<th>Nombre del Cliente</th>
					<th>Servicios Adquiridos</th>
					<th>Subtotal (L)</th>
					<th>ISV (L)</th>
					<th>Total Pagado (L)</th>
				</tr>
			</thead>
			<tbody>
				<?php
				$total_subtotal = 0;
				$total_isv = 0;
				$total_general = 0;
				foreach ($resumen_receptores as $r):
					$total_subtotal += $r['subtotal'];
					$total_isv += $r['isv'];
					$total_general += $r['total'];
				?>
					<tr>
						<td><?= htmlspecialchars($r['receptor_nombre'] ?? 'N/D') ?></td>
						<td><?= number_format((int)$r['cantidad_servicios']) ?></td>
						<td>L <?= number_format((float)$r['subtotal'], 2) ?></td>
						<td>L <?= number_format((float)$r['isv'], 2) ?></td>
						<td><strong>L <?= number_format((float)$r['total'], 2) ?></strong></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot class="table-light">
				<tr>
					<th colspan="2" class="text-end">Totales:</th>
					<th>L <?= number_format($total_subtotal, 2) ?></th>
					<th>L <?= number_format($total_isv, 2) ?></th>
					<th><strong>L <?= number_format($total_general, 2) ?></strong></th>
				</tr>
			</tfoot>
		</table>
		<?php
		// Año seleccionado desde GET, o actual por defecto
		$anio_promedio = $_GET['anio_promedio'] ?? date('Y');

		// Obtener años disponibles de facturación
		$stmtAnios = $pdo->prepare("
	SELECT DISTINCT YEAR(fecha_emision) AS anio
	FROM facturas
	WHERE cliente_id = ?
	  AND establecimiento_id = ?
	  AND estado = 'emitida'
	ORDER BY anio DESC
");
		$stmtAnios->execute([$cliente_id, $establecimiento_activo]);
		$lista_anios = $stmtAnios->fetchAll(PDO::FETCH_COLUMN);
		?>
		<div class="card border-0 shadow-sm p-4 mb-5">
			<form method="GET" class="row g-3 align-items-end mb-4">
				<div class="col-auto">
					<label for="anio_promedio">📅 Ingresos por Año:</label>
					<select name="anio_promedio" id="anio_promedio" class="form-control" onchange="this.form.submit()">
						<?php foreach ($lista_anios as $anio): ?>
							<option value="<?= $anio ?>" <?= $anio == $anio_promedio ? 'selected' : '' ?>><?= $anio ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</form>
			<!-- GRÁFICO POR AÑO -->
			<canvas id="graficoAnual" height="100" class="mt-5"></canvas>


			<h4 class="mt-4">📊 Promedio Mensual de Ingresos - Año <?= htmlspecialchars($anio_promedio) ?></h4>
			<table class="table table-bordered table-hover table-sm">
				<thead class="thead-light">
					<tr>
						<th>Mes</th>
						<th>Subtotal (L)</th>
						<th>ISV (L)</th>
						<th>Total (L)</th>
					</tr>
				</thead>
				<tbody>
					<?php
					$total_sub = 0;
					$total_isv = 0;
					$total_all = 0;
					$meses_contados = count($promedio_mensual);

					foreach ($promedio_mensual as $fila):
						$total_sub += $fila['subtotal'];
						$total_isv += $fila['isv'];
						$total_all += $fila['total'];
					?>
						<tr>
							<td><?= ucfirst($fila['mes_nombre']) ?></td>
							<td>L <?= number_format($fila['subtotal'], 2) ?></td>
							<td>L <?= number_format($fila['isv'], 2) ?></td>
							<td>L <?= number_format($fila['total'], 2) ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot class="table-light">
					<tr>
						<th>Promedio mensual:</th>
						<th>L <?= number_format($meses_contados ? $total_sub / $meses_contados : 0, 2) ?></th>
						<th>L <?= number_format($meses_contados ? $total_isv / $meses_contados : 0, 2) ?></th>
						<th><strong>L <?= number_format($meses_contados ? $total_all / $meses_contados : 0, 2) ?></strong></th>
					</tr>
				</tfoot>
			</table>
		</div>


		<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
		<script>
			const ctx = document.getElementById('graficoIngresos').getContext('2d');

			const chart = new Chart(ctx, {
				type: 'bar',
				data: {
					labels: <?= json_encode(array_column($ingresos, 'mes')) ?>,
					datasets: [{
							label: 'Subtotal',
							backgroundColor: 'rgba(54, 162, 235, 0.6)',
							data: <?= json_encode(array_map(fn($r) => (float)$r['subtotal'], $ingresos)) ?>
						},
						{
							label: 'ISV',
							backgroundColor: 'rgba(255, 206, 86, 0.6)',
							data: <?= json_encode(array_map(fn($r) => (float)$r['isv'], $ingresos)) ?>
						},
						{
							label: 'Total',
							backgroundColor: 'rgba(75, 192, 192, 0.6)',
							data: <?= json_encode(array_map(fn($r) => (float)$r['total'], $ingresos)) ?>
						}
					]
				},
				options: {
					responsive: true,
					scales: {
						y: {
							beginAtZero: true,
							title: {
								display: true,
								text: 'Lempiras'
							}
						}
					}
				}
			});
		</script>
		<script>
			const ctxAnual = document.getElementById('graficoAnual').getContext('2d');

			const chartAnual = new Chart(ctxAnual, {
				type: 'bar',
				data: {
					labels: <?= json_encode(array_map(fn($r) => ucfirst($r['mes_nombre']), $promedio_mensual)) ?>,
					datasets: [{
							label: 'Subtotal',
							backgroundColor: 'rgba(54, 162, 235, 0.4)',
							data: <?= json_encode(array_map(fn($r) => (float)$r['subtotal'], $promedio_mensual)) ?>
						},
						{
							label: 'ISV',
							backgroundColor: 'rgba(255, 206, 86, 0.4)',
							data: <?= json_encode(array_map(fn($r) => (float)$r['isv'], $promedio_mensual)) ?>
						},
						{
							label: 'Total',
							backgroundColor: 'rgba(75, 192, 192, 0.4)',
							data: <?= json_encode(array_map(fn($r) => (float)$r['total'], $promedio_mensual)) ?>
						}
					]
				},
				options: {
					responsive: true,
					plugins: {
						title: {
							display: true,
							text: '📊 Ingresos por Mes - Año <?= $anio_promedio ?>'
						}
					},
					scales: {
						y: {
							beginAtZero: true,
							title: {
								display: true,
								text: 'Lempiras'
							}
						}
					}
				}
			});
		</script>
		<script>
			const ctxProductosFacturas = document.getElementById('graficoProductosFacturas').getContext('2d');

			const chartProductosFacturas = new Chart(ctxProductosFacturas, {
				type: 'bar',
				data: {
					labels: <?= json_encode(array_column($top_productos_facturas, 'nombre')) ?>,
					datasets: [{
						label: 'Veces facturado (en facturas distintas)',
						data: <?= json_encode(array_map(fn($p) => (int)$p['veces_facturado'], $top_productos_facturas)) ?>,
						backgroundColor: 'rgba(153, 102, 255, 0.6)'
					}]
				},
				options: {
					indexAxis: 'y',
					responsive: true,
					scales: {
						x: {
							beginAtZero: true,
							title: {
								display: true,
								text: 'Cantidad de Facturas'
							}
						}
					}
				}
			});
		</script>
		<script>
			const ctxProductos = document.getElementById('graficoTopProductos').getContext('2d');

			const chartProductos = new Chart(ctxProductos, {
				type: 'bar',
				data: {
					labels: <?= json_encode(array_column($top_productos, 'nombre')) ?>,
					datasets: [{
						label: 'Cantidad Vendida',
						data: <?= json_encode(array_map(fn($p) => (int)$p['total_vendido'], $top_productos)) ?>,
						backgroundColor: 'rgba(255, 99, 132, 0.6)'
					}]
				},
				options: {
					indexAxis: 'y',
					responsive: true,
					scales: {
						x: {
							beginAtZero: true,
							title: {
								display: true,
								text: 'Unidades Vendidas'
							}
						}
					}
				}
			});
		</script>

	<?php else: ?>
		<div class="alert alert-info">No hay datos de ingresos en el rango seleccionado.</div>
	<?php endif; ?>

	<?php if ($facturas_restantes <= ALERTA_FACTURAS_RESTANTES && $total_facturas > 0): ?>
		<div class="alert alert-warning mt-4">
			⚠️ ¡Atención! Estás por agotar tu rango de facturación.
		</div>
	<?php endif; ?>

	<?php if ($alerta_cai_vencido): ?>
		<div class="alert alert-danger">
			⏰ Tu CAI está por vencer. Fecha límite: <?= formatFechaLimite($fecha_limite) ?>
		</div>
	<?php endif; ?>
</div>

</body>

</html>