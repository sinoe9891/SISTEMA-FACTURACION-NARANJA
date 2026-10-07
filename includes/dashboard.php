<?php
define('ALERTA_FACTURAS_RESTANTES', 20);
define('ALERTA_CAI_DIAS', 30); // Alertar CAI por vencer con 30 días o menos

$pdo->exec("SET lc_time_names = 'es_ES'");

// Obtener datos del usuario logueado
$usuario_id = $_SESSION['usuario_id'];
$establecimiento_activo = $_SESSION['establecimiento_activo'] ?? null;

$es_superadmin = (USUARIO_ROL === 'superadmin');

if (!$establecimiento_activo && !$es_superadmin) {
	header("Location: ./seleccionar_establecimiento");
	exit;
}

// Si es superadmin y no ha seleccionado cliente/establecimiento aún
if ($es_superadmin && (!defined('CLIENTE_ID') || !CLIENTE_ID)) {
	$titulo = "Dashboard";
	require_once '../../includes/templates/header.php';
	echo '<div class="container mt-5">';
	echo '<div class="alert alert-info">🧭 Bienvenido Superadmin. Seleccione un cliente para continuar.</div>';
	echo '</div></body></html>';
	exit;
}

// El nombre del establecimiento y los datos del usuario/cliente los carga header.php
// (desde la sesión, sin consultas extra); aquí solo hace falta el cliente activo.
$cliente_id = (USUARIO_ROL === 'superadmin') ? (int)($_SESSION['cliente_seleccionado'] ?? 0) : (int)CLIENTE_ID;

// ✅ Primero leer $_POST/$_GET
$fecha_inicio = $_POST['fecha_inicio'] ?? $_GET['fecha_inicio'] ?? date('Y-m-01');
$fecha_fin    = $_POST['fecha_fin']    ?? $_GET['fecha_fin']    ?? date('Y-m-t');

// Luego validar con DateTime
/** @var string $fecha_inicio */
/** @var string $fecha_fin */
$fi = DateTime::createFromFormat('Y-m-d', $fecha_inicio);
$ff = DateTime::createFromFormat('Y-m-d', $fecha_fin);
$fecha_inicio = ($fi instanceof DateTime) ? (string)$fecha_inicio : date('Y-m-01');
$fecha_fin    = ($ff instanceof DateTime) ? (string)$fecha_fin    : date('Y-m-t');

if ($fecha_inicio > $fecha_fin) {
	$tmp = $fecha_inicio;
	$fecha_inicio = $fecha_fin;
	$fecha_fin = $tmp;
}


// 2. Consulta de ingresos agrupados por mes
$stmtIngresos = $pdo->prepare("
	SELECT DATE_FORMAT(fecha_emision, '%Y-%m') AS mes,
	       SUM(subtotal) AS subtotal,
	       SUM(isv_15 + isv_18) AS isv,
	       SUM(total) AS total
	FROM facturas
	WHERE cliente_id = ? 
	  AND establecimiento_id = ?
	  AND estado = 'emitida'
	  AND fecha_emision BETWEEN ? AND ?
	GROUP BY mes
	ORDER BY mes ASC
");
$stmtIngresos->execute([$cliente_id, $establecimiento_activo, $fecha_inicio, $fecha_fin]);
$ingresos = $stmtIngresos->fetchAll(PDO::FETCH_ASSOC);

// Totales del mes actual
$stmtTotalesMes = $pdo->prepare("
	SELECT 
		IFNULL(SUM(subtotal), 0) AS subtotal,
		IFNULL(SUM(isv_15 + isv_18), 0) AS isv,
		IFNULL(SUM(total), 0) AS total
	FROM facturas
	WHERE cliente_id = ? 
	  AND establecimiento_id = ?
	  AND estado = 'emitida'
	  AND fecha_emision BETWEEN ? AND ?
");
$fecha_mes_inicio = date('Y-m-01');
$fecha_mes_fin = date('Y-m-t');
$stmtTotalesMes->execute([$cliente_id, $establecimiento_activo, $fecha_mes_inicio, $fecha_mes_fin]);
$totales_mes = $stmtTotalesMes->fetch(PDO::FETCH_ASSOC);

// Totales del año a la fecha
$stmtTotalesAnio = $pdo->prepare("
	SELECT 
		IFNULL(SUM(subtotal), 0) AS subtotal,
		IFNULL(SUM(isv_15 + isv_18), 0) AS isv,
		IFNULL(SUM(total), 0) AS total
	FROM facturas
	WHERE cliente_id = ? 
	  AND establecimiento_id = ?
	  AND estado = 'emitida'
	  AND fecha_emision BETWEEN ? AND ?
");
$fecha_anio_inicio = date('Y-01-01');
$fecha_anio_fin = date('Y-m-d');
$stmtTotalesAnio->execute([$cliente_id, $establecimiento_activo, $fecha_anio_inicio, $fecha_anio_fin]);
$totales_anio = $stmtTotalesAnio->fetch(PDO::FETCH_ASSOC);


/// ==============================
/// CAI activos + restantes ✅ (MODELO OFFSET)
/// ==============================

// --- CAIs activos (modelo: correlativo_actual = OFFSET emitido) ---
$stmtCAIs = $pdo->prepare("
    SELECT id, cai, rango_inicio, rango_fin, correlativo_actual, fecha_limite, fecha_recepcion
    FROM cai_rangos
    WHERE cliente_id = ?
      AND establecimiento_id = ?
      AND CURDATE() <= fecha_limite
      -- hay disponibilidad si (rango_inicio + correlativo_actual) <= rango_fin
      AND (rango_inicio + correlativo_actual) <= rango_fin
    ORDER BY fecha_limite ASC
");
$stmtCAIs->execute([$cliente_id, $establecimiento_activo]);
$cais_activos = $stmtCAIs->fetchAll(PDO::FETCH_ASSOC);

// Variables CAI para mostrar en dashboard
$facturas_restantes = 0;
$fecha_limite = null;       // la más próxima (mínima)
$dias_restantes_cai = null;
$alerta_cai_vencido = false;

$hoy = new DateTime('today');

if (!empty($cais_activos)) {
	foreach ($cais_activos as &$cai) {
		$rango_inicio = (int)$cai['rango_inicio'];
		$rango_fin = (int)$cai['rango_fin'];
		$offset = (int)$cai['correlativo_actual'];

		// ✅ restantes = rango_fin - (rango_inicio + offset) + 1
		$restantes = $rango_fin - ($rango_inicio + $offset) + 1;
		$cai['restantes'] = max(0, (int)$restantes);
		$facturas_restantes += $cai['restantes'];

		$limite = new DateTime($cai['fecha_limite']);
		$cai['dias_para_vencer'] = (int)$hoy->diff($limite)->format('%r%a');

		if ($cai['dias_para_vencer'] <= ALERTA_CAI_DIAS && $cai['dias_para_vencer'] >= 0) {
			$alerta_cai_vencido = true;
		}
	}
	unset($cai);

	// como vienen ORDER BY fecha_limite ASC, el primero es el que vence más pronto
	$fecha_limite = $cais_activos[0]['fecha_limite'];
	$dias_restantes_cai = $cais_activos[0]['dias_para_vencer'];
}


/// ==============================
/// Facturas emitidas (en el rango de fechas seleccionado)
/// ==============================

// Si tú quieres contar TODAS emitidas sin importar CAI, usa solo facturas.
// Aquí lo dejo como lo tenías (con join CAI y vigencia), pero corregido a OFFSET:
$stmtFact = $pdo->prepare("
    SELECT COUNT(*) 
    FROM facturas f
    JOIN cai_rangos c ON c.id = f.cai_id
    WHERE f.cliente_id = ?
      AND f.establecimiento_id = ?
      AND f.estado = 'emitida'
      AND f.fecha_emision BETWEEN ? AND ?
      AND CURDATE() <= c.fecha_limite
      AND (c.rango_inicio + c.correlativo_actual) <= c.rango_fin
");
$stmtFact->execute([$cliente_id, $establecimiento_activo, $fecha_inicio, $fecha_fin]);
$total_facturas = (int)$stmtFact->fetchColumn();


/// ==============================
/// Utilidad: formatear fecha
/// ==============================
function formatFechaLimite($fecha)
{
	if (!$fecha || strtotime($fecha) === false) {
		return 'No disponible';
	}
	$ts = strtotime($fecha);
	if ($ts === 0) {
		return 'No disponible';
	}
	return date('d/m/Y', $ts);
}


// Definir variables para header
$titulo = "Dashboard";
$usuario = [
	'usuario_nombre' => $datos['usuario_nombre'] ?? '',
	'rol' => $datos['rol'] ?? '',
	'cliente_nombre' => $datos['cliente_nombre'] ?? '',
	'logo_url' => $datos['logo_url'] ?? ''
];


/// ==============================
/// Facturas no declaradas (tu lógica original)
/// ==============================
$primer_dia_mes_actual = date('Y-m-01');
$ultimo_dia_mes_actual = date('Y-m-t');

$stmtNoDeclaradas = $pdo->prepare("
	SELECT COUNT(*) AS cantidad, 
	       IFNULL(SUM(isv_15 + isv_18), 0) AS isv_pendiente
	FROM facturas
	WHERE cliente_id = ? 
	  AND establecimiento_id = ?
	  AND estado = 'emitida'
	  AND estado_declarada = 'no'
	  AND fecha_emision < ?
");
$stmtNoDeclaradas->execute([$cliente_id, $establecimiento_activo, $primer_dia_mes_actual]);
$no_declaradas = $stmtNoDeclaradas->fetch(PDO::FETCH_ASSOC);

$cant_no_declaradas = (int)$no_declaradas['cantidad'];
$isv_pendiente = (float)$no_declaradas['isv_pendiente'];

/// ==============================
/// Pendientes de pago (facturas emitidas no pagadas)
/// ==============================
$stmtPendientesPago = $pdo->prepare("
	SELECT COUNT(*) AS cantidad,
	       IFNULL(SUM(total), 0) AS monto_pendiente
	FROM facturas
	WHERE cliente_id = ?
	  AND establecimiento_id = ?
	  AND estado = 'emitida'
	  AND (pagada = 0 OR pagada IS NULL)
");
$stmtPendientesPago->execute([$cliente_id, $establecimiento_activo]);
$pendientes_pago = $stmtPendientesPago->fetch(PDO::FETCH_ASSOC);
$cant_pendientes_pago  = (int)($pendientes_pago['cantidad'] ?? 0);
$monto_pendientes_pago = (float)($pendientes_pago['monto_pendiente'] ?? 0);

// Lógica para color de alerta según día del mes
$dia_hoy = (int)date('d');
$color_alerta = 'success';

if ($dia_hoy < 10) {
	$color_alerta = 'success';
} elseif ($dia_hoy >= 10 && $dia_hoy < 15) {
	$color_alerta = 'warning';
} elseif ($dia_hoy >= 15 && $dia_hoy < 20) {
	$color_alerta = 'orange';
} elseif ($dia_hoy >= 20 && $dia_hoy <= 25) {
	$color_alerta = 'ocre';
} elseif ($dia_hoy > 25) {
	$color_alerta = 'danger';
}

// Obtener los meses con facturas no declaradas (tu código referenciaba $meses_no_declarados)
$lista_meses = [];
if (!empty($meses_no_declarados)) {
	$lista_meses_en = array_column($meses_no_declarados, 'mes_anio');
	$lista_meses = traducirMeses($lista_meses_en);
}

$stmtActualMes = $pdo->prepare("
	SELECT COUNT(*) AS cantidad, 
	       IFNULL(SUM(isv_15 + isv_18), 0) AS isv_mes_actual
	FROM facturas
	WHERE cliente_id = ? 
	  AND establecimiento_id = ?
	  AND estado = 'emitida'
	  AND estado_declarada = 'no'
	  AND fecha_emision >= ? AND fecha_emision <= ?
");
$stmtActualMes->execute([
	$cliente_id,
	$establecimiento_activo,
	$primer_dia_mes_actual,
	$ultimo_dia_mes_actual
]);

$facturas_mes_actual = $stmtActualMes->fetch(PDO::FETCH_ASSOC);
$cant_mes_actual = (int)$facturas_mes_actual['cantidad'];
$isv_mes_actual = (float)$facturas_mes_actual['isv_mes_actual'];


/// ==============================
/// Top productos / resúmenes (tu lógica original)
/// ==============================
$stmtTopProductos = $pdo->prepare("
    SELECT p.nombre, SUM(fi.cantidad) AS total_vendido
    FROM factura_items_receptor fi
    JOIN productos_clientes p ON fi.producto_id = p.id
    JOIN facturas f ON fi.factura_id = f.id
    WHERE f.cliente_id = ? 
      AND f.establecimiento_id = ? 
      AND f.estado = 'emitida'
      AND f.fecha_emision BETWEEN ? AND ?
    GROUP BY fi.producto_id
    ORDER BY total_vendido DESC
    LIMIT 10
");
$stmtTopProductos->execute([$cliente_id, $establecimiento_activo, $fecha_inicio, $fecha_fin]);
$top_productos = $stmtTopProductos->fetchAll(PDO::FETCH_ASSOC);

$stmtTopProductosFacturas = $pdo->prepare("
	SELECT p.nombre, COUNT(DISTINCT fi.factura_id) AS veces_facturado
	FROM factura_items_receptor fi
	JOIN productos_clientes p ON fi.producto_id = p.id
	JOIN facturas f ON fi.factura_id = f.id
	WHERE f.cliente_id = ? 
	  AND f.establecimiento_id = ? 
	  AND f.estado = 'emitida'
	  AND f.fecha_emision BETWEEN ? AND ?
	GROUP BY fi.producto_id
	ORDER BY veces_facturado DESC
	LIMIT 50
");
$stmtTopProductosFacturas->execute([$cliente_id, $establecimiento_activo, $fecha_inicio, $fecha_fin]);
$top_productos_facturas = $stmtTopProductosFacturas->fetchAll(PDO::FETCH_ASSOC);

$stmtIngresosPorAnio = $pdo->prepare("
	SELECT 
		YEAR(fecha_emision) AS anio,
		IFNULL(SUM(subtotal), 0) AS subtotal,
		IFNULL(SUM(isv_15 + isv_18), 0) AS isv,
		IFNULL(SUM(total), 0) AS total
	FROM facturas
	WHERE cliente_id = ?
	  AND establecimiento_id = ?
	  AND estado = 'emitida'
	  AND DATE(fecha_emision) BETWEEN ? AND ?
	GROUP BY anio
	ORDER BY anio ASC
");
$stmtIngresosPorAnio->execute([$cliente_id, $establecimiento_activo, $fecha_inicio, $fecha_fin]);
$ingresos_anuales = $stmtIngresosPorAnio->fetchAll(PDO::FETCH_ASSOC);

// ✅ Resumen por receptor (trae receptor_id y cantidad_facturas)
$stmtResumenReceptores = $pdo->prepare("
	SELECT
		r.receptor_id,
		COALESCE(cf.nombre, 'N/D') AS receptor_nombre,
		r.cantidad_facturas,
		COALESCE(s.cantidad_servicios, 0) AS cantidad_servicios,
		r.subtotal,
		r.isv,
		r.total
	FROM (
		SELECT 
			receptor_id,
			COUNT(*) AS cantidad_facturas,
			IFNULL(SUM(subtotal),0) AS subtotal,
			IFNULL(SUM(isv_15 + isv_18),0) AS isv,
			IFNULL(SUM(total),0) AS total
		FROM facturas
		WHERE cliente_id = ?
		  AND establecimiento_id = ?
		  AND estado = 'emitida'
		  AND DATE(fecha_emision) BETWEEN ? AND ?
		GROUP BY receptor_id
	) r
	LEFT JOIN clientes_factura cf ON cf.id = r.receptor_id
	LEFT JOIN (
		SELECT 
			f.receptor_id,
			COUNT(fi.id) AS cantidad_servicios
		FROM facturas f
		JOIN factura_items_receptor fi ON fi.factura_id = f.id
		WHERE f.cliente_id = ?
		  AND f.establecimiento_id = ?
		  AND f.estado = 'emitida'
		  AND DATE(f.fecha_emision) BETWEEN ? AND ?
		GROUP BY f.receptor_id
	) s ON s.receptor_id = r.receptor_id
	ORDER BY r.total DESC
");
$stmtResumenReceptores->execute([
	$cliente_id,
	$establecimiento_activo,
	$fecha_inicio,
	$fecha_fin,
	$cliente_id,
	$establecimiento_activo,
	$fecha_inicio,
	$fecha_fin
]);
$resumen_receptores = $stmtResumenReceptores->fetchAll(PDO::FETCH_ASSOC);

// ✅ Detalle por receptor: facturas + items (para el botón +)
$detalle_receptores = [];

$receptorIds = array_values(array_filter(array_map(
	fn($r) => (int)($r['receptor_id'] ?? 0),
	$resumen_receptores
)));

if (!empty($receptorIds)) {
	$ph = implode(',', array_fill(0, count($receptorIds), '?'));

	// 1) Facturas por receptor en el rango
	$sqlFact = "
		SELECT 
			id, receptor_id, correlativo, fecha_emision,
			subtotal, isv_15, isv_18, total
		FROM facturas
		WHERE cliente_id = ?
		  AND establecimiento_id = ?
		  AND estado = 'emitida'
		  AND DATE(fecha_emision) BETWEEN ? AND ?
		  AND receptor_id IN ($ph)
		ORDER BY receptor_id ASC, fecha_emision DESC, id DESC
	";
	$params = array_merge([$cliente_id, $establecimiento_activo, $fecha_inicio, $fecha_fin], $receptorIds);
	$stmt = $pdo->prepare($sqlFact);
	$stmt->execute($params);
	$facturas_det = $stmt->fetchAll(PDO::FETCH_ASSOC);

	$facturaIds = [];
	$factura_to_receptor = [];

	foreach ($facturas_det as $f) {
		$rid = (int)$f['receptor_id'];
		$fid = (int)$f['id'];
		$facturaIds[] = $fid;
		$factura_to_receptor[$fid] = $rid;

		if (!isset($detalle_receptores[$rid])) $detalle_receptores[$rid] = [];
		$f['items'] = [];
		$detalle_receptores[$rid][$fid] = $f; // index por factura id
	}

	// 2) Items de esas facturas
	if (!empty($facturaIds)) {
		$ph2 = implode(',', array_fill(0, count($facturaIds), '?'));
		$sqlItems = "
			SELECT 
				fi.*,
				p.nombre AS nombre_producto
			FROM factura_items_receptor fi
			LEFT JOIN productos_clientes p ON p.id = fi.producto_id
			WHERE fi.factura_id IN ($ph2)
			ORDER BY fi.factura_id ASC, fi.id ASC
		";
		$stmtI = $pdo->prepare($sqlItems);
		$stmtI->execute($facturaIds);
		$items_det = $stmtI->fetchAll(PDO::FETCH_ASSOC);

		foreach ($items_det as $it) {
			$fid = (int)$it['factura_id'];
			$rid = $factura_to_receptor[$fid] ?? null;
			if ($rid && isset($detalle_receptores[$rid][$fid])) {
				$detalle_receptores[$rid][$fid]['items'][] = $it;
			}
		}
	}

	// Pasar de map a lista
	foreach ($detalle_receptores as $rid => $map) {
		$detalle_receptores[$rid] = array_values($map);
	}
}

// PROMEDIO ANUAL
$anio_promedio = $_GET['anio_promedio'] ?? date('Y');
$stmtPromedioMensual = $pdo->prepare("
	SELECT 
		MONTH(fecha_emision) AS mes_num,
		MONTHNAME(fecha_emision) AS mes_nombre,
		SUM(subtotal) AS subtotal,
		SUM(isv_15 + isv_18) AS isv,
		SUM(total) AS total
	FROM facturas
	WHERE cliente_id = ?
	  AND establecimiento_id = ?
	  AND estado = 'emitida'
	  AND YEAR(fecha_emision) = ?
	GROUP BY mes_num
	ORDER BY mes_num
");
$stmtPromedioMensual->execute([$cliente_id, $establecimiento_activo, $anio_promedio]);
$promedio_mensual = $stmtPromedioMensual->fetchAll(PDO::FETCH_ASSOC);

// ── Reportes periódicos (Trimestral / Semestral / Anual / Comparativo) ───────
$anio_reporte = (int)($_GET['anio_reporte'] ?? $anio_promedio);

// ── Tab "Por Fecha": patrón PRG (POST → Session → Redirect GET) ──────────────
// Así la URL queda limpia y el tab se restaura correctamente sin reenvío de form.
$tabs_validos = ['tab-trim','tab-sem','tab-anual','tab-fecha','tab-comp'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fecha_desde_rep'])) {
    $fd = !empty($_POST['fecha_desde_rep']) ? $_POST['fecha_desde_rep'] : null;
    $fh = !empty($_POST['fecha_hasta_rep']) ? $_POST['fecha_hasta_rep'] : null;
    if ($fd && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fd)) $fd = null;
    if ($fh && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fh)) $fh = null;
    $_SESSION['rep_fecha_desde'] = $fd;
    $_SESSION['rep_fecha_hasta'] = $fh;
    // Redirigir a GET preservando otros params, forzando tab-fecha
    $p = array_filter($_GET, fn($k) => !in_array($k, ['clear_fecha_rep']), ARRAY_FILTER_USE_KEY);
    $p['active_tab_rep'] = 'tab-fecha';
    header('Location: ?' . http_build_query($p));
    exit;
}

// Limpiar datos de sesión si se solicita
if (isset($_GET['clear_fecha_rep'])) {
    unset($_SESSION['rep_fecha_desde'], $_SESSION['rep_fecha_hasta']);
}

// Leer fechas desde sesión (POST ya redirigió, aquí siempre es GET)
$fecha_desde_rep = isset($_SESSION['rep_fecha_desde']) ? $_SESSION['rep_fecha_desde'] : null;
$fecha_hasta_rep = isset($_SESSION['rep_fecha_hasta']) ? $_SESSION['rep_fecha_hasta'] : null;

// Tab activo desde GET param
$active_tab_rep = in_array($_GET['active_tab_rep'] ?? '', $tabs_validos)
    ? $_GET['active_tab_rep']
    : 'tab-trim';

// Si hay rango → úsalo; si no → año completo seleccionado
$usar_rango_rep = ($fecha_desde_rep !== null || $fecha_hasta_rep !== null);
$rep_desde = $fecha_desde_rep ?? "{$anio_reporte}-01-01";
$rep_hasta = $fecha_hasta_rep ?? "{$anio_reporte}-12-31";

$stmtTrimestral = $pdo->prepare("
    SELECT
        QUARTER(fecha_emision) AS trimestre,
        COUNT(*) AS facturas,
        IFNULL(SUM(subtotal), 0) AS subtotal,
        IFNULL(SUM(isv_15 + isv_18), 0) AS isv,
        IFNULL(SUM(total), 0) AS total
    FROM facturas
    WHERE cliente_id = ?
      AND establecimiento_id = ?
      AND estado = 'emitida'
      AND DATE(fecha_emision) BETWEEN ? AND ?
    GROUP BY trimestre
    ORDER BY trimestre
");
$stmtTrimestral->execute([$cliente_id, $establecimiento_activo, $rep_desde, $rep_hasta]);
$datos_trimestrales = $stmtTrimestral->fetchAll(PDO::FETCH_ASSOC);

$stmtSemestral = $pdo->prepare("
    SELECT
        IF(MONTH(fecha_emision) <= 6, 1, 2) AS semestre,
        COUNT(*) AS facturas,
        IFNULL(SUM(subtotal), 0) AS subtotal,
        IFNULL(SUM(isv_15 + isv_18), 0) AS isv,
        IFNULL(SUM(total), 0) AS total
    FROM facturas
    WHERE cliente_id = ?
      AND establecimiento_id = ?
      AND estado = 'emitida'
      AND DATE(fecha_emision) BETWEEN ? AND ?
    GROUP BY semestre
    ORDER BY semestre
");
$stmtSemestral->execute([$cliente_id, $establecimiento_activo, $rep_desde, $rep_hasta]);
$datos_semestrales = $stmtSemestral->fetchAll(PDO::FETCH_ASSOC);

$stmtAnualReporte = $pdo->prepare("
    SELECT
        YEAR(fecha_emision) AS anio,
        COUNT(*) AS facturas,
        IFNULL(SUM(subtotal), 0) AS subtotal,
        IFNULL(SUM(isv_15 + isv_18), 0) AS isv,
        IFNULL(SUM(total), 0) AS total
    FROM facturas
    WHERE cliente_id = ?
      AND establecimiento_id = ?
      AND estado = 'emitida'
    GROUP BY anio
    ORDER BY anio ASC
");
$stmtAnualReporte->execute([$cliente_id, $establecimiento_activo]);
$datos_anuales_reporte = $stmtAnualReporte->fetchAll(PDO::FETCH_ASSOC);

// ── Comparativo: últimos 4 años con datos ────────────────────────────────────
$stmtAniosDisp = $pdo->prepare("
    SELECT DISTINCT YEAR(fecha_emision) AS anio
    FROM facturas
    WHERE cliente_id = ? AND establecimiento_id = ? AND estado = 'emitida'
    ORDER BY anio DESC LIMIT 4
");
$stmtAniosDisp->execute([$cliente_id, $establecimiento_activo]);
$anios_disponibles = array_reverse($stmtAniosDisp->fetchAll(PDO::FETCH_COLUMN));

$datos_comp_trim = [];
$datos_comp_sem  = [];

if (!empty($anios_disponibles)) {
    $ph_anios = implode(',', array_fill(0, count($anios_disponibles), '?'));

    $stmtCompTrim = $pdo->prepare("
        SELECT
            YEAR(fecha_emision)    AS anio,
            QUARTER(fecha_emision) AS trimestre,
            COUNT(*)               AS facturas,
            IFNULL(SUM(subtotal), 0)          AS subtotal,
            IFNULL(SUM(isv_15 + isv_18), 0)   AS isv,
            IFNULL(SUM(total), 0)              AS total
        FROM facturas
        WHERE cliente_id = ? AND establecimiento_id = ?
          AND estado = 'emitida'
          AND YEAR(fecha_emision) IN ($ph_anios)
        GROUP BY anio, trimestre
        ORDER BY anio, trimestre
    ");
    $stmtCompTrim->execute(array_merge([$cliente_id, $establecimiento_activo], $anios_disponibles));
    $datos_comp_trim = $stmtCompTrim->fetchAll(PDO::FETCH_ASSOC);

    $stmtCompSem = $pdo->prepare("
        SELECT
            YEAR(fecha_emision)                  AS anio,
            IF(MONTH(fecha_emision) <= 6, 1, 2)  AS semestre,
            COUNT(*)                             AS facturas,
            IFNULL(SUM(subtotal), 0)             AS subtotal,
            IFNULL(SUM(isv_15 + isv_18), 0)      AS isv,
            IFNULL(SUM(total), 0)                AS total
        FROM facturas
        WHERE cliente_id = ? AND establecimiento_id = ?
          AND estado = 'emitida'
          AND YEAR(fecha_emision) IN ($ph_anios)
        GROUP BY anio, semestre
        ORDER BY anio, semestre
    ");
    $stmtCompSem->execute(array_merge([$cliente_id, $establecimiento_activo], $anios_disponibles));
    $datos_comp_sem = $stmtCompSem->fetchAll(PDO::FETCH_ASSOC);
}

// ── Contratos por vencer (alertas dashboard) ────────────────────────────────
$stmtContratosAlerta = $pdo->prepare("
    SELECT x.*,
           fp.id          AS factura_pendiente_id,
           fp.correlativo AS factura_correlativo
    FROM (
        SELECT
            c.id, c.cliente_id, c.receptor_id, c.producto_id, c.nombre_contrato, c.monto,
            c.fecha_inicio, c.fecha_fin, c.dia_pago, c.estado,
            c.tipo_contrato,
            cf.nombre   AS receptor_nombre,
            cf.telefono AS receptor_tel,
            p.nombre    AS servicio_nombre,
            DATEDIFF(c.fecha_fin, CURDATE()) AS dias_restantes,
            CASE
                WHEN DAY(CURDATE()) <= LEAST(c.dia_pago, DAY(LAST_DAY(CURDATE())))
                    THEN STR_TO_DATE(CONCAT(DATE_FORMAT(CURDATE(), '%Y-%m-'), LPAD(LEAST(c.dia_pago, DAY(LAST_DAY(CURDATE()))), 2, '0')), '%Y-%m-%d')
                ELSE STR_TO_DATE(CONCAT(DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-'), LPAD(LEAST(c.dia_pago, DAY(LAST_DAY(DATE_ADD(CURDATE(), INTERVAL 1 MONTH)))), 2, '0')), '%Y-%m-%d')
            END AS proxima_fecha_pago,
            CASE
                WHEN DAY(CURDATE()) <= LEAST(c.dia_pago, DAY(LAST_DAY(CURDATE())))
                    THEN LEAST(c.dia_pago, DAY(LAST_DAY(CURDATE()))) - DAY(CURDATE())
                ELSE DATEDIFF(
                    STR_TO_DATE(CONCAT(DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-'), LPAD(LEAST(c.dia_pago, DAY(LAST_DAY(DATE_ADD(CURDATE(), INTERVAL 1 MONTH)))), 2, '0')), '%Y-%m-%d'),
                    CURDATE()
                )
            END AS dias_para_pago
        FROM contratos c
        INNER JOIN clientes_factura   cf ON cf.id = c.receptor_id AND cf.cliente_id = c.cliente_id
        INNER JOIN productos_clientes p  ON p.id  = c.producto_id  AND p.cliente_id  = c.cliente_id
        WHERE c.cliente_id = ?
          AND c.estado     = 'activo'
          AND c.tipo_contrato <> 'proyecto'
          AND c.fecha_inicio <= CURDATE()
    ) x
    -- Factura pagada este mes → excluir contrato
   LEFT JOIN facturas fpagada
      ON fpagada.cliente_id  = x.cliente_id
     AND fpagada.estado <> 'anulada'
     AND fpagada.pagada = 1
     AND YEAR(fpagada.fecha_emision)  = YEAR(x.proxima_fecha_pago)
     AND MONTH(fpagada.fecha_emision) = MONTH(x.proxima_fecha_pago)
     AND (
         fpagada.contrato_id = x.id                          -- vinculada directamente
         OR (fpagada.contrato_id IS NULL AND fpagada.receptor_id = x.receptor_id)  -- fallback por receptor
     )
    -- Factura pendiente/emitida este mes → mostrar botón 'Ver'
    LEFT JOIN facturas fp
  ON fp.cliente_id  = x.cliente_id
 AND fp.estado <> 'anulada'
 AND (fp.pagada = 0 OR fp.pagada IS NULL)
 AND YEAR(fp.fecha_emision)  = YEAR(x.proxima_fecha_pago)
 AND MONTH(fp.fecha_emision) = MONTH(x.proxima_fecha_pago)
 AND (
     fp.contrato_id = x.id
     OR (
         fp.contrato_id IS NULL
         AND fp.receptor_id = x.receptor_id
         AND NOT EXISTS (
             SELECT 1 FROM facturas f_check
             WHERE f_check.contrato_id = x.id
               AND f_check.estado <> 'anulada'
               AND (f_check.pagada = 0 OR f_check.pagada IS NULL)
               AND YEAR(f_check.fecha_emision) = YEAR(x.proxima_fecha_pago)
               AND MONTH(f_check.fecha_emision) = MONTH(x.proxima_fecha_pago)
         )
     )
 )
    WHERE fpagada.id IS NULL
    ORDER BY x.dias_para_pago ASC
");
$stmtContratosAlerta->execute([$cliente_id]);
$contratos_dashboard = $stmtContratosAlerta->fetchAll(PDO::FETCH_ASSOC);

// Separar: por vencer (contrato termina en ≤3 días) vs próximos pagos
$contratos_por_vencer = array_filter(
	$contratos_dashboard,
	fn($c) =>
	$c['fecha_fin'] !== null && (int)$c['dias_restantes'] <= 3 && (int)$c['dias_restantes'] >= 0
);
// Contratos con plan de pagos: en vez del día de pago mensual se usan las fechas del plan
// (lo vencido sin cobrar y lo que vence en los próximos 45 días), con o sin factura.
$planFilas = [];
try {
	require_once __DIR__ . '/contrato_plan.php';
	if (planDisponible($pdo)) {
		$conPlanIds = planContratosConPlan($pdo, (int)$cliente_id);
		if ($conPlanIds) {
			$contratos_dashboard = array_values(array_filter($contratos_dashboard, fn($c) => !in_array((int)$c['id'], $conPlanIds, true)));
			$stCp = $pdo->prepare("SELECT c.id, c.receptor_id, c.producto_id, c.tipo_contrato, c.dia_pago, c.fecha_fin, cf.nombre AS receptor_nombre, cf.telefono AS receptor_tel
			                       FROM contratos c JOIN clientes_factura cf ON cf.id = c.receptor_id AND cf.cliente_id = c.cliente_id
			                       WHERE c.cliente_id = ? AND c.estado = 'activo' AND c.id IN (" . implode(',', $conPlanIds) . ")");
			$stCp->execute([$cliente_id]);
			foreach ($stCp->fetchAll(PDO::FETCH_ASSOC) as $ct) {
				foreach (planLineas($pdo, (int)$cliente_id, (int)$ct['id']) as $l) {
					if (!in_array($l['estado'], ['pendiente', 'vencido', 'facturado'], true) || -$l['dias'] > 45) continue;
					$planFilas[] = $ct + [
						'servicio_nombre' => $l['concepto'], 'monto' => $l['total'], 'proxima_fecha_pago' => $l['fecha'],
						'dias_para_pago' => -(int)$l['dias'], 'dias_restantes' => $ct['fecha_fin'] ? (int)((strtotime($ct['fecha_fin']) - strtotime(date('Y-m-d'))) / 86400) : null,
						'factura_pendiente_id' => $l['estado'] === 'facturado' ? $l['factura_id'] : null, 'plan_linea' => (int)$l['id'],
					];
				}
			}
		}
	}
} catch (Throwable $e) {
	$planFilas = [];   // sin módulo de plan de pagos: queda el cálculo mensual
}
$contratos_proximos_pagos = array_merge($contratos_dashboard, $planFilas);
usort($contratos_proximos_pagos, fn($a, $b) => (int)$a['dias_para_pago'] <=> (int)$b['dias_para_pago']);
$contratos_proximos_pagos = array_slice($contratos_proximos_pagos, 0, 8);
