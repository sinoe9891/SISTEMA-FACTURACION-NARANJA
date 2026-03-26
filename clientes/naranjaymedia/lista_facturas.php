<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';

define('ALERTA_FACTURAS_RESTANTES', 20);
define('ALERTA_CAI_DIAS', 30);

require_once '../../includes/templates/header.php';

$stmtEstab = $pdo->prepare("SELECT nombre FROM establecimientos WHERE establecimiento_id = ?");
$stmtEstab->execute([$establecimiento_activo]);
$establecimiento    = $stmtEstab->fetch(PDO::FETCH_ASSOC);
$nombre_establecimiento = $establecimiento ? $establecimiento['nombre'] : 'No asignado';

$stmtCAIs = $pdo->prepare("
    SELECT *, CASE WHEN CURDATE() <= fecha_limite THEN 1 ELSE 0 END AS activo
    FROM cai_rangos
    WHERE cliente_id = ? AND establecimiento_id = ?
    ORDER BY fecha_recepcion DESC
");
$stmtCAIs->execute([$cliente_id, $establecimiento_activo]);
$cais = $stmtCAIs->fetchAll();

$caix = null;
if (isset($_GET['cai_id']) && ctype_digit($_GET['cai_id'])) {
	$caix = intval($_GET['cai_id']);
	$caix_valid = false;
	foreach ($cais as $cai) {
		if ($cai['id'] == $caix) {
			$caix_valid = true;
			break;
		}
	}
	if (!$caix_valid) $caix = null;
}
if (count($cais) === 1) $caix = $cais[0]['id'];
if ($caix === null && !isset($_GET['cai_id'])) {
	foreach ($cais as $cai) {
		if ($cai['activo'] == 1) {
			$caix = $cai['id'];
			break;
		}
	}
}

$ultimoCorrelativoCAI = null;
$fecha_desde = !empty($_GET['fecha_desde']) ? $_GET['fecha_desde'] : null;
$fecha_hasta = !empty($_GET['fecha_hasta']) ? $_GET['fecha_hasta'] : null;
if ($fecha_desde && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_desde)) $fecha_desde = null;
if ($fecha_hasta && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha_hasta)) $fecha_hasta = null;

$params     = [$cliente_id, $establecimiento_activo];
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
    SELECT f.id, f.correlativo, f.fecha_emision, f.total,
           f.estado, f.pagada, f.enviada_receptor, cf.nombre AS receptor
    FROM facturas f
    INNER JOIN clientes_factura cf ON f.receptor_id = cf.id
    WHERE f.cliente_id = ? AND f.establecimiento_id = ?
    $whereExtra
    ORDER BY f.fecha_emision DESC
");
$stmtFacturas->execute($params);
$facturas = $stmtFacturas->fetchAll();

if ($caix) {
	$stmtUltimo = $pdo->prepare("SELECT ultimo_correlativo FROM cai_rangos WHERE id = ?");
	$stmtUltimo->execute([$caix]);
	$ultimoCorrelativoCAI = $stmtUltimo->fetchColumn();
}

$total_facturas   = count($facturas);
$emitidas_count   = count(array_filter($facturas, fn($f) => $f['estado'] === 'emitida'));
$anuladas_count   = count(array_filter($facturas, fn($f) => $f['estado'] === 'anulada'));
$pagadas_count    = count(array_filter($facturas, fn($f) => $f['pagada'] == 1));
$total_monto      = array_sum(array_map(fn($f) => (float)$f['total'], array_filter($facturas, fn($f) => $f['estado'] === 'emitida')));
$esAdmin          = in_array($datos['rol'], ['admin', 'superadmin']);
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>
	:root {
		--brand: #4f46e5;
		--brand-light: #eef2ff;
		--brand-dark: #3730a3;
		--success: #10b981;
		--success-bg: #ecfdf5;
		--danger: #ef4444;
		--danger-bg: #fef2f2;
		--warning: #f59e0b;
		--warning-bg: #fffbeb;
		--info: #0ea5e9;
		--info-bg: #f0f9ff;
		--surface: #ffffff;
		--surface-2: #f8fafc;
		--border: #e2e8f0;
		--text-main: #1e293b;
		--text-muted: #64748b;
		--shadow-sm: 0 1px 3px rgba(0, 0, 0, .06);
		--shadow-md: 0 4px 16px rgba(0, 0, 0, .08);
		--radius: 14px;
		--radius-sm: 8px;
		--tr: .2s cubic-bezier(.4, 0, .2, 1);
	}

	.fh-page {
		padding: 1.5rem 0 3rem;
	}

	/* Header */
	.fh-header {
		background: linear-gradient(135deg, #1e40af 0%, #1e3a8a 100%);
		border-radius: var(--radius);
		padding: 1.5rem 2rem;
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

	.fh-header::before {
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

	.fh-header::after {
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

	.fh-header-title {
		font-size: 1.35rem;
		font-weight: 700;
		margin: 0;
	}

	.fh-header-sub {
		font-size: .82rem;
		opacity: .8;
		margin: .25rem 0 0;
	}

	.fh-header-logo {
		max-height: 52px;
		border-radius: 8px;
		background: rgba(255, 255, 255, .15);
		padding: 4px;
	}

	/* Stats */
	.fh-stats {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
		gap: 1rem;
		margin-bottom: 1.5rem;
	}

	.fh-stat {
		background: var(--surface);
		border: 1px solid var(--border);
		border-radius: var(--radius);
		padding: 1rem 1.1rem;
		display: flex;
		align-items: center;
		gap: .8rem;
		box-shadow: var(--shadow-sm);
		transition: box-shadow var(--tr), transform var(--tr);
	}

	.fh-stat:hover {
		box-shadow: var(--shadow-md);
		transform: translateY(-2px);
	}

	.fh-stat-icon {
		width: 40px;
		height: 40px;
		border-radius: 10px;
		display: flex;
		align-items: center;
		justify-content: center;
		font-size: 1.15rem;
		flex-shrink: 0;
	}

	.fh-stat-icon.blue {
		background: #dbeafe;
		color: #1d4ed8;
	}

	.fh-stat-icon.green {
		background: var(--success-bg);
		color: var(--success);
	}

	.fh-stat-icon.red {
		background: var(--danger-bg);
		color: var(--danger);
	}

	.fh-stat-icon.amber {
		background: var(--warning-bg);
		color: var(--warning);
	}

	.fh-stat-icon.teal {
		background: #ccfbf1;
		color: #0f766e;
	}

	.fh-stat-val {
		font-size: 1.4rem;
		font-weight: 700;
		color: var(--text-main);
		line-height: 1;
	}

	.fh-stat-lbl {
		font-size: .72rem;
		color: var(--text-muted);
		margin-top: 2px;
	}

	/* Filter card */
	.fh-filter-card {
		background: var(--surface);
		border: 1px solid var(--border);
		border-radius: var(--radius);
		box-shadow: var(--shadow-sm);
		padding: 1.25rem 1.5rem;
		margin-bottom: 1.5rem;
	}

	.fh-filter-title {
		font-size: .82rem;
		font-weight: 700;
		color: var(--text-muted);
		text-transform: uppercase;
		letter-spacing: .05em;
		margin-bottom: .9rem;
		display: flex;
		align-items: center;
		gap: .4rem;
	}

	.fh-filter-grid {
		display: grid;
		grid-template-columns: 2fr 1fr 1fr auto;
		gap: .75rem;
		align-items: end;
	}

	.fh-label {
		font-size: .78rem;
		font-weight: 600;
		color: var(--text-muted);
		margin-bottom: .3rem;
		display: block;
	}

	.fh-select,
	.fh-date {
		width: 100%;
		padding: .55rem .8rem;
		border: 1.5px solid var(--border);
		border-radius: var(--radius-sm);
		font-size: .875rem;
		background: var(--surface);
		color: var(--text-main);
		outline: none;
		transition: border-color var(--tr), box-shadow var(--tr);
	}

	.fh-select:focus,
	.fh-date:focus {
		border-color: #1d4ed8;
		box-shadow: 0 0 0 3px rgba(29, 78, 216, .1);
	}

	.fh-filter-btns {
		display: flex;
		gap: .5rem;
	}

	.btn-filter {
		display: inline-flex;
		align-items: center;
		gap: .4rem;
		padding: .55rem 1rem;
		background: #1e40af;
		color: #fff;
		border: none;
		border-radius: var(--radius-sm);
		font-size: .85rem;
		font-weight: 600;
		cursor: pointer;
		white-space: nowrap;
		transition: background var(--tr), transform var(--tr);
	}

	.btn-filter:hover {
		background: #1e3a8a;
		transform: translateY(-1px);
	}

	.btn-clear-filter {
		display: inline-flex;
		align-items: center;
		gap: .4rem;
		padding: .55rem .85rem;
		background: var(--surface);
		color: var(--text-muted);
		border: 1.5px solid var(--border);
		border-radius: var(--radius-sm);
		font-size: .85rem;
		font-weight: 600;
		text-decoration: none;
		cursor: pointer;
		transition: all var(--tr);
	}

	.btn-clear-filter:hover {
		border-color: var(--text-muted);
		color: var(--text-main);
	}

	/* Toolbar */
	.fh-toolbar {
		display: flex;
		align-items: center;
		gap: .75rem;
		flex-wrap: wrap;
		margin-bottom: 1.25rem;
	}

	.fh-search-wrap {
		position: relative;
		flex: 1 1 200px;
		min-width: 180px;
	}

	.fh-search-wrap>i {
		position: absolute;
		left: .8rem;
		top: 50%;
		transform: translateY(-50%);
		color: var(--text-muted);
		font-size: .9rem;
		pointer-events: none;
	}

	.fh-search {
		width: 100%;
		padding: .52rem .8rem .52rem 2.2rem;
		border: 1.5px solid var(--border);
		border-radius: var(--radius-sm);
		font-size: .875rem;
		background: var(--surface);
		color: var(--text-main);
		outline: none;
		transition: border-color var(--tr), box-shadow var(--tr);
	}

	.fh-search:focus {
		border-color: #1d4ed8;
		box-shadow: 0 0 0 3px rgba(29, 78, 216, .1);
	}

	.fh-search::placeholder {
		color: #94a3b8;
	}

	.fh-clear-btn {
		position: absolute;
		right: .6rem;
		top: 50%;
		transform: translateY(-50%);
		background: none;
		border: none;
		color: var(--text-muted);
		font-size: .95rem;
		cursor: pointer;
		padding: 0;
		display: none;
	}

	.fh-clear-btn.visible {
		display: block;
	}

	.fh-per-page {
		padding: .48rem .65rem;
		border: 1.5px solid var(--border);
		border-radius: var(--radius-sm);
		font-size: .82rem;
		background: var(--surface);
		color: var(--text-main);
		cursor: pointer;
		outline: none;
	}

	.fh-per-page:focus {
		border-color: #1d4ed8;
	}

	/* Table card */
	.fh-card {
		background: var(--surface);
		border: 1px solid var(--border);
		border-radius: var(--radius);
		box-shadow: var(--shadow-sm);
		overflow: hidden;
	}

	.fh-card-header {
		padding: 1rem 1.5rem;
		border-bottom: 1px solid var(--border);
		display: flex;
		align-items: center;
		justify-content: space-between;
		background: var(--surface-2);
		gap: 1rem;
		flex-wrap: wrap;
	}

	.fh-card-title {
		font-weight: 700;
		font-size: .95rem;
		color: var(--text-main);
		display: flex;
		align-items: center;
		gap: .5rem;
	}

	.fh-result-badge {
		display: inline-flex;
		align-items: center;
		background: #dbeafe;
		color: #1d4ed8;
		border-radius: 20px;
		padding: .15rem .65rem;
		font-size: .78rem;
		font-weight: 600;
	}

	.fh-table-wrap {
		overflow-x: auto;
	}

	.fh-table {
		width: 100%;
		border-collapse: collapse;
		font-size: .855rem;
	}

	.fh-table thead th {
		padding: .7rem 1rem;
		background: var(--surface-2);
		color: var(--text-muted);
		font-weight: 600;
		font-size: .72rem;
		text-transform: uppercase;
		letter-spacing: .05em;
		border-bottom: 1px solid var(--border);
		white-space: nowrap;
		cursor: pointer;
		user-select: none;
		transition: background var(--tr), color var(--tr);
	}

	.fh-table thead th:last-child,
	.fh-table thead th:nth-last-child(2) {
		cursor: default;
	}

	.fh-table thead th:hover:not(:last-child):not(:nth-last-child(2)) {
		background: #e9edf5;
		color: #1d4ed8;
	}

	.fh-table thead th.sort-asc,
	.fh-table thead th.sort-desc {
		color: #1d4ed8;
		background: #dbeafe;
	}

	.sort-icon {
		margin-left: .35rem;
		font-size: .68rem;
		opacity: .3;
		display: inline-block;
		transition: opacity .15s, transform .15s;
	}

	.fh-table thead th:hover .sort-icon {
		opacity: .6;
	}

	.fh-table thead th.sort-asc .sort-icon,
	.fh-table thead th.sort-desc .sort-icon {
		opacity: 1;
	}

	.fh-table thead th.sort-desc .sort-icon {
		transform: rotate(180deg);
	}

	.fh-table tbody tr {
		border-bottom: 1px solid var(--border);
		transition: background var(--tr);
	}

	.fh-table tbody tr:last-child {
		border-bottom: none;
	}

	.fh-table tbody tr:hover {
		background: #eff6ff;
	}

	.fh-table tbody td {
		padding: .8rem 1rem;
		color: var(--text-main);
		vertical-align: middle;
	}

	/* Badges */
	.st-badge {
		display: inline-flex;
		align-items: center;
		gap: .3rem;
		padding: .2rem .6rem;
		border-radius: 20px;
		font-size: .75rem;
		font-weight: 600;
	}

	.st-emitida {
		background: var(--success-bg);
		color: var(--success);
	}

	.st-anulada {
		background: var(--danger-bg);
		color: var(--danger);
	}

	.st-borrador {
		background: var(--warning-bg);
		color: var(--warning);
	}

	.yn-yes {
		background: var(--success-bg);
		color: var(--success);
		border: 1px solid #a7f3d0;
	}

	.yn-no {
		background: var(--danger-bg);
		color: var(--danger);
		border: 1px solid #fecaca;
	}

	.yn-badge {
		display: inline-flex;
		align-items: center;
		gap: .25rem;
		padding: .18rem .55rem;
		border-radius: 20px;
		font-size: .73rem;
		font-weight: 600;
	}

	/* Action buttons */
	.fh-actions {
		display: flex;
		gap: .35rem;
		align-items: center;
		flex-wrap: wrap;
	}

	.btn-fa {
		display: inline-flex;
		align-items: center;
		gap: .3rem;
		padding: .32rem .65rem;
		border-radius: var(--radius-sm);
		font-size: .77rem;
		font-weight: 600;
		cursor: pointer;
		border: 1.5px solid transparent;
		transition: all var(--tr);
		text-decoration: none;
		white-space: nowrap;
	}

	.btn-fa-view {
		background: #eff6ff;
		color: #1d4ed8;
		border-color: rgba(29, 78, 216, .2);
	}

	.btn-fa-view:hover {
		background: #1d4ed8;
		color: #fff;
	}

	.btn-fa-edit {
		background: var(--info-bg);
		color: var(--info);
		border-color: rgba(14, 165, 233, .2);
	}

	.btn-fa-edit:hover {
		background: var(--info);
		color: #fff;
	}

	.btn-fa-warn {
		background: var(--warning-bg);
		color: #b45309;
		border-color: rgba(245, 158, 11, .2);
	}

	.btn-fa-warn:hover {
		background: var(--warning);
		color: #fff;
	}

	.btn-fa-danger {
		background: var(--danger-bg);
		color: var(--danger);
		border-color: rgba(239, 68, 68, .2);
	}

	.btn-fa-danger:hover {
		background: var(--danger);
		color: #fff;
	}

	.btn-fa-success {
		background: var(--success-bg);
		color: var(--success);
		border-color: rgba(16, 185, 129, .2);
	}

	.btn-fa-success:hover {
		background: var(--success);
		color: #fff;
	}

	.btn-fa-sec {
		background: var(--surface-2);
		color: var(--text-muted);
		border-color: var(--border);
	}

	.btn-fa-sec[disabled] {
		opacity: .5;
		cursor: not-allowed;
	}

	/* Highlight */
	.fh-highlight {
		background: #fef08a;
		border-radius: 3px;
		padding: 0 2px;
	}

	/* Pagination */
	.fh-pagination {
		display: flex;
		align-items: center;
		justify-content: space-between;
		padding: .85rem 1.25rem;
		border-top: 1px solid var(--border);
		background: var(--surface-2);
		gap: .75rem;
		flex-wrap: wrap;
	}

	.fh-page-info {
		font-size: .78rem;
		color: var(--text-muted);
	}

	.fh-page-btns {
		display: flex;
		gap: .3rem;
		flex-wrap: wrap;
	}

	.page-btn {
		min-width: 32px;
		height: 32px;
		display: flex;
		align-items: center;
		justify-content: center;
		border-radius: var(--radius-sm);
		border: 1.5px solid var(--border);
		background: var(--surface);
		color: var(--text-muted);
		font-size: .8rem;
		font-weight: 600;
		cursor: pointer;
		transition: all var(--tr);
		padding: 0 .45rem;
		user-select: none;
	}

	.page-btn:hover:not(.disabled):not(.active) {
		border-color: #1d4ed8;
		color: #1d4ed8;
		background: #eff6ff;
	}

	.page-btn.active {
		background: #1d4ed8;
		border-color: #1d4ed8;
		color: #fff;
		box-shadow: 0 2px 8px rgba(29, 78, 216, .3);
	}

	.page-btn.disabled {
		opacity: .35;
		cursor: not-allowed;
		pointer-events: none;
	}

	/* Empty */
	.fh-empty {
		text-align: center;
		padding: 3rem 1rem;
		color: var(--text-muted);
	}

	.fh-empty-icon {
		font-size: 2.8rem;
		margin-bottom: .7rem;
		opacity: .3;
	}

	/* Correlativo mono */
	.corr-mono {
		font-family: 'Courier New', monospace;
		font-size: .82rem;
		font-weight: 600;
		color: #1e293b;
	}

	@media(max-width:768px) {
		.fh-filter-grid {
			grid-template-columns: 1fr 1fr;
		}

		.fh-filter-btns {
			grid-column: 1/-1;
		}

		.fh-table thead th:nth-child(5),
		.fh-table tbody td:nth-child(5),
		.fh-table thead th:nth-child(6),
		.fh-table tbody td:nth-child(6) {
			display: none;
		}
	}

	@media(max-width:480px) {
		.fh-filter-grid {
			grid-template-columns: 1fr;
		}

		.fh-table thead th:nth-child(3),
		.fh-table tbody td:nth-child(3) {
			display: none;
		}
	}
</style>

<div class="fh-page container">

	<!-- Header -->
	<div class="fh-header">
		<div>
			<h4 class="fh-header-title">📜 Historial de Facturas</h4>
			<p class="fh-header-sub">
				Sucursal: <?= htmlspecialchars($nombre_establecimiento) ?> &nbsp;·&nbsp;
				Rol: <?= htmlspecialchars(ucfirst($datos['rol'])) ?> &nbsp;·&nbsp;
				<?= htmlspecialchars($datos['cliente_nombre']) ?>
			</p>
		</div>
		<?php if (!empty($datos['logo_url'])): ?>
			<img src="<?= htmlspecialchars($datos['logo_url']) ?>" alt="Logo" class="fh-header-logo">
		<?php endif; ?>
	</div>

	<!-- Stats strip -->
	<div class="fh-stats">
		<div class="fh-stat">
			<div class="fh-stat-icon blue"><i class="bi bi-receipt"></i></div>
			<div>
				<div class="fh-stat-val"><?= $total_facturas ?></div>
				<div class="fh-stat-lbl">Total facturas</div>
			</div>
		</div>
		<div class="fh-stat">
			<div class="fh-stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
			<div>
				<div class="fh-stat-val"><?= $emitidas_count ?></div>
				<div class="fh-stat-lbl">Emitidas</div>
			</div>
		</div>
		<div class="fh-stat">
			<div class="fh-stat-icon red"><i class="bi bi-x-circle-fill"></i></div>
			<div>
				<div class="fh-stat-val"><?= $anuladas_count ?></div>
				<div class="fh-stat-lbl">Anuladas</div>
			</div>
		</div>
		<div class="fh-stat">
			<div class="fh-stat-icon amber"><i class="bi bi-cash-coin"></i></div>
			<div>
				<div class="fh-stat-val"><?= $pagadas_count ?></div>
				<div class="fh-stat-lbl">Pagadas</div>
			</div>
		</div>
		<div class="fh-stat">
			<div class="fh-stat-icon teal"><i class="bi bi-currency-dollar"></i></div>
			<div>
				<div class="fh-stat-val" style="font-size:1.1rem;">L <?= number_format($total_monto, 0) ?></div>
				<div class="fh-stat-lbl">Monto emitido</div>
			</div>
		</div>
	</div>

	<!-- Filter card -->
	<?php if (count($cais) >= 1): ?>
		<div class="fh-filter-card">
			<div class="fh-filter-title"><i class="bi bi-funnel-fill"></i> Filtros de búsqueda</div>
			<form method="GET" id="fhFilterForm">
				<div class="fh-filter-grid">
					<div>
						<label class="fh-label">CAI</label>
						<select name="cai_id" class="fh-select" <?= (count($cais) === 1) ? 'disabled' : '' ?>>
							<option value="">— Todos los CAI —</option>
							<?php foreach ($cais as $cai): ?>
								<option value="<?= $cai['id'] ?>" <?= ($caix == $cai['id']) ? 'selected' : '' ?>>
									<?= htmlspecialchars($cai['cai']) ?>
									<?= $cai['activo'] ? ' ✅' : ' ⛔ Vencido' ?>
									| <?= $cai['rango_inicio'] ?>–<?= $cai['rango_fin'] ?>
								</option>
							<?php endforeach; ?>
						</select>
						<?php if (count($cais) === 1): ?>
							<input type="hidden" name="cai_id" value="<?= $cais[0]['id'] ?>">
						<?php endif; ?>
					</div>
					<div>
						<label class="fh-label">Desde</label>
						<input type="date" name="fecha_desde" class="fh-date"
							value="<?= htmlspecialchars($_GET['fecha_desde'] ?? '') ?>">
					</div>
					<div>
						<label class="fh-label">Hasta</label>
						<input type="date" name="fecha_hasta" class="fh-date"
							value="<?= htmlspecialchars($_GET['fecha_hasta'] ?? '') ?>">
					</div>
					<div class="fh-filter-btns">
						<button type="submit" class="btn-filter"><i class="bi bi-search"></i> Filtrar</button>
						<a href="?" class="btn-clear-filter"><i class="bi bi-x-lg"></i> Limpiar</a>
					</div>
				</div>
			</form>
		</div>
	<?php endif; ?>

	<!-- Table card -->
	<div class="fh-card">
		<div class="fh-card-header">
			<span class="fh-card-title"><i class="bi bi-table"></i> Facturas</span>
			<div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
				<!-- inline search -->
				<div class="fh-search-wrap">
					<i class="bi bi-search"></i>
					<input type="text" id="fhSearch" class="fh-search"
						placeholder="Buscar correlativo, cliente, estado…" autocomplete="off">
					<button class="fh-clear-btn" id="fhClear"><i class="bi bi-x-lg"></i></button>
				</div>
				<select class="fh-per-page" id="fhPerPage">
					<option value="10" selected>10/pág</option>
					<option value="25">25/pág</option>
					<option value="50">50/pág</option>
				</select>
				<span class="fh-result-badge" id="fhBadge"><?= $total_facturas ?> registros</span>
			</div>
		</div>

		<div class="fh-table-wrap">
			<table class="fh-table" id="fhTable">
				<thead>
					<tr>
						<th data-col="0"><i class="bi bi-hash me-1"></i>Correlativo<i
								class="bi bi-arrow-up sort-icon"></i></th>
						<th data-col="1"><i class="bi bi-calendar3 me-1"></i>Fecha<i
								class="bi bi-arrow-up sort-icon"></i></th>
						<th data-col="2"><i class="bi bi-person me-1"></i>Cliente<i
								class="bi bi-arrow-up sort-icon"></i></th>
						<th data-col="3"><i class="bi bi-cash me-1"></i>Total<i class="bi bi-arrow-up sort-icon"></i>
						</th>
						<th data-col="4"><i class="bi bi-circle me-1"></i>Estado<i class="bi bi-arrow-up sort-icon"></i>
						</th>
						<th data-col="5"><i class="bi bi-send me-1"></i>Enviada<i class="bi bi-arrow-up sort-icon"></i>
						</th>
						<th data-col="6"><i class="bi bi-check2 me-1"></i>Pagada<i class="bi bi-arrow-up sort-icon"></i>
						</th>
						<th>Acciones</th>
						<th>PDF</th>
					</tr>
				</thead>
				<tbody id="fhBody">
					<?php foreach ($facturas as $f):
						$corrStr    = trim((string)$f['correlativo']);
						$ultimoStr  = trim((string)($ultimoCorrelativoCAI ?? ''));
						$puedeElim  = ($corrStr === $ultimoStr);
						$estadoCls  = match ($f['estado']) {
							'emitida' => 'st-emitida',
							'anulada' => 'st-anulada',
							default => 'st-borrador'
						};
					?>
						<tr data-search="<?= strtolower(htmlspecialchars(
												$f['correlativo'] . ' ' . $f['receptor'] . ' ' . $f['estado'] . ' ' . date('d/m/Y', strtotime($f['fecha_emision']))
											)) ?>">
							<td><span class="corr-mono" data-col="corr"><?= htmlspecialchars($f['correlativo']) ?></span>
							</td>
							<td data-col="fecha"><?= date('d/m/Y', strtotime($f['fecha_emision'])) ?></td>
							<td data-col="receptor"><?= htmlspecialchars($f['receptor']) ?></td>
							<td data-col="total" data-sort-val="<?= $f['total'] ?>">
								<strong>L <?= number_format($f['total'], 2) ?></strong>
							</td>
							<td>
								<span class="st-badge <?= $estadoCls ?>">
									<?= $f['estado'] === 'emitida' ? '<i class="bi bi-check-circle-fill"></i>' : ($f['estado'] === 'anulada' ? '<i class="bi bi-x-circle-fill"></i>' : '<i class="bi bi-pencil-fill"></i>') ?>
									<?= ucfirst($f['estado']) ?>
								</span>
							</td>
							<td>
								<span class="yn-badge <?= $f['enviada_receptor'] == 1 ? 'yn-yes' : 'yn-no' ?>">
									<?= $f['enviada_receptor'] == 1 ? '<i class="bi bi-check-lg"></i> Sí' : '<i class="bi bi-x-lg"></i> No' ?>
								</span>
							</td>
							<td>
								<span class="yn-badge <?= $f['pagada'] == 1 ? 'yn-yes' : 'yn-no' ?>">
									<?= $f['pagada'] == 1 ? '<i class="bi bi-check-lg"></i> Sí' : '<i class="bi bi-x-lg"></i> No' ?>
								</span>
							</td>
							<td>
								<div class="fh-actions">
									<?php if ($f['estado'] === 'emitida'): ?>
										<button onclick="accionFactura(<?= $f['id'] ?>,'anular')" class="btn-fa btn-fa-warn">
											<i class="bi bi-slash-circle"></i> Anular
										</button>
										<?php if ($puedeElim || $esAdmin): ?>
											<a href="editar_factura?id=<?= $f['id'] ?>" class="btn-fa btn-fa-edit">
												<i class="bi bi-pencil-fill"></i> Editar
											</a>
											<button onclick="accionFactura(<?= $f['id'] ?>,'eliminar')"
												class="btn-fa btn-fa-danger">
												<i class="bi bi-trash3-fill"></i> Eliminar
											</button>
										<?php endif; ?>
									<?php elseif ($f['estado'] === 'anulada'): ?>
										<span class="btn-fa btn-fa-sec" style="cursor:default;">Anulada</span>
										<button onclick="accionFactura(<?= $f['id'] ?>,'restaurar')"
											class="btn-fa btn-fa-success">
											<i class="bi bi-arrow-counterclockwise"></i> Reactivar
										</button>
									<?php elseif ($f['estado'] === 'borrador'): ?>
										<button onclick="accionFactura(<?= $f['id'] ?>,'restaurar')"
											class="btn-fa btn-fa-success">
											<i class="bi bi-arrow-counterclockwise"></i> Reactivar
										</button>
									<?php endif; ?>
								</div>
								<?php if ($esAdmin && !$puedeElim && $f['estado'] === 'emitida'): ?>
									<div style="font-size:.7rem;color:var(--warning);margin-top:4px;">
										<i class="bi bi-exclamation-triangle-fill"></i> No es la última del CAI
									</div>
								<?php endif; ?>
							</td>
							<td>
								<a href="ver_factura?id=<?= $f['id'] ?>" class="btn-fa btn-fa-view" target="_blank">
									<i class="bi bi-printer-fill"></i> Ver/PDF
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<div class="fh-empty" id="fhEmpty" style="display:none;">
				<div class="fh-empty-icon"><i class="bi bi-receipt-cutoff"></i></div>
				<div style="font-weight:600;">Sin resultados</div>
				<div id="fhEmptySub" style="font-size:.85rem;margin-top:.3rem;"></div>
			</div>
		</div>

		<div class="fh-pagination">
			<span class="fh-page-info" id="fhPageInfo"></span>
			<div class="fh-page-btns" id="fhPageBtns"></div>
		</div>
	</div>

</div>

<script>
	/* ── Table engine ── */
	(() => {
		let query = '',
			page = 1,
			perPage = 10,
			sortCol = -1,
			sortDir = 'asc';
		const allRows = Array.from(document.querySelectorAll('#fhBody tr'));
		const $s = $('#fhSearch'),
			$cl = $('#fhClear'),
			$pp = $('#fhPerPage');
		const $empty = $('#fhEmpty'),
			$sub = $('#fhEmptySub'),
			$info = $('#fhPageInfo');
		const $btns = $('#fhPageBtns'),
			$badge = $('#fhBadge');
		const headers = document.querySelectorAll('#fhTable thead th[data-col]');

		function hl(t, q) {
			if (!q) return t;
			return t.replace(new RegExp(`(${q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')})`, `gi`),
				'<mark class="fh-highlight">$1</mark>');
		}

		function colTxt(r, i) {
			const td = r.querySelectorAll('td')[i];
			return td ? (td.dataset.original || td.getAttribute('data-sort-val') || td.textContent).trim()
				.toLowerCase() : '';
		}

		function filtered() {
			const base = !query ? allRows : allRows.filter(r => r.dataset.search.includes(query.toLowerCase()));
			if (sortCol < 0) return base;
			return [...base].sort((a, b) => {
				const va = colTxt(a, sortCol),
					vb = colTxt(b, sortCol);
				return sortDir === 'asc' ? va.localeCompare(vb, 'es') : vb.localeCompare(va, 'es');
			});
		}

		function updIcons() {
			headers.forEach(th => {
				const i = parseInt(th.dataset.col);
				th.classList.remove('sort-asc', 'sort-desc');
				if (i === sortCol) th.classList.add(sortDir === 'asc' ? 'sort-asc' : 'sort-desc');
			});
		}

		function render() {
			const rows = filtered(),
				total = rows.length,
				totPg = Math.max(1, Math.ceil(total / perPage));
			if (page > totPg) page = totPg;
			const s = (page - 1) * perPage,
				e = Math.min(s + perPage, total);
			allRows.forEach(r => r.style.display = 'none');
			if (total === 0) {
				$empty.style.display = 'block';
				$sub.textContent = query ? `Sin resultados para "${query}".` : 'No hay facturas en este rango.';
			} else {
				$empty.style.display = 'none';
				rows.slice(s, e).forEach(r => {
					r.style.display = '';
					r.querySelectorAll('[data-col]').forEach(c => {
						const o = c.dataset.original ?? c.textContent;
						c.dataset.original = o;
						c.innerHTML = hl(o, query);
					});
				});
			}
			$badge.textContent = `${total} registro${total!==1?'s':''}`;
			$info.textContent = total === 0 ? 'Sin resultados' : `Mostrando ${s+1}–${e} de ${total}`;
			buildPg(page, totPg);
		}

		function buildPg(cur, tot) {
			$btns.innerHTML = '';
			if (tot <= 1) return;
			const mk = (html, p, cls = '') => {
				const b = document.createElement('button');
				b.className = `page-btn ${cls}`;
				b.innerHTML = html;
				if (!cls.includes('disabled') && !cls.includes('active')) b.addEventListener('click', () => {
					page = p;
					render();
				});
				$btns.appendChild(b);
			};
			mk('<i class="bi bi-chevron-double-left"></i>', 1, cur === 1 ? 'disabled' : '');
			mk('<i class="bi bi-chevron-left"></i>', cur - 1, cur === 1 ? 'disabled' : '');
			let pages = new Set([1, tot]);
			for (let i = Math.max(2, cur - 2); i <= Math.min(tot - 1, cur + 2); i++) pages.add(i);
			pages = [...pages].sort((a, b) => a - b);
			let prev = 0;
			pages.forEach(pg => {
				if (pg - prev > 1) {
					const d = document.createElement('button');
					d.className = 'page-btn disabled';
					d.textContent = '…';
					$btns.appendChild(d);
				}
				mk(pg, pg, pg === cur ? 'active' : '');
				prev = pg;
			});
			mk('<i class="bi bi-chevron-right"></i>', cur + 1, cur === tot ? 'disabled' : '');
			mk('<i class="bi bi-chevron-double-right"></i>', tot, cur === tot ? 'disabled' : '');
		}

		headers.forEach(th => th.addEventListener('click', () => {
			const i = parseInt(th.dataset.col);
			sortDir = (sortCol === i && sortDir === 'asc') ? 'desc' : 'asc';
			sortCol = i;
			page = 1;
			updIcons();
			render();
		}));
		let deb;
		$s.addEventListener('input', () => {
			clearTimeout(deb);
			deb = setTimeout(() => {
				query = $s.value.trim();
				page = 1;
				$cl.classList.toggle('visible', query.length > 0);
				render();
			}, 180);
		});
		$cl.addEventListener('click', () => {
			$s.value = '';
			query = '';
			page = 1;
			$cl.classList.remove('visible');
			render();
			$s.focus();
		});
		$pp.addEventListener('change', () => {
			perPage = parseInt($pp.value);
			page = 1;
			render();
		});
		updIcons();
		render();
	})();

	/* ── Acciones factura ── */
	function accionFactura(facturaId, accion) {
		Swal.fire({
			title: `¿${accion.charAt(0).toUpperCase()+accion.slice(1)} esta factura?`,
			html: `
            <input type="text" id="motivo" class="swal2-input" placeholder="Motivo (obligatorio)">
            <input type="text" id="usuario" class="swal2-input" placeholder="Usuario admin/superadmin">
            <input type="password" id="clave" class="swal2-input" placeholder="Contraseña">
        `,
			icon: 'warning',
			showCancelButton: true,
			confirmButtonText: 'Confirmar',
			cancelButtonText: 'Cancelar',
			confirmButtonColor: '#1e40af',
			focusConfirm: false,
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
		}).then(result => {
			if (!result.isConfirmed) return;
			fetch('procesar_accion_factura', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json'
					},
					body: JSON.stringify({
						factura_id: facturaId,
						accion,
						motivo: result.value.motivo,
						usuario_autoriza: result.value.usuario,
						clave_autoriza: result.value.clave
					})
				})
				.then(r => r.json())
				.then(data => {
					if (data.success) Swal.fire('Correcto', data.message, 'success').then(() => location
						.reload());
					else Swal.fire('Error', data.error, 'error');
				});
		});
	}
</script>

<?php require_once '../../includes/templates/footer.php'; ?>