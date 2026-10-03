<?php
// clientes/naranjaymedia/includes/facturas_xlsx.php
// Exporta a Excel las facturas seleccionadas en el historial: una fila por factura,
// el detalle de productos en una sola celda (enumerado) y una fila final de totales.
// GET ?ids=1,2,3
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once '../../../includes/xlsx.php';

$cid = cliente_actual();
$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? ''))), fn($i) => $i > 0)));
if (!$cid || !$ids) {
    http_response_code(400);
    exit('No se indicaron facturas.');
}
if (count($ids) > 2000) {
    http_response_code(400);
    exit('Demasiadas facturas a la vez (máximo 2000).');
}

$in = implode(',', array_fill(0, count($ids), '?'));
$st = $pdo->prepare("
    SELECT f.*, cf.nombre AS receptor_nombre, cf.rtn AS receptor_rtn, cf.direccion AS receptor_direccion,
           cf.telefono AS receptor_telefono, cf.email AS receptor_email,
           e.nombre AS establecimiento, cai.cai, cai.rango_cai_inicio, cai.rango_cai_fin, cai.fecha_limite,
           ct.nombre_contrato
    FROM facturas f
    INNER JOIN clientes_factura cf ON cf.id = f.receptor_id
    LEFT JOIN establecimientos e ON e.establecimiento_id = f.establecimiento_id
    LEFT JOIN cai_rangos cai ON cai.id = f.cai_id
    LEFT JOIN contratos ct ON ct.id = f.contrato_id
    WHERE f.cliente_id = ? AND f.id IN ($in)
    ORDER BY f.fecha_emision, f.correlativo
");
$st->execute(array_merge([$cid], $ids));
$facturas = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$facturas) {
    http_response_code(404);
    exit('Facturas no encontradas.');
}

// Ítems de todas las facturas en una sola consulta
$fids = array_column($facturas, 'id');
$st = $pdo->prepare("
    SELECT fi.*, p.nombre AS nombre_producto
    FROM factura_items_receptor fi
    LEFT JOIN productos_clientes p ON p.id = fi.producto_id
    WHERE fi.factura_id IN (" . implode(',', array_fill(0, count($fids), '?')) . ")
    ORDER BY fi.factura_id, fi.id
");
$st->execute($fids);
$items = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $it) $items[$it['factura_id']][] = $it;

// HTML de la descripción → texto plano conservando los saltos de línea
function textoPlano(?string $html): string
{
    $t = preg_replace('~<\s*(br|/p|/div|/li|/h[1-6])\b[^>]*>~i', "\n", (string)$html);
    $t = preg_replace('~<\s*li\b[^>]*>~i', '• ', $t);
    $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace("/[ \t\xC2\xA0]+/u", ' ', $t);
    return trim(preg_replace("/\s*\n\s*/", "\n", $t));
}

$L = fn($v) => 'L ' . number_format((float)$v, 2);
$condiciones = ['contado' => 'Contado', 'credito' => 'Crédito', 'credito/contado' => 'Crédito / Contado'];

$x = new XlsxSimple('Facturas');
$x->columnas([
    ['Correlativo', 22], ['Fecha de emisión', 13, 'fecha'], ['Estado', 10], ['Pago', 11], ['Condición', 12],
    ['Cliente', 34], ['RTN', 17], ['Dirección', 30], ['Teléfono', 14], ['Correo', 26],
    ['Sucursal', 18], ['Contrato', 26],
    ['Detalle (productos / servicios)', 60], ['Líneas', 7, 'entero'],
    ['Importe exento', 13, 'dinero'], ['Importe exonerado', 13, 'dinero'],
    ['Importe gravado 15%', 13, 'dinero'], ['Importe gravado 18%', 13, 'dinero'],
    ['Subtotal', 14, 'dinero'], ['ISV 15%', 12, 'dinero'], ['ISV 18%', 12, 'dinero'], ['ISV total', 12, 'dinero'],
    ['Total a pagar', 15, 'dinero'], ['Total en letras', 40],
    ['Orden de compra exenta', 16], ['Constancia de exoneración', 16], ['Registro SAG', 14],
    ['CAI', 40], ['Rango autorizado', 44], ['Fecha límite de emisión', 13, 'fecha'], ['Declarada', 10],
]);

$sum = array_fill_keys(['exento', 'exonerado', 'g15', 'g18', 'subtotal', 'isv15', 'isv18', 'total'], 0.0);
$anuladas = 0;
foreach ($facturas as $f) {
    $detalle = [];
    foreach ($items[$f['id']] ?? [] as $n => $it) {
        $nombre = trim((string)($it['nombre_producto'] ?? ''));
        $desc = textoPlano($it['descripcion_html'] ?? '');
        $linea = ($n + 1) . '. ' . ($nombre !== '' ? $nombre : 'Producto') . ($desc !== '' && $desc !== $nombre ? ' — ' . $desc : '');
        $linea .= "\n    " . (float)$it['cantidad'] . ' × ' . $L($it['precio_unitario']) . ' = ' . $L($it['subtotal']);
        $detalle[] = $linea;
    }
    $anulada = $f['estado'] === 'anulada';
    $isv15 = (float)$f['isv_15'];
    $isv18 = (float)$f['isv_18'];
    $x->fila([
        $f['correlativo'], $f['fecha_emision'], ucfirst($f['estado']), $f['pagada'] ? 'Pagada' : 'Pendiente',
        $condiciones[strtolower((string)$f['condicion_pago'])] ?? ucfirst((string)$f['condicion_pago']),
        $f['receptor_nombre'], $f['receptor_rtn'], $f['receptor_direccion'], $f['receptor_telefono'], $f['receptor_email'],
        $f['establecimiento'], $f['nombre_contrato'],
        implode("\n", $detalle), count($detalle),
        (float)$f['exento_total'], (float)$f['importe_exonerado'],
        (float)$f['importe_gravado_15'], (float)$f['importe_gravado_18'],
        (float)$f['subtotal'], $isv15, $isv18, round($isv15 + $isv18, 2),
        (float)$f['total'], $f['monto_letras'],
        $f['orden_compra_exenta'], $f['constancia_exoneracion'], $f['registro_sag'],
        $f['cai'], $f['rango_cai_inicio'] ? $f['rango_cai_inicio'] . ' al ' . $f['rango_cai_fin'] : '', $f['fecha_limite'],
        $f['estado_declarada'] ? 'Sí' : 'No',
    ]);
    if ($anulada) { $anuladas++; continue; }
    $sum['exento'] += (float)$f['exento_total'];
    $sum['exonerado'] += (float)$f['importe_exonerado'];
    $sum['g15'] += (float)$f['importe_gravado_15'];
    $sum['g18'] += (float)$f['importe_gravado_18'];
    $sum['subtotal'] += (float)$f['subtotal'];
    $sum['isv15'] += $isv15;
    $sum['isv18'] += $isv18;
    $sum['total'] += (float)$f['total'];
}

$x->fila([]);
$x->fila([
    'TOTALES', null, null, null, null,
    (count($facturas) - $anuladas) . ' factura(s)' . ($anuladas ? " · sin contar $anuladas anulada(s)" : ''),
    null, null, null, null, null, null, null, null,
    round($sum['exento'], 2), round($sum['exonerado'], 2), round($sum['g15'], 2), round($sum['g18'], 2),
    round($sum['subtotal'], 2), round($sum['isv15'], 2), round($sum['isv18'], 2), round($sum['isv15'] + $sum['isv18'], 2),
    round($sum['total'], 2),
], true);

$x->descargar('facturas_' . date('Y-m-d_His') . '.xlsx');
