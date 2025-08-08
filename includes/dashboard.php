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
if ($es_superadmin && !CLIENTE_ID) {
	$titulo = "Dashboard";
	require_once '../../includes/templates/header.php';
	echo '<div class="container mt-5">';
	echo '<div class="alert alert-info">🧭 Bienvenido Superadmin. Seleccione un cliente para continuar.</div>';
	echo '</div></body></html>';
	exit;
}
// Obtener nombre del establecimiento activo
$stmtEstab = $pdo->prepare("SELECT nombre FROM establecimientos WHERE establecimiento_id = ?");
$stmtEstab->execute([$establecimiento_activo]);
$establecimiento = $stmtEstab->fetch(PDO::FETCH_ASSOC);
$nombre_establecimiento = $establecimiento && isset($establecimiento['nombre']) ? $establecimiento['nombre'] : 'No asignado';

// Obtener datos del usuario y cliente
$datos = [];

if (!$es_superadmin) {
	$stmt = $pdo->prepare("
        SELECT u.nombre AS usuario_nombre, u.rol, c.nombre AS cliente_nombre, c.logo_url, c.id AS cliente_id
        FROM usuarios u
        INNER JOIN clientes_saas c ON u.cliente_id = c.id
        WHERE u.id = ?
    ");
	$stmt->execute([$usuario_id]);
	$datos = $stmt->fetch();

	if (!$datos) {
		die("Error: no se encontró información del usuario.");
	}

	$cliente_id = $datos['cliente_id'];
} else {
	$cliente_id = $_SESSION['cliente_seleccionado'] ?? null;

	// Traer información del cliente seleccionado
	$stmt = $pdo->prepare("SELECT nombre AS cliente_nombre, logo_url FROM clientes_saas WHERE id = ?");
	$stmt->execute([$cliente_id]);
	$cliente_info = $stmt->fetch(PDO::FETCH_ASSOC);

	$datos['usuario_nombre'] = USUARIO_NOMBRE;
	$datos['rol'] = USUARIO_ROL;
	$datos['cliente_nombre'] = $cliente_info['cliente_nombre'] ?? 'Cliente no asignado';
	$datos['logo_url'] = $cliente_info['logo_url'] ?? '';
}


$cliente_id = USUARIO_ROL === 'superadmin' ? $_SESSION['cliente_seleccionado'] : $datos['cliente_id'];
// 1. Rango de fechas (por GET o mes actual por defecto)
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-t');

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

/// Obtener CAI activo filtrando por establecimiento_activo
$stmtCAI = $pdo->prepare("
    SELECT *
    FROM cai_rangos
    WHERE cliente_id = ? 
    AND establecimiento_id = ?
    AND correlativo_actual < rango_fin
    AND CURDATE() <= fecha_limite
    ORDER BY fecha_recepcion DESC
    LIMIT 1
");
$stmtCAI->execute([$cliente_id, $establecimiento_activo]);
$cai = $stmtCAI->fetch();
if (
    !empty($_GET['fecha_inicio']) &&
    !empty($_GET['fecha_fin'])
) {
    $fecha_inicio = $_GET['fecha_inicio'];
    $fecha_fin    = $_GET['fecha_fin'];
} else {
    $fecha_inicio = date('Y-01-01'); // 1 de enero del año corriente
    $fecha_fin    = date('Y-m-d');   // hoy
}
// Contar facturas filtrando por establecimiento
// FACTURAS EMITIDAS asociadas a un CAI vigente (no vencido / no agotado)
$stmtFact = $pdo->prepare("
    SELECT COUNT(*) 
    FROM facturas f
    JOIN cai_rangos c   ON c.id = f.cai_id
    WHERE f.cliente_id        = ?
      AND f.establecimiento_id = ?
      AND f.estado             = 'emitida'
      AND f.fecha_emision      BETWEEN ? AND ?
      -- CAI aún válido
      AND CURDATE()           <= c.fecha_limite
      AND c.correlativo_actual <  c.rango_fin
");
$stmtFact->execute([
    $cliente_id,
    $establecimiento_activo,
    $fecha_inicio,
    $fecha_fin               // ← ya lo tenías arriba
]);
$total_facturas = (int)$stmtFact->fetchColumn();


// Datos CAI para mostrar
$facturas_restantes = $cai ? ($cai['rango_fin'] - $cai['correlativo_actual']) : 0;
$fecha_limite = $cai ? $cai['fecha_limite'] : null;

// Función para formatear fecha evitando 01/01/1970
function formatFechaLimite($fecha)
{
	if (!$fecha || strtotime($fecha) === false) {
		return 'No disponible';
	}
	$ts = strtotime($fecha);
	if ($ts === 0) { // fecha inválida
		return 'No disponible';
	}
	return date('d/m/Y', $ts);
}

// Calcular si CAI está por vencer (dentro de ALERTA_CAI_DIAS días)
$alerta_cai_vencido = false;
if ($fecha_limite && $total_facturas > 0) {
	$dias_restantes = (strtotime($fecha_limite) - time()) / (60 * 60 * 24);
	if ($dias_restantes <= ALERTA_CAI_DIAS && $dias_restantes >= 0) {
		$alerta_cai_vencido = true;
	}
}

// Definir variables para header
$titulo = "Dashboard";
$usuario = [
	'usuario_nombre' => $datos['usuario_nombre'] ?? '',
	'rol' => $datos['rol'] ?? '',
	'cliente_nombre' => $datos['cliente_nombre'] ?? '',
	'logo_url' => $datos['logo_url'] ?? ''
];



// Facturas no declaradas
$primer_dia_mes_actual = date('Y-m-01');
$ultimo_dia_mes_actual = date('Y-m-t'); // Último día del mes actual
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

// Lógica para color de alerta según día del mes
$dia_hoy = (int)date('d');
$color_alerta = 'success'; // verde por defecto
// echo $dia_hoy;
if ($dia_hoy < 10) {
	$color_alerta = 'success';   // 🟢 Verde
} elseif ($dia_hoy >= 10 && $dia_hoy < 15) {
	$color_alerta = 'warning';   // 🟡 Amarillo
} elseif ($dia_hoy >= 15 && $dia_hoy < 20) {
	$color_alerta = 'orange';    // 🟠 Naranja personalizado
} elseif ($dia_hoy >= 20 && $dia_hoy <= 25) {
	$color_alerta = 'ocre';      // 🟤 Ocre personalizado
} elseif ($dia_hoy > 25) {
	$color_alerta = 'danger';    // 🔴 Rojo Bootstrap
}

// Obtener los meses con facturas no declaradas


$lista_meses = [];

if (!empty($meses_no_declarados)) {
	$lista_meses_en = array_column($meses_no_declarados, 'mes_anio');
	$lista_meses = traducirMeses($lista_meses_en);
}
// $primer_dia_mes_actual = date('Y-m-01');


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




// Top 10 productos_clientes más vendidos por cantidad
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

// Top 10 productos_clientes más frecuentes en facturas (sin importar cantidad)
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


// Ingresos anuales (todos los meses del año actual sin filtro)
$stmtIngresosAnuales = $pdo->prepare("
	SELECT DATE_FORMAT(fecha_emision, '%Y-%m') AS mes,
	       SUM(subtotal) AS subtotal,
	       SUM(isv_15 + isv_18) AS isv,
	       SUM(total) AS total
	FROM facturas
	WHERE cliente_id = ?
	  AND establecimiento_id = ?
	  AND estado = 'emitida'
	  AND YEAR(fecha_emision) = YEAR(CURDATE())
	GROUP BY mes
	ORDER BY mes ASC
");
$stmtIngresosAnuales->execute([$cliente_id, $establecimiento_activo]);
$ingresos_anuales = $stmtIngresosAnuales->fetchAll(PDO::FETCH_ASSOC);


// Resumen de facturación por receptor con nombre
$stmtResumenReceptores = $pdo->prepare("
	SELECT 
		cf.nombre AS receptor_nombre,
		COUNT(fi.id) AS cantidad_servicios,
		SUM(fi.subtotal) AS subtotal,
		SUM(fi.isv_15 + fi.isv_18) AS isv,
		SUM(fi.subtotal + fi.isv_15 + fi.isv_18) AS total
	FROM factura_items_receptor fi
	JOIN facturas f ON fi.factura_id = f.id
	LEFT JOIN clientes_factura cf ON f.receptor_id = cf.id
	WHERE f.cliente_id = ?
	  AND f.establecimiento_id = ?
	  AND f.estado = 'emitida'
	  AND f.fecha_emision BETWEEN ? AND ?
	GROUP BY f.receptor_id
	ORDER BY total DESC
");
$stmtResumenReceptores->execute([$cliente_id, $establecimiento_activo, $fecha_inicio, $fecha_fin]);
$resumen_receptores = $stmtResumenReceptores->fetchAll(PDO::FETCH_ASSOC);
 
// PRIOMEDIO ANUAL
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