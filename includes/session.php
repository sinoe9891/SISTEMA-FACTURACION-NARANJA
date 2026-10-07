<?php
require_once __DIR__ . '/sesion_inicio.php';
iniciarSesionSegura();

require_once __DIR__ . '/db.php';

$uri = $_SERVER['REQUEST_URI'] ?? '';
$isApi = (strpos($uri, '/includes/api/') === 0);

// Si NO hay sesión
if (!isset($_SESSION['usuario_id'])) {
    if ($isApi) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'No autenticado']);
        exit;
    }
    header("Location: /");
    exit;
}

$usuario_id = (int)$_SESSION['usuario_id'];

// Traer usuario + subdominio asignado (+ marca del cliente, que reutiliza el header
// para no repetir esta consulta en cada página)
$stmt = $pdo->prepare("
    SELECT u.*, c.subdominio, c.estado AS cliente_estado,
           c.nombre AS cliente_nombre, c.alias AS cliente_alias, c.logo_url,
           c.og_image_url, c.favicon_url, c.apple_touch_icon_url
    FROM usuarios u
    LEFT JOIN clientes_saas c ON u.cliente_id = c.id
    WHERE u.id = ?
    LIMIT 1
");
$stmt->execute([$usuario_id]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$usuario) {
    session_destroy();
    if ($isApi) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Sesión inválida']);
        exit;
    }
    header("Location: /");
    exit;
}

// Usuario desactivado o empresa desactivada (excepto superadmin): cerrar la sesión
if (($usuario['estado'] ?? 'activo') !== 'activo'
    || (($usuario['rol'] ?? '') !== 'superadmin' && ($usuario['cliente_estado'] ?? 'activo') === 'inactivo')) {
    session_destroy();
    if ($isApi) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Cuenta o empresa desactivada']);
        exit;
    }
    header("Location: ./");
    exit;
}

// --- Detectar cliente por URL (/clientes/<cliente>/...) ---
$cliente_en_url = null;
$uri_parts = explode('/', trim(parse_url($uri, PHP_URL_PATH) ?? '', '/'));
for ($i = count($uri_parts) - 1; $i >= 0; $i--) {
    if ($uri_parts[$i] === 'clientes' && !empty($uri_parts[$i + 1])) {
        $cliente_en_url = strtolower(trim($uri_parts[$i + 1]));
        break;
    }
}

// --- Detectar subdominio, pero IGNORAR facturacion (igual que index.php) ---
$host = $_SERVER['HTTP_HOST'] ?? '';
$host_parts = explode('.', $host);
if ($host_parts[0] === 'www') array_shift($host_parts);
$subdominio_detectado = (count($host_parts) >= 3) ? strtolower($host_parts[0]) : null;

// ⚠️ importante
if (in_array($subdominio_detectado, ['www', 'facturacion', ''])) {
    $subdominio_detectado = null;
}

// Cliente detectado: URL > subdominio real > sesión guardada
$cliente_detectado = $cliente_en_url ?? $subdominio_detectado ?? ($_SESSION['subdominio_actual'] ?? null);

// Guardar subdominio actual si no existe
if (!isset($_SESSION['subdominio_actual']) && $cliente_detectado) {
    $_SESSION['subdominio_actual'] = $cliente_detectado;
}

// Validar acceso (solo si tenemos cliente_detectado)
if (($usuario['rol'] ?? '') !== 'superadmin' && $cliente_detectado && $cliente_detectado !== ($usuario['subdominio'] ?? null)) {
    session_destroy();

    if ($isApi) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Acceso no autorizado (cliente incorrecto)']);
        exit;
    }

    header("Location: /clientes/{$cliente_detectado}/");
    exit;
}

// Copia para el header (algunas páginas reutilizan la variable $usuario)
$__usuarioSesion = $usuario;

// Constantes
define('USUARIO_ID', $usuario['id']);
define('USUARIO_NOMBRE', $usuario['nombre']);
define('USUARIO_ROL', $usuario['rol']);

/*
 * Rol «nomina» (Nómina y gastos): Personal, Pagos y gastos y Bancos. Lista blanca de archivos (páginas y acciones);
 * cualquier otro se rechaza aquí, para no depender de que cada página revise el rol. Lo delicado sigue siendo de
 * administradores dentro de cada acción (crear/editar cuentas bancarias, anular movimientos y cheques, eliminar gastos).
 */
const ROL_NOMINA_ARCHIVOS = [
    // Páginas
    'colaboradores', 'colaborador_ver', 'colaborador_reporte', 'colaborador_recibo_pdf', 'pagos_nomina', 'pagos_nomina_exportar',
    'gasto_archivo', 'gasto_ver', 'movimiento_archivo', 'logout', 'seleccionar_establecimiento',
    'cuentas_pagar', 'gastos', 'categorias_gastos', 'bancos', 'banco_cuenta', 'cheques', 'tarjetas',
    // Acciones (clientes/<empresa>/includes/)
    'colaborador_guardar', 'colaborador_actualizar', 'colaborador_cuotas_info', 'colaborador_pago_guardar',
    'prestamo_guardar', 'prestamo_editar', 'prestamo_cancelar', 'prestamo_eliminar', 'prestamo_cuota_pagar', 'prestamo_cuota_editar',
    'nomina_pago_accion', 'correo_accion', 'colaborador_firma', 'boucher_pdf', 'boucher_lote', 'bouchers',
    'gasto_guardar', 'gasto_actualizar', 'gasto_marcar_pagado', 'gasto_eliminar',
    'categoria_gasto_guardar', 'categoria_gasto_actualizar', 'categoria_gasto_eliminar',
    'tarjeta_guardar', 'tarjeta_actualizar', 'tarjeta_eliminar', 'banco_accion',
];
if (USUARIO_ROL === 'nomina' && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? '', '.php'), ROL_NOMINA_ARCHIVOS, true)) {
    $__esAccion = str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/includes/') || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET';
    if ($__esAccion) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['success' => false, 'error' => 'Tu rol (Nómina) no tiene acceso a esta acción.'], JSON_UNESCAPED_UNICODE));
    }
    header('Location: colaboradores');
    exit;
}

/** ¿El gasto es un pago a colaborador (sueldo, bono, viático o pago adicional)? */
function esGastoNomina(string $descripcion): bool
{
    return (bool)preg_match('/^(Sueldo |Bono: |Vi[aá]tico: |Pago adicional - )/u', $descripcion);
}

/** ¿Puede administrar la nómina (registrar, editar, anular)? admin, superadmin y el rol Nómina. */
function puedeNomina(): bool
{
    return in_array(USUARIO_ROL, ['admin', 'superadmin', 'nomina'], true);
}

// (El resto de tu session.php sigue igual)
// ✅ Mantener compatibilidad con el resto del sistema
define('SUBDOMINIO_ACTUAL', $_SESSION['subdominio_actual'] ?? null);

$__clienteIdConst = null;
$__clienteSubdomConst = null;

// Para superadmin, el cliente sale de la selección
if (USUARIO_ROL === 'superadmin') {
    $__clienteIdConst = isset($_SESSION['cliente_seleccionado']) ? (int)$_SESSION['cliente_seleccionado'] : null;
} else {
    // Para usuario normal, sale del usuario (u.cliente_id) y del join (c.subdominio)
    $__clienteIdConst = isset($usuario['cliente_id']) ? (int)$usuario['cliente_id'] : null;
    $__clienteSubdomConst = $usuario['subdominio'] ?? null;
}

define('CLIENTE_ID', $__clienteIdConst);
define('CLIENTE_SUBDOMINIO', $__clienteSubdomConst);

// (Opcional pero recomendado) establecimientos en sesión si no existen
if (!isset($_SESSION['establecimientos'])) {
    $stmtEstab = $pdo->prepare("SELECT establecimiento_id FROM usuario_establecimientos WHERE usuario_id = ?");
    $stmtEstab->execute([USUARIO_ID]);
    $_SESSION['establecimientos'] = $stmtEstab->fetchAll(PDO::FETCH_COLUMN);
}
define('USUARIO_ESTABLECIMIENTOS', $_SESSION['establecimientos'] ?? []);

/**
 * Empresa (cliente_saas) con la que se trabaja: la del usuario, o la seleccionada si es
 * superadmin. Usar siempre esta función para filtrar datos por empresa.
 */
function cliente_actual(): int
{
    return (int)(USUARIO_ROL === 'superadmin' ? ($_SESSION['cliente_seleccionado'] ?? 0) : CLIENTE_ID);
}

// Protección CSRF: todo POST/PUT/PATCH/DELETE autenticado debe traer el token de la sesión
require_once __DIR__ . '/csrf.php';
csrf_verificar();
