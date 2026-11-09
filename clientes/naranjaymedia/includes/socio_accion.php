<?php
// clientes/naranjaymedia/includes/socio_accion.php — Aportes de socios (solo administradores).
//   POST accion=aporte: fecha, socio, monto, metodo, referencia, cuenta_id (opcional), notas, comprobante (archivo)
//   POST accion=editar_aporte: id + los de aporte (+ quitar_comprobante) → corrige el aporte y su depósito en el banco
//   POST accion=anular: id, motivo
//   POST accion=socio:  nombre (agrega un socio a la empresa)
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once '../../../includes/socios.php';
header('Content-Type: application/json; charset=utf-8');

$destino = null;
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    if (!permisoPuede($pdo, 'socios')) throw new Exception("Tu rol no tiene acceso a Socios.");
    $cid = (int)cliente_actual();
    if (!$cid) throw new Exception("Empresa no identificada.");
    if (!sociosDisponible($pdo)) throw new Exception("Falta instalar el registro de socios.");
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'socio') {
        $nombre = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($_POST['nombre'] ?? ''))), 0, 150);
        if ($nombre === '') throw new Exception("Escribe el nombre del socio.");
        $pdo->prepare("INSERT INTO socios (cliente_id, nombre) VALUES (?, ?) ON DUPLICATE KEY UPDATE activo = 1")->execute([$cid, $nombre]);
        echo json_encode(['success' => true, 'message' => "Socio agregado: $nombre"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($accion === 'anular') {
        $id = (int)($_POST['id'] ?? 0);
        $motivo = mb_substr(trim((string)($_POST['motivo'] ?? '')), 0, 200);
        if ($motivo === '') throw new Exception("Escribe el motivo.");
        $st = $pdo->prepare("SELECT * FROM socios_aportes WHERE id = ? AND cliente_id = ? AND anulado = 0");
        $st->execute([$id, $cid]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a) throw new Exception("El aporte no existe o ya está anulado.");
        $pdo->beginTransaction();
        if ($a['movimiento_id']) {
            require_once '../../../includes/bancos.php';
            bancoAnularMovimiento($pdo, $cid, (int)$a['movimiento_id']);
        }
        $pdo->prepare("UPDATE socios_aportes SET anulado = 1, notas = CONCAT(COALESCE(notas, ''), ?) WHERE id = ? AND cliente_id = ?")
            ->execute([' | Anulado el ' . date('d/m/Y') . ': ' . $motivo, $id, $cid]);
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Aporte anulado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $editarId = 0;
    if ($accion === 'editar_aporte') { $editarId = (int)($_POST['id'] ?? 0); if (!$editarId) throw new Exception("Aporte no válido."); }
    elseif ($accion !== 'aporte') throw new Exception("Acción no válida.");
    $fecha  = trim((string)($_POST['fecha'] ?? ''));
    $socio  = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($_POST['socio'] ?? ''))), 0, 150);
    $monto  = round((float)str_replace(',', '', (string)($_POST['monto'] ?? 0)), 2);
    $metodo = in_array($_POST['metodo'] ?? '', ['transferencia', 'efectivo', 'cheque', 'deposito', 'otro'], true) ? $_POST['metodo'] : 'transferencia';
    $ref    = mb_substr(trim((string)($_POST['referencia'] ?? '')), 0, 80) ?: null;
    $notas  = trim((string)($_POST['notas'] ?? '')) ?: null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha)) throw new Exception("Fecha inválida.");
    // El aporte se liga a un socio de la lista (no se crean socios al escribir un nombre); quien depositó puede ser cualquier nombre
    if ($socio === '') throw new Exception("Elige el socio al que corresponde el aporte.");
    $st = $pdo->prepare("SELECT nombre FROM socios WHERE cliente_id = ? AND activo = 1 AND LOWER(nombre) = LOWER(?) LIMIT 1");
    $st->execute([$cid, $socio]);
    $socioReal = $st->fetchColumn();
    if (!$socioReal) throw new Exception("«{$socio}» no está en la lista de socios. Elígelo de la lista o agrégalo con «Agregar socio nuevo».");
    $socio = $socioReal;
    $deposita = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($_POST['depositante'] ?? ''))), 0, 150) ?: null;
    if ($deposita !== null && mb_strtolower($deposita) === mb_strtolower($socio)) $deposita = null;
    if ($monto <= 0) throw new Exception("El monto debe ser mayor a 0.");

    // Comprobante (opcional): se guarda con los de gastos y se entrega por socio_archivo.php
    $archivo = null;
    if (isset($_FILES['comprobante']) && $_FILES['comprobante']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['comprobante']['tmp_name'];
        if ((int)$_FILES['comprobante']['size'] > 5 * 1024 * 1024) throw new Exception("El archivo supera 5 MB.");
        $mime = function_exists('mime_content_type') ? mime_content_type($tmp) : null;
        $ok = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
        if (!$mime || !isset($ok[$mime])) throw new Exception("Tipo no permitido. Solo JPG, PNG, WEBP o PDF.");
        $archivo = 'socio_aporte_' . $cid . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ok[$mime];
        $destino = __DIR__ . '/uploads/gastos/' . $archivo;
        if (!move_uploaded_file($tmp, $destino)) throw new Exception("No se pudo guardar el comprobante.");
    }

    if ($editarId) {
        // Editar: socio, quién depositó, fecha, monto, método, referencia, cuenta, notas y comprobante; el depósito del banco se mueve, se crea o se anula
        require_once '../../../includes/bancos.php';
        require_once '../../../includes/socios_prestamos.php';
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT * FROM socios_aportes WHERE id = ? AND cliente_id = ? AND anulado = 0 FOR UPDATE");
        $st->execute([$editarId, $cid]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a) throw new Exception("El aporte no existe o está anulado.");
        $cuenta = (int)($_POST['cuenta_id'] ?? 0) ?: null;
        $desc = mb_substr("Aporte de socio: $socio" . ($deposita ? " (depositó: $deposita)" : ''), 0, 255);
        $mov = spMovimiento($pdo, $cid, (int)USUARIO_ID, $a['movimiento_id'] ? (int)$a['movimiento_id'] : null, $cuenta, 'entrada', $fecha, $monto, $ref, $desc);
        $nuevoArchivo = $archivo ?: $a['archivo_adjunto'];
        if (!$archivo && !empty($_POST['quitar_comprobante'])) $nuevoArchivo = null;
        $pdo->prepare("UPDATE socios_aportes SET fecha = ?, socio = ?, depositante = ?, monto = ?, metodo = ?, referencia = ?, cuenta_id = ?, movimiento_id = ?, notas = ?, archivo_adjunto = ? WHERE id = ? AND cliente_id = ?")
            ->execute([$fecha, $socio, $deposita, $monto, $metodo, $ref, $cuenta, $mov, $notas, $nuevoArchivo, $editarId, $cid]);
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Aporte actualizado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo->beginTransaction();
    $cuenta = (int)($_POST['cuenta_id'] ?? 0) ?: null;
    $mov = null;
    if ($cuenta) {
        require_once '../../../includes/bancos.php';
        $mov = bancoDepositoCobro($pdo, $cid, $cuenta, $fecha, $monto, mb_substr("Aporte de socio: $socio" . ($deposita ? " (depositó: $deposita)" : ''), 0, 255), $ref, (int)USUARIO_ID);
    }
    $pdo->prepare("INSERT INTO socios_aportes (cliente_id, fecha, socio, depositante, monto, metodo, referencia, cuenta_id, movimiento_id, notas, archivo_adjunto, usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$cid, $fecha, $socio, $deposita, $monto, $metodo, $ref, $cuenta, $mov, $notas, $archivo, (int)USUARIO_ID]);
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Aporte registrado: ' . $socio . ($deposita ? ' (depositó: ' . $deposita . ')' : '') . ' · L ' . number_format($monto, 2)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if ($destino && is_file($destino)) @unlink($destino);
    http_response_code(400);
    if ($e instanceof PDOException) error_log('socio_accion.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e instanceof PDOException ? 'Error de base de datos.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
