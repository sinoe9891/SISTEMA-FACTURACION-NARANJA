<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

$usuario_id             = $_SESSION['usuario_id'] ?? null;
$establecimiento_activo = $_SESSION['establecimiento_activo'] ?? null;
$es_superadmin          = (USUARIO_ROL === 'superadmin');

if (!$establecimiento_activo && !$es_superadmin) {
	header("Location: ./seleccionar_establecimiento");
	exit;
}

$stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->execute([$usuario_id]);
$usuario = $stmt->fetch();

if (!$es_superadmin && ($usuario['cliente_id'] ?? null)) {
	$stmt = $pdo->prepare("SELECT nombre, logo_url FROM clientes_saas WHERE id = ?");
	$stmt->execute([$usuario['cliente_id']]);
	$cliente    = $stmt->fetch();
	$cliente_id = $usuario['cliente_id'];
} else {
	$cliente_id = $_SESSION['cliente_seleccionado'] ?? null;
	if (!$cliente_id) die("Cliente no seleccionado.");
	$stmt = $pdo->prepare("SELECT nombre, logo_url FROM clientes_saas WHERE id = ?");
	$stmt->execute([$cliente_id]);
	$cliente = $stmt->fetch();
}

// Establecimiento nombre
$nombre_establecimiento = 'No asignado';
if ($establecimiento_activo) {
	$stmt = $pdo->prepare("SELECT nombre FROM establecimientos WHERE establecimiento_id = ?");
	$stmt->execute([$establecimiento_activo]);
	$nombre_establecimiento = $stmt->fetchColumn() ?: 'No asignado';
}

$stmt = $pdo->prepare("SELECT * FROM productos WHERE cliente_id = ?");
$stmt->execute([$cliente_id]);
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT * FROM clientes_factura WHERE cliente_id = ?");
$stmt->execute([$cliente_id]);
$receptores = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT pc.*, r.nombre AS receptor_nombre FROM productos_clientes pc INNER JOIN clientes_factura r ON pc.receptores_id = r.id WHERE pc.cliente_id = ? ORDER BY r.nombre, pc.nombre");
$stmt->execute([$cliente_id]);
$productos_clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total_pc = count($productos_clientes);

require_once '../../includes/templates/header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>
	:root {
		--brand: #059669;
		--brand-light: #d1fae5;
		--brand-dark: #047857;
		--purple: #7c3aed;
		--purple-light: #ede9fe;
		--info: #0ea5e9;
		--info-bg: #f0f9ff;
		--danger: #ef4444;
		--danger-bg: #fef2f2;
		--warning: #f59e0b;
		--warning-bg: #fffbeb;
		--surface: #fff;
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

	.pc-page {
		padding: 1.5rem 0 3rem;
	}

	/* Header */
	.pc-header {
		background: linear-gradient(135deg, #059669 0%, #047857 100%);
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

	.pc-header::before {
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

	.pc-header::after {
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

	.pc-header-title {
		font-size: 1.35rem;
		font-weight: 700;
		margin: 0;
	}

	.pc-header-sub {
		font-size: .82rem;
		opacity: .8;
		margin: .25rem 0 0;
	}

	.pc-header-logo {
		max-height: 52px;
		border-radius: 8px;
		background: rgba(255, 255, 255, .15);
		padding: 4px;
	}

	/* Stats */
	.pc-stats {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
		gap: 1rem;
		margin-bottom: 1.5rem;
	}

	.pc-stat {
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

	.pc-stat:hover {
		box-shadow: var(--shadow-md);
		transform: translateY(-2px);
	}

	.pc-stat-icon {
		width: 40px;
		height: 40px;
		border-radius: 10px;
		display: flex;
		align-items: center;
		justify-content: center;
		font-size: 1.1rem;
		flex-shrink: 0;
	}

	.pc-stat-icon.green {
		background: var(--brand-light);
		color: var(--brand);
	}

	.pc-stat-icon.purple {
		background: var(--purple-light);
		color: var(--purple);
	}

	.pc-stat-icon.blue {
		background: #dbeafe;
		color: #1d4ed8;
	}

	.pc-stat-icon.amber {
		background: var(--warning-bg);
		color: var(--warning);
	}

	.pc-stat-val {
		font-size: 1.35rem;
		font-weight: 700;
		color: var(--text-main);
		line-height: 1;
	}

	.pc-stat-lbl {
		font-size: .72rem;
		color: var(--text-muted);
		margin-top: 2px;
	}

	/* Toolbar */
	.pc-toolbar {
		display: flex;
		align-items: center;
		gap: .75rem;
		flex-wrap: wrap;
		margin-bottom: 1.25rem;
	}

	.pc-search-wrap {
		position: relative;
		flex: 1 1 200px;
		min-width: 180px;
	}

	.pc-search-wrap>i {
		position: absolute;
		left: .8rem;
		top: 50%;
		transform: translateY(-50%);
		color: var(--text-muted);
		font-size: .9rem;
		pointer-events: none;
	}

	.pc-search {
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

	.pc-search:focus {
		border-color: var(--brand);
		box-shadow: 0 0 0 3px rgba(5, 150, 105, .12);
	}

	.pc-search::placeholder {
		color: #94a3b8;
	}

	.pc-clear-btn {
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

	.pc-clear-btn.visible {
		display: block;
	}

	.btn-action-group {
		display: flex;
		gap: .5rem;
	}

	.btn-assign {
		display: inline-flex;
		align-items: center;
		gap: .4rem;
		padding: .52rem 1rem;
		background: var(--brand);
		color: #fff;
		border-radius: var(--radius-sm);
		font-size: .85rem;
		font-weight: 600;
		border: none;
		cursor: pointer;
		white-space: nowrap;
		box-shadow: 0 2px 8px rgba(5, 150, 105, .25);
		transition: background var(--tr), transform var(--tr);
	}

	.btn-assign:hover {
		background: var(--brand-dark);
		transform: translateY(-1px);
	}

	.btn-create-prod {
		display: inline-flex;
		align-items: center;
		gap: .4rem;
		padding: .52rem 1rem;
		background: var(--purple);
		color: #fff;
		border-radius: var(--radius-sm);
		font-size: .85rem;
		font-weight: 600;
		border: none;
		cursor: pointer;
		white-space: nowrap;
		box-shadow: 0 2px 8px rgba(124, 58, 237, .25);
		transition: background var(--tr), transform var(--tr);
	}

	.btn-create-prod:hover {
		background: #5b21b6;
		transform: translateY(-1px);
	}

	.pc-per-page {
		padding: .48rem .65rem;
		border: 1.5px solid var(--border);
		border-radius: var(--radius-sm);
		font-size: .82rem;
		background: var(--surface);
		color: var(--text-main);
		cursor: pointer;
		outline: none;
	}

	/* Card / Table */
	.pc-card {
		background: var(--surface);
		border: 1px solid var(--border);
		border-radius: var(--radius);
		box-shadow: var(--shadow-sm);
		overflow: hidden;
	}

	.pc-card-header {
		padding: 1rem 1.5rem;
		border-bottom: 1px solid var(--border);
		display: flex;
		align-items: center;
		justify-content: space-between;
		background: var(--surface-2);
		gap: 1rem;
		flex-wrap: wrap;
	}

	.pc-card-title {
		font-weight: 700;
		font-size: .95rem;
		color: var(--text-main);
		display: flex;
		align-items: center;
		gap: .5rem;
	}

	.pc-result-badge {
		display: inline-flex;
		align-items: center;
		background: var(--brand-light);
		color: var(--brand);
		border-radius: 20px;
		padding: .15rem .65rem;
		font-size: .78rem;
		font-weight: 600;
	}

	.pc-table-wrap {
		overflow-x: auto;
	}

	.pc-table {
		width: 100%;
		border-collapse: collapse;
		font-size: .855rem;
	}

	.pc-table thead th {
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

	.pc-table thead th:last-child {
		cursor: default;
	}

	.pc-table thead th:hover:not(:last-child) {
		background: #d1fae5;
		color: var(--brand);
	}

	.pc-table thead th.sort-asc,
	.pc-table thead th.sort-desc {
		color: var(--brand);
		background: #d1fae5;
	}

	.sort-icon {
		margin-left: .35rem;
		font-size: .68rem;
		opacity: .3;
		display: inline-block;
		transition: opacity .15s, transform .15s;
	}

	.pc-table thead th:hover:not(:last-child) .sort-icon {
		opacity: .7;
	}

	.pc-table thead th.sort-asc .sort-icon,
	.pc-table thead th.sort-desc .sort-icon {
		opacity: 1;
	}

	.pc-table thead th.sort-desc .sort-icon {
		transform: rotate(180deg);
	}

	.pc-table tbody tr {
		border-bottom: 1px solid var(--border);
		transition: background var(--tr);
	}

	.pc-table tbody tr:last-child {
		border-bottom: none;
	}

	.pc-table tbody tr:hover {
		background: #f0fdf4;
	}

	.pc-table tbody td {
		padding: .8rem 1rem;
		color: var(--text-main);
		vertical-align: middle;
	}

	.isv-badge {
		display: inline-flex;
		align-items: center;
		padding: .18rem .55rem;
		border-radius: 20px;
		font-size: .73rem;
		font-weight: 700;
	}

	.isv-0 {
		background: #f1f5f9;
		color: var(--text-muted);
	}

	.isv-15 {
		background: #fef3c7;
		color: #92400e;
	}

	.isv-18 {
		background: #fee2e2;
		color: #991b1b;
	}

	.fijo-yes {
		background: var(--brand-light);
		color: var(--brand);
		display: inline-flex;
		align-items: center;
		gap: .25rem;
		padding: .18rem .55rem;
		border-radius: 20px;
		font-size: .73rem;
		font-weight: 600;
	}

	.fijo-no {
		background: #f1f5f9;
		color: var(--text-muted);
		display: inline-flex;
		align-items: center;
		gap: .25rem;
		padding: .18rem .55rem;
		border-radius: 20px;
		font-size: .73rem;
		font-weight: 600;
	}

	.pc-highlight {
		background: #fef08a;
		border-radius: 3px;
		padding: 0 2px;
	}

	/* Actions */
	.pc-actions {
		display: flex;
		gap: .35rem;
		align-items: center;
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

	.btn-fa-edit {
		background: var(--info-bg);
		color: var(--info);
		border-color: rgba(14, 165, 233, .2);
	}

	.btn-fa-edit:hover {
		background: var(--info);
		color: #fff;
		transform: translateY(-1px);
	}

	.btn-fa-del {
		background: var(--danger-bg);
		color: var(--danger);
		border-color: rgba(239, 68, 68, .2);
	}

	.btn-fa-del:hover {
		background: var(--danger);
		color: #fff;
		transform: translateY(-1px);
	}

	/* Pagination */
	.pc-pagination {
		display: flex;
		align-items: center;
		justify-content: space-between;
		padding: .85rem 1.25rem;
		border-top: 1px solid var(--border);
		background: var(--surface-2);
		gap: .75rem;
		flex-wrap: wrap;
	}

	.pc-page-info {
		font-size: .78rem;
		color: var(--text-muted);
	}

	.pc-page-btns {
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
		border-color: var(--brand);
		color: var(--brand);
		background: var(--brand-light);
	}

	.page-btn.active {
		background: var(--brand);
		border-color: var(--brand);
		color: #fff;
		box-shadow: 0 2px 8px rgba(5, 150, 105, .3);
	}

	.page-btn.disabled {
		opacity: .35;
		cursor: not-allowed;
		pointer-events: none;
	}

	/* Empty */
	.pc-empty {
		text-align: center;
		padding: 3rem 1rem;
		color: var(--text-muted);
	}

	.pc-empty-icon {
		font-size: 2.8rem;
		margin-bottom: .7rem;
		opacity: .3;
	}

	/* Modal overrides */
	.modal-content {
		border: none;
		border-radius: var(--radius);
		box-shadow: 0 20px 60px rgba(0, 0, 0, .15);
	}

	.modal-header {
		border-bottom: 1px solid var(--border);
		padding: 1.1rem 1.5rem;
	}

	.modal-title {
		font-size: 1rem;
		font-weight: 700;
	}

	.modal-body {
		padding: 1.5rem;
	}

	.modal-footer {
		border-top: 1px solid var(--border);
		padding: .9rem 1.5rem;
	}

	.mf-label {
		font-size: .78rem;
		font-weight: 600;
		color: var(--text-muted);
		text-transform: uppercase;
		letter-spacing: .04em;
		margin-bottom: .35rem;
		display: block;
	}

	.mf-input,
	.mf-select,
	.mf-textarea {
		width: 100%;
		padding: .55rem .8rem;
		border: 1.5px solid var(--border);
		border-radius: var(--radius-sm);
		font-size: .88rem;
		color: var(--text-main);
		background: var(--surface);
		outline: none;
		transition: border-color var(--tr), box-shadow var(--tr);
	}

	.mf-input:focus,
	.mf-select:focus,
	.mf-textarea:focus {
		border-color: var(--brand);
		box-shadow: 0 0 0 3px rgba(5, 150, 105, .1);
	}

	.mf-textarea {
		resize: vertical;
		min-height: 70px;
	}

	.mb-mf {
		margin-bottom: .9rem;
	}

	.btn-modal-save {
		display: inline-flex;
		align-items: center;
		gap: .4rem;
		padding: .55rem 1.2rem;
		background: var(--brand);
		color: #fff;
		border: none;
		border-radius: var(--radius-sm);
		font-size: .88rem;
		font-weight: 600;
		cursor: pointer;
		transition: background var(--tr);
	}

	.btn-modal-save:hover {
		background: var(--brand-dark);
	}

	.btn-modal-save.purple-btn {
		background: var(--purple);
	}

	.btn-modal-save.purple-btn:hover {
		background: #5b21b6;
	}

	@media(max-width:640px) {
		.pc-header {
			padding: 1.1rem 1.25rem;
		}

		.pc-header-title {
			font-size: 1.1rem;
		}

		.pc-table thead th:nth-child(4),
		.pc-table tbody td:nth-child(4),
		.pc-table thead th:nth-child(5),
		.pc-table tbody td:nth-child(5) {
			display: none;
		}
	}
</style>

<div class="pc-page container">

	<!-- Header -->
	<div class="pc-header">
		<div>
			<h4 class="pc-header-title">🌍 Productos por Cliente</h4>
			<p class="pc-header-sub">
				<?= htmlspecialchars($cliente['nombre']) ?> &nbsp;·&nbsp;
				Sucursal: <?= htmlspecialchars($nombre_establecimiento) ?> &nbsp;·&nbsp;
				Rol: <?= htmlspecialchars(ucfirst($usuario['rol'] ?? '')) ?>
			</p>
		</div>
		<?php if (!empty($cliente['logo_url'])): ?>
			<img src="<?= htmlspecialchars($cliente['logo_url']) ?>" alt="Logo" class="pc-header-logo">
		<?php endif; ?>
	</div>

	<!-- Stats -->
	<?php
	$isv_0  = count(array_filter($productos_clientes, fn($p) => $p['tipo_isv'] == 0));
	$isv_15 = count(array_filter($productos_clientes, fn($p) => $p['tipo_isv'] == 15));
	$isv_18 = count(array_filter($productos_clientes, fn($p) => $p['tipo_isv'] == 18));
	?>
	<div class="pc-stats">
		<div class="pc-stat">
			<div class="pc-stat-icon green"><i class="bi bi-box-seam-fill"></i></div>
			<div>
				<div class="pc-stat-val" id="stat-total"><?= $total_pc ?></div>
				<div class="pc-stat-lbl">Total productos</div>
			</div>
		</div>
		<div class="pc-stat">
			<div class="pc-stat-icon purple"><i class="bi bi-people-fill"></i></div>
			<div>
				<div class="pc-stat-val"><?= count($receptores) ?></div>
				<div class="pc-stat-lbl">Receptores</div>
			</div>
		</div>
		<div class="pc-stat">
			<div class="pc-stat-icon blue"><i class="bi bi-percent"></i></div>
			<div>
				<div class="pc-stat-val"><?= $isv_15 ?></div>
				<div class="pc-stat-lbl">ISV 15%</div>
			</div>
		</div>
		<div class="pc-stat">
			<div class="pc-stat-icon amber"><i class="bi bi-funnel-fill"></i></div>
			<div>
				<div class="pc-stat-val" id="stat-filtered"><?= $total_pc ?></div>
				<div class="pc-stat-lbl">Resultados</div>
			</div>
		</div>
	</div>

	<!-- Toolbar -->
	<div class="pc-toolbar">
		<div class="btn-action-group">
			<button class="btn-assign" data-bs-toggle="modal" data-bs-target="#modalAsignarExistente">
				<i class="bi bi-link-45deg"></i> Asignar Producto
			</button>
			<button class="btn-create-prod" data-bs-toggle="modal" data-bs-target="#modalCrearNuevo">
				<i class="bi bi-plus-lg"></i> Crear Nuevo
			</button>
		</div>
		<div class="pc-search-wrap">
			<i class="bi bi-search"></i>
			<input type="text" id="pcSearch" class="pc-search" placeholder="Buscar por receptor, nombre, descripción…"
				autocomplete="off">
			<button class="pc-clear-btn" id="pcClear"><i class="bi bi-x-lg"></i></button>
		</div>
		<select class="pc-per-page" id="pcPerPage">
			<option value="10" selected>10/pág</option>
			<option value="25">25/pág</option>
			<option value="50">50/pág</option>
		</select>
	</div>

	<!-- Table card -->
	<div class="pc-card">
		<div class="pc-card-header">
			<span class="pc-card-title"><i class="bi bi-table"></i> Productos asignados</span>
			<span class="pc-result-badge" id="pcBadge"><?= $total_pc ?> registros</span>
		</div>

		<div class="pc-table-wrap">
			<table class="pc-table" id="pcTable">
				<thead>
					<tr>
						<th data-col="0"><i class="bi bi-person me-1"></i>Receptor<i
								class="bi bi-arrow-up sort-icon"></i></th>
						<th data-col="1"><i class="bi bi-box me-1"></i>Nombre<i class="bi bi-arrow-up sort-icon"></i>
						</th>
						<th data-col="2"><i class="bi bi-card-text me-1"></i>Descripción<i
								class="bi bi-arrow-up sort-icon"></i></th>
						<th data-col="3"><i class="bi bi-cash me-1"></i>Precio<i class="bi bi-arrow-up sort-icon"></i>
						</th>
						<th data-col="4"><i class="bi bi-percent me-1"></i>ISV<i class="bi bi-arrow-up sort-icon"></i>
						</th>
						<th data-col="5">Fijo<i class="bi bi-arrow-up sort-icon"></i></th>
						<th><i class="bi bi-gear me-1"></i>Acciones</th>
					</tr>
				</thead>
				<tbody id="pcBody">
					<?php foreach ($productos_clientes as $p): ?>
						<tr
							data-search="<?= strtolower(htmlspecialchars($p['receptor_nombre'] . ' ' . $p['nombre'] . ' ' . ($p['descripcion'] ?? ''))) ?>">
							<td data-col="receptor"><strong><?= htmlspecialchars($p['receptor_nombre']) ?></strong></td>
							<td data-col="nombre"><?= htmlspecialchars($p['nombre']) ?></td>
							<td data-col="desc" style="max-width:220px;"><span
									style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
									title="<?= htmlspecialchars($p['descripcion']) ?>"><?= htmlspecialchars($p['descripcion']) ?></span>
							</td>
							<td data-sort-val="<?= $p['precio'] ?>"><strong>L <?= number_format($p['precio'], 2) ?></strong>
							</td>
							<td>
								<span class="isv-badge isv-<?= (int)$p['tipo_isv'] ?>"><?= (int)$p['tipo_isv'] ?>%</span>
							</td>
							<td>
								<?php if ($p['precio_fijo']): ?>
									<span class="fijo-yes"><i class="bi bi-lock-fill"></i> Sí</span>
								<?php else: ?>
									<span class="fijo-no"><i class="bi bi-unlock"></i> No</span>
								<?php endif; ?>
							</td>
							<td>
								<div class="pc-actions">
									<button class="btn-fa btn-fa-edit"
										onclick='editarProducto(<?= json_encode($p, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
										<i class="bi bi-pencil-fill"></i>
										<span class="d-none d-md-inline">Editar</span>
									</button>
									<button class="btn-fa btn-fa-del" onclick="confirmarEliminar(<?= $p['id'] ?>)">
										<i class="bi bi-trash3-fill"></i>
										<span class="d-none d-md-inline">Eliminar</span>
									</button>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<div class="pc-empty" id="pcEmpty" style="display:none;">
				<div class="pc-empty-icon"><i class="bi bi-box-seam"></i></div>
				<div style="font-weight:600;">Sin resultados</div>
				<div id="pcEmptySub" style="font-size:.85rem;margin-top:.3rem;"></div>
			</div>
		</div>

		<div class="pc-pagination">
			<span class="pc-page-info" id="pcPageInfo"></span>
			<div class="pc-page-btns" id="pcPageBtns"></div>
		</div>
	</div>
</div>

<!-- ── Modal: Asignar producto existente ── -->
<div class="modal fade" id="modalAsignarExistente" tabindex="-1">
	<div class="modal-dialog modal-dialog-centered">
		<form method="POST" action="includes/productos_clientes_agregar.php" class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title"><i class="bi bi-link-45deg me-2 text-success"></i>Asignar Producto Existente
				</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
			</div>
			<div class="modal-body">
				<input type="hidden" name="cliente_id" value="<?= $cliente_id ?>">

				<div class="mb-mf">
					<label class="mf-label">Receptor</label>
					<select name="receptores_id" class="mf-select" required>
						<option value="">— Seleccione receptor —</option>
						<?php foreach ($receptores as $r): ?>
							<option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['nombre']) ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="mb-mf">
					<label class="mf-label">Producto base</label>
					<select name="producto_id" class="mf-select" onchange="autocompletarProducto(this)" required>
						<option value="">— Seleccione producto —</option>
						<?php foreach ($productos as $p): ?>
							<option value='<?= htmlspecialchars(json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>
								<?= htmlspecialchars($p['nombre']) ?> — L <?= number_format($p['precio'], 2) ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<input type="hidden" name="nombre" id="nombreNuevo">
				<input type="hidden" name="descripcion" id="descripcionNuevo">
				<input type="hidden" name="precio" id="precioNuevo">
				<input type="hidden" name="tipo_isv" id="tipoISVNuevo">
				<input type="hidden" name="precio_fijo" value="1">
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
				<button type="submit" class="btn-modal-save"><i class="bi bi-check-lg me-1"></i>Asignar</button>
			</div>
		</form>
	</div>
</div>

<!-- ── Modal: Crear producto nuevo ── -->
<div class="modal fade" id="modalCrearNuevo" tabindex="-1">
	<div class="modal-dialog modal-dialog-centered">
		<form method="POST" action="includes/productos_clientes_agregar.php" class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title"><i class="bi bi-plus-circle-fill me-2 text-purple"
						style="color:#7c3aed"></i>Crear Producto Nuevo</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
			</div>
			<div class="modal-body">
				<input type="hidden" name="cliente_id" value="<?= $cliente_id ?>">

				<div class="mb-mf">
					<label class="mf-label">Receptor</label>
					<select name="receptores_id" class="mf-select" required>
						<option value="">— Seleccione receptor —</option>
						<?php foreach ($receptores as $r): ?>
							<option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['nombre']) ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="mb-mf">
					<label class="mf-label">Nombre del producto</label>
					<input type="text" name="nombre" class="mf-input" placeholder="Ej: Servicio de mantenimiento"
						required>
				</div>
				<div class="mb-mf">
					<label class="mf-label">Descripción</label>
					<textarea name="descripcion" class="mf-textarea"
						placeholder="Breve descripción del servicio o producto" required></textarea>
				</div>
				<div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;" class="mb-mf">
					<div>
						<label class="mf-label">Precio (L)</label>
						<input type="number" name="precio" step="0.01" min="0" class="mf-input" placeholder="0.00"
							required>
					</div>
					<div>
						<label class="mf-label">ISV</label>
						<select name="tipo_isv" class="mf-select" required>
							<option value="15">15%</option>
							<option value="18">18%</option>
							<option value="0">Exento (0%)</option>
						</select>
					</div>
				</div>
				<div class="form-check">
					<input class="form-check-input" type="checkbox" name="precio_fijo" value="1" id="nuevo_precio_fijo"
						checked>
					<label class="form-check-label" for="nuevo_precio_fijo" style="font-size:.85rem;">
						<i class="bi bi-lock-fill me-1 text-success"></i>Precio fijo (no editable en factura)
					</label>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
				<button type="submit" class="btn-modal-save purple-btn"><i class="bi bi-plus-lg me-1"></i>Crear</button>
			</div>
		</form>
	</div>
</div>

<!-- ── Modal: Editar producto ── -->
<div class="modal fade" id="modalEditar" tabindex="-1">
	<div class="modal-dialog modal-dialog-centered">
		<form method="POST" action="includes/productos_clientes_editar.php" class="modal-content">
			<div class="modal-header">
				<h5 class="modal-title"><i class="bi bi-pencil-square me-2 text-info"></i>Editar Producto</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
			</div>
			<div class="modal-body">
				<input type="hidden" name="id" id="editar_id">
				<input type="hidden" name="cliente_id" value="<?= $cliente_id ?>">
				<div class="mb-mf">
					<label class="mf-label">Nombre</label>
					<input type="text" name="nombre" id="editar_nombre" class="mf-input" required>
				</div>
				<div class="mb-mf">
					<label class="mf-label">Descripción</label>
					<textarea name="descripcion" id="editar_descripcion" class="mf-textarea" required></textarea>
				</div>
				<div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;" class="mb-mf">
					<div>
						<label class="mf-label">Precio (L)</label>
						<input type="number" step="0.01" min="0" name="precio" id="editar_precio" class="mf-input"
							required>
					</div>
					<div>
						<label class="mf-label">ISV</label>
						<select name="tipo_isv" id="editar_tipo_isv" class="mf-select" required>
							<option value="15">15%</option>
							<option value="18">18%</option>
							<option value="0">Exento (0%)</option>
						</select>
					</div>
				</div>
				<div class="form-check">
					<input class="form-check-input" type="checkbox" name="precio_fijo" id="editar_precio_fijo"
						value="1">
					<label class="form-check-label" for="editar_precio_fijo" style="font-size:.85rem;">
						<i class="bi bi-lock-fill me-1 text-success"></i>Precio fijo
					</label>
				</div>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
				<button type="submit" class="btn-modal-save"><i class="bi bi-floppy-fill me-1"></i>Actualizar</button>
			</div>
		</form>
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
		const allRows = Array.from(document.querySelectorAll('#pcBody tr'));
		const $s = document.getElementById('pcSearch'),
			$cl = document.getElementById('pcClear'),
			$pp = document.getElementById('pcPerPage');
		const $empty = document.getElementById('pcEmpty'),
			$sub = document.getElementById('pcEmptySub');
		const $info = document.getElementById('pcPageInfo'),
			$btns = document.getElementById('pcPageBtns');
		const $badge = document.getElementById('pcBadge'),
			$statF = document.getElementById('stat-filtered');
		const headers = document.querySelectorAll('#pcTable thead th[data-col]');

		function hl(t, q) {
			if (!q) return t;
			return t.replace(new RegExp(`(${q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')})`, `gi`),
				'<mark class="pc-highlight">$1</mark>');
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
				$sub.textContent = query ? `Sin resultados para "${query}".` : 'No hay productos asignados aún.';
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
			if ($statF) $statF.textContent = total;
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

	/* ── Autocompletar desde selector de producto ── */
	function autocompletarProducto(select) {
		if (!select.value) return;
		try {
			const data = JSON.parse(select.value);
			document.getElementById('nombreNuevo').value = data.nombre;
			document.getElementById('descripcionNuevo').value = data.descripcion;
			document.getElementById('precioNuevo').value = data.precio;
			document.getElementById('tipoISVNuevo').value = data.tipo_isv;
		} catch (e) {}
	}

	/* ── Abrir modal editar ── */
	function editarProducto(p) {
		document.getElementById('editar_id').value = p.id;
		document.getElementById('editar_nombre').value = p.nombre;
		document.getElementById('editar_descripcion').value = p.descripcion;
		document.getElementById('editar_precio').value = p.precio;
		document.getElementById('editar_tipo_isv').value = p.tipo_isv;
		document.getElementById('editar_precio_fijo').checked = (p.precio_fijo == 1);
		new bootstrap.Modal(document.getElementById('modalEditar')).show();
	}

	/* ── Eliminar ── */
	function confirmarEliminar(id) {
		Swal.fire({
			title: '¿Eliminar producto?',
			text: 'Se eliminará este producto del cliente.',
			icon: 'warning',
			showCancelButton: true,
			confirmButtonColor: '#ef4444',
			cancelButtonColor: '#6b7280',
			confirmButtonText: '<i class="bi bi-trash3-fill me-1"></i> Sí, eliminar',
			cancelButtonText: 'Cancelar',
			reverseButtons: true
		}).then(r => {
			if (!r.isConfirmed) return;
			fetch('includes/productos_clientes_eliminar.php', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded'
					},
					body: 'id=' + encodeURIComponent(id)
				})
				.then(res => res.json())
				.then(data => {
					if (data.status === 'ok') Swal.fire('¡Eliminado!', 'El producto ha sido eliminado.',
						'success').then(() => location.reload());
					else throw new Error(data.message);
				})
				.catch(() => Swal.fire('Error', 'Hubo un problema al eliminar.', 'error'));
		});
	}
</script>

<?php require_once '../../includes/templates/footer.php'; ?>