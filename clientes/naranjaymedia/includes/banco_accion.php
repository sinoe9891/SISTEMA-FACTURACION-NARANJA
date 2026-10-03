<?php
// clientes/naranjaymedia/includes/banco_accion.php
// Acciones del módulo de bancos (solo admin / superadmin). POST accion=…
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once '../../../includes/bancos.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception("Método no permitido.");
    if (!in_array(USUARIO_ROL, ['admin', 'superadmin'], true)) throw new Exception("Solo un administrador puede registrar operaciones bancarias.");
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
            $mensaje = $activa ? 'Cuenta activada.' : 'Cuenta desactivada.';
            break;

        case 'movimiento':
            $extra['id'] = bancoMovimientoManual($pdo, $cid, $uid, $_POST);
            $mensaje = 'Movimiento registrado.';
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
    http_response_code(400);
    $msg = $e instanceof PDOException ? 'Error de base de datos al guardar la operación.' : $e->getMessage();
    if ($e instanceof PDOException) error_log('banco_accion.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
}
