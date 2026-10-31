<?php
/**
 * Orden de las fechas de emisión: en un mismo talonario (establecimiento-punto-tipo del correlativo) cada factura
 * debe tener fecha igual o posterior a la anterior y no posterior a la siguiente. La fecha se puede poner libre;
 * esto solo sirve para avisar y sugerir una que respete el orden (clientes/js/factura-fecha-orden.js).
 */

/** Factura anterior y siguiente del mismo talonario: ['anterior' => [id, correlativo, fecha]|null, 'siguiente' => …]. */
function facturaVecinas(PDO $pdo, int $cid, string $correlativo): array
{
    $serie = implode('-', array_slice(explode('-', $correlativo), 0, 3));
    $out = [];
    foreach (['anterior' => ['<', 'DESC'], 'siguiente' => ['>', 'ASC']] as $k => [$op, $ord]) {
        $st = $pdo->prepare("SELECT id, correlativo, fecha_emision AS fecha FROM facturas
                             WHERE cliente_id = ? AND correlativo LIKE ? AND correlativo $op ? AND fecha_emision IS NOT NULL
                             ORDER BY correlativo $ord LIMIT 1");
        $st->execute([$cid, $serie . '-%', $correlativo]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        $out[$k] = $f ? ['id' => (int)$f['id'], 'correlativo' => $f['correlativo'], 'fecha' => date('Y-m-d H:i', strtotime($f['fecha']))] : null;
    }
    return $out;
}

/** Última factura emitida con cada CAI (la «anterior» de una factura nueva): [cai_id => [id, correlativo, fecha]]. */
function facturaUltimaPorCai(PDO $pdo, int $cid): array
{
    $st = $pdo->prepare("SELECT f.cai_id, f.id, f.correlativo, f.fecha_emision FROM facturas f
                         JOIN (SELECT cai_id, MAX(correlativo) c FROM facturas WHERE cliente_id = ? AND cai_id IS NOT NULL GROUP BY cai_id) u
                           ON u.cai_id = f.cai_id AND u.c = f.correlativo
                         WHERE f.cliente_id = ?");
    $st->execute([$cid, $cid]);
    $out = [];
    foreach ($st as $f) $out[(int)$f['cai_id']] = ['id' => (int)$f['id'], 'correlativo' => $f['correlativo'], 'fecha' => date('Y-m-d H:i', strtotime($f['fecha_emision']))];
    return $out;
}


/**
 * Facturas cuya fecha no sigue el orden de su talonario: [id => [motivos]] (para el aviso «Revisar fecha» en la lista).
 */
function facturasFechaFueraDeOrden(PDO $pdo, int $cid): array
{
    $st = $pdo->prepare("SELECT id, correlativo, fecha_emision FROM facturas WHERE cliente_id = ? AND fecha_emision IS NOT NULL ORDER BY correlativo");
    $st->execute([$cid]);
    $series = [];
    foreach ($st as $f) $series[implode('-', array_slice(explode('-', $f['correlativo']), 0, 3))][] = $f;
    $ver = fn($f) => ltrim(substr($f['correlativo'], -8), '0') . ' (' . date('d/m/Y h:i a', strtotime($f['fecha_emision'])) . ')';
    // Se compara por día: dentro del mismo día no se avisa (horas cargadas sin minuto o editadas sin segundos)
    $dia = fn($f) => substr($f['fecha_emision'], 0, 10);
    $hoy = date('Y-m-d');
    $out = [];
    foreach ($series as $lista) {
        foreach ($lista as $i => $f) {
            $m = [];
            if ($i > 0 && $dia($f) < $dia($lista[$i - 1])) $m[] = 'Fecha anterior a la de la factura ' . $ver($lista[$i - 1]);
            if (isset($lista[$i + 1]) && $dia($f) > $dia($lista[$i + 1])) $m[] = 'Fecha posterior a la de la factura ' . $ver($lista[$i + 1]);
            if ($m && $dia($f) > $hoy) $m[] = 'Fecha futura';   // futura sola no es problema: se muestra aparte (facturasFechaFutura)
            if ($m) $out[(int)$f['id']] = $m;
        }
    }
    return $out;
}
