<?php
// clientes/naranjaymedia/includes/colaborador_pago_guardar.php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';

header('Content-Type: application/json; charset=utf-8');

define('IHSS_EMPLEADO',  0.035);
define('IHSS_PATRONAL',  0.07);
define('RAP_EMPLEADO',   0.015);
define('RAP_PATRONAL',   0.015);
define('IHSS_TOPE_MES',  10294.10);

// Carpeta de subida — ajusta la ruta raíz según tu servidor
define('UPLOAD_DIR_BASE', __DIR__ . '/uploads/comprobantes_nomina/');
define('UPLOAD_URL_BASE', 'includes/uploads/comprobantes_nomina/');  // ya no se usa para guardar

$destino = null;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Método no permitido.");
    }

    $cid = (int)(USUARIO_ROL === 'superadmin'
        ? ($_SESSION['cliente_seleccionado'] ?? 0)
        : CLIENTE_ID);

    if (!$cid) {
        throw new Exception("Cliente no identificado.");
    }

    $colab_id = filter_input(INPUT_POST, 'colaborador_id', FILTER_VALIDATE_INT);
    $fecha    = trim($_POST['fecha']       ?? date('Y-m-d'));
    $metodo   = trim($_POST['metodo_pago'] ?? 'transferencia');
    $quincena = (int)($_POST['quincena']   ?? 0);
    $notas    = trim($_POST['notas']       ?? '');
    $notas    = ($notas !== '') ? $notas : null;

    if (!$colab_id) {
        throw new Exception("Colaborador no identificado.");
    }

    // Cargar colaborador
    $sv = $pdo->prepare("SELECT * FROM colaboradores WHERE id=? AND cliente_id=? AND activo=1");
    $sv->execute([$colab_id, $cid]);
    $c = $sv->fetch(PDO::FETCH_ASSOC);

    if (!$c) {
        throw new Exception("Colaborador no encontrado o inactivo.");
    }

    $salario = (float)$c['salario_base'];
    $tipo    = $c['tipo_pago']; // 'quincenal' o 'mensual'

    if (!in_array($tipo, ['quincenal', 'mensual'], true)) {
        $tipo = 'mensual';
    }

    if ($tipo === 'quincenal' && !in_array($quincena, [1, 2], true)) {
        throw new Exception("Para pago quincenal debes indicar quincena 1 o 2.");
    }

    // ── Calcular deducciones ───────────────────────────────────────────────────
    $base_ihss    = min($salario, IHSS_TOPE_MES);
    $ihss_emp     = !empty($c['aplica_ihss']) ? round($base_ihss * IHSS_EMPLEADO, 2) : 0;
    $rap_emp      = !empty($c['aplica_rap'])  ? round($salario   * RAP_EMPLEADO,  2) : 0;

    $neto_mensual = $salario - $ihss_emp - $rap_emp;
    $monto_pago   = ($tipo === 'quincenal') ? round($neto_mensual / 2, 2) : $neto_mensual;

    // ── Descripción (PHP 7/8 compatible) ───────────────────────────────────────
    $qLabel = '';
    if ($tipo === 'quincenal' && $quincena === 1) {
        $qLabel = ' — 1ª Quincena';
    } elseif ($tipo === 'quincenal' && $quincena === 2) {
        $qLabel = ' — 2ª Quincena';
    }

    $nombreCompleto = trim(($c['nombre'] ?? '') . ' ' . ($c['apellido'] ?? ''));
    $descripcion = 'Sueldo ' . $nombreCompleto . $qLabel;

    // ── Categoría ──────────────────────────────────────────────────────────────
    $cat_id = $c['categoria_gasto_id'] ?? null;
    if (!$cat_id) {
        $svCat = $pdo->prepare("
            SELECT id
            FROM categorias_gastos
            WHERE cliente_id=? AND activa=1 AND LOWER(nombre) LIKE '%nomina%'
            LIMIT 1
        ");
        $svCat->execute([$cid]);
        $cat_id = $svCat->fetchColumn() ?: null;
    }

    // ── Procesar comprobante (opcional) ────────────────────────────────────────
    $archivo_adjunto = null; // URL
    $archivo_nombre  = null; // nombre guardado

    // Aceptar input name 'comprobante' o 'archivo_adjunto'
    $fileKey = null;
    if (isset($_FILES['comprobante'])) {
        $fileKey = 'comprobante';
    } elseif (isset($_FILES['archivo_adjunto'])) {
        $fileKey = 'archivo_adjunto';
    }

    if ($fileKey && isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
        $file    = $_FILES[$fileKey];
        $tmpPath = $file['tmp_name'];
        $size    = (int)$file['size'];
        $maxSize = 5 * 1024 * 1024; // 5 MB

        if (!is_file($tmpPath)) {
            throw new Exception("Archivo temporal inválido.");
        }

        $mime = function_exists('mime_content_type') ? mime_content_type($tmpPath) : null;

        $allowedMimes = [
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'image/webp'      => 'webp',
            'application/pdf' => 'pdf',
        ];

        if (!$mime || !isset($allowedMimes[$mime])) {
            throw new Exception("Tipo de archivo no permitido. Solo JPG, PNG, WEBP o PDF.");
        }
        if ($size > $maxSize) {
            throw new Exception("El archivo supera el límite de 5 MB.");
        }

        $ext       = $allowedMimes[$mime];
        $subCarpeta = date('Y') . '/' . date('m') . '/';   // ej: 2026/02/
        $uploadDir  = UPLOAD_DIR_BASE . $subCarpeta;
        echo "uploadDir: $uploadDir<br>";
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            throw new Exception("No se pudo crear el directorio de comprobantes.");
        }

        $filename = 'pago_' . $colab_id . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $destino  = $uploadDir . $filename;

        $archivo_nombre  = $filename;
        $archivo_adjunto = $subCarpeta . $filename;   // ← guarda "2026/02/pago_1_xxx.jpg"

    } elseif ($fileKey && isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] !== UPLOAD_ERR_NO_FILE) {
        $phpErrors = [
            UPLOAD_ERR_INI_SIZE   => 'El archivo supera upload_max_filesize en php.ini.',
            UPLOAD_ERR_FORM_SIZE  => 'El archivo supera MAX_FILE_SIZE del formulario.',
            UPLOAD_ERR_PARTIAL    => 'El archivo se subió de forma incompleta.',
            UPLOAD_ERR_NO_TMP_DIR => 'No se encontró carpeta temporal.',
            UPLOAD_ERR_CANT_WRITE => 'Error al escribir en disco.',
            UPLOAD_ERR_EXTENSION  => 'Extensión de PHP bloqueó la subida.',
        ];
        $code = $_FILES[$fileKey]['error'];
        throw new Exception($phpErrors[$code] ?? "Error de subida desconocido (código $code).");
    }

    // ── Insertar gasto ─────────────────────────────────────────────────────────
    $pdo->beginTransaction();

    $stmtIns = $pdo->prepare("
        INSERT INTO gastos
            (cliente_id, categoria_id, descripcion, monto, fecha,
             frecuencia, dia_pago, dia_pago_2,
             gasto_grupo_id, quincena_num, fecha_vencimiento,
             tipo, metodo_pago, proveedor, notas,
             archivo_adjunto, archivo_nombre,
             estado, usuario_id)
        VALUES
            (?,?,?,?,?,
             ?,?,?, NULL,?, NULL,
             ?,?,?,?,
             ?,?,
             ?,?)
    ");

    $stmtIns->execute([
        $cid,
        $cat_id,
        $descripcion,
        $monto_pago,
        $fecha,
        $tipo,
        !empty($c['dia_pago']) ? (int)$c['dia_pago'] : null,
        !empty($c['dia_pago_2']) ? (int)$c['dia_pago_2'] : null,
        ($tipo === 'quincenal') ? $quincena : null,
        'fijo',
        $metodo,
        null,
        $notas,
        $archivo_adjunto,
        $archivo_nombre,
        'pagado',
        USUARIO_ID
    ]);

    $gasto_id = (int)$pdo->lastInsertId();
    $pdo->commit();

    // Calcular patronal para la respuesta
    $div      = ($tipo === 'quincenal') ? 2 : 1;
    $ihss_pat = !empty($c['aplica_ihss']) ? round(min($salario, IHSS_TOPE_MES) * IHSS_PATRONAL / $div, 2) : 0;
    $rap_pat  = !empty($c['aplica_rap'])  ? round($salario * RAP_PATRONAL / $div, 2) : 0;

    echo json_encode([
        'success'        => true,
        'gasto_id'       => $gasto_id,
        'archivo_url'    => $archivo_adjunto,
        'archivo_nombre' => $archivo_nombre,
        'message'        => "Pago registrado: $descripcion — L " . number_format($monto_pago, 2),
        'desglose'       => [
            'salario_bruto'  => round($salario  / $div, 2),
            'ihss_empleado'  => round($ihss_emp / $div, 2),
            'rap_empleado'   => round($rap_emp  / $div, 2),
            'neto'           => $monto_pago,
            'ihss_patronal'  => $ihss_pat,
            'rap_patronal'   => $rap_pat,
            'costo_total'    => round($monto_pago + $ihss_pat + $rap_pat, 2),
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($destino && file_exists($destino)) {
        @unlink($destino);
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
