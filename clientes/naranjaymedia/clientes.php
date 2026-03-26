<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

$usuario_id = $_SESSION['usuario_id'];
$establecimiento_activo = $_SESSION['establecimiento_activo'] ?? null;
$nombre_establecimiento = 'No asignado';

if ($establecimiento_activo) {
	$stmt = $pdo->prepare("SELECT nombre FROM establecimientos WHERE establecimiento_id = ?");
	$stmt->execute([$establecimiento_activo]);
	$nombre_establecimiento = $stmt->fetchColumn() ?: 'No asignado';
}

$stmt = $pdo->prepare("
    SELECT u.nombre AS usuario_nombre, u.rol, c.id AS cliente_id, c.logo_url, c.nombre AS cliente_nombre
    FROM usuarios u
    INNER JOIN clientes_saas c ON u.cliente_id = c.id
    WHERE u.id = ?
");
$stmt->execute([$usuario_id]);
$datos = $stmt->fetch();
$_SESSION['usuario_rol'] = $datos['rol'];

require_once '../../includes/templates/header.php';

if (!in_array($datos['rol'], ['admin', 'superadmin'])) {
	echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
    <script>
        Swal.fire('Acceso denegado', 'Solo los administradores pueden ver esta sección.', 'error')
        .then(() => window.location.href = './dashboard');
    </script>";
	exit;
}

$cliente_id = $datos['cliente_id'];

$stmtClientes = $pdo->prepare("SELECT * FROM clientes_factura WHERE cliente_id = ? ORDER BY nombre ASC");
$stmtClientes->execute([$cliente_id]);
$clientes = $stmtClientes->fetchAll();
$total_clientes = count($clientes);
?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<?php if (isset($_GET['created'])): ?>
	<script>
		document.addEventListener('DOMContentLoaded', () => {
			Swal.fire({
					title: '¡Cliente creado!',
					text: 'El cliente se guardó correctamente.',
					icon: 'success',
					confirmButtonColor: '#4f46e5'
				})
				.then(() => window.history.replaceState(null, '', 'clientes'));
		});
	</script>
<?php elseif (isset($_GET['updated'])): ?>
	<script>
		document.addEventListener('DOMContentLoaded', () => {
			Swal.fire({
					title: '¡Actualizado!',
					text: 'Los cambios se guardaron correctamente.',
					icon: 'success',
					confirmButtonColor: '#4f46e5'
				})
				.then(() => window.history.replaceState(null, '', 'clientes'));
		});
	</script>
<?php elseif (isset($_GET['deleted'])): ?>
	<script>
		document.addEventListener('DOMContentLoaded', () => {
			Swal.fire({
					title: 'Cliente eliminado',
					text: 'El cliente ha sido eliminado.',
					icon: 'success',
					confirmButtonColor: '#4f46e5'
				})
				.then(() => window.history.replaceState(null, '', 'clientes'));
		});
	</script>
<?php elseif (isset($_GET['error'])): ?>
	<script>
		document.addEventListener('DOMContentLoaded', () => {
			Swal.fire({
					title: 'Error',
					text: decodeURIComponent("<?= htmlspecialchars($_GET['error']) ?>"),
					icon: 'error',
					confirmButtonColor: '#4f46e5'
				})
				.then(() => window.history.replaceState(null, '', 'clientes'));
		});
	</script>
<?php endif; ?>

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
		--surface: #ffffff;
		--surface-2: #f8fafc;
		--border: #e2e8f0;
		--text-main: #1e293b;
		--text-muted: #64748b;
		--shadow-sm: 0 1px 3px rgba(0, 0, 0, .06), 0 1px 2px rgba(0, 0, 0, .04);
		--shadow-md: 0 4px 16px rgba(0, 0, 0, .08);
		--radius: 14px;
		--radius-sm: 8px;
		--tr: .2s cubic-bezier(.4, 0, .2, 1);
	}

	.cf-page {
		padding: 1.5rem 0 3rem;
	}

	/* ── Header ── */
	.cf-header-card {
		background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%);
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

	.cf-header-card::before {
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

	.cf-header-card::after {
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

	.cf-header-title {
		font-size: 1.35rem;
		font-weight: 700;
		margin: 0;
	}

	.cf-header-sub {
		font-size: .82rem;
		opacity: .8;
		margin: .25rem 0 0;
	}

	.cf-header-logo {
		max-height: 52px;
		border-radius: 8px;
		background: rgba(255, 255, 255, .15);
		padding: 4px;
	}

	/* ── Stats ── */
	.cf-stats {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(155px, 1fr));
		gap: 1rem;
		margin-bottom: 1.5rem;
	}

	.cf-stat {
		background: var(--surface);
		border: 1px solid var(--border);
		border-radius: var(--radius);
		padding: 1.1rem 1.25rem;
		display: flex;
		align-items: center;
		gap: .9rem;
		box-shadow: var(--shadow-sm);
		transition: box-shadow var(--tr), transform var(--tr);
	}

	.cf-stat:hover {
		box-shadow: var(--shadow-md);
		transform: translateY(-2px);
	}

	.cf-stat-icon {
		width: 44px;
		height: 44px;
		border-radius: 10px;
		display: flex;
		align-items: center;
		justify-content: center;
		font-size: 1.25rem;
		flex-shrink: 0;
	}

	.cf-stat-icon.purple {
		background: var(--brand-light);
		color: var(--brand);
	}

	.cf-stat-icon.green {
		background: var(--success-bg);
		color: var(--success);
	}

	.cf-stat-icon.amber {
		background: #fffbeb;
		color: var(--warning);
	}

	.cf-stat-val {
		font-size: 1.5rem;
		font-weight: 700;
		color: var(--text-main);
		line-height: 1;
	}

	.cf-stat-lbl {
		font-size: .74rem;
		color: var(--text-muted);
		margin-top: 2px;
	}

	/* ── Toolbar ── */
	.cf-toolbar {
		display: flex;
		align-items: center;
		gap: .75rem;
		flex-wrap: wrap;
		margin-bottom: 1.25rem;
	}

	.cf-search-wrap {
		position: relative;
		flex: 1 1 220px;
		min-width: 200px;
	}

	.cf-search-wrap>i {
		position: absolute;
		left: .85rem;
		top: 50%;
		transform: translateY(-50%);
		color: var(--text-muted);
		font-size: .95rem;
		pointer-events: none;
	}

	.cf-search {
		width: 100%;
		padding: .55rem .85rem .55rem 2.4rem;
		border: 1.5px solid var(--border);
		border-radius: var(--radius-sm);
		font-size: .9rem;
		background: var(--surface);
		color: var(--text-main);
		transition: border-color var(--tr), box-shadow var(--tr);
		outline: none;
	}

	.cf-search:focus {
		border-color: var(--brand);
		box-shadow: 0 0 0 3px rgba(79, 70, 229, .12);
	}

	.cf-search::placeholder {
		color: #94a3b8;
	}

	.cf-clear-btn {
		position: absolute;
		right: .65rem;
		top: 50%;
		transform: translateY(-50%);
		background: none;
		border: none;
		color: var(--text-muted);
		font-size: 1rem;
		cursor: pointer;
		padding: 0;
		display: none;
		line-height: 1;
	}

	.cf-clear-btn.visible {
		display: block;
	}

	.btn-new {
		display: inline-flex;
		align-items: center;
		gap: .45rem;
		padding: .55rem 1.1rem;
		background: var(--brand);
		color: #fff !important;
		border-radius: var(--radius-sm);
		font-size: .88rem;
		font-weight: 600;
		text-decoration: none;
		border: none;
		cursor: pointer;
		white-space: nowrap;
		box-shadow: 0 2px 8px rgba(79, 70, 229, .25);
		transition: background var(--tr), box-shadow var(--tr), transform var(--tr);
	}

	.btn-new:hover {
		background: var(--brand-dark);
		transform: translateY(-1px);
		box-shadow: 0 4px 14px rgba(79, 70, 229, .35);
	}

	.cf-per-page {
		padding: .5rem .7rem;
		border: 1.5px solid var(--border);
		border-radius: var(--radius-sm);
		font-size: .85rem;
		background: var(--surface);
		color: var(--text-main);
		cursor: pointer;
		outline: none;
		transition: border-color var(--tr);
	}

	.cf-per-page:focus {
		border-color: var(--brand);
	}

	/* ── Card ── */
	.cf-card {
		background: var(--surface);
		border: 1px solid var(--border);
		border-radius: var(--radius);
		box-shadow: var(--shadow-sm);
		overflow: hidden;
	}

	.cf-card-header {
		padding: 1rem 1.5rem;
		border-bottom: 1px solid var(--border);
		display: flex;
		align-items: center;
		justify-content: space-between;
		background: var(--surface-2);
		gap: 1rem;
		flex-wrap: wrap;
	}

	.cf-card-title {
		font-weight: 700;
		font-size: .95rem;
		color: var(--text-main);
		display: flex;
		align-items: center;
		gap: .5rem;
	}

	.cf-result-badge {
		display: inline-flex;
		align-items: center;
		background: var(--brand-light);
		color: var(--brand);
		border-radius: 20px;
		padding: .15rem .65rem;
		font-size: .78rem;
		font-weight: 600;
	}

	/* ── Table ── */
	.cf-table-wrap {
		overflow-x: auto;
	}

	.cf-table {
		width: 100%;
		border-collapse: collapse;
		font-size: .875rem;
	}

	.cf-table thead th {
		padding: .75rem 1.1rem;
		background: var(--surface-2);
		color: var(--text-muted);
		font-weight: 600;
		font-size: .75rem;
		text-transform: uppercase;
		letter-spacing: .05em;
		border-bottom: 1px solid var(--border);
		white-space: nowrap;
		cursor: pointer;
		user-select: none;
		transition: background var(--tr), color var(--tr);
	}

	.cf-table thead th:last-child {
		cursor: default;
	}

	.cf-table thead th:hover:not(:last-child) {
		background: #e9edf5;
		color: var(--brand);
	}

	.cf-table thead th.sort-asc,
	.cf-table thead th.sort-desc {
		color: var(--brand);
		background: #e6e8ff;
	}

	.sort-icon {
		margin-left: .4rem;
		font-size: .7rem;
		opacity: .35;
		display: inline-block;
		transition: opacity .15s, transform .15s;
	}

	.cf-table thead th:hover:not(:last-child) .sort-icon {
		opacity: .7;
	}

	.cf-table thead th.sort-asc .sort-icon,
	.cf-table thead th.sort-desc .sort-icon {
		opacity: 1;
	}

	.cf-table thead th.sort-desc .sort-icon {
		transform: rotate(180deg);
	}

	.cf-table tbody tr {
		border-bottom: 1px solid var(--border);
		transition: background var(--tr);
	}

	.cf-table tbody tr:last-child {
		border-bottom: none;
	}

	.cf-table tbody tr:hover {
		background: var(--brand-light);
	}

	.cf-table tbody td {
		padding: .85rem 1.1rem;
		color: var(--text-main);
		vertical-align: middle;
	}

	.cf-highlight {
		background: #fef08a;
		border-radius: 3px;
		padding: 0 2px;
	}

	.rtn-badge {
		display: inline-block;
		background: #f1f5f9;
		border: 1px solid var(--border);
		border-radius: 6px;
		padding: .15rem .55rem;
		font-size: .78rem;
		font-family: 'Courier New', monospace;
		color: var(--text-muted);
		letter-spacing: .03em;
	}

	/* ── Action btns ── */
	.cf-actions {
		display: flex;
		gap: .4rem;
		align-items: center;
	}

	.btn-act {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		gap: .35rem;
		padding: .38rem .75rem;
		border-radius: var(--radius-sm);
		font-size: .8rem;
		font-weight: 600;
		cursor: pointer;
		border: 1.5px solid transparent;
		transition: all var(--tr);
		text-decoration: none;
		white-space: nowrap;
	}

	.btn-act-edit {
		background: var(--brand-light);
		color: var(--brand);
		border-color: rgba(79, 70, 229, .2);
	}

	.btn-act-edit:hover {
		background: var(--brand);
		color: #fff;
		border-color: var(--brand);
		transform: translateY(-1px);
	}

	.btn-act-del {
		background: var(--danger-bg);
		color: var(--danger);
		border-color: rgba(239, 68, 68, .2);
	}

	.btn-act-del:hover {
		background: var(--danger);
		color: #fff;
		border-color: var(--danger);
		transform: translateY(-1px);
	}

	/* ── Pagination ── */
	.cf-pagination {
		display: flex;
		align-items: center;
		justify-content: space-between;
		padding: .9rem 1.25rem;
		border-top: 1px solid var(--border);
		background: var(--surface-2);
		gap: .75rem;
		flex-wrap: wrap;
	}

	.cf-page-info {
		font-size: .8rem;
		color: var(--text-muted);
	}

	.cf-page-btns {
		display: flex;
		gap: .35rem;
		flex-wrap: wrap;
	}

	.page-btn {
		min-width: 34px;
		height: 34px;
		display: flex;
		align-items: center;
		justify-content: center;
		border-radius: var(--radius-sm);
		border: 1.5px solid var(--border);
		background: var(--surface);
		color: var(--text-muted);
		font-size: .82rem;
		font-weight: 600;
		cursor: pointer;
		transition: all var(--tr);
		padding: 0 .5rem;
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
		box-shadow: 0 2px 8px rgba(79, 70, 229, .3);
	}

	.page-btn.disabled {
		opacity: .35;
		cursor: not-allowed;
		pointer-events: none;
	}

	/* ── Empty state ── */
	.cf-empty {
		text-align: center;
		padding: 3.5rem 1rem;
		color: var(--text-muted);
	}

	.cf-empty-icon {
		font-size: 3rem;
		margin-bottom: .75rem;
		opacity: .35;
	}

	.cf-empty-text {
		font-size: 1rem;
		font-weight: 500;
	}

	.cf-empty-sub {
		font-size: .85rem;
		margin-top: .35rem;
	}

	/* ── Responsive ── */
	@media (max-width:640px) {
		.cf-header-card {
			padding: 1.1rem 1.25rem;
		}

		.cf-header-title {
			font-size: 1.1rem;
		}

		.cf-table thead th:nth-child(3),
		.cf-table tbody td:nth-child(3),
		.cf-table thead th:nth-child(5),
		.cf-table tbody td:nth-child(5) {
			display: none;
		}
	}
</style>

<div class="cf-page container">

	<!-- Header -->
	<div class="cf-header-card">
		<div>
			<h4 class="cf-header-title">📄 Clientes de Facturación</h4>
			<p class="cf-header-sub">
				Sucursal: <?= htmlspecialchars($nombre_establecimiento) ?> &nbsp;·&nbsp;
				Rol: <?= htmlspecialchars(ucfirst($datos['rol'])) ?> &nbsp;·&nbsp;
				<?= htmlspecialchars($datos['cliente_nombre']) ?>
			</p>
		</div>
		<?php if (!empty($datos['logo_url'])): ?>
			<img src="<?= htmlspecialchars($datos['logo_url']) ?>" alt="Logo" class="cf-header-logo">
		<?php endif; ?>
	</div>

	<!-- Stats strip -->
	<div class="cf-stats">
		<div class="cf-stat">
			<div class="cf-stat-icon purple"><i class="bi bi-people-fill"></i></div>
			<div>
				<div class="cf-stat-val" id="stat-total"><?= $total_clientes ?></div>
				<div class="cf-stat-lbl">Total clientes</div>
			</div>
		</div>
		<div class="cf-stat">
			<div class="cf-stat-icon green"><i class="bi bi-funnel-fill"></i></div>
			<div>
				<div class="cf-stat-val" id="stat-filtered"><?= $total_clientes ?></div>
				<div class="cf-stat-lbl">Resultados</div>
			</div>
		</div>
		<div class="cf-stat">
			<div class="cf-stat-icon amber"><i class="bi bi-layers-fill"></i></div>
			<div>
				<div class="cf-stat-val" id="stat-page">1</div>
				<div class="cf-stat-lbl">Página actual</div>
			</div>
		</div>
	</div>

	<!-- Toolbar -->
	<div class="cf-toolbar">
		<a href="crear_cliente" class="btn-new">
			<i class="bi bi-plus-lg"></i> Nuevo Cliente
		</a>
		<div class="cf-search-wrap">
			<i class="bi bi-search"></i>
			<input type="text" id="cfSearch" class="cf-search"
				placeholder="Buscar por nombre, RTN, dirección, teléfono o email…" autocomplete="off">
			<button class="cf-clear-btn" id="cfClear" title="Limpiar búsqueda">
				<i class="bi bi-x-lg"></i>
			</button>
		</div>
		<select class="cf-per-page" id="cfPerPage" title="Registros por página">
			<option value="10" selected>10 / pág</option>
			<option value="25">25 / pág</option>
			<option value="50">50 / pág</option>
			<option value="100">100 / pág</option>
		</select>
	</div>

	<!-- Table card -->
	<div class="cf-card">
		<div class="cf-card-header">
			<span class="cf-card-title"><i class="bi bi-table"></i> Lista de Clientes</span>
			<span class="cf-result-badge" id="resultBadge">
				<?= $total_clientes ?> registro<?= $total_clientes !== 1 ? 's' : '' ?>
			</span>
		</div>

		<div class="cf-table-wrap">
			<table class="cf-table" id="cfTable">
				<thead>
					<tr>
						<th data-col="0" title="Ordenar por Nombre">
							<i class="bi bi-person me-1"></i>Nombre<i class="bi bi-arrow-up sort-icon"></i>
						</th>
						<th data-col="1" title="Ordenar por RTN">
							<i class="bi bi-upc me-1"></i>RTN<i class="bi bi-arrow-up sort-icon"></i>
						</th>
						<th data-col="2" title="Ordenar por Dirección">
							<i class="bi bi-geo-alt me-1"></i>Dirección<i class="bi bi-arrow-up sort-icon"></i>
						</th>
						<th data-col="3" title="Ordenar por Teléfono">
							<i class="bi bi-telephone me-1"></i>Teléfono<i class="bi bi-arrow-up sort-icon"></i>
						</th>
						<th data-col="4" title="Ordenar por Email">
							<i class="bi bi-envelope me-1"></i>Email<i class="bi bi-arrow-up sort-icon"></i>
						</th>
						<th style="text-align:center;cursor:default;">
							<i class="bi bi-gear me-1"></i>Acciones
						</th>
					</tr>
				</thead>
				<tbody id="cfBody">
					<?php foreach ($clientes as $c): ?>
						<tr data-search="<?= strtolower(htmlspecialchars(
												$c['nombre'] . ' ' . $c['rtn'] . ' ' . ($c['direccion'] ?? '') . ' ' . ($c['telefono'] ?? '') . ' ' . ($c['email'] ?? '')
											)) ?>">
							<td><span class="fw-semibold" data-col="nombre"><?= htmlspecialchars($c['nombre']) ?></span>
							</td>
							<td><span class="rtn-badge" data-col="rtn"><?= htmlspecialchars($c['rtn']) ?></span></td>
							<td data-col="direccion"><?= htmlspecialchars($c['direccion'] ?? '—') ?></td>
							<td data-col="telefono"><?= htmlspecialchars($c['telefono'] ?? '—') ?></td>
							<td data-col="email"><?= htmlspecialchars($c['email'] ?? '—') ?></td>
							<td>
								<div class="cf-actions" style="justify-content:center;">
									<a href="editar_cliente?id=<?= $c['id'] ?>" class="btn-act btn-act-edit">
										<i class="bi bi-pencil-fill"></i>
										<span class="d-none d-md-inline">Editar</span>
									</a>
									<form method="POST" action="eliminar_cliente" style="display:inline;"
										onsubmit="return cfConfirmarElim(event,this);">
										<input type="hidden" name="id" value="<?= $c['id'] ?>">
										<button type="submit" class="btn-act btn-act-del">
											<i class="bi bi-trash3-fill"></i>
											<span class="d-none d-md-inline">Eliminar</span>
										</button>
									</form>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<div class="cf-empty" id="cfEmpty" style="display:none;">
				<div class="cf-empty-icon"><i class="bi bi-person-x"></i></div>
				<div class="cf-empty-text">Sin resultados</div>
				<div class="cf-empty-sub" id="cfEmptySub">No se encontraron clientes que coincidan con tu búsqueda.
				</div>
			</div>
		</div>

		<div class="cf-pagination">
			<span class="cf-page-info" id="cfPageInfo">Mostrando 1–10 de <?= $total_clientes ?></span>
			<div class="cf-page-btns" id="cfPageBtns"></div>
		</div>
	</div>

</div>

<script>
	(() => {
		/* ── State ── */
		let query = '';
		let page = 1;
		let perPage = 10; // ← default 10
		let sortCol = -1;
		let sortDir = 'asc';

		const allRows = Array.from(document.querySelectorAll('#cfBody tr'));
		const $search = document.getElementById('cfSearch');
		const $clear = document.getElementById('cfClear');
		const $perPage = document.getElementById('cfPerPage');
		const $empty = document.getElementById('cfEmpty');
		const $emptySub = document.getElementById('cfEmptySub');
		const $info = document.getElementById('cfPageInfo');
		const $btns = document.getElementById('cfPageBtns');
		const $badge = document.getElementById('resultBadge');
		const $statF = document.getElementById('stat-filtered');
		const $statP = document.getElementById('stat-page');
		const headers = document.querySelectorAll('#cfTable thead th[data-col]');

		/* ── Highlight ── */
		function highlight(text, q) {
			if (!q) return text;
			return text.replace(
				new RegExp(`(${q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')})`, 'gi'),
				'<mark class="cf-highlight">$1</mark>'
			);
		}

		/* ── Sort helper ── */
		function colText(row, idx) {
			const td = row.querySelectorAll('td')[idx];
			return td ? (td.dataset.original || td.textContent).trim().toLowerCase() : '';
		}

		function getSorted(rows) {
			if (sortCol < 0) return rows;
			return [...rows].sort((a, b) => {
				const va = colText(a, sortCol),
					vb = colText(b, sortCol);
				return sortDir === 'asc' ? va.localeCompare(vb, 'es') : vb.localeCompare(va, 'es');
			});
		}

		function updateHeaderIcons() {
			headers.forEach(th => {
				const idx = parseInt(th.dataset.col, 10);
				th.classList.remove('sort-asc', 'sort-desc');
				if (idx === sortCol) th.classList.add(sortDir === 'asc' ? 'sort-asc' : 'sort-desc');
			});
		}

		/* ── Filter ── */
		function getFiltered() {
			const base = !query ? allRows :
				allRows.filter(r => r.dataset.search.includes(query.toLowerCase()));
			return getSorted(base);
		}

		/* ── Render ── */
		function render() {
			const filtered = getFiltered();
			const total = filtered.length;
			const totalPg = Math.max(1, Math.ceil(total / perPage));
			if (page > totalPg) page = totalPg;

			const start = (page - 1) * perPage;
			const end = Math.min(start + perPage, total);
			const slice = filtered.slice(start, end);

			allRows.forEach(r => r.style.display = 'none');

			if (total === 0) {
				$empty.style.display = 'block';
				$emptySub.textContent = query ?
					`No se encontraron clientes para "${query}".` :
					'No hay clientes registrados aún.';
			} else {
				$empty.style.display = 'none';
				slice.forEach(r => {
					r.style.display = '';
					r.querySelectorAll('[data-col]').forEach(cell => {
						const orig = cell.dataset.original ?? cell.textContent;
						cell.dataset.original = orig;
						cell.innerHTML = highlight(orig, query);
					});
				});
			}

			$badge.textContent = `${total} registro${total !== 1 ? 's' : ''}`;
			$statF.textContent = total;
			$statP.textContent = page;
			$info.textContent = total === 0 ? 'Sin resultados' :
				`Mostrando ${start + 1}–${end} de ${total}`;

			buildPagination(page, totalPg);
		}

		/* ── Pagination buttons ── */
		function buildPagination(cur, total) {
			$btns.innerHTML = '';
			if (total <= 1) return;

			const make = (html, p, cls = '') => {
				const btn = document.createElement('button');
				btn.className = `page-btn ${cls}`;
				btn.innerHTML = html;
				if (!cls.includes('disabled') && !cls.includes('active'))
					btn.addEventListener('click', () => {
						page = p;
						render();
					});
				$btns.appendChild(btn);
			};

			make('<i class="bi bi-chevron-double-left"></i>', 1, cur === 1 ? 'disabled' : '');
			make('<i class="bi bi-chevron-left"></i>', cur - 1, cur === 1 ? 'disabled' : '');

			const delta = 2;
			let pages = new Set([1, total]);
			for (let i = Math.max(2, cur - delta); i <= Math.min(total - 1, cur + delta); i++) pages.add(i);
			pages = [...pages].sort((a, b) => a - b);

			let prev = 0;
			pages.forEach(pg => {
				if (pg - prev > 1) {
					const d = document.createElement('button');
					d.className = 'page-btn disabled';
					d.textContent = '…';
					$btns.appendChild(d);
				}
				make(pg, pg, pg === cur ? 'active' : '');
				prev = pg;
			});

			make('<i class="bi bi-chevron-right"></i>', cur + 1, cur === total ? 'disabled' : '');
			make('<i class="bi bi-chevron-double-right"></i>', total, cur === total ? 'disabled' : '');
		}

		/* ── Column sort ── */
		headers.forEach(th => {
			th.addEventListener('click', () => {
				const idx = parseInt(th.dataset.col, 10);
				sortDir = (sortCol === idx && sortDir === 'asc') ? 'desc' : 'asc';
				sortCol = idx;
				page = 1;
				updateHeaderIcons();
				render();
			});
		});

		/* ── Search ── */
		let debounce;
		$search.addEventListener('input', () => {
			clearTimeout(debounce);
			debounce = setTimeout(() => {
				query = $search.value.trim();
				page = 1;
				$clear.classList.toggle('visible', query.length > 0);
				render();
			}, 180);
		});

		$clear.addEventListener('click', () => {
			$search.value = '';
			query = '';
			page = 1;
			$clear.classList.remove('visible');
			render();
			$search.focus();
		});

		$perPage.addEventListener('change', () => {
			perPage = parseInt($perPage.value, 10);
			page = 1;
			render();
		});

		/* ── Delete confirm (SweetAlert2) ── */
		window.cfConfirmarElim = (e, form) => {
			e.preventDefault();
			Swal.fire({
				title: '¿Eliminar cliente?',
				text: 'Esta acción no se puede deshacer.',
				icon: 'warning',
				showCancelButton: true,
				confirmButtonColor: '#ef4444',
				cancelButtonColor: '#6b7280',
				confirmButtonText: '<i class="bi bi-trash3-fill me-1"></i> Sí, eliminar',
				cancelButtonText: 'Cancelar',
				reverseButtons: true
			}).then(r => {
				if (r.isConfirmed) form.submit();
			});
			return false;
		};

		/* ── Init ── */
		updateHeaderIcons();
		render();
	})();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>