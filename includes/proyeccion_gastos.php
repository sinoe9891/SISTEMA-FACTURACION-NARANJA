<?php
/** Calendario de egresos: el detalle es la única fuente de los totales. */
function proyeccionGastosMes(array $registros, int $anio, int $mes): array
{
    $inicio = sprintf('%04d-%02d-01', $anio, $mes);
    $fin = date('Y-m-t', strtotime($inicio));
    $series = [];
    $detalle = [];
    foreach ($registros as $g) {
        if ($g['estado'] === 'anulado') continue;
        if ($g['frecuencia'] === 'unico') {
            if ($g['fecha'] >= $inicio && $g['fecha'] <= $fin) {
                $g['fechas'] = [$g['fecha']];
                $g['total'] = round((float)$g['monto'], 2);
                $detalle[] = $g;
            }
            continue;
        }
        if (!in_array($g['frecuencia'], ['mensual', 'quincenal', 'anual'], true)) continue;
        // Registros antiguos sin grupo: misma descripción/proveedor/categoría identifica la serie.
        $clave = !empty($g['gasto_grupo_id']) ? 'grupo:' . $g['gasto_grupo_id'] :
            'legacy:' . json_encode([$g['descripcion'], $g['proveedor'] ?? '', $g['categoria_id'], $g['tipo'], $g['frecuencia']]);
        $clave .= ':' . ($g['quincena_num'] ?? 0);
        $series[$clave][] = $g;
    }
    foreach ($series as $registrosSerie) {
        usort($registrosSerie, fn($a, $b) => [$a['fecha'], $a['id']] <=> [$b['fecha'], $b['id']]);
        $g = null;
        foreach ($registrosSerie as $r) {
            if ($r['fecha'] <= $fin) $g = $r;
        }
        if (!$g) continue;
        if ($g['frecuencia'] === 'anual' && (int)substr($g['fecha'], 5, 2) !== $mes) continue;
        $dias = [(int)($g['dia_pago'] ?: substr($g['fecha'], 8, 2))];
        if ($g['frecuencia'] === 'quincenal') {
            if ((int)($g['quincena_num'] ?? 0) === 2) $dias = [(int)$g['dia_pago_2']];
            elseif (empty($g['quincena_num'])) $dias[] = (int)$g['dia_pago_2'];
        }
        $fechas = [];
        foreach ($dias as $dia) {
            $fecha = sprintf('%04d-%02d-%02d', $anio, $mes, min(max(1, $dia), (int)substr($fin, 8, 2)));
            if ($fecha < $registrosSerie[0]['fecha']) continue;
            if (!empty($g['fecha_vencimiento']) && $fecha > $g['fecha_vencimiento']) continue;
            $fechas[] = $fecha;
        }
        if (!$fechas) continue;
        $g['fechas'] = $fechas;
        $g['total'] = round((float)$g['monto'] * count($fechas), 2);
        $detalle[] = $g;
    }
    usort($detalle, fn($a, $b) => [$a['fechas'][0], $a['id']] <=> [$b['fechas'][0], $b['id']]);
    return $detalle;
}
