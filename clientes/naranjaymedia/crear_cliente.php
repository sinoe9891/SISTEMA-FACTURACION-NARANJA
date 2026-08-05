<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

$usuario_id = $_SESSION['usuario_id'];

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

if (!in_array($datos['rol'], ['admin', 'superadmin'])) {
	echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
    <script>
        Swal.fire('Acceso denegado', 'Solo administradores pueden agregar clientes.', 'error')
        .then(() => window.location.href = 'clientes');
    </script>";
	exit;
}

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

<style>
	:root {
		--brand: #4f46e5;
		--brand-light: #eef2ff;
		--brand-dark: #3730a3;
		--success: #10b981;
		--success-bg: #ecfdf5;
		--danger: #ef4444;
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
		background: var(--brand-light);
		color: var(--brand);
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
		border-color: var(--brand);
		box-shadow: 0 0 0 3px rgba(79, 70, 229, .12);
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

	/* ── Divider ── */
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

	/* ── Footer actions ── */
	.cf-form-footer {
		padding: 1.1rem 1.75rem;
		border-top: 1px solid var(--border);
		background: var(--surface-2);
		display: flex;
		align-items: center;
		gap: .75rem;
		flex-wrap: wrap;
		justify-content: flex-end;
	}

	.btn-save {
		display: inline-flex;
		align-items: center;
		gap: .45rem;
		padding: .6rem 1.4rem;
		background: var(--brand);
		color: #fff;
		border-radius: var(--radius-sm);
		font-size: .9rem;
		font-weight: 600;
		border: none;
		cursor: pointer;
		white-space: nowrap;
		box-shadow: 0 2px 8px rgba(79, 70, 229, .25);
		transition: background var(--tr), box-shadow var(--tr), transform var(--tr);
	}

	.btn-save:hover {
		background: var(--brand-dark);
		transform: translateY(-1px);
		box-shadow: 0 4px 14px rgba(79, 70, 229, .35);
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

	/* ── RTN input mask hint ── */
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
			justify-content: stretch;
		}

		.btn-save,
		.btn-cancel {
			flex: 1;
			justify-content: center;
		}
	}
</style>

<div class="cf-page container">

	<!-- Header -->
	<div class="cf-header-card">
		<div>
			<h4 class="cf-header-title">➕ Crear Nuevo Cliente</h4>
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

	<!-- Form card -->
	<div class="cf-form-card">
		<div class="cf-form-card-header">
			<div class="cf-form-card-header-icon"><i class="bi bi-person-plus-fill"></i></div>
			<h5 class="cf-form-card-title">Datos del cliente de facturación</h5>
		</div>

		<form method="POST" action="guardar_cliente" id="cfForm" novalidate>
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
							placeholder="Ej: Empresa XYZ, S.A. de C.V." required autocomplete="organization">
						<span class="cf-field-hint">Nombre completo tal como aparece en los documentos legales.</span>
					</div>

					<!-- RTN -->
					<div class="cf-field">
						<label class="cf-label" for="rtn">
							<i class="bi bi-upc"></i> RTN <span class="req">*</span>
						</label>
						<input type="text" id="rtn" name="rtn" class="cf-input" placeholder="0000-0000-000000" required
							maxlength="20" autocomplete="off">
						<span class="cf-field-hint">Formato: <span class="rtn-sample">0501-1990-00001</span></span>
					</div>

					<!-- Teléfono -->
					<div class="cf-field">
						<label class="cf-label" for="telefono">
							<i class="bi bi-telephone"></i> Teléfono
						</label>
						<input type="tel" id="telefono" name="telefono" class="cf-input"
							placeholder="Ej: 2222-3344 / 9999-8877" autocomplete="tel">
					</div>

				</div>

				<!-- Sección: Contacto & ubicación -->
				<div class="cf-section-label" style="margin-top:1.25rem;"><i class="bi bi-envelope me-1"></i>Contacto y
					ubicación</div>

				<div class="cf-field-grid" style="margin-top:.9rem;">

					<!-- Contacto -->
					<div class="cf-field">
						<label class="cf-label" for="contacto_nombre">
							<i class="bi bi-person-lines-fill"></i> Contacto
						</label>
						<input type="text" id="contacto_nombre" name="contacto_nombre" class="cf-input"
							placeholder="Ej: Kevin" autocomplete="off">
						<span class="cf-field-hint">Nombre de la persona a quien se dirigen los correos (ej. "Buen día,
							Kevin"). Si se deja vacío, se usará "Estimado equipo de [Cliente]".</span>
					</div>

					<!-- Email -->
					<div class="cf-field">
						<label class="cf-label" for="email">
							<i class="bi bi-envelope"></i> Correo electrónico
						</label>
						<input type="email" id="email" name="email" class="cf-input" placeholder="correo@empresa.com"
							autocomplete="email">
					</div>

					<!-- Dirección -->
					<div class="cf-field cf-field-full">
						<label class="cf-label" for="direccion">
							<i class="bi bi-geo-alt"></i> Dirección <span class="req">*</span>
						</label>
						<textarea id="direccion" name="direccion" class="cf-textarea" required
							placeholder="Colonia, calle, número, ciudad, departamento…"></textarea>
					</div>

				</div>

			</div><!-- /cf-form-body -->

			<div class="cf-form-footer">
				<a href="clientes" class="btn-cancel">
					<i class="bi bi-arrow-left"></i> Cancelar
				</a>
				<button type="submit" class="btn-save" id="cfSubmit">
					<i class="bi bi-floppy-fill"></i> Guardar Cliente
				</button>
			</div>
		</form>
	</div>

</div>

<script>
	/* ── Client-side validation + loading state ── */
	document.getElementById('cfForm').addEventListener('submit', function(e) {
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
				confirmButtonColor: '#4f46e5'
			});
			return;
		}

		/* loading state */
		const btn = document.getElementById('cfSubmit');
		btn.disabled = true;
		btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span> Guardando…';
	});

	/* remove invalid on input */
	document.querySelectorAll('.cf-input,.cf-textarea').forEach(el => {
		el.addEventListener('input', () => el.classList.remove('is-invalid'));
	});

	/* RTN auto-format: digits only → insert dashes on blur */
	document.getElementById('rtn').addEventListener('blur', function() {
		let v = this.value.replace(/\D/g, '');
		if (v.length === 13)
			this.value = v.slice(0, 4) + '-' + v.slice(4, 8) + '-' + v.slice(8);
	});
</script>

<?php require_once '../../includes/templates/footer.php'; ?>