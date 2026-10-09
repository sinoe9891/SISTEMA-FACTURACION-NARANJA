<?php
// clientes/naranjaymedia/includes/banco_accion.php
// Acciones del módulo de bancos (solo admin / superadmin). POST accion=…
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once '../../../includes/bancos.php';
require_once __DIR__ . '/_gasto_adjuntos.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    // Nómina y gastos registra movimientos, transferencias y cheques; las cuentas y las anulaciones son de administradores
    $accionPedida = $_POST['accion'] ?? '';
    $soloAdmin = ['cuenta_guardar', 'cuenta_estado', 'predeterminar', 'anular_movimiento', 'cheque_anular', 'tasa_clave'];
    if ($accionPedida === 'tasa_clave' || str_starts_with($accionPedida, 'tasa_')) {
        if (!permisoPuede($pdo, 'configuracion_tasa')) throw new Exception("Tu rol no tiene acceso a Tasa del dólar.");
    } elseif (!in_array(USUARIO_ROL, ['admin', 'superadmin'], true) && (USUARIO_ROL !== 'nomina' || in_array($accionPedida, $soloAdmin, true)))
        throw new Exception("Solo un administrador puede " . (in_array($accionPedida, $soloAdmin, true) ? 'administrar las cuentas o anular operaciones' : 'registrar operaciones bancarias') . ".");
    if (!bancosDisponible($pdo)) throw new Exception("El módulo de bancos no está instalado (falta sql/migraciones/2026-10-03_bancos.sql).");
    $cid = cliente_actual();
    if (!$cid) throw new Exception("Empresa no identificada.");
    $uid = (int)USUARIO_ID;
    $accion = $_POST['accion'] ?? '';
    $mensaje = 'Listo.';
    $extra = [];

    $pdo->beginTransaction();
    switch ($accion) {
        case 'cuenta_guardar':
            $id     = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: null;
            $banco  = mb_substr(trim((string)($_POST['banco'] ?? '')), 0, 100);
            $numero = mb_substr(trim((string)($_POST['numero'] ?? '')), 0, 40);
            $tipo   = $_POST['tipo'] ?? '';
            $moneda = $_POST['moneda'] ?? '';
            $titular = mb_substr(trim((string)($_POST['titular'] ?? '')), 0, 150) ?: null;
            $notas  = mb_substr(trim((string)($_POST['notas'] ?? '')), 0, 255) ?: null;
            if ($banco === '' || $numero === '') throw new Exception("Banco y número de cuenta son obligatorios.");
            if (!in_array($tipo, ['ahorro', 'cheques'], true)) throw new Exception("Tipo de cuenta inválido.");
            if (!in_array($moneda, ['HNL', 'USD'], true)) throw new Exception("Moneda inválida.");
            $saldoIni = is_numeric($_POST['saldo_inicial'] ?? null) ? round((float)$_POST['saldo_inicial'], 2) : 0.0;
            $fechaIni = bancoFecha((string)($_POST['fecha_saldo_inicial'] ?? date('Y-m-d')));
            $dup = $pdo->prepare("SELECT COUNT(*) FROM cuentas_bancarias WHERE cliente_id = ? AND banco = ? AND numero = ? AND id <> ?");
            $dup->execute([$cid, $banco, $numero, (int)$id]);
            if ($dup->fetchColumn()) throw new Exception("Ya existe la cuenta $banco $numero.");
            if ($id) {
                $actual = bancoCuenta($pdo, $cid, $id);
                $movs = $pdo->prepare("SELECT COUNT(*) FROM movimientos_bancarios WHERE cuenta_id = ?");
                $movs->execute([$id]);
                // Con movimientos, la moneda ya no se puede cambiar (los montos quedarían en otra moneda)
                if ($movs->fetchColumn() && $actual['moneda'] !== $moneda) throw new Exception("La cuenta ya tiene movimientos: no se puede cambiar su moneda.");
                $pdo->prepare("UPDATE cuentas_bancarias SET banco=?, numero=?, tipo=?, moneda=?, titular=?, saldo_inicial=?, fecha_saldo_inicial=?, notas=? WHERE id=? AND cliente_id=?")
                    ->execute([$banco, $numero, $tipo, $moneda, $titular, $saldoIni, $fechaIni, $notas, $id, $cid]);
                $mensaje = 'Cuenta actualizada.';
            } else {
                $pdo->prepare("INSERT INTO cuentas_bancarias (cliente_id, banco, tipo, numero, titular, moneda, saldo_inicial, fecha_saldo_inicial, notas) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$cid, $banco, $tipo, $numero, $titular, $moneda, $saldoIni, $fechaIni, $notas]);
                $extra['id'] = (int)$pdo->lastInsertId();
                $mensaje = 'Cuenta creada.';
            }
            break;

        case 'cuenta_estado':
            $c = bancoCuenta($pdo, $cid, (int)($_POST['id'] ?? 0));
            $activa = (int)!((int)$c['activa']);
            $pdo->prepare("UPDATE cuentas_bancarias SET activa = ? WHERE id = ?")->execute([$activa, $c['id']]);
            // Una cuenta inactiva no puede seguir siendo la predeterminada
            if (!$activa && $pdo->query("SHOW COLUMNS FROM cuentas_bancarias LIKE 'predeterminada'")->fetchColumn())
                $pdo->prepare("UPDATE cuentas_bancarias SET predeterminada = 0 WHERE id = ?")->execute([$c['id']]);
            $mensaje = $activa ? 'Cuenta activada.' : 'Cuenta desactivada.';
            break;

        case 'predeterminar':
            $cuenta = bancoCuenta($pdo, $cid, (int)($_POST['id'] ?? 0), true);
            if (!(int)$cuenta['activa']) throw new Exception("Activa la cuenta antes de hacerla predeterminada.");
            $col = $pdo->query("SHOW COLUMNS FROM cuentas_bancarias LIKE 'predeterminada'")->fetchColumn();
            if (!$col) throw new Exception("Falta la migración sql/migraciones/2026-10-03_cuenta_predeterminada.sql.");
            $pdo->prepare("UPDATE cuentas_bancarias SET predeterminada = (id = ?) WHERE cliente_id = ?")->execute([$cuenta['id'], $cid]);
            $mensaje = "Cuenta {$cuenta['banco']} {$cuenta['numero']} predeterminada.";
            break;

        case 'tasa_actualizar':
            require_once '../../../includes/tasa_cambio.php';
            $t = tasaActualizar($pdo, $cid);
            $mensaje = $t['fuente'] === 'BCH' ? "Tasa del BCH: compra L {$t['compra']} · venta L {$t['venta']}." : "Tasa de referencia: L {$t['referencia']} por dólar." . (isset($t['aviso']) ? ' ' . $t['aviso'] : '');
            break;

        case 'tasa_clave':
            require_once '../../../includes/tasa_cambio.php';
            tasaGuardarClaveBch($pdo, $cid, (string)($_POST['clave'] ?? ''));
            $mensaje = trim((string)($_POST['clave'] ?? '')) === '' ? 'Clave del BCH quitada: se usará la tasa de referencia.' : 'Clave del BCH guardada.';
            break;

        case 'movimiento':
            // Comprobante opcional (JPG, PNG, WEBP o PDF) y, en las salidas, registrarlo también como gasto pagado
            [$archivo, $archivoNombre] = guardarAdjuntoGasto($_FILES['comprobante'] ?? null, $cid) ?? [null, null];
            $extra['id'] = bancoMovimientoManual($pdo, $cid, $uid, $_POST);
            $mov = $pdo->query("SELECT * FROM movimientos_bancarios WHERE id = " . (int)$extra['id'])->fetch(PDO::FETCH_ASSOC);
            if ($archivo) $pdo->prepare("UPDATE movimientos_bancarios SET archivo_adjunto = ?, archivo_nombre = ? WHERE id = ?")->execute([$archivo, $archivoNombre, $mov['id']]);
            $mensaje = 'Movimiento registrado.';
            if (!empty($_POST['como_gasto'])) {
                if ($mov['sentido'] !== 'salida') throw new Exception("Solo una salida (retiro, comisión o ajuste que resta) se registra como gasto.");
                $cuentaMov = bancoCuenta($pdo, $cid, (int)$mov['cuenta_id']);
                if ($cuentaMov['moneda'] !== 'HNL') throw new Exception("Los gastos son en lempiras: elige una cuenta en lempiras.");
                $cat = filter_input(INPUT_POST, 'categoria_id', FILTER_VALIDATE_INT) ?: null;
                if (!$cat) throw new Exception("Elige la categoría del gasto.");
                $st = $pdo->prepare("SELECT COUNT(*) FROM categorias_gastos WHERE id = ? AND cliente_id = ?");
                $st->execute([$cat, $cid]);
                if (!$st->fetchColumn()) throw new Exception("Categoría no válida.");
                $proveedor = mb_substr(trim((string)($_POST['proveedor'] ?? '')), 0, 150) ?: null;
                $pdo->prepare("INSERT INTO gastos (cliente_id, categoria_id, descripcion, monto, fecha, frecuencia, tipo, metodo_pago, proveedor, factura_ref, estado, archivo_adjunto, archivo_nombre, usuario_id)
                               VALUES (?, ?, ?, ?, ?, 'unico', 'variable', 'transferencia', ?, ?, 'pagado', ?, ?, ?)")
                    ->execute([$cid, $cat, $mov['descripcion'], $mov['monto'], $mov['fecha'], $proveedor, $mov['referencia'], $archivo, $archivoNombre, $uid]);
                $gastoId = (int)$pdo->lastInsertId();
                $pdo->prepare("UPDATE movimientos_bancarios SET gasto_id = ? WHERE id = ?")->execute([$gastoId, $mov['id']]);
                $extra['gasto_id'] = $gastoId;
                $mensaje = 'Movimiento registrado y agregado a Gastos.';
            }
            break;

        case 'transferencia':
            [$mo, $md] = bancoTransferir($pdo, $cid, $uid, $_POST);
            $extra += ['monto_origen' => $mo, 'monto_destino' => $md];
            $mensaje = 'Transferencia registrada.';
            break;

        case 'anular_movimiento':
            bancoAnularMovimiento($pdo, $cid, (int)($_POST['id'] ?? 0));
            $mensaje = 'Movimiento anulado.';
            break;

        case 'conciliar':
            $stmt = $pdo->prepare("UPDATE movimientos_bancarios SET conciliado = ? WHERE id = ? AND cliente_id = ? AND anulado = 0");
            $stmt->execute([!empty($_POST['conciliado']) ? 1 : 0, (int)($_POST['id'] ?? 0), $cid]);
            if (!$stmt->rowCount()) {
                $chk = $pdo->prepare("SELECT COUNT(*) FROM movimientos_bancarios WHERE id = ? AND cliente_id = ? AND anulado = 0");
                $chk->execute([(int)($_POST['id'] ?? 0), $cid]);
                if (!$chk->fetchColumn()) throw new Exception("Movimiento no encontrado.");
            }
            $mensaje = !empty($_POST['conciliado']) ? 'Movimiento conciliado.' : 'Conciliación quitada.';
            break;

        case 'cheque_emitir':
            $extra['id'] = bancoEmitirCheque($pdo, $cid, $uid, $_POST);
            $mensaje = 'Cheque registrado.';
            break;

        case 'cheque_cobrar':
            bancoCobrarCheque($pdo, $cid, $uid, (int)($_POST['id'] ?? 0), (string)($_POST['fecha'] ?? date('Y-m-d')));
            $mensaje = 'Cheque marcado como cobrado.';
            break;

        case 'cheque_anular':
            bancoAnularCheque($pdo, $cid, (int)($_POST['id'] ?? 0), (string)($_POST['motivo'] ?? ''));
            $mensaje = 'Cheque anulado.';
            break;

        default:
            throw new Exception("Acción no válida.");
    }
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => $mensaje] + $extra, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if (!empty($archivo)) borrarAdjuntoGastoSiHuerfano($pdo, $archivo);   // el comprobante subido no quedó en ningún registro
    http_response_code(400);
    $msg = $e instanceof PDOException ? 'Error de base de datos al guardar la operación.' : $e->getMessage();
    if ($e instanceof PDOException) error_log('banco_accion.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
}
