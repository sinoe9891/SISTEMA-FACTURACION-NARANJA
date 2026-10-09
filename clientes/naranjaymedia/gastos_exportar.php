<?php
// Exportar gastos seleccionados (o todos los del filtro) desde la lista de Gastos.
//   GET  ?xlsx=1,2,3                       → descarga el Excel con esos gastos
//   POST accion=iniciar ids=… bouchers=0|1  → {token, total}   (ZIP por partes, con barra de progreso)
//   POST accion=paso token=…               → copia comprobantes y genera bouchers de a 4 → {hechos, total}
//   GET  ?descargar=<token>                → arma y descarga el ZIP (Excel + comprobantes/ + bouchers/) y borra los temporales
// Lo usa clientes/js/gastos-exportar.js.
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/xlsx.php';
require_once '../../includes/bouchers.php';
require_once '../../vendor/autoload.php';

const GEXP_MAX = 1000;       // gastos por descarga
const GEXP_POR_PASO = 4;     // bouchers por paso (cada uno es un PDF)

function gexpIds(string $t): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $t)), fn($i) => $i > 0)));
    if (!$ids) throw new Exception("Selecciona al menos un gasto.");
    if (count($ids) > GEXP_MAX) throw new Exception("Máximo " . GEXP_MAX . " gastos por descarga.");
    return $ids;
}

function gexpGastos(PDO $pdo, int $cid, array $ids): array
{
    $st = $pdo->prepare("SELECT g.*, cg.nombre AS categoria, t.banco AS tarjeta_banco, t.ultimos_digitos AS tarjeta_digitos
                         FROM gastos g LEFT JOIN categorias_gastos cg ON cg.id = g.categoria_id LEFT JOIN tarjetas t ON t.id = g.tarjeta_id
                         WHERE g.cliente_id = ? AND g.id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY g.fecha, g.id");
    $st->execute([$cid, ...$ids]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Ruta del comprobante en el servidor (misma regla que gasto_archivo.php) o null. */
function gexpComprobante(array $g): ?string
{
    if (empty($g['archivo_adjunto'])) return null;
    $base = realpath(__DIR__ . '/includes/uploads');
    foreach ([__DIR__ . '/includes/uploads/gastos/' . basename($g['archivo_adjunto']), __DIR__ . '/includes/uploads/comprobantes_nomina/' . $g['archivo_adjunto']] as $c) {
        $real = realpath($c);
        if ($real && $base && str_starts_with($real, $base . DIRECTORY_SEPARATOR) && is_file($real)) return $real;
    }
    return null;
}

/** 0247_2026-10-01_Pago-de-hosting.pdf */
function gexpNombre(array $g, string $ext): string
{
    // Sin acentos ni ñ (iconv TRANSLIT deja «'e» en algunos servidores)
    $n = strtr((string)$g['descripcion'], ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
    $n = iconv('UTF-8', 'ASCII//IGNORE', $n) ?: 'gasto';
    $n = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $n), '-');
    return str_pad((string)$g['id'], 4, '0', STR_PAD_LEFT) . '_' . $g['fecha'] . '_' . substr($n ?: 'gasto', 0, 50) . '.' . strtolower($ext);
}

function gexpXlsx(array $gastos): XlsxSimple
{
    $met = ['efectivo' => 'Efectivo', 'transferencia' => 'Transferencia', 'cheque' => 'Cheque', 'tarjeta' => 'Tarjeta', 'otro' => 'Otro'];
    $nat = ['gasto' => 'Gasto del período', 'capital' => 'Abono a capital', 'activo' => 'Compra de activo', 'anticipo' => 'Anticipo a proveedor', 'isv' => 'Pago de ISV', 'retiro' => 'Retiro de socio'];
    $x = new XlsxSimple('Gastos');
    $x->columnas([
        ['N.º', 8, 'entero'], ['Fecha', 12, 'fecha'], ['Descripción', 46], ['Proveedor', 26], ['Categoría', 22], ['Tipo', 14],
        ['Naturaleza', 20], ['Estado', 11], ['Método de pago', 16], ['Tarjeta', 18], ['Referencia / factura', 20],
        ['Monto', 14, 'dinero'], ['Comprobante', 34], ['Notas', 40],
    ]);
    $tot = 0.0;
    $anulados = 0;
    foreach ($gastos as $g) {
        $anulado = $g['estado'] === 'anulado';
        $comp = gexpComprobante($g);
        $x->fila([
            (int)$g['id'], $g['fecha'], $g['descripcion'], $g['proveedor'], $g['categoria'], ucfirst((string)$g['tipo']),
            $nat[$g['naturaleza'] ?? 'gasto'] ?? $g['naturaleza'], ucfirst((string)$g['estado']), $met[$g['metodo_pago']] ?? $g['metodo_pago'],
            $g['tarjeta_banco'] ? $g['tarjeta_banco'] . ' •' . $g['tarjeta_digitos'] : '', $g['factura_ref'],
            (float)$g['monto'], $comp ? 'comprobantes/' . gexpNombre($g, pathinfo($comp, PATHINFO_EXTENSION)) : (empty($g['archivo_adjunto']) ? 'Sin comprobante' : 'Archivo no encontrado'),
            $g['notas'],
        ], false, $anulado);   // anulados en rojo tachado: no suman
        if ($anulado) { $anulados++; continue; }
        $tot += (float)$g['monto'];
    }
    $x->fila([]);
    $x->fila(['TOTAL', null, (count($gastos) - $anulados) . ' gasto(s)' . ($anulados ? " · sin contar $anulados anulado(s)" : ''), null, null, null, null, null, null, null, null, round($tot, 2)], true);
    return $x;
}

function gexpDir(string $token): string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) throw new Exception("Descarga no válida.");
    $d = sys_get_temp_dir() . '/gastos_export/' . $token . '/';
    if (!is_dir($d)) throw new Exception("La descarga ya no existe; vuelve a intentarlo.");
    $meta = json_decode((string)@file_get_contents($d . 'meta.json'), true) ?: [];
    if ((int)($meta['usuario'] ?? 0) !== (int)USUARIO_ID || (int)($meta['cid'] ?? 0) !== (int)cliente_actual()) throw new Exception("Descarga no válida.");
    return $d;
}

function gexpBorrar(string $d): void
{
    foreach (['comprobantes', 'bouchers'] as $sub) { array_map('unlink', glob($d . $sub . '/*') ?: []); @rmdir($d . $sub); }
    array_map('unlink', glob($d . '*') ?: []);
    @rmdir($d);
}

try {
    $cid = (int)cliente_actual();
    if (!$cid) throw new Exception("Empresa no identificada.");
    $puedeBouchers = in_array(USUARIO_ROL, ['admin', 'superadmin', 'nomina'], true);
    @set_time_limit(120);

    // Solo el Excel
    if (isset($_GET['xlsx'])) {
        $gastos = gexpGastos($pdo, $cid, gexpIds((string)$_GET['xlsx']));
        if (!$gastos) throw new Exception("Gastos no encontrados.");
        gexpXlsx($gastos)->descargar('gastos_' . date('Y-m-d_His') . '.xlsx');
        exit;
    }

    if (isset($_GET['descargar'])) {
        $d = gexpDir((string)$_GET['descargar']);
        $meta = json_decode(file_get_contents($d . 'meta.json'), true);
        $zipRuta = $d . 'gastos.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipRuta, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new Exception("No se pudo crear el ZIP.");
        $gastos = gexpGastos($pdo, $cid, $meta['ids']);
        $zip->addFromString('gastos.xlsx', gexpXlsx($gastos)->contenido());
        foreach (['comprobantes', 'bouchers'] as $sub)
            foreach (glob($d . $sub . '/*') ?: [] as $f) $zip->addFile($f, $sub . '/' . basename($f));
        $faltan = array_filter($gastos, fn($g) => !empty($g['archivo_adjunto']) && !gexpComprobante($g));
        $sin = array_filter($gastos, fn($g) => empty($g['archivo_adjunto']));
        $leeme = "Exportación de gastos — " . date('d/m/Y H:i') . "\r\n\r\n"
            . count($gastos) . " gasto(s) en gastos.xlsx\r\n"
            . count(glob($d . 'comprobantes/*') ?: []) . " comprobante(s) en comprobantes/ (nombre: N.º_fecha_descripción)\r\n"
            . ($meta['bouchers'] ? count(glob($d . 'bouchers/*') ?: []) . " boucher(s) en bouchers/ (solo gastos pagados)\r\n" : '')
            . ($sin ? "\r\nSin comprobante: " . implode(', ', array_map(fn($g) => '#' . $g['id'], $sin)) . "\r\n" : '')
            . ($faltan ? "\r\nEl archivo no está en el servidor: " . implode(', ', array_map(fn($g) => '#' . $g['id'], $faltan)) . "\r\n" : '');
        $zip->addFromString('LEEME.txt', $leeme);
        $zip->close();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="gastos_' . date('Y-m-d_His') . '.zip"');
        header('Content-Length: ' . filesize($zipRuta));
        header('Cache-Control: no-store');
        readfile($zipRuta);
        gexpBorrar($d);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");

    if (($_POST['accion'] ?? '') === 'iniciar') {
        // Limpieza de descargas viejas (más de 2 horas)
        foreach (glob(sys_get_temp_dir() . '/gastos_export/*', GLOB_ONLYDIR) ?: [] as $viejo)
            if (filemtime($viejo) < time() - 7200) gexpBorrar($viejo . '/');
        $gastos = gexpGastos($pdo, $cid, gexpIds((string)($_POST['ids'] ?? '')));
        if (!$gastos) throw new Exception("Gastos no encontrados.");
        $token = bin2hex(random_bytes(16));
        $d = sys_get_temp_dir() . '/gastos_export/' . $token . '/';
        if (!mkdir($d . 'comprobantes', 0700, true)) throw new Exception("No se pudo preparar la descarga.");
        $bouchers = $puedeBouchers && !empty($_POST['bouchers']);
        if ($bouchers) mkdir($d . 'bouchers', 0700);
        file_put_contents($d . 'meta.json', json_encode(['usuario' => (int)USUARIO_ID, 'cid' => $cid, 'ids' => array_map(fn($g) => (int)$g['id'], $gastos), 'bouchers' => $bouchers, 'hechos' => 0]));
        echo json_encode(['success' => true, 'token' => $token, 'total' => count($gastos)]);
        exit;
    }

    if (($_POST['accion'] ?? '') === 'paso') {
        $d = gexpDir((string)($_POST['token'] ?? ''));
        $meta = json_decode(file_get_contents($d . 'meta.json'), true);
        // Con bouchers van de a 4 (cada uno es un PDF); solo comprobantes, de a 40
        $siguientes = array_slice($meta['ids'], $meta['hechos'], $meta['bouchers'] ? GEXP_POR_PASO : 40);
        if ($siguientes) {
            $ctx = $meta['bouchers'] ? boucherContexto($pdo, $cid, __DIR__ . '/includes/uploads') : null;
            foreach (gexpGastos($pdo, $cid, $siguientes) as $g) {
                if ($comp = gexpComprobante($g)) copy($comp, $d . 'comprobantes/' . gexpNombre($g, pathinfo($comp, PATHINFO_EXTENSION)));
                if ($ctx && $g['estado'] === 'pagado') {
                    $dat = boucherDatos($ctx, $g);
                    file_put_contents($d . 'bouchers/' . boucherArchivo($dat), boucherPdf([boucherPagina($ctx, $dat)]));
                }
            }
            $meta['hechos'] += count($siguientes);
            file_put_contents($d . 'meta.json', json_encode($meta));
        }
        echo json_encode(['success' => true, 'hechos' => $meta['hechos'], 'total' => count($meta['ids'])]);
        exit;
    }
    throw new Exception("Acción no válida.");
} catch (Throwable $e) {
    if (!headers_sent()) { http_response_code(400); header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode(['success' => false, 'error' => $e instanceof PDOException ? 'Error de base de datos.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
