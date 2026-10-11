<?php
/**
 * cron/respaldo.php — Respaldo diario de la base de datos completa y de los archivos subidos (ver includes/respaldos.php).
 * En cPanel → Trabajos de cron, una vez al día (1:15 del servidor = 2:15 a.m. de Honduras):
 *   /usr/local/bin/php /home/USUARIO/public_html/facturacion.naranjaymediahn.com/cron/respaldo.php >> /home/USUARIO/respaldo.log 2>&1
 * Si ya hay un respaldo automático de hoy, no crea otro (por si el cron se configura más seguido).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo línea de comandos.\n");
}
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/respaldos.php';

$lock = fopen(sys_get_temp_dir() . '/facturacion_respaldo.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit("Ya hay un respaldo en curso.\n");

$log = fn(string $m) => print('[' . date('Y-m-d H:i:s') . "] $m\n");
try {
    $forzar = in_array('--forzar', $argv ?? [], true);
    $hoyBd = null;
    foreach (respaldoLista(false) as $c) if (($c['origen'] ?? '') === 'cron' && substr($c['creado'], 0, 10) === date('Y-m-d')) { $hoyBd = $c; break; }
    if (!$forzar && $hoyBd) {
        $log("Ya existe el respaldo automático de hoy ({$hoyBd['archivo']}); no se crea otro.");
    } else {
        $m = respaldoCrear($pdo, 'cron');
        $log("Respaldo creado: {$m['archivo']} · " . round($m['bytes'] / 1024) . " KB · {$m['tablas']} tablas · {$m['filas']} filas · {$m['segundos']} s");
    }
    $log("Copias guardadas: " . count(respaldoLista(false)) . " de " . RESPALDO_COPIAS . " · carpeta: " . respaldoDir());
    // Archivos subidos (comprobantes, firmas, documentos, cobros): uno por día
    $hoyArch = array_filter(respaldoArchivosLista(false), fn($c) => ($c['origen'] ?? '') === 'cron' && substr($c['creado'], 0, 10) === date('Y-m-d'));
    if ($forzar || !$hoyArch) {
        $a = respaldoArchivosCrear('cron');
        $log("Archivos respaldados: {$a['archivo']} · {$a['archivos']} archivos · " . round($a['bytes'] / 1048576, 1) . " MB · {$a['segundos']} s");
    }
} catch (Throwable $e) {
    $log("ERROR: " . $e->getMessage());
    exit(1);
}
