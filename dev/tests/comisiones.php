<?php
// Comisiones por venta: vendedores (colaboradores y externos con ID), comisión en la factura (% del subtotal o fija),
// editable; esperando cobro → por pagar → pagada (gasto + banco); anular pago, quitar, permisos.
suite('Comisiones por venta: en la factura, editables y pagadas como gasto', function () {
    $pdo = db();
    require_once __DIR__ . '/../../includes/comisiones.php';
    $c = login('qa.admin@local.test');
    $g = $c->get('generar_factura');
    check('Nueva factura tiene la sección «Comisión por venta»', sinErroresPhp($g['body']) && str_contains($g['body'], 'id="cmCard"') && str_contains($g['body'], 'Agregar vendedor nuevo'), errorPhp($g['body']));
    $idn = '0801' . rand(100000000, 999999999);
    $r = $c->post('includes/comision_accion.php', ['accion' => 'vendedor_guardar', 'nombre' => 'QA Referidor', 'identidad' => $idn, 'telefono' => '9999-0000']);
    $vid = (int)($r['json']['vendedor']['id'] ?? 0);
    check('se crea un vendedor externo con su ID', $vid > 0 && $pdo->query("SELECT CONCAT(tipo,'|',identidad) FROM vendedores WHERE id = $vid")->fetchColumn() === "externo|$idn", $r['body']);
    $r = $c->post('includes/comision_accion.php', ['accion' => 'vendedor_guardar', 'nombre' => 'Otro', 'identidad' => $idn]);
    check('no repite el número de identidad', !($r['json']['success'] ?? true));
    check('los colaboradores activos aparecen como vendedores', (bool)array_filter(vendedoresLista($pdo, 2), fn($v) => $v['tipo'] === 'colaborador'));

    $cai = $pdo->query("SELECT * FROM cai_rangos WHERE cliente_id=2 AND fecha_limite >= CURDATE() AND correlativo_actual < (rango_fin - rango_inicio + 1) ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $prod = (int)$pdo->query("SELECT id FROM productos_clientes WHERE cliente_id=2 AND tipo_isv = '15' LIMIT 1")->fetchColumn();
    $r = $c->post('guardar_factura.php', ['receptor_id' => 1, 'cai_rango_id' => $cai['id'], 'condicion_pago' => 'Contado', 'establecimiento_id' => $cai['establecimiento_id'],
        'productos[0][id]' => $prod, 'productos[0][cantidad]' => 1, 'productos[0][precio]' => 10000,
        'comision_activa' => 1, 'comision_vendedor_id' => $vid, 'comision_tipo' => 'porcentaje', 'comision_valor' => 10]);
    $fid = (int)($r['json']['factura_id'] ?? 0);
    $cm = comisionDeFactura($pdo, 2, $fid);
    check('la factura guarda la comisión: 10 % de L 10,000 = L 1,000', $cm && (float)$cm['monto'] === 1000.0 && (float)$cm['base'] === 10000.0, $r['body']);
    $vis = fn() => (comisionesLista($pdo, 2, ['desde' => '2000-01-01'])[0] ?? null);
    $l = array_values(array_filter(comisionesLista($pdo, 2, ['desde' => '2000-01-01']), fn($x) => (int)$x['factura_id'] === $fid));
    check('queda «Esperando cobro» mientras la factura no se paga', ($l[0]['vista'] ?? '') === 'esperando');
    $e = $c->get('editar_factura', ['id' => $fid]);
    check('al editar la factura se ve la comisión', sinErroresPhp($e['body']) && str_contains($e['body'], 'id="cmActiva" checked'), errorPhp($e['body']));
    $antes = $pdo->query("SELECT * FROM facturas WHERE id = $fid")->fetch(PDO::FETCH_ASSOC);
    $form = ['factura_id' => $fid, 'receptor_id' => 1, 'fecha_emision' => date('Y-m-d\TH:i', strtotime($antes['fecha_emision'])), 'condicion_pago' => 'Contado', 'estado' => 'emitida',
        'motivo' => 'QA', 'usuario_autoriza' => 'qa.admin@local.test', 'clave_autoriza' => QA_PASS, 'productos[0][id]' => $prod, 'productos[0][cantidad]' => 1, 'productos[0][precio_unitario]' => 20000, 'productos[0][descripcion_html]' => 'QA',
        'comision_activa' => 1, 'comision_vendedor_id' => $vid, 'comision_tipo' => 'porcentaje', 'comision_valor' => 5];
    $c->post('guardar_factura_editada.php', $form);
    check('editar la factura cambia la comisión (5 % de L 20,000 = L 1,000)', (float)comisionDeFactura($pdo, 2, $fid)['monto'] === 1000.0 && (float)comisionDeFactura($pdo, 2, $fid)['valor'] === 5.0);
    $cmid = (int)comisionDeFactura($pdo, 2, $fid)['id'];
    $r = $c->post('includes/comision_accion.php', ['accion' => 'editar', 'id' => $cmid, 'vendedor_id' => $vid, 'tipo' => 'fijo', 'valor' => 750]);
    check('desde Comisiones se edita a cantidad fija (L 750)', ($r['json']['success'] ?? false) && (float)comisionDeFactura($pdo, 2, $fid)['monto'] === 750.0, $r['body']);
    $r = $c->post('includes/comision_accion.php', ['accion' => 'pagar', 'id' => $cmid, 'fecha' => date('Y-m-d'), 'metodo' => 'transferencia']);
    check('no se paga antes de que el cliente pague (sin confirmarlo)', !($r['json']['success'] ?? true));
    $pdo->exec("UPDATE facturas SET pagada = 1 WHERE id = $fid");
    $l = array_values(array_filter(comisionesLista($pdo, 2, ['desde' => '2000-01-01']), fn($x) => (int)$x['factura_id'] === $fid));
    check('pagada la factura, la comisión queda «Por pagar»', ($l[0]['vista'] ?? '') === 'por_pagar');
    $pdo->exec("INSERT INTO cuentas_bancarias (cliente_id, banco, tipo, numero, titular, moneda, saldo_inicial, fecha_saldo_inicial, activa) VALUES (2,'QA','ahorro','QA-COM','QA','HNL',5000,'2020-01-01',1)");
    $cta = (int)$pdo->lastInsertId();
    $f = login('qa.facturador@local.test');
    $r = $f->post('includes/comision_accion.php', ['accion' => 'pagar', 'id' => $cmid, 'fecha' => date('Y-m-d')]);
    check('el facturador no paga comisiones', !($r['json']['success'] ?? true));
    $r = $c->post('includes/comision_accion.php', ['accion' => 'pagar', 'id' => $cmid, 'fecha' => date('Y-m-d'), 'monto' => 750, 'metodo' => 'transferencia', 'cuenta_id' => $cta]);
    $gid = (int)($r['json']['gasto_id'] ?? 0);
    $gs = $pdo->query("SELECT g.*, cg.nombre AS cat FROM gastos g LEFT JOIN categorias_gastos cg ON cg.id = g.categoria_id WHERE g.id = $gid")->fetch(PDO::FETCH_ASSOC);
    $mov = $pdo->query("SELECT sentido, monto FROM movimientos_bancarios WHERE gasto_id = $gid AND anulado = 0")->fetch(PDO::FETCH_ASSOC);
    check('al pagarla se registra el gasto «Comisiones por venta» y sale del banco', $gs && $gs['cat'] === 'Comisiones por venta' && (float)$gs['monto'] === 750.0 && $gs['proveedor'] === 'QA Referidor'
        && $mov && $mov['sentido'] === 'salida', $r['body']);
    $p = $c->get('comisiones', ['desde' => '2000-01-01']);
    check('la página Comisiones la muestra pagada', sinErroresPhp($p['body']) && str_contains($p['body'], 'QA Referidor') && str_contains($p['body'], 'gasto #' . $gid), errorPhp($p['body']));
    $p = $c->get('comision_recibo_pdf', ['id' => $cmid]);
    check('genera el recibo de pago en PDF para firmar', str_starts_with($p['body'], '%PDF'), substr($p['body'], 0, 300));
    $r = $c->post('includes/comision_accion.php', ['accion' => 'anular_pago', 'id' => $cmid]);
    check('anular el pago anula el gasto y su salida, y vuelve a «Por pagar»', ($r['json']['success'] ?? false) && $pdo->query("SELECT estado FROM gastos WHERE id = $gid")->fetchColumn() === 'anulado'
        && !(int)$pdo->query("SELECT COUNT(*) FROM movimientos_bancarios WHERE gasto_id = $gid AND anulado = 0")->fetchColumn() && comisionDeFactura($pdo, 2, $fid)['estado'] === 'pendiente', $r['body']);
    $p = $c->get('comision_recibo_pdf', ['id' => $cmid]);
    check('sin pago no hay recibo', !str_starts_with($p['body'], '%PDF'));
    $r = $c->post('includes/comision_accion.php', ['accion' => 'quitar', 'id' => $cmid]);
    check('se puede quitar la comisión', ($r['json']['success'] ?? false) && !comisionDeFactura($pdo, 2, $fid));

    // Limpieza (la factura de prueba es la última: se borra)
    $c->postJson('procesar_accion_factura.php', ['accion' => 'eliminar', 'factura_id' => $fid, 'motivo' => 'QA', 'usuario_autoriza' => 'qa.admin@local.test', 'clave_autoriza' => QA_PASS]);
    $pdo->exec("DELETE FROM gastos WHERE id = $gid");
    $pdo->exec("DELETE FROM movimientos_bancarios WHERE cuenta_id = $cta");
    $pdo->exec("DELETE FROM cuentas_bancarias WHERE id = $cta");
    $pdo->exec("DELETE FROM vendedores WHERE id = $vid");
});
