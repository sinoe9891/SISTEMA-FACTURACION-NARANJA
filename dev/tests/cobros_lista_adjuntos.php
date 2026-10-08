<?php
suite('Cobros lista y adjuntos editables', function () {
    $pdo = db();
    require_once __DIR__ . '/../../includes/cobros.php';
    require_once __DIR__ . '/../../includes/documentos.php';
    $cli = login('qa.admin@local.test');
    $tag = 'QA-COBROS-' . bin2hex(random_bytes(5));
    $rid = (int)$pdo->query('SELECT id FROM clientes_factura WHERE cliente_id=2 LIMIT 1')->fetchColumn();
    $pdo->prepare("INSERT INTO clientes_saas (nombre,alias,direccion,subdominio) VALUES (?,?,'QA',?)")->execute([$tag,$tag,$tag]);
    $cidOtro=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO clientes_factura (cliente_id,nombre) VALUES (?,?)")->execute([$cidOtro,$tag]);
    $ridOtro = (int)$pdo->lastInsertId();
    $ids = $docs = $archivos = [];
    $ins = $pdo->prepare("INSERT INTO cobros_programados (cliente_id,receptor_id,asunto,para,mensaje_html,programado_para,estado) VALUES (?,?,?,'qa@ejemplo.test','Mensaje QA','2099-01-01 08:00:00',?)");
    try {
        for ($n=0; $n<305; $n++) {
            $ins->execute([2,$rid,$tag . ' ' . $n,'programado']);
            $ids[] = (int)$pdo->lastInsertId();
        }
        $id = $ids[0];
        $ins->execute([$cidOtro,$ridOtro,$tag,'programado']); $otro = (int)$pdo->lastInsertId(); $ids[]=$otro;
        $ins->execute([2,$rid,$tag . ' enviado','enviado']); $enviado = (int)$pdo->lastInsertId(); $ids[]=$enviado;
        $list = $cli->get('cobros_programados.php',['ajax'=>1,'q'=>$tag,'estado'=>'programado','por_pagina'=>300]);
        check('busca todo el historial y permite 300 por página', ($list['json']['total'] ?? 0) === 305 && ($list['json']['paginas'] ?? 0) === 2 && substr_count($list['json']['html'] ?? '', 'class="form-check-input cb-seleccion"') === 300, $list['body']);
        $list = $cli->get('cobros_programados.php',['ajax'=>1,'q'=>$tag,'estado'=>'programado','por_pagina'=>300,'pagina'=>2]);
        check('segunda página contiene los cinco restantes', substr_count($list['json']['html'] ?? '', 'class="form-check-input cb-seleccion"') === 5);
        $list = $cli->get('cobros_programados.php',['ajax'=>1,'q'=>$tag,'cliente'=>$ridOtro]);
        check('filtro de cliente respeta aislamiento de empresa', ($list['json']['total'] ?? -1) === 0);
        $enviadosTab = $cli->get('cobros_programados.php', ['ajax'=>1,'tab'=>'enviados','q'=>$tag,'cliente'=>$rid,'por_pagina'=>10]);
        check('enviados filtra por cliente y búsqueda con paginación', ($enviadosTab['json']['total'] ?? -1) === 1 && ($enviadosTab['json']['por_pagina'] ?? 0) === 10);
        $programadosTab = $cli->get('cobros_programados.php', ['ajax'=>1,'tab'=>'programados','q'=>$tag,'por_pagina'=>300,'pagina'=>2]);
        check('programados excluye enviados y conserva segunda página', ($programadosTab['json']['total'] ?? -1) === 305 && ($programadosTab['json']['pagina'] ?? 0) === 2);
        $page = $cli->get('cobros_programados.php');
        check('pantalla incluye filtro autocompletable y edición de documentos', sinErroresPhp($page['body']) && str_contains($page['body'],'id="cbCliente" data-buscar') && str_contains($page['body'],'id="eDocs"'), errorPhp($page['body']));
        file_put_contents('/tmp/cobros-pagina-test.html', $page['body']);
        check('listado sin formulario nuevo y con enlace a página independiente', !str_contains($page['body'], 'id="formCobro"') && str_contains($page['body'], 'href="nuevo_cobro"'));
        $nuevo = $cli->get('nuevo_cobro.php', ['receptor_id'=>$rid, 'tipo'=>'saldo_pendiente', 'facturas'=>'256,245']);
        check('página nueva solo con formulario y preselección', sinErroresPhp($nuevo['body']) && str_contains($nuevo['body'], 'id="formCobro"') && !str_contains($nuevo['body'], 'id="cbTabla"') && str_contains($nuevo['body'], '"ids":[256,245]') && str_contains($nuevo['body'], 'value="saldo_pendiente" selected'));
        file_put_contents('/tmp/cobros-nuevo-test.html', $nuevo['body']);
        $antiguo = $cli->get('cobros_programados.php', ['receptor_id'=>$rid, 'tipo'=>'envio_factura', 'facturas'=>'256,245']);
        check('enlace antiguo redirige preservando selección', $antiguo['code'] === 302 && $antiguo['loc'] === 'nuevo_cobro?'.http_build_query(['receptor_id'=>$rid, 'tipo'=>'envio_factura', 'facturas'=>'256,245']));

        foreach ([2,$cidOtro] as $cid) {
            $dir = docDir($cid); if (!is_dir($dir)) mkdir($dir,0775,true);
            $archivo = $tag . '-' . $cid . '.pdf';
            file_put_contents($dir . $archivo, "%PDF-1.4\nQA documento\n%%EOF"); $archivos[]=$dir.$archivo;
            $pdo->prepare('INSERT INTO empresa_documentos (cliente_id,nombre,archivo,archivo_nombre) VALUES (?,?,?,?)')->execute([$cid,$tag,$archivo,$archivo]);
            $docs[$cid]=(int)$pdo->lastInsertId();
        }
        $base = ['accion'=>'editar','id'=>$id,'para'=>'qa@ejemplo.test','asunto'=>$tag,'mensaje_html'=>'Mensaje actualizado','programado_para'=>'2099-01-01T08:00','actualizar_documentos'=>1];
        $r=$cli->post('cobro_accion.php',$base+['documento_ids[0]'=>$docs[2]]);
        check('adjunta documento a un correo antiguo sin adjuntos', $r['json']['success'] ?? false, $r['body']);
        $det=$cli->get('cobro_accion.php',['ver'=>$id]);
        $adj=$det['json']['adjuntos'][0] ?? [];
        check('al reabrir aparece documento con archivo y opciones disponibles', ($adj['documento_id'] ?? 0)===$docs[2] && !empty($adj['existe']) && in_array($docs[2],array_column($det['json']['documentos'] ?? [],'id')));
        check('programado conserva el párrafo con el nombre del documento', str_contains($det['json']['html'] ?? '', 'Para facilitar su gestión administrativa y tributaria') && str_contains($det['json']['html'] ?? '', $tag));
        foreach ([...COBRO_TIPOS_FACTURA, 'recordatorio_pago'] as $tipo) {
            $prev = $cli->post('cobro_accion.php', ['accion'=>'previsualizar','receptor_id'=>$rid,'tipo'=>$tipo,'mensaje_html'=>'Hola','documento_ids[0]'=>$docs[2]]);
            check('párrafo en vista previa: ' . $tipo, str_contains($prev['json']['html'] ?? '', 'Para facilitar su gestión administrativa y tributaria'));
        }
        [$html, $texto] = cobroPlantilla('Hola', [], [], [], false, ['Constancia SAR'], 'envio_factura');
        check('versión de texto del correo también menciona la constancia', str_contains($html, 'Constancia SAR') && str_contains($texto, 'Constancia SAR'));
        foreach (['<p>Información personalizada.</p><p>Formas de pago:<br>Banco 123</p><p>Saludos cordiales,</p>', 'Información personalizada.<br><br>Formas de pago:<br>Banco 123<br><br>Agradecemos su apoyo.<br><br>Saludos cordiales,'] as $original) {
            $conDoc = cobroMensajeDocumentos($original, ['Constancia SAR'], 'envio_factura');
            $repetido = cobroMensajeDocumentos($conDoc, ['Constancia SAR'], 'envio_factura');
            check('párrafo entre cuentas y despedida sin duplicarse', substr_count($repetido, 'Para facilitar') === 1 && strpos($repetido, 'Banco 123') < strpos($repetido, 'Para facilitar') && strpos($repetido, 'Para facilitar') < strpos($repetido, 'Saludos cordiales'));
            $sinDocumento = cobroMensajeDocumentos($conDoc, [], 'envio_factura');
            check('desmarcar conserva el texto personalizado y retira solo el párrafo', str_contains($sinDocumento, 'Información personalizada.') && !str_contains($sinDocumento, 'Para facilitar'));
        }
        [$sinDoc] = cobroPlantilla('Hola', [], [], [], false, [], 'envio_factura');
        [$recibo] = cobroPlantilla('Hola', [], [], [], false, ['Constancia SAR'], 'envio_recibo');
        check('sin documentos y envío de recibos no agregan el párrafo', !str_contains($sinDoc, 'Para facilitar') && !str_contains($recibo, 'Para facilitar'));
        $copia = cobroDir(2,$id).($adj['archivo'] ?? '');
        check('copia adjunta idéntica al documento original', is_file($copia) && file_get_contents($copia)===file_get_contents($archivos[0]));
        $r=$cli->post('cobro_accion.php',$base+['documento_ids[0]'=>$docs[2]]);
        check('guardar de nuevo no duplica adjuntos ni falla sin cambios', ($r['json']['success'] ?? false) && (int)$pdo->query("SELECT COUNT(*) FROM cobros_programados_documentos WHERE cobro_id=$id")->fetchColumn()===1);
        $r=$cli->post('cobro_accion.php',array_replace($base,['asunto'=>'No debe guardarse'])+['documento_ids[0]'=>$docs[$cidOtro]]);
        check('rechaza documentos ajenos y revierte todo', !($r['json']['success'] ?? true) && $pdo->query("SELECT asunto FROM cobros_programados WHERE id=$id")->fetchColumn()===$tag && is_file($copia));
        $r=$cli->post('cobro_accion.php',$base);
        check('desmarcar retira solo la copia y conserva el original', ($r['json']['success'] ?? false) && !is_file($copia) && is_file($archivos[0]));
        $r=$cli->post('cobro_accion.php',$base+['documento_ids[0]'=>$docs[2]]);
        check('documento se puede volver a adjuntar', $r['json']['success'] ?? false);
        $r=$cli->post('cobro_accion.php',['accion'=>'eliminar_lote','ids[0]'=>$id,'ids[1]'=>$otro]);
        check('lote con otra empresa no elimina ninguno', !($r['json']['success'] ?? true) && (int)$pdo->query("SELECT COUNT(*) FROM cobros_programados WHERE id IN ($id,$otro)")->fetchColumn()===2);
        $r=$cli->post('cobro_accion.php',['accion'=>'eliminar_lote','ids[0]'=>$id,'ids[1]'=>$enviado]);
        check('lote con correo enviado no elimina ninguno', !($r['json']['success'] ?? true) && (int)$pdo->query("SELECT COUNT(*) FROM cobros_programados WHERE id IN ($id,$enviado)")->fetchColumn()===2);
        $pdo->exec("UPDATE cobros_programados SET estado='enviando' WHERE id=$enviado");
        $r=$cli->post('cobro_accion.php',['accion'=>'eliminar_lote','ids[0]'=>$id,'ids[1]'=>$enviado]);
        check('protege correos en envío', !($r['json']['success'] ?? true));
        $cli->sinCsrf=true;
        $r=$cli->post('cobro_accion.php',['accion'=>'eliminar_lote','ids[0]'=>$id]);
        check('el lote exige CSRF', $r['code']===403); $cli->sinCsrf=false;
        $facturador=login('qa.facturador@local.test');
        $r=$facturador->post('cobro_accion.php',['accion'=>'eliminar_lote','ids[0]'=>$id]);
        check('solo administradores eliminan por lote', !($r['json']['success'] ?? true));
        $r=$cli->post('cobro_accion.php',['accion'=>'eliminar_lote','ids[0]'=>$id,'ids[1]'=>$ids[1]]);
        check('elimina varios programados y sus copias sin afectar el documento original', ($r['json']['success'] ?? false) && (int)$pdo->query("SELECT COUNT(*) FROM cobros_programados WHERE id IN ($id,{$ids[1]})")->fetchColumn()===0 && !is_dir(cobroDir(2,$id)) && is_file($archivos[0]), $r['body']);
    } finally {
        if ($ids) {
            $in=implode(',',$ids);
            $pdo->exec("DELETE FROM cobros_programados_documentos WHERE cobro_id IN ($in)");
            $pdo->exec("DELETE FROM cobros_programados WHERE id IN ($in)");
            foreach ($ids as $id) foreach ([2,$cidOtro] as $cid) {
                $dir=cobroDir($cid,$id); foreach (glob($dir.'*') ?: [] as $f) if (is_file($f)) unlink($f); if (is_dir($dir)) rmdir($dir);
            }
        }
        $pdo->prepare('DELETE FROM clientes_factura WHERE id=? AND cliente_id=?')->execute([$ridOtro,$cidOtro]);
        $pdo->prepare('DELETE FROM clientes_saas WHERE id=?')->execute([$cidOtro]);
        if ($docs) $pdo->exec('DELETE FROM empresa_documentos WHERE id IN ('.implode(',',$docs).')');
        foreach ($archivos as $f) if (is_file($f)) unlink($f);
    }
});
