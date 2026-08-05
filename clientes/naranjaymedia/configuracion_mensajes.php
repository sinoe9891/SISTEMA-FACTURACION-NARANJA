<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

$usuario_id = $_SESSION['usuario_id'];

$stmt = $pdo->prepare("SELECT rol, cliente_id FROM usuarios WHERE id = ?");
$stmt->execute([$usuario_id]);
$usuario = $stmt->fetch();

if (!$usuario || !in_array($usuario['rol'], ['admin', 'superadmin'])) {
	echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
	<script>Swal.fire('Acceso denegado','Solo administradores pueden acceder.','error').then(()=>window.location.href='./dashboard');</script>";
	exit;
}

$cliente_id = (USUARIO_ROL === 'superadmin')
	? (int)($_SESSION['cliente_seleccionado'] ?? 0)
	: (int)$usuario['cliente_id'];

if (!$cliente_id) {
	die('Selecciona un cliente primero.');
}

$mensaje = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$accion = $_POST['accion'] ?? '';
	try {
		if ($accion === 'agregar_cuenta') {
			$banco = trim($_POST['banco'] ?? '');
			$tipo_cuenta = trim($_POST['tipo_cuenta'] ?? '');
			$numero_cuenta = trim($_POST['numero_cuenta'] ?? '');
			$titular = trim($_POST['titular'] ?? '');
			if ($banco === '' || $numero_cuenta === '' || $titular === '') {
				throw new Exception('Banco, número de cuenta y titular son obligatorios.');
			}
			$stmtOrden = $pdo->prepare("SELECT IFNULL(MAX(orden), 0) + 1 FROM configuracion_cuentas_pago WHERE cliente_id = ?");
			$stmtOrden->execute([$cliente_id]);
			$orden = (int) $stmtOrden->fetchColumn();

			$pdo->prepare("INSERT INTO configuracion_cuentas_pago (cliente_id, banco, tipo_cuenta, numero_cuenta, titular, orden) VALUES (?, ?, ?, ?, ?, ?)")
				->execute([$cliente_id, $banco, $tipo_cuenta, $numero_cuenta, $titular, $orden]);
			$mensaje = 'Cuenta agregada.';
		} elseif ($accion === 'eliminar_cuenta') {
			$id = (int)($_POST['id'] ?? 0);
			$pdo->prepare("DELETE FROM configuracion_cuentas_pago WHERE id = ? AND cliente_id = ?")->execute([$id, $cliente_id]);
			$mensaje = 'Cuenta eliminada.';
		} elseif ($accion === 'toggle_cuenta') {
			$id = (int)($_POST['id'] ?? 0);
			$pdo->prepare("UPDATE configuracion_cuentas_pago SET activo = 1 - activo WHERE id = ? AND cliente_id = ?")->execute([$id, $cliente_id]);
			$mensaje = 'Cuenta actualizada.';
		} elseif ($accion === 'guardar_plantilla') {
			$tipo = $_POST['tipo'] ?? '';
			$contenido = $_POST['contenido'] ?? '';
			$asunto = trim($_POST['asunto'] ?? '');
			if (!in_array($tipo, ['envio_factura', 'saldo_pendiente'])) {
				throw new Exception('Plantilla inválida.');
			}
			if (trim($contenido) === '') {
				throw new Exception('El contenido de la plantilla no puede estar vacío.');
			}
			$stmtExists = $pdo->prepare("SELECT id FROM configuracion_mensajes WHERE cliente_id = ? AND tipo = ?");
			$stmtExists->execute([$cliente_id, $tipo]);
			if ($stmtExists->fetch()) {
				$pdo->prepare("UPDATE configuracion_mensajes SET asunto = ?, contenido = ? WHERE cliente_id = ? AND tipo = ?")
					->execute([$asunto, $contenido, $cliente_id, $tipo]);
			} else {
				$pdo->prepare("INSERT INTO configuracion_mensajes (cliente_id, tipo, asunto, contenido) VALUES (?, ?, ?, ?)")
					->execute([$cliente_id, $tipo, $asunto, $contenido]);
			}
			$mensaje = 'Plantilla guardada.';
		}
	} catch (Throwable $e) {
		$error = $e->getMessage();
	}
}

$stmtCuentas = $pdo->prepare("SELECT * FROM configuracion_cuentas_pago WHERE cliente_id = ? ORDER BY orden, id");
$stmtCuentas->execute([$cliente_id]);
$cuentas = $stmtCuentas->fetchAll(PDO::FETCH_ASSOC);

$stmtPlantillas = $pdo->prepare("SELECT * FROM configuracion_mensajes WHERE cliente_id = ?");
$stmtPlantillas->execute([$cliente_id]);
$plantillasRaw = $stmtPlantillas->fetchAll(PDO::FETCH_ASSOC);
$plantillas = [];
foreach ($plantillasRaw as $p) {
	$plantillas[$p['tipo']] = $p;
}
$defaults = [
	'envio_factura' => [
		'asunto' => 'Facturas {{cliente_nombre}} - Mes de {{mes_actual}} de {{anio_actual}}',
		'contenido' => "{{saludo}}\n\nEspero que se encuentre bien.\n\nAdjunto {{detalle_facturas}}\n\n{{cuentas_pago}}\n\nQuedo atento a cualquier consulta o confirmación de recepción.\n\nSaludos cordiales,"
	],
	'saldo_pendiente' => [
		'asunto' => 'Saldo pendiente de pago - {{cliente_nombre}}',
		'contenido' => "{{saludo}}\n\nEspero que se encuentre muy bien.\n\nLe escribo para darle seguimiento a las siguientes facturas pendientes de pago:\n\n{{detalle_facturas}}\n\nPor lo anterior, el saldo total pendiente asciende a L {{total}}.\n\n{{cuentas_pago}}\n\nAgradecemos mucho su apoyo y gestión. Quedamos atentos a su confirmación.\n\nSaludos cordiales,"
	]
];

require_once '../../includes/templates/header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<style>
	:root {
		--brand: #0891b2;
		--brand-dark: #0e7490;
		--border: #e2e8f0;
		--text-main: #1e293b;
		--text-muted: #64748b;
		--radius: 14px;
		--radius-sm: 8px;
		--shadow-md: 0 4px 16px rgba(0, 0, 0, .08);
	}

	.cm-page {
		padding: 1.5rem 0 3rem;
	}

	.cm-header {
		background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%);
		border-radius: var(--radius);
		padding: 1.5rem 2rem;
		color: #fff;
		margin-bottom: 1.5rem;
		box-shadow: var(--shadow-md);
	}

	.cm-header h4 {
		margin: 0;
		font-weight: 700;
	}

	.cm-header p {
		margin: .25rem 0 0;
		font-size: .85rem;
		opacity: .85;
	}

	.cm-card {
		background: #fff;
		border: 1px solid var(--border);
		border-radius: var(--radius);
		box-shadow: 0 1px 3px rgba(0, 0, 0, .06);
		margin-bottom: 1.5rem;
	}

	.cm-card-header {
		padding: 1rem 1.4rem;
		border-bottom: 1px solid var(--border);
		font-weight: 700;
		color: var(--text-main);
		display: flex;
		align-items: center;
		gap: .5rem;
	}

	.cm-card-body {
		padding: 1.2rem 1.4rem;
	}

	table.cm-table {
		width: 100%;
		border-collapse: collapse;
	}

	table.cm-table th,
	table.cm-table td {
		padding: .6rem .5rem;
		border-bottom: 1px solid var(--border);
		font-size: .88rem;
		text-align: left;
	}

	.cm-input,
	.cm-select,
	textarea.cm-textarea {
		width: 100%;
		padding: .55rem .75rem;
		border: 1px solid var(--border);
		border-radius: var(--radius-sm);
		font-size: .88rem;
		font-family: inherit;
	}

	textarea.cm-textarea {
		min-height: 260px;
		font-family: 'Courier New', monospace;
		font-size: .85rem;
		line-height: 1.5;
	}

	.cm-btn {
		display: inline-flex;
		align-items: center;
		gap: .4rem;
		padding: .55rem 1.1rem;
		background: var(--brand);
		color: #fff;
		border: none;
		border-radius: var(--radius-sm);
		font-size: .85rem;
		font-weight: 600;
		cursor: pointer;
	}

	.cm-btn:hover {
		background: var(--brand-dark);
	}

	.cm-btn-danger {
		background: #ef4444;
	}

	.cm-btn-danger:hover {
		background: #dc2626;
	}

	.cm-badge {
		display: inline-block;
		padding: .2rem .55rem;
		border-radius: 999px;
		font-size: .72rem;
		font-weight: 700;
	}

	.cm-badge-on {
		background: #dcfce7;
		color: #15803d;
	}

	.cm-badge-off {
		background: #f1f5f9;
		color: #64748b;
	}

	.cm-tabs {
		display: flex;
		gap: .5rem;
		margin-bottom: 1rem;
	}

	.cm-tab {
		padding: .5rem 1rem;
		border-radius: var(--radius-sm);
		background: #f1f5f9;
		color: var(--text-muted);
		font-size: .85rem;
		font-weight: 600;
		cursor: pointer;
		border: 1px solid transparent;
	}

	.cm-tab.active {
		background: #ecfeff;
		color: var(--brand-dark);
		border-color: var(--brand);
	}

	.cm-placeholders {
		background: #f8fafc;
		border: 1px solid var(--border);
		border-radius: var(--radius-sm);
		padding: .8rem 1rem;
		font-size: .8rem;
		color: var(--text-muted);
		margin-bottom: .8rem;
	}

	.cm-placeholders code {
		background: #e2e8f0;
		padding: .1rem .35rem;
		border-radius: 4px;
		color: #0f172a;
	}

	.cm-plantilla-form {
		display: none;
	}

	.cm-plantilla-form.active {
		display: block;
	}
</style>

<div class="cm-page container-xxl">
	<div class="cm-header">
		<h4><i class="bi bi-envelope-paper-fill"></i> Configuración de mensajes</h4>
		<p>Cuentas de pago y plantillas de correo usadas al redactar mensajes desde el listado de facturas.</p>
	</div>

	<?php if ($mensaje): ?>
		<div class="alert alert-success"><?= htmlspecialchars($mensaje) ?></div>
	<?php endif; ?>
	<?php if ($error): ?>
		<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
	<?php endif; ?>

	<!-- Cuentas de pago -->
	<div class="cm-card">
		<div class="cm-card-header"><i class="bi bi-bank"></i> Cuentas de pago</div>
		<div class="cm-card-body">
			<table class="cm-table">
				<thead>
					<tr>
						<th>Banco</th>
						<th>Tipo</th>
						<th>N.° Cuenta</th>
						<th>Titular</th>
						<th>Estado</th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($cuentas as $c): ?>
						<tr>
							<td><?= htmlspecialchars($c['banco']) ?></td>
							<td><?= htmlspecialchars($c['tipo_cuenta'] ?? '') ?></td>
							<td><?= htmlspecialchars($c['numero_cuenta']) ?></td>
							<td><?= htmlspecialchars($c['titular']) ?></td>
							<td>
								<span class="cm-badge <?= $c['activo'] ? 'cm-badge-on' : 'cm-badge-off' ?>">
									<?= $c['activo'] ? 'Activa' : 'Inactiva' ?>
								</span>
							</td>
							<td style="white-space:nowrap;">
								<form method="POST" style="display:inline;">
									<input type="hidden" name="accion" value="toggle_cuenta">
									<input type="hidden" name="id" value="<?= $c['id'] ?>">
									<button type="submit" class="cm-btn" style="padding:.35rem .7rem;">
										<i class="bi bi-toggle2-<?= $c['activo'] ? 'on' : 'off' ?>"></i>
									</button>
								</form>
								<form method="POST" style="display:inline;"
									onsubmit="return confirm('¿Eliminar esta cuenta?');">
									<input type="hidden" name="accion" value="eliminar_cuenta">
									<input type="hidden" name="id" value="<?= $c['id'] ?>">
									<button type="submit" class="cm-btn cm-btn-danger" style="padding:.35rem .7rem;">
										<i class="bi bi-trash3-fill"></i>
									</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if (empty($cuentas)): ?>
						<tr>
							<td colspan="6" style="text-align:center;color:var(--text-muted);padding:1.5rem;">Aún no hay
								cuentas de pago registradas.</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<hr>

			<form method="POST" class="row g-2 align-items-end">
				<input type="hidden" name="accion" value="agregar_cuenta">
				<div class="col-md-3">
					<label class="form-label" style="font-size:.8rem;">Banco</label>
					<input type="text" name="banco" class="cm-input" placeholder="Ej: Banpaís" required>
				</div>
				<div class="col-md-2">
					<label class="form-label" style="font-size:.8rem;">Tipo</label>
					<input type="text" name="tipo_cuenta" class="cm-input" placeholder="Ahorros / Corriente">
				</div>
				<div class="col-md-3">
					<label class="form-label" style="font-size:.8rem;">N.° Cuenta</label>
					<input type="text" name="numero_cuenta" class="cm-input" placeholder="000 000 000 000" required>
				</div>
				<div class="col-md-3">
					<label class="form-label" style="font-size:.8rem;">Titular</label>
					<input type="text" name="titular" class="cm-input" placeholder="Nombre del titular" required>
				</div>
				<div class="col-md-1">
					<button type="submit" class="cm-btn" style="width:100%;justify-content:center;"><i
							class="bi bi-plus-lg"></i></button>
				</div>
			</form>
		</div>
	</div>

	<!-- Plantillas -->
	<div class="cm-card">
		<div class="cm-card-header"><i class="bi bi-file-earmark-text-fill"></i> Plantillas de correo</div>
		<div class="cm-card-body">
			<div class="cm-placeholders">
				Placeholders disponibles (funcionan tanto en el <strong>Asunto</strong> como en el
				<strong>Contenido</strong>): <code>{{saludo}}</code> (saluda por nombre de contacto o "Estimado equipo de
				[Cliente]"), <code>{{cliente_nombre}}</code>, <code>{{detalle_facturas}}</code> (lista de facturas con
				sus conceptos o montos, según la plantilla), <code>{{total}}</code> (suma en Lempiras),
				<code>{{cuentas_pago}}</code> (lista de cuentas activas), <code>{{mes_actual}}</code> (ej. "Agosto"),
				<code>{{anio_actual}}</code> (ej. "2026").
			</div>

			<div class="cm-tabs">
				<div class="cm-tab active" data-tab="envio_factura">Envío de factura(s)</div>
				<div class="cm-tab" data-tab="saldo_pendiente">Saldo pendiente</div>
			</div>

			<?php foreach (['envio_factura', 'saldo_pendiente'] as $tipo):
				$p = $plantillas[$tipo] ?? $defaults[$tipo];
			?>
				<form method="POST" class="cm-plantilla-form <?= $tipo === 'envio_factura' ? 'active' : '' ?>"
					data-tab-form="<?= $tipo ?>">
					<input type="hidden" name="accion" value="guardar_plantilla">
					<input type="hidden" name="tipo" value="<?= $tipo ?>">
					<div class="mb-2">
						<label class="form-label" style="font-size:.8rem;">Asunto sugerido</label>
						<input type="text" name="asunto" class="cm-input" value="<?= htmlspecialchars($p['asunto'] ?? '') ?>">
					</div>
					<div class="mb-2">
						<label class="form-label" style="font-size:.8rem;">Contenido</label>
						<textarea name="contenido" class="cm-textarea"><?= htmlspecialchars($p['contenido']) ?></textarea>
					</div>
					<button type="submit" class="cm-btn"><i class="bi bi-floppy-fill"></i> Guardar plantilla</button>
				</form>
			<?php endforeach; ?>
		</div>
	</div>
</div>

<script>
	document.querySelectorAll('.cm-tab').forEach(tab => {
		tab.addEventListener('click', () => {
			document.querySelectorAll('.cm-tab').forEach(t => t.classList.remove('active'));
			document.querySelectorAll('.cm-plantilla-form').forEach(f => f.classList.remove('active'));
			tab.classList.add('active');
			document.querySelector(`.cm-plantilla-form[data-tab-form="${tab.dataset.tab}"]`).classList.add('active');
		});
	});
</script>
