<?php
// clientes/naranjaymedia/includes/gasto_guardar.php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    $cid = (int)(USUARIO_ROL === 'superadmin' ? ($_SESSION['cliente_seleccionado'] ?? 0) : CLIENTE_ID);
    if (!$cid) throw new Exception("Cliente no identificado.");

    $descripcion  = trim($_POST['descripcion']  ?? '');
    $monto        = (float)($_POST['monto']     ?? 0);
    $fecha        = trim($_POST['fecha']        ?? '');
    $frecuencia   = trim($_POST['frecuencia']   ?? 'unico');
    $tipo         = trim($_POST['tipo']         ?? 'variable');
    $metodo_pago  = trim($_POST['metodo_pago']  ?? 'efectivo');
    $estado       = trim($_POST['estado']       ?? 'pendiente');
    $categoria_id = filter_input(INPUT_POST, 'categoria_id',      FILTER_VALIDATE_INT) ?: null;
    $colaborador_id = filter_input(INPUT_POST, 'colaborador_id_asignado', FILTER_VALIDATE_INT) ?: null;
    $dia_pago     = filter_input(INPUT_POST, 'dia_pago',          FILTER_VALIDATE_INT) ?: null;
    $dia_pago_2   = filter_input(INPUT_POST, 'dia_pago_2',        FILTER_VALIDATE_INT) ?: null;
    $fecha_venc   = trim($_POST['fecha_vencimiento'] ?? '') ?: null;
    if ($fecha_venc && !DateTime::createFromFormat('Y-m-d', $fecha_venc)) $fecha_venc = null;
    $proveedor    = trim($_POST['proveedor']    ?? '') ?: null;
    $factura_ref  = trim($_POST['factura_ref']  ?? '') ?: null;
    $notas        = trim($_POST['notas']        ?? '') ?: null;
    $viatico_destino      = trim($_POST['viatico_destino']       ?? '') ?: null;
    $viatico_colaborador  = trim($_POST['viatico_colaborador']   ?? '') ?: null;
    $viatico_motivo       = trim($_POST['viatico_motivo']        ?? '') ?: null;
    $viatico_fecha_salida = trim($_POST['viatico_fecha_salida']  ?? '') ?: null;
    $viatico_fecha_regreso = trim($_POST['viatico_fecha_regreso'] ?? '') ?: null;
    $tarjeta_id  = ($metodo_pago === 'tarjeta')
        ? (filter_input(INPUT_POST, 'tarjeta_id', FILTER_VALIDATE_INT) ?: null)
        : null;

    // ── Validaciones básicas ──────────────────────────────────────────────
    if (!$descripcion) throw new Exception("La descripción es obligatoria.");
    if ($monto <= 0)   throw new Exception("El monto debe ser mayor a 0.");
    if (!$fecha || !DateTime::createFromFormat('Y-m-d', $fecha)) throw new Exception("Fecha inválida.");
    if (!in_array($tipo,       ['variable', 'fijo', 'extraordinario', 'viaticos'])) throw new Exception("Tipo inválido.");
    if (!in_array($frecuencia, ['unico', 'mensual', 'quincenal', 'anual']))          throw new Exception("Frecuencia inválida.");
    if (!in_array($estado,     ['pagado', 'pendiente']))                            throw new Exception("Estado inválido.");

    // ── Días de pago según frecuencia ────────────────────────────────────
    if ($frecuencia === 'mensual') {
        if (!$dia_pago || $dia_pago < 1 || $dia_pago > 31) throw new Exception("Ingresa el día del mes de pago.");
        $dia_pago_2 = null;
    } elseif ($frecuencia === 'quincenal') {
        if (!$dia_pago  || $dia_pago  < 1 || $dia_pago  > 31) throw new Exception("Ingresa el 1er día de pago.");
        if (!$dia_pago_2 || $dia_pago_2 < 1 || $dia_pago_2 > 31) throw new Exception("Ingresa el 2° día de pago.");
        if ($dia_pago >= $dia_pago_2) throw new Exception("El primer día debe ser menor al segundo.");
    } else {
        // unico, anual: sin días de pago recurrente
        $dia_pago = $dia_pago_2 = null;
    }

    // Si hay colaborador asignado y no hay proveedor explícito, usar nombre del colaborador
    if ($colaborador_id && !$proveedor) {
        $stmtCN = $pdo->prepare("SELECT CONCAT(nombre,' ',apellido) FROM colaboradores WHERE id=? AND cliente_id=?");
        $stmtCN->execute([$colaborador_id, $cid]);
        $proveedor = $stmtCN->fetchColumn() ?: null;
    }

    $usuario_id = defined('USUARIO_ID') ? (int)USUARIO_ID : (int)($_SESSION['usuario_id'] ?? 0);

    // ── Archivo adjunto ──────────────────────────────────────────────────────
    $arch_adj = null;
    $arch_nom = null;
    if (!empty($_FILES['archivo_adjunto']['name']) && $_FILES['archivo_adjunto']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/uploads/gastos/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
        $file = $_FILES['archivo_adjunto'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'])) throw new Exception("Tipo de archivo no permitido.");
        if ($file['size'] > 5 * 1024 * 1024) throw new Exception("El archivo supera 5 MB.");
        $arch_adj = 'gasto_' . $cid . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $arch_adj))
            throw new Exception("Error al guardar el archivo.");
        $arch_nom = basename($file['name']);
    }

    $pdo->beginTransaction();

    // ── Campos comunes para INSERT ────────────────────────────────────────
    $insertBase = function (string $desc, ?string $fechaI, int $quincena_num = 0, ?int $grupo_id = null) use (
        $pdo,
        $cid,
        $categoria_id,
        $monto,
        $frecuencia,
        $dia_pago,
        $dia_pago_2,
        $fecha_venc,
        $tipo,
        $metodo_pago,
        $proveedor,
        $factura_ref,
        $notas,
        $estado,
        $usuario_id,
        $viatico_destino,
        $viatico_colaborador,
        $viatico_motivo,
        $viatico_fecha_salida,
        $viatico_fecha_regreso,
        $arch_adj,
        $arch_nom,
        $tarjeta_id
    ) {
        $cols = "cliente_id,categoria_id,descripcion,monto,fecha,frecuencia,dia_pago,dia_pago_2,
                 gasto_grupo_id,quincena_num,fecha_vencimiento,tipo,metodo_pago,tarjeta_id,proveedor,factura_ref,notas,estado,usuario_id";
        $vals = "?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?";
        // Archivo adjunto (closure tiene acceso a $arch_adj/$arch_nom del scope externo)
        if ($arch_adj) {
            $cols .= ",archivo_adjunto,archivo_nombre";
            $vals .= ",?,?";
        }
        $params = [
            $cid,
            $categoria_id,
            $desc,
            $monto,
            $fechaI ?? date('Y-m-d'),
            $frecuencia,
            $dia_pago,
            $dia_pago_2,
            $grupo_id,
            $quincena_num ?: null,
            $fecha_venc,
            $tipo,
            $metodo_pago,
            $tarjeta_id,
            $proveedor,
            $factura_ref,
            $notas,
            $estado,
            $usuario_id
        ];
        if ($arch_adj) {
            $params[] = $arch_adj;
            $params[] = $arch_nom;
        }
        $pdo->prepare("INSERT INTO gastos ($cols) VALUES ($vals)")->execute($params);
        return (int)$pdo->lastInsertId();
    };

    $ids_creados = [];

    if ($frecuencia === 'quincenal' && $tipo === 'fijo') {
        // ── Quincenal: crear 2 registros (1ª y 2ª quincena) con grupo ────
        // Primero insertar el 1° para obtener el ID
        $id1 = $insertBase($descripcion . ' — 1ª Quincena', $fecha, 1);
        // El grupo es el ID del primer gasto
        $pdo->prepare("UPDATE gastos SET gasto_grupo_id=? WHERE id=?")->execute([$id1, $id1]);
        // Calcular fecha de 2ª quincena: mismo mes, día_pago_2
        $dt2 = new DateTime($fecha);
        $diasMes = (int)$dt2->format('t');
        $dia2real = min((int)$dia_pago_2, $diasMes);
        $fecha2 = $dt2->format('Y-m-') . str_pad($dia2real, 2, '0', STR_PAD_LEFT);
        $id2 = $insertBase($descripcion . ' — 2ª Quincena', $fecha2, 2, $id1);
        $pdo->prepare("UPDATE gastos SET gasto_grupo_id=? WHERE id=?")->execute([$id1, $id2]);
        $ids_creados = [$id1, $id2];
    } else {
        // ── Todos los demás: un único INSERT ─────────────────────────────
        $id1 = $insertBase($descripcion, $fecha);
        $ids_creados = [$id1];
    }

    $pdo->commit();

    $msg = count($ids_creados) > 1
        ? "Gasto quincenal registrado en " . count($ids_creados) . " registros."
        : "Gasto registrado correctamente.";

    echo json_encode([
        'success'     => true,
        'message'     => $msg,
        'gasto_id'    => $ids_creados[0],
        'ids_creados' => $ids_creados,
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
