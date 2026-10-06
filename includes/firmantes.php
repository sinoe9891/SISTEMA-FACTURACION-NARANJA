<?php
/**
 * firmantes.php — Quién firma los documentos de pago (bouchers y recibos): Elaborado por, Revisado, Autorizado y Vo.Bo.
 * Se configuran en Configuración → Firmas de documentos (nombre, cargo e imagen de firma), por empresa.
 * Si un firmante no está configurado, el documento deja la línea en blanco («Elaborado por» usa al usuario que registró).
 */
require_once __DIR__ . '/firmas.php';

const FIRMANTES_ROLES = ['elaborado' => 'Elaborado por', 'autorizado' => 'Autorizado por', 'vobo' => 'Vo.Bo.'];

function firmantesDisponible(PDO $pdo): bool
{
    static $ok = null;
    return $ok ??= (bool)$pdo->query("SHOW TABLES LIKE 'documento_firmantes'")->fetchColumn();
}

/** [rol => ['nombre','cargo','firma' (ruta relativa o null)]] de la empresa. */
function firmantesLista(PDO $pdo, int $cid): array
{
    $out = array_fill_keys(array_keys(FIRMANTES_ROLES), ['nombre' => '', 'cargo' => '', 'firma' => null]);
    if (!firmantesDisponible($pdo)) return $out;
    $st = $pdo->prepare("SELECT rol, nombre, cargo, firma FROM documento_firmantes WHERE cliente_id = ?");
    $st->execute([$cid]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['rol']] = ['nombre' => (string)$r['nombre'], 'cargo' => (string)$r['cargo'], 'firma' => $r['firma']];
    return $out;
}

/** Firmantes con la firma ya en base64 (para incrustarla en el PDF). $uploads = carpeta includes/uploads/. */
function firmantesParaPdf(PDO $pdo, int $cid, string $uploads): array
{
    $out = [];
    foreach (firmantesLista($pdo, $cid) as $rol => $f) {
        $ruta = $f['firma'] ? rtrim($uploads, '/') . '/firmas/' . $f['firma'] : null;
        $f['firma_b64'] = $ruta && is_file($ruta) ? 'data:image/png;base64,' . base64_encode(file_get_contents($ruta)) : '';
        $out[$rol] = $f;
    }
    return $out;
}
