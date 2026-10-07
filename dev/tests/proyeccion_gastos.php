<?php
require __DIR__ . '/../../includes/proyeccion_gastos.php';
$base = ['id'=>1,'estado'=>'pagado','descripcion'=>'Préstamo','categoria_id'=>1,'tipo'=>'fijo','frecuencia'=>'mensual','fecha'=>'2026-08-15','dia_pago'=>15,'dia_pago_2'=>30,'monto'=>2152.18,'fecha_vencimiento'=>'2026-10-15','gasto_grupo_id'=>1,'quincena_num'=>null];
function comprobar($valor, $esperado, $caso) {
    if ($valor !== $esperado) throw new RuntimeException($caso . ': ' . json_encode($valor));
    echo "OK $caso\n";
}
function totalMes($gs, $a, $m) { return round(array_sum(array_column(proyeccionGastosMes($gs,$a,$m),'total')),2); }
comprobar(totalMes([$base],2026,10),2152.18,'Incluye última cuota en vencimiento');
comprobar(totalMes([$base],2026,11),0.0,'No revive préstamo vencido');
comprobar(totalMes([array_replace($base,['fecha_vencimiento'=>'2026-10-14'])],2026,10),0.0,'Vence antes del día de pago');
comprobar(totalMes([$base,array_replace($base,['id'=>2,'fecha'=>'2026-09-15'])],2026,10),2152.18,'Historial no duplica cuotas');
comprobar(totalMes([array_replace($base,['fecha'=>'2026-12-15','fecha_vencimiento'=>null])],2026,11),0.0,'No proyecta antes del inicio');
$q = array_replace($base,['frecuencia'=>'quincenal','fecha_vencimiento'=>'2026-10-20','monto'=>100]);
comprobar(totalMes([$q],2026,10),100.0,'Quincenal respeta vencimiento entre cuotas');
comprobar(totalMes([array_replace($q,['quincena_num'=>1,'fecha_vencimiento'=>null]),array_replace($q,['id'=>2,'quincena_num'=>2,'fecha_vencimiento'=>null])],2026,10),200.0,'Quincenas separadas sin duplicación');
$anual = array_replace($base,['frecuencia'=>'anual','monto'=>1200,'fecha_vencimiento'=>null]);
comprobar(totalMes([$anual],2027,8),1200.0,'Anual completo en mes de pago');
comprobar(totalMes([$anual],2027,9),0.0,'Anual no se repite cada mes');
$unico = array_replace($base,['frecuencia'=>'unico','fecha'=>'2026-10-10']);
comprobar(totalMes([$unico],2026,10),2152.18,'Único en su mes');
comprobar(totalMes([$unico],2026,11),0.0,'Único no se repite');
comprobar(totalMes([array_replace($base,['estado'=>'anulado'])],2026,10),0.0,'Anulados excluidos');
$finMes = array_replace($base,['dia_pago'=>31,'fecha_vencimiento'=>null]);
comprobar(proyeccionGastosMes([$finMes],2027,2)[0]['fechas'],['2027-02-28'],'Día 31 ajustado al fin de mes');
