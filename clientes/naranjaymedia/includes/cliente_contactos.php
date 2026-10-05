<?php
// clientes/naranjaymedia/includes/cliente_contactos.php — Contactos adicionales de un cliente.
//   GET  ?receptor_id=X                          → lista + contratos del cliente (JSON)
// Un contacto puede pertenecer a un proyecto/contrato (contrato_id): solo va en copia de las facturas de ese contrato.
//   POST accion=guardar (id opcional) | eliminar  → admin/facturador
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if (!$pdo->query("SHOW TABLES LIKE 'clientes_factura_contactos'")->fetchColumn()) throw new Exception("Falta instalar sql/migraciones/2026-10-04_cliente_contactos.sql.");
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
            $pdo->prepare("DELETE FROM clientes_factura_contactos WHERE id = ? AND cliente_id = ? AND receptor_id = ?")->execute([$id, $cid, $rid]);
        } else {
            $nombre = mb_substr(trim((string)($_POST['nombre'] ?? '')), 0, 120);
            $email = trim((string)($_POST['email'] ?? ''));
            if ($nombre === '') throw new Exception("Escribe el nombre del contacto.");
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception("Correo inválido: $email");
            $contrato = (int)($_POST['contrato_id'] ?? 0) ?: null;
            if ($contrato) {
                $st = $pdo->prepare("SELECT id FROM contratos WHERE id = ? AND cliente_id = ? AND receptor_id = ?");
                $st->execute([$contrato, $cid, $rid]);
                if (!$st->fetchColumn()) throw new Exception("El contrato no pertenece a este cliente.");
            }
            $vals = [$nombre, mb_substr(trim((string)($_POST['cargo'] ?? '')), 0, 100) ?: null, $email ?: null,
                mb_substr(trim((string)($_POST['telefono'] ?? '')), 0, 40) ?: null, empty($_POST['copiar_cobros']) ? 0 : 1, $contrato];
            if ($id) {
                $pdo->prepare("UPDATE clientes_factura_contactos SET nombre = ?, cargo = ?, email = ?, telefono = ?, copiar_cobros = ?, contrato_id = ? WHERE id = ? AND cliente_id = ? AND receptor_id = ?")
                    ->execute([...$vals, $id, $cid, $rid]);
            } else {
                $pdo->prepare("INSERT INTO clientes_factura_contactos (nombre, cargo, email, telefono, copiar_cobros, contrato_id, cliente_id, receptor_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([...$vals, $cid, $rid]);
            }
        }
    }
    $st = $pdo->prepare("SELECT k.id, k.nombre, k.cargo, k.email, k.telefono, k.copiar_cobros, k.contrato_id, ct.nombre_contrato AS proyecto
                         FROM clientes_factura_contactos k LEFT JOIN contratos ct ON ct.id = k.contrato_id
                         WHERE k.cliente_id = ? AND k.receptor_id = ? ORDER BY k.contrato_id IS NOT NULL, ct.nombre_contrato, k.nombre");
    $st->execute([$cid, $rid]);
    $contactos = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $pdo->prepare("SELECT id, nombre_contrato, estado FROM contratos WHERE cliente_id = ? AND receptor_id = ? ORDER BY estado = 'activo' DESC, nombre_contrato");
    $st->execute([$cid, $rid]);
    echo json_encode(['success' => true, 'contactos' => $contactos, 'contratos' => $st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e instanceof PDOException ? 'Error de base de datos.' : $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
