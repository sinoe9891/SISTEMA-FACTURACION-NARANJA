<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';

define('ALERTA_FACTURAS_RESTANTES', 20);
define('ALERTA_CAI_DIAS', 30); // Alertar CAI por vencer con 30 días o menos

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
}  else {
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

// Contar facturas filtrando por establecimiento
$stmtFact = $pdo->prepare("SELECT COUNT(*) AS total FROM facturas WHERE cliente_id = ? AND establecimiento_id = ?");
$stmtFact->execute([$cliente_id, $establecimiento_activo]);
$total_facturas = $stmtFact->fetchColumn();

// Datos CAI para mostrar
$facturas_restantes = $cai ? ($cai['rango_fin'] - $cai['correlativo_actual']) : 0;
$fecha_limite = $cai ? $cai['fecha_limite'] : null;

// Función para formatear fecha evitando 01/01/1970
function formatFechaLimite($fecha) {
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
// Usar header común
require_once '../../includes/templates/header.php';
?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4> <?= $emoji ?> <?= $saludo ?>, <?= htmlspecialchars(USUARIO_NOMBRE) ?></h4>
            <h6 class="text-muted"> Sucursal: <?= htmlspecialchars($nombre_establecimiento) ?> | Rol: <?= htmlspecialchars(ucfirst($datos['rol'])) ?> | Cliente: <?= htmlspecialchars($datos['cliente_nombre']) ?></h6>
        </div>
        <div>
            <img src="<?= htmlspecialchars($datos['logo_url']) ?>" alt="Logo cliente" style="max-height: 60px;">
        </div>
    </div>

    <div class="row">
        <div class="col-md-4">
            <div class="card border-primary mb-3">
                <div class="card-header">Facturas emitidas</div>
                <div class="card-body text-primary">
                    <h5 class="card-title"><?= $total_facturas ?></h5>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-success mb-3">
                <div class="card-header">Facturas restantes</div>
                <div class="card-body text-success">
                    <h5 class="card-title"><?= $facturas_restantes ?></h5>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card border-warning mb-3">
                <div class="card-header">Fecha límite CAI</div>
                <div class="card-body text-warning">
                    <h5 class="card-title">
                        <h5 class="card-title"><?= formatFechaLimite($fecha_limite) ?></h5>
                    </h5>
                </div>
            </div>
        </div>
    </div>

    <?php if ($facturas_restantes <= ALERTA_FACTURAS_RESTANTES && $total_facturas > 0): ?>
        <div class="alert alert-warning mt-4">
            ⚠️ ¡Atención! Estás por agotar tu rango de facturación.
        </div>
    <?php endif; ?>

    <?php if ($alerta_cai_vencido): ?>
        <div class="alert alert-danger">
            ⏰ Tu CAI está por vencer. Fecha límite: <?= formatFechaLimite($fecha_limite) ?>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
