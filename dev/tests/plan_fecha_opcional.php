<?php
if (getenv('APP_DB')!=='dev') exit(1);
require __DIR__.'/../../includes/db.php';
require __DIR__.'/../../includes/contrato_plan.php';
if (!str_ends_with($pdo->query('SELECT DATABASE()')->fetchColumn(),'_dev')) exit(1);
$pdo->beginTransaction();
try {
 $pdo->exec("INSERT INTO contratos (cliente_id,receptor_id,nombre_contrato,producto_id,monto,fecha_inicio,dia_pago,estado,tipo_contrato) SELECT cliente_id,receptor_id,'QA fecha pendiente',producto_id,47014.60,CURDATE(),22,'activo','proyecto' FROM contratos WHERE cliente_id=2 LIMIT 1");$id=(int)$pdo->lastInsertId();
 planGuardar($pdo,2,$id,[['concepto'=>'Segundo 50%','tipo'=>'etapa','fecha'=>'','monto'=>23507.30]],true);
 $ls=planLineas($pdo,2,$id);$l=$ls[0];$r=planResumen($ls);
 if ($l['fecha']!==null || $l['dias']!==null || $l['estado']!=='pendiente' || $r['proximo']!==null || $r['vencido']!=0 || $r['pendiente']!=27033.40) throw new Exception('Fecha pendiente inválida');
 foreach(planPendientesPorMes($pdo,2) as $mes) if(isset($mes[$id]))throw new Exception('Proyección inventada');
 planGuardar($pdo,2,$id,[['id'=>$l['id'],'concepto'=>'Segundo 50% actualizado','tipo'=>'etapa','fecha'=>'2027-02-01','monto'=>23507.30]],true);
 if(planLineas($pdo,2,$id)[0]['fecha']!=='2027-02-01')throw new Exception('No permite definir fecha');
 planGuardar($pdo,2,$id,[],true);
 if(planLineas($pdo,2,$id))throw new Exception('No elimina pendiente');
 echo "OK: línea sin fecha, saldo pendiente, sin vencimiento ni proyección, edición y eliminación.\n";
} finally {$pdo->rollBack();}
