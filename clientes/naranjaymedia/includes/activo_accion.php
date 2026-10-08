<?php
// clientes/naranjaymedia/includes/activo_accion.php — Activos fijos y préstamos recibidos (admin / superadmin).
//   POST accion=activo_guardar   id?, nombre, categoria, fecha_compra, costo, valor_residual, vida_util_meses, origen, proveedor, notas,
//                                registrar_compra (1 = crea el gasto «Compra de activo» pagado), cuenta_id, categoria_id
//   POST accion=activo_baja      id, fecha_baja
//   POST accion=activo_eliminar  id
//   POST accion=prestamo_guardar acreedor, descripcion, fecha, monto, num_cuotas, tasa_anual, fecha_primera_cuota, cuenta_id, activo_id,
//                                categoria_id, gasto_grupo_id (cuotas ya registradas en Gastos) | generar_cuotas=1
//   POST accion=prestamo_anular  id
//   POST accion=depreciacion     activa (1 | 0): calcular o no la depreciación de los activos
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once '../../../includes/activos.php';
require_once '../../../includes/bancos.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    if (!activosDisponible($pdo)) throw new Exception("Falta instalar sql/migraciones/2026-10-08_activos_prestamos.sql.");
    if (!in_array(USUARIO_ROL, ['admin', 'superadmin'], true)) throw new Exception("Solo un administrador puede administrar activos y préstamos.");
    $cid = (int)cliente_actual();
    if (!$cid) throw new Exception("Empresa no identificada.");
    $uid = (int)USUARIO_ID;
    $accion = $_POST['accion'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $fechaOk = fn($f) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f) && strtotime((string)$f);
    $num = fn($k) => round((float)str_replace(',', '', (string)($_POST[$k] ?? 0)), 2);
    $catValida = function (?int $cat) use ($pdo, $cid): ?int {
        if (!$cat) return null;
        $st = $pdo->prepare("SELECT COUNT(*) FROM categorias_gastos WHERE id = ? AND cliente_id = ?");
        $st->execute([$cat, $cid]);
        if (!$st->fetchColumn()) throw new Exception("Categoría no válida.");
        return $cat;
    };
    $extra = [];

    $pdo->beginTransaction();
    switch ($accion) {
        case 'depreciacion':
            // Interruptor: la depreciación se calcula al vuelo, así que al volver a encenderla todo se recalcula
            $on = !empty($_POST['activa']) ? 1 : 0;
            $pdo->prepare("UPDATE clientes_saas SET depreciacion_activa = ? WHERE id = ?")->execute([$on, $cid]);
            $msg = $on ? 'Depreciación activada: se recalculó en todos los reportes.' : 'Depreciación desactivada: ya no se resta en los reportes.';
            break;
        case 'activo_guardar':
            $nombre = mb_substr(trim((string)($_POST['nombre'] ?? '')), 0, 200);
            $categoria = isset(ACTIVO_CATEGORIAS[$_POST['categoria'] ?? '']) ? $_POST['categoria'] : 'otro';
            $fecha = (string)($_POST['fecha_compra'] ?? '');
            $costo = $num('costo');
            $residual = $num('valor_residual');
            $vida = (int)($_POST['vida_util_meses'] ?? 0);
            $origen = in_array($_POST['origen'] ?? '', ['compra', 'donacion', 'prestamo', 'mixto'], true) ? $_POST['origen'] : 'compra';
            if ($nombre === '') throw new Exception("Escribe el nombre del activo.");
            if (!$fechaOk($fecha)) throw new Exception("Fecha de compra inválida.");
            if ($costo <= 0) throw new Exception("El costo debe ser mayor a 0.");
            if ($residual < 0 || $residual >= $costo) throw new Exception("El valor residual debe ser menor que el costo.");
            if ($vida < 1 || $vida > 600) throw new Exception("La vida útil debe estar entre 1 y 600 meses.");
            $datos = [$nombre, $categoria, $fecha, $costo, $residual, $vida, $origen, mb_substr(trim((string)($_POST['proveedor'] ?? '')), 0, 150) ?: null, trim((string)($_POST['notas'] ?? '')) ?: null];
            if ($id) {
                $st = $pdo->prepare("UPDATE activos_fijos SET nombre=?, categoria=?, fecha_compra=?, costo=?, valor_residual=?, vida_util_meses=?, origen=?, proveedor=?, notas=? WHERE id=? AND cliente_id=?");
                $st->execute([...$datos, $id, $cid]);
                $msg = 'Activo actualizado.';
            } else {
                $pdo->prepare("INSERT INTO activos_fijos (nombre, categoria, fecha_compra, costo, valor_residual, vida_util_meses, origen, proveedor, notas, cliente_id, usuario_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([...$datos, $cid, $uid]);
                $id = (int)$pdo->lastInsertId();
                $msg = 'Activo registrado.';
                // Compra pagada: queda en Gastos como «Compra de activo» (no resta en resultados; se reconoce con la depreciación)
                if (!empty($_POST['registrar_compra'])) {
                    $cat = $catValida(filter_input(INPUT_POST, 'categoria_id', FILTER_VALIDATE_INT) ?: null);
                    $pdo->prepare("INSERT INTO gastos (cliente_id, categoria_id, descripcion, monto, fecha, frecuencia, tipo, naturaleza, activo_id, metodo_pago, proveedor, estado, usuario_id)
                                   VALUES (?, ?, ?, ?, ?, 'unico', 'extraordinario', 'activo', ?, 'transferencia', ?, 'pagado', ?)")
                        ->execute([$cid, $cat, 'Compra de activo: ' . $nombre, $costo, $fecha, $id, $datos[7], $uid]);
                    $gid = (int)$pdo->lastInsertId();
                    if ($cta = filter_input(INPUT_POST, 'cuenta_id', FILTER_VALIDATE_INT)) bancoSalidaPago($pdo, $cid, $cta, $fecha, $costo, 'Compra de activo: ' . $nombre, null, $uid, $gid);
                    $msg .= ' La compra quedó en Gastos (no resta en resultados: se reconoce con la depreciación).';
                }
            }
            $extra['id'] = $id;
            break;

        case 'activo_baja':
            $f = (string)($_POST['fecha_baja'] ?? '');
            if (!$fechaOk($f)) throw new Exception("Fecha de baja inválida.");
            $st = $pdo->prepare("UPDATE activos_fijos SET fecha_baja = ? WHERE id = ? AND cliente_id = ?");
            $st->execute([$f, $id, $cid]);
            if (!$st->rowCount()) throw new Exception("Activo no encontrado.");
            $msg = 'Activo dado de baja: deja de depreciarse y sale del Balance.';
            break;

        case 'activo_eliminar':
            $st = $pdo->prepare("SELECT COUNT(*) FROM gastos WHERE activo_id = ? AND cliente_id = ? AND estado <> 'anulado'");
            $st->execute([$id, $cid]);
            if ($st->fetchColumn()) throw new Exception("El activo tiene su compra registrada en Gastos: anula ese gasto primero o dalo de baja.");
            $pdo->prepare("UPDATE prestamos_recibidos SET activo_id = NULL WHERE activo_id = ? AND cliente_id = ?")->execute([$id, $cid]);
            $st = $pdo->prepare("DELETE FROM activos_fijos WHERE id = ? AND cliente_id = ?");
            $st->execute([$id, $cid]);
            if (!$st->rowCount()) throw new Exception("Activo no encontrado.");
            $msg = 'Activo eliminado.';
            break;

        case 'prestamo_guardar':
            $acreedor = mb_substr(trim((string)($_POST['acreedor'] ?? '')), 0, 150);
            $fecha = (string)($_POST['fecha'] ?? '');
            $monto = $num('monto');
            $cuotas = max(1, min(360, (int)($_POST['num_cuotas'] ?? 1)));
            $tasa = max(0, min(200, (float)($_POST['tasa_anual'] ?? 0)));
            if ($acreedor === '') throw new Exception("Indica quién prestó el dinero.");
            if (!$fechaOk($fecha)) throw new Exception("Fecha inválida.");
            if ($monto <= 0) throw new Exception("El monto debe ser mayor a 0.");
            $activo = (int)($_POST['activo_id'] ?? 0) ?: null;
            if ($activo) { $st = $pdo->prepare("SELECT COUNT(*) FROM activos_fijos WHERE id = ? AND cliente_id = ?"); $st->execute([$activo, $cid]); if (!$st->fetchColumn()) throw new Exception("Activo no válido."); }
            $cat = $catValida(filter_input(INPUT_POST, 'categoria_id', FILTER_VALIDATE_INT) ?: null);
            $grupo = (int)($_POST['gasto_grupo_id'] ?? 0) ?: null;
            $pdo->prepare("INSERT INTO prestamos_recibidos (cliente_id, acreedor, descripcion, monto, fecha, tasa_anual, num_cuotas, activo_id, gasto_grupo_id, notas, usuario_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$cid, $acreedor, mb_substr(trim((string)($_POST['descripcion'] ?? '')), 0, 255) ?: null, $monto, $fecha, $tasa, $cuotas, $activo, $grupo, trim((string)($_POST['notas'] ?? '')) ?: null, $uid]);
            $pid = (int)$pdo->lastInsertId();
            $msg = 'Préstamo registrado.';
            // El dinero entró a una cuenta (si no fue en especie)
            if ($cta = filter_input(INPUT_POST, 'cuenta_id', FILTER_VALIDATE_INT)) {
                $cuenta = bancoCuenta($pdo, $cid, $cta, true);
                if ($cuenta['moneda'] !== 'HNL') throw new Exception("Elige una cuenta en lempiras.");
                $mov = null;
                if (empty($cuenta['fecha_saldo_inicial']) || $fecha >= $cuenta['fecha_saldo_inicial'])
                    $mov = bancoInsertarMovimiento($pdo, $cid, $cta, ['fecha' => $fecha, 'sentido' => 'entrada', 'tipo' => 'prestamo_recibido', 'monto' => $monto, 'descripcion' => 'Préstamo recibido de ' . $acreedor, 'usuario_id' => $uid]);
                $pdo->prepare("UPDATE prestamos_recibidos SET cuenta_id = ?, movimiento_id = ? WHERE id = ?")->execute([$cta, $mov, $pid]);
            }
            if ($grupo) {
                // Cuotas que ya estaban en Gastos (p. ej. una serie mensual): pasan a ser abonos a capital del préstamo
                $st = $pdo->prepare("UPDATE gastos SET naturaleza = 'capital', prestamo_id = ? WHERE cliente_id = ? AND (gasto_grupo_id = ? OR id = ?) AND estado <> 'anulado'");
                $st->execute([$pid, $cid, $grupo, $grupo]);
                $msg .= ' ' . $st->rowCount() . ' cuota(s) ya registradas pasaron a ser abonos a capital (no restan en resultados).';
            } elseif (!empty($_POST['generar_cuotas'])) {
                // Cuotas nuevas en Gastos (pendientes): sistema francés si hay tasa; capital e intereses van por separado
                $f1 = (string)($_POST['fecha_primera_cuota'] ?? '');
                if (!$fechaOk($f1)) throw new Exception("Fecha de la primera cuota inválida.");
                $i = $tasa / 100 / 12;
                $cuota = $i > 0 ? round($monto * $i / (1 - pow(1 + $i, -$cuotas)), 2) : round($monto / $cuotas, 2);
                $saldo = $monto;
                $ins = $pdo->prepare("INSERT INTO gastos (cliente_id, categoria_id, descripcion, monto, fecha, frecuencia, dia_pago, gasto_grupo_id, tipo, naturaleza, prestamo_id, metodo_pago, proveedor, estado, usuario_id)
                                      VALUES (?, ?, ?, ?, ?, 'unico', ?, ?, 'fijo', ?, ?, 'transferencia', ?, 'pendiente', ?)");
                $base = new DateTime($f1);
                $dia = (int)$base->format('j');
                $primerGasto = null;
                for ($k = 1; $k <= $cuotas; $k++) {
                    $f = (clone $base)->modify('first day of this month')->modify('+' . ($k - 1) . ' month');
                    $f->setDate((int)$f->format('Y'), (int)$f->format('n'), min($dia, (int)$f->format('t')));
                    $interes = round($saldo * $i, 2);
                    $capital = $k === $cuotas ? round($saldo, 2) : round($cuota - $interes, 2);
                    $saldo = round($saldo - $capital, 2);
                    $ins->execute([$cid, $cat, "Cuota $k/$cuotas préstamo $acreedor (capital)", $capital, $f->format('Y-m-d'), $dia, $primerGasto, 'capital', $pid, $acreedor, $uid]);
                    $primerGasto ??= (int)$pdo->lastInsertId();
                    if ($interes > 0) $ins->execute([$cid, $cat, "Cuota $k/$cuotas préstamo $acreedor (intereses)", $interes, $f->format('Y-m-d'), $dia, $primerGasto, 'gasto', $pid, $acreedor, $uid]);
                }
                $pdo->prepare("UPDATE gastos SET gasto_grupo_id = ? WHERE id = ?")->execute([$primerGasto, $primerGasto]);
                $pdo->prepare("UPDATE prestamos_recibidos SET gasto_grupo_id = ? WHERE id = ?")->execute([$primerGasto, $pid]);
                $msg .= " Se programaron $cuotas cuota(s) en Cuentas por pagar.";
            }
            $extra['id'] = $pid;
            break;

        case 'prestamo_anular':
            $st = $pdo->prepare("SELECT * FROM prestamos_recibidos WHERE id = ? AND cliente_id = ? AND estado <> 'anulado'");
            $st->execute([$id, $cid]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) throw new Exception("Préstamo no encontrado.");
            // Las cuotas ya registradas vuelven a ser gastos normales (no se borran) y las pendientes generadas se anulan
            $pdo->prepare("UPDATE gastos SET estado = 'anulado' WHERE prestamo_id = ? AND cliente_id = ? AND estado = 'pendiente' AND descripcion LIKE 'Cuota %préstamo%'")->execute([$id, $cid]);
            $pdo->prepare("UPDATE gastos SET naturaleza = 'gasto', prestamo_id = NULL WHERE prestamo_id = ? AND cliente_id = ?")->execute([$id, $cid]);
            if ($p['movimiento_id']) $pdo->prepare("UPDATE movimientos_bancarios SET anulado = 1 WHERE id = ? AND conciliado = 0")->execute([$p['movimiento_id']]);
            $pdo->prepare("UPDATE prestamos_recibidos SET estado = 'anulado' WHERE id = ?")->execute([$id]);
            $msg = 'Préstamo anulado.';
            break;

        default:
            throw new Exception("Acción no válida.");
    }
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => $msg] + $extra, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    if ($e instanceof PDOException) error_log('activo_accion.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e instanceof PDOException ? 'Error de base de datos.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
