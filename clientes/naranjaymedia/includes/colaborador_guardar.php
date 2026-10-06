<?php
// clientes/naranjaymedia/includes/colaborador_guardar.php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    $cid = (int)(USUARIO_ROL === 'superadmin' ? ($_SESSION['cliente_seleccionado'] ?? 0) : CLIENTE_ID);
    if (!$cid) throw new Exception("Cliente no identificado.");

    $nombre       = trim($_POST['nombre']       ?? '');
    $apellido     = trim($_POST['apellido']      ?? '');
    $puesto       = trim($_POST['puesto']        ?? '');
    $departamento = trim($_POST['departamento']  ?? '') ?: null;
    $dpi          = trim($_POST['dpi']           ?? '') ?: null;
    $telefono     = trim($_POST['telefono']      ?? '') ?: null;
    $email        = trim($_POST['email']         ?? '') ?: null;
    $salario_base = (float)($_POST['salario_base'] ?? 0);
    $tipo_pago    = trim($_POST['tipo_pago']     ?? 'quincenal');
    $dia_pago     = filter_input(INPUT_POST, 'dia_pago',   FILTER_VALIDATE_INT) ?: null;
    $dia_pago_2   = filter_input(INPUT_POST, 'dia_pago_2', FILTER_VALIDATE_INT) ?: null;
    $aplica_ihss  = !empty($_POST['aplica_ihss'])  ? 1 : 0;
    $aplica_rap   = !empty($_POST['aplica_rap'])   ? 1 : 0;
    $cat_id       = filter_input(INPUT_POST, 'categoria_gasto_id', FILTER_VALIDATE_INT) ?: null;
    $fecha_ingreso= trim($_POST['fecha_ingreso'] ?? '');
    $notas        = trim($_POST['notas']         ?? '') ?: null;

    // Validaciones
    if (!$nombre)        throw new Exception("El nombre es obligatorio.");
    if (!$apellido)      throw new Exception("El apellido es obligatorio.");
    if (!$puesto)        throw new Exception("El puesto es obligatorio.");
    if ($salario_base <= 0) throw new Exception("El salario base debe ser mayor a 0.");
    if (!$fecha_ingreso) throw new Exception("La fecha de ingreso es obligatoria.");
    if (!in_array($tipo_pago, ['mensual','quincenal'])) throw new Exception("Tipo de pago inválido.");

    if ($tipo_pago === 'quincenal') {
        if (!$dia_pago  || $dia_pago  < 1 || $dia_pago  > 31) throw new Exception("Ingresa el 1er día de pago (1-31).");
        if (!$dia_pago_2|| $dia_pago_2 < 1 || $dia_pago_2 > 31) throw new Exception("Ingresa el 2° día de pago (1-31).");
        if ($dia_pago >= $dia_pago_2) throw new Exception("El primer día debe ser menor al segundo.");
    } elseif ($tipo_pago === 'mensual') {
        if (!$dia_pago || $dia_pago < 1 || $dia_pago > 31) throw new Exception("Ingresa el día de pago (1-31).");
        $dia_pago_2 = null;
    }

    // Verificar DPI duplicado (si se ingresó)
    if ($dpi) {
        $svDpi = $pdo->prepare("SELECT COUNT(*) FROM colaboradores WHERE dpi=? AND cliente_id=? AND activo=1");
        $svDpi->execute([$dpi, $cid]);
        if ($svDpi->fetchColumn() > 0) throw new Exception("Ya existe un colaborador activo con ese DPI.");
    }


    // Género (opcional; solo si la columna existe — migración 2026-10-04_colaborador_genero.sql)
    $genero = in_array($_POST['genero'] ?? '', ['masculino', 'femenino'], true) ? $_POST['genero'] : null;
    $hayGenero = (bool)$pdo->query("SHOW COLUMNS FROM colaboradores LIKE 'genero'")->fetchColumn();
    $stmt = $pdo->prepare("
        INSERT INTO colaboradores
            (cliente_id, nombre, apellido, puesto, departamento, dpi, telefono, email,
             salario_base, tipo_pago, dia_pago, dia_pago_2,
             aplica_ihss, aplica_rap, categoria_gasto_id,
             fecha_ingreso, notas, activo, usuario_id)
        VALUES (?,?,?,?,?,?,?,?, ?,?,?,?, ?,?,?, ?,?,1,?)
    ");
    $stmt->execute([
        $cid, $nombre, $apellido, $puesto, $departamento, $dpi, $telefono, $email,
        $salario_base, $tipo_pago, $dia_pago, $dia_pago_2,
        $aplica_ihss, $aplica_rap, $cat_id,
        $fecha_ingreso, $notas, USUARIO_ID
    ]);

    $nuevoId = (int)$pdo->lastInsertId();
    if ($hayGenero) $pdo->prepare("UPDATE colaboradores SET genero = ? WHERE id = ? AND cliente_id = ?")->execute([$genero, $nuevoId, $cid]);
    // Concepto de pago del boucher (opcional; migración 2026-10-06_firmantes_concepto.sql)
    if ($pdo->query("SHOW COLUMNS FROM colaboradores LIKE 'concepto_pago'")->fetchColumn())
        $pdo->prepare("UPDATE colaboradores SET concepto_pago = ? WHERE id = ? AND cliente_id = ?")->execute([mb_substr(trim((string)($_POST['concepto_pago'] ?? '')), 0, 200) ?: null, $nuevoId, $cid]);
    // Cuenta bancaria del colaborador (migración 2026-10-04_colaborador_cuenta.sql)
    if ($pdo->query("SHOW COLUMNS FROM colaboradores LIKE 'numero_cuenta'")->fetchColumn()) {
        $tipoCta = in_array($_POST['tipo_cuenta'] ?? '', ['ahorro', 'cheques'], true) ? $_POST['tipo_cuenta'] : null;
        $pdo->prepare("UPDATE colaboradores SET banco = ?, tipo_cuenta = ?, numero_cuenta = ? WHERE id = ? AND cliente_id = ?")
            ->execute([mb_substr(trim((string)($_POST['banco'] ?? '')), 0, 100) ?: null, $tipoCta,
                mb_substr(preg_replace('/[^0-9A-Za-z-]/', '', (string)($_POST['numero_cuenta'] ?? '')), 0, 40) ?: null, $nuevoId, $cid]);
    }


    echo json_encode([
        'success' => true,
        'id'      => $nuevoId,
        'message' => "Colaborador $nombre $apellido registrado correctamente."
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
