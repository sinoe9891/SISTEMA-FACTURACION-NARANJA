<?php
// clientes/naranjaymedia/includes/empresa_guardar.php
// Superadmin: crea o actualiza una empresa (tenant). Al crearla también crea su primer
// establecimiento + punto de emisión y, opcionalmente, su usuario administrador.
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
header('Content-Type: application/json; charset=utf-8');

const SUBDOMINIOS_RESERVADOS = ['css', 'js', 'includes', 'api', 'app', 'admin', 'www', 'facturacion', 'uploads', 'dev', 'docs', 'sql', 'vendor'];
const PLANES = ['basico', 'profesional', 'empresarial'];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    if (USUARIO_ROL !== 'superadmin') throw new Exception("Solo el superadmin puede administrar empresas.");

    $id          = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;
    $txt         = fn(string $k, int $max) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
    $nombre      = $txt('nombre', 100);
    $razon       = $txt('razon_social', 255);
    $alias       = $txt('alias', 250);
    $subdominio  = strtolower($txt('subdominio', 40));
    $rtn         = preg_replace('/\D/', '', $txt('rtn', 25));
    $email       = $txt('email', 100);
    $telefono    = $txt('telefono', 20);
    $direccion   = $txt('direccion', 250);
    $plan        = $txt('tipo_plan', 50) ?: 'basico';
    $urls        = [];
    foreach (['logo_url' => 255, 'favicon_url' => 500, 'og_image_url' => 500, 'apple_touch_icon_url' => 500] as $k => $max) {
        $u = $txt($k, $max);
        if ($u !== '' && !preg_match('#^https?://#i', $u)) throw new Exception("La URL de " . str_replace('_', ' ', $k) . " debe empezar con http:// o https://.");
        $urls[$k] = $u ?: null;
    }

    if ($nombre === '')    throw new Exception("El nombre es obligatorio.");
    if ($direccion === '') throw new Exception("La dirección es obligatoria.");
    if (!preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $subdominio))
        throw new Exception("El subdominio debe tener de 2 a 40 caracteres: letras minúsculas, números y guiones (ej. grupo-velmez).");
    if (in_array($subdominio, SUBDOMINIOS_RESERVADOS, true)) throw new Exception("El subdominio «{$subdominio}» está reservado.");
    if ($rtn !== '' && strlen($rtn) !== 14) throw new Exception("El RTN debe tener 14 dígitos.");
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception("Correo inválido.");
    if (!in_array($plan, PLANES, true)) throw new Exception("Plan inválido.");
    $alias = $alias !== '' ? $alias : $nombre;

    $stmt = $pdo->prepare("SELECT id FROM clientes_saas WHERE subdominio = ? AND id <> ?");
    $stmt->execute([$subdominio, (int)$id]);
    if ($stmt->fetchColumn()) throw new Exception("Ya existe una empresa con el subdominio «{$subdominio}».");

    $campos = [
        'nombre' => $nombre, 'razon_social' => $razon ?: null, 'alias' => $alias, 'subdominio' => $subdominio,
        'rtn' => $rtn ?: null, 'email' => $email ?: null, 'telefono' => $telefono ?: null, 'direccion' => $direccion,
        'tipo_plan' => $plan,
    ] + $urls;

    $pdo->beginTransaction();

    if ($id) {
        $stmt = $pdo->prepare("SELECT subdominio FROM clientes_saas WHERE id = ?");
        $stmt->execute([$id]);
        $anterior = $stmt->fetchColumn();
        if ($anterior === false) throw new Exception("Empresa no encontrada.");
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($campos)));
        $pdo->prepare("UPDATE clientes_saas SET $sets WHERE id = ?")->execute([...array_values($campos), $id]);
        $pdo->commit();
        $aviso = ($anterior && $anterior !== $subdominio)
            ? " Atención: la dirección de acceso cambió de /clientes/$anterior/ a /clientes/$subdominio/."
            : '';
        echo json_encode(['success' => true, 'message' => 'Empresa actualizada.' . $aviso, 'id' => $id]);
        exit;
    }

    // ── Nueva empresa ────────────────────────────────────────────────────────
    $cols = implode(', ', array_keys($campos));
    $ph   = implode(', ', array_fill(0, count($campos), '?'));
    $pdo->prepare("INSERT INTO clientes_saas ($cols, estado) VALUES ($ph, 'activo')")->execute(array_values($campos));
    $nuevoId = (int)$pdo->lastInsertId();

    // Primer establecimiento y punto de emisión
    $estNombre = $txt('establecimiento_nombre', 100) ?: 'Principal';
    $pdo->prepare("INSERT INTO establecimientos (cliente_id, nombre, codigo_establecimiento, codigo_punto) VALUES (?, ?, '001', '01')")
        ->execute([$nuevoId, $estNombre]);
    $estId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO puntos_emision (establecimiento_id, codigo_punto, descripcion) VALUES (?, '01', ?)")
        ->execute([$estId, 'Caja principal']);

    // Administrador opcional
    $adminCorreo = $txt('admin_correo', 100);
    if ($adminCorreo !== '') {
        $adminNombre = $txt('admin_nombre', 100);
        $adminClave  = (string)($_POST['admin_clave'] ?? '');
        if ($adminNombre === '') throw new Exception("Indica el nombre del administrador.");
        if (!filter_var($adminCorreo, FILTER_VALIDATE_EMAIL)) throw new Exception("Correo del administrador inválido.");
        if (strlen($adminClave) < 8) throw new Exception("La contraseña del administrador debe tener al menos 8 caracteres.");
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE correo = ?");
        $stmt->execute([$adminCorreo]);
        if ($stmt->fetchColumn()) throw new Exception("Ya existe un usuario con el correo $adminCorreo.");
        $pdo->prepare("INSERT INTO usuarios (cliente_id, nombre, correo, clave, rol, estado) VALUES (?, ?, ?, ?, 'admin', 'activo')")
            ->execute([$nuevoId, $adminNombre, $adminCorreo, password_hash($adminClave, PASSWORD_DEFAULT)]);
        $pdo->prepare("INSERT INTO usuario_establecimientos (usuario_id, establecimiento_id) VALUES (?, ?)")
            ->execute([(int)$pdo->lastInsertId(), $estId]);
    }

    $pdo->commit();
    echo json_encode([
        'success' => true,
        'message' => "Empresa creada. Acceso: /clientes/$subdominio/",
        'id'      => $nuevoId,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
