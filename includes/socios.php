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
        $conSocio = socioRetiroDisponible($pdo);
        $st = $pdo->prepare("SELECT g.id, g.fecha, g.monto, g.descripcion, g.proveedor, g.notas, g.archivo_adjunto, g.archivo_nombre, " . ($conSocio ? "g.socio_id, s.nombre AS socio_nombre" : "NULL AS socio_id, NULL AS socio_nombre") . "
                             FROM gastos g " . ($conSocio ? "LEFT JOIN socios s ON s.id = g.socio_id" : "") . "
                             WHERE g.cliente_id = ? AND g.naturaleza = 'retiro' AND g.estado <> 'anulado'" . ($corte ? " AND g.fecha <= ?" : '') . " ORDER BY g.fecha DESC, g.id DESC");
        $st->execute($corte ? [$cid, $corte] : [$cid]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** ¿Los gastos guardan el socio del retiro? (migración 2026-10-26_retiro_socio) */
function socioRetiroDisponible(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) { try { $ok = (bool)$pdo->query("SHOW COLUMNS FROM gastos LIKE 'socio_id'")->fetchColumn(); } catch (Throwable $e) { $ok = false; } }
    return $ok;
}

/** Socios activos con su id (para elegir en un retiro). */
function sociosLista(PDO $pdo, int $cid): array
{
    try {
        $st = $pdo->prepare("SELECT id, nombre FROM socios WHERE cliente_id = ? AND activo = 1 ORDER BY id");
        $st->execute([$cid]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

/**
 * Socio de un gasto al guardarlo: obligatorio si es «Retiro de socio» (y de la empresa); en otra naturaleza, ninguno.
 * Devuelve el id o null. Sin socios registrados no se exige (se puede agregar después en Socios).
 */
function socioRetiroValidar(PDO $pdo, int $cid, string $naturaleza, $socioId): ?int
{
    if ($naturaleza !== 'retiro' || !socioRetiroDisponible($pdo)) return null;
    $id = (int)$socioId;
    if (!$id) {
        if (sociosLista($pdo, $cid)) throw new Exception("Elige de qué socio es el retiro.");
        return null;
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM socios WHERE id = ? AND cliente_id = ?");
    $st->execute([$id, $cid]);
    if (!$st->fetchColumn()) throw new Exception("Socio no válido.");
    return $id;
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
    if (!empty($retiro['socio_nombre'])) return $retiro['socio_nombre'];   // elegido al registrar el retiro
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
