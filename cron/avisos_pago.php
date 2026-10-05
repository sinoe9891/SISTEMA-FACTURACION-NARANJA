<?php
/**
 * cron/avisos_pago.php — Aviso automático de pago a colaboradores.
 *
 * Envía el aviso de cada pago de nómina con fecha de HOY (hora de Honduras), a partir de la
 * hora configurada (7:00 por defecto), solo si:
 *   - la empresa tiene el correo activo y el aviso automático encendido,
 *   - el colaborador tiene correo,
 *   - el aviso no se envió antes (manual o automático),
 *   - no lleva 3 intentos fallidos hoy.
 * Los pagos de fechas pasadas nunca se envían solos.
 *
 * cPanel → Trabajos de cron, cada 30 minutos:
 *   /usr/local/bin/php /home/USUARIO/public_html/facturacion.naranjaymediahn.com/cron/avisos_pago.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo línea de comandos.\n");
}
require __DIR__ . '/../includes/db.php';          // fija America/Tegucigalpa y la BD en -06:00
require __DIR__ . '/../includes/correo_pagos.php';

// Un solo proceso a la vez
$lock = fopen(sys_get_temp_dir() . '/facturacion_avisos_pago.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit("Ya hay una ejecución en curso.\n");

$ahora = new DateTime('now');
$log = fn(string $m) => print('[' . $ahora->format('Y-m-d H:i') . "] $m\n");

if (!correoDisponible($pdo)) { $log('El módulo de correo no está instalado.'); exit(1); }
$hayAuto = (bool)$pdo->query("SHOW COLUMNS FROM configuracion_correo LIKE 'aviso_pago_auto'")->fetchColumn();
$empresas = $pdo->query("SELECT cliente_id, " . ($hayAuto ? "aviso_pago_hora" : "7 AS aviso_pago_hora") . " FROM configuracion_correo
                         WHERE activo = 1" . ($hayAuto ? " AND aviso_pago_auto = 1" : ""))->fetchAll(PDO::FETCH_ASSOC);

$pendientes = $pdo->prepare("
    SELECT g.id FROM gastos g
    WHERE g.cliente_id = ? AND g.descripcion LIKE 'Sueldo %' AND g.estado = 'pagado' AND g.fecha = CURDATE()
      AND NOT EXISTS (SELECT 1 FROM correos_enviados e WHERE e.cliente_id = g.cliente_id AND e.tipo = 'pago_colaborador'
                      AND e.referencia_id = g.id AND e.estado = 'enviado')
      AND (SELECT COUNT(*) FROM correos_enviados e WHERE e.cliente_id = g.cliente_id AND e.tipo = 'pago_colaborador'
           AND e.referencia_id = g.id AND e.estado = 'error' AND DATE(e.creado_en) = CURDATE()) < 3
    ORDER BY g.id");

$enviados = $fallidos = $sinCorreo = 0;
foreach ($empresas as $emp) {
    if ((int)$ahora->format('G') < (int)$emp['aviso_pago_hora']) continue;   // aún no es la hora
    $pendientes->execute([$emp['cliente_id']]);
    foreach ($pendientes->fetchAll(PDO::FETCH_COLUMN) as $gastoId) {
        try {
            [, $c] = correoPagoDatos($pdo, (int)$emp['cliente_id'], (int)$gastoId);
            if (!filter_var(trim((string)$c['email']), FILTER_VALIDATE_EMAIL)) { $sinCorreo++; continue; }
            $para = correoNotificarPago($pdo, (int)$emp['cliente_id'], (int)$gastoId, null);
            $enviados++;
            $log("Aviso del pago #$gastoId enviado a $para");
        } catch (Throwable $e) {
            $fallidos++;
            $log("Pago #$gastoId: " . $e->getMessage());
        }
    }
}
$log("Listo: $enviados enviado(s), $fallidos con error, $sinCorreo sin correo del colaborador.");
