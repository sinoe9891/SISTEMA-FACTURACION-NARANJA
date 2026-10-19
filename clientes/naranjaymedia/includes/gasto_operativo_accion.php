<?php
// clientes/naranjaymedia/includes/gasto_operativo_accion.php — Gastos operativos (ver includes/gastos_operativos.php).
//   GET  ?ver=<id>                                  → gasto, archivos y bitácora (JSON)
//   POST accion=guardar  (id?, receptor_id, tipo_id, fecha, descripcion, moneda, monto, tasa_cambio, metodo_pago, tarjeta_id,
//                         campana, pauta_inicio, pauta_fin, proveedor, factura_ref, cobrar_cliente, notas, archivos[], archivo_tipo)
//        accion=archivos (id, archivos[], archivo_tipo) · accion=archivo_quitar (archivo_id) · accion=eliminar (id)
//        Administrador: accion=aprobar (id, registro nuevo|existente|ninguno, categoria_id, gasto_id) · accion=rechazar (id, motivo)
//                       accion=reabrir (id) · accion=tipo_guardar (id?, nombre, icono, es_pauta, categoria_id, activo) · accion=asignar (usuario_id, clientes[])
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once '../../../includes/gastos_operativos.php';
header('Content-Type: application/json; charset=utf-8');

$subidos = [];
try {
    if (!gastosOpDisponible($pdo)) throw new Exception("Falta instalar sql/migraciones/2026-10-19_gastos_operativos.sql.");
    $cid = (int)cliente_actual();
    if (!$cid) throw new Exception("Empresa no identificada.");
    $uid = (int)USUARIO_ID;
    $rol = USUARIO_ROL;
    if ($rol === 'lector' && $_SERVER['REQUEST_METHOD'] !== 'GET') throw new Exception("Tu rol solo puede consultar.");
    $permitidos = gastosOpPermitidos($pdo, $uid, $rol);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $g = gastosOpObtener($pdo, $cid, (int)($_GET['ver'] ?? 0), $permitidos);
        $g['puede_editar'] = $g['estado'] !== 'aprobado' && (gastosOpEsAdmin($rol) || (int)$g['usuario_id'] === $uid);
        echo json_encode(['success' => true, 'gasto' => $g, 'archivos' => array_map(fn($a) => [
            'id' => (int)$a['id'], 'tipo' => $a['tipo'], 'nombre' => $a['nombre'], 'subido_por' => $a['subido_por'], 'fecha' => date('d/m/Y H:i', strtotime($a['creado_en'])),
            'puede_quitar' => gastosOpEsAdmin($rol) || ($g['estado'] !== 'aprobado' && (int)$a['usuario_id'] === $uid),
        ], gastosOpArchivos($pdo, (int)$g['id'])), 'bitacora' => array_map(fn($b) => ['accion' => $b['accion'], 'detalle' => $b['detalle'], 'usuario' => $b['usuario'],
            'fecha' => date('d/m/Y H:i', strtotime($b['creado_en']))], gastosOpBitacora($pdo, (int)$g['id']))], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $accion = (string)($_POST['accion'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    // Archivos primero (si alguno no es válido, no se guarda nada)
    if (in_array($accion, ['guardar', 'archivos'], true))
        foreach (gastosOpArchivosPost($_FILES['archivos'] ?? null) as $f) if ($s = gastosOpSubir($f, $cid)) $subidos[] = $s;

    $pdo->beginTransaction();
    $msg = 'Listo.';
    $extra = [];
    switch ($accion) {
        case 'guardar':
            $nuevo = !$id;
            $id = gastosOpGuardar($pdo, $cid, $uid, $rol, $_POST, $permitidos, $id);
            foreach ($subidos as $s) gastosOpArchivoAgregar($pdo, $cid, $uid, $id, $s, (string)($_POST['archivo_tipo'] ?? 'comprobante'));
            $msg = $nuevo ? 'Gasto registrado: queda por revisar.' : 'Gasto actualizado.';
            $extra['id'] = $id;
            break;
        case 'archivos':
            gastosOpObtener($pdo, $cid, $id, $permitidos);
            if (!$subidos) throw new Exception("Elige al menos un archivo.");
            foreach ($subidos as $s) gastosOpArchivoAgregar($pdo, $cid, $uid, $id, $s, (string)($_POST['archivo_tipo'] ?? 'comprobante'));
            $msg = count($subidos) === 1 ? 'Archivo agregado.' : count($subidos) . ' archivos agregados.';
            break;
        case 'archivo_quitar':
            gastosOpArchivoQuitar($pdo, $cid, $uid, $rol, (int)($_POST['archivo_id'] ?? 0), $permitidos);
            $msg = 'Archivo quitado.';
            break;
        case 'eliminar':
            gastosOpEliminar($pdo, $cid, $uid, $rol, $id, $permitidos);
            $msg = 'Gasto eliminado.';
            break;
        case 'aprobar':
            $gid = gastosOpRevisar($pdo, $cid, $uid, $rol, $id, 'aprobado', $_POST);
            $msg = 'Gasto aprobado' . ($gid ? ' (Gastos N.º ' . $gid . ')' : '') . '.';
            $extra['gasto_id'] = $gid;
            break;
        case 'rechazar':
            gastosOpRevisar($pdo, $cid, $uid, $rol, $id, 'rechazado', $_POST);
            $msg = 'Gasto rechazado: quien lo registró verá el motivo.';
            break;
        case 'reabrir':
            gastosOpReabrir($pdo, $cid, $uid, $rol, $id);
            $msg = 'El gasto volvió a «Por revisar».';
            break;
        case 'tipo_guardar':
            if (!gastosOpEsAdmin($rol)) throw new Exception("Solo un administrador maneja los tipos.");
            $extra['id'] = gastosOpTipoGuardar($pdo, $cid, $_POST);
            $msg = 'Tipo guardado.';
            break;
        case 'asignar':
            if (!gastosOpEsAdmin($rol)) throw new Exception("Solo un administrador asigna clientes.");
            gastosOpAsignar($pdo, $cid, $uid, (int)($_POST['usuario_id'] ?? 0), (array)($_POST['cuentas'] ?? $_POST['clientes'] ?? []));
            $msg = 'Clientes asignados.';
            break;
        default:
            throw new Exception("Acción no válida.");
    }
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => $msg] + $extra, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    foreach ($subidos as $s) @unlink(gastosOpDir() . $s[0]);
    http_response_code(400);
    if ($e instanceof PDOException) error_log('gasto_operativo_accion.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e instanceof PDOException ? 'Error de base de datos.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
