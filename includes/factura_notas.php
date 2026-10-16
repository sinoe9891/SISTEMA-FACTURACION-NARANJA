<?php
/** Notas privadas: nunca se incorporan a la representación del PDF o correo. */
function facturaGuardarNotas(PDO $pdo, int $cid, int $id, array $datos): void
{
    if (!array_key_exists('notas_internas', $datos)) return;
    $nota = trim((string)$datos['notas_internas']);
    if (mb_strlen($nota) > 5000) throw new Exception('Las notas internas admiten hasta 5,000 caracteres.');
    $pdo->prepare('UPDATE facturas SET notas_internas = ? WHERE id = ? AND cliente_id = ?')->execute([$nota !== '' ? $nota : null, $id, $cid]);
}

/** Marca interna independiente del estado fiscal; preserva envíos de formularios antiguos. */
function facturaGuardarSubsanacion(PDO $pdo, int $cid, int $id, array $datos): void
{
    if (!isset($datos['subsanacion_presente'])) return;
    $valor = ($datos['requiere_subsanacion'] ?? '') === '1' ? 1 : 0;
    $pdo->prepare('UPDATE facturas SET requiere_subsanacion = ? WHERE id = ? AND cliente_id = ?')->execute([$valor, $id, $cid]);
}
