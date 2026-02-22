<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';
require_once '../../includes/dashboard.php';
// Usar header común
require_once '../../includes/templates/header.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>
	/* Mejoras responsive en tablas grandes */
	.table-responsive {
		-webkit-overflow-scrolling: touch;
	}

	/* Ocultamos el caret del accordion para usar + / - */
	.accordion-button::after {
		display: none !important;
	}

	/* Botón compactito en tabla */
	.btn-icon {
		width: 34px;
		height: 34px;
		display: inline-flex;
		align-items: center;
		justify-content: center;
		padding: 0;
	}

	/* Mejoras de lectura en móviles */
	@media (max-width: 576px) {
		.card-header {
			font-size: 0.95rem;
		}

		.card-body p {
			margin-bottom: .4rem;
		}
	}
</style>

<div class="container mt-4">
	<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
		<div class="flex-grow-1">
			<h4 class="mb-1"> <?= $emoji ?> <?= $saludo ?>, <?= htmlspecialchars(USUARIO_NOMBRE) ?></h4>
			<h6 class="text-muted mb-0">
				Sucursal: <?= htmlspecialchars($nombre_establecimiento) ?> |
				Rol: <?= htmlspecialchars(ucfirst($datos['rol'])) ?> |
				Cliente: <?= htmlspecialchars($datos['cliente_nombre']) ?>
			</h6>
		</div>
		<div>
			<img src="<?= htmlspecialchars($datos['logo_url']) ?>" alt="Logo cliente" style="max-height: 60px;">
		</div>
	</div>

	<!-- FILTRO (POST) -->
	<form method="POST" class="row g-2 align-items-end mb-4">
		<div class="col-12 col-sm-6 col-md-auto">
			<label class="form-label mb-0">Desde:</label>
			<input type="date" name="fecha_inicio" class="form-control" value="<?= htmlspecialchars($fecha_inicio) ?>">
		</div>
		<div class="col-12 col-sm-6 col-md-auto">
			<label class="form-label mb-0">Hasta:</label>
			<input type="date" name="fecha_fin" class="form-control" value="<?= htmlspecialchars($fecha_fin) ?>">
		</div>
		<div class="col-12 col-md-auto">
			<button class="btn btn-primary w-100 w-md-auto" type="submit">
				<i class="bi bi-funnel-fill me-1"></i> Filtrar
			</button>
		</div>
	</form>

	<?php
	// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
	// DASHBOARD — Bloque de Contratos MEJORADO
	// Agrega este código en includes/dashboard.php (sección de queries, al final)
	// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

	// ── Contratos: alertas de vencimiento + próximos pagos ────────────────────────
	$stmtContratosAlerta = $pdo->prepare("
    SELECT c.*,
           cf.nombre   AS receptor_nombre,
           cf.telefono AS receptor_tel,
           p.nombre    AS servicio_nombre,
           DATEDIFF(c.fecha_fin, CURDATE()) AS dias_restantes,
           -- Próxima fecha de pago
           CASE
               WHEN DAY(CURDATE()) <= c.dia_pago
                   THEN DATE(CONCAT(YEAR(CURDATE()), '-', LPAD(MONTH(CURDATE()), 2, '0'), '-', LPAD(c.dia_pago, 2, '0')))
               ELSE
                   DATE(CONCAT(
                       YEAR(DATE_ADD(CURDATE(), INTERVAL 1 MONTH)), '-',
                       LPAD(MONTH(DATE_ADD(CURDATE(), INTERVAL 1 MONTH)), 2, '0'), '-',
                       LPAD(c.dia_pago, 2, '0')
                   ))
           END AS proxima_fecha_pago,
           CASE
               WHEN DAY(CURDATE()) <= c.dia_pago
                   THEN c.dia_pago - DAY(CURDATE())
               ELSE
                   DATEDIFF(
                       DATE(CONCAT(
                           YEAR(DATE_ADD(CURDATE(), INTERVAL 1 MONTH)), '-',
                           LPAD(MONTH(DATE_ADD(CURDATE(), INTERVAL 1 MONTH)), 2, '0'), '-',
                           LPAD(c.dia_pago, 2, '0')
                       )),
                       CURDATE()
                   )
           END AS dias_para_pago
    FROM contratos c
    INNER JOIN clientes_factura   cf ON cf.id = c.receptor_id
    INNER JOIN productos_clientes p  ON p.id  = c.producto_id
    WHERE c.cliente_id = ?
      AND c.estado     = 'activo'
    ORDER BY dias_para_pago ASC
");
	$stmtContratosAlerta->execute([$cliente_id]);
	$contratos_dashboard = $stmtContratosAlerta->fetchAll(PDO::FETCH_ASSOC);

	// Separar: por vencer (contrato termina en ≤3 días) vs próximos pagos
	$contratos_por_vencer  = array_filter(
		$contratos_dashboard,
		fn($c) =>
		$c['fecha_fin'] !== null && (int)$c['dias_restantes'] <= 3 && (int)$c['dias_restantes'] >= 0
	);
	$contratos_proximos_pagos = array_slice($contratos_dashboard, 0, 8); // top 8 más cercanos a pagar
	?>


	<?php
	// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
	// HTML: Pegar en dashboard.php (vista), antes de <?php if (!empty($alerta_cai_vencido)):
	// ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
	?>




	<?php if (!empty($alerta_cai_vencido)): ?>
		<div class="alert alert-danger">
			⏰ Tu CAI está por vencer. Fecha límite: <?= formatFechaLimite($fecha_limite) ?>
		</div>
	<?php endif; ?>

	<div class="row">
		<div class="col-md-4">
			<div class="card border-primary mb-3">
				<div class="card-header">Facturas emitidas</div>
				<div class="card-body text-primary">
					<h5 class="card-title mb-0"><?= (int)$total_facturas ?></h5>
				</div>
			</div>
		</div>

		<div class="col-md-4">
			<div class="card border-success mb-3">
				<div class="card-header">Facturas restantes</div>
				<div class="card-body text-success">
					<h5 class="card-title mb-0"><?= (int)$facturas_restantes ?></h5>
				</div>
			</div>
		</div>

		<div class="col-md-4">
			<div class="card border-warning mb-3">
				<div class="card-header">Fecha límite CAI</div>
				<div class="card-body text-warning">
					<h5 class="card-title mb-0"><?= formatFechaLimite($fecha_limite) ?></h5>
					<?php if ($dias_restantes_cai !== null): ?>
						<small class="text-muted">Faltan <?= (int)$dias_restantes_cai ?> día(s)</small>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>

	<?php if (!empty($ingresos)): ?>
		<div class="row mb-4">
			<div class="col-md-4">
				<div class="card border-info h-100">
					<div class="card-header bg-info text-white">💰 Totales del mes actual (<?= date('F Y') ?>)</div>
					<div class="card-body">
						<p><strong>Subtotal:</strong> L <?= number_format($totales_mes['subtotal'] ?? 0, 2) ?></p>
						<p><strong>ISV:</strong> L <?= number_format($totales_mes['isv'] ?? 0, 2) ?></p>
						<p class="mb-0"><strong>Total:</strong> L <?= number_format($totales_mes['total'] ?? 0, 2) ?></p>
					</div>
				</div>
			</div>

			<div class="col-md-4">
				<div class="card border-secondary h-100">
					<div class="card-header bg-secondary text-white">📅 Totales del año a la fecha (<?= date('Y') ?>)</div>
					<div class="card-body">
						<p><strong>Subtotal:</strong> L <?= number_format($totales_anio['subtotal'] ?? 0, 2) ?></p>
						<p><strong>ISV:</strong> L <?= number_format($totales_anio['isv'] ?? 0, 2) ?></p>
						<p class="mb-0"><strong>Total:</strong> L <?= number_format($totales_anio['total'] ?? 0, 2) ?></p>
					</div>
				</div>
			</div>

			<div class="col-md-4">
				<div class="card border-<?= $color_alerta ?> h-100">
					<div class="card-header text-<?= $color_alerta ?>">🚨 Facturas no declaradas</div>
					<div class="card-body text-<?= $color_alerta ?>">
						<?php if (($cant_no_declaradas ?? 0) > 0): ?>
							<p class="mb-1"><strong>Cantidad atrasadas:</strong> <?= (int)$cant_no_declaradas ?> facturas</p>
							<p class="mb-1"><strong>ISV pendiente:</strong> L <?= number_format($isv_pendiente ?? 0, 2) ?></p>
							<?php if (!empty($lista_meses) && count($lista_meses) > 0): ?>
								<p class="mb-0">
									<strong><?= count($lista_meses) > 1 ? 'Meses pendientes:' : 'Mes pendiente:' ?></strong>
									<?= $texto_meses ?? '' ?>
								</p>
							<?php endif; ?>
						<?php else: ?>
							<p class="mb-0"><strong>No hay meses pendientes de declaración.</strong></p>
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
						if (($cant_mes_actual ?? 0) > 0): ?>
							<hr class="my-2">
							<p class="mb-1"><strong><?= $emoji_mes_actual ?> Facturas del mes actual:</strong> <?= (int)$cant_mes_actual ?> facturas</p>
							<p class="mb-1"><strong>ISV estimado:</strong> L <?= number_format($isv_mes_actual ?? 0, 2) ?></p>
							<p class="mb-0"><small>💡 Recuerda declarar antes del 30 de este mes.</small></p>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
		<!-- ═══════════════════════════════════════════════════════════════════════════
     SECCIÓN CONTRATOS EN DASHBOARD
════════════════════════════════════════════════════════════════════════════ -->
		<?php if (!empty($contratos_dashboard)): ?>
			<div class="mb-4">

				<!-- ── 1. Alertas: contratos por vencer ────────────────────────────────── -->
				<?php if (!empty($contratos_por_vencer)): ?>
					<div class="mb-3">
						<h5 class="text-danger mb-3">
							<i class="fa-solid fa-triangle-exclamation me-2"></i>
							Contratos por Vencer
							<span class="badge bg-danger ms-1"><?= count($contratos_por_vencer) ?></span>
						</h5>
						<div class="row g-3">
							<?php foreach ($contratos_por_vencer as $cv):
								$dias = (int)$cv['dias_restantes'];
								$colorBorde = $dias <= 1 ? 'danger' : 'warning';
								$icono      = $dias <= 1 ? '🔴' : '🟡';
								$diasTexto  = $dias === 0 ? '¡Vence HOY!' : "Faltan {$dias} día(s)";
							?>
								<div class="col-md-6 col-lg-4">
									<div class="card border-<?= $colorBorde ?> h-100 shadow-sm">
										<div class="card-header bg-<?= $colorBorde ?> bg-opacity-10 d-flex justify-content-between align-items-center py-2">
											<span class="fw-bold text-<?= $colorBorde ?> small"><?= $icono ?> <?= $diasTexto ?></span>
											<span class="badge bg-<?= $colorBorde ?>"><?= htmlspecialchars($cv['fecha_fin']) ?></span>
										</div>
										<div class="card-body pb-2">
											<h6 class="card-title mb-1 fw-bold"><?= htmlspecialchars($cv['receptor_nombre']) ?></h6>
											<p class="card-text mb-1 text-muted small">
												<i class="fa-solid fa-box me-1"></i><?= htmlspecialchars($cv['servicio_nombre']) ?>
											</p>
											<p class="card-text mb-0">
												<strong>L <?= number_format((float)$cv['monto'], 2) ?></strong>
												<span class="text-muted small">/ mes</span>
											</p>
										</div>
										<div class="card-footer bg-transparent border-top-0 d-flex gap-2 pb-3">
											<a href="contratos" class="btn btn-sm btn-outline-secondary flex-fill">
												<i class="fa-solid fa-eye me-1"></i> Ver
											</a>
											<a href="generar_factura?receptor_id=<?= $cv['receptor_id'] ?>&producto_id=<?= $cv['producto_id'] ?>&monto=<?= $cv['monto'] ?>&contrato_id=<?= $cv['id'] ?>"
												class="btn btn-sm btn-success flex-fill">
												<i class="fa-solid fa-file-invoice-dollar me-1"></i> Facturar
											</a>
										</div>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>

				<!-- ── 2. Próximas fechas de pago ──────────────────────────────────────── -->
				<div class="card border-0 shadow-sm">
					<div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
						<h6 class="mb-0 fw-bold">
							<i class="fa-solid fa-calendar-check me-2 text-primary"></i>
							Próximas Fechas de Cobro
						</h6>
						<a href="contratos" class="btn btn-sm btn-outline-primary">
							Ver todos <i class="fa-solid fa-arrow-right ms-1"></i>
						</a>
					</div>
					<div class="card-body p-0">
						<div class="table-responsive">
							<table class="table table-hover align-middle mb-0">
								<thead class="table-light">
									<tr>
										<th>Cliente</th>
										<th class="d-none d-md-table-cell">Servicio</th>
										<th class="text-end">Monto</th>
										<th class="text-center">Próximo Cobro</th>
										<th class="text-center">Días</th>
										<th class="text-center d-none d-sm-table-cell">Acción</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($contratos_proximos_pagos as $p):
										$dias = (int)$p['dias_para_pago'];
										if ($dias === 0) {
											$badgeClass = 'bg-danger';
											$iconoPago = '🔴';
										} elseif ($dias <= 3) {
											$badgeClass = 'bg-danger';
											$iconoPago = '🔴';
										} elseif ($dias <= 7) {
											$badgeClass = 'bg-warning text-dark';
											$iconoPago = '🟡';
										} elseif ($dias <= 15) {
											$badgeClass = 'bg-info';
											$iconoPago = '🔵';
										} else {
											$badgeClass = 'bg-secondary';
											$iconoPago = '⚪';
										}
									?>
										<tr>
											<td>
												<div class="fw-semibold"><?= htmlspecialchars($p['receptor_nombre']) ?></div>
												<?php if ($p['receptor_tel']): ?>
													<small class="text-muted"><?= htmlspecialchars($p['receptor_tel']) ?></small>
												<?php endif; ?>
											</td>
											<td class="text-muted small d-none d-md-table-cell">
												<?= htmlspecialchars($p['servicio_nombre']) ?>
											</td>
											<td class="text-end fw-bold">L <?= number_format((float)$p['monto'], 2) ?></td>
											<td class="text-center">
												<div class="fw-semibold small"><?= htmlspecialchars($p['proxima_fecha_pago']) ?></div>
												<small class="text-muted">Día <?= (int)$p['dia_pago'] ?></small>
											</td>
											<td class="text-center">
												<span class="badge <?= $badgeClass ?>">
													<?= $iconoPago ?> <?= $dias === 0 ? '¡Hoy!' : "{$dias}d" ?>
												</span>
											</td>
											<td class="text-center d-none d-sm-table-cell">
												<a href="generar_factura?receptor_id=<?= $p['receptor_id'] ?>&producto_id=<?= $p['producto_id'] ?>&monto=<?= $p['monto'] ?>&contrato_id=<?= $p['id'] ?>"
													class="btn btn-sm btn-success" title="Crear Factura">
													<i class="fa-solid fa-file-invoice-dollar"></i>
												</a>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</div>
				</div>

			</div>
		<?php endif; ?>
		<!-- ═══════════════════════════════════════════════ FIN SECCIÓN CONTRATOS === -->
		<?php if (!empty($cais_activos)): ?>
			<div class="card mt-3">
				<div class="card-header">CAI activos</div>
				<div class="card-body table-responsive">
					<table class="table table-sm table-bordered mb-0">
						<thead>
							<tr>
								<th>CAI</th>
								<th>Rango</th>
								<th>Restantes</th>
								<th>Fecha límite</th>
								<th>Días</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($cais_activos as $cai): ?>
								<tr>
									<td><?= htmlspecialchars($cai['cai']) ?></td>
									<td><?= (int)$cai['rango_inicio'] ?> - <?= (int)$cai['rango_fin'] ?></td>
									<td><strong><?= (int)$cai['restantes'] ?></strong></td>
									<td><?= formatFechaLimite($cai['fecha_limite']) ?></td>
									<td><?= (int)$cai['dias_para_vencer'] ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		<?php endif; ?>

		<!-- GRÁFICO INGRESOS POR MES -->
		<div class="card border-0 shadow-sm p-3 mb-4">
			<h5 class="mb-3">📊 Ingresos por Mes (rango seleccionado)</h5>
			<canvas id="graficoIngresos" height="110"></canvas>
		</div>

		<!-- RESUMEN POR CLIENTE FACTURADO + DETALLES -->
		<div class="card border-0 shadow-sm p-4 mb-5">
			<div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
				<h4 class="mb-0">📋 Resumen por Cliente Facturado</h4>
				<small class="text-muted">
					Rango: <?= htmlspecialchars($fecha_inicio) ?> → <?= htmlspecialchars($fecha_fin) ?>
				</small>
			</div>

			<div class="table-responsive mt-3">
				<table class="table table-bordered table-hover table-sm align-middle mb-0">
					<thead class="thead-light">
						<tr>
							<th>Nombre del Cliente</th>
							<th class="text-center">Servicios</th>
							<th class="text-end">Subtotal (L)</th>
							<th class="text-end">ISV (L)</th>
							<th class="text-end">Total Pagado (L)</th>
							<th class="text-center" style="width: 70px;">Detalles</th>
						</tr>
					</thead>

					<tbody id="accordionReceptores">
						<?php
						$total_subtotal = 0;
						$total_isv = 0;
						$total_general = 0;

						foreach ($resumen_receptores as $r):
							$rid = (int)$r['receptor_id'];
							$total_subtotal += (float)$r['subtotal'];
							$total_isv += (float)$r['isv'];
							$total_general += (float)$r['total'];
						?>
							<tr>
								<td><?= htmlspecialchars($r['receptor_nombre'] ?? 'N/D') ?></td>
								<td class="text-center"><?= (int)($r['cantidad_servicios'] ?? 0) ?></td>
								<td class="text-end">L <?= number_format((float)$r['subtotal'], 2) ?></td>
								<td class="text-end">L <?= number_format((float)$r['isv'], 2) ?></td>
								<td class="text-end"><strong>L <?= number_format((float)$r['total'], 2) ?></strong></td>
								<td class="text-center">
									<button
										class="btn btn-outline-primary btn-sm btn-icon toggle-receptor"
										type="button"
										data-bs-toggle="collapse"
										data-bs-target="#detalles-receptor-<?= $rid ?>"
										aria-expanded="false"
										aria-controls="detalles-receptor-<?= $rid ?>"
										title="Ver detalles">
										<i class="bi bi-plus-lg"></i>
									</button>
								</td>
							</tr>

							<!-- ROW DETALLE (COLSPAN) -->
							<tr class="bg-light">
								<td colspan="6" class="p-0">
									<div
										id="detalles-receptor-<?= $rid ?>"
										class="collapse detalle-receptor"
										data-bs-parent="#accordionReceptores">
										<div class="p-3">

											<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
												<div>
													<strong><?= htmlspecialchars($r['receptor_nombre'] ?? 'Cliente') ?></strong>
													<span class="text-muted ms-2">
														(<?= (int)($r['cantidad_facturas'] ?? 0) ?> factura(s))
													</span>
												</div>
											</div>

											<?php
											$listaFacturas = $detalle_receptores[$rid] ?? [];
											?>

											<?php if (!empty($listaFacturas)): ?>
												<div class="accordion accordion-flush" id="acc-facturas-<?= $rid ?>">
													<?php foreach ($listaFacturas as $fx): ?>
														<?php
														$fid = (int)$fx['id'];
														$isvFactura = (float)($fx['isv_15'] ?? 0) + (float)($fx['isv_18'] ?? 0);
														$items = $fx['items'] ?? [];
														?>
														<div class="accordion-item">
															<h2 class="accordion-header" id="h-<?= $rid ?>-<?= $fid ?>">
																<button
																	class="accordion-button collapsed py-2 d-flex align-items-center gap-2 toggle-factura"
																	type="button"
																	data-bs-toggle="collapse"
																	data-bs-target="#c-<?= $rid ?>-<?= $fid ?>"
																	aria-expanded="false"
																	aria-controls="c-<?= $rid ?>-<?= $fid ?>">
																	<i class="bi bi-plus-lg icon-plusminus"></i>
																	<div class="w-100 d-flex flex-wrap justify-content-between align-items-center gap-2">
																		<div>
																			<span class="fw-semibold">Factura <?= htmlspecialchars($fx['correlativo'] ?? $fid) ?></span>
																			<span class="text-muted ms-2"><?= htmlspecialchars(substr($fx['fecha_emision'] ?? '', 0, 10)) ?></span>
																		</div>
																		<div class="ms-auto fw-bold">
																			L <?= number_format((float)$fx['total'], 2) ?>
																		</div>
																	</div>
																</button>
															</h2>

															<div
																id="c-<?= $rid ?>-<?= $fid ?>"
																class="accordion-collapse collapse"
																data-bs-parent="#acc-facturas-<?= $rid ?>">
																<div class="accordion-body pt-2">

																	<div class="row g-2 mb-3">
																		<div class="col-12 col-md-8">
																			<div class="small text-muted">
																				Subtotal: <strong>L <?= number_format((float)$fx['subtotal'], 2) ?></strong> ·
																				ISV: <strong>L <?= number_format($isvFactura, 2) ?></strong> ·
																				Total: <strong>L <?= number_format((float)$fx['total'], 2) ?></strong>
																			</div>
																		</div>
																		<div class="col-12 col-md-4 text-md-end">
																			<a class="btn btn-sm btn-outline-secondary"
																				href="ver_factura?id=<?= $fid ?>"
																				target="_blank"
																				rel="noopener noreferrer">
																				<i class="bi bi-receipt me-1"></i> Ver factura
																			</a>
																		</div>
																	</div>

																	<?php if (!empty($items)): ?>
																		<div class="table-responsive">
																			<table class="table table-sm table-bordered mb-0 align-middle">
																				<thead>
																					<tr>
																						<th>Servicio / Producto</th>
																						<th class="text-end">Cantidad</th>
																						<th class="text-end">P. Unitario</th>
																						<th class="text-end">Subtotal</th>
																						<th class="text-end">ISV %</th>
																					</tr>
																				</thead>
																				<tbody>
																					<?php foreach ($items as $it): ?>
																						<tr>
																							<td>
																								<div class="fw-semibold">
																									<?= htmlspecialchars($it['nombre_producto'] ?? 'SIN PRODUCTO') ?>
																								</div>
																								<?php if (!empty($it['descripcion_html'])): ?>
																									<div class="text-muted small">
																										<?= nl2br(htmlspecialchars($it['descripcion_html'])) ?>
																									</div>
																								<?php endif; ?>
																							</td>
																							<td class="text-end"><?= (int)($it['cantidad'] ?? 0) ?></td>
																							<td class="text-end">L <?= number_format((float)($it['precio_unitario'] ?? 0), 2) ?></td>
																							<td class="text-end">L <?= number_format((float)($it['subtotal'] ?? 0), 2) ?></td>
																							<td class="text-end"><?= number_format((float)($it['isv_aplicado'] ?? 0), 2) ?></td>
																						</tr>
																					<?php endforeach; ?>
																				</tbody>
																			</table>
																		</div>
																	<?php else: ?>
																		<div class="alert alert-warning mb-0">Esta factura no tiene items asociados.</div>
																	<?php endif; ?>

																</div>
															</div>
														</div>
													<?php endforeach; ?>
												</div>
											<?php else: ?>
												<div class="alert alert-info mb-0">No hay facturas para este cliente en el rango.</div>
											<?php endif; ?>

										</div>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>

					<tfoot class="table-light">
						<tr>
							<th colspan="2" class="text-end">Totales:</th>
							<th class="text-end">L <?= number_format($total_subtotal, 2) ?></th>
							<th class="text-end">L <?= number_format($total_isv, 2) ?></th>
							<th class="text-end"><strong>L <?= number_format($total_general, 2) ?></strong></th>
							<th></th>
						</tr>
					</tfoot>
				</table>
			</div>
		</div>

		<!-- INGRESOS POR AÑO (se mantiene, SIN SELECT) -->
		<div class="card border-0 shadow-sm p-4 mb-5" id="datosporano">
			<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
				<h4 class="mb-0"><i class="bi bi-calendar-event-fill me-1"></i> Ingresos por Año</h4>
				<small class="text-muted">
					(Usa el mismo filtro Desde/Hasta)
				</small>
			</div>

			<div style="width: 100%; margin: auto;">
				<canvas id="graficoAnual" height="110"></canvas>
			</div>

			<?php if (!empty($ingresos_anuales)): ?>
				<div class="table-responsive mt-3">
					<table class="table table-bordered table-hover table-sm mb-0">
						<thead class="thead-light">
							<tr>
								<th>Año</th>
								<th class="text-end">Subtotal (L)</th>
								<th class="text-end">ISV (L)</th>
								<th class="text-end">Total (L)</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($ingresos_anuales as $ax): ?>
								<tr>
									<td><?= htmlspecialchars($ax['anio']) ?></td>
									<td class="text-end">L <?= number_format((float)$ax['subtotal'], 2) ?></td>
									<td class="text-end">L <?= number_format((float)$ax['isv'], 2) ?></td>
									<td class="text-end"><strong>L <?= number_format((float)$ax['total'], 2) ?></strong></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>

		<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

		<script>
			// ====== CHART: INGRESOS POR MES ======
			const ctx = document.getElementById('graficoIngresos')?.getContext('2d');
			if (ctx) {
				new Chart(ctx, {
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
			}

			// ====== CHART: INGRESOS POR AÑO ======
			const ctxAnual = document.getElementById('graficoAnual')?.getContext('2d');
			if (ctxAnual) {
				const anios = <?= json_encode(array_map(fn($x) => (string)$x['anio'], $ingresos_anuales ?? [])) ?>;
				const subtotalA = <?= json_encode(array_map(fn($x) => (float)$x['subtotal'], $ingresos_anuales ?? [])) ?>;
				const isvA = <?= json_encode(array_map(fn($x) => (float)$x['isv'], $ingresos_anuales ?? [])) ?>;
				const totalA = <?= json_encode(array_map(fn($x) => (float)$x['total'], $ingresos_anuales ?? [])) ?>;

				new Chart(ctxAnual, {
					type: 'bar',
					data: {
						labels: anios,
						datasets: [{
								label: 'Subtotal',
								backgroundColor: 'rgba(54, 162, 235, 0.4)',
								data: subtotalA
							},
							{
								label: 'ISV',
								backgroundColor: 'rgba(255, 206, 86, 0.4)',
								data: isvA
							},
							{
								label: 'Total',
								backgroundColor: 'rgba(75, 192, 192, 0.4)',
								data: totalA
							}
						]
					},
					options: {
						responsive: true,
						plugins: {
							title: {
								display: true,
								text: '📊 Ingresos por Año (rango seleccionado)'
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
			}


			(() => {
				// --- RECEPTOR: botón Detalles (+ / -) ---
				document.querySelectorAll('.toggle-receptor').forEach((btn) => {
					const targetSel = btn.getAttribute('data-bs-target');
					const target = document.querySelector(targetSel);
					if (!target) return;

					const icon = btn.querySelector('i');
					const setIcon = (open) => {
						if (!icon) return;
						icon.classList.toggle('bi-plus-lg', !open);
						icon.classList.toggle('bi-dash-lg', open);
					};

					// click manual (para que el "-" SI cierre)
					btn.addEventListener('click', (e) => {
						e.preventDefault();
						e.stopPropagation();

						const inst = bootstrap.Collapse.getOrCreateInstance(target, {
							toggle: false
						});
						const isOpen = target.classList.contains('show');

						if (isOpen) {
							inst.hide();
						} else {
							// cerrar otros receptores abiertos
							document.querySelectorAll('.detalle-receptor.show').forEach((openEl) => {
								if (openEl !== target) {
									bootstrap.Collapse.getOrCreateInstance(openEl, {
										toggle: false
									}).hide();
								}
							});
							inst.show();
						}
					});

					target.addEventListener('shown.bs.collapse', () => setIcon(true));

					target.addEventListener('hidden.bs.collapse', () => {
						// cerrar cualquier factura abierta dentro de este receptor
						target.querySelectorAll('.accordion-collapse.show').forEach((c) => {
							bootstrap.Collapse.getOrCreateInstance(c, {
								toggle: false
							}).hide();
						});

						// reset iconos de facturas dentro
						target.querySelectorAll('.toggle-factura .icon-plusminus').forEach((ic) => {
							ic.classList.remove('bi-dash-lg');
							ic.classList.add('bi-plus-lg');
						});

						setIcon(false);
					});
				});

				// --- FACTURAS: acordeón dentro del receptor (+ / -) ---
				document.querySelectorAll('.toggle-factura').forEach((btn) => {
					const targetSel = btn.getAttribute('data-bs-target');
					const target = document.querySelector(targetSel);
					if (!target) return;

					const icon = btn.querySelector('.icon-plusminus');
					const setIcon = (open) => {
						if (!icon) return;
						icon.classList.toggle('bi-plus-lg', !open);
						icon.classList.toggle('bi-dash-lg', open);
					};

					btn.addEventListener('click', (e) => {
						e.preventDefault();
						e.stopPropagation();

						const inst = bootstrap.Collapse.getOrCreateInstance(target, {
							toggle: false
						});
						const isOpen = target.classList.contains('show');

						if (isOpen) inst.hide();
						else inst.show();
					});

					target.addEventListener('shown.bs.collapse', () => setIcon(true));
					target.addEventListener('hidden.bs.collapse', () => setIcon(false));
				});
			})();
		</script>


	<?php else: ?>
		<div class="alert alert-info">No hay datos de ingresos en el rango seleccionado.</div>
	<?php endif; ?>

	<?php if (($facturas_restantes ?? 999999) <= (defined('ALERTA_FACTURAS_RESTANTES') ? ALERTA_FACTURAS_RESTANTES : 0) && ($total_facturas ?? 0) > 0): ?>
		<div class="alert alert-warning mt-4">
			⚠️ ¡Atención! Estás por agotar tu rango de facturación.
		</div>
	<?php endif; ?>
</div>

</body>

</html>