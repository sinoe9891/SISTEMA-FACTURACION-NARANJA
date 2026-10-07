<?php
/** Contratos facturables de la empresa; incluye históricos y receptores de turnos rotativos. */
function facturaContratosDisponibles(PDO $pdo, int $cid): array
{
    $st = $pdo->prepare("SELECT c.id, c.nombre_contrato, c.receptor_id, c.tipo_contrato, c.estado,
        r.receptor_id AS receptor_rotativo
        FROM contratos c LEFT JOIN contratos_clientes_rotativos r ON r.contrato_id=c.id AND r.activo=1
        WHERE c.cliente_id=? AND c.tipo_contrato<>'sin_factura' ORDER BY c.nombre_contrato, c.id");
    $st->execute([$cid]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $id = (int)$r['id'];
        if (!isset($out[$id])) $out[$id] = ['id'=>$id,'nombre'=>$r['nombre_contrato'] ?: 'Contrato #'.$id,'estado'=>$r['estado'],'receptores'=>[(int)$r['receptor_id']]];
        if ($r['tipo_contrato']==='rotativo' && $r['receptor_rotativo']) $out[$id]['receptores'][]=(int)$r['receptor_rotativo'];
    }
    return array_values($out);
}

/** Se ejecuta dentro de la transacción de edición, antes de modificar la factura. */
function facturaValidarCambioContrato(PDO $pdo, array $factura, ?int $contratoId, int $receptorId): void
{
    $cid = (int)$factura['cliente_id'];
    $cambio = (int)$factura['contrato_id'] !== (int)$contratoId;
    if ($contratoId) {
        $st=$pdo->prepare("SELECT c.id FROM contratos c WHERE c.id=? AND c.cliente_id=? AND c.tipo_contrato<>'sin_factura'
            AND (c.receptor_id=? OR (c.tipo_contrato='rotativo' AND EXISTS (SELECT 1 FROM contratos_clientes_rotativos r WHERE r.contrato_id=c.id AND r.receptor_id=? AND r.activo=1)))");
        $st->execute([$contratoId,$cid,$receptorId,$receptorId]);
        if (!$st->fetchColumn()) throw new Exception('El contrato no corresponde al cliente de esta factura o no permite facturación. Selecciona un contrato válido o «Sin contrato».');
    }
    if (!$cambio) return;
    foreach (['contratos_plan','contratos_anticipos'] as $tabla) {
        if (!$pdo->query('SHOW TABLES LIKE '.$pdo->quote($tabla))->fetchColumn()) continue;
        $st=$pdo->prepare("SELECT COUNT(*) FROM $tabla WHERE cliente_id=? AND factura_id=?" . ($tabla==='contratos_anticipos' ? ' AND anulado=0' : ''));
        $st->execute([$cid,$factura['id']]);
        if ($st->fetchColumn()) throw new Exception('Esta factura tiene pagos del plan o anticipos vinculados. Desvincúlalos desde el contrato antes de cambiar su asociación.');
    }
}
