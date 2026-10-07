<?php
/**
 * salarios.php — Historial de sueldo de los colaboradores (colaborador_salarios).
 * Cada fila dice desde qué fecha rige un sueldo mensual; el sueldo vigente en una fecha es el de la
 * fila más reciente con «desde» ≤ esa fecha. Sin historial, rige colaboradores.salario_base.
 */

function salariosDisponible(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try { $ok = (bool)$pdo->query("SHOW TABLES LIKE 'colaborador_salarios'")->fetchColumn(); } catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

/** [colaborador_id => [[desde, salario_base, motivo, id], …] ordenado por fecha]. */
function salariosHistorial(PDO $pdo, int $cid, ?int $colaboradorId = null): array
{
    if (!salariosDisponible($pdo)) return [];
    $sql = "SELECT id, colaborador_id, desde, salario_base, motivo FROM colaborador_salarios WHERE cliente_id = ?" . ($colaboradorId ? " AND colaborador_id = ?" : '') . " ORDER BY desde, id";
    $st = $pdo->prepare($sql);
    $st->execute($colaboradorId ? [$cid, $colaboradorId] : [$cid]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['colaborador_id']][] = $r;
    return $out;
}

/** Sueldo mensual vigente en una fecha. */
function salarioVigente(array $historial, array $colaborador, string $fecha): float
{
    $vigente = null;
    foreach ($historial[(int)$colaborador['id']] ?? [] as $h) if ($h['desde'] <= $fecha) $vigente = (float)$h['salario_base'];
    return $vigente ?? (float)$colaborador['salario_base'];
}

/** Registra un ajuste de sueldo; si ya rige (desde ≤ hoy) y es el más reciente, actualiza el sueldo actual del colaborador. */
function salarioRegistrar(PDO $pdo, int $cid, int $colaboradorId, string $desde, float $salario, string $motivo, int $usuario): void
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || !strtotime($desde)) throw new Exception("Fecha inválida.");
    if ($salario < 0) throw new Exception("El sueldo no puede ser negativo.");
    $st = $pdo->prepare("SELECT id FROM colaboradores WHERE id = ? AND cliente_id = ?");
    $st->execute([$colaboradorId, $cid]);
    if (!$st->fetchColumn()) throw new Exception("Colaborador no encontrado.");
    $pdo->prepare("INSERT INTO colaborador_salarios (cliente_id, colaborador_id, desde, salario_base, motivo, usuario_id) VALUES (?, ?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE salario_base = VALUES(salario_base), motivo = VALUES(motivo)")
        ->execute([$cid, $colaboradorId, $desde, round($salario, 2), mb_substr(trim($motivo), 0, 255) ?: null, $usuario]);
    salarioSincronizar($pdo, $cid, $colaboradorId);
}

/** Edita un ajuste (fecha, sueldo o motivo) y vuelve a sincronizar el sueldo actual. */
function salarioEditar(PDO $pdo, int $cid, int $id, string $desde, float $salario, string $motivo): void
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || !strtotime($desde)) throw new Exception("Fecha inválida.");
    if ($salario < 0) throw new Exception("El sueldo no puede ser negativo.");
    $st = $pdo->prepare("SELECT colaborador_id FROM colaborador_salarios WHERE id = ? AND cliente_id = ?");
    $st->execute([$id, $cid]);
    $colId = (int)$st->fetchColumn();
    if (!$colId) throw new Exception("Ajuste no encontrado.");
    $st = $pdo->prepare("SELECT COUNT(*) FROM colaborador_salarios WHERE colaborador_id = ? AND desde = ? AND id <> ?");
    $st->execute([$colId, $desde, $id]);
    if ($st->fetchColumn()) throw new Exception("Ya hay otro ajuste con esa fecha.");
    $pdo->prepare("UPDATE colaborador_salarios SET desde = ?, salario_base = ?, motivo = ? WHERE id = ?")->execute([$desde, round($salario, 2), mb_substr(trim($motivo), 0, 255) ?: null, $id]);
    salarioSincronizar($pdo, $cid, $colId);
}

/** Deja salario_base del colaborador igual al sueldo vigente hoy según el historial. */
function salarioSincronizar(PDO $pdo, int $cid, int $colaboradorId): void
{
    $st = $pdo->prepare("SELECT salario_base FROM colaborador_salarios WHERE cliente_id = ? AND colaborador_id = ? AND desde <= CURDATE() ORDER BY desde DESC LIMIT 1");
    $st->execute([$cid, $colaboradorId]);
    $v = $st->fetchColumn();
    if ($v !== false) $pdo->prepare("UPDATE colaboradores SET salario_base = ? WHERE id = ? AND cliente_id = ?")->execute([$v, $colaboradorId, $cid]);
}
