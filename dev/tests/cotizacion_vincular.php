<?php
suite('Cotizaciones: vincular factura existente en otra moneda', function () {
    $pdo=db();$adm=login('qa.admin@local.test');
    $f=$pdo->query("SELECT f.id,f.receptor_id FROM facturas f WHERE f.cliente_id=2 AND f.estado='emitida' AND f.moneda='HNL' AND NOT EXISTS(SELECT 1 FROM cotizaciones c WHERE c.factura_id=f.id) LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    check('hay factura de prueba disponible',(bool)$f);if(!$f)return;
    $uid=(int)$pdo->query("SELECT id FROM usuarios WHERE correo='qa.admin@local.test'")->fetchColumn();
    require_once __DIR__.'/../../includes/cotizaciones.php';
    $pid=(int)$pdo->query('SELECT id FROM productos_clientes WHERE cliente_id=2 LIMIT 1')->fetchColumn();
    $id=cotizacionGuardar($pdo,2,$uid,['receptor_id'=>$f['receptor_id'],'fecha'=>date('Y-m-d'),'valida_hasta'=>date('Y-m-d',strtotime('+10 days')),'moneda'=>'USD','items'=>[['producto_id'=>$pid,'descripcion'=>'QA proyecto USD','cantidad'=>1,'precio'=>2000]]]);
    try {
        $r=$adm->get('includes/cotizacion_accion.php',['facturas'=>$id]);
        check('ofrece solo facturas del mismo cliente',($r['json']['success']??false) && in_array((int)$f['id'],array_map('intval',array_column($r['json']['facturas']??[],'id'))),$r['body']);
        $r=$adm->get('cotizacion_editar',['id'=>$id]);
        check('editar ofrece vincular',sinErroresPhp($r['body']) && str_contains($r['body'],'cot-vincular'),errorPhp($r['body']));
        $otro=(int)$pdo->query('SELECT id FROM facturas WHERE cliente_id=2 AND receptor_id<>'.(int)$f['receptor_id'].' LIMIT 1')->fetchColumn();
        $r=$adm->post('includes/cotizacion_accion.php',['accion'=>'vincular_factura','id'=>$id,'factura_id'=>$otro]);
        check('rechaza factura de otro cliente',!($r['json']['success']??true));
        $r=login('qa.ccic@local.test',3)->post('includes/cotizacion_accion.php',['accion'=>'vincular_factura','id'=>$id,'factura_id'=>$f['id']]);
        check('rechaza otra empresa',!($r['json']['success']??true));
        $antes=cotizacionObtener($pdo,2,$id);
        $r=$adm->post('includes/cotizacion_accion.php',['accion'=>'vincular_factura','id'=>$id,'factura_id'=>$f['id']]);
        check('vincula factura en HNL',($r['json']['success']??false),$r['body']);
        $c=cotizacionObtener($pdo,2,$id);
        check('conserva moneda USD y total originales',$c['moneda']==='USD' && $c['total']===$antes['total'] && (int)$c['factura_id']===(int)$f['id'] && $c['factura_moneda']==='HNL');
        $r=$adm->post('includes/cotizacion_accion.php',['accion'=>'vincular_factura','id'=>$id,'factura_id'=>$f['id']]);
        check('evita vincular dos veces',!($r['json']['success']??true));
        $r=$adm->get('cotizaciones');
        check('lista muestra moneda de la factura',sinErroresPhp($r['body']) && str_contains($r['body'],'Factura en HNL'),errorPhp($r['body']));
        $r=$adm->get('cotizacion_editar',['id'=>$id]);
        check('explica anticipo y conservación del original',sinErroresPhp($r['body']) && str_contains($r['body'],'puede corresponder a un anticipo'),errorPhp($r['body']));
    } finally {$pdo->exec("DELETE FROM cotizaciones WHERE id=$id");}
});
