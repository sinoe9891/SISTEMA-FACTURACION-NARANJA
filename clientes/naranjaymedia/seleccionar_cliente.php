<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';

if (USUARIO_ROL !== 'superadmin') {
    header('Location: ./dashboard');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cliente_id'])) {
    $cliente_id = (int)$_POST['cliente_id'];
    $stmt = $pdo->prepare("SELECT id FROM clientes_saas WHERE id = ?");
    $stmt->execute([$cliente_id]);
    if ($stmt->fetchColumn()) {
        $_SESSION['cliente_seleccionado'] = $cliente_id;
        // Los establecimientos a elegir son los del cliente seleccionado (antes quedaban los
        // asignados al superadmin, que podían ser de otra empresa)
        $stmtE = $pdo->prepare("SELECT establecimiento_id FROM establecimientos WHERE cliente_id = ?");
        $stmtE->execute([$cliente_id]);
        $_SESSION['establecimientos'] = $stmtE->fetchAll(PDO::FETCH_COLUMN);
        unset($_SESSION['establecimiento_activo'], $_SESSION['__cache_cliente'], $_SESSION['__cache_establecimiento']);
        header("Location: ./seleccionar_establecimiento");
        exit;
    }
}

$clientes = $pdo->query("
    SELECT c.id, c.nombre, c.logo_url, c.estado, COUNT(e.establecimiento_id) AS sucursales
    FROM clientes_saas c
    LEFT JOIN establecimientos e ON e.cliente_id = c.id
    GROUP BY c.id
    ORDER BY c.nombre ASC
")->fetchAll(PDO::FETCH_ASSOC);
$actual = (int)($_SESSION['cliente_seleccionado'] ?? 0);
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Seleccionar empresa | Sistema de Facturación</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="../../clientes/css/app.css?v=<?= @filemtime(__DIR__ . '/../css/app.css') ?>" rel="stylesheet">
</head>

<body class="app-login">
    <main class="app-login-wrap">
        <div class="app-login-card app-select-card">
            <div class="text-center mb-4">
                <div class="app-select-icon"><i class="bi bi-buildings"></i></div>
                <h1 class="app-login-title">Selecciona una empresa</h1>
                <p class="app-login-sub">Hola, <?= htmlspecialchars(USUARIO_NOMBRE) ?> · Superadmin</p>
            </div>

            <?php if (!$clientes): ?>
                <div class="alert alert-warning mb-0">No hay empresas registradas.</div>
            <?php else: ?>
                <form method="POST" class="app-select-list">
                    <?= csrf_input() ?>
                    <?php foreach ($clientes as $c): ?>
                        <button type="submit" name="cliente_id" value="<?= (int)$c['id'] ?>"
                            class="app-select-item<?= (int)$c['id'] === $actual ? ' is-actual' : '' ?>">
                            <span class="app-select-logo">
                                <?php if (!empty($c['logo_url'])): ?>
                                    <img src="<?= htmlspecialchars($c['logo_url']) ?>" alt="">
                                <?php else: ?>
                                    <i class="bi bi-building"></i>
                                <?php endif; ?>
                            </span>
                            <span class="app-select-text">
                                <strong><?= htmlspecialchars($c['nombre']) ?></strong>
                                <small><?= (int)$c['sucursales'] ?> establecimiento(s)<?= ($c['estado'] ?? 'activo') !== 'activo' ? ' · inactiva' : '' ?><?= (int)$c['id'] === $actual ? ' · actual' : '' ?></small>
                            </span>
                            <i class="bi bi-chevron-right app-select-go"></i>
                        </button>
                    <?php endforeach; ?>
                </form>
            <?php endif; ?>

            <div class="text-center mt-3">
                <a href="logout" class="small text-muted"><i class="bi bi-box-arrow-right"></i> Cerrar sesión</a>
            </div>
        </div>
    </main>
</body>

</html>
