<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/bancos.php';
require_once '../../includes/xlsx.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !permisoPuede($pdo, 'bancos')) { http_response_code(403); exit('Acceso no autorizado.'); }
$cid=(int)cliente_actual();
$ids=json_decode((string)($_POST['ids']??''),true);
if (!is_array($ids) || !$ids || count($ids)>20000) { http_response_code(400); exit('Selecciona movimientos válidos (máximo 20,000).'); }
$ids=array_values(array_unique(array_map('intval',$ids)));
$st=$pdo->prepare('SELECT m.*,c.banco,c.numero,c.tipo AS cuenta_tipo,c.moneda FROM movimientos_bancarios m JOIN cuentas_bancarias c ON c.id=m.cuenta_id AND c.cliente_id=m.cliente_id WHERE m.cliente_id=? AND m.id IN ('.implode(',',array_fill(0,count($ids),'?')).')');
$st->execute([$cid,...$ids]);$porId=array_column($st->fetchAll(PDO::FETCH_ASSOC),null,'id');
if(count($porId)!==count($ids)){http_response_code(403);exit('Uno o más movimientos no están disponibles para tu empresa.');}
$x=new XlsxSimple('Movimientos bancarios');
$x->columnas([['N.º',7,'entero'],['ID',9,'entero'],['Fecha',13,'fecha'],['Banco',20],['Cuenta',22],['Tipo de cuenta',16],['Moneda',10],['Tipo de movimiento',24],['Descripción',65],['Referencia',20],['Estado',16],['Entrada',18,'dinero'],['Salida',18,'dinero'],['Movimiento neto',20,'dinero']]);
$tipos=['deposito'=>'Depósito','retiro'=>'Retiro','transferencia'=>'Transferencia','cheque'=>'Cheque','pago_gasto'=>'Pago de gasto','cobro_factura'=>'Cobro de factura','comision'=>'Comisión bancaria','interes'=>'Interés','ajuste'=>'Ajuste','prestamo_socio'=>'Préstamo a socio'];
$tot=['HNL'=>[0.,0.],'USD'=>[0.,0.]];
foreach($ids as $i=>$id){
 $m=$porId[$id];$r=$i+2;$anulado=(bool)$m['anulado'];$entrada=$m['sentido']==='entrada'?(float)$m['monto']:0.;$salida=$m['sentido']==='salida'?(float)$m['monto']:0.;
 if(!$anulado){$tot[$m['moneda']][0]+=$entrada;$tot[$m['moneda']][1]+=$salida;}
 $x->fila([$i+1,$id,$m['fecha'],$m['banco'],(string)$m['numero'],$m['cuenta_tipo']==='cheques'?'Cheques':'Ahorro',$m['moneda'],$tipos[$m['tipo']]??$m['tipo'],$m['descripcion'],(string)$m['referencia'],$anulado?'Anulado':($m['conciliado']?'Conciliado':'Sin conciliar'),$entrada,$salida,new XlsxFormula('IF(K'.$r.'="Anulado",0,L'.$r.'-M'.$r.')',$anulado?0:$entrada-$salida)],false,$anulado);
}
$fin=count($ids)+1;$x->filtroHasta($fin);$x->fila([]);
foreach($tot as $mon=>$t){
 $r=$fin+2+($mon==='HNL'?0:1);
 $x->fila(['','','','','','','','','TOTAL '.$mon.' (sin anulados)','','',new XlsxFormula('SUMIFS(L2:L'.$fin.',G2:G'.$fin.',"'.$mon.'",K2:K'.$fin.',"<>Anulado")',$t[0]),new XlsxFormula('SUMIFS(M2:M'.$fin.',G2:G'.$fin.',"'.$mon.'",K2:K'.$fin.',"<>Anulado")',$t[1]),new XlsxFormula('L'.$r.'-M'.$r,$t[0]-$t[1])],true);
}
$x->fila([]);
$x->fila(['Reporte generado',date('d/m/Y H:i')]);
$x->fila(['Filtros',mb_substr((string)($_POST['filtros']??''),0,500)]);
$x->fila(['Nota','Incluye todas las páginas de los filtros seleccionados, en el orden de la tabla. Los anulados no suman. El movimiento neto no es el saldo bancario: no incluye saldo inicial ni movimientos fuera de la selección.']);
$x->descargar('movimientos_bancarios_'.date('Y-m-d').'.xlsx');
