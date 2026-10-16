<?php
/** Notas privadas: nunca se incorporan a la representación del PDF o correo. */
function facturaGuardarNotas(PDO $pdo, int $cid, int $id, array $datos): void
{
    if (!array_key_exists('notas_internas', $datos)) return;
    $nota = trim((string)$datos['notas_internas']);
    if (mb_strlen($nota) > 5000) throw new Exception('Las notas internas admiten hasta 5,000 caracteres.');
    $pdo->prepare('UPDATE facturas SET notas_internas = ? WHERE id = ? AND cliente_id = ?')->execute([$nota !== '' ? $nota : null, $id, $cid]);
}
