<?php
/**
 * intentos.php — Límite de intentos fallidos (login y clave autorizadora).
 *
 * Bloquea temporalmente después de varios fallos seguidos para frenar ataques de
 * fuerza bruta. Usa la tabla `login_intentos` (crearla con
 * sql/migraciones/2026-10-03_login_intentos.sql). Si la tabla no existe, el sistema
 * sigue funcionando sin límite (no crea tablas por su cuenta) y lo registra en el log.
 */

const INTENTOS_MAX_USUARIO = 5;   // fallos por correo + IP
const INTENTOS_MAX_IP      = 20;  // fallos por IP (varios correos)
const INTENTOS_MINUTOS     = 15;  // ventana y duración del bloqueo

function intentosTablaLista(PDO $pdo): bool
{
    static $lista = null;
    if ($lista !== null) return $lista;
    try {
        $lista = (bool)$pdo->query("SHOW TABLES LIKE 'login_intentos'")->fetchColumn();
    } catch (Throwable $e) {
        $lista = false;
    }
    if (!$lista) error_log('intentos.php: falta la tabla login_intentos (ver sql/migraciones); sin límite de intentos.');
    return $lista;
}

function intentosIp(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? 'sin-ip', 0, 45);
}

/** Claves que se controlan para un intento: por correo+IP y por IP. */
function intentosClaves(string $tipo, string $correo): array
{
    $ip = intentosIp();
    return [
        [$tipo . ':' . mb_strtolower(trim($correo)) . '|' . $ip, INTENTOS_MAX_USUARIO],
        [$tipo . ':ip|' . $ip, INTENTOS_MAX_IP],
    ];
}

/** Minutos que faltan para poder reintentar (0 = no está bloqueado). */
function intentosBloqueado(PDO $pdo, string $tipo, string $correo): int
{
    if (!intentosTablaLista($pdo)) return 0;
    $stmt = $pdo->prepare("SELECT COUNT(*), MIN(creado_en) FROM login_intentos WHERE clave = ? AND creado_en > (NOW() - INTERVAL " . INTENTOS_MINUTOS . " MINUTE)");
    foreach (intentosClaves($tipo, $correo) as [$clave, $max]) {
        $stmt->execute([$clave]);
        [$n, $primero] = $stmt->fetch(PDO::FETCH_NUM);
        if ((int)$n >= $max) {
            $restante = INTENTOS_MINUTOS * 60 - (time() - strtotime($primero));
            return max(1, (int)ceil($restante / 60));
        }
    }
    return 0;
}

function intentosRegistrarFallo(PDO $pdo, string $tipo, string $correo): void
{
    if (!intentosTablaLista($pdo)) return;
    $stmt = $pdo->prepare("INSERT INTO login_intentos (clave) VALUES (?)");
    foreach (intentosClaves($tipo, $correo) as [$clave]) $stmt->execute([$clave]);
    // Limpieza ocasional de registros viejos
    if (random_int(1, 50) === 1) $pdo->exec("DELETE FROM login_intentos WHERE creado_en < (NOW() - INTERVAL 1 DAY)");
}

/** Tras un acceso correcto se olvidan los fallos de ese correo (no los de la IP). */
function intentosLimpiar(PDO $pdo, string $tipo, string $correo): void
{
    if (!intentosTablaLista($pdo)) return;
    $pdo->prepare("DELETE FROM login_intentos WHERE clave = ?")->execute([intentosClaves($tipo, $correo)[0][0]]);
}
