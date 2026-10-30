<?php
suite('Editar factura: gastos de cobrarle al cliente', function () {
    $pdo = db(); $adm = login('qa.admin@local.test');
    require_once __DIR__ . '/../../includes/gastos_cobrar.php';
    $f = $pdo->query("SELECT id, receptor_id FROM facturas WHERE cliente_id = 2 AND estado = 'emitida' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    check('hay factura de prueba', (bool)$f); if (!$f) return;
    $ins = $pdo->prepare("INSERT INTO gastos (cliente_id, descripcion, monto, fecha, frecuencia, tipo, metodo_pago, estado, cobrar_receptor_id) VALUES (2, ?, ?, CURDATE(), 'unico', 'variable', 'efectivo', 'pagado', ?)");
    $ins->execute(['QA hosting por cobrar', 500, $f['receptor_id']]); $g1 = (int)$pdo->lastInsertId();
    $ins->execute(['QA dominio por cobrar', 300, $f['receptor_id']]); $g2 = (int)$pdo->lastInsertId();
    try {
        $p = $adm->get('editar_factura', ['id' => $f['id']]);
        check('editar factura muestra los gastos pendientes de cobrarle', sinErroresPhp($p['body']) && str_contains($p['body'], 'QA hosting por cobrar') && str_contains($p['body'], 'name="gastos_cobrar_form"'), errorPhp($p['body']));
        $pdo->beginTransaction();
        gastosSincronizarCobrados($pdo, 2, (int)$f['id'], (int)$f['receptor_id'], [$g1, $g2]);
        $cob = fn($id) => (int)$pdo->query("SELECT COALESCE(cobrado_factura_id, 0) FROM gastos WHERE id = $id")->fetchColumn();
        check('al guardar quedan cobrados en la factura los marcados', $cob($g1) === (int)$f['id'] && $cob($g2) === (int)$f['id']);
        gastosSincronizarCobrados($pdo, 2, (int)$f['id'], (int)$f['receptor_id'], [$g1]);
        check('el que se desmarca vuelve a quedar pendiente', $cob($g1) === (int)$f['id'] && $cob($g2) === 0);
        $pdo->commit();
        $p = $adm->get('editar_factura', ['id' => $f['id']]);
        check('el cobrado sale marcado al volver a editar', preg_match('/value="' . $g1 . '"[^>]*checked/', $p['body']) === 1 && preg_match('/value="' . $g2 . '"[^>]*checked/', $p['body']) === 0);
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->exec("DELETE FROM gastos WHERE id IN ($g1, $g2)");
    }
});
