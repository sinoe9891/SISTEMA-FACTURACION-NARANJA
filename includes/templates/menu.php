<?php
/**
 * menu.php — Lista ÚNICA de opciones del menú lateral.
 * Para agregar una página al menú, agrégala aquí: el sidebar (sidebar.php) y el
 * título de la barra superior (header.php) se generan a partir de esta lista.
 *
 * Cada item: [archivo sin .php, icono Bootstrap Icons, texto, páginas hijas que lo marcan activo]
 * Requiere: USUARIO_ROL, $es_superadmin (definidos antes en header.php)
 */
$paginaActual = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');
$__menuEsAdmin      = in_array(USUARIO_ROL, ['admin', 'superadmin']);

// Aviso de inventario: productos en o bajo el mínimo en alguna tienda (en sesión 5 min)
$__menuPorReponer = 0;
require_once __DIR__ . '/../inventario.php';
require_once __DIR__ . '/../respaldos.php';
require_once __DIR__ . '/../permisos.php';
if (function_exists('cliente_actual') && cliente_actual() && invDisponible($pdo)) {
	$__c = $_SESSION['__cache_reponer'] ?? null;
	if (!$__c || $__c['cid'] !== cliente_actual() || $__c['t'] < time() - 300) {
		$__st = $pdo->prepare("SELECT COUNT(DISTINCT p.id) FROM productos_clientes p
			JOIN establecimientos e ON e.cliente_id = p.cliente_id
			LEFT JOIN inv_existencias x ON x.producto_id = p.id AND x.establecimiento_id = e.establecimiento_id
			WHERE p.cliente_id = ? AND p.tipo = 'bien' AND p.activo = 1 AND p.stock_minimo > 0 AND COALESCE(x.cantidad, 0) <= p.stock_minimo");
		$__st->execute([cliente_actual()]);
		$__c = ['cid' => cliente_actual(), 't' => time(), 'n' => (int)$__st->fetchColumn()];
		$_SESSION['__cache_reponer'] = $__c;
	}
	$__menuPorReponer = $__c['n'];
}

$menuLateral = [
	'' => [
		['dashboard', 'bi-house-door', 'Inicio', []],
	],
	'Facturación' => [
		['generar_factura', 'bi-plus-circle', 'Nueva factura', []],
		['lista_facturas', 'bi-receipt', 'Historial de facturas', ['editar_factura', 'ver_factura']],
	],
	'Contratos' => [
		['contratos', 'bi-file-earmark-text', 'Lista de contratos', ['editar_contrato', 'facturas_contrato', 'generar_recibo']],
		['crear_contrato', 'bi-file-earmark-plus', 'Nuevo contrato', []],
	],
	// Ciclo del ingreso: facturar (arriba) → contratos → cobrar
	'Cobros' => array_values(array_filter([
		['cuentas_cobrar', 'bi-cash-coin', 'Cuentas por cobrar', ['estado_cuenta']],
		$__menuEsAdmin ? ['cobros_programados', 'bi-send-check', 'Cobros por correo', []] : null,
	])),
	// Ciclo del egreso: pagar → boucher → banco
	'Pagos y gastos' => array_values(array_filter([
		['cuentas_pagar', 'bi-calendar-check', 'Cuentas por pagar', []],
		['gastos', 'bi-wallet2', 'Gastos', ['gasto_ver']],
		$__menuEsAdmin ? ['bouchers', 'bi-receipt-cutoff', 'Bouchers', []] : null,
		['categorias_gastos', 'bi-tags', 'Categorías de gastos', []],
	])),
	'Bancos' => [
		['bancos', 'bi-bank', 'Bancos', ['banco_cuenta']],
		['cheques', 'bi-journal-check', 'Cheques', []],
		['tarjetas', 'bi-credit-card', 'Tarjetas', []],
		['activos', 'bi-pc-display', 'Activos y préstamos', []],
	],
	'Reportes' => [
		['financiero', 'bi-graph-up', 'Estado de resultados', []],
		['estados_financieros', 'bi-journal-text', 'Estado de resultados clásico', []],
		['balance_general', 'bi-bank2', 'Balance general', []],
		['proyeccion', 'bi-graph-up-arrow', 'Proyección de flujo', []],
	],
	'Personal' => array_values(array_filter([
		['colaboradores', 'bi-people', 'Colaboradores', ['colaborador_ver', 'colaborador_reporte']],
		['pagos_nomina', 'bi-cash-stack', 'Pagos de nómina', []],
		USUARIO_ROL === 'nomina' ? ['bouchers', 'bi-receipt-cutoff', 'Bouchers', []] : null,
	])),
	// Se usa al facturar: por eso va antes de Ventas e Inventario
	'Catálogo' => [
		['clientes', 'bi-person-vcard', 'Clientes', ['crear_cliente', 'editar_cliente']],
		['productos', 'bi-box-seam', 'Productos / servicios', []],
		['productos_clientes', 'bi-bookmark-star', 'Productos por cliente', []],
	],
	'Ventas' => [
		['pos', 'bi-cart3', 'Punto de venta', []],
		['pos_turnos', 'bi-clock-history', 'Turnos de caja', []],
	],
	'Inventario' => [
		['inventario', 'bi-boxes', 'Existencias', ['inventario_kardex', 'inventario_reportes'], $__menuPorReponer],
		['inventario_traslados', 'bi-truck', 'Traslados', []],
	],
	// Solo administradores: esas páginas rechazan a facturador/lector
	'Configuración' => array_values(array_filter([
		$__menuEsAdmin ? ['configuracion_cai', 'bi-key', 'Configuración CAI', ['crear_cai', 'editar_cai']] : null,
		$__menuEsAdmin ? ['configuracion_mensajes', 'bi-envelope', 'Mensajes y cuentas de pago', []] : null,
		$__menuEsAdmin ? ['configuracion_correo', 'bi-envelope-at', 'Correo (SMTP)', []] : null,
		$__menuEsAdmin ? ['configuracion_firmas', 'bi-pen', 'Firmas de documentos', []] : null,
		$__menuEsAdmin ? ['usuarios', 'bi-person-gear', 'Usuarios', []] : null,
		function_exists('respaldoPuede') && respaldoPuede() ? ['respaldos', 'bi-database-check', 'Respaldos', []] : null,
		$es_superadmin ? ['configuracion_permisos', 'bi-shield-lock', 'Permisos por rol', []] : null,
	])),
	// Solo superadmin: administración de la plataforma multiempresa
	'Plataforma' => $es_superadmin ? [
		['empresas', 'bi-buildings', 'Empresas', []],
		['seleccionar_cliente', 'bi-arrow-left-right', 'Cambiar empresa', []],
	] : [],
];

// Rol Nómina y gastos: Personal, Pagos y gastos y Bancos (session.php bloquea el resto)
if (USUARIO_ROL === 'nomina') {
	$menuLateral = ['Personal' => $menuLateral['Personal'], 'Pagos y gastos' => $menuLateral['Pagos y gastos'], 'Bancos' => $menuLateral['Bancos']];
	foreach ($menuLateral as $__s => $__items) $menuLateral[$__s] = array_values(array_filter($__items, fn($it) => in_array($it[0], ROL_NOMINA_ARCHIVOS, true)));
}

// Permisos por rol (Configuración → Permisos por rol): se ocultan las opciones desactivadas para este rol
// y, si se abre una de sus páginas directamente, se redirige a la primera opción permitida. «Inicio» siempre se ve.
if (USUARIO_ROL !== 'superadmin') {
	$__menuBloqueada = false;
	foreach ($menuLateral as $__menuSec => $__menuItems) {
		foreach ($__menuItems as $__menuK => $__menuIt) {
			if ($__menuIt[0] === 'dashboard' || permisoMenu($pdo, USUARIO_ROL, $__menuIt[0])) continue;
			if ($__menuIt[0] === $paginaActual || in_array($paginaActual, $__menuIt[3], true)) $__menuBloqueada = true;
			unset($menuLateral[$__menuSec][$__menuK]);
		}
		$menuLateral[$__menuSec] = array_values($menuLateral[$__menuSec]);
	}
	if ($__menuBloqueada) {
		$__menuDestino = 'dashboard';
		foreach ($menuLateral as $__menuItems) if ($__menuItems) { $__menuDestino = $__menuItems[0][0]; break; }
		if (!headers_sent()) header('Location: ' . $__menuDestino);
		else echo '<script>location.replace(' . json_encode($__menuDestino) . ')</script>';
		exit;
	}
	unset($__menuBloqueada, $__menuDestino, $__menuSec, $__menuK);
}

// Título de la página actual para la barra superior
$tituloActual = $titulo ?? null;
if (!$tituloActual) {
	// Variables con prefijo __menu: este archivo se incluye dentro de las páginas y no
	// debe pisar sus variables (p. ej. $items en editar_factura).
	foreach ($menuLateral as $__menuItems) {
		foreach ($__menuItems as $__menuIt) {
			if ($__menuIt[0] === $paginaActual || in_array($paginaActual, $__menuIt[3], true)) {
				$tituloActual = $__menuIt[2];
				break 2;
			}
		}
	}
}
$tituloActual = $tituloActual ?: 'Inicio';
unset($__menuItems, $__menuIt);
