<?php
// ZIP de bouchers por partes, con barra de progreso (lo usa clientes/js/bouchers-descarga.js).
//   POST accion=iniciar ids=1,2,3   → {token, total}
//   POST accion=paso token=…        → genera los siguientes 4 PDF → {hechos, total}
//   GET  ?descargar=<token>         → arma y descarga el ZIP (y borra los temporales)
// Cada paso es una petición corta: el servidor no la corta por tiempo aunque sean cientos de bouchers.
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/bouchers.php';
require_once '../../vendor/autoload.php';

const LOTE_POR_PASO = 4;

function loteDir(string $token): string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) throw new Exception("Descarga no válida.");
    $d = sys_get_temp_dir() . '/bouchers_lote/' . $token . '/';
    if (!is_dir($d)) throw new Exception("La descarga ya no existe; vuelve a intentarlo.");
    $meta = json_decode((string)@file_get_contents($d . 'meta.json'), true) ?: [];
    if ((int)($meta['usuario'] ?? 0) !== (int)USUARIO_ID || (int)($meta['cid'] ?? 0) !== (int)cliente_actual()) throw new Exception("Descarga no válida.");
    return $d;
}

try {
    if (!in_array(USUARIO_ROL, ['admin', 'superadmin', 'nomina'], true)) throw new Exception("No autorizado.");
    $cid = (int)cliente_actual();
    @set_time_limit(120);

    if (isset($_GET['descargar'])) {
        $d = loteDir((string)$_GET['descargar']);
        $pdfs = glob($d . '*.pdf') ?: [];
        if (!$pdfs) throw new Exception("No hay bouchers generados.");
        $zipRuta = $d . 'bouchers.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipRuta, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new Exception("No se pudo crear el ZIP.");
        foreach ($pdfs as $p) $zip->addFile($p, basename($p));
        $zip->close();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="bouchers_' . date('Y-m-d_His') . '.zip"');
        header('Content-Length: ' . filesize($zipRuta));
        readfile($zipRuta);
        array_map('unlink', glob($d . '*') ?: []);
        @rmdir($d);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");

    if (($_POST['accion'] ?? '') === 'iniciar') {
        // Limpieza de descargas viejas (más de 2 horas)
        foreach (glob(sys_get_temp_dir() . '/bouchers_lote/*', GLOB_ONLYDIR) ?: [] as $viejo)
            if (filemtime($viejo) < time() - 7200) { array_map('unlink', glob($viejo . '/*') ?: []); @rmdir($viejo); }
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_POST['ids'] ?? ''))))));
        if (!$ids) throw new Exception("Selecciona al menos un pago.");
        if (count($ids) > BOUCHER_MAX) throw new Exception("Máximo " . BOUCHER_MAX . " bouchers por descarga.");
        $st = $pdo->prepare("SELECT id, descripcion FROM gastos WHERE cliente_id = ? AND estado = 'pagado' AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY fecha, id");
        $st->execute([$cid, ...$ids]);
        $validos = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $g) if (USUARIO_ROL !== 'nomina' || esGastoNomina((string)$g['descripcion'])) $validos[] = (int)$g['id'];
        if (!$validos) throw new Exception("Ninguno de los pagos seleccionados tiene boucher.");
        $token = bin2hex(random_bytes(16));
        $d = sys_get_temp_dir() . '/bouchers_lote/' . $token . '/';
        if (!mkdir($d, 0700, true)) throw new Exception("No se pudo preparar la descarga.");
        file_put_contents($d . 'meta.json', json_encode(['usuario' => (int)USUARIO_ID, 'cid' => $cid, 'ids' => $validos, 'hechos' => 0]));
        echo json_encode(['success' => true, 'token' => $token, 'total' => count($validos)]);
        exit;
    }

    if (($_POST['accion'] ?? '') === 'paso') {
        $d = loteDir((string)($_POST['token'] ?? ''));
        $meta = json_decode(file_get_contents($d . 'meta.json'), true);
        $siguientes = array_slice($meta['ids'], $meta['hechos'], LOTE_POR_PASO);
        if ($siguientes) {
            $ctx = boucherContexto($pdo, $cid, __DIR__ . '/includes/uploads');
            $st = $pdo->prepare("SELECT g.*, cg.nombre AS categoria FROM gastos g LEFT JOIN categorias_gastos cg ON cg.id = g.categoria_id WHERE g.id = ? AND g.cliente_id = ?");
            foreach ($siguientes as $id) {
                $st->execute([$id, $cid]);
                if (!($g = $st->fetch(PDO::FETCH_ASSOC))) continue;
                $dat = boucherDatos($ctx, $g);
                file_put_contents($d . boucherArchivo($dat), boucherPdf([boucherPagina($ctx, $dat)]));
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
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
