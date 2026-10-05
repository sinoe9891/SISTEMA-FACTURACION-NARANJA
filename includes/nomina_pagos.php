<?php
/**
 * nomina_pagos.php — Pagos realizados a colaboradores (sueldos, bonos, viáticos y otros pagos
 * registrados como gastos con el nombre del colaborador). Lo usan la página «Pagos de nómina»
 * y sus exportaciones a PDF y XLSX, para que muestren exactamente lo mismo.
 */

const NOMINA_MESES = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const NOMINA_TIPOS = ['sueldo' => 'Sueldo', 'bono' => 'Bono', 'viatico' => 'Viático', 'otro' => 'Otro pago'];

/** Filtros normalizados desde $_GET (por defecto: el año en curso). */
function nominaFiltros(array $g): array
{
    $fecha = fn($v, $d) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : $d;
    $f = [
        'desde' => $fecha($g['desde'] ?? null, date('Y') . '-01-01'),
        'hasta' => $fecha($g['hasta'] ?? null, date('Y-m-d')),
        'colaborador' => (int)($g['colaborador'] ?? 0),
        'tipo' => isset(NOMINA_TIPOS[$g['tipo'] ?? '']) ? $g['tipo'] : '',
        'quincena' => in_array($g['quincena'] ?? '', ['1', '2', 'mensual'], true) ? $g['quincena'] : '',
        'comprobante' => in_array($g['comprobante'] ?? '', ['con', 'sin'], true) ? $g['comprobante'] : '',
        'aviso' => in_array($g['aviso'] ?? '', ['si', 'no'], true) ? $g['aviso'] : '',
    ];
    if ($f['desde'] > $f['hasta']) [$f['desde'], $f['hasta']] = [$f['hasta'], $f['desde']];
    return $f;
}

function nominaTipo(string $descripcion): string
{
    if (stripos($descripcion, 'Sueldo ') === 0) return 'sueldo';
    if (stripos($descripcion, 'Bono') === 0) return 'bono';
    if (preg_match('/^vi[aá]tico/iu', $descripcion)) return 'viatico';
    return 'otro';
}

/** Pagos que cumplen los filtros, con su colaborador, tipo y si ya se le envió el aviso por correo. */
function nominaPagos(PDO $pdo, int $cid, array $f): array
{
    $col = $pdo->prepare("SELECT id, nombre, apellido, activo, email FROM colaboradores WHERE cliente_id = ?");
    $col->execute([$cid]);
    $colabs = [];
    foreach ($col->fetchAll(PDO::FETCH_ASSOC) as $c) $colabs[(int)$c['id']] = $c + ['completo' => trim($c['nombre'] . ' ' . $c['apellido'])];

    $st = $pdo->prepare("SELECT id, descripcion, monto, fecha, metodo_pago, quincena_num, archivo_adjunto, notas
                         FROM gastos WHERE cliente_id = ? AND estado <> 'anulado' AND fecha BETWEEN ? AND ? ORDER BY fecha DESC, id DESC");
    $st->execute([$cid, $f['desde'], $f['hasta']]);

    $avisos = [];
    try {
        $a = $pdo->prepare("SELECT referencia_id, MAX(creado_en) FROM correos_enviados WHERE cliente_id = ? AND tipo = 'pago_colaborador' AND estado = 'enviado' GROUP BY referencia_id");
        $a->execute([$cid]);
        $avisos = $a->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Throwable $e) {
        $avisos = [];   // módulo de correo no instalado
    }

    $filas = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $g) {
        // El colaborador se identifica por su nombre completo dentro de la descripción del gasto
        $colab = null;
        foreach ($colabs as $c) {
            if ($c['completo'] !== '' && mb_stripos($g['descripcion'], $c['completo']) !== false) { $colab = $c; break; }
        }
        if (!$colab) continue;
        $tipo = nominaTipo($g['descripcion']);
        $q = $g['quincena_num'] === null ? 'mensual' : (string)(int)$g['quincena_num'];
        if ($f['colaborador'] && (int)$colab['id'] !== $f['colaborador']) continue;
        if ($f['tipo'] && $tipo !== $f['tipo']) continue;
        if ($f['quincena'] && ($tipo !== 'sueldo' || $q !== $f['quincena'])) continue;
        if ($f['comprobante'] === 'con' && empty($g['archivo_adjunto'])) continue;
        if ($f['comprobante'] === 'sin' && !empty($g['archivo_adjunto'])) continue;
        $aviso = $avisos[$g['id']] ?? null;
        if ($f['aviso'] === 'si' && !$aviso) continue;
        if ($f['aviso'] === 'no' && ($aviso || $tipo !== 'sueldo')) continue;
        $ts = strtotime($g['fecha']);
        $filas[] = $g + [
            'colaborador_id' => (int)$colab['id'], 'colaborador' => $colab['completo'], 'colaborador_activo' => (int)$colab['activo'],
            'tiene_email' => (bool)filter_var((string)$colab['email'], FILTER_VALIDATE_EMAIL),
            'tipo' => $tipo, 'tipo_txt' => NOMINA_TIPOS[$tipo],
            'periodo' => ($tipo === 'sueldo' ? ($q === '1' ? '1ª quincena · ' : ($q === '2' ? '2ª quincena · ' : '')) : '') . NOMINA_MESES[(int)date('n', $ts)] . ' ' . date('Y', $ts),
            'mes' => date('Y-m', $ts), 'aviso_enviado' => $aviso,
            'referencia' => preg_match('/ref\.\s*([A-Za-z0-9]+)/i', (string)$g['notas'], $m) ? $m[1] : '',
        ];
    }
    return $filas;
}

/** Totales y resúmenes por colaborador y por mes. */
function nominaResumen(array $filas): array
{
    $porColab = $porMes = [];
    foreach ($filas as $p) {
        $c = &$porColab[$p['colaborador_id']];
        $c ??= ['nombre' => $p['colaborador'], 'n' => 0, 'total' => 0.0, 'sueldos' => 0.0, 'otros' => 0.0, 'comprobantes' => 0, 'ultimo' => null];
        $c['n']++;
        $c['total'] += (float)$p['monto'];
        $c[$p['tipo'] === 'sueldo' ? 'sueldos' : 'otros'] += (float)$p['monto'];
        $c['comprobantes'] += $p['archivo_adjunto'] ? 1 : 0;
        $c['ultimo'] = max($c['ultimo'] ?? $p['fecha'], $p['fecha']);
        unset($c);
        $m = &$porMes[$p['mes']];
        $m ??= ['n' => 0, 'total' => 0.0, 'colabs' => []];
        $m['n']++;
        $m['total'] += (float)$p['monto'];
        $m['colabs'][$p['colaborador']] = ($m['colabs'][$p['colaborador']] ?? 0) + (float)$p['monto'];
        unset($m);
    }
    uasort($porColab, fn($a, $b) => $b['total'] <=> $a['total']);
    krsort($porMes);
    return [
        'total' => array_sum(array_column($filas, 'monto')),
        'n' => count($filas),
        'comprobantes' => count(array_filter($filas, fn($p) => !empty($p['archivo_adjunto']))),
        'avisos' => count(array_filter($filas, fn($p) => $p['aviso_enviado'])),
        'por_colaborador' => $porColab,
        'por_mes' => $porMes,
    ];
}

function nominaMesTxt(string $ym): string
{
    return ucfirst(NOMINA_MESES[(int)substr($ym, 5, 2)]) . ' ' . substr($ym, 0, 4);
}
