<?php
/**
 * correo_movimientos.php — Aviso por correo al colaborador cuando se le registra un bono, viático, adelanto, préstamo o multa.
 * Mismo diseño y tono neutral que el aviso de pago (correo_pagos.php): informativo, sin valor de contrato ni documento fiscal.
 */
require_once __DIR__ . '/correo_pagos.php';

/** Asunto, HTML y texto del aviso de un movimiento (fila de colaborador_prestamos). */
function correoMovimientoPlantilla(array $m, array $c, array $empresa, bool $conAdjunto, array $cfg = []): array
{
    $empresaNombre = $empresa['alias'] ?: ($empresa['nombre'] ?? 'La empresa');
    $e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
    $en = $e($empresaNombre);
    $nombre = trim($c['nombre']);
    $monto = 'L ' . number_format((float)$m['monto_total'], 2);
    $fecha = date('d/m/Y', strtotime($m['fecha']));
    $auto = !empty($m['descuento_auto']);
    $cuotas = (int)$m['num_cuotas'];
    $primera = $m['primera_cuota'] ?? null;
    $primeraTxt = $primera ? date('d/m/Y', strtotime($primera)) : '';

    // Por tipo: etiqueta de color, asunto, título, mensaje y filas
    $cfgTipo = [
        'bono' => ['Reconocimiento', '#16a34a', 'Un reconocimiento para vos', '🎉 ¡Felicidades!',
            "Queremos agradecerte tu esfuerzo y compromiso. <strong>{$en}</strong> te otorgó un bono como reconocimiento a tu trabajo. Gracias por ser parte del equipo."],
        'viatico' => ['Viático registrado', '#0369a1', 'Viático registrado', '',
            "<strong>{$en}</strong> registró un viático a tu favor con el siguiente detalle."],
        'adelanto' => ['Adelanto registrado', '#d97706', 'Adelanto registrado', '',
            "<strong>{$en}</strong> registró un adelanto a tu favor con el siguiente detalle."],
        'prestamo' => ['Préstamo registrado', '#dc2626', 'Préstamo registrado', '',
            "<strong>{$en}</strong> registró un préstamo a tu favor con el siguiente detalle."],
        'multa' => ['Descuento registrado', '#64748b', 'Descuento registrado', '',
            "<strong>{$en}</strong> registró un descuento con el siguiente detalle."],
    ];
    [$etiqueta, $color, $titulo, $saludoExtra, $mensaje] = $cfgTipo[$m['tipo']] ?? $cfgTipo['adelanto'];
    $asunto = "$titulo · " . date('d/m/Y', strtotime($m['fecha'])) . " · $empresaNombre";

    $filas = [['Concepto', $m['descripcion']], ['Fecha', $fecha], ['Monto', $monto]];
    if ($m['tipo'] === 'prestamo') {
        $filas[] = ['Cuotas', $cuotas . ' de L ' . number_format((float)$m['monto_cuota'], 2) . ' (' . ($m['frecuencia_cuota'] === 'quincenal' ? 'quincenales' : 'mensuales') . ')'];
        if ($primeraTxt) $filas[] = ['Primera cuota', $primeraTxt];
    } elseif (in_array($m['tipo'], ['adelanto', 'multa'], true) && $primeraTxt) {
        $filas[] = ['Se descuenta el', $primeraTxt];
    }
    $nota = '';
    switch ($m['tipo']) {
        case 'bono':    $nota = 'Se pagará junto con tu próxima nómina.'; break;
        case 'viatico': $nota = 'Se acreditará junto con tu próxima nómina.'; break;
        case 'adelanto':
        case 'multa':
        case 'prestamo':
            $nota = $auto ? 'El descuento se aplicará automáticamente al procesar tu nómina.' : 'El descuento se coordinará con la administración.';
    }
    if (!empty($m['notas'])) $filas[] = ['Notas', $m['notas']];
    if ($conAdjunto) $filas[] = ['Comprobante', 'Adjunto a este correo'];

    $tabla = '';
    foreach ($filas as [$k, $v]) {
        $tabla .= '<tr><td style="padding:10px 14px;border-bottom:1px solid #e2e8f0;color:#64748b;font-size:13px;width:38%">' . $e($k) . '</td>'
            . '<td style="padding:10px 14px;border-bottom:1px solid #e2e8f0;color:#0f172a;font-size:14px;font-weight:600">' . $e($v) . '</td></tr>';
    }
    $logoUrl = $cfg['logo_url'] ?? '';
    if (!$logoUrl && !empty($empresa['logo_url']) && preg_match('#^https?://#', $empresa['logo_url']) && !preg_match('/\.svg($|\?)/i', $empresa['logo_url'])) $logoUrl = $empresa['logo_url'];
    $enlace = $cfg['enlace_url'] ?? '';
    $logo = $logoUrl
        ? '<img src="' . $e($logoUrl) . '" alt="' . $en . '" height="56" style="height:56px;width:auto;max-width:200px;border:0;display:block">'
        : '<span style="font-size:18px;font-weight:700;color:#0f172a">' . $en . '</span>';
    if ($enlace) $logo = '<a href="' . $e($enlace) . '" target="_blank" style="text-decoration:none">' . $logo . '</a>';
    $pie = $enlace ? '<br><a href="' . $e($enlace) . '" target="_blank" style="color:#64748b">' . $e(preg_replace('#^https?://|/$#', '', $enlace)) . '</a>' : '';

    $html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($asunto) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f1f5f9;font-family:Segoe UI,Helvetica,Arial,sans-serif">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 12px"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0">'
        . '<tr><td style="padding:22px 28px;border-bottom:4px solid #e4550d">' . $logo . '</td></tr>'
        . '<tr><td style="padding:28px">'
        . '<p style="margin:0 0 6px;font-size:13px;color:' . $color . ';font-weight:700;text-transform:uppercase;letter-spacing:.5px">' . $e($etiqueta) . '</p>'
        . '<h1 style="margin:0 0 18px;font-size:21px;color:#0f172a">Hola, ' . $e($nombre) . '</h1>'
        . ($saludoExtra ? '<p style="margin:0 0 10px;font-size:18px;color:#0f172a">' . $saludoExtra . '</p>' : '')
        . '<p style="margin:0 0 18px;font-size:14.5px;line-height:1.6;color:#334155">' . $mensaje . '</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:8px;border-collapse:separate;overflow:hidden">' . $tabla . '</table>'
        . ($nota ? '<p style="margin:20px 0 0;font-size:14px;line-height:1.6;color:#334155">' . $e($nota) . '</p>' : '')
        . '<p style="margin:12px 0 0;font-size:13px;line-height:1.6;color:#475569;background:#f8fafc;border-radius:8px;padding:10px 12px">' . correoPieAutomatico($cfg, $e) . '</p>'
        . '<p style="margin:22px 0 0;font-size:14px;color:#334155">Saludos cordiales,<br><strong>Administración · ' . $en . '</strong></p>'
        . '</td></tr>'
        . '<tr><td style="padding:16px 28px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:11px;line-height:1.55;color:#94a3b8">'
        . 'Este mensaje es una notificación informativa generada automáticamente. No constituye contrato, constancia laboral, recibo ni documento fiscal, '
        . 'y no modifica las condiciones acordadas entre las partes. La información es confidencial y está dirigida únicamente a su destinatario: si la recibiste por error, avísanos y elimínala.' . $pie
        . '</td></tr></table></td></tr></table></body></html>';

    $texto = "Hola, $nombre\n\n" . strip_tags(str_replace('<strong>', '', $mensaje)) . "\n\n";
    foreach ($filas as [$k, $v]) $texto .= "- $k: $v\n";
    if ($nota) $texto .= "\n$nota\n";
    $texto .= "\n" . strip_tags(correoPieAutomatico($cfg, fn($t) => $t)) . "\n\nSaludos cordiales,\nAdministración · $empresaNombre\n\n"
        . "Notificación informativa. No constituye contrato, constancia laboral, recibo ni documento fiscal.";
    return [$asunto, $html, $texto];
}

/** Envía al colaborador el aviso de un movimiento recién registrado. Devuelve el correo; lanza Exception si no se pudo. */
function correoNotificarMovimiento(PDO $pdo, int $cid, int $movId, ?int $usuario = null): string
{
    $st = $pdo->prepare("SELECT * FROM colaborador_prestamos WHERE id = ? AND cliente_id = ?");
    $st->execute([$movId, $cid]);
    $m = $st->fetch(PDO::FETCH_ASSOC);
    if (!$m) throw new Exception('Movimiento no encontrado.');
    $st = $pdo->prepare("SELECT * FROM colaboradores WHERE id = ? AND cliente_id = ?");
    $st->execute([$m['colaborador_id'], $cid]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    $para = trim((string)($c['email'] ?? ''));
    if (!filter_var($para, FILTER_VALIDATE_EMAIL)) throw new Exception('El colaborador no tiene un correo válido registrado.');
    $st = $pdo->prepare("SELECT MIN(fecha_esperada) FROM colaborador_prestamo_cuotas WHERE prestamo_id = ?");
    $st->execute([$movId]);
    $m['primera_cuota'] = $st->fetchColumn() ?: null;
    $st = $pdo->prepare("SELECT nombre, alias, logo_url, email, telefono FROM clientes_saas WHERE id = ?");
    $st->execute([$cid]);
    $empresa = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $adj = null;
    require_once __DIR__ . '/../clientes/naranjaymedia/includes/prestamo_comprobante_lib.php';
    if ($ruta = prestamoComprobanteRuta($m['archivo_adjunto'] ?? null))
        $adj = ['ruta' => $ruta, 'nombre' => 'comprobante_' . $m['tipo'] . '_' . $m['fecha'] . '.' . pathinfo($ruta, PATHINFO_EXTENSION)];
    [$asunto, $html, $texto] = correoMovimientoPlantilla($m, $c, $empresa, (bool)$adj, correoConfig($pdo, $cid) ?? []);
    correoEnviar($pdo, $cid, $para, $asunto, $html, $texto, $adj ? [$adj] : [], 'movimiento_colaborador', $movId, $usuario);
    return $para;
}
