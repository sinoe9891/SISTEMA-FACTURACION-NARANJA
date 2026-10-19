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

/** Clientes que puede ver el usuario: null = todos (administrador); si no, los ids asignados. */
function gastosOpPermitidos(PDO $pdo, int $uid, string $rol): ?array
{
    if (gastosOpEsAdmin($rol)) return null;
    $st = $pdo->prepare("SELECT receptor_id FROM usuarios_clientes WHERE usuario_id = ?");
    $st->execute([$uid]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Clientes para elegir (los asignados, o todos para el administrador). */
function gastosOpClientes(PDO $pdo, int $cid, ?array $permitidos): array
{
    if ($permitidos === []) return [];
    $sql = "SELECT id, nombre FROM clientes_factura WHERE cliente_id = ?";
    $p = [$cid];
    if ($permitidos !== null) { $sql .= " AND id IN (" . implode(',', array_fill(0, count($permitidos), '?')) . ")"; $p = array_merge($p, $permitidos); }
    $st = $pdo->prepare($sql . " ORDER BY nombre");
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Usuarios con el rol Gastos operativos y sus clientes asignados. */
function gastosOpResponsables(PDO $pdo, int $cid): array
{
    $st = $pdo->prepare("SELECT id, nombre, correo, estado FROM usuarios WHERE cliente_id = ? AND rol = 'operativo' ORDER BY nombre");
    $st->execute([$cid]);
    $us = $st->fetchAll(PDO::FETCH_ASSOC);
    $a = $pdo->prepare("SELECT receptor_id FROM usuarios_clientes WHERE usuario_id = ?");
    foreach ($us as &$u) { $a->execute([$u['id']]); $u['clientes'] = array_map('intval', $a->fetchAll(PDO::FETCH_COLUMN)); }
    return $us;
}

function gastosOpAsignar(PDO $pdo, int $cid, int $adminId, int $usuarioId, array $receptores): void
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE id = ? AND cliente_id = ? AND rol = 'operativo'");
    $st->execute([$usuarioId, $cid]);
    if (!$st->fetchColumn()) throw new Exception("El usuario no tiene el rol Gastos operativos.");
    $ids = array_values(array_unique(array_filter(array_map('intval', $receptores))));
    if ($ids) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM clientes_factura WHERE cliente_id = ? AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
        $st->execute(array_merge([$cid], $ids));
        if ((int)$st->fetchColumn() !== count($ids)) throw new Exception("Hay clientes que no son de la empresa.");
    }
    $pdo->prepare("DELETE FROM usuarios_clientes WHERE usuario_id = ?" . ($ids ? " AND receptor_id NOT IN (" . implode(',', $ids) . ")" : ''))->execute([$usuarioId]);
    $ins = $pdo->prepare("INSERT IGNORE INTO usuarios_clientes (usuario_id, receptor_id, asignado_por) VALUES (?, ?, ?)");
    foreach ($ids as $r) $ins->execute([$usuarioId, $r, $adminId]);
}

/** Lista con filtros: desde, hasta, receptor, tipo, estado, moneda, usuario, sin_archivo. */
function gastosOpLista(PDO $pdo, int $cid, ?array $permitidos, array $f = []): array
{
    if ($permitidos === []) return [];
    $w = ["g.cliente_id = ?"];
    $p = [$cid];
    if ($permitidos !== null) { $w[] = "g.receptor_id IN (" . implode(',', array_fill(0, count($permitidos), '?')) . ")"; $p = array_merge($p, $permitidos); }
    if (!empty($f['desde'])) { $w[] = "g.fecha >= ?"; $p[] = $f['desde']; }
    if (!empty($f['hasta'])) { $w[] = "g.fecha <= ?"; $p[] = $f['hasta']; }
    foreach (['receptor' => 'g.receptor_id', 'tipo' => 'g.tipo_id', 'usuario' => 'g.usuario_id'] as $k => $col)
        if (!empty($f[$k])) { $w[] = "$col = ?"; $p[] = (int)$f[$k]; }
    if (!empty($f['estado']) && isset(GASTO_OP_ESTADOS[$f['estado']])) { $w[] = "g.estado = ?"; $p[] = $f['estado']; }
    if (!empty($f['moneda']) && in_array($f['moneda'], ['HNL', 'USD'], true)) { $w[] = "g.moneda = ?"; $p[] = $f['moneda']; }
    $st = $pdo->prepare("SELECT g.*, cf.nombre AS cliente, t.nombre AS tipo, t.icono AS tipo_icono, t.es_pauta, u.nombre AS registrado_por, r.nombre AS revisado_por_nombre,
                                CONCAT(tj.banco, ' •••• ', tj.ultimos_digitos) AS tarjeta,
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
    if (!$l || ($permitidos !== null && !in_array((int)$l['receptor_id'], $permitidos, true))) throw new Exception("Gasto no encontrado.");
    return $l;
}

function gastosOpListaPorId(PDO $pdo, int $cid, int $id): ?array
{
    $st = $pdo->prepare("SELECT g.*, cf.nombre AS cliente, t.nombre AS tipo, t.es_pauta, t.categoria_id AS tipo_categoria_id, u.nombre AS registrado_por, r.nombre AS revisado_por_nombre,
                                CONCAT(tj.banco, ' •••• ', tj.ultimos_digitos) AS tarjeta
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
    $receptor = (int)($d['receptor_id'] ?? 0);
    if (!$receptor || ($permitidos !== null && !in_array($receptor, $permitidos, true))) throw new Exception("Elige uno de tus clientes asignados.");
    $st = $pdo->prepare("SELECT COUNT(*) FROM clientes_factura WHERE id = ? AND cliente_id = ?");
    $st->execute([$receptor, $cid]);
    if (!$st->fetchColumn()) throw new Exception("Cliente no válido.");
    $st = $pdo->prepare("SELECT * FROM gastos_op_tipos WHERE id = ? AND cliente_id = ?");
    $st->execute([(int)($d['tipo_id'] ?? 0), $cid]);
    $tipo = $st->fetch(PDO::FETCH_ASSOC);
    if (!$tipo || (!(int)$tipo['activo'] && (int)($antes['tipo_id'] ?? 0) !== (int)$tipo['id'])) throw new Exception("Elige el tipo de gasto.");

    $fecha = (string)($d['fecha'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha)) throw new Exception("Revisa la fecha del gasto.");
    if ($fecha > date('Y-m-d', strtotime('+1 day'))) throw new Exception("La fecha del gasto no puede ser futura.");
    $desc = mb_substr(trim((string)($d['descripcion'] ?? '')), 0, 300);
    if ($desc === '') throw new Exception("Escribe qué se pagó (descripción).");
    $monto = round((float)str_replace(',', '', (string)($d['monto'] ?? 0)), 2);
    if ($monto <= 0) throw new Exception("El monto debe ser mayor que 0.");
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
    $vals = [$receptor, (int)$tipo['id'], $fecha, $desc, $txt('proveedor', 150), $txt('factura_ref', 100), $moneda, $monto, $tasa, $lempiras, $metodo, $tarjeta,
        $campana, $ini, $fin, !empty($d['cobrar_cliente']) ? 1 : 0, $txt('notas', 3000)];
    if ($id) {
        $pdo->prepare("UPDATE gastos_operativos SET receptor_id = ?, tipo_id = ?, fecha = ?, descripcion = ?, proveedor = ?, factura_ref = ?, moneda = ?, monto = ?, tasa_cambio = ?,
                       monto_lempiras = ?, metodo_pago = ?, tarjeta_id = ?, campana = ?, pauta_inicio = ?, pauta_fin = ?, cobrar_cliente = ?, notas = ?,
                       estado = 'pendiente', motivo_rechazo = NULL WHERE id = ? AND cliente_id = ?")->execute([...$vals, $id, $cid]);
        gastosOpLog($pdo, $id, $uid, 'editado', $antes['estado'] === 'rechazado' ? 'Corregido y enviado otra vez a revisión' : null);
        return $id;
    }
    $pdo->prepare("INSERT INTO gastos_operativos (receptor_id, tipo_id, fecha, descripcion, proveedor, factura_ref, moneda, monto, tasa_cambio, monto_lempiras, metodo_pago, tarjeta_id,
                   campana, pauta_inicio, pauta_fin, cobrar_cliente, notas, cliente_id, usuario_id) VALUES (" . implode(',', array_fill(0, 19, '?')) . ")")->execute([...$vals, $cid, $uid]);
    $id = (int)$pdo->lastInsertId();
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
    $registro = (string)($d['registro'] ?? 'nuevo');
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
        $desc = mb_substr('Gasto operativo · ' . $g['tipo'] . ' · ' . $g['cliente'] . ': ' . $g['descripcion']
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

/** Cuántos esperan revisión (aviso del menú para el administrador). */
function gastosOpPorRevisar(PDO $pdo, int $cid): int
{
    if (!gastosOpDisponible($pdo)) return 0;
    $st = $pdo->prepare("SELECT COUNT(*) FROM gastos_operativos WHERE cliente_id = ? AND estado = 'pendiente'");
    $st->execute([$cid]);
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
