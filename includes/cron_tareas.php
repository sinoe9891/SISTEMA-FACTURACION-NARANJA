<?php
/**
 * cron_tareas.php — Tareas automáticas que corre cron/tareas.php:
 *   1) Aviso de pago a colaboradores el día del pago (cuenta «nomina»).
 *   2) Cobros por correo programados (cuenta «facturacion»).
 */
require_once __DIR__ . '/correo_pagos.php';
require_once __DIR__ . '/cobros.php';

/** Avisos de pago con fecha de hoy, desde la hora configurada, sin repetir los ya enviados. */
function cronAvisosPago(PDO $pdo, callable $log): void
{
    if (!correoDisponible($pdo)) return;
    $hayAuto = (bool)$pdo->query("SHOW COLUMNS FROM configuracion_correo LIKE 'aviso_pago_auto'")->fetchColumn();
    $empresas = $pdo->query("SELECT cliente_id, " . ($hayAuto ? "aviso_pago_hora" : "7 AS aviso_pago_hora") . " FROM configuracion_correo
                             WHERE activo = 1" . ($hayAuto ? " AND aviso_pago_auto = 1" : "") . (correoTienePerfiles($pdo) ? " AND perfil = 'nomina'" : ""))->fetchAll(PDO::FETCH_ASSOC);
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
        if ((int)date('G') < (int)$emp['aviso_pago_hora']) continue;   // aún no es la hora
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
    if ($enviados || $fallidos || $sinCorreo) $log("Avisos de pago: $enviados enviado(s), $fallidos con error, $sinCorreo sin correo del colaborador.");
}
