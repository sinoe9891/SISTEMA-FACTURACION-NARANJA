<?php
// clientes/naranjaymedia/includes/inventario_accion.php
// Inventario (POST accion=…). Escritura: admin / superadmin.
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once '../../../includes/inventario.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    if (!in_array(USUARIO_ROL, ['admin', 'superadmin'], true)) throw new Exception("Solo un administrador puede modificar el inventario.");
    if (!invDisponible($pdo)) throw new Exception("El inventario no está instalado (falta sql/migraciones/2026-10-03_inventario.sql).");
    $cid = cliente_actual();
    if (!$cid) throw new Exception("Empresa no identificada.");
    $uid = (int)USUARIO_ID;
    $accion = $_POST['accion'] ?? '';
    $extra = [];

    $pdo->beginTransaction();
    switch ($accion) {
        case 'producto_guardar':
            // Crea o configura un producto. Los "bien" llevan inventario; los nuevos son generales (todos los clientes).
            $id      = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;
            $nombre  = mb_substr(trim((string)($_POST['nombre'] ?? '')), 0, 400);
            $tipo    = $_POST['tipo'] ?? 'bien';
            $sku     = mb_substr(trim((string)($_POST['sku'] ?? '')), 0, 50) ?: null;
            $barras  = mb_substr(preg_replace('/\s+/', '', (string)($_POST['codigo_barras'] ?? '')), 0, 64) ?: null;
            $unidad  = mb_substr(trim((string)($_POST['unidad'] ?? '')), 0, 20) ?: 'unidad';
            $isv     = (string)($_POST['tipo_isv'] ?? '15');
            if ($nombre === '') throw new Exception("El nombre es obligatorio.");
            if (!in_array($tipo, ['bien', 'servicio'], true)) throw new Exception("Tipo inválido.");
            if (!in_array($isv, ['15', '18', '0'], true)) throw new Exception("ISV inválido.");
            foreach (['precio', 'stock_minimo', 'costo'] as $k) {
                if (($_POST[$k] ?? '') !== '' && (!is_numeric($_POST[$k]) || (float)$_POST[$k] < 0)) throw new Exception("El campo $k debe ser un número de 0 o más.");
            }
            $precio = round((float)($_POST['precio'] ?? 0), 2);
            $minimo = round((float)($_POST['stock_minimo'] ?? 0), 3);
            // Códigos únicos dentro de la empresa
            foreach (['sku' => $sku, 'codigo_barras' => $barras] as $col => $val) {
                if (!$val) continue;
                $dup = $pdo->prepare("SELECT nombre FROM productos_clientes WHERE cliente_id = ? AND $col = ? AND id <> ?");
                $dup->execute([$cid, $val, (int)$id]);
                if ($n = $dup->fetchColumn()) throw new Exception(($col === 'sku' ? 'El SKU' : 'El código de barras') . " $val ya lo usa «{$n}».");
            }
            if ($id) {
                $p = invProducto($pdo, $cid, $id, false);
                if ($p['tipo'] === 'bien' && $tipo === 'servicio') {
                    $st = $pdo->prepare("SELECT COALESCE(SUM(cantidad), 0) FROM inv_existencias WHERE producto_id = ?");
                    $st->execute([$id]);
                    if ((float)$st->fetchColumn() > 0) throw new Exception("Tiene existencias: ajústalas a 0 antes de convertirlo en servicio.");
                }
                $pdo->prepare("UPDATE productos_clientes SET nombre=?, tipo=?, sku=?, codigo_barras=?, unidad=?, precio=?, tipo_isv=?, stock_minimo=? WHERE id=? AND cliente_id=?")
                    ->execute([$nombre, $tipo, $sku, $barras, $unidad, $precio, $isv, $minimo, $id, $cid]);
                $mensaje = 'Producto actualizado.';
            } else {
                $costo = round((float)($_POST['costo'] ?? 0), 4);
                $pdo->prepare("INSERT INTO productos_clientes (cliente_id, receptores_id, nombre, descripcion, precio, tipo_isv, precio_fijo, tipo, sku, codigo_barras, unidad, costo, stock_minimo)
                               VALUES (?, NULL, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)")
                    ->execute([$cid, $nombre, $nombre, $precio, $isv, $tipo, $sku, $barras, $unidad, $costo, $minimo]);
                $extra['id'] = (int)$pdo->lastInsertId();
                $mensaje = 'Producto creado.';
            }
            break;

        case 'entrada':
            $extra['saldo'] = invEntrada($pdo, $cid, $uid, $_POST);
            $mensaje = 'Entrada registrada. Existencia: ' . invFmt($extra['saldo']);
            break;

        case 'ajuste':
            $extra['saldo'] = invAjuste($pdo, $cid, $uid, $_POST);
            $mensaje = 'Ajuste registrado. Existencia: ' . invFmt($extra['saldo']);
            break;

        case 'traslado':
            $extra['id'] = invCrearTraslado($pdo, $cid, $uid, $_POST);
            $mensaje = 'Traslado enviado: queda en tránsito hasta que la tienda de destino lo reciba.';
            break;

        case 'traslado_recibir':
            invRecibirTraslado($pdo, $cid, $uid, (int)($_POST['id'] ?? 0));
            $mensaje = 'Traslado recibido.';
            break;

        case 'traslado_anular':
            invAnularTraslado($pdo, $cid, $uid, (int)($_POST['id'] ?? 0));
            $mensaje = 'Traslado anulado: la mercadería regresó al origen.';
            break;

        default:
            throw new Exception("Acción no válida.");
    }
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => $mensaje] + $extra, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    if ($e instanceof PDOException) error_log('inventario_accion.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e instanceof PDOException ? 'Error de base de datos al guardar.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
