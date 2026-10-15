<?php
require __DIR__.'/../../includes/aviso_pendientes.php';
$base=['contrato_id'=>1,'dia_pago'=>15,'pa'=>2026,'pm'=>10,'emision'=>'2026-10-02'];
$casos=[[$base,'2026-10-15'],[array_replace($base,['emision'=>'2026-10-20']),'2026-10-20'],[array_replace($base,['contrato_id'=>null]),'2026-10-02'],[array_replace($base,['pm'=>9]),'2026-10-02'],[array_replace($base,['dia_pago'=>31,'pm'=>2,'emision'=>'2026-02-01']),'2026-02-28']];
foreach($casos as [$fila,$esperado]) if(avisoPendientesDesdeEnvio($fila)!==$esperado) throw new Exception('Fecha incorrecta');
$desde=avisoPendientesDesdeEnvio($base);
foreach(['2026-10-14'=>false,'2026-10-15'=>true,'2026-10-16'=>true,'2027-03-01'=>true] as $hoy=>$esperado) if(($desde<=$hoy)!==$esperado) throw new Exception('Aviso incorrecto');
echo "OK: antes, mismo día, después, más de 90 días, sin contrato y fin de mes.\n";
