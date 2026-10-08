<?php
// clientes/naranjaymedia/includes/socio_accion.php — Aportes de socios (solo administradores).
//   POST accion=aporte: fecha, socio, monto, metodo, referencia, cuenta_id (opcional), notas, comprobante (archivo)
//   POST accion=anular: id, motivo
//   POST accion=socio:  nombre (agrega un socio a la empresa)
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once '../../../includes/socios.php';
header('Content-Type: application/json; charset=utf-8');

$destino = null;
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    if (!in_array(USUARIO_ROL, ['admin', 'superadmin'], true)) throw new Exception("Solo un administrador puede registrar aportes de socios.");
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

    if ($accion !== 'aporte') throw new Exception("Acción no válida.");
    $fecha  = trim((string)($_POST['fecha'] ?? ''));
    $socio  = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($_POST['socio'] ?? ''))), 0, 150);
    $monto  = round((float)str_replace(',', '', (string)($_POST['monto'] ?? 0)), 2);
    $metodo = in_array($_POST['metodo'] ?? '', ['transferencia', 'efectivo', 'cheque', 'deposito', 'otro'], true) ? $_POST['metodo'] : 'transferencia';
    $ref    = mb_substr(trim((string)($_POST['referencia'] ?? '')), 0, 80) ?: null;
    $notas  = trim((string)($_POST['notas'] ?? '')) ?: null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha)) throw new Exception("Fecha inválida.");
    if ($socio === '') throw new Exception("Elige o escribe el socio.");
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

    $pdo->beginTransaction();
    $pdo->prepare("INSERT IGNORE INTO socios (cliente_id, nombre) VALUES (?, ?)")->execute([$cid, $socio]);
    $cuenta = (int)($_POST['cuenta_id'] ?? 0) ?: null;
    $mov = null;
    if ($cuenta) {
        require_once '../../../includes/bancos.php';
        $mov = bancoDepositoCobro($pdo, $cid, $cuenta, $fecha, $monto, "Aporte de socio: $socio", $ref, (int)USUARIO_ID);
    }
    $pdo->prepare("INSERT INTO socios_aportes (cliente_id, fecha, socio, monto, metodo, referencia, cuenta_id, movimiento_id, notas, archivo_adjunto, usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$cid, $fecha, $socio, $monto, $metodo, $ref, $cuenta, $mov, $notas, $archivo, (int)USUARIO_ID]);
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Aporte registrado: ' . $socio . ' · L ' . number_format($monto, 2)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if ($destino && is_file($destino)) @unlink($destino);
    http_response_code(400);
    if ($e instanceof PDOException) error_log('socio_accion.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e instanceof PDOException ? 'Error de base de datos.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
