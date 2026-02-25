<?php
// clientes/naranjaymedia/includes/colaborador_actualizar.php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    $cid  = (int)(USUARIO_ROL === 'superadmin' ? ($_SESSION['cliente_seleccionado'] ?? 0) : CLIENTE_ID);
    $id   = filter_input(INPUT_POST, 'colaborador_id', FILTER_VALIDATE_INT);
    if (!$id) throw new Exception("Colaborador no identificado.");

    // Verificar pertenencia
    $sv = $pdo->prepare("SELECT id FROM colaboradores WHERE id=? AND cliente_id=?");
    $sv->execute([$id, $cid]);
    if (!$sv->fetch()) throw new Exception("Colaborador no encontrado o sin permiso.");

    // ── Dar de baja / reactivar ──────────────────────────────────────────────
    if (!empty($_POST['_cambiar_estado'])) {
        $activo = (int)!empty($_POST['activo']);
        $fecha_baja = $activo ? null : date('Y-m-d');
        $pdo->prepare("UPDATE colaboradores SET activo=?, fecha_baja=? WHERE id=? AND cliente_id=?")
            ->execute([$activo, $fecha_baja, $id, $cid]);
        $msg = $activo ? 'Colaborador reactivado.' : 'Colaborador dado de baja.';
        echo json_encode(['success' => true, 'message' => $msg]);
        exit;
    }

    // ── Actualización completa ───────────────────────────────────────────────
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

    if (!$nombre)        throw new Exception("El nombre es obligatorio.");
    if (!$apellido)      throw new Exception("El apellido es obligatorio.");
    if (!$puesto)        throw new Exception("El puesto es obligatorio.");
    if ($salario_base <= 0) throw new Exception("El salario base debe ser mayor a 0.");
    if (!$fecha_ingreso) throw new Exception("La fecha de ingreso es obligatoria.");
    if (!in_array($tipo_pago, ['mensual','quincenal'])) throw new Exception("Tipo de pago inválido.");

    if ($tipo_pago === 'quincenal') {
        if (!$dia_pago  || $dia_pago  < 1 || $dia_pago  > 31) throw new Exception("Ingresa el 1er día de pago.");
        if (!$dia_pago_2|| $dia_pago_2 < 1 || $dia_pago_2 > 31) throw new Exception("Ingresa el 2° día de pago.");
        if ($dia_pago >= $dia_pago_2) throw new Exception("El primer día debe ser menor al segundo.");
    } elseif ($tipo_pago === 'mensual') {
        if (!$dia_pago || $dia_pago < 1 || $dia_pago > 31) throw new Exception("Ingresa el día de pago.");
        $dia_pago_2 = null;
    }

    // DPI duplicado (excluir el mismo colaborador)
    if ($dpi) {
        $svDpi = $pdo->prepare("SELECT COUNT(*) FROM colaboradores WHERE dpi=? AND cliente_id=? AND activo=1 AND id!=?");
        $svDpi->execute([$dpi, $cid, $id]);
        if ($svDpi->fetchColumn() > 0) throw new Exception("Ya existe otro colaborador activo con ese DPI.");
    }

    $pdo->prepare("
        UPDATE colaboradores
        SET nombre=?, apellido=?, puesto=?, departamento=?, dpi=?, telefono=?, email=?,
            salario_base=?, tipo_pago=?, dia_pago=?, dia_pago_2=?,
            aplica_ihss=?, aplica_rap=?, categoria_gasto_id=?,
            fecha_ingreso=?, notas=?
        WHERE id=? AND cliente_id=?
    ")->execute([
        $nombre, $apellido, $puesto, $departamento, $dpi, $telefono, $email,
        $salario_base, $tipo_pago, $dia_pago, $dia_pago_2,
        $aplica_ihss, $aplica_rap, $cat_id,
        $fecha_ingreso, $notas,
        $id, $cid
    ]);

    echo json_encode(['success' => true, 'message' => "Colaborador $nombre $apellido actualizado correctamente."]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
