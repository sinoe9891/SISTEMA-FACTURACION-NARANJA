<?php
// clientes/naranjaymedia/includes/licencia_accion.php — Licencias (solo administradores).
//   POST accion=guardar: id (0 = nueva), nombre, proveedor, categoria_id, frecuencia, moneda, costo, proxima_renovacion,
//                        receptor_id (opcional: se le cobra a ese cliente), comision_pct, metodo_pago, notas
//   POST accion=activa:  id, activa (1/0)
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once '../../../includes/licencias.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    if (!in_array(USUARIO_ROL, ['admin', 'superadmin'], true)) throw new Exception("Solo un administrador puede manejar licencias.");
    $cid = (int)cliente_actual();
    if (!$cid || !licenciasDisponible($pdo)) throw new Exception("Falta instalar el módulo de licencias.");
    $accion = $_POST['accion'] ?? '';

    if ($accion === 'activa') {
        $l = licenciaObtener($pdo, $cid, (int)($_POST['id'] ?? 0));
        $act = (int)!empty($_POST['activa']);
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE licencias SET activa = ? WHERE id = ? AND cliente_id = ?")->execute([$act, (int)$l['id'], $cid]);
        licenciasSincronizar($pdo, $cid, (int)USUARIO_ID);
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => $act ? 'Licencia activada: su próxima renovación quedó en Cuentas por pagar.' : 'Licencia desactivada: se anuló su renovación pendiente.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($accion !== 'guardar') throw new Exception("Acción no válida.");

    $id = (int)($_POST['id'] ?? 0);
    $nombre = mb_substr(trim((string)($_POST['nombre'] ?? '')), 0, 150);
    $proveedor = mb_substr(trim((string)($_POST['proveedor'] ?? '')), 0, 150) ?: null;
    $frecuencia = isset(LICENCIA_FRECUENCIAS[$_POST['frecuencia'] ?? '']) ? $_POST['frecuencia'] : 'anual';
    $moneda = ($_POST['moneda'] ?? '') === 'USD' ? 'USD' : 'HNL';
    $costo = round((float)str_replace(',', '', (string)($_POST['costo'] ?? 0)), 2);
    $fecha = trim((string)($_POST['proxima_renovacion'] ?? ''));
    $receptor = (int)($_POST['receptor_id'] ?? 0) ?: null;
    $pct = ($_POST['comision_pct'] ?? '') === '' ? LICENCIA_COMISION_DEFECTO : round((float)$_POST['comision_pct'], 2);
    $metodo = in_array($_POST['metodo_pago'] ?? '', ['tarjeta', 'transferencia', 'efectivo', 'cheque', 'otro'], true) ? $_POST['metodo_pago'] : 'tarjeta';
    $categoria = (int)($_POST['categoria_id'] ?? 0) ?: null;
    $notas = trim((string)($_POST['notas'] ?? '')) ?: null;
    if ($nombre === '') throw new Exception("Escribe el nombre de la licencia.");
    if ($costo <= 0) throw new Exception("El costo debe ser mayor a 0.");
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha)) throw new Exception("Fecha de renovación inválida.");
    if ($pct < 0 || $pct > 500) throw new Exception("La comisión debe estar entre 0 y 500 %.");
    if ($moneda === 'USD' && licenciaTasa($pdo, $cid) <= 0) throw new Exception("No hay tasa del dólar registrada para convertir el costo a lempiras.");
    if ($receptor) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM clientes_factura WHERE id = ? AND cliente_id = ?");
        $st->execute([$receptor, $cid]);
        if (!$st->fetchColumn()) throw new Exception("El cliente no existe.");
    }
    if ($categoria) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM categorias_gastos WHERE id = ? AND cliente_id = ?");
        $st->execute([$categoria, $cid]);
        if (!$st->fetchColumn()) $categoria = null;
    }

    $pdo->beginTransaction();
    $datos = [$nombre, $proveedor, $categoria, $frecuencia, $moneda, $costo, $fecha, $receptor, $pct, $metodo, $notas];
    if ($id) {
        licenciaObtener($pdo, $cid, $id);
        $pdo->prepare("UPDATE licencias SET nombre = ?, proveedor = ?, categoria_id = ?, frecuencia = ?, moneda = ?, costo = ?, proxima_renovacion = ?, receptor_id = ?, comision_pct = ?, metodo_pago = ?, notas = ? WHERE id = ? AND cliente_id = ?")
            ->execute(array_merge($datos, [$id, $cid]));
        // Si se cambió la fecha de renovación, el gasto pendiente se mueve a esa fecha
        $pdo->prepare("UPDATE gastos SET fecha = ?, dia_pago = ? WHERE cliente_id = ? AND licencia_id = ? AND estado = 'pendiente'")->execute([$fecha, (int)substr($fecha, 8, 2), $cid, $id]);
    } else {
        $pdo->prepare("INSERT INTO licencias (nombre, proveedor, categoria_id, frecuencia, moneda, costo, proxima_renovacion, receptor_id, comision_pct, metodo_pago, notas, cliente_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute(array_merge($datos, [$cid]));
        $id = (int)$pdo->lastInsertId();
    }
    licenciasSincronizar($pdo, $cid, (int)USUARIO_ID);
    $pdo->commit();
    echo json_encode(['success' => true, 'id' => $id, 'message' => 'Licencia guardada. Su próxima renovación está en Cuentas por pagar' . ($receptor ? ' y saldrá como aviso en la factura del cliente.' : '.')], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    if ($e instanceof PDOException) error_log('licencia_accion.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e instanceof PDOException ? 'Error de base de datos.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
