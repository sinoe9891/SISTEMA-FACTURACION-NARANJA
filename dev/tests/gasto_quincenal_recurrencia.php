<?php
if (getenv('APP_DB') !== 'dev') exit("Usa APP_DB=dev\n");
require __DIR__.'/../../includes/db.php';
require __DIR__.'/../../clientes/naranjaymedia/includes/_gasto_recurrencia.php';
if (!str_ends_with($pdo->query('SELECT DATABASE()')->fetchColumn(), '_dev')) exit(1);
$pdo->beginTransaction();
try {
    $pdo->exec("INSERT INTO gastos (cliente_id,descripcion,monto,fecha,frecuencia,dia_pago,dia_pago_2,quincena_num,fecha_vencimiento,tipo,metodo_pago,estado) VALUES (2,'QA CRV',3400,'2027-10-15','quincenal',15,30,1,'2027-11-15','fijo','transferencia','pendiente')");
    $id=(int)$pdo->lastInsertId();
    $g=$pdo->query("SELECT * FROM gastos WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    if(programarSiguienteGastoRecurrente($pdo,$g,2,1)!=='2027-11-15') throw new Exception('No creó última primera quincena');
    if(programarSiguienteGastoRecurrente($pdo,$g,2,1)!==null) throw new Exception('Duplicado');
    $g['quincena_num']=2; $g['fecha']='2027-10-30';
    if(programarSiguienteGastoRecurrente($pdo,$g,2,1)!==null) throw new Exception('Excedió vencimiento');
    $g['fecha_vencimiento']='2027-12-30';
    if(programarSiguienteGastoRecurrente($pdo,$g,2,1)!=='2027-11-30') throw new Exception('Primera quincena bloquea segunda');
    $g['fecha']='2027-11-30';$g['fecha_vencimiento']='2027-11-15';
    if(programarSiguienteGastoRecurrente($pdo,$g,2,1)!==null) throw new Exception('Generó diciembre');
    echo "OK: dos quincenas independientes, sin duplicados y vencimiento respetado.\n";
} finally { $pdo->rollBack(); }
