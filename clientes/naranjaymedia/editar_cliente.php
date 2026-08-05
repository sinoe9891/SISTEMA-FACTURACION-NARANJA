<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

$usuario_id = $_SESSION['usuario_id'];

// ── Datos del usuario/cliente ─────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT u.rol, c.id AS cliente_id, c.nombre AS cliente_nombre, c.logo_url
    FROM usuarios u
    INNER JOIN clientes_saas c ON u.cliente_id = c.id
    WHERE u.id = ?
");
$stmt->execute([$usuario_id]);
$datos = $stmt->fetch();
$cliente_id = $datos['cliente_id'];
$_SESSION['usuario_rol'] = $datos['rol'];

require_once '../../includes/templates/header.php';

// ── Permisos ──────────────────────────────────────────────────────────────────
if (!in_array($datos['rol'], ['admin', 'superadmin'])) {
	echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
    <script>
        Swal.fire('Acceso denegado', 'Solo administradores pueden editar clientes.', 'error')
        .then(() => window.location.href = 'clientes');
    </script>";
	exit;
}

// ── Validar ID ────────────────────────────────────────────────────────────────
if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) {
	echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            Swal.fire('ID inválido', 'No se pudo cargar el cliente.', 'error')
            .then(() => window.location.href = 'clientes');
        });
    </script>";
	exit;
}

$cliente_id_factura = (int) $_GET['id'];

$stmt = $pdo->prepare("SELECT * FROM clientes_factura WHERE id = ? AND cliente_id = ?");
$stmt->execute([$cliente_id_factura, $cliente_id]);
$cliente = $stmt->fetch();

if (!$cliente) {
	echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            Swal.fire('No encontrado', 'El cliente no existe o no pertenece a tu cuenta.', 'error')
            .then(() => window.location.href = 'clientes');
        });
    </script>";
	exit;
}

// ── Establecimiento activo ────────────────────────────────────────────────────
$establecimiento_activo = $_SESSION['establecimiento_activo'] ?? null;
$nombre_establecimiento = 'No asignado';
if ($establecimiento_activo) {
	$stmt = $pdo->prepare("SELECT nombre FROM establecimientos WHERE establecimiento_id = ?");
	$stmt->execute([$establecimiento_activo]);
	$nombre_establecimiento = $stmt->fetchColumn() ?: 'No asignado';
}
?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<?php if (isset($_GET['updated'])): ?>
	<script>
		document.addEventListener('DOMContentLoaded', () => {
			Swal.fire({
					title: '¡Actualizado!',
					text: 'Los cambios se guardaron correctamente.',
					icon: 'success',
					confirmButtonColor: '#4f46e5'
				})
				.then(() => window.history.replaceState(null, '', location.pathname +
					'?id=<?= $cliente_id_factura ?>'));
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
			});
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
		--warning: #f59e0b;
		--warning-bg: #fffbeb;
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
		background: linear-gradient(135deg, #0f766e 0%, #0d5c56 100%);
		/* teal para diferenciar de crear */
		border-radius: var(--radius);
		padding: 1.5rem 2rem;
		color: #fff;
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 1rem;
		margin-bottom: 1.75rem;
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

	/* ── Info strip (client name badge) ── */
	.cf-client-strip {
		display: flex;
		align-items: center;
		gap: .65rem;
		background: var(--warning-bg);
		border: 1px solid #fde68a;
		border-radius: var(--radius-sm);
		padding: .6rem 1rem;
		margin-bottom: 1.5rem;
		font-size: .875rem;
	}

	.cf-client-strip-icon {
		width: 32px;
		height: 32px;
		border-radius: 8px;
		background: #fef3c7;
		color: var(--warning);
		display: flex;
		align-items: center;
		justify-content: center;
		font-size: 1rem;
		flex-shrink: 0;
	}

	.cf-client-strip strong {
		color: var(--text-main);
	}

	.cf-client-strip span {
		color: var(--text-muted);
		font-size: .82rem;
	}

	/* ── Form card ── */
	.cf-form-card {
		background: var(--surface);
		border: 1px solid var(--border);
		border-radius: var(--radius);
		box-shadow: var(--shadow-sm);
		overflow: hidden;
	}

	.cf-form-card-header {
		padding: 1rem 1.5rem;
		border-bottom: 1px solid var(--border);
		background: var(--surface-2);
		display: flex;
		align-items: center;
		gap: .6rem;
	}

	.cf-form-card-header-icon {
		width: 34px;
		height: 34px;
		border-radius: 8px;
		background: #ccfbf1;
		color: #0f766e;
		display: flex;
		align-items: center;
		justify-content: center;
		font-size: 1rem;
		flex-shrink: 0;
	}

	.cf-form-card-title {
		font-weight: 700;
		font-size: .95rem;
		color: var(--text-main);
		margin: 0;
	}

	.cf-client-id-badge {
		margin-left: auto;
		background: var(--surface);
		border: 1px solid var(--border);
		border-radius: 20px;
		padding: .15rem .65rem;
		font-size: .75rem;
		color: var(--text-muted);
		font-family: 'Courier New', monospace;
	}

	.cf-form-body {
		padding: 1.75rem;
	}

	/* ── Field groups ── */
	.cf-field-grid {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
		gap: 1.1rem;
	}

	.cf-field-full {
		grid-column: 1/-1;
	}

	.cf-field {
		display: flex;
		flex-direction: column;
		gap: .35rem;
	}

	.cf-label {
		font-size: .8rem;
		font-weight: 600;
		color: var(--text-muted);
		text-transform: uppercase;
		letter-spacing: .04em;
		display: flex;
		align-items: center;
		gap: .35rem;
	}

	.cf-label .req {
		color: var(--danger);
		font-size: .85rem;
	}

	.cf-input,
	.cf-textarea {
		padding: .6rem .85rem;
		border: 1.5px solid var(--border);
		border-radius: var(--radius-sm);
		font-size: .9rem;
		color: var(--text-main);
		background: var(--surface);
		transition: border-color var(--tr), box-shadow var(--tr);
		outline: none;
		width: 100%;
	}

	.cf-input:focus,
	.cf-textarea:focus {
		border-color: #0f766e;
		box-shadow: 0 0 0 3px rgba(15, 118, 110, .12);
	}

	.cf-input::placeholder,
	.cf-textarea::placeholder {
		color: #94a3b8;
	}

	.cf-input.is-invalid,
	.cf-textarea.is-invalid {
		border-color: var(--danger);
		box-shadow: 0 0 0 3px rgba(239, 68, 68, .1);
	}

	.cf-textarea {
		resize: vertical;
		min-height: 80px;
	}

	.cf-field-hint {
		font-size: .75rem;
		color: var(--text-muted);
		margin-top: .1rem;
	}

	/* ── Section divider ── */
	.cf-section-label {
		font-size: .72rem;
		font-weight: 700;
		color: var(--text-muted);
		text-transform: uppercase;
		letter-spacing: .08em;
		padding: .5rem 0 .25rem;
		border-top: 1px solid var(--border);
		margin-top: .5rem;
		display: flex;
		align-items: center;
		gap: .5rem;
	}

	.cf-section-label::after {
		content: '';
		flex: 1;
		height: 1px;
		background: var(--border);
	}

	/* ── Changed indicator ── */
	.cf-input.changed,
	.cf-textarea.changed {
		border-color: #0f766e;
		background: #f0fdf4;
	}

	/* ── Footer ── */
	.cf-form-footer {
		padding: 1.1rem 1.75rem;
		border-top: 1px solid var(--border);
		background: var(--surface-2);
		display: flex;
		align-items: center;
		gap: .75rem;
		flex-wrap: wrap;
		justify-content: space-between;
	}

	.cf-footer-left {
		display: flex;
		align-items: center;
		gap: .5rem;
	}

	.cf-footer-right {
		display: flex;
		align-items: center;
		gap: .75rem;
	}

	.btn-update {
		display: inline-flex;
		align-items: center;
		gap: .45rem;
		padding: .6rem 1.4rem;
		background: #0f766e;
		color: #fff;
		border-radius: var(--radius-sm);
		font-size: .9rem;
		font-weight: 600;
		border: none;
		cursor: pointer;
		white-space: nowrap;
		box-shadow: 0 2px 8px rgba(15, 118, 110, .25);
		transition: background var(--tr), box-shadow var(--tr), transform var(--tr);
	}

	.btn-update:hover {
		background: #0d5c56;
		transform: translateY(-1px);
		box-shadow: 0 4px 14px rgba(15, 118, 110, .35);
	}

	.btn-cancel {
		display: inline-flex;
		align-items: center;
		gap: .45rem;
		padding: .6rem 1.2rem;
		background: var(--surface);
		color: var(--text-muted);
		border: 1.5px solid var(--border);
		border-radius: var(--radius-sm);
		font-size: .9rem;
		font-weight: 600;
		text-decoration: none;
		cursor: pointer;
		transition: all var(--tr);
	}

	.btn-cancel:hover {
		border-color: var(--text-muted);
		color: var(--text-main);
		background: #f1f5f9;
	}

	.btn-reset {
		display: inline-flex;
		align-items: center;
		gap: .4rem;
		padding: .55rem 1rem;
		background: transparent;
		color: var(--text-muted);
		border: 1.5px solid var(--border);
		border-radius: var(--radius-sm);
		font-size: .82rem;
		font-weight: 600;
		cursor: pointer;
		transition: all var(--tr);
	}

	.btn-reset:hover {
		border-color: #f59e0b;
		color: #b45309;
		background: #fffbeb;
	}

	/* ── Changed fields counter ── */
	.changes-pill {
		display: none;
		align-items: center;
		gap: .4rem;
		background: #ecfdf5;
		border: 1px solid #a7f3d0;
		color: #065f46;
		border-radius: 20px;
		padding: .2rem .7rem;
		font-size: .78rem;
		font-weight: 600;
	}

	.changes-pill.visible {
		display: inline-flex;
	}

	/* ── RTN badge ── */
	.rtn-sample {
		font-family: 'Courier New', monospace;
		font-size: .78rem;
		background: #f1f5f9;
		border: 1px solid var(--border);
		border-radius: 5px;
		padding: .1rem .45rem;
		color: var(--text-muted);
	}

	@media (max-width:640px) {
		.cf-header-card {
			padding: 1.1rem 1.25rem;
		}

		.cf-header-title {
			font-size: 1.1rem;
		}

		.cf-form-body {
			padding: 1.25rem;
		}

		.cf-form-footer {
			padding: .9rem 1.25rem;
			flex-direction: column;
			align-items: stretch;
		}

		.cf-footer-left,
		.cf-footer-right {
			justify-content: center;
		}

		.btn-update,
		.btn-cancel,
		.btn-reset {
			flex: 1;
			justify-content: center;
		}
	}
</style>

<div class="cf-page container">

	<!-- Header -->
	<div class="cf-header-card">
		<div>
			<h4 class="cf-header-title">✏️ Editar Cliente</h4>
			<p class="cf-header-sub">
				Sucursal: <?= htmlspecialchars($nombre_establecimiento) ?> &nbsp;·&nbsp;
				Rol: <?= htmlspecialchars(ucfirst($datos['rol'])) ?> &nbsp;·&nbsp;
				<?= htmlspecialchars($datos['cliente_nombre']) ?>
			</p>
		</div>
		<?php if (!empty($datos['logo_url'])): ?>
			<img src="https://www.naranjaymediahn.com/wp-content/uploads/2023/06/logo-naranja.svg" alt="Logo" class="cf-header-logo">
		<?php endif; ?>
	</div>

	<!-- Client identity strip -->
	<div class="cf-client-strip">
		<div class="cf-client-strip-icon"><i class="bi bi-person-fill"></i></div>
		<div>
			<strong><?= htmlspecialchars($cliente['nombre']) ?></strong>
			<span>&nbsp;·&nbsp; RTN: <?= htmlspecialchars($cliente['rtn']) ?></span>
		</div>
		<span class="ms-auto" style="font-size:.75rem; color:var(--text-muted);">
			ID #<?= $cliente_id_factura ?>
		</span>
	</div>

	<!-- Form card -->
	<div class="cf-form-card">
		<div class="cf-form-card-header">
			<div class="cf-form-card-header-icon"><i class="bi bi-pencil-square"></i></div>
			<h5 class="cf-form-card-title">Modificar datos del cliente</h5>
			<span class="cf-client-id-badge">id: <?= $cliente_id_factura ?></span>
		</div>

		<form method="POST" action="actualizar_cliente" id="cfEditForm" novalidate>
			<input type="hidden" name="id" value="<?= $cliente['id'] ?>">

			<div class="cf-form-body">

				<!-- Sección: Info principal -->
				<div class="cf-section-label"><i class="bi bi-person-badge me-1"></i>Información principal</div>

				<div class="cf-field-grid" style="margin-top:.9rem;">

					<!-- Nombre -->
					<div class="cf-field cf-field-full">
						<label class="cf-label" for="nombre">
							<i class="bi bi-person"></i> Nombre o Razón Social <span class="req">*</span>
						</label>
						<input type="text" id="nombre" name="nombre" class="cf-input"
							value="<?= htmlspecialchars($cliente['nombre']) ?>"
							placeholder="Ej: Empresa XYZ, S.A. de C.V." required autocomplete="organization"
							data-original="<?= htmlspecialchars($cliente['nombre']) ?>">
						<span class="cf-field-hint">Nombre completo tal como aparece en los documentos legales.</span>
					</div>

					<!-- RTN -->
					<div class="cf-field">
						<label class="cf-label" for="rtn">
							<i class="bi bi-upc"></i> RTN <span class="req">*</span>
						</label>
						<input type="text" id="rtn" name="rtn" class="cf-input"
							value="<?= htmlspecialchars($cliente['rtn']) ?>" placeholder="0000-0000-000000" required
							maxlength="20" autocomplete="off" data-original="<?= htmlspecialchars($cliente['rtn']) ?>">
						<span class="cf-field-hint">Formato: <span class="rtn-sample">0501-1990-00001</span></span>
					</div>

					<!-- Teléfono -->
					<div class="cf-field">
						<label class="cf-label" for="telefono">
							<i class="bi bi-telephone"></i> Teléfono
						</label>
						<input type="tel" id="telefono" name="telefono" class="cf-input"
							value="<?= htmlspecialchars($cliente['telefono'] ?? '') ?>"
							placeholder="Ej: 2222-3344 / 9999-8877" autocomplete="tel"
							data-original="<?= htmlspecialchars($cliente['telefono'] ?? '') ?>">
					</div>

				</div>

				<!-- Sección: Contacto & ubicación -->
				<div class="cf-section-label" style="margin-top:1.25rem;">
					<i class="bi bi-envelope me-1"></i>Contacto y ubicación
				</div>

				<div class="cf-field-grid" style="margin-top:.9rem;">

					<!-- Contacto -->
					<div class="cf-field">
						<label class="cf-label" for="contacto_nombre">
							<i class="bi bi-person-lines-fill"></i> Contacto
						</label>
						<input type="text" id="contacto_nombre" name="contacto_nombre" class="cf-input"
							value="<?= htmlspecialchars($cliente['contacto_nombre'] ?? '') ?>" placeholder="Ej: Kevin"
							autocomplete="off">
						<span class="cf-field-hint">Nombre de la persona a quien se dirigen los correos. Si se deja vacío,
							se usará "Estimado equipo de [Cliente]".</span>
					</div>

					<!-- Email -->
					<div class="cf-field">
						<label class="cf-label" for="email">
							<i class="bi bi-envelope"></i> Correo electrónico
						</label>
						<input type="email" id="email" name="email" class="cf-input"
							value="<?= htmlspecialchars($cliente['email'] ?? '') ?>" placeholder="correo@empresa.com"
							autocomplete="email" data-original="<?= htmlspecialchars($cliente['email'] ?? '') ?>">
					</div>

					<!-- Dirección -->
					<div class="cf-field cf-field-full">
						<label class="cf-label" for="direccion">
							<i class="bi bi-geo-alt"></i> Dirección <span class="req">*</span>
						</label>
						<textarea id="direccion" name="direccion" class="cf-textarea" required
							placeholder="Colonia, calle, número, ciudad, departamento…"
							data-original="<?= htmlspecialchars($cliente['direccion']) ?>"><?= htmlspecialchars($cliente['direccion']) ?></textarea>
					</div>

				</div>

			</div><!-- /cf-form-body -->

			<div class="cf-form-footer">
				<div class="cf-footer-left">
					<span class="changes-pill" id="changesPill">
						<i class="bi bi-pencil-fill"></i>
						<span id="changesCount">0</span> campo(s) modificado(s)
					</span>
					<button type="button" class="btn-reset" id="cfReset" style="display:none;"
						title="Deshacer todos los cambios">
						<i class="bi bi-arrow-counterclockwise"></i> Restaurar
					</button>
				</div>
				<div class="cf-footer-right">
					<a href="clientes" class="btn-cancel">
						<i class="bi bi-arrow-left"></i> Cancelar
					</a>
					<button type="submit" class="btn-update" id="cfSubmit">
						<i class="bi bi-floppy-fill"></i> Actualizar Cliente
					</button>
				</div>
			</div>
		</form>
	</div>

</div>

<script>
	/* ── Track changes ────────────────────────────────────────────────────────── */
	const fields = document.querySelectorAll('.cf-input[data-original], .cf-textarea[data-original]');
	const $pill = document.getElementById('changesPill');
	const $count = document.getElementById('changesCount');
	const $resetBtn = document.getElementById('cfReset');

	function countChanges() {
		let n = 0;
		fields.forEach(f => {
			const changed = f.value.trim() !== (f.dataset.original || '').trim();
			f.classList.toggle('changed', changed);
			if (changed) n++;
		});
		$count.textContent = n;
		$pill.classList.toggle('visible', n > 0);
		$resetBtn.style.display = n > 0 ? '' : 'none';
	}

	fields.forEach(f => {
		f.addEventListener('input', () => {
			f.classList.remove('is-invalid');
			countChanges();
		});
	});

	/* ── Reset / restore ─────────────────────────────────────────────────────── */
	$resetBtn.addEventListener('click', () => {
		Swal.fire({
			title: '¿Restaurar campos?',
			text: 'Se revertirán todos los cambios realizados.',
			icon: 'question',
			showCancelButton: true,
			confirmButtonText: '<i class="bi bi-arrow-counterclockwise me-1"></i> Restaurar',
			cancelButtonText: 'Seguir editando',
			confirmButtonColor: '#f59e0b',
			cancelButtonColor: '#6b7280',
		}).then(r => {
			if (!r.isConfirmed) return;
			fields.forEach(f => {
				f.value = f.dataset.original || '';
				f.classList.remove('changed', 'is-invalid');
			});
			countChanges();
		});
	});

	/* ── Validation + submit ─────────────────────────────────────────────────── */
	document.getElementById('cfEditForm').addEventListener('submit', function(e) {
		let valid = true;
		['nombre', 'rtn', 'direccion'].forEach(id => {
			const el = document.getElementById(id);
			el.classList.remove('is-invalid');
			if (!el.value.trim()) {
				el.classList.add('is-invalid');
				valid = false;
			}
		});

		if (!valid) {
			e.preventDefault();
			Swal.fire({
				title: 'Campos requeridos',
				text: 'Por favor completa Nombre, RTN y Dirección antes de continuar.',
				icon: 'warning',
				confirmButtonColor: '#0f766e'
			});
			return;
		}

		/* loading state */
		const btn = document.getElementById('cfSubmit');
		btn.disabled = true;
		btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span> Guardando…';
	});

	/* ── RTN auto-format ─────────────────────────────────────────────────────── */
	document.getElementById('rtn').addEventListener('blur', function() {
		let v = this.value.replace(/\D/g, '');
		if (v.length === 13)
			this.value = v.slice(0, 4) + '-' + v.slice(4, 8) + '-' + v.slice(8);
		countChanges();
	});

	/* ── Init ────────────────────────────────────────────────────────────────── */
	countChanges();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>