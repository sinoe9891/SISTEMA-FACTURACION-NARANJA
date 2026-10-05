<?php
/**
 * clave_reset.php — «¿Olvidaste tu contraseña?»: enlace por correo para poner una contraseña nueva.
 *
 * - El enlace lleva un token aleatorio de 256 bits; en la BD solo se guarda su hash SHA-256.
 * - Vence en 60 minutos y sirve una sola vez; pedir uno nuevo invalida los anteriores.
 * - La respuesta es la misma exista o no el correo (no revela qué cuentas existen).
 * - Límite de solicitudes por correo e IP (tabla login_intentos, tipo «reset»).
 * - Se envía con la cuenta SMTP de la empresa (Facturación, o Nómina si Facturación no está lista).
 * Requiere sql/migraciones/2026-10-05_clave_reset.sql.
 */
require_once __DIR__ . '/correo.php';
require_once __DIR__ . '/intentos.php';

const CLAVE_RESET_MINUTOS = 60;

function claveResetDisponible(PDO $pdo): bool
{
    static $ok = null;
    return $ok ??= (bool)$pdo->query("SHOW TABLES LIKE 'clave_resets'")->fetchColumn();
}

/** Reglas de contraseña. Devuelve el error o null si es válida. */
function claveValidar(string $clave, string $correo = ''): ?string
{
    if (mb_strlen($clave) < 10) return 'La contraseña debe tener al menos 10 caracteres.';
    if (!preg_match('/[a-záéíóúñ]/u', $clave) || !preg_match('/[A-ZÁÉÍÓÚÑ]/u', $clave) || !preg_match('/\d/', $clave))
        return 'Usa mayúsculas, minúsculas y números.';
    $local = mb_strtolower(strtok($correo, '@') ?: '');
    if ($local !== '' && mb_strlen($local) >= 4 && str_contains(mb_strtolower($clave), $local)) return 'La contraseña no puede contener tu correo.';
    return null;
}

/** Cuenta SMTP lista para enviar (Facturación primero). */
function claveResetPerfil(PDO $pdo, int $cid): ?string
{
    foreach (['facturacion', 'nomina'] as $p) {
        $cfg = correoConfig($pdo, $cid, $p);
        if ($cfg && (int)($cfg['activo'] ?? 0) && !empty($cfg['clave_cifrada'])) return $p;
    }
    return null;
}

/**
 * Pide el enlace. $cid: empresa de la URL (su cuenta de correo envía el mensaje); $base: URL de la carpeta
 * de la empresa (https://…/clientes/naranjaymedia/). Devuelve true si se envió (solo para registro interno).
 */
function claveResetSolicitar(PDO $pdo, string $correo, int $cid, string $subcarpeta, string $base): bool
{
    $correo = mb_strtolower(trim($correo));
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL) || !claveResetDisponible($pdo)) return false;
    intentosRegistrarFallo($pdo, 'reset', $correo);   // cuenta cada solicitud para el límite

    $st = $pdo->prepare("SELECT u.id, u.nombre, u.correo, u.rol, u.estado, c.subdominio FROM usuarios u LEFT JOIN clientes_saas c ON c.id = u.cliente_id WHERE u.correo = ?");
    $st->execute([$correo]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    // Mismas reglas que el login: activo y de esta empresa (el superadmin entra por cualquiera)
    if (!$u || ($u['estado'] ?? 'activo') !== 'activo' || ($u['rol'] !== 'superadmin' && $u['subdominio'] !== $subcarpeta)) return false;
    $perfil = claveResetPerfil($pdo, $cid);
    if (!$perfil) { error_log('clave_reset: la empresa ' . $cid . ' no tiene cuenta SMTP lista'); return false; }

    $token = bin2hex(random_bytes(32));
    $pdo->prepare("UPDATE clave_resets SET usado_en = NOW() WHERE usuario_id = ? AND usado_en IS NULL")->execute([$u['id']]);
    $pdo->prepare("INSERT INTO clave_resets (usuario_id, token_hash, expira, ip) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL " . CLAVE_RESET_MINUTOS . " MINUTE), ?)")
        ->execute([$u['id'], hash('sha256', $token), intentosIp()]);

    $enlace = rtrim($base, '/') . '/restablecer_clave?token=' . $token;
    $cfg = correoConfig($pdo, $cid, $perfil) ?? [];
    $emp = $pdo->prepare("SELECT nombre, alias FROM clientes_saas WHERE id = ?");
    $emp->execute([$cid]);
    $emp = $emp->fetch(PDO::FETCH_ASSOC) ?: [];
    [$html, $texto] = claveResetPlantilla($u['nombre'], $enlace, $emp['alias'] ?: ($emp['nombre'] ?? 'Sistema de Facturación'), $cfg);
    correoEnviar($pdo, $cid, $u['correo'], 'Restablecer tu contraseña · ' . ($emp['alias'] ?: 'Sistema de Facturación'), $html, $texto, [], 'clave_reset', (int)$u['id'], null, $perfil);
    return true;
}

/** Usuario del token si sigue vigente; null si no existe, venció o ya se usó. */
function claveResetValidar(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token) || !claveResetDisponible($pdo)) return null;
    $st = $pdo->prepare("SELECT r.id AS reset_id, u.id, u.nombre, u.correo FROM clave_resets r JOIN usuarios u ON u.id = r.usuario_id
                         WHERE r.token_hash = ? AND r.usado_en IS NULL AND r.expira > NOW() AND u.estado = 'activo'");
    $st->execute([hash('sha256', $token)]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Cambia la contraseña con un token vigente (lo marca como usado). Devuelve el error o null. */
function claveResetAplicar(PDO $pdo, string $token, string $clave, string $confirmacion): ?string
{
    $u = claveResetValidar($pdo, $token);
    if (!$u) return 'El enlace no es válido o ya venció. Pide uno nuevo.';
    if ($clave !== $confirmacion) return 'Las contraseñas no coinciden.';
    if ($err = claveValidar($clave, $u['correo'])) return $err;
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE usuarios SET clave = ? WHERE id = ?")->execute([password_hash($clave, PASSWORD_DEFAULT), $u['id']]);
    $pdo->prepare("UPDATE clave_resets SET usado_en = NOW() WHERE usuario_id = ? AND usado_en IS NULL")->execute([$u['id']]);
    $pdo->commit();
    intentosLimpiar($pdo, 'login', $u['correo']);
    return null;
}

function claveResetPlantilla(string $nombre, string $enlace, string $empresa, array $cfg): array
{
    $e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
    $logo = !empty($cfg['logo_url'])
        ? '<img src="' . $e($cfg['logo_url']) . '" alt="' . $e($empresa) . '" height="48" style="height:48px;width:auto;border:0;display:block">'
        : '<span style="font-size:18px;font-weight:700;color:#0f172a">' . $e($empresa) . '</span>';
    $html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#f1f5f9;font-family:Segoe UI,Helvetica,Arial,sans-serif">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 12px"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0">'
        . '<tr><td style="padding:22px 28px;border-bottom:4px solid #e4550d">' . $logo . '</td></tr>'
        . '<tr><td style="padding:28px;font-size:14.5px;line-height:1.65;color:#1e293b">'
        . '<p style="margin:0 0 14px">Hola, ' . $e($nombre) . ':</p>'
        . '<p style="margin:0 0 18px">Recibimos una solicitud para restablecer la contraseña de tu cuenta en el sistema de facturación. Para crear una nueva, usa este botón:</p>'
        . '<p style="margin:0 0 22px"><a href="' . $e($enlace) . '" style="display:inline-block;background:#e4550d;color:#fff;text-decoration:none;font-weight:600;padding:12px 22px;border-radius:8px">Crear contraseña nueva</a></p>'
        . '<p style="margin:0 0 10px;font-size:13px;color:#475569">El enlace vence en ' . CLAVE_RESET_MINUTOS . ' minutos y sirve una sola vez.</p>'
        . '<p style="margin:0;font-size:13px;color:#475569">Si no lo pediste, ignora este correo: tu contraseña no cambia.</p>'
        . '</td></tr>'
        . '<tr><td style="padding:16px 28px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:11.5px;line-height:1.55;color:#94a3b8">'
        . correoPieAutomatico($cfg, $e) . ' Nunca te pediremos tu contraseña por correo.</td></tr>'
        . '</table></td></tr></table></body></html>';
    $texto = "Hola, $nombre:\n\nPara crear una contraseña nueva abre este enlace (vence en " . CLAVE_RESET_MINUTOS . " minutos y sirve una sola vez):\n$enlace\n\nSi no lo pediste, ignora este correo: tu contraseña no cambia.";
    return [$html, $texto];
}
