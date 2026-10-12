<?php
/**
 * proveedores.php — Catálogo de proveedores (a quién se le paga). Gastos y licencias lo eligen de una lista.
 * Logo: se descarga del sitio web del proveedor (favicon) o se sube; se guarda en uploads/proveedores/ y se
 * sirve con proveedor_logo.php (solo con sesión). Sin logo se muestra un ícono según el tipo.
 */

const PROVEEDOR_TIPOS = [
    'software'  => ['Software y suscripciones', 'bi-cloud-check', '#2563eb'],
    'hosting'   => ['Hosting y dominios', 'bi-hdd-network', '#0891b2'],
    'empresa'   => ['Empresa / comercio', 'bi-shop', '#d97706'],
    'persona'   => ['Persona', 'bi-person', '#7c3aed'],
    'banco'     => ['Banco / tarjeta', 'bi-bank', '#059669'],
    'gobierno'  => ['Gobierno e impuestos', 'bi-building-check', '#475569'],
    'otro'      => ['Otro', 'bi-three-dots', '#94a3b8'],
];

function proveedoresDisponible(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try { $ok = (bool)$pdo->query("SHOW TABLES LIKE 'proveedores'")->fetchColumn() && (bool)$pdo->query("SHOW COLUMNS FROM gastos LIKE 'proveedor_id'")->fetchColumn(); }
        catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

/** Carpeta de logos de la empresa (dentro de uploads, protegida). */
function proveedorLogoDir(int $cid): string
{
    $d = dirname(__DIR__) . '/clientes/naranjaymedia/includes/uploads/proveedores/' . $cid . '/';
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $d;
}

/** Lista de proveedores con lo pagado (gastos pagados) y cuántos gastos. */
function proveedoresLista(PDO $pdo, int $cid, bool $soloActivos = false): array
{
    if (!proveedoresDisponible($pdo)) return [];
    proveedoresAutovincular($pdo, $cid);
    $st = $pdo->prepare("SELECT p.*,
                                (SELECT COUNT(*) FROM gastos g WHERE g.proveedor_id = p.id AND g.cliente_id = p.cliente_id AND g.estado <> 'anulado') AS gastos_n,
                                (SELECT COALESCE(SUM(g.monto), 0) FROM gastos g WHERE g.proveedor_id = p.id AND g.cliente_id = p.cliente_id AND g.estado = 'pagado') AS pagado,
                                (SELECT MAX(g.fecha) FROM gastos g WHERE g.proveedor_id = p.id AND g.cliente_id = p.cliente_id AND g.estado = 'pagado') AS ultimo_pago,
                                (SELECT COUNT(*) FROM licencias l WHERE l.proveedor_id = p.id AND l.cliente_id = p.cliente_id) AS licencias_n
                         FROM proveedores p WHERE p.cliente_id = ?" . ($soloActivos ? " AND p.activo = 1" : '') . " ORDER BY p.nombre");
    $st->execute([$cid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** [id => nombre] de los activos (para los select). */
function proveedoresOpciones(PDO $pdo, int $cid): array
{
    if (!proveedoresDisponible($pdo)) return [];
    $st = $pdo->prepare("SELECT id, nombre, tipo, logo FROM proveedores WHERE cliente_id = ? AND activo = 1 ORDER BY nombre");
    $st->execute([$cid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function proveedorObtener(PDO $pdo, int $cid, int $id): ?array
{
    $st = $pdo->prepare("SELECT * FROM proveedores WHERE id = ? AND cliente_id = ?");
    $st->execute([$id, $cid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** «https://www.GoDaddy.com/es» → «godaddy.com» (o '' si no parece un dominio). */
function proveedorDominio(?string $sitio): string
{
    $s = strtolower(trim((string)$sitio));
    if ($s === '') return '';
    $s = preg_replace('#^[a-z]+://#', '', $s);
    $s = preg_replace('#^www\.#', '', explode('/', $s)[0]);
    return preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $s) ? $s : '';
}

/**
 * Descarga el logo (favicon de 64 px) del sitio web del proveedor y lo guarda. Devuelve el nombre del archivo o null.
 * Se pide al servicio público de íconos de Google; si falla, al de DuckDuckGo.
 */
function proveedorDescargarLogo(int $cid, int $id, string $sitio): ?string
{
    $dom = proveedorDominio($sitio);
    if ($dom === '') return null;
    foreach (['https://www.google.com/s2/favicons?domain=' . rawurlencode($dom) . '&sz=64', 'https://icons.duckduckgo.com/ip3/' . rawurlencode($dom) . '.ico'] as $url) {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_MAXFILESIZE => 512000]);
        $b = curl_exec($c);
        $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);
        if ($b === false || $code !== 200 || strlen($b) < 100) continue;
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($b) ?: '';
        $ext = ['image/png' => 'png', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/svg+xml' => 'svg'][$mime] ?? null;
        if (!$ext || $ext === 'svg') continue;   // SVG externo no se guarda (podría traer scripts)
        $nombre = $id . '_' . substr(sha1($b), 0, 8) . '.' . $ext;
        if (file_put_contents(proveedorLogoDir($cid) . $nombre, $b) === false) return null;
        return $nombre;
    }
    return null;
}

/** <img> del logo o el ícono del tipo (HTML listo). $tam en px. */
function proveedorAvatar(?array $p, int $tam = 28): string
{
    if (!$p) return '';
    $tipo = PROVEEDOR_TIPOS[$p['tipo'] ?? 'otro'] ?? PROVEEDOR_TIPOS['otro'];
    $estilo = "width:{$tam}px;height:{$tam}px;border-radius:" . round($tam / 4) . "px;";
    if (!empty($p['logo'])) {
        return '<img src="proveedor_logo?id=' . (int)$p['id'] . '&v=' . rawurlencode((string)$p['logo']) . '" alt="" loading="lazy" style="' . $estilo . 'object-fit:contain;background:#fff;border:1px solid #e2e8f0;padding:2px;vertical-align:middle" title="' . htmlspecialchars($p['nombre'] ?? '') . '">';
    }
    return '<span style="' . $estilo . 'display:inline-flex;align-items:center;justify-content:center;background:' . $tipo[2] . '1a;color:' . $tipo[2] . ';vertical-align:middle" title="' . htmlspecialchars($tipo[0]) . '"><i class="bi ' . $tipo[1] . '"></i></span>';
}

/** Busca un proveedor por su nombre (sin importar mayúsculas ni tildes). */
function proveedorPorNombre(PDO $pdo, int $cid, string $nombre): ?array
{
    $st = $pdo->prepare("SELECT * FROM proveedores WHERE cliente_id = ? AND nombre = ? COLLATE utf8mb4_general_ci LIMIT 1");
    $st->execute([$cid, trim($nombre)]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Crea (o devuelve si ya existe con ese nombre) un proveedor. Descarga su logo si trae sitio web. */
function proveedorGuardar(PDO $pdo, int $cid, array $d, int $id = 0): array
{
    $nombre = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($d['nombre'] ?? ''))), 0, 150);
    if ($nombre === '') throw new Exception("Escribe el nombre del proveedor.");
    $tipo = isset(PROVEEDOR_TIPOS[$d['tipo'] ?? '']) ? $d['tipo'] : 'empresa';
    $sitio = trim((string)($d['sitio_web'] ?? ''));
    if ($sitio !== '' && proveedorDominio($sitio) === '') throw new Exception("El sitio web no es válido (ej. godaddy.com).");
    $correo = trim((string)($d['correo'] ?? ''));
    if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) throw new Exception("El correo no es válido.");
    $otro = proveedorPorNombre($pdo, $cid, $nombre);
    if ($otro && (int)$otro['id'] !== $id) {
        if (!$id) return $otro;   // alta rápida desde un select: ya existe, se usa ese
        throw new Exception("Ya existe un proveedor llamado «{$otro['nombre']}».");
    }
    $vals = [$nombre, $tipo, mb_substr(trim((string)($d['rtn'] ?? '')), 0, 20) ?: null, $sitio ?: null, mb_substr(trim((string)($d['telefono'] ?? '')), 0, 40) ?: null, $correo ?: null, trim((string)($d['notas'] ?? '')) ?: null];
    if ($id) {
        $ant = proveedorObtener($pdo, $cid, $id);
        if (!$ant) throw new Exception("El proveedor no existe.");
        $pdo->prepare("UPDATE proveedores SET nombre = ?, tipo = ?, rtn = ?, sitio_web = ?, telefono = ?, correo = ?, notas = ? WHERE id = ? AND cliente_id = ?")->execute([...$vals, $id, $cid]);
        // El nombre nuevo también en sus gastos y licencias
        $pdo->prepare("UPDATE gastos SET proveedor = ? WHERE cliente_id = ? AND proveedor_id = ?")->execute([$nombre, $cid, $id]);
        $pdo->prepare("UPDATE licencias SET proveedor = ? WHERE cliente_id = ? AND proveedor_id = ?")->execute([$nombre, $cid, $id]);
        if ($sitio !== '' && (proveedorDominio($sitio) !== proveedorDominio($ant['sitio_web']) || empty($ant['logo']))) {
            if ($logo = proveedorDescargarLogo($cid, $id, $sitio)) $pdo->prepare("UPDATE proveedores SET logo = ? WHERE id = ?")->execute([$logo, $id]);
        }
    } else {
        $pdo->prepare("INSERT INTO proveedores (nombre, tipo, rtn, sitio_web, telefono, correo, notas, cliente_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")->execute([...$vals, $cid]);
        $id = (int)$pdo->lastInsertId();
        if ($sitio !== '' && ($logo = proveedorDescargarLogo($cid, $id, $sitio))) $pdo->prepare("UPDATE proveedores SET logo = ? WHERE id = ?")->execute([$logo, $id]);
    }
    return proveedorObtener($pdo, $cid, $id);
}

/** Proveedor elegido en un formulario (proveedor_id) → [id, nombre]; si no hay id, se usa el texto escrito. */
function proveedorDeFormulario(PDO $pdo, int $cid, $proveedorId, ?string $texto): array
{
    if (proveedoresDisponible($pdo) && (int)$proveedorId > 0 && ($p = proveedorObtener($pdo, $cid, (int)$proveedorId))) return [(int)$p['id'], $p['nombre']];
    $texto = trim((string)$texto);
    if ($texto !== '' && proveedoresDisponible($pdo) && ($p = proveedorPorNombre($pdo, $cid, $texto))) return [(int)$p['id'], $p['nombre']];
    return [null, $texto !== '' ? mb_substr($texto, 0, 150) : null];
}

/**
 * Liga gastos (o licencias) al proveedor del catálogo según el nombre guardado. $antes = nombre que tenía antes de editar:
 * si no cambió, se conserva el proveedor ligado (p. ej. los gastos viejos con otra forma de escribir el nombre).
 */
function proveedorVincular(PDO $pdo, int $cid, string $tabla, array $ids, ?string $antes = null): void
{
    if (!proveedoresDisponible($pdo) || !in_array($tabla, ['gastos', 'licencias'], true)) return;
    $st = $pdo->prepare("SELECT id, proveedor, proveedor_id FROM $tabla WHERE cliente_id = ? AND id = ?");
    $up = $pdo->prepare("UPDATE $tabla SET proveedor_id = ? WHERE id = ? AND cliente_id = ?");
    foreach (array_filter(array_map('intval', $ids)) as $id) {
        $st->execute([$cid, $id]);
        if (!($r = $st->fetch(PDO::FETCH_ASSOC))) continue;
        $nombre = trim((string)$r['proveedor']);
        $p = $nombre !== '' ? proveedorPorNombre($pdo, $cid, $nombre) : null;
        // Sin coincidencia: si el nombre no cambió se queda el proveedor que tenía; si cambió, queda sin proveedor
        $nuevo = $p ? (int)$p['id'] : (($antes !== null && mb_strtolower(trim($antes)) === mb_strtolower($nombre)) ? $r['proveedor_id'] : null);
        if ((string)$nuevo !== (string)$r['proveedor_id']) $up->execute([$nuevo, $id, $cid]);
    }
}

/** Liga por nombre los gastos que aún no tienen proveedor (p. ej. los que crea la recurrencia). */
function proveedoresAutovincular(PDO $pdo, int $cid): void
{
    if (!proveedoresDisponible($pdo)) return;
    $pdo->prepare("UPDATE gastos g JOIN proveedores p ON p.cliente_id = g.cliente_id AND p.nombre = g.proveedor COLLATE utf8mb4_unicode_ci
                   SET g.proveedor_id = p.id WHERE g.cliente_id = ? AND g.proveedor_id IS NULL")->execute([$cid]);
}
