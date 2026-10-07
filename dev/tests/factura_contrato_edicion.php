<?php
suite('Asociación de contrato al editar factura', function () {
    $pdo=db(); $c=login('qa.admin@local.test');
    $f=$pdo->query("SELECT * FROM facturas WHERE cliente_id=2 AND estado='emitida' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $prod=(int)$pdo->query('SELECT id FROM productos_clientes WHERE cliente_id=2 LIMIT 1')->fetchColumn();
    $rid=(int)$f['receptor_id'];
    $otro=(int)$pdo->query("SELECT id FROM clientes_factura WHERE cliente_id=2 AND id<>$rid LIMIT 1")->fetchColumn();
    unset($f['id']); $f['correlativo']='QA-'.bin2hex(random_bytes(5)); $f['contrato_id']=null; $f['estado_declarada']=0;
    $pdo->prepare('INSERT INTO facturas (`'.implode('`,`',array_keys($f)).'`) VALUES ('.implode(',',array_fill(0,count($f),'?')).')')->execute(array_values($f));
    $fid=(int)$pdo->lastInsertId(); $cts=[];
    try {
        foreach ([$rid,$otro] as $r) {
            $pdo->prepare("INSERT INTO contratos (cliente_id,receptor_id,nombre_contrato,producto_id,monto,fecha_inicio,dia_pago,estado,tipo_contrato) VALUES (2,?,'QA asociación',?,100,'2026-01-01',1,'activo','estandar')")->execute([$r,$prod]);
            $cts[]=(int)$pdo->lastInsertId();
        }
        $base=['factura_id'=>$fid,'receptor_id'=>$rid,'fecha_emision'=>'2026-10-07T08:00','condicion_pago'=>'Credito','estado'=>'emitida','motivo'=>'QA asociación', 'usuario_autoriza'=>'qa.admin@local.test','clave_autoriza'=>QA_PASS,'productos[0][id]'=>$prod,'productos[0][cantidad]'=>1,'productos[0][precio_unitario]'=>100];
        $valor=fn()=>$pdo->query("SELECT contrato_id FROM facturas WHERE id=$fid")->fetchColumn();
        $r=$c->post('guardar_factura_editada.php',$base+['contrato_id'=>$cts[0]]);
        check('vincula contrato del receptor', (int)$valor()===$cts[0],$r['body']);
        $pg=$c->get('editar_factura',['id'=>$fid]);
        check('muestra selector opcional y contrato seleccionado', sinErroresPhp($pg['body']) && str_contains($pg['body'],'id="contratoSelect"') && str_contains($pg['body'],'value="'.$cts[0].'" selected'), errorPhp($pg['body']));
        file_put_contents('/tmp/factura-editar-test.html',$pg['body']);
        $total=$pdo->query("SELECT total FROM facturas WHERE id=$fid")->fetchColumn();
        $r=$c->post('guardar_factura_editada.php',$base+['contrato_id'=>$cts[1]]);
        check('rechaza contrato de otro receptor sin alterar asociación', (int)$valor()===$cts[0] && str_contains($r['body'],'no corresponde'));
        $r=$c->post('guardar_factura_editada.php',$base);
        check('formulario antiguo conserva contrato al omitir el campo', (int)$valor()===$cts[0]);
        $r=$c->post('guardar_factura_editada.php',$base+['contrato_id'=>'']);
        check('sin contrato desvincula manteniendo importes', $valor()===null && $pdo->query("SELECT total FROM facturas WHERE id=$fid")->fetchColumn()===$total);
        $det=$pdo->query("SELECT detalles FROM bitacora_facturas WHERE factura_id=$fid ORDER BY id DESC LIMIT 1")->fetchColumn();
        check('cambio registrado en bitácora', isset(json_decode($det,true)['contrato']));
        $pdo->prepare("UPDATE contratos SET tipo_contrato='rotativo' WHERE id=?")->execute([$cts[1]]);
        $pdo->prepare('INSERT INTO contratos_clientes_rotativos (contrato_id,receptor_id,monto,orden,activo) VALUES (?,?,100,1,1)')->execute([$cts[1],$rid]);
        $r=$c->post('guardar_factura_editada.php',$base+['contrato_id'=>$cts[1]]);
        check('acepta receptor de un turno rotativo', (int)$valor()===$cts[1],$r['body']);
        $pdo->prepare("INSERT INTO contratos_plan (cliente_id,contrato_id,fecha,concepto,monto,isv,total,tipo,orden,factura_id) VALUES (2,?,'2026-10-07','QA',100,0,100,'cuota',1,?)")->execute([$cts[1],$fid]);
        $r=$c->post('guardar_factura_editada.php',$base+['contrato_id'=>'']);
        check('no desvincula si hay pagos del plan asociados', (int)$valor()===$cts[1] && str_contains($r['body'],'pagos del plan'));
    } finally {
        $pdo->prepare('DELETE FROM contratos_plan WHERE factura_id=?')->execute([$fid]);
        foreach ($cts as $ct) { $pdo->prepare('DELETE FROM contratos_clientes_rotativos WHERE contrato_id=?')->execute([$ct]); }
        $pdo->prepare('DELETE FROM bitacora_facturas WHERE factura_id=?')->execute([$fid]);
        $pdo->prepare('DELETE FROM factura_items_receptor WHERE factura_id=?')->execute([$fid]);
        $pdo->prepare('DELETE FROM facturas WHERE id=?')->execute([$fid]);
        foreach ($cts as $ct) $pdo->prepare('DELETE FROM contratos WHERE id=?')->execute([$ct]);
    }
});
