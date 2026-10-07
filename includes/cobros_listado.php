<?php
/** Lista paginada de correos, filtrada en el servidor sin recortar el historial. */
function cobrosListado(PDO $pdo, int $cid, array $f): array
{
    $tam = (int)($f['por_pagina'] ?? 25);
    if (!in_array($tam, [10, 25, 50, 100, 200, 300], true)) $tam = 25;
    $where = ['c.cliente_id = ?'];
    $params = [$cid];
    if (!empty($f['cliente'])) { $where[] = 'c.receptor_id = ?'; $params[] = (int)$f['cliente']; }
    if (in_array($f['estado'] ?? '', ['programado', 'enviando', 'enviado', 'error', 'cancelado'], true)) {
        $where[] = 'c.estado = ?'; $params[] = $f['estado'];
    }
    $q = mb_substr(trim((string)($f['q'] ?? '')), 0, 200);
    $extras = cobrosExtrasDisponible($pdo);
    if ($q !== '') {
        $like = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $campos = ["cf.nombre", "c.asunto", "c.para", "COALESCE(c.cc, '')", "CAST(c.id AS CHAR)"];
        $or = [];
        foreach ($campos as $campo) { $or[] = "$campo LIKE ? ESCAPE '!'"; $params[] = $like; }
        $or[] = "EXISTS (SELECT 1 FROM cobros_programados_facturas x JOIN facturas f ON f.id=x.factura_id AND f.cliente_id=c.cliente_id WHERE x.cobro_id=c.id AND f.correlativo LIKE ? ESCAPE '!')";
        $params[] = $like;
        if ($extras) {
            $or[] = "EXISTS (SELECT 1 FROM cobros_programados_recibos x JOIN contratos_recibos r ON r.id=x.recibo_id AND r.cliente_id=c.cliente_id WHERE x.cobro_id=c.id AND CONCAT('Recibo ', LPAD(r.numero_recibo, 5, '0')) LIKE ? ESCAPE '!')";
            $params[] = $like;
        }
        $where[] = '(' . implode(' OR ', $or) . ')';
    }
    $from = ' FROM cobros_programados c JOIN clientes_factura cf ON cf.id=c.receptor_id AND cf.cliente_id=c.cliente_id WHERE ' . implode(' AND ', $where);
    $st = $pdo->prepare('SELECT COUNT(*)' . $from);
    $st->execute($params);
    $total = (int)$st->fetchColumn();
    $paginas = max(1, (int)ceil($total / $tam));
    $pagina = min($paginas, max(1, (int)($f['pagina'] ?? 1)));
    $offset = ($pagina - 1) * $tam;
    $sql = "SELECT c.id, c.receptor_id, c.asunto, c.para, c.cc, c.programado_para, c.enviado_en, c.estado, c.prueba, c.error, cf.nombre AS cliente,
        (SELECT GROUP_CONCAT(f.correlativo ORDER BY f.correlativo SEPARATOR ', ') FROM cobros_programados_facturas x JOIN facturas f ON f.id=x.factura_id AND f.cliente_id=c.cliente_id WHERE x.cobro_id=c.id) AS facturas";
    if ($extras) $sql .= ", (SELECT GROUP_CONCAT(CONCAT('Recibo ', LPAD(r.numero_recibo, 5, '0')) ORDER BY r.numero_recibo SEPARATOR ', ') FROM cobros_programados_recibos x JOIN contratos_recibos r ON r.id=x.recibo_id AND r.cliente_id=c.cliente_id WHERE x.cobro_id=c.id) AS recibos,
        (SELECT COUNT(*) FROM cobros_programados_plan x WHERE x.cobro_id=c.id) AS pagos_plan";
    $st = $pdo->prepare($sql . $from . " ORDER BY c.estado = 'programado' DESC, c.programado_para DESC, c.id DESC LIMIT $tam OFFSET $offset");
    $st->execute($params);
    return ['cobros' => $st->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas, 'por_pagina' => $tam];
}
