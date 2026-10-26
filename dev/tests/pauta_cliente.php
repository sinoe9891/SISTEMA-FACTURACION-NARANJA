<?php
suite('Pauta del cliente: métricas manuales, permisos y reportes sin movimientos financieros', function () {
    $pdo = db();
    $adm = login('qa.admin@local.test');
    $op = login('qa.operativo@local.test');
    $tipo = (int)$pdo->query("SELECT id FROM gastos_op_tipos WHERE cliente_id=2 AND es_pauta=1 LIMIT 1")->fetchColumn();
    $base = ['accion'=>'guardar','pagador'=>'cliente','receptor_id'=>1,'tipo_id'=>$tipo,'fecha'=>date('Y-m-d'),'descripcion'=>'QA campaña tarjeta cliente','moneda'=>'USD','monto'=>50,'tasa_cambio'=>26,
      'campana'=>'QA Meta manual','pauta_inicio'=>date('Y-m-01'),'pauta_fin'=>date('Y-m-d'),'tarjeta_cliente_alias'=>'BAC publicidad','tarjeta_cliente_ultimos'=>'1234','pauta_presupuesto'=>100,'pauta_objetivo'=>'Clientes potenciales','pauta_resultado_tipo'=>'Contactos','pauta_resultados'=>10,'pauta_alcance'=>1000,'pauta_impresiones'=>2000,'pauta_clics'=>40,'cobrar_cliente'=>1,'tarjeta_id'=>999999];
    $uid=(int)$pdo->query("SELECT id FROM usuarios WHERE correo='qa.operativo@local.test'")->fetchColumn();
    $existia=(bool)$pdo->query("SELECT id FROM usuarios_clientes WHERE usuario_id=$uid AND receptor_id=1")->fetchColumn();
    if (!$existia) $pdo->exec("INSERT INTO usuarios_clientes (usuario_id,receptor_id) VALUES ($uid,1)");
    $id=0;
    try {
        $r=$op->post('includes/gasto_operativo_accion.php',array_replace($base,['pauta_impresiones'=>-1]));
        check('no acepta métricas negativas', !($r['json']['success']??true));
        $r=$op->post('includes/gasto_operativo_accion.php',array_replace($base,['tarjeta_cliente_ultimos'=>'123456789']));
        check('no acepta números completos de tarjeta', !($r['json']['success']??true));
        $otro=(int)$pdo->query('SELECT id FROM clientes_factura WHERE cliente_id=2 AND id<>1 LIMIT 1')->fetchColumn();
        $r=$op->post('includes/gasto_operativo_accion.php',array_replace($base,['receptor_id'=>$otro]));
        check('Jafeth conserva el alcance de clientes asignados', !($r['json']['success']??true));
        $r=$op->post('includes/gasto_operativo_accion.php',$base);
        $id=(int)($r['json']['id']??0);
        check('registra la campaña con sus métricas', $id>0, $r['body']);
        if (!$id) return;
        $g=$pdo->query("SELECT * FROM gastos_operativos WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
        check('no se cobra al cliente ni usa una tarjeta de la agencia', !(int)$g['cobrar_cliente'] && $g['tarjeta_id']===null && $g['pagador']==='cliente' && (int)$g['pauta_resultados']===10);
        $r=$op->get('gastos_operativos',['pagador'=>'cliente']);
        check('muestra presupuesto, ejecución y costo por resultado',sinErroresPhp($r['body']) && str_contains($r['body'],'50.0%') && str_contains($r['body'],'$ 5.00'),errorPhp($r['body']));
        file_put_contents('/tmp/pauta-cliente-preview.html',$r['body']);
        $r=$op->get('gastos_operativos');
        check('la campaña no se mezcla con gastos de la agencia',!str_contains($r['body'],'QA campaña tarjeta cliente'));
        $r=$op->get('gastos_operativos',['pagador'=>'cliente','tarjeta_cliente'=>'9999']);
        check('filtra por tarjeta del cliente',!str_contains($r['body'],'QA campaña tarjeta cliente'));
        foreach(['pdf'=>'%PDF','xlsx'=>'PK'] as $formato=>$firma) {
            $r=$op->get('gastos_operativos_exportar',['pagador'=>'cliente','formato'=>$formato]);
            check("exporta $formato",$r['code']===200 && str_starts_with($r['body'],$firma),substr($r['body'],0,200));
            file_put_contents('/tmp/pauta-cliente.'.$formato,$r['body']);
        }
        $r=login('qa.ccic@local.test',3)->get('includes/gasto_operativo_accion.php',['ver'=>$id]);
        check('otra empresa no puede leer la campaña',!($r['json']['success']??true));
        $r=$op->post('includes/gasto_operativo_accion.php',array_replace($base,['id'=>$id,'monto'=>0,'pauta_resultados'=>0,'pauta_alcance'=>'']));
        check('permite registrar cero ejecutado y métricas aún no disponibles', $r['json']['success']??false,$r['body']);
        $g=$pdo->query("SELECT * FROM gastos_operativos WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
        check('distingue cero de sin registrar', (int)$g['pauta_resultados']===0 && $g['pauta_alcance']===null);
        $r=$op->post('includes/gasto_operativo_accion.php',array_replace($base,['id'=>$id,'pagador'=>'empresa']));
        check('no permite convertirla en gasto de agencia',!($r['json']['success']??true));
        $antes=(int)$pdo->query('SELECT COUNT(*) FROM gastos WHERE cliente_id=2')->fetchColumn();
        $r=$adm->post('includes/gasto_operativo_accion.php',['accion'=>'aprobar','id'=>$id,'registro'=>'nuevo']);
        $g=$pdo->query("SELECT * FROM gastos_operativos WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
        check('aprobar no crea gasto aunque se fuerce la solicitud',($r['json']['success']??false) && $g['estado']==='aprobado' && $g['gasto_id']===null && $antes===(int)$pdo->query('SELECT COUNT(*) FROM gastos WHERE cliente_id=2')->fetchColumn(),$r['body']);
    } finally {
        if (!$existia) $pdo->exec("DELETE FROM usuarios_clientes WHERE usuario_id=$uid AND receptor_id=1");
        if($id) $pdo->exec("DELETE FROM gastos_operativos WHERE id=$id AND cliente_id=2");
    }
});
