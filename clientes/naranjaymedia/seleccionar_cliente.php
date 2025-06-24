<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';

if (USUARIO_ROL !== 'superadmin') {
    header('Location: ./dashboard');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cliente_id'])) {
    $_SESSION['cliente_seleccionado'] = intval($_POST['cliente_id']);
    header("Location: ./seleccionar_establecimiento");
    exit;
}

$stmt = $pdo->query("SELECT id, nombre, logo_url FROM clientes_saas ORDER BY nombre ASC");
$clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Seleccionar Cliente</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5">
    <div class="card shadow">
        <div class="card-body">
            <h4 class="mb-4">🧭 Seleccione un cliente</h4>

            <?php if (count($clientes) === 0): ?>
                <div class="alert alert-warning">No hay clientes registrados.</div>
            <?php else: ?>
                <form method="POST">
                    <div class="mb-3">
                        <label for="cliente_id" class="form-label">Cliente:</label>
                        <select name="cliente_id" id="cliente_id" class="form-select" required>
                            <option value="" disabled selected>Seleccione...</option>
                            <?php foreach ($clientes as $cliente): ?>
                                <option value="<?= $cliente['id'] ?>">
                                    <?= htmlspecialchars($cliente['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Continuar</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
