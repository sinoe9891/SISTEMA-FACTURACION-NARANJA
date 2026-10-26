<?php
/**
 * gastos_operativos.php — Gastos operativos de clientes: lo que se gasta por cada cliente (pauta en Google, Meta o TikTok,
 * combustible, alimentación en visitas de campo…) y cómo se justifica (comprobantes y reportes adjuntos).
 *
 * - El rol «operativo» (Gastos operativos) solo ve y registra gastos de sus clientes asignados (usuarios_clientes).
 * - Montos en lempiras o dólares: en USD se guarda la tasa y el equivalente en lempiras.
 * - Pauta (tipos con es_pauta): campaña y fechas de inicio y fin.
 * - Estados: pendiente → aprobado o rechazado (el administrador). Aprobado puede pasar a Gastos de la empresa
 *   (con el cliente para cobrárselo al facturar) o ligarse a un gasto ya registrado. El colaborador solo edita lo pendiente o rechazado.
 * Requiere sql/migraciones/2026-10-19_gastos_operativos.sql.
 */
require_once __DIR__ . '/moneda_factura.php';

const GASTO_OP_ESTADOS = [
    'pendiente' => ['Por revisar', '#fef3c7', '#92400e', 'bi-hourglass-split'],
    'aprobado' => ['Aprobado', '#dcfce7', '#166534', 'bi-check-circle'],
    'rechazado' => ['Rechazado', '#fee2e2', '#991b1b', 'bi-x-circle'],
];
const GASTO_OP_METODOS = ['tarjeta' => 'Tarjeta de crédito / débito', 'transferencia' => 'Transferencia', 'efectivo' => 'Efectivo (caja de la empresa)',
    'propio' => 'Pagado por el colaborador (reembolso)', 'otro' => 'Otro'];
const GASTO_OP_ARCHIVO_TIPOS = ['comprobante' => 'Comprobante', 'reporte' => 'Reporte', 'otro' => 'Otro'];
// [nombre, icono, es pauta, categoría de Gastos sugerida]
const GASTO_OP_TIPOS_DEFECTO = [
    ['Google Ads', 'bi-google', 1, 'Publicidad y pautas'], ['Meta Ads (Facebook e Instagram)', 'bi-meta', 1, 'Publicidad y pautas'],
    ['TikTok Ads', 'bi-tiktok', 1, 'Publicidad y pautas'], ['LinkedIn Ads', 'bi-linkedin', 1, 'Publicidad y pautas'],
    ['Combustible', 'bi-fuel-pump', 0, 'Combustible'], ['Alimentación en visitas de campo', 'bi-cup-hot', 0, 'Alimentación'],
    ['Transporte, peajes y parqueo', 'bi-car-front', 0, 'Viaticos'], ['Hospedaje', 'bi-building', 0, 'Viaticos'],
    ['Impresiones y materiales', 'bi-printer', 0, 'Otros gastos'], ['Otro gasto operativo', 'bi-three-dots', 0, 'Otros gastos'],
];
const GASTO_OP_MAX_BYTES = 10 * 1024 * 1024;
const GASTO_OP_EXT = ['jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'], 'webp' => ['image/webp'], 'pdf' => ['application/pdf'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
    'xls' => ['application/vnd.ms-excel', 'application/octet-stream', 'application/CDFV2'], 'csv' => ['text/csv', 'text/plain', 'application/csv']];

function gastosOpDisponible(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try { $ok = (bool)$pdo->query("SHOW TABLES LIKE 'gastos_operativos'")->fetchColumn(); } catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

function gastosOpEsAdmin(string $rol): bool
{
    return in_array($rol, ['admin', 'superadmin'], true);
}

function gastosOpDir(): string
{
    return __DIR__ . '/../clientes/naranjaymedia/includes/uploads/gastos_operativos/';
}

/** Tipos de la empresa (la primera vez se crean los de fábrica, con su categoría de Gastos si existe). */
function gastosOpTipos(PDO $pdo, int $cid, bool $soloActivos = false): array
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM gastos_op_tipos WHERE cliente_id = ?");
    $st->execute([$cid]);
    if (!(int)$st->fetchColumn()) {
        $cat = $pdo->prepare("SELECT id FROM categorias_gastos WHERE cliente_id = ? AND nombre = ? LIMIT 1");
        $ins = $pdo->prepare("INSERT IGNORE INTO gastos_op_tipos (cliente_id, nombre, icono, es_pauta, categoria_id, orden) VALUES (?, ?, ?, ?, ?, ?)");
        foreach (GASTO_OP_TIPOS_DEFECTO as $i => [$n, $ic, $p, $c]) {
            $cat->execute([$cid, $c]);
            $ins->execute([$cid, $n, $ic, $p, $cat->fetchColumn() ?: null, $i + 1]);
        }
    }
    $st = $pdo->prepare("SELECT t.*, cg.nombre AS categoria FROM gastos_op_tipos t LEFT JOIN categorias_gastos cg ON cg.id = t.categoria_id
                         WHERE t.cliente_id = ?" . ($soloActivos ? " AND t.activo = 1" : "") . " ORDER BY t.activo DESC, t.orden, t.nombre");
    $st->execute([$cid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function gastosOpTipoGuardar(PDO $pdo, int $cid, array $d): int
{
    $id = (int)($d['id'] ?? 0);
    $nombre = mb_substr(trim((string)($d['nombre'] ?? '')), 0, 80);
    if ($nombre === '') throw new Exception("Escribe el nombre del tipo.");
    $icono = preg_match('/^bi-[a-z0-9-]{1,36}$/', (string)($d['icono'] ?? '')) ? $d['icono'] : 'bi-tag';
    $cat = (int)($d['categoria_id'] ?? 0) ?: null;
    if ($cat) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM categorias_gastos WHERE id = ? AND cliente_id = ?");
        $st->execute([$cat, $cid]);
        if (!$st->fetchColumn()) throw new Exception("Categoría no válida.");
    }
    $st = $pdo->prepare("SELECT id FROM gastos_op_tipos WHERE cliente_id = ? AND nombre = ? AND id <> ?");
    $st->execute([$cid, $nombre, $id]);
    if ($st->fetchColumn()) throw new Exception("Ya existe un tipo con ese nombre.");
    $vals = [$nombre, $icono, !empty($d['es_pauta']) ? 1 : 0, $cat, isset($d['activo']) ? (!empty($d['activo']) ? 1 : 0) : 1];
    if ($id) {
        $st = $pdo->prepare("UPDATE gastos_op_tipos SET nombre = ?, icono = ?, es_pauta = ?, categoria_id = ?, activo = ? WHERE id = ? AND cliente_id = ?");
        $st->execute([...$vals, $id, $cid]);
        if (!$st->rowCount()) {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM gastos_op_tipos WHERE id = ? AND cliente_id = ?");
            $chk->execute([$id, $cid]);
            if (!$chk->fetchColumn()) throw new Exception("Tipo no encontrado.");
        }
        return $id;
    }
    $pdo->prepare("INSERT INTO gastos_op_tipos (nombre, icono, es_pauta, categoria_id, activo, cliente_id, orden) VALUES (?, ?, ?, ?, ?, ?, 100)")->execute([...$vals, $cid]);
    return (int)$pdo->lastInsertId();
}

/**
 * Lo que puede ver el usuario: null = todo (administrador); si no, ['c' => clientes completos, 'p' => programas, 'k' => contratos].
 * Un cliente completo incluye sus programas; un programa o un contrato (p. ej. el rotativo de Texaco) solo eso.
 */
function gastosOpPermitidos(PDO $pdo, int $uid, string $rol): ?array
{
    if (gastosOpEsAdmin($rol)) return null;
    $conAmbito = gastosOpConProgramas($pdo);
    $st = $pdo->prepare("SELECT receptor_id, " . ($conAmbito ? "programa_id, contrato_id" : "NULL AS programa_id, NULL AS contrato_id") . " FROM usuarios_clientes WHERE usuario_id = ?");
    $st->execute([$uid]);
    $perm = ['c' => [], 'p' => [], 'k' => []];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['contrato_id']) $perm['k'][] = (int)$r['contrato_id'];
        elseif ($r['programa_id']) $perm['p'][] = (int)$r['programa_id'];
        else $perm['c'][] = (int)$r['receptor_id'];
    }
    return $perm;
}

function gastosOpSinAcceso(?array $perm): bool
{
    return $perm !== null && !$perm['c'] && !$perm['p'] && !$perm['k'];
}

/** ¿Puede ver un gasto (o registrar en esa cuenta)? */
function gastosOpPuede(?array $perm, int $receptor, ?int $programa, ?int $contrato): bool
{
    if ($perm === null) return true;
    return in_array($receptor, $perm['c'], true) || ($programa && in_array($programa, $perm['p'], true)) || ($contrato && in_array($contrato, $perm['k'], true));
}

function gastosOpConProgramas(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) { try { $ok = (bool)$pdo->query("SHOW COLUMNS FROM gastos_operativos LIKE 'programa_id'")->fetchColumn(); } catch (Throwable $e) { $ok = false; } }
    return $ok;
}

/**
 * Cuentas a las que se carga un gasto: el cliente (directo), uno de sus programas (p. ej. Aldea Global → PROGRESE) o un contrato
 * rotativo (varias empresas, p. ej. Texaco Valeriano). Cada una: clave (c:id, p:id, k:id), nombre, grupo, receptor_id, programa_id, contrato_id.
 * $perm null = todas (para asignar o para el administrador).
 */
function gastosOpCuentas(PDO $pdo, int $cid, ?array $perm): array
{
    if (gastosOpSinAcceso($perm)) return [];
    $out = [];
    $st = $pdo->prepare("SELECT id, nombre FROM clientes_factura WHERE cliente_id = ? ORDER BY nombre");
    $st->execute([$cid]);
    $nombres = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    $progs = [];
    if (gastosOpConProgramas($pdo)) {
        $st = $pdo->prepare("SELECT id, receptor_id, nombre FROM programas WHERE cliente_id = ? AND activo = 1 ORDER BY nombre");
        $st->execute([$cid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) $progs[(int)$p['receptor_id']][] = $p;
    }
    foreach ($nombres as $rid => $nom) {
        $rid = (int)$rid;
        $todo = $perm === null || in_array($rid, $perm['c'], true);
        if ($todo) $out[] = ['clave' => "c:$rid", 'nombre' => $nom . (isset($progs[$rid]) ? ' (directo)' : ''), 'grupo' => 'Clientes', 'receptor_id' => $rid, 'programa_id' => null, 'contrato_id' => null];
        foreach ($progs[$rid] ?? [] as $p)
            if ($todo || in_array((int)$p['id'], $perm['p'], true))
                $out[] = ['clave' => 'p:' . $p['id'], 'nombre' => $nom . ' → ' . $p['nombre'], 'grupo' => 'Programas', 'receptor_id' => $rid, 'programa_id' => (int)$p['id'], 'contrato_id' => null];
    }
    if (gastosOpConProgramas($pdo)) {
        $st = $pdo->prepare("SELECT c.id, c.receptor_id, c.nombre_contrato, (SELECT COUNT(*) FROM contratos_clientes_rotativos r WHERE r.contrato_id = c.id AND r.activo = 1) AS empresas,
                                    (SELECT GROUP_CONCAT(cf.nombre ORDER BY r.orden SEPARATOR ' · ') FROM contratos_clientes_rotativos r JOIN clientes_factura cf ON cf.id = r.receptor_id WHERE r.contrato_id = c.id AND r.activo = 1) AS empresas_nombres
                             FROM contratos c WHERE c.cliente_id = ? AND c.tipo_contrato = 'rotativo' AND c.estado = 'activo' ORDER BY c.nombre_contrato");
        $st->execute([$cid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $k)
            if ($perm === null || in_array((int)$k['id'], $perm['k'], true))
                $out[] = ['clave' => 'k:' . $k['id'], 'nombre' => ($k['nombre_contrato'] ?: 'Contrato #' . $k['id']) . ' (contrato de ' . (int)$k['empresas'] . ' empresas)', 'grupo' => 'Contratos con varias empresas',
                    'receptor_id' => (int)$k['receptor_id'], 'programa_id' => null, 'contrato_id' => (int)$k['id'], 'empresas' => (string)$k['empresas_nombres']];
    }
    return $out;
}

/** Cuenta elegida (c:5, p:3, k:21; o receptor_id suelto) → [receptor_id, programa_id, contrato_id], validada contra la empresa. */
function gastosOpCuentaResolver(PDO $pdo, int $cid, array $d): array
{
    $clave = (string)($d['cuenta'] ?? '');
    if ($clave === '' && !empty($d['receptor_id'])) $clave = 'c:' . (int)$d['receptor_id'];
    if (!preg_match('/^([cpk]):(\d+)$/', $clave, $m)) throw new Exception("Elige el cliente, programa o contrato.");
    $id = (int)$m[2];
    if ($m[1] === 'c') {
        $st = $pdo->prepare("SELECT COUNT(*) FROM clientes_factura WHERE id = ? AND cliente_id = ?");
        $st->execute([$id, $cid]);
        if (!$st->fetchColumn()) throw new Exception("Cliente no válido.");
        return [$id, null, null];
    }
    if (!gastosOpConProgramas($pdo)) throw new Exception("Falta instalar sql/migraciones/2026-10-19_programas.sql.");
    if ($m[1] === 'p') {
        $st = $pdo->prepare("SELECT receptor_id FROM programas WHERE id = ? AND cliente_id = ?");
        $st->execute([$id, $cid]);
        $r = $st->fetchColumn();
        if ($r === false) throw new Exception("Programa no válido.");
        return [(int)$r, $id, null];
    }
    $st = $pdo->prepare("SELECT receptor_id, programa_id FROM contratos WHERE id = ? AND cliente_id = ?");
    $st->execute([$id, $cid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new Exception("Contrato no válido.");
    return [(int)$r['receptor_id'], (int)$r['programa_id'] ?: null, $id];
}

/** Usuarios con el rol Gastos operativos y sus cuentas asignadas (claves c:/p:/k:). */
function gastosOpResponsables(PDO $pdo, int $cid): array
{
    $st = $pdo->prepare("SELECT id, nombre, correo, estado FROM usuarios WHERE cliente_id = ? AND rol = 'operativo' ORDER BY nombre");
    $st->execute([$cid]);
    $us = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($us as &$u) {
        $perm = gastosOpPermitidos($pdo, (int)$u['id'], 'operativo');
        $u['claves'] = array_merge(array_map(fn($i) => "c:$i", $perm['c']), array_map(fn($i) => "p:$i", $perm['p']), array_map(fn($i) => "k:$i", $perm['k']));
    }
    return $us;
}

/** Reemplaza lo asignado al usuario por las claves dadas (c:id cliente completo, p:id programa, k:id contrato). */
function gastosOpAsignar(PDO $pdo, int $cid, int $adminId, int $usuarioId, array $claves): void
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE id = ? AND cliente_id = ? AND rol = 'operativo'");
    $st->execute([$usuarioId, $cid]);
    if (!$st->fetchColumn()) throw new Exception("El usuario no tiene el rol Gastos operativos.");
    $filas = [];
    foreach (array_unique(array_map('strval', $claves)) as $k) {
        if (ctype_digit($k)) $k = "c:$k";   // compatibilidad: id de cliente suelto
        [$r, $p, $c] = gastosOpCuentaResolver($pdo, $cid, ['cuenta' => $k]);
        $filas[] = $k[0] === 'c' ? [$r, null, null] : ($k[0] === 'p' ? [$r, $p, null] : [$r, null, $c]);
    }
    $pdo->prepare("DELETE FROM usuarios_clientes WHERE usuario_id = ?")->execute([$usuarioId]);
    $conAmbito = gastosOpConProgramas($pdo);
    $ins = $pdo->prepare($conAmbito ? "INSERT INTO usuarios_clientes (usuario_id, receptor_id, programa_id, contrato_id, asignado_por) VALUES (?, ?, ?, ?, ?)"
                                    : "INSERT IGNORE INTO usuarios_clientes (usuario_id, receptor_id, asignado_por) VALUES (?, ?, ?)");
    foreach ($filas as [$r, $p, $c]) $ins->execute($conAmbito ? [$usuarioId, $r, $p, $c, $adminId] : [$usuarioId, $r, $adminId]);
}

/** Lista con filtros: desde, hasta, receptor, tipo, estado, moneda, usuario, sin_archivo. */
function gastosOpLista(PDO $pdo, int $cid, ?array $permitidos, array $f = []): array
{
    if (gastosOpSinAcceso($permitidos)) return [];
    $w = ["g.cliente_id = ?", "g.pagador = " . $pdo->quote(($f['pagador'] ?? 'empresa') === 'cliente' ? 'cliente' : 'empresa')];
    $p = [$cid];
    $conAmbito = gastosOpConProgramas($pdo);
    if ($permitidos !== null) {
        $o = [];
        foreach (['c' => 'g.receptor_id', 'p' => 'g.programa_id', 'k' => 'g.contrato_id'] as $k => $col)
            if ($permitidos[$k] && ($k === 'c' || $conAmbito)) { $o[] = "$col IN (" . implode(',', array_map('intval', $permitidos[$k])) . ")"; }
        $w[] = '(' . ($o ? implode(' OR ', $o) : '0') . ')';
    }
    if (!empty($f['cuenta']) && preg_match('/^([cpk]):(\d+)$/', (string)$f['cuenta'], $m)) {
        $w[] = ['c' => 'g.receptor_id', 'p' => 'g.programa_id', 'k' => 'g.contrato_id'][$m[1]] . ' = ?';
        $p[] = (int)$m[2];
    }
    if (!empty($f['tarjeta_cliente'])) { $w[] = "(g.tarjeta_cliente_alias LIKE ? OR g.tarjeta_cliente_ultimos = ?)"; $p[] = '%' . $f['tarjeta_cliente'] . '%'; $p[] = $f['tarjeta_cliente']; }
    if (!empty($f['desde'])) { $w[] = "g.fecha >= ?"; $p[] = $f['desde']; }
    if (!empty($f['hasta'])) { $w[] = "g.fecha <= ?"; $p[] = $f['hasta']; }
    foreach (['receptor' => 'g.receptor_id', 'tipo' => 'g.tipo_id', 'usuario' => 'g.usuario_id'] as $k => $col)
        if (!empty($f[$k])) { $w[] = "$col = ?"; $p[] = (int)$f[$k]; }
    if (!empty($f['estado']) && isset(GASTO_OP_ESTADOS[$f['estado']])) { $w[] = "g.estado = ?"; $p[] = $f['estado']; }
    if (!empty($f['moneda']) && in_array($f['moneda'], ['HNL', 'USD'], true)) { $w[] = "g.moneda = ?"; $p[] = $f['moneda']; }
    $st = $pdo->prepare("SELECT g.*, cf.nombre AS cliente, t.nombre AS tipo, t.icono AS tipo_icono, t.es_pauta, u.nombre AS registrado_por, r.nombre AS revisado_por_nombre,
                                CASE WHEN g.pagador='cliente' THEN CONCAT(g.tarjeta_cliente_alias, ' •••• ', g.tarjeta_cliente_ultimos) ELSE CONCAT(tj.banco, ' •••• ', tj.ultimos_digitos) END AS tarjeta, " . gastosOpSqlCuenta($pdo) . ",
                                (SELECT COUNT(*) FROM gastos_op_archivos a WHERE a.gasto_op_id = g.id) AS archivos
                         FROM gastos_operativos g
                         JOIN clientes_factura cf ON cf.id = g.receptor_id
                         JOIN gastos_op_tipos t ON t.id = g.tipo_id
                         LEFT JOIN usuarios u ON u.id = g.usuario_id
                         LEFT JOIN usuarios r ON r.id = g.revisado_por
                         LEFT JOIN tarjetas tj ON tj.id = g.tarjeta_id
                         WHERE " . implode(' AND ', $w) . " ORDER BY g.fecha DESC, g.id DESC");
    $st->execute($p);
    $out = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($f['sin_archivo'])) $out = array_values(array_filter($out, fn($g) => !(int)$g['archivos']));
    return $out;
}

function gastosOpObtener(PDO $pdo, int $cid, int $id, ?array $permitidos): array
{
    $l = gastosOpListaPorId($pdo, $cid, $id);
    if (!$l || !gastosOpPuede($permitidos, (int)$l['receptor_id'], (int)($l['programa_id'] ?? 0) ?: null, (int)($l['contrato_id'] ?? 0) ?: null)) throw new Exception("Gasto no encontrado.");
    return $l;
}

/** Columnas con el programa y el contrato del gasto (para mostrar a qué se cargó). */
function gastosOpSqlCuenta(PDO $pdo): string
{
    return gastosOpConProgramas($pdo)
        ? "(SELECT pg.nombre FROM programas pg WHERE pg.id = g.programa_id) AS programa, (SELECT ck.nombre_contrato FROM contratos ck WHERE ck.id = g.contrato_id) AS contrato"
        : "NULL AS programa, NULL AS contrato";
}

/** Texto de la cuenta: «Cliente → Programa» o «Contrato (rotativo)». */
function gastosOpCuentaTexto(array $g): string
{
    if (!empty($g['contrato'])) return $g['contrato'];
    return $g['cliente'] . (!empty($g['programa']) ? ' → ' . $g['programa'] : '');
}

function gastosOpListaPorId(PDO $pdo, int $cid, int $id): ?array
{
    $st = $pdo->prepare("SELECT g.*, cf.nombre AS cliente, t.nombre AS tipo, t.es_pauta, t.categoria_id AS tipo_categoria_id, u.nombre AS registrado_por, r.nombre AS revisado_por_nombre,
                                CASE WHEN g.pagador='cliente' THEN CONCAT(g.tarjeta_cliente_alias, ' •••• ', g.tarjeta_cliente_ultimos) ELSE CONCAT(tj.banco, ' •••• ', tj.ultimos_digitos) END AS tarjeta, " . gastosOpSqlCuenta($pdo) . "
                         FROM gastos_operativos g JOIN clientes_factura cf ON cf.id = g.receptor_id JOIN gastos_op_tipos t ON t.id = g.tipo_id
                         LEFT JOIN usuarios u ON u.id = g.usuario_id LEFT JOIN usuarios r ON r.id = g.revisado_por LEFT JOIN tarjetas tj ON tj.id = g.tarjeta_id
                         WHERE g.id = ? AND g.cliente_id = ?");
    $st->execute([$id, $cid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function gastosOpArchivos(PDO $pdo, int $id): array
{
    $st = $pdo->prepare("SELECT a.*, u.nombre AS subido_por FROM gastos_op_archivos a LEFT JOIN usuarios u ON u.id = a.usuario_id WHERE a.gasto_op_id = ? ORDER BY a.id");
    $st->execute([$id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function gastosOpBitacora(PDO $pdo, int $id): array
{
    $st = $pdo->prepare("SELECT b.*, u.nombre AS usuario FROM gastos_op_bitacora b LEFT JOIN usuarios u ON u.id = b.usuario_id WHERE b.gasto_op_id = ? ORDER BY b.id DESC");
    $st->execute([$id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function gastosOpLog(PDO $pdo, int $id, int $uid, string $accion, ?string $detalle = null): void
{
    $pdo->prepare("INSERT INTO gastos_op_bitacora (gasto_op_id, usuario_id, accion, detalle) VALUES (?, ?, ?, ?)")->execute([$id, $uid, $accion, $detalle ? mb_substr($detalle, 0, 500) : null]);
}

/** Crea o edita. El colaborador solo edita los suyos pendientes o rechazados (al editar un rechazado vuelve a «Por revisar»). */
function gastosOpGuardar(PDO $pdo, int $cid, int $uid, string $rol, array $d, ?array $permitidos, int $id = 0): int
{
    $admin = gastosOpEsAdmin($rol);
    $antes = null;
    if ($id) {
        $antes = gastosOpObtener($pdo, $cid, $id, $permitidos);
        if ($antes['estado'] === 'aprobado') throw new Exception("El gasto ya está aprobado: pídele al administrador que lo regrese a revisión para cambiarlo.");
        if (!$admin && (int)$antes['usuario_id'] !== $uid) throw new Exception("Solo puedes editar los gastos que registraste.");
    }
    [$receptor, $programa, $contrato] = gastosOpCuentaResolver($pdo, $cid, $d);
    if (!gastosOpPuede($permitidos, $receptor, $programa, $contrato)) throw new Exception("Elige uno de tus clientes, programas o contratos asignados.");
    $st = $pdo->prepare("SELECT * FROM gastos_op_tipos WHERE id = ? AND cliente_id = ?");
    $st->execute([(int)($d['tipo_id'] ?? 0), $cid]);
    $tipo = $st->fetch(PDO::FETCH_ASSOC);
    if (!$tipo || (!(int)$tipo['activo'] && (int)($antes['tipo_id'] ?? 0) !== (int)$tipo['id'])) throw new Exception("Elige el tipo de gasto.");

    $pagador = ($d['pagador'] ?? ($antes['pagador'] ?? 'empresa')) === 'cliente' ? 'cliente' : 'empresa';
    if ($id && $pagador !== ($antes['pagador'] ?? 'empresa')) throw new Exception('No se puede cambiar quién pagó un registro existente.');
    $aliasTarjeta = $ultimosTarjeta = null;
    if ($pagador === 'cliente') {
        if (!(int)$tipo['es_pauta']) throw new Exception('Selecciona una plataforma de pauta.');
        $aliasTarjeta = mb_substr(trim((string)($d['tarjeta_cliente_alias'] ?? '')), 0, 80);
        $ultimosTarjeta = trim((string)($d['tarjeta_cliente_ultimos'] ?? ''));
        if ($aliasTarjeta === '' || !preg_match('/^\d{4}$/', $ultimosTarjeta)) throw new Exception('Indica el nombre de referencia de la tarjeta y sus últimos cuatro dígitos.');
        if (preg_match('/(?:\d[ -]*){8,}/', $aliasTarjeta)) throw new Exception('Usa solo un nombre de referencia; no escribas el número completo de tarjeta.');
        $d['metodo_pago'] = 'tarjeta'; $d['tarjeta_id'] = null; $d['cobrar_cliente'] = 0;
    }
    $metricas = [];
    if ($pagador === 'cliente') {
        foreach (['pauta_presupuesto', 'pauta_resultados', 'pauta_alcance', 'pauta_impresiones', 'pauta_clics'] as $k) {
            $v = trim((string)($d[$k] ?? ''));
            if ($v === '') { $metricas[$k] = null; continue; }
            $patron = $k === 'pauta_presupuesto' ? '/^\d{1,12}(?:\.\d{1,2})?$/' : '/^\d{1,12}$/';
            if (!preg_match($patron, $v)) throw new Exception('Presupuesto y métricas deben ser números positivos o cero, sin separadores de miles.');
            $metricas[$k] = $v;
        }
        if ($metricas['pauta_presupuesto'] === null || (float)$metricas['pauta_presupuesto'] <= 0) throw new Exception('Indica el presupuesto asignado a este período.');
        foreach (['pauta_objetivo'=>120, 'pauta_resultado_tipo'=>100] as $k=>$max) $metricas[$k] = mb_substr(trim((string)($d[$k] ?? '')),0,$max);
        if (!$metricas['pauta_objetivo'] || !trim((string)($d['campana'] ?? ''))) throw new Exception('Indica la campaña y su objetivo.');
        if ($metricas['pauta_resultados'] !== null && !$metricas['pauta_resultado_tipo']) throw new Exception('Indica qué resultado estás midiendo, por ejemplo conversaciones o clientes potenciales.');
    }
    $fecha = (string)($d['fecha'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha)) throw new Exception("Revisa la fecha del gasto.");
    if ($fecha > date('Y-m-d', strtotime('+1 day'))) throw new Exception("La fecha del gasto no puede ser futura.");
    $desc = mb_substr(trim((string)($d['descripcion'] ?? '')), 0, 300);
    if ($desc === '') throw new Exception("Escribe qué se pagó (descripción).");
    $monto = round((float)str_replace(',', '', (string)($d['monto'] ?? 0)), 2);
    if ($monto < 0 || ($pagador !== 'cliente' && $monto == 0)) throw new Exception("El monto debe ser mayor que 0.");
    $moneda = ($d['moneda'] ?? 'HNL') === 'USD' ? 'USD' : 'HNL';
    $tasa = null;
    if ($moneda === 'USD') {
        $tasa = round((float)str_replace(',', '', (string)($d['tasa_cambio'] ?? '')), 4);
        if ($tasa < 1 || $tasa > 1000) throw new Exception("Escribe la tasa de cambio (lempiras por dólar).");
    }
    $lempiras = $moneda === 'USD' ? round($monto * $tasa, 2) : $monto;
    $metodo = (string)($d['metodo_pago'] ?? 'tarjeta');
    if (!isset(GASTO_OP_METODOS[$metodo])) throw new Exception("Método de pago no válido.");
    $tarjeta = null;
    if ($metodo === 'tarjeta') {
        $tarjeta = (int)($d['tarjeta_id'] ?? 0) ?: null;
        if ($tarjeta) {
            $st = $pdo->prepare("SELECT activa FROM tarjetas WHERE id = ? AND cliente_id = ?");
            $st->execute([$tarjeta, $cid]);
            $act = $st->fetchColumn();
            if ($act === false || (!(int)$act && (int)($antes['tarjeta_id'] ?? 0) !== $tarjeta)) throw new Exception("Tarjeta no válida.");
        }
    }
    $campana = $ini = $fin = null;
    if ((int)$tipo['es_pauta']) {
        $campana = mb_substr(trim((string)($d['campana'] ?? '')), 0, 200) ?: null;
        $ini = (string)($d['pauta_inicio'] ?? '');
        $fin = (string)($d['pauta_fin'] ?? '');
        foreach ([$ini, $fin] as $x) if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $x) || !strtotime($x)) throw new Exception("Escribe las fechas de inicio y fin de la pauta.");
        if ($fin < $ini) throw new Exception("La pauta no puede terminar antes de empezar.");
    }
    $txt = fn($k, $max) => ($v = mb_substr(trim((string)($d[$k] ?? '')), 0, $max)) === '' ? null : $v;
    // Proveedor del catálogo (si viene su id, el nombre es el del catálogo)
    $provId = (int)($d['proveedor_id'] ?? 0) ?: null;
    $provNom = $txt('proveedor', 150);
    if ($provNom === '__nuevo__') $provNom = null;
    if ($provId) {
        $st = $pdo->prepare("SELECT nombre FROM proveedores WHERE id = ? AND cliente_id = ?");
        $st->execute([$provId, $cid]);
        $n = $st->fetchColumn();
        if ($n === false) throw new Exception("Proveedor no válido.");
        $provNom = $n;
    }
    $vals = [$receptor, (int)$tipo['id'], $fecha, $desc, $provNom, $txt('factura_ref', 100), $moneda, $monto, $tasa, $lempiras, $metodo, $tarjeta,
        $campana, $ini, $fin, !empty($d['cobrar_cliente']) ? 1 : 0, $txt('notas', 3000)];
    if ($id) {
        $pdo->prepare("UPDATE gastos_operativos SET receptor_id = ?, tipo_id = ?, fecha = ?, descripcion = ?, proveedor = ?, factura_ref = ?, moneda = ?, monto = ?, tasa_cambio = ?,
                       monto_lempiras = ?, metodo_pago = ?, tarjeta_id = ?, campana = ?, pauta_inicio = ?, pauta_fin = ?, cobrar_cliente = ?, notas = ?,
                       estado = 'pendiente', motivo_rechazo = NULL WHERE id = ? AND cliente_id = ?")->execute([...$vals, $id, $cid]);
        gastosOpLog($pdo, $id, $uid, 'editado', $antes['estado'] === 'rechazado' ? 'Corregido y enviado otra vez a revisión' : null);
        if (gastosOpConProgramas($pdo)) $pdo->prepare("UPDATE gastos_operativos SET programa_id = ?, contrato_id = ? WHERE id = ?")->execute([$programa, $contrato, $id]);
        gastosOpProveedorId($pdo, $id, $provId);
        $pdo->prepare('UPDATE gastos_operativos SET pagador=?, tarjeta_cliente_alias=?, tarjeta_cliente_ultimos=? WHERE id=? AND cliente_id=?')->execute([$pagador,$aliasTarjeta,$ultimosTarjeta,$id,$cid]);
        gastosOpGuardarMetricas($pdo, $cid, $id, $metricas);
        return $id;
    }
    $pdo->prepare("INSERT INTO gastos_operativos (receptor_id, tipo_id, fecha, descripcion, proveedor, factura_ref, moneda, monto, tasa_cambio, monto_lempiras, metodo_pago, tarjeta_id,
                   campana, pauta_inicio, pauta_fin, cobrar_cliente, notas, cliente_id, usuario_id) VALUES (" . implode(',', array_fill(0, 19, '?')) . ")")->execute([...$vals, $cid, $uid]);
    $id = (int)$pdo->lastInsertId();
    if (gastosOpConProgramas($pdo)) $pdo->prepare("UPDATE gastos_operativos SET programa_id = ?, contrato_id = ? WHERE id = ?")->execute([$programa, $contrato, $id]);
    gastosOpProveedorId($pdo, $id, $provId);
    $pdo->prepare('UPDATE gastos_operativos SET pagador=?, tarjeta_cliente_alias=?, tarjeta_cliente_ultimos=? WHERE id=? AND cliente_id=?')->execute([$pagador,$aliasTarjeta,$ultimosTarjeta,$id,$cid]);
    gastosOpGuardarMetricas($pdo, $cid, $id, $metricas);
    gastosOpLog($pdo, $id, $uid, 'creado');
    return $id;
}

/**
 * Valida y guarda un archivo subido (comprobante o reporte). Devuelve [archivo, nombre original] o null si no vino.
 * Imágenes, PDF, Excel (xlsx/xls) y CSV, hasta 10 MB; se valida por contenido, no solo por el nombre.
 */
function gastosOpSubir(?array $file, int $cid): ?array
{
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if (!$file || $err === UPLOAD_ERR_NO_FILE || ($file['name'] ?? '') === '') return null;
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw new Exception("«{$file['name']}» supera el tamaño máximo (" . ini_get('upload_max_filesize') . ").");
    if ($err !== UPLOAD_ERR_OK) throw new Exception("No se pudo subir «{$file['name']}» (código $err).");
    if (!is_uploaded_file($file['tmp_name'])) throw new Exception("Archivo temporal inválido.");
    if ($file['size'] > GASTO_OP_MAX_BYTES) throw new Exception("«{$file['name']}» supera 10 MB.");
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(GASTO_OP_EXT[$ext]) || !in_array($mime, GASTO_OP_EXT[$ext], true)) throw new Exception("«{$file['name']}»: solo se aceptan JPG, PNG, WEBP, PDF, Excel (xlsx, xls) o CSV.");
    if ($ext === 'xlsx' && file_get_contents($file['tmp_name'], false, null, 0, 2) !== 'PK') throw new Exception("«{$file['name']}» no es un Excel válido.");
    $dir = gastosOpDir();
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new Exception("No se pudo crear la carpeta de comprobantes.");
    if (!is_file($dir . '.htaccess')) @file_put_contents($dir . '.htaccess', "Require all denied\nDeny from all\n");   // solo se sirven con permiso
    $archivo = 'gop_' . $cid . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    if (!move_uploaded_file($file['tmp_name'], $dir . $archivo)) throw new Exception("No se pudo guardar «{$file['name']}».");
    return [$archivo, mb_substr(basename((string)$file['name']), 0, 255)];
}

/** $_FILES['archivos'] (varios) → lista de archivos sueltos. */
function gastosOpArchivosPost(?array $f): array
{
    if (!$f || !isset($f['name'])) return [];
    if (!is_array($f['name'])) return [$f];
    $out = [];
    foreach ($f['name'] as $i => $n) $out[] = ['name' => $n, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    return $out;
}

function gastosOpArchivoAgregar(PDO $pdo, int $cid, int $uid, int $id, array $guardado, string $tipo): void
{
    $tipo = isset(GASTO_OP_ARCHIVO_TIPOS[$tipo]) ? $tipo : 'comprobante';
    $pdo->prepare("INSERT INTO gastos_op_archivos (gasto_op_id, cliente_id, tipo, archivo, nombre, usuario_id) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$id, $cid, $tipo, $guardado[0], $guardado[1], $uid]);
    gastosOpLog($pdo, $id, $uid, 'archivo', GASTO_OP_ARCHIVO_TIPOS[$tipo] . ': ' . $guardado[1]);
}

/** Quita un archivo: el colaborador, solo los suyos mientras el gasto no esté aprobado. */
function gastosOpArchivoQuitar(PDO $pdo, int $cid, int $uid, string $rol, int $archivoId, ?array $permitidos): void
{
    $st = $pdo->prepare("SELECT * FROM gastos_op_archivos WHERE id = ? AND cliente_id = ?");
    $st->execute([$archivoId, $cid]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) throw new Exception("Archivo no encontrado.");
    $g = gastosOpObtener($pdo, $cid, (int)$a['gasto_op_id'], $permitidos);
    if (!gastosOpEsAdmin($rol) && ($g['estado'] === 'aprobado' || (int)$a['usuario_id'] !== $uid)) throw new Exception("Solo el administrador puede quitar archivos de un gasto aprobado o subidos por otra persona.");
    $pdo->prepare("DELETE FROM gastos_op_archivos WHERE id = ?")->execute([$archivoId]);
    gastosOpLog($pdo, (int)$a['gasto_op_id'], $uid, 'archivo_quitado', $a['nombre']);
    $chk = $pdo->prepare("SELECT COUNT(*) FROM gastos_op_archivos WHERE archivo = ?");
    $chk->execute([$a['archivo']]);
    if (!$chk->fetchColumn()) @unlink(gastosOpDir() . basename($a['archivo']));
}

/** Elimina un gasto (el colaborador, solo los suyos sin aprobar; el administrador, cualquiera sin aprobar). */
function gastosOpEliminar(PDO $pdo, int $cid, int $uid, string $rol, int $id, ?array $permitidos): void
{
    $g = gastosOpObtener($pdo, $cid, $id, $permitidos);
    if ($g['estado'] === 'aprobado') throw new Exception("Está aprobado: regrésalo a revisión antes de eliminarlo.");
    if (!gastosOpEsAdmin($rol) && (int)$g['usuario_id'] !== $uid) throw new Exception("Solo puedes eliminar los gastos que registraste.");
    $archivos = gastosOpArchivos($pdo, $id);
    $pdo->prepare("DELETE FROM gastos_op_archivos WHERE gasto_op_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM gastos_operativos WHERE id = ? AND cliente_id = ?")->execute([$id, $cid]);
    gastosOpLog($pdo, $id, $uid, 'eliminado', $g['descripcion'] . ' · ' . monedaFmt($g['monto'], $g['moneda']));
    foreach ($archivos as $a) @unlink(gastosOpDir() . basename($a['archivo']));
}

/**
 * Aprueba o rechaza (solo administrador).
 * Al aprobar, $d['registro']: 'nuevo' crea el gasto en Gastos (categoría $d['categoria_id'], con el cliente para cobrárselo si
 * el gasto lo pide); 'existente' lo liga al gasto N.º $d['gasto_id'] ya registrado; 'ninguno' solo lo aprueba.
 */
function gastosOpRevisar(PDO $pdo, int $cid, int $uid, string $rol, int $id, string $decision, array $d = []): ?int
{
    if (!gastosOpEsAdmin($rol)) throw new Exception("Solo un administrador aprueba o rechaza gastos operativos.");
    $g = gastosOpObtener($pdo, $cid, $id, null);
    if ($g['estado'] === 'aprobado') throw new Exception("Ya está aprobado.");
    if ($decision === 'rechazado') {
        $motivo = mb_substr(trim((string)($d['motivo'] ?? '')), 0, 300);
        if ($motivo === '') throw new Exception("Escribe el motivo del rechazo (lo verá quien lo registró).");
        $pdo->prepare("UPDATE gastos_operativos SET estado = 'rechazado', motivo_rechazo = ?, revisado_por = ?, revisado_en = NOW() WHERE id = ?")->execute([$motivo, $uid, $id]);
        gastosOpLog($pdo, $id, $uid, 'rechazado', $motivo);
        return null;
    }
    if ($decision !== 'aprobado') throw new Exception("Decisión no válida.");
    $gastoId = null;
    $registro = ($g['pagador'] ?? 'empresa') === 'cliente' ? 'ninguno' : (string)($d['registro'] ?? 'nuevo');
    if ($registro === 'existente') {
        $gastoId = (int)($d['gasto_id'] ?? 0);
        $st = $pdo->prepare("SELECT estado FROM gastos WHERE id = ? AND cliente_id = ?");
        $st->execute([$gastoId, $cid]);
        $e = $st->fetchColumn();
        if ($e === false) throw new Exception("No existe el gasto N.º $gastoId en Gastos.");
        if ($e === 'anulado') throw new Exception("El gasto N.º $gastoId está anulado.");
        $st = $pdo->prepare("SELECT id FROM gastos_operativos WHERE gasto_id = ? AND cliente_id = ? AND id <> ?");
        $st->execute([$gastoId, $cid, $id]);
        if ($otro = $st->fetchColumn()) throw new Exception("El gasto N.º $gastoId ya está ligado al gasto operativo #$otro.");
    } elseif ($registro === 'nuevo') {
        $cat = (int)($d['categoria_id'] ?? 0) ?: ((int)$g['tipo_categoria_id'] ?: null);
        if ($cat) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM categorias_gastos WHERE id = ? AND cliente_id = ?");
            $st->execute([$cat, $cid]);
            if (!$st->fetchColumn()) throw new Exception("Categoría no válida.");
        }
        $propio = $g['metodo_pago'] === 'propio';
        $desc = mb_substr('Gasto operativo · ' . $g['tipo'] . ' · ' . gastosOpCuentaTexto($g) . ': ' . $g['descripcion']
            . ($g['moneda'] === 'USD' ? ' (USD ' . number_format((float)$g['monto'], 2) . ' × ' . rtrim(rtrim(number_format((float)$g['tasa_cambio'], 4), '0'), '.') . ')' : ''), 0, 300);
        $notas = trim('Registrado por ' . ($g['registrado_por'] ?: 'colaborador') . ' en Gastos operativos (#' . $id . ').'
            . ($g['pauta_inicio'] ? ' Pauta del ' . date('d/m/Y', strtotime($g['pauta_inicio'])) . ' al ' . date('d/m/Y', strtotime($g['pauta_fin'])) . ($g['campana'] ? ' · ' . $g['campana'] : '') . '.' : '')
            . ($propio ? ' Lo pagó el colaborador: queda pendiente de reembolsarle.' : ''));
        // El primer comprobante (imagen o PDF) también queda en el gasto
        $adj = $adjNom = null;
        foreach (gastosOpArchivos($pdo, $id) as $a) {
            if (!preg_match('/\.(jpe?g|png|webp|pdf)$/i', $a['archivo'])) continue;
            $dest = 'gasto_' . $cid . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . strtolower(pathinfo($a['archivo'], PATHINFO_EXTENSION));
            $dirG = __DIR__ . '/../clientes/naranjaymedia/includes/uploads/gastos/';
            if (is_dir($dirG) && @copy(gastosOpDir() . basename($a['archivo']), $dirG . $dest)) { $adj = $dest; $adjNom = $a['nombre']; }
            break;
        }
        $metodo = ['tarjeta' => 'tarjeta', 'transferencia' => 'transferencia', 'efectivo' => 'efectivo'][$g['metodo_pago']] ?? 'otro';
        $pdo->prepare("INSERT INTO gastos (cliente_id, categoria_id, descripcion, monto, fecha, frecuencia, tipo, naturaleza, metodo_pago, tarjeta_id, proveedor, factura_ref, notas,
                       archivo_adjunto, archivo_nombre, estado, usuario_id, cobrar_receptor_id, fecha_vencimiento)
                       VALUES (?, ?, ?, ?, ?, 'unico', 'variable', 'gasto', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$cid, $cat, $desc, (float)$g['monto_lempiras'], $g['fecha'], $metodo, $metodo === 'tarjeta' ? ($g['tarjeta_id'] ?: null) : null, $g['proveedor'], $g['factura_ref'], $notas,
                $adj, $adjNom, $propio ? 'pendiente' : 'pagado', $uid, (int)$g['cobrar_cliente'] ? (int)$g['receptor_id'] : null, $propio ? date('Y-m-d') : null]);
        $gastoId = (int)$pdo->lastInsertId();
        if (!empty($g['proveedor_id'])) $pdo->prepare("UPDATE gastos SET proveedor_id = ? WHERE id = ?")->execute([(int)$g['proveedor_id'], $gastoId]);
    } elseif ($registro !== 'ninguno') throw new Exception("Elige cómo registrarlo en Gastos.");
    $pdo->prepare("UPDATE gastos_operativos SET estado = 'aprobado', motivo_rechazo = NULL, revisado_por = ?, revisado_en = NOW(), gasto_id = ? WHERE id = ?")->execute([$uid, $gastoId, $id]);
    gastosOpLog($pdo, $id, $uid, 'aprobado', $gastoId ? ($registro === 'nuevo' ? 'Registrado en Gastos como N.º ' : 'Ligado al gasto N.º ') . $gastoId : 'Sin registrar en Gastos');
    return $gastoId;
}

/** Regresa un aprobado a revisión (solo administrador). El gasto que se creó al aprobarlo se anula (uno ligado ya existente se deja). */
function gastosOpReabrir(PDO $pdo, int $cid, int $uid, string $rol, int $id): void
{
    if (!gastosOpEsAdmin($rol)) throw new Exception("Solo un administrador puede regresarlo a revisión.");
    $g = gastosOpObtener($pdo, $cid, $id, null);
    if ($g['estado'] !== 'aprobado') throw new Exception("Solo se regresa a revisión un gasto aprobado.");
    $det = 'Regresado a revisión';
    if ($g['gasto_id']) {
        $st = $pdo->prepare("SELECT cobrado_factura_id, notas FROM gastos WHERE id = ? AND cliente_id = ?");
        $st->execute([(int)$g['gasto_id'], $cid]);
        $gs = $st->fetch(PDO::FETCH_ASSOC);
        if ($gs && $gs['cobrado_factura_id']) throw new Exception("El gasto N.º {$g['gasto_id']} ya se le cobró al cliente en una factura: no se puede regresar.");
        if ($gs && str_contains((string)$gs['notas'], 'Gastos operativos (#' . $id . ')')) {
            $pdo->prepare("UPDATE gastos SET estado = 'anulado' WHERE id = ? AND cliente_id = ?")->execute([(int)$g['gasto_id'], $cid]);
            $det .= ' · se anuló el gasto N.º ' . $g['gasto_id'];
        } else $det .= ' · se desligó del gasto N.º ' . $g['gasto_id'];
    }
    $pdo->prepare("UPDATE gastos_operativos SET estado = 'pendiente', revisado_por = NULL, revisado_en = NULL, gasto_id = NULL WHERE id = ?")->execute([$id]);
    gastosOpLog($pdo, $id, $uid, 'reabierto', $det);
}

function gastosOpProveedorId(PDO $pdo, int $id, ?int $provId): void
{
    static $ok = null;
    if ($ok === null) { try { $ok = (bool)$pdo->query("SHOW COLUMNS FROM gastos_operativos LIKE 'proveedor_id'")->fetchColumn(); } catch (Throwable $e) { $ok = false; } }
    if ($ok) $pdo->prepare("UPDATE gastos_operativos SET proveedor_id = ? WHERE id = ?")->execute([$provId, $id]);
}

/** Alta rápida de un tipo desde el gasto (colaborador o administrador): solo nombre y si es pauta. Si ya existe, devuelve ese. */
function gastosOpTipoCrear(PDO $pdo, int $cid, string $nombre, bool $pauta): array
{
    $nombre = mb_substr(trim(preg_replace('/\s+/u', ' ', $nombre)), 0, 80);
    if ($nombre === '') throw new Exception("Escribe el nombre del tipo.");
    $st = $pdo->prepare("SELECT id, nombre, es_pauta, activo FROM gastos_op_tipos WHERE cliente_id = ? AND nombre = ?");
    $st->execute([$cid, $nombre]);
    if ($t = $st->fetch(PDO::FETCH_ASSOC)) {
        if (!(int)$t['activo']) $pdo->prepare("UPDATE gastos_op_tipos SET activo = 1 WHERE id = ?")->execute([$t['id']]);
        return ['id' => (int)$t['id'], 'nombre' => $t['nombre'], 'es_pauta' => (int)$t['es_pauta']];
    }
    $id = gastosOpTipoGuardar($pdo, $cid, ['nombre' => $nombre, 'icono' => $pauta ? 'bi-megaphone' : 'bi-tag', 'es_pauta' => $pauta ? 1 : 0]);
    return ['id' => $id, 'nombre' => $nombre, 'es_pauta' => $pauta ? 1 : 0];
}

/**
 * Gastos operativos aún por aprobar que se le cobrarán al cliente (para avisar al facturar). receptor_id => [n, lempiras].
 * Los de un contrato con varias empresas salen para todas ellas.
 */
function gastosOpPendientesPorCliente(PDO $pdo, int $cid, array $grupos): array
{
    if (!gastosOpDisponible($pdo)) return [];
    $st = $pdo->prepare("SELECT receptor_id, COUNT(*) n, SUM(monto_lempiras) l FROM gastos_operativos WHERE cliente_id = ? AND estado = 'pendiente' AND cobrar_cliente = 1 GROUP BY receptor_id");
    $st->execute([$cid]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r)
        foreach ($grupos[(int)$r['receptor_id']] ?? [(int)$r['receptor_id']] as $rid) {
            $out[$rid] ??= ['n' => 0, 'l' => 0.0, 'cuenta' => 'c:' . (int)$r['receptor_id']];
            $out[$rid]['n'] += (int)$r['n'];
            $out[$rid]['l'] += (float)$r['l'];
        }
    return $out;
}

/** Cuántos esperan revisión (aviso del menú para el administrador). */
function gastosOpPorRevisar(PDO $pdo, int $cid, string $pagador = 'empresa'): int
{
    if (!gastosOpDisponible($pdo)) return 0;
    $st = $pdo->prepare("SELECT COUNT(*) FROM gastos_operativos WHERE cliente_id = ? AND estado = 'pendiente' AND pagador = ?");
    $st->execute([$cid, $pagador]);
    return (int)$st->fetchColumn();
}

/** Totales por moneda y en lempiras: ['HNL' => x, 'USD' => y, 'lempiras' => z]. */
function gastosOpTotales(array $lista): array
{
    $t = ['HNL' => 0.0, 'USD' => 0.0, 'lempiras' => 0.0];
    foreach ($lista as $g) {
        if ($g['estado'] === 'rechazado') continue;
        $t[$g['moneda'] === 'USD' ? 'USD' : 'HNL'] += (float)$g['monto'];
        $t['lempiras'] += (float)$g['monto_lempiras'];
    }
    return $t;
}

/** Campos de rendimiento: solo columnas conocidas, nunca SQL procedente del formulario. */
function gastosOpGuardarMetricas(PDO $pdo, int $cid, int $id, array $metricas): void
{
    if (!$metricas) return;
    $columnas = ['pauta_presupuesto','pauta_resultados','pauta_alcance','pauta_impresiones','pauta_clics','pauta_objetivo','pauta_resultado_tipo'];
    $pdo->prepare('UPDATE gastos_operativos SET ' . implode(', ', array_map(fn($k)=>"$k = ?", $columnas)) . ' WHERE id = ? AND cliente_id = ?')->execute([...array_map(fn($k)=>$metricas[$k], $columnas), $id, $cid]);
}

/** Valores derivados por registro/período; no sumar alcance entre campañas. */
function gastosOpMetricas(array $g): array
{
    $pres = (float)($g['pauta_presupuesto'] ?? 0);
    $monto = (float)$g['monto'];
    return [
        'Presupuesto' => $g['pauta_presupuesto'] === null ? 'Sin registrar' : monedaFmt($pres, $g['moneda']),
        'Ejecutado' => monedaFmt($monto, $g['moneda']),
        'Saldo disponible' => $pres > 0 ? monedaFmt($pres - $monto, $g['moneda']) : '—',
        'Ejecución' => $pres > 0 ? number_format($monto / $pres * 100, 1) . '%' : '—',
        'Objetivo' => $g['pauta_objetivo'] ?: 'Sin registrar',
        'Resultados' => $g['pauta_resultados'] !== null ? number_format((float)$g['pauta_resultados']) . ' ' . $g['pauta_resultado_tipo'] : 'Sin registrar',
        'Costo por resultado' => (float)$g['pauta_resultados'] > 0 ? monedaFmt($monto / $g['pauta_resultados'], $g['moneda']) : '—',
        'Alcance' => $g['pauta_alcance'] !== null ? number_format((float)$g['pauta_alcance']) : 'Sin registrar',
        'Impresiones' => $g['pauta_impresiones'] !== null ? number_format((float)$g['pauta_impresiones']) : 'Sin registrar',
        'Clics en el enlace' => $g['pauta_clics'] !== null ? number_format((float)$g['pauta_clics']) : 'Sin registrar',
    ];
}
