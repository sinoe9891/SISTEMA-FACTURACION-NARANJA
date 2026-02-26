<?php
// clientes/naranjaymedia/includes/gasto_actualizar.php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    $cid      = (int)(USUARIO_ROL === 'superadmin' ? ($_SESSION['cliente_seleccionado'] ?? 0) : CLIENTE_ID);
    $gasto_id = filter_input(INPUT_POST, 'gasto_id', FILTER_VALIDATE_INT);
    if (!$gasto_id) throw new Exception("Gasto no identificado.");

    // Verificar pertenencia
    $svCheck = $pdo->prepare("SELECT id, gasto_grupo_id, quincena_num, frecuencia FROM gastos WHERE id=? AND cliente_id=?");
    $svCheck->execute([$gasto_id, $cid]);
    $gastoActual = $svCheck->fetch(PDO::FETCH_ASSOC);
    if (!$gastoActual) throw new Exception("Gasto no encontrado o sin permiso.");

    // ══════════════════════════════════════════════════════════════════════════
    // Actualización rápida de solo estado (botón "Marcar pagado" / modal Registrar Pago)
    // Soporta: estado, fecha_pago_real, metodo_pago, notas_pago, archivo_adjunto
    // ══════════════════════════════════════════════════════════════════════════
    if (!empty($_POST['_solo_estado'])) {
        $estado = trim($_POST['estado'] ?? 'pagado');
        if (!in_array($estado, ['pendiente', 'pagado', 'anulado'])) throw new Exception("Estado invalido.");

        // Campos opcionales del registro de pago
        $fecha_real   = trim($_POST['fecha_pago_real'] ?? '') ?: null;
        if ($fecha_real && !DateTime::createFromFormat('Y-m-d', $fecha_real)) $fecha_real = null;
        $met_pago     = trim($_POST['metodo_pago_reg'] ?? '') ?: null;
        $notas_pago   = trim($_POST['notas_pago']      ?? '') ?: null;

        // Archivo adjunto en el pago
        $arch_adj = null;
        $arch_nom = null;
        $uploadDir = __DIR__ . '/uploads/gastos/';

        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0775, true)) {
                throw new Exception("No se pudo crear carpeta de uploads: $uploadDir");
            }
        }

        if (!is_writable($uploadDir)) {
            throw new Exception("La carpeta no tiene permisos de escritura: $uploadDir");
        }

        if (!empty($_FILES['archivo_adjunto']['name'])) {
            $file = $_FILES['archivo_adjunto'];

            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Error PHP upload: " . $file['error']);
            }

            if (!is_uploaded_file($file['tmp_name'])) {
                throw new Exception("tmp_name inválido (no es upload): " . ($file['tmp_name'] ?? ''));
            }

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'])) throw new Exception("Archivo no permitido.");
            if ($file['size'] > 5 * 1024 * 1024) throw new Exception("Archivo supera 5 MB.");

            $arch_adj = 'gasto_' . $cid . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $destino = $uploadDir . $arch_adj;

            if (!move_uploaded_file($file['tmp_name'], $destino)) {
                throw new Exception("No se pudo guardar el archivo en: $destino");
            }

            $arch_nom = basename($file['name']);
        }

        // Construir SET dinámico
        $sets   = ['estado=?'];
        $params = [$estado];
        if ($fecha_real) {
            $sets[] = 'fecha=?';
            $params[] = $fecha_real;
        }
        if ($met_pago) {
            $sets[] = 'metodo_pago=?';
            $params[] = $met_pago;
        }
        if ($notas_pago) {
            $sets[] = 'notas=?';
            $params[] = $notas_pago;
        }
        if ($arch_adj) {
            $sets[] = 'archivo_adjunto=?';
            $params[] = $arch_adj;
            $sets[] = 'archivo_nombre=?';
            $params[] = $arch_nom;
        }
        $params[] = $gasto_id;
        $params[] = $cid;

        $pdo->prepare("UPDATE gastos SET " . implode(',', $sets) . " WHERE id=? AND cliente_id=?")
            ->execute($params);
        echo json_encode(['success' => true, 'message' => 'Pago registrado correctamente.']);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Actualización completa
    // ══════════════════════════════════════════════════════════════════════════
    $descripcion       = trim($_POST['descripcion']  ?? '');
    $monto             = (float)($_POST['monto']     ?? 0);
    $fecha             = trim($_POST['fecha']        ?? '');
    $frecuencia        = trim($_POST['frecuencia']   ?? 'unico');
    $dia_pago          = filter_input(INPUT_POST, 'dia_pago',   FILTER_VALIDATE_INT) ?: null;
    $dia_pago_2        = filter_input(INPUT_POST, 'dia_pago_2', FILTER_VALIDATE_INT) ?: null;
    $tipo              = trim($_POST['tipo']         ?? 'variable');
    $metodo_pago       = trim($_POST['metodo_pago']  ?? 'efectivo');
    $categoria_id      = filter_input(INPUT_POST, 'categoria_id', FILTER_VALIDATE_INT) ?: null;
    $proveedor         = trim($_POST['proveedor']    ?? '') ?: null;
    $factura_ref       = trim($_POST['factura_ref']  ?? '') ?: null;
    $notas             = trim($_POST['notas']        ?? '') ?: null;
    $estado            = trim($_POST['estado']       ?? 'pagado');
    $fecha_venc        = trim($_POST['fecha_vencimiento'] ?? '') ?: null;
    if ($fecha_venc && !DateTime::createFromFormat('Y-m-d', $fecha_venc)) $fecha_venc = null;
    $actualizar_grupo  = !empty($_POST['actualizar_grupo']);

    if (!$descripcion) throw new Exception("La descripción es obligatoria.");
    if ($monto <= 0)   throw new Exception("El monto debe ser mayor a 0.");
    if (!$fecha)       throw new Exception("La fecha es obligatoria.");
    if (!in_array($frecuencia, ['unico', 'mensual', 'quincenal'])) throw new Exception("Frecuencia inválida.");

    if ($frecuencia === 'mensual') {
        if (!$dia_pago || $dia_pago < 1 || $dia_pago > 31) throw new Exception("Ingresa el día del mes de pago.");
        $dia_pago_2 = null;
    } elseif ($frecuencia === 'quincenal') {
        if (!$dia_pago  || $dia_pago  < 1 || $dia_pago  > 31) throw new Exception("Ingresa el 1er día de pago.");
        if (!$dia_pago_2 || $dia_pago_2 < 1 || $dia_pago_2 > 31) throw new Exception("Ingresa el 2° día de pago.");
        if ($dia_pago >= $dia_pago_2) throw new Exception("El primer día debe ser menor al segundo.");
    } else {
        $dia_pago = $dia_pago_2 = null;
    }

    // ── Determinar qué registros actualizar ───────────────────────────────────
    // Si marcó "actualizar ambas quincenas" y el gasto tiene grupo → actualizar el grupo
    $grupoId = (int)($gastoActual['gasto_grupo_id'] ?? 0);
    $usarGrupo = ($actualizar_grupo && $grupoId && $frecuencia === 'quincenal');

    $pdo->beginTransaction();

    if ($usarGrupo) {
        // ── Actualizar AMBAS quincenas del grupo ──────────────────────────────
        // La 1ª quincena usa dia_pago, la 2ª usa dia_pago_2 como su día de referencia
        // pero ambas guardan ambos días (para consistencia). Solo el monto/desc/etc. cambia igual.
        $pdo->prepare("
            UPDATE gastos
            SET categoria_id=?, descripcion=?, monto=?, fecha=?,
                frecuencia=?, dia_pago=?, dia_pago_2=?, fecha_vencimiento=?,
                tipo=?, metodo_pago=?, proveedor=?, factura_ref=?, notas=?, estado=?
            WHERE gasto_grupo_id=? AND cliente_id=?
        ")->execute([
            $categoria_id,
            $descripcion,
            $monto,
            $fecha,
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
            $grupoId,
            $cid
        ]);
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Ambas quincenas del grupo actualizadas correctamente.']);
    } else {
        // ── Actualizar solo este registro ─────────────────────────────────────
        $pdo->prepare("
            UPDATE gastos
            SET categoria_id=?, descripcion=?, monto=?, fecha=?,
                frecuencia=?, dia_pago=?, dia_pago_2=?, fecha_vencimiento=?,
                tipo=?, metodo_pago=?, proveedor=?, factura_ref=?, notas=?, estado=?
            WHERE id=? AND cliente_id=?
        ")->execute([
            $categoria_id,
            $descripcion,
            $monto,
            $fecha,
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
            $gasto_id,
            $cid
        ]);
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Gasto actualizado correctamente.']);
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
