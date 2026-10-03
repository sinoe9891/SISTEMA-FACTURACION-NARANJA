<?php
// clientes/naranjaymedia/includes/gasto_actualizar.php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once __DIR__ . '/_gasto_adjuntos.php';
require_once __DIR__ . '/_gasto_recurrencia.php';
header('Content-Type: application/json; charset=utf-8');

$arch_adj = null;
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    $cid      = (int)(USUARIO_ROL === 'superadmin' ? ($_SESSION['cliente_seleccionado'] ?? 0) : CLIENTE_ID);
    $gasto_id = filter_input(INPUT_POST, 'gasto_id', FILTER_VALIDATE_INT);
    if (!$gasto_id) throw new Exception("Gasto no identificado.");

    $svCheck = $pdo->prepare("SELECT * FROM gastos WHERE id=? AND cliente_id=?");
    $svCheck->execute([$gasto_id, $cid]);
    $gastoActual = $svCheck->fetch(PDO::FETCH_ASSOC);
    if (!$gastoActual) throw new Exception("Gasto no encontrado o sin permiso.");
    $archivoAnterior = $gastoActual['archivo_adjunto'] ?? null;
    $usuario_id = (int)($_SESSION['usuario_id'] ?? 0);
    $siguiente  = null;

    /* ── Solo estado (botón Marcar pagado / modal pago) ──────────────────── */
    if (!empty($_POST['_solo_estado'])) {
        $estado = trim($_POST['estado'] ?? 'pagado');
        if (!in_array($estado, ['pendiente', 'pagado', 'anulado'])) throw new Exception("Estado inválido.");

        $fecha_real = trim($_POST['fecha_pago_real'] ?? '') ?: null;
        if ($fecha_real && !DateTime::createFromFormat('Y-m-d', $fecha_real)) $fecha_real = null;
        $met_pago   = trim($_POST['metodo_pago_reg'] ?? '') ?: null;
        if ($met_pago && !in_array($met_pago, GASTO_METODOS_PAGO)) throw new Exception("Método de pago inválido.");
        $notas_pago = trim($_POST['notas_pago']      ?? '') ?: null;

        // Comprobante (opcional)
        [$arch_adj, $arch_nom] = guardarAdjuntoGasto($_FILES['archivo_adjunto'] ?? null, $cid) ?? [null, null];

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
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE gastos SET " . implode(',', $sets) . " WHERE id=? AND cliente_id=?")->execute($params);
        // Gasto recurrente pagado: programar el del período siguiente
        if ($estado === 'pagado') $siguiente = programarSiguienteGastoRecurrente($pdo, $gastoActual, $cid, $usuario_id);
        $pdo->commit();

        // Si se reemplazó el comprobante, borrar el anterior si ya nadie lo usa
        if ($arch_adj && $archivoAnterior !== $arch_adj) borrarAdjuntoGastoSiHuerfano($pdo, $archivoAnterior);

        $msg = 'Pago registrado correctamente.';
        if ($siguiente) $msg .= ' Se programó el siguiente pago para el ' . date('d/m/Y', strtotime($siguiente)) . '.';
        echo json_encode(['success' => true, 'message' => $msg, 'siguiente' => $siguiente]);
        exit;
    }

    /* ── Actualización completa ──────────────────────────────────────────── */
    $descripcion    = trim($_POST['descripcion']  ?? '');
    $monto          = (float)($_POST['monto']     ?? 0);
    $fecha          = trim($_POST['fecha']        ?? '');
    $frecuencia     = trim($_POST['frecuencia']   ?? 'unico');
    $dia_pago       = filter_input(INPUT_POST, 'dia_pago',   FILTER_VALIDATE_INT) ?: null;
    $dia_pago_2     = filter_input(INPUT_POST, 'dia_pago_2', FILTER_VALIDATE_INT) ?: null;
    $tipo           = trim($_POST['tipo']         ?? 'variable');
    $metodo_pago    = trim($_POST['metodo_pago']  ?? 'efectivo');
    $tarjeta_id     = ($metodo_pago === 'tarjeta')
        ? (filter_input(INPUT_POST, 'tarjeta_id', FILTER_VALIDATE_INT) ?: null)
        : null;
    $categoria_id   = filter_input(INPUT_POST, 'categoria_id', FILTER_VALIDATE_INT) ?: null;
    $proveedor      = trim($_POST['proveedor']    ?? '') ?: null;
    $factura_ref    = trim($_POST['factura_ref']  ?? '') ?: null;
    $notas          = trim($_POST['notas']        ?? '') ?: null;
    $estado         = trim($_POST['estado']       ?? 'pagado');
    $fecha_venc     = trim($_POST['fecha_vencimiento'] ?? '') ?: null;
    if ($fecha_venc && !DateTime::createFromFormat('Y-m-d', $fecha_venc)) $fecha_venc = null;
    $actualizar_grupo = !empty($_POST['actualizar_grupo']);

    if (!$descripcion) throw new Exception("La descripción es obligatoria.");
    if ($monto <= 0)   throw new Exception("El monto debe ser mayor a 0.");
    if (!$fecha || !DateTime::createFromFormat('Y-m-d', $fecha)) throw new Exception("Fecha inválida.");
    if (!in_array($tipo, ['variable', 'fijo', 'extraordinario', 'viaticos'])) throw new Exception("Tipo inválido.");
    if (!in_array($estado, ['pendiente', 'pagado', 'anulado'])) throw new Exception("Estado inválido.");
    if (!in_array($metodo_pago, GASTO_METODOS_PAGO)) throw new Exception("Método de pago inválido.");

    /* ── FIX: 'anual' añadido a frecuencias válidas ──────────────────────── */
    if (!in_array($frecuencia, ['unico', 'mensual', 'quincenal', 'anual'])) {
        throw new Exception("Frecuencia inválida.");
    }

    if ($frecuencia === 'mensual') {
        if (!$dia_pago || $dia_pago < 1 || $dia_pago > 31) throw new Exception("Ingresa el día del mes de pago.");
        $dia_pago_2 = null;
    } elseif ($frecuencia === 'quincenal') {
        if (!$dia_pago  || $dia_pago  < 1 || $dia_pago  > 31) throw new Exception("Ingresa el 1er día de pago.");
        if (!$dia_pago_2 || $dia_pago_2 < 1 || $dia_pago_2 > 31) throw new Exception("Ingresa el 2° día de pago.");
        if ($dia_pago >= $dia_pago_2) throw new Exception("El primer día debe ser menor al segundo.");
    } else {
        // unico, anual: sin días
        $dia_pago = $dia_pago_2 = null;
    }

    // Categoría y tarjeta deben pertenecer al cliente (la tarjeta puede estar desactivada en gastos viejos)
    $categoria_id = categoriaGastoValida($pdo, $categoria_id, $cid);
    $tarjeta_id   = tarjetaGastoValida($pdo, $tarjeta_id, $cid, false);

    $grupoId  = (int)($gastoActual['gasto_grupo_id'] ?? 0);
    $usarGrupo = ($actualizar_grupo && $grupoId && $frecuencia === 'quincenal');

    // ── Comprobante (opcional) ────────────────────────────────────────────
    [$arch_adj, $arch_nom] = guardarAdjuntoGasto($_FILES['archivo_adjunto'] ?? null, $cid) ?? [null, null];

    $pdo->beginTransaction();

    if ($usarGrupo) {
        // 1) Datos compartidos por ambas quincenas
        $pdo->prepare("UPDATE gastos SET categoria_id=?, monto=?, frecuencia=?, dia_pago=?, dia_pago_2=?,
                fecha_vencimiento=?, tipo=?, proveedor=?
            WHERE gasto_grupo_id=? AND cliente_id=?")
            ->execute([$categoria_id, $monto, $frecuencia, $dia_pago, $dia_pago_2, $fecha_venc, $tipo, $proveedor, $grupoId, $cid]);

        // 2) Descripción: misma base, pero cada registro conserva su sufijo de quincena
        $base = preg_replace('/\s*—\s*[12]ª Quincena$/u', '', $descripcion);
        $pdo->prepare("UPDATE gastos SET descripcion = CONCAT(?, CASE quincena_num
                    WHEN 1 THEN ' — 1ª Quincena' WHEN 2 THEN ' — 2ª Quincena' ELSE '' END)
            WHERE gasto_grupo_id=? AND cliente_id=?")
            ->execute([$base, $grupoId, $cid]);

        // 3) Datos propios del pago editado: fecha, estado, forma de pago y comprobante
        //    (antes se copiaban a la otra quincena: ambas quedaban con la misma fecha,
        //    el mismo estado y el mismo comprobante)
        $sqlFila = "UPDATE gastos SET fecha=?, metodo_pago=?, tarjeta_id=?, factura_ref=?, notas=?, estado=?";
        $paramsFila = [$fecha, $metodo_pago, $tarjeta_id, $factura_ref, $notas, $estado];
        if ($arch_adj) {
            $sqlFila .= ", archivo_adjunto=?, archivo_nombre=?";
            $paramsFila[] = $arch_adj;
            $paramsFila[] = $arch_nom;
        }
        $pdo->prepare($sqlFila . " WHERE id=? AND cliente_id=?")->execute(array_merge($paramsFila, [$gasto_id, $cid]));
        // Si pasó de pendiente a pagado y es recurrente, programar el período siguiente
        // (con los datos ya editados: monto, día de pago, vencimiento, etc.)
        if ($estado === 'pagado') {
            $siguiente = programarSiguienteGastoRecurrente($pdo, array_merge($gastoActual, [
                'categoria_id' => $categoria_id, 'descripcion' => $descripcion, 'monto' => $monto,
                'frecuencia' => $frecuencia, 'dia_pago' => $dia_pago, 'dia_pago_2' => $dia_pago_2,
                'fecha_vencimiento' => $fecha_venc, 'tipo' => $tipo, 'metodo_pago' => $metodo_pago,
                'tarjeta_id' => $tarjeta_id, 'proveedor' => $proveedor,
            ]), $cid, $usuario_id);
        }
        $pdo->commit();
        $mensaje = 'Ambas quincenas del grupo actualizadas correctamente.';
    } else {
        $sqlUpd = "UPDATE gastos SET categoria_id=?,descripcion=?,monto=?,fecha=?,
            frecuencia=?,dia_pago=?,dia_pago_2=?,fecha_vencimiento=?,tipo=?,metodo_pago=?,tarjeta_id=?,
            proveedor=?,factura_ref=?,notas=?,estado=?";
        $paramsUpd = [
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
            $tarjeta_id,
            $proveedor,
            $factura_ref,
            $notas,
            $estado
        ];
        if ($arch_adj) {
            $sqlUpd .= ",archivo_adjunto=?,archivo_nombre=?";
            $paramsUpd[] = $arch_adj;
            $paramsUpd[] = $arch_nom;
        }
        $sqlUpd .= " WHERE id=? AND cliente_id=?";
        $pdo->prepare($sqlUpd)->execute(array_merge($paramsUpd, [$gasto_id, $cid]));
        // Si pasó de pendiente a pagado y es recurrente, programar el período siguiente
        // (con los datos ya editados: monto, día de pago, vencimiento, etc.)
        if ($estado === 'pagado') {
            $siguiente = programarSiguienteGastoRecurrente($pdo, array_merge($gastoActual, [
                'categoria_id' => $categoria_id, 'descripcion' => $descripcion, 'monto' => $monto,
                'frecuencia' => $frecuencia, 'dia_pago' => $dia_pago, 'dia_pago_2' => $dia_pago_2,
                'fecha_vencimiento' => $fecha_venc, 'tipo' => $tipo, 'metodo_pago' => $metodo_pago,
                'tarjeta_id' => $tarjeta_id, 'proveedor' => $proveedor,
            ]), $cid, $usuario_id);
        }
        $pdo->commit();
        $mensaje = 'Gasto actualizado correctamente.';
    }

    // Si se reemplazó el comprobante, borrar el anterior si ya nadie lo usa
    if ($arch_adj && $archivoAnterior !== $arch_adj) borrarAdjuntoGastoSiHuerfano($pdo, $archivoAnterior);

    if ($siguiente) $mensaje .= ' Se programó el siguiente pago para el ' . date('d/m/Y', strtotime($siguiente)) . '.';
    echo json_encode(['success' => true, 'message' => $mensaje, 'siguiente' => $siguiente]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    // Si no se guardó el cambio, no dejar el comprobante nuevo huérfano en disco
    if ($arch_adj && isset($pdo)) borrarAdjuntoGastoSiHuerfano($pdo, $arch_adj);
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
