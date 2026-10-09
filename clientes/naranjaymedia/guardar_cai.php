<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("Método no permitido.");
    }

    if (!permisoPuede($pdo, 'configuracion_cai')) {
        throw new Exception("No tienes permisos para crear un CAI.");
    }

    $cliente_id = (int)(USUARIO_ROL === 'superadmin'
        ? ($_SESSION['cliente_seleccionado'] ?? 0)
        : CLIENTE_ID);
    if (!$cliente_id) throw new Exception("Cliente no identificado.");

    $cai                 = strtoupper(trim($_POST['cai']        ?? ''));
    $establecimiento_id  = (int)($_POST['establecimiento_id']   ?? 0);
    $punto_emision_id    = (int)($_POST['punto_emision_id']     ?? 0);
    $fecha_recepcion     = trim($_POST['fecha_recepcion']       ?? '');
    $fecha_limite        = trim($_POST['fecha_limite']          ?? '');
    $rango_inicio        = (int)($_POST['rango_inicio']         ?? 0);
    $rango_fin           = (int)($_POST['rango_fin']            ?? 0);
    $rango_cai_inicio    = strtoupper(trim($_POST['rango_cai_inicio'] ?? ''));
    $rango_cai_fin       = strtoupper(trim($_POST['rango_cai_fin']    ?? ''));
    $numero_certificado  = trim($_POST['numero_certificado']    ?? '') ?: null;

    if (!$cai) throw new Exception("El código CAI es obligatorio.");
    if (!$establecimiento_id) throw new Exception("Selecciona un establecimiento.");
    if (!$punto_emision_id) throw new Exception("Selecciona un punto de emisión.");
    if (!$fecha_recepcion) throw new Exception("La fecha de recepción es obligatoria.");
    if (!$fecha_limite) throw new Exception("La fecha límite es obligatoria.");
    if ($fecha_limite < $fecha_recepcion) throw new Exception("La fecha límite no puede ser anterior a la fecha de recepción.");
    if ($rango_inicio < 1 || $rango_fin < 1) throw new Exception("El rango de correlativo es obligatorio.");
    if ($rango_fin < $rango_inicio) throw new Exception("El rango fin no puede ser menor que el inicio.");

    // Formato y traslape con otros CAI del cliente
    validarRangoCai($pdo, $cliente_id, $rango_inicio, $rango_fin, $rango_cai_inicio, $rango_cai_fin);

    $stmtEst = $pdo->prepare("SELECT establecimiento_id FROM establecimientos WHERE establecimiento_id = ? AND cliente_id = ?");
    $stmtEst->execute([$establecimiento_id, $cliente_id]);
    if (!$stmtEst->fetchColumn()) throw new Exception("Establecimiento no válido.");

    $stmtPe = $pdo->prepare("SELECT id FROM puntos_emision WHERE id = ? AND establecimiento_id = ?");
    $stmtPe->execute([$punto_emision_id, $establecimiento_id]);
    if (!$stmtPe->fetchColumn()) throw new Exception("Punto de emisión no válido.");

    $stmt = $pdo->prepare("
        INSERT INTO cai_rangos (
            cliente_id, establecimiento_id, punto_emision_id, cai,
            rango_inicio, rango_fin, correlativo_actual,
            fecha_recepcion, fecha_limite, fecha_creacion,
            rango_cai_inicio, rango_cai_fin, numero_certificado
        ) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, NOW(), ?, ?, ?)
    ");
    $stmt->execute([
        $cliente_id,
        $establecimiento_id,
        $punto_emision_id,
        $cai,
        $rango_inicio,
        $rango_fin,
        $fecha_recepcion,
        $fecha_limite,
        $rango_cai_inicio,
        $rango_cai_fin,
        $numero_certificado,
    ]);

    header("Location: configuracion_cai?created=1");
    exit;
} catch (Exception $e) {
    $error = urlencode($e->getMessage());
    header("Location: crear_cai?error=$error");
    exit;
}
