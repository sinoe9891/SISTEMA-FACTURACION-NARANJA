<?php
/**
 * programas.php — Programas de un cliente (p. ej. Proyecto Aldea Global → PROGRESE).
 * Un contrato es «directo» al cliente o de uno de sus programas; la factura toma el programa de su contrato
 * (o el que se elija al facturar) y lo muestra como «Programa: PROGRESE», igual que los correos de cobro.
 * Requiere sql/migraciones/2026-10-19_programas.sql.
 */

function programasDisponible(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try { $ok = (bool)$pdo->query("SHOW TABLES LIKE 'programas'")->fetchColumn(); } catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

/** Programas de la empresa, agrupados por cliente: [receptor_id => [[id, nombre, activo], …]]. */
function programasPorCliente(PDO $pdo, int $cid, bool $soloActivos = true): array
{
    if (!programasDisponible($pdo)) return [];
    $st = $pdo->prepare("SELECT id, receptor_id, nombre, activo, notas FROM programas WHERE cliente_id = ?" . ($soloActivos ? " AND activo = 1" : "") . " ORDER BY nombre");
    $st->execute([$cid]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) $out[(int)$p['receptor_id']][] = ['id' => (int)$p['id'], 'nombre' => $p['nombre'], 'activo' => (int)$p['activo'], 'notas' => (string)$p['notas']];
    return $out;
}

function programaObtener(PDO $pdo, int $cid, int $id): ?array
{
    if (!$id || !programasDisponible($pdo)) return null;
    $st = $pdo->prepare("SELECT * FROM programas WHERE id = ? AND cliente_id = ?");
    $st->execute([$id, $cid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Valida que el programa sea del cliente (receptor). Devuelve el id o null («directo»). */
function programaValido(PDO $pdo, int $cid, int $receptorId, $programaId): ?int
{
    $id = (int)$programaId;
    if (!$id || !programasDisponible($pdo)) return null;
    $p = programaObtener($pdo, $cid, $id);
    if (!$p || (int)$p['receptor_id'] !== $receptorId) throw new Exception("El programa elegido no es de este cliente.");
    return $id;
}

function programaGuardar(PDO $pdo, int $cid, array $d): int
{
    $id = (int)($d['id'] ?? 0);
    $rid = (int)($d['receptor_id'] ?? 0);
    $nombre = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($d['nombre'] ?? ''))), 0, 120);
    if ($nombre === '') throw new Exception("Escribe el nombre del programa.");
    if ($id) {
        $p = programaObtener($pdo, $cid, $id);
        if (!$p) throw new Exception("Programa no encontrado.");
        $rid = (int)$p['receptor_id'];
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM clientes_factura WHERE id = ? AND cliente_id = ?");
    $st->execute([$rid, $cid]);
    if (!$st->fetchColumn()) throw new Exception("Cliente no válido.");
    $st = $pdo->prepare("SELECT id FROM programas WHERE receptor_id = ? AND nombre = ? AND id <> ?");
    $st->execute([$rid, $nombre, $id]);
    if ($st->fetchColumn()) throw new Exception("Este cliente ya tiene un programa con ese nombre.");
    $notas = mb_substr(trim((string)($d['notas'] ?? '')), 0, 255) ?: null;
    $activo = array_key_exists('activo', $d) ? (!empty($d['activo']) ? 1 : 0) : 1;
    if ($id) {
        $pdo->prepare("UPDATE programas SET nombre = ?, notas = ?, activo = ? WHERE id = ? AND cliente_id = ?")->execute([$nombre, $notas, $activo, $id, $cid]);
        return $id;
    }
    $pdo->prepare("INSERT INTO programas (cliente_id, receptor_id, nombre, notas, activo) VALUES (?, ?, ?, ?, ?)")->execute([$cid, $rid, $nombre, $notas, $activo]);
    return (int)$pdo->lastInsertId();
}

/** Programa del contrato (null = directo). */
function contratoPrograma(PDO $pdo, ?int $contratoId): ?int
{
    if (!$contratoId || !programasDisponible($pdo)) return null;
    $st = $pdo->prepare("SELECT programa_id FROM contratos WHERE id = ?");
    $st->execute([$contratoId]);
    return (int)$st->fetchColumn() ?: null;
}

/**
 * Programa de una factura al guardarla: el de su contrato si lo tiene; si no, el elegido en el formulario
 * (validado contra el cliente). Sin contrato ni elección, directo.
 */
function facturaProgramaResolver(PDO $pdo, int $cid, int $receptorId, ?int $contratoId, $elegido): ?int
{
    if (!programasDisponible($pdo)) return null;
    if ($contratoId && ($p = contratoPrograma($pdo, $contratoId))) return $p;
    try {
        return programaValido($pdo, $cid, $receptorId, $elegido);
    } catch (Exception $e) {
        return null;   // un programa de otro cliente (p. ej. se cambió el receptor) no se arrastra
    }
}

/** Al cambiar el programa de un contrato, sus facturas lo toman también. Devuelve cuántas cambiaron. */
function contratoProgramaPropagar(PDO $pdo, int $cid, int $contratoId, ?int $programaId): int
{
    if (!programasDisponible($pdo)) return 0;
    $st = $pdo->prepare("UPDATE facturas SET programa_id = ? WHERE contrato_id = ? AND cliente_id = ? AND NOT (programa_id <=> ?)");
    $st->execute([$programaId, $contratoId, $cid, $programaId]);
    return $st->rowCount();
}

/** Nombre del programa de una factura (o null). */
function facturaProgramaNombre(PDO $pdo, array $factura): ?string
{
    if (empty($factura['programa_id']) || !programasDisponible($pdo)) return null;
    $st = $pdo->prepare("SELECT nombre FROM programas WHERE id = ?");
    $st->execute([(int)$factura['programa_id']]);
    return $st->fetchColumn() ?: null;
}

/** Etiqueta pequeña para listas. */
function programaEtiqueta(?string $nombre): string
{
    return $nombre ? '<span class="badge rounded-pill text-bg-light border fw-normal ms-1" title="Programa"><i class="bi bi-diagram-3 me-1"></i>' . htmlspecialchars($nombre) . '</span>' : '';
}
