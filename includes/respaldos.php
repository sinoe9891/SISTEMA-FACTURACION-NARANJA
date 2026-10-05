<?php
/**
 * respaldos.php — Respaldo de la base de datos completa (ISO/IEC 27001, control 8.13 «Respaldo de la información»).
 *
 * Política:
 *  - Copia completa diaria (cron/respaldo.php, 2:15 a.m. de Honduras) y a pedido desde Configuración → Respaldos.
 *  - Retención: se conservan las 7 copias más recientes; al crear una nueva se borran las anteriores.
 *  - Se guardan FUERA de la carpeta pública (por defecto ~/respaldos_bd), con permisos solo del dueño (0600/0700).
 *  - Integridad: cada copia lleva su huella SHA-256; se comprueba antes de descargarla.
 *  - Trazabilidad: creaciones, descargas, borrados y fallos van a respaldos.log (JSON por línea) en la
 *    misma carpeta, para que la bitácora sobreviva aunque se pierda la base de datos.
 *  - Acceso: superadmin y el admin de la empresa dueña de la plataforma (RESPALDO_EMPRESA_DUENA).
 *
 * El volcado se hace con PHP/PDO (no depende de mysqldump) en una transacción de solo lectura con
 * instantánea consistente, y se comprime en .sql.gz. Se restaura importándolo en phpMyAdmin o con
 * `gunzip < archivo.sql.gz | mysql -u USUARIO -p BASE`.
 */

const RESPALDO_COPIAS = 7;
const RESPALDO_EMPRESA_DUENA = 2;   // Naranja & Media: su admin también administra los respaldos
const RESPALDO_PATRON = '/^respaldo_\d{4}-\d{2}-\d{2}_\d{6}\.sql\.gz$/';

/** Carpeta de respaldos (fuera de public_html). RESPALDO_DIR la cambia; en pruebas (APP_DB=dev) usa una temporal. */
function respaldoDir(): string
{
    $d = getenv('RESPALDO_DIR') ?: (getenv('APP_DB') === 'dev'
        ? sys_get_temp_dir() . '/respaldos_bd_dev'
        : dirname(__DIR__, 3) . '/respaldos_bd');   // /home/USUARIO/public_html/sitio/includes → /home/USUARIO/respaldos_bd
    $d = rtrim($d, '/') . '/';
    if (!is_dir($d) && !@mkdir($d, 0700, true)) throw new Exception("No se pudo crear la carpeta de respaldos.");
    // Por si la carpeta quedara dentro del sitio (p. ej. en local): que el servidor web no la sirva
    if (!is_file($d . '.htaccess')) @file_put_contents($d . '.htaccess', "Require all denied\nDeny from all\n");
    if (!is_writable($d)) throw new Exception("La carpeta de respaldos no tiene permiso de escritura.");
    return $d;
}

/** ¿El usuario de la sesión puede ver, crear y descargar respaldos? */
function respaldoPuede(): bool
{
    if (!defined('USUARIO_ROL')) return false;
    if (USUARIO_ROL === 'superadmin') return true;
    return USUARIO_ROL === 'admin' && function_exists('cliente_actual') && (int)cliente_actual() === RESPALDO_EMPRESA_DUENA;
}

/** Agrega un evento a la bitácora de respaldos. */
function respaldoLog(string $evento, array $datos = []): void
{
    $linea = ['fecha' => date('Y-m-d H:i:s'), 'evento' => $evento] + $datos + [
        'usuario' => defined('USUARIO_ID') ? (int)USUARIO_ID : null,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'cron',
    ];
    try {
        @file_put_contents(respaldoDir() . 'respaldos.log', json_encode($linea, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        error_log('respaldoLog: ' . $e->getMessage());
    }
}

/** Últimos eventos de la bitácora (más recientes primero). */
function respaldoBitacora(int $max = 200): array
{
    $f = respaldoDir() . 'respaldos.log';
    if (!is_file($f)) return [];
    $lineas = array_slice(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [], -$max);
    return array_reverse(array_values(array_filter(array_map(fn($l) => json_decode($l, true), $lineas))));
}

/**
 * Crea una copia completa. $origen: 'cron' | 'manual'. Devuelve los metadatos de la copia.
 */
function respaldoCrear(PDO $pdo, string $origen, ?int $usuario = null): array
{
    @set_time_limit(600);
    $dir = respaldoDir();
    // Nombre por fecha y hora; si ya existe uno de este mismo segundo, espera para no sobrescribirlo
    while (is_file($dir . ($nombre = 'respaldo_' . date('Y-m-d_His') . '.sql.gz'))) sleep(1);
    $tmp = $dir . '.' . $nombre . '.tmp';
    $ini = microtime(true);
    $base = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
    $tablas = $filas = 0;

    $gz = gzopen($tmp, 'wb6');
    if (!$gz) throw new Exception("No se pudo crear el archivo de respaldo.");
    try {
        $w = function (string $s) use ($gz) { if (gzwrite($gz, $s) === false) throw new Exception("No se pudo escribir el respaldo (¿disco lleno?)."); };
        // Instantánea consistente: todas las tablas InnoDB se leen como estaban en este instante
        $pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ");
        $pdo->exec("START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY");

        $w("-- Respaldo completo de la base `$base`\n-- Generado: " . date('Y-m-d H:i:s') . " (hora de Honduras) · origen: $origen\n"
            . "-- Restaurar: importar en phpMyAdmin, o: gunzip < $nombre | mysql -u USUARIO -p BASE\n\n"
            . "SET NAMES utf8mb4;\nSET time_zone = '-06:00';\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n");

        $lista = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
        foreach ($lista as [$t]) {
            $q = '`' . str_replace('`', '``', $t) . '`';
            $create = $pdo->query("SHOW CREATE TABLE $q")->fetch(PDO::FETCH_NUM)[1];
            $w("-- ----------------------------------------\n-- Tabla $q\n-- ----------------------------------------\nDROP TABLE IF EXISTS $q;\n$create;\n\n");
            $st = $pdo->query("SELECT * FROM $q");
            $lote = [];
            while ($fila = $st->fetch(PDO::FETCH_NUM)) {
                $lote[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $fila)) . ')';
                $filas++;
                if (count($lote) === 200) { $w("INSERT INTO $q VALUES\n" . implode(",\n", $lote) . ";\n"); $lote = []; }
            }
            if ($lote) $w("INSERT INTO $q VALUES\n" . implode(",\n", $lote) . ";\n");
            $w("\n");
            $tablas++;
        }
        // Vistas (si las hubiera), al final porque dependen de las tablas
        foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetchAll(PDO::FETCH_NUM) as [$v]) {
            $q = '`' . str_replace('`', '``', $v) . '`';
            $create = preg_replace('/DEFINER=`[^`]*`@`[^`]*`\s*/', '', $pdo->query("SHOW CREATE VIEW $q")->fetch(PDO::FETCH_NUM)[1]);
            $w("DROP VIEW IF EXISTS $q;\n$create;\n\n");
        }
        $w("SET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n-- Fin del respaldo ($tablas tablas, $filas filas)\n");
        $pdo->exec("COMMIT");
    } catch (Throwable $e) {
        try { $pdo->exec("ROLLBACK"); } catch (Throwable $ignorar) {}
        gzclose($gz);
        @unlink($tmp);
        respaldoLog('error', ['origen' => $origen, 'detalle' => mb_substr($e->getMessage(), 0, 300)]);
        throw $e;
    }
    gzclose($gz);

    $final = $dir . $nombre;
    if (!rename($tmp, $final)) { @unlink($tmp); throw new Exception("No se pudo guardar el respaldo."); }
    @chmod($final, 0600);
    $meta = [
        'archivo' => $nombre, 'creado' => date('Y-m-d H:i:s'), 'bytes' => filesize($final), 'sha256' => hash_file('sha256', $final),
        'base' => $base, 'tablas' => $tablas, 'filas' => $filas, 'segundos' => round(microtime(true) - $ini, 1),
        'origen' => $origen, 'usuario' => $usuario,
    ];
    file_put_contents($final . '.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    @chmod($final . '.json', 0600);
    respaldoLog('creado', ['archivo' => $nombre, 'origen' => $origen, 'bytes' => $meta['bytes'], 'tablas' => $tablas, 'filas' => $filas, 'sha256' => $meta['sha256']]);
    respaldoRetencion();
    return $meta;
}

/** Deja solo las RESPALDO_COPIAS copias más recientes. Devuelve los archivos borrados. */
function respaldoRetencion(): array
{
    $copias = array_column(respaldoLista(false), 'archivo');   // más recientes primero
    $borrados = [];
    foreach (array_slice($copias, RESPALDO_COPIAS) as $a) {
        @unlink(respaldoDir() . $a);
        @unlink(respaldoDir() . $a . '.json');
        $borrados[] = $a;
        respaldoLog('eliminado', ['archivo' => $a, 'motivo' => 'retención de ' . RESPALDO_COPIAS . ' copias']);
    }
    return $borrados;
}

/** Copias existentes (más recientes primero). Con $verificar, recalcula la huella SHA-256 de cada una. */
function respaldoLista(bool $verificar = true): array
{
    $dir = respaldoDir();
    $out = [];
    foreach (glob($dir . 'respaldo_*.sql.gz') ?: [] as $ruta) {
        $a = basename($ruta);
        if (!preg_match(RESPALDO_PATRON, $a)) continue;
        $meta = is_file($ruta . '.json') ? (json_decode((string)file_get_contents($ruta . '.json'), true) ?: []) : [];
        $meta += ['archivo' => $a, 'creado' => date('Y-m-d H:i:s', filemtime($ruta)), 'bytes' => filesize($ruta), 'sha256' => null, 'tablas' => null, 'filas' => null, 'origen' => '—'];
        $meta['bytes'] = filesize($ruta);
        if ($verificar) $meta['integro'] = $meta['sha256'] ? hash_equals($meta['sha256'], hash_file('sha256', $ruta)) : null;
        $out[] = $meta;
    }
    usort($out, fn($x, $y) => strcmp($y['archivo'], $x['archivo']));
    return $out;
}

/** Ruta de una copia por nombre (valida el nombre: nada de rutas arbitrarias). */
function respaldoRuta(string $archivo): string
{
    if (!preg_match(RESPALDO_PATRON, $archivo)) throw new Exception("Respaldo no válido.");
    $ruta = respaldoDir() . $archivo;
    if (!is_file($ruta)) throw new Exception("El respaldo ya no existe.");
    return $ruta;
}

/** Prueba de lectura: descomprime todo el archivo y confirma que el volcado está completo. */
function respaldoProbar(string $archivo): array
{
    $ruta = respaldoRuta($archivo);
    $meta = is_file($ruta . '.json') ? (json_decode((string)file_get_contents($ruta . '.json'), true) ?: []) : [];
    $hashOk = !empty($meta['sha256']) && hash_equals($meta['sha256'], hash_file('sha256', $ruta));
    $gz = gzopen($ruta, 'rb');
    if (!$gz) throw new Exception("No se pudo abrir el respaldo.");
    $creates = $bytes = 0;
    $cola = '';
    while (!gzeof($gz)) {
        $b = gzread($gz, 1 << 20);
        if ($b === false) break;
        $bytes += strlen($b);
        $creates += substr_count($cola . $b, 'CREATE TABLE') - substr_count($cola, 'CREATE TABLE');
        $cola = substr($b, -64);
    }
    gzclose($gz);
    $completo = str_contains($cola, '-- Fin del respaldo');
    $ok = $hashOk && $completo && (!isset($meta['tablas']) || $creates === (int)$meta['tablas']);
    respaldoLog('verificado', ['archivo' => $archivo, 'resultado' => $ok ? 'correcto' : 'con problemas']);
    return ['ok' => $ok, 'huella' => $hashOk, 'completo' => $completo, 'tablas' => $creates, 'mb_sql' => round($bytes / 1048576, 2)];
}
