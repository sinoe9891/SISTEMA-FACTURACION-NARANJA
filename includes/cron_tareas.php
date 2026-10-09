<?php
/**
 * cron_tareas.php — Tareas automáticas que corre cron/tareas.php:
 *   1) Aviso de pago a colaboradores el día del pago (cuenta «nomina»).
 *   2) Cobros por correo programados (cuenta «facturacion»).
 *   3) Tasa del dólar del día.
 *   4) Aviso de documentos de la empresa por vencer (7 días) y vencidos (cuenta «facturacion»).
 */
require_once __DIR__ . '/correo_pagos.php';
require_once __DIR__ . '/cobros.php';
require_once __DIR__ . '/tasa_cambio.php';
require_once __DIR__ . '/documentos.php';

/** Tasa del dólar del día (una vez al día, desde las 9 a. m., cuando el BCH ya publicó). */
function cronTasaCambio(PDO $pdo, callable $log): void
{
    // Una consulta al día desde las 00:00 (el cron corre cada 5 minutos: solo la primera vez del día consulta).
    // Si el BCH falló y quedó la tasa de referencia, se reintenta como mucho cada 3 horas y solo hasta las 3 pm.
    if (!tasaDisponible($pdo)) return;
    $hoy = $pdo->query("SELECT fuente, actualizado_en FROM tasas_cambio WHERE fecha = CURDATE()")->fetch(PDO::FETCH_ASSOC);
    if ($hoy && $hoy['fuente'] === 'BCH') return;
    $cid = (int)($pdo->query("SELECT cliente_id FROM configuracion_api WHERE bch_clave_cifrada IS NOT NULL LIMIT 1")->fetchColumn() ?: 0);
    if ($hoy) {
        if (!$cid) return;   // ya hay referencia de hoy y no hay clave del BCH
        if ((int)date('G') >= 15 || time() - strtotime((string)$hoy['actualizado_en']) < 3 * 3600) return;
    }
    try {
        $t = tasaActualizar($pdo, $cid);
        $pdo->exec("UPDATE tasas_cambio SET actualizado_en = NOW() WHERE fecha = CURDATE()");   // marca el intento (para no reintentar seguido)
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

/**
 * Documentos de la empresa: un correo cuando faltan 7 días o menos y otro cuando ya vencieron (una vez cada uno;
 * al renovar el documento se vuelve a avisar). Va a los correos de «Responder a» de la cuenta Facturación o, si no
 * hay, al correo de la empresa. Desde las 8 a. m.
 */
function cronDocumentosVencimiento(PDO $pdo, callable $log): void
{
    if (!docsDisponible($pdo) || !correoDisponible($pdo) || (int)date('G') < 8) return;
    $st = $pdo->query("SELECT d.*, s.email AS empresa_email, s.nombre AS empresa FROM empresa_documentos d JOIN clientes_saas s ON s.id = d.cliente_id
                       WHERE d.fecha_vencimiento IS NOT NULL AND d.fecha_vencimiento <= CURDATE() + INTERVAL 7 DAY ORDER BY d.cliente_id, d.fecha_vencimiento");
    $ya = $pdo->prepare("SELECT COUNT(*) FROM correos_enviados WHERE cliente_id = ? AND tipo = ? AND referencia_id = ? AND estado = 'enviado' AND creado_en >= ?");
    $fallos = $pdo->prepare("SELECT COUNT(*) FROM correos_enviados WHERE cliente_id = ? AND tipo = ? AND referencia_id = ? AND estado = 'error' AND DATE(creado_en) = CURDATE()");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $d = docEstado($d);
        $tipo = $d['dias'] < 0 ? 'documento_vencido' : 'documento_por_vencer';
        $cid = (int)$d['cliente_id'];
        $ya->execute([$cid, $tipo, $d['id'], $d['actualizado_en']]);
        if ($ya->fetchColumn()) continue;
        $fallos->execute([$cid, $tipo, $d['id']]);
        if ($fallos->fetchColumn() >= 3) continue;
        $cfg = correoConfig($pdo, $cid, 'facturacion') ?? [];
        $para = implode(', ', correoLista((string)($cfg['responder_a'] ?? ''))) ?: (string)$d['empresa_email'];
        if (!filter_var(correoLista($para)[0] ?? '', FILTER_VALIDATE_EMAIL)) { $log("Documento #{$d['id']}: sin correo para avisar."); continue; }
        $e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
        $vence = date('d/m/Y', strtotime($d['fecha_vencimiento']));
        $estado = $d['dias'] < 0 ? 'venció el ' . $vence . ' (hace ' . abs($d['dias']) . ' día' . (abs($d['dias']) === 1 ? '' : 's') . ')' : ($d['dias'] === 0 ? 'vence hoy (' . $vence . ')' : 'vence el ' . $vence . ' (en ' . $d['dias'] . ' día' . ($d['dias'] === 1 ? '' : 's') . ')');
        $asunto = ($d['dias'] < 0 ? 'Documento vencido: ' : 'Documento por vencer: ') . $d['nombre'];
        $html = '<div style="font-family:Segoe UI,Helvetica,Arial,sans-serif;font-size:14.5px;line-height:1.6;color:#1e293b">'
            . '<p>El documento <strong>' . $e($d['nombre']) . '</strong> de ' . $e($d['empresa']) . ' <strong style="color:' . ($d['dias'] < 0 ? '#b91c1c' : '#9a3412') . '">' . $e($estado) . '</strong>.</p>'
            . '<p>Súbelo renovado en <em>Configuración → Documentos de la empresa</em> para que los cobros por correo lleven la versión vigente.</p></div>';
        try {
            correoEnviar($pdo, $cid, $para, $asunto, $html, strip_tags(str_replace('</p>', "\n\n", $html)), [], $tipo, (int)$d['id'], null, 'facturacion');
            $log("Aviso «{$asunto}» enviado a $para");
        } catch (Throwable $ex) {
            $log("Documento #{$d['id']}: " . $ex->getMessage());
        }
    }
}
