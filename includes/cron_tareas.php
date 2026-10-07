<?php
/**
 * cron_tareas.php — Tareas automáticas que corre cron/tareas.php:
 *   1) Aviso de pago a colaboradores el día del pago (cuenta «nomina»).
 *   2) Cobros por correo programados (cuenta «facturacion»).
 */
require_once __DIR__ . '/correo_pagos.php';
require_once __DIR__ . '/cobros.php';
require_once __DIR__ . '/tasa_cambio.php';

/** Tasa del dólar del día (una vez al día, desde las 9 a. m., cuando el BCH ya publicó). */
function cronTasaCambio(PDO $pdo, callable $log): void
{
    if (!tasaDisponible($pdo) || (int)date('G') < 9) return;
    $hoy = $pdo->query("SELECT fuente FROM tasas_cambio WHERE fecha = CURDATE()")->fetchColumn();
    if ($hoy === 'BCH') return;
    $cid = (int)($pdo->query("SELECT cliente_id FROM configuracion_api WHERE bch_clave_cifrada IS NOT NULL LIMIT 1")->fetchColumn() ?: 0);
    if ($hoy && !$cid) return;   // ya hay referencia de hoy y no hay clave del BCH
    try {
        $t = tasaActualizar($pdo, $cid);
        $log("Tasa del dólar: " . ($t['fuente'] === 'BCH' ? "compra {$t['compra']} · venta {$t['venta']} (BCH)" : "referencia {$t['referencia']}") . (isset($t['aviso']) ? " · {$t['aviso']}" : ''));
    } catch (Throwable $e) {
        $log("Tasa del dólar: " . $e->getMessage());
    }
}

/**
 * Avisos de pago pendientes, desde la hora configurada, sin repetir los ya enviados.
 * Se pone al día: envía los de pagos con fecha entre la fecha de corte (aviso_pago_desde) y hoy, con un
 * máximo de 45 días atrás. Un pago registrado por adelantado (p. ej. el 29 con fecha 30) se avisa el día 30.
 * Sin fecha de corte, solo los pagos con fecha de hoy.
 */
function cronAvisosPago(PDO $pdo, callable $log): void
{
    if (!correoDisponible($pdo)) return;
    $hayAuto = (bool)$pdo->query("SHOW COLUMNS FROM configuracion_correo LIKE 'aviso_pago_auto'")->fetchColumn();
    $hayDesde = (bool)$pdo->query("SHOW COLUMNS FROM configuracion_correo LIKE 'aviso_pago_desde'")->fetchColumn();
    $empresas = $pdo->query("SELECT cliente_id, " . ($hayAuto ? "aviso_pago_hora" : "7 AS aviso_pago_hora") . ", " . ($hayDesde ? "aviso_pago_desde" : "NULL AS aviso_pago_desde") . " FROM configuracion_correo
                             WHERE activo = 1" . ($hayAuto ? " AND aviso_pago_auto = 1" : "") . (correoTienePerfiles($pdo) ? " AND perfil = 'nomina'" : ""))->fetchAll(PDO::FETCH_ASSOC);
    $pendientes = $pdo->prepare("
        SELECT g.id FROM gastos g
        WHERE g.cliente_id = ? AND g.descripcion LIKE 'Sueldo %' AND g.estado = 'pagado'
          AND g.fecha <= CURDATE() AND g.fecha >= GREATEST(COALESCE(?, CURDATE()), CURDATE() - INTERVAL 45 DAY)
          AND NOT EXISTS (SELECT 1 FROM correos_enviados e WHERE e.cliente_id = g.cliente_id AND e.tipo = 'pago_colaborador'
                          AND e.referencia_id = g.id AND e.estado = 'enviado')
          AND (SELECT COUNT(*) FROM correos_enviados e WHERE e.cliente_id = g.cliente_id AND e.tipo = 'pago_colaborador'
               AND e.referencia_id = g.id AND e.estado = 'error' AND DATE(e.creado_en) = CURDATE()) < 3
        ORDER BY g.fecha, g.id");
    $enviados = $fallidos = $sinCorreo = 0;
    foreach ($empresas as $emp) {
        if ((int)date('G') < (int)$emp['aviso_pago_hora']) continue;   // aún no es la hora
        $pendientes->execute([$emp['cliente_id'], $emp['aviso_pago_desde']]);
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
