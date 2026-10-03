<?php
require_once '../../includes/sesion_inicio.php';
iniciarSesionSegura();
require_once '../../includes/db.php';
require_once '../../includes/csrf.php';

if (!isset($_SESSION['usuario_id'])) {
    header("Location: ./index.php");
    exit;
}

$stmtU = $pdo->prepare("
    SELECT u.id, u.nombre, u.rol, u.cliente_id, u.estado
    FROM usuarios u WHERE u.id = ?
");
$stmtU->execute([(int)$_SESSION['usuario_id']]);
$usuario = $stmtU->fetch(PDO::FETCH_ASSOC);
if (!$usuario || ($usuario['estado'] ?? 'activo') !== 'activo') {
    session_destroy();
    header("Location: ./index.php");
    exit;
}

// Empresa de la que se eligen establecimientos
$esSuper = $usuario['rol'] === 'superadmin';
$cliente_id = $esSuper ? (int)($_SESSION['cliente_seleccionado'] ?? 0) : (int)$usuario['cliente_id'];
if ($esSuper && !$cliente_id) {
    header("Location: ./seleccionar_cliente");
    exit;
}

// Establecimientos permitidos (calculados aquí, no tomados de la sesión):
// - superadmin: todos los de la empresa seleccionada
// - demás: los asignados al usuario dentro de su empresa; si no tiene asignados, todos los de su empresa
if ($esSuper) {
    $stmt = $pdo->prepare("SELECT establecimiento_id, nombre FROM establecimientos WHERE cliente_id = ? ORDER BY nombre");
    $stmt->execute([$cliente_id]);
} else {
    $stmt = $pdo->prepare("
        SELECT e.establecimiento_id, e.nombre
        FROM usuario_establecimientos ue
        JOIN establecimientos e ON e.establecimiento_id = ue.establecimiento_id
        WHERE ue.usuario_id = ? AND e.cliente_id = ?
        ORDER BY e.nombre
    ");
    $stmt->execute([$usuario['id'], $cliente_id]);
}
$establecimientos = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$establecimientos && !$esSuper && $cliente_id) {
    $stmt = $pdo->prepare("SELECT establecimiento_id, nombre FROM establecimientos WHERE cliente_id = ? ORDER BY nombre");
    $stmt->execute([$cliente_id]);
    $establecimientos = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$permitidos = array_map('intval', array_column($establecimientos, 'establecimiento_id'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verificar();
    $elegido = (int)($_POST['establecimiento_id'] ?? 0);
    if (in_array($elegido, $permitidos, true)) {
        $_SESSION['establecimiento_activo'] = $elegido;
        unset($_SESSION['establecimientos'], $_SESSION['__cache_establecimiento']);
        header("Location: ./dashboard");
        exit;
    }
    // Establecimiento no permitido: cerrar sesión por seguridad
    session_destroy();
    header("Location: ./index.php");
    exit;
}

// Marca de la empresa
$stmtC = $pdo->prepare("SELECT nombre, logo_url FROM clientes_saas WHERE id = ?");
$stmtC->execute([$cliente_id]);
$cliente = $stmtC->fetch(PDO::FETCH_ASSOC) ?: [];
$nombre_cliente = $cliente['nombre'] ?? 'Sistema de Facturación';
$logo_url = $cliente['logo_url'] ?? null;
$actual = (int)($_SESSION['establecimiento_activo'] ?? 0);
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Seleccionar establecimiento | <?= htmlspecialchars($nombre_cliente) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
    <link href="../../clientes/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?>" rel="stylesheet" />
</head>

<body class="app-login">
    <main class="app-login-wrap">
        <div class="app-login-card app-select-card">
            <div class="text-center mb-4">
                <?php if ($logo_url): ?>
                    <img src="<?= htmlspecialchars($logo_url) ?>" alt="<?= htmlspecialchars($nombre_cliente) ?>" class="app-login-logo">
                <?php else: ?>
                    <div class="app-select-icon"><i class="bi bi-shop"></i></div>
                <?php endif; ?>
                <h1 class="app-login-title">Selecciona un establecimiento</h1>
                <p class="app-login-sub"><?= htmlspecialchars($nombre_cliente) ?></p>
            </div>

            <?php if (!$establecimientos): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    No hay establecimientos disponibles. Pide a un administrador que te asigne uno.
                </div>
                <a href="logout" class="btn btn-outline-secondary w-100">Cerrar sesión</a>
            <?php else: ?>
                <form method="POST" class="app-select-list">
                    <?= csrf_input() ?>
                    <?php foreach ($establecimientos as $e): ?>
                        <button type="submit" name="establecimiento_id" value="<?= (int)$e['establecimiento_id'] ?>"
                            class="app-select-item<?= (int)$e['establecimiento_id'] === $actual ? ' is-actual' : '' ?>">
                            <span class="app-select-logo"><i class="bi bi-shop"></i></span>
                            <span class="app-select-text">
                                <strong><?= htmlspecialchars($e['nombre']) ?></strong>
                                <?php if ((int)$e['establecimiento_id'] === $actual): ?><small>Establecimiento actual</small><?php endif; ?>
                            </span>
                            <i class="bi bi-chevron-right app-select-go"></i>
                        </button>
                    <?php endforeach; ?>
                </form>
                <div class="text-center mt-3 d-flex justify-content-center gap-3">
                    <?php if ($esSuper): ?><a href="seleccionar_cliente" class="small text-muted"><i class="bi bi-buildings"></i> Cambiar empresa</a><?php endif; ?>
                    <a href="logout" class="small text-muted"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</a>
                </div>
            <?php endif; ?>
        </div>
    </main>
</body>

</html>
