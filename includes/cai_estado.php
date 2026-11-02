<?php
/**
 * cai_estado.php — Estado del CAI del establecimiento: avisa cuando ya no se puede facturar (rango agotado o CAI vencido)
 * y cuando está por agotarse o por vencer. Lo usan el Dashboard, el Historial y Nueva factura.
 */
const CAI_ALERTA_RESTANTES = 10;    // ámbar con 10 facturas o menos; rojo con 3 o menos
const CAI_ALERTA_DIAS = 30;         // ámbar con 30 días o menos; rojo con 7 o menos

/**
 * Devuelve null si todo está bien o ['nivel' => 'rojo'|'ambar', 'bloquea' => bool, 'titulo', 'detalle'].
 * «bloquea» = no se puede emitir ninguna factura nueva en este establecimiento.
 */
function caiAlerta(PDO $pdo, int $cid, int $estId): ?array
{
    $st = $pdo->prepare("SELECT cai, rango_inicio, rango_fin, correlativo_actual, fecha_limite, ultimo_correlativo,
                                (rango_fin - rango_inicio + 1 - correlativo_actual) AS restantes
                         FROM cai_rangos WHERE cliente_id = ? AND establecimiento_id = ? ORDER BY fecha_limite DESC, id DESC");
    $st->execute([$cid, $estId]);
    $cais = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$cais) return null;   // sin CAI registrado: lo dice la pantalla de configuración
    $hoy = date('Y-m-d');
    $d = fn($f) => date('d/m/Y', strtotime($f));
    $vigentes = array_values(array_filter($cais, fn($c) => $c['fecha_limite'] >= $hoy));
    $usables = array_values(array_filter($vigentes, fn($c) => (int)$c['restantes'] > 0));
    $paso = 'Tramitá un nuevo CAI en el SAR y registralo en Configuración → CAI.';

    if (!$usables) {
        if ($vigentes) {   // hay CAI vigente pero se acabaron los números
            $c = $vigentes[0];
            return ['nivel' => 'rojo', 'bloquea' => true, 'titulo' => 'No podés emitir facturas: se agotó el rango del CAI',
                'detalle' => 'Se usó hasta la factura ' . htmlspecialchars((string)($c['ultimo_correlativo'] ?: '—')) . ' (rango autorizado hasta el ' . (int)$c['rango_fin'] . '). El CAI vence el ' . $d($c['fecha_limite']) . ' pero ya no le quedan números. ' . $paso];
        }
        $c = $cais[0];
        return ['nivel' => 'rojo', 'bloquea' => true, 'titulo' => 'No podés emitir facturas: el CAI venció',
            'detalle' => 'El último CAI venció el ' . $d($c['fecha_limite']) . '. ' . $paso];
    }
    $restantes = array_sum(array_map(fn($c) => (int)$c['restantes'], $usables));
    $minDias = min(array_map(fn($c) => (int)round((strtotime($c['fecha_limite']) - strtotime($hoy)) / 86400), $usables));
    $limite = $usables[count($usables) - 1]['fecha_limite'];   // el que vence primero (vienen ordenados de más lejano a más próximo)
    $avisos = []; $rojo = false;
    if ($restantes <= CAI_ALERTA_RESTANTES) { $avisos[] = 'Quedan <strong>' . $restantes . '</strong> factura(s) disponibles en el CAI'; $rojo = $rojo || $restantes <= 3; }
    if ($minDias <= CAI_ALERTA_DIAS) { $avisos[] = 'El CAI vence el <strong>' . $d($limite) . '</strong> (en ' . $minDias . ' día(s))'; $rojo = $rojo || $minDias <= 7; }
    if (!$avisos) return null;
    return ['nivel' => $rojo ? 'rojo' : 'ambar', 'bloquea' => false, 'titulo' => 'El CAI está por agotarse o vencer', 'detalle' => implode('. ', $avisos) . '. ' . $paso];
}

/**
 * Estado de un CAI para listarlo: ['clave' => activo|por_vencer|agotado|vencido, 'rank' (orden), 'txt', 'dias'].
 * Orden: activos → por vencer (30 días o menos) → agotados (vigentes sin números) → vencidos.
 */
function caiEstadoFila(array $c, ?string $hoy = null): array
{
    $hoy = $hoy ?? date('Y-m-d');
    $dias = (int)round((strtotime($c['fecha_limite']) - strtotime($hoy)) / 86400);
    $restantes = (int)$c['rango_fin'] - (int)$c['rango_inicio'] + 1 - (int)$c['correlativo_actual'];
    if ($dias < 0) return ['clave' => 'vencido', 'rank' => 4, 'txt' => 'Vencido', 'dias' => $dias];
    if ($restantes <= 0) return ['clave' => 'agotado', 'rank' => 3, 'txt' => 'Agotado', 'dias' => $dias];
    if ($dias <= CAI_ALERTA_DIAS) return ['clave' => 'por_vencer', 'rank' => 2, 'txt' => 'Por vencer · ' . $dias . ' d', 'dias' => $dias];
    return ['clave' => 'activo', 'rank' => 1, 'txt' => 'Activo', 'dias' => $dias];
}
