<?php
session_start();
require_once '../../includes/db.php';

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['establecimientos'])) {
    session_destroy();
    header("Location: ./index.php");
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$establecimientos_ids = $_SESSION['establecimientos'];

// Si no tiene establecimientos asignados, mostramos alerta y forzamos logout
if (empty($establecimientos_ids)) {
    // Enviar una variable para disparar alerta JS
    $sinEstablecimientos = true;
} else {
    $sinEstablecimientos = false;
}

// Obtener datos del cliente para logo y nombre
$cliente_subcarpeta = $_SESSION['subdominio_actual'] ?? null;
$logo_url = null;
$nombre_cliente = null;
if ($cliente_subcarpeta) {
    $stmtCliente = $pdo->prepare("SELECT nombre, logo_url FROM clientes_saas WHERE subdominio = ?");
    $stmtCliente->execute([$cliente_subcarpeta]);
    $cliente = $stmtCliente->fetch();
    if ($cliente) {
        $logo_url = $cliente['logo_url'];
        $nombre_cliente = $cliente['nombre'];
    }
}

// Obtener datos de establecimientos para mostrar nombres
if (!$sinEstablecimientos) {
    $stmt = $pdo->prepare("SELECT establecimiento_id, nombre FROM establecimientos WHERE establecimiento_id IN (" . implode(',', array_map('intval', $establecimientos_ids)) . ")");
    $stmt->execute();
    $establecimientos = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $establecimiento_seleccionado = $_POST['establecimiento_id'] ?? null;
    if (in_array($establecimiento_seleccionado, $establecimientos_ids)) {
        $_SESSION['establecimiento_activo'] = intval($establecimiento_seleccionado);
        unset($_SESSION['establecimientos']); // ya no hace falta
        header("Location: ./dashboard");
        exit;
    } else {
        // Si no es válido, cerrar sesión y volver a login para mayor seguridad
        session_destroy();
        header("Location: ./index.php");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Seleccionar Establecimiento | <?= htmlspecialchars($nombre_cliente ?: 'Sistema de Facturación') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>

<body class="bg-light">

    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-4">
                <?php if ($logo_url): ?>
                    <div class="text-center mb-4">
                        <img src="<?= htmlspecialchars($logo_url) ?>" alt="<?= htmlspecialchars($nombre_cliente) ?>" style="max-height: 80px;">
                    </div>
                <?php endif; ?>

                <?php if ($sinEstablecimientos): ?>
                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            Swal.fire({
                                icon: 'warning',
                                title: 'No tienes establecimientos asignados',
                                text: 'No es posible continuar sin un establecimiento asignado.',
                                confirmButtonText: 'Cerrar sesión'
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    window.location.href = 'logout'; // Cambia la ruta si tu logout es diferente
                                }
                            });
                        });
                    </script>
                <?php else: ?>
                    <div class="card shadow">
                        <div class="card-body">
                            <h4 class="text-center mb-4">Seleccione un establecimiento para continuar</h4>

                            <form method="POST">
                                <div class="mb-3">
                                    <select name="establecimiento_id" class="form-select" required>
                                        <option value="">-- Seleccione un establecimiento --</option>
                                        <?php foreach ($establecimientos as $estab): ?>
                                            <option value="<?= htmlspecialchars($estab['establecimiento_id']) ?>">
                                                <?= htmlspecialchars($estab['nombre']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-primary w-100">Continuar</button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

</body>

</html>
