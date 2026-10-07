<?php
// clientes/naranjaymedia/cobro_accion.php — Cobros por correo programados (solo administradores).
//   GET  ?facturas=<receptor_id>                    → facturas del cliente con su saldo, sus recibos y los pagos pendientes de sus planes (JSON)
//   GET  ?pdf=<id>&recibo=<recibo_id>               → recibo adjunto tal como se envió
//   POST accion=generar_mensaje (receptor_id, tipo recordatorio_pago|envio_recibo, ids[]) → asunto y mensaje con la plantilla
//   POST accion=crear  modo=programar|ahora|prueba   → crea el cobro (genera los PDF) y, si toca, lo envía
//   GET  ?ver=<id>                                  → detalle: datos, vista del correo, adjuntos e intentos de envío
//   GET  ?pdf=<id>&factura=<factura_id>             → PDF adjunto tal como se envió (&documento=<id>: documento de la empresa)
//   POST accion=enviar_ya | cancelar | reprogramar   → id (+ programado_para)
//   POST accion=editar (para, cc, asunto, mensaje_html, programado_para) | reenviar (para, cc, prueba) | eliminar
//   POST accion=previsualizar (receptor_id, factura_ids[], asunto, mensaje_html) → HTML del correo tal como llegará (no envía)
// Está junto a ver_factura.php porque los PDF se generan incluyéndolo (como la descarga ZIP).
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/cobros.php';
require_once '../../includes/cuentas.php';
require_once '../../includes/contrato_plan.php';
require_once '../../includes/recibo_pdf.php';
require_once '../../includes/documentos.php';
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
        if (isset($_GET['recibo'])) {
            $st = $pdo->prepare("SELECT archivo FROM cobros_programados_recibos WHERE cobro_id = ? AND recibo_id = ?");
            $st->execute([$cobro['id'], (int)$_GET['recibo']]);
        } elseif (isset($_GET['documento'])) {
            $st = $pdo->prepare("SELECT archivo FROM cobros_programados_documentos WHERE cobro_id = ? AND documento_id = ?");
            $st->execute([$cobro['id'], (int)$_GET['documento']]);
        } elseif (isset($_GET['anticipo'])) {
            $st = $pdo->prepare("SELECT archivo FROM cobros_programados_anticipos WHERE cobro_id = ? AND anticipo_id = ?");
            $st->execute([$cobro['id'], (int)$_GET['anticipo']]);
        } else {
            $st = $pdo->prepare("SELECT archivo FROM cobros_programados_facturas WHERE cobro_id = ? AND factura_id = ?");
            $st->execute([$cobro['id'], (int)($_GET['factura'] ?? 0)]);
        }
        $archivo = (string)$st->fetchColumn();
        $ruta = cobroDir($cid, (int)$cobro['id']) . $archivo;
        if ($archivo === '' || !is_file($ruta)) throw new Exception("El PDF ya no está disponible.");
        header('Content-Type: ' . ((string)(new finfo(FILEINFO_MIME_TYPE))->file($ruta) ?: 'application/pdf'));
        header('Content-Disposition: inline; filename="' . $archivo . '"');
        header('X-Content-Type-Options: nosniff');
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
            SELECT f.id, f.correlativo, f.contrato_id, DATE(f.fecha_emision) AS fecha, f.total, f.pagada, f.enviada_receptor AS enviada,
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
        // Recibos (contratos sin factura) y pagos del plan aún no cobrados, de los contratos de este cliente
        $recibos = $plan = [];
        if (cobrosExtrasDisponible($pdo)) {
            $st = $pdo->prepare("SELECT r.id, r.numero_recibo, r.fecha_emision AS fecha, r.monto, r.concepto, r.contrato_id FROM contratos_recibos r
                                 WHERE r.cliente_id = ? AND r.receptor_id = ? AND r.estado = 'emitido' AND r.fecha_emision >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH) ORDER BY r.fecha_emision DESC, r.id DESC");
            $st->execute([$cid, $rid]);
            $recibos = $st->fetchAll(PDO::FETCH_ASSOC);
            // Pagos anticipados (recibo de anticipo) de los contratos del cliente
            if (cobroAnticiposDisponible($pdo)) {
                $st = $pdo->prepare("SELECT a.id, CONCAT('A-', LPAD(a.id, 5, '0')) AS numero_recibo, a.fecha, a.monto, COALESCE(a.concepto, 'Pago anticipado') AS concepto, a.contrato_id, 1 AS anticipo
                                     FROM contratos_anticipos a JOIN contratos c ON c.id = a.contrato_id AND c.cliente_id = a.cliente_id
                                     WHERE a.cliente_id = ? AND c.receptor_id = ? AND a.anulado = 0 ORDER BY a.fecha DESC");
                $st->execute([$cid, $rid]);
                $recibos = array_merge($recibos, $st->fetchAll(PDO::FETCH_ASSOC));
            }
            $st = $pdo->prepare("SELECT id, nombre_contrato FROM contratos WHERE cliente_id = ? AND receptor_id = ? AND estado IN ('activo','vencido')");
            $st->execute([$cid, $rid]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ct) {
                foreach (planLineas($pdo, $cid, (int)$ct['id']) as $l) {
                    if ($l['estado'] === 'pagado') continue;
                    $plan[] = ['id' => (int)$l['id'], 'contrato_id' => (int)$ct['id'], 'contrato' => $ct['nombre_contrato'], 'fecha' => $l['fecha'], 'concepto' => $l['concepto'],
                               'total' => (float)$l['total'], 'estado' => $l['estado'], 'dias' => $l['dias'], 'cobro' => $l['cobro']];
                }
            }
            usort($plan, fn($a, $b) => $a['fecha'] <=> $b['fecha']);
        }
        // Documentos de la empresa que se pueden adjuntar (con su semáforo de vencimiento)
        $documentos = array_map(function ($d) {
            [$bg, $fg, $txt] = docSemaforo($d);
            return ['id' => (int)$d['id'], 'nombre' => $d['nombre'], 'vence' => $d['fecha_vencimiento'], 'estado' => $d['estado'], 'defecto' => (int)$d['adjuntar_por_defecto'], 'bg' => $bg, 'fg' => $fg, 'txt' => $txt];
        }, docsLista($pdo, $cid));
        echo json_encode(['success' => true, 'cliente' => $cli, 'facturas' => $facturas, 'recibos' => $recibos, 'plan' => $plan, 'contactos' => $contactos, 'documentos' => $documentos], JSON_UNESCAPED_UNICODE);
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
            $nuevo = cobroCrear($pdo, $cid, $uid, $datos, 'cobroPdfFactura', fn($recId) => reciboPdf($pdo, $cid, $recId));
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

        case 'generar_mensaje':
            echo json_encode(['success' => true] + cobroMensajeGenerar($pdo, $cid, (int)($_POST['receptor_id'] ?? 0), (string)($_POST['tipo'] ?? ''), (array)($_POST['ids'] ?? []), (array)($_POST['anticipo_ids'] ?? [])), JSON_UNESCAPED_UNICODE);
            break;

        case 'previsualizar':
            $rid = (int)($_POST['receptor_id'] ?? 0);
            $ids = array_values(array_filter(array_map('intval', (array)($_POST['factura_ids'] ?? []))));
            $nums = [];
            if ($ids) {
                $st = $pdo->prepare("SELECT CONCAT('factura ', correlativo) FROM facturas WHERE cliente_id = ? AND receptor_id = ? AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY correlativo");
                $st->execute([$cid, $rid, ...$ids]);
                $nums = $st->fetchAll(PDO::FETCH_COLUMN);
            }
            foreach (array_filter(array_map('intval', (array)($_POST['anticipo_ids'] ?? []))) as $aid) $nums[] = 'recibo de anticipo A-' . str_pad((string)$aid, 5, '0', STR_PAD_LEFT);
            $rids = array_values(array_filter(array_map('intval', (array)($_POST['recibo_ids'] ?? []))));
            if ($rids) {
                $st = $pdo->prepare("SELECT CONCAT('recibo ', LPAD(numero_recibo, 5, '0')) FROM contratos_recibos WHERE cliente_id = ? AND receptor_id = ? AND id IN (" . implode(',', array_fill(0, count($rids), '?')) . ") ORDER BY numero_recibo");
                $st->execute([$cid, $rid, ...$rids]);
                $nums = array_merge($nums, $st->fetchAll(PDO::FETCH_COLUMN));
            }
            foreach (array_filter(array_map('intval', (array)($_POST['documento_ids'] ?? []))) as $did) $nums[] = docObtener($pdo, $cid, $did)['nombre'];
            $emp = $pdo->prepare("SELECT nombre, alias FROM clientes_saas WHERE id = ?");
            $emp->execute([$cid]);
            [$html] = cobroPlantilla(cobroLimpiarHtml((string)($_POST['mensaje_html'] ?? '')), $emp->fetch(PDO::FETCH_ASSOC) ?: [], correoConfig($pdo, $cid, 'facturacion') ?? [], $nums, false);
            echo json_encode(['success' => true, 'html' => $html, 'adjuntos' => $nums], JSON_UNESCAPED_UNICODE);
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
