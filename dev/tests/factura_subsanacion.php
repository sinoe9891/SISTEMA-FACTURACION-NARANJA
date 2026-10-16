<?php
if (getenv('APP_DB') !== 'dev') exit('Usa APP_DB=dev');
require __DIR__.'/../../includes/db.php';
require __DIR__.'/../../includes/factura_notas.php';
if (!str_ends_with($pdo->query('SELECT DATABASE()')->fetchColumn(), '_dev')) exit(1);
$id = (int)$pdo->query('SELECT id FROM facturas WHERE cliente_id=2 LIMIT 1')->fetchColumn();
$pdo->beginTransaction();
try {
    $leer = fn() => $pdo->query("SELECT requiere_subsanacion FROM facturas WHERE id=$id")->fetchColumn();
    $fiscal = fn() => $pdo->query("SELECT estado, estado_declarada, total, isv_15, isv_18 FROM facturas WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    $antes = $fiscal();
    facturaGuardarSubsanacion($pdo, 2, $id, ['subsanacion_presente'=>'1', 'requiere_subsanacion'=>'1']);
    if ((int)$leer() !== 1) throw new Exception('No marcó');
    facturaGuardarSubsanacion($pdo, 2, $id, []);
    if ((int)$leer() !== 1) throw new Exception('No preservó formulario antiguo');
    facturaGuardarSubsanacion($pdo, 999999, $id, ['subsanacion_presente'=>'1']);
    if ((int)$leer() !== 1) throw new Exception('Sin aislamiento');
    if ($pdo->query("SELECT COUNT(*) FROM facturas WHERE id=$id AND requiere_subsanacion=0")->fetchColumn() != 0) throw new Exception('Aparece en alertas');
    facturaGuardarSubsanacion($pdo, 2, $id, ['subsanacion_presente'=>'1', 'requiere_subsanacion'=>'2']);
    if ((int)$leer() !== 2) throw new Exception('No guardó subsanada');
    facturaGuardarSubsanacion($pdo, 2, $id, ['subsanacion_presente'=>'1']);
    if ((int)$leer() !== 0) throw new Exception('No desmarcó');
    if ($fiscal() !== $antes) throw new Exception('Cambió datos fiscales');
    echo "OK: marcar, desmarcar, preservar, aislamiento y datos fiscales intactos.\n";
} finally { $pdo->rollBack(); }
