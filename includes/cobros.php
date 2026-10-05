<?php
/**
 * cobros.php — Cobros por correo programados (facturas en PDF + mensaje + fecha y hora).
 *
 *   cobroCrear($pdo, $cid, $uid, $datos, $renderPdf)   → id (genera y guarda los PDF al programar)
 *   cobroEnviar($pdo, $cid, $cobroId, $uid)            → envía (desde la cuenta «facturacion»)
 *   cobrosPendientesEnviar($pdo, $log)                  → para el cron: envía los que ya tocan
 *
 * Tablas: sql/migraciones/2026-10-04_cobros_programados.sql. Hora: America/Tegucigalpa (db.php).
 */
require_once __DIR__ . '/correo.php';

const COBRO_TIPOS = ['saldo_pendiente' => 'Saldo pendiente', 'envio_factura' => 'Envío de facturas'];

function cobrosDisponible(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = (bool)$pdo->query("SHOW TABLES LIKE 'cobros_programados'")->fetchColumn() && correoTienePerfiles($pdo);
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

function cobroDir(int $cid, int $cobroId): string
{
    return __DIR__ . '/../clientes/naranjaymedia/includes/uploads/cobros/' . $cid . '/' . $cobroId . '/';
}

/** Deja solo etiquetas de formato básicas y sin atributos (el mensaje viene de un editor del navegador). */
function cobroLimpiarHtml(string $html): string
{
    $html = preg_replace('#<(script|style|iframe|object|embed)[^>]*>.*?</\1>#is', '', $html);
    $html = strip_tags($html, '<p><br><div><strong><b><em><i><u><ul><ol><li><span>');
    $html = preg_replace('#<(p|br|div|strong|b|em|i|u|ul|ol|li|span)\b[^>]*>#i', '<$1>', $html);
    // Texto plano con saltos de línea (de la plantilla) → <br>
    if (!preg_match('#<(p|div|br|li)\b#i', $html)) $html = nl2br(trim($html), false);
    return trim($html);
}

function cobroTextoPlano(string $html): string
{
    // En HTML los saltos de línea son solo espacio: los saltos reales salen de <br>, </p>, </div>, </li>
    $t = preg_replace('/\s*\R\s*/', ' ', $html);
    $t = preg_replace('#\s*<\s*(br|/p|/div|/li)\b[^>]*>\s*#i', "\n", $t);
    $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace("/\n{3,}/", "\n\n", $t));
}

/**
 * Crea el cobro y guarda los PDF de las facturas. $renderPdf(int $facturaId): string devuelve el PDF.
 * $d: receptor_id, factura_ids[], tipo, para, cc, asunto, mensaje_html, programado_para (Y-m-d\TH:i), prueba
 */
function cobroCrear(PDO $pdo, int $cid, int $uid, array $d, callable $renderPdf): int
{
    $rid = (int)($d['receptor_id'] ?? 0);
    $st = $pdo->prepare("SELECT id, nombre FROM clientes_factura WHERE id = ? AND cliente_id = ?");
    $st->execute([$rid, $cid]);
    if (!$st->fetch()) throw new Exception("Cliente no válido.");

    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($d['factura_ids'] ?? [])))));
    if (!$ids) throw new Exception("Selecciona al menos una factura.");
    if (count($ids) > 30) throw new Exception("Máximo 30 facturas por cobro.");
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, correlativo FROM facturas WHERE id IN ($in) AND cliente_id = ? AND receptor_id = ? AND estado = 'emitida'");
    $st->execute([...$ids, $cid, $rid]);
    $facturas = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    if (count($facturas) !== count($ids)) throw new Exception("Alguna factura no pertenece a este cliente o está anulada.");

    $para = implode(', ', correoLista((string)($d['para'] ?? '')));
    $cc = implode(', ', correoLista((string)($d['cc'] ?? '')));
    if ($para === '') throw new Exception("Indica el correo del destinatario.");
    foreach (correoLista("$para, $cc") as $m) if (!filter_var($m, FILTER_VALIDATE_EMAIL)) throw new Exception("Correo inválido: $m");
    $asunto = trim((string)($d['asunto'] ?? ''));
    if ($asunto === '') throw new Exception("Escribe el asunto.");
    $mensaje = cobroLimpiarHtml((string)($d['mensaje_html'] ?? ''));
    if (cobroTextoPlano($mensaje) === '') throw new Exception("Escribe el mensaje.");
    $tipo = isset(COBRO_TIPOS[$d['tipo'] ?? '']) ? $d['tipo'] : 'saldo_pendiente';
    $prueba = !empty($d['prueba']);

    if ($prueba || !empty($d['enviar_ahora'])) {
        $cuando = date('Y-m-d H:i:s');
    } else {
        $dt = DateTime::createFromFormat('Y-m-d\TH:i', (string)($d['programado_para'] ?? ''), new DateTimeZone('America/Tegucigalpa'));
        if (!$dt) throw new Exception("Fecha y hora de envío inválidas.");
        if ($dt < new DateTime('-5 minutes')) throw new Exception("La fecha de envío ya pasó; elige una fecha futura o «Enviar ahora».");
        $cuando = $dt->format('Y-m-d H:i:s');
    }

    $pdo->prepare("INSERT INTO cobros_programados (cliente_id, receptor_id, tipo, para, cc, asunto, mensaje_html, programado_para, prueba, usuario_id)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$cid, $rid, $tipo, $para, $cc ?: null, mb_substr($asunto, 0, 255), $mensaje, $cuando, $prueba ? 1 : 0, $uid]);
    $id = (int)$pdo->lastInsertId();

    // PDF de cada factura, tal como se ve al imprimirla
    $dir = cobroDir($cid, $id);
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new Exception("No se pudo crear la carpeta de los PDF.");
    $ins = $pdo->prepare("INSERT INTO cobros_programados_facturas (cobro_id, factura_id, archivo) VALUES (?, ?, ?)");
    foreach ($ids as $fid) {
        $pdf = $renderPdf($fid);
        if (!is_string($pdf) || strncmp($pdf, '%PDF', 4) !== 0) throw new Exception("No se pudo generar el PDF de la factura {$facturas[$fid]}.");
        $archivo = 'factura_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $facturas[$fid]) . '.pdf';
        file_put_contents($dir . $archivo, $pdf);
        $ins->execute([$id, $fid, $archivo]);
    }
    return $id;
}

/** Plantilla del correo de cobro: el mensaje redactado dentro del diseño de la empresa. */
function cobroPlantilla(string $mensaje, array $empresa, array $cfg, array $facturas, bool $prueba): array
{
    $e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
    $empresaNombre = $empresa['alias'] ?: ($empresa['nombre'] ?? 'La empresa');
    $logoUrl = $cfg['logo_url'] ?? '';
    $enlace = $cfg['enlace_url'] ?? '';
    $logo = $logoUrl
        ? '<img src="' . $e($logoUrl) . '" alt="' . $e($empresaNombre) . '" height="56" style="height:56px;width:auto;max-width:200px;border:0;display:block">'
        : '<span style="font-size:18px;font-weight:700;color:#0f172a">' . $e($empresaNombre) . '</span>';
    if ($enlace) $logo = '<a href="' . $e($enlace) . '" target="_blank" style="text-decoration:none">' . $logo . '</a>';
    $adjuntos = $facturas ? '<p style="margin:18px 0 0;font-size:13px;color:#64748b">📎 Adjuntos: ' . $e(implode(', ', array_map(fn($f) => 'factura ' . $f, $facturas))) . '</p>' : '';
    $aviso = $prueba ? '<tr><td style="padding:10px 28px;background:#fef3c7;color:#92400e;font-size:12px;font-weight:600">PRUEBA · Este correo es una vista previa y no se envió al cliente.</td></tr>' : '';

    $html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#f1f5f9;font-family:Segoe UI,Helvetica,Arial,sans-serif">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 12px"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0">'
        . $aviso
        . '<tr><td style="padding:22px 28px;border-bottom:4px solid #e4550d">' . $logo . '</td></tr>'
        . '<tr><td style="padding:28px;font-size:14.5px;line-height:1.65;color:#1e293b">' . $mensaje . $adjuntos . '</td></tr>'
        . '<tr><td style="padding:16px 28px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:11.5px;line-height:1.55;color:#94a3b8">'
        . correoPieAutomatico($cfg, $e) . ' Si ya realizó el pago, por favor haga caso omiso de este mensaje. '
        . 'La información es confidencial y está dirigida únicamente a su destinatario.'
        . ($enlace ? '<br><a href="' . $e($enlace) . '" target="_blank" style="color:#64748b">' . $e(preg_replace('#^https?://|/$#', '', $enlace)) . '</a>' : '')
        . '</td></tr></table></td></tr></table></body></html>';
    return [$html, ($prueba ? "[PRUEBA]\n\n" : '') . cobroTextoPlano($mensaje) . "\n\n" . strip_tags(correoPieAutomatico($cfg, fn($t) => $t))];
}

/** Envía un cobro (programado o de prueba). Lanza Exception si falla. */
function cobroEnviar(PDO $pdo, int $cid, int $cobroId, ?int $uid = null): void
{
    $st = $pdo->prepare("SELECT * FROM cobros_programados WHERE id = ? AND cliente_id = ?");
    $st->execute([$cobroId, $cid]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) throw new Exception("Cobro no encontrado.");
    if ($c['estado'] === 'enviado') throw new Exception("Este cobro ya se envió.");
    if ($c['estado'] === 'cancelado') throw new Exception("Este cobro está cancelado.");
    // Marca «enviando» de forma atómica: evita que el cron y un clic lo envíen dos veces
    $u = $pdo->prepare("UPDATE cobros_programados SET estado = 'enviando', intentos = intentos + 1 WHERE id = ? AND estado IN ('programado','error')");
    $u->execute([$cobroId]);
    if (!$u->rowCount()) throw new Exception("El cobro se está enviando en este momento.");

    try {
        $st = $pdo->prepare("SELECT cpf.archivo, f.correlativo FROM cobros_programados_facturas cpf JOIN facturas f ON f.id = cpf.factura_id WHERE cpf.cobro_id = ? ORDER BY f.correlativo");
        $st->execute([$cobroId]);
        $adjuntos = $nums = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $ruta = cobroDir($cid, $cobroId) . $a['archivo'];
            if (!is_file($ruta)) throw new Exception("Falta el PDF de la factura {$a['correlativo']}.");
            $adjuntos[] = ['ruta' => $ruta, 'nombre' => $a['archivo']];
            $nums[] = $a['correlativo'];
        }
        $emp = $pdo->prepare("SELECT nombre, alias FROM clientes_saas WHERE id = ?");
        $emp->execute([$cid]);
        $cfg = correoConfig($pdo, $cid, 'facturacion') ?? [];
        [$html, $texto] = cobroPlantilla($c['mensaje_html'], $emp->fetch(PDO::FETCH_ASSOC) ?: [], $cfg, $nums, (bool)$c['prueba']);
        $asunto = ((int)$c['prueba'] ? '[PRUEBA] ' : '') . $c['asunto'];
        correoEnviar($pdo, $cid, $c['para'], $asunto, $html, $texto, $adjuntos, (int)$c['prueba'] ? 'cobro_prueba' : 'cobro', $cobroId, $uid, 'facturacion', (string)$c['cc']);
        $pdo->prepare("UPDATE cobros_programados SET estado = 'enviado', enviado_en = NOW(), error = NULL WHERE id = ?")->execute([$cobroId]);
    } catch (Throwable $e) {
        // Hasta 3 intentos automáticos; después queda en «error» para revisarlo
        $pdo->prepare("UPDATE cobros_programados SET estado = IF(intentos >= 3, 'error', 'programado'), error = ? WHERE id = ?")
            ->execute([mb_substr($e->getMessage(), 0, 500), $cobroId]);
        throw new Exception($e->getMessage());
    }
}

/** Un cobro de la empresa (o excepción). */
function cobroObtener(PDO $pdo, int $cid, int $id): array
{
    $st = $pdo->prepare("SELECT c.*, cf.nombre AS cliente FROM cobros_programados c JOIN clientes_factura cf ON cf.id = c.receptor_id WHERE c.id = ? AND c.cliente_id = ?");
    $st->execute([$id, $cid]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) throw new Exception("Cobro no encontrado.");
    return $c;
}

/** Todo lo que se envió (o se enviará): datos, PDF adjuntos, vista del correo e intentos de envío. */
function cobroDetalle(PDO $pdo, int $cid, int $id): array
{
    $c = cobroObtener($pdo, $cid, $id);
    $st = $pdo->prepare("SELECT cpf.factura_id, cpf.archivo, f.correlativo FROM cobros_programados_facturas cpf JOIN facturas f ON f.id = cpf.factura_id WHERE cpf.cobro_id = ? ORDER BY f.correlativo");
    $st->execute([$id]);
    $adjuntos = array_map(fn($a) => $a + ['existe' => is_file(cobroDir($cid, $id) . $a['archivo'])], $st->fetchAll(PDO::FETCH_ASSOC));
    $emp = $pdo->prepare("SELECT nombre, alias FROM clientes_saas WHERE id = ?");
    $emp->execute([$cid]);
    [$html] = cobroPlantilla($c['mensaje_html'], $emp->fetch(PDO::FETCH_ASSOC) ?: [], correoConfig($pdo, $cid, 'facturacion') ?? [], array_column($adjuntos, 'correlativo'), (bool)$c['prueba']);
    $st = $pdo->prepare("SELECT destinatario, asunto, estado, error, creado_en FROM correos_enviados WHERE cliente_id = ? AND tipo IN ('cobro','cobro_prueba') AND referencia_id = ? ORDER BY id");
    $st->execute([$cid, $id]);
    return ['cobro' => $c, 'adjuntos' => $adjuntos, 'html' => $html, 'envios' => $st->fetchAll(PDO::FETCH_ASSOC)];
}

/** Edita un cobro que aún no se envía (destinatarios, asunto, mensaje y fecha). */
function cobroEditar(PDO $pdo, int $cid, int $id, array $d): void
{
    $c = cobroObtener($pdo, $cid, $id);
    if (!in_array($c['estado'], ['programado', 'error'], true)) throw new Exception("Solo se pueden editar cobros que aún no se envían.");
    $para = implode(', ', correoLista((string)($d['para'] ?? '')));
    $cc = implode(', ', correoLista((string)($d['cc'] ?? '')));
    if ($para === '') throw new Exception("Indica el correo del destinatario.");
    foreach (correoLista("$para, $cc") as $m) if (!filter_var($m, FILTER_VALIDATE_EMAIL)) throw new Exception("Correo inválido: $m");
    $asunto = trim((string)($d['asunto'] ?? ''));
    if ($asunto === '') throw new Exception("Escribe el asunto.");
    $mensaje = cobroLimpiarHtml((string)($d['mensaje_html'] ?? ''));
    if (cobroTextoPlano($mensaje) === '') throw new Exception("Escribe el mensaje.");
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', (string)($d['programado_para'] ?? ''));
    if (!$dt || $dt < new DateTime('-5 minutes')) throw new Exception("Elige una fecha y hora futura.");
    $u = $pdo->prepare("UPDATE cobros_programados SET para = ?, cc = ?, asunto = ?, mensaje_html = ?, programado_para = ?, estado = 'programado', intentos = 0, error = NULL
                        WHERE id = ? AND cliente_id = ? AND estado IN ('programado','error')");
    $u->execute([$para, $cc ?: null, mb_substr($asunto, 0, 255), $mensaje, $dt->format('Y-m-d H:i:s'), $id, $cid]);
    if (!$u->rowCount()) throw new Exception("El cobro cambió mientras lo editabas; recarga la página.");
}

/** Copia un cobro (mismo mensaje y mismos PDF) para enviarlo otra vez. Devuelve el id del nuevo cobro. */
function cobroDuplicar(PDO $pdo, int $cid, int $uid, int $id, string $para, string $cc, bool $prueba): int
{
    $c = cobroObtener($pdo, $cid, $id);
    if ($c['estado'] === 'enviando') throw new Exception("El cobro se está enviando en este momento.");
    $para = implode(', ', correoLista($para));
    $cc = $prueba ? '' : implode(', ', correoLista($cc));
    if ($para === '') throw new Exception("Indica el correo del destinatario.");
    foreach (correoLista("$para, $cc") as $m) if (!filter_var($m, FILTER_VALIDATE_EMAIL)) throw new Exception("Correo inválido: $m");
    $pdo->prepare("INSERT INTO cobros_programados (cliente_id, receptor_id, tipo, para, cc, asunto, mensaje_html, programado_para, prueba, usuario_id)
                   VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)")
        ->execute([$cid, $c['receptor_id'], $c['tipo'], $para, $cc ?: null, $c['asunto'], $c['mensaje_html'], $prueba ? 1 : 0, $uid]);
    $nuevo = (int)$pdo->lastInsertId();
    $dir = cobroDir($cid, $nuevo);
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new Exception("No se pudo crear la carpeta de los PDF.");
    $st = $pdo->prepare("SELECT cpf.factura_id, cpf.archivo, f.correlativo FROM cobros_programados_facturas cpf JOIN facturas f ON f.id = cpf.factura_id WHERE cpf.cobro_id = ?");
    $st->execute([$id]);
    $ins = $pdo->prepare("INSERT INTO cobros_programados_facturas (cobro_id, factura_id, archivo) VALUES (?, ?, ?)");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
        if (!copy(cobroDir($cid, $id) . $a['archivo'], $dir . $a['archivo'])) throw new Exception("Falta el PDF de la factura {$a['correlativo']}.");
        $ins->execute([$nuevo, $a['factura_id'], $a['archivo']]);
    }
    return $nuevo;
}

/** Borra un cobro de prueba, cancelado o con error (los cobros reales enviados se conservan como registro). */
function cobroEliminar(PDO $pdo, int $cid, int $id): void
{
    $c = cobroObtener($pdo, $cid, $id);
    if (!((int)$c['prueba'] && $c['estado'] !== 'enviando') && !in_array($c['estado'], ['cancelado', 'error'], true))
        throw new Exception("Solo se eliminan cobros de prueba, cancelados o con error. Los cobros enviados quedan como registro; si uno programado ya no va, cancélalo primero.");
    $pdo->prepare("DELETE FROM cobros_programados_facturas WHERE cobro_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM cobros_programados WHERE id = ? AND cliente_id = ?")->execute([$id, $cid]);
    $dir = cobroDir($cid, $id);
    foreach (glob($dir . '*.pdf') ?: [] as $f) @unlink($f);
    @rmdir($dir);
}

/** Cron: envía los cobros cuya fecha y hora ya llegaron. */
function cobrosPendientesEnviar(PDO $pdo, callable $log): void
{
    if (!cobrosDisponible($pdo)) return;
    $st = $pdo->query("SELECT id, cliente_id FROM cobros_programados WHERE estado = 'programado' AND programado_para <= NOW() AND intentos < 3 ORDER BY programado_para LIMIT 50");
    $ok = $err = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        try {
            cobroEnviar($pdo, (int)$c['cliente_id'], (int)$c['id']);
            $ok++;
            $log("Cobro #{$c['id']} enviado.");
        } catch (Throwable $e) {
            $err++;
            $log("Cobro #{$c['id']}: " . $e->getMessage());
        }
    }
    if ($ok || $err) $log("Cobros programados: $ok enviado(s), $err con error.");
}
