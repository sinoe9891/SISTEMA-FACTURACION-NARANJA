<?php
/**
 * socios.php — Aportes de socios (tabla socios_aportes) y retiros de socio (gastos con naturaleza «retiro»).
 * Lo usan la página Socios y el Balance general (dentro del patrimonio).
 */

function sociosDisponible(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try { $ok = (bool)$pdo->query("SHOW TABLES LIKE 'socios_aportes'")->fetchColumn(); } catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

/** Aportes vigentes, del más reciente al más antiguo (opcional: hasta un corte). */
function sociosAportes(PDO $pdo, int $cid, ?string $corte = null): array
{
    if (!sociosDisponible($pdo)) return [];
    $st = $pdo->prepare("SELECT * FROM socios_aportes WHERE cliente_id = ? AND anulado = 0" . ($corte ? " AND fecha <= ?" : '') . " ORDER BY fecha DESC, id DESC");
    $st->execute($corte ? [$cid, $corte] : [$cid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Retiros de socio: gastos con naturaleza «retiro» que no están anulados. */
function sociosRetiros(PDO $pdo, int $cid, ?string $corte = null): array
{
    try {
        $st = $pdo->prepare("SELECT id, fecha, monto, descripcion, proveedor, notas, archivo_adjunto FROM gastos
                             WHERE cliente_id = ? AND naturaleza = 'retiro' AND estado <> 'anulado'" . ($corte ? " AND fecha <= ?" : '') . " ORDER BY fecha DESC, id DESC");
        $st->execute($corte ? [$cid, $corte] : [$cid]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** Totales acumulados al corte (para el Balance). */
function sociosTotales(PDO $pdo, int $cid, string $corte): array
{
    return ['aportes' => round(array_sum(array_column(sociosAportes($pdo, $cid, $corte), 'monto')), 2),
            'retiros' => round(array_sum(array_column(sociosRetiros($pdo, $cid, $corte), 'monto')), 2)];
}

/**
 * A qué socio corresponde un retiro: busca en la descripción, el beneficiario y las notas el nombre del socio
 * (dos primeros nombres, o primer nombre y primer apellido, p. ej. «María José» o «Danny Velásquez»).
 * Solo con los socios registrados de la empresa. Si no se reconoce, «Sin asignar».
 */
function socioDeRetiro(array $retiro, array $socios): string
{
    $quitar = fn($t) => mb_strtolower(strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u']));
    $texto = $quitar(($retiro['descripcion'] ?? '') . ' ' . ($retiro['proveedor'] ?? '') . ' ' . ($retiro['notas'] ?? ''));
    foreach ($socios as $s) {
        $w = preg_split('/\s+/', $quitar(trim($s)));
        $claves = count($w) >= 2 ? [$w[0] . ' ' . $w[1]] : [];
        if (count($w) >= 3) $claves[] = $w[0] . ' ' . $w[count($w) - 2];
        foreach ($claves as $k) if (str_contains($texto, $k)) return $s;
    }
    return 'Sin asignar';
}

/** Socios registrados de la empresa (tabla socios). */
function sociosRegistrados(PDO $pdo, int $cid): array
{
    try {
        $st = $pdo->prepare("SELECT nombre FROM socios WHERE cliente_id = ? AND activo = 1 ORDER BY id");
        $st->execute([$cid]);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { return []; }
}

/** Socios de la empresa (tabla socios) más los nombres usados en aportes. */
function sociosNombres(PDO $pdo, int $cid): array
{
    if (!sociosDisponible($pdo)) return [];
    $n = [];
    try {
        $st = $pdo->prepare("SELECT nombre FROM socios WHERE cliente_id = ? AND activo = 1 ORDER BY id");
        $st->execute([$cid]);
        $n = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { $n = []; }
    $st = $pdo->prepare("SELECT DISTINCT socio FROM socios_aportes WHERE cliente_id = ? AND anulado = 0");
    $st->execute([$cid]);
    return array_values(array_unique(array_merge($n, $st->fetchAll(PDO::FETCH_COLUMN))));
}
