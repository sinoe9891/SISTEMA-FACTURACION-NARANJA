<?php
/**
 * aviso_pendientes.php — Resumen interno para gerencia (cuenta Facturación): lo que ya tocaba y no se ha hecho.
 *   1. Contratos sin facturar: ya pasó su día de pago del mes y no hay factura (o recibo) que cubra el mes;
 *      con plan de pagos, las cuotas vencidas sin cobrar.
 *   2. Facturas emitidas que no se le han enviado al cliente (enviada_receptor = 0), de los últimos 90 días.
 *   3. Facturas vencidas sin pagar: las de contrato vencen el día de pago del mes que cubren (nunca antes de emitirse);
 *      las demás, a los 30 días de emitidas.
 *   4. Gastos que se le cobran a un cliente (p. ej. licencias) y aún no están en una factura.
 * El cron lo envía una vez al día a la hora configurada, solo si hay algo en la lista.
 */
require_once __DIR__ . '/correo.php';
require_once __DIR__ . '/contrato_plan.php';
require_once __DIR__ . '/gastos_cobrar.php';

const AVISO_PENDIENTES_DIAS = ['habiles' => 'De lunes a viernes', 'diario' => 'Todos los días', 'lunes' => 'Solo los lunes'];
const AVISO_PENDIENTES_CREDITO = 30;   // días para cobrar una factura que no es de contrato

function avisoPendientesDisponible(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try { $ok = correoDisponible($pdo) && (bool)$pdo->query("SHOW COLUMNS FROM configuracion_correo LIKE 'aviso_pendientes_auto'")->fetchColumn(); }
        catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

/** Destinatarios: los de la configuración o, si no hay, los de «Responder a» de la cuenta Facturación. */
function avisoPendientesPara(array $cfg): string
{
    return implode(', ', correoLista((string)(($cfg['aviso_pendientes_para'] ?? '') ?: ($cfg['responder_a'] ?? ''))));
}

/** ¿Toca hoy según la frecuencia? (1 = lunes … 7 = domingo) */
function avisoPendientesTocaHoy(string $dias, ?int $diaSemana = null): bool
{
    $d = $diaSemana ?? (int)date('N');
    return match ($dias) { 'diario' => true, 'lunes' => $d === 1, default => $d <= 5 };
}

/** Fecha (Y-m-d) del día de pago de un contrato en un mes, sin pasarse del último día. */
function avisoPendientesDiaPago(int $anio, int $mes, int $diaPago): string
{
    $ult = (int)date('t', mktime(0, 0, 0, $mes, 1, $anio));
    return sprintf('%04d-%02d-%02d', $anio, $mes, min(max(1, $diaPago), $ult));
}

function avisoPendientesDatos(PDO $pdo, int $cid, ?string $hoy = null): array
{
    $hoy ??= date('Y-m-d');
    [$a, $m] = [(int)substr($hoy, 0, 4), (int)substr($hoy, 5, 2)];
    $ini = sprintf('%04d-%02d-01', $a, $m);
    $out = ['sin_facturar' => [], 'sin_enviar' => [], 'vencidas' => [], 'por_cobrar' => []];

    // 1. Contratos activos sin factura/recibo del mes después de su día de pago
    $conPlan = array_flip(planContratosConPlan($pdo, $cid));
    $st = $pdo->prepare("
        SELECT c.id, c.tipo_contrato, c.dia_pago, c.monto, c.fecha_inicio, cf.nombre AS cliente,
               (CASE WHEN c.tipo_contrato = 'sin_factura'
                     THEN (SELECT COUNT(*) FROM contratos_recibos r WHERE r.contrato_id = c.id AND r.cliente_id = c.cliente_id AND r.estado = 'emitido'
                           AND r.fecha_emision >= ? AND r.fecha_emision < ? + INTERVAL 1 MONTH)
                     ELSE (SELECT COUNT(*) FROM facturas f WHERE f.contrato_id = c.id AND f.cliente_id = c.cliente_id AND f.estado = 'emitida'
                           AND ((COALESCE(f.periodo_anio, YEAR(f.fecha_emision)) = ? AND COALESCE(f.periodo_mes, MONTH(f.fecha_emision)) = ?)
                                OR (f.fecha_emision >= ? AND f.fecha_emision < ? + INTERVAL 1 MONTH)))
                END) AS hechas
        FROM contratos c JOIN clientes_factura cf ON cf.id = c.receptor_id AND cf.cliente_id = c.cliente_id
        WHERE c.cliente_id = ? AND c.estado = 'activo' AND c.tipo_contrato <> 'proyecto'
          AND c.fecha_inicio <= ? AND (c.fecha_fin IS NULL OR c.fecha_fin >= ?)
        ORDER BY c.dia_pago, cf.nombre");
    $st->execute([$ini, $ini, $a, $m, $ini, $ini, $cid, $hoy, $ini]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if (isset($conPlan[(int)$c['id']])) continue;   // los de plan van por cuota (abajo)
        $fecha = avisoPendientesDiaPago($a, $m, (int)$c['dia_pago']);
        if ((int)$c['hechas'] || $fecha > $hoy || $c['fecha_inicio'] > $fecha) continue;
        $out['sin_facturar'][] = ['contrato_id' => (int)$c['id'], 'cliente' => $c['cliente'], 'fecha' => $fecha, 'monto' => (float)$c['monto'],
                                  'dias' => avisoPendientesDiasEntre($fecha, $hoy), 'detalle' => $c['tipo_contrato'] === 'sin_factura' ? 'Recibo del mes' : 'Factura del mes'];
    }
    if ($conPlan) {
        $nom = $pdo->prepare("SELECT cf.nombre FROM contratos c JOIN clientes_factura cf ON cf.id = c.receptor_id WHERE c.id = ? AND c.cliente_id = ?");
        foreach (array_keys($conPlan) as $kid) {
            $nom->execute([$kid, $cid]);
            $cliente = (string)$nom->fetchColumn();
            foreach (planLineas($pdo, $cid, (int)$kid) as $l) {
                if ($l['estado'] !== 'vencido' || $l['fecha'] > $hoy) continue;
                $out['sin_facturar'][] = ['contrato_id' => (int)$kid, 'cliente' => $cliente, 'fecha' => $l['fecha'], 'monto' => (float)$l['total'],
                                          'dias' => avisoPendientesDiasEntre($l['fecha'], $hoy), 'detalle' => 'Cuota del plan' . (!empty($l['concepto']) ? ': ' . $l['concepto'] : '')];
            }
        }
        usort($out['sin_facturar'], fn($x, $y) => [$x['fecha'], $x['cliente']] <=> [$y['fecha'], $y['cliente']]);
    }

    // 2 y 3. Facturas emitidas: sin enviar y vencidas sin pagar (saldo con abonos, como en Cuentas por cobrar)
    $hayCobros = (bool)$pdo->query("SHOW TABLES LIKE 'cobros_factura'")->fetchColumn();
    $st = $pdo->prepare("
        SELECT f.id, f.correlativo, DATE(f.fecha_emision) AS emision, f.total, f.pagada, f.enviada_receptor, f.contrato_id,
               COALESCE(f.periodo_anio, YEAR(f.fecha_emision)) AS pa, COALESCE(f.periodo_mes, MONTH(f.fecha_emision)) AS pm,
               c.dia_pago, cf.nombre AS cliente, cf.email,
               " . ($hayCobros ? "(SELECT COALESCE(SUM(cb.monto), 0) FROM cobros_factura cb WHERE cb.factura_id = f.id AND cb.anulado = 0)" : "0") . " AS abonado
        FROM facturas f JOIN clientes_factura cf ON cf.id = f.receptor_id
        LEFT JOIN contratos c ON c.id = f.contrato_id AND c.cliente_id = f.cliente_id
        WHERE f.cliente_id = ? AND f.estado = 'emitida' AND DATE(f.fecha_emision) <= ?
          AND (f.enviada_receptor = 0 OR f.pagada = 0)
        ORDER BY f.fecha_emision, f.id");
    $st->execute([$cid, $hoy]);
    $limiteEnvio = date('Y-m-d', strtotime($hoy . ' -90 days'));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $fila = ['id' => (int)$f['id'], 'correlativo' => $f['correlativo'], 'cliente' => $f['cliente'], 'emision' => $f['emision'], 'total' => (float)$f['total']];
        // Sin enviar: desde el día siguiente de emitida (la del mismo día aún se puede estar enviando)
        if (!(int)$f['enviada_receptor'] && $f['emision'] < $hoy && $f['emision'] >= $limiteEnvio)
            $out['sin_enviar'][] = $fila + ['dias' => avisoPendientesDiasEntre($f['emision'], $hoy), 'email' => (string)$f['email']];
        $saldo = (float)$f['abonado'] > 0 ? (float)$f['total'] - (float)$f['abonado'] : ((int)$f['pagada'] ? 0.0 : (float)$f['total']);
        if ($saldo <= 0.004) continue;
        $vence = $f['contrato_id'] && $f['dia_pago'] !== null
            ? max(avisoPendientesDiaPago((int)$f['pa'], (int)$f['pm'], (int)$f['dia_pago']), $f['emision'])
            : date('Y-m-d', strtotime($f['emision'] . ' +' . AVISO_PENDIENTES_CREDITO . ' days'));
        if ($vence < $hoy) $out['vencidas'][] = $fila + ['vence' => $vence, 'dias' => avisoPendientesDiasEntre($vence, $hoy), 'saldo' => round($saldo, 2)];
    }
    usort($out['vencidas'], fn($x, $y) => $y['dias'] <=> $x['dias']);

    // 4. Gastos que se cobran al cliente y aún no tienen factura
    foreach (gastosPorCobrar($pdo, $cid) as $g) {
        $pct = $g['comision_pct'] !== null ? (float)$g['comision_pct'] : 0.0;
        $out['por_cobrar'][] = ['id' => (int)$g['id'], 'cliente' => $g['cliente'], 'descripcion' => $g['descripcion'], 'fecha' => $g['fecha'],
                                'estado' => $g['estado'], 'monto' => round((float)$g['monto'] * (1 + $pct / 100), 2), 'comision' => $pct];
    }
    return $out;
}

function avisoPendientesDiasEntre(string $desde, string $hasta): int
{
    return (int)round((strtotime($hasta) - strtotime($desde)) / 86400);
}

function avisoPendientesTotal(array $d): int
{
    return count($d['sin_facturar']) + count($d['sin_enviar']) + count($d['vencidas']) + count($d['por_cobrar']);
}

/** [asunto, html, texto] del resumen. $base = URL del sistema de la empresa (…/clientes/<subdominio>/). */
function avisoPendientesCorreo(array $d, array $empresa, array $cfg, string $base, bool $prueba = false, ?string $hoy = null): array
{
    $hoy ??= date('Y-m-d');
    $e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
    $L = fn($n) => 'L ' . number_format((float)$n, 2);
    $f = fn($s) => date('d/m/Y', strtotime($s));
    $dias = fn($n) => $n . ' día' . ($n === 1 ? '' : 's');
    $empresaNombre = ($empresa['alias'] ?? '') ?: ($empresa['nombre'] ?? 'La empresa');
    $th = 'style="text-align:left;padding:6px 8px;font-size:11.5px;color:#64748b;text-transform:uppercase;letter-spacing:.03em;border-bottom:1px solid #e2e8f0"';
    $thR = str_replace('text-align:left', 'text-align:right', $th);
    $td = 'style="padding:7px 6px;border-bottom:1px solid #f1f5f9;font-size:13.5px;vertical-align:top"';
    $tdR = 'style="padding:7px 6px;border-bottom:1px solid #f1f5f9;font-size:13.5px;text-align:right;white-space:nowrap;vertical-align:top"';
    // 000-002-01-00000148: el prefijo en gris y pequeño, el número en negrita (cabe en el celular)
    $num = fn($c) => '<span style="white-space:nowrap">' . (preg_match('/^(.*-)(\d+)$/', (string)$c, $p) ? '<span style="color:#94a3b8;font-size:11px">' . $e($p[1]) . '</span><strong>' . $e($p[2]) . '</strong>' : '<strong>' . $e($c) . '</strong>') . '</span>';
    $rojo = fn($t) => '<span style="color:#b91c1c;font-weight:600">' . $t . '</span>';
    $seccion = function (string $titulo, string $nota, string $color, array $cab, array $filas, string $link, string $linkTxt) use ($e, $th, $thR) {
        $h = '<h3 style="margin:26px 0 4px;font-size:15.5px;color:#0f172a"><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' . $color . ';margin-right:8px"></span>' . $e($titulo) . ' (' . count($filas) . ')</h3>'
            . '<p style="margin:0 0 8px;font-size:12.5px;color:#64748b">' . $e($nota) . '</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse"><tr>';
        foreach ($cab as [$t, $der]) $h .= '<th ' . ($der ? $thR : $th) . '>' . $e($t) . '</th>';
        $h .= '</tr>' . implode('', $filas) . '</table>';
        return $h . '<p style="margin:8px 0 0;font-size:12.5px"><a href="' . $e($link) . '" style="color:#e4550d">' . $e($linkTxt) . ' →</a></p>';
    };

    $partes = [];
    $resumen = [];
    if ($d['sin_facturar']) {
        $filas = array_map(fn($r) => '<tr><td ' . $td . '><strong>' . $e($r['cliente']) . '</strong><br><span style="color:#64748b;font-size:12px">' . $e($r['detalle']) . ' · contrato #' . $r['contrato_id'] . '</span></td>'
            . '<td ' . $tdR . '>' . $f($r['fecha']) . '<br>' . $rojo($r['dias'] ? 'hace ' . $dias($r['dias']) : 'hoy') . '</td><td ' . $tdR . '>' . $L($r['monto']) . '</td></tr>', $d['sin_facturar']);
        $partes[] = $seccion('Contratos sin facturar', 'Ya pasó el día de pago y no hay factura (o recibo) del mes.', '#dc2626',
            [['Cliente', false], ['Día de pago', true], ['Monto', true]], $filas, $base . 'contratos.php', 'Ver contratos');
        $resumen[] = count($d['sin_facturar']) . ' sin facturar';
    }
    if ($d['sin_enviar']) {
        $filas = array_map(fn($r) => '<tr><td ' . $td . '>' . $num($r['correlativo']) . '<br><span style="color:#64748b;font-size:12px">' . $e($r['cliente']) . ($r['email'] ? '' : ' · sin correo registrado') . '</span></td>'
            . '<td ' . $tdR . '>' . $f($r['emision']) . '<br><span style="color:#9a3412">hace ' . $dias($r['dias']) . '</span></td><td ' . $tdR . '>' . $L($r['total']) . '</td></tr>', $d['sin_enviar']);
        $partes[] = $seccion('Facturas sin enviar al cliente', 'Emitidas y aún no enviadas desde el sistema. Si ya se mandaron por otro medio, márcalas como «Enviada al cliente».', '#f59e0b',
            [['Factura', false], ['Emitida', true], ['Total', true]], $filas, $base . 'lista_facturas.php', 'Ver facturas');
        $resumen[] = count($d['sin_enviar']) . ' sin enviar';
    }
    if ($d['vencidas']) {
        $filas = array_map(fn($r) => '<tr><td ' . $td . '>' . $num($r['correlativo']) . '<br><span style="color:#64748b;font-size:12px">' . $e($r['cliente']) . '</span></td>'
            . '<td ' . $tdR . '>' . $f($r['vence']) . '<br>' . $rojo($dias($r['dias']) . ' de atraso') . '</td><td ' . $tdR . '>' . $L($r['saldo']) . '</td></tr>', $d['vencidas']);
        $total = array_sum(array_column($d['vencidas'], 'saldo'));
        $filas[] = '<tr><td ' . $td . ' colspan="2"><strong>Total vencido</strong></td><td ' . $tdR . '><strong>' . $L($total) . '</strong></td></tr>';
        $partes[] = $seccion('Facturas vencidas sin pagar', 'Las de contrato vencen el día de pago del mes que cubren; las demás, a los ' . AVISO_PENDIENTES_CREDITO . ' días de emitidas.', '#b91c1c',
            [['Factura', false], ['Venció', true], ['Saldo', true]], $filas, $base . 'cuentas_cobrar.php', 'Ver cuentas por cobrar');
        $resumen[] = count($d['vencidas']) . ' vencida' . (count($d['vencidas']) === 1 ? '' : 's') . ' (' . $L($total) . ')';
    }
    if ($d['por_cobrar']) {
        $filas = array_map(fn($r) => '<tr><td ' . $td . '><strong>' . $e($r['cliente']) . '</strong><br><span style="color:#64748b;font-size:12px">' . $e($r['descripcion']) . ' · gasto #' . $r['id'] . ($r['comision'] ? ' · +' . rtrim(rtrim(number_format($r['comision'], 2), '0'), '.') . ' %' : '') . '</span></td>'
            . '<td ' . $tdR . '>' . $f($r['fecha']) . '<br><span style="color:#64748b">' . ($r['estado'] === 'pagado' ? 'pagado' : 'por pagar') . '</span></td><td ' . $tdR . '>' . $L($r['monto']) . '</td></tr>', $d['por_cobrar']);
        $partes[] = $seccion('Gastos por cobrar al cliente', 'Pagados (o que vencen este mes) por cuenta del cliente y aún sin incluir en una factura.', '#2563eb',
            [['Cliente', false], ['Fecha', true], ['A cobrar', true]], $filas, $base . 'generar_factura.php', 'Nueva factura');
        $resumen[] = count($d['por_cobrar']) . ' gasto' . (count($d['por_cobrar']) === 1 ? '' : 's') . ' por cobrar';
    }

    $hoyTxt = $f($hoy);
    $asunto = ($prueba ? '[PRUEBA] ' : '') . 'Pendientes de facturación al ' . $hoyTxt . ': ' . ($resumen ? implode(', ', $resumen) : 'todo al día');
    $cuerpo = '<p style="margin:0">Resumen de lo que ya tocaba y está pendiente en ' . $e($empresaNombre) . ' al <strong>' . $hoyTxt . '</strong>.</p>'
        . ($partes ? implode('', $partes) : '<p style="margin:16px 0 0;color:#15803d;font-weight:600">Todo al día: no hay pendientes.</p>');
    $logo = !empty($cfg['logo_url'])
        ? '<img src="' . $e($cfg['logo_url']) . '" alt="' . $e($empresaNombre) . '" height="48" style="height:48px;width:auto;max-width:200px;border:0;display:block">'
        : '<span style="font-size:18px;font-weight:700;color:#0f172a">' . $e($empresaNombre) . '</span>';
    $aviso = $prueba ? '<tr><td style="padding:10px 28px;background:#fef3c7;color:#92400e;font-size:12px;font-weight:600">PRUEBA · Vista previa del resumen diario.</td></tr>' : '';
    $html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<style>@media (max-width:520px){.ap-pad{padding:18px 14px!important}.ap-out{padding:10px 4px!important}}</style></head>'
        . '<body style="margin:0;padding:0;background:#f1f5f9;font-family:Segoe UI,Helvetica,Arial,sans-serif">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" class="ap-out" style="background:#f1f5f9;padding:24px 12px"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0">'
        . $aviso
        . '<tr><td style="padding:20px 28px;border-bottom:4px solid #e4550d">' . $logo . '</td></tr>'
        . '<tr><td class="ap-pad" style="padding:24px 28px;font-size:14.5px;line-height:1.6;color:#1e293b">' . $cuerpo . '</td></tr>'
        . '<tr><td style="padding:14px 28px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:11.5px;line-height:1.55;color:#94a3b8">'
        . 'Correo interno automático del sistema de facturación. Se cambia en Configuración → Correo → Facturación.</td></tr>'
        . '</table></td></tr></table></body></html>';

    $txt = [($prueba ? "[PRUEBA]\n" : '') . "Pendientes de facturación — $empresaNombre — $hoyTxt"];
    foreach ($d['sin_facturar'] as $r) $txt[] = "Sin facturar: {$r['cliente']} ({$r['detalle']}), día de pago " . $f($r['fecha']) . ', ' . $L($r['monto']);
    foreach ($d['sin_enviar'] as $r) $txt[] = "Sin enviar: {$r['correlativo']} {$r['cliente']}, emitida " . $f($r['emision']) . ', ' . $L($r['total']);
    foreach ($d['vencidas'] as $r) $txt[] = "Vencida: {$r['correlativo']} {$r['cliente']}, {$r['dias']} días de atraso, saldo " . $L($r['saldo']);
    foreach ($d['por_cobrar'] as $r) $txt[] = "Por cobrar: {$r['cliente']} — {$r['descripcion']}, " . $L($r['monto']);
    return [$asunto, $html, implode("\n", $txt)];
}

/** URL del sistema de la empresa para los enlaces del correo. */
function avisoPendientesBase(PDO $pdo, int $cid, array $cfg): string
{
    $st = $pdo->prepare("SELECT subdominio FROM clientes_saas WHERE id = ?");
    $st->execute([$cid]);
    $sub = (string)$st->fetchColumn() ?: 'naranjaymedia';
    $raiz = rtrim((string)($cfg['enlace_url'] ?? ''), '/') ?: 'https://facturacion.naranjaymediahn.com';
    return $raiz . '/clientes/' . rawurlencode($sub) . '/';
}

/** Arma y envía el resumen. Devuelve [para, número de pendientes]. */
function avisoPendientesEnviar(PDO $pdo, int $cid, ?int $usuario = null, bool $prueba = false, ?string $para = null): array
{
    $cfg = correoConfig($pdo, $cid, 'facturacion') ?? [];
    $para ??= avisoPendientesPara($cfg);
    if (!$para) throw new Exception("No hay destinatarios: escribe los correos de gerencia o llena «Responder a».");
    $d = avisoPendientesDatos($pdo, $cid);
    $emp = $pdo->prepare("SELECT nombre, alias FROM clientes_saas WHERE id = ?");
    $emp->execute([$cid]);
    [$asunto, $html, $texto] = avisoPendientesCorreo($d, $emp->fetch(PDO::FETCH_ASSOC) ?: [], $cfg, avisoPendientesBase($pdo, $cid, $cfg), $prueba);
    correoEnviar($pdo, $cid, $para, $asunto, $html, $texto, [], $prueba ? 'resumen_pendientes_prueba' : 'resumen_pendientes', null, $usuario, 'facturacion');
    return [$para, avisoPendientesTotal($d)];
}

/** Guarda la configuración del resumen en la cuenta Facturación (que ya debe existir). */
function avisoPendientesGuardar(PDO $pdo, int $cid, array $d): void
{
    if (!avisoPendientesDisponible($pdo)) throw new Exception("Falta instalar sql/migraciones/2026-10-09_aviso_pendientes.sql.");
    if (!correoConfig($pdo, $cid, 'facturacion')) throw new Exception("Primero guarda la cuenta de Facturación.");
    $lista = correoLista((string)($d['aviso_pendientes_para'] ?? ''));
    foreach ($lista as $m) if (!filter_var($m, FILTER_VALIDATE_EMAIL)) throw new Exception("Correo inválido: $m");
    $dias = array_key_exists($d['aviso_pendientes_dias'] ?? '', AVISO_PENDIENTES_DIAS) ? $d['aviso_pendientes_dias'] : 'habiles';
    $pdo->prepare("UPDATE configuracion_correo SET aviso_pendientes_auto = ?, aviso_pendientes_hora = ?, aviso_pendientes_dias = ?, aviso_pendientes_para = ?
                   WHERE cliente_id = ?" . (correoTienePerfiles($pdo) ? " AND perfil = 'facturacion'" : ""))
        ->execute([empty($d['aviso_pendientes_auto']) ? 0 : 1, max(5, min(20, (int)($d['aviso_pendientes_hora'] ?? 8))), $dias, $lista ? implode(', ', $lista) : null, $cid]);
}

/** HTML del resumen de hoy (vista previa en Configuración). */
function avisoPendientesVista(PDO $pdo, int $cid): array
{
    $cfg = correoConfig($pdo, $cid, 'facturacion') ?? [];
    $d = avisoPendientesDatos($pdo, $cid);
    $emp = $pdo->prepare("SELECT nombre, alias FROM clientes_saas WHERE id = ?");
    $emp->execute([$cid]);
    [$asunto, $html] = avisoPendientesCorreo($d, $emp->fetch(PDO::FETCH_ASSOC) ?: [], $cfg, avisoPendientesBase($pdo, $cid, $cfg));
    return ['asunto' => $asunto, 'html' => $html, 'total' => avisoPendientesTotal($d), 'para' => avisoPendientesPara($cfg)];
}
