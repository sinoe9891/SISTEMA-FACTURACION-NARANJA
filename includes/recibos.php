<?php
/**
 * recibos.php — Recibos de cobro de contratos «sin factura» (contratos_recibos).
 * Un recibo se emite al recibir el pago: su fecha es la del cobro y su monto es ingreso (sin ISV).
 */

/** Emite un recibo y devuelve [id, número]. $d: monto, fecha_emision, descripcion, metodo_pago, notas, periodo_mes, periodo_anio. */
function reciboRegistrar(PDO $pdo, int $cid, int $usuario, int $contratoId, array $d): array
{
    $st = $pdo->prepare("SELECT id, receptor_id FROM contratos WHERE id = ? AND cliente_id = ? AND tipo_contrato = 'sin_factura'");
    $st->execute([$contratoId, $cid]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) throw new Exception("Contrato no encontrado.");
    $st = $pdo->prepare("SELECT estado FROM contratos WHERE id = ?");
    $st->execute([$contratoId]);
    if ($st->fetchColumn() === 'borrador') throw new Exception("Este contrato está en borrador: actívalo en Editar contrato para facturar o cobrar.");

    $monto = round((float)($d['monto'] ?? 0), 2);
    $fecha = trim((string)($d['fecha_emision'] ?? ''));
    $desc = mb_substr(trim((string)($d['descripcion'] ?? '')), 0, 500);
    $metodo = trim((string)($d['metodo_pago'] ?? 'transferencia'));
    $mes = (int)($d['periodo_mes'] ?? 0) ?: (int)substr($fecha, 5, 2);
    $anio = (int)($d['periodo_anio'] ?? 0) ?: (int)substr($fecha, 0, 4);
    if ($monto <= 0) throw new Exception("El monto debe ser mayor a 0.");
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha)) throw new Exception("La fecha es obligatoria.");
    if ($desc === '') throw new Exception("La descripción es obligatoria.");
    if (!in_array($metodo, ['efectivo', 'transferencia', 'cheque', 'tarjeta', 'otro'], true)) throw new Exception("Método de pago inválido.");
    if ($mes < 1 || $mes > 12) throw new Exception("Mes inválido.");
    if ($anio < 2020) throw new Exception("Año inválido.");

    // Cuenta donde se depositó: entra a Bancos (salvo que sea anterior al saldo inicial de la cuenta)
    $cuentaId = (int)($d['cuenta_id'] ?? 0) ?: null;
    $movId = null;
    if ($cuentaId) {
        require_once __DIR__ . '/bancos.php';
        $movId = bancoDepositoCobro($pdo, $cid, $cuentaId, $fecha, $monto, 'Recibo contrato #' . $contratoId . ' · ' . $desc, null, $usuario);
    }

    // Próximo número de la empresa (bloquea para que dos recibos simultáneos no tomen el mismo)
    $st = $pdo->prepare("SELECT COALESCE(MAX(CAST(numero_recibo AS UNSIGNED)),0)+1 FROM contratos_recibos WHERE cliente_id = ? FOR UPDATE");
    $st->execute([$cid]);
    $num = (int)$st->fetchColumn();
    $hayCuenta = (bool)$pdo->query("SHOW COLUMNS FROM contratos_recibos LIKE 'cuenta_id'")->fetchColumn();
    $pdo->prepare("INSERT INTO contratos_recibos (cliente_id, contrato_id, receptor_id, numero_recibo, concepto, monto, fecha_emision, periodo_mes, periodo_anio, metodo_pago, notas, estado, usuario_id"
                  . ($hayCuenta ? ", cuenta_id, movimiento_id" : "") . ")
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'emitido', ?" . ($hayCuenta ? ", ?, ?" : "") . ")")
        ->execute(array_merge([$cid, $contratoId, (int)$c['receptor_id'], $num, $desc, $monto, $fecha, $mes, $anio, $metodo, trim((string)($d['notas'] ?? '')) ?: null, $usuario],
            $hayCuenta ? [$cuentaId, $movId] : []));
    $recId = (int)$pdo->lastInsertId();
    if ($movId) $pdo->prepare("UPDATE movimientos_bancarios SET referencia = ? WHERE id = ?")->execute(['Recibo ' . str_pad((string)$num, 5, '0', STR_PAD_LEFT), $movId]);
    return [$recId, $num];
}
