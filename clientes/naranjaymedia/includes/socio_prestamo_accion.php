<?php
// clientes/naranjaymedia/includes/socio_prestamo_accion.php — Préstamos a socios (ver includes/socios_prestamos.php).
//   GET  ?calcular=1&monto&num_cuotas&frecuencia&primera_cuota  → cuotas calculadas (vista previa)
//   POST accion=crear (socio_id, descripcion, monto, fecha, num_cuotas, frecuencia, primera_cuota, cuenta_id, referencia,
//                      avisar, aviso_dias, aviso_correo, aviso_cc, notas, comprobante)
//        accion=editar (id + los campos de crear, sin socio) · accion=editar_pago (pago_id + los de pagar)
//        accion=pagar (id, fecha, monto, metodo, referencia, cuenta_id, notas, comprobante) · accion=anular_pago (pago_id, motivo)
//        accion=condonar (id, motivo, fecha) · accion=anular (id, motivo) · accion=avisos (id, avisar, aviso_dias, aviso_correo, aviso_cc)
//        accion=enviar (id) → recordatorio ahora · accion=socio_email (socio_id, email)
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once '../../../includes/socios_prestamos.php';
header('Content-Type: application/json; charset=utf-8');

$archivo = null;
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
    if (in_array($accion, ['crear', 'pagar', 'editar', 'editar_pago'], true)) $archivo = spSubir($_FILES['comprobante'] ?? null, $cid, in_array($accion, ['crear', 'editar'], true) ? 'socio_prestamo' : 'socio_prestamo_pago');

    $pdo->beginTransaction();
    $extra = [];
    switch ($accion) {
        case 'crear':
            $extra['id'] = spCrear($pdo, $cid, $uid, $_POST, $archivo);
            $msg = 'Préstamo registrado con sus cuotas.';
            break;
        case 'pagar':
            spPagar($pdo, $cid, $uid, $id, $_POST, $archivo);
            $msg = 'Pago registrado.';
            break;
        case 'editar':
            spEditar($pdo, $cid, $uid, $id, $_POST, $archivo);
            $msg = 'Préstamo actualizado: las cuotas se volvieron a calcular.';
            break;
        case 'editar_pago':
            spEditarPago($pdo, $cid, $uid, (int)($_POST['pago_id'] ?? 0), $_POST, $archivo);
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
    if ($archivo) @unlink(spDir() . $archivo[0]);
    http_response_code(400);
    if ($e instanceof PDOException) error_log('socio_prestamo_accion.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e instanceof PDOException ? 'Error de base de datos.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
