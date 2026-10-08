<?php
/**
 * facturacion.php — Creación de facturas (la usan guardar_factura.php y el punto de venta).
 *
 * crearFactura() valida, toma el correlativo del CAI, guarda la factura y sus líneas y
 * descuenta el inventario. NO abre ni confirma la transacción: el que llama debe hacer
 * beginTransaction() antes y commit() después, para poder agrupar otras operaciones
 * (por ejemplo la venta y los pagos del POS) en la misma transacción.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/inventario.php';

/**
 * @param array $d receptor_id, cai_rango_id, establecimiento_id, condicion_pago, productos[] (id, cantidad,
 *                 precio opcional, detalles), contrato_id?, exonerado?, orden_compra_exenta?, constancia_exoneracion?, registro_sag?
 * @return array  id, correlativo, subtotal, isv_15, isv_18, total
 */
function crearFactura(PDO $pdo, int $cliente_id, int $usuario_id, array $d): array
{
    if (!$pdo->inTransaction()) throw new LogicException("crearFactura() requiere una transacción abierta.");

    $receptor_id        = filter_var($d['receptor_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $cai_id             = filter_var($d['cai_rango_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $condicion_pago     = trim((string)($d['condicion_pago'] ?? ''));
    $contrato_id        = filter_var($d['contrato_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $exonerado          = !empty($d['exonerado']) ? 1 : 0;
    $establecimiento_id = filter_var($d['establecimiento_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $productos          = $d['productos'] ?? [];
    // Una factura nueva siempre nace emitida y con la fecha/hora real de emisión
    $estado        = 'emitida';
    $fecha_emision = date('Y-m-d H:i:s');

    $orden_compra_exenta    = $exonerado ? trim((string)($d['orden_compra_exenta'] ?? '')) : null;
    $constancia_exoneracion = $exonerado ? trim((string)($d['constancia_exoneracion'] ?? '')) : null;
    $registro_sag           = $exonerado ? trim((string)($d['registro_sag'] ?? '')) : null;
    if ($exonerado && (!$orden_compra_exenta || !$constancia_exoneracion || !$registro_sag)) {
        throw new Exception("Debe llenar todos los campos de exoneración.");
    }

    // ── Validaciones básicas ──────────────────────────────────────────────────
    if (!$receptor_id)        throw new Exception("Selecciona un cliente receptor.");
    if (!$cai_id)             throw new Exception("Selecciona un CAI válido.");
    if (!$condicion_pago)     throw new Exception("Selecciona la condición de pago.");
    if (!$establecimiento_id) throw new Exception("Establecimiento no especificado. Verifica tu sesión.");
    if (empty($productos) || !is_array($productos)) throw new Exception("Agrega al menos un producto.");
    $productos = array_values($productos);
    foreach ($productos as $i => $item) {
        $n = $i + 1;
        if (!is_array($item) || empty($item['id'])) throw new Exception("Línea $n: selecciona un producto.");
        if (!is_numeric($item['cantidad'] ?? null) || (float)$item['cantidad'] <= 0) throw new Exception("Línea $n: la cantidad debe ser mayor que 0.");
        if (isset($item['precio']) && $item['precio'] !== '' && (!is_numeric($item['precio']) || (float)$item['precio'] < 0)) throw new Exception("Línea $n: el precio no puede ser negativo.");
    }

    // ── Pertenencia a la empresa ──────────────────────────────────────────────
    $stmt = $pdo->prepare("SELECT punto_emision_id FROM cai_rangos WHERE id = ? AND cliente_id = ? AND fecha_limite >= CURDATE()");
    $stmt->execute([$cai_id, $cliente_id]);
    $punto_emision_id = $stmt->fetchColumn();
    if ($punto_emision_id === false) throw new Exception("CAI inválido o vencido.");
    if (!$punto_emision_id) throw new Exception("No se encontró el punto de emisión del CAI.");

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM clientes_factura WHERE id = ? AND cliente_id = ?");
    $stmt->execute([$receptor_id, $cliente_id]);
    if (!$stmt->fetchColumn()) throw new Exception("Cliente receptor inválido.");

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM establecimientos WHERE establecimiento_id = ? AND cliente_id = ?");
    $stmt->execute([$establecimiento_id, $cliente_id]);
    if (!$stmt->fetchColumn()) throw new Exception("Establecimiento inválido para este cliente.");

    if ($contrato_id) {
        // Del cliente, o de una de las empresas de un contrato rotativo (igual que al editar la factura)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM contratos c WHERE c.id = ? AND c.cliente_id = ? AND c.estado = 'activo' AND c.tipo_contrato <> 'sin_factura'
            AND (c.receptor_id = ? OR (c.tipo_contrato = 'rotativo' AND EXISTS (SELECT 1 FROM contratos_clientes_rotativos r WHERE r.contrato_id = c.id AND r.receptor_id = ? AND r.activo = 1)))");
        $stmt->execute([$contrato_id, $cliente_id, $receptor_id, $receptor_id]);
        if (!$stmt->fetchColumn()) throw new Exception("El contrato seleccionado no corresponde a este cliente.");
    }

    // ── Correlativo (bloquea el CAI hasta el commit) ──────────────────────────
    $correlativo = generarCorrelativoFactura($pdo, $cai_id, $cliente_id, $establecimiento_id, (int)$punto_emision_id);

    // ── Totales ───────────────────────────────────────────────────────────────
    $stmtProd = $pdo->prepare("
        SELECT p.id, p.tipo_isv,
               COALESCE((SELECT precio_especial FROM precios_especiales WHERE producto_id = p.id AND cliente_id = :cid LIMIT 1), p.precio) AS precio_unitario
        FROM productos_clientes p WHERE p.cliente_id = :cid
    ");
    $stmtProd->execute(['cid' => $cliente_id]);
    $productos_db = [];
    foreach ($stmtProd->fetchAll(PDO::FETCH_ASSOC) as $p) $productos_db[$p['id']] = $p;

    $subtotal = $isv_15 = $isv_18 = $gravado_total = $exento_total = 0.0;
    $importe_exonerado = $importe_gravado_15 = $importe_gravado_18 = 0.0;
    $lineas = [];
    foreach ($productos as $item) {
        $prod_id = (int)$item['id'];
        if (!isset($productos_db[$prod_id])) throw new Exception("Producto inválido: $prod_id");
        $cantidad = (float)$item['cantidad'];
        $precio_unitario = isset($item['precio']) && $item['precio'] !== '' ? (float)$item['precio'] : (float)$productos_db[$prod_id]['precio_unitario'];
        $tipo_isv = (int)$productos_db[$prod_id]['tipo_isv'];
        $subtotal_item = round($cantidad * $precio_unitario, 2);
        $subtotal += $subtotal_item;
        if ($exonerado) {
            $importe_exonerado += $subtotal_item;
            $exento_total += $subtotal_item;
        } elseif ($tipo_isv === 15) {
            $importe_gravado_15 += $subtotal_item;
            $isv_15 += $subtotal_item * 0.15;
            $gravado_total += $subtotal_item;
        } elseif ($tipo_isv === 18) {
            $importe_gravado_18 += $subtotal_item;
            $isv_18 += $subtotal_item * 0.18;
            $gravado_total += $subtotal_item;
        } else {
            $exento_total += $subtotal_item;
        }
        $lineas[] = [$prod_id, trim((string)($item['detalles'] ?? '')), $cantidad, $precio_unitario, $subtotal_item, in_array($tipo_isv, [15, 18], true) ? $tipo_isv : 0];
    }
    // Montos a 2 decimales (lo que se guarda y se cobra); las letras salen del mismo total
    $subtotal = round($subtotal, 2);
    $isv_15   = round($isv_15, 2);
    $isv_18   = round($isv_18, 2);
    $total    = round($subtotal + $isv_15 + $isv_18, 2);
    if ($total <= 0) throw new Exception("El total de la factura debe ser mayor que 0.");

    // Sin contrato elegido: si el cliente tiene un solo contrato activo (propio o como empresa de un rotativo) y la factura
    // incluye la mensualidad (subtotal ≥ monto del contrato), se liga a ese contrato. Así ninguna mensualidad queda suelta.
    if (!$contrato_id) {
        $stmt = $pdo->prepare("SELECT c.id, COALESCE((SELECT r.monto FROM contratos_clientes_rotativos r WHERE r.contrato_id = c.id AND r.receptor_id = ? AND r.activo = 1 LIMIT 1), c.monto) AS mensual
            FROM contratos c WHERE c.cliente_id = ? AND c.estado = 'activo' AND c.tipo_contrato <> 'sin_factura'
              AND (c.receptor_id = ? OR (c.tipo_contrato = 'rotativo' AND EXISTS (SELECT 1 FROM contratos_clientes_rotativos r WHERE r.contrato_id = c.id AND r.receptor_id = ? AND r.activo = 1)))");
        $stmt->execute([$receptor_id, $cliente_id, $receptor_id, $receptor_id]);
        $activos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($activos) === 1 && (float)$activos[0]['mensual'] > 0 && $subtotal + 0.005 >= (float)$activos[0]['mensual']) $contrato_id = (int)$activos[0]['id'];
    }

    // ── Guardar ───────────────────────────────────────────────────────────────
    $pdo->prepare("
        INSERT INTO facturas (
            cliente_id, cai_id, receptor_id, contrato_id, establecimiento_id,
            correlativo, fecha_emision, estado_declarada, enviada_receptor,
            condicion_pago, exonerado, orden_compra_exenta, constancia_exoneracion, registro_sag,
            gravado_total, exento_total, importe_exonerado, importe_gravado_15, importe_gravado_18,
            subtotal, isv_15, isv_18, total, monto_letras, estado
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $cliente_id, $cai_id, $receptor_id, $contrato_id, $establecimiento_id,
        $correlativo, $fecha_emision,
        $condicion_pago, $exonerado, $orden_compra_exenta, $constancia_exoneracion, $registro_sag,
        round($gravado_total, 2), round($exento_total, 2), round($importe_exonerado, 2), round($importe_gravado_15, 2), round($importe_gravado_18, 2),
        $subtotal, $isv_15, $isv_18, $total, numeroALetras($total), $estado,
    ]);
    $factura_id = (int)$pdo->lastInsertId();

    $pdo->prepare("UPDATE cai_rangos SET ultimo_correlativo = ? WHERE id = ?")->execute([$correlativo, $cai_id]);

    $stmtItem = $pdo->prepare("INSERT INTO factura_items_receptor (factura_id, producto_id, descripcion_html, cantidad, precio_unitario, subtotal, isv_aplicado) VALUES (?, ?, ?, ?, ?, ?, ?)");
    foreach ($lineas as $l) $stmtItem->execute([$factura_id, ...$l]);

    // Inventario: descontar los bienes (sin existencia suficiente, falla toda la factura)
    invDescontarFactura($pdo, $cliente_id, $factura_id, (int)$establecimiento_id, $usuario_id);

    return ['id' => $factura_id, 'correlativo' => $correlativo, 'subtotal' => $subtotal, 'isv_15' => $isv_15, 'isv_18' => $isv_18, 'total' => $total, 'contrato_id' => $contrato_id];
}
