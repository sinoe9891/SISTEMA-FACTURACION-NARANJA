<?php
if(getenv('APP_DB')!=='dev') exit('Usa APP_DB=dev');
require __DIR__.'/../../includes/db.php';
require __DIR__.'/../../includes/factura_notas.php';
if(!str_ends_with($pdo->query('SELECT DATABASE()')->fetchColumn(),'_dev')) exit(1);
$id=(int)$pdo->query('SELECT id FROM facturas WHERE cliente_id=2 LIMIT 1')->fetchColumn();
$pdo->beginTransaction();
try {
 $leer=fn()=>$pdo->query("SELECT notas_internas FROM facturas WHERE id=$id")->fetchColumn();
 facturaGuardarNotas($pdo,2,$id,['notas_internas'=>'Nota privada <b>texto</b>']);
 if($leer()!=='Nota privada <b>texto</b>') throw new Exception('No guardó');
 facturaGuardarNotas($pdo,2,$id,[]);
 if($leer()!=='Nota privada <b>texto</b>') throw new Exception('No preservó');
 facturaGuardarNotas($pdo,999999,$id,['notas_internas'=>'Otra empresa']);
 if($leer()!=='Nota privada <b>texto</b>') throw new Exception('Sin aislamiento');
 try {facturaGuardarNotas($pdo,2,$id,['notas_internas'=>str_repeat('x',5001)]);throw new LogicException('Sin límite');} catch (LogicException $e) {throw $e;} catch(Exception $e) {}
 facturaGuardarNotas($pdo,2,$id,['notas_internas'=>'']);
 if($leer()!==null) throw new Exception('No borró');
 echo "OK: guardar, conservar, vaciar, límite y aislamiento.\n";
} finally {$pdo->rollBack();}
