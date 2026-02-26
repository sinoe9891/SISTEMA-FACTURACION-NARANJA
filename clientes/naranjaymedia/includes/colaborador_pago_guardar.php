<?php
// clientes/naranjaymedia/includes/colaborador_pago_guardar.php
// Soporta: cuotas (descuentos) + bonos + viáticos
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
header('Content-Type: application/json; charset=utf-8');

define('IHSS_EMPLEADO',  0.035); define('IHSS_PATRONAL', 0.07);
define('RAP_EMPLEADO',   0.015); define('RAP_PATRONAL',  0.015);
define('IHSS_TOPE_MES',  10294.10);
define('UPLOAD_DIR_BASE', __DIR__ . '/uploads/comprobantes_nomina/');
$destino = null;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    $cid = (int)(USUARIO_ROL === 'superadmin' ? ($_SESSION['cliente_seleccionado'] ?? 0) : CLIENTE_ID);
    if (!$cid) throw new Exception("Cliente no identificado.");

    $colab_id = filter_input(INPUT_POST, 'colaborador_id', FILTER_VALIDATE_INT);
    $fecha    = trim($_POST['fecha']       ?? date('Y-m-d'));
    $metodo   = trim($_POST['metodo_pago'] ?? 'transferencia');
    $quincena = (int)($_POST['quincena']   ?? 0);
    $notas    = trim($_POST['notas']       ?? '') ?: null;
    if (!$colab_id) throw new Exception("Colaborador no identificado.");

    $sv = $pdo->prepare("SELECT * FROM colaboradores WHERE id=? AND cliente_id=? AND activo=1");
    $sv->execute([$colab_id, $cid]);
    $c = $sv->fetch(PDO::FETCH_ASSOC);
    if (!$c) throw new Exception("Colaborador no encontrado o inactivo.");

    $salario = (float)$c['salario_base'];
    $tipo    = in_array($c['tipo_pago'], ['quincenal','mensual']) ? $c['tipo_pago'] : 'mensual';
    if ($tipo === 'quincenal' && !in_array($quincena, [1,2], true))
        throw new Exception("Para pago quincenal debes indicar quincena 1 o 2.");

    $base_ihss    = min($salario, IHSS_TOPE_MES);
    $ihss_emp     = !empty($c['aplica_ihss']) ? round($base_ihss * IHSS_EMPLEADO, 2) : 0;
    $rap_emp      = !empty($c['aplica_rap'])  ? round($salario   * RAP_EMPLEADO,  2) : 0;
    $neto_mensual = $salario - $ihss_emp - $rap_emp;
    $div          = ($tipo === 'quincenal') ? 2 : 1;
    $neto_pago    = round($neto_mensual / $div, 2);

    // ── Cuotas ────────────────────────────────────────────────────────────────
    $cuotas_ids_post = array_filter(array_map('intval', (array)($_POST['cuotas_ids'] ?? [])));
    $cuotas_auto = []; $total_deduccion = 0;
    if (!empty($cuotas_ids_post)) {
        $ph2 = implode(',', array_fill(0, count($cuotas_ids_post), '?'));
        $st  = $pdo->prepare("
            SELECT c.id AS cuota_id, c.monto AS cuota_monto, c.numero_cuota,
                   p.id AS prestamo_id, p.descripcion AS prest_desc
            FROM colaborador_prestamo_cuotas c
            JOIN colaborador_prestamos p ON p.id = c.prestamo_id
            WHERE c.id IN ($ph2) AND p.colaborador_id=? AND p.cliente_id=?
              AND p.estado='activo' AND p.descuento_auto=1 AND c.estado='pendiente'
        ");
        $st->execute(array_merge(array_values($cuotas_ids_post), [$colab_id, $cid]));
        $cuotas_auto     = $st->fetchAll(PDO::FETCH_ASSOC);
        $total_deduccion = array_sum(array_column($cuotas_auto, 'cuota_monto'));
        if ($total_deduccion > $neto_pago)
            throw new Exception("Descuentos (L".number_format($total_deduccion,2).") superan el neto (L".number_format($neto_pago,2).").");
    }

    // ── Bonos ─────────────────────────────────────────────────────────────────
    $bonos_ids_post = array_filter(array_map('intval', (array)($_POST['bonos_ids'] ?? [])));
    $bonos_aplicar = []; $total_bonos = 0;
    if (!empty($bonos_ids_post)) {
        $phB = implode(',', array_fill(0, count($bonos_ids_post), '?'));
        $stB = $pdo->prepare("SELECT id,monto_total,descripcion FROM colaborador_prestamos
            WHERE id IN ($phB) AND colaborador_id=? AND cliente_id=? AND tipo='bono' AND estado='activo'");
        $stB->execute([...$bonos_ids_post, $colab_id, $cid]);
        $bonos_aplicar = $stB->fetchAll(PDO::FETCH_ASSOC);
        $total_bonos   = array_sum(array_column($bonos_aplicar, 'monto_total'));
    }

    // ── Viáticos ──────────────────────────────────────────────────────────────
    $viaticos_ids_post = array_filter(array_map('intval', (array)($_POST['viaticos_ids'] ?? [])));
    $viaticos_aplicar = []; $total_viaticos = 0;
    if (!empty($viaticos_ids_post)) {
        $phV = implode(',', array_fill(0, count($viaticos_ids_post), '?'));
        $stV = $pdo->prepare("SELECT id,monto_total,descripcion FROM colaborador_prestamos
            WHERE id IN ($phV) AND colaborador_id=? AND cliente_id=? AND tipo='viatico' AND estado='activo'");
        $stV->execute([...$viaticos_ids_post, $colab_id, $cid]);
        $viaticos_aplicar = $stV->fetchAll(PDO::FETCH_ASSOC);
        $total_viaticos   = array_sum(array_column($viaticos_aplicar, 'monto_total'));
    }

    $monto_final = max(0, round($neto_pago - $total_deduccion + $total_bonos + $total_viaticos, 2));

    $qLabel = '';
    if ($tipo === 'quincenal' && $quincena === 1) $qLabel = ' — 1ª Quincena';
    elseif ($tipo === 'quincenal' && $quincena === 2) $qLabel = ' — 2ª Quincena';
    $nombreCompleto = trim(($c['nombre']??'').' '.($c['apellido']??''));
    $descripcion    = 'Sueldo ' . $nombreCompleto . $qLabel;

    $cat_id = $c['categoria_gasto_id'] ?? null;
    if (!$cat_id) {
        $svCat = $pdo->prepare("SELECT id FROM categorias_gastos WHERE cliente_id=? AND activa=1 AND LOWER(nombre) LIKE '%nomina%' LIMIT 1");
        $svCat->execute([$cid]);
        $cat_id = $svCat->fetchColumn() ?: null;
    }

    // ── Comprobante ───────────────────────────────────────────────────────────
    $archivo_adjunto = null; $archivo_nombre = null;
    $fileKey = isset($_FILES['comprobante']) ? 'comprobante' : (isset($_FILES['archivo_adjunto']) ? 'archivo_adjunto' : null);
    if ($fileKey && isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES[$fileKey]; $tmpPath = $file['tmp_name']; $size = (int)$file['size'];
        if (!is_file($tmpPath)) throw new Exception("Archivo temporal inválido.");
        if ($size > 5*1024*1024) throw new Exception("El archivo supera 5 MB.");
        $mime = function_exists('mime_content_type') ? mime_content_type($tmpPath) : null;
        $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf'];
        if (!$mime || !isset($allowed[$mime])) throw new Exception("Tipo no permitido. Solo JPG, PNG, WEBP, PDF.");
        $ext = $allowed[$mime];
        $subCarpeta = date('Y').'/'.date('m').'/';
        $uploadDir  = UPLOAD_DIR_BASE . $subCarpeta;
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) throw new Exception("No se pudo crear directorio.");
        $filename = 'pago_'.$colab_id.'_'.date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.'.$ext;
        $destino  = $uploadDir . $filename;
        $archivo_nombre  = $filename;
        $archivo_adjunto = $subCarpeta . $filename;
        if (!move_uploaded_file($tmpPath, $destino)) throw new Exception("No se pudo mover el archivo.");
    }

    // ── Transacción ───────────────────────────────────────────────────────────
    $pdo->beginTransaction();

    $stmtIns = $pdo->prepare("
        INSERT INTO gastos (cliente_id,categoria_id,descripcion,monto,fecha,
             frecuencia,dia_pago,dia_pago_2,gasto_grupo_id,quincena_num,fecha_vencimiento,
             tipo,metodo_pago,proveedor,notas,archivo_adjunto,archivo_nombre,estado,usuario_id)
        VALUES (?,?,?,?,?, ?,?,?,NULL,?,NULL, ?,?,?,?, ?,?, ?,?)
    ");
    $stmtIns->execute([
        $cid, $cat_id, $descripcion, $monto_final, $fecha,
        $tipo,
        !empty($c['dia_pago'])   ? (int)$c['dia_pago']   : null,
        !empty($c['dia_pago_2']) ? (int)$c['dia_pago_2'] : null,
        ($tipo === 'quincenal') ? $quincena : null,
        'fijo', $metodo, null, $notas,
        $archivo_adjunto, $archivo_nombre, 'pagado', USUARIO_ID
    ]);
    $gasto_id = (int)$pdo->lastInsertId();

    foreach ($cuotas_auto as $cuota) {
        $pdo->prepare("UPDATE colaborador_prestamo_cuotas SET estado='pagado',fecha_pago=?,metodo_pago='descuento_nomina',
            notas=CONCAT(IFNULL(notas,''),' | Descontado en nómina gasto #$gasto_id') WHERE id=?")->execute([$fecha, $cuota['cuota_id']]);
        $pdo->prepare("UPDATE colaborador_prestamos SET saldo_pendiente=GREATEST(0,saldo_pendiente-?) WHERE id=?")->execute([$cuota['cuota_monto'], $cuota['prestamo_id']]);
        $pdo->prepare("UPDATE colaborador_prestamos SET estado=CASE WHEN saldo_pendiente<=0 THEN 'pagado' ELSE estado END WHERE id=?")->execute([$cuota['prestamo_id']]);
    }

    $insertExtra = $pdo->prepare("
        INSERT INTO gastos (cliente_id,categoria_id,descripcion,monto,fecha,
             frecuencia,dia_pago,dia_pago_2,gasto_grupo_id,quincena_num,fecha_vencimiento,
             tipo,metodo_pago,proveedor,notas,archivo_adjunto,archivo_nombre,estado,usuario_id)
        VALUES (?,?,?,?,?, 'unico',NULL,NULL, NULL,NULL,NULL, 'fijo',?,NULL,?, NULL,NULL,'pagado',?)
    ");

    foreach ($bonos_aplicar as $bono) {
        $pdo->prepare("UPDATE colaborador_prestamos SET estado='pagado',
            notas=CONCAT(IFNULL(notas,''),' | Aplicado en nómina gasto #$gasto_id el $fecha')
            WHERE id=? AND colaborador_id=?")->execute([$bono['id'], $colab_id]);
        $insertExtra->execute([$cid, $cat_id, 'Bono: '.$bono['descripcion'].' — '.$nombreCompleto,
            (float)$bono['monto_total'], $fecha, $metodo, 'Aplicado junto con nómina gasto #'.$gasto_id, USUARIO_ID]);
    }

    foreach ($viaticos_aplicar as $viat) {
        $pdo->prepare("UPDATE colaborador_prestamos SET estado='pagado',
            notas=CONCAT(IFNULL(notas,''),' | Aplicado en nómina gasto #$gasto_id el $fecha')
            WHERE id=? AND colaborador_id=?")->execute([$viat['id'], $colab_id]);
        $insertExtra->execute([$cid, $cat_id, 'Viático: '.$viat['descripcion'].' — '.$nombreCompleto,
            (float)$viat['monto_total'], $fecha, $metodo, 'Aplicado junto con nómina gasto #'.$gasto_id, USUARIO_ID]);
    }

    $pdo->commit();

    $ihss_pat = !empty($c['aplica_ihss']) ? round($base_ihss * IHSS_PATRONAL / $div, 2) : 0;
    $rap_pat  = !empty($c['aplica_rap'])  ? round($salario   * RAP_PATRONAL  / $div, 2) : 0;

    $msg = "Pago registrado: $descripcion — L ".number_format($monto_final, 2);
    if ($total_deduccion > 0) $msg .= " (desc. L ".number_format($total_deduccion,2).")";
    if ($total_bonos > 0)     $msg .= " (bono L ".number_format($total_bonos,2).")";
    if ($total_viaticos > 0)  $msg .= " (viáticos L ".number_format($total_viaticos,2).")";

    echo json_encode([
        'success'            => true,
        'gasto_id'           => $gasto_id,
        'message'            => $msg,
        'cuotas_pagadas'     => count($cuotas_auto),
        'bonos_aplicados'    => count($bonos_aplicar),
        'viaticos_aplicados' => count($viaticos_aplicar),
        'recibo_url'         => 'colaborador_recibo_pdf.php?gasto_id='.$gasto_id.'&vista=1',
        'desglose' => [
            'neto_sin_descuento' => $neto_pago,
            'descuento_prestamo' => $total_deduccion,
            'bonos_aplicados'    => $total_bonos,
            'viaticos_aplicados' => $total_viaticos,
            'neto_pagado'        => $monto_final,
            'ihss_patronal'      => $ihss_pat,
            'rap_patronal'       => $rap_pat,
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if ($destino && file_exists($destino)) @unlink($destino);
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
