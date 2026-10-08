<?php
// clientes/naranjaymedia/includes/_gasto_recurrencia.php
// Gastos recurrentes (mensual / anual): al pagar uno, se programa el del período siguiente.
// Solo funciones, sin salida. Lo usan gasto_marcar_pagado y gasto_actualizar.

/**
 * Crea el siguiente gasto pendiente de una serie recurrente, si corresponde.
 *
 * @param array $gasto Fila del gasto ANTES de marcarlo pagado (necesita la fecha programada).
 * @return string|null Fecha (Y-m-d) del gasto creado, o null si no se creó.
 */
function programarSiguienteGastoRecurrente(PDO $pdo, array $gasto, int $cid, int $usuario_id): ?string
{
    $frecuencia = $gasto['frecuencia'] ?? 'unico';
    if (!in_array($frecuencia, ['mensual', 'anual'], true)) return null;     // quincenal/único: no
    if (($gasto['estado'] ?? '') !== 'pendiente') return null;              // solo al pasar de pendiente a pagado
    if (strpos($gasto['descripcion'] ?? '', 'Sueldo ') === 0) return null;  // nómina: módulo colaboradores

    // Siguiente fecha a partir de la fecha PROGRAMADA (no la del pago real)
    $base = new DateTime($gasto['fecha']);
    $sig  = (clone $base)->modify('first day of this month')->modify($frecuencia === 'anual' ? '+1 year' : '+1 month');
    $dia  = (int)($gasto['dia_pago'] ?: $base->format('j'));
    $sig->setDate((int)$sig->format('Y'), (int)$sig->format('n'), min($dia, (int)$sig->format('t')));
    $fechaSig = $sig->format('Y-m-d');

    // Respetar la fecha de vencimiento de la serie
    if (!empty($gasto['fecha_vencimiento']) && $fechaSig > $gasto['fecha_vencimiento']) return null;

    // Serie: se identifica por gasto_grupo_id (o el propio id si aún no tiene)
    $grupo = (int)($gasto['gasto_grupo_id'] ?: $gasto['id']);

    // No duplicar si ya existe el gasto de ese período (p. ej. cuotas cargadas por adelantado)
    if ($frecuencia === 'anual') {
        $desde = $sig->format('Y-01-01');
        $hasta = $sig->format('Y-12-31');
    } else {
        $desde = $sig->format('Y-m-01');
        $hasta = $sig->format('Y-m-t');
    }
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM gastos
        WHERE cliente_id = ? AND estado != 'anulado' AND fecha BETWEEN ? AND ?
          AND (gasto_grupo_id = ? OR id = ? OR (descripcion = ? AND frecuencia = ?))
    ");
    $stmt->execute([$cid, $desde, $hasta, $grupo, $grupo, $gasto['descripcion'], $frecuencia]);
    if ((int)$stmt->fetchColumn() > 0) return null;

    // Marcar la serie en el gasto original (si aún no la tenía)
    if (empty($gasto['gasto_grupo_id'])) {
        $pdo->prepare("UPDATE gastos SET gasto_grupo_id = ? WHERE id = ? AND cliente_id = ?")
            ->execute([$grupo, $gasto['id'], $cid]);
    }

    // Nuevo gasto pendiente: mismos datos de la serie, sin comprobante ni referencia de pago
    $pdo->prepare("
        INSERT INTO gastos (cliente_id, categoria_id, descripcion, monto, fecha, frecuencia, dia_pago, dia_pago_2,
                            gasto_grupo_id, fecha_vencimiento, tipo, metodo_pago, tarjeta_id, proveedor, estado, usuario_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente', ?)
    ")->execute([
        $cid,
        $gasto['categoria_id'],
        $gasto['descripcion'],
        $gasto['monto'],
        $fechaSig,
        $frecuencia,
        $gasto['dia_pago'],
        $gasto['dia_pago_2'],
        $grupo,
        $gasto['fecha_vencimiento'],
        $gasto['tipo'],
        $gasto['metodo_pago'],
        $gasto['tarjeta_id'],
        $gasto['proveedor'],
        $usuario_id,
    ]);
    $nuevoId = (int)$pdo->lastInsertId();
    // La siguiente cuota conserva su naturaleza (p. ej. abono a capital de un préstamo) y su préstamo
    if (!empty($gasto['naturaleza']) && $gasto['naturaleza'] !== 'gasto') {
        $pdo->prepare("UPDATE gastos SET naturaleza = ?, prestamo_id = ?, activo_id = ? WHERE id = ?")
            ->execute([$gasto['naturaleza'], $gasto['prestamo_id'] ?? null, $gasto['activo_id'] ?? null, $nuevoId]);
    }
    // El período siguiente también se le cobra al mismo cliente (p. ej. la renovación anual de su hosting)
    if (!empty($gasto['cobrar_receptor_id']))
        $pdo->prepare("UPDATE gastos SET cobrar_receptor_id = ? WHERE id = ? AND cliente_id = ?")->execute([(int)$gasto['cobrar_receptor_id'], $nuevoId, $cid]);
    // Renovación de una licencia: el gasto siguiente sigue ligado a ella
    if (!empty($gasto['licencia_id']))
        $pdo->prepare("UPDATE gastos SET licencia_id = ? WHERE id = ? AND cliente_id = ?")->execute([(int)$gasto['licencia_id'], $nuevoId, $cid]);

    return $fechaSig;
}
