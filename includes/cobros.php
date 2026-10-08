<?php
/**
 * cobros.php — Cobros por correo programados (facturas en PDF + mensaje + fecha y hora).
 *
 *   cobroCrear($pdo, $cid, $uid, $datos, $renderPdf)   → id (genera y guarda los PDF al programar)
 *   Tipos: envio_factura, saldo_pendiente y factura_y_saldo (factura nueva + facturas con saldo, con sus abonos), todas
 *   con las facturas en PDF; envio_recibo (recibos en PDF, contratos sin factura) y recordatorio_pago (pagos del plan).
 *   Cualquier cobro puede llevar además documentos de la empresa (includes/documentos.php), p. ej. la Constancia del SAR.
 *   cobroEnviar($pdo, $cid, $cobroId, $uid)            → envía (desde la cuenta «facturacion»)
 *   cobrosPendientesEnviar($pdo, $log)                  → para el cron: envía los que ya tocan
 *
 * Tablas: sql/migraciones/2026-10-04_cobros_programados.sql. Hora: America/Tegucigalpa (db.php).
 */
require_once __DIR__ . '/correo.php';

const COBRO_TIPOS = ['envio_factura' => 'Envío de factura', 'saldo_pendiente' => 'Cobro de saldo pendiente', 'factura_y_saldo' => 'Factura + saldo pendiente',
                     'recordatorio_pago' => 'Recordatorio de pago (plan de pagos)', 'envio_recibo' => 'Envío de recibos'];
/** Tipos que adjuntan facturas */
const COBRO_TIPOS_FACTURA = ['envio_factura', 'saldo_pendiente', 'factura_y_saldo'];

/** Adapta las frases heredadas únicamente en plantillas dirigidas al equipo. */
function cobroPlantillaEquipo(string $contenido): string
{
    if (strpos($contenido, '{{saludo}}') === false) return $contenido;
    return strtr($contenido, [
        'Espero que se encuentre bien.' => 'Espero se encuentren muy bien.',
        'Espero que se encuentre muy bien.' => 'Espero se encuentren muy bien.',
        'Asimismo, le recordamos' => 'Asimismo, les recordamos',
        'Le compartimos el detalle' => 'Les compartimos el detalle',
        'Le escribo para darle seguimiento' => 'Les escribo para dar seguimiento',
    ]);
}

/** Plantilla por defecto de «Factura + saldo pendiente»: la factura nueva con sus conceptos y las que tienen saldo (con abonos). */
const COBRO_PLANTILLA_FACTURA_Y_SALDO = [
    'asunto' => 'Factura N.° {{numeros_facturas}} y saldo pendiente - {{cliente_nombre}}',
    'contenido' => "{{saludo}}\n\nEspero se encuentren muy bien.\n\nAdjunto {{detalle_facturas}}\n\nAsimismo, les recordamos las facturas que tienen saldo pendiente de pago:\n\n{{saldo_pendiente}}\n\nCon la nueva factura, el saldo total pendiente asciende a L {{total}}.\n\n{{cuentas_pago}}\n\nAgradecemos mucho su apoyo y gestión. Quedamos atentos a su confirmación.\n\nSaludos cordiales,",
];

/** Plantillas por defecto de los tipos sin factura (se pueden cambiar en Mensajes y cuentas de pago). */
const COBRO_PLANTILLAS_EXTRA = [
    'recordatorio_pago' => [
        'asunto' => 'Recordatorio de pago - {{cliente_nombre}}',
        'contenido' => "{{saludo}}\n\nEspero se encuentren muy bien.\n\nLes compartimos el detalle de los pagos de su plan:\n\n{{detalle_facturas}}\n\nTotal: L {{total}}.\n\n{{cuentas_pago}}\n\nAgradecemos su apoyo. Quedamos atentos a su confirmación.\n\nSaludos cordiales,",
    ],
    'envio_recibo' => [
        'asunto' => 'Recibo de pago - {{cliente_nombre}}',
        'contenido' => "{{saludo}}\n\nMuchas gracias por su pago.\n\nAdjunto {{detalle_facturas}}\n\nQuedamos atentos a cualquier consulta.\n\nSaludos cordiales,",
    ],
];

/**
 * Asunto y mensaje (HTML) de un recordatorio del plan o de un envío de recibos, con la plantilla de la empresa
 * (o la de COBRO_PLANTILLAS_EXTRA). Mismo saludo y cuentas de pago que los cobros de facturas.
 */
function cobroMensajeGenerar(PDO $pdo, int $cid, int $rid, string $tipo, array $ids, array $antIds = []): array
{
    if (!isset(COBRO_PLANTILLAS_EXTRA[$tipo])) throw new Exception("Tipo de mensaje inválido.");
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $antIds = $tipo === 'envio_recibo' ? array_values(array_unique(array_filter(array_map('intval', $antIds)))) : [];
    if (!$ids && !$antIds) throw new Exception($tipo === 'envio_recibo' ? "Selecciona al menos un recibo." : "Selecciona al menos un pago del plan.");
    $st = $pdo->prepare("SELECT nombre FROM clientes_factura WHERE id = ? AND cliente_id = ?");
    $st->execute([$rid, $cid]);
    $cliente = $st->fetchColumn();
    if ($cliente === false) throw new Exception("Cliente no válido.");
    $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $b = fn($v) => '<strong>' . $h($v) . '</strong>';
    $MES = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $fecha = fn($f) => (int)substr($f, 8, 2) . ' de ' . $MES[(int)substr($f, 5, 2)] . ' de ' . substr($f, 0, 4);
    $in = implode(',', array_fill(0, max(1, count($ids)), '?'));
    $total = 0.0;
    $lineas = [];
    if ($tipo === 'envio_recibo') {
        $filas = [];
        if ($ids) {
            $st = $pdo->prepare("SELECT numero_recibo, fecha_emision, monto, concepto FROM contratos_recibos WHERE id IN ($in) AND cliente_id = ? AND receptor_id = ? AND estado = 'emitido' ORDER BY numero_recibo");
            $st->execute([...$ids, $cid, $rid]);
            $filas = $st->fetchAll(PDO::FETCH_ASSOC);
            if (count($filas) !== count($ids)) throw new Exception("Algún recibo no pertenece a este cliente.");
        }
        foreach ($filas as $r) {
            $total += (float)$r['monto'];
            $lineas[] = '- Recibo N.° ' . $b(str_pad((string)$r['numero_recibo'], 5, '0', STR_PAD_LEFT)) . ' del ' . $h($fecha($r['fecha_emision'])) . ': ' . $b('L ' . number_format((float)$r['monto'], 2)) . ' · ' . $h($r['concepto']);
        }
        if ($antIds) {
            $inA = implode(',', array_fill(0, count($antIds), '?'));
            $st = $pdo->prepare("SELECT a.id, a.fecha, a.monto, a.concepto FROM contratos_anticipos a JOIN contratos c ON c.id = a.contrato_id AND c.cliente_id = a.cliente_id
                                 WHERE a.id IN ($inA) AND a.cliente_id = ? AND c.receptor_id = ? AND a.anulado = 0 ORDER BY a.fecha");
            $st->execute([...$antIds, $cid, $rid]);
            $fa = $st->fetchAll(PDO::FETCH_ASSOC);
            if (count($fa) !== count($antIds)) throw new Exception("Algún pago anticipado no pertenece a este cliente.");
            foreach ($fa as $a) {
                $total += (float)$a['monto'];
                $lineas[] = '- Recibo de anticipo N.° ' . $b('A-' . str_pad((string)$a['id'], 5, '0', STR_PAD_LEFT)) . ' del ' . $h($fecha($a['fecha'])) . ': ' . $b('L ' . number_format((float)$a['monto'], 2)) . ($a['concepto'] ? ' · ' . $h($a['concepto']) : '');
            }
        }
        $detalle = (count($lineas) === 1 ? 'el recibo de su pago:' : 'los recibos de sus pagos:') . "\n\n" . implode("\n", $lineas);
    } else {
        require_once __DIR__ . '/contrato_plan.php';
        $st = $pdo->prepare("SELECT p.id, p.contrato_id FROM contratos_plan p JOIN contratos c ON c.id = p.contrato_id AND c.cliente_id = p.cliente_id WHERE p.id IN ($in) AND p.cliente_id = ? AND c.receptor_id = ?");
        $st->execute([...$ids, $cid, $rid]);
        $porContrato = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $porContrato[(int)$r['contrato_id']][] = (int)$r['id'];
        if (array_sum(array_map('count', $porContrato)) !== count($ids)) throw new Exception("Algún pago del plan no es de un contrato de este cliente.");
        $vencidos = $proximos = [];
        foreach ($porContrato as $ctId => $sel) {
            foreach (planLineas($pdo, $cid, $ctId) as $l) {
                if (!in_array((int)$l['id'], $sel, true)) continue;
                $total += (float)$l['total'];
                $txt = $b($fecha($l['fecha'])) . ' — ' . $h($l['concepto']) . ': ' . $b('L ' . number_format((float)$l['total'], 2));
                if ($l['estado'] === 'vencido') $vencidos[] = '- ' . $txt . ' (vencido)';
                else $proximos[] = '- ' . $txt . ($l['estado'] === 'pagado' ? ' (pagado, gracias)' : '');
            }
        }
        $detalle = ($vencidos ? '<strong>Pagos vencidos:</strong>' . "\n" . implode("\n", $vencidos) . ($proximos ? "\n\n" : '') : '')
                 . ($proximos ? '<strong>Próximos pagos:</strong>' . "\n" . implode("\n", $proximos) : '');
    }

    $st = $pdo->prepare("SELECT * FROM configuracion_cuentas_pago WHERE cliente_id = ? AND activo = 1 ORDER BY orden, id");
    $st->execute([$cid]);
    $cuentas = array_map(fn($c) => '- ' . $b($c['banco']) . ' (' . (!empty($c['tipo_cuenta']) ? $h($c['tipo_cuenta']) . ' ' : '') . $h($c['numero_cuenta']) . ') a nombre de ' . $h($c['titular']), $st->fetchAll(PDO::FETCH_ASSOC));
    $cuentasTxt = $cuentas ? '<strong>Formas de pago:</strong>' . "\n" . implode("\n", $cuentas) : '';

    $pl = COBRO_PLANTILLAS_EXTRA[$tipo];
    try {
        $st = $pdo->prepare("SELECT asunto, contenido FROM configuracion_mensajes WHERE cliente_id = ? AND tipo = ?");
        $st->execute([$cid, $tipo]);
        $pl = $st->fetch(PDO::FETCH_ASSOC) ?: $pl;
    } catch (Throwable $e) {
    }
    $MESU = array_map('ucfirst', $MES);
    $rep = ['{{saludo}}' => $b('Buen día, equipo de ' . $cliente . ':'), '{{cliente_nombre}}' => $h($cliente), '{{detalle_facturas}}' => $detalle,
            '{{total}}' => $b(number_format($total, 2)), '{{cuentas_pago}}' => $cuentasTxt, '{{mes_actual}}' => $MESU[(int)date('n')], '{{anio_actual}}' => date('Y')];
    $html = nl2br(strtr(htmlspecialchars(cobroPlantillaEquipo((string)$pl['contenido']), ENT_QUOTES, 'UTF-8'), $rep), false);
    $asunto = strtr((string)$pl['asunto'], ['{{cliente_nombre}}' => $cliente, '{{mes_actual}}' => $MESU[(int)date('n')], '{{anio_actual}}' => date('Y'), '{{total}}' => number_format($total, 2)]);
    return ['asunto' => $asunto, 'mensaje_html' => str_replace(["\r", "\n"], '', $html)];
}

/** ¿Están las tablas de recibos y recordatorios en los cobros? (migración 2026-10-07_cobros_plan_recibos) */
function cobrosExtrasDisponible(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = (bool)$pdo->query("SHOW TABLES LIKE 'cobros_programados_recibos'")->fetchColumn() && (bool)$pdo->query("SHOW TABLES LIKE 'cobros_programados_plan'")->fetchColumn();
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

function cobroAnticiposDisponible(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try { $ok = (bool)$pdo->query("SHOW TABLES LIKE 'cobros_programados_anticipos'")->fetchColumn(); } catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

/** Adjuntos de un cobro: facturas y recibos en PDF, con su etiqueta («factura 000-…», «recibo 00012»). */
function cobroAdjuntos(PDO $pdo, int $cid, int $cobroId): array
{
    $st = $pdo->prepare("SELECT cpf.factura_id, NULL AS recibo_id, cpf.archivo, f.correlativo, CONCAT('factura ', f.correlativo) AS etiqueta FROM cobros_programados_facturas cpf JOIN facturas f ON f.id = cpf.factura_id WHERE cpf.cobro_id = ? ORDER BY f.correlativo");
    $st->execute([$cobroId]);
    $out = $st->fetchAll(PDO::FETCH_ASSOC);
    if (cobrosExtrasDisponible($pdo)) {
        $st = $pdo->prepare("SELECT NULL AS factura_id, x.recibo_id, x.archivo, LPAD(r.numero_recibo, 5, '0') AS correlativo, CONCAT('recibo ', LPAD(r.numero_recibo, 5, '0')) AS etiqueta
                             FROM cobros_programados_recibos x JOIN contratos_recibos r ON r.id = x.recibo_id AND r.cliente_id = ? WHERE x.cobro_id = ? ORDER BY r.numero_recibo");
        $st->execute([$cid, $cobroId]);
        $out = array_merge($out, $st->fetchAll(PDO::FETCH_ASSOC));
    }
    if (cobroAnticiposDisponible($pdo)) {
        $st = $pdo->prepare("SELECT NULL AS factura_id, NULL AS recibo_id, x.anticipo_id, x.archivo, CONCAT('A-', LPAD(x.anticipo_id, 5, '0')) AS correlativo, CONCAT('recibo de anticipo A-', LPAD(x.anticipo_id, 5, '0')) AS etiqueta
                             FROM cobros_programados_anticipos x WHERE x.cobro_id = ? ORDER BY x.anticipo_id");
        $st->execute([$cobroId]);
        $out = array_merge($out, $st->fetchAll(PDO::FETCH_ASSOC));
    }
    if (cobroDocumentosDisponible($pdo)) {
        $st = $pdo->prepare("SELECT NULL AS factura_id, NULL AS recibo_id, x.documento_id, x.archivo, x.nombre AS correlativo, x.nombre AS etiqueta FROM cobros_programados_documentos x WHERE x.cobro_id = ? ORDER BY x.id");
        $st->execute([$cobroId]);
        $out = array_merge($out, $st->fetchAll(PDO::FETCH_ASSOC));
    }
    return array_map(fn($a) => $a + ['existe' => is_file(cobroDir($cid, $cobroId) . $a['archivo'])], $out);
}

function cobroDocumentosDisponible(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try { $ok = (bool)$pdo->query("SHOW TABLES LIKE 'cobros_programados_documentos'")->fetchColumn(); } catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

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
function cobroCrear(PDO $pdo, int $cid, int $uid, array $d, callable $renderPdf, ?callable $renderRecibo = null): int
{
    $rid = (int)($d['receptor_id'] ?? 0);
    $st = $pdo->prepare("SELECT id, nombre FROM clientes_factura WHERE id = ? AND cliente_id = ?");
    $st->execute([$rid, $cid]);
    if (!$st->fetch()) throw new Exception("Cliente no válido.");

    $tipo = isset(COBRO_TIPOS[$d['tipo'] ?? '']) ? $d['tipo'] : 'saldo_pendiente';
    $lista = fn($k) => array_values(array_unique(array_filter(array_map('intval', (array)($d[$k] ?? [])))));
    $ids = $tipo === 'envio_recibo' || $tipo === 'recordatorio_pago' ? [] : $lista('factura_ids');
    $recIds = $tipo === 'envio_recibo' ? $lista('recibo_ids') : [];
    $antIds = $tipo === 'envio_recibo' && cobroAnticiposDisponible($pdo) ? $lista('anticipo_ids') : [];
    $planIds = $tipo === 'recordatorio_pago' ? $lista('plan_ids') : [];
    if (($recIds || $planIds) && !cobrosExtrasDisponible($pdo)) throw new Exception("Falta instalar sql/migraciones/2026-10-07_cobros_plan_recibos.sql.");
    if ($tipo === 'envio_recibo' && !$recIds && !$antIds) throw new Exception("Selecciona al menos un recibo.");
    if ($tipo === 'recordatorio_pago' && !$planIds) throw new Exception("Selecciona al menos un pago del plan.");
    if (in_array($tipo, COBRO_TIPOS_FACTURA, true) && !$ids) throw new Exception("Selecciona al menos una factura.");
    if (count($ids) + count($recIds) + count($antIds) > 30) throw new Exception("Máximo 30 documentos por cobro.");
    if ($antIds) {
        $in = implode(',', array_fill(0, count($antIds), '?'));
        $st = $pdo->prepare("SELECT COUNT(*) FROM contratos_anticipos a JOIN contratos c ON c.id = a.contrato_id AND c.cliente_id = a.cliente_id WHERE a.id IN ($in) AND a.cliente_id = ? AND c.receptor_id = ? AND a.anulado = 0");
        $st->execute([...$antIds, $cid, $rid]);
        if ((int)$st->fetchColumn() !== count($antIds)) throw new Exception("Algún pago anticipado no es de este cliente o está anulado.");
    }
    if (count($planIds) > 60) throw new Exception("Máximo 60 pagos por recordatorio.");
    // Documentos de la empresa que van adjuntos (se copian al cobro tal como están hoy)
    $docIds = $lista('documento_ids');
    $docs = [];
    if ($docIds) {
        require_once __DIR__ . '/documentos.php';
        if (!docsDisponible($pdo)) throw new Exception("Falta instalar sql/migraciones/2026-10-09_documentos_empresa.sql.");
        foreach ($docIds as $did) {
            $doc = docObtener($pdo, $cid, $did);
            if (!is_file(docDir($cid) . $doc['archivo'])) throw new Exception("Falta el archivo del documento «{$doc['nombre']}».");
            $docs[] = $doc;
        }
    }
    $facturas = $recibos = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT id, correlativo FROM facturas WHERE id IN ($in) AND cliente_id = ? AND receptor_id = ? AND estado = 'emitida'");
        $st->execute([...$ids, $cid, $rid]);
        $facturas = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        if (count($facturas) !== count($ids)) throw new Exception("Alguna factura no pertenece a este cliente o está anulada.");
    }
    if ($recIds) {
        $in = implode(',', array_fill(0, count($recIds), '?'));
        $st = $pdo->prepare("SELECT id, numero_recibo FROM contratos_recibos WHERE id IN ($in) AND cliente_id = ? AND receptor_id = ? AND estado = 'emitido'");
        $st->execute([...$recIds, $cid, $rid]);
        $recibos = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        if (count($recibos) !== count($recIds)) throw new Exception("Algún recibo no pertenece a este cliente o está anulado.");
    }
    if ($planIds) {
        $in = implode(',', array_fill(0, count($planIds), '?'));
        $st = $pdo->prepare("SELECT COUNT(*) FROM contratos_plan p JOIN contratos c ON c.id = p.contrato_id AND c.cliente_id = p.cliente_id WHERE p.id IN ($in) AND p.cliente_id = ? AND c.receptor_id = ?");
        $st->execute([...$planIds, $cid, $rid]);
        if ((int)$st->fetchColumn() !== count($planIds)) throw new Exception("Algún pago del plan no es de un contrato de este cliente.");
    }

    $para = implode(', ', correoLista((string)($d['para'] ?? '')));
    $cc = implode(', ', correoLista((string)($d['cc'] ?? '')));
    if ($para === '') throw new Exception("Indica el correo del destinatario.");
    foreach (correoLista("$para, $cc") as $m) if (!filter_var($m, FILTER_VALIDATE_EMAIL)) throw new Exception("Correo inválido: $m");
    $asunto = trim((string)($d['asunto'] ?? ''));
    if ($asunto === '') throw new Exception("Escribe el asunto.");
    $mensaje = cobroLimpiarHtml((string)($d['mensaje_html'] ?? ''));
    if (cobroTextoPlano($mensaje) === '') throw new Exception("Escribe el mensaje.");
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
    // Recibos en PDF (contratos sin factura)
    if ($recibos) {
        require_once __DIR__ . '/recibo_pdf.php';
        $insR = $pdo->prepare("INSERT INTO cobros_programados_recibos (cobro_id, recibo_id, archivo) VALUES (?, ?, ?)");
        foreach ($recibos as $recId => $num) {
            $pdf = $renderRecibo ? $renderRecibo((int)$recId) : reciboPdf($pdo, $cid, (int)$recId);
            if (!is_string($pdf) || strncmp($pdf, '%PDF', 4) !== 0) throw new Exception("No se pudo generar el PDF del recibo $num.");
            $archivo = 'recibo_' . str_pad((string)$num, 5, '0', STR_PAD_LEFT) . '.pdf';
            file_put_contents($dir . $archivo, $pdf);
            $insR->execute([$id, $recId, $archivo]);
        }
    }
    // Recibos de pagos anticipados
    if ($antIds) {
        require_once __DIR__ . '/recibo_pdf.php';
        $insA = $pdo->prepare("INSERT INTO cobros_programados_anticipos (cobro_id, anticipo_id, archivo) VALUES (?, ?, ?)");
        foreach ($antIds as $aid) {
            $pdf = anticipoPdf($pdo, $cid, $aid);
            $archivo = anticipoArchivo(['id' => $aid]);
            file_put_contents($dir . $archivo, $pdf);
            $insA->execute([$id, $aid, $archivo]);
        }
    }
    if ($docs) {
        $insD = $pdo->prepare("INSERT INTO cobros_programados_documentos (cobro_id, documento_id, nombre, archivo) VALUES (?, ?, ?, ?)");
        foreach ($docs as $doc) {
            $archivo = 'doc_' . (int)$doc['id'] . '_' . preg_replace('/[^A-Za-z0-9._-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', docNombreAdjunto($doc)));
            if (!copy(docDir($cid) . $doc['archivo'], $dir . $archivo)) throw new Exception("No se pudo adjuntar «{$doc['nombre']}».");
            $insD->execute([$id, $doc['id'], $doc['nombre'], $archivo]);
        }
    }
    // Pagos del plan que se recuerdan (para mostrar en el plan que ya se avisó)
    if ($planIds) {
        $insP = $pdo->prepare("INSERT INTO cobros_programados_plan (cobro_id, plan_id) VALUES (?, ?)");
        foreach ($planIds as $pid) $insP->execute([$id, $pid]);
    }
    return $id;
}

/** Plantilla del correo de cobro: el mensaje redactado dentro del diseño de la empresa. */
/** Párrafo automático editable: quitar la versión anterior antes de reconstruirlo. */
function cobroMensajeDocumentos(string $mensaje, array $documentos, string $tipo): string
{
    $auto = 'Para facilitar su gestión administrativa y tributaria, adjuntamos la documentación de respaldo: .*?Quedamos a su disposición para cualquier consulta sobre esta documentación\.';
    $mensaje = preg_replace('~<p\b[^>]*>\s*' . $auto . '\s*</p>|' . $auto . '(?:\s*<br\s*/?>){0,2}~su', '', $mensaje);
    $documentos = array_values(array_unique(array_filter(array_map('strval', $documentos))));
    if (!$documentos || !in_array($tipo, [...COBRO_TIPOS_FACTURA, 'recordatorio_pago'], true)) return $mensaje;
    $nombres = array_map(fn($nombre) => '<strong>' . htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') . '</strong>', $documentos);
    $ultimo = array_pop($nombres);
    $lista = $nombres ? implode(', ', $nombres) . ' y ' . $ultimo : $ultimo;
    $texto = 'Para facilitar su gestión administrativa y tributaria, adjuntamos la documentación de respaldo: ' . $lista
        . '. Quedamos a su disposición para cualquier consulta sobre esta documentación.';
    $desde = stripos($mensaje, 'Formas de pago');
    $cierre = '~(?:^|<br\s*/?>|<p\b[^>]*>|<div\b[^>]*>)\s*(?=(?:<(?:strong|b|span)\b[^>]*>\s*)*(?:Quedo atent[oa]|Quedamos atent[oa]s|Agradecemos|Saludos|Atentamente|Cordialmente)\b)~iu';
    if (preg_match($cierre, $mensaje, $m, PREG_OFFSET_CAPTURE, $desde === false ? 0 : $desde)) {
        $pos = $m[0][1] + strlen($m[0][0]);
        return substr($mensaje, 0, $pos) . $texto . '<br><br>' . substr($mensaje, $pos);
    }
    return $mensaje . '<p>' . $texto . '</p>';
}

function cobroPlantilla(string $mensaje, array $empresa, array $cfg, array $facturas, bool $prueba, array $documentos = [], string $tipo = ''): array
{
    $e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
    // Conservar intacto el texto histórico de correos enviados (no pasan documentos).
    if ($documentos) $mensaje = cobroMensajeDocumentos($mensaje, $documentos, $tipo);
    $empresaNombre = ($empresa['alias'] ?? '') ?: ($empresa['nombre'] ?? 'La empresa');
    $logoUrl = $cfg['logo_url'] ?? '';
    $enlace = $cfg['enlace_url'] ?? '';
    $logo = $logoUrl
        ? '<img src="' . $e($logoUrl) . '" alt="' . $e($empresaNombre) . '" height="56" style="height:56px;width:auto;max-width:200px;border:0;display:block">'
        : '<span style="font-size:18px;font-weight:700;color:#0f172a">' . $e($empresaNombre) . '</span>';
    if ($enlace) $logo = '<a href="' . $e($enlace) . '" target="_blank" style="text-decoration:none">' . $logo . '</a>';
    // $facturas: etiquetas de los adjuntos («factura 000-…», «recibo 00012»); un número suelto se toma como factura
    $adjuntos = $facturas ? '<p style="margin:18px 0 0;font-size:13px;color:#64748b">📎 Adjuntos: ' . $e(implode(', ', array_map(fn($f) => preg_match('/^(factura|recibo) /', $f) || !preg_match('/^[\d-]+$/', $f) ? $f : 'factura ' . $f, $facturas))) . '</p>' : '';
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
    return [$html, ($prueba ? "[PRUEBA]\n\n" : '') . cobroTextoPlano($mensaje) . "\n\n" . strip_tags(correoPieAutomatico($cfg, fn($t) => $t)), $mensaje];
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

    // La edición puede haber terminado mientras esperábamos el bloqueo del UPDATE.
    $c = cobroObtener($pdo, $cid, $cobroId);
    try {
        $adjuntos = $nums = $documentos = [];
        foreach (cobroAdjuntos($pdo, $cid, $cobroId) as $a) {
            if (!$a['existe']) throw new Exception(!empty($a['documento_id']) ? "Falta el documento «{$a['etiqueta']}»." : "Falta el PDF de la {$a['etiqueta']}.");
            $adjuntos[] = ['ruta' => cobroDir($cid, $cobroId) . $a['archivo'], 'nombre' => !empty($a['documento_id']) ? $a['etiqueta'] . '.' . pathinfo($a['archivo'], PATHINFO_EXTENSION) : $a['archivo']];
            $nums[] = $a['etiqueta'];
            if (!empty($a['documento_id'])) $documentos[] = $a['etiqueta'];
        }
        $emp = $pdo->prepare("SELECT nombre, alias FROM clientes_saas WHERE id = ?");
        $emp->execute([$cid]);
        $cfg = correoConfig($pdo, $cid, 'facturacion') ?? [];
        [$html, $texto, $mensajeFinal] = cobroPlantilla($c['mensaje_html'], $emp->fetch(PDO::FETCH_ASSOC) ?: [], $cfg, $nums, (bool)$c['prueba'], $documentos, $c['tipo']);
        $asunto = ((int)$c['prueba'] ? '[PRUEBA] ' : '') . $c['asunto'];
        correoEnviar($pdo, $cid, $c['para'], $asunto, $html, $texto, $adjuntos, (int)$c['prueba'] ? 'cobro_prueba' : 'cobro', $cobroId, $uid, 'facturacion', (string)$c['cc']);
        $pdo->prepare("UPDATE cobros_programados SET estado = 'enviado', enviado_en = NOW(), error = NULL, mensaje_html = ? WHERE id = ?")->execute([$mensajeFinal, $cobroId]);
        // Las facturas adjuntas quedan como «Enviada al cliente» (las pruebas no cuentan)
        if (!(int)$c['prueba'])
            $pdo->prepare("UPDATE facturas f JOIN cobros_programados_facturas x ON x.factura_id = f.id SET f.enviada_receptor = 1 WHERE x.cobro_id = ? AND f.cliente_id = ?")->execute([$cobroId, $cid]);
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
    $adjuntos = cobroAdjuntos($pdo, $cid, $id);
    $emp = $pdo->prepare("SELECT nombre, alias FROM clientes_saas WHERE id = ?");
    $emp->execute([$cid]);
    [$html] = cobroPlantilla($c['mensaje_html'], $emp->fetch(PDO::FETCH_ASSOC) ?: [], correoConfig($pdo, $cid, 'facturacion') ?? [], array_column($adjuntos, 'etiqueta'), (bool)$c['prueba'], $c['estado'] === 'enviado' ? [] : array_column(array_filter($adjuntos, fn($a) => !empty($a['documento_id'])), 'etiqueta'), $c['tipo']);
    $st = $pdo->prepare("SELECT destinatario, asunto, estado, error, creado_en FROM correos_enviados WHERE cliente_id = ? AND tipo IN ('cobro','cobro_prueba') AND referencia_id = ? ORDER BY id");
    $st->execute([$cid, $id]);
    $envios = $st->fetchAll(PDO::FETCH_ASSOC);
    require_once __DIR__ . '/documentos.php';
    $documentos = array_map(function ($d) {
        [$bg, $fg, $txt] = docSemaforo($d);
        return ['id' => (int)$d['id'], 'nombre' => $d['nombre'], 'estado' => $d['estado'], 'txt' => $txt, 'bg' => $bg, 'fg' => $fg];
    }, docsLista($pdo, $cid));
    return ['cobro' => $c, 'adjuntos' => $adjuntos, 'html' => $html, 'envios' => $envios, 'documentos' => $documentos];
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
    $nuevos = $retirados = [];
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare("SELECT estado FROM cobros_programados WHERE id = ? AND cliente_id = ? FOR UPDATE");
        $lock->execute([$id, $cid]);
        if (!in_array($lock->fetchColumn(), ['programado', 'error'], true)) throw new Exception("El cobro cambió mientras lo editabas; recarga la lista.");
        if (!empty($d['actualizar_documentos'])) {
            require_once __DIR__ . '/documentos.php';
            $docIds = array_values(array_unique(array_filter(array_map('intval', (array)($d['documento_ids'] ?? [])), fn($v) => $v > 0)));
            if (count($docIds) > 30) throw new Exception("Máximo 30 documentos de empresa por cobro.");
            if (!docsDisponible($pdo) && $docIds) throw new Exception("El módulo de documentos de empresa no está instalado.");
            if (docsDisponible($pdo)) {
                $st = $pdo->prepare("SELECT documento_id, archivo FROM cobros_programados_documentos WHERE cobro_id = ?");
                $st->execute([$id]);
                $actuales = $st->fetchAll(PDO::FETCH_KEY_PAIR);
                $dir = cobroDir($cid, $id);
                $insertar = $pdo->prepare("INSERT INTO cobros_programados_documentos (cobro_id, documento_id, nombre, archivo) VALUES (?, ?, ?, ?)");
                foreach ($docIds as $did) {
                    if (isset($actuales[$did])) continue; // Conservar la copia ya adjunta, aunque el original haya cambiado.
                    $doc = docObtener($pdo, $cid, $did);
                    if (!is_file(docDir($cid) . $doc['archivo'])) throw new Exception("Falta el archivo de «{$doc['nombre']}».");
                    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new Exception("No se pudo preparar la carpeta de adjuntos.");
                    $archivo = 'doc_' . $did . '_' . bin2hex(random_bytes(8)) . '.' . pathinfo($doc['archivo'], PATHINFO_EXTENSION);
                    $nuevos[] = $dir . $archivo;
                    if (!copy(docDir($cid) . $doc['archivo'], $dir . $archivo)) throw new Exception("No se pudo adjuntar «{$doc['nombre']}».");
                    $insertar->execute([$id, $did, $doc['nombre'], $archivo]);
                }
                foreach ($actuales as $did => $archivo) {
                    if (in_array((int)$did, $docIds, true)) continue;
                    $pdo->prepare("DELETE FROM cobros_programados_documentos WHERE cobro_id = ? AND documento_id = ?")->execute([$id, $did]);
                    $retirados[] = $dir . $archivo;
                }
            }
        }
        if (!empty($d['actualizar_documentos'])) {
            $nombresDocumentos = array_column(array_filter(cobroAdjuntos($pdo, $cid, $id), fn($a) => !empty($a['documento_id'])), 'etiqueta');
            $mensaje = cobroMensajeDocumentos($mensaje, $nombresDocumentos, $c['tipo']);
        }
        $u = $pdo->prepare("UPDATE cobros_programados SET para = ?, cc = ?, asunto = ?, mensaje_html = ?, programado_para = ?, estado = 'programado', intentos = 0, error = NULL WHERE id = ? AND cliente_id = ?");
        $u->execute([$para, $cc ?: null, mb_substr($asunto, 0, 255), $mensaje, $dt->format('Y-m-d H:i:s'), $id, $cid]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($nuevos as $ruta) if (is_file($ruta)) @unlink($ruta);
        throw $e;
    }
    foreach ($retirados as $ruta) if (is_file($ruta)) @unlink($ruta);
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
    if (cobrosExtrasDisponible($pdo)) {
        $st = $pdo->prepare("SELECT recibo_id, archivo FROM cobros_programados_recibos WHERE cobro_id = ?");
        $st->execute([$id]);
        $insR = $pdo->prepare("INSERT INTO cobros_programados_recibos (cobro_id, recibo_id, archivo) VALUES (?, ?, ?)");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            if (!copy(cobroDir($cid, $id) . $a['archivo'], $dir . $a['archivo'])) throw new Exception("Falta el PDF del {$a['archivo']}.");
            $insR->execute([$nuevo, $a['recibo_id'], $a['archivo']]);
        }
        $pdo->prepare("INSERT INTO cobros_programados_plan (cobro_id, plan_id) SELECT ?, plan_id FROM cobros_programados_plan WHERE cobro_id = ?")->execute([$nuevo, $id]);
    }
    if (cobroAnticiposDisponible($pdo)) {
        $st = $pdo->prepare("SELECT anticipo_id, archivo FROM cobros_programados_anticipos WHERE cobro_id = ?");
        $st->execute([$id]);
        $insA = $pdo->prepare("INSERT INTO cobros_programados_anticipos (cobro_id, anticipo_id, archivo) VALUES (?, ?, ?)");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            if (!copy(cobroDir($cid, $id) . $a['archivo'], $dir . $a['archivo'])) throw new Exception("Falta el PDF del {$a['archivo']}.");
            $insA->execute([$nuevo, $a['anticipo_id'], $a['archivo']]);
        }
    }
    if (cobroDocumentosDisponible($pdo)) {
        $st = $pdo->prepare("SELECT documento_id, nombre, archivo FROM cobros_programados_documentos WHERE cobro_id = ?");
        $st->execute([$id]);
        $insD = $pdo->prepare("INSERT INTO cobros_programados_documentos (cobro_id, documento_id, nombre, archivo) VALUES (?, ?, ?, ?)");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            if (!copy(cobroDir($cid, $id) . $a['archivo'], $dir . $a['archivo'])) throw new Exception("Falta el documento «{$a['nombre']}».");
            $insD->execute([$nuevo, $a['documento_id'], $a['nombre'], $a['archivo']]);
        }
    }
    return $nuevo;
}

/** Los correos enviados se conservan; las pruebas y los pendientes se pueden eliminar. */
function cobroPuedeEliminar(array $c): bool
{
    return $c['estado'] !== 'enviando' && ((int)$c['prueba'] || in_array($c['estado'], ['programado', 'cancelado', 'error'], true));
}

function cobroEliminar(PDO $pdo, int $cid, int $id): void
{
    cobrosEliminarLote($pdo, $cid, [$id]);
}

/** Validación y borrado atómicos: si algún registro cambió o pertenece a otra empresa, no se borra ninguno. */
function cobrosEliminarLote(PDO $pdo, int $cid, array $ids): int
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => $id > 0)));
    if (!$ids || count($ids) > 300) throw new Exception("Selecciona entre 1 y 300 correos para eliminar.");
    sort($ids);
    // Comprobar módulos antes de abrir la transacción.
    $tablas = ['cobros_programados_facturas'];
    if (cobrosExtrasDisponible($pdo)) array_push($tablas, 'cobros_programados_recibos', 'cobros_programados_plan');
    if (cobroAnticiposDisponible($pdo)) $tablas[] = 'cobros_programados_anticipos';
    if (cobroDocumentosDisponible($pdo)) $tablas[] = 'cobros_programados_documentos';
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT id, estado, prueba FROM cobros_programados WHERE cliente_id = ? AND id IN ($ph) ORDER BY id FOR UPDATE");
        $st->execute([$cid, ...$ids]);
        $filas = $st->fetchAll(PDO::FETCH_ASSOC);
        if (count($filas) !== count($ids)) throw new Exception("Algún correo ya no existe o no pertenece a esta empresa. Actualiza la lista.");
        foreach ($filas as $c) if (!cobroPuedeEliminar($c)) throw new Exception("El correo #{$c['id']} ya se envió o se está enviando. No se eliminó ningún correo.");
        foreach ($tablas as $tabla) $pdo->prepare("DELETE FROM $tabla WHERE cobro_id IN ($ph)")->execute($ids);
        $pdo->prepare("DELETE FROM cobros_programados WHERE cliente_id = ? AND id IN ($ph)")->execute([$cid, ...$ids]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    foreach ($ids as $id) {
        $dir = cobroDir($cid, $id);
        foreach (glob($dir . '*') ?: [] as $f) if (is_file($f)) @unlink($f);
        @rmdir($dir);
    }
    return count($ids);
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
