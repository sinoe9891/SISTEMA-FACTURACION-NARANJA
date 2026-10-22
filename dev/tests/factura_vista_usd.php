<?php
require __DIR__.'/../../includes/moneda_factura.php';
$f=['moneda'=>'USD','tasa_cambio'=>26.5,'total'=>12345,'exonerado'=>0];
$items=[['cantidad'=>2,'precio_usd'=>10,'precio_unitario'=>265,'isv_aplicado'=>15], ['cantidad'=>1,'precio_usd'=>20,'precio_unitario'=>530,'isv_aplicado'=>18], ['cantidad'=>1,'precio_usd'=>5,'precio_unitario'=>132.5,'isv_aplicado'=>0]];
[$v,$i]=facturaVistaUsd($f,$items);
if ($v['total']!==51.6 || $v['isv_15']!==3.0 || $v['isv_18']!==3.6 || $v['exento_total']!==5.0 || $f['total']!==12345) throw new Exception('Conversión mixta incorrecta');
$f['exonerado']=1;
[$v]=facturaVistaUsd($f,$items);
if ($v['total']!==45.0 || $v['importe_exonerado']!==45.0) throw new Exception('Exoneración incorrecta');
$f['tasa_cambio']=0;
if (facturaVistaUsd($f,$items)!==[$f,$items]) throw new Exception('Tasa inválida');
echo "OK: ISV mixto, exentos, exonerados, tasa inválida y originales intactos.\n";
