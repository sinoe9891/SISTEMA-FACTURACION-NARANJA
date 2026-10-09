<?php
/**
 * cron/tasa_dolar.php — Tasa del dólar del BCH (compra y venta), independiente de los demás envíos automáticos.
 * Consulta UNA vez al día a la medianoche de Honduras; si el BCH falla, un solo reintento a las 6:00 am.
 * Las demás horas no consulta nada (solo revisa la base). Así funciona aunque el servidor no esté en hora de Honduras.
 * En cPanel → Trabajos de cron, cada hora en punto (0 * * * *):
 *   /usr/local/bin/php /home/USUARIO/public_html/facturacion.naranjaymediahn.com/cron/tasa_dolar.php >> /home/USUARIO/tasa_dolar.log 2>&1
 * Para probarlo a mano a cualquier hora: agregar el argumento «forzar».
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo línea de comandos.\n");
}
require __DIR__ . '/../includes/db.php';            // fija America/Tegucigalpa
require __DIR__ . '/../includes/tasa_cambio.php';

$log = fn(string $m) => print('[' . date('Y-m-d H:i') . " Honduras] $m\n");
if (!tasaDisponible($pdo)) exit;
$forzar = in_array('forzar', $argv ?? [], true);
$hora = (int)date('G');
$hoy = $pdo->query("SELECT fuente FROM tasas_cambio WHERE fecha = CURDATE()")->fetchColumn();
if (!$forzar) {
    if ($hoy === 'BCH') exit;                       // ya está la oficial de hoy
    if ($hora !== 0 && !($hora === 6 && $hoy !== 'BCH')) exit;   // solo a las 00:00 y, si falló, a las 6:00
}
$cid = (int)($pdo->query("SELECT cliente_id FROM configuracion_api WHERE bch_clave_cifrada IS NOT NULL LIMIT 1")->fetchColumn() ?: 0);
try {
    $t = tasaActualizar($pdo, $cid);
    $log($t['fuente'] === 'BCH' ? "BCH: compra {$t['compra']} · venta {$t['venta']}" : "Referencia {$t['referencia']}" . (isset($t['aviso']) ? " · {$t['aviso']}" : ''));
} catch (Throwable $e) {
    $log('Error: ' . $e->getMessage());
}
