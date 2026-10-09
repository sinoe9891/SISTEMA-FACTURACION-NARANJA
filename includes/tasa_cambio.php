<?php
/**
 * tasa_cambio.php — Tasa del dólar (US$ → L), guardada por día en tasas_cambio.
 *
 * Fuente principal: Web-API del Banco Central de Honduras (gratuita con registro en
 * https://bchapi-am.developer.azure-api.net), tasas de referencia de compra y venta.
 * La clave de suscripción se guarda cifrada por empresa (configuracion_api).
 * Respaldo sin clave: open.er-api.com (tasa de referencia del mercado, sin compra/venta).
 */
require_once __DIR__ . '/correo.php';   // correoCifrar / correoDescifrar

const TASA_BCH_BASE = 'https://bchapi-am.azure-api.net/api/v1';

function tasaDisponible(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try { $ok = (bool)$pdo->query("SHOW TABLES LIKE 'tasas_cambio'")->fetchColumn(); } catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

function tasaClaveBch(PDO $pdo, int $cid): string
{
    try {
        $st = $pdo->prepare("SELECT bch_clave_cifrada FROM configuracion_api WHERE cliente_id = ?");
        $st->execute([$cid]);
        return correoDescifrar($st->fetchColumn() ?: null);
    } catch (Throwable $e) {
        return '';
    }
}

function tasaGuardarClaveBch(PDO $pdo, int $cid, string $clave): void
{
    $clave = trim($clave);
    $pdo->prepare("INSERT INTO configuracion_api (cliente_id, bch_clave_cifrada) VALUES (?, ?) ON DUPLICATE KEY UPDATE bch_clave_cifrada = VALUES(bch_clave_cifrada)")
        ->execute([$cid, $clave === '' ? null : correoCifrar($clave)]);
}

function tasaHttp(string $url, array $headers = [], int $espera = 15): array
{
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $espera, CURLOPT_CONNECTTIMEOUT => min(15, $espera), CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => true]);
    $b = curl_exec($c);
    $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
    $err = curl_error($c);
    curl_close($c);
    if ($code === 429) throw new Exception("se alcanzó el límite de consultas del BCH (429): se usa la tasa de referencia y se reintenta más tarde.");
    if (in_array($code, [401, 403], true)) throw new Exception("la llave fue rechazada ($code): revisa que sea la llave principal vigente y que la suscripción esté activa en el portal del BCH.");
    if ($b === false || $code >= 400) throw new Exception("La fuente respondió " . ($code ?: 'sin conexión') . ($err ? " ($err)" : '') . '.');
    $j = json_decode((string)$b, true);
    if (!is_array($j)) throw new Exception("Respuesta inesperada de la fuente.");
    return $j;
}

/**
 * BCH: trae el último valor del tipo de cambio nominal de compra (indicador 619) y de venta (620).
 * Devuelve ['fecha', 'compra', 'venta'].
 */
function tasaDesdeBch(string $clave): array
{
    // La llave va solo en encabezados (nunca en la URL): «clave» (el que usa la Web-API del BCH) y el estándar de Azure API Management
    $h = ['clave: ' . $clave, 'Ocp-Apim-Subscription-Key: ' . $clave, 'Accept: application/json'];
    // Indicadores oficiales del catálogo del BCH (Catalogo_Indicadores_v1.xlsx, grupo EC-TCN-01):
    // 619 «Tipo de Cambio Nominal - Compra» y 620 «Tipo de Cambio Nominal - Venta»
    $ids = ['compra' => 619, 'venta' => 620];
    $out = [];
    foreach ($ids as $k => $id) {
        // El BCH a veces tarda (de madrugada no respondió en 15 s): se le dan 40 s
        $cifras = tasaHttp(TASA_BCH_BASE . '/indicadores/' . rawurlencode((string)$id) . '/cifras?formato=Json&reciente=5', $h, 40);
        usort($cifras, fn($a, $b) => strcmp((string)($b['Fecha'] ?? $b['fecha'] ?? ''), (string)($a['Fecha'] ?? $a['fecha'] ?? '')));
        $u = $cifras[0] ?? null;
        if (!$u) throw new Exception("El BCH no devolvió cifras de $k.");
        $out[$k] = (float)($u['Valor'] ?? $u['valor'] ?? 0);
        $out['fecha'] = substr((string)($u['Fecha'] ?? $u['fecha'] ?? date('Y-m-d')), 0, 10);
    }
    if ($out['compra'] <= 0 || $out['venta'] <= 0) throw new Exception("Valores de tasa inválidos del BCH.");
    return $out;
}

/** Respaldo: tasa de referencia del mercado (sin compra/venta). */
function tasaDesdeReferencia(): array
{
    $j = tasaHttp('https://open.er-api.com/v6/latest/USD');
    $hnl = (float)($j['rates']['HNL'] ?? 0);
    if ($hnl <= 0) throw new Exception("La fuente de referencia no trajo el lempira.");
    return ['fecha' => date('Y-m-d', (int)($j['time_last_update_unix'] ?? time())), 'referencia' => round($hnl, 4)];
}

/** Actualiza la tasa del día. Devuelve la fila guardada. */
function tasaActualizar(PDO $pdo, int $cid): array
{
    $clave = tasaClaveBch($pdo, $cid);
    $errBch = null;
    if ($clave !== '') {
        try {
            $t = tasaDesdeBch($clave);
            $pdo->prepare("INSERT INTO tasas_cambio (fecha, compra, venta, referencia, fuente) VALUES (?, ?, ?, ?, 'BCH')
                           ON DUPLICATE KEY UPDATE compra = VALUES(compra), venta = VALUES(venta), referencia = VALUES(referencia), fuente = 'BCH'")
                ->execute([date('Y-m-d'), $t['compra'], $t['venta'], round(($t['compra'] + $t['venta']) / 2, 4)]);   // la vigente hoy (aunque el BCH la haya publicado ayer)
            return tasaUltima($pdo);
        } catch (Throwable $e) {
            $errBch = $e->getMessage();
        }
        // Si el BCH no responde, se sigue usando su última tasa oficial (de los últimos 10 días) en vez de cambiar a la
        // de referencia: así compra y venta no «saltan» a otro valor. El cron vuelve a intentar cada hora.
        $st = $pdo->prepare("SELECT * FROM tasas_cambio WHERE fuente = 'BCH' AND fecha <= ? AND fecha >= ? - INTERVAL 10 DAY ORDER BY fecha DESC LIMIT 1");
        $st->execute([date('Y-m-d'), date('Y-m-d')]);
        if ($u = $st->fetch(PDO::FETCH_ASSOC)) {
            $u['aviso'] = "No se pudo consultar el BCH ($errBch); se mantiene la tasa oficial del " . date('d/m/Y', strtotime($u['fecha'])) . " y se reintenta en una hora.";
            return $u;
        }
    }
    $t = tasaDesdeReferencia();
    // No pisa una tasa del BCH del mismo día
    $pdo->prepare("INSERT INTO tasas_cambio (fecha, referencia, fuente) VALUES (?, ?, 'referencia')
                   ON DUPLICATE KEY UPDATE referencia = IF(fuente = 'BCH', referencia, VALUES(referencia))")
        ->execute([date('Y-m-d'), $t['referencia']]);   // con la fecha de hoy: la fuente fecha su última actualización (a veces la de ayer) y así no se reconsulta cada 5 minutos
    $u = tasaUltima($pdo);
    if ($errBch) $u['aviso'] = "No se pudo usar el BCH ($errBch); se usó la tasa de referencia.";
    return $u;
}

/** Tasa más reciente guardada (o null). */
function tasaUltima(PDO $pdo, ?string $corte = null): ?array
{
    if (!tasaDisponible($pdo)) return null;
    $st = $pdo->prepare("SELECT * FROM tasas_cambio WHERE fecha <= ? ORDER BY fecha DESC LIMIT 1");
    $st->execute([$corte ?? date('Y-m-d')]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Tasa para valorar saldos en dólares al corte: compra del BCH (lo que pagan por tus dólares) o, si no hay, la referencia. */
function tasaValoracion(PDO $pdo, ?string $corte = null): float
{
    $t = tasaUltima($pdo, $corte);
    if (!$t) return 0.0;
    return (float)($t['compra'] ?: $t['referencia'] ?: 0);
}
