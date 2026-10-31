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

// Mismo alcance que la opción de subsanaciones: todos los CAI y períodos del establecimiento.
$stmtSubsanaciones = $pdo->prepare('SELECT COUNT(*) FROM facturas WHERE cliente_id = ? AND establecimiento_id = ? AND requiere_subsanacion = 1');
$stmtSubsanaciones->execute([$cliente_id, $establecimiento_activo]);
$totalSubsanaciones = (int)$stmtSubsanaciones->fetchColumn();

$subsanacion = in_array($_GET['subsanacion'] ?? '', ['0', '1', '2'], true) ? $_GET['subsanacion'] : '';
if (in_array($_GET['estado'] ?? '', ['sub_0', 'sub_1', 'sub_2'], true)) $subsanacion = substr($_GET['estado'], 4);
$estadoFiltro = in_array($_GET['estado'] ?? '', ['emitida', 'anulada', 'borrador', 'pago_pendiente', 'pagada'], true) ? $_GET['estado'] : '';
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
if ($caix === null && !isset($_GET['cai_id']) && !in_array($subsanacion, ['1', '2'], true)) {
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
if ($estadoFiltro === 'pago_pendiente') $whereExtra .= " AND f.estado = 'emitida' AND (f.pagada = 0 OR f.pagada IS NULL)";   // emitidas sin pagar
elseif ($estadoFiltro === 'pagada') $whereExtra .= " AND f.estado = 'emitida' AND f.pagada = 1";
elseif ($estadoFiltro !== '') { $whereExtra .= " AND f.estado = ?"; $params[] = $estadoFiltro; }
if ($subsanacion !== '') {
    $whereExtra .= " AND f.requiere_subsanacion = ?";
    $params[] = (int)$subsanacion;
}
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

require_once '../../includes/programas.php';
$__conProg = programasDisponible($pdo);
$stmtFacturas = $pdo->prepare("
    SELECT " . ($__conProg ? "pg.nombre" : "NULL") . " AS programa, f.id, f.correlativo, f.fecha_emision, f.total, f.subtotal,
           f.estado, f.pagada, f.enviada_receptor, f.estado_declarada,
           (f.isv_15 + f.isv_18) AS isv_total,
           f.requiere_subsanacion, f.notas_internas, f.receptor_id, cf.nombre AS receptor
    FROM facturas f
    INNER JOIN clientes_factura cf ON f.receptor_id = cf.id
    " . ($__conProg ? "LEFT JOIN programas pg ON pg.id = f.programa_id" : "") . "
    WHERE f.cliente_id = ? AND f.establecimiento_id = ?
    $whereExtra
    ORDER BY f.fecha_emision DESC
");
$stmtFacturas->execute($params);
$facturas = $stmtFacturas->fetchAll();
// Fechas de emisión fuera del orden de su talonario (o futuras): aviso en la fila
require_once '../../includes/factura_fechas.php';
$fechaDesorden = facturasFechaFueraDeOrden($pdo, (int)$cliente_id);

// Envíos por correo de cada factura (Cobros por correo): el programado pendiente manda; si no, el último enviado o con error
$enviosCorreo = [];
try {
    $stEnv = $pdo->prepare("SELECT x.factura_id, c.estado, c.programado_para, c.enviado_en FROM cobros_programados_facturas x
                            JOIN cobros_programados c ON c.id = x.cobro_id
                            WHERE c.cliente_id = ? AND c.prueba = 0 AND c.estado IN ('programado','enviando','enviado','error')
                            ORDER BY c.programado_para");
    $stEnv->execute([$cliente_id]);
    foreach ($stEnv->fetchAll(PDO::FETCH_ASSOC) as $ev) {
        $prev = $enviosCorreo[$ev['factura_id']] ?? null;
        $peso = ['programado' => 3, 'enviando' => 3, 'error' => 2, 'enviado' => 1][$ev['estado']];
        if (!$prev || $peso > $prev['peso'] || ($peso === $prev['peso'] && $ev['programado_para'] >= $prev['programado_para'])) $enviosCorreo[$ev['factura_id']] = $ev + ['peso' => $peso];
    }
} catch (Throwable $e) {
    $enviosCorreo = [];   // módulo de cobros no instalado
}

if ($caix) {
	$stmtUltimo = $pdo->prepare("SELECT ultimo_correlativo FROM cai_rangos WHERE id = ?");
	$stmtUltimo->execute([$caix]);
	$ultimoCorrelativoCAI = $stmtUltimo->fetchColumn();
}

$total_facturas    = count($facturas);
$emitidas_count    = count(array_filter($facturas, fn($f) => $f['estado'] === 'emitida'));
$anuladas_count    = count(array_filter($facturas, fn($f) => $f['estado'] === 'anulada'));
$pagadas_count     = count(array_filter($facturas, fn($f) => $f['pagada'] == 1 && $f['estado'] === 'emitida'));
$pendientes_facts  = array_filter($facturas, fn($f) => $f['estado'] === 'emitida' && $f['pagada'] != 1);
$pendientes_count  = count($pendientes_facts);
$pendientes_monto  = array_sum(array_map(fn($f) => (float)$f['total'], $pendientes_facts));
$total_monto       = array_sum(array_map(fn($f) => (float)$f['total'], array_filter($facturas, fn($f) => $f['estado'] === 'emitida')));
$esAdmin           = in_array($datos['rol'], ['admin', 'superadmin']);
$puedeCobrarLf     = in_array($datos['rol'], ['admin', 'superadmin', 'facturador'], true) && permisoPuede($pdo, 'cuentas_cobrar');   // «Registrar cobro» → Cuentas por cobrar
// Cuentas en lempiras para registrar el cobro de varias facturas a la vez (entra al banco)
$cuentasCobroLf = [];
if ($puedeCobrarLf) { require_once '../../includes/bancos.php'; if (bancosDisponible($pdo)) foreach (bancoCuentas($pdo, (int)cliente_actual()) as $cb) if ($cb['moneda'] === 'HNL' && (int)$cb['activa']) $cuentasCobroLf[] = ['id' => (int)$cb['id'], 'txt' => $cb['banco'] . ' ' . $cb['numero'], 'pred' => (int)($cb['predeterminada'] ?? 0)]; }

// Tarjeta "Totales": respeta el filtro de fecha/CAI activo; sin filtro, muestra el mes actual (igual que el dashboard).
if ($fecha_desde || $fecha_hasta || $caix) {
	$facturasTotales = array_filter($facturas, fn($f) => $f['estado'] === 'emitida');
	$totales_subtotal = array_sum(array_map(fn($f) => (float)$f['subtotal'], $facturasTotales));
	$totales_isv      = array_sum(array_map(fn($f) => (float)$f['isv_total'], $facturasTotales));
	$totales_total    = array_sum(array_map(fn($f) => (float)$f['total'], $facturasTotales));

	if ($fecha_desde && $fecha_hasta) {
		$totalesLabel = 'Totales ' . date('d/m/Y', strtotime($fecha_desde)) . ' – ' . date('d/m/Y', strtotime($fecha_hasta));
	} elseif ($fecha_desde) {
		$totalesLabel = 'Totales desde ' . date('d/m/Y', strtotime($fecha_desde));
	} elseif ($fecha_hasta) {
		$totalesLabel = 'Totales hasta ' . date('d/m/Y', strtotime($fecha_hasta));
	} else {
		$totalesLabel = 'Totales (filtro aplicado)';
	}
} else {
	$stmtTotalesMesLista = $pdo->prepare("
		SELECT IFNULL(SUM(subtotal), 0) AS subtotal,
		       IFNULL(SUM(isv_15 + isv_18), 0) AS isv,
		       IFNULL(SUM(total), 0) AS total
		FROM facturas
		WHERE cliente_id = ? AND establecimiento_id = ? AND estado = 'emitida'
		  AND fecha_emision BETWEEN ? AND ?
	");
	$stmtTotalesMesLista->execute([$cliente_id, $establecimiento_activo, date('Y-m-01'), date('Y-m-t')]);
	$totalesMesLista = $stmtTotalesMesLista->fetch(PDO::FETCH_ASSOC);
	$totales_subtotal = (float)$totalesMesLista['subtotal'];
	$totales_isv      = (float)$totalesMesLista['isv'];
	$totales_total    = (float)$totalesMesLista['total'];
	$totalesLabel     = 'Totales ' . date('F Y');
}

// Facturas emitidas no declaradas — semáforo por días restantes del mes
$hoy              = new DateTime();
$fin_de_mes       = new DateTime($hoy->format('Y-m-t'));
$dias_restantes   = (int)$hoy->diff($fin_de_mes)->days; // 0 = hoy es el último día

if (!$fecha_desde && !$fecha_hasta) {
	// Sin filtro: solo mes actual; la alerta se muestra únicamente los últimos 3 días
	$alerta_desde = $hoy->format('Y-m-01');
	$alerta_hasta = $hoy->format('Y-m-t');
	$no_declaradas_facts = array_filter(
		$facturas,
		fn($f) =>
		$f['estado'] === 'emitida' &&
			(int)$f['requiere_subsanacion'] !== 1 && ($f['estado_declarada'] == 0 || $f['estado_declarada'] === 'no') &&
			$f['fecha_emision'] >= $alerta_desde &&
			$f['fecha_emision'] <= $alerta_hasta
	);
	$mostrar_alerta_isv = $dias_restantes <= 3;
} else {
	// Con filtro de fecha: siempre mostrar si hay sin declarar en ese rango
	$no_declaradas_facts = array_filter(
		$facturas,
		fn($f) =>
		$f['estado'] === 'emitida' &&
			(int)$f['requiere_subsanacion'] !== 1 && ($f['estado_declarada'] == 0 || $f['estado_declarada'] === 'no')
	);
	$mostrar_alerta_isv  = true;
	$dias_restantes      = null; // no aplica semáforo cuando hay filtro explícito
}
$no_declaradas_count = count($no_declaradas_facts);
$no_declaradas_isv   = array_sum(array_map(fn($f) => (float)$f['isv_total'], $no_declaradas_facts));

// Resumen "Facturas no declaradas" — mismas reglas que el dashboard (independiente de los filtros de la tabla):
// "atrasadas" = emitidas antes del mes actual y aún sin declarar; "mes actual" = emitidas este mes y sin declarar (informativo).
$primer_dia_mes_actual = date('Y-m-01');
$ultimo_dia_mes_actual = date('Y-m-t');

$stmtNoDeclAtrasadas = $pdo->prepare("
	SELECT COUNT(*) AS cantidad, IFNULL(SUM(isv_15 + isv_18), 0) AS isv_pendiente
	FROM facturas
	WHERE cliente_id = ? AND establecimiento_id = ?
	  AND estado = 'emitida' AND estado_declarada = 0 AND requiere_subsanacion <> 1
	  AND fecha_emision < ?
");
$stmtNoDeclAtrasadas->execute([$cliente_id, $establecimiento_activo, $primer_dia_mes_actual]);
$noDeclAtrasadas = $stmtNoDeclAtrasadas->fetch(PDO::FETCH_ASSOC);
$cant_no_decl_atrasadas = (int)$noDeclAtrasadas['cantidad'];
$isv_no_decl_atrasadas  = (float)$noDeclAtrasadas['isv_pendiente'];

// Identificar las pendientes aunque la tabla esté mostrando otro CAI o período.
$stmtDetalleNoDecl = $pdo->prepare("SELECT correlativo, fecha_emision, cai_id, (isv_15 + isv_18) AS isv
    FROM facturas WHERE cliente_id = ? AND establecimiento_id = ?
    AND estado = 'emitida' AND estado_declarada = 0 AND requiere_subsanacion <> 1 AND fecha_emision < ?
    ORDER BY fecha_emision ASC, id ASC LIMIT 8");
$stmtDetalleNoDecl->execute([$cliente_id, $establecimiento_activo, $primer_dia_mes_actual]);
$detalleNoDecl = $stmtDetalleNoDecl->fetchAll(PDO::FETCH_ASSOC);


$stmtNoDeclMesActual = $pdo->prepare("
	SELECT COUNT(*) AS cantidad, IFNULL(SUM(isv_15 + isv_18), 0) AS isv_mes_actual
	FROM facturas
	WHERE cliente_id = ? AND establecimiento_id = ?
	  AND estado = 'emitida' AND estado_declarada = 0 AND requiere_subsanacion <> 1
	  AND fecha_emision >= ? AND fecha_emision <= ?
");
$stmtNoDeclMesActual->execute([$cliente_id, $establecimiento_activo, $primer_dia_mes_actual, $ultimo_dia_mes_actual]);
$noDeclMesActual = $stmtNoDeclMesActual->fetch(PDO::FETCH_ASSOC);
$cant_no_decl_mes_actual = (int)$noDeclMesActual['cantidad'];
$isv_no_decl_mes_actual  = (float)$noDeclMesActual['isv_mes_actual'];
?>
<script src="https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js"></script>

<style>
.fh-nota-btn { display:inline-flex; align-items:center; gap:.25rem; padding:0; border:0; background:transparent; color:#2563eb; font-size:.7rem; line-height:1.4; text-align:left; cursor:pointer; }
.fh-nota-btn svg { width:13px; height:13px; flex-shrink:0; }
.fh-nota-btn:hover { color:#1d4ed8; text-decoration:underline; }
.fh-nota-btn:focus-visible { outline:2px solid #2563eb; outline-offset:2px; }
.fh-nota-modal { border-radius:16px !important; padding:0 0 1.25rem !important; text-align:left; }
.fh-nota-titulo { padding:1.4rem 1.5rem .4rem !important; font-size:1.15rem !important; color:#1e293b !important; text-align:left !important; }
.fh-nota-contenido { margin:.25rem 1.5rem 0 !important; overflow-wrap:anywhere; text-align:left !important; max-height:60vh; overflow-y:auto; font-size:.9rem !important; color:#475569 !important; line-height:1.7 !important; }
.fh-nota-factura { font-size:.78rem; color:#64748b; padding-bottom:1rem; border-bottom:1px solid #e2e8f0; margin-bottom:1rem; }
.fh-nota-texto { white-space:pre-wrap; margin:0; }
.fh-nota-privada { margin-top:1.2rem; font-size:.75rem; color:#64748b; }
.fh-nota-acciones { justify-content:flex-end !important; margin:1.2rem 1.5rem 0 !important; }


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
		grid-template-columns: repeat(5, minmax(0, 1fr));
		gap: .75rem;
		align-items: end;
	}

	.fh-filter-grid > div { min-width:0; }
	.fh-filter-grid select, .fh-filter-grid input { width:100%; min-width:0; max-width:100%; }
	.fh-filter-grid .fh-filter-btns { grid-column:1/-1; }
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

	.fh-toolbar { min-width:0; max-width:100%; }
	.fh-search-wrap { min-width:0; max-width:100%; }
	.fh-search { max-width:100%; }
	.fh-page { min-width:0; }
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

	.fh-actions {
		display: flex;
		gap: .35rem;
		align-items: center;
		flex-wrap: wrap;
	}

	.fh-select-col {
		width: 42px;
		text-align: center;
	}

	.fh-row-check {
		width: 16px;
		height: 16px;
		cursor: pointer;
		accent-color: #1d4ed8;
	}

	.fh-bulk-actions {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: .75rem;
		padding: .85rem 1rem;
		border-top: 1px solid var(--border);
		background: #f8fafc;
		flex-wrap: wrap;
	}

	.fh-bulk-text {
		font-size: .82rem;
		font-weight: 600;
		color: var(--text-main);
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

	/* Botón solo con ícono (el nombre sale al pasar el mouse) */
	.btn-fa-ico {
		padding: .32rem .5rem;
		font-size: .85rem;
		justify-content: center;
	}

	.btn-fa-view {
		background: #eff6ff;
		color: #1d4ed8;
		border-color: rgba(29, 78, 216, .2);
	}

	.btn-fa-cobro {
		background: #f0fdf4;
		color: #15803d;
		border-color: rgba(21, 128, 61, .25);
	}

	.btn-fa-cobro:hover {
		background: #15803d;
		color: #fff;
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

	.fh-highlight {
		background: #fef08a;
		border-radius: 3px;
		padding: 0 2px;
	}

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

	.corr-mono {
		font-family: 'Courier New', monospace;
		font-size: .82rem;
		font-weight: 600;
		color: #1e293b;
	}

	/* Situación: enviada · pagada · declarada como semáforo */
	.sit { display: flex; gap: .3rem; }
	.sit-ico { display: inline-flex; align-items: center; justify-content: center; width: 1.7rem; height: 1.7rem; border-radius: 50%; font-size: .8rem; border: 1px solid transparent; }
	.sit-ok { background: #ecfdf5; color: #059669; border-color: #a7f3d0; }
	.sit-no { background: #f1f5f9; color: #94a3b8; border-color: #e2e8f0; }
	.sit-pend { background: #fff7ed; color: #c2410c; border-color: #fed7aa; }
	.sit-na { background: #f8fafc; color: #cbd5e1; border-color: #f1f5f9; }
	.fh-sit-filtros { display: flex; gap: .35rem; flex-wrap: wrap; }
	td .fh-actions { flex-wrap: nowrap; }

	/* Badge declarada */
	.dec-si {
		background: #ecfdf5;
		color: #059669;
		border: 1px solid #a7f3d0;
	}

	.dec-no {
		background: #fff7ed;
		color: #c2410c;
		border: 1px solid #fed7aa;
	}

	/* Alerta ISV no declarado — semáforo */
	.isv-alert-card {
		border-radius: var(--radius);
		padding: 1rem 1.25rem;
		margin-bottom: 1.25rem;
		display: flex;
		align-items: flex-start;
		gap: .85rem;
		border-left-width: 4px;
		border-left-style: solid;
	}

	.isv-alert-card.semaforo-green {
		background: #f0fdf4;
		border-color: #86efac;
		border-left-color: #16a34a;
	}

	.isv-alert-card.semaforo-yellow {
		background: #fefce8;
		border-color: #fde047;
		border-left-color: #ca8a04;
	}

	.isv-alert-card.semaforo-orange {
		background: #fff7ed;
		border-color: #fed7aa;
		border-left-color: #f97316;
	}

	.isv-alert-card.semaforo-red {
		background: #fef2f2;
		border-color: #fca5a5;
		border-left-color: #dc2626;
	}

	.isv-alert-card.semaforo-green .isv-alert-title {
		color: #15803d;
	}

	.isv-alert-card.semaforo-yellow .isv-alert-title {
		color: #854d0e;
	}

	.isv-alert-card.semaforo-orange .isv-alert-title {
		color: #9a3412;
	}

	.isv-alert-card.semaforo-red .isv-alert-title {
		color: #991b1b;
	}

	.isv-alert-card.semaforo-green .isv-alert-body {
		color: #166534;
	}

	.isv-alert-card.semaforo-yellow .isv-alert-body {
		color: #713f12;
	}

	.isv-alert-card.semaforo-orange .isv-alert-body {
		color: #7c2d12;
	}

	.isv-alert-card.semaforo-red .isv-alert-body {
		color: #7f1d1d;
	}

	.isv-alert-card.semaforo-green .isv-chip {
		background: #dcfce7;
		border-color: #86efac;
		color: #15803d;
	}

	.isv-alert-card.semaforo-yellow .isv-chip {
		background: #fef9c3;
		border-color: #fde047;
		color: #854d0e;
	}

	.isv-alert-card.semaforo-orange .isv-chip {
		background: #ffedd5;
		border-color: #fdba74;
		color: #9a3412;
	}

	.isv-alert-card.semaforo-red .isv-chip {
		background: #fee2e2;
		border-color: #fca5a5;
		color: #991b1b;
	}

	.isv-alert-icon {
		font-size: 1.5rem;
		flex-shrink: 0;
		margin-top: 2px;
	}

	.isv-alert-title {
		font-weight: 700;
		font-size: .95rem;
		margin-bottom: .3rem;
	}

	.isv-alert-body {
		font-size: .83rem;
		line-height: 1.5;
	}

	.isv-fact-chips {
		display: flex;
		flex-wrap: wrap;
		gap: .4rem;
		margin-top: .5rem;
	}

	.isv-chip {
		border-radius: 6px;
		padding: .2rem .55rem;
		font-size: .75rem;
		font-weight: 600;
		border-width: 1px;
		border-style: solid;
	}

	.fh-dual-card-row {
		display: grid;
		grid-template-columns: 1fr 1fr 1fr;
		gap: 1.25rem;
		margin-bottom: 1.25rem;
	}

	@media(max-width:1024px) {
		.fh-dual-card-row {
			grid-template-columns: 1fr 1fr;
		}
	}

	@media(max-width:1199px) {
		.fh-filter-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
	}

	@media(max-width:768px) {
		.fh-dual-card-row {
			grid-template-columns: 1fr;
		}

		.fh-filter-grid {
			grid-template-columns: 1fr 1fr;
		}

		.fh-filter-btns {
			grid-column: 1/-1;
		}

	}

	@media(max-width:480px) {
		.fh-filter-grid {
			grid-template-columns: 1fr;
		}

        .fh-filter-card, .fh-card-header { padding:1rem; }
        .fh-toolbar { width:100%; }
        .fh-search-wrap { flex:1 1 100%; width:100%; }
        .fh-search { width:100%; }
        .fh-filter-btns { flex-wrap:wrap; }

	}
</style>

<div class="fh-page container">

	<!-- Header -->
	<div class="fh-header">
		<div>
			<h4 class="fh-header-title"><i class="bi bi-clock-history me-2"></i>Historial de Facturas</h4>
			<p class="fh-header-sub">Busca, filtra, imprime y descarga las facturas emitidas.</p>
		</div>
		<?php if (!empty($datos['logo_url'])): ?>
			<img src="<?= htmlspecialchars($datos['logo_url']) ?>" alt="Logo" class="fh-header-logo">
		<?php endif; ?>
	</div>

	<!-- Resumen (se reemplaza al filtrar sin recargar) -->
	<div id="fhZonaResumen">
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
		<div class="fh-stat fh-ver-pendientes" role="button" title="Ver solo las facturas pendientes de pago" style="cursor:pointer">
			<div class="fh-stat-icon red"><i class="bi bi-hourglass-split"></i></div>
			<div>
				<div class="fh-stat-val"><?= $pendientes_count ?></div>
				<div class="fh-stat-lbl">Pendientes de pago<?php if ($pendientes_monto > 0): ?> · L
					<?= number_format($pendientes_monto, 2) ?><?php endif; ?></div>
			</div>
		</div>
		<!-- ← FIX: número_format con 2 decimales ↓ -->
		<div class="fh-stat">
			<div class="fh-stat-icon teal"><i class="bi bi-currency-dollar"></i></div>
			<div>
				<div class="fh-stat-val" style="font-size:1rem;">L <?= number_format($total_monto, 2) ?></div>
				<div class="fh-stat-lbl">Monto emitido</div>
			</div>
		</div>
	</div>

	<!-- Pendientes de pago / Totales -->
	<?php
	$hayAtrasadas = $cant_no_decl_atrasadas > 0;
	$colorNoDecl = $hayAtrasadas ? '#c2410c' : '#15803d';
	$borderNoDecl = $hayAtrasadas ? '#fed7aa' : 'var(--border)';
	$bgNoDecl = $hayAtrasadas ? '#fff7ed' : '#fff';
	?>
	<div class="fh-dual-card-row">
		<div class="fh-card" style="margin-bottom:0;<?= $pendientes_count > 0 ? 'border-color:#fecaca;' : '' ?>">
			<div class="fh-card-header">
				<span class="fh-card-title"><i class="bi bi-hourglass-split text-danger"></i> Pendientes de pago</span>
				<?php if ($pendientes_count > 0): ?><span class="d-flex gap-2">
					<a href="#" class="fh-ver-pendientes small fw-semibold text-decoration-none"><i class="bi bi-funnel"></i> Ver en la lista</a>
					<?php if (permisoPuede($pdo, 'cuentas_cobrar')): ?><a href="cuentas_cobrar" class="small fw-semibold text-decoration-none"><i class="bi bi-cash-coin"></i> Cuentas por cobrar</a><?php endif; ?>
				</span><?php endif; ?>
			</div>
			<div style="padding:.25rem 1.25rem 1.1rem;">
				<?php if ($pendientes_count > 0): ?>
					<div style="display:flex;justify-content:space-between;margin-bottom:.35rem;"><span
							class="text-muted" style="font-size:.85rem;">Facturas</span><strong><?= $pendientes_count ?></strong>
					</div>
					<div style="display:flex;justify-content:space-between;padding-top:.5rem;border-top:1px solid var(--border);">
						<strong>Monto adeudado</strong><strong style="color:#dc2626;">L
							<?= number_format($pendientes_monto, 2) ?></strong>
					</div>
				<?php else: ?>
					<div style="color:#15803d;"><i class="bi bi-check-circle-fill"></i> No hay facturas pendientes de pago.
					</div>
				<?php endif; ?>
			</div>
		</div>
		<div class="fh-card" style="margin-bottom:0;">
			<div class="fh-card-header">
				<span class="fh-card-title"><i class="bi bi-currency-dollar text-success"></i> <?= htmlspecialchars($totalesLabel) ?></span>
			</div>
			<div style="padding:.25rem 1.25rem 1.1rem;">
				<div style="display:flex;justify-content:space-between;margin-bottom:.35rem;"><span class="text-muted"
						style="font-size:.85rem;">Subtotal</span><strong>L <?= number_format($totales_subtotal, 2) ?></strong>
				</div>
				<div style="display:flex;justify-content:space-between;margin-bottom:.35rem;"><span class="text-muted"
						style="font-size:.85rem;">ISV</span><strong>L <?= number_format($totales_isv, 2) ?></strong></div>
				<div style="display:flex;justify-content:space-between;padding-top:.5rem;border-top:1px solid var(--border);">
					<strong>Total</strong><strong style="color:#15803d;">L <?= number_format($totales_total, 2) ?></strong>
				</div>
			</div>
		</div>
		<div class="fh-card" style="margin-bottom:0;border-color:<?= $borderNoDecl ?>;background:<?= $bgNoDecl ?>;">
			<div class="fh-card-header">
				<span class="fh-card-title" style="color:<?= $colorNoDecl ?>;"><i
						class="bi bi-exclamation-triangle-fill"></i> Facturas no declaradas</span>
			</div>
			<div style="padding:.25rem 1.25rem 1.1rem;color:<?= $colorNoDecl ?>;">
				<?php if ($hayAtrasadas): ?>
					<div class="mb-1"><strong><?= $cant_no_decl_atrasadas ?></strong> factura<?= $cant_no_decl_atrasadas > 1 ? 's' : '' ?> atrasada<?= $cant_no_decl_atrasadas > 1 ? 's' : '' ?></div>
					<div class="mb-1">ISV pendiente: <strong>L <?= number_format($isv_no_decl_atrasadas, 2) ?></strong></div>
                    <ul class="list-unstyled small mb-1">
                        <?php foreach ($detalleNoDecl as $pendiente): ?>
                            <li class="d-flex flex-wrap align-items-center gap-2 py-1 border-bottom">
                                <a class="fw-semibold" href="lista_facturas?cai_id=<?= (int)$pendiente['cai_id'] ?>&amp;buscar=<?= urlencode($pendiente['correlativo']) ?>" title="Mostrar esta factura en el historial">
                                    <?= htmlspecialchars(substr($pendiente['correlativo'], -8)) ?> <i class="bi bi-search" aria-hidden="true"></i>
                                </a>
                                <span><?= date('d/m/Y', strtotime($pendiente['fecha_emision'])) ?></span>
                                <?php if (substr($pendiente['fecha_emision'], 0, 4) !== date('Y')): ?><span class="badge bg-danger-subtle text-danger-emphasis">Año <?= htmlspecialchars(substr($pendiente['fecha_emision'], 0, 4)) ?></span><?php endif; ?>
                                <?php if ($caix && (int)$pendiente['cai_id'] !== (int)$caix): ?><span class="badge bg-secondary-subtle text-secondary-emphasis">Otro CAI</span><?php endif; ?>
                                <span class="ms-auto">ISV L <?= number_format((float)$pendiente['isv'], 2) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($cant_no_decl_atrasadas > count($detalleNoDecl)): ?><div class="small">Y <?= $cant_no_decl_atrasadas - count($detalleNoDecl) ?> más.</div><?php endif; ?>
                    <div class="small mt-2">Incluye todos los CAI y períodos. Pulsa el número para mostrar la factura.</div>

				<?php else: ?>
					<div><i class="bi bi-check-circle-fill"></i> No hay meses pendientes de declaración.</div>
				<?php endif; ?>
				<?php if ($cant_no_decl_mes_actual > 0): ?>
					<hr style="opacity:.25;margin:.6rem 0;">
					<div style="font-size:.85rem;"><strong>Mes actual:</strong> <?= $cant_no_decl_mes_actual ?> factura<?= $cant_no_decl_mes_actual > 1 ? 's' : '' ?> · ISV est. L
						<?= number_format($isv_no_decl_mes_actual, 2) ?></div>
					<div style="font-size:.78rem;color:#78716c;margin-top:.3rem;"><i class="bi bi-lightbulb"></i> Recuerda
						declarar antes del 30.</div>
				<?php endif; ?>
			</div>
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
								<option value="<?= $cai['id'] ?>" data-desde="<?= htmlspecialchars((string)$cai['fecha_recepcion']) ?>" data-activo="<?= (int)$cai['activo'] ?>" data-hasta="<?= htmlspecialchars((string)$cai['fecha_limite']) ?>" <?= ($caix == $cai['id']) ? 'selected' : '' ?>>
									<?= htmlspecialchars($cai['cai']) ?><?= $cai['activo'] ? ' ✅' : ' ⛔ Vencido' ?> |
									<?= $cai['rango_inicio'] ?>–<?= $cai['rango_fin'] ?>
								</option>
							<?php endforeach; ?>
						</select>
						<?php if (count($cais) === 1): ?>
							<input type="hidden" name="cai_id" value="<?= $cais[0]['id'] ?>">
						<?php endif; ?>
					</div>
					<div>
						<label class="fh-label">Año o mes</label>
						<select class="fh-select" id="fhPeriodo"><option value="">— Rango libre —</option></select>
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
					<div>
<label class="fh-label" for="fhEstado">Estado de factura</label>
<select class="fh-select" id="fhEstado" name="estado">
<option value="">Todos los estados</option>
<optgroup label="Estado de factura">
<?php foreach (['emitida'=>'Emitidas', 'anulada'=>'Anuladas', 'borrador'=>'Borradores'] as $valorEstado=>$textoEstado): ?>
<option value="<?= $valorEstado ?>" <?= $estadoFiltro === $valorEstado ? 'selected' : '' ?>><?= $textoEstado ?></option>
<?php endforeach; ?>
</optgroup>
<optgroup label="Pago">
<option value="pago_pendiente" <?= $estadoFiltro === 'pago_pendiente' ? 'selected' : '' ?>>Pendientes de pago</option>
<option value="pagada" <?= $estadoFiltro === 'pagada' ? 'selected' : '' ?>>Pagadas</option>
</optgroup>
<option disabled>────────────────────</option>
<optgroup label="Seguimiento de subsanación">
<?php foreach (['1'=>'Requieren subsanación', '2'=>'Subsanadas', '0'=>'Sin subsanación'] as $valorSub=>$textoSub): ?>
<option value="sub_<?= $valorSub ?>" <?= $estadoFiltro === '' && $subsanacion === (string)$valorSub ? 'selected' : '' ?>><?= $textoSub ?><?= (string)$valorSub === '1' ? ' (' . $totalSubsanaciones . ')' : '' ?></option>
<?php endforeach; ?>
</optgroup>
</select></div>
<div class="fh-filter-btns">
						<button type="submit" class="btn-filter"><i class="bi bi-search"></i> Filtrar</button>
						<a href="?" class="btn-clear-filter"><i class="bi bi-x-lg"></i> Limpiar</a>
					</div>
				</div>
			</form>
			<script>
				// Filtros automáticos y sin recargar: al cambiar el CAI, el período o las fechas se piden los datos y se
				// reemplazan el resumen y la tabla. «Año o mes» solo ofrece los meses de la vigencia del CAI elegido.
				(() => {
					const f = document.getElementById('fhFilterForm');
					const desde = f.querySelector('[name=fecha_desde]'), hasta = f.querySelector('[name=fecha_hasta]');
					const selEstado = f.querySelector('[name=estado]');

					const selCai = f.querySelector('select[name=cai_id]'), selPer = document.getElementById('fhPeriodo');
					const btn = f.querySelector('.btn-filter'), btnHtml = btn.innerHTML;
					const MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
					const pad = n => String(n).padStart(2, '0');
					const finMes = (y, m) => `${y}-${pad(m)}-${pad(new Date(y, m, 0).getDate())}`;
					const hoy = new Date(), mesHoy = hoy.getFullYear() * 12 + hoy.getMonth();
					const llenarPeriodos = () => {
						// Vigencia: la del CAI elegido; con «Todos los CAI», de la recepción más antigua al vencimiento más reciente
						const ops = selCai.value ? [selCai.selectedOptions[0]] : [...selCai.options].filter(o => o.value);
						const ini = ops.map(o => o.dataset.desde).filter(Boolean).sort()[0];
						const fin = ops.map(o => o.dataset.hasta).filter(Boolean).sort().pop();
						let html = '<option value="">— Rango libre —</option>';
						if (ini && fin) {
							const a = +ini.slice(0, 4) * 12 + (+ini.slice(5, 7) - 1);
							const b = Math.min(+fin.slice(0, 4) * 12 + (+fin.slice(5, 7) - 1), Math.max(mesHoy, a));   // no ofrecer meses futuros
							let anios = '', meses = '';
							for (let y = Math.floor(b / 12); y >= Math.floor(a / 12); y--) anios += `<option value="${y}">Todo ${y}</option>`;
							for (let k = b; k >= a; k--) meses += `<option value="${Math.floor(k / 12)}-${pad(k % 12 + 1)}">${MESES[k % 12]} ${Math.floor(k / 12)}</option>`;
							html += `<optgroup label="Año completo">${anios}</optgroup><optgroup label="Mes">${meses}</optgroup>`;
						}
						selPer.innerHTML = html;
						marcarPeriodo();
					};
					const marcarPeriodo = () => {
						const d = desde.value, h = hasta.value;
						let v = '';
						if (d && h && d.slice(5) === '01-01' && h.slice(5) === '12-31' && d.slice(0, 4) === h.slice(0, 4)) v = d.slice(0, 4);
						else if (d && h && d.slice(8) === '01' && h === finMes(+d.slice(0, 4), +d.slice(5, 7))) v = d.slice(0, 7);
						selPer.value = [...selPer.options].some(o => o.value === v) ? v : '';
					};
					let pedido = 0;
					const aplicar = async () => {
						const qs = new URLSearchParams();
						for (const [k, v] of new FormData(f)) if (k !== '_csrf' && v !== '') qs.set(k, v);
						if (selCai && !selCai.disabled && !selCai.value) qs.set('cai_id', '');   // «Todos los CAI» explícito
						const url = location.pathname + (qs.toString() ? '?' + qs : '');
						const yo = ++pedido;
						btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Filtrando…';
						try {
							const r = await fetch(url, { credentials: 'same-origin' });
							if (!r.ok) throw new Error('HTTP ' + r.status);
							const doc = new DOMParser().parseFromString(await r.text(), 'text/html');
							if (yo !== pedido) return;   // llegó otra búsqueda más nueva
							const zonas = ['fhZonaResumen', 'fhZonaTabla'].map(id => [document.getElementById(id), doc.getElementById(id)]);
							if (zonas.some(([act, nva]) => !act || !nva)) throw new Error('Respuesta incompleta');
							zonas.forEach(([act, nva]) => act.replaceWith(nva));
							fhInitTabla();
							history.replaceState(null, '', url);
						} catch (e) {
							if (yo === pedido) location.href = url;   // si algo falla, carga normal
						} finally {
							if (yo === pedido) btn.innerHTML = btnHtml;
						}
					};
					selEstado.addEventListener('change', () => {
 if (['sub_1', 'sub_2', 'pago_pendiente'].includes(selEstado.value)) { if (selCai && !selCai.disabled) selCai.value = ''; desde.value = hasta.value = ''; llenarPeriodos(); }   // todas, de cualquier período
 aplicar();
});
f.addEventListener('submit', e => { e.preventDefault(); aplicar(); });
					// «Pendientes de pago» (tarjeta o «Ver en la lista»): filtra la lista sin salir de la página
					document.addEventListener('click', e => {
						const v = e.target.closest('.fh-ver-pendientes');
						if (!v) return;
						e.preventDefault();
						selEstado.value = 'pago_pendiente';
						selEstado.dispatchEvent(new Event('change'));
						document.getElementById('fhZonaTabla')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
					});
					f.querySelector('.btn-clear-filter')?.addEventListener('click', e => {
						e.preventDefault();
 selEstado.value = '';
						desde.value = hasta.value = '';
						if (selCai && !selCai.disabled) {
							const activo = [...selCai.options].find(o => o.dataset.activo === '1');
							selCai.value = activo ? activo.value : '';
						}
						llenarPeriodos();
						aplicar();
					});
					selPer.addEventListener('change', () => {
						const v = selPer.value;
						if (!v) return;
						if (/^\d{4}$/.test(v)) { desde.value = v + '-01-01'; hasta.value = v + '-12-31'; }
						else { desde.value = v + '-01'; hasta.value = finMes(+v.slice(0, 4), +v.slice(5, 7)); }
						aplicar();
					});
					selCai?.addEventListener('change', () => {
						llenarPeriodos();
						// Si las fechas quedan fuera de la vigencia del nuevo CAI, se quita el filtro de fechas
						const o = selCai.value ? selCai.selectedOptions[0] : null;
						if (o && o.dataset.desde && ((hasta.value && hasta.value < o.dataset.desde.slice(0, 10)) || (desde.value && desde.value > o.dataset.hasta.slice(0, 10)))) desde.value = hasta.value = '';
						marcarPeriodo();
						aplicar();
					});
					[desde, hasta].forEach(i => i.addEventListener('change', () => { marcarPeriodo(); if (!i.value || i.value.length === 10) aplicar(); }));
					llenarPeriodos();
				})();
			</script>
		</div>
	<?php endif; ?>

	<!-- Alerta + tabla (se reemplazan al filtrar sin recargar) -->
	<div id="fhZonaTabla">
	<!-- Alerta facturas no declaradas -->
	<?php if ($no_declaradas_count > 0 && $mostrar_alerta_isv): ?>
		<?php
		// Período descriptivo
		if (!$fecha_desde && !$fecha_hasta) {
			$periodoAlerta = 'mes actual (' . $hoy->format('m/Y') . ')';
		} elseif ($fecha_desde && $fecha_hasta) {
			$periodoAlerta = htmlspecialchars($fecha_desde) . ' al ' . htmlspecialchars($fecha_hasta);
		} elseif ($fecha_desde) {
			$periodoAlerta = 'desde ' . htmlspecialchars($fecha_desde);
		} else {
			$periodoAlerta = 'hasta ' . htmlspecialchars($fecha_hasta);
		}

		// Clase semáforo
		if ($dias_restantes === null) {
			$semaforoClass = 'semaforo-orange'; // filtro manual, color neutro
			$semaforoIcon  = '⚠️';
			$diasTexto     = '';
		} elseif ($dias_restantes === 0) {
			$semaforoClass = 'semaforo-red';
			$semaforoIcon  = '🔴';
			$diasTexto     = ' · Hoy vence el plazo de declaración';
		} elseif ($dias_restantes === 1) {
			$semaforoClass = 'semaforo-orange';
			$semaforoIcon  = '🟠';
			$diasTexto     = ' · Queda 1 día para declarar';
		} elseif ($dias_restantes === 2) {
			$semaforoClass = 'semaforo-yellow';
			$semaforoIcon  = '🟡';
			$diasTexto     = ' · Quedan 2 días para declarar';
		} else {
			$semaforoClass = 'semaforo-green';
			$semaforoIcon  = '🟢';
			$diasTexto     = ' · Quedan ' . $dias_restantes . ' días para declarar';
		}
		?>
		<div class="isv-alert-card <?= $semaforoClass ?>">
			<div class="isv-alert-icon"><?= $semaforoIcon ?></div>
			<div style="flex:1">
				<div class="isv-alert-title">
					<?= $no_declaradas_count ?> factura<?= $no_declaradas_count > 1 ? 's' : '' ?>
					emitida<?= $no_declaradas_count > 1 ? 's' : '' ?> sin declarar — ISV pendiente: L
					<?= number_format($no_declaradas_isv, 2) ?><?= $diasTexto ?>
				</div>
				<div class="isv-alert-body">
					Período: <?= $periodoAlerta ?>. Las siguientes facturas están emitidas pero aún no han sido declaradas
					ante SAR. El ISV debe declararse cuando se emite la factura, independientemente de si el cliente la ha
					pagado.
				</div>
				<div class="isv-fact-chips">
					<?php foreach ($no_declaradas_facts as $nd): ?>
						<span class="isv-chip"
							title="<?= htmlspecialchars($nd['receptor']) ?> — L <?= number_format($nd['isv_total'], 2) ?> ISV">
							<?= htmlspecialchars($nd['correlativo']) ?>
						</span>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
	<?php endif; ?>

	<!-- Table card -->
	<div class="fh-card">
		<div class="fh-card-header">
			<span class="fh-card-title"><i class="bi bi-table"></i> Facturas</span>
			<div class="fh-toolbar" style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
				<a href="generar_factura" class="btn-filter" style="text-decoration:none;"><i class="bi bi-plus-lg"></i>
					Nueva Factura</a>
				<div class="fh-search-wrap">
					<i class="bi bi-search"></i>
					<input type="text" id="fhSearch" class="fh-search"
						placeholder="Buscar correlativo, cliente, estado…" autocomplete="off">
					<button class="fh-clear-btn" id="fhClear"><i class="bi bi-x-lg"></i></button>
				</div>
				<div class="fh-sit-filtros" title="Filtrar por situación (se combinan)">
					<select class="fh-per-page" data-sit="env" aria-label="Filtrar por enviada"><option value="">Enviada: todas</option><option value="1">Enviada: sí</option><option value="0">Enviada: no</option></select>
					<select class="fh-per-page" data-sit="pag" aria-label="Filtrar por pagada"><option value="">Pagada: todas</option><option value="1">Pagada: sí</option><option value="0">Pagada: no</option></select>
					<select class="fh-per-page" data-sit="dec" aria-label="Filtrar por declarada"><option value="">Declarada: todas</option><option value="1">Declarada: sí</option><option value="0">Declarada: pendiente</option></select>
				</div>
				<select class="fh-per-page" id="fhPerPage">
					<option value="10" selected>10/pág</option>
					<option value="25">25/pág</option>
					<option value="50">50/pág</option>
					<option value="100">100/pág</option>
					<option value="200">200/pág</option>
					<option value="300">300/pág</option>
					<option value="100000">Todas</option>
				</select>
				<span class="fh-result-badge" id="fhBadge"><?= $total_facturas ?> registros</span>
			</div>
		</div>

		<div class="fh-table-wrap" role="region" aria-label="Listado de facturas, desliza horizontalmente para ver todas las columnas" tabindex="0">
			<div class="fh-bulk-actions" id="fhBulkActions" style="display:none;">
				<span class="fh-bulk-text" id="fhBulkText">No hay facturas seleccionadas</span>
				<div class="fh-actions">
					<button type="button" id="fhBulkPdfBtn" class="btn-fa btn-fa-view"><i
							class="bi bi-file-earmark-pdf"></i> Descargar PDFs</button>
					<button type="button" id="fhBulkXlsxBtn" class="btn-fa btn-fa-view"><i
							class="bi bi-file-earmark-excel"></i> Descargar XLSX</button>
					<?php if (permisoPuede($pdo, 'cobros_programados')): ?><button type="button" id="fhBulkCorreoBtn" class="btn-fa btn-fa-view" title="Abre «Cobros por correo» con estas facturas como PDF adjunto"><i
							class="bi bi-send"></i> Enviar por correo</button><?php endif; ?>
					<button type="button" id="fhBulkMensajeBtn" class="btn-fa btn-fa-edit"><i
							class="bi bi-envelope-fill"></i> Redactar correo</button>
					<button type="button" id="fhBulkAnularBtn" class="btn-fa btn-fa-warn"><i
							class="bi bi-slash-circle"></i> Anular seleccionadas</button>
					<button type="button" id="fhBulkEditBtn" class="btn-fa btn-fa-edit"><i
							class="bi bi-pencil-fill"></i> Editar estado</button>
				</div>
			</div>
			<table class="fh-table" id="fhTable">
				<thead>
					<tr>
						<th class="fh-select-col"><input type="checkbox" id="fhSelectAll" class="fh-row-check"></th>
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
						<th data-col="5" title="Enviada · Pagada · Declarada SAR. Al ordenar, primero las que tienen menos pendientes (de nuevo: las que más)"><i class="bi bi-stoplights me-1"></i>Situación<i class="bi bi-arrow-up sort-icon"></i>
						</th>
						<th>Acciones</th>
						<th>PDF</th>
					</tr>
				</thead>
				<tbody id="fhBody">
					<?php foreach ($facturas as $f):
						$corrStr   = trim((string)$f['correlativo']);
						$ultimoStr = trim((string)($ultimoCorrelativoCAI ?? ''));
						$puedeElim = ($corrStr === $ultimoStr);
						$estadoCls = match ($f['estado']) {
							'emitida' => 'st-emitida',
							'anulada' => 'st-anulada',
							default   => 'st-borrador'
						};
					?>
						<tr data-env="<?= (int)$f['enviada_receptor'] === 1 ? 1 : 0 ?>" data-pag="<?= (int)$f['pagada'] === 1 ? 1 : 0 ?>" data-dec="<?= $f['estado'] === 'anulada' ? 'na' : (($f['estado_declarada'] == 1 || $f['estado_declarada'] === 'si') ? 1 : 0) ?>"
							data-search="<?= strtolower(htmlspecialchars($f['correlativo'] . ' ' . $f['receptor'] . ' ' . $f['programa'] . ' ' . $f['estado'] . ' ' . date('d/m/Y', strtotime($f['fecha_emision'])))) ?>">
							<td class="fh-select-col"><input type="checkbox" class="fh-row-check fh-row-select"
									data-factura-id="<?= $f['id'] ?>" data-receptor-id="<?= (int)$f['receptor_id'] ?>" data-estado="<?= htmlspecialchars($f['estado']) ?>"></td>
							<td data-sort-val="<?= htmlspecialchars($f['correlativo']) ?>"><span class="corr-mono" data-col="corr"><?= htmlspecialchars($f['correlativo']) ?></span>
								<?php if ($ev = $enviosCorreo[$f['id']] ?? null):
									$mesCorto = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
									$evFecha = fn($t) => (int)date('j', $t) . ' ' . $mesCorto[(int)date('n', $t)];
									if (in_array($ev['estado'], ['programado', 'enviando'], true)) {
										$t = strtotime($ev['programado_para']);
										$evTxt = ['programado', 'bi-envelope', 'bi-clock', 'Programado ' . $evFecha($t), 'Envío por correo programado para el ' . date('d/m/Y g:i a', $t)];
									} elseif ($ev['estado'] === 'enviado') {
										$t = strtotime($ev['enviado_en'] ?: $ev['programado_para']);
										$evTxt = ['enviado', 'bi-envelope-check', '', 'Enviada ' . $evFecha($t), 'Enviada por correo el ' . date('d/m/Y g:i a', $t)];
									} else {
										$evTxt = ['error', 'bi-envelope-exclamation', '', 'Error de envío', 'El envío por correo falló: revísalo en Cobros por correo'];
									} ?>
									<a href="cobros_programados" class="app-envio app-envio-<?= $evTxt[0] ?>" title="<?= htmlspecialchars($evTxt[4]) ?>"><i class="bi <?= $evTxt[1] ?>"></i><?php if ($evTxt[2]): ?><i class="bi <?= $evTxt[2] ?>"></i><?php endif; ?> <?= $evTxt[3] ?></a>
								<?php endif; ?>
							</td>
							<td data-col="fecha" data-sort-val="<?= htmlspecialchars((string)$f['fecha_emision']) ?>"><span data-hl><?= date('d/m/Y', strtotime($f['fecha_emision'])) ?></span>
								<?php if (!isset($fechaDesorden[(int)$f['id']]) && substr((string)$f['fecha_emision'], 0, 10) > date('Y-m-d')): ?><span class="badge text-bg-info d-block mt-1" style="width:max-content" title="Fecha de emisión posterior a hoy, en orden con las demás facturas"><i class="bi bi-calendar-event me-1"></i>Fecha futura</span><?php endif; ?>
								<?php if (isset($fechaDesorden[(int)$f['id']])): ?><a href="editar_factura?id=<?= (int)$f['id'] ?>" class="badge text-bg-warning text-decoration-none d-block mt-1" style="width:max-content" title="<?= htmlspecialchars(implode('. ', $fechaDesorden[(int)$f['id']]) . '. Abrí Editar para ver la fecha sugerida.') ?>"><i class="bi bi-exclamation-triangle-fill me-1"></i>Revisar fecha</a><?php endif; ?></td>
							<td><span data-col="receptor"><?= htmlspecialchars($f['receptor']) ?></span><?= programaEtiqueta($f['programa']) ?>
<?php if (!empty($f['requiere_subsanacion']) || !empty($f['notas_internas'])): ?>
<div class="mt-1"><button type="button" class="fh-nota-btn"
 data-factura="<?= htmlspecialchars($f['correlativo'], ENT_QUOTES, 'UTF-8') ?>"
 data-nota="<?= htmlspecialchars($f['notas_internas'] ?: ((int)$f['requiere_subsanacion'] === 2 ? 'Subsanación resuelta. Sin nota interna adicional.' : 'Esta factura requiere subsanación. Aún no se ha agregado una nota interna.'), ENT_QUOTES, 'UTF-8') ?>"
 aria-label="Ver nota interna de factura <?= htmlspecialchars($f['correlativo'], ENT_QUOTES, 'UTF-8') ?>">
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h6"/></svg>
<?= (int)$f['requiere_subsanacion'] === 2 ? 'Subsanada · Ver nota' : ((int)$f['requiere_subsanacion'] === 1 ? 'Subsanación · Ver nota' : 'Ver nota interna') ?>
</button></div>
<?php endif; ?>
</td>
							<td data-col="total" data-sort-val="<?= $f['total'] ?>" style="white-space:nowrap"><strong>L
									<?= number_format($f['total'], 2) ?></strong></td>
							<td>
								<span class="st-badge <?= $estadoCls ?>">
									<?= $f['estado'] === 'emitida' ? '<i class="bi bi-check-circle-fill"></i>' : ($f['estado'] === 'anulada' ? '<i class="bi bi-x-circle-fill"></i>' : '<i class="bi bi-pencil-fill"></i>') ?>
									<?= ucfirst($f['estado']) ?>
								</span>
							</td>
							<?php // Situación: enviada, pagada y declarada como semáforo (el orden usa cuántas faltan)
								$sitEnv = (int)$f['enviada_receptor'] === 1; $sitPag = (int)$f['pagada'] === 1;
								$sitAnul = $f['estado'] === 'anulada'; $sitDec = $f['estado_declarada'] == 1 || $f['estado_declarada'] === 'si';
								$sitFaltan = $sitAnul ? -1 : (!$sitEnv) + (!$sitPag) + (!$sitDec); ?>
							<td data-sort-val="<?= $sitFaltan ?>"><div class="sit">
								<span class="sit-ico <?= $sitEnv ? 'sit-ok' : 'sit-no' ?>" title="Enviada: <?= $sitEnv ? 'Sí' : 'No' ?>" aria-label="Enviada: <?= $sitEnv ? 'Sí' : 'No' ?>"><i class="bi bi-send-fill"></i></span>
								<span class="sit-ico <?= $sitPag ? 'sit-ok' : 'sit-no' ?>" title="Pagada: <?= $sitPag ? 'Sí' : 'No' ?>" aria-label="Pagada: <?= $sitPag ? 'Sí' : 'No' ?>"><i class="bi bi-cash-coin"></i></span>
								<?php if ($sitAnul): ?><span class="sit-ico sit-na" title="Declarada SAR: no aplica (anulada)" aria-label="Declarada SAR: no aplica"><i class="bi bi-bank"></i></span>
								<?php else: ?><span class="sit-ico <?= $sitDec ? 'sit-ok' : 'sit-pend' ?>" title="Declarada SAR: <?= $sitDec ? 'Sí' : 'Pendiente' ?>" aria-label="Declarada SAR: <?= $sitDec ? 'Sí' : 'Pendiente' ?>"><i class="bi bi-bank"></i></span><?php endif; ?>
							</div></td>
							<td>
								<div class="fh-actions">
									<?php if ($f['estado'] === 'emitida'): ?>
										<button onclick="accionFactura(<?= $f['id'] ?>,'anular')" class="btn-fa btn-fa-ico btn-fa-warn" title="Anular" aria-label="Anular"><i
												class="bi bi-slash-circle"></i></button>
										<?php if ($puedeElim || $esAdmin): ?>
											<button type="button" onclick="editarFacturaMenu(<?= $f['id'] ?>, <?= (int)$f['pagada'] ?>, <?= ($f['estado_declarada'] == 1 || $f['estado_declarada'] === 'si') ? 1 : 0 ?>, <?= (int)$f['enviada_receptor'] ?>)"
												class="btn-fa btn-fa-ico btn-fa-edit" title="Editar" aria-label="Editar"><i class="bi bi-pencil-fill"></i></button>
											<button onclick="accionFactura(<?= $f['id'] ?>,'eliminar')"
												class="btn-fa btn-fa-ico btn-fa-danger" title="Eliminar" aria-label="Eliminar"><i class="bi bi-trash3-fill"></i></button>
										<?php endif; ?>
									<?php elseif ($f['estado'] === 'anulada'): ?>
										<button onclick="accionFactura(<?= $f['id'] ?>,'restaurar')"
											class="btn-fa btn-fa-ico btn-fa-success" title="Reactivar" aria-label="Reactivar"><i class="bi bi-arrow-counterclockwise"></i></button>
									<?php elseif ($f['estado'] === 'borrador'): ?>
										<button onclick="accionFactura(<?= $f['id'] ?>,'restaurar')"
											class="btn-fa btn-fa-ico btn-fa-success" title="Reactivar" aria-label="Reactivar"><i class="bi bi-arrow-counterclockwise"></i></button>
									<?php endif; ?>
								</div>
								<?php if ($esAdmin && !$puedeElim && $f['estado'] === 'emitida'): ?>
									<div style="font-size:.7rem;color:var(--warning);margin-top:4px;white-space:nowrap;"><i
											class="bi bi-exclamation-triangle-fill"></i> No es la última del CAI</div>
								<?php endif; ?>
							</td>
							<td>
								<div class="fh-actions">
								<a href="ver_factura?id=<?= $f['id'] ?>" class="btn-fa btn-fa-ico btn-fa-view" target="_blank"
									rel="noopener noreferrer" title="Ver / PDF" aria-label="Ver / PDF"
									onclick="window.open(this.href, '_blank', 'noopener,noreferrer'); return false;"><i
										class="bi bi-printer-fill"></i></a>
									<?php if ($puedeCobrarLf && $f['estado'] === 'emitida' && empty($f['pagada'])): ?>
										<a href="cuentas_cobrar?cobrar=<?= (int)$f['id'] ?>" class="btn-fa btn-fa-ico btn-fa-cobro" title="Registrar cobro: el pago del cliente con su comprobante; al completar el total queda pagada" aria-label="Registrar cobro"><i class="bi bi-cash-coin"></i></a>
									<?php endif; ?>
								</div>
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
</div>

<script>
	// ?buscar=000-002-01-00000153 (desde el aviso de facturas no declaradas): la busca y la resalta
	window.addEventListener('load', () => {
		const b = (new URLSearchParams(location.search).get('buscar') || '').trim();
		const s = document.getElementById('fhSearch');
		if (!b || !s) return;
		s.value = b;
		s.dispatchEvent(new Event('input'));
		setTimeout(() => {
			const fila = Array.from(document.querySelectorAll('#fhBody tr')).find(tr => tr.style.display !== 'none' && (tr.dataset.search || '').includes(b.toLowerCase()));
			if (fila) { fila.classList.add('fh-encontrada'); fila.scrollIntoView({ block: 'center' }); }
			else if (window.Swal) Swal.fire('No aparece con este filtro', 'Revisa el CAI y las fechas del filtro: la factura ' + b + ' no está en la lista actual.', 'info');
		}, 450);
	});
</script>
<style>
	#fhBody tr.fh-encontrada td { background: #fef9c3 !important; }
</style>
<script>
	// Tabla: búsqueda, orden, paginación y selección. Se vuelve a iniciar cada vez que los filtros reemplazan la tabla.
	function fhInitTabla() {
		let query = '',
			page = 1,
			perPage = 10,
			sortCol = -1,
			sortDir = 'asc';
		const allRows = Array.from(document.querySelectorAll('#fhBody tr'));
		const $s = document.getElementById('fhSearch'),
			$cl = document.getElementById('fhClear'),
			$pp = document.getElementById('fhPerPage');
		const $bulkActions = document.getElementById('fhBulkActions'),
			$bulkText = document.getElementById('fhBulkText'),
			$bulkPdfBtn = document.getElementById('fhBulkPdfBtn'),
			$bulkMensajeBtn = document.getElementById('fhBulkMensajeBtn'),
			$bulkAnularBtn = document.getElementById('fhBulkAnularBtn'),
			$bulkEditBtn = document.getElementById('fhBulkEditBtn'),
			$selectAll = document.getElementById('fhSelectAll');
		const rowCheckboxes = Array.from(document.querySelectorAll('.fh-row-select'));
		const selectedIds = new Set();
		const $empty = document.getElementById('fhEmpty'),
			$sub = document.getElementById('fhEmptySub');
		const $info = document.getElementById('fhPageInfo'),
			$btns = document.getElementById('fhPageBtns'),
			$badge = document.getElementById('fhBadge');
		const headers = document.querySelectorAll('#fhTable thead th[data-col]');
		const hl = (t, q) => !q ? t : t.replace(new RegExp(`(${q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')})`, `gi`),
			'<mark class="fh-highlight">$1</mark>');
		// +1: la primera celda es la casilla de selección. data-sort-val manda (fecha AAAA-MM-DD, total numérico, correlativo sin las etiquetas de envío)
		const colTxt = (r, i) => {
			const td = r.querySelectorAll('td')[i + 1];
			return td ? (td.getAttribute('data-sort-val') ?? td.dataset.original ?? td.textContent).trim().toLowerCase() : '';
		};
		const comparar = (a, b) => {
			const na = parseFloat(a), nb = parseFloat(b);
			if (a !== '' && b !== '' && !isNaN(na) && !isNaN(nb) && /^-?[\d.]+$/.test(a) && /^-?[\d.]+$/.test(b)) return na - nb;   // números como números
			return tablaComparar(a, b);
		};
		const filtered = () => {
			const sit = [...document.querySelectorAll('[data-sit]')].filter(x => x.value !== '');
			const base = allRows.filter(r => (!query || r.dataset.search.includes(query.toLowerCase())) && sit.every(x => r.dataset[x.dataset.sit] === x.value));
			if (sortCol < 0) return base;
			return [...base].sort((a, b) => {
				const va = colTxt(a, sortCol),
					vb = colTxt(b, sortCol);
				return sortDir === 'asc' ? comparar(va, vb) : comparar(vb, va);
			});
		};
		const updIcons = () => headers.forEach(th => {
			const i = parseInt(th.dataset.col);
			th.classList.remove('sort-asc', 'sort-desc');
			if (i === sortCol) th.classList.add(sortDir === 'asc' ? 'sort-asc' : 'sort-desc');
		});
		const updateBulkState = () => {
			const count = selectedIds.size;
			$bulkText.textContent = count ?
				`${count} factura${count !== 1 ? 's' : ''} seleccionada${count !== 1 ? 's' : ''}` :
				'No hay facturas seleccionadas';
			$bulkActions.style.display = count ? 'flex' : 'none';
			if ($selectAll) {
				const allChecked = rowCheckboxes.length > 0 && rowCheckboxes.every(cb => cb.checked);
				const someChecked = rowCheckboxes.some(cb => cb.checked);
				$selectAll.checked = allChecked;
				$selectAll.indeterminate = someChecked && !allChecked;
			}
		};
		const render = () => {
			const rows = filtered(),
				total = rows.length,
				totPg = Math.max(1, Math.ceil(total / perPage));
			if (page > totPg) page = totPg;
			const s = (page - 1) * perPage,
				e = Math.min(s + perPage, total);
			allRows.forEach(r => r.style.display = 'none');
			rows.forEach(r => r.parentNode && r.parentNode.appendChild(r));   // el orden elegido se ve en la tabla (antes solo se calculaba)
			if (total === 0) {
				$empty.style.display = 'block';
				$sub.textContent = query ? `Sin resultados para "${query}".` : 'No hay facturas en este rango.';
			} else {
				$empty.style.display = 'none';
				rows.slice(s, e).forEach(r => {
					r.style.display = '';
					r.querySelectorAll('[data-col]').forEach(td => {
						const c = td.querySelector('[data-hl]') || td;   // si la celda trae avisos, solo se resalta su texto
						const o = c.dataset.original ?? c.textContent;
						c.dataset.original = o;
						c.innerHTML = hl(o, query);
					});
				});
			}
			$badge.textContent = `${total} registro${total!==1?'s':''}`;
			$info.textContent = total === 0 ? 'Sin resultados' : `Mostrando ${s+1}–${e} de ${total}`;
			buildPg(page, totPg);
		};
		const buildPg = (cur, tot) => {
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
		};
		headers.forEach(th => th.addEventListener('click', () => {
			const i = parseInt(th.dataset.col);
			sortDir = (sortCol === i && sortDir === 'asc') ? 'desc' : 'asc';
			sortCol = i;
			page = 1;
			updIcons();
			render();
		}));
		// Filtros de situación (enviada, pagada, declarada): se combinan con la búsqueda
		document.querySelectorAll('[data-sit]').forEach(x => x.addEventListener('change', () => { page = 1; render(); }));
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
			try { localStorage.setItem('fhPorPagina', $pp.value); } catch (e) {}
			render();
		});
		// Recordar cuántas por página (en este navegador)
		try { const g = localStorage.getItem('fhPorPagina'); if (g && $pp.querySelector(`option[value="${g}"]`)) { $pp.value = g; perPage = parseInt(g); } } catch (e) {}
		rowCheckboxes.forEach(cb => cb.addEventListener('change', () => {
			const id = cb.dataset.facturaId;
			if (cb.checked) selectedIds.add(id);
			else selectedIds.delete(id);
			updateBulkState();
		}));
		if ($selectAll) {
			$selectAll.addEventListener('change', () => {
				const checked = $selectAll.checked;
				rowCheckboxes.forEach(cb => {
					cb.checked = checked;
					const id = cb.dataset.facturaId;
					if (checked) selectedIds.add(id);
					else selectedIds.delete(id);
				});
				updateBulkState();
			});
		}
		$bulkPdfBtn?.addEventListener('click', () => {
			const ids = Array.from(selectedIds);
			if (!ids.length) return;
			bulkPdfMenu(ids);
		});
		document.getElementById('fhBulkXlsxBtn')?.addEventListener('click', () => {
			const ids = Array.from(selectedIds);
			if (!ids.length) return;
			// Descarga directa: el servidor arma el .xlsx con las facturas de esta empresa
			window.location.href = 'includes/facturas_xlsx.php?ids=' + encodeURIComponent(ids.join(','));
		});
		// Enviar por correo: un correo por cliente, en «Cobros por correo» (adjunta los PDF, CC de contactos, programar)
		document.getElementById('fhBulkCorreoBtn')?.addEventListener('click', () => {
			const cbs = rowCheckboxes.filter(cb => selectedIds.has(cb.dataset.facturaId));
			if (!cbs.length) return;
			if (cbs.some(cb => cb.dataset.estado !== 'emitida')) return Swal.fire('Facturas no válidas', 'Solo se pueden enviar facturas emitidas (no anuladas).', 'info');
			const clientes = new Set(cbs.map(cb => cb.dataset.receptorId));
			if (clientes.size > 1) return Swal.fire('Varios clientes', 'Cada correo va a un solo cliente. Selecciona facturas de un mismo cliente.', 'info');
			window.location.href = 'nuevo_cobro?receptor_id=' + [...clientes][0] + '&tipo=envio_factura&facturas=' + cbs.map(cb => cb.dataset.facturaId).join(',');
		});
		$bulkMensajeBtn?.addEventListener('click', () => {
			const ids = Array.from(selectedIds);
			if (!ids.length) return;
			bulkRedactarMensaje(ids);
		});
		$bulkAnularBtn?.addEventListener('click', () => {
			const ids = Array.from(selectedIds);
			if (!ids.length) return;
			bulkAccionFactura(ids, 'anular');
		});
		$bulkEditBtn?.addEventListener('click', () => {
			const ids = Array.from(selectedIds);
			if (!ids.length) return;
			bulkEditarEstados(ids);
		});
		updIcons();
		updateBulkState();
		render();
	}
	fhInitTabla();

	function bulkRedactarMensaje(facturaIds) {
		Swal.fire({
			title: 'Redactar correo',
			text: '¿Qué tipo de mensaje quieres generar?',
			icon: 'question',
			showDenyButton: true,
			showCancelButton: true,
			confirmButtonText: 'Envío de factura(s)',
			denyButtonText: 'Saldo pendiente',
			cancelButtonText: 'Cancelar',
			confirmButtonColor: '#1e40af',
			denyButtonColor: '#c2410c'
		}).then(result => {
			if (result.isConfirmed) {
				generarMensajeFactura(facturaIds, 'envio_factura');
			} else if (result.isDenied) {
				generarMensajeFactura(facturaIds, 'saldo_pendiente');
			}
		});
	}

	function generarMensajeFactura(facturaIds, tipo) {
		Swal.fire({
			title: 'Generando mensaje…',
			showConfirmButton: false,
			allowOutsideClick: false,
			willOpen: () => Swal.showLoading()
		});

		fetch('procesar_accion_factura.php', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json'
				},
				body: JSON.stringify({
					accion: 'generar_mensaje',
					factura_ids: facturaIds,
					tipo
				})
			})
			.then(r => r.json())
			.then(data => {
				if (!data.success) {
					throw new Error(data.error || 'No se pudo generar el mensaje.');
				}
				const escapeHtml = s => String(s).replace(/[&<>]/g, c => ({
					'&': '&amp;',
					'<': '&lt;',
					'>': '&gt;'
				} [c]));
				const asuntoEscapado = escapeHtml(data.asunto || '');

				Swal.fire({
					title: 'Mensaje generado',
					html: `
						<div style="text-align:left;">
							<div style="display:flex;justify-content:flex-end;margin-bottom:.5rem;">
								<button type="button" id="fhBtnVerPdf" class="btn-fa btn-fa-view" style="border:none;">
									<i class="bi bi-file-earmark-pdf"></i> Generar PDF
								</button>
							</div>
							<label style="font-size:.78rem;font-weight:700;color:#64748b;">Asunto</label>
							<input id="fhMensajeAsunto" type="text" value="${asuntoEscapado}"
								style="width:100%;padding:.55rem .7rem;border:1px solid #e2e8f0;border-radius:8px;font-size:.88rem;margin:.25rem 0 .9rem;box-sizing:border-box;">
							<label style="font-size:.78rem;font-weight:700;color:#64748b;">Mensaje</label>
							<div id="fhMensajeTexto" contenteditable="true"
								style="width:100%;min-height:340px;max-height:50vh;overflow-y:auto;font-size:.85rem;padding:.75rem;border:1px solid #e2e8f0;border-radius:8px;font-family:inherit;margin-top:.25rem;box-sizing:border-box;text-align:left;">${data.mensaje_html}</div>
						</div>
					`,
					width: 640,
					showCancelButton: true,
					confirmButtonText: '<i class="bi bi-clipboard-check"></i> Copiar',
					cancelButtonText: 'Cerrar',
					confirmButtonColor: '#1e40af',
					didOpen: () => {
						document.getElementById('fhBtnVerPdf')?.addEventListener('click', () => {
							facturaIds.forEach(id => {
								window.open(`ver_factura?id=${id}`, '_blank', 'noopener,noreferrer');
							});
						});
					},
					preConfirm: () => {
						const $cuerpo = document.getElementById('fhMensajeTexto');
						const htmlTexto = $cuerpo.innerHTML;
						const planoTexto = $cuerpo.innerText;
						if (window.ClipboardItem) {
							const item = new ClipboardItem({
								'text/html': new Blob([htmlTexto], {
									type: 'text/html'
								}),
								'text/plain': new Blob([planoTexto], {
									type: 'text/plain'
								})
							});
							navigator.clipboard.write([item]).catch(() => navigator.clipboard.writeText(planoTexto));
						} else {
							navigator.clipboard.writeText(planoTexto);
						}
						const $btn = Swal.getConfirmButton();
						if ($btn) {
							const original = $btn.innerHTML;
							$btn.innerHTML = '<i class="bi bi-check2"></i> ¡Copiado!';
							setTimeout(() => {
								$btn.innerHTML = original;
							}, 1500);
						}
						return false; // no cerrar el modal al copiar
					}
				});
			})
			.catch(error => {
				Swal.fire('Error', error.message, 'error');
			});
	}

	function bulkPdfMenu(facturaIds) {
		if (!facturaIds.length) return;
		const esUna = facturaIds.length === 1;

		Swal.fire({
			title: esUna ? 'Factura seleccionada' : `${facturaIds.length} facturas seleccionadas`,
			text: esUna ?
				'¿Qué deseas hacer con esta factura?' :
				'¿Deseas ver todas las facturas o descargarlas en un ZIP?',
			icon: 'question',
			showDenyButton: true,
			showCancelButton: true,
			confirmButtonText: esUna ? 'Descargar factura' : 'Descargar todo',
			denyButtonText: esUna ? 'Ver factura' : 'Ver todas las facturas',
			cancelButtonText: 'Cancelar',
			confirmButtonColor: '#1e40af',
			denyButtonColor: '#0891b2'
		}).then(result => {
			if (result.isConfirmed) {
				bulkDescargarPdf(facturaIds);
			} else if (result.isDenied) {
				facturaIds.forEach(id => {
					window.open(`ver_factura?id=${id}`, '_blank', 'noopener,noreferrer');
				});
			}
		});
	}

	async function bulkDescargarPdf(facturaIds) {
		if (!facturaIds.length) return;
		const total = facturaIds.length;

		Swal.fire({
			title: 'Generando facturas',
			html: `
				<div style="margin-top:10px;">
					<div id="fhPdfProgressLabel" style="font-size:.9rem;color:#555;margin-bottom:8px;">Preparando 0 de ${total}...</div>
					<div style="background:#e5e7eb;border-radius:8px;overflow:hidden;height:14px;">
						<div id="fhPdfProgressBar" style="background:#1e40af;height:100%;width:0%;transition:width .25s;"></div>
					</div>
				</div>
			`,
			showConfirmButton: false,
			allowOutsideClick: false,
			allowEscapeKey: false
		});

		const $bar = () => document.getElementById('fhPdfProgressBar');
		const $label = () => document.getElementById('fhPdfProgressLabel');
		const updateProgress = (done) => {
			const pct = Math.round((done / total) * 100);
			if ($bar()) $bar().style.width = pct + '%';
			if ($label()) $label().textContent = `Procesando ${done} de ${total}...`;
		};

		try {
			const zip = new JSZip();
			let done = 0;

			for (const id of facturaIds) {
				const response = await fetch(`procesar_accion_factura.php?accion=exportar_pdf_single&factura_id=${encodeURIComponent(id)}`, {
					method: 'GET',
					credentials: 'same-origin'
				});
				const contentType = response.headers.get('content-type') || '';
				if (!response.ok || !contentType.includes('application/pdf')) {
					const rawText = await response.text();
					let data = null;
					try {
						data = JSON.parse(rawText);
					} catch (e) {
						console.error(`exportar_pdf_single (factura ${id}): respuesta no válida (status ${response.status}):`, rawText);
					}
					throw new Error(data?.error || `No se pudo generar el PDF de la factura ${id}.`);
				}

				const disposition = response.headers.get('content-disposition') || '';
				const match = disposition.match(/filename="?([^"]+)"?/);
				const filename = match ? match[1] : `factura_${id}.pdf`;
				const buffer = await response.arrayBuffer();
				zip.file(filename, buffer);

				done++;
				updateProgress(done);
			}

			if ($label()) $label().textContent = 'Comprimiendo ZIP...';
			const blob = await zip.generateAsync({
				type: 'blob'
			}, meta => {
				if ($label()) $label().textContent = `Comprimiendo ZIP... ${Math.round(meta.percent)}%`;
			});

			const url = window.URL.createObjectURL(blob);
			const link = document.createElement('a');
			link.href = url;
			link.download = `facturas_${total}.zip`;
			link.click();
			window.URL.revokeObjectURL(url);

			Swal.fire({
				title: 'Descarga lista',
				text: 'El ZIP con las facturas se descargó correctamente.',
				icon: 'success',
				confirmButtonColor: '#1e40af'
			});
		} catch (error) {
			console.error('exportar_pdf_single error:', error);
			Swal.fire({
				title: 'Error',
				text: error.message,
				icon: 'error',
				confirmButtonColor: '#1e40af'
			});
		}
	}

	function bulkAccionFactura(facturaIds, accion) {
		Swal.fire({
			title: accion === 'anular' ?
				`¿Anular ${facturaIds.length} factura${facturaIds.length > 1 ? 's' : ''}?` : 'Confirmar acción',
			html: `<input type="text" id="motivo" class="swal2-input" placeholder="Motivo (obligatorio)"><input type="text" id="usuario" class="swal2-input" placeholder="Usuario admin/superadmin"><input type="password" id="clave" class="swal2-input" placeholder="Contraseña">`,
			icon: 'warning',
			showCancelButton: true,
			confirmButtonText: 'Confirmar',
			cancelButtonText: 'Cancelar',
			confirmButtonColor: '#1e40af',
			focusConfirm: false,
			preConfirm: () => {
				const motivo = document.getElementById('motivo').value.trim(),
					usuario = document.getElementById('usuario').value.trim(),
					clave = document.getElementById('clave').value.trim();
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
			fetch('procesar_accion_factura.php', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json'
				},
				body: JSON.stringify({
					factura_ids: facturaIds,
					action: accion,
					accion,
					motivo: result.value.motivo,
					usuario_autoriza: result.value.usuario,
					clave_autoriza: result.value.clave
				})
			}).then(r => r.json()).then(data => {
				if (data.success) Swal.fire('Correcto', data.message, 'success').then(() => location
					.reload());
				else Swal.fire('Error', data.error, 'error');
			});
		});
	}

	function editarFacturaMenu(facturaId, pagada, declarada, enviada) {
		Swal.fire({
			title: 'Editar factura',
			text: '¿Qué deseas editar?',
			icon: 'question',
			showDenyButton: true,
			showCancelButton: true,
			confirmButtonText: 'Editar estado',
			denyButtonText: 'Editar todo',
			cancelButtonText: 'Cancelar',
			confirmButtonColor: '#1e40af',
			denyButtonColor: '#0891b2'
		}).then(result => {
			if (result.isConfirmed) {
				bulkEditarEstados([facturaId], {
					pagada,
					declarada,
					enviada
				});
			} else if (result.isDenied) {
				window.location.href = `editar_factura?id=${facturaId}`;
			}
		});
	}

	function bulkEditarEstados(facturaIds, presetEstados = null) {
		Swal.fire({
			title: `Editar estado de ${facturaIds.length} factura${facturaIds.length > 1 ? 's' : ''}`,
			html: `
				<div style="text-align:left;display:grid;gap:.65rem;margin:.25rem 0 1rem;">
					<label style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;"><strong>Pagada</strong>
						<select id="swal_pagada" class="swal2-select" style="margin:0;">
							<option value="">No cambiar</option>
							<option value="1">Sí</option>
							<option value="0">No</option>
						</select>
					</label>
					<label style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;"><strong>Declarada</strong>
						<select id="swal_declarada" class="swal2-select" style="margin:0;">
							<option value="">No cambiar</option>
							<option value="1">Sí</option>
							<option value="0">No</option>
						</select>
					</label>
					<label style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;"><strong>Enviada</strong>
						<select id="swal_enviada" class="swal2-select" style="margin:0;">
							<option value="">No cambiar</option>
							<option value="1">Sí</option>
							<option value="0">No</option>
						</select>
					</label>
				</div>
				<div style="font-size:.78rem;color:#6b7280;margin:-.4rem 0 .8rem;">${presetEstados ? 'Se muestra el estado actual de la factura. Deja un campo igual para no modificarlo.' : 'Los campos en "No cambiar" no se modifican.'}</div>
				<input type="text" id="motivo" class="swal2-input" placeholder="Motivo (obligatorio)">
				<input type="text" id="usuario" class="swal2-input" placeholder="Usuario admin/superadmin">
				<input type="password" id="clave" class="swal2-input" placeholder="Contraseña">
			`,
			icon: 'info',
			showCancelButton: true,
			confirmButtonText: 'Guardar cambios',
			cancelButtonText: 'Cancelar',
			confirmButtonColor: '#1e40af',
			focusConfirm: false,
			willOpen: () => {
				if (presetEstados) {
					document.getElementById('swal_pagada').value = String(presetEstados.pagada);
					document.getElementById('swal_declarada').value = String(presetEstados.declarada);
					document.getElementById('swal_enviada').value = String(presetEstados.enviada);
				}
                const motivo = document.getElementById('motivo');
                let ultimoAutomatico = '';
                const actualizarMotivo = () => {
                    const cambios = [];
                    for (const [campo, etiqueta] of [['pagada','Pagada'], ['declarada','Declarada'], ['enviada','Enviada']]) {
                        const valor = document.getElementById('swal_' + campo).value;
                        if (valor !== '' && (!presetEstados || valor !== String(presetEstados[campo]))) cambios.push(etiqueta + ': ' + (valor === '1' ? 'Sí' : 'No'));
                    }
                    const sugerido = cambios.length ? 'Cambio de estado: ' + cambios.join('; ') + '.' : '';
                    if (!motivo.value.trim() || motivo.value === ultimoAutomatico) motivo.value = sugerido;
                    ultimoAutomatico = sugerido;
                };
                ['pagada','declarada','enviada'].forEach(campo => document.getElementById('swal_' + campo).addEventListener('change', actualizarMotivo));

			},
			preConfirm: () => {
				const motivo = document.getElementById('motivo').value.trim(),
					usuario = document.getElementById('usuario').value.trim(),
					clave = document.getElementById('clave').value.trim();
				if (!motivo || !usuario || !clave) {
					Swal.showValidationMessage('Todos los campos son obligatorios.');
					return false;
				}
				const pagada = document.getElementById('swal_pagada').value,
					declarada = document.getElementById('swal_declarada').value,
					enviada = document.getElementById('swal_enviada').value;
				if (!presetEstados && pagada === '' && declarada === '' && enviada === '') {
					Swal.showValidationMessage('Selecciona al menos un estado para cambiar.');
					return false;
				}
				return {
					motivo,
					usuario,
					clave,
					pagada,
					declarada,
					enviada
				};
			}
		}).then(async result => {
			if (!result.isConfirmed) return;
			const estados = {};
			if (result.value.pagada !== '') estados.pagada = parseInt(result.value.pagada, 10);
			// Marcar UNA factura como pagada = registrar su cobro (fecha, monto, banco y comprobante)
			let irACobro = false;
			if (facturaIds.length === 1 && estados.pagada === 1 && !(presetEstados && +presetEstados.pagada === 1) && <?= $puedeCobrarLf ? 'true' : 'false' ?>) {
				const q = await Swal.fire({
					icon: 'question', title: '¿Registrar el cobro?',
					html: 'Para que quede pagada lo correcto es <strong>registrar el cobro</strong>: fecha, monto, banco y el comprobante de la transferencia. Así cuadra con Cuentas por cobrar y Bancos.',
					showCancelButton: true, showDenyButton: true,
					confirmButtonText: '<i class="bi bi-cash-coin"></i> Registrar cobro', denyButtonText: 'Solo marcarla pagada', cancelButtonText: 'Cancelar',
					confirmButtonColor: '#15803d', denyButtonColor: '#64748b'
				});
				if (q.isDismissed) return;
				if (q.isConfirmed) { irACobro = true; delete estados.pagada; }
			}
			if (irACobro && !Object.keys(estados).length) { location.href = 'cuentas_cobrar?cobrar=' + facturaIds[0]; return; }
			// Marcar VARIAS como pagadas: se registra el cobro de cada una (su saldo) y entra al banco, para que cuadre con Bancos
			if (facturaIds.length > 1 && estados.pagada === 1 && !(presetEstados && +presetEstados.pagada === 1) && <?= $puedeCobrarLf ? 'true' : 'false' ?>) {
				const CTAS = <?= json_encode($cuentasCobroLf, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
				const esc = t => String(t).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
				// Saldo de cada factura (una marcada «pagada» sin cobros se cobra por su total)
				const facts = await Promise.all(facturaIds.map(id => fetch('includes/cxc_accion.php?factura_id=' + id, { credentials: 'same-origin' }).then(r => r.json()).then(d => d.factura).catch(() => null)));
				const L = n => 'L ' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
				const optCtas = sel => '<option value="">— No registrar en banco —</option>' + CTAS.map(c => `<option value="${c.id}" ${String(c.id) === String(sel) ? 'selected' : ''}>${esc(c.txt)}</option>`).join('');
				const pred = (CTAS.find(c => c.pred) || CTAS[0] || {}).id || '';
				const filas = facts.map((f, i) => f ? `<tr><td><input type="checkbox" class="form-check-input cv-chk" data-id="${f.id}" checked></td><td class="font-monospace small">${esc(f.correlativo.slice(-8))}</td>
					<td class="text-end small">${L(f.abonado > 0 ? f.saldo : f.total)}</td><td><select class="form-select form-select-sm cv-cta" data-id="${f.id}">${optCtas(pred)}</select></td></tr>` : '').join('');
				const q = await Swal.fire({
					icon: 'question', title: 'Registrar el cobro de las facturas', width: 640,
					html: `<div class="text-start small mb-2">Marca las que se pagaron y a qué cuenta entró cada una. Se registra su saldo y <strong>entra al banco</strong> (un depósito por factura, con la misma referencia).</div>
						<table class="table table-sm align-middle text-start mb-2"><thead><tr><th></th><th>Factura</th><th class="text-end">Saldo</th><th>Entró a la cuenta</th></tr></thead><tbody>${filas}</tbody></table>
						<div class="row g-2 text-start">
						<div class="col-6"><label class="form-label small mb-0">Fecha del pago</label><input type="date" id="cvFecha" class="form-control" value="${new Date().toLocaleDateString('en-CA')}"></div>
						<div class="col-6"><label class="form-label small mb-0">Método</label><select id="cvMetodo" class="form-select"><option value="transferencia">Transferencia</option><option value="deposito">Depósito</option><option value="efectivo">Efectivo</option><option value="cheque">Cheque</option><option value="otro">Otro</option></select></div>
						<div class="col-6"><label class="form-label small mb-0">Referencia</label><input id="cvRef" class="form-control" maxlength="100"></div>
						<div class="col-6"><label class="form-label small mb-0">Comprobante (opcional)</label><input type="file" id="cvComp" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf"></div></div>`,
					showCancelButton: true, showDenyButton: true, focusConfirm: false,
					confirmButtonText: '<i class="bi bi-cash-coin"></i> Registrar cobros', denyButtonText: 'Solo marcarlas pagadas', cancelButtonText: 'Cancelar',
					confirmButtonColor: '#15803d', denyButtonColor: '#64748b',
					preConfirm: () => {
						const ids = [...document.querySelectorAll('.cv-chk:checked')].map(c => c.dataset.id);
						if (!ids.length) { Swal.showValidationMessage('Marca al menos una factura.'); return false; }
						if (!document.getElementById('cvFecha').value) { Swal.showValidationMessage('Escribe la fecha del pago.'); return false; }
						const cuentas = {}; document.querySelectorAll('.cv-cta').forEach(s => cuentas[s.dataset.id] = s.value);
						return { ids, cuentas, fecha: document.getElementById('cvFecha').value, metodo: document.getElementById('cvMetodo').value, ref: document.getElementById('cvRef').value, comp: document.getElementById('cvComp').files[0] || null };
					}
				});
				if (q.isDismissed) return;
				if (q.isConfirmed) {
					const fd = new FormData();
					fd.append('accion', 'cobrar_varias'); q.value.ids.forEach(id => { fd.append('factura_ids[]', id); fd.append('cuentas[' + id + ']', q.value.cuentas[id]); });
					fd.append('fecha', q.value.fecha); fd.append('metodo', q.value.metodo); fd.append('referencia', q.value.ref);
					if (q.value.comp) fd.append('comprobante', q.value.comp);
					Swal.fire({ title: 'Registrando cobros…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
					const d = await fetch('includes/cxc_accion.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(r => r.json()).catch(() => ({ success: false, error: 'Error de conexión.' }));
					if (!d.success) return Swal.fire('No se pudo registrar', d.error, 'error');
					delete estados.pagada;
					if (!Object.keys(estados).length && result.value.declarada === '' && result.value.enviada === '') return Swal.fire('Cobros registrados', d.message, 'success').then(() => location.reload());
				}
			}
			if (result.value.declarada !== '') estados.estado_declarada = parseInt(result.value.declarada, 10);
			if (result.value.enviada !== '') estados.enviada_receptor = parseInt(result.value.enviada, 10);
			fetch('procesar_accion_factura.php', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json'
				},
				body: JSON.stringify({
					factura_ids: facturaIds,
					accion: 'editar_estados',
					motivo: result.value.motivo,
					usuario_autoriza: result.value.usuario,
					clave_autoriza: result.value.clave,
					estados
				})
			}).then(r => r.json()).then(data => {
				if (data.success && irACobro) location.href = 'cuentas_cobrar?cobrar=' + facturaIds[0];
				else if (data.success) Swal.fire('Correcto', data.message, 'success').then(() => location
					.reload());
				else Swal.fire('Error', data.error, 'error');
			});
		});
	}

	function accionFactura(facturaId, accion) {
		Swal.fire({
			title: `¿${accion.charAt(0).toUpperCase()+accion.slice(1)} esta factura?`,
			html: `<input type="text" id="motivo" class="swal2-input" placeholder="Motivo (obligatorio)"><input type="text" id="usuario" class="swal2-input" placeholder="Usuario admin/superadmin"><input type="password" id="clave" class="swal2-input" placeholder="Contraseña">`,
			icon: 'warning',
			showCancelButton: true,
			confirmButtonText: 'Confirmar',
			cancelButtonText: 'Cancelar',
			confirmButtonColor: '#1e40af',
			focusConfirm: false,
			preConfirm: () => {
				const motivo = document.getElementById('motivo').value.trim(),
					usuario = document.getElementById('usuario').value.trim(),
					clave = document.getElementById('clave').value.trim();
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
			fetch('procesar_accion_factura.php', {
					method: 'POST',
					credentials: 'same-origin',
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
				.then(r => r.json()).then(data => {
					if (data.success) Swal.fire('Correcto', data.message, 'success').then(() => location
						.reload());
					else Swal.fire('Error', data.error, 'error');
				});
		});
	}
</script>


<script>
document.addEventListener('click', function (event) {
    const boton = event.target.closest('.fh-nota-btn');
    if (!boton) return;
    const contenido = document.createElement('div');
    const factura = document.createElement('div');
    factura.className = 'fh-nota-factura';
    factura.textContent = 'Factura ' + boton.dataset.factura;
    const nota = document.createElement('p');
    nota.className = 'fh-nota-texto';
    nota.textContent = boton.dataset.nota;
    const privada = document.createElement('div');
    privada.className = 'fh-nota-privada';
    privada.textContent = 'Uso interno · No se incluye en el PDF ni en los correos al cliente.';
    contenido.append(factura, nota, privada);
    Swal.fire({
        titleText: 'Nota interna',
        html: contenido,
        confirmButtonText: 'Cerrar',
        confirmButtonColor: '#2563eb',
        width: 640,
        customClass: { popup: 'fh-nota-modal', title: 'fh-nota-titulo', htmlContainer: 'fh-nota-contenido', actions: 'fh-nota-acciones' }
    });
});
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
