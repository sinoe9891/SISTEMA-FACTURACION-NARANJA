<?php
/**
 * correo_pagos.php — Aviso por correo al colaborador cuando se registra un pago.
 *
 * El texto es deliberadamente neutral: habla de "pago" y "transferencia" (no de salario ni de
 * relación laboral, porque hay colaboradores que facturan servicios) y aclara que el aviso es
 * informativo y que prevalecen los registros bancarios.
 */
require_once __DIR__ . '/correo.php';

const CORREO_MESES = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

/** Busca el colaborador de un gasto de nómina ("Sueldo <nombre> — …"). */
function correoPagoDatos(PDO $pdo, int $cid, int $gastoId): array
{
    $st = $pdo->prepare("SELECT * FROM gastos WHERE id = ? AND cliente_id = ? AND descripcion LIKE 'Sueldo %' AND estado <> 'anulado'");
    $st->execute([$gastoId, $cid]);
    $g = $st->fetch(PDO::FETCH_ASSOC);
    if (!$g) throw new Exception("Pago no encontrado.");
    $nombre = preg_replace('/^Sueldo (.*?)( — .*)?$/u', '$1', $g['descripcion']);
    $st = $pdo->prepare("SELECT * FROM colaboradores WHERE cliente_id = ? AND CONCAT(nombre, ' ', apellido) = ? LIMIT 1");
    $st->execute([$cid, $nombre]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) throw new Exception("No se encontró el colaborador de este pago.");
    $st = $pdo->prepare("SELECT nombre, alias, logo_url, email, telefono FROM clientes_saas WHERE id = ?");
    $st->execute([$cid]);
    return [$g, $c, $st->fetch(PDO::FETCH_ASSOC) ?: []];
}

/** Ruta absoluta del comprobante adjunto al gasto (misma lógica que gasto_archivo.php). */
function correoPagoAdjunto(array $g): ?array
{
    if (empty($g['archivo_adjunto'])) return null;
    $base = realpath(__DIR__ . '/../clientes/naranjaymedia/includes/uploads');
    foreach ([$base . '/gastos/' . basename($g['archivo_adjunto']), $base . '/comprobantes_nomina/' . $g['archivo_adjunto']] as $c) {
        $r = realpath($c);
        if ($r && $base && strpos($r, $base . DIRECTORY_SEPARATOR) === 0 && is_file($r)) {
            $ext = pathinfo($r, PATHINFO_EXTENSION);
            return ['ruta' => $r, 'nombre' => 'comprobante_pago_' . date('Y-m-d', strtotime($g['fecha'])) . '.' . $ext];
        }
    }
    return null;
}

/** Asunto, HTML y texto del aviso de pago. */
function correoPagoPlantilla(array $g, array $c, array $empresa, bool $conAdjunto, array $cfg = []): array
{
    $empresaNombre = $empresa['alias'] ?: ($empresa['nombre'] ?? 'La empresa');
    $mes = CORREO_MESES[(int)date('n', strtotime($g['fecha']))] . ' ' . date('Y', strtotime($g['fecha']));
    $periodo = (int)$g['quincena_num'] === 1 ? "primera quincena de $mes" : ((int)$g['quincena_num'] === 2 ? "segunda quincena de $mes" : $mes);
    $monto = 'L ' . number_format((float)$g['monto'], 2);
    $fecha = date('d/m/Y', strtotime($g['fecha']));
    $ref = preg_match('/ref\.\s*([A-Za-z0-9]+)/i', (string)$g['notas'], $m) ? $m[1] : null;
    $nombre = trim($c['nombre']);
    $asunto = "Notificación de pago · $periodo · $empresaNombre";
    $e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');

    $filas = [['Concepto', 'Pago correspondiente a la ' . $periodo], ['Fecha de la transferencia', $fecha], ['Monto acreditado', $monto]];
    if ($ref) $filas[] = ['Referencia bancaria', $ref];
    // Cuenta de destino del colaborador (solo los últimos 4 dígitos)
    if (!empty($c['numero_cuenta'])) {
        $dig = preg_replace('/\D/', '', (string)$c['numero_cuenta']);
        $tipoCta = ['ahorro' => 'Ahorro', 'cheques' => 'Cheques'][$c['tipo_cuenta'] ?? ''] ?? '';
        $filas[] = ['Cuenta de destino', implode(' · ', array_filter([trim((string)($c['banco'] ?? '')), trim($tipoCta . ' ••••' . substr($dig, -4))]))];
    }
    if ($conAdjunto) $filas[] = ['Comprobante', 'Adjunto a este correo'];
    $tabla = '';
    foreach ($filas as [$k, $v]) {
        $tabla .= '<tr><td style="padding:10px 14px;border-bottom:1px solid #e2e8f0;color:#64748b;font-size:13px;width:44%">' . $e($k) . '</td>'
            . '<td style="padding:10px 14px;border-bottom:1px solid #e2e8f0;color:#0f172a;font-size:14px;font-weight:600">' . $e($v) . '</td></tr>';
    }
    // Logo: el configurado en el correo; si no, el de la empresa (excepto SVG, que Gmail no muestra)
    $logoUrl = $cfg['logo_url'] ?? '';
    if (!$logoUrl && !empty($empresa['logo_url']) && preg_match('#^https?://#', $empresa['logo_url']) && !preg_match('/\.svg($|\?)/i', $empresa['logo_url'])) $logoUrl = $empresa['logo_url'];
    $enlace = $cfg['enlace_url'] ?? '';
    $logo = $logoUrl
        ? '<img src="' . $e($logoUrl) . '" alt="' . $e($empresaNombre) . '" height="56" style="height:56px;width:auto;max-width:200px;border:0;display:block">'
        : '<span style="font-size:18px;font-weight:700;color:#0f172a">' . $e($empresaNombre) . '</span>';
    if ($enlace) $logo = '<a href="' . $e($enlace) . '" target="_blank" style="text-decoration:none">' . $logo . '</a>';
    $pie = $enlace ? '<br><a href="' . $e($enlace) . '" target="_blank" style="color:#64748b">' . $e(preg_replace('#^https?://|/$#', '', $enlace)) . '</a>' : '';

    $html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $e($asunto) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f1f5f9;font-family:Segoe UI,Helvetica,Arial,sans-serif">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 12px"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0">'
        . '<tr><td style="padding:22px 28px;border-bottom:4px solid #e4550d">' . $logo . '</td></tr>'
        . '<tr><td style="padding:28px">'
        . '<p style="margin:0 0 6px;font-size:13px;color:#16a34a;font-weight:700;text-transform:uppercase;letter-spacing:.5px">Pago acreditado</p>'
        . '<h1 style="margin:0 0 18px;font-size:21px;color:#0f172a">Hola, ' . $e($nombre) . '</h1>'
        . '<p style="margin:0 0 18px;font-size:14.5px;line-height:1.6;color:#334155">Te informamos que <strong>' . $e($empresaNombre) . '</strong> realizó una transferencia a tu favor con el siguiente detalle:</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:8px;border-collapse:separate;overflow:hidden">' . $tabla . '</table>'
        . '<p style="margin:20px 0 0;font-size:14px;line-height:1.6;color:#334155">El acreditamiento en tu cuenta depende de los tiempos de tu banco.</p>'
        . '<p style="margin:12px 0 0;font-size:13px;line-height:1.6;color:#475569;background:#f8fafc;border-radius:8px;padding:10px 12px">' . correoPieAutomatico($cfg, $e) . '</p>'
        . '<p style="margin:22px 0 0;font-size:14px;color:#334155">Saludos cordiales,<br><strong>Administración · ' . $e($empresaNombre) . '</strong></p>'
        . '</td></tr>'
        . '<tr><td style="padding:16px 28px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:11px;line-height:1.55;color:#94a3b8">'
        . 'Este mensaje es una notificación informativa generada automáticamente sobre una transferencia realizada. No constituye contrato, constancia laboral, recibo ni documento fiscal, '
        . 'y no modifica las condiciones acordadas entre las partes. El comprobante adjunto corresponde a la operación bancaria; ante cualquier diferencia prevalecen los registros de la entidad bancaria. '
        . 'La información es confidencial y está dirigida únicamente a su destinatario: si la recibiste por error, avísanos y elimínala.' . $pie
        . '</td></tr></table></td></tr></table></body></html>';

    $texto = "Hola, $nombre\n\n$empresaNombre realizó una transferencia a tu favor:\n\n";
    foreach ($filas as [$k, $v]) $texto .= "- $k: $v\n";
    $texto .= "\n" . strip_tags(correoPieAutomatico($cfg, fn($t) => $t)) . "\n\nSaludos cordiales,\nAdministración · $empresaNombre\n\n"
        . "Notificación informativa. No constituye contrato, constancia laboral, recibo ni documento fiscal. Ante cualquier diferencia prevalecen los registros bancarios.";
    return [$asunto, $html, $texto];
}

/** Envía el aviso de un pago al colaborador. Devuelve el correo al que se envió; lanza Exception si no se pudo. */
function correoNotificarPago(PDO $pdo, int $cid, int $gastoId, ?int $usuario = null): string
{
    [$g, $c, $empresa] = correoPagoDatos($pdo, $cid, $gastoId);
    $para = trim((string)$c['email']);
    if (!filter_var($para, FILTER_VALIDATE_EMAIL)) throw new Exception("El colaborador no tiene un correo válido registrado.");
    $adj = correoPagoAdjunto($g);
    [$asunto, $html, $texto] = correoPagoPlantilla($g, $c, $empresa, (bool)$adj, correoConfig($pdo, $cid) ?? []);
    correoEnviar($pdo, $cid, $para, $asunto, $html, $texto, $adj ? [$adj] : [], 'pago_colaborador', $gastoId, $usuario);
    return $para;
}
