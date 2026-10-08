<?php
/**
 * nomina_pagos.php — Pagos realizados a colaboradores (sueldos, bonos, viáticos y otros pagos
 * registrados como gastos con el nombre del colaborador). Lo usan la página «Pagos de nómina»
 * y sus exportaciones a PDF y XLSX, para que muestren exactamente lo mismo.
 */

const NOMINA_MESES = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const NOMINA_TIPOS = ['sueldo' => 'Sueldo', 'honorarios' => 'Honorarios', 'bono' => 'Bono', 'viatico' => 'Viático', 'otro' => 'Otro pago'];

/** Filtros normalizados desde $_GET (por defecto: el año en curso). */
function nominaFiltros(array $g): array
{
    // Fecha válida (año 2000 en adelante: al escribir el año en el campo de fecha llegan valores como 0002-…)
    $fecha = fn($v, $d) => (is_string($v) && preg_match('/^(\d{4})-\d{2}-\d{2}$/', $v, $m) && $m[1] >= 2000 && strtotime($v)) ? $v : $d;
    // ?anio=2024 → todo ese año (el selector de año de la página)
    $anio = (int)($g['anio'] ?? 0);
    if ($anio >= 2000 && $anio <= (int)date('Y') + 1) { $g['desde'] = "$anio-01-01"; $g['hasta'] = "$anio-12-31"; }
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
    if (stripos($descripcion, 'Honorarios ') === 0) return 'honorarios';   // pagos por proyecto, antes de tener salario
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

/* ══ Editar / anular / eliminar un pago de nómina ══════════════════════════════════════════════
 * El pago de nómina es un gasto «Sueldo …». Al guardarlo (colaborador_pago_guardar.php) se marcaron
 * cuotas de préstamos («Descontado en nómina gasto #ID»), se liquidaron bonos/viáticos («Aplicado en
 * nómina gasto #ID el …») y se crearon gastos extra por esos bonos/viáticos («Aplicado junto con
 * nómina gasto #ID»). Anular deshace todo eso para que la quincena quede libre y se registre de nuevo.
 */

/** Pago de nómina de la empresa (o excepción). */
function nominaPagoObtener(PDO $pdo, int $cid, int $gid): array
{
    $st = $pdo->prepare("SELECT * FROM gastos WHERE id = ? AND cliente_id = ?");
    $st->execute([$gid, $cid]);
    $g = $st->fetch(PDO::FETCH_ASSOC);
    if (!$g || stripos((string)$g['descripcion'], 'Sueldo ') !== 0) throw new Exception("Pago de nómina no encontrado.");
    return $g;
}

/** Lo que se revertiría al anular (para mostrarlo antes de confirmar). */
function nominaPagoVinculos(PDO $pdo, int $cid, int $gid): array
{
    $re = 'gasto #' . $gid . '([^0-9]|$)';
    $st = $pdo->prepare("SELECT q.id, q.prestamo_id, q.numero_cuota, q.monto, p.tipo, p.descripcion FROM colaborador_prestamo_cuotas q JOIN colaborador_prestamos p ON p.id = q.prestamo_id
                         WHERE q.cliente_id = ? AND q.estado = 'pagado' AND q.metodo_pago = 'descuento_nomina' AND q.notas REGEXP ?");
    $st->execute([$cid, $re]);
    $cuotas = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $pdo->prepare("SELECT id, tipo, descripcion, monto_total FROM colaborador_prestamos WHERE cliente_id = ? AND tipo IN ('bono','viatico') AND estado = 'pagado' AND notas REGEXP ?");
    $st->execute([$cid, 'gasto #' . $gid . ' el ']);
    $extras = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $pdo->prepare("SELECT id, descripcion, monto FROM gastos WHERE cliente_id = ? AND estado <> 'anulado' AND notas = ?");
    $st->execute([$cid, 'Aplicado junto con nómina gasto #' . $gid]);
    return ['cuotas' => $cuotas, 'bonos_viaticos' => $extras, 'gastos_extra' => $st->fetchAll(PDO::FETCH_ASSOC)];
}

/** Anula el pago y deshace sus descuentos y liquidaciones. Devuelve lo revertido. */
function nominaPagoAnular(PDO $pdo, int $cid, int $gid, string $motivo): array
{
    $g = nominaPagoObtener($pdo, $cid, $gid);
    if ($g['estado'] === 'anulado') throw new Exception("Este pago ya está anulado.");
    $motivo = trim($motivo);
    if ($motivo === '') throw new Exception("Escribe el motivo de la anulación.");
    $v = nominaPagoVinculos($pdo, $cid, $gid);
    $gastos = array_merge([$gid], array_map('intval', array_column($v['gastos_extra'], 'id')));
    $in = implode(',', $gastos);

    $hayBancos = (bool)$pdo->query("SHOW TABLES LIKE 'movimientos_bancarios'")->fetchColumn();
    if ($hayBancos && $pdo->query("SELECT COUNT(*) FROM movimientos_bancarios WHERE cliente_id = $cid AND gasto_id IN ($in) AND anulado = 0 AND conciliado = 1")->fetchColumn())
        throw new Exception("El pago ya está conciliado en el banco: quita la conciliación antes de anularlo.");

    $propia = !$pdo->inTransaction();
    if ($propia) $pdo->beginTransaction();
    try {
        $nota = ' | Revertido: nómina gasto #' . $gid . ' anulada el ' . date('d/m/Y');
        foreach ($v['cuotas'] as $q) {
            $pdo->prepare("UPDATE colaborador_prestamo_cuotas SET estado = 'pendiente', fecha_pago = NULL, metodo_pago = NULL, notas = LEFT(CONCAT(IFNULL(notas,''), ?), 300) WHERE id = ?")
                ->execute([$nota, $q['id']]);
            $pdo->prepare("UPDATE colaborador_prestamos SET saldo_pendiente = LEAST(monto_total, saldo_pendiente + ?), estado = IF(estado = 'pagado', 'activo', estado) WHERE id = ? AND cliente_id = ?")
                ->execute([$q['monto'], $q['prestamo_id'], $cid]);
        }
        foreach ($v['bonos_viaticos'] as $b)
            $pdo->prepare("UPDATE colaborador_prestamos SET estado = 'activo', notas = CONCAT(IFNULL(notas,''), ?) WHERE id = ? AND cliente_id = ?")->execute([$nota, $b['id'], $cid]);
        $pdo->prepare("UPDATE gastos SET estado = 'anulado', notas = CONCAT(IFNULL(notas,''), ?) WHERE cliente_id = ? AND id IN ($in)")
            ->execute([' | ANULADO el ' . date('d/m/Y') . ': ' . mb_substr($motivo, 0, 200), $cid]);
        if ($hayBancos) $pdo->exec("UPDATE movimientos_bancarios SET anulado = 1 WHERE cliente_id = $cid AND gasto_id IN ($in) AND tipo = 'pago_gasto'");
        if ($propia) $pdo->commit();
    } catch (Throwable $e) {
        if ($propia && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $v;
}

/** Edita los datos del pago que no cambian descuentos: fecha, método, notas y comprobante. */
function nominaPagoEditar(PDO $pdo, int $cid, int $gid, array $d, ?array $adjunto = null): void
{
    $g = nominaPagoObtener($pdo, $cid, $gid);
    if ($g['estado'] === 'anulado') throw new Exception("No se edita un pago anulado.");
    $fecha = (string)($d['fecha'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha)) throw new Exception("Fecha inválida.");
    $metodo = in_array($d['metodo_pago'] ?? '', ['efectivo', 'transferencia', 'cheque', 'tarjeta', 'otro'], true) ? $d['metodo_pago'] : $g['metodo_pago'];
    $notas = mb_substr(trim((string)($d['notas'] ?? '')), 0, 1000);
    $sql = "UPDATE gastos SET fecha = ?, metodo_pago = ?, notas = ?" . ($adjunto ? ", archivo_adjunto = ?, archivo_nombre = ?" : "") . " WHERE id = ? AND cliente_id = ?";
    $pdo->prepare($sql)->execute([$fecha, $metodo, $notas ?: null, ...($adjunto ?: []), $gid, $cid]);
    if ($pdo->query("SHOW TABLES LIKE 'movimientos_bancarios'")->fetchColumn())
        $pdo->prepare("UPDATE movimientos_bancarios SET fecha = ? WHERE cliente_id = ? AND gasto_id = ? AND anulado = 0 AND conciliado = 0")->execute([$fecha, $cid, $gid]);
}
