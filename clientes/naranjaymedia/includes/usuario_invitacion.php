<?php
/* clientes/naranjaymedia/includes/usuario_invitacion.php
 * Enlace para que el usuario ponga su propia contraseña (72 horas, un solo uso). Así nadie más conoce la contraseña.
 * POST user_id, enviar (1 = también se lo manda por correo) → { enlace, vence, enviado } */
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/session.php';
require_once __DIR__ . '/../../../includes/clave_reset.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if (!permisoPuede($pdo, 'usuarios')) throw new Exception('Sin permisos.');
    $id = (int)($_POST['user_id'] ?? 0);
    $st = $pdo->prepare("SELECT u.*, c.nombre AS empresa, c.alias FROM usuarios u LEFT JOIN clientes_saas c ON c.id = u.cliente_id WHERE u.id = ?");
    $st->execute([$id]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) throw new Exception('Usuario no encontrado.');
    if (USUARIO_ROL !== 'superadmin') {
        if ((int)$u['cliente_id'] !== (int)cliente_actual()) throw new Exception('No puedes modificar usuarios de otra empresa.');
        if ($u['rol'] === 'superadmin' || ($u['rol'] === 'admin' && USUARIO_ROL !== 'admin')) throw new Exception('No puedes generar el enlace de ese usuario.');
    }
    if ($u['estado'] !== 'activo') throw new Exception('El usuario está inactivo: actívalo primero.');
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
    $horas = 72;
    $enlace = claveInvitacionCrear($pdo, (int)$u['id'], $base, $horas);
    $enviado = false;
    if (!empty($_POST['enviar'])) {
        $cid = (int)$u['cliente_id'];
        $perfil = claveResetPerfil($pdo, $cid);
        if (!$perfil) throw new Exception('El enlace se generó, pero la empresa no tiene una cuenta de correo lista para enviarlo: cópialo y compártelo tú.');
        $empresa = ($u['alias'] ?? '') ?: ($u['empresa'] ?? 'Sistema de Facturación');
        [$html, $texto] = claveInvitacionPlantilla($u['nombre'], $enlace, $empresa, $base, $horas, correoConfig($pdo, $cid, $perfil) ?? []);
        $enviado = correoEnviar($pdo, $cid, $u['correo'], 'Tu acceso al sistema · ' . $empresa, $html, $texto, [], 'clave_reset', (int)$u['id'], (int)USUARIO_ID, $perfil);
    }
    echo json_encode(['success' => true, 'enlace' => $enlace, 'vence' => date('d/m/Y g:i a', strtotime("+$horas hours")), 'enviado' => $enviado, 'correo' => $u['correo'], 'login' => $base], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e instanceof PDOException ? 'Error de base de datos.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
