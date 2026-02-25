<?php
define('ALERTA_FACTURAS_RESTANTES', 20);
define('ALERTA_CAI_DIAS', 30); // Alertar CAI por vencer con 30 dÃ­as o menos

$pdo->exec("SET lc_time_names = 'es_ES'");

// Obtener datos del usuario logueado
$usuario_id = $_SESSION['usuario_id'];
$establecimiento_activo = $_SESSION['establecimiento_activo'] ?? null;

$es_superadmin = (USUARIO_ROL === 'superadmin');

if (!$establecimiento_activo && !$es_superadmin) {
	header("Location: ./seleccionar_establecimiento");
	exit;
}

// Si es superadmin y no ha seleccionado cliente/establecimiento aÃºn
if ($es_superadmin && (!defined('CLIENTE_ID') || !CLIENTE_ID)) {
	$titulo = "Dashboard";
	require_once '../../includes/templates/header.php';
	echo '<div class="container mt-5">';
	echo '<div class="alert alert-info">ðŸ§­ Bienvenido Superadmin. Seleccione un cliente para continuar.</div>';
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
	$datos = $stmt->fetch(PDO::FETCH_ASSOC);

	if (!$datos) {
		die("Error: no se encontrÃ³ informaciÃ³n del usuario.");
	}

	$cliente_id = (int)$datos['cliente_id'];
} else {
	$cliente_id = (int)($_SESSION['cliente_seleccionado'] ?? 0);

	// Traer informaciÃ³n del cliente seleccionado
	$stmt = $pdo->prepare("SELECT nombre AS cliente_nombre, logo_url FROM clientes_saas WHERE id = ?");
	$stmt->execute([$cliente_id]);
	$cliente_info = $stmt->fetch(PDO::FETCH_ASSOC);

	$datos['usuario_nombre'] = USUARIO_NOMBRE;
	$datos['rol'] = USUARIO_ROL;
	$datos['cliente_nombre'] = $cliente_info['cliente_nombre'] ?? 'Cliente no asignado';
	$datos['logo_url'] = $cliente_info['logo_url'] ?? '';
}

$cliente_id = (USUARIO_ROL === 'superadmin') ? (int)$_SESSION['cliente_seleccionado'] : (int)$datos['cliente_id'];

// 1. Rango de fechas (por GET o mes actual por defecto)  âœ… (NO volver a sobrescribirlo despuÃ©s)
// 1. Rango de fechas (POST primero, luego GET, luego mes actual)
$fecha_inicio = $_POST['fecha_inicio'] ?? $_GET['fecha_inicio'] ?? date('Y-m-01');
$fecha_fin    = $_POST['fecha_fin'] ?? $_GET['fecha_fin'] ?? date('Y-m-t');

// Normalizar y validar (evita rangos invertidos o valores raros)
$fi = DateTime::createFromFormat('Y-m-d', $fecha_inicio);
$ff = DateTime::createFromFormat('Y-m-d', $fecha_fin);

$fecha_inicio = ($fi && $fi->format('Y-m-d') === $fecha_inicio) ? $fecha_inicio : date('Y-m-01');
$fecha_fin    = ($ff && $ff->format('Y-m-d') === $fecha_fin) ? $fecha_fin : date('Y-m-t');

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

// Totales del aÃ±o a la fecha
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
/// CAI activos + restantes âœ… (MODELO OFFSET)
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
$fecha_limite = null;       // la mÃ¡s prÃ³xima (mÃ­nima)
$dias_restantes_cai = null;
$alerta_cai_vencido = false;

$hoy = new DateTime('today');

if (!empty($cais_activos)) {
	foreach ($cais_activos as &$cai) {
		$rango_inicio = (int)$cai['rango_inicio'];
		$rango_fin = (int)$cai['rango_fin'];
		$offset = (int)$cai['correlativo_actual'];

		// âœ… restantes = rango_fin - (rango_inicio + offset) + 1
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

	// como vienen ORDER BY fecha_limite ASC, el primero es el que vence mÃ¡s pronto
	$fecha_limite = $cais_activos[0]['fecha_limite'];
	$dias_restantes_cai = $cais_activos[0]['dias_para_vencer'];
}


/// ==============================
/// Facturas emitidas (en el rango de fechas seleccionado)
/// ==============================

// Si tÃº quieres contar TODAS emitidas sin importar CAI, usa solo facturas.
// AquÃ­ lo dejo como lo tenÃ­as (con join CAI y vigencia), pero corregido a OFFSET:
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
/// Facturas no declaradas (tu lÃ³gica original)
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

// LÃ³gica para color de alerta segÃºn dÃ­a del mes
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

// Obtener los meses con facturas no declaradas (tu cÃ³digo referenciaba $meses_no_declarados)
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
/// Top productos / resÃºmenes (tu lÃ³gica original)
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

// âœ… Resumen por receptor (trae receptor_id y cantidad_facturas)
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
	$cliente_id, $establecimiento_activo, $fecha_inicio, $fecha_fin,
	$cliente_id, $establecimiento_activo, $fecha_inicio, $fecha_fin
]);
$resumen_receptores = $stmtResumenReceptores->fetchAll(PDO::FETCH_ASSOC);

// âœ… Detalle por receptor: facturas + items (para el botÃ³n +)
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

// ── Contratos por vencer (alertas dashboard) ────────────────────────────────
$stmtContratosAlerta = $pdo->prepare("
    SELECT x.*,
           fp.id          AS factura_pendiente_id,
           fp.correlativo AS factura_correlativo
    FROM (
        SELECT
            c.id, c.cliente_id, c.receptor_id, c.producto_id, c.nombre_contrato, c.monto,
            c.fecha_inicio, c.fecha_fin, c.dia_pago, c.estado,
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
$contratos_por_vencer = array_filter($contratos_dashboard, fn($c) =>
    $c['fecha_fin'] !== null && (int)$c['dias_restantes'] <= 3 && (int)$c['dias_restantes'] >= 0
);
$contratos_proximos_pagos = array_slice($contratos_dashboard, 0, 8);