<?php
// clientes/naranjaymedia/cobro_accion.php — Cobros por correo programados (solo administradores).
//   GET  ?facturas=<receptor_id>                    → facturas del cliente con su saldo (JSON)
//   POST accion=crear  modo=programar|ahora|prueba   → crea el cobro (genera los PDF) y, si toca, lo envía
//   GET  ?ver=<id>                                  → detalle: datos, vista del correo, adjuntos e intentos de envío
//   GET  ?pdf=<id>&factura=<factura_id>             → PDF adjunto tal como se envió
//   POST accion=enviar_ya | cancelar | reprogramar   → id (+ programado_para)
//   POST accion=editar (para, cc, asunto, mensaje_html, programado_para) | reenviar (para, cc, prueba) | eliminar
// Está junto a ver_factura.php porque los PDF se generan incluyéndolo (como la descarga ZIP).
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/cobros.php';
require_once '../../includes/cuentas.php';
header('Content-Type: application/json; charset=utf-8');

/** PDF de una factura, tal como «Imprimir / PDF». */
function cobroPdfFactura(int $facturaId): string
{
    global $pdo;
    $previo = $_GET;
    $_GET = ['id' => (string)$facturaId, 'pdf_bytes' => 1];
    ob_start();
    try {
        include __DIR__ . '/ver_factura.php';
        return (string)ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    } finally {
        $_GET = $previo;
    }
}

try {
    if (!cobrosDisponible($pdo)) throw new Exception("Falta instalar sql/migraciones/2026-10-04_cobros_programados.sql.");
    if (!in_array(USUARIO_ROL, ['admin', 'superadmin'], true)) throw new Exception("Solo un administrador puede programar cobros.");
    $cid = cliente_actual();
    if (!$cid) throw new Exception("Empresa no identificada.");
    $uid = (int)USUARIO_ID;

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ver'])) {
        echo json_encode(['success' => true] + cobroDetalle($pdo, $cid, (int)$_GET['ver']), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['pdf'])) {
        $cobro = cobroObtener($pdo, $cid, (int)$_GET['pdf']);
        $st = $pdo->prepare("SELECT archivo FROM cobros_programados_facturas WHERE cobro_id = ? AND factura_id = ?");
        $st->execute([$cobro['id'], (int)($_GET['factura'] ?? 0)]);
        $archivo = (string)$st->fetchColumn();
        $ruta = cobroDir($cid, (int)$cobro['id']) . $archivo;
        if ($archivo === '' || !is_file($ruta)) throw new Exception("El PDF ya no está disponible.");
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $archivo . '"');
        header('Content-Length: ' . filesize($ruta));
        readfile($ruta);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $rid = (int)($_GET['facturas'] ?? 0);
        $st = $pdo->prepare("SELECT id, nombre, email, contacto_nombre FROM clientes_factura WHERE id = ? AND cliente_id = ?");
        $st->execute([$rid, $cid]);
        $cli = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cli) throw new Exception("Cliente no encontrado.");
        $hayCxc = cxcDisponible($pdo);
        $st = $pdo->prepare("
            SELECT f.id, f.correlativo, f.contrato_id, DATE(f.fecha_emision) AS fecha, f.total, f.pagada,
                   COALESCE(f.periodo_mes, MONTH(f.fecha_emision)) AS pm, COALESCE(f.periodo_anio, YEAR(f.fecha_emision)) AS pa,
                   " . ($hayCxc ? "COALESCE((SELECT SUM(c.monto) FROM cobros_factura c WHERE c.factura_id = f.id AND c.anulado = 0), 0)" : "0") . " AS abonado
            FROM facturas f
            WHERE f.cliente_id = ? AND f.receptor_id = ? AND f.estado = 'emitida' AND f.fecha_emision >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
            ORDER BY f.fecha_emision DESC, f.id DESC");
        $st->execute([$cid, $rid]);
        $facturas = array_map(function ($f) {
            $f['abonado'] = round((float)$f['abonado'], 2);
            $f['saldo'] = $f['abonado'] > 0 ? max(0, round((float)$f['total'] - $f['abonado'], 2)) : ((int)$f['pagada'] ? 0.0 : round((float)$f['total'], 2));
            return $f;
        }, $st->fetchAll(PDO::FETCH_ASSOC));
        // Contactos que van en copia de los cobros (si el módulo de contactos está instalado).
        // Con contrato_id solo aplican a las facturas de ese contrato (proyectos distintos del mismo cliente).
        $contactos = [];
        if ($pdo->query("SHOW TABLES LIKE 'clientes_factura_contactos'")->fetchColumn()) {
            $st = $pdo->prepare("SELECT nombre, email, contrato_id FROM clientes_factura_contactos WHERE cliente_id = ? AND receptor_id = ? AND copiar_cobros = 1 AND email IS NOT NULL AND email <> '' ORDER BY nombre");
            $st->execute([$cid, $rid]);
            $contactos = array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC), fn($c) => strcasecmp($c['email'], (string)$cli['email']) !== 0));
        }
        echo json_encode(['success' => true, 'cliente' => $cli, 'facturas' => $facturas, 'contactos' => $contactos], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    $accion = $_POST['accion'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    switch ($accion) {
        case 'crear':
            $modo = in_array($_POST['modo'] ?? '', ['programar', 'ahora', 'prueba'], true) ? $_POST['modo'] : 'programar';
            $datos = $_POST;
            if ($modo === 'prueba') {
                $datos['para'] = $_POST['para_prueba'] ?? '';
                $datos['cc'] = '';
                $datos['prueba'] = 1;
            }
            $datos['enviar_ahora'] = $modo === 'ahora';
            $pdo->beginTransaction();
            $nuevo = cobroCrear($pdo, $cid, $uid, $datos, 'cobroPdfFactura');
            $pdo->commit();
            if ($modo === 'programar') {
                $cuando = $pdo->query("SELECT programado_para FROM cobros_programados WHERE id = $nuevo")->fetchColumn();
                echo json_encode(['success' => true, 'id' => $nuevo, 'message' => 'Cobro programado para el ' . date('d/m/Y \a \l\a\s g:i a', strtotime($cuando)) . '.'], JSON_UNESCAPED_UNICODE);
            } else {
                try {
                    cobroEnviar($pdo, $cid, $nuevo, $uid);
                } catch (Throwable $e) {
                    throw new Exception("Se guardó, pero no se pudo enviar: " . $e->getMessage());
                }
                echo json_encode(['success' => true, 'id' => $nuevo, 'message' => $modo === 'prueba' ? 'Prueba enviada a: ' . trim((string)$datos['para']) : 'Cobro enviado.'], JSON_UNESCAPED_UNICODE);
            }
            break;

        case 'enviar_ya':
            cobroEnviar($pdo, $cid, $id, $uid);
            echo json_encode(['success' => true, 'message' => 'Cobro enviado.']);
            break;

        case 'cancelar':
            $u = $pdo->prepare("UPDATE cobros_programados SET estado = 'cancelado' WHERE id = ? AND cliente_id = ? AND estado IN ('programado','error')");
            $u->execute([$id, $cid]);
            if (!$u->rowCount()) throw new Exception("Solo se pueden cancelar cobros que aún no se envían.");
            echo json_encode(['success' => true, 'message' => 'Cobro cancelado.']);
            break;

        case 'reprogramar':
            $dt = DateTime::createFromFormat('Y-m-d\TH:i', (string)($_POST['programado_para'] ?? ''));
            if (!$dt || $dt < new DateTime('-5 minutes')) throw new Exception("Elige una fecha y hora futura.");
            $u = $pdo->prepare("UPDATE cobros_programados SET programado_para = ?, estado = 'programado', intentos = 0, error = NULL WHERE id = ? AND cliente_id = ? AND estado IN ('programado','error')");
            $u->execute([$dt->format('Y-m-d H:i:s'), $id, $cid]);
            if (!$u->rowCount()) throw new Exception("Solo se pueden reprogramar cobros que aún no se envían.");
            echo json_encode(['success' => true, 'message' => 'Reprogramado para el ' . $dt->format('d/m/Y \a \l\a\s g:i a') . '.'], JSON_UNESCAPED_UNICODE);
            break;

        case 'editar':
            cobroEditar($pdo, $cid, $id, $_POST);
            echo json_encode(['success' => true, 'message' => 'Cobro actualizado.'], JSON_UNESCAPED_UNICODE);
            break;

        case 'reenviar':
            $prueba = !empty($_POST['prueba']);
            $pdo->beginTransaction();
            $nuevo = cobroDuplicar($pdo, $cid, $uid, $id, (string)($_POST['para'] ?? ''), (string)($_POST['cc'] ?? ''), $prueba);
            $pdo->commit();
            try {
                cobroEnviar($pdo, $cid, $nuevo, $uid);
            } catch (Throwable $e) {
                throw new Exception("Se guardó la copia (#$nuevo), pero no se pudo enviar: " . $e->getMessage());
            }
            echo json_encode(['success' => true, 'id' => $nuevo, 'message' => ($prueba ? 'Prueba reenviada' : 'Cobro reenviado') . ' (#' . $nuevo . ').'], JSON_UNESCAPED_UNICODE);
            break;

        case 'eliminar':
            cobroEliminar($pdo, $cid, $id);
            echo json_encode(['success' => true, 'message' => 'Cobro eliminado.'], JSON_UNESCAPED_UNICODE);
            break;

        default:
            throw new Exception("Acción no válida.");
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    if ($e instanceof PDOException) error_log('cobro_accion.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e instanceof PDOException ? 'Error de base de datos.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
