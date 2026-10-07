<?php
// clientes/naranjaymedia/includes/contrato_eliminar.php — Eliminar contratos (solo administradores).
//   GET  ?info=1&ids=1,2   → por contrato: facturas, recibos, pagos anticipados y líneas del plan; y a qué contratos se pueden pasar
//   POST ids[], facturas=huerfanas|reasignar, destino_id
//        huerfanas: las facturas quedan sin contrato (siguen en el historial y en cuentas por cobrar)
//        reasignar: facturas, recibos, pagos anticipados y contactos pasan a otro contrato del mismo cliente
// Los recibos y pagos anticipados no pueden quedar sin contrato: si hay, hay que reasignarlos.
// Se borran: el contrato, sus servicios, su plan de pagos y sus empresas rotativas. Queda un respaldo en JSON.
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if (!in_array(USUARIO_ROL, ['admin', 'superadmin'], true)) throw new Exception("Solo un administrador puede eliminar contratos.");
    $cid = (int)cliente_actual();
    if (!$cid) throw new Exception("Empresa no identificada.");
    $ids = array_values(array_unique(array_filter(array_map('intval', $_SERVER['REQUEST_METHOD'] === 'GET' ? explode(',', (string)($_GET['ids'] ?? '')) : (array)($_POST['ids'] ?? [])))));
    if (!$ids) throw new Exception("Selecciona al menos un contrato.");
    if (count($ids) > 50) throw new Exception("Máximo 50 contratos a la vez.");
    $in = implode(',', array_fill(0, count($ids), '?'));
    $cuenta = fn($tabla, $extra = '') => "(SELECT COUNT(*) FROM $tabla x WHERE x.contrato_id = c.id $extra)";
    $st = $pdo->prepare("SELECT c.id, c.nombre_contrato, c.receptor_id, c.estado, c.monto, cf.nombre AS cliente,
                                {$cuenta('facturas')} AS facturas, {$cuenta('contratos_recibos')} AS recibos,
                                {$cuenta('contratos_anticipos')} AS anticipos, {$cuenta('contratos_plan')} AS plan
                         FROM contratos c JOIN clientes_factura cf ON cf.id = c.receptor_id
                         WHERE c.id IN ($in) AND c.cliente_id = ? ORDER BY c.id");
    $st->execute([...$ids, $cid]);
    $contratos = $st->fetchAll(PDO::FETCH_ASSOC);
    if (count($contratos) !== count($ids)) throw new Exception("Algún contrato no existe o es de otra empresa.");
    $receptores = array_unique(array_column($contratos, 'receptor_id'));

    // Contratos a los que se puede pasar todo: del mismo cliente y que no se van a eliminar
    $destinos = [];
    if (count($receptores) === 1) {
        $st = $pdo->prepare("SELECT id, nombre_contrato, estado, monto FROM contratos WHERE cliente_id = ? AND receptor_id = ? AND id NOT IN ($in) ORDER BY estado = 'activo' DESC, id DESC");
        $st->execute([$cid, $receptores[0], ...$ids]);
        $destinos = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode(['success' => true, 'contratos' => $contratos, 'destinos' => $destinos, 'un_cliente' => count($receptores) === 1], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");

    $modo = ($_POST['facturas'] ?? '') === 'reasignar' ? 'reasignar' : 'huerfanas';
    $destino = null;
    if ($modo === 'reasignar') {
        $destino = (int)($_POST['destino_id'] ?? 0);
        if (!in_array($destino, array_map('intval', array_column($destinos, 'id')), true))
            throw new Exception(count($receptores) > 1 ? "Para pasar las facturas a otro contrato, elige contratos de un solo cliente." : "Elige el contrato al que pasan las facturas (del mismo cliente).");
    } else {
        $conCaja = array_filter($contratos, fn($c) => $c['recibos'] > 0 || $c['anticipos'] > 0);
        if ($conCaja) throw new Exception("El contrato #" . implode(', #', array_column($conCaja, 'id')) . " tiene recibos o pagos anticipados: pásalos a otro contrato del mismo cliente (no pueden quedar sin contrato).");
    }

    // Respaldo de todo lo que se borra o se mueve
    $respaldo = ['fecha' => date('c'), 'usuario' => (int)USUARIO_ID, 'modo' => $modo, 'destino' => $destino];
    foreach (['contratos' => 'id', 'contratos_servicios' => 'contrato_id', 'contratos_plan' => 'contrato_id', 'contratos_clientes_rotativos' => 'contrato_id', 'clientes_factura_contactos' => 'contrato_id'] as $t => $col) {
        $q = $pdo->prepare("SELECT * FROM $t WHERE $col IN ($in)");
        $q->execute($ids);
        $respaldo[$t] = $q->fetchAll(PDO::FETCH_ASSOC);
    }
    foreach (['facturas' => 'id', 'contratos_recibos' => 'id', 'contratos_anticipos' => 'id'] as $t => $col) {
        $q = $pdo->prepare("SELECT $col, contrato_id FROM $t WHERE contrato_id IN ($in)");
        $q->execute($ids);
        $respaldo[$t] = $q->fetchAll(PDO::FETCH_ASSOC);
    }

    $pdo->beginTransaction();
    $planIds = array_column($respaldo['contratos_plan'], 'id');
    if ($planIds && $pdo->query("SHOW TABLES LIKE 'cobros_programados_plan'")->fetchColumn())
        $pdo->prepare("DELETE FROM cobros_programados_plan WHERE plan_id IN (" . implode(',', array_map('intval', $planIds)) . ")")->execute();
    if ($modo === 'reasignar') {
        foreach (['facturas', 'contratos_recibos', 'contratos_anticipos', 'clientes_factura_contactos'] as $t)
            $pdo->prepare("UPDATE $t SET contrato_id = ? WHERE contrato_id IN ($in)")->execute([$destino, ...$ids]);
    } else {
        $pdo->prepare("UPDATE facturas SET contrato_id = NULL WHERE contrato_id IN ($in) AND cliente_id = ?")->execute([...$ids, $cid]);
        $pdo->prepare("UPDATE clientes_factura_contactos SET contrato_id = NULL WHERE contrato_id IN ($in)")->execute($ids);
    }
    foreach (['contratos_plan', 'contratos_servicios', 'contratos_clientes_rotativos'] as $t)
        $pdo->prepare("DELETE FROM $t WHERE contrato_id IN ($in)")->execute($ids);
    $pdo->prepare("DELETE FROM contratos WHERE id IN ($in) AND cliente_id = ?")->execute([...$ids, $cid]);
    $pdo->prepare("DELETE FROM proyecciones_cache WHERE cliente_id = ?")->execute([$cid]);   // la proyección se recalcula sin estos contratos
    $pdo->commit();

    $dir = __DIR__ . '/uploads/respaldos_contratos/';
    if (is_dir($dir) || @mkdir($dir, 0775, true)) {
        if (!is_file($dir . '.htaccess')) @file_put_contents($dir . '.htaccess', "Require all denied\nDeny from all\n");   // solo lectura desde el servidor
        @file_put_contents($dir . 'contratos_' . implode('-', $ids) . '_' . date('Ymd_His') . '.json', json_encode($respaldo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    $nFact = array_sum(array_column($contratos, 'facturas'));
    $msg = (count($ids) === 1 ? 'Contrato eliminado.' : count($ids) . ' contratos eliminados.')
        . ($nFact ? ' ' . $nFact . ' factura(s) ' . ($modo === 'reasignar' ? 'pasaron al contrato #' . $destino . '.' : 'quedaron sin contrato (siguen en el historial y en cuentas por cobrar).') : '');
    echo json_encode(['success' => true, 'message' => $msg], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    if ($e instanceof PDOException) error_log('contrato_eliminar.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e instanceof PDOException ? 'Error de base de datos.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
