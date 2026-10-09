<?php
/**
 * cron/tasa_dolar.php — Tasa del dólar del BCH (compra y venta), independiente de los demás envíos automáticos.
 * Consulta a la medianoche de Honduras; si el BCH no responde, reintenta cada hora hasta lograrlo y mientras tanto
 * se mantiene la última tasa oficial del BCH (no la de referencia).
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
$cid = (int)($pdo->query("SELECT cliente_id FROM configuracion_api WHERE bch_clave_cifrada IS NOT NULL LIMIT 1")->fetchColumn() ?: 0);
if (!$forzar) {
    if ($hoy === 'BCH') exit;                       // ya está la oficial de hoy
    // Con la llave del BCH: a la medianoche y, si el BCH no respondió, cada hora hasta lograrlo (mientras tanto se usa
    // su última tasa oficial). Sin llave: solo la de referencia a las 00:00 y a las 6:00.
    if (!$cid && $hora !== 0 && !($hora === 6 && !$hoy)) exit;
}
try {
    $t = tasaActualizar($pdo, $cid);
    $log($t['fuente'] === 'BCH' ? "BCH: compra {$t['compra']} · venta {$t['venta']}" : "Referencia {$t['referencia']}" . (isset($t['aviso']) ? " · {$t['aviso']}" : ''));
} catch (Throwable $e) {
    $log('Error: ' . $e->getMessage());
}
