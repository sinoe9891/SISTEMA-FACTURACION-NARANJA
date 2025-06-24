<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

$usuario_id = $_SESSION['usuario_id'] ?? null;
$rol_usuario = '';
$cliente_id = null;
$establecimiento_activo = $_SESSION['establecimiento_activo'] ?? null;

// Obtener datos del usuario
$stmt = $pdo->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->execute([$usuario_id]);
$usuario = $stmt->fetch();

$rol_usuario = $usuario['rol'];
$cliente_id = $usuario['cliente_id'] ?? null;

// Si no es superadmin, obtenemos datos del cliente
if ($rol_usuario !== 'superadmin' && $cliente_id) {
    $stmt = $pdo->prepare("SELECT nombre, logo_url FROM clientes_saas WHERE id = ?");
    $stmt->execute([$cliente_id]);
    $cliente = $stmt->fetch();
    $cliente_nombre = $cliente['nombre'];
    $logo_url = $cliente['logo_url'] ?? '';
} else {
    $cliente_nombre = 'Todos los clientes';
    $logo_url = '';
}

if (!in_array($rol_usuario, ['admin', 'superadmin'])) {
    echo "<script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
    <script>
        Swal.fire('Acceso denegado', 'Solo administradores pueden acceder.', 'error')
        .then(() => window.location.href = './dashboard');
    </script>";
    exit;
}

// Establecimiento activo y nombre
$nombre_establecimiento = 'No asignado';
if ($establecimiento_activo) {
    $stmt = $pdo->prepare("SELECT nombre FROM establecimientos WHERE establecimiento_id = ?");
    $stmt->execute([$establecimiento_activo]);
    $nombre_establecimiento = $stmt->fetchColumn() ?: 'No asignado';
}

// Obtener lista de establecimientos
if ($rol_usuario === 'superadmin') {
    $stmt = $pdo->query("SELECT * FROM establecimientos ORDER BY nombre ASC");
} else {
    $stmt = $pdo->prepare("SELECT * FROM establecimientos WHERE cliente_id = ? ORDER BY nombre ASC");
    $stmt->execute([$cliente_id]);
}
$establecimientos = $stmt->fetchAll();

// Obtener lista de rangos CAI
if ($rol_usuario === 'superadmin') {
    $stmt = $pdo->query("SELECT cr.*, e.nombre AS establecimiento_nombre, c.nombre AS cliente_nombre
                         FROM cai_rangos cr
                         INNER JOIN establecimientos e ON cr.establecimiento_id = e.establecimiento_id
                         INNER JOIN clientes_saas c ON e.cliente_id = c.id
                         ORDER BY cr.id DESC");
} else {
    $stmt = $pdo->prepare("SELECT cr.*, e.nombre AS establecimiento_nombre
                            FROM cai_rangos cr
                            INNER JOIN establecimientos e ON cr.establecimiento_id = e.establecimiento_id
                            WHERE cr.cliente_id = ? AND cr.establecimiento_id = ?
                            ORDER BY cr.id DESC");
    $stmt->execute([$cliente_id, $establecimiento_activo]);
}
$cais = $stmt->fetchAll();

require_once '../../includes/templates/header.php';
?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4>🔐 Configuración de Rango CAI</h4>
            <h6 class="text-muted">
                Sucursal: <?= htmlspecialchars($nombre_establecimiento) ?> |
                Rol: <?= htmlspecialchars(ucfirst($rol_usuario)) ?> |
                Cliente: <?= htmlspecialchars($cliente_nombre) ?>
            </h6>
        </div>
        <?php if ($logo_url): ?>
            <img src="<?= htmlspecialchars($logo_url) ?>" alt="Logo cliente" style="max-height: 60px;">
        <?php endif; ?>
    </div>

    <a href="crear_cai.php" class="btn btn-success mb-3">➕ Nuevo Rango CAI</a>

    <table class="table table-bordered table-striped">
        <thead class="table-light">
            <tr>
                <th>CAI</th>
                <th>Establecimiento</th>
                <?php if ($rol_usuario === 'superadmin'): ?>
                    <th>Cliente</th>
                <?php endif; ?>
                <th>Rango Inicio</th>
                <th>Rango Fin</th>
                <th>Correlativo Actual</th>
                <th>Recepción</th>
                <th>Vencimiento</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($cais as $cai): ?>
                <tr>
                    <td><?= htmlspecialchars($cai['cai']) ?></td>
                    <td><?= htmlspecialchars($cai['establecimiento_nombre']) ?></td>
                    <?php if ($rol_usuario === 'superadmin'): ?>
                        <td><?= htmlspecialchars($cai['cliente_nombre']) ?></td>
                    <?php endif; ?>
                    <td><?= htmlspecialchars($cai['rango_inicio']) ?></td>
                    <td><?= htmlspecialchars($cai['rango_fin']) ?></td>
                    <td><?= htmlspecialchars($cai['correlativo_actual']) ?></td>
                    <td><?= htmlspecialchars($cai['fecha_recepcion']) ?></td>
                    <td><?= htmlspecialchars($cai['fecha_limite']) ?></td>
                    <td>
                        <a href="editar_cai.php?id=<?= $cai['id'] ?>" class="btn btn-sm btn-primary">✏️ Editar</a>
                        <form method="POST" action="eliminar_cai.php" style="display:inline;" onsubmit="return confirmarEliminacion(event, this);">
                            <input type="hidden" name="id" value="<?= $cai['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">🗑 Eliminar</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    function confirmarEliminacion(e, form) {
        e.preventDefault();
        Swal.fire({
            title: '¿Está seguro?',
            text: 'Esta acción no se puede deshacer.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Sí, eliminar'
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
        return false;
    }
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
