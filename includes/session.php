<?php
ini_set('session.cookie_path', '/');
session_start();

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

// Traer usuario + subdominio asignado
$stmt = $pdo->prepare("
    SELECT u.*, c.subdominio 
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

// Constantes
define('USUARIO_ID', $usuario['id']);
define('USUARIO_NOMBRE', $usuario['nombre']);
define('USUARIO_ROL', $usuario['rol']);

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
