<?php
// clientes/naranjaymedia/includes/cliente_contactos.php — Contactos adicionales de un cliente.
//   GET  ?receptor_id=X                          → lista + programas y contratos del cliente (JSON)
// Cada persona se registra una vez y se marca a qué cobros va en copia: todos (sin alcance) o uno o varios
// programas (p. ej. PROGRESE) y/o contratos (tabla clientes_factura_contactos_alcance).
//   POST accion=guardar (id opcional, alcance[] = p:<id> | k:<id>) | eliminar  → admin/facturador
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
require_once '../../../includes/programas.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if (!$pdo->query("SHOW TABLES LIKE 'clientes_factura_contactos'")->fetchColumn()) throw new Exception("Falta instalar sql/migraciones/2026-10-04_cliente_contactos.sql.");
    $conAlcance = (bool)$pdo->query("SHOW TABLES LIKE 'clientes_factura_contactos_alcance'")->fetchColumn();
    $cid = cliente_actual();
    if (!$cid) throw new Exception("Empresa no identificada.");
    $rid = (int)($_REQUEST['receptor_id'] ?? 0);
    $st = $pdo->prepare("SELECT id FROM clientes_factura WHERE id = ? AND cliente_id = ?");
    $st->execute([$rid, $cid]);
    if (!$st->fetchColumn()) throw new Exception("Cliente no encontrado.");

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!in_array(USUARIO_ROL, ['admin', 'superadmin', 'facturador'], true)) throw new Exception("No tienes permiso para editar contactos.");
        $id = (int)($_POST['id'] ?? 0);
        if (($_POST['accion'] ?? '') === 'eliminar') {
            $st = $pdo->prepare("DELETE FROM clientes_factura_contactos WHERE id = ? AND cliente_id = ? AND receptor_id = ?");
            $st->execute([$id, $cid, $rid]);
            if ($st->rowCount() && $conAlcance) $pdo->prepare("DELETE FROM clientes_factura_contactos_alcance WHERE contacto_id = ?")->execute([$id]);
        } else {
            $nombre = mb_substr(trim((string)($_POST['nombre'] ?? '')), 0, 120);
            $email = trim((string)($_POST['email'] ?? ''));
            if ($nombre === '') throw new Exception("Escribe el nombre del contacto.");
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception("Correo inválido: $email");
            // Alcance: programas (p:) y contratos (k:) del cliente; vacío = todos los cobros
            $alcance = [];
            foreach (array_unique((array)($_POST['alcance'] ?? [])) as $a) {
                if (!preg_match('/^([pk]):(\d+)$/', (string)$a, $m)) continue;
                if ($m[1] === 'p') {
                    $p = programaObtener($pdo, (int)$cid, (int)$m[2]);
                    if (!$p || (int)$p['receptor_id'] !== $rid) throw new Exception("El programa no es de este cliente.");
                    $alcance[] = [(int)$m[2], null];
                } else {
                    $st = $pdo->prepare("SELECT id FROM contratos WHERE id = ? AND cliente_id = ? AND receptor_id = ?");
                    $st->execute([(int)$m[2], $cid, $rid]);
                    if (!$st->fetchColumn()) throw new Exception("El contrato no pertenece a este cliente.");
                    $alcance[] = [null, (int)$m[2]];
                }
            }
            if (!$conAlcance && count($alcance) > 1) throw new Exception("Falta instalar sql/migraciones/2026-10-26_contactos_alcance.sql.");
            // Mismo correo ya registrado en este cliente: se avisa en vez de duplicar a la persona
            if ($email !== '') {
                $st = $pdo->prepare("SELECT nombre FROM clientes_factura_contactos WHERE cliente_id = ? AND receptor_id = ? AND LOWER(email) = LOWER(?) AND id <> ?");
                $st->execute([$cid, $rid, $email, $id]);
                if ($otro = $st->fetchColumn()) throw new Exception("$otro ya está registrado con ese correo: edítalo y márcale también este programa o contrato.");
            }
            $contratoUnico = !$conAlcance && $alcance ? $alcance[0][1] : null;
            $vals = [$nombre, mb_substr(trim((string)($_POST['cargo'] ?? '')), 0, 100) ?: null, $email ?: null,
                mb_substr(trim((string)($_POST['telefono'] ?? '')), 0, 40) ?: null, empty($_POST['copiar_cobros']) ? 0 : 1, $contratoUnico];
            $pdo->beginTransaction();
            if ($id) {
                $st = $pdo->prepare("UPDATE clientes_factura_contactos SET nombre = ?, cargo = ?, email = ?, telefono = ?, copiar_cobros = ?, contrato_id = ? WHERE id = ? AND cliente_id = ? AND receptor_id = ?");
                $st->execute([...$vals, $id, $cid, $rid]);
                $chk = $pdo->prepare("SELECT COUNT(*) FROM clientes_factura_contactos WHERE id = ? AND cliente_id = ? AND receptor_id = ?");
                $chk->execute([$id, $cid, $rid]);
                if (!$chk->fetchColumn()) throw new Exception("Contacto no encontrado.");
            } else {
                $pdo->prepare("INSERT INTO clientes_factura_contactos (nombre, cargo, email, telefono, copiar_cobros, contrato_id, cliente_id, receptor_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([...$vals, $cid, $rid]);
                $id = (int)$pdo->lastInsertId();
            }
            if ($conAlcance) {
                $pdo->prepare("DELETE FROM clientes_factura_contactos_alcance WHERE contacto_id = ?")->execute([$id]);
                $ins = $pdo->prepare("INSERT INTO clientes_factura_contactos_alcance (contacto_id, programa_id, contrato_id) VALUES (?, ?, ?)");
                foreach ($alcance as [$p, $k]) $ins->execute([$id, $p, $k]);
            }
            $pdo->commit();
        }
    }
    $st = $pdo->prepare("SELECT k.id, k.nombre, k.cargo, k.email, k.telefono, k.copiar_cobros, k.contrato_id FROM clientes_factura_contactos k
                         WHERE k.cliente_id = ? AND k.receptor_id = ? ORDER BY k.nombre");
    $st->execute([$cid, $rid]);
    $contactos = $st->fetchAll(PDO::FETCH_ASSOC);
    // Alcance de cada contacto (con nombres para mostrarlo)
    $stA = $conAlcance ? $pdo->prepare("SELECT a.programa_id, a.contrato_id, pg.nombre AS programa, ct.nombre_contrato AS contrato FROM clientes_factura_contactos_alcance a
                                        LEFT JOIN programas pg ON pg.id = a.programa_id LEFT JOIN contratos ct ON ct.id = a.contrato_id WHERE a.contacto_id = ?") : null;
    $stC = $pdo->prepare("SELECT nombre_contrato FROM contratos WHERE id = ?");
    foreach ($contactos as &$c) {
        $c['alcance'] = [];
        if ($stA) {
            $stA->execute([$c['id']]);
            foreach ($stA->fetchAll(PDO::FETCH_ASSOC) as $a)
                $c['alcance'][] = $a['programa_id'] ? ['clave' => 'p:' . $a['programa_id'], 'nombre' => $a['programa'], 'tipo' => 'programa'] : ['clave' => 'k:' . $a['contrato_id'], 'nombre' => $a['contrato'], 'tipo' => 'contrato'];
        } elseif ($c['contrato_id']) {
            $stC->execute([$c['contrato_id']]);
            $c['alcance'][] = ['clave' => 'k:' . $c['contrato_id'], 'nombre' => $stC->fetchColumn(), 'tipo' => 'contrato'];
        }
    }
    unset($c);
    $st = $pdo->prepare("SELECT id, nombre_contrato, estado, " . (programasDisponible($pdo) ? "programa_id" : "NULL AS programa_id") . " FROM contratos WHERE cliente_id = ? AND receptor_id = ? ORDER BY estado = 'activo' DESC, nombre_contrato");
    $st->execute([$cid, $rid]);
    $contratos = $st->fetchAll(PDO::FETCH_ASSOC);
    $programas = programasPorCliente($pdo, (int)$cid)[$rid] ?? [];
    echo json_encode(['success' => true, 'contactos' => $contactos, 'contratos' => $contratos, 'programas' => $programas], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e instanceof PDOException ? 'Error de base de datos.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
