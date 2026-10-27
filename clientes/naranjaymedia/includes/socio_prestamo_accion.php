<?php
// clientes/naranjaymedia/includes/socio_prestamo_accion.php — Préstamos a socios (ver includes/socios_prestamos.php).
//   GET  ?calcular=1&monto&num_cuotas&frecuencia&primera_cuota  → cuotas calculadas (vista previa)
//   POST accion=crear (socio_id, descripcion, monto, fecha, num_cuotas, frecuencia, primera_cuota, cuenta_id, referencia,
//                      avisar, aviso_dias, aviso_correo, aviso_cc, notas, comprobante)
//        accion=editar (id + los campos de crear, sin socio) · accion=editar_pago (pago_id + los de pagar)
//        accion=pagar (id, fecha, monto, metodo, referencia, cuenta_id, notas, comprobante) · accion=anular_pago (pago_id, motivo)
//        accion=condonar (id, motivo, fecha) · accion=anular (id, motivo) · accion=avisos (id, avisar, aviso_dias, aviso_correo, aviso_cc)
//        accion=enviar (id) → recordatorio ahora · accion=socio_email (socio_id, email)
//        accion=archivos (id, pago_id?, comprobantes[]) · accion=archivo_quitar (archivo_id)
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once '../../../includes/socios_prestamos.php';
header('Content-Type: application/json; charset=utf-8');

$archivo = null;
$subidos = [];
try {
    if (!permisoPuede($pdo, 'socios')) throw new Exception("Tu rol no tiene acceso a Socios.");
    if (!spDisponible($pdo)) throw new Exception("Falta instalar sql/migraciones/2026-10-26_socios_prestamos.sql.");
    $cid = (int)cliente_actual();
    if (!$cid) throw new Exception("Empresa no identificada.");
    $uid = (int)USUARIO_ID;

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['calcular'])) {
        $n = max(1, min(120, (int)($_GET['num_cuotas'] ?? 1)));
        $monto = round((float)($_GET['monto'] ?? 0), 2);
        $primera = (string)($_GET['primera_cuota'] ?? '');
        if ($monto <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $primera)) throw new Exception("Faltan el monto y la primera cuota.");
        echo json_encode(['success' => true, 'cuotas' => spCalcularCuotas($monto, $n, ($_GET['frecuencia'] ?? '') === 'mensual' ? 'mensual' : 'quincenal', $primera)]);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    if (!in_array(USUARIO_ROL, ['admin', 'superadmin'], true)) throw new Exception("Solo un administrador maneja los préstamos a socios.");
    $accion = (string)($_POST['accion'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    // Comprobantes: uno o varios (comprobantes[]; también el campo viejo «comprobante»)
    if (in_array($accion, ['crear', 'pagar', 'editar', 'editar_pago', 'archivos'], true))
        $subidos = spSubirVarios(array_merge(spArchivosPost($_FILES['comprobantes'] ?? null), spArchivosPost($_FILES['comprobante'] ?? null)), $cid,
            in_array($accion, ['crear', 'editar'], true) || ($accion === 'archivos' && empty($_POST['pago_id'])) ? 'socio_prestamo' : 'socio_prestamo_pago');
    $archivo = $subidos[0] ?? null;

    $pdo->beginTransaction();
    $extra = [];
    switch ($accion) {
        case 'crear':
            $extra['id'] = spCrear($pdo, $cid, $uid, $_POST, $archivo);
            spArchivosAgregar($pdo, $cid, $uid, $extra['id'], null, $subidos);
            $msg = 'Préstamo registrado con sus cuotas.';
            if (!empty($_POST['enviar_info'])) {   // correo de bienvenida con el cuadro de pagos (si falla, el préstamo igual queda)
                $pdo->commit();
                try { $msg .= ' Información enviada a ' . spEnviarAviso($pdo, $cid, $extra['id'], 'bienvenida', null, $uid) . '.'; }
                catch (Throwable $ex) { $msg .= ' No se pudo enviar el correo: ' . $ex->getMessage(); }
                echo json_encode(['success' => true, 'message' => $msg] + $extra, JSON_UNESCAPED_UNICODE);
                exit;
            }
            break;
        case 'enviar_info':
            $pdo->commit();
            $para = spEnviarAviso($pdo, $cid, $id, 'bienvenida', null, $uid);
            echo json_encode(['success' => true, 'message' => "Información del préstamo enviada a $para."], JSON_UNESCAPED_UNICODE);
            exit;
        case 'pagar':
            $pagoId = spPagar($pdo, $cid, $uid, $id, $_POST, $archivo);
            spArchivosAgregar($pdo, $cid, $uid, $id, $pagoId, $subidos);
            $msg = 'Pago registrado.';
            break;
        case 'archivos':   // agregar comprobantes al préstamo o a un pago
            spObtener($pdo, $cid, $id);
            if (!$subidos) throw new Exception("Elige al menos un archivo.");
            $pagoId = (int)($_POST['pago_id'] ?? 0) ?: null;
            if ($pagoId) { $st = $pdo->prepare("SELECT COUNT(*) FROM socios_prestamo_pagos WHERE id = ? AND prestamo_id = ? AND cliente_id = ?"); $st->execute([$pagoId, $id, $cid]); if (!$st->fetchColumn()) throw new Exception("Pago no encontrado."); }
            spArchivosAgregar($pdo, $cid, $uid, $id, $pagoId, $subidos);
            $msg = count($subidos) === 1 ? 'Comprobante agregado.' : count($subidos) . ' comprobantes agregados.';
            break;
        case 'archivo_quitar':
            spArchivoQuitar($pdo, $cid, (int)($_POST['archivo_id'] ?? 0));
            $msg = 'Comprobante quitado.';
            break;
        case 'editar':
            spEditar($pdo, $cid, $uid, $id, $_POST, null);
            spArchivosAgregar($pdo, $cid, $uid, $id, null, $subidos);
            $msg = 'Préstamo actualizado: las cuotas se volvieron a calcular.';
            break;
        case 'editar_pago':
            spEditarPago($pdo, $cid, $uid, (int)($_POST['pago_id'] ?? 0), $_POST, null);
            $st = $pdo->prepare("SELECT prestamo_id FROM socios_prestamo_pagos WHERE id = ?");
            $st->execute([(int)$_POST['pago_id']]);
            spArchivosAgregar($pdo, $cid, $uid, (int)$st->fetchColumn(), (int)$_POST['pago_id'], $subidos);
            $msg = 'Pago actualizado.';
            break;
        case 'anular_pago':
            spAnularPago($pdo, $cid, (int)($_POST['pago_id'] ?? 0), (string)($_POST['motivo'] ?? ''));
            $msg = 'Pago anulado.';
            break;
        case 'condonar':
            $extra['gasto_id'] = spCondonar($pdo, $cid, $uid, $id, (string)($_POST['motivo'] ?? ''), (string)($_POST['fecha'] ?? date('Y-m-d')));
            $msg = 'El saldo pasó a retiro de socio (gasto N.º ' . $extra['gasto_id'] . ').';
            break;
        case 'anular':
            spAnular($pdo, $cid, $id, (string)($_POST['motivo'] ?? ''));
            $msg = 'Préstamo anulado.';
            break;
        case 'avisos':
            spAvisos($pdo, $cid, $id, $_POST);
            $msg = 'Avisos guardados.';
            break;
        case 'enviar':
            $pdo->commit();
            $det = spDetalle($pdo, $cid, $id);
            $para = spEnviarAviso($pdo, $cid, $id, $det['vencidas'] ? 'vencida' : ($det['proxima'] ? 'por_vencer' : 'manual'),
                $det['vencidas'] ? array_values(array_filter($det['cuotas'], fn($c) => $c['estado'] === 'vencida'))[0] : $det['proxima'], $uid);
            echo json_encode(['success' => true, 'message' => "Recordatorio enviado a $para."], JSON_UNESCAPED_UNICODE);
            exit;
        case 'socio_email':
            $email = trim((string)($_POST['email'] ?? ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception("Correo inválido.");
            $pdo->prepare("UPDATE socios SET email = ? WHERE id = ? AND cliente_id = ?")->execute([$email ?: null, (int)($_POST['socio_id'] ?? 0), $cid]);
            $msg = 'Correo del socio guardado.';
            break;
        default:
            throw new Exception("Acción no válida.");
    }
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => $msg] + $extra, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    foreach ($subidos as $sb) @unlink(spDir() . $sb[0]);
    http_response_code(400);
    if ($e instanceof PDOException) error_log('socio_prestamo_accion.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e instanceof PDOException ? 'Error de base de datos.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
