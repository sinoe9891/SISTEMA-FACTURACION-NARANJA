<?php
// clientes/naranjaymedia/includes/colaborador_honorarios_guardar.php
// Pago por proyecto (honorarios) a un colaborador: sin IHSS ni RAP, sin descontar cuotas ni sumar bonos.
// Se guarda como gasto «Honorarios {Nombre} — {proyecto}» para que salga en Pagos de nómina, en la ficha y en Bouchers.
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/session.php';
header('Content-Type: application/json; charset=utf-8');

define('UPLOAD_DIR_BASE', __DIR__ . '/uploads/comprobantes_nomina/');
$destino = null;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    if (!puedeNomina()) throw new Exception("No tienes permiso para registrar pagos a colaboradores.");
    $cid = (int)cliente_actual();
    if (!$cid) throw new Exception("Cliente no identificado.");

    $colab_id = filter_input(INPUT_POST, 'colaborador_id', FILTER_VALIDATE_INT);
    $concepto = mb_substr(trim((string)($_POST['concepto'] ?? '')), 0, 200);
    $monto    = round((float)str_replace(',', '', (string)($_POST['monto'] ?? 0)), 2);
    $fecha    = trim((string)($_POST['fecha'] ?? date('Y-m-d')));
    $metodo   = in_array($_POST['metodo_pago'] ?? '', ['transferencia', 'efectivo', 'cheque', 'tarjeta', 'otro'], true) ? $_POST['metodo_pago'] : 'transferencia';
    $ref      = mb_substr(trim((string)($_POST['referencia'] ?? '')), 0, 60);
    $notas    = trim((string)($_POST['notas'] ?? ''));
    if (!$colab_id) throw new Exception("Colaborador no identificado.");
    if ($concepto === '') throw new Exception("Escribe el proyecto o concepto.");
    if ($monto <= 0) throw new Exception("El monto debe ser mayor a 0.");
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha)) throw new Exception("Fecha inválida.");

    $sv = $pdo->prepare("SELECT * FROM colaboradores WHERE id = ? AND cliente_id = ?");
    $sv->execute([$colab_id, $cid]);
    $c = $sv->fetch(PDO::FETCH_ASSOC);
    if (!$c) throw new Exception("Colaborador no encontrado.");
    $nombre = trim($c['nombre'] . ' ' . $c['apellido']);

    // Categoría: «Servicios profesionales» si existe; si no, la del colaborador
    $st = $pdo->prepare("SELECT id FROM categorias_gastos WHERE cliente_id = ? AND nombre LIKE 'Servicios profesionales%' ORDER BY activa DESC, id LIMIT 1");
    $st->execute([$cid]);
    $cat_id = (int)$st->fetchColumn() ?: ((int)$c['categoria_gasto_id'] ?: null);

    // Comprobante (opcional)
    $archivo_adjunto = $archivo_nombre = null;
    if (isset($_FILES['comprobante']) && $_FILES['comprobante']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['comprobante']['tmp_name'];
        if (!is_file($tmp)) throw new Exception("Archivo temporal inválido.");
        if ((int)$_FILES['comprobante']['size'] > 5 * 1024 * 1024) throw new Exception("El archivo supera 5 MB.");
        $mime = function_exists('mime_content_type') ? mime_content_type($tmp) : null;
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
        if (!$mime || !isset($allowed[$mime])) throw new Exception("Tipo no permitido. Solo JPG, PNG, WEBP o PDF.");
        $subDir = date('Y') . '/' . date('m') . '/';
        if (!is_dir(UPLOAD_DIR_BASE . $subDir) && !mkdir(UPLOAD_DIR_BASE . $subDir, 0755, true)) throw new Exception("No se pudo crear directorio de uploads.");
        $archivo_nombre  = 'honorarios_' . $colab_id . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
        $archivo_adjunto = $subDir . $archivo_nombre;
        $destino = UPLOAD_DIR_BASE . $archivo_adjunto;
        if (!move_uploaded_file($tmp, $destino)) throw new Exception("No se pudo mover el archivo subido.");
    }

    $descripcion = "Honorarios $nombre — $concepto";
    $notasFin = trim(($ref !== '' ? "ref. $ref. " : '') . $notas) ?: null;

    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO gastos (cliente_id, categoria_id, descripcion, monto, fecha, frecuencia, tipo, metodo_pago, proveedor, notas, archivo_adjunto, archivo_nombre, estado, usuario_id)
                   VALUES (?, ?, ?, ?, ?, 'unico', 'variable', ?, ?, ?, ?, ?, 'pagado', ?)")
        ->execute([$cid, $cat_id, $descripcion, $monto, $fecha, $metodo, $nombre, $notasFin, $archivo_adjunto, $archivo_nombre, (int)USUARIO_ID]);
    $gasto_id = (int)$pdo->lastInsertId();

    // «Sale de la cuenta»: salida en Bancos ligada al gasto
    $cuenta = filter_input(INPUT_POST, 'cuenta_id', FILTER_VALIDATE_INT) ?: null;
    if ($cuenta) {
        require_once __DIR__ . '/../../../includes/bancos.php';
        bancoSalidaPago($pdo, $cid, $cuenta, $fecha, $monto, 'Pago: ' . $descripcion, null, (int)USUARIO_ID, $gasto_id);
    }
    $pdo->commit();

    echo json_encode([
        'success' => true, 'gasto_id' => $gasto_id,
        'message' => "Honorarios registrados: $concepto — L " . number_format($monto, 2),
        'recibo_url' => 'colaborador_recibo_pdf.php?gasto_id=' . $gasto_id . '&vista=1',
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if ($destino && file_exists($destino)) @unlink($destino);
    http_response_code(400);
    if ($e instanceof PDOException) error_log('colaborador_honorarios_guardar.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e instanceof PDOException ? 'Error de base de datos.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
