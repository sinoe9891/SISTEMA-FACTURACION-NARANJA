<?php
// Programas de un cliente (Aldea Global → PROGRESE): contrato, factura («Programa: …»), correo al equipo del programa,
// listas y gastos operativos por programa o por contrato rotativo.
suite('Programas: contrato, factura, correo y gastos operativos por programa', function () {
    $pdo = db();
    $c = login('qa.admin@local.test');
    $rec = 1;
    $nom = 'QA PROGRESE ' . time();
    $r = $c->post('includes/programa_accion.php', ['accion' => 'guardar', 'receptor_id' => $rec, 'nombre' => $nom, 'notas' => 'Programa interno']);
    $pid = (int)($r['json']['id'] ?? 0);
    check('se crea el programa del cliente', $pid > 0, $r['body']);
    $r = $c->post('includes/programa_accion.php', ['accion' => 'guardar', 'receptor_id' => $rec, 'nombre' => $nom]);
    check('no se repite el nombre en el mismo cliente', !($r['json']['success'] ?? true));
    $l = $c->get('includes/programa_accion.php', ['receptor_id' => $rec]);
    check('la ficha del cliente lo lista', str_contains($l['body'], $nom));
    $e = $c->get('editar_cliente', ['id' => $rec]);
    check('editar cliente tiene la tarjeta Programas', sinErroresPhp($e['body']) && str_contains($e['body'], 'id="pgCard"'), errorPhp($e['body']));
    $cc = $c->get('crear_contrato');
    check('nuevo contrato ofrece el campo Programa', sinErroresPhp($cc['body']) && str_contains($cc['body'], 'id="programa_id"') && str_contains($cc['body'], $nom), errorPhp($cc['body']));

    // Contrato activo del cliente: se le pone el programa y sus facturas lo toman
    $ct = (int)$pdo->query("SELECT id FROM contratos WHERE cliente_id = 2 AND receptor_id = $rec AND estado = 'activo' AND tipo_contrato <> 'rotativo' ORDER BY id DESC LIMIT 1")->fetchColumn();
    check('hay un contrato de prueba', $ct > 0);
    $antesProg = $pdo->query("SELECT programa_id FROM contratos WHERE id = $ct")->fetchColumn();
    $pdo->exec("UPDATE contratos SET programa_id = $pid WHERE id = $ct");
    $cai = $pdo->query("SELECT * FROM cai_rangos WHERE cliente_id=2 AND fecha_limite >= CURDATE() AND correlativo_actual < (rango_fin - rango_inicio + 1) ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $prod = $pdo->query("SELECT id FROM productos_clientes WHERE cliente_id=2 AND precio > 0 LIMIT 1")->fetchColumn();
    $r = $c->post('guardar_factura.php', ['receptor_id' => $rec, 'cai_rango_id' => $cai['id'], 'condicion_pago' => 'Contado', 'establecimiento_id' => $cai['establecimiento_id'], 'contrato_id' => $ct,
        'productos[0][id]' => $prod, 'productos[0][cantidad]' => 1, 'productos[0][precio]' => 100]);
    $fid = (int)($r['json']['factura_id'] ?? 0);
    check('la factura del contrato toma su programa', $fid && (int)$pdo->query("SELECT programa_id FROM facturas WHERE id = $fid")->fetchColumn() === $pid, $r['body']);
    $v = $c->get('ver_factura', ['id' => $fid]);
    check('la factura dice «Programa: …» y sigue a nombre del cliente', str_contains($v['body'], '<strong>Programa:</strong> ' . htmlspecialchars($nom)) && str_contains($v['body'], '<strong>Cliente:</strong>'));
    $m = $c->postJson('procesar_accion_factura.php', ['accion' => 'generar_mensaje', 'factura_ids' => [$fid], 'tipo' => 'envio_factura']);
    check('el correo saluda al equipo del programa y el asunto lo nombra', str_contains($m['json']['mensaje'] ?? '', 'equipo de ' . $nom . ':') && str_contains($m['json']['asunto'] ?? '', $nom), substr($m['body'], 0, 300));
    $lf = $c->get('lista_facturas');
    check('el historial muestra la etiqueta del programa', str_contains($lf['body'], htmlspecialchars($nom)));
    $cx = $c->get('cuentas_cobrar');
    check('cuentas por cobrar muestra el programa', sinErroresPhp($cx['body']) && str_contains($cx['body'], htmlspecialchars($nom)), errorPhp($cx['body']));
    $ec = $c->get('estado_cuenta', ['receptor_id' => $rec]);
    check('el estado de cuenta también', sinErroresPhp($ec['body']) && str_contains($ec['body'], htmlspecialchars($nom)), errorPhp($ec['body']));
    $ef = $c->get('editar_factura', ['id' => $fid]);
    check('editar la factura muestra el programa elegido', sinErroresPhp($ef['body']) && str_contains($ef['body'], 'data-actual="' . $pid . '"'), errorPhp($ef['body']));
    // Sin contrato: se elige el programa a mano; de otro cliente no se acepta
    $r = $c->post('guardar_factura.php', ['receptor_id' => $rec, 'cai_rango_id' => $cai['id'], 'condicion_pago' => 'Contado', 'establecimiento_id' => $cai['establecimiento_id'], 'programa_id' => $pid,
        'productos[0][id]' => $prod, 'productos[0][cantidad]' => 1, 'productos[0][precio]' => 50]);
    $fid2 = (int)($r['json']['factura_id'] ?? 0);
    check('sin contrato, se le pone el programa elegido', $fid2 && (int)$pdo->query("SELECT programa_id FROM facturas WHERE id = $fid2")->fetchColumn() === $pid, $r['body']);
    $lc = $c->get('contratos');
    check('la lista de contratos muestra el programa', sinErroresPhp($lc['body']) && str_contains($lc['body'], htmlspecialchars($nom)), errorPhp($lc['body']));

    // Gastos operativos: asignado solo al programa, registra ahí y no en el cliente directo
    $oid = (int)$pdo->query("SELECT id FROM usuarios WHERE correo = 'qa.operativo@local.test'")->fetchColumn();
    if ($oid) {
        $r = $c->post('includes/gasto_operativo_accion.php', ['accion' => 'asignar', 'usuario_id' => $oid, 'cuentas[0]' => "p:$pid"]);
        check('se le asigna solo el programa', ($r['json']['success'] ?? false) && (int)$pdo->query("SELECT programa_id FROM usuarios_clientes WHERE usuario_id = $oid")->fetchColumn() === $pid, $r['body']);
        $op = login('qa.operativo@local.test');
        $tipo = (int)$pdo->query("SELECT id FROM gastos_op_tipos WHERE cliente_id = 2 AND nombre = 'Combustible'")->fetchColumn();
        $base = ['accion' => 'guardar', 'tipo_id' => $tipo, 'fecha' => date('Y-m-d'), 'descripcion' => 'QA visita programa', 'moneda' => 'HNL', 'monto' => 300, 'metodo_pago' => 'efectivo'];
        $r = $op->post('includes/gasto_operativo_accion.php', $base + ['cuenta' => "c:$rec"]);
        check('no registra en el cliente directo (no lo tiene asignado)', !($r['json']['success'] ?? true));
        $r = $op->post('includes/gasto_operativo_accion.php', $base + ['cuenta' => "p:$pid"]);
        $gid = (int)($r['json']['id'] ?? 0);
        check('sí en su programa', $gid && (int)$pdo->query("SELECT programa_id FROM gastos_operativos WHERE id = $gid")->fetchColumn() === $pid, $r['body']);
        $p = $op->get('gastos_operativos', ['estado' => 'pendiente']);
        check('lo ve con la etiqueta del programa', sinErroresPhp($p['body']) && str_contains($p['body'], 'QA visita programa') && str_contains($p['body'], htmlspecialchars($nom)), errorPhp($p['body']));
        $pdo->exec("DELETE FROM gastos_operativos WHERE id = $gid");
        // Contrato rotativo: la cuenta es el contrato
        $rot = (int)$pdo->query("SELECT id FROM contratos WHERE cliente_id = 2 AND tipo_contrato = 'rotativo' AND estado = 'activo' LIMIT 1")->fetchColumn();
        if ($rot) {
            $c->post('includes/gasto_operativo_accion.php', ['accion' => 'asignar', 'usuario_id' => $oid, 'cuentas[0]' => "k:$rot"]);
            $r = $op->post('includes/gasto_operativo_accion.php', $base + ['cuenta' => "k:$rot"]);
            $g2 = (int)($r['json']['id'] ?? 0);
            check('con un contrato de varias empresas, el gasto queda en el contrato', $g2 && (int)$pdo->query("SELECT contrato_id FROM gastos_operativos WHERE id = $g2")->fetchColumn() === $rot, $r['body']);
            $pdo->exec("DELETE FROM gastos_operativos WHERE id = $g2");
        }
        $pdo->exec("DELETE FROM usuarios_clientes WHERE usuario_id = $oid");
    }

    // Limpieza (las facturas de prueba son las últimas: se borran)
    foreach ([$fid2, $fid] as $f) if ($f) $c->postJson('procesar_accion_factura.php', ['accion' => 'eliminar', 'factura_id' => $f, 'motivo' => 'QA', 'usuario_autoriza' => 'qa.admin@local.test', 'clave_autoriza' => QA_PASS]);
    $pdo->prepare("UPDATE contratos SET programa_id = ? WHERE id = ?")->execute([$antesProg ?: null, $ct]);
    $pdo->exec("DELETE FROM programas WHERE id = $pid");
});

suite('Gastos operativos: tipo y proveedor con búsqueda y alta rápida; aviso al facturar', function () {
    $pdo = db();
    $c = login('qa.admin@local.test');
    $oid = (int)$pdo->query("SELECT id FROM usuarios WHERE correo = 'qa.operativo@local.test'")->fetchColumn();
    if (!$oid) { check('existe el usuario operativo de QA', false); return; }
    $c->post('includes/gasto_operativo_accion.php', ['accion' => 'asignar', 'usuario_id' => $oid, 'cuentas[0]' => 'c:1']);
    $op = login('qa.operativo@local.test');
    $p = $op->get('gastos_operativos');
    check('el formulario trae tipo y proveedor con buscador y «Agregar nuevo»', sinErroresPhp($p['body']) && str_contains($p['body'], 'name="tipo_id" class="form-select" required data-buscar') && str_contains($p['body'], 'proveedor-select') && str_contains($p['body'], 'Agregar tipo nuevo'), errorPhp($p['body']));
    $nom = 'QA Pinterest Ads ' . time();
    $r = $op->post('includes/gasto_operativo_accion.php', ['accion' => 'tipo_crear', 'nombre' => $nom, 'es_pauta' => 1]);
    $tid = (int)($r['json']['tipo']['id'] ?? 0);
    check('el colaborador agrega un tipo (pauta)', $tid && (int)$pdo->query("SELECT es_pauta FROM gastos_op_tipos WHERE id = $tid")->fetchColumn() === 1, $r['body']);
    $r2 = $op->post('includes/gasto_operativo_accion.php', ['accion' => 'tipo_crear', 'nombre' => $nom]);
    check('si ya existe, usa el mismo', (int)($r2['json']['tipo']['id'] ?? 0) === $tid);
    $r = $op->post('includes/proveedor_accion.php', ['accion' => 'guardar', 'nombre' => 'QA Proveedor op ' . time(), 'tipo' => 'empresa']);
    $prov = (int)($r['json']['proveedor']['id'] ?? 0);
    check('el colaborador agrega un proveedor al catálogo', $prov > 0, $r['body']);
    $r = $op->post('includes/proveedor_accion.php', ['accion' => 'guardar', 'id' => $prov, 'nombre' => 'cambiado']);
    check('pero no edita proveedores', !($r['json']['success'] ?? true));
    $r = $op->post('includes/gasto_operativo_accion.php', ['accion' => 'guardar', 'cuenta' => 'c:1', 'tipo_id' => $tid, 'fecha' => date('Y-m-d'), 'descripcion' => 'QA pauta nueva', 'moneda' => 'HNL', 'monto' => 400,
        'metodo_pago' => 'tarjeta', 'pauta_inicio' => date('Y-m-d'), 'pauta_fin' => date('Y-m-d'), 'proveedor' => 'x', 'proveedor_id' => $prov, 'cobrar_cliente' => 1]);
    $gid = (int)($r['json']['id'] ?? 0);
    $g = $pdo->query("SELECT proveedor, proveedor_id FROM gastos_operativos WHERE id = $gid")->fetch(PDO::FETCH_ASSOC);
    check('el gasto guarda el proveedor del catálogo (con su nombre)', $g && (int)$g['proveedor_id'] === $prov && str_starts_with($g['proveedor'], 'QA Proveedor op'), $r['body']);
    $gf = $c->get('generar_factura', ['receptor_id' => 1]);
    check('al facturarle al cliente avisa los gastos operativos por aprobar', sinErroresPhp($gf['body']) && preg_match('/GASTOS_OP_PEND = \{[^;]*"1":\{"n":\d+/', $gf['body']), errorPhp($gf['body']));
    $r = $c->post('includes/gasto_operativo_accion.php', ['accion' => 'aprobar', 'id' => $gid, 'registro' => 'nuevo']);
    $gasto = (int)($r['json']['gasto_id'] ?? 0);
    check('al aprobarlo, el gasto de la empresa queda con ese proveedor', $gasto && (int)$pdo->query("SELECT proveedor_id FROM gastos WHERE id = $gasto")->fetchColumn() === $prov, $r['body']);
    $pdo->exec("DELETE FROM gastos WHERE id = $gasto");
    $pdo->exec("DELETE FROM gastos_operativos WHERE id = $gid");
    $pdo->exec("DELETE FROM gastos_op_tipos WHERE id = $tid");
    $pdo->exec("DELETE FROM proveedores WHERE id = $prov");
    $pdo->exec("DELETE FROM usuarios_clientes WHERE usuario_id = $oid");
});

suite('Contactos: una persona, varios programas o contratos', function () {
    $pdo = db();
    $c = login('qa.admin@local.test');
    $rid = 1;
    $pdo->exec("DELETE FROM clientes_factura_contactos WHERE receptor_id = $rid AND email LIKE '%@alcance.test'");
    $r = $c->post('includes/programa_accion.php', ['accion' => 'guardar', 'receptor_id' => $rid, 'nombre' => 'QA Prog contacto ' . time()]);
    $pid = (int)($r['json']['id'] ?? 0);
    $ks = array_map('intval', $pdo->query("SELECT id FROM contratos WHERE cliente_id = 2 AND receptor_id = $rid ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN));
    $r = $c->post('includes/cliente_contactos.php', ['receptor_id' => $rid, 'accion' => 'guardar', 'nombre' => 'Mavi QA', 'email' => 'mavi@alcance.test', 'copiar_cobros' => 1,
        'alcance[0]' => "p:$pid", 'alcance[1]' => 'k:' . ($ks[0] ?? 0)]);
    $m = array_values(array_filter($r['json']['contactos'] ?? [], fn($x) => $x['email'] === 'mavi@alcance.test'));
    check('una sola persona con un programa y un contrato', count($m) === 1 && count($m[0]['alcance']) === 2 && in_array("p:$pid", array_column($m[0]['alcance'], 'clave'), true), $r['body']);
    check('la ficha ofrece los programas para marcar', count($r['json']['programas'] ?? []) >= 1);
    $x = $c->get('cobro_accion.php', ['facturas' => $rid]);
    $m2 = array_values(array_filter($x['json']['contactos'] ?? [], fn($y) => $y['email'] === 'mavi@alcance.test'));
    check('el cobro sabe sus programas y contratos', in_array($pid, $m2[0]['programas'] ?? [], true) && in_array($ks[0] ?? -1, $m2[0]['contratos'] ?? [], true) && isset($x['json']['contrato_programa']), substr($x['body'], 0, 200));
    $id = (int)$m[0]['id'];
    $r = $c->post('includes/cliente_contactos.php', ['receptor_id' => $rid, 'accion' => 'guardar', 'id' => $id, 'nombre' => 'Mavi QA', 'email' => 'mavi@alcance.test', 'copiar_cobros' => 1]);
    $m = array_values(array_filter($r['json']['contactos'] ?? [], fn($x) => $x['email'] === 'mavi@alcance.test'));
    check('sin marcar nada vuelve a «todos los cobros»', $m && !$m[0]['alcance'], $r['body']);
    $c->post('includes/cliente_contactos.php', ['receptor_id' => $rid, 'accion' => 'eliminar', 'id' => $id]);
    check('al eliminarlo se borra su alcance', !(int)$pdo->query("SELECT COUNT(*) FROM clientes_factura_contactos_alcance WHERE contacto_id = $id")->fetchColumn());
    $pdo->exec("DELETE FROM programas WHERE id = $pid");
});

suite('Retiros de socio: en Gastos con su socio y comprobante; Socios los lista', function () {
    $pdo = db();
    $c = login('qa.admin@local.test');
    $soc = (int)$pdo->query("SELECT id FROM socios WHERE cliente_id = 2 AND activo = 1 ORDER BY id LIMIT 1")->fetchColumn();
    if (!$soc) { check('hay socios registrados', false); return; }
    $g = $c->get('gastos', ['nuevo' => 'retiro']);
    check('Gastos tiene el campo Socio para «Retiro de socio»', sinErroresPhp($g['body']) && str_contains($g['body'], 'id="g_socio"') && str_contains($g['body'], "q.get('nuevo') !== 'retiro'"), errorPhp($g['body']));
    $base = ['descripcion' => 'QA retiro socio', 'monto' => 321, 'fecha' => date('Y-m-d'), 'tipo' => 'variable', 'frecuencia' => 'unico', 'estado' => 'pagado', 'metodo_pago' => 'transferencia', 'naturaleza' => 'retiro'];
    $r = $c->post('includes/gasto_guardar.php', $base);
    check('un retiro pide el socio', !($r['json']['success'] ?? true) && str_contains($r['json']['error'] ?? '', 'socio') && !$pdo->query("SELECT COUNT(*) FROM gastos WHERE descripcion = 'QA retiro socio'")->fetchColumn(), $r['body']);
    $r = $c->post('includes/gasto_guardar.php', $base + ['socio_id' => $soc, 'archivo_adjunto' => new CURLFile(archivoTemporal('png'), 'image/png', 'comprobante.png')]);
    $gid = (int)$pdo->query("SELECT id FROM gastos WHERE descripcion = 'QA retiro socio' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $row = $pdo->query("SELECT naturaleza, socio_id, archivo_adjunto FROM gastos WHERE id = $gid")->fetch(PDO::FETCH_ASSOC);
    check('se guarda como retiro, con su socio y su comprobante', $row && $row['naturaleza'] === 'retiro' && (int)$row['socio_id'] === $soc && $row['archivo_adjunto'], $r['body']);
    $s = $c->get('socios', ['anio' => date('Y')]);
    $nom = (string)$pdo->query("SELECT nombre FROM socios WHERE id = $soc")->fetchColumn();
    check('Socios lo lista con su socio, el clip del comprobante y el botón «Registrar retiro»', sinErroresPhp($s['body']) && str_contains($s['body'], 'QA retiro socio') && str_contains($s['body'], 'gasto_archivo?id=' . $gid) && str_contains($s['body'], 'gastos?nuevo=retiro'), errorPhp($s['body']));
    $v = $c->get('gasto_ver', ['id' => $gid]);
    check('la ficha del gasto dice de qué socio es', sinErroresPhp($v['body']) && str_contains($v['body'], 'Retiro de socio') && str_contains($v['body'], htmlspecialchars($nom)), errorPhp($v['body']));
    $r = $c->post('includes/gasto_actualizar.php', ['gasto_id' => $gid, 'descripcion' => 'QA retiro socio', 'monto' => 400, 'fecha' => date('Y-m-d'), 'tipo' => 'variable', 'frecuencia' => 'unico', 'estado' => 'pagado', 'metodo_pago' => 'transferencia', 'naturaleza' => 'gasto']);
    check('si deja de ser retiro, ya no tiene socio', (int)$pdo->query("SELECT COUNT(*) FROM gastos WHERE id = $gid AND naturaleza = 'gasto' AND socio_id IS NULL")->fetchColumn() === 1, $r['body']);
    $a = $pdo->query("SELECT archivo_adjunto FROM gastos WHERE id = $gid")->fetchColumn();
    if ($a) @unlink(__DIR__ . '/../../clientes/naranjaymedia/includes/uploads/gastos/' . $a);
    $pdo->exec("DELETE FROM gastos WHERE id = $gid");
});

suite('Gastos operativos: el administrador corrige uno aprobado y se actualiza su gasto', function () {
    $pdo = db();
    $c = login('qa.admin@local.test');
    $oid = (int)$pdo->query("SELECT id FROM usuarios WHERE correo = 'qa.operativo@local.test'")->fetchColumn();
    if (!$oid) { check('existe el usuario operativo de QA', false); return; }
    $c->post('includes/gasto_operativo_accion.php', ['accion' => 'asignar', 'usuario_id' => $oid, 'cuentas[0]' => 'c:1']);
    $op = login('qa.operativo@local.test');
    $tipo = (int)$pdo->query("SELECT id FROM gastos_op_tipos WHERE cliente_id = 2 AND nombre = 'Combustible'")->fetchColumn();
    $base = ['accion' => 'guardar', 'cuenta' => 'c:1', 'tipo_id' => $tipo, 'fecha' => date('Y-m-d'), 'descripcion' => 'QA corregir aprobado', 'moneda' => 'HNL', 'monto' => 500, 'metodo_pago' => 'efectivo', 'cobrar_cliente' => 1];
    $gid = (int)($op->post('includes/gasto_operativo_accion.php', $base)['json']['id'] ?? 0);
    $gasto = (int)($c->post('includes/gasto_operativo_accion.php', ['accion' => 'aprobar', 'id' => $gid, 'registro' => 'nuevo'])['json']['gasto_id'] ?? 0);
    check('aprobado y registrado en Gastos', $gid && $gasto);
    $v = $c->get('includes/gasto_operativo_accion.php', ['ver' => $gid]);
    check('el administrador puede editarlo (el colaborador no)', ($v['json']['gasto']['puede_editar'] ?? false) === true && ($op->get('includes/gasto_operativo_accion.php', ['ver' => $gid])['json']['gasto']['puede_editar'] ?? true) === false);
    $r = $op->post('includes/gasto_operativo_accion.php', $base + ['id' => $gid, 'monto' => 600]);
    check('el colaborador no cambia uno aprobado', !($r['json']['success'] ?? true));
    $r = $c->post('includes/gasto_operativo_accion.php', array_merge($base, ['id' => $gid, 'monto' => 650, 'descripcion' => 'QA corregido']));
    $g = $pdo->query("SELECT estado, monto_lempiras FROM gastos_operativos WHERE id = $gid")->fetch(PDO::FETCH_ASSOC);
    $gs = $pdo->query("SELECT monto, descripcion FROM gastos WHERE id = $gasto")->fetch(PDO::FETCH_ASSOC);
    check('sigue aprobado y el gasto de Gastos toma el monto y la descripción nuevos', ($r['json']['success'] ?? false) && $g['estado'] === 'aprobado' && (float)$g['monto_lempiras'] === 650.0
        && (float)$gs['monto'] === 650.0 && str_contains($gs['descripcion'], 'QA corregido'), $r['body']);
    $pdo->exec("UPDATE gastos SET cobrado_factura_id = 999999 WHERE id = $gasto");
    $r = $c->post('includes/gasto_operativo_accion.php', array_merge($base, ['id' => $gid, 'monto' => 700, 'descripcion' => 'QA corregido']));
    check('si ya se le cobró al cliente, no deja cambiar el monto', !($r['json']['success'] ?? true) && (float)$pdo->query("SELECT monto FROM gastos WHERE id = $gasto")->fetchColumn() === 650.0, $r['body']);
    $r = $c->post('includes/gasto_operativo_accion.php', array_merge($base, ['id' => $gid, 'monto' => 650, 'descripcion' => 'QA corregido 2']));
    check('pero sí la descripción', ($r['json']['success'] ?? false) && str_contains((string)$pdo->query("SELECT descripcion FROM gastos WHERE id = $gasto")->fetchColumn(), 'QA corregido 2'), $r['body']);
    $pdo->exec("DELETE FROM gastos WHERE id = $gasto");
    $pdo->exec("DELETE FROM gastos_operativos WHERE id = $gid");
    $pdo->exec("DELETE FROM usuarios_clientes WHERE usuario_id = $oid");
});
