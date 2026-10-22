<?php
/**
 * moneda_factura.php — Facturas con precios en dólares: la factura fiscal sigue en lempiras (SAR); los precios
 * en USD se convierten con la tasa elegida y se guardan como referencia (tasa, total en USD y precio USD por línea).
 *   facturaMonedaDisponible($pdo)          → si ya existen las columnas
 *   facturaMonedaDePost($d)                → ['HNL', null] o ['USD', tasa]
 *   facturaPrecioLempiras($usd, $tasa)     → precio unitario en L
 *   facturaTasaSugerida($pdo, $cid)        → tasa del día (BCH venta o referencia)
 *   monedaFmt($v, $moneda)                 → «L 1,000.00» o «$ 1,000.00»
 */

function facturaMonedaDisponible(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try { $ok = (bool)$pdo->query("SHOW COLUMNS FROM facturas LIKE 'tasa_cambio'")->fetchColumn(); } catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

/** Moneda de los precios que vienen del formulario; en dólares exige una tasa válida. */
function facturaMonedaDePost(array $d): array
{
    if (($d['moneda'] ?? 'HNL') !== 'USD') return ['HNL', null];
    $tasa = round((float)str_replace(',', '', (string)($d['tasa_cambio'] ?? '')), 4);
    if ($tasa < 1 || $tasa > 1000) throw new Exception("Escribe la tasa de cambio (lempiras por dólar) para facturar en dólares.");
    return ['USD', $tasa];
}

function facturaPrecioLempiras(float $usd, float $tasa): float
{
    return round($usd * $tasa, 2);
}

/** Total en dólares con el mismo cálculo de la factura (centavos por línea). $lineas: [cantidad, precio_usd, isv 0|15|18]. */
function facturaTotalUsd(array $lineas, bool $exonerado): float
{
    $sub = $isv = 0.0;
    foreach ($lineas as [$cant, $pu, $tipo]) {
        $t = round($cant * $pu, 2);
        $sub += $t;
        if (!$exonerado && $tipo === 15) $isv += round($t * 0.15, 2);
        elseif (!$exonerado && $tipo === 18) $isv += round($t * 0.18, 2);
    }
    return round($sub + $isv, 2);
}

/** Guarda la moneda de la factura y el precio USD de cada línea (en el orden en que se insertaron). Sin tasa: queda en lempiras. */
function facturaGuardarMoneda(PDO $pdo, int $facturaId, ?float $tasa, ?float $totalUsd, array $preciosUsd = []): void
{
    if (!facturaMonedaDisponible($pdo)) return;
    $pdo->prepare("UPDATE facturas SET moneda = ?, tasa_cambio = ?, total_usd = ? WHERE id = ?")
        ->execute([$tasa ? 'USD' : 'HNL', $tasa ?: null, $tasa ? $totalUsd : null, $facturaId]);
    $st = $pdo->prepare("SELECT id FROM factura_items_receptor WHERE factura_id = ? ORDER BY id");
    $st->execute([$facturaId]);
    $up = $pdo->prepare("UPDATE factura_items_receptor SET precio_usd = ? WHERE id = ?");
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $i => $itemId) $up->execute([$tasa ? ($preciosUsd[$i] ?? null) : null, $itemId]);
}

/** Tasa para sugerir al facturar en dólares: venta del BCH del día o, si no hay, la de referencia. */
function facturaTasaSugerida(PDO $pdo, int $cid): float
{
    require_once __DIR__ . '/tasa_cambio.php';
    $u = tasaDisponible($pdo) ? tasaUltima($pdo) : null;
    return $u ? (float)($u['venta'] ?: $u['referencia'] ?: 0) : 0.0;
}

function monedaFmt($v, string $moneda = 'HNL'): string
{
    return ($moneda === 'USD' ? '$ ' : 'L ') . number_format((float)$v, 2);
}

/** Vista en USD sobre copias de los datos; nunca modifica la factura almacenada. */
function facturaVistaUsd(array $factura, array $items): array
{
    $tasa = (float)($factura['tasa_cambio'] ?? 0);
    if (($factura['moneda'] ?? 'HNL') !== 'USD' || $tasa <= 0) return [$factura, $items];
    foreach (['subtotal','gravado_total','exento_total','importe_exonerado','importe_gravado_15','importe_gravado_18','isv_15','isv_18'] as $campo) $factura[$campo] = 0.0;
    foreach ($items as &$item) {
        $item['precio_unitario'] = (float)($item['precio_usd'] ?? round((float)$item['precio_unitario'] / $tasa, 2));
        $item['subtotal'] = round((float)$item['cantidad'] * $item['precio_unitario'], 2);
        $sub = $item['subtotal'];
        $factura['subtotal'] += $sub;
        $tipo = (int)($item['isv_aplicado'] ?? 0);
        if (!empty($factura['exonerado'])) {
            $factura['importe_exonerado'] += $sub;
            $factura['exento_total'] += $sub;
        } elseif (in_array($tipo, [15,18], true)) {
            $factura['gravado_total'] += $sub;
            $factura['importe_gravado_'.$tipo] += $sub;
            $factura['isv_'.$tipo] += round($sub * $tipo / 100, 2);
        } else $factura['exento_total'] += $sub;
    }
    unset($item);
    $factura['descuentos'] = round((float)($factura['descuentos'] ?? 0) / $tasa, 2);
    $factura['total'] = round($factura['subtotal'] + $factura['isv_15'] + $factura['isv_18'], 2);
    return [$factura, $items];
}
