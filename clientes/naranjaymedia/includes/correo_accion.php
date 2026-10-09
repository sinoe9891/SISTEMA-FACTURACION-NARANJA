<?php
// clientes/naranjaymedia/includes/correo_accion.php
//   POST accion=guardar       → configuración SMTP (admin)
//   POST accion=probar        → correo de prueba a "para" (admin)
//   POST accion=enviar_pago   → aviso de pago al colaborador (gasto_id) (admin/facturador)
//   POST accion=pendientes_guardar | pendientes_vista | pendientes_probar → resumen diario de pendientes a gerencia (admin)
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once '../../../includes/correo_pagos.php';
require_once '../../../includes/aviso_pendientes.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    if (!correoDisponible($pdo)) throw new Exception("Falta instalar sql/migraciones/2026-10-04_correo.sql.");
    $cid = cliente_actual();
    if (!$cid) throw new Exception("Empresa no identificada.");
    $esAdmin = permisoPuede($pdo, 'configuracion_correo');   // configurar la cuenta, pruebas y el resumen a gerencia
    $uid = (int)USUARIO_ID;

    switch ($_POST['accion'] ?? '') {
        case 'guardar':
            if (!$esAdmin) throw new Exception("Solo un administrador puede configurar el correo.");
            correoGuardarConfig($pdo, $cid, $_POST);
            echo json_encode(['success' => true, 'message' => 'Configuración guardada.']);
            break;

        case 'probar':
            if (!$esAdmin) throw new Exception("Solo un administrador puede enviar pruebas.");
            $para = trim((string)($_POST['para'] ?? ''));
            $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#0f172a"><p><strong>Correo de prueba</strong></p>'
                . '<p>Si recibes este mensaje, la configuración SMTP del sistema de facturación funciona correctamente.</p>'
                . '<p style="color:#64748b;font-size:12px">Enviado el ' . date('d/m/Y H:i') . '</p></div>';
            $perfil = correoPerfil($_POST['perfil'] ?? 'nomina');
            correoEnviar($pdo, $cid, $para, 'Prueba de correo (' . CORREO_PERFILES[$perfil] . ') · Sistema de facturación', $html,
                "Correo de prueba: la configuración SMTP funciona correctamente.\nEnviado el " . date('d/m/Y H:i'), [], 'prueba', null, $uid, $perfil);
            echo json_encode(['success' => true, 'message' => "Correo de prueba enviado a: $para"], JSON_UNESCAPED_UNICODE);
            break;

        case 'enviar_pago':
            if (!in_array(USUARIO_ROL, ['admin', 'superadmin', 'facturador', 'nomina'], true)) throw new Exception("No tienes permiso para enviar avisos.");
            $para = correoNotificarPago($pdo, $cid, (int)($_POST['gasto_id'] ?? 0), $uid);
            echo json_encode(['success' => true, 'message' => "Aviso de pago enviado a: $para"], JSON_UNESCAPED_UNICODE);
            break;

        case 'pendientes_guardar':
            if (!$esAdmin) throw new Exception("Solo un administrador puede configurar el resumen.");
            avisoPendientesGuardar($pdo, $cid, $_POST);
            echo json_encode(['success' => true, 'message' => 'Resumen diario guardado.'], JSON_UNESCAPED_UNICODE);
            break;

        case 'pendientes_vista':
            if (!$esAdmin) throw new Exception("Solo un administrador puede ver el resumen.");
            echo json_encode(['success' => true] + avisoPendientesVista($pdo, $cid), JSON_UNESCAPED_UNICODE);
            break;

        case 'pendientes_probar':
            if (!$esAdmin) throw new Exception("Solo un administrador puede enviar el resumen.");
            [$para, $n] = avisoPendientesEnviar($pdo, $cid, $uid, true);
            echo json_encode(['success' => true, 'message' => "Resumen ($n pendiente" . ($n === 1 ? '' : 's') . ") enviado a: $para"], JSON_UNESCAPED_UNICODE);
            break;

        default:
            throw new Exception("Acción no válida.");
    }
} catch (Throwable $e) {
    http_response_code(400);
    if ($e instanceof PDOException) error_log('correo_accion.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e instanceof PDOException ? 'Error de base de datos.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
