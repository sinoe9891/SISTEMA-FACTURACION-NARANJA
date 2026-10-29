<?php
// clientes/naranjaymedia/includes/cotizacion_accion.php — Cotizaciones (ver includes/cotizaciones.php).
//   GET  ?correo=<id>                          → para, asunto y mensaje sugeridos para enviarla
//   GET  ?vista_correo=<id>&mensaje=…          → HTML del correo tal como llegará (no envía)
//   POST accion=guardar [id] receptor_id, fecha, valida_hasta, servicio, incluye, condiciones, notas, items[n][producto_id|descripcion|detalle|cantidad|precio]
//   POST accion=estado id, estado (enviada|aceptada|rechazada|borrador)   · accion=eliminar id   · accion=duplicar id
//   POST accion=enviar id, para, cc, asunto, mensaje
//   POST accion=cliente_crear nombre, rtn, email, telefono, direccion, contacto_nombre  → alta rápida de cliente
//   POST accion=producto_crear nombre, precio, tipo_isv, descripcion, receptor_id, para_todos → alta rápida de producto
//   POST accion=categoria_guardar [id] nombre, icono, incluye, condiciones, vigencia_dias · accion=categoria_eliminar id (administradores)
//   POST accion=config vigencia_dias, incluye, condiciones, lema, contacto_email, contacto_telefono, firmante, asunto_correo, mensaje_correo
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once '../../../includes/cotizaciones.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if (!cotizacionesDisponible($pdo)) throw new Exception("Falta instalar sql/migraciones/2026-10-12_cotizaciones.sql.");
    if (!permisoPuede($pdo, 'cotizaciones')) throw new Exception("Tu rol no tiene acceso a Cotizaciones.");
    $cid = (int)cliente_actual();
    if (!$cid) throw new Exception("Empresa no identificada.");
    $uid = (int)USUARIO_ID;
    $esAdmin = in_array(USUARIO_ROL, ['admin', 'superadmin'], true);
    $puedeEditar = in_array(USUARIO_ROL, ['admin', 'superadmin', 'facturador'], true);

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['facturas'])) {
        if (!$puedeEditar || !permisoPuede($pdo, 'generar_factura')) throw new Exception('No tienes permiso para vincular facturas.');
        $c = cotizacionObtener($pdo, $cid, (int)$_GET['facturas']);
        $st = $pdo->prepare("SELECT f.id,f.correlativo,f.fecha_emision,f.moneda,f.total,f.total_usd FROM facturas f WHERE f.cliente_id=? AND f.receptor_id=? AND f.estado='emitida' AND NOT EXISTS (SELECT 1 FROM cotizaciones c WHERE c.factura_id=f.id AND c.cliente_id=f.cliente_id) ORDER BY f.fecha_emision DESC,f.id DESC");
        $st->execute([$cid,$c['receptor_id']]);
        echo json_encode(['success'=>true,'cotizacion'=>['numero'=>$c['numero'],'moneda'=>$c['moneda']??'HNL','total'=>$c['total']],'facturas'=>$st->fetchAll(PDO::FETCH_ASSOC)],JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['correo'])) {
        $c = cotizacionObtener($pdo, $cid, (int)$_GET['correo']);
        [$asunto, $mensaje] = cotizacionCorreoDefecto($pdo, $cid, $c);
        $cfg = correoConfig($pdo, $cid, 'facturacion') ?? [];
        echo json_encode(['success' => true, 'para' => (string)$c['cliente_email'], 'asunto' => $asunto, 'mensaje' => $mensaje, 'numero' => $c['numero'],
            'copias' => correoCopiaOculta($cfg), 'responder_a' => (string)($cfg['responder_a'] ?? ''), 'configurado' => !empty($cfg['host'])], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'vista_correo') {
        $c = cotizacionObtener($pdo, $cid, (int)($_POST['id'] ?? 0));
        [$html] = cotizacionCorreoPlantilla($pdo, $cid, $c, (string)($_POST['mensaje'] ?? ''), correoConfig($pdo, $cid, 'facturacion') ?? []);
        echo json_encode(['success' => true, 'html' => $html], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    if (!$puedeEditar) throw new Exception("Tu rol solo puede consultar cotizaciones.");

    $accion = $_POST['accion'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    switch ($accion) {
        case 'vincular_factura':
            if (!permisoPuede($pdo, 'generar_factura')) throw new Exception('No tienes permiso para vincular facturas.');
            $pdo->beginTransaction();
            cotizacionVincularFactura($pdo,$cid,$id,(int)($_POST['factura_id']??0));
            $pdo->commit();
            echo json_encode(['success'=>true,'message'=>'Factura vinculada. La cotización conserva sus importes y moneda originales.']);
            break;
        case 'guardar':
            $pdo->beginTransaction();
            $id = cotizacionGuardar($pdo, $cid, $uid, $_POST, $id);
            $pdo->commit();
            $c = cotizacionObtener($pdo, $cid, $id);
            echo json_encode(['success' => true, 'id' => $id, 'numero' => $c['numero'], 'message' => 'Cotización ' . $c['numero'] . ' guardada.'], JSON_UNESCAPED_UNICODE);
            break;

        case 'estado':
            $c = cotizacionObtener($pdo, $cid, $id);
            $nuevo = (string)($_POST['estado'] ?? '');
            if (!in_array($nuevo, ['borrador', 'enviada', 'aceptada', 'rechazada'], true)) throw new Exception("Estado no válido.");
            if ($c['estado'] === 'facturada') throw new Exception("La cotización ya se facturó.");
            $pdo->prepare("UPDATE cotizaciones SET estado = ? WHERE id = ? AND cliente_id = ?")->execute([$nuevo, $id, $cid]);
            // Enviada por otro medio (WhatsApp, en persona…): queda registrado cuándo y por dónde, sin mandar correo
            $medio = mb_substr(trim((string)($_POST['medio'] ?? '')), 0, 100);
            if ($nuevo === 'enviada' && $medio !== '')
                $pdo->prepare("UPDATE cotizaciones SET enviada_en = NOW(), enviada_a = ? WHERE id = ? AND cliente_id = ?")->execute(['Por ' . $medio, $id, $cid]);
            echo json_encode(['success' => true, 'message' => 'Cotización ' . $c['numero'] . ': ' . mb_strtolower(COTIZACION_ESTADOS[$nuevo][0]) . '.'], JSON_UNESCAPED_UNICODE);
            break;

        case 'eliminar':
            if (!$esAdmin) throw new Exception("Solo un administrador puede eliminar cotizaciones.");
            $c = cotizacionObtener($pdo, $cid, $id);
            if ($c['estado'] === 'facturada') throw new Exception("No se puede eliminar: ya se convirtió en la factura " . $c['factura_correlativo'] . ".");
            $pdo->prepare("DELETE FROM cotizaciones WHERE id = ? AND cliente_id = ?")->execute([$id, $cid]);
            echo json_encode(['success' => true, 'message' => 'Cotización ' . $c['numero'] . ' eliminada.'], JSON_UNESCAPED_UNICODE);
            break;

        case 'duplicar':
            $c = cotizacionObtener($pdo, $cid, $id);
            $cfg = cotizacionConfig($pdo, $cid);
            $items = array_map(fn($i) => ['producto_id' => $i['producto_id'], 'descripcion' => $i['descripcion'], 'detalle' => $i['detalle'], 'cantidad' => $i['cantidad'], 'precio' => $i['precio']], cotizacionItems($pdo, $id));
            $pdo->beginTransaction();
            $nuevo = cotizacionGuardar($pdo, $cid, $uid, ['receptor_id' => $c['receptor_id'], 'fecha' => date('Y-m-d'), 'valida_hasta' => date('Y-m-d', strtotime('+' . $cfg['vigencia_dias'] . ' days')),
                'servicio' => $c['servicio'], 'incluye' => $c['incluye'], 'condiciones' => $c['condiciones'], 'notas' => $c['notas'], 'items' => $items]);
            $pdo->commit();
            echo json_encode(['success' => true, 'id' => $nuevo, 'message' => 'Copia creada.'], JSON_UNESCAPED_UNICODE);
            break;

        case 'enviar':
            cotizacionEnviar($pdo, $cid, $id, $uid, (string)($_POST['para'] ?? ''), (string)($_POST['cc'] ?? ''), (string)($_POST['asunto'] ?? ''), (string)($_POST['mensaje'] ?? ''));
            echo json_encode(['success' => true, 'message' => 'Cotización enviada a ' . trim((string)$_POST['para']) . '.'], JSON_UNESCAPED_UNICODE);
            break;

        case 'cliente_crear':
            $nombre = mb_substr(trim((string)($_POST['nombre'] ?? '')), 0, 100);
            if ($nombre === '') throw new Exception("Escribe el nombre o razón social del cliente.");
            $email = trim((string)($_POST['email'] ?? ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception("El correo no es válido.");
            $st = $pdo->prepare("SELECT id FROM clientes_factura WHERE cliente_id = ? AND (nombre = ? OR (rtn <> '' AND rtn = ?)) LIMIT 1");
            $st->execute([$cid, $nombre, trim((string)($_POST['rtn'] ?? ''))]);
            if ($ya = $st->fetchColumn()) throw new Exception("Ese cliente ya existe: elígelo en la lista.");
            $t = fn($k, $m) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $m) ?: null;
            $pdo->prepare("INSERT INTO clientes_factura (cliente_id, nombre, rtn, direccion, telefono, email, contacto_nombre) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$cid, $nombre, $t('rtn', 25), $t('direccion', 2000), $t('telefono', 50), $email ?: null, $t('contacto_nombre', 150)]);
            echo json_encode(['success' => true, 'cliente' => ['id' => (int)$pdo->lastInsertId(), 'nombre' => $nombre, 'email' => $email], 'message' => 'Cliente creado.'], JSON_UNESCAPED_UNICODE);
            break;

        case 'producto_crear':
            $nombre = mb_substr(trim((string)($_POST['nombre'] ?? '')), 0, 400);
            if ($nombre === '') throw new Exception("Escribe el nombre del producto o servicio.");
            $precio = round((float)str_replace(',', '', (string)($_POST['precio'] ?? 0)), 2);
            if ($precio < 0) throw new Exception("El precio no puede ser negativo.");
            $isv = in_array((string)($_POST['tipo_isv'] ?? '15'), ['0', '15', '18'], true) ? (string)$_POST['tipo_isv'] : '15';
            $rec = empty($_POST['para_todos']) ? ((int)($_POST['receptor_id'] ?? 0) ?: null) : null;
            if ($rec) {
                $st = $pdo->prepare("SELECT COUNT(*) FROM clientes_factura WHERE id = ? AND cliente_id = ?");
                $st->execute([$rec, $cid]);
                if (!$st->fetchColumn()) $rec = null;
            }
            $pdo->prepare("INSERT INTO productos_clientes (cliente_id, receptores_id, nombre, descripcion, precio, tipo_isv, precio_fijo, tipo) VALUES (?, ?, ?, ?, ?, ?, 0, 'servicio')")
                ->execute([$cid, $rec, $nombre, mb_substr(trim((string)($_POST['descripcion'] ?? '')), 0, 2000) ?: $nombre, $precio, $isv]);
            echo json_encode(['success' => true, 'producto' => ['id' => (int)$pdo->lastInsertId(), 'nombre' => $nombre, 'precio' => $precio, 'tipo_isv' => $isv], 'message' => 'Producto creado.'], JSON_UNESCAPED_UNICODE);
            break;

        case 'categoria_guardar':
            if (!$esAdmin) throw new Exception("Solo un administrador administra las categorías.");
            if (!cotizacionCategoriasDisponible($pdo)) throw new Exception("Falta instalar sql/migraciones/2026-10-12_cotizaciones_categorias.sql.");
            $kid = cotizacionCategoriaGuardar($pdo, $cid, $_POST);
            echo json_encode(['success' => true, 'id' => $kid, 'message' => 'Categoría guardada.'], JSON_UNESCAPED_UNICODE);
            break;

        case 'categoria_eliminar':
            if (!$esAdmin) throw new Exception("Solo un administrador administra las categorías.");
            cotizacionCategoriaEliminar($pdo, $cid, $id);
            echo json_encode(['success' => true, 'message' => 'Categoría eliminada; sus cotizaciones quedaron sin categoría.'], JSON_UNESCAPED_UNICODE);
            break;

        case 'config':
            if (!$esAdmin) throw new Exception("Solo un administrador cambia los textos por defecto.");
            cotizacionGuardarConfig($pdo, $cid, $_POST);
            echo json_encode(['success' => true, 'message' => 'Textos por defecto guardados.'], JSON_UNESCAPED_UNICODE);
            break;

        default:
            throw new Exception("Acción no válida.");
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if (!headers_sent()) http_response_code(400);
    if ($e instanceof PDOException) error_log('cotizacion_accion.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e instanceof PDOException ? 'Error de base de datos.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
