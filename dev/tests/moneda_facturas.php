<?php
// Facturas y cotizaciones con precios en dólares: la factura queda en lempiras con la tasa y guarda la referencia en USD.
suite('Moneda: facturas con precios en dólares y cotizaciones en USD', function () {
    $pdo = db();
    $c = login('qa.admin@local.test');
    $cai = $pdo->query("SELECT * FROM cai_rangos WHERE cliente_id=2 AND fecha_limite >= CURDATE() AND correlativo_actual < (rango_fin - rango_inicio + 1) ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    check('hay un CAI vigente para probar', (bool)$cai);
    if (!$cai) return;
    $pdo->exec("INSERT INTO productos_clientes (cliente_id, receptores_id, nombre, descripcion, precio, tipo_isv, precio_fijo) VALUES (2, 1, 'QA servicio USD', 'qa', 2650, '15', 0)");
    $pid = (int)$pdo->lastInsertId();
    $base = ['receptor_id' => 1, 'cai_rango_id' => $cai['id'], 'condicion_pago' => 'Contado', 'establecimiento_id' => $cai['establecimiento_id'],
        'productos[0][id]' => $pid, 'productos[0][cantidad]' => 2, 'productos[0][precio]' => 100, 'productos[0][detalles]' => 'QA USD'];

    $g = $c->get('generar_factura');
    check('Nueva factura ofrece la moneda y la tasa', sinErroresPhp($g['body']) && str_contains($g['body'], 'id="fMoneda"') && str_contains($g['body'], 'id="fTasa"'), errorPhp($g['body']));
    $r = $c->post('guardar_factura.php', $base + ['moneda' => 'USD', 'tasa_cambio' => '']);
    check('en dólares sin tasa no se emite', !($r['json']['success'] ?? true) && str_contains($r['json']['error'] ?? '', 'tasa'), $r['body']);
    $r = $c->post('guardar_factura.php', $base + ['moneda' => 'USD', 'tasa_cambio' => '26.5']);
    $fid = (int)($r['json']['factura_id'] ?? 0);
    $f = $pdo->query("SELECT * FROM facturas WHERE id = $fid")->fetch(PDO::FETCH_ASSOC);
    $it = $pdo->query("SELECT * FROM factura_items_receptor WHERE factura_id = $fid")->fetch(PDO::FETCH_ASSOC);
    check('USD 100 × 26.50 = L 2,650 por unidad; total en lempiras con ISV (L 6,095.00)', $f && (float)$it['precio_unitario'] === 2650.0 && (float)$f['subtotal'] === 5300.0 && (float)$f['total'] === 6095.0, $r['body']);
    check('guarda la moneda, la tasa, el total en USD (230.00) y el precio USD de la línea', $f['moneda'] === 'USD' && (float)$f['tasa_cambio'] === 26.5 && (float)$f['total_usd'] === 230.0 && (float)$it['precio_usd'] === 100.0, json_encode([$f['moneda'], $f['tasa_cambio'], $f['total_usd'], $it['precio_usd']]));
    $v = $c->get('ver_factura', ['id' => $fid]);
    check('la factura muestra el total en dólares y la tasa', sinErroresPhp($v['body']) && str_contains($v['body'], 'Valores en dólares') && str_contains($v['body'], 'USD 230.00') && str_contains($v['body'], '26.5000'), errorPhp($v['body']));
    $usd = $c->get('ver_factura', ['id'=>$fid, 'vista_moneda'=>'USD', 'render_only'=>1]);
    check('vista USD completa con precio, subtotal, ISV, total y letras', sinErroresPhp($usd['body']) && str_contains($usd['body'], 'USD 100.00') && str_contains($usd['body'], 'USD 200.00') && str_contains($usd['body'], 'USD 30.00') && str_contains($usd['body'], 'USD 230.00') && str_contains($usd['body'], 'dólares estadounidenses'));
    $hnl = $c->get('ver_factura', ['id'=>$fid, 'vista_moneda'=>'HNL', 'render_only'=>1]);
    check('vista HNL conserva valores completos', sinErroresPhp($hnl['body']) && str_contains($hnl['body'], 'L 2,650.00') && str_contains($hnl['body'], 'L 6,095.00'));
    $pdf = $c->get('ver_factura', ['id'=>$fid, 'vista_moneda'=>'USD', 'pdf_bytes'=>1]);
    check('PDF USD válido', $pdf['code'] === 200 && str_starts_with($pdf['body'], '%PDF-'));
    check('ver ambas monedas no modifica el total almacenado', (float)$pdo->query("SELECT total FROM facturas WHERE id=$fid")->fetchColumn() === 6095.0);
    $e = $c->get('editar_factura', ['id' => $fid]);
    check('al editarla viene en dólares con su tasa y el precio en USD', sinErroresPhp($e['body']) && str_contains($e['body'], '<option value="USD" selected') && str_contains($e['body'], 'value="26.5"') && str_contains($e['body'], 'value="100"'), errorPhp($e['body']));

    $form = ['factura_id' => $fid, 'receptor_id' => 1, 'fecha_emision' => date('Y-m-d\TH:i', strtotime($f['fecha_emision'])), 'condicion_pago' => 'Contado', 'estado' => 'emitida',
        'motivo' => 'QA', 'usuario_autoriza' => 'qa.admin@local.test', 'clave_autoriza' => QA_PASS,
        'productos[0][id]' => $pid, 'productos[0][cantidad]' => 1, 'productos[0][precio_unitario]' => 100, 'productos[0][descripcion_html]' => 'QA'];
    $c->post('guardar_factura_editada.php', $form + ['moneda' => 'USD', 'tasa_cambio' => '27']);
    $f = $pdo->query("SELECT * FROM facturas WHERE id = $fid")->fetch(PDO::FETCH_ASSOC);
    check('editada en dólares con otra tasa: L 2,700 + ISV y USD 115.00', (float)$f['subtotal'] === 2700.0 && (float)$f['total'] === 3105.0 && (float)$f['total_usd'] === 115.0 && (float)$f['tasa_cambio'] === 27.0, json_encode($f));
    $c->post('guardar_factura_editada.php', array_merge($form, ['productos[0][precio_unitario]' => 2000]) + ['moneda' => 'HNL']);
    $f = $pdo->query("SELECT * FROM facturas WHERE id = $fid")->fetch(PDO::FETCH_ASSOC);
    $it = $pdo->query("SELECT * FROM factura_items_receptor WHERE factura_id = $fid")->fetch(PDO::FETCH_ASSOC);
    check('pasada a lempiras: sin tasa ni referencia en USD', $f['moneda'] === 'HNL' && $f['tasa_cambio'] === null && $f['total_usd'] === null && $it['precio_usd'] === null && (float)$f['subtotal'] === 2000.0);
    $borrar = $c->postJson('procesar_accion_factura.php', ['accion' => 'eliminar', 'factura_id' => $fid, 'motivo' => 'QA', 'usuario_autoriza' => 'qa.admin@local.test', 'clave_autoriza' => QA_PASS]);
    check('se borra la factura de prueba (era la última)', ($borrar['json']['success'] ?? false), $borrar['body']);

    // Cotización en dólares
    $q = $c->req('POST', $c->base . 'includes/cotizacion_accion.php', http_build_query(['accion' => 'guardar', 'receptor_id' => 1, 'moneda' => 'USD', 'fecha' => date('Y-m-d'),
        'valida_hasta' => date('Y-m-d', strtotime('+15 days')), 'items' => [['producto_id' => $pid, 'cantidad' => 1, 'precio' => 500]]]));
    $cid = (int)($q['json']['id'] ?? 0);
    check('la cotización se guarda en dólares', $cid && $pdo->query("SELECT moneda FROM cotizaciones WHERE id = $cid")->fetchColumn() === 'USD', $q['body']);
    $vc = $c->post('includes/cotizacion_accion.php', ['accion' => 'vista_correo', 'id' => $cid, 'mensaje' => '<p>Hola</p>']);
    check('el correo muestra el total en dólares ($ 575.00)', str_contains($vc['json']['html'] ?? '', '$ 575.00'), substr($vc['body'], 0, 200));
    $l = $c->get('cotizaciones');
    check('la lista muestra el total con $', sinErroresPhp($l['body']) && str_contains($l['body'], '$ 575.00'), errorPhp($l['body']));
    $pdf = $c->get('cotizacion_pdf', ['id' => $cid]);
    check('el PDF se genera', $pdf['code'] === 200 && str_starts_with($pdf['body'], '%PDF'));
    $gf = $c->get('generar_factura', ['cotizacion_id' => $cid]);
    check('al facturarla la factura nueva viene en dólares', sinErroresPhp($gf['body']) && str_contains($gf['body'], '<option value="USD" selected'), errorPhp($gf['body']));
    $pdo->exec("DELETE FROM cotizaciones WHERE id = $cid");
    $pdo->exec("DELETE FROM productos_clientes WHERE id = $pid");
});
