<?php
/**
 * pagos_recibidos.php — Todo el dinero que entra por los clientes, en una sola lista:
 *   factura  → abonos a facturas (cobros_factura), sin los que vienen de aplicar un anticipo (ese dinero ya está como anticipo)
 *   recibo   → recibos de contratos sin factura (contratos_recibos)
 *   anticipo → pagos anticipados de un contrato (contratos_anticipos), estén o no aplicados a una factura
 * Se puede editar la fecha, el método, la referencia, las notas y la cuenta del banco (el depósito se mueve con ella),
 * y adjuntar o cambiar el comprobante (también queda en el depósito del banco). El monto no se edita: se anula y se registra otro.
 */
require_once __DIR__ . '/cuentas.php';
require_once __DIR__ . '/bancos.php';

const PAGO_RECIBIDO_TIPOS = ['factura' => 'Abono a factura', 'recibo' => 'Recibo', 'anticipo' => 'Pago anticipado'];
const PAGO_RECIBIDO_METODOS = ['transferencia' => 'Transferencia', 'efectivo' => 'Efectivo', 'cheque' => 'Cheque', 'tarjeta' => 'Tarjeta', 'otro' => 'Otro'];

function pagosRecibidosDisponible(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try { $ok = (bool)$pdo->query("SHOW COLUMNS FROM contratos_anticipos LIKE 'archivo_adjunto'")->fetchColumn(); } catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

/**
 * Pagos de la empresa entre dos fechas (incluye los anulados, marcados). Cada fila:
 * tipo, id, fecha, monto, metodo, referencia, notas, cuenta_id, cuenta, movimiento_id, conciliado, con_comprobante,
 * anulado, cliente, receptor_id, contrato_id, contrato, documento, documento_url, aplicado_a
 */
function pagosRecibidosLista(PDO $pdo, int $cid, string $desde, string $hasta): array
{
    $cta = "LEFT JOIN cuentas_bancarias b ON b.id = x.cuenta_id LEFT JOIN movimientos_bancarios m ON m.id = x.movimiento_id";
    $comun = "x.id, x.monto, x.cuenta_id, CONCAT(b.banco, ' ', b.numero) AS cuenta, x.movimiento_id, COALESCE(m.conciliado, 0) AS conciliado, x.archivo_adjunto IS NOT NULL AS con_comprobante";
    $out = [];

    $st = $pdo->prepare("SELECT 'factura' AS tipo, $comun, x.fecha, x.metodo, x.referencia, x.notas, x.anulado, x.motivo_anulacion,
                                cf.nombre AS cliente, f.receptor_id, f.contrato_id, ct.nombre_contrato AS contrato, f.correlativo AS documento, f.id AS doc_id
                         FROM cobros_factura x JOIN facturas f ON f.id = x.factura_id JOIN clientes_factura cf ON cf.id = f.receptor_id
                         LEFT JOIN contratos ct ON ct.id = f.contrato_id $cta
                         WHERE x.cliente_id = ? AND x.fecha BETWEEN ? AND ?
                           AND x.id NOT IN (SELECT cobro_id FROM contratos_anticipos WHERE cliente_id = ? AND cobro_id IS NOT NULL)");
    $st->execute([$cid, $desde, $hasta, $cid]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = $r + ['documento_url' => 'ver_factura?id=' . (int)$r['doc_id'], 'aplicado_a' => ''];

    $st = $pdo->prepare("SELECT 'recibo' AS tipo, $comun, x.fecha_emision AS fecha, x.metodo_pago AS metodo, NULL AS referencia, x.notas,
                                (x.estado = 'anulado') AS anulado, NULL AS motivo_anulacion,
                                cf.nombre AS cliente, x.receptor_id, x.contrato_id, ct.nombre_contrato AS contrato, LPAD(x.numero_recibo, 5, '0') AS documento, x.id AS doc_id
                         FROM contratos_recibos x JOIN clientes_factura cf ON cf.id = x.receptor_id
                         LEFT JOIN contratos ct ON ct.id = x.contrato_id $cta
                         WHERE x.cliente_id = ? AND x.fecha_emision BETWEEN ? AND ?");
    $st->execute([$cid, $desde, $hasta]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = $r + ['documento_url' => 'recibo_pdf?id=' . (int)$r['doc_id'], 'aplicado_a' => ''];

    $st = $pdo->prepare("SELECT 'anticipo' AS tipo, $comun, x.fecha, x.metodo, x.referencia, x.concepto AS notas, x.anulado, x.motivo_anulacion,
                                cf.nombre AS cliente, ct.receptor_id, x.contrato_id, ct.nombre_contrato AS contrato, CONCAT('A-', LPAD(x.id, 5, '0')) AS documento, x.id AS doc_id,
                                fa.correlativo AS aplicado_a, x.factura_id
                         FROM contratos_anticipos x JOIN contratos ct ON ct.id = x.contrato_id JOIN clientes_factura cf ON cf.id = ct.receptor_id
                         LEFT JOIN facturas fa ON fa.id = x.factura_id $cta
                         WHERE x.cliente_id = ? AND x.fecha BETWEEN ? AND ?");
    $st->execute([$cid, $desde, $hasta]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = $r + ['documento_url' => 'facturas_contrato?contrato_id=' . (int)$r['contrato_id'] . '#anticipos'];

    usort($out, fn($a, $b) => [$b['fecha'], $b['tipo'], (int)$b['id']] <=> [$a['fecha'], $a['tipo'], (int)$a['id']]);
    return $out;
}

/** Tabla, columnas y nombres de cada tipo. */
function pagoRecibidoMapa(string $tipo): array
{
    return match ($tipo) {
        'factura' => ['tabla' => 'cobros_factura', 'fecha' => 'fecha', 'metodo' => 'metodo', 'referencia' => 'referencia', 'notas' => 'notas', 'anulado' => 'anulado = 1'],
        'recibo' => ['tabla' => 'contratos_recibos', 'fecha' => 'fecha_emision', 'metodo' => 'metodo_pago', 'referencia' => null, 'notas' => 'notas', 'anulado' => "estado = 'anulado'"],
        'anticipo' => ['tabla' => 'contratos_anticipos', 'fecha' => 'fecha', 'metodo' => 'metodo', 'referencia' => 'referencia', 'notas' => 'concepto', 'anulado' => 'anulado = 1'],
        default => throw new Exception("Tipo de pago no válido."),
    };
}

function pagoRecibidoObtener(PDO $pdo, int $cid, string $tipo, int $id): array
{
    $mp = pagoRecibidoMapa($tipo);
    $st = $pdo->prepare("SELECT *, ({$mp['anulado']}) AS esta_anulado FROM {$mp['tabla']} WHERE id = ? AND cliente_id = ?");
    $st->execute([$id, $cid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new Exception("Pago no encontrado.");
    return $r;
}

/**
 * Edita un pago. $d: fecha, metodo, referencia, notas, cuenta_id ('' = no registrar en banco), quitar_comprobante.
 * $adjunto: [archivo, nombre] ya guardado en uploads/gastos (o null).
 */
function pagoRecibidoEditar(PDO $pdo, int $cid, int $uid, string $tipo, int $id, array $d, ?array $adjunto = null): void
{
    $mp = pagoRecibidoMapa($tipo);
    $p = pagoRecibidoObtener($pdo, $cid, $tipo, $id);
    if ((int)$p['esta_anulado']) throw new Exception("El pago está anulado: no se edita.");
    $fecha = bancoFecha((string)($d['fecha'] ?? $p[$mp['fecha']]));
    if ($tipo === 'factura') {
        $st = $pdo->prepare("SELECT DATE(fecha_emision) FROM facturas WHERE id = ?");
        $st->execute([(int)$p['factura_id']]);
        if ($fecha < (string)$st->fetchColumn()) throw new Exception("La fecha del cobro no puede ser anterior a la factura.");
    }
    $metodo = (string)($d['metodo'] ?? $p[$mp['metodo']]);
    if (!isset(PAGO_RECIBIDO_METODOS[$metodo])) throw new Exception("Método de pago inválido.");
    $ref = $mp['referencia'] ? (mb_substr(trim((string)($d['referencia'] ?? '')), 0, 100) ?: null) : null;
    $notas = mb_substr(trim((string)($d['notas'] ?? '')), 0, 255) ?: null;
    $monto = (float)$p['monto'];

    // Cuenta del banco: el depósito se crea, se mueve de cuenta o se anula según lo elegido
    $antesCuenta = (int)$p['cuenta_id'] ?: null;
    $nuevaCuenta = (int)($d['cuenta_id'] ?? 0) ?: null;
    $mov = (int)$p['movimiento_id'] ?: null;
    if ($mov) {
        $st = $pdo->prepare("SELECT * FROM movimientos_bancarios WHERE id = ? AND cliente_id = ?");
        $st->execute([$mov, $cid]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m || (int)$m['anulado']) $mov = null;
        elseif ((int)$m['conciliado'] && ($nuevaCuenta !== $antesCuenta || $fecha !== $m['fecha']))
            throw new Exception("El depósito ya está conciliado con el estado de cuenta: quita la conciliación en Bancos para cambiar la cuenta o la fecha.");
    }
    if ($nuevaCuenta) {
        $cta = bancoCuenta($pdo, $cid, $nuevaCuenta, $nuevaCuenta !== $antesCuenta);   // la misma cuenta puede estar inactiva; una nueva, no
        if ($cta['moneda'] !== 'HNL') throw new Exception("Elige una cuenta en lempiras.");
    }
    $desc = (PAGO_RECIBIDO_TIPOS[$tipo] ?? 'Pago') . ' #' . $id;
    if ($mov && !$nuevaCuenta) {
        $pdo->prepare("UPDATE movimientos_bancarios SET anulado = 1 WHERE id = ?")->execute([$mov]);
        $mov = null;
    } elseif ($mov) {
        $pdo->prepare("UPDATE movimientos_bancarios SET cuenta_id = ?, fecha = ?, referencia = ? WHERE id = ?")->execute([$nuevaCuenta, $fecha, $ref, $mov]);
    } elseif ($nuevaCuenta) {
        $mov = bancoDepositoCobro($pdo, $cid, $nuevaCuenta, $fecha, $monto, match ($tipo) {
            'factura' => 'Cobro factura ' . pagoRecibidoDocumento($pdo, $tipo, $p),
            'recibo' => 'Recibo ' . pagoRecibidoDocumento($pdo, $tipo, $p) . ' · contrato #' . (int)$p['contrato_id'],
            default => 'Anticipo contrato #' . (int)$p['contrato_id'],
        }, $ref, $uid);
        if ($mov && $tipo === 'factura') $pdo->prepare("UPDATE movimientos_bancarios SET factura_id = ?, tipo = 'cobro_factura' WHERE id = ?")->execute([(int)$p['factura_id'], $mov]);
    }

    $sets = ["{$mp['fecha']} = ?", "{$mp['metodo']} = ?", "{$mp['notas']} = ?", "cuenta_id = ?", "movimiento_id = ?"];
    $vals = [$fecha, $metodo, $notas, $nuevaCuenta, $mov];
    if ($mp['referencia']) { $sets[] = "{$mp['referencia']} = ?"; $vals[] = $ref; }
    // Comprobante: nuevo, quitado o el mismo
    $archivoAntes = $p['archivo_adjunto'] ?? null;
    if ($adjunto) { $sets[] = 'archivo_adjunto = ?'; $sets[] = 'archivo_nombre = ?'; array_push($vals, $adjunto[0], $adjunto[1]); }
    elseif (!empty($d['quitar_comprobante'])) { $sets[] = 'archivo_adjunto = NULL'; $sets[] = 'archivo_nombre = NULL'; }
    $pdo->prepare("UPDATE {$mp['tabla']} SET " . implode(', ', $sets) . " WHERE id = ? AND cliente_id = ?")->execute([...$vals, $id, $cid]);

    // El comprobante también en el depósito del banco (si no tiene otro propio)
    if ($mov) {
        if ($adjunto) $pdo->prepare("UPDATE movimientos_bancarios SET archivo_adjunto = ?, archivo_nombre = ? WHERE id = ? AND (archivo_adjunto IS NULL OR archivo_adjunto = ?)")->execute([$adjunto[0], $adjunto[1], $mov, (string)$archivoAntes]);
        elseif (!empty($d['quitar_comprobante']) && $archivoAntes) $pdo->prepare("UPDATE movimientos_bancarios SET archivo_adjunto = NULL, archivo_nombre = NULL WHERE id = ? AND archivo_adjunto = ?")->execute([$mov, $archivoAntes]);
        elseif ($archivoAntes) $pdo->prepare("UPDATE movimientos_bancarios SET archivo_adjunto = ?, archivo_nombre = ? WHERE id = ? AND archivo_adjunto IS NULL")->execute([$archivoAntes, $p['archivo_nombre'], $mov]);
    }
    // Un anticipo ya aplicado a una factura tiene su abono espejo: se mantiene igual
    if ($tipo === 'anticipo' && !empty($p['cobro_id']))
        $pdo->prepare("UPDATE cobros_factura SET fecha = ?, metodo = ?, referencia = ?, cuenta_id = ?, movimiento_id = ? WHERE id = ? AND cliente_id = ?")
            ->execute([$fecha, $metodo, $ref, $nuevaCuenta, $mov, (int)$p['cobro_id'], $cid]);
}

/** Número del documento del pago (para descripciones). */
function pagoRecibidoDocumento(PDO $pdo, string $tipo, array $p): string
{
    if ($tipo === 'factura') {
        $st = $pdo->prepare("SELECT correlativo FROM facturas WHERE id = ?");
        $st->execute([(int)$p['factura_id']]);
        return (string)$st->fetchColumn();
    }
    return $tipo === 'recibo' ? str_pad((string)$p['numero_recibo'], 5, '0', STR_PAD_LEFT) : 'A-' . str_pad((string)$p['id'], 5, '0', STR_PAD_LEFT);
}
