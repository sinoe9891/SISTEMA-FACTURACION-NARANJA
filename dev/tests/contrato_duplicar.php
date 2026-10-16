<?php
suite('Duplicar contrato', function () {
    $pdo=db(); $c=login('qa.admin@local.test');
    $origen=$pdo->query("SELECT * FROM contratos WHERE cliente_id=2 AND tipo_contrato='estandar' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$origen) throw new Exception('Falta contrato de prueba');
    $id=(int)$origen['id'];
    $r=$c->get('editar_contrato.php',['id'=>$id,'duplicar'=>1]);
    check('formulario duplicado abre sin errores', $r['code']===200 && sinErroresPhp($r['body']));
    check('usa creación y no envía ID original', str_contains(str_replace('\\/', '/', $r['body']),'includes/contrato_guardar.php') && !str_contains($r['body'],'name="id"') && !str_contains($r['body'],'id="ppActivar"'));
    check('inicio vacío para elegir nueva vigencia', str_contains($r['body'],'name="fecha_inicio" class="mf-input" value=""'));
    file_put_contents('/tmp/contrato-duplicar.html',$r['body']);
    $producto=$pdo->query('SELECT id FROM productos_clientes WHERE cliente_id=2 LIMIT 1')->fetchColumn();
    $d=['tipo_contrato'=>'estandar','receptor_id'=>$origen['receptor_id'],'nombre_contrato'=>'QA duplicar '.bin2hex(random_bytes(4)),'fecha_inicio'=>'2027-01-15','fecha_fin'=>'2027-11-15','dia_pago'=>15,'servicios[0][producto_id]'=>$producto,'servicios[0][monto]'=>3400];
    $creado=$c->post('includes/contrato_guardar.php',$d);
    $nuevo=(int)($creado['json']['contrato_id']??0);
    try {
        check('crear copia genera ID diferente', !empty($creado['json']['success']) && $nuevo!==$id && $nuevo>0, $creado['body']);
        if ($nuevo) {
            $fila=$pdo->query("SELECT * FROM contratos WHERE id=$nuevo")->fetch(PDO::FETCH_ASSOC);
            check('guarda fechas y monto elegidos', $fila['fecha_inicio']==='2027-01-15' && $fila['fecha_fin']==='2027-11-15' && (float)$fila['monto']===3400.0);
        }
        check('contrato original intacto', $pdo->query("SELECT * FROM contratos WHERE id=$id")->fetch(PDO::FETCH_ASSOC)===$origen);
    } finally { if($nuevo && $nuevo!==$id) { $pdo->exec("DELETE FROM contratos_servicios WHERE contrato_id=$nuevo"); $pdo->exec("DELETE FROM contratos WHERE id=$nuevo AND cliente_id=2"); } }
});
