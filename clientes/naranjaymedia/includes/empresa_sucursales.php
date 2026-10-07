<?php
// clientes/naranjaymedia/includes/empresa_sucursales.php
// Superadmin: establecimientos y puntos de emisión de una empresa.
//   GET  ?empresa_id=X                       → lista (JSON)
//   POST accion=establecimiento (id opcional) → crea/edita establecimiento
//   POST accion=punto (id opcional)           → crea/edita punto de emisión (con departamento y municipio)
//   POST accion=eliminar_establecimiento id   → solo si no tiene facturas, CAI, turnos ni inventario
//   POST accion=eliminar_punto id             → solo si no tiene CAI
//   GET  ?ubicaciones=1                       → departamentos y municipios (para los selectores)
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if (USUARIO_ROL !== 'superadmin') throw new Exception("Solo el superadmin puede administrar sucursales.");

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ubicaciones'])) {
        echo json_encode(['success' => true,
            'departamentos' => $pdo->query("SELECT id, nombre FROM departamentos ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC),
            'municipios' => $pdo->query("SELECT id, nombre, departamento_id FROM municipios ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $empresa = filter_input(INPUT_GET, 'empresa_id', FILTER_VALIDATE_INT);
        if (!$empresa) throw new Exception("Empresa no indicada.");
        $stmt = $pdo->prepare("
            SELECT e.establecimiento_id AS id, e.nombre, e.codigo_establecimiento AS codigo,
                   (SELECT COUNT(*) FROM establecimientos e2 WHERE e2.cliente_id = e.cliente_id AND e2.codigo_establecimiento = e.codigo_establecimiento) > 1 AS codigo_duplicado,
                   (SELECT COUNT(*) FROM facturas f WHERE f.establecimiento_id = e.establecimiento_id) AS facturas
            FROM establecimientos e WHERE e.cliente_id = ? ORDER BY e.codigo_establecimiento, e.nombre
        ");
        $stmt->execute([$empresa]);
        $ests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stP = $pdo->prepare("SELECT p.id, p.codigo_punto AS codigo, p.descripcion, p.departamento_id, p.municipio_id, d.nombre AS departamento, m.nombre AS municipio,
                (SELECT COUNT(*) FROM cai_rangos c WHERE c.punto_emision_id = p.id) AS cais
            FROM puntos_emision p LEFT JOIN departamentos d ON d.id = p.departamento_id LEFT JOIN municipios m ON m.id = p.municipio_id
            WHERE p.establecimiento_id = ? ORDER BY p.codigo_punto");
        foreach ($ests as &$e) {
            $stP->execute([$e['id']]);
            $e['puntos'] = $stP->fetchAll(PDO::FETCH_ASSOC);
            $e['codigo_duplicado'] = (bool)$e['codigo_duplicado'];
        }
        echo json_encode(['success' => true, 'establecimientos' => $ests], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    $accion = $_POST['accion'] ?? '';
    $id     = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;

    if ($accion === 'establecimiento') {
        $empresa = filter_input(INPUT_POST, 'empresa_id', FILTER_VALIDATE_INT);
        $nombre  = mb_substr(trim((string)($_POST['nombre'] ?? '')), 0, 100);
        $codigo  = trim((string)($_POST['codigo'] ?? ''));
        if (!$empresa) throw new Exception("Empresa no indicada.");
        if ($nombre === '') throw new Exception("El nombre del establecimiento es obligatorio.");
        if (!preg_match('/^\d{3}$/', $codigo)) throw new Exception("El código del establecimiento son 3 dígitos (ej. 001).");
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM establecimientos WHERE cliente_id = ? AND codigo_establecimiento = ? AND establecimiento_id <> ?");
        $stmt->execute([$empresa, $codigo, (int)$id]);
        if ($stmt->fetchColumn()) throw new Exception("Ya existe un establecimiento con el código $codigo en esta empresa.");

        if ($id) {
            $stmt = $pdo->prepare("SELECT codigo_establecimiento, (SELECT COUNT(*) FROM facturas WHERE establecimiento_id = ?) FROM establecimientos WHERE establecimiento_id = ? AND cliente_id = ?");
            $stmt->execute([$id, $id, $empresa]);
            $act = $stmt->fetch(PDO::FETCH_NUM);
            if (!$act) throw new Exception("Establecimiento no encontrado.");
            // El código aparece en el número fiscal de las facturas ya emitidas
            if ($act[0] !== $codigo && (int)$act[1] > 0) throw new Exception("Este establecimiento ya tiene facturas: no se puede cambiar su código.");
            $pdo->prepare("UPDATE establecimientos SET nombre = ?, codigo_establecimiento = ? WHERE establecimiento_id = ?")->execute([$nombre, $codigo, $id]);
            echo json_encode(['success' => true, 'message' => 'Establecimiento actualizado.']);
        } else {
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO establecimientos (cliente_id, nombre, codigo_establecimiento, codigo_punto) VALUES (?, ?, ?, '01')")->execute([$empresa, $nombre, $codigo]);
            $nuevo = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO puntos_emision (establecimiento_id, codigo_punto, descripcion) VALUES (?, '01', 'Caja principal')")->execute([$nuevo]);
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Establecimiento creado con su punto de emisión 01.', 'id' => $nuevo]);
        }
        exit;
    }

    if ($accion === 'punto') {
        $est    = filter_input(INPUT_POST, 'establecimiento_id', FILTER_VALIDATE_INT);
        $codigo = trim((string)($_POST['codigo'] ?? ''));
        $desc   = mb_substr(trim((string)($_POST['descripcion'] ?? '')), 0, 100);
        $dep    = (int)($_POST['departamento_id'] ?? 0) ?: null;
        $mun    = (int)($_POST['municipio_id'] ?? 0) ?: null;
        if ($mun) {
            $st = $pdo->prepare("SELECT departamento_id FROM municipios WHERE id = ?");
            $st->execute([$mun]);
            $depMun = (int)$st->fetchColumn();
            if (!$depMun) throw new Exception("Municipio no válido.");
            $dep = $depMun;
        }
        if (!$est) throw new Exception("Establecimiento no indicado.");
        if (!preg_match('/^\d{2,3}$/', $codigo)) throw new Exception("El código del punto de emisión son 2 o 3 dígitos (ej. 01).");
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM puntos_emision WHERE establecimiento_id = ? AND codigo_punto = ? AND id <> ?");
        $stmt->execute([$est, $codigo, (int)$id]);
        if ($stmt->fetchColumn()) throw new Exception("Ese establecimiento ya tiene un punto de emisión $codigo.");
        if ($id) {
            $stmt = $pdo->prepare("SELECT codigo_punto, (SELECT COUNT(*) FROM cai_rangos WHERE punto_emision_id = ?) FROM puntos_emision WHERE id = ? AND establecimiento_id = ?");
            $stmt->execute([$id, $id, $est]);
            $act = $stmt->fetch(PDO::FETCH_NUM);
            if (!$act) throw new Exception("Punto de emisión no encontrado.");
            if ($act[0] !== $codigo && (int)$act[1] > 0) throw new Exception("Este punto ya tiene CAI asignados: no se puede cambiar su código.");
            $pdo->prepare("UPDATE puntos_emision SET codigo_punto = ?, descripcion = ?, departamento_id = ?, municipio_id = ? WHERE id = ?")->execute([$codigo, $desc ?: null, $dep, $mun, $id]);
            echo json_encode(['success' => true, 'message' => 'Punto de emisión actualizado.']);
        } else {
            $pdo->prepare("INSERT INTO puntos_emision (establecimiento_id, codigo_punto, descripcion, departamento_id, municipio_id) VALUES (?, ?, ?, ?, ?)")->execute([$est, $codigo, $desc ?: null, $dep, $mun]);
            echo json_encode(['success' => true, 'message' => 'Punto de emisión creado.', 'id' => (int)$pdo->lastInsertId()]);
        }
        exit;
    }

    if ($accion === 'eliminar_establecimiento') {
        if (!$id) throw new Exception("Establecimiento no indicado.");
        $usos = [];
        foreach (['facturas' => 'facturas', 'cai_rangos' => 'rangos CAI', 'pos_turnos' => 'turnos de caja', 'inv_existencias' => 'existencias', 'inv_movimientos' => 'movimientos de inventario'] as $t => $txt) {
            try { $st = $pdo->prepare("SELECT COUNT(*) FROM $t WHERE establecimiento_id = ?"); $st->execute([$id]); if ($n = (int)$st->fetchColumn()) $usos[] = "$n $txt"; }
            catch (PDOException $ignorar) {}   // módulo no instalado
        }
        if ($usos) throw new Exception("No se puede eliminar: tiene " . implode(', ', $usos) . ".");
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM puntos_emision WHERE establecimiento_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM usuario_establecimientos WHERE establecimiento_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM establecimientos WHERE establecimiento_id = ?")->execute([$id]);
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Establecimiento eliminado.']);
        exit;
    }

    if ($accion === 'eliminar_punto') {
        if (!$id) throw new Exception("Punto de emisión no indicado.");
        $st = $pdo->prepare("SELECT establecimiento_id, (SELECT COUNT(*) FROM cai_rangos WHERE punto_emision_id = p.id) cais,
                                    (SELECT COUNT(*) FROM puntos_emision p2 WHERE p2.establecimiento_id = p.establecimiento_id) total FROM puntos_emision p WHERE id = ?");
        $st->execute([$id]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) throw new Exception("Punto de emisión no encontrado.");
        if ((int)$p['cais'] > 0) throw new Exception("No se puede eliminar: tiene CAI asignados.");
        if ((int)$p['total'] <= 1) throw new Exception("Es el único punto de emisión del establecimiento: elimina el establecimiento o crea otro punto primero.");
        $pdo->prepare("DELETE FROM puntos_emision WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'Punto de emisión eliminado.']);
        exit;
    }

    throw new Exception("Acción no válida.");
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
