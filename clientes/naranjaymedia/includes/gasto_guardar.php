<?php
// clientes/naranjaymedia/includes/gasto_guardar.php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Metodo no permitido.");
    $cid = (int)(USUARIO_ROL === 'superadmin' ? ($_SESSION['cliente_seleccionado'] ?? 0) : CLIENTE_ID);
    if (!$cid) throw new Exception("Cliente no identificado.");

    $descripcion  = trim($_POST['descripcion']  ?? '');
    $monto        = (float)($_POST['monto']      ?? 0);
    $fecha        = trim($_POST['fecha']         ?? '');
    $frecuencia   = trim($_POST['frecuencia']    ?? 'unico');
    $dia_pago     = filter_input(INPUT_POST, 'dia_pago',   FILTER_VALIDATE_INT) ?: null;
    $dia_pago_2   = filter_input(INPUT_POST, 'dia_pago_2', FILTER_VALIDATE_INT) ?: null;
    $tipo         = trim($_POST['tipo']          ?? 'variable');
    $metodo_pago  = trim($_POST['metodo_pago']   ?? 'efectivo');
    $categoria_id = filter_input(INPUT_POST, 'categoria_id', FILTER_VALIDATE_INT) ?: null;
    $proveedor    = trim($_POST['proveedor']     ?? '') ?: null;
    $factura_ref  = trim($_POST['factura_ref']   ?? '') ?: null;
    $notas        = trim($_POST['notas']         ?? '') ?: null;
    $estado_ini   = trim($_POST['estado']        ?? 'pendiente');
    $fecha_venc   = trim($_POST['fecha_vencimiento'] ?? '') ?: null;
    if ($fecha_venc && !DateTime::createFromFormat('Y-m-d', $fecha_venc)) $fecha_venc = null;

    // ── Archivo adjunto ───────────────────────────────────────────────────────
    $archivo_adjunto = null;
    $archivo_nombre  = null;
    if (!empty($_FILES['archivo_adjunto']['name'])) {
        $file       = $_FILES['archivo_adjunto'];
        $ext        = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $permitidos = ['jpg','jpeg','png','webp','pdf'];
        if (!in_array($ext, $permitidos))  throw new Exception("Archivo no permitido. Solo JPG, PNG, WEBP o PDF.");
        if ($file['size'] > 5*1024*1024)   throw new Exception("El archivo supera el limite de 5 MB.");
        if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception("Error al subir el archivo.");
        $uploadDir = __DIR__ . '/uploads/gastos/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $archivo_adjunto = 'gasto_'.$cid.'_'.time().'_'.bin2hex(random_bytes(4)).'.'.$ext;
        $archivo_nombre  = basename($file['name']);
        if (!move_uploaded_file($file['tmp_name'], $uploadDir.$archivo_adjunto))
            throw new Exception("No se pudo guardar el archivo.");
    }

    // ── Validaciones ──────────────────────────────────────────────────────────
    if (!$descripcion) throw new Exception("La descripcion es obligatoria.");
    if ($monto <= 0)   throw new Exception("El monto debe ser mayor a 0.");
    if (!$fecha)       throw new Exception("La fecha es obligatoria.");
    if (!in_array($frecuencia, ['unico','mensual','quincenal'])) throw new Exception("Frecuencia invalida.");
    if (!in_array($tipo, ['fijo','variable','extraordinario'])) throw new Exception("Tipo invalido.");
    if (!in_array($metodo_pago, ['efectivo','transferencia','cheque','tarjeta','otro'])) throw new Exception("Metodo invalido.");
    if (!in_array($estado_ini, ['pendiente','pagado','anulado'])) throw new Exception("Estado invalido.");

    if ($frecuencia === 'mensual') {
        if (!$dia_pago || $dia_pago < 1 || $dia_pago > 31) throw new Exception("Ingresa el dia del mes (1-31).");
        $dia_pago_2 = null;
    } elseif ($frecuencia === 'quincenal') {
        if (!$dia_pago  || $dia_pago  < 1 || $dia_pago  > 31) throw new Exception("Ingresa el 1er dia de pago.");
        if (!$dia_pago_2|| $dia_pago_2 < 1 || $dia_pago_2 > 31) throw new Exception("Ingresa el 2o dia de pago.");
        if ($dia_pago >= $dia_pago_2) throw new Exception("El 1er dia debe ser menor al 2o.");
    } else {
        $dia_pago = $dia_pago_2 = null;
    }

    if ($categoria_id) {
        $sv = $pdo->prepare("SELECT COUNT(*) FROM categorias_gastos WHERE id=? AND cliente_id=? AND activa=1");
        $sv->execute([$categoria_id, $cid]);
        if (!$sv->fetchColumn()) throw new Exception("Categoria invalida.");
    }

    // ══════════════════════════════════════════════════════════════════════════
    // ÚNICO → un solo registro, estado tal como lo eligió el usuario
    // ══════════════════════════════════════════════════════════════════════════
    if ($frecuencia === 'unico') {
        $s = $pdo->prepare("
            INSERT INTO gastos
                (cliente_id,categoria_id,descripcion,monto,fecha,
                 frecuencia,dia_pago,dia_pago_2,
                 gasto_grupo_id,quincena_num,fecha_vencimiento,
                 archivo_adjunto,archivo_nombre,
                 tipo,metodo_pago,proveedor,factura_ref,notas,estado,usuario_id)
            VALUES (?,?,?,?,?, ?,?,?, NULL,NULL,?, ?,?, ?,?,?,?,?,?,?)
        ");
        $s->execute([
            $cid,$categoria_id,$descripcion,$monto,$fecha,
            'unico',null,null,
            $fecha_venc,$archivo_adjunto,$archivo_nombre,
            $tipo,$metodo_pago,$proveedor,$factura_ref,$notas,$estado_ini,USUARIO_ID
        ]);
        echo json_encode(['success'=>true,'id'=>$pdo->lastInsertId(),'message'=>'Gasto registrado correctamente.']);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // MENSUAL o QUINCENAL → generar UN REGISTRO POR CADA OCURRENCIA
    //
    // Lógica:
    //   - Recorre mes a mes desde el mes de inicio hasta la fecha de vencimiento
    //   - Mensual:    1 fila por mes  (en el día $dia_pago)
    //   - Quincenal:  2 filas por mes (en $dia_pago y $dia_pago_2)
    //   - Cada fila: estado = 'pendiente' (salvo que ya esté pagado en el pasado)
    //   - Si NO hay fecha_vencimiento → solo genera el mes de la fecha de inicio
    //   - Todas las filas comparten el mismo gasto_grupo_id
    //
    // Ejemplo quincenal 15/30 desde Feb-2026 hasta May-2026:
    //   Feb-15 Pendiente | Feb-30 Pendiente
    //   Mar-15 Pendiente | Mar-30 Pendiente
    //   Abr-15 Pendiente | Abr-30 Pendiente
    //   May-15 Pendiente | May-30 Pendiente   → 8 filas
    // ══════════════════════════════════════════════════════════════════════════

    $dtInicio = new DateTime($fecha);
    $dtFin    = $fecha_venc
        ? new DateTime($fecha_venc)
        : (clone $dtInicio)->modify('last day of this month');

    if ($dtFin < $dtInicio)
        throw new Exception("La fecha de vencimiento debe ser posterior a la fecha de inicio.");

    $hoy = new DateTime('today');

    // ── Construir lista de pagos ──────────────────────────────────────────────
    $pagos = [];
    $dtCursor = new DateTime($dtInicio->format('Y-m-01')); // primer día del mes de inicio
    $limite   = 120; // máx. 10 años quincenal

    while ($dtCursor <= $dtFin && count($pagos) < $limite) {
        $anio     = (int)$dtCursor->format('Y');
        $mes      = (int)$dtCursor->format('m');
        $ultimoDia = (int)(new DateTime("$anio-$mes-01"))->format('t'); // días en ese mes

        if ($frecuencia === 'mensual') {
            $dia   = min($dia_pago, $ultimoDia);
            $fecha_pago = sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
            $dtPago = new DateTime($fecha_pago);
            if ($dtPago >= $dtInicio && $dtPago <= $dtFin) {
                // Pasado → pendiente (debería haberse pagado pero no se registró)
                // Futuro → pendiente
                $pagos[] = ['fecha'=>$fecha_pago, 'quincena'=>null, 'estado'=>'pendiente'];
            }

        } elseif ($frecuencia === 'quincenal') {
            // 1ª quincena
            $d1 = min($dia_pago, $ultimoDia);
            $fp1 = sprintf('%04d-%02d-%02d', $anio, $mes, $d1);
            if (new DateTime($fp1) >= $dtInicio && new DateTime($fp1) <= $dtFin)
                $pagos[] = ['fecha'=>$fp1, 'quincena'=>1, 'estado'=>'pendiente'];

            // 2ª quincena
            $d2 = min($dia_pago_2, $ultimoDia);
            $fp2 = sprintf('%04d-%02d-%02d', $anio, $mes, $d2);
            if (new DateTime($fp2) >= $dtInicio && new DateTime($fp2) <= $dtFin)
                $pagos[] = ['fecha'=>$fp2, 'quincena'=>2, 'estado'=>'pendiente'];
        }

        $dtCursor->modify('+1 month');
    }

    if (empty($pagos))
        throw new Exception("No se generaron pagos. Verifica la fecha de inicio y vencimiento.");

    // ── Insertar en transaccion ───────────────────────────────────────────────
    $pdo->beginTransaction();

    $sql = "
        INSERT INTO gastos
            (cliente_id,categoria_id,descripcion,monto,fecha,
             frecuencia,dia_pago,dia_pago_2,
             gasto_grupo_id,quincena_num,fecha_vencimiento,
             archivo_adjunto,archivo_nombre,
             tipo,metodo_pago,proveedor,factura_ref,notas,estado,usuario_id)
        VALUES (?,?,?,?,?, ?,?,?, ?,?,?, ?,?, ?,?,?,?,?,?,?)
    ";

    $grupoId = null;
    $ids     = [];

    foreach ($pagos as $pago) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $cid, $categoria_id, $descripcion, $monto, $pago['fecha'],
            $frecuencia, $dia_pago, $dia_pago_2,
            $grupoId, $pago['quincena'], $fecha_venc,
            $archivo_adjunto, $archivo_nombre,
            $tipo, $metodo_pago, $proveedor, $factura_ref, $notas, $pago['estado'], USUARIO_ID
        ]);
        $newId = (int)$pdo->lastInsertId();
        $ids[] = $newId;
        if ($grupoId === null) $grupoId = $newId;
    }

    // Actualizar grupo_id en todos los registros
    if (count($ids) > 1) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE gastos SET gasto_grupo_id=? WHERE id IN ($ph)")
            ->execute(array_merge([$grupoId], $ids));
    }

    $pdo->commit();

    $total     = count($ids);
    $pendCount = count(array_filter($pagos, fn($p) => $p['estado']==='pendiente'));

    $msg = $frecuencia === 'quincenal'
        ? "Pago quincenal registrado: {$total} pagos generados (".ceil($total/2)." mes(es)), {$pendCount} pendiente(s)."
        : "Gasto mensual registrado: {$total} pago(s) generado(s), {$pendCount} pendiente(s).";

    echo json_encode(['success'=>true,'ids'=>$ids,'total'=>$total,'message'=>$msg]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}