<?php
// Comprobante opcional de cheques, préstamos recibidos y activos fijos: al registrar, después (cambiar / quitar), ver y permisos.
suite('Comprobantes de cheques, préstamos recibidos y activos fijos', function () {
    $pdo = db();
    $f = fn($sql) => $pdo->query($sql)->fetchColumn();
    $c = login('qa.admin@local.test');
    $o = login('qa.ccic@local.test');

    // Cheque: cuenta de cheques propia de la prueba
    $pdo->exec("INSERT INTO cuentas_bancarias (cliente_id, banco, tipo, numero, moneda, saldo_inicial, fecha_saldo_inicial, activa, predeterminada) VALUES (2, 'QA Banco Cheques', 'cheques', 'QA0000099', 'HNL', 10000, '2026-10-03', 1, 0)");
    $cta = (int)$pdo->lastInsertId();
    $r = $c->post('includes/banco_accion.php', ['accion' => 'cheque_emitir', 'cuenta_id' => $cta, 'numero' => '9001', 'fecha_emision' => date('Y-m-d'), 'beneficiario' => 'QA Proveedor', 'monto' => 100, 'concepto' => 'QA',
        'comprobante' => new CURLFile(archivoTemporal('png'), 'image/png', 'cheque.png')]);
    $ch1 = (int)($r['json']['id'] ?? 0);
    check('un cheque guarda su comprobante al emitirse', $ch1 && $f("SELECT archivo_adjunto FROM cheques WHERE id = $ch1"), $r['body']);
    $v = $c->get('comprobante_archivo', ['tipo' => 'cheque', 'id' => $ch1]);
    check('el comprobante del cheque se puede ver', $v['code'] === 200 && str_contains($v['head'], 'image/png'), $v['head']);
    check('otra empresa no ve el comprobante', $o->get('comprobante_archivo', ['tipo' => 'cheque', 'id' => $ch1])['code'] === 404);
    $r = $c->post('includes/banco_accion.php', ['accion' => 'cheque_emitir', 'cuenta_id' => $cta, 'numero' => '9002', 'fecha_emision' => date('Y-m-d'), 'beneficiario' => 'QA Proveedor', 'monto' => 50]);
    $ch2 = (int)($r['json']['id'] ?? 0);
    check('el comprobante del cheque es opcional', $ch2 && $f("SELECT archivo_adjunto FROM cheques WHERE id = $ch2") === null, $r['body']);
    $antes = (int)$f("SELECT COUNT(*) FROM cheques WHERE cuenta_id = $cta");
    $r = $c->post('includes/banco_accion.php', ['accion' => 'cheque_emitir', 'cuenta_id' => $cta, 'numero' => '9003', 'fecha_emision' => date('Y-m-d'), 'beneficiario' => 'QA', 'monto' => 10,
        'comprobante' => new CURLFile(archivoTemporal('txt'), 'text/plain', 'malo.php')]);
    check('un archivo que no es imagen ni PDF se rechaza y no se emite el cheque', empty($r['json']['success']) && (int)$f("SELECT COUNT(*) FROM cheques WHERE cuenta_id = $cta") === $antes, $r['body']);

    // Adjuntar después, cambiar y quitar
    $r = $c->post('includes/comprobante_accion.php', ['tipo' => 'cheque', 'id' => $ch2, 'comprobante' => new CURLFile(archivoTemporal('pdf'), 'application/pdf', 'cheque.pdf')]);
    check('se adjunta el comprobante a un cheque ya emitido', !empty($r['json']['success']) && $f("SELECT archivo_adjunto FROM cheques WHERE id = $ch2"), $r['body']);
    $r = $c->post('includes/comprobante_accion.php', ['tipo' => 'cheque', 'id' => $ch2]);
    check('adjuntar sin elegir archivo avisa', empty($r['json']['success']), $r['body']);
    $r = $c->post('includes/comprobante_accion.php', ['tipo' => 'cheque', 'id' => $ch2, 'quitar_comprobante' => 1]);
    check('se quita el comprobante', !empty($r['json']['success']) && $f("SELECT archivo_adjunto FROM cheques WHERE id = $ch2") === null, $r['body']);
    check('otra empresa no puede cambiar el comprobante', empty($o->post('includes/comprobante_accion.php', ['tipo' => 'cheque', 'id' => $ch1, 'quitar_comprobante' => 1])['json']['success']) && $f("SELECT archivo_adjunto FROM cheques WHERE id = $ch1") !== null);
    check('un tipo inválido se rechaza', empty($c->post('includes/comprobante_accion.php', ['tipo' => 'usuarios', 'id' => 1, 'quitar_comprobante' => 1])['json']['success']));
    $pg = $c->get('cheques');
    check('la página de cheques ofrece ver y adjuntar', sinErroresPhp($pg['body']) && str_contains($pg['body'], 'btn-comp') && str_contains($pg['body'], 'comprobante_archivo?tipo=cheque'), errorPhp($pg['body']));

    // Activo fijo (con la compra pagada: el comprobante queda también en su gasto)
    $r = $c->post('includes/activo_accion.php', ['accion' => 'activo_guardar', 'nombre' => 'QA Laptop comprobante', 'categoria' => 'equipo_computo', 'fecha_compra' => date('Y-m-d'), 'costo' => 5000, 'valor_residual' => 0,
        'vida_util_meses' => 36, 'origen' => 'compra', 'registrar_compra' => 1,
        'comprobante' => new CURLFile(archivoTemporal('pdf'), 'application/pdf', 'factura.pdf')]);
    $ac = (int)($r['json']['id'] ?? 0);
    $arch = $ac ? $f("SELECT archivo_adjunto FROM activos_fijos WHERE id = $ac") : null;
    check('un activo guarda el comprobante de su compra y también en su gasto', $arch && $f("SELECT archivo_adjunto FROM gastos WHERE activo_id = $ac LIMIT 1") === $arch, $r['body']);
    check('el comprobante del activo se puede ver', ($v = $c->get('comprobante_archivo', ['tipo' => 'activo', 'id' => $ac]))['code'] === 200 && str_contains($v['head'], 'application/pdf'), $v['head'] ?? '');

    // Préstamo recibido
    $r = $c->post('includes/activo_accion.php', ['accion' => 'prestamo_guardar', 'acreedor' => 'QA Prestamista', 'fecha' => date('Y-m-d'), 'monto' => 1000, 'num_cuotas' => 1, 'tasa_anual' => 0,
        'comprobante' => new CURLFile(archivoTemporal('png'), 'image/png', 'contrato.png')]);
    $pr = (int)($r['json']['id'] ?? 0) ?: (int)$f("SELECT id FROM prestamos_recibidos WHERE acreedor = 'QA Prestamista' ORDER BY id DESC LIMIT 1");
    check('un préstamo recibido guarda su comprobante', $pr && $f("SELECT archivo_adjunto FROM prestamos_recibidos WHERE id = $pr"), $r['body']);
    $r = $c->post('includes/comprobante_accion.php', ['tipo' => 'prestamo', 'id' => $pr, 'quitar_comprobante' => 1]);
    check('se quita el comprobante del préstamo', !empty($r['json']['success']) && $f("SELECT archivo_adjunto FROM prestamos_recibidos WHERE id = $pr") === null, $r['body']);
    $pg = $c->get('activos');
    check('la página de activos y préstamos ofrece adjuntar', sinErroresPhp($pg['body']) && str_contains($pg['body'], 'btn-comp') && str_contains($pg['body'], 'name="comprobante"'), errorPhp($pg['body']));
    $fac = login('qa.facturador@local.test');
    check('un rol sin permiso no cambia el comprobante del activo', empty($fac->post('includes/comprobante_accion.php', ['tipo' => 'activo', 'id' => $ac, 'quitar_comprobante' => 1])['json']['success']) && $f("SELECT archivo_adjunto FROM activos_fijos WHERE id = $ac") !== null);

    // Licencias: comprobante de la última renovación pagada (vive en su gasto)
    $pdo->exec("INSERT INTO licencias (cliente_id, nombre, frecuencia, moneda, costo, proxima_renovacion, activa) VALUES (2, 'QA Licencia comprobante', 'anual', 'HNL', 100, '2027-01-01', 1)");
    $lic = (int)$pdo->lastInsertId();
    $pdo->exec("INSERT INTO gastos (cliente_id, descripcion, monto, fecha, frecuencia, tipo, metodo_pago, estado, licencia_id) VALUES (2, 'QA renovación', 100, '2026-05-01', 'unico', 'fijo', 'tarjeta', 'pagado', $lic)");
    $gl = (int)$pdo->lastInsertId();
    $pg = $c->get('licencias');
    check('Licencias avisa cuando falta el comprobante de la última renovación pagada', sinErroresPhp($pg['body']) && str_contains($pg['body'], 'data-tipo="gasto" data-id="' . $gl . '" data-tiene="0"') && str_contains($pg['body'], 'Falta el comprobante'), errorPhp($pg['body']));
    $r = $c->post('includes/comprobante_accion.php', ['tipo' => 'gasto', 'id' => $gl, 'comprobante' => new CURLFile(archivoTemporal('pdf'), 'application/pdf', 'cargo.pdf')]);
    check('se adjunta el comprobante a la renovación desde Licencias (queda en su gasto)', !empty($r['json']['success']) && $f("SELECT archivo_adjunto FROM gastos WHERE id = $gl"), $r['body']);
    $pg = $c->get('licencias');
    check('con comprobante sale el clip para verlo', str_contains($pg['body'], 'gasto_archivo?id=' . $gl) && str_contains($pg['body'], 'data-tipo="gasto" data-id="' . $gl . '" data-tiene="1"'));
    check('el comprobante del gasto se abre con su visor', ($v = $c->get('gasto_archivo', ['id' => $gl]))['code'] === 200 && str_contains($v['head'], 'application/pdf'), $v['head'] ?? '');
    check('otra empresa no puede cambiar el comprobante de ese gasto', empty($o->post('includes/comprobante_accion.php', ['tipo' => 'gasto', 'id' => $gl, 'quitar_comprobante' => 1])['json']['success']) && $f("SELECT archivo_adjunto FROM gastos WHERE id = $gl") !== null);
    $pdo->exec("DELETE FROM gastos WHERE licencia_id = $lic");   // incluye el pendiente que Licencias programa sola
    $pdo->exec("DELETE FROM licencias WHERE id = $lic");

    // limpieza
    $pdo->exec("DELETE FROM gastos WHERE activo_id = $ac");
    $pdo->exec("DELETE FROM activos_fijos WHERE id = $ac");
    $pdo->exec("DELETE FROM gastos WHERE prestamo_id = $pr");
    $pdo->exec("DELETE FROM prestamos_recibidos WHERE id = $pr");
    $pdo->exec("DELETE FROM movimientos_bancarios WHERE cuenta_id = $cta");
    $pdo->exec("DELETE FROM cheques WHERE cuenta_id = $cta");
    $pdo->exec("DELETE FROM cuentas_bancarias WHERE id = $cta");
});
