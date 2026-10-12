<?php
/**
 * cotizaciones.php — Cotizaciones a clientes: se arman como una factura (cliente y productos), con vigencia,
 * «Incluye» y «Condiciones»; se envían por correo con su PDF y se pueden convertir en factura.
 *   cotizacionGuardar($pdo, $cid, $uid, $datos, $id)   → id (nueva o editada)
 *   cotizacionPdf($pdo, $cid, $id)                      → bytes del PDF (dompdf, con logo, firma y sello)
 *   cotizacionEnviar($pdo, $cid, $id, $uid, $para, $cc, $asunto, $mensaje) → envía con el PDF adjunto
 * Textos por defecto (vigencia, incluye, condiciones, correo): cotizaciones_config. Sello: clientes_saas.sello.
 */
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/firmantes.php';
require_once __DIR__ . '/correo.php';

const COTIZACION_ESTADOS = [
    'borrador' => ['Borrador', '#f1f5f9', '#475569', 'bi-pencil'],
    'enviada' => ['Enviada', '#dbeafe', '#1e40af', 'bi-send-check'],
    'aceptada' => ['Aceptada', '#dcfce7', '#166534', 'bi-hand-thumbs-up'],
    'rechazada' => ['Rechazada', '#fee2e2', '#991b1b', 'bi-hand-thumbs-down'],
    'facturada' => ['Facturada', '#ede9fe', '#5b21b6', 'bi-receipt'],
    'vencida' => ['Vencida', '#ffedd5', '#9a3412', 'bi-hourglass-bottom'],
];
const COTIZACION_UPLOADS = __DIR__ . '/../clientes/naranjaymedia/includes/uploads';

function cotizacionesDisponible(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try { $ok = (bool)$pdo->query("SHOW TABLES LIKE 'cotizaciones'")->fetchColumn(); } catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

/** Textos por defecto de la empresa (con valores de fábrica si no se han configurado). */
function cotizacionConfig(PDO $pdo, int $cid): array
{
    $st = $pdo->prepare("SELECT * FROM cotizaciones_config WHERE cliente_id = ?");
    $st->execute([$cid]);
    $c = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $dias = (int)($c['vigencia_dias'] ?? 15) ?: 15;
    return [
        'vigencia_dias' => $dias,
        'incluye' => $c['incluye'] ?? '',
        'condiciones' => $c['condiciones'] ?? "Forma de pago: 50 % al confirmar el pedido y 50 % contra entrega.\nEntrega: a coordinar una vez aprobado el trabajo.\nCambios: modificaciones después de aprobada la propuesta se cotizan por separado.\nVigencia: {dias} días calendario a partir de la fecha de esta cotización.",
        'lema' => $c['lema'] ?? 'Agencia de marketing digital y desarrollo de software',
        'contacto_email' => $c['contacto_email'] ?? '',
        'contacto_telefono' => $c['contacto_telefono'] ?? '',
        'firmante' => isset(FIRMANTES_ROLES[$c['firmante'] ?? '']) ? $c['firmante'] : 'vobo',
        'asunto_correo' => $c['asunto_correo'] ?? 'Cotización {numero} · {empresa}',
        'mensaje_correo' => $c['mensaje_correo'] ?? '',
    ];
}

function cotizacionGuardarConfig(PDO $pdo, int $cid, array $d): void
{
    $txt = fn($k, $max = 5000) => ($v = trim((string)($d[$k] ?? ''))) === '' ? null : mb_substr($v, 0, $max);
    $pdo->prepare("INSERT INTO cotizaciones_config (cliente_id, vigencia_dias, incluye, condiciones, lema, contacto_email, contacto_telefono, firmante, asunto_correo, mensaje_correo)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE vigencia_dias = VALUES(vigencia_dias), incluye = VALUES(incluye), condiciones = VALUES(condiciones), lema = VALUES(lema),
                       contacto_email = VALUES(contacto_email), contacto_telefono = VALUES(contacto_telefono), firmante = VALUES(firmante),
                       asunto_correo = VALUES(asunto_correo), mensaje_correo = VALUES(mensaje_correo)")
        ->execute([$cid, max(1, min(365, (int)($d['vigencia_dias'] ?? 15))), $txt('incluye'), $txt('condiciones'), $txt('lema', 200), $txt('contacto_email', 150),
                   $txt('contacto_telefono', 50), isset(FIRMANTES_ROLES[$d['firmante'] ?? '']) ? $d['firmante'] : 'vobo', $txt('asunto_correo', 255), $txt('mensaje_correo')]);
}

/** ¿Están instaladas las categorías? */
function cotizacionCategoriasDisponible(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try { $ok = (bool)$pdo->query("SHOW TABLES LIKE 'cotizaciones_categorias'")->fetchColumn(); } catch (Throwable $e) { $ok = false; }
    }
    return $ok;
}

/** Categorías de la empresa (Impresión, Diseño…) con sus textos y cuántas cotizaciones tiene cada una. */
function cotizacionCategorias(PDO $pdo, int $cid): array
{
    if (!cotizacionCategoriasDisponible($pdo)) return [];
    $st = $pdo->prepare("SELECT k.*, (SELECT COUNT(*) FROM cotizaciones c WHERE c.categoria_id = k.id) AS usos
                         FROM cotizaciones_categorias k WHERE k.cliente_id = ? ORDER BY k.orden, k.nombre");
    $st->execute([$cid]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function cotizacionCategoriaGuardar(PDO $pdo, int $cid, array $d): int
{
    $id = (int)($d['id'] ?? 0);
    $nombre = mb_substr(trim((string)($d['nombre'] ?? '')), 0, 100);
    if ($nombre === '') throw new Exception("Escribe el nombre de la categoría.");
    $st = $pdo->prepare("SELECT id FROM cotizaciones_categorias WHERE cliente_id = ? AND nombre = ? AND id <> ?");
    $st->execute([$cid, $nombre, $id]);
    if ($st->fetchColumn()) throw new Exception("Ya existe la categoría «{$nombre}».");
    $txt = fn($k) => ($v = trim((string)($d[$k] ?? ''))) === '' ? null : mb_substr($v, 0, 5000);
    $dias = ($d['vigencia_dias'] ?? '') === '' ? null : max(1, min(365, (int)$d['vigencia_dias']));
    $icono = preg_match('/^bi-[a-z0-9-]{1,36}$/', (string)($d['icono'] ?? '')) ? $d['icono'] : null;
    if ($id) {
        $pdo->prepare("UPDATE cotizaciones_categorias SET nombre = ?, icono = ?, incluye = ?, condiciones = ?, vigencia_dias = ? WHERE id = ? AND cliente_id = ?")
            ->execute([$nombre, $icono, $txt('incluye'), $txt('condiciones'), $dias, $id, $cid]);
        return $id;
    }
    $orden = (int)$pdo->query("SELECT COALESCE(MAX(orden), 0) + 1 FROM cotizaciones_categorias WHERE cliente_id = " . (int)$cid)->fetchColumn();
    $pdo->prepare("INSERT INTO cotizaciones_categorias (cliente_id, nombre, icono, incluye, condiciones, vigencia_dias, orden) VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([$cid, $nombre, $icono, $txt('incluye'), $txt('condiciones'), $dias, $orden]);
    return (int)$pdo->lastInsertId();
}

/** Elimina la categoría; sus cotizaciones quedan sin categoría (no se borran). */
function cotizacionCategoriaEliminar(PDO $pdo, int $cid, int $id): void
{
    $pdo->prepare("UPDATE cotizaciones SET categoria_id = NULL WHERE cliente_id = ? AND categoria_id = ?")->execute([$cid, $id]);
    $pdo->prepare("DELETE FROM cotizaciones_categorias WHERE id = ? AND cliente_id = ?")->execute([$id, $cid]);
}

/** COT-AAAAMMDD-NN: el siguiente del día de la cotización. */
function cotizacionSiguienteNumero(PDO $pdo, int $cid, string $fecha): string
{
    $base = 'COT-' . str_replace('-', '', $fecha) . '-';
    $st = $pdo->prepare("SELECT numero FROM cotizaciones WHERE cliente_id = ? AND numero LIKE ? ORDER BY numero DESC LIMIT 1");
    $st->execute([$cid, $base . '%']);
    $ult = (int)substr((string)$st->fetchColumn(), strlen($base));
    return $base . str_pad((string)($ult + 1), 2, '0', STR_PAD_LEFT);
}

function cotizacionObtener(PDO $pdo, int $cid, int $id): array
{
    $st = $pdo->prepare("SELECT c.*, cf.nombre AS cliente, cf.rtn AS cliente_rtn, cf.email AS cliente_email, cf.direccion AS cliente_direccion,
                                cf.telefono AS cliente_telefono, cf.contacto_nombre, f.correlativo AS factura_correlativo
                         FROM cotizaciones c JOIN clientes_factura cf ON cf.id = c.receptor_id
                         LEFT JOIN facturas f ON f.id = c.factura_id
                         WHERE c.id = ? AND c.cliente_id = ?");
    $st->execute([$id, $cid]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) throw new Exception("Cotización no encontrada.");
    return $c;
}

function cotizacionItems(PDO $pdo, int $id): array
{
    $st = $pdo->prepare("SELECT * FROM cotizacion_items WHERE cotizacion_id = ? ORDER BY orden, id");
    $st->execute([$id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Estado que se muestra: las que vencieron sin respuesta salen como «Vencida». */
function cotizacionEstadoVista(array $c): string
{
    return in_array($c['estado'], ['borrador', 'enviada'], true) && $c['valida_hasta'] < date('Y-m-d') ? 'vencida' : $c['estado'];
}

/** Crea o edita una cotización con sus líneas (cada línea con un producto de la empresa, como en la factura). */
function cotizacionGuardar(PDO $pdo, int $cid, int $uid, array $d, int $id = 0): int
{
    $receptor = (int)($d['receptor_id'] ?? 0);
    $st = $pdo->prepare("SELECT COUNT(*) FROM clientes_factura WHERE id = ? AND cliente_id = ?");
    $st->execute([$receptor, $cid]);
    if (!$st->fetchColumn()) throw new Exception("Elige el cliente.");
    $fecha = (string)($d['fecha'] ?? '');
    $valida = (string)($d['valida_hasta'] ?? '');
    foreach ([$fecha, $valida] as $f) if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) || !strtotime($f)) throw new Exception("Revisa la fecha y la vigencia.");
    if ($valida < $fecha) throw new Exception("La vigencia no puede ser anterior a la fecha de la cotización.");
    $categoria = null;
    if (cotizacionCategoriasDisponible($pdo) && (int)($d['categoria_id'] ?? 0)) {
        $st = $pdo->prepare("SELECT id FROM cotizaciones_categorias WHERE id = ? AND cliente_id = ?");
        $st->execute([(int)$d['categoria_id'], $cid]);
        $categoria = (int)$st->fetchColumn() ?: null;
    }

    $lineas = array_values(array_filter((array)($d['items'] ?? []), fn($l) => is_array($l) && (!empty($l['producto_id']) || trim((string)($l['descripcion'] ?? '')) !== '')));
    if (!$lineas) throw new Exception("Agrega al menos un producto o servicio.");
    $prod = $pdo->prepare("SELECT nombre, tipo_isv FROM productos_clientes WHERE id = ? AND cliente_id = ?");
    $items = [];
    $sub = $i15 = $i18 = 0.0;
    foreach ($lineas as $n => $l) {
        $pid = (int)($l['producto_id'] ?? 0);
        if (!$pid) throw new Exception("Línea " . ($n + 1) . ": elige el producto o servicio (o créalo con «Nuevo producto»).");
        $prod->execute([$pid, $cid]);
        $p = $prod->fetch(PDO::FETCH_ASSOC);
        if (!$p) throw new Exception("Línea " . ($n + 1) . ": el producto no existe.");
        $cant = round((float)str_replace(',', '', (string)($l['cantidad'] ?? 0)), 2);
        $precio = round((float)str_replace(',', '', (string)($l['precio'] ?? 0)), 2);
        if ($cant <= 0) throw new Exception("Línea " . ($n + 1) . ": la cantidad debe ser mayor a 0.");
        if ($precio < 0) throw new Exception("Línea " . ($n + 1) . ": el precio no puede ser negativo.");
        $isv = (int)$p['tipo_isv'];
        $t = round($cant * $precio, 2);
        $sub += $t;
        if ($isv === 15) $i15 += $t * 0.15; elseif ($isv === 18) $i18 += $t * 0.18;
        $desc = trim((string)($l['descripcion'] ?? '')) ?: $p['nombre'];
        $items[] = [$pid, mb_substr($desc, 0, 400), trim((string)($l['detalle'] ?? '')) ?: null, $cant, $precio, $isv, $t, $n];
    }
    $i15 = round($i15, 2);
    $i18 = round($i18, 2);
    $txt = fn($k, $max = 5000) => ($v = trim((string)($d[$k] ?? ''))) === '' ? null : mb_substr($v, 0, $max);
    $datos = [$receptor, $fecha, $valida, $txt('servicio', 255), $txt('incluye'), $txt('condiciones'), $txt('notas'), round($sub, 2), $i15, $i18, round($sub + $i15 + $i18, 2)];

    $guardarCategoria = function (int $id) use ($pdo, $cid, $categoria) {
        if (cotizacionCategoriasDisponible($pdo)) $pdo->prepare("UPDATE cotizaciones SET categoria_id = ? WHERE id = ? AND cliente_id = ?")->execute([$categoria, $id, $cid]);
    };
    if ($id) {
        $c = cotizacionObtener($pdo, $cid, $id);
        if ($c['estado'] === 'facturada') throw new Exception("La cotización ya se facturó: no se puede editar.");
        $pdo->prepare("UPDATE cotizaciones SET receptor_id = ?, fecha = ?, valida_hasta = ?, servicio = ?, incluye = ?, condiciones = ?, notas = ?,
                       subtotal = ?, isv_15 = ?, isv_18 = ?, total = ? WHERE id = ? AND cliente_id = ?")->execute([...$datos, $id, $cid]);
        $pdo->prepare("DELETE FROM cotizacion_items WHERE cotizacion_id = ?")->execute([$id]);
    } else {
        $pdo->prepare("INSERT INTO cotizaciones (cliente_id, numero, receptor_id, fecha, valida_hasta, servicio, incluye, condiciones, notas, subtotal, isv_15, isv_18, total, usuario_id)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([$cid, cotizacionSiguienteNumero($pdo, $cid, $fecha), ...$datos, $uid]);
        $id = (int)$pdo->lastInsertId();
    }
    $guardarCategoria($id);
    $ins = $pdo->prepare("INSERT INTO cotizacion_items (cotizacion_id, producto_id, descripcion, detalle, cantidad, precio, tipo_isv, subtotal, orden) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($items as $it) $ins->execute([$id, ...$it]);
    return $id;
}

function cotizacionArchivo(array $c): string
{
    return 'Cotizacion_' . $c['numero'] . '.pdf';
}

/** Fecha larga: 12 de octubre de 2026 */
function cotizacionFechaLarga(string $f): string
{
    $MES = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    return (int)substr($f, 8, 2) . ' de ' . $MES[(int)substr($f, 5, 2)] . ' de ' . substr($f, 0, 4);
}

/** Sello de la empresa en base64 (o '' si no tiene). */
function selloB64(PDO $pdo, int $cid): string
{
    try {
        $st = $pdo->prepare("SELECT sello FROM clientes_saas WHERE id = ?");
        $st->execute([$cid]);
        $s = (string)$st->fetchColumn();
    } catch (Throwable $e) {
        return '';
    }
    $ruta = $s ? COTIZACION_UPLOADS . '/firmas/' . $s : '';
    if (!$ruta || !is_file($ruta)) return '';
    return 'data:' . ((new finfo(FILEINFO_MIME_TYPE))->file($ruta) ?: 'image/png') . ';base64,' . base64_encode(file_get_contents($ruta));
}

/** Logo de la empresa para PDF (dompdf no lee SVG ni imágenes remotas): el PNG del sistema si el logo es SVG. */
function cotizacionLogoB64(array $emp): string
{
    if (!empty($emp['logo_url']) && !preg_match('/\.svg($|\?)/i', $emp['logo_url'])) {
        $d = @file_get_contents($emp['logo_url'], false, stream_context_create(['http' => ['timeout' => 4], 'https' => ['timeout' => 4]]));
        if ($d && str_starts_with($m = (string)(new finfo(FILEINFO_MIME_TYPE))->buffer($d), 'image/')) return 'data:' . $m . ';base64,' . base64_encode($d);
    }
    $local = __DIR__ . '/../clientes/css/logo-correo.png';
    return is_file($local) ? 'data:image/png;base64,' . base64_encode(file_get_contents($local)) : '';
}

/** Lista de viñetas: una por línea; «Título: texto» pone el título en negrita. */
function cotizacionVinetas(string $texto, int $dias, callable $e): string
{
    $li = '';
    foreach (preg_split('/\R/', $texto) as $l) {
        $l = trim(preg_replace('/^[-•*]\s*/u', '', trim($l)));
        if ($l === '') continue;
        $l = str_replace('{dias}', (string)$dias, $l);
        $li .= '<tr><td class="b">•</td><td>' . (preg_match('/^([^:]{2,40}):\s*(.+)$/u', $l, $m) ? '<strong>' . $e($m[1]) . ':</strong> ' . $e($m[2]) : $e($l)) . '</td></tr>';
    }
    return $li ? '<table class="v">' . $li . '</table>' : '';
}

/** HTML del documento (lo usan el PDF y la vista en pantalla). */
function cotizacionHtml(PDO $pdo, int $cid, int $id): string
{
    $c = cotizacionObtener($pdo, $cid, $id);
    $items = cotizacionItems($pdo, $id);
    $cfg = cotizacionConfig($pdo, $cid);
    $st = $pdo->prepare("SELECT * FROM clientes_saas WHERE id = ?");
    $st->execute([$cid]);
    $emp = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
    $L = fn($v) => 'L ' . number_format((float)$v, 2);
    $cant = fn($v) => rtrim(rtrim(number_format((float)$v, 2, '.', ','), '0'), '.');
    $logo = cotizacionLogoB64($emp);
    $sello = !empty($emp['sello_cotizaciones']) ? selloB64($pdo, $cid) : '';
    $firm = firmantesParaPdf($pdo, $cid, COTIZACION_UPLOADS)[$cfg['firmante']] ?? ['nombre' => '', 'cargo' => '', 'firma_b64' => ''];
    $alias = ($emp['alias'] ?? '') ?: ($emp['nombre'] ?? '');
    $correo = $cfg['contacto_email'] ?: ($emp['email'] ?? '');
    $tel = $cfg['contacto_telefono'] ?: ($emp['telefono'] ?? '');
    $dias = max(1, (int)round((strtotime($c['valida_hasta']) - strtotime($c['fecha'])) / 86400));
    $web = preg_replace('#^https?://(www\.)?|/.*$#', '', (string)((correoConfig($pdo, $cid, 'facturacion') ?? [])['enlace_url'] ?? ''));

    $filas = '';
    foreach ($items as $i => $it)
        $filas .= '<tr class="' . ($i % 2 ? 'par' : '') . '"><td class="c">' . $e($cant($it['cantidad'])) . '</td><td>' . $e($it['descripcion'])
            . ($it['detalle'] ? '<div class="det">' . nl2br($e($it['detalle'])) . '</div>' : '') . '</td><td class="r">' . $L($it['precio']) . '</td><td class="r">' . $L($it['subtotal']) . '</td></tr>';
    $isv = (float)$c['isv_15'] + (float)$c['isv_18'];
    $tot = '<tr class="tot"><td></td><td></td><td class="r"><strong>Subtotal</strong></td><td class="r n">' . $L($c['subtotal']) . '</td></tr>'
        . ((float)$c['isv_15'] > 0 ? '<tr class="tot"><td></td><td></td><td class="r"><strong>ISV (15 %)</strong></td><td class="r n">' . $L($c['isv_15']) . '</td></tr>' : '')
        . ((float)$c['isv_18'] > 0 ? '<tr class="tot"><td></td><td></td><td class="r"><strong>ISV (18 %)</strong></td><td class="r n">' . $L($c['isv_18']) . '</td></tr>' : '')
        . (!$isv ? '<tr class="tot"><td></td><td></td><td class="r"><strong>ISV</strong></td><td class="r n">Exento</td></tr>' : '')
        . '<tr class="tot"><td></td><td></td><td class="r"><strong>Total a pagar</strong></td><td class="r n">' . $L($c['total']) . '</td></tr>';
    $incluye = cotizacionVinetas((string)$c['incluye'], $dias, $e);
    $condic = cotizacionVinetas((string)$c['condiciones'], $dias, $e);

    return '<!doctype html><html><head><meta charset="utf-8"><style>
        @page { margin: 46px 52px 60px; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10.5px; color: #1f2937; }
        .top { width: 100%; }
        .top td { vertical-align: top; }
        .logo { height: 70px; max-width: 220px; }
        .emp { font-size: 9.5px; color: #4b5563; line-height: 1.45; margin-top: 6px; }
        .emp b { color: #111827; }
        .tit { text-align: right; }
        .tit h1 { margin: 0 0 4px; font-size: 26px; color: #e8590c; letter-spacing: .5px; }
        .tit div { font-size: 11px; line-height: 1.5; }
        .raya { border-top: 2.5px solid #e8590c; margin: 16px 0 14px; }
        table.t { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        table.t th { background: #e8590c; color: #fff; text-align: left; padding: 7px 9px; font-size: 10.5px; }
        table.t td { padding: 7px 9px; border: 1px solid #f1e7e0; }
        table.t tr.par td { background: #faf6f3; }
        table.t td.k { width: 26%; font-weight: bold; }
        table.t .c { text-align: center; width: 9%; }
        table.t .r { text-align: right; white-space: nowrap; }
        table.t tr.tot td { background: #fde8dc; padding-top: 3px; padding-bottom: 3px; }
        table.t td.n { color: #e8590c; font-weight: bold; }
        .det { color: #6b7280; font-size: 9.5px; margin-top: 2px; }
        h3 { font-size: 13px; margin: 12px 0 4px; color: #111827; }
        table.v { border-collapse: collapse; margin-left: 6px; }
        table.v td { padding: 0 0 1px; vertical-align: top; line-height: 1.3; }
        table.v td.b { color: #e8590c; font-weight: bold; width: 14px; }
        .notas { margin-top: 10px; font-size: 10px; color: #374151; }
        .firma { margin-top: 50px; position: relative; }
        .firma .img { height: 78px; width: 300px; text-align: center; margin-bottom: -18px; }
        .firma .img img { max-height: 75px; max-width: 300px; }
        .firma .linea { border-top: 1.2px solid #111827; width: 300px; padding-top: 5px; font-size: 10px; line-height: 1.45; color: #4b5563; }
        .firma .linea b { color: #111827; }
        .sello { height: 0; overflow: visible; }
        .sello img { height: 112px; margin-left: 205px; margin-top: -100px; }
        .pie { position: fixed; bottom: -36px; left: 0; right: 0; text-align: center; font-size: 8.5px; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 6px; }
    </style></head><body>
    <table class="top"><tr>
        <td style="width:58%">' . ($logo ? '<img class="logo" src="' . $logo . '">' : '<h2 style="margin:0;color:#e8590c">' . $e($alias) . '</h2>') . '
            <div class="emp">' . ($cfg['lema'] ? $e($cfg['lema']) . '<br>' : '') . '<b>' . $e(($emp['razon_social'] ?? '') ?: ($emp['nombre'] ?? '')) . '</b>'
                . (!empty($emp['rtn']) ? '<br>RTN: ' . $e($emp['rtn']) : '') . (!empty($emp['direccion']) ? '<br>' . $e($emp['direccion']) : '')
                . ($correo || $tel ? '<br>' . $e(trim($correo . ($correo && $tel ? ' • ' : '') . $tel)) : '') . '</div></td>
        <td class="tit"><h1>COTIZACIÓN</h1><div>No. ' . $e($c['numero']) . '</div><div>Fecha: ' . cotizacionFechaLarga($c['fecha']) . '</div><div>Válida hasta: ' . cotizacionFechaLarga($c['valida_hasta']) . '</div></td>
    </tr></table>
    <div class="raya"></div>
    <table class="t"><tr><th colspan="2">Cliente</th></tr>
        <tr><td class="k">Razón social</td><td>' . $e($c['cliente']) . '</td></tr>'
        . ($c['cliente_rtn'] ? '<tr class="par"><td class="k">RTN</td><td>' . $e($c['cliente_rtn']) . '</td></tr>' : '')
        . ($c['contacto_nombre'] ? '<tr><td class="k">Atención</td><td>' . $e($c['contacto_nombre']) . '</td></tr>' : '')
        . ($c['servicio'] ? '<tr class="par"><td class="k">Servicio</td><td>' . $e($c['servicio']) . '</td></tr>' : '') . '
    </table>
    <table class="t"><tr><th class="c" style="width:9%">Cant.</th><th style="width:55%">Descripción</th><th class="r" style="width:18%">Precio unitario</th><th class="r" style="width:18%">Total</th></tr>' . $filas . $tot . '</table>
    ' . ($incluye ? '<h3>Incluye</h3>' . $incluye : '') . ($condic ? '<h3>Condiciones</h3>' . $condic : '')
    . ($c['notas'] ? '<div class="notas">' . nl2br($e($c['notas'])) . '</div>' : '') . '
    <div class="firma">
        <div class="img">' . (!empty($firm['firma_b64']) ? '<img src="' . $firm['firma_b64'] . '">' : '') . '</div>
        ' . ($sello ? '<div class="sello"><img src="' . $sello . '"></div>' : '') . '
        <div class="linea">' . ($firm['nombre'] ? '<b>' . $e($firm['nombre']) . '</b><br>' : '') . $e(trim(($firm['cargo'] ?? '') . ($firm['cargo'] ? ' • ' : '') . $alias))
            . ($correo || $tel ? '<br>' . $e(trim($correo . ($correo && $tel ? ' • ' : '') . ($tel ? 'Cel. ' . $tel : ''))) : '') . '</div>
    </div>
    <div class="pie">' . $e(implode('  •  ', array_filter([$alias, !empty($emp['rtn']) ? 'RTN ' . $emp['rtn'] : '', !empty($emp['direccion']) ? trim(explode(',', $emp['direccion'])[0]) : '', $web]))) . '</div>
    </body></html>';
}

function cotizacionPdf(PDO $pdo, int $cid, int $id): string
{
    require_once __DIR__ . '/../vendor/autoload.php';
    $opt = new \Dompdf\Options();
    $opt->set('defaultFont', 'DejaVu Sans');
    $opt->set('isRemoteEnabled', false);
    $dompdf = new \Dompdf\Dompdf($opt);
    $dompdf->loadHtml(cotizacionHtml($pdo, $cid, $id), 'UTF-8');
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();
    return (string)$dompdf->output();
}

/** Asunto y mensaje del correo, redactados con los datos de la cotización. */
function cotizacionCorreoDefecto(PDO $pdo, int $cid, array $c): array
{
    $cfg = cotizacionConfig($pdo, $cid);
    $st = $pdo->prepare("SELECT nombre, alias FROM clientes_saas WHERE id = ?");
    $st->execute([$cid]);
    $emp = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $alias = ($emp['alias'] ?? '') ?: ($emp['nombre'] ?? '');
    $firm = firmantesLista($pdo, $cid)[$cfg['firmante']] ?? ['nombre' => '', 'cargo' => ''];
    $e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
    $vars = ['{numero}' => $c['numero'], '{empresa}' => $alias, '{cliente}' => $c['cliente'], '{contacto}' => $c['contacto_nombre'] ?: $c['cliente'],
             '{total}' => 'L ' . number_format((float)$c['total'], 2), '{valida_hasta}' => cotizacionFechaLarga($c['valida_hasta']), '{servicio}' => $c['servicio'] ?: 'los servicios solicitados'];
    $asunto = strtr($cfg['asunto_correo'], $vars);
    if (trim($cfg['mensaje_correo']) !== '') {
        $mensaje = nl2br($e(strtr($cfg['mensaje_correo'], $vars)));
    } else {
        $mensaje = '<p>Estimado(a) ' . $e($vars['{contacto}']) . ':</p>'
            . '<p>Reciba un cordial saludo de parte de todo el equipo de <strong>' . $e($alias) . '</strong>. Agradecemos la confianza y la oportunidad de presentarle nuestra propuesta para <strong>' . $e($vars['{servicio}']) . '</strong>.</p>'
            . '<p>Adjuntamos la cotización <strong>' . $e($c['numero']) . '</strong> por un total de <strong>' . $e($vars['{total}']) . '</strong>, con el detalle de lo que incluye y las condiciones del servicio. '
            . 'Esta propuesta es válida hasta el <strong>' . $e($vars['{valida_hasta}']) . '</strong>.</p>'
            . '<p>Quedamos a su disposición para resolver cualquier duda, ajustar la propuesta a sus necesidades o coordinar los siguientes pasos. Para aprobarla, basta con responder a este correo.</p>'
            . '<p>Atentamente,<br>' . ($firm['nombre'] ? '<strong>' . $e($firm['nombre']) . '</strong>' . ($firm['cargo'] ? '<br>' . $e($firm['cargo']) : '') . '<br>' . $e($alias) : '<strong>' . $e($alias) . '</strong>') . '</p>';
    }
    return [$asunto, $mensaje];
}

/** Correo con el formato de la empresa: logo, mensaje y un resumen de la cotización. */
function cotizacionCorreoPlantilla(PDO $pdo, int $cid, array $c, string $mensaje, array $cfgCorreo): array
{
    $e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
    $st = $pdo->prepare("SELECT nombre, alias, logo_url FROM clientes_saas WHERE id = ?");
    $st->execute([$cid]);
    $emp = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $alias = ($emp['alias'] ?? '') ?: ($emp['nombre'] ?? '');
    $logoUrl = ($cfgCorreo['logo_url'] ?? '') ?: '';
    $enlace = $cfgCorreo['enlace_url'] ?? '';
    $logo = $logoUrl ? '<img src="' . $e($logoUrl) . '" alt="' . $e($alias) . '" height="56" style="height:56px;width:auto;max-width:200px;border:0;display:block">'
        : '<span style="font-size:20px;font-weight:700;color:#e8590c">' . $e($alias) . '</span>';
    if ($enlace) $logo = '<a href="' . $e($enlace) . '" target="_blank" style="text-decoration:none">' . $logo . '</a>';
    $mensaje = cobroLimpiarHtmlSeguro($mensaje);
    $fila = fn($k, $v, $fuerte = false) => '<tr><td style="padding:6px 0;color:#64748b;font-size:13px">' . $k . '</td><td style="padding:6px 0;text-align:right;font-size:13px;' . ($fuerte ? 'font-weight:700;color:#e8590c;font-size:15px' : 'color:#0f172a;font-weight:600') . '">' . $v . '</td></tr>';
    $resumen = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:22px 0 4px;background:#fff7f2;border:1px solid #fde1d0;border-radius:10px"><tr><td style="padding:14px 18px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
        . $fila('Cotización', $e($c['numero'])) . $fila('Fecha', $e(cotizacionFechaLarga($c['fecha']))) . $fila('Válida hasta', $e(cotizacionFechaLarga($c['valida_hasta'])))
        . $fila('Total', 'L ' . number_format((float)$c['total'], 2), true)
        . '</table></td></tr></table><p style="margin:10px 0 0;font-size:12.5px;color:#64748b">📎 Adjunto: ' . $e(cotizacionArchivo($c)) . '</p>';
    $html = '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#f1f5f9;font-family:Segoe UI,Helvetica,Arial,sans-serif">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 12px"><tr><td align="center">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0">'
        . '<tr><td style="padding:22px 28px;border-bottom:4px solid #e8590c">' . $logo . '</td></tr>'
        . '<tr><td style="padding:28px;font-size:14.5px;line-height:1.65;color:#1e293b">' . $mensaje . $resumen . '</td></tr>'
        . '<tr><td style="padding:16px 28px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:11.5px;line-height:1.55;color:#94a3b8">'
        . correoPieAutomatico($cfgCorreo, $e) . ' La información de este mensaje es confidencial y está dirigida únicamente a su destinatario.'
        . ($enlace ? '<br><a href="' . $e($enlace) . '" target="_blank" style="color:#64748b">' . $e(preg_replace('#^https?://|/$#', '', $enlace)) . '</a>' : '')
        . '</td></tr></table></td></tr></table></body></html>';
    $texto = trim(html_entity_decode(strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $mensaje)), ENT_QUOTES, 'UTF-8'))
        . "\n\nCotización " . $c['numero'] . ' · Total L ' . number_format((float)$c['total'], 2) . ' · Válida hasta ' . cotizacionFechaLarga($c['valida_hasta']);
    return [$html, $texto];
}

/** Deja solo etiquetas de formato en el mensaje escrito por el usuario. */
function cobroLimpiarHtmlSeguro(string $html): string
{
    $html = strip_tags($html, '<p><br><strong><b><em><i><u><ul><ol><li><a><span>');
    $html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
    return preg_replace('/href\s*=\s*("|\')\s*javascript:[^"\']*\1/i', 'href="#"', $html);
}

/** Envía la cotización con su PDF. Si estaba en borrador, queda «Enviada». */
function cotizacionEnviar(PDO $pdo, int $cid, int $id, ?int $uid, string $para, string $cc, string $asunto, string $mensaje): void
{
    $c = cotizacionObtener($pdo, $cid, $id);
    $para = trim($para);
    if ($para === '' || !correoLista($para)) throw new Exception("Escribe el correo del cliente.");
    foreach (array_merge(correoLista($para), correoLista($cc)) as $m) if (!filter_var($m, FILTER_VALIDATE_EMAIL)) throw new Exception("Correo no válido: $m");
    if (trim($asunto) === '') throw new Exception("Escribe el asunto.");
    $cfgCorreo = correoConfig($pdo, $cid, 'facturacion') ?? [];
    [$html, $texto] = cotizacionCorreoPlantilla($pdo, $cid, $c, $mensaje, $cfgCorreo);
    $tmp = tempnam(sys_get_temp_dir(), 'cot');
    file_put_contents($tmp, cotizacionPdf($pdo, $cid, $id));
    try {
        correoEnviar($pdo, $cid, $para, mb_substr($asunto, 0, 255), $html, $texto, [['ruta' => $tmp, 'nombre' => cotizacionArchivo($c)]], 'cotizacion', $id, $uid, 'facturacion', $cc);
    } finally {
        @unlink($tmp);
    }
    $pdo->prepare("UPDATE cotizaciones SET estado = IF(estado = 'borrador', 'enviada', estado), enviada_en = NOW(), enviada_a = ? WHERE id = ? AND cliente_id = ?")
        ->execute([mb_substr(trim($para . ($cc ? ', ' . $cc : '')), 0, 500), $id, $cid]);
}
