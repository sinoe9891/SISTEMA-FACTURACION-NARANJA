<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

$modo_pdf = !empty($_GET['modo_pdf']);
$modo_pdf_bytes = !empty($_GET['pdf_bytes']);
$modo_render_html = !empty($_GET['render_only']);
$modo_exportar = $modo_pdf || $modo_pdf_bytes || $modo_render_html;

if ($modo_exportar) {
	ob_start();
}

if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) {
	http_response_code(400);
	echo "ID de factura inválido.";
	return;
}

$factura_id = intval($_GET['id']);
$usuario_id = $_SESSION['usuario_id'];

$stmtUser = $pdo->prepare("SELECT cliente_id, rol FROM usuarios WHERE id = ?");
$stmtUser->execute([$usuario_id]);
$usuario = $stmtUser->fetch();

if (!$usuario) {
	die("Usuario inválido.");
}

$cliente_id_usuario = $usuario['cliente_id'];
$rol_usuario = $usuario['rol'];
$esAdmin = in_array($rol_usuario, ['admin', 'superadmin']);

// Obtener datos generales de la factura, cliente, receptor y CAI
if ($rol_usuario === 'superadmin') {
	$stmt = $pdo->prepare("
		SELECT f.*, 
			   cf.nombre AS receptor_nombre,
			   cf.rtn AS receptor_rtn,
			   cf.direccion AS receptor_direccion,
			   cf.telefono AS receptor_telefono,
			   cf.email AS receptor_email,
			   c.logo_url, c.nombre AS cliente_nombre, c.rtn, c.direccion, c.telefono, c.email,
			   cai.cai, cai.rango_inicio, cai.rango_fin, cai.fecha_limite, cai.fecha_recepcion,
			   cai.rango_cai_inicio, cai.rango_cai_fin
		FROM facturas f
		INNER JOIN clientes_factura cf ON f.receptor_id = cf.id
		INNER JOIN clientes_saas c ON f.cliente_id = c.id
		LEFT JOIN cai_rangos cai ON f.cai_id = cai.id
		WHERE f.id = ?
	");
	$stmt->execute([$factura_id]);
} else {
	$stmt = $pdo->prepare("
		SELECT f.*, 
           cf.nombre AS receptor_nombre,
           cf.rtn AS receptor_rtn,
           cf.direccion AS receptor_direccion,
           cf.telefono AS receptor_telefono,
           cf.email AS receptor_email,
           c.logo_url, c.nombre AS cliente_nombre, c.rtn, c.direccion, c.telefono, c.email,
           cai.cai, cai.rango_inicio, cai.rango_fin, cai.fecha_limite, cai.fecha_recepcion,
           cai.rango_cai_inicio, cai.rango_cai_fin
    FROM facturas f
    INNER JOIN clientes_factura cf ON f.receptor_id = cf.id
    INNER JOIN clientes_saas c ON f.cliente_id = c.id
    LEFT JOIN cai_rangos cai ON f.cai_id = cai.id
    WHERE f.id = ? AND f.cliente_id = ?
	");
	$stmt->execute([$factura_id, $cliente_id_usuario]);
}
$factura = $stmt->fetch();

$esAnulada = strtolower($factura['estado']) === 'anulada';
$rangoCAIInicio = htmlspecialchars($factura['rango_cai_inicio']);
$rangoCAIFin    = htmlspecialchars($factura['rango_cai_fin']);

/* ── Condición de pago (Contado / Crédito / Mixto) ─────────────────────── */
$cond_pago_raw = strtolower(trim($factura['condicion_pago'] ?? 'contado'));
switch ($cond_pago_raw) {
	case 'credito':
	case 'crédito':
		$cond_pago_label = 'Crédito';
		break;
	case 'credito/contado':
	case 'crédito/contado':
		$cond_pago_label = 'Crédito / Contado';
		break;
	case 'contado':
	default:
		$cond_pago_label = 'Contado';
		break;
}

/* ── Estado de pago ────────────────────────────────────────────────────── */
$estado_pago_label = !empty($factura['pagada']) ? 'Pagada' : 'Pendiente de pago';

if (!$factura) {
	http_response_code(404);
	echo "Factura no encontrada o no autorizada.";
	return;
}

// Obtener ítems de la factura junto con el nombre del producto
$stmtItems = $pdo->prepare("
    SELECT fi.*, p.nombre AS nombre_producto
    FROM factura_items_receptor fi
    LEFT JOIN productos_clientes p ON fi.producto_id = p.id
    WHERE fi.factura_id = ?
");
$stmtItems->execute([$factura_id]);
$items = $stmtItems->fetchAll();

$stmtConfig = $pdo->prepare("SELECT * FROM configuracion_sistema WHERE id = 1");
$stmtConfig->execute();
$configuracion = $stmtConfig->fetch();

if (!function_exists('formatMoneda')) {
	function formatMoneda($monto)
	{
		return 'L ' . number_format($monto, 2, '.', ',');
	}
}

if (!function_exists('formatFecha')) {
	function formatFecha($fecha)
	{
		$meses = [
			'January' => 'enero',
			'February' => 'febrero',
			'March' => 'marzo',
			'April' => 'abril',
			'May' => 'mayo',
			'June' => 'junio',
			'July' => 'julio',
			'August' => 'agosto',
			'September' => 'septiembre',
			'October' => 'octubre',
			'November' => 'noviembre',
			'December' => 'diciembre'
		];
		$date = new DateTime($fecha);
		$mes_en = $date->format('F');
		$mes_es = $meses[$mes_en] ?? $mes_en;
		return $date->format('d') . ' de ' . $mes_es . ' de ' . $date->format('Y');
	}
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
	<meta charset="UTF-8" />
	<?php
	$titulo_factura = 'Factura ' . htmlspecialchars($factura['correlativo']);
	if (!empty($factura['receptor_nombre'])) $titulo_factura .= ' - ' . htmlspecialchars($factura['receptor_nombre']);
	if (!empty($factura['fecha_emision']))   $titulo_factura .= ' - ' . formatFecha($factura['fecha_emision']);
	if (!empty($items) && !empty($items[0]['descripcion_html'])) $titulo_factura .= ' - Corresponde a ' . htmlspecialchars($items[0]['descripcion_html']);
	?>
	<title><?= $titulo_factura ?></title>
	<?php if (!($modo_pdf || $modo_pdf_bytes)): ?>
		<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
	<?php endif; ?>

	<style>
		/* ══════════════════════════════════════════
		   BARRA DE ACCIONES (solo pantalla)
		══════════════════════════════════════════ */
		.action-bar {
			background: #1e293b;
			padding: .55rem 1.25rem;
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: .75rem;
			flex-wrap: wrap;
			position: sticky;
			top: 0;
			z-index: 999;
			box-shadow: 0 2px 10px rgba(0, 0, 0, .3);
		}

		.action-bar-info {
			color: #94a3b8;
			font-size: .82rem;
			font-family: Arial, sans-serif;
		}

		.action-bar-info strong {
			color: #e2e8f0;
		}

		.action-bar-btns {
			display: flex;
			gap: .5rem;
			align-items: center;
		}

		.btn-ab {
			display: inline-flex;
			align-items: center;
			gap: .4rem;
			padding: .42rem 1rem;
			border-radius: 8px;
			font-size: .82rem;
			font-weight: 600;
			cursor: pointer;
			border: none;
			text-decoration: none;
			transition: all .15s;
			white-space: nowrap;
			font-family: Arial, sans-serif;
		}

		.btn-ab-back {
			background: rgba(255, 255, 255, .1);
			color: #94a3b8 !important;
			border: 1px solid rgba(255, 255, 255, .15);
		}

		.btn-ab-back:hover {
			background: rgba(255, 255, 255, .18);
			color: #e2e8f0 !important;
		}

		.btn-ab-edit {
			background: #3b82f6;
			color: #fff !important;
			box-shadow: 0 1px 6px rgba(59, 130, 246, .35);
		}

		.btn-ab-edit:hover {
			background: #2563eb;
			color: #fff !important;
		}

		.btn-ab-print {
			background: #e36f1f;
			color: #fff !important;
			box-shadow: 0 1px 6px rgba(227, 111, 31, .4);
		}

		.btn-ab-print:hover {
			background: #c45e16;
			color: #fff !important;
		}

		/* ══════════════════════════════════════════
		   ESTILOS ORIGINALES DE LA FACTURA
		══════════════════════════════════════════ */
		body {
			font-family: Arial, sans-serif;
			font-size: 12px;
		}

		.marca-agua-anulada {
			position: fixed;
			top: 35%;
			left: 50%;
			transform: translate(-50%, -50%) rotate(-30deg);
			font-size: 90px;
			color: rgba(100, 100, 100, 0.15);
			font-weight: bold;
			z-index: 0;
			pointer-events: none;
			user-select: none;
		}

		.totalapagar td {
			font-size: 14px;
		}

		td {
			padding-bottom: 0 !important;
		}

		.factura-header h2 {
			color: #e36f1f;
			font-weight: bold;
		}

		.factura {
			font-size: 14px;
			white-space: nowrap;
			flex-shrink: 0;
		}

		.table thead th {
			background-color: #e36f1f;
			color: white;
		}

		.totales-row td {
			font-weight: bold;
		}

		.cantidadletras {
			font-size: 16px;
		}

		.agradecimiento {
			color: #e36f1f;
			font-size: 30px;
		}

		.footer-text {
			font-size: 11px;
			color: #555;
			margin-top: 30px;
		}

		table.table.table-borderless td {
			padding: 4px;
		}

		hr {
			margin: 9px;
		}

		@media print {

			/* Ocultar barra de acciones al imprimir */
			.action-bar,
			.no-print {
				display: none !important;
			}

			body {
				font-size: 8px;
				font-family: Arial, sans-serif;
				color: #000;
				background: white;
			}

			.totalapagar td {
				font-size: 12px !important;
			}

			.btn,
			a,
			.btn-secondary,
			.agradecimiento {
				display: none !important;
			}

			.container {
				border: none;
				padding: 0;
				margin: 0;
				width: 100%;
			}

			.factura-header {
				font-size: 10px;
			}

			.infocliente {
				font-size: 12px;
			}

			.factura {
				font-size: 14px;
			}

			hr {
				margin: 9px;
			}

			.factura-header h2 {
				font-size: 22px;
			}

			.table th,
			.table td {
				font-size: 8px;
				color: #000;
			}

			.cantidadletras {
				font-size: 14px;
				color: #000;
			}

			.footer-text {
				font-size: 10px;
				color: #000;
			}

			table {
				page-break-inside: auto;
			}

			tr {
				page-break-inside: avoid;
				page-break-after: auto;
			}

			html,
			body {
				margin: 1cm;
			}

			@page {
				size: letter portrait;
				margin: 1cm;
			}

			.container {
				width: 100% !important;
			}

			body.print {
				transform: translateY(0px) !important;
				margin: 0;
			}

			h6 {
				font-size: 12px !important;
			}

			h5 {
				font-size: 14px !important;
			}
		}

		.text-end-factura-titulo {
			width: 60%;
		}

		<?php if ($modo_pdf || $modo_pdf_bytes): ?>
			/* Bootstrap se omite en modo PDF (usa variables CSS que Dompdf no soporta).
			   Reemplazo mínimo, sin variables ni flexbox, de las clases usadas en esta plantilla. */
			* {
				font-family: Arial, Helvetica, sans-serif !important;
			}

			/* Dompdf no soporta flexbox: los .d-flex pasan a layout de tabla */
			.d-flex {
				display: table !important;
				width: 100%;
			}

			.d-flex.justify-content-between>* {
				display: table-cell;
				vertical-align: top;
			}

			.d-flex.justify-content-between>*:last-child {
				text-align: right;
			}

			.container {
				width: 100%;
			}

			.border {
				border: 1px solid #dee2e6;
			}

			.p-4 {
				padding: 1.5rem;
			}

			.mt-2 {
				margin-top: .5rem;
			}

			.mt-3 {
				margin-top: 1rem;
			}

			.mb-2 {
				margin-bottom: .5rem;
			}

			.text-end {
				text-align: right;
			}

			.text-center {
				text-align: center;
			}

			.text-uppercase {
				text-transform: uppercase;
			}

			table.table {
				width: 100%;
				border-collapse: collapse;
				margin-bottom: 1rem;
			}

			table.table th,
			table.table td {
				padding: .5rem;
				vertical-align: top;
			}

			table.table-bordered th,
			table.table-bordered td {
				border: 1px solid #dee2e6;
			}

			table.table-borderless th,
			table.table-borderless td {
				border: none;
			}

			.alert {
				padding: .75rem 1.25rem;
				border: 1px solid transparent;
				border-radius: .25rem;
			}

			.alert-warning {
				color: #664d03;
				background-color: #fff3cd;
				border-color: #ffecb5;
			}
		<?php endif; ?>
	</style>
</head>

<body class="">

	<?php if ($esAnulada): ?>
		<div class="marca-agua-anulada">ANULADA</div>
	<?php endif; ?>

	<!-- ══════════════════════════════════════
	     BARRA DE ACCIONES (no se imprime)
	══════════════════════════════════════ -->
	<?php if (!$modo_exportar): ?>
		<div class="action-bar no-print">
			<div class="action-bar-info">
				Factura <strong><?= htmlspecialchars($factura['correlativo']) ?></strong>
				&nbsp;·&nbsp; <?= htmlspecialchars($factura['receptor_nombre']) ?>
				&nbsp;·&nbsp; <?= ucfirst($factura['estado']) ?>
				&nbsp;·&nbsp; <strong><?= $cond_pago_label ?></strong>
				&nbsp;·&nbsp; <strong><?= $estado_pago_label ?></strong>
			</div>
			<div class="action-bar-btns">
				<a href="lista_facturas" class="btn-ab btn-ab-back">← Volver</a>
				<?php if ($esAdmin): ?>
					<a href="editar_factura?id=<?= $factura_id ?>" class="btn-ab btn-ab-edit">
						✏️ Editar Factura
					</a>
				<?php endif; ?>
				<button onclick="window.print()" class="btn-ab btn-ab-print">
					🖨️ Imprimir / PDF
				</button>
			</div>
		</div>
	<?php endif; ?>

	<!-- ══════════════════════════════════════
	     CONTENIDO ORIGINAL DE LA FACTURA
	══════════════════════════════════════ -->
	<div class="container border p-4">
		<div class="d-flex justify-content-between factura-header">
			<div class="factura-header" style="max-width: 300px;max-height: 300px">
				<?php if (!empty($factura['logo_url'])): ?>
					<img src="<?= htmlspecialchars($factura['logo_url']) ?>" alt="Logo" class="factura-logo"
						style="width: 150px;">
				<?php endif; ?>
				<div><strong></strong></div>
			</div>

			<div class="text-end text-end-factura-titulo">
				<h2>FACTURA</h2>
				<div><strong><?= htmlspecialchars($factura['cliente_nombre']) ?></strong></div>
				<div>Dirección: <?= htmlspecialchars($factura['direccion']) ?></div>
				<div>Teléfono: <?= htmlspecialchars($factura['telefono']) ?></div>
				<div>RTN: <?= htmlspecialchars($factura['rtn']) ?></div>
				<div>Email: <?= htmlspecialchars($factura['email']) ?></div>
				<div><strong>CAI:</strong> <?= htmlspecialchars($factura['cai'] ?? '') ?></div>
				<div><strong>Rango autorizado:</strong> <?= $rangoCAIInicio ?> al<br><?= $rangoCAIFin ?></div>
				<div><strong>Fecha Recepción:</strong> <?= formatFecha($factura['fecha_recepcion']) ?></div>
				<div><strong>Fecha límite emisión:</strong> <?= formatFecha($factura['fecha_limite']) ?></div>
			</div>
		</div>

		<hr>

		<div class="d-flex justify-content-between align-items-start">
			<div class="infocliente">
				<strong>Cliente:</strong> <?= htmlspecialchars($factura['receptor_nombre']) ?><br>
				<strong>RTN:</strong> <?= htmlspecialchars($factura['receptor_rtn'] ?? '') ?><br>
				<strong>Dirección:</strong> <?= htmlspecialchars($factura['receptor_direccion'] ?? '') ?><br>
				<strong>Teléfono:</strong> <?= htmlspecialchars($factura['receptor_telefono'] ?? '') ?><br>
				<strong>Email:</strong> <?= htmlspecialchars($factura['receptor_email'] ?? '') ?><br>
				<strong>Fecha de emisión:</strong> <?= formatFecha($factura['fecha_emision']) ?><br>
				<strong>Condición de pago:</strong> <strong><?= $cond_pago_label ?></strong>
				<span class="no-print"><br>
					<strong>Estado de pago:</strong> <strong><?= $estado_pago_label ?></strong>
				</span>
			</div>
			<div class="text-end">
				<div class="factura"><strong>Factura N.°:</strong> <?= htmlspecialchars($factura['correlativo']) ?></div>
			</div>
		</div>

		<hr>

		<?php if (count($items) > 0): ?>
			<table class="table table-bordered mt-3">
				<thead>
					<tr>
						<th style="max-width: 460px;">Artículo</th>
						<th class="text-center">Cantidad</th>
						<th class="text-center">Precio Unitario</th>
						<th class="text-center">Total</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($items as $item): ?>
						<tr>
							<td style="max-width: 460px;">
								<?php
								if (!empty($item['descripcion_html'])) {
									echo mb_strtoupper(htmlspecialchars($item['nombre_producto']), 'UTF-8') . ' - ' . nl2br(mb_strtoupper($item['descripcion_html']));
								} else {
									echo mb_strtoupper(htmlspecialchars($item['nombre_producto'] ?? 'SIN DESCRIPCIÓN'), 'UTF-8');
								}
								?>
							</td>
							<td class="text-end"><?= htmlspecialchars($item['cantidad']) ?></td>
							<td class="text-end"><?= formatMoneda($item['precio_unitario']) ?></td>
							<td class="text-end"><?= formatMoneda($item['subtotal']) ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else: ?>
			<div class="alert alert-warning mt-3">No hay ítems asociados a esta factura.</div>
		<?php endif; ?>

		<hr>
		<div class="d-flex justify-content-between">
			<div class="mt-2 mb-2">
				<h6 class="text-uppercase" style="font-size: 14px;">Datos del Adquiriente Exonerado</h6>
				<div><strong>Orden de Compra Exenta:</strong> <?= htmlspecialchars($factura['orden_compra_exenta']) ?>
				</div>
				<div><strong>Constancia de Registro Exonerado:</strong>
					<?= htmlspecialchars($factura['constancia_exoneracion']) ?></div>
				<div><strong>Registro SAG:</strong> <?= htmlspecialchars($factura['registro_sag']) ?></div>
			</div>
			<table class="table table-borderless" style="max-width: 400px; float: right; text-align: right;">
				<tbody>
					<tr>
						<td>Gravado:</td>
						<td id="gravado"><?= formatMoneda($factura['gravado_total']) ?></td>
					</tr>
					<tr>
						<td>Desc. / rebajas otorgadas:</td>
						<td id="descrebajas"><?= formatMoneda($factura['descuentos'] ?? 0) ?></td>
					</tr>
					<tr>
						<td>Importe exonerado:</td>
						<td id="importe_exonerado"><?= formatMoneda($factura['importe_exonerado']) ?></td>
					</tr>
					<tr>
						<td>Importe Exento:</td>
						<td id="importe_exento"><?= formatMoneda($factura['exento_total']) ?></td>
					</tr>
					<tr>
						<td>Importe Gravado 15%:</td>
						<td id="importe_gravado_15"><?= formatMoneda($factura['importe_gravado_15']) ?></td>
					</tr>
					<tr>
						<td>Importe Gravado 18%:</td>
						<td id="importe_gravado_18"><?= formatMoneda($factura['importe_gravado_18']) ?></td>
					</tr>
					<tr>
						<td>Subtotal:</td>
						<td id="subtotal"><?= formatMoneda($factura['subtotal']) ?></td>
					</tr>
					<tr>
						<td>ISV (18%):</td>
						<td id="isv_18"><?= formatMoneda($factura['isv_18']) ?></td>
					</tr>
					<tr>
						<td>ISV (15%):</td>
						<td id="isv_15"><?= formatMoneda($factura['isv_15']) ?></td>
					</tr>
					<tr class="totalapagar">
						<td><strong>Total a pagar:</strong></td>
						<td id="total"><strong><?= formatMoneda($factura['total']) ?></strong></td>
					</tr>
				</tbody>
			</table>
		</div>
		<div style="clear: both;"></div>
		<h5>
			<span class="cantidadletras">Cantidad en letras: </span><?= htmlspecialchars($factura['monto_letras']) ?>
		</h5>
		<p class="agradecimiento">
			Gracias por su preferencia.
		</p>
		<div class="footer-text" style="margin-top: 24px; padding-top: 10px; border-top: 2px solid #e36f1f;">
			<div style="font-size: 9px; color: #444; text-align: center; line-height: 1.8;">

				<strong style="font-size: 10px;">— INFORMACIÓN DEL EMISOR AUTORIZADO —</strong><br>

				<strong>Imprenta:</strong> <?= htmlspecialchars($configuracion['certificador_nombre']) ?>
				&nbsp;|&nbsp;
				<strong>RTN Imprenta:</strong> <?= htmlspecialchars($configuracion['certificador_rtn']) ?>
				&nbsp;|&nbsp;
				<strong>N.° Certificado:</strong> <?= htmlspecialchars($configuracion['numero_certificado']) ?>
				<br>

				<?php if (!empty($configuracion['imprenta_direccion'])): ?>
					<strong>Dir. Imprenta:</strong> <?= htmlspecialchars($configuracion['imprenta_direccion']) ?>
					&nbsp;|&nbsp;
				<?php endif; ?>

				<?php if (!empty($configuracion['imprenta_telefono'])): ?>
					<strong>Tel:</strong> <?= htmlspecialchars($configuracion['imprenta_telefono']) ?>
					&nbsp;|&nbsp;
				<?php endif; ?>

				<?php if (!empty($configuracion['imprenta_correo'])): ?>
					<?= htmlspecialchars($configuracion['imprenta_correo']) ?>
				<?php endif; ?>
				<br>

				<?php if (!empty($configuracion['footer_factura'])): ?>
					<em><?= nl2br(htmlspecialchars($configuracion['footer_factura'])) ?></em><br>
				<?php endif; ?>
				<!-- 
                🔍 Esta factura puede ser verificada en
                <strong>www.sar.gob.hn</strong> -->
			</div>
		</div>
		<?php if (!$modo_exportar): ?>
			<a href="./lista_facturas" class="btn btn-secondary mt-3 no-print">Volver al listado</a>
		<?php endif; ?>
	</div>

	<script>
		document.querySelector("body").classList.add("print");
	</script>
</body>

</html>
<?php
if ($modo_exportar) {
	$html = ob_get_clean();
	if ($modo_pdf || $modo_pdf_bytes) {
		require_once '../../vendor/autoload.php';

		$options = new \Dompdf\Options();
		$options->set('isRemoteEnabled', true);
		$options->set('defaultFont', 'Arial');
		$options->set('defaultMediaType', 'print');
		$dompdf = new \Dompdf\Dompdf($options);
		$dompdf->loadHtml($html, 'UTF-8');
		$dompdf->setPaper('letter', 'portrait');
		$dompdf->render();

		$filename = 'factura_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $factura['correlativo'] ?? $factura_id) . '.pdf';
		if ($modo_pdf_bytes) {
			echo $dompdf->output();
			return;
		}
		$dompdf->stream($filename, ['Attachment' => true]);
		return;
	}

	echo $html;
	return;
}
