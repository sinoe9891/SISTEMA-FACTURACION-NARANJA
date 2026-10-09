<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");

    if (!permisoPuede($pdo, 'configuracion_cai')) {
        throw new Exception("No tienes permisos para editar CAI.");
    }

    $cai_id              = (int)($_POST['id']                  ?? 0);
    $cai                 = strtoupper(trim($_POST['cai']        ?? ''));
    $fecha_recepcion     = trim($_POST['fecha_recepcion']       ?? '');
    $fecha_limite        = trim($_POST['fecha_limite']          ?? '');
    $establecimiento_id  = (int)($_POST['establecimiento_id']   ?? 0);
    $punto_emision_id    = (int)($_POST['punto_emision_id']     ?? 0);
    $rango_inicio        = (int)($_POST['rango_inicio']         ?? 0);
    $rango_fin           = (int)($_POST['rango_fin']            ?? 0);
    $rango_cai_inicio    = strtoupper(trim($_POST['rango_cai_inicio'] ?? ''));
    $rango_cai_fin       = strtoupper(trim($_POST['rango_cai_fin']    ?? ''));
    $numero_certificado  = trim($_POST['numero_certificado']    ?? '') ?: null;
    $imprenta_nombre     = mb_substr(trim($_POST['imprenta_nombre']   ?? ''), 0, 150) ?: null;
    $imprenta_rtn        = mb_substr(preg_replace('/[^0-9]/', '', $_POST['imprenta_rtn'] ?? ''), 0, 20) ?: null;
    $imprenta_telefono   = mb_substr(trim($_POST['imprenta_telefono'] ?? ''), 0, 60) ?: null;

    if (!$cai_id) throw new Exception("CAI no identificado.");
    if (!$cai) throw new Exception("El código CAI es obligatorio.");
    if (!$fecha_recepcion) throw new Exception("La fecha de recepción es obligatoria.");
    if (!$fecha_limite) throw new Exception("La fecha límite es obligatoria.");
    if (!$establecimiento_id) throw new Exception("Selecciona un establecimiento.");
    if (!$punto_emision_id) throw new Exception("Selecciona un punto de emisión.");
    if ($rango_inicio < 1 || $rango_fin < 1) throw new Exception("El rango de correlativo es obligatorio.");
    if ($rango_fin < $rango_inicio) throw new Exception("El rango fin no puede ser menor que el inicio.");

    // Verificar propiedad / permisos
    if (USUARIO_ROL === 'superadmin') {
        $stmtV = $pdo->prepare("SELECT * FROM cai_rangos WHERE id = ?");
        $stmtV->execute([$cai_id]);
    } else {
        $stmtV = $pdo->prepare("SELECT * FROM cai_rangos WHERE id = ? AND cliente_id = ?");
        $stmtV->execute([$cai_id, CLIENTE_ID]);
    }
    $existente = $stmtV->fetch();
    if (!$existente) throw new Exception("CAI no encontrado o no autorizado.");

    $usadas = (int)$existente['correlativo_actual'];
    if (($rango_fin - $rango_inicio + 1) < $usadas) {
        throw new Exception("Ya se emitieron $usadas facturas. El rango no puede ser menor.");
    }

    // CAI ya usado: lo que define los números ya emitidos no se puede cambiar
    // (el correlativo se calcula como rango_inicio + correlativo_actual, y las facturas
    // emitidas muestran este CAI). Sí se puede: fechas, ampliar el rango final y el certificado.
    if ($usadas > 0) {
        $bloqueados = [
            'cai'                => [$cai, $existente['cai'], 'el código CAI'],
            'establecimiento_id' => [$establecimiento_id, (int)$existente['establecimiento_id'], 'el establecimiento'],
            'punto_emision_id'   => [$punto_emision_id, (int)$existente['punto_emision_id'], 'el punto de emisión'],
            'rango_inicio'       => [$rango_inicio, (int)$existente['rango_inicio'], 'el inicio del rango'],
            'rango_cai_inicio'   => [$rango_cai_inicio, strtoupper($existente['rango_cai_inicio']), 'el rango CAI inicial'],
        ];
        foreach ($bloqueados as [$nuevo, $actual, $nombre]) {
            if ((string)$nuevo !== (string)$actual) {
                throw new Exception("Este CAI ya tiene $usadas factura(s) emitida(s): no se puede cambiar $nombre.");
            }
        }
    }

    // Formato y traslape con otros CAI del cliente
    validarRangoCai($pdo, (int)$existente['cliente_id'], $rango_inicio, $rango_fin, $rango_cai_inicio, $rango_cai_fin, $cai_id);

    // Verificar que el establecimiento/punto de emisión pertenezcan al cliente
    $stmtEst = $pdo->prepare("SELECT establecimiento_id FROM establecimientos WHERE establecimiento_id = ? AND cliente_id = ?");
    $stmtEst->execute([$establecimiento_id, $existente['cliente_id']]);
    if (!$stmtEst->fetchColumn()) throw new Exception("Establecimiento no válido.");

    $stmtPe = $pdo->prepare("SELECT id FROM puntos_emision WHERE id = ? AND establecimiento_id = ?");
    $stmtPe->execute([$punto_emision_id, $establecimiento_id]);
    if (!$stmtPe->fetchColumn()) throw new Exception("Punto de emisión no válido.");

    $pdo->prepare("
        UPDATE cai_rangos SET
            cai                 = ?,
            fecha_recepcion     = ?,
            fecha_limite        = ?,
            establecimiento_id  = ?,
            punto_emision_id    = ?,
            rango_inicio        = ?,
            rango_fin           = ?,
            rango_cai_inicio    = ?,
            rango_cai_fin       = ?,
            numero_certificado  = ?,
            imprenta_nombre     = ?,
            imprenta_rtn        = ?,
            imprenta_telefono   = ?
        WHERE id = ?
    ")->execute([
        $cai,
        $fecha_recepcion,
        $fecha_limite,
        $establecimiento_id,
        $punto_emision_id,
        $rango_inicio,
        $rango_fin,
        $rango_cai_inicio,
        $rango_cai_fin,
        $numero_certificado,
        $imprenta_nombre,
        $imprenta_rtn,
        $imprenta_telefono,
        $cai_id,
    ]);

    echo json_encode(['success' => true, 'message' => 'CAI actualizado correctamente.']);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
