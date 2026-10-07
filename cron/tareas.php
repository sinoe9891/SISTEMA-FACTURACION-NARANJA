<?php
/**
 * cron/tareas.php — Envíos automáticos (hora de Honduras): avisos de pago pendientes (se pone al día desde la
 * fecha de corte de la cuenta Nómina), cobros programados, tasa del dólar y aviso de documentos por vencer. En cPanel → Trabajos de cron, cada 5 minutos:
 *   /usr/local/bin/php /home/USUARIO/public_html/facturacion.naranjaymediahn.com/cron/tareas.php >> /home/USUARIO/tareas.log 2>&1
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo línea de comandos.\n");
}
require __DIR__ . '/../includes/db.php';          // fija America/Tegucigalpa y la BD en -06:00
require __DIR__ . '/../includes/cron_tareas.php';

// Un solo proceso a la vez
$lock = fopen(sys_get_temp_dir() . '/facturacion_tareas.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit("Ya hay una ejecución en curso.\n");

$log = fn(string $m) => print('[' . date('Y-m-d H:i') . "] $m\n");
cronAvisosPago($pdo, $log);
cobrosPendientesEnviar($pdo, $log);
cronTasaCambio($pdo, $log);
cronDocumentosVencimiento($pdo, $log);
