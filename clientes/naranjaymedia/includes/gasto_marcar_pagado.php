<?php
// clientes/naranjaymedia/includes/gasto_marcar_pagado.php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once __DIR__ . '/_gasto_adjuntos.php';
require_once __DIR__ . '/_gasto_recurrencia.php';
require_once '../../../includes/bancos.php';
header('Content-Type: application/json; charset=utf-8');

$arch_adj = null;
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    $cid      = (int)(USUARIO_ROL === 'superadmin' ? ($_SESSION['cliente_seleccionado'] ?? 0) : CLIENTE_ID);
    $gasto_id = filter_input(INPUT_POST, 'gasto_id', FILTER_VALIDATE_INT);
    if (!$gasto_id) throw new Exception("Gasto inválido.");

    $stmtCk = $pdo->prepare("SELECT * FROM gastos WHERE id=? AND cliente_id=? AND estado!='anulado'");
    $stmtCk->execute([$gasto_id, $cid]);
    $gastoActual = $stmtCk->fetch(PDO::FETCH_ASSOC);
    if (!$gastoActual) throw new Exception("Gasto no encontrado.");

    $fecha       = trim($_POST['fecha']       ?? '') ?: date('Y-m-d');
    $metodo      = trim($_POST['metodo_pago'] ?? 'efectivo');
    $tarjeta_id  = filter_input(INPUT_POST, 'tarjeta_id', FILTER_VALIDATE_INT) ?: null;
    $tiene_fact  = !empty($_POST['tiene_factura']);
    $factura_ref = $tiene_fact ? (trim($_POST['factura_ref'] ?? '') ?: null) : null;
    $notas       = trim($_POST['notas'] ?? '') ?: null;

    if (!in_array($metodo, GASTO_METODOS_PAGO))
        throw new Exception("Método de pago inválido.");
    if (!DateTime::createFromFormat('Y-m-d', $fecha))
        throw new Exception("Fecha inválida.");

    // Si método = tarjeta, validar que pertenezca al cliente
    if ($metodo === 'tarjeta' && $tarjeta_id) {
        $stmtT = $pdo->prepare("SELECT id FROM tarjetas WHERE id=? AND cliente_id=? AND activa=1");
        $stmtT->execute([$tarjeta_id, $cid]);
        if (!$stmtT->fetchColumn()) {
            $tarjeta_id = null; // Tarjeta no válida, ignorar
        }
    } else {
        $tarjeta_id = null;
    }

    // Comprobante (opcional)
    [$arch_adj, $arch_nom] = guardarAdjuntoGasto($_FILES['archivo_adjunto'] ?? null, $cid) ?? [null, null];

    $sets   = ['estado=?', 'fecha=?', 'metodo_pago=?', 'tarjeta_id=?'];
    $params = ['pagado', $fecha, $metodo, $tarjeta_id];
    if ($factura_ref !== null) { $sets[] = 'factura_ref=?'; $params[] = $factura_ref; }
    if ($notas)                { $sets[] = 'notas=?';       $params[] = $notas; }
    if ($arch_adj)             { $sets[] = 'archivo_adjunto=?'; $params[] = $arch_adj;
                                 $sets[] = 'archivo_nombre=?';  $params[] = $arch_nom; }
    $params[] = $gasto_id;
    $params[] = $cid;

    $pdo->beginTransaction();
    $pdo->prepare("UPDATE gastos SET " . implode(',', $sets) . " WHERE id=? AND cliente_id=?")->execute($params);
    $usuario_id = (int)($_SESSION['usuario_id'] ?? 0);
    // Opcional: el pago sale de una cuenta bancaria (queda como movimiento "pago de gasto")
    $cuentaPago = filter_input(INPUT_POST, 'cuenta_id', FILTER_VALIDATE_INT) ?: null;
    if ($cuentaPago) {
        if ($gastoActual['estado'] === 'pagado') throw new Exception("Este gasto ya está pagado: registrar otra salida del banco lo pagaría dos veces.");
        if (!bancosDisponible($pdo)) throw new Exception("El módulo de bancos no está instalado.");
        $cuenta = bancoCuenta($pdo, $cid, $cuentaPago, true);
        if ($cuenta['moneda'] !== 'HNL') throw new Exception("Los gastos son en lempiras: elige una cuenta en lempiras.");
        bancoInsertarMovimiento($pdo, $cid, $cuentaPago, [
            'fecha' => $fecha, 'sentido' => 'salida', 'tipo' => 'pago_gasto', 'monto' => (float)$gastoActual['monto'],
            'descripcion' => mb_substr('Pago: ' . $gastoActual['descripcion'], 0, 255),
            'referencia' => $factura_ref, 'gasto_id' => $gasto_id, 'usuario_id' => $usuario_id,
        ]);
    }
    // Gasto recurrente: programar el del período siguiente
    $siguiente  = programarSiguienteGastoRecurrente($pdo, $gastoActual, $cid, $usuario_id);
    $pdo->commit();
    // Si se reemplazó el comprobante, borrar el anterior si ya nadie lo usa
    if ($arch_adj && $gastoActual['archivo_adjunto'] !== $arch_adj) borrarAdjuntoGastoSiHuerfano($pdo, $gastoActual['archivo_adjunto']);

    $msg = 'Gasto marcado como pagado.';
    if ($siguiente) $msg .= ' Se programó el siguiente pago para el ' . date('d/m/Y', strtotime($siguiente)) . '.';
    echo json_encode(['success' => true, 'message' => $msg, 'siguiente' => $siguiente]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if ($arch_adj) borrarAdjuntoGastoSiHuerfano($pdo, $arch_adj);
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
