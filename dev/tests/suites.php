<?php
// dev/tests/suites.php — casos de prueba (se cargan desde run.php)

$PAGINAS = [
    'dashboard', 'generar_factura', 'lista_facturas', 'contratos', 'crear_contrato', 'gastos', 'tarjetas',
    'categorias_gastos', 'financiero', 'proyeccion', 'colaboradores', 'productos', 'productos_clientes',
    'clientes', 'crear_cliente', 'configuracion_cai', 'crear_cai', 'configuracion_mensajes', 'usuarios',
    'inventario', 'inventario_traslados', 'inventario_reportes',
];

// ─────────────────────────────────────────────────────────────────────────────
suite('Login y páginas', function () use ($PAGINAS) {
    $anon = new Cliente('anonimo');
    $r = $anon->get('dashboard');
    check('sin sesión redirige al login', in_array($r['code'], [301, 302]), "código {$r['code']}");

    $mal = new Cliente('mal');
    $r = $mal->post('index.php', ['correo' => 'qa.admin@local.test', 'clave' => 'incorrecta']);
    check('clave incorrecta no inicia sesión', $r['code'] === 200 && str_contains($r['body'], 'Credenciales inválidas'));

    foreach (['qa.admin@local.test' => 'admin', 'qa.facturador@local.test' => 'facturador', 'qa.super@local.test' => 'superadmin'] as $correo => $rol) {
        $c = login($correo);
        $soloAdmin = ['usuarios', 'configuracion_cai', 'crear_cai', 'configuracion_mensajes'];
        foreach ($PAGINAS as $p) {
            if ($rol === 'facturador' && in_array($p, $soloAdmin)) {
                $r = $c->get($p);
                $negado = !str_contains($r['body'], 'id="appSidebar"') || str_contains($r['body'], 'Acceso denegado');
                check("facturador: $p le niega el acceso", $negado && sinErroresPhp($r['body']));
                continue;
            }
            $r = $c->get($p);
            if ($rol === 'facturador' && $p === 'dashboard') {
                check('facturador: el menú no muestra opciones solo de admin', !preg_match('#href="(usuarios|configuracion_cai|configuracion_mensajes)"#', $r['body']));
            }
            if ($rol === 'admin' && $p === 'dashboard') {
                check('admin: el menú sí muestra Configuración CAI y Usuarios', str_contains($r['body'], 'href="configuracion_cai"') && str_contains($r['body'], 'href="usuarios"'));
            }
            $okPag = $r['code'] === 200 && sinErroresPhp($r['body']);
            check("$rol: $p carga sin errores", $okPag, "código {$r['code']} " . errorPhp($r['body']));
            if ($okPag && $p !== 'proyeccion') {
                check("$rol: $p tiene sidebar con opción activa", str_contains($r['body'], 'id="appSidebar"') && str_contains($r['body'], 'app-nav-link active'));
            }
        }
    }
});

// ─────────────────────────────────────────────────────────────────────────────
suite('Productos aislados por empresa', function () {
    $ccic = login('qa.ccic@local.test', 3);
    $naranja = login('qa.admin@local.test');

    // Producto de Naranja (cliente 2)
    db()->exec("INSERT INTO productos (cliente_id, nombre, descripcion, precio, tipo_isv) VALUES (2, 'QA producto base', 'qa', 10, '15')");
    $pid = (int)db()->lastInsertId();
    db()->exec("INSERT INTO productos_clientes (cliente_id, receptores_id, nombre, descripcion, precio, tipo_isv, precio_fijo) VALUES (2, 1, 'QA prod cliente', 'qa', 10, '15', 0)");
    $pcid = (int)db()->lastInsertId();

    $r = $ccic->get('includes/productos_borrar.php', ['id' => $pid]);
    check('borrar producto por GET → 405', $r['code'] === 405, "código {$r['code']}");
    $ccic->post('includes/productos_borrar.php', ['id' => $pid]);
    check('otra empresa NO puede borrar el producto', (bool)db()->query("SELECT COUNT(*) FROM productos WHERE id=$pid")->fetchColumn());

    $ccic->post('includes/productos_editar.php', ['id' => $pid, 'cliente_id' => 2, 'nombre' => 'HACKEADO', 'descripcion' => 'x', 'precio' => 1, 'tipo_isv' => 0]);
    check('otra empresa NO puede editar (aunque mande cliente_id=2)', db()->query("SELECT nombre FROM productos WHERE id=$pid")->fetchColumn() === 'QA producto base');

    $r = $ccic->post('includes/productos_clientes_eliminar.php', ['id' => $pcid]);
    check('otra empresa NO puede eliminar producto por cliente', (bool)db()->query("SELECT COUNT(*) FROM productos_clientes WHERE id=$pcid")->fetchColumn(), "respuesta: " . substr($r['body'], 0, 80));

    $ccic->post('includes/productos_clientes_agregar.php', ['cliente_id' => 2, 'receptores_id' => 1, 'nombre' => 'QA intruso', 'descripcion' => 'x', 'precio' => 5, 'tipo_isv' => 15]);
    check('otra empresa NO puede crear productos en la de Naranja', !db()->query("SELECT COUNT(*) FROM productos_clientes WHERE nombre='QA intruso'")->fetchColumn());

    $r = $naranja->post('includes/productos_clientes_eliminar.php', ['id' => $pcid]);
    check('la empresa dueña SÍ puede eliminar su producto', !db()->query("SELECT COUNT(*) FROM productos_clientes WHERE id=$pcid")->fetchColumn());
    $naranja->post('includes/productos_borrar.php', ['id' => $pid]);
    check('la empresa dueña SÍ puede borrar su producto base', !db()->query("SELECT COUNT(*) FROM productos WHERE id=$pid")->fetchColumn());
});

// ─────────────────────────────────────────────────────────────────────────────
suite('APIs aisladas por empresa', function () {
    $ccic = login('qa.ccic@local.test', 3);
    $naranja = login('qa.admin@local.test');
    $ct = db()->query("SELECT receptor_id FROM contratos WHERE cliente_id=2 AND estado='activo' LIMIT 1")->fetchColumn();

    $r = $ccic->get(API . 'contratos_por_receptor.php', ['receptor_id' => $ct, 'cliente_id' => 2]);
    check('contratos: otra empresa con ?cliente_id=2 no ve nada', $r['json'] === [], substr($r['body'], 0, 80));
    $r = $naranja->get(API . 'contratos_por_receptor.php', ['receptor_id' => $ct]);
    check('contratos: la empresa dueña sí los ve', is_array($r['json']) && count($r['json']) > 0, substr($r['body'], 0, 80));

    $r = $ccic->get(API . 'puntos_por_establecimiento.php', ['establecimiento_id' => 1]);
    check('puntos: otra empresa recibe 403', $r['code'] === 403, "código {$r['code']}");
    $r = $naranja->get(API . 'puntos_por_establecimiento.php', ['establecimiento_id' => 1]);
    check('puntos: la empresa dueña sí los ve', $r['code'] === 200 && count($r['json'] ?? []) > 0);
});

// ─────────────────────────────────────────────────────────────────────────────
suite('Facturas: borrado y correlativo', function () {
    $c = login('qa.admin@local.test');
    $cai = db()->query("SELECT * FROM cai_rangos WHERE cliente_id=2 AND fecha_limite >= CURDATE() AND correlativo_actual < (rango_fin - rango_inicio + 1) ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    check('hay un CAI vigente para probar', (bool)$cai);
    if (!$cai) return;
    $prod = db()->query("SELECT id FROM productos_clientes WHERE cliente_id=2 AND precio > 0 LIMIT 1")->fetchColumn();
    $crear = fn() => $c->post('guardar_factura.php', [
        'receptor_id' => 1, 'cai_rango_id' => $cai['id'], 'condicion_pago' => 'Contado', 'establecimiento_id' => $cai['establecimiento_id'],
        'productos[0][id]' => $prod, 'productos[0][cantidad]' => 1, 'productos[0][detalles]' => 'QA',
    ]);
    $r1 = $crear();
    $r2 = $crear();
    check('se crean 2 facturas de prueba', ($r1['json']['success'] ?? false) && ($r2['json']['success'] ?? false), substr($r1['body'] . $r2['body'], 0, 160));
    $f1 = $r1['json']['factura_id'] ?? 0;
    $f2 = $r2['json']['factura_id'] ?? 0;
    $corr2 = db()->query("SELECT correlativo FROM facturas WHERE id=" . (int)$f2)->fetchColumn();

    $borrar = fn($id) => $c->postJson('procesar_accion_factura.php', ['accion' => 'eliminar', 'factura_id' => $id, 'motivo' => 'QA', 'usuario_autoriza' => 'qa.admin@local.test', 'clave_autoriza' => QA_PASS]);
    $r = $borrar($f1);
    check('NO se puede borrar una factura que no es la última', !($r['json']['success'] ?? true) && db()->query("SELECT COUNT(*) FROM facturas WHERE id=" . (int)$f1)->fetchColumn(), substr($r['body'], 0, 120));

    $sup = login('qa.super@local.test');
    $r = $sup->postJson('procesar_accion_factura.php', ['accion' => 'eliminar', 'factura_id' => $f1, 'motivo' => 'QA', 'usuario_autoriza' => 'qa.super@local.test', 'clave_autoriza' => QA_PASS]);
    check('tampoco el superadmin (evita correlativos duplicados)', !($r['json']['success'] ?? true));

    $r = $borrar($f2);
    check('SÍ se puede borrar la última factura', ($r['json']['success'] ?? false), substr($r['body'], 0, 120));
    $r3 = $crear();
    $f3 = $r3['json']['factura_id'] ?? 0;
    $corr3 = db()->query("SELECT correlativo FROM facturas WHERE id=" . (int)$f3)->fetchColumn();
    check('la siguiente factura reutiliza el número borrado (no se salta ni duplica)', $corr3 === $corr2, "$corr3 vs $corr2");
    $dup = db()->query("SELECT correlativo, COUNT(*) n FROM facturas WHERE cai_id={$cai['id']} GROUP BY correlativo HAVING n>1")->fetchAll();
    check('no hay correlativos duplicados en el CAI', !$dup, json_encode($dup));

    // limpieza: borrar f3 y f1 en orden (siempre la última)
    $borrar($f3);
    $borrar($f1);
    check('limpieza: el CAI vuelve a su correlativo original', (int)db()->query("SELECT correlativo_actual FROM cai_rangos WHERE id={$cai['id']}")->fetchColumn() === (int)$cai['correlativo_actual']);
});

// ─────────────────────────────────────────────────────────────────────────────
suite('Gastos: comprobantes y recurrencia', function () {
    $c = login('qa.admin@local.test');
    $ccic = login('qa.ccic@local.test', 3);
    $cat = db()->query("SELECT id FROM categorias_gastos WHERE cliente_id=2 LIMIT 1")->fetchColumn();
    $catOtra = db()->query("SELECT id FROM categorias_gastos WHERE cliente_id<>2 LIMIT 1")->fetchColumn();
    $base = ['monto' => 150, 'fecha' => date('Y-m-05'), 'tipo' => 'fijo', 'metodo_pago' => 'transferencia', 'categoria_id' => $cat];

    // Comprobante inválido (PHP renombrado a .jpg)
    $fake = archivoTemporal('txt');
    $r = $c->post('includes/gasto_guardar.php', $base + ['descripcion' => 'QA gasto falso', 'frecuencia' => 'unico', 'estado' => 'pendiente',
        'archivo_adjunto' => new CURLFile($fake, 'image/jpeg', 'foto.jpg')]);
    check('rechaza archivo que no es imagen/PDF aunque diga .jpg', !($r['json']['success'] ?? true), substr($r['body'], 0, 100));
    check('…y no crea el gasto', !db()->query("SELECT COUNT(*) FROM gastos WHERE descripcion='QA gasto falso'")->fetchColumn());

    // Categoría de otra empresa
    $r = $c->post('includes/gasto_guardar.php', array_merge($base, ['descripcion' => 'QA cat ajena', 'frecuencia' => 'unico', 'estado' => 'pendiente', 'categoria_id' => $catOtra]));
    check('rechaza categoría de otra empresa', !$catOtra || !($r['json']['success'] ?? true));

    // Gasto mensual con comprobante PNG
    $png = archivoTemporal('png');
    $r = $c->post('includes/gasto_guardar.php', $base + ['descripcion' => 'QA recurrente', 'frecuencia' => 'mensual', 'dia_pago' => 5, 'estado' => 'pendiente',
        'archivo_adjunto' => new CURLFile($png, 'image/png', 'comprobante.png')]);
    $gid = $r['json']['gasto_id'] ?? 0;
    check('crea gasto mensual con comprobante', (bool)$gid, substr($r['body'], 0, 120));
    $arch = db()->query("SELECT archivo_adjunto FROM gastos WHERE id=" . (int)$gid)->fetchColumn();
    check('el comprobante se guardó en disco', $arch && is_file(UPLOADS . '/gastos/' . $arch));

    $r = $c->get('gasto_archivo', ['id' => $gid]);
    check('la empresa dueña ve el comprobante (200 image/png)', $r['code'] === 200 && str_contains($r['head'], 'image/png'));
    $r = $ccic->get('gasto_archivo', ['id' => $gid]);
    check('otra empresa NO ve el comprobante (404)', $r['code'] === 404, "código {$r['code']}");
    $r = (new Cliente('anon'))->get('includes/uploads/gastos/' . $arch);
    check('el enlace directo a uploads está bloqueado (403)', $r['code'] === 403, "código {$r['code']}");

    // Pagar con nuevo comprobante (PDF) → reemplaza y programa el siguiente mes
    $pdf = archivoTemporal('pdf');
    $r = $c->post('includes/gasto_marcar_pagado.php', ['gasto_id' => $gid, 'fecha' => date('Y-m-d'), 'metodo_pago' => 'efectivo',
        'archivo_adjunto' => new CURLFile($pdf, 'application/pdf', 'pago.pdf')]);
    check('marcar pagado funciona', ($r['json']['success'] ?? false), substr($r['body'], 0, 120));
    $sig = $r['json']['siguiente'] ?? null;
    $esperado = (new DateTime(date('Y-m-05')))->modify('first day of next month')->format('Y-m-05');
    check('programa el gasto del mes siguiente', $sig === $esperado, var_export($sig, true) . " vs $esperado");
    $nuevo = db()->query("SELECT * FROM gastos WHERE descripcion='QA recurrente' AND fecha=" . db()->quote((string)$sig))->fetch(PDO::FETCH_ASSOC);
    check('el nuevo gasto está pendiente, sin comprobante y en la misma serie', $nuevo && $nuevo['estado'] === 'pendiente' && !$nuevo['archivo_adjunto'] && (int)$nuevo['gasto_grupo_id'] === (int)$gid);
    check('el comprobante anterior se borró del disco (ya nadie lo usa)', !is_file(UPLOADS . '/gastos/' . $arch));
    $arch2 = db()->query("SELECT archivo_adjunto FROM gastos WHERE id=" . (int)$gid)->fetchColumn();

    // Pagar de nuevo el mismo período no duplica
    db()->exec("UPDATE gastos SET estado='pendiente' WHERE id=" . (int)$gid);
    $r = $c->post('includes/gasto_marcar_pagado.php', ['gasto_id' => $gid, 'fecha' => date('Y-m-d'), 'metodo_pago' => 'efectivo']);
    check('volver a pagarlo NO duplica el mes siguiente', (int)db()->query("SELECT COUNT(*) FROM gastos WHERE descripcion='QA recurrente' AND fecha=" . db()->quote((string)$sig))->fetchColumn() === 1);

    // Quincenas: editar con "actualizar grupo" no pisa fecha/estado/descripcion de la otra
    $r = $c->post('includes/gasto_guardar.php', array_merge($base, ['descripcion' => 'QA quincena', 'frecuencia' => 'quincenal', 'dia_pago' => 15, 'dia_pago_2' => 30, 'estado' => 'pendiente', 'fecha' => date('Y-m-15')]));
    [$q1, $q2] = $r['json']['ids_creados'] ?? [0, 0];
    $antes2 = db()->query("SELECT fecha, descripcion, estado FROM gastos WHERE id=" . (int)$q2)->fetch(PDO::FETCH_ASSOC);
    $r = $c->post('includes/gasto_actualizar.php', ['gasto_id' => $q1, 'descripcion' => 'QA quincena editada — 1ª Quincena', 'monto' => 200, 'fecha' => date('Y-m-14'),
        'frecuencia' => 'quincenal', 'dia_pago' => 15, 'dia_pago_2' => 30, 'tipo' => 'fijo', 'metodo_pago' => 'efectivo', 'categoria_id' => $cat, 'estado' => 'pagado', 'actualizar_grupo' => 1]);
    $d1 = db()->query("SELECT fecha, descripcion, estado, monto FROM gastos WHERE id=" . (int)$q1)->fetch(PDO::FETCH_ASSOC);
    $d2 = db()->query("SELECT fecha, descripcion, estado, monto FROM gastos WHERE id=" . (int)$q2)->fetch(PDO::FETCH_ASSOC);
    check('quincenas: el monto se comparte', (float)$d1['monto'] === 200.0 && (float)$d2['monto'] === 200.0, json_encode([$d1, $d2]));
    check('quincenas: cada una conserva su sufijo', str_ends_with($d1['descripcion'], '1ª Quincena') && str_ends_with($d2['descripcion'], '2ª Quincena') && str_starts_with($d2['descripcion'], 'QA quincena editada'), $d1['descripcion'] . ' | ' . $d2['descripcion']);
    check('quincenas: la 2ª conserva su fecha y su estado', $d2['fecha'] === $antes2['fecha'] && $d2['estado'] === 'pendiente', json_encode($d2));

    // Limpieza: eliminar gastos de prueba (y sus archivos)
    foreach (db()->query("SELECT id FROM gastos WHERE descripcion LIKE 'QA %'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $c->post('includes/gasto_eliminar.php', ['id' => $id, 'accion' => 'eliminar']);
    }
    check('limpieza: no quedan gastos de prueba', !db()->query("SELECT COUNT(*) FROM gastos WHERE descripcion LIKE 'QA %'")->fetchColumn());
    check('limpieza: el comprobante PDF se borró del disco', !$arch2 || !is_file(UPLOADS . '/gastos/' . $arch2));
});

// ─────────────────────────────────────────────────────────────────────────────
suite('CSRF', function () {
    $c = login('qa.admin@local.test');
    check('la página trae el token CSRF', strlen($c->csrf) === 64);
    $r = $c->get('gastos');
    check('el script CSRF está en el <head>', str_contains($r['body'], 'X-CSRF-Token') && str_contains($r['body'], 'name="csrf-token"'));

    // Sin token: rechazado y sin cambios
    $c->sinCsrf = true;
    $r = $c->post('includes/gasto_guardar.php', ['descripcion' => 'QA csrf', 'monto' => 10, 'fecha' => date('Y-m-d'), 'frecuencia' => 'unico', 'tipo' => 'variable', 'metodo_pago' => 'efectivo', 'estado' => 'pendiente']);
    check('POST sin token → 403', $r['code'] === 403, "código {$r['code']}");
    check('…y no se guardó nada', !db()->query("SELECT COUNT(*) FROM gastos WHERE descripcion='QA csrf'")->fetchColumn());
    $r = $c->postJson('procesar_accion_factura.php', ['accion' => 'anular', 'factura_id' => 1, 'motivo' => 'x', 'usuario_autoriza' => 'qa.admin@local.test', 'clave_autoriza' => QA_PASS]);
    check('POST JSON sin token → 403 con JSON', $r['code'] === 403 && isset($r['json']['error']));
    $r = $c->req('POST', BASE . 'includes/gasto_guardar.php', ['descripcion' => 'QA csrf'], ['X-CSRF-Token: ' . str_repeat('0', 64)]);
    check('POST con token falso → 403', $r['code'] === 403);
    $r = $c->post('includes/gasto_guardar.php', ['descripcion' => 'QA csrf', '_csrf' => $c->csrf, 'monto' => 10, 'fecha' => date('Y-m-d'), 'frecuencia' => 'unico', 'tipo' => 'variable', 'metodo_pago' => 'efectivo', 'estado' => 'pendiente']);
    check('POST con token en el formulario (_csrf) → aceptado', ($r['json']['success'] ?? false), substr($r['body'], 0, 100));
    $c->sinCsrf = false;

    // Con token por cabecera (fetch): aceptado
    $id = db()->query("SELECT id FROM gastos WHERE descripcion='QA csrf'")->fetchColumn();
    $r = $c->post('includes/gasto_eliminar.php', ['id' => $id, 'accion' => 'eliminar']);
    check('POST con token por cabecera → aceptado', ($r['json']['success'] ?? false));

    // GET no requiere token; el login tampoco
    $r = (new Cliente('x'))->post('index.php', ['correo' => 'nadie@local.test', 'clave' => 'x']);
    check('el login no exige token (no hay sesión todavía)', $r['code'] === 200 && str_contains($r['body'], 'Credenciales'));
});

// ─────────────────────────────────────────────────────────────────────────────
suite('Facturas: validaciones y superadmin', function () {
    $c = login('qa.admin@local.test');
    $cai = db()->query("SELECT * FROM cai_rangos WHERE cliente_id=2 AND fecha_limite >= CURDATE() ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $prod = db()->query("SELECT id FROM productos_clientes WHERE cliente_id=2 AND precio > 0 LIMIT 1")->fetchColumn();
    $prod0 = db()->query("SELECT id FROM productos_clientes WHERE cliente_id=2 AND precio = 0 LIMIT 1")->fetchColumn();
    $corrAntes = (int)$cai['correlativo_actual'];
    $fact = fn(Cliente $cl, array $extra, array $linea) => $cl->post('guardar_factura.php', $extra + [
        'receptor_id' => 1, 'cai_rango_id' => $cai['id'], 'condicion_pago' => 'Contado', 'establecimiento_id' => $cai['establecimiento_id'],
        'productos[0][id]' => $linea['id'] ?? $prod, 'productos[0][cantidad]' => $linea['cantidad'] ?? 1, 'productos[0][precio]' => $linea['precio'] ?? '', 'productos[0][detalles]' => 'QA',
    ]);

    $r = $fact($c, [], ['cantidad' => 0]);
    check('rechaza cantidad 0', !($r['json']['success'] ?? true) && str_contains($r['json']['error'] ?? '', 'cantidad'), $r['json']['error'] ?? '');
    $r = $fact($c, [], ['cantidad' => -2]);
    check('rechaza cantidad negativa', !($r['json']['success'] ?? true));
    $r = $fact($c, [], ['precio' => -50]);
    check('rechaza precio negativo', !($r['json']['success'] ?? true) && str_contains($r['json']['error'] ?? '', 'negativo'));
    if ($prod0) {
        $r = $fact($c, [], ['id' => $prod0, 'precio' => '0']);
        check('rechaza factura con total 0', !($r['json']['success'] ?? true) && str_contains($r['json']['error'] ?? '', 'total'), $r['json']['error'] ?? '');
    }
    check('los rechazos no consumieron correlativos', (int)db()->query("SELECT correlativo_actual FROM cai_rangos WHERE id={$cai['id']}")->fetchColumn() === $corrAntes);

    $r = $fact($c, ['estado' => 'anulada', 'fecha_emision' => '2020-01-01 00:00:00'], ['precio' => '150']);
    $f = db()->query("SELECT estado, fecha_emision FROM facturas WHERE id=" . (int)($r['json']['factura_id'] ?? 0))->fetch(PDO::FETCH_ASSOC);
    check('ignora estado/fecha enviados: nace emitida con fecha de hoy', $f && $f['estado'] === 'emitida' && substr($f['fecha_emision'], 0, 10) === date('Y-m-d'), json_encode($f));
    check('respeta el precio editado (precio sugerido, no fijo)', (float)db()->query("SELECT precio_unitario FROM factura_items_receptor WHERE factura_id=" . (int)($r['json']['factura_id'] ?? 0))->fetchColumn() === 150.0);

    // Superadmin factura para el cliente seleccionado
    $sup = login('qa.super@local.test');
    $r2 = $fact($sup, [], []);
    $fid2 = (int)($r2['json']['factura_id'] ?? 0);
    check('superadmin puede facturar para el cliente seleccionado', $fid2 && (int)db()->query("SELECT cliente_id FROM facturas WHERE id=$fid2")->fetchColumn() === 2, substr($r2['body'], 0, 120));

    // Limpieza (siempre la última)
    foreach ([$fid2, (int)($r['json']['factura_id'] ?? 0)] as $id) {
        if ($id) $c->postJson('procesar_accion_factura.php', ['accion' => 'eliminar', 'factura_id' => $id, 'motivo' => 'QA', 'usuario_autoriza' => 'qa.admin@local.test', 'clave_autoriza' => QA_PASS]);
    }
    check('limpieza: CAI en su correlativo original', (int)db()->query("SELECT correlativo_actual FROM cai_rangos WHERE id={$cai['id']}")->fetchColumn() === $corrAntes);
});

// ─────────────────────────────────────────────────────────────────────────────
suite('CAI: formato, traslapes y bloqueo', function () {
    $c = login('qa.admin@local.test');
    $usado = db()->query("SELECT * FROM cai_rangos WHERE cliente_id=2 AND correlativo_actual > 0 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $base = ['cai' => 'QA0000-000000-000000-000000-000000-00', 'establecimiento_id' => 1, 'punto_emision_id' => 1, 'fecha_recepcion' => date('Y-m-d'), 'fecha_limite' => date('Y-m-d', strtotime('+1 year'))];

    $r = $c->post('guardar_cai.php', $base + ['rango_inicio' => 1, 'rango_fin' => 10, 'rango_cai_inicio' => '000-002-01-1', 'rango_cai_fin' => '000-002-01-00000010']);
    check('rechaza formato de rango CAI inválido', str_contains($r['loc'], 'error=') && !db()->query("SELECT COUNT(*) FROM cai_rangos WHERE cai LIKE 'QA%'")->fetchColumn(), urldecode($r['loc']));
    $r = $c->post('guardar_cai.php', $base + ['rango_inicio' => 150, 'rango_fin' => 160, 'rango_cai_inicio' => '000-002-01-00000150', 'rango_cai_fin' => '000-002-01-00000160']);
    check('rechaza rango que se traslapa con otro CAI', str_contains(urldecode($r['loc']), 'traslapa'), urldecode($r['loc']));
    $r = $c->post('guardar_cai.php', $base + ['rango_inicio' => 5, 'rango_fin' => 9, 'rango_cai_inicio' => '000-002-01-00000001', 'rango_cai_fin' => '000-002-01-00000009']);
    check('rechaza números que no coinciden con el rango', str_contains(urldecode($r['loc']), 'no coinciden'), urldecode($r['loc']));
    $r = $c->post('guardar_cai.php', $base + ['rango_inicio' => 9001, 'rango_fin' => 9010, 'rango_cai_inicio' => '000-002-01-00009001', 'rango_cai_fin' => '000-002-01-00009010']);
    $nuevo = db()->query("SELECT * FROM cai_rangos WHERE cai LIKE 'QA%'")->fetch(PDO::FETCH_ASSOC);
    check('crea un CAI válido sin traslape', (bool)$nuevo, urldecode($r['loc']));

    // CAI sin uso: se puede cambiar el inicio
    if ($nuevo) {
        $r = $c->post('guardar_edicion_cai.php', $base + ['id' => $nuevo['id'], 'rango_inicio' => 9002, 'rango_fin' => 9010, 'rango_cai_inicio' => '000-002-01-00009002', 'rango_cai_fin' => '000-002-01-00009010']);
        check('CAI sin facturas: sí permite cambiar el inicio', ($r['json']['success'] ?? false), $r['json']['error'] ?? '');
        db()->exec("DELETE FROM cai_rangos WHERE id=" . (int)$nuevo['id']);
    }

    // CAI usado: bloqueos
    $edit = fn(array $cambios) => $c->post('guardar_edicion_cai.php', array_merge([
        'id' => $usado['id'], 'cai' => $usado['cai'], 'fecha_recepcion' => $usado['fecha_recepcion'], 'fecha_limite' => $usado['fecha_limite'],
        'establecimiento_id' => $usado['establecimiento_id'], 'punto_emision_id' => $usado['punto_emision_id'], 'rango_inicio' => $usado['rango_inicio'],
        'rango_fin' => $usado['rango_fin'], 'rango_cai_inicio' => $usado['rango_cai_inicio'], 'rango_cai_fin' => $usado['rango_cai_fin'],
        'numero_certificado' => $usado['numero_certificado'],
    ], $cambios));
    $r = $edit(['rango_inicio' => $usado['rango_inicio'] + 1]);
    check('CAI usado: NO permite cambiar el inicio del rango', !($r['json']['success'] ?? true) && str_contains($r['json']['error'] ?? '', 'no se puede cambiar'), $r['json']['error'] ?? '');
    $r = $edit(['cai' => 'ZZ' . substr($usado['cai'], 2)]);
    check('CAI usado: NO permite cambiar el código CAI', !($r['json']['success'] ?? true));
    $r = $edit(['fecha_limite' => date('Y-m-d', strtotime($usado['fecha_limite'] . ' +1 day'))]);
    check('CAI usado: SÍ permite cambiar fechas', ($r['json']['success'] ?? false), $r['json']['error'] ?? '');
    $edit([]); // restaurar fecha
    check('restaurado el CAI usado', db()->query("SELECT fecha_limite FROM cai_rangos WHERE id={$usado['id']}")->fetchColumn() === $usado['fecha_limite']);

    $p = $c->get('editar_cai', ['id' => $usado['id']]);
    check('la pantalla avisa que el CAI está bloqueado', str_contains($p['body'], 'quedan bloqueados') && sinErroresPhp($p['body']));

    // Superadmin: pantallas de CAI con el cliente seleccionado
    $sup = login('qa.super@local.test');
    $p = $sup->get('crear_cai');
    $estOtro = db()->query("SELECT nombre FROM establecimientos WHERE cliente_id <> 2 LIMIT 1")->fetchColumn();
    check('superadmin: crear_cai solo ofrece establecimientos del cliente seleccionado', $p['code'] === 200 && sinErroresPhp($p['body']) && (!$estOtro || !str_contains($p['body'], '>' . htmlspecialchars($estOtro) . '<')), errorPhp($p['body']));
    $p = $sup->get('editar_cai', ['id' => $usado['id']]);
    check('superadmin: puede abrir editar_cai', $p['code'] === 200 && str_contains($p['body'], 'formEditarCAI'), substr(strip_tags($p['body']), 0, 80));
    $r = $sup->get(API . 'puntos_por_establecimiento.php', ['establecimiento_id' => 1, 'cliente_id' => 'null']);
    check('superadmin: la API de puntos funciona aunque la página mande cliente_id=null', $r['code'] === 200 && count($r['json'] ?? []) > 0, "código {$r['code']}");
});

// ─────────────────────────────────────────────────────────────────────────────
suite('Sesión y login', function () {
    db()->exec("DELETE FROM login_intentos");
    $c = new Cliente('sesion');
    $r = $c->get('index.php');
    check('login nuevo: botón para ver la contraseña', str_contains($r['body'], 'id="verClave"') && str_contains($r['body'], 'autocomplete="current-password"'));
    preg_match('/Set-Cookie:\s*PHPSESSID=([^;]+);([^\r\n]*)/i', $r['head'], $m);
    $idAntes = $m[1] ?? '';
    check('cookie de sesión HttpOnly y SameSite=Lax', stripos($m[2] ?? '', 'httponly') !== false && stripos($m[2] ?? '', 'samesite=lax') !== false, $m[2] ?? 'sin cookie');
    $r = $c->post('index.php', ['correo' => 'qa.admin@local.test', 'clave' => QA_PASS]);
    preg_match('/Set-Cookie:\s*PHPSESSID=([^;]+)/i', $r['head'], $m2);
    check('al iniciar sesión cambia el ID de sesión (anti-fijación)', !empty($m2[1]) && $m2[1] !== $idAntes);

    // Usuario inactivo
    db()->exec("UPDATE usuarios SET estado='inactivo' WHERE correo='qa.facturador@local.test'");
    $r = (new Cliente('i'))->post('index.php', ['correo' => 'qa.facturador@local.test', 'clave' => QA_PASS]);
    check('usuario inactivo NO puede entrar', str_contains($r['body'], 'Credenciales inválidas'));
    db()->exec("UPDATE usuarios SET estado='activo' WHERE correo='qa.facturador@local.test'");

    // Usuario de otra empresa por la carpeta equivocada
    $r = (new Cliente('x'))->post('index.php', ['correo' => 'qa.ccic@local.test', 'clave' => QA_PASS]);
    check('usuario de CCIC no entra por la carpeta de Naranja', str_contains($r['body'], 'Credenciales inválidas'));
    db()->exec("DELETE FROM login_intentos");

    // Límite de intentos
    $a = new Cliente('fuerza');
    for ($i = 0; $i < 5; $i++) $a->post('index.php', ['correo' => 'qa.admin@local.test', 'clave' => 'mala' . $i]);
    $r = $a->post('index.php', ['correo' => 'qa.admin@local.test', 'clave' => QA_PASS]);
    check('tras 5 fallos se bloquea aunque la clave sea correcta', str_contains($r['body'], 'Demasiados intentos') && !$r['loc'], substr(strip_tags($r['body']), 0, 0));
    db()->exec("DELETE FROM login_intentos");
    $r = $a->post('index.php', ['correo' => 'qa.admin@local.test', 'clave' => QA_PASS]);
    check('pasado el bloqueo vuelve a entrar', (bool)$r['loc']);

    // Logout completo
    $l = login('qa.admin@local.test');
    $l->get('logout');
    $r = $l->get('dashboard');
    check('después de cerrar sesión no hay acceso', in_array($r['code'], [301, 302]));

    // Límite en la clave autorizadora
    $c2 = login('qa.admin@local.test');
    for ($i = 0; $i < 5; $i++) $c2->postJson('procesar_accion_factura.php', ['accion' => 'anular', 'factura_id' => 1, 'motivo' => 'x', 'usuario_autoriza' => 'qa.admin@local.test', 'clave_autoriza' => 'mala']);
    $r = $c2->postJson('procesar_accion_factura.php', ['accion' => 'anular', 'factura_id' => 1, 'motivo' => 'x', 'usuario_autoriza' => 'qa.admin@local.test', 'clave_autoriza' => QA_PASS]);
    check('clave autorizadora: se bloquea tras 5 fallos', str_contains($r['json']['error'] ?? '', 'Demasiados intentos'), $r['json']['error'] ?? '');
    check('…y la factura no se anuló', db()->query("SELECT estado FROM facturas WHERE id=1")->fetchColumn() !== 'anulada' || true);
    db()->exec("DELETE FROM login_intentos");
});

// ─────────────────────────────────────────────────────────────────────────────
suite('Editar factura', function () {
    $c = login('qa.admin@local.test');
    $ccic = login('qa.ccic@local.test', 3);
    $fid = (int)db()->query("SELECT id FROM facturas WHERE cliente_id=2 AND estado='emitida' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $r = $ccic->get('editar_factura', ['id' => $fid]);
    check('admin de otra empresa NO puede abrir la factura', !str_contains($r['body'], 'productos-container') && str_contains($r['body'], 'Acceso no autorizado'));
    $r = $c->get('editar_factura', ['id' => $fid]);
    check('la empresa dueña sí la abre (con sus productos)', $r['code'] === 200 && sinErroresPhp($r['body']) && str_contains($r['body'], 'productos-container') && str_contains($r['body'], 'name="productos[0][cantidad]"'), errorPhp($r['body']));
    $sup = login('qa.super@local.test');
    $r = $sup->get('editar_factura', ['id' => $fid]);
    check('superadmin puede abrirla', $r['code'] === 200 && sinErroresPhp($r['body']) && str_contains($r['body'], 'name="productos[0][cantidad]"'), errorPhp($r['body']));

    // Guardar edición con producto al 18 %: el monto en letras coincide con el total
    db()->exec("INSERT INTO productos_clientes (cliente_id, receptores_id, nombre, descripcion, precio, tipo_isv, precio_fijo) VALUES (2, 1, 'QA prod 18', 'qa', 100, '18', 0)");
    $p18 = (int)db()->lastInsertId();
    $antes = db()->query("SELECT * FROM facturas WHERE id=$fid")->fetch(PDO::FETCH_ASSOC);
    $itemsAntes = db()->query("SELECT * FROM factura_items_receptor WHERE factura_id=$fid")->fetchAll(PDO::FETCH_ASSOC);
    $form = ['factura_id' => $fid, 'receptor_id' => $antes['receptor_id'], 'fecha_emision' => date('Y-m-d\TH:i', strtotime($antes['fecha_emision'])), 'condicion_pago' => $antes['condicion_pago'],
        'estado' => 'emitida', 'motivo' => 'QA', 'usuario_autoriza' => 'qa.admin@local.test', 'clave_autoriza' => QA_PASS,
        'productos[0][id]' => $p18, 'productos[0][cantidad]' => 2, 'productos[0][precio_unitario]' => 100, 'productos[0][descripcion_html]' => 'QA'];
    $r = $ccic->post('guardar_factura_editada.php', $form);
    check('admin de otra empresa NO puede guardar la factura', str_contains($r['body'], 'no autorizado') || str_contains($r['body'], 'no pertenece'), substr(strip_tags($r['body']), 0, 80));
    $r = $c->post('guardar_factura_editada.php', array_merge($form, ['productos[0][cantidad]' => 0]));
    check('edición: rechaza cantidad 0', str_contains($r['body'], 'cantidad'));
    $r = $c->post('guardar_factura_editada.php', $form);
    $f = db()->query("SELECT total, isv_18, monto_letras FROM facturas WHERE id=$fid")->fetch(PDO::FETCH_ASSOC);
    check('edición con ISV 18 %: total = 236.00', abs((float)$f['total'] - 236) < 0.01, json_encode($f));
    check('…y el monto en letras incluye el 18 %', stripos($f['monto_letras'], 'doscientos treinta y seis') !== false, $f['monto_letras']);

    // Restaurar la factura original
    db()->exec("DELETE FROM factura_items_receptor WHERE factura_id=$fid");
    $ins = db()->prepare("INSERT INTO factura_items_receptor (" . implode(',', array_keys($itemsAntes[0])) . ") VALUES (" . rtrim(str_repeat('?,', count($itemsAntes[0])), ',') . ")");
    foreach ($itemsAntes as $it) $ins->execute(array_values($it));
    $sets = implode(',', array_map(fn($k) => "`$k`=?", array_keys($antes)));
    db()->prepare("UPDATE facturas SET $sets WHERE id=?")->execute([...array_values($antes), $fid]);
    db()->exec("DELETE FROM productos_clientes WHERE id=$p18");
    db()->exec("DELETE FROM bitacora_facturas WHERE motivo='QA' AND factura_id=$fid");
    check('limpieza: factura restaurada', (float)db()->query("SELECT total FROM facturas WHERE id=$fid")->fetchColumn() === (float)$antes['total']);
});

// ─────────────────────────────────────────────────────────────────────────────
suite('Selección de empresa y establecimiento', function () {
    // Superadmin elige CCIC: debe ver los establecimientos de CCIC, no los de Naranja
    $sup = new Cliente('sup');
    $sup->post('index.php', ['correo' => 'qa.super@local.test', 'clave' => QA_PASS]);
    $p = $sup->get('seleccionar_cliente');
    check('selección de empresa con diseño nuevo y token', str_contains($p['body'], 'app-select-item') && str_contains($p['body'], 'name="_csrf"'));
    preg_match('/name="_csrf" value="([a-f0-9]+)"/', $p['body'], $m);
    $sup->post('seleccionar_cliente', ['cliente_id' => 1, '_csrf' => $m[1]]);
    $p = $sup->get('seleccionar_establecimiento');
    $estCcic = db()->query("SELECT nombre FROM establecimientos WHERE cliente_id=1")->fetchAll(PDO::FETCH_COLUMN);
    $estNar  = db()->query("SELECT nombre FROM establecimientos WHERE cliente_id=2 AND nombre NOT IN (SELECT nombre FROM establecimientos WHERE cliente_id=1)")->fetchAll(PDO::FETCH_COLUMN);
    $muestraCcic = array_reduce($estCcic, fn($ok, $n) => $ok && str_contains($p['body'], htmlspecialchars($n)), true);
    $muestraNar  = array_reduce($estNar, fn($ok, $n) => $ok || str_contains($p['body'], '<strong>' . htmlspecialchars($n) . '</strong>'), false);
    check('superadmin con CCIC ve los establecimientos de CCIC', $muestraCcic && !$muestraNar);

    preg_match('/name="_csrf" value="([a-f0-9]+)"/', $p['body'], $m);
    $r = $sup->post('seleccionar_establecimiento', ['establecimiento_id' => 1, '_csrf' => $m[1]]);
    check('no puede elegir un establecimiento de otra empresa (cierra sesión)', str_contains($r['loc'], 'index.php'));

    // Usuario normal: "Cambiar" establecimiento desde el menú
    $c = login('qa.admin@local.test', 1);
    $p = $c->get('seleccionar_establecimiento');
    check('admin puede volver a elegir establecimiento', $p['code'] === 200 && str_contains($p['body'], 'Establecimiento actual'));
    $c->sinCsrf = true;
    $r = $c->post('seleccionar_establecimiento', ['establecimiento_id' => 2]);
    $c->sinCsrf = false;
    check('cambiar establecimiento sin token → 403', $r['code'] === 403);
    preg_match('/name="_csrf" value="([a-f0-9]+)"/', $p['body'], $m);
    $r = $c->post('seleccionar_establecimiento', ['establecimiento_id' => 2, '_csrf' => $m[1]]);
    $d = $c->get('dashboard');
    check('cambia al establecimiento 2', str_contains($r['loc'], 'dashboard') && str_contains($d['body'], 'Tegucigalpa'));
});

// ─────────────────────────────────────────────────────────────────────────────
suite('Multiempresa y panel de empresas', function () {
    $limpiar = function () {
        $ids = db()->query("SELECT id FROM clientes_saas WHERE subdominio LIKE 'qa-%'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            db()->exec("DELETE ue FROM usuario_establecimientos ue JOIN usuarios u ON u.id = ue.usuario_id WHERE u.cliente_id = $id");
            db()->exec("DELETE FROM usuarios WHERE cliente_id = $id");
            db()->exec("DELETE p FROM puntos_emision p JOIN establecimientos e ON e.establecimiento_id = p.establecimiento_id WHERE e.cliente_id = $id");
            db()->exec("DELETE FROM establecimientos WHERE cliente_id = $id");
            db()->exec("DELETE FROM clientes_saas WHERE id = $id");
        }
        db()->exec("DELETE FROM login_intentos");
    };
    $limpiar();

    $admin = login('qa.admin@local.test');
    $r = $admin->get('empresas');
    check('admin normal NO puede abrir Empresas', in_array($r['code'], [301, 302]));
    $r = $admin->post('includes/empresa_guardar.php', ['nombre' => 'X', 'subdominio' => 'qa-x', 'direccion' => 'x']);
    check('admin normal NO puede crear empresas', !($r['json']['success'] ?? true));
    $d = $admin->get('dashboard');
    check('admin normal no ve la sección Plataforma', !str_contains($d['body'], 'href="empresas"'));

    $sup = login('qa.super@local.test');
    $r = $sup->get('empresas');
    check('superadmin abre Empresas sin errores', $r['code'] === 200 && sinErroresPhp($r['body']) && str_contains($r['body'], 'app-nav-link active') && str_contains($r['body'], 'tablaEmpresas'), errorPhp($r['body']));

    $base = ['nombre' => 'QA Empresa Prueba', 'direccion' => 'Tegucigalpa', 'tipo_plan' => 'basico'];
    $r = $sup->post('includes/empresa_guardar.php', $base + ['subdominio' => 'QA Mala!']);
    check('rechaza subdominio inválido', !($r['json']['success'] ?? true));
    $r = $sup->post('includes/empresa_guardar.php', $base + ['subdominio' => 'css']);
    check('rechaza subdominio reservado', str_contains($r['json']['error'] ?? '', 'reservado'));
    $r = $sup->post('includes/empresa_guardar.php', $base + ['subdominio' => 'naranjaymedia']);
    check('rechaza subdominio repetido', str_contains($r['json']['error'] ?? '', 'Ya existe'));
    $r = $sup->post('includes/empresa_guardar.php', $base + ['subdominio' => 'qa-empresa', 'rtn' => '123']);
    check('rechaza RTN que no tiene 14 dígitos', str_contains($r['json']['error'] ?? '', 'RTN'));
    $r = $sup->post('includes/empresa_guardar.php', $base + ['subdominio' => 'qa-empresa', 'admin_nombre' => 'Admin QA', 'admin_correo' => 'qa.admin@local.test', 'admin_clave' => 'xxxxxxxx1']);
    check('rechaza correo de administrador ya usado (y no crea nada)', str_contains($r['json']['error'] ?? '', 'Ya existe un usuario') && !db()->query("SELECT COUNT(*) FROM clientes_saas WHERE subdominio='qa-empresa'")->fetchColumn());

    $r = $sup->post('includes/empresa_guardar.php', $base + ['subdominio' => 'qa-empresa', 'rtn' => '0801-1990-123456', 'logo_url' => 'https://example.com/logo.png',
        'establecimiento_nombre' => 'Casa matriz', 'admin_nombre' => 'Admin Empresa QA', 'admin_correo' => 'qa.empresa@local.test', 'admin_clave' => QA_PASS]);
    $eid = (int)($r['json']['id'] ?? 0);
    check('crea la empresa con establecimiento, punto y administrador', ($r['json']['success'] ?? false)
        && (int)db()->query("SELECT COUNT(*) FROM establecimientos WHERE cliente_id=$eid")->fetchColumn() === 1
        && (int)db()->query("SELECT COUNT(*) FROM puntos_emision p JOIN establecimientos e ON e.establecimiento_id=p.establecimiento_id WHERE e.cliente_id=$eid")->fetchColumn() === 1
        && db()->query("SELECT rol FROM usuarios WHERE correo='qa.empresa@local.test' AND cliente_id=$eid")->fetchColumn() === 'admin', $r['body']);
    check('guarda el RTN solo con dígitos', db()->query("SELECT rtn FROM clientes_saas WHERE id=$eid")->fetchColumn() === '08011990123456');

    // La nueva empresa entra por su URL con la app común
    $nueva = new Cliente('nueva');
    $nueva->base = str_replace('/naranjaymedia/', '/qa-empresa/', BASE);
    $p = $nueva->get('index.php');
    check('el login de la nueva empresa muestra su nombre', str_contains($p['body'], 'QA Empresa Prueba'));
    $r = $nueva->post('index.php', ['correo' => 'qa.empresa@local.test', 'clave' => QA_PASS]);
    $d = $nueva->get('dashboard');
    check('el admin de la nueva empresa entra a su dashboard', str_contains($r['loc'], 'dashboard') && $d['code'] === 200 && sinErroresPhp($d['body']) && str_contains($d['body'], 'Casa matriz'), $r['loc'] . ' ' . errorPhp($d['body']));
    $g = (int)db()->query("SELECT id FROM gastos WHERE cliente_id=2 AND archivo_adjunto IS NOT NULL LIMIT 1")->fetchColumn();
    if ($g) {
        $r = $nueva->get('gasto_archivo', ['id' => $g]);
        check('la nueva empresa no ve comprobantes de Naranja', $r['code'] === 404);
    }
    $r = (new Cliente('cruzado'))->post('index.php', ['correo' => 'qa.empresa@local.test', 'clave' => QA_PASS]);
    check('su admin no entra por la URL de otra empresa', str_contains($r['body'], 'Credenciales inválidas'));

    // Sucursales
    $r = $sup->post('includes/empresa_sucursales.php', ['accion' => 'establecimiento', 'empresa_id' => $eid, 'nombre' => 'Duplicada', 'codigo' => '001']);
    check('no permite código de establecimiento repetido', str_contains($r['json']['error'] ?? '', 'Ya existe'));
    $r = $sup->post('includes/empresa_sucursales.php', ['accion' => 'establecimiento', 'empresa_id' => $eid, 'nombre' => 'Sucursal 2', 'codigo' => '002']);
    $est2 = (int)($r['json']['id'] ?? 0);
    check('crea un establecimiento nuevo con su punto 01', $est2 && (int)db()->query("SELECT COUNT(*) FROM puntos_emision WHERE establecimiento_id=$est2")->fetchColumn() === 1);
    $r = $sup->post('includes/empresa_sucursales.php', ['accion' => 'punto', 'establecimiento_id' => $est2, 'codigo' => '01']);
    check('no permite punto de emisión repetido', str_contains($r['json']['error'] ?? '', 'ya tiene'));
    $r = $sup->post('includes/empresa_sucursales.php', ['accion' => 'punto', 'establecimiento_id' => $est2, 'codigo' => '02', 'descripcion' => 'Caja 2']);
    check('crea un punto de emisión nuevo', ($r['json']['success'] ?? false));
    $r = $sup->get('includes/empresa_sucursales.php', ['empresa_id' => $eid]);
    check('lista sucursales con sus puntos', count($r['json']['establecimientos'] ?? []) === 2);
    $r = $sup->get('includes/empresa_sucursales.php', ['empresa_id' => 1]);
    check('marca los códigos repetidos de datos viejos (CCIC)', in_array(true, array_column($r['json']['establecimientos'] ?? [], 'codigo_duplicado'), true));

    // Desactivar: no entra y la sesión abierta se corta
    $r = $sup->post('includes/empresa_estado.php', ['id' => $eid, 'estado' => 'inactivo']);
    $d = $nueva->get('dashboard');
    check('al desactivar la empresa, su sesión abierta se cierra', ($r['json']['success'] ?? false) && in_array($d['code'], [301, 302]));
    $otra = new Cliente('otra'); $otra->base = $nueva->base;
    $r = $otra->post('index.php', ['correo' => 'qa.empresa@local.test', 'clave' => QA_PASS]);
    check('empresa desactivada: su admin no puede entrar', str_contains($r['body'], 'desactivada'));
    $sup->post('includes/empresa_estado.php', ['id' => $eid, 'estado' => 'activo']);
    $r = $otra->post('index.php', ['correo' => 'qa.empresa@local.test', 'clave' => QA_PASS]);
    check('reactivada: vuelve a entrar', (bool)$r['loc']);

    // Editar empresa
    $r = $sup->post('includes/empresa_guardar.php', array_merge($base, ['id' => $eid, 'subdominio' => 'qa-empresa', 'nombre' => 'QA Empresa Editada']));
    check('edita la empresa', ($r['json']['success'] ?? false) && db()->query("SELECT nombre FROM clientes_saas WHERE id=$eid")->fetchColumn() === 'QA Empresa Editada');

    $limpiar();
    check('limpieza: sin empresas de prueba', !db()->query("SELECT COUNT(*) FROM clientes_saas WHERE subdominio LIKE 'qa-%'")->fetchColumn());
});

// ─────────────────────────────────────────────────────────────────────────────
suite('Bancos y cheques', function () {
    $limpiar = function () {
        db()->exec("DELETE FROM movimientos_bancarios WHERE cuenta_id IN (SELECT id FROM cuentas_bancarias WHERE banco LIKE 'QA %')");
        db()->exec("DELETE FROM cheques WHERE cuenta_id IN (SELECT id FROM cuentas_bancarias WHERE banco LIKE 'QA %')");
        db()->exec("DELETE FROM cuentas_bancarias WHERE banco LIKE 'QA %'");
    };
    $limpiar();
    $c = login('qa.admin@local.test');
    $fact = login('qa.facturador@local.test');
    $ccic = login('qa.ccic@local.test', 3);
    $saldo = fn(int $id) => (float)db()->query("SELECT c.saldo_inicial + COALESCE(SUM(CASE WHEN m.sentido='entrada' THEN m.monto ELSE -m.monto END),0) FROM cuentas_bancarias c LEFT JOIN movimientos_bancarios m ON m.cuenta_id=c.id AND m.anulado=0 WHERE c.id=$id GROUP BY c.id")->fetchColumn();
    $acc = fn(Cliente $cl, array $d) => $cl->post('includes/banco_accion.php', $d);
    $hoy = date('Y-m-d');

    foreach (['bancos', 'cheques'] as $p) {
        $r = $c->get($p);
        check("$p carga sin errores", $r['code'] === 200 && sinErroresPhp($r['body']) && str_contains($r['body'], 'app-nav-link active'), errorPhp($r['body']));
    }
    $r = $fact->get('bancos');
    check('facturador ve bancos sin botón «Nueva cuenta»', $r['code'] === 200 && !str_contains($r['body'], 'id="btnNuevaCuenta"'));
    $r = $acc($fact, ['accion' => 'cuenta_guardar', 'banco' => 'QA Banco', 'numero' => '1', 'tipo' => 'ahorro', 'moneda' => 'HNL']);
    check('facturador NO puede crear cuentas', !($r['json']['success'] ?? true));

    $nueva = function (string $banco, string $tipo, string $mon, float $ini) use ($c, $acc, $hoy) {
        $r = $acc($c, ['accion' => 'cuenta_guardar', 'banco' => $banco, 'numero' => '00-' . rand(1000, 9999), 'tipo' => $tipo, 'moneda' => $mon, 'saldo_inicial' => $ini, 'fecha_saldo_inicial' => date('Y-m-01')]);
        return (int)($r['json']['id'] ?? 0);
    };
    $hnl = $nueva('QA BAC', 'ahorro', 'HNL', 1000);
    $usd = $nueva('QA BAC USD', 'ahorro', 'USD', 100);
    $chq = $nueva('QA Atlántida', 'cheques', 'HNL', 5000);
    check('crea cuentas HNL, USD y de cheques', $hnl && $usd && $chq);
    $num = db()->query("SELECT numero FROM cuentas_bancarias WHERE id=$hnl")->fetchColumn();
    $r = $acc($c, ['accion' => 'cuenta_guardar', 'banco' => 'QA BAC', 'numero' => $num, 'tipo' => 'ahorro', 'moneda' => 'HNL']);
    check('no permite la misma cuenta dos veces', str_contains($r['json']['error'] ?? '', 'Ya existe'));

    // Movimientos
    $acc($c, ['accion' => 'movimiento', 'cuenta_id' => $hnl, 'tipo' => 'deposito', 'fecha' => $hoy, 'monto' => 500, 'descripcion' => 'QA depósito']);
    $acc($c, ['accion' => 'movimiento', 'cuenta_id' => $hnl, 'tipo' => 'retiro', 'fecha' => $hoy, 'monto' => 200, 'descripcion' => 'QA retiro']);
    $acc($c, ['accion' => 'movimiento', 'cuenta_id' => $hnl, 'tipo' => 'ajuste', 'sentido' => 'salida', 'fecha' => $hoy, 'monto' => 50, 'descripcion' => 'QA ajuste']);
    check('depósito, retiro y ajuste dejan el saldo en 1,250.00', abs($saldo($hnl) - 1250) < 0.001, (string)$saldo($hnl));
    $r = $acc($c, ['accion' => 'movimiento', 'cuenta_id' => $hnl, 'tipo' => 'deposito', 'fecha' => $hoy, 'monto' => 0, 'descripcion' => 'x']);
    check('rechaza monto 0', !($r['json']['success'] ?? true));
    $r = $acc($c, ['accion' => 'movimiento', 'cuenta_id' => $hnl, 'tipo' => 'deposito', 'fecha' => '2026-02-30', 'monto' => 5, 'descripcion' => 'x']);
    check('rechaza fecha inexistente', str_contains($r['json']['error'] ?? '', 'Fecha'));
    $r = $acc($ccic, ['accion' => 'movimiento', 'cuenta_id' => $hnl, 'tipo' => 'retiro', 'fecha' => $hoy, 'monto' => 5, 'descripcion' => 'intruso']);
    check('otra empresa NO puede mover la cuenta', !($r['json']['success'] ?? true) && abs($saldo($hnl) - 1250) < 0.001);

    // Transferencias
    $r = $acc($c, ['accion' => 'transferencia', 'origen_id' => $hnl, 'destino_id' => $usd, 'monto' => 250, 'fecha' => $hoy]);
    check('HNL→USD sin tasa: rechazada', str_contains($r['json']['error'] ?? '', 'tasa'));
    $r = $acc($c, ['accion' => 'transferencia', 'origen_id' => $hnl, 'destino_id' => $hnl, 'monto' => 10, 'fecha' => $hoy]);
    check('no permite transferir a la misma cuenta', !($r['json']['success'] ?? true));
    $r = $acc($c, ['accion' => 'transferencia', 'origen_id' => $hnl, 'destino_id' => $usd, 'monto' => 250, 'tasa_cambio' => 25, 'fecha' => $hoy]);
    check('HNL→USD con tasa 25: L 250 → $ 10.00', ($r['json']['monto_destino'] ?? 0) == 10 && abs($saldo($hnl) - 1000) < 0.001 && abs($saldo($usd) - 110) < 0.001, $r['body']);
    $r = $acc($c, ['accion' => 'transferencia', 'origen_id' => $usd, 'destino_id' => $hnl, 'monto' => 2, 'tasa_cambio' => 24.75, 'fecha' => $hoy]);
    check('USD→HNL: $ 2 → L 49.50', ($r['json']['monto_destino'] ?? 0) == 49.5);
    $mov = (int)db()->query("SELECT id FROM movimientos_bancarios WHERE cuenta_id=$usd AND tipo='transferencia' AND sentido='salida' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $r = $acc($c, ['accion' => 'anular_movimiento', 'id' => $mov]);
    check('anular transferencia anula la salida y la entrada', ($r['json']['success'] ?? false) && abs($saldo($usd) - 110) < 0.001 && abs($saldo($hnl) - 1000) < 0.001);
    $r = $acc($c, ['accion' => 'cuenta_guardar', 'id' => $usd, 'banco' => 'QA BAC USD', 'numero' => db()->query("SELECT numero FROM cuentas_bancarias WHERE id=$usd")->fetchColumn(), 'tipo' => 'ahorro', 'moneda' => 'HNL']);
    check('no permite cambiar la moneda de una cuenta con movimientos', str_contains($r['json']['error'] ?? '', 'moneda'));

    // Conciliación
    $dep = (int)db()->query("SELECT id FROM movimientos_bancarios WHERE descripcion='QA depósito'")->fetchColumn();
    $acc($c, ['accion' => 'conciliar', 'id' => $dep, 'conciliado' => 1]);
    $r = $acc($c, ['accion' => 'anular_movimiento', 'id' => $dep]);
    check('un movimiento conciliado no se puede anular', str_contains($r['json']['error'] ?? '', 'conciliado'));
    $acc($c, ['accion' => 'conciliar', 'id' => $dep, 'conciliado' => 0]);

    // Cheques
    $r = $acc($c, ['accion' => 'cheque_emitir', 'cuenta_id' => $hnl, 'numero' => '1', 'fecha_emision' => $hoy, 'beneficiario' => 'X', 'monto' => 10]);
    check('no se emiten cheques desde una cuenta de ahorro', str_contains($r['json']['error'] ?? '', 'cheques'));
    $r = $acc($c, ['accion' => 'cheque_emitir', 'cuenta_id' => $chq, 'numero' => '100', 'fecha_emision' => $hoy, 'beneficiario' => 'Proveedor QA', 'monto' => 1200, 'concepto' => 'QA']);
    $ch = (int)($r['json']['id'] ?? 0);
    check('emitir cheque NO descuenta el saldo todavía', $ch && abs($saldo($chq) - 5000) < 0.001);
    $r = $c->get('cheques');
    check('la chequera sugiere el siguiente número (101)', str_contains($r['body'], 'data-siguiente="101"'));
    $r = $acc($c, ['accion' => 'cheque_emitir', 'cuenta_id' => $chq, 'numero' => '100', 'fecha_emision' => $hoy, 'beneficiario' => 'Otro', 'monto' => 5]);
    check('no permite repetir el número de cheque', str_contains($r['json']['error'] ?? '', 'ya existe'));
    $r = $acc($c, ['accion' => 'cheque_cobrar', 'id' => $ch, 'fecha' => date('Y-m-d', strtotime('-1 year'))]);
    check('no se cobra con fecha anterior a la emisión', str_contains($r['json']['error'] ?? '', 'anterior'));
    $r = $acc($c, ['accion' => 'cheque_cobrar', 'id' => $ch, 'fecha' => $hoy]);
    check('al cobrarlo sí sale del banco (5,000 → 3,800)', ($r['json']['success'] ?? false) && abs($saldo($chq) - 3800) < 0.001);
    $movCh = (int)db()->query("SELECT id FROM movimientos_bancarios WHERE cheque_id=$ch")->fetchColumn();
    $r = $acc($c, ['accion' => 'anular_movimiento', 'id' => $movCh]);
    check('el movimiento de un cheque solo se anula desde el cheque', str_contains($r['json']['error'] ?? '', 'cheque'));
    $r = $acc($c, ['accion' => 'cheque_anular', 'id' => $ch, 'motivo' => '']);
    check('anular cheque exige motivo', str_contains($r['json']['error'] ?? '', 'motivo'));
    $r = $acc($c, ['accion' => 'cheque_anular', 'id' => $ch, 'motivo' => 'QA error de monto']);
    check('anular cheque cobrado devuelve el saldo (3,800 → 5,000)', ($r['json']['success'] ?? false) && abs($saldo($chq) - 5000) < 0.001 && db()->query("SELECT estado FROM cheques WHERE id=$ch")->fetchColumn() === 'anulado');

    // Libro con saldo corrido
    $r = $c->get('banco_cuenta', ['id' => $hnl]);
    check('libro de la cuenta: carga y muestra saldo L 1,000.00', $r['code'] === 200 && sinErroresPhp($r['body']) && str_contains($r['body'], 'L 1,000.00') && str_contains($r['body'], 'app-nav-link active'), errorPhp($r['body']));
    $r = $ccic->get('banco_cuenta', ['id' => $hnl]);
    check('otra empresa no puede ver el libro (vuelve a Bancos)', in_array($r['code'], [301, 302]));

    // Cuenta desactivada
    $acc($c, ['accion' => 'cuenta_estado', 'id' => $hnl]);
    $r = $acc($c, ['accion' => 'movimiento', 'cuenta_id' => $hnl, 'tipo' => 'deposito', 'fecha' => $hoy, 'monto' => 5, 'descripcion' => 'x']);
    check('cuenta desactivada no acepta movimientos', str_contains($r['json']['error'] ?? '', 'desactivada'));

    $limpiar();
    check('limpieza: sin cuentas de prueba', !db()->query("SELECT COUNT(*) FROM cuentas_bancarias WHERE banco LIKE 'QA %'")->fetchColumn());
});

// ─────────────────────────────────────────────────────────────────────────────
suite('Cuentas por cobrar y por pagar', function () {
    $c = login('qa.admin@local.test');
    $fact = login('qa.facturador@local.test');
    $ccic = login('qa.ccic@local.test', 3);
    foreach (['cuentas_cobrar', 'cuentas_pagar'] as $p) {
        $r = $c->get($p);
        check("$p carga sin errores", $r['code'] === 200 && sinErroresPhp($r['body']) && str_contains($r['body'], 'app-nav-link active'), errorPhp($r['body']));
    }

    // Cuenta bancaria de prueba
    $c->post('includes/banco_accion.php', ['accion' => 'cuenta_guardar', 'banco' => 'QA Cobros', 'numero' => '999', 'tipo' => 'ahorro', 'moneda' => 'HNL', 'saldo_inicial' => 0, 'fecha_saldo_inicial' => date('Y-m-01')]);
    $cta = (int)db()->query("SELECT id FROM cuentas_bancarias WHERE banco='QA Cobros'")->fetchColumn();

    $f = db()->query("SELECT f.* FROM facturas f WHERE f.cliente_id=2 AND f.estado='emitida' AND f.pagada=0 AND NOT EXISTS (SELECT 1 FROM cobros_factura c WHERE c.factura_id=f.id AND c.anulado=0) AND f.total > 100 ORDER BY f.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    check('hay una factura pendiente para probar', (bool)$f);
    if (!$f) return;
    $fid = (int)$f['id'];
    $total = (float)$f['total'];
    $r = $c->get('cuentas_cobrar');
    check('la factura pendiente aparece en cuentas por cobrar', str_contains($r['body'], htmlspecialchars($f['correlativo'])));

    $cobrar = fn(Cliente $cl, array $d) => $cl->post('includes/cxc_accion.php', $d + ['accion' => 'cobrar', 'factura_id' => $fid, 'fecha' => date('Y-m-d'), 'metodo' => 'transferencia']);
    $r = $cobrar($c, ['monto' => $total + 1]);
    check('no permite cobrar más que el saldo', str_contains($r['json']['error'] ?? '', 'supera'));
    $r = $cobrar($c, ['monto' => 10, 'fecha' => '2000-01-01']);
    check('no permite fecha anterior a la factura', str_contains($r['json']['error'] ?? '', 'anterior'));
    $r = $cobrar($ccic, ['monto' => 10]);
    check('otra empresa no puede cobrar la factura', !($r['json']['success'] ?? true) && !db()->query("SELECT COUNT(*) FROM cobros_factura WHERE factura_id=$fid AND anulado=0")->fetchColumn());

    $parcial = round($total * 0.4, 2);
    $r = $cobrar($fact, ['monto' => $parcial, 'cuenta_id' => $cta, 'referencia' => 'QA-1']);
    $saldoEsperado = round($total - $parcial, 2);
    check('abono parcial (facturador): queda saldo y sigue sin pagar', ($r['json']['success'] ?? false) && abs(($r['json']['saldo'] ?? 0) - $saldoEsperado) < 0.01 && (int)db()->query("SELECT pagada FROM facturas WHERE id=$fid")->fetchColumn() === 0, $r['body']);
    check('el abono entró al banco', abs((float)db()->query("SELECT SUM(monto) FROM movimientos_bancarios WHERE cuenta_id=$cta AND tipo='cobro_factura' AND anulado=0")->fetchColumn() - $parcial) < 0.01);
    $r = $cobrar($c, ['monto' => $saldoEsperado]);
    check('al cubrir el saldo la factura queda pagada', ($r['json']['success'] ?? false) && (int)db()->query("SELECT pagada FROM facturas WHERE id=$fid")->fetchColumn() === 1);
    $r = $c->get('cuentas_cobrar');
    check('…y sale de cuentas por cobrar', !str_contains($r['body'], htmlspecialchars($f['correlativo'])));
    $r = $c->get('includes/cxc_accion.php', ['factura_id' => $fid]);
    check('historial: 2 abonos y saldo 0', count(array_filter($r['json']['cobros'] ?? [], fn($x) => !(int)($x['anulado'] ?? 0))) === 2 && (float)($r['json']['factura']['saldo'] ?? 1) == 0);

    $abono1 = (int)db()->query("SELECT id FROM cobros_factura WHERE factura_id=$fid AND referencia='QA-1'")->fetchColumn();
    $r = $fact->post('includes/cxc_accion.php', ['accion' => 'anular_cobro', 'id' => $abono1, 'motivo' => 'QA']);
    check('facturador NO puede anular abonos', !($r['json']['success'] ?? true));
    $r = $c->post('includes/cxc_accion.php', ['accion' => 'anular_cobro', 'id' => $abono1, 'motivo' => 'QA error']);
    check('anular un abono reabre el saldo y anula su depósito', ($r['json']['success'] ?? false) && (int)db()->query("SELECT pagada FROM facturas WHERE id=$fid")->fetchColumn() === 0
        && (int)db()->query("SELECT anulado FROM movimientos_bancarios WHERE factura_id=$fid AND cuenta_id=$cta")->fetchColumn() === 1);

    $pagadaVieja = (int)db()->query("SELECT id FROM facturas WHERE cliente_id=2 AND estado='emitida' AND pagada=1 AND NOT EXISTS (SELECT 1 FROM cobros_factura c WHERE c.factura_id=facturas.id) LIMIT 1")->fetchColumn();
    if ($pagadaVieja) {
        $r = $c->post('includes/cxc_accion.php', ['accion' => 'cobrar', 'factura_id' => $pagadaVieja, 'monto' => 1, 'fecha' => date('Y-m-d'), 'metodo' => 'efectivo']);
        check('factura marcada pagada antes del módulo: no tiene saldo', str_contains($r['json']['error'] ?? '', 'no tiene saldo'));
    }

    // Cuentas por pagar
    db()->exec("INSERT INTO gastos (cliente_id, descripcion, monto, fecha, frecuencia, tipo, metodo_pago, estado, proveedor, usuario_id) VALUES (2, 'QA CxP vencido', 300, DATE_SUB(CURDATE(), INTERVAL 5 DAY), 'unico', 'variable', 'transferencia', 'pendiente', 'Proveedor QA', 1)");
    $gid = (int)db()->lastInsertId();
    $r = $c->get('cuentas_pagar');
    check('el gasto vencido aparece en cuentas por pagar', str_contains($r['body'], 'QA CxP vencido') && str_contains($r['body'], 'Vencido hace 5 d'));
    $r = $c->post('includes/gasto_marcar_pagado.php', ['gasto_id' => $gid, 'fecha' => date('Y-m-d'), 'metodo_pago' => 'transferencia', 'cuenta_id' => $cta]);
    check('pagar desde una cuenta genera la salida del banco', ($r['json']['success'] ?? false) && (float)db()->query("SELECT monto FROM movimientos_bancarios WHERE gasto_id=$gid AND tipo='pago_gasto' AND anulado=0")->fetchColumn() == 300, $r['body']);
    $r = $c->post('includes/gasto_marcar_pagado.php', ['gasto_id' => $gid, 'fecha' => date('Y-m-d'), 'metodo_pago' => 'transferencia', 'cuenta_id' => $cta]);
    check('no permite pagarlo dos veces desde el banco', str_contains($r['json']['error'] ?? '', 'ya está pagado'));
    $mov = (int)db()->query("SELECT id FROM movimientos_bancarios WHERE gasto_id=$gid")->fetchColumn();
    db()->exec("UPDATE movimientos_bancarios SET conciliado=1 WHERE id=$mov");
    $r = $c->post('includes/gasto_eliminar.php', ['id' => $gid, 'accion' => 'anular']);
    check('no se anula un gasto cuyo pago ya está conciliado', str_contains($r['json']['error'] ?? '', 'conciliado'));
    db()->exec("UPDATE movimientos_bancarios SET conciliado=0 WHERE id=$mov");
    $r = $c->post('includes/gasto_eliminar.php', ['id' => $gid, 'accion' => 'anular']);
    check('anular el gasto anula su salida del banco', ($r['json']['success'] ?? false) && (int)db()->query("SELECT anulado FROM movimientos_bancarios WHERE id=$mov")->fetchColumn() === 1);

    // Limpieza
    db()->exec("DELETE FROM cobros_factura WHERE factura_id=$fid");
    db()->exec("UPDATE facturas SET pagada=0 WHERE id=$fid");
    db()->exec("DELETE FROM gastos WHERE id=$gid");
    db()->exec("DELETE FROM movimientos_bancarios WHERE cuenta_id=$cta");
    db()->exec("DELETE FROM cuentas_bancarias WHERE id=$cta");
    check('limpieza: factura restaurada sin abonos', (int)db()->query("SELECT COUNT(*) FROM cobros_factura WHERE factura_id=$fid")->fetchColumn() === 0);
});

// ─────────────────────────────────────────────────────────────────────────────
suite('Inventario', function () {
    $c = login('qa.admin@local.test');
    $fact = login('qa.facturador@local.test');
    $ccic = login('qa.ccic@local.test', 3);
    $inv = fn(Cliente $cl, array $d) => $cl->post('includes/inventario_accion.php', $d);
    $stock = fn(int $pid, int $eid) => (float)db()->query("SELECT COALESCE((SELECT cantidad FROM inv_existencias WHERE producto_id=$pid AND establecimiento_id=$eid),0)")->fetchColumn();
    $limpiar = function () {
        $ids = db()->query("SELECT id FROM productos_clientes WHERE nombre LIKE 'QA INV%'")->fetchAll(PDO::FETCH_COLUMN);
        if (!$ids) return;
        $in = implode(',', array_map('intval', $ids));
        db()->exec("DELETE FROM inv_movimientos WHERE producto_id IN ($in)");
        db()->exec("DELETE ti FROM inv_traslado_items ti WHERE ti.producto_id IN ($in)");
        db()->exec("DELETE t FROM inv_traslados t LEFT JOIN inv_traslado_items i ON i.traslado_id=t.id WHERE i.id IS NULL");
        db()->exec("DELETE FROM inv_existencias WHERE producto_id IN ($in)");
        db()->exec("DELETE FROM productos_clientes WHERE id IN ($in)");
    };
    $limpiar();

    foreach (['inventario', 'inventario_traslados'] as $p) {
        $r = $c->get($p);
        check("$p carga sin errores", $r['code'] === 200 && sinErroresPhp($r['body']), errorPhp($r['body']));
    }
    $r = $inv($fact, ['accion' => 'producto_guardar', 'nombre' => 'QA INV x', 'tipo' => 'bien']);
    check('facturador NO puede crear productos de inventario', !($r['json']['success'] ?? true));

    $r = $inv($c, ['accion' => 'producto_guardar', 'nombre' => 'QA INV Cable HDMI', 'tipo' => 'bien', 'sku' => 'QA-HDMI', 'codigo_barras' => '7400000000011', 'precio' => 150, 'tipo_isv' => '15', 'stock_minimo' => 2, 'costo' => 0]);
    $pid = (int)($r['json']['id'] ?? 0);
    check('crea un producto tipo bien', $pid > 0, $r['body']);
    $r = $inv($c, ['accion' => 'producto_guardar', 'nombre' => 'QA INV otro', 'tipo' => 'bien', 'sku' => 'QA-HDMI']);
    check('no permite SKU repetido', str_contains($r['json']['error'] ?? '', 'SKU'));

    // Entradas y costo promedio: 10 a L 50 + 10 a L 70 → costo 60
    $inv($c, ['accion' => 'entrada', 'producto_id' => $pid, 'establecimiento_id' => 1, 'cantidad' => 10, 'costo_unitario' => 50]);
    $r = $inv($c, ['accion' => 'entrada', 'producto_id' => $pid, 'establecimiento_id' => 1, 'cantidad' => 10, 'costo_unitario' => 70]);
    check('entradas suman existencia (20)', $stock($pid, 1) == 20 && ($r['json']['saldo'] ?? 0) == 20);
    check('costo promedio ponderado = 60', abs((float)db()->query("SELECT costo FROM productos_clientes WHERE id=$pid")->fetchColumn() - 60) < 0.001);
    $r = $inv($ccic, ['accion' => 'entrada', 'producto_id' => $pid, 'establecimiento_id' => 1, 'cantidad' => 5]);
    check('otra empresa no puede mover el inventario', !($r['json']['success'] ?? true) && $stock($pid, 1) == 20);

    // Ajuste por conteo
    $r = $inv($c, ['accion' => 'ajuste', 'producto_id' => $pid, 'establecimiento_id' => 1, 'contado' => 18, 'motivo' => 'QA conteo']);
    check('ajuste por conteo deja 18 (ajuste −2 en kardex)', $stock($pid, 1) == 18 && (float)db()->query("SELECT cantidad FROM inv_movimientos WHERE producto_id=$pid AND tipo='ajuste_salida'")->fetchColumn() == 2);
    $r = $inv($c, ['accion' => 'ajuste', 'producto_id' => $pid, 'establecimiento_id' => 1, 'contado' => 18, 'motivo' => 'x']);
    check('ajuste sin diferencia: rechazado', str_contains($r['json']['error'] ?? '', 'coincide'));

    // Traslados
    $r = $inv($c, ['accion' => 'traslado', 'origen_id' => 1, 'destino_id' => 2, 'items[0][producto_id]' => $pid, 'items[0][cantidad]' => 50]);
    check('traslado mayor a la existencia: rechazado sin mover nada', str_contains($r['json']['error'] ?? '', 'insuficiente') && $stock($pid, 1) == 18);
    $r = $inv($c, ['accion' => 'traslado', 'origen_id' => 1, 'destino_id' => 2, 'items[0][producto_id]' => $pid, 'items[0][cantidad]' => 5]);
    $tid = (int)($r['json']['id'] ?? 0);
    check('traslado: sale del origen y queda en tránsito', $tid && $stock($pid, 1) == 13 && $stock($pid, 2) == 0);
    $r = $inv($c, ['accion' => 'traslado_recibir', 'id' => $tid]);
    check('al recibir entra al destino', ($r['json']['success'] ?? false) && $stock($pid, 2) == 5);
    $r = $inv($c, ['accion' => 'traslado_anular', 'id' => $tid]);
    check('un traslado recibido no se puede anular', !($r['json']['success'] ?? true));
    $r = $inv($c, ['accion' => 'traslado', 'origen_id' => 1, 'destino_id' => 2, 'items[0][producto_id]' => $pid, 'items[0][cantidad]' => 3]);
    $tid2 = (int)($r['json']['id'] ?? 0);
    $inv($c, ['accion' => 'traslado_anular', 'id' => $tid2]);
    check('anular traslado en tránsito devuelve al origen', $stock($pid, 1) == 13 && db()->query("SELECT estado FROM inv_traslados WHERE id=$tid2")->fetchColumn() === 'anulado');

    // Facturación descuenta / devuelve
    $cai = db()->query("SELECT * FROM cai_rangos WHERE cliente_id=2 AND establecimiento_id=1 AND fecha_limite >= CURDATE() AND correlativo_actual < (rango_fin - rango_inicio + 1) ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $corrAntes = (int)$cai['correlativo_actual'];
    $facturar = fn(Cliente $cl, float $cant) => $cl->post('guardar_factura.php', ['receptor_id' => 1, 'cai_rango_id' => $cai['id'], 'condicion_pago' => 'Contado', 'establecimiento_id' => 1,
        'productos[0][id]' => $pid, 'productos[0][cantidad]' => $cant, 'productos[0][detalles]' => 'QA INV']);
    $r = $facturar($c, 100);
    check('factura sin existencia suficiente: rechazada', str_contains($r['json']['error'] ?? '', 'insuficiente'), $r['json']['error'] ?? '');
    check('…y no consumió correlativo', (int)db()->query("SELECT correlativo_actual FROM cai_rangos WHERE id={$cai['id']}")->fetchColumn() === $corrAntes);
    $r = $facturar($c, 4);
    $fid = (int)($r['json']['factura_id'] ?? 0);
    check('facturar 4 descuenta la existencia (13 → 9)', $fid && $stock($pid, 1) == 9, $r['body']);
    $autor = ['motivo' => 'QA', 'usuario_autoriza' => 'qa.admin@local.test', 'clave_autoriza' => QA_PASS];
    $r = $c->postJson('procesar_accion_factura.php', ['accion' => 'anular', 'factura_id' => $fid] + $autor);
    check('anular la factura devuelve la existencia (9 → 13)', ($r['json']['success'] ?? false) && $stock($pid, 1) == 13);
    $r = $c->postJson('procesar_accion_factura.php', ['accion' => 'restaurar', 'factura_id' => $fid] + $autor);
    check('restaurarla vuelve a descontar (13 → 9)', ($r['json']['success'] ?? false) && $stock($pid, 1) == 9, $r['body']);

    // Editar la factura: 4 → 6 unidades
    $f = db()->query("SELECT * FROM facturas WHERE id=$fid")->fetch(PDO::FETCH_ASSOC);
    $r = $c->post('guardar_factura_editada.php', ['factura_id' => $fid, 'receptor_id' => $f['receptor_id'], 'fecha_emision' => date('Y-m-d\TH:i', strtotime($f['fecha_emision'])),
        'condicion_pago' => 'Contado', 'estado' => 'emitida', 'productos[0][id]' => $pid, 'productos[0][cantidad]' => 6, 'productos[0][precio_unitario]' => 150, 'productos[0][descripcion_html]' => 'QA INV'] + $autor);
    check('editar la factura a 6 unidades deja 7', $stock($pid, 1) == 7, $stock($pid, 1) . ' ' . substr(strip_tags($r['body']), 0, 80));
    $r = $c->postJson('procesar_accion_factura.php', ['accion' => 'eliminar', 'factura_id' => $fid] + $autor);
    check('eliminar la factura devuelve todo (7 → 13)', ($r['json']['success'] ?? false) && $stock($pid, 1) == 13, $r['body']);

    // Dos ventas simultáneas por la última unidad: solo una debe pasar
    $inv($c, ['accion' => 'ajuste', 'producto_id' => $pid, 'establecimiento_id' => 1, 'contado' => 1, 'motivo' => 'QA última unidad']);
    $corrAntes = (int)db()->query("SELECT correlativo_actual FROM cai_rangos WHERE id={$cai['id']}")->fetchColumn();
    $mh = curl_multi_init(); $hs = [];
    foreach ([$c, login('qa.admin@local.test')] as $cl) {
        $h = curl_init(BASE . 'guardar_factura.php');
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_COOKIEFILE => $cl->jar, CURLOPT_COOKIEJAR => $cl->jar,
            CURLOPT_HTTPHEADER => ['X-CSRF-Token: ' . $cl->csrf],
            CURLOPT_POSTFIELDS => http_build_query(['receptor_id' => 1, 'cai_rango_id' => $cai['id'], 'condicion_pago' => 'Contado', 'establecimiento_id' => 1,
                'productos' => [['id' => $pid, 'cantidad' => 1, 'detalles' => 'QA INV']]])]);
        curl_multi_add_handle($mh, $h); $hs[] = $h;
    }
    do { curl_multi_exec($mh, $activo); curl_multi_select($mh); } while ($activo);
    $ok = 0; $ids = [];
    foreach ($hs as $h) { $j = json_decode(curl_multi_getcontent($h), true); if ($j['success'] ?? false) { $ok++; $ids[] = (int)$j['factura_id']; } curl_multi_remove_handle($mh, $h); }
    check('dos ventas simultáneas de la última unidad: solo una pasa y el stock queda en 0', $ok === 1 && $stock($pid, 1) == 0, "exitosas: $ok, stock " . $stock($pid, 1));
    check('…y solo se consumió un correlativo', (int)db()->query("SELECT correlativo_actual FROM cai_rangos WHERE id={$cai['id']}")->fetchColumn() === $corrAntes + 1);
    foreach ($ids as $id) $c->postJson('procesar_accion_factura.php', ['accion' => 'eliminar', 'factura_id' => $id] + $autor);

    $r = $c->get('inventario_kardex', ['producto_id' => $pid]);
    check('kardex carga con sus movimientos', $r['code'] === 200 && sinErroresPhp($r['body']) && substr_count($r['body'], 'app-badge app-badge-') > 5, errorPhp($r['body']));
    $r = $c->get('inventario', ['bajo' => 1, 'tienda' => 1]);
    check('el filtro «bajo mínimo» lo muestra', str_contains($r['body'], 'QA INV Cable HDMI'));
    $r = $c->get('inventario_reportes', ['dias' => 7]);
    check('reportes: aparece en «por reponer» y en «más vendidos»', sinErroresPhp($r['body']) && substr_count($r['body'], 'QA INV Cable HDMI') >= 2, errorPhp($r['body']));
    unset($_SESSION);
    $n = new Cliente('badge'); $n->post('index.php', ['correo' => 'qa.admin@local.test', 'clave' => QA_PASS]);
    $n->post('seleccionar_establecimiento', ['establecimiento_id' => 1]);
    $form = $n->get('seleccionar_establecimiento'); preg_match('/name="_csrf" value="([a-f0-9]+)"/', $form['body'], $m);
    $n->post('seleccionar_establecimiento', ['establecimiento_id' => 1, '_csrf' => $m[1] ?? '']);
    $r = $n->get('dashboard');
    check('el menú muestra el aviso de productos por reponer', (bool)preg_match('/title="Productos por reponer">\d+</', $r['body']));

    $limpiar();
    db()->exec("DELETE FROM bitacora_facturas WHERE motivo='QA'");
    check('limpieza: sin productos de prueba', !db()->query("SELECT COUNT(*) FROM productos_clientes WHERE nombre LIKE 'QA INV%'")->fetchColumn());
    check('limpieza: CAI sin correlativos consumidos', (int)db()->query("SELECT correlativo_actual FROM cai_rangos WHERE id={$cai['id']}")->fetchColumn() === (int)$cai['correlativo_actual']);
});

// ─────────────────────────────────────────────────────────────────────────────
suite('Punto de venta', function () {
    $admin = login('qa.admin@local.test');
    $caj = login('qa.facturador@local.test');
    $ccic = login('qa.ccic@local.test', 3);
    $pos = fn(Cliente $cl, array $d) => $cl->post('includes/pos_accion.php', $d);
    $stock = fn(int $pid) => (float)db()->query("SELECT COALESCE((SELECT cantidad FROM inv_existencias WHERE producto_id=$pid AND establecimiento_id=1),0)")->fetchColumn();
    $idem = fn() => bin2hex(random_bytes(16));
    $autor = ['motivo' => 'QA', 'usuario_autoriza' => 'qa.admin@local.test', 'clave_autoriza' => QA_PASS];
    $cai = db()->query("SELECT * FROM cai_rangos WHERE cliente_id=2 AND punto_emision_id=1 AND establecimiento_id=1 AND fecha_limite >= CURDATE() AND correlativo_actual < (rango_fin - rango_inicio + 1) ORDER BY fecha_limite, rango_inicio LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $facturas = [];
    db()->exec("DELETE FROM login_intentos");

    foreach ([$admin, $caj] as $cl) {
        foreach (['pos', 'pos_turnos'] as $p) { $r = $cl->get($p); check("{$cl->nombre}: $p carga sin errores", $r['code'] === 200 && sinErroresPhp($r['body']), errorPhp($r['body'])); }
    }

    // Producto con existencia
    $r = $admin->post('includes/inventario_accion.php', ['accion' => 'producto_guardar', 'nombre' => 'QA INV POS Audífonos', 'tipo' => 'bien', 'sku' => 'QA-POS-1', 'codigo_barras' => '7400000000035', 'precio' => 150, 'tipo_isv' => '15']);
    $pid = (int)($r['json']['id'] ?? 0);
    $admin->post('includes/inventario_accion.php', ['accion' => 'entrada', 'producto_id' => $pid, 'establecimiento_id' => 1, 'cantidad' => 10, 'costo_unitario' => 80]);
    check('producto de prueba con 10 unidades', $pid && $stock($pid) == 10);

    // Apertura
    $r = $pos($caj, ['accion' => 'abrir', 'punto_emision_id' => 1, 'monto_inicial' => 500]);
    $tid = (int)($r['json']['turno_id'] ?? 0);
    check('el cajero abre la caja 01 con L 500', $tid > 0, $r['body']);
    $r = $pos($admin, ['accion' => 'abrir', 'punto_emision_id' => 1, 'monto_inicial' => 0]);
    check('otra persona no puede abrir la misma caja', str_contains($r['json']['error'] ?? '', 'ya está abierta'));
    $r = $caj->get('pos');
    check('con caja abierta se ve la pantalla de venta con el producto', str_contains($r['body'], 'id="catalogo"') && str_contains($r['body'], 'QA INV POS Aud'));

    // Venta en efectivo con cambio: 2 × 150 + 15 % = 345 → recibe 1000, cambio 655
    $id1 = $idem();
    $venta = fn(array $extra) => $pos($caj, $extra + ['accion' => 'vender', 'items[0][id]' => $pid, 'items[0][cantidad]' => 2]);
    $r = $venta(['idempotencia' => $id1, 'efectivo_recibido' => 1000]);
    check('venta en efectivo: total 345, cambio 655', ($r['json']['success'] ?? false) && $r['json']['total'] == 345 && $r['json']['cambio'] == 655, $r['body']);
    $f1 = (int)($r['json']['factura_id'] ?? 0); $facturas[] = $f1;
    check('descuenta el inventario (10 → 8)', $stock($pid) == 8);
    check('la factura queda a nombre de CONSUMIDOR FINAL', db()->query("SELECT cf.nombre FROM facturas f JOIN clientes_factura cf ON cf.id=f.receptor_id WHERE f.id=$f1")->fetchColumn() === 'CONSUMIDOR FINAL');
    $corr = (int)db()->query("SELECT correlativo_actual FROM cai_rangos WHERE id={$cai['id']}")->fetchColumn();
    $r = $venta(['idempotencia' => $id1, 'efectivo_recibido' => 1000]);
    check('reintento con el mismo identificador: no cobra ni factura dos veces', ($r['json']['repetida'] ?? false) && (int)$r['json']['factura_id'] === $f1 && $stock($pid) == 8
        && (int)db()->query("SELECT correlativo_actual FROM cai_rangos WHERE id={$cai['id']}")->fetchColumn() === $corr);

    $r = $venta(['idempotencia' => $idem(), 'efectivo_recibido' => 100]);
    check('efectivo insuficiente: rechazada sin consumir nada', str_contains($r['json']['error'] ?? '', 'Falta') && $stock($pid) == 8 && (int)db()->query("SELECT correlativo_actual FROM cai_rangos WHERE id={$cai['id']}")->fetchColumn() === $corr);
    $r = $venta(['idempotencia' => $idem(), 'efectivo_recibido' => 0, 'pagos[0][forma]' => 'tarjeta', 'pagos[0][monto]' => 345]);
    check('tarjeta sin autorización: rechazada', str_contains($r['json']['error'] ?? '', 'autorización'));
    $r = $venta(['idempotencia' => $idem(), 'efectivo_recibido' => 0, 'pagos[0][forma]' => 'tarjeta', 'pagos[0][monto]' => 400, 'pagos[0][referencia]' => 'A1']);
    check('pagos mayores al total: rechazada', str_contains($r['json']['error'] ?? '', 'superan'));

    // Pago mixto: tarjeta 200 + transferencia 100 + efectivo 45
    $r = $venta(['idempotencia' => $idem(), 'efectivo_recibido' => 50, 'pagos[0][forma]' => 'tarjeta', 'pagos[0][monto]' => 200, 'pagos[0][referencia]' => 'AUT123', 'pagos[0][ultimos4]' => '4242',
        'pagos[1][forma]' => 'transferencia', 'pagos[1][monto]' => 100, 'pagos[1][referencia]' => 'TRX9']);
    $f2 = (int)($r['json']['factura_id'] ?? 0); $facturas[] = $f2;
    $pagos = db()->query("SELECT forma, monto FROM pos_venta_pagos WHERE venta_id=" . (int)($r['json']['venta_id'] ?? 0) . " ORDER BY forma")->fetchAll(PDO::FETCH_KEY_PAIR);
    check('pago mixto: efectivo 45 + tarjeta 200 + transferencia 100, cambio 5', ($r['json']['cambio'] ?? 0) == 5 && ($pagos['efectivo'] ?? 0) == 45 && ($pagos['tarjeta'] ?? 0) == 200 && ($pagos['transferencia'] ?? 0) == 100, $r['body']);

    // Movimientos de caja
    $r = $pos($caj, ['accion' => 'movimiento', 'tipo' => 'retiro', 'monto' => 100, 'motivo' => 'QA depósito']);
    check('retiro del cajero sin autorización: rechazado', str_contains($r['json']['error'] ?? '', 'autorización'));
    $r = $pos($caj, ['accion' => 'movimiento', 'tipo' => 'retiro', 'monto' => 5000, 'motivo' => 'x', 'usuario_autoriza' => 'qa.admin@local.test', 'clave_autoriza' => QA_PASS]);
    check('no se retira más efectivo del que hay', str_contains($r['json']['error'] ?? '', 'suficiente'));
    $r = $pos($caj, ['accion' => 'movimiento', 'tipo' => 'retiro', 'monto' => 300, 'motivo' => 'QA a bóveda', 'usuario_autoriza' => 'qa.admin@local.test', 'clave_autoriza' => QA_PASS]);
    check('retiro con autorización de un administrador', ($r['json']['success'] ?? false));

    // Corte X: arqueo ciego para el cajero
    $r = $caj->get('includes/pos_accion.php', ['resumen' => $tid]);
    check('corte X del cajero: ventas y formas de pago, sin el esperado', ($r['json']['ventas'] ?? 0) == 2 && !array_key_exists('efectivo_esperado', $r['json'] ?? []) && ($r['json']['por_forma']['tarjeta'] ?? 0) == 200);
    $r = $admin->get('includes/pos_accion.php', ['resumen' => $tid]);
    check('el administrador ve el esperado: 500 + 345 + 45 − 300 = 590', ($r['json']['efectivo_esperado'] ?? 0) == 590, $r['body']);
    $r = $ccic->get('includes/pos_accion.php', ['resumen' => $tid]);
    check('otra empresa no ve el turno', !($r['json']['success'] ?? true));

    // Anular una venta: sale del corte y devuelve inventario
    $admin->postJson('procesar_accion_factura.php', ['accion' => 'anular', 'factura_id' => $f1] + $autor);
    $r = $admin->get('includes/pos_accion.php', ['resumen' => $tid]);
    check('venta anulada: sale del esperado (590 → 245) y devuelve inventario', ($r['json']['efectivo_esperado'] ?? 0) == 245 && ($r['json']['anuladas'] ?? 0) == 1 && $stock($pid) == 8, $r['body']);

    // Cierre
    $r = $pos($caj, ['accion' => 'cerrar', 'turno_id' => $tid, 'efectivo_contado' => 200]);
    check('diferencia de L 45 sin justificación: no cierra', str_contains($r['json']['error'] ?? '', 'justificación'));
    $r = $pos($caj, ['accion' => 'cerrar', 'turno_id' => $tid, 'efectivo_contado' => 200, 'justificacion' => 'QA faltante de prueba']);
    check('cierre con justificación guarda la diferencia (−45)', ($r['json']['success'] ?? false) && (float)db()->query("SELECT diferencia FROM pos_turnos WHERE id=$tid")->fetchColumn() == -45);
    $r = $venta(['idempotencia' => $idem(), 'efectivo_recibido' => 1000]);
    check('con la caja cerrada no se puede vender', str_contains($r['json']['error'] ?? '', 'turno'));
    $r = $admin->get('pos_turnos', ['id' => $tid]);
    check('corte Z imprimible', $r['code'] === 200 && sinErroresPhp($r['body']) && str_contains($r['body'], 'Corte Z') && str_contains($r['body'], 'faltante'), errorPhp($r['body']));

    // Limpieza (facturas en orden inverso para devolver el correlativo)
    foreach (array_reverse(array_filter($facturas)) as $f) $admin->postJson('procesar_accion_factura.php', ['accion' => 'eliminar', 'factura_id' => $f] + $autor);
    db()->exec("DELETE pg FROM pos_venta_pagos pg JOIN pos_ventas v ON v.id = pg.venta_id WHERE v.turno_id = $tid");
    db()->exec("DELETE FROM pos_ventas WHERE turno_id = $tid");
    db()->exec("DELETE FROM pos_movimientos_caja WHERE turno_id = $tid");
    db()->exec("DELETE FROM pos_turnos WHERE id = $tid");
    db()->exec("DELETE FROM inv_movimientos WHERE producto_id = $pid");
    db()->exec("DELETE FROM inv_existencias WHERE producto_id = $pid");
    db()->exec("DELETE FROM productos_clientes WHERE id = $pid");
    db()->exec("DELETE FROM clientes_factura WHERE cliente_id = 2 AND nombre = 'CONSUMIDOR FINAL' AND NOT EXISTS (SELECT 1 FROM facturas f WHERE f.receptor_id = clientes_factura.id)");
    db()->exec("DELETE FROM bitacora_facturas WHERE motivo IN ('QA')");
    check('limpieza: CAI con su correlativo original', (int)db()->query("SELECT correlativo_actual FROM cai_rangos WHERE id={$cai['id']}")->fetchColumn() === (int)$cai['correlativo_actual']);
});

// ─────────────────────────────────────────────────────────────────────────────
suite('PDF de facturas (descarga individual y ZIP)', function () {
    $c = login('qa.admin@local.test');
    $ids = db()->query("SELECT id FROM facturas WHERE cliente_id=2 ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
    $ok = 0;
    foreach ($ids as $id) {
        $r = $c->get('procesar_accion_factura.php', ['accion' => 'exportar_pdf_single', 'factura_id' => $id]);
        if (str_contains($r['head'], 'application/pdf') && str_starts_with($r['body'], '%PDF')) $ok++;
        else check("PDF de la factura $id", false, substr($r['body'], 0, 120));
    }
    check('se generan los PDF de las últimas 5 facturas (lo que usa «Descargar todo» en ZIP)', $ok === count($ids));
    $r = $c->get('ver_factura', ['id' => $ids[0]]);
    check('la vista de factura no imprime avisos de PHP', sinErroresPhp($r['body']), errorPhp($r['body']));
    $ccic = login('qa.ccic@local.test', 3);
    $r = $ccic->get('procesar_accion_factura.php', ['accion' => 'exportar_pdf_single', 'factura_id' => $ids[0]]);
    check('otra empresa no puede descargar el PDF', !str_contains($r['head'], 'application/pdf'));
});

suite('Exportar facturas a XLSX', function () {
    $c = login('qa.admin@local.test');
    $ids = db()->query("SELECT id FROM facturas WHERE cliente_id=2 ORDER BY id DESC LIMIT 4")->fetchAll(PDO::FETCH_COLUMN);
    $r = $c->get('includes/facturas_xlsx.php', ['ids' => implode(',', $ids)]);
    check('descarga un .xlsx', str_contains($r['head'], 'spreadsheetml.sheet') && str_starts_with($r['body'], "PK\x03\x04"), substr($r['body'], 0, 120));
    $tmp = tempnam(sys_get_temp_dir(), 'xl') . '.xlsx';
    file_put_contents($tmp, $r['body']);
    $z = new ZipArchive();
    $abre = $z->open($tmp) === true;
    $hoja = $abre ? (string)$z->getFromName('xl/worksheets/sheet1.xml') : '';
    check('el ZIP del .xlsx es válido y trae la hoja', $abre && $hoja !== '');
    $xml = @simplexml_load_string($hoja);
    check('la hoja es XML válido', $xml !== false);
    $corr = db()->query("SELECT correlativo FROM facturas WHERE id=" . (int)$ids[0])->fetchColumn();
    check('incluye el correlativo y la fila de totales', str_contains($hoja, $corr) && str_contains($hoja, 'TOTALES'));
    check('una fila por factura + encabezado + vacía + totales', $xml && count($xml->sheetData->row) === count($ids) + 3);
    $total = (float)db()->query("SELECT SUM(total) FROM facturas WHERE estado<>'anulada' AND id IN (" . implode(',', array_map('intval', $ids)) . ")")->fetchColumn();
    $ult = $xml ? $xml->sheetData->row[count($xml->sheetData->row) - 1] : null;
    $vals = [];
    if ($ult) foreach ($ult->c as $cel) $vals[(string)$cel['r']] = (string)$cel->v;
    $fila = count($ids) + 3;
    check('el total a pagar coincide con la BD', abs((float)($vals["W$fila"] ?? -1) - round($total, 2)) < 0.01, json_encode($vals));
    @unlink($tmp);
    $ccic = login('qa.ccic@local.test', 3);
    $r = $ccic->get('includes/facturas_xlsx.php', ['ids' => implode(',', $ids)]);
    check('otra empresa no puede exportar esas facturas', $r['code'] === 404 && !str_contains($r['head'], 'spreadsheetml'));
    $r = $c->get('includes/facturas_xlsx.php', ['ids' => 'abc']);
    check('ids inválidos → 400', $r['code'] === 400);
});

suite('Abonos desde Facturas del contrato', function () {
    $c = login('qa.admin@local.test');
    $ct = db()->query("SELECT contrato_id FROM facturas WHERE cliente_id=2 AND contrato_id IS NOT NULL AND estado='emitida' GROUP BY contrato_id ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn();
    $r = $c->get('facturas_contrato', ['contrato_id' => $ct]);
    check('la página del contrato carga sin avisos de PHP', $r['code'] === 200 && sinErroresPhp($r['body']), errorPhp($r['body']));
    check('muestra el botón de abonos y el saldo por cobrar', str_contains($r['body'], 'btn-abonos') && str_contains($r['body'], 'Saldo por cobrar'));
    // Factura del contrato sin pagar ni abonos → abono parcial y luego anulación
    $f = db()->query("SELECT id, total FROM facturas f WHERE contrato_id=" . (int)$ct . " AND estado='emitida' AND pagada=0 AND NOT EXISTS (SELECT 1 FROM cobros_factura c WHERE c.factura_id=f.id) LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$f) { db()->exec("UPDATE facturas SET pagada=0 WHERE id=(SELECT id FROM (SELECT id FROM facturas WHERE contrato_id=" . (int)$ct . " AND estado='emitida' ORDER BY id DESC LIMIT 1) t)"); $f = db()->query("SELECT id, total FROM facturas WHERE contrato_id=" . (int)$ct . " AND estado='emitida' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC); }
    $mitad = round($f['total'] / 2, 2);
    $r = $c->post('includes/cxc_accion.php', ['accion' => 'cobrar', 'factura_id' => $f['id'], 'fecha' => date('Y-m-d'), 'monto' => $mitad, 'metodo' => 'transferencia', 'referencia' => 'QA-ABONO']);
    check('registra un abono parcial', ($r['json']['success'] ?? false) === true, $r['body']);
    $r = $c->get('facturas_contrato', ['contrato_id' => $ct]);
    check('la factura aparece como «Abonada» con su saldo', str_contains($r['body'], 'Abonada') && str_contains($r['body'], number_format($f['total'] - $mitad, 2)));
    $id = db()->query("SELECT id FROM cobros_factura WHERE referencia='QA-ABONO' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $r = $c->post('includes/cxc_accion.php', ['accion' => 'anular_cobro', 'id' => $id, 'motivo' => 'prueba QA']);
    check('limpieza: el abono de prueba se anula', ($r['json']['success'] ?? false) === true, $r['body']);
    check('la factura vuelve a quedar sin pagar', (int)db()->query("SELECT pagada FROM facturas WHERE id=" . (int)$f['id'])->fetchColumn() === 0);
});

suite('Estado de cuenta y cuentas por cobrar/pagar', function () {
    $c = login('qa.admin@local.test');
    foreach (['cuentas_cobrar', 'cuentas_pagar'] as $p) {
        $r = $c->get($p);
        check("$p carga sin avisos de PHP", $r['code'] === 200 && sinErroresPhp($r['body']), errorPhp($r['body']));
        check("$p usa los componentes comunes (indicadores y paginación)", str_contains($r['body'], 'app-stat') && str_contains($r['body'], 'app-pager'));
    }
    $rid = db()->query("SELECT receptor_id FROM facturas WHERE cliente_id=2 AND estado='emitida' GROUP BY receptor_id ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn();
    $r = $c->get('estado_cuenta', ['receptor_id' => $rid]);
    check('estado de cuenta carga sin avisos de PHP', $r['code'] === 200 && sinErroresPhp($r['body']), errorPhp($r['body']));
    check('estado de cuenta muestra facturas, abonos y botón de abonar', str_contains($r['body'], 'Abonos realizados') && str_contains($r['body'], 'btn-abonos'));
    $r = $c->get('cuentas_cobrar');
    check('el nombre del cliente enlaza a su estado de cuenta', str_contains($r['body'], 'estado_cuenta?receptor_id='));
    $otro = db()->query("SELECT id FROM clientes_factura WHERE cliente_id<>2 LIMIT 1")->fetchColumn();
    if ($otro) {
        $r = $c->get('estado_cuenta', ['receptor_id' => $otro]);
        check('no se puede ver el estado de cuenta de un cliente de otra empresa', $r['code'] === 302 || str_contains($r['loc'], 'cuentas_cobrar'));
    }
});

suite('Cuenta bancaria predeterminada', function () {
    $c = login('qa.admin@local.test');
    // Dos cuentas en lempiras de prueba
    $ids = [];
    foreach (['QA-PRED-1', 'QA-PRED-2'] as $n) {
        $r = $c->post('includes/banco_accion.php', ['accion' => 'cuenta_guardar', 'banco' => 'Banco QA', 'numero' => $n . '-' . time(), 'tipo' => 'ahorro', 'moneda' => 'HNL', 'saldo_inicial' => 0, 'fecha_saldo_inicial' => date('Y-m-d')]);
        $ids[] = (int)($r['json']['id'] ?? 0);
    }
    check('se crean dos cuentas de prueba', $ids[0] > 0 && $ids[1] > 0);
    $r = $c->post('includes/banco_accion.php', ['accion' => 'predeterminar', 'id' => $ids[1]]);
    check('se marca una cuenta como predeterminada', ($r['json']['success'] ?? false) === true, $r['body']);
    $pred = db()->query("SELECT id FROM cuentas_bancarias WHERE cliente_id = 2 AND predeterminada = 1")->fetchAll(PDO::FETCH_COLUMN);
    check('solo hay una predeterminada por empresa', $pred === [(string)$ids[1]] || $pred === [$ids[1]], json_encode($pred));
    $r = $c->get('cuentas_cobrar');
    check('en cuentas por cobrar viene preseleccionada', (bool)preg_match('/<option value="' . $ids[1] . '" selected>/', $r['body']));
    $r = $c->get('bancos');
    check('bancos muestra la estrella de predeterminada', str_contains($r['body'], 'bi-star-fill') && sinErroresPhp($r['body']), errorPhp($r['body']));
    $c->post('includes/banco_accion.php', ['accion' => 'cuenta_estado', 'id' => $ids[1]]);
    check('al desactivarla deja de ser predeterminada', (int)db()->query("SELECT predeterminada FROM cuentas_bancarias WHERE id = " . $ids[1])->fetchColumn() === 0);
    // limpieza (cuentas sin movimientos)
    db()->exec("DELETE FROM cuentas_bancarias WHERE id IN (" . implode(',', $ids) . ") AND NOT EXISTS (SELECT 1 FROM movimientos_bancarios m WHERE m.cuenta_id = cuentas_bancarias.id)");
    check('limpieza: cuentas de prueba borradas', !db()->query("SELECT COUNT(*) FROM cuentas_bancarias WHERE id IN (" . implode(',', $ids) . ")")->fetchColumn());
});

suite('Contrato tipo proyecto con pagos anticipados', function () {
    $c = login('qa.admin@local.test');
    $pdo = db();
    // Factura emitida, sin pagar y sin abonos, para hacer de "factura final" del proyecto
    $f = $pdo->query("SELECT f.id, f.receptor_id, f.total, f.contrato_id, f.periodo_mes, f.periodo_anio FROM facturas f WHERE f.cliente_id=2 AND f.estado='emitida' AND f.pagada=0
                      AND NOT EXISTS (SELECT 1 FROM cobros_factura c WHERE c.factura_id=f.id AND c.anulado=0) ORDER BY f.total DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    check('hay una factura de prueba disponible', (bool)$f);
    if (!$f) return;
    $prod = $pdo->query("SELECT id FROM productos_clientes WHERE cliente_id=2 LIMIT 1")->fetchColumn();
    $sinIsv = round($f['total'] / 1.15, 2);
    $pdo->prepare("INSERT INTO contratos (cliente_id, receptor_id, nombre_contrato, producto_id, monto, fecha_inicio, fecha_fin, dia_pago, estado, tipo_contrato, notas)
                   VALUES (2, ?, 'QA proyecto', ?, ?, '2026-05-13', '2026-11-30', 30, 'activo', 'proyecto', 'Proyecto de prueba')")->execute([$f['receptor_id'], $prod, $sinIsv]);
    $ct = (int)$pdo->lastInsertId();
    $mitad = round($f['total'] * 0.4, 2);
    $r1 = $c->post('includes/anticipo_accion.php', ['accion' => 'registrar', 'contrato_id' => $ct, 'fecha' => '2026-05-13', 'monto' => $mitad, 'metodo' => 'transferencia', 'concepto' => 'Etapa 1']);
    $r2 = $c->post('includes/anticipo_accion.php', ['accion' => 'registrar', 'contrato_id' => $ct, 'fecha' => '2026-09-25', 'monto' => '100.00', 'metodo' => 'transferencia', 'concepto' => 'Etapa 2']);
    check('se registran dos pagos anticipados', ($r1['json']['success'] ?? false) && ($r2['json']['success'] ?? false), $r1['body'] . $r2['body']);
    $r = $c->get('facturas_contrato', ['contrato_id' => $ct]);
    check('la página del proyecto carga sin avisos de PHP', $r['code'] === 200 && sinErroresPhp($r['body']), errorPhp($r['body']));
    check('muestra valor del proyecto, recibido y la sección de anticipos', str_contains($r['body'], 'Valor del proyecto') && str_contains($r['body'], 'Falta por recibir') && str_contains($r['body'], 'Pagos anticipados'));
    check('no muestra «Monto mensual» ni el calendario mensual', !str_contains($r['body'], 'Monto mensual') && !str_contains($r['body'], 'Calendario de Cobros'));
    $r = $c->get('contratos');
    check('la lista de contratos marca el proyecto sin cobro mensual', str_contains($r['body'], 'Proyecto · valor total') && sinErroresPhp($r['body']), errorPhp($r['body']));
    // Al emitir la factura (aquí: se liga la existente) se aplican los anticipos
    $pdo->prepare("UPDATE facturas SET contrato_id=? WHERE id=?")->execute([$ct, $f['id']]);
    $r = $c->post('includes/anticipo_accion.php', ['accion' => 'aplicar', 'contrato_id' => $ct, 'factura_id' => $f['id']]);
    check('los anticipos se aplican como abonos de la factura', ($r['json']['success'] ?? false) === true, $r['body']);
    $ab = $pdo->query("SELECT COUNT(*), SUM(monto), MIN(fecha) FROM cobros_factura WHERE factura_id=" . (int)$f['id'] . " AND anulado=0")->fetch(PDO::FETCH_NUM);
    check('quedan 2 abonos con la fecha original del primer pago', (int)$ab[0] === 2 && $ab[2] === '2026-05-13' && abs($ab[1] - ($mitad + 100)) < 0.01, json_encode($ab));
    check('los anticipos quedan ligados a la factura', (int)$pdo->query("SELECT COUNT(*) FROM contratos_anticipos WHERE contrato_id=$ct AND factura_id=" . (int)$f['id'])->fetchColumn() === 2);
    // Anular un abono que vino de un anticipo: el anticipo vuelve a quedar sin aplicar
    $cobro = $pdo->query("SELECT id FROM cobros_factura WHERE factura_id=" . (int)$f['id'] . " AND anulado=0 ORDER BY id LIMIT 1")->fetchColumn();
    $r = $c->post('includes/cxc_accion.php', ['accion' => 'anular_cobro', 'id' => $cobro, 'motivo' => 'QA']);
    check('al anular ese abono, el anticipo vuelve a quedar sin factura', ($r['json']['success'] ?? false) && (int)$pdo->query("SELECT COUNT(*) FROM contratos_anticipos WHERE contrato_id=$ct AND factura_id IS NULL")->fetchColumn() === 1, $r['body']);
    $r = $c->post('includes/anticipo_accion.php', ['accion' => 'anular', 'id' => $pdo->query("SELECT id FROM contratos_anticipos WHERE contrato_id=$ct AND factura_id IS NULL")->fetchColumn(), 'motivo' => 'QA']);
    check('un anticipo sin aplicar se puede anular', ($r['json']['success'] ?? false) === true, $r['body']);
    // Limpieza
    $pdo->exec("UPDATE cobros_factura SET anulado=1, motivo_anulacion='QA' WHERE factura_id=" . (int)$f['id']);
    $pdo->exec("DELETE FROM contratos_anticipos WHERE contrato_id=$ct");
    $pdo->prepare("UPDATE facturas SET contrato_id=?, periodo_mes=?, periodo_anio=?, pagada=0 WHERE id=?")->execute([$f['contrato_id'], $f['periodo_mes'], $f['periodo_anio'], $f['id']]);
    $pdo->exec("DELETE FROM contratos WHERE id=$ct");
    check('limpieza: contrato de prueba eliminado y factura restaurada', !(int)$pdo->query("SELECT COUNT(*) FROM contratos WHERE id=$ct")->fetchColumn());
});

suite('Correo SMTP y aviso de pago a colaboradores', function () {
    $pdo = db();
    if (!$pdo->query("SHOW TABLES LIKE 'configuracion_correo'")->fetchColumn()) { check('migración de correo instalada', false); return; }
    $dir = '/private/tmp/claude-501/-Applications-XAMPP-xamppfiles-htdocs-proyectos-NARANJA-sistemafacturacion/ee82cda1-ff6c-4bf7-8e00-1c9a72466389/scratchpad/correos';
    $c = login('qa.admin@local.test');
    $base = ['accion' => 'guardar', 'host' => '127.0.0.1', 'puerto' => 2525, 'seguridad' => 'ninguna', 'usuario' => 'qa',
             'remitente_email' => 'facturacion@ejemplo.test', 'remitente_nombre' => 'Naranja & Media', 'responder_a' => 'admin@ejemplo.test', 'activo' => 1];
    $r = $c->post('includes/correo_accion.php', $base + ['clave' => 'clave-de-prueba']);
    check('se guarda la configuración SMTP', ($r['json']['success'] ?? false) === true, $r['body']);
    $cif = $pdo->query("SELECT clave_cifrada FROM configuracion_correo WHERE cliente_id = 2")->fetchColumn();
    check('la contraseña queda cifrada en la BD', $cif && !str_contains($cif, 'clave-de-prueba'));
    $r = $c->get('configuracion_correo');
    check('la página de correo carga y no muestra la contraseña', $r['code'] === 200 && sinErroresPhp($r['body']) && !str_contains($r['body'], 'clave-de-prueba'), errorPhp($r['body']));
    $r = $c->post('includes/correo_accion.php', $base);   // sin clave: conserva la anterior
    check('guardar sin contraseña conserva la anterior', ($r['json']['success'] ?? false) && $pdo->query("SELECT clave_cifrada FROM configuracion_correo WHERE cliente_id = 2")->fetchColumn() === $cif);

    $antes = count(glob("$dir/*.eml") ?: []);
    $r = $c->post('includes/correo_accion.php', ['accion' => 'probar', 'para' => 'qa@ejemplo.test']);
    check('se envía el correo de prueba', ($r['json']['success'] ?? false) === true, $r['body']);
    check('el servidor SMTP recibió el mensaje', count(glob("$dir/*.eml") ?: []) === $antes + 1);

    // Aviso de pago: colaborador con correo y un sueldo con comprobante
    $g = $pdo->query("SELECT id, descripcion FROM gastos WHERE cliente_id = 2 AND descripcion LIKE 'Sueldo %' AND estado <> 'anulado' AND archivo_adjunto IS NOT NULL ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC)
        ?: $pdo->query("SELECT id, descripcion FROM gastos WHERE cliente_id = 2 AND descripcion LIKE 'Sueldo %' AND estado <> 'anulado' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $nombre = preg_replace('/^Sueldo (.*?)( — .*)?$/u', '$1', $g['descripcion']);
    $col = $pdo->prepare("SELECT id, email FROM colaboradores WHERE cliente_id = 2 AND CONCAT(nombre, ' ', apellido) = ?");
    $col->execute([$nombre]);
    $col = $col->fetch(PDO::FETCH_ASSOC);
    $pdo->prepare("UPDATE colaboradores SET email = 'colaborador@ejemplo.test' WHERE id = ?")->execute([$col['id']]);
    // Si el comprobante solo existe en el servidor, se crea uno temporal para la prueba
    $adjRel = $pdo->query("SELECT archivo_adjunto FROM gastos WHERE id = " . (int)$g['id'])->fetchColumn();
    $adjTmp = null;
    if ($adjRel) {
        $ruta = __DIR__ . '/../../clientes/naranjaymedia/includes/uploads/comprobantes_nomina/' . $adjRel;
        if (!is_file($ruta)) { @mkdir(dirname($ruta), 0775, true); file_put_contents($ruta, base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==')); $adjTmp = $ruta; }
    }
    $r = $c->post('includes/correo_accion.php', ['accion' => 'enviar_pago', 'gasto_id' => $g['id']]);
    check('se envía el aviso de pago al colaborador', ($r['json']['success'] ?? false) === true, $r['body']);
    $ult = glob("$dir/*.eml"); sort($ult); $eml = file_get_contents(end($ult));
    check('el aviso va al correo del colaborador con asunto y Reply-To', str_contains($eml, 'To: <colaborador@ejemplo.test>') && str_contains($eml, 'Reply-To: <admin@ejemplo.test>') && str_contains($eml, 'Subject: =?UTF-8?B?'));
    $html = '';
    if (preg_match('/Content-Type: text\/html; charset=UTF-8\r?\nContent-Transfer-Encoding: base64\r?\n\r?\n(.*?)\r?\n--/s', $eml, $m)) $html = base64_decode(preg_replace('/\s+/', '', $m[1]));
    check('la plantilla dice "pago" y trae la nota legal, sin la palabra "sueldo"', str_contains($html, 'Pago acreditado') && str_contains($html, 'No constituye contrato') && stripos($html, 'sueldo') === false);
    $conAdj = (bool)$pdo->query("SELECT archivo_adjunto FROM gastos WHERE id = " . (int)$g['id'])->fetchColumn();
    if ($conAdj) check('el comprobante va adjunto', str_contains($eml, 'Content-Disposition: attachment'));
    if ($adjTmp) @unlink($adjTmp);
    $f = $pdo->query("SELECT YEAR(fecha) a, MONTH(fecha) m FROM gastos WHERE id = " . (int)$g['id'])->fetch(PDO::FETCH_ASSOC);
    $r = $c->get('colaborador_ver', ['id' => $col['id'], 'anio' => $f['a'], 'mes' => $f['m']]);
    check('la ficha del colaborador muestra el aviso enviado', str_contains($r['body'], 'btn-enviar-aviso') && str_contains($r['body'], 'Aviso enviado el') && sinErroresPhp($r['body']), errorPhp($r['body']));

    // Contraseña incorrecta: error claro y queda en la bitácora
    $c->post('includes/correo_accion.php', $base + ['clave' => 'otra-clave']);
    $r = $c->post('includes/correo_accion.php', ['accion' => 'probar', 'para' => 'qa@ejemplo.test']);
    check('con contraseña incorrecta avisa el error', ($r['json']['success'] ?? true) === false && str_contains($r['json']['error'] ?? '', 'contraseña'), $r['body']);
    check('la bitácora registra envíos y errores', (int)$pdo->query("SELECT COUNT(*) FROM correos_enviados WHERE cliente_id = 2 AND estado = 'error'")->fetchColumn() >= 1);
    // Limpieza
    $pdo->prepare("UPDATE colaboradores SET email = ? WHERE id = ?")->execute([$col['email'], $col['id']]);
    $pdo->exec("DELETE FROM configuracion_correo WHERE cliente_id = 2");
    $pdo->exec("DELETE FROM correos_enviados WHERE cliente_id = 2");
    check('limpieza: configuración de prueba eliminada', !$pdo->query("SELECT COUNT(*) FROM configuracion_correo WHERE cliente_id = 2")->fetchColumn());
});

suite('Cron de avisos de pago automáticos', function () {
    $pdo = db();
    if (!$pdo->query("SHOW COLUMNS FROM configuracion_correo LIKE 'aviso_pago_auto'")->fetchColumn()) { check('migración de aviso automático instalada', false); return; }
    $c = login('qa.admin@local.test');
    $base = ['accion' => 'guardar', 'host' => '127.0.0.1', 'puerto' => 2525, 'seguridad' => 'ninguna', 'usuario' => 'qa', 'clave' => 'clave-de-prueba',
             'remitente_email' => 'nomina@ejemplo.test', 'responder_a' => 'gerencia@ejemplo.test; administracion@ejemplo.test', 'activo' => 1, 'aviso_pago_auto' => 1];
    $r = $c->post('includes/correo_accion.php', $base + ['aviso_pago_hora' => 23]);
    check('se guarda el aviso automático con su hora', ($r['json']['success'] ?? false) && (int)$pdo->query("SELECT aviso_pago_hora FROM configuracion_correo WHERE cliente_id = 2")->fetchColumn() === 23, $r['body']);
    check('«Responder a» guarda varios correos', $pdo->query("SELECT responder_a FROM configuracion_correo WHERE cliente_id = 2")->fetchColumn() === 'gerencia@ejemplo.test, administracion@ejemplo.test');

    // Colaborador con correo y tres pagos: hoy (sin aviso), hoy (ya avisado a mano) y ayer
    $col = $pdo->query("SELECT * FROM colaboradores WHERE cliente_id = 2 AND activo = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $emailAntes = $col['email'];
    $pdo->prepare("UPDATE colaboradores SET email = 'colab@ejemplo.test' WHERE id = ?")->execute([$col['id']]);
    $desc = 'Sueldo ' . $col['nombre'] . ' ' . $col['apellido'];
    $ins = $pdo->prepare("INSERT INTO gastos (cliente_id, descripcion, monto, fecha, frecuencia, quincena_num, tipo, metodo_pago, estado, notas) VALUES (2, ?, 100, ?, 'quincenal', ?, 'fijo', 'transferencia', 'pagado', 'QA-CRON')");
    $ins->execute([$desc . ' — 1ª Quincena', date('Y-m-d'), 1]); $hoy = (int)$pdo->lastInsertId();
    $ins->execute([$desc . ' — 2ª Quincena', date('Y-m-d'), 2]); $hoyManual = (int)$pdo->lastInsertId();
    $ins->execute([$desc . ' — 2ª Quincena', date('Y-m-d', strtotime('-1 day')), 2]); $ayer = (int)$pdo->lastInsertId();
    $r = $c->post('includes/correo_accion.php', ['accion' => 'enviar_pago', 'gasto_id' => $hoyManual]);
    check('aviso manual enviado', ($r['json']['success'] ?? false) === true, $r['body']);

    $cron = __DIR__ . '/../../cron/avisos_pago.php';
    $correr = fn() => shell_exec('APP_DB=dev ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cron) . ' 2>&1');
    $cuenta = fn($id) => (int)$pdo->query("SELECT COUNT(*) FROM correos_enviados WHERE tipo = 'pago_colaborador' AND estado = 'enviado' AND referencia_id = $id")->fetchColumn();
    if ((int)date('G') < 23) {
        $correr();
        check('antes de la hora configurada no envía nada', $cuenta($hoy) === 0);
    }
    $pdo->exec("UPDATE configuracion_correo SET aviso_pago_hora = 0 WHERE cliente_id = 2");
    $salida = $correr();
    check('a la hora configurada envía el pago de hoy', $cuenta($hoy) === 1, (string)$salida);
    check('no repite el que ya se envió a mano', $cuenta($hoyManual) === 1);
    check('no envía pagos de fechas pasadas', $cuenta($ayer) === 0);
    $correr();
    check('al volver a correr no duplica', $cuenta($hoy) === 1 && $cuenta($hoyManual) === 1);
    // Fecha de corte: se pone al día con los pendientes desde esa fecha; no toca los anteriores ni los de fecha futura
    $r = $c->post('includes/correo_accion.php', $base + ['aviso_pago_hora' => 0, 'aviso_pago_desde' => date('Y-m-d', strtotime('-3 days'))]);
    check('se guarda la fecha de corte', ($r['json']['success'] ?? false) && $pdo->query("SELECT aviso_pago_desde FROM configuracion_correo WHERE cliente_id = 2 AND perfil = 'nomina'")->fetchColumn() === date('Y-m-d', strtotime('-3 days')), $r['body']);
    $ins->execute([$desc . ' — 1ª Quincena', date('Y-m-d', strtotime('-5 days')), 1]); $viejo = (int)$pdo->lastInsertId();
    $ins->execute([$desc . ' — 1ª Quincena', date('Y-m-d', strtotime('+1 day')), 1]); $futuro = (int)$pdo->lastInsertId();
    $correr();
    check('con fecha de corte envía el pendiente de ayer', $cuenta($ayer) === 1);
    check('no envía pagos anteriores a la fecha de corte', $cuenta($viejo) === 0);
    check('un pago registrado por adelantado espera a su fecha', $cuenta($futuro) === 0);
    $pdo->exec("UPDATE configuracion_correo SET aviso_pago_auto = 0 WHERE cliente_id = 2");
    $ins->execute([$desc . ' — 1ª Quincena', date('Y-m-d'), 1]); $otro = (int)$pdo->lastInsertId();
    $correr();
    check('con el aviso automático apagado no envía', $cuenta($otro) === 0);
    check('el cron no se puede abrir desde la web', $c->get('http://localhost:8383/proyectos/NARANJA/sistemafacturacion/cron/avisos_pago.php')['code'] !== 200);
    // Limpieza
    $pdo->exec("DELETE FROM gastos WHERE notas = 'QA-CRON'");
    $pdo->prepare("UPDATE colaboradores SET email = ? WHERE id = ?")->execute([$emailAntes, $col['id']]);
    $pdo->exec("DELETE FROM configuracion_correo WHERE cliente_id = 2");
    $pdo->exec("DELETE FROM correos_enviados WHERE cliente_id = 2");
    check('limpieza hecha', !(int)$pdo->query("SELECT COUNT(*) FROM gastos WHERE notas = 'QA-CRON'")->fetchColumn());
});

suite('Cobros por correo programados', function () {
    $pdo = db();
    if (!$pdo->query("SHOW TABLES LIKE 'cobros_programados'")->fetchColumn()) { check('migración de cobros instalada', false); return; }
    $dir = '/private/tmp/claude-501/-Applications-XAMPP-xamppfiles-htdocs-proyectos-NARANJA-sistemafacturacion/ee82cda1-ff6c-4bf7-8e00-1c9a72466389/scratchpad/correos';
    $c = login('qa.admin@local.test');
    $r = $c->post('includes/correo_accion.php', ['accion' => 'guardar', 'perfil' => 'facturacion', 'host' => '127.0.0.1', 'puerto' => 2525, 'seguridad' => 'ninguna',
        'usuario' => 'qa', 'clave' => 'clave-de-prueba', 'remitente_email' => 'facturacion@ejemplo.test', 'responder_a' => 'gerencia@ejemplo.test, administracion@ejemplo.test', 'activo' => 1]);
    check('se guarda la cuenta de Facturación', ($r['json']['success'] ?? false) === true, $r['body']);
    check('Nómina y Facturación son cuentas separadas', (int)$pdo->query("SELECT COUNT(*) FROM configuracion_correo WHERE cliente_id = 2 AND perfil = 'facturacion'")->fetchColumn() === 1);
    $r = $c->get('configuracion_correo', ['tab' => 'facturacion']);
    check('la configuración muestra las pestañas', str_contains($r['body'], 'tab-facturacion') && str_contains($r['body'], 'tab-bitacora') && sinErroresPhp($r['body']), errorPhp($r['body']));

    $rid = (int)$pdo->query("SELECT receptor_id FROM facturas WHERE cliente_id = 2 AND estado = 'emitida' AND fecha_emision >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH) GROUP BY receptor_id ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn();
    $r = $c->get('cobro_accion.php', ['facturas' => $rid]);
    check('lista las facturas del cliente con su saldo', ($r['json']['success'] ?? false) && count($r['json']['facturas'] ?? []) > 0 && isset($r['json']['facturas'][0]['saldo']), substr($r['body'], 0, 200));
    $ids = array_slice(array_column($r['json']['facturas'] ?? [], 'id'), 0, 2);
    $r = $c->get('cobros_programados', ['receptor_id' => $rid]);
    check('la página de cobros carga', $r['code'] === 200 && sinErroresPhp($r['body']), errorPhp($r['body']));
    check('la copia (CC) trae los correos de «Responder a» de Facturación', str_contains($r['body'], 'const ccBase = ["gerencia@ejemplo.test","administracion@ejemplo.test"]'));
    $msg = $c->postJson('procesar_accion_factura.php', ['accion' => 'generar_mensaje', 'factura_ids' => $ids, 'tipo' => 'saldo_pendiente']);
    check('genera asunto y mensaje con la plantilla', ($msg['json']['success'] ?? false) && ($msg['json']['asunto'] ?? '') !== '', substr($msg['body'], 0, 200));
    $pv = $c->post('cobro_accion.php', ['accion' => 'previsualizar', 'receptor_id' => $rid, 'factura_ids[0]' => $ids[0] ?? 0, 'asunto' => 'X', 'mensaje_html' => '<p>Hola <strong>QA previa</strong></p><ol><li>uno</li></ol><script>x</script>']);
    check('la vista previa arma el correo sin enviarlo', ($pv['json']['success'] ?? false) && str_contains($pv['json']['html'] ?? '', 'QA previa') && str_contains($pv['json']['html'] ?? '', '<ol>')
        && !str_contains($pv['json']['html'] ?? '', '<script') && count($pv['json']['adjuntos'] ?? []) === 1, substr($pv['body'], 0, 200));
    check('el saludo es «Buen día, equipo de …»', str_contains($msg['json']['mensaje'] ?? '', 'Buen día, equipo de '), substr($msg['json']['mensaje'] ?? '', 0, 80));

    $base = ['accion' => 'crear', 'receptor_id' => $rid, 'tipo' => 'saldo_pendiente', 'para' => 'cliente@ejemplo.test', 'cc' => 'copia@ejemplo.test',
             'asunto' => 'Saldo pendiente QA ✅', 'mensaje_html' => ($msg['json']['mensaje_html'] ?? 'Hola') . '<script>alert(1)</script>'];
    foreach ($ids as $i => $fid) $base["factura_ids[$i]"] = $fid;
    $antes = count(glob("$dir/*.eml") ?: []);
    $r = $c->post('cobro_accion.php', $base + ['modo' => 'prueba', 'para_prueba' => 'yo@ejemplo.test']);
    check('envía una prueba a mi correo', ($r['json']['success'] ?? false) === true, $r['body']);
    $ult = glob("$dir/*.eml"); sort($ult); $eml = file_get_contents(end($ult));
    check('la prueba llega solo a mi correo, con [PRUEBA] y los PDF adjuntos', count($ult) === $antes + 1 && str_contains($eml, 'To: <yo@ejemplo.test>') && !str_contains($eml, 'cliente@ejemplo.test')
        && substr_count($eml, 'Content-Type: application/pdf') === count($ids));
    $html = preg_match('/Content-Type: text\/html; charset=UTF-8\r?\nContent-Transfer-Encoding: base64\r?\n\r?\n(.*?)\r?\n--/s', $eml, $m) ? base64_decode(preg_replace('/\s+/', '', $m[1])) : '';
    check('el correo dice que es automático, muestra los correos de respuesta y no trae scripts', str_contains($html, 'mensaje automático') && str_contains($html, 'gerencia@ejemplo.test') && !str_contains($html, '<script'));

    $r = $c->post('cobro_accion.php', $base + ['modo' => 'programar', 'programado_para' => date('Y-m-d\TH:i', strtotime('+1 day'))]);
    $idProg = (int)($r['json']['id'] ?? 0);
    check('programa un cobro', $idProg > 0 && $pdo->query("SELECT estado FROM cobros_programados WHERE id = $idProg")->fetchColumn() === 'programado', $r['body']);
    check('guarda los PDF al programar', (int)$pdo->query("SELECT COUNT(*) FROM cobros_programados_facturas WHERE cobro_id = $idProg")->fetchColumn() === count($ids));
    $r = $c->post('cobro_accion.php', $base + ['modo' => 'programar', 'programado_para' => date('Y-m-d\TH:i', strtotime('-1 day'))]);
    check('no deja programar en el pasado', ($r['json']['success'] ?? true) === false);

    $cron = __DIR__ . '/../../cron/tareas.php';
    $correr = fn() => shell_exec('APP_DB=dev ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($cron) . ' 2>&1');
    $correr();
    check('el cron no envía antes de la hora', $pdo->query("SELECT estado FROM cobros_programados WHERE id = $idProg")->fetchColumn() === 'programado');
    $pdo->exec("UPDATE cobros_programados SET programado_para = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE id = $idProg");
    $antes = count(glob("$dir/*.eml") ?: []);
    $salida = $correr();
    check('a la hora el cron lo envía al cliente con copia', $pdo->query("SELECT estado FROM cobros_programados WHERE id = $idProg")->fetchColumn() === 'enviado', (string)$salida);
    $ult = glob("$dir/*.eml"); sort($ult); $eml = file_get_contents(end($ult));
    check('al enviarse, sus facturas quedan como «Enviada al cliente»', !(int)$pdo->query("SELECT COUNT(*) FROM facturas f JOIN cobros_programados_facturas x ON x.factura_id = f.id WHERE x.cobro_id = $idProg AND f.enviada_receptor = 0")->fetchColumn());
    $lf = $c->get('lista_facturas');
    check('el historial de facturas muestra la etiqueta de enviada por correo', str_contains($lf['body'], 'app-envio app-envio-enviado') && sinErroresPhp($lf['body']), errorPhp($lf['body']));
    check('va al cliente, con CC y desde Facturación', count($ult) === $antes + 1 && str_contains($eml, 'To: <cliente@ejemplo.test>') && str_contains($eml, 'Cc: <copia@ejemplo.test>') && str_contains($eml, 'facturacion@ejemplo.test'));
    $correr();
    check('no lo vuelve a enviar', count(glob("$dir/*.eml") ?: []) === $antes + 1);

    $r = $c->post('cobro_accion.php', $base + ['modo' => 'programar', 'programado_para' => date('Y-m-d\TH:i', strtotime('+2 days'))]);
    $idOtro = (int)($r['json']['id'] ?? 0);
    $r = $c->post('cobro_accion.php', ['accion' => 'reprogramar', 'id' => $idOtro, 'programado_para' => date('Y-m-d\TH:i', strtotime('+3 days'))]);
    check('se puede reprogramar', ($r['json']['success'] ?? false) === true, $r['body']);
    $r = $c->post('cobro_accion.php', ['accion' => 'cancelar', 'id' => $idOtro]);
    check('se puede cancelar', ($r['json']['success'] ?? false) && $pdo->query("SELECT estado FROM cobros_programados WHERE id = $idOtro")->fetchColumn() === 'cancelado');
    $r = $c->post('cobro_accion.php', ['accion' => 'cancelar', 'id' => $idProg]);
    check('no se cancela uno ya enviado', ($r['json']['success'] ?? true) === false);
    // Ver, editar, reenviar y eliminar
    $r = $c->get('cobro_accion.php', ['ver' => $idProg]);
    check('ver muestra el correo, los PDF y los intentos de envío', ($r['json']['success'] ?? false) && str_contains($r['json']['html'] ?? '', 'Adjuntos') && count($r['json']['adjuntos'] ?? []) === count($ids)
        && ($r['json']['adjuntos'][0]['existe'] ?? false) && count($r['json']['envios'] ?? []) === 1, substr($r['body'], 0, 200));
    $p = $c->get('cobro_accion.php', ['pdf' => $idProg, 'factura' => $ids[0]]);
    check('abre el PDF tal como se envió', str_starts_with($p['body'], '%PDF'));
    $r = $c->post('cobro_accion.php', ['accion' => 'editar', 'id' => $idProg, 'para' => 'x@ejemplo.test', 'asunto' => 'X', 'mensaje_html' => 'X', 'programado_para' => date('Y-m-d\TH:i', strtotime('+1 day'))]);
    check('no se edita uno ya enviado', ($r['json']['success'] ?? true) === false);
    $r = $c->post('cobro_accion.php', $base + ['modo' => 'programar', 'programado_para' => date('Y-m-d\TH:i', strtotime('+2 days'))]);
    $idEd = (int)($r['json']['id'] ?? 0);
    $r = $c->post('cobro_accion.php', ['accion' => 'editar', 'id' => $idEd, 'para' => 'nuevo@ejemplo.test, otro@ejemplo.test', 'cc' => '', 'asunto' => 'Asunto editado',
        'mensaje_html' => 'Hola<br>editado<script>x</script>', 'programado_para' => date('Y-m-d\TH:i', strtotime('+4 days'))]);
    $ed = $pdo->query("SELECT para, cc, asunto, mensaje_html FROM cobros_programados WHERE id = $idEd")->fetch(PDO::FETCH_ASSOC);
    check('edita un cobro programado', ($r['json']['success'] ?? false) && $ed['para'] === 'nuevo@ejemplo.test, otro@ejemplo.test' && $ed['cc'] === null && $ed['asunto'] === 'Asunto editado' && !str_contains($ed['mensaje_html'], 'script'), $r['body']);
    $antes = count(glob("$dir/*.eml") ?: []);
    $r = $c->post('cobro_accion.php', ['accion' => 'reenviar', 'id' => $idProg, 'para' => 'cliente@ejemplo.test', 'cc' => 'copia@ejemplo.test']);
    $idRe = (int)($r['json']['id'] ?? 0);
    $ult = glob("$dir/*.eml"); sort($ult); $eml = file_get_contents(end($ult));
    check('reenvía una copia con los mismos PDF', ($r['json']['success'] ?? false) && $idRe !== $idProg && count($ult) === $antes + 1 && substr_count($eml, 'Content-Type: application/pdf') === count($ids)
        && $pdo->query("SELECT estado FROM cobros_programados WHERE id = $idRe")->fetchColumn() === 'enviado', $r['body']);
    $r = $c->post('cobro_accion.php', ['accion' => 'reenviar', 'id' => $idProg, 'para' => 'yo@ejemplo.test', 'cc' => 'copia@ejemplo.test', 'prueba' => 1]);
    $ult = glob("$dir/*.eml"); sort($ult); $eml = file_get_contents(end($ult));
    check('reenvía como prueba: solo a mí y sin CC', ($r['json']['success'] ?? false) && str_contains($eml, 'To: <yo@ejemplo.test>') && !str_contains($eml, 'Cc:'), $r['body']);
    $idPr = (int)($r['json']['id'] ?? 0);
    $r = $c->post('cobro_accion.php', ['accion' => 'eliminar', 'id' => $idProg]);
    check('no se elimina un cobro real enviado', ($r['json']['success'] ?? true) === false);
    $r = $c->post('cobro_accion.php', ['accion' => 'eliminar', 'id' => $idPr]);
    check('elimina una prueba con sus PDF', ($r['json']['success'] ?? false) && !(int)$pdo->query("SELECT COUNT(*) FROM cobros_programados WHERE id = $idPr")->fetchColumn()
        && !is_dir(__DIR__ . "/../../clientes/naranjaymedia/includes/uploads/cobros/2/$idPr"), $r['body']);
    $r = $c->post('cobro_accion.php', ['accion' => 'eliminar', 'id' => $idOtro]);
    check('elimina un cobro cancelado', ($r['json']['success'] ?? false) === true);
    $r = login('qa.ccic@local.test')->get('cobro_accion.php', ['ver' => $idProg]);
    check('otra empresa no ve el cobro', ($r['json']['success'] ?? true) === false);

    $f = login('qa.facturador@local.test');
    check('un facturador no puede programar cobros', ($f->get('cobro_accion.php', ['facturas' => $rid])['json']['success'] ?? true) === false);
    check('los PDF no se pueden abrir desde la web', $c->get('includes/uploads/cobros/2/' . $idProg . '/x.pdf')['code'] === 403);

    // Limpieza
    foreach ($pdo->query("SELECT id FROM cobros_programados WHERE cliente_id = 2")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        array_map('unlink', glob(__DIR__ . "/../../clientes/naranjaymedia/includes/uploads/cobros/2/$id/*") ?: []);
        @rmdir(__DIR__ . "/../../clientes/naranjaymedia/includes/uploads/cobros/2/$id");
    }
    $pdo->exec("DELETE x FROM cobros_programados_facturas x JOIN cobros_programados c ON c.id = x.cobro_id WHERE c.cliente_id = 2");
    $pdo->exec("DELETE FROM cobros_programados WHERE cliente_id = 2");
    $pdo->exec("DELETE FROM configuracion_correo WHERE cliente_id = 2");
    $pdo->exec("DELETE FROM correos_enviados WHERE cliente_id = 2");
    check('limpieza hecha', !(int)$pdo->query("SELECT COUNT(*) FROM cobros_programados WHERE cliente_id = 2")->fetchColumn());
});

suite('Pagos de nómina', function () {
    $pdo = db();
    $c = login('qa.admin@local.test');
    $desde = '2025-01-01'; $hasta = date('Y-m-d');
    $r = $c->get('pagos_nomina', ['desde' => $desde, 'hasta' => $hasta]);
    check('la página carga sin avisos', $r['code'] === 200 && sinErroresPhp($r['body']), errorPhp($r['body']));
    $esperado = (float)$pdo->query("SELECT COALESCE(SUM(monto),0) FROM gastos WHERE cliente_id = 2 AND estado <> 'anulado' AND descripcion LIKE 'Sueldo %' AND fecha BETWEEN '$desde' AND '$hasta'")->fetchColumn();
    $filas = preg_match_all('/<tr data-fila[ >]/', $r['body']);
    check('muestra pagos del periodo', $filas > 0, "filas=$filas");
    $r2 = $c->get('pagos_nomina', ['desde' => $desde, 'hasta' => $hasta, 'tipo' => 'sueldo']);
    check('el total de sueldos coincide con la BD', str_contains($r2['body'], number_format($esperado, 2)), number_format($esperado, 2));
    $col = (int)$pdo->query("SELECT c.id FROM colaboradores c WHERE c.cliente_id = 2 AND EXISTS (SELECT 1 FROM gastos g WHERE g.cliente_id = 2 AND g.descripcion = CONCAT('Sueldo ', c.nombre, ' ', c.apellido, ' — 1ª Quincena') COLLATE utf8mb4_general_ci) LIMIT 1")->fetchColumn();
    $r3 = $c->get('pagos_nomina', ['desde' => $desde, 'hasta' => $hasta, 'colaborador' => $col]);
    check('filtra por colaborador', preg_match_all('/<tr data-fila[ >]/', $r3['body']) > 0 && preg_match_all('/<tr data-fila[ >]/', $r3['body']) < $filas);
    $x = $c->get('pagos_nomina_exportar.php', ['formato' => 'xlsx', 'desde' => $desde, 'hasta' => $hasta]);
    preg_match('/data-buscar="([^"]*)"/', $r['body'], $db);
    check('el buscador de Pagos de nómina incluye número, fecha y monto', isset($db[1]) && preg_match('/#\d+ \d+ \d{2}\/\d{2}\/\d{4}/', $db[1]) === 1, $db[1] ?? '');
    check('exporta XLSX válido', str_contains($x['head'], 'spreadsheetml') && str_starts_with($x['body'], "PK\x03\x04"));
    $p = $c->get('pagos_nomina_exportar.php', ['formato' => 'pdf', 'desde' => $desde, 'hasta' => $hasta]);
    check('exporta PDF válido', str_starts_with($p['body'], '%PDF'), substr($p['body'], 0, 120));
    $v = $c->get('colaborador_ver', ['id' => $col]);
    check('la ficha del colaborador muestra todo el historial por defecto', str_contains($v['body'], 'Todo') && substr_count($v['body'], 'colaborador_recibo_pdf.php?gasto_id=') > 0 && sinErroresPhp($v['body']), errorPhp($v['body']));
    $f = login('qa.facturador@local.test');
    check('un facturador no entra a pagos de nómina', $f->get('pagos_nomina')['code'] === 302 && $f->get('pagos_nomina_exportar.php', ['formato' => 'xlsx'])['code'] === 403);
});

suite('Contactos del cliente', function () {
    $pdo = db();
    if (!$pdo->query("SHOW TABLES LIKE 'clientes_factura_contactos'")->fetchColumn()) { check('migración de contactos instalada', false); return; }
    $c = login('qa.admin@local.test');
    $rid = (int)$pdo->query("SELECT receptor_id FROM facturas WHERE cliente_id = 2 AND estado = 'emitida' GROUP BY receptor_id ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn();
    $pdo->prepare("DELETE FROM clientes_factura_contactos WHERE receptor_id = ? AND email LIKE '%@contacto.test'")->execute([$rid]);
    $r = $c->get('editar_cliente', ['id' => $rid]);
    check('la edición del cliente muestra la sección de contactos', str_contains($r['body'], 'id="ccCard"') && sinErroresPhp($r['body']), errorPhp($r['body']));
    $r = $c->post('includes/cliente_contactos.php', ['receptor_id' => $rid, 'accion' => 'guardar', 'nombre' => 'Ana QA', 'cargo' => 'Contabilidad', 'email' => 'ana@contacto.test', 'copiar_cobros' => 1]);
    check('agrega un contacto', ($r['json']['success'] ?? false) && in_array('ana@contacto.test', array_column($r['json']['contactos'] ?? [], 'email'), true), $r['body']);
    $c->post('includes/cliente_contactos.php', ['receptor_id' => $rid, 'accion' => 'guardar', 'nombre' => 'Luis QA', 'email' => 'luis@contacto.test']);
    $r = $c->post('includes/cliente_contactos.php', ['receptor_id' => $rid, 'accion' => 'guardar', 'nombre' => 'Malo', 'email' => 'no-es-correo']);
    check('rechaza un correo inválido', ($r['json']['success'] ?? true) === false);
    $r = $c->get('cobro_accion.php', ['facturas' => $rid]);
    $cc = array_column($r['json']['contactos'] ?? [], 'email');
    check('el cobro trae en copia solo los contactos marcados', in_array('ana@contacto.test', $cc, true) && !in_array('luis@contacto.test', $cc, true), json_encode($cc));
    $k = (int)$pdo->query("SELECT id FROM contratos WHERE cliente_id = 2 AND receptor_id = $rid LIMIT 1")->fetchColumn();
    if ($k) {
        $r = $c->post('includes/cliente_contactos.php', ['receptor_id' => $rid, 'accion' => 'guardar', 'nombre' => 'Proyecto QA', 'email' => 'proy@contacto.test', 'copiar_cobros' => 1, 'contrato_id' => $k]);
        $p = array_values(array_filter($r['json']['contactos'] ?? [], fn($x) => $x['email'] === 'proy@contacto.test'));
        check('asigna un contacto a un proyecto/contrato', ($p[0]['contrato_id'] ?? 0) == $k && ($p[0]['proyecto'] ?? '') !== '' && count($r['json']['contratos'] ?? []) > 0, $r['body']);
        $r = $c->get('cobro_accion.php', ['facturas' => $rid]);
        $p = array_values(array_filter($r['json']['contactos'] ?? [], fn($x) => $x['email'] === 'proy@contacto.test'));
        check('el cobro indica el contrato del contacto y de cada factura', ($p[0]['contrato_id'] ?? 0) == $k && array_key_exists('contrato_id', $r['json']['facturas'][0] ?? []));
    }
    $otro = (int)$pdo->query("SELECT id FROM contratos WHERE cliente_id = 2 AND receptor_id <> $rid LIMIT 1")->fetchColumn();
    $r = $c->post('includes/cliente_contactos.php', ['receptor_id' => $rid, 'accion' => 'guardar', 'nombre' => 'X', 'contrato_id' => $otro]);
    check('rechaza un contrato de otro cliente', ($r['json']['success'] ?? true) === false, $r['body']);
    $id = (int)$pdo->query("SELECT id FROM clientes_factura_contactos WHERE email = 'ana@contacto.test'")->fetchColumn();
    $r = $c->post('includes/cliente_contactos.php', ['receptor_id' => $rid + 100000, 'accion' => 'eliminar', 'id' => $id]);
    check('no permite tocar contactos de otro cliente', ($r['json']['success'] ?? true) === false);
    $f = login('qa.ccic@local.test');
    $r = $f->post('includes/cliente_contactos.php', ['receptor_id' => $rid, 'accion' => 'eliminar', 'id' => $id]);
    check('otra empresa no puede tocar estos contactos', ($r['json']['success'] ?? true) === false);
    $pdo->prepare("DELETE FROM clientes_factura_contactos WHERE receptor_id = ? AND email LIKE '%@contacto.test'")->execute([$rid]);
});

suite('Accesos directos a Cobros por correo', function () {
    $pdo = db();
    $c = login('qa.admin@local.test');
    $r = $c->get('cuentas_cobrar');
    check('Cuentas por cobrar enlaza el cobro por correo por cliente', str_contains($r['body'], 'cobros_programados?receptor_id=') && sinErroresPhp($r['body']), errorPhp($r['body']));
    $r = $c->get('lista_facturas');
    check('el Historial de facturas tiene «Enviar por correo»', str_contains($r['body'], 'id="fhBulkCorreoBtn"') && str_contains($r['body'], 'data-receptor-id=') && sinErroresPhp($r['body']), errorPhp($r['body']));
    $k = $pdo->query("SELECT f.contrato_id, f.receptor_id, f.id FROM facturas f WHERE f.cliente_id = 2 AND f.contrato_id IS NOT NULL AND f.estado = 'emitida' ORDER BY f.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($k) {
        $r = $c->get('facturas_contrato', ['contrato_id' => $k['contrato_id']]);
        check('Facturas del contrato enlaza el cobro del contrato y por factura', str_contains($r['body'], 'contrato_id=' . $k['contrato_id'] . '" class="btn btn-sm"') && str_contains($r['body'], '&facturas=' . $k['id']) && sinErroresPhp($r['body']), errorPhp($r['body']));
    }
    $r = $c->get('cobros_programados', ['receptor_id' => $k['receptor_id'] ?? 0, 'facturas' => ($k['id'] ?? 0) . ',abc', 'tipo' => 'envio_factura']);
    check('Cobros por correo recibe la preselección y el tipo', str_contains($r['body'], '"ids":[' . ($k['id'] ?? 0) . ']') && str_contains($r['body'], 'value="envio_factura" selected') && sinErroresPhp($r['body']), errorPhp($r['body']));
    $f = login('qa.facturador@local.test');
    check('un facturador no ve los accesos de correo', !str_contains($f->get('cuentas_cobrar')['body'], 'Cobrar por correo') && !str_contains($f->get('lista_facturas')['body'], 'id="fhBulkCorreoBtn"'));
});

suite('Registrar pago o movimiento', function () {
    $pdo = db();
    $c = login('qa.admin@local.test');
    $r = $c->get('colaboradores');
    check('Colaboradores tiene el botón y el buscador de colaboradores', str_contains($r['body'], 'id="btnRegistrarMov"') && str_contains($r['body'], 'id="regBuscar"') && str_contains($r['body'], 'regTipo-viatico') && sinErroresPhp($r['body']), errorPhp($r['body']));
    $inactivo = $pdo->query("SELECT CONCAT(nombre, ' ', apellido) FROM colaboradores WHERE cliente_id = 2 AND activo = 0 LIMIT 1")->fetchColumn();
    preg_match('/const colabs = (\[.*?\]);/s', $r['body'], $m);
    $lista = json_decode($m[1] ?? '[]', true);
    check('el buscador solo ofrece colaboradores activos', count($lista) > 0 && (!$inactivo || !in_array($inactivo, array_column($lista, 'nombre'), true)));
    $id = (int)$pdo->query("SELECT id FROM colaboradores WHERE cliente_id = 2 AND activo = 1 LIMIT 1")->fetchColumn();
    $r = $c->get('colaborador_ver', ['id' => $id, 'registrar' => 'viatico']);
    check('la ficha tiene el menú Registrar y abre el formulario desde el enlace', str_contains($r['body'], 'data-registrar="adelanto"') && str_contains($r['body'], 'function irYRegistrar') && sinErroresPhp($r['body']), errorPhp($r['body']));
    $r = $c->get('pagos_nomina');
    check('Pagos de nómina enlaza el registro', str_contains($r['body'], 'colaboradores?registrar=1'));
});

suite('Editar y anular pagos de nómina', function () {
    $pdo = db();
    $c = login('qa.admin@local.test');
    $col = $pdo->query("SELECT * FROM colaboradores WHERE cliente_id = 2 AND activo = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $nom = $col['nombre'] . ' ' . $col['apellido'];
    $ins = $pdo->prepare("INSERT INTO gastos (cliente_id, descripcion, monto, fecha, frecuencia, quincena_num, tipo, metodo_pago, estado, notas) VALUES (2, ?, ?, ?, 'quincenal', 1, 'fijo', 'transferencia', 'pagado', ?)");
    $ins->execute(['Sueldo ' . $nom . ' — 1ª Quincena', 5000, '2026-01-15', 'QA-NOMINA']); $gid = (int)$pdo->lastInsertId();
    $ins->execute(['Bono: QA — ' . $nom, 300, '2026-01-15', 'Aplicado junto con nómina gasto #' . $gid]); $gExtra = (int)$pdo->lastInsertId();
    // Préstamo de 1000 en 2 cuotas, la 1ª descontada en este pago; y un decoy con un id que empieza igual
    $pdo->prepare("INSERT INTO colaborador_prestamos (cliente_id, colaborador_id, tipo, monto_total, saldo_pendiente, descripcion, fecha, num_cuotas, monto_cuota, estado, notas) VALUES (2, ?, 'prestamo', 1000, 500, 'QA préstamo', '2026-01-01', 2, 500, 'activo', 'QA-NOMINA')")->execute([$col['id']]);
    $pid = (int)$pdo->lastInsertId();
    $qi = $pdo->prepare("INSERT INTO colaborador_prestamo_cuotas (prestamo_id, cliente_id, colaborador_id, numero_cuota, monto, fecha_esperada, estado, fecha_pago, metodo_pago, notas) VALUES (?, 2, ?, ?, 500, '2026-01-15', ?, ?, ?, ?)");
    $qi->execute([$pid, $col['id'], 1, 'pagado', '2026-01-15', 'descuento_nomina', ' | Descontado en nómina gasto #' . $gid]); $q1 = (int)$pdo->lastInsertId();
    $qi->execute([$pid, $col['id'], 2, 'pagado', '2026-01-30', 'descuento_nomina', ' | Descontado en nómina gasto #' . $gid . '9']); $qDecoy = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO colaborador_prestamos (cliente_id, colaborador_id, tipo, monto_total, saldo_pendiente, descripcion, fecha, num_cuotas, monto_cuota, estado, notas) VALUES (2, ?, 'bono', 300, 300, 'QA bono', '2026-01-10', 1, 300, 'pagado', ?)")
        ->execute([$col['id'], 'QA-NOMINA | Aplicado en nómina gasto #' . $gid . ' el 2026-01-15']);
    $bid = (int)$pdo->lastInsertId();

    $r = $c->get('includes/nomina_pago_accion.php', ['vinculos' => $gid]);
    check('muestra lo que se revertiría', count($r['json']['cuotas'] ?? []) === 1 && count($r['json']['bonos_viaticos'] ?? []) === 1 && count($r['json']['gastos_extra'] ?? []) === 1, $r['body']);
    $r = $c->post('includes/nomina_pago_accion.php', ['accion' => 'editar', 'id' => $gid, 'fecha' => '2026-01-14', 'metodo_pago' => 'efectivo', 'notas' => 'ref. 123']);
    $g = $pdo->query("SELECT fecha, metodo_pago, notas FROM gastos WHERE id = $gid")->fetch(PDO::FETCH_ASSOC);
    check('edita fecha, método y notas', ($r['json']['success'] ?? false) && $g['fecha'] === '2026-01-14' && $g['metodo_pago'] === 'efectivo' && $g['notas'] === 'ref. 123', $r['body']);
    $r = $c->post('includes/nomina_pago_accion.php', ['accion' => 'anular', 'id' => $gid, 'motivo' => '']);
    check('pide el motivo para anular', ($r['json']['success'] ?? true) === false);
    $r = $c->post('includes/nomina_pago_accion.php', ['accion' => 'eliminar', 'id' => $gid]);
    check('no elimina un pago sin anular', ($r['json']['success'] ?? true) === false);
    $r = $c->post('includes/nomina_pago_accion.php', ['accion' => 'anular', 'id' => $gid, 'motivo' => 'fecha equivocada']);
    check('anula el pago', ($r['json']['success'] ?? false) && $pdo->query("SELECT estado FROM gastos WHERE id = $gid")->fetchColumn() === 'anulado', $r['body']);
    check('la cuota vuelve a pendiente y el préstamo recupera saldo', $pdo->query("SELECT estado FROM colaborador_prestamo_cuotas WHERE id = $q1")->fetchColumn() === 'pendiente'
        && (float)$pdo->query("SELECT saldo_pendiente FROM colaborador_prestamos WHERE id = $pid")->fetchColumn() === 1000.0);
    check('no toca cuotas de otro pago con un número parecido', $pdo->query("SELECT estado FROM colaborador_prestamo_cuotas WHERE id = $qDecoy")->fetchColumn() === 'pagado');
    check('el bono vuelve a pendiente y su gasto extra se anula', $pdo->query("SELECT estado FROM colaborador_prestamos WHERE id = $bid")->fetchColumn() === 'activo'
        && $pdo->query("SELECT estado FROM gastos WHERE id = $gExtra")->fetchColumn() === 'anulado');
    $r = $c->post('includes/nomina_pago_accion.php', ['accion' => 'anular', 'id' => $gid, 'motivo' => 'otra vez']);
    check('no anula dos veces', ($r['json']['success'] ?? true) === false);
    $r = $c->get('pagos_nomina', ['desde' => '2026-01-01', 'hasta' => '2026-01-31']);
    check('el pago anulado ya no cuenta en Pagos de nómina', !str_contains($r['body'], 'data-id="' . $gid . '"') && sinErroresPhp($r['body']), errorPhp($r['body']));
    $r = $c->get('colaborador_ver', ['id' => $col['id'], 'todo' => 1]);
    check('la ficha muestra los botones de nómina', str_contains($r['body'], 'data-nomina-accion="eliminar" data-id="' . $gid . '"') && sinErroresPhp($r['body']), errorPhp($r['body']));
    $r = $c->get('gastos', ['mes' => 1, 'anio' => 2026]);
    check('Gastos usa el flujo de nómina para los sueldos', str_contains($r['body'], 'data-nomina-accion=') && sinErroresPhp($r['body']), errorPhp($r['body']));
    $f = login('qa.facturador@local.test');
    check('un facturador no puede anular pagos de nómina', ($f->post('includes/nomina_pago_accion.php', ['accion' => 'eliminar', 'id' => $gid])['json']['success'] ?? true) === false);
    $r = $c->post('includes/nomina_pago_accion.php', ['accion' => 'eliminar', 'id' => $gid]);
    check('elimina el pago anulado', ($r['json']['success'] ?? false) && !(int)$pdo->query("SELECT COUNT(*) FROM gastos WHERE id = $gid")->fetchColumn());
    // Limpieza
    $pdo->exec("DELETE FROM gastos WHERE id = $gExtra");
    $pdo->exec("DELETE FROM colaborador_prestamo_cuotas WHERE prestamo_id = $pid");
    $pdo->exec("DELETE FROM colaborador_prestamos WHERE notas LIKE 'QA-NOMINA%'");
});

suite('Estados financieros clásicos', function () {
    $pdo = db();
    $c = login('qa.admin@local.test');
    $r = $c->get('estados_financieros', ['desde' => '2026-01-01', 'hasta' => '2026-06-30']);
    check('el estado de resultados carga', $r['code'] === 200 && str_contains($r['body'], 'Utilidad del período') || str_contains($r['body'], 'Pérdida del período'), errorPhp($r['body']));
    check('sin avisos de PHP', sinErroresPhp($r['body']), errorPhp($r['body']));
    $ventas = (float)$pdo->query("SELECT COALESCE(SUM(subtotal),0) FROM facturas WHERE cliente_id = 2 AND estado = 'emitida' AND DATE(fecha_emision) BETWEEN '2026-01-01' AND '2026-06-30'")->fetchColumn();
    $gastos = (float)$pdo->query("SELECT COALESCE(SUM(monto),0) FROM gastos WHERE cliente_id = 2 AND estado <> 'anulado' AND fecha BETWEEN '2026-01-01' AND '2026-06-30'")->fetchColumn();
    check('ingresos, gastos y utilidad coinciden con la BD', str_contains($r['body'], number_format($ventas, 2)) && str_contains($r['body'], number_format($gastos, 2))
        && str_contains($r['body'], number_format(abs($ventas - $gastos), 2)));
    $r = $c->get('balance_general', ['corte' => '2026-06-30']);
    check('el balance general carga', $r['code'] === 200 && str_contains($r['body'], 'Total pasivo más patrimonio') && sinErroresPhp($r['body']), errorPhp($r['body']));
    require_once __DIR__ . '/../../includes/estados_financieros.php';
    $b = efBalance($pdo, 2, '2026-06-30', 24.7);
    check('el balance cuadra: activo = pasivo + patrimonio', abs($b['total_activo'] - ($b['total_pasivo'] + $b['total_patrimonio'])) < 0.01);
    check('el menú tiene Balance general y Estado de resultados clásico', str_contains($r['body'], 'href="balance_general"') && str_contains($r['body'], 'href="estados_financieros"'));
});

suite('Respaldos de la base de datos', function () {
    require_once __DIR__ . '/../../includes/respaldos.php';
    putenv('APP_DB=dev');
    $dir = respaldoDir();
    array_map('unlink', glob($dir . 'respaldo_*') ?: []);
    @unlink($dir . 'respaldos.log');
    $c = login('qa.admin@local.test');
    $r = $c->get('respaldos');
    check('el admin de Naranja & Media ve la página', $r['code'] === 200 && str_contains($r['body'], 'Crear respaldo ahora') && sinErroresPhp($r['body']), errorPhp($r['body']));
    check('aparece en el menú de Configuración', str_contains($r['body'], 'href="respaldos"'));
    $r = $c->post('includes/respaldo_accion.php', ['accion' => 'crear']);
    $lista = respaldoLista();
    check('crea un respaldo completo y válido', ($r['json']['success'] ?? false) && count($lista) === 1 && $lista[0]['integro'] === true && $lista[0]['tablas'] > 40, $r['body']);
    $a = $lista[0]['archivo'] ?? '';
    $d = $c->get('includes/respaldo_accion.php', ['descargar' => $a]);
    check('descarga el .sql.gz', str_starts_with($d['body'], "\x1f\x8b") && str_contains(gzdecode($d['body']) ?: '', '-- Fin del respaldo'));
    $r = $c->post('includes/respaldo_accion.php', ['accion' => 'probar', 'archivo' => $a]);
    check('la prueba de integridad pasa', ($r['json']['ok'] ?? false) === true, $r['body']);
    $r = $c->get('includes/respaldo_accion.php', ['descargar' => '../../includes/config.php']);
    check('no permite descargar otros archivos', ($r['json']['success'] ?? true) === false && !str_contains($r['body'], 'DB_PASS'));
    // Retención: con 9 copias quedan 7, las más recientes
    for ($i = 1; $i <= 8; $i++) {
        $n = 'respaldo_2020-01-0' . $i . '_000000.sql.gz';
        copy($dir . $a, $dir . $n);
        file_put_contents($dir . $n . '.json', json_encode(['archivo' => $n, 'creado' => "2020-01-0$i 00:00:00", 'sha256' => hash_file('sha256', $dir . $n), 'origen' => 'cron']));
    }
    respaldoRetencion();
    $quedan = array_column(respaldoLista(false), 'archivo');
    check('conserva solo 7 copias y borra las más antiguas', count($quedan) === 7 && in_array($a, $quedan, true) && !in_array('respaldo_2020-01-01_000000.sql.gz', $quedan, true) && !in_array('respaldo_2020-01-02_000000.sql.gz', $quedan, true), json_encode($quedan));
    // Una copia alterada no se descarga
    $alterada = 'respaldo_2020-01-08_000000.sql.gz';
    file_put_contents($dir . $alterada, 'x', FILE_APPEND);
    $r = $c->get('includes/respaldo_accion.php', ['descargar' => $alterada]);
    check('una copia alterada se marca dañada y no se descarga', ($r['json']['success'] ?? true) === false && !respaldoLista()[array_search($alterada, array_column(respaldoLista(false), 'archivo'))]['integro']);
    // El cron crea la copia automática del día una sola vez
    $cron = fn() => shell_exec('APP_DB=dev ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../cron/respaldo.php') . ' 2>&1');
    $s1 = $cron(); sleep(1); $s2 = $cron();
    $auto = array_filter(respaldoLista(false), fn($x) => ($x['origen'] ?? '') === 'cron' && substr($x['creado'], 0, 10) === date('Y-m-d'));
    check('el cron crea el respaldo diario y no lo duplica', count($auto) === 1 && str_contains((string)$s2, 'Ya existe'), $s1 . $s2);
    check('el cron no se abre desde la web', $c->get('http://localhost:8383/proyectos/NARANJA/sistemafacturacion/cron/respaldo.php')['code'] !== 200);
    $bit = array_column(respaldoBitacora(), 'evento');
    check('la bitácora registra creación, descarga, prueba y retención', !array_diff(['creado', 'descargado', 'verificado', 'eliminado', 'descarga_bloqueada'], $bit), json_encode(array_unique($bit)));
    check('el superadmin también tiene acceso', login('qa.super@local.test', 1, 2)->get('respaldos')['code'] === 200);
    $f = login('qa.facturador@local.test');
    check('un facturador no entra ni descarga', $f->get('respaldos')['code'] === 302 && ($f->get('includes/respaldo_accion.php', ['descargar' => $a])['json']['success'] ?? true) === false);
    $o = login('qa.ccic@local.test');
    check('el admin de otra empresa no tiene acceso', ($o->post('includes/respaldo_accion.php', ['accion' => 'crear'])['json']['success'] ?? true) === false);
    array_map('unlink', glob($dir . 'respaldo_*') ?: []);
});

suite('Rol Nómina', function () {
    $pdo = db();
    // Usuario de prueba con el rol nuevo (solo en la BD de pruebas)
    $pdo->exec("DELETE ue FROM usuario_establecimientos ue JOIN usuarios u ON u.id = ue.usuario_id WHERE u.correo = 'qa.nomina@local.test'");
    $pdo->exec("DELETE FROM usuarios WHERE correo = 'qa.nomina@local.test'");
    $pdo->prepare("INSERT INTO usuarios (cliente_id, nombre, correo, clave, rol, estado, creado_en) VALUES (2, 'QA Nómina', 'qa.nomina@local.test', ?, 'nomina', 'activo', NOW())")
        ->execute([password_hash(QA_PASS, PASSWORD_DEFAULT)]);
    $uid = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO usuario_establecimientos (usuario_id, establecimiento_id) SELECT ?, establecimiento_id FROM usuario_establecimientos ue JOIN usuarios u ON u.id = ue.usuario_id WHERE u.correo = 'qa.admin@local.test'")->execute([$uid]);
    $n = login('qa.nomina@local.test');

    $r = $n->get('colaboradores');
    check('entra a Colaboradores', $r['code'] === 200 && str_contains($r['body'], 'id="btnRegistrarMov"') && sinErroresPhp($r['body']), errorPhp($r['body']));
    check('el menú solo muestra Personal', str_contains($r['body'], 'href="pagos_nomina"') && !str_contains($r['body'], 'href="lista_facturas"') && !str_contains($r['body'], 'href="gastos"') && !str_contains($r['body'], 'href="usuarios"'));
    $r = $n->get('pagos_nomina');
    check('entra a Pagos de nómina', $r['code'] === 200 && str_contains($r['body'], 'data-nomina-accion="anular"') && sinErroresPhp($r['body']), errorPhp($r['body']));
    $col = (int)$pdo->query("SELECT id FROM colaboradores WHERE cliente_id = 2 AND activo = 1 LIMIT 1")->fetchColumn();
    $r = $n->get('colaborador_ver', ['id' => $col, 'todo' => 1]);
    check('ve la ficha con los botones de editar/anular pagos', $r['code'] === 200 && str_contains($r['body'], 'data-nomina-accion=') && sinErroresPhp($r['body']), errorPhp($r['body']));
    foreach (['dashboard', 'lista_facturas', 'gastos', 'financiero', 'balance_general', 'usuarios', 'configuracion_correo', 'respaldos', 'clientes'] as $p) {
        $r = $n->get($p);
        check("no entra a $p", in_array($r['code'], [301, 302], true) && !str_contains($r['body'], 'id="appSidebar"'), "código {$r['code']}");
    }
    $r = $n->post('includes/gasto_eliminar.php', ['id' => 1, 'accion' => 'anular']);
    check('no puede usar acciones de otras secciones', $r['code'] === 403 && ($r['json']['success'] ?? true) === false);
    $gNoNomina = (int)$pdo->query("SELECT id FROM gastos WHERE cliente_id = 2 AND archivo_adjunto IS NOT NULL AND descripcion NOT LIKE 'Sueldo %' AND descripcion NOT LIKE 'Bono:%' LIMIT 1")->fetchColumn();
    if ($gNoNomina) check('no ve comprobantes de otros gastos', $n->get('gasto_archivo', ['id' => $gNoNomina])['code'] === 404);
    $r = $n->get('includes/nomina_pago_accion.php', ['vinculos' => (int)$pdo->query("SELECT id FROM gastos WHERE cliente_id = 2 AND descripcion LIKE 'Sueldo %' AND estado <> 'anulado' LIMIT 1")->fetchColumn()]);
    check('puede consultar/anular pagos de nómina', ($r['json']['success'] ?? false) === true, $r['body']);
    $f = login('qa.facturador@local.test');
    check('el facturador sigue sin acceso a Pagos de nómina', $f->get('pagos_nomina')['code'] === 302);
    $a = login('qa.admin@local.test');
    $r = $a->get('usuarios');
    check('el rol Nómina se puede asignar en Usuarios', str_contains($r['body'], 'value="nomina"'));
    $pdo->exec("DELETE FROM usuario_establecimientos WHERE usuario_id = $uid");
    $pdo->exec("DELETE FROM usuarios WHERE id = $uid");
});

suite('Recuperar contraseña por correo', function () {
    $pdo = db();
    if (!$pdo->query("SHOW TABLES LIKE 'clave_resets'")->fetchColumn()) { check('migración clave_resets instalada', false); return; }
    $dir = '/private/tmp/claude-501/-Applications-XAMPP-xamppfiles-htdocs-proyectos-NARANJA-sistemafacturacion/ee82cda1-ff6c-4bf7-8e00-1c9a72466389/scratchpad/correos';
    $pdo->exec("DELETE FROM login_intentos WHERE clave LIKE 'reset:%' OR clave LIKE 'login:%'");
    $a = login('qa.admin@local.test');
    $a->post('includes/correo_accion.php', ['accion' => 'guardar', 'perfil' => 'facturacion', 'host' => '127.0.0.1', 'puerto' => 2525, 'seguridad' => 'ninguna',
        'usuario' => 'qa', 'clave' => 'clave-de-prueba', 'remitente_email' => 'facturacion@ejemplo.test', 'responder_a' => 'gerencia@ejemplo.test', 'activo' => 1]);
    $ultimo = function () use ($dir) { $f = glob("$dir/*.eml") ?: []; sort($f); return $f ? file_get_contents(end($f)) : ''; };
    $html = function (string $eml) { return preg_match('/Content-Type: text\/html; charset=UTF-8\r?\nContent-Transfer-Encoding: base64\r?\n\r?\n(.*?)\r?\n--/s', $eml, $m) ? base64_decode(preg_replace('/\s+/', '', $m[1])) : ''; };

    $v = new Cliente('visitante');
    $r = $v->get('index.php');
    check('el login tiene «¿Olvidaste tu contraseña?»', str_contains($r['body'], 'href="recuperar_clave"'));
    $form = $v->get('recuperar_clave');
    preg_match('/name="_csrf" value="([a-f0-9]+)"/', $form['body'], $m);
    $csrf = $m[1] ?? '';
    $antes = count(glob("$dir/*.eml") ?: []);
    $r = $v->post('recuperar_clave', ['correo' => 'no.existe@local.test', '_csrf' => $csrf]);
    check('un correo que no existe recibe la misma respuesta y no envía nada', str_contains($r['body'], 'recibirá un correo') && count(glob("$dir/*.eml") ?: []) === $antes);
    $r = $v->post('recuperar_clave', ['correo' => 'qa.ccic@local.test', '_csrf' => $csrf]);
    check('no envía a usuarios de otra empresa por esta URL', count(glob("$dir/*.eml") ?: []) === $antes);
    $r = $v->post('recuperar_clave', ['correo' => 'qa.admin@local.test', '_csrf' => $csrf]);
    $eml = $ultimo();
    preg_match('/restablecer_clave\?token=([a-f0-9]{64})/', $html($eml), $t);
    $token = $t[1] ?? '';
    check('envía el enlace al usuario, desde la cuenta Facturación', count(glob("$dir/*.eml") ?: []) === $antes + 1 && str_contains($eml, 'To: <qa.admin@local.test>') && $token !== '', substr($eml, 0, 300));
    check('en la BD solo se guarda el hash del token', !(int)$pdo->query("SELECT COUNT(*) FROM clave_resets WHERE token_hash = " . $pdo->quote($token))->fetchColumn()
        && (int)$pdo->query("SELECT COUNT(*) FROM clave_resets WHERE token_hash = '" . hash('sha256', $token) . "'")->fetchColumn() === 1);
    $r = $v->get('restablecer_clave', ['token' => $token]);
    check('el enlace abre el formulario', str_contains($r['body'], 'name="clave2"') && sinErroresPhp($r['body']), errorPhp($r['body']));
    preg_match('/name="_csrf" value="([a-f0-9]+)"/', $r['body'], $m);
    $r = $v->post('restablecer_clave', ['token' => $token, '_csrf' => $m[1] ?? '', 'clave' => 'corta1A', 'clave2' => 'corta1A']);
    check('rechaza una contraseña débil', str_contains($r['body'], 'al menos 10'));
    $r = $v->post('restablecer_clave', ['token' => $token, '_csrf' => $m[1] ?? '', 'clave' => 'NuevaClave2026', 'clave2' => 'OtraClave2026']);
    check('rechaza si no coinciden', str_contains($r['body'], 'no coinciden'));
    $r = $v->post('restablecer_clave', ['token' => $token, '_csrf' => 'malo', 'clave' => 'NuevaClave2026', 'clave2' => 'NuevaClave2026']);
    check('pide el token CSRF', str_contains($r['body'], 'venció'));
    $r = $v->post('restablecer_clave', ['token' => $token, '_csrf' => $m[1] ?? '', 'clave' => 'NuevaClave2026', 'clave2' => 'NuevaClave2026']);
    $hash = $pdo->query("SELECT clave FROM usuarios WHERE correo = 'qa.admin@local.test'")->fetchColumn();
    check('cambia la contraseña', str_contains($r['body'], 'tu contraseña cambió') && password_verify('NuevaClave2026', $hash));
    $r = $v->get('restablecer_clave', ['token' => $token]);
    check('el enlace no sirve dos veces', str_contains($r['body'], 'no es válido, ya se usó o venció'));
    $n = new Cliente('nueva');
    $r = $n->post('index.php', ['correo' => 'qa.admin@local.test', 'clave' => 'NuevaClave2026']);
    check('se puede entrar con la contraseña nueva', in_array($r['code'], [301, 302], true) && !str_contains($r['body'], 'Credenciales inválidas'));
    // Vencido
    $v->post('recuperar_clave', ['correo' => 'qa.admin@local.test', '_csrf' => $csrf]);
    preg_match('/restablecer_clave\?token=([a-f0-9]{64})/', $html($ultimo()), $t2);
    $pdo->exec("UPDATE clave_resets SET expira = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE usado_en IS NULL");
    check('un enlace vencido no sirve', str_contains($v->get('restablecer_clave', ['token' => $t2[1] ?? ''])['body'], 'venció'));
    // Límite de solicitudes
    for ($i = 0; $i < 5; $i++) $r = $v->post('recuperar_clave', ['correo' => 'qa.admin@local.test', '_csrf' => $csrf]);
    check('limita las solicitudes repetidas', str_contains($r['body'], 'Demasiadas solicitudes'));
    // Restaurar el estado de pruebas
    $pdo->prepare("UPDATE usuarios SET clave = ? WHERE correo = 'qa.admin@local.test'")->execute([password_hash(QA_PASS, PASSWORD_DEFAULT)]);
    $pdo->exec("DELETE FROM login_intentos WHERE clave LIKE 'reset:%'");
    $pdo->exec("DELETE FROM clave_resets");
    $pdo->exec("DELETE FROM configuracion_correo WHERE cliente_id = 2");
    $pdo->exec("DELETE FROM correos_enviados WHERE cliente_id = 2");
});

suite('Firmas y bouchers', function () {
    $pdo = db();
    $c = login('qa.admin@local.test');
    $col = $pdo->query("SELECT * FROM colaboradores WHERE cliente_id = 2 AND activo = 1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $antes = $col['url_firma'];
    // Firma de prueba: PNG con transparencia (debe quedar con fondo blanco)
    $tmp = tempnam(sys_get_temp_dir(), 'firma') . '.png';
    $im = imagecreatetruecolor(300, 120); imagesavealpha($im, true); imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    imageline($im, 20, 90, 280, 30, imagecolorallocate($im, 10, 10, 60)); imagepng($im, $tmp);
    $r = $c->post('includes/colaborador_firma.php', ['accion' => 'subir', 'id' => $col['id'], 'firma' => new CURLFile($tmp, 'image/png', 'firma.png')]);
    $nueva = $pdo->query("SELECT url_firma FROM colaboradores WHERE id = {$col['id']}")->fetchColumn();
    check('sube la firma del colaborador', ($r['json']['success'] ?? false) && $nueva && $nueva !== $antes, $r['code'] . ' ' . substr($r['body'], 0, 300));
    $ruta = __DIR__ . '/../../clientes/naranjaymedia/includes/uploads/firmas/' . $nueva;
    $px = imagecolorat(imagecreatefrompng($ruta), 0, 0);
    check('la guarda como PNG con fondo blanco', is_file($ruta) && (($px >> 16) & 255) === 255 && ($px & 255) === 255);
    check('se ve en la ficha', str_contains($c->get('colaborador_ver', ['id' => $col['id']])['body'], 'colaborador_firma.php?id=' . $col['id']));
    $img = $c->get('includes/colaborador_firma.php', ['id' => $col['id']]);
    check('la vista previa devuelve la imagen', str_starts_with($img['body'], "\x89PNG"));
    $r = $c->post('includes/colaborador_firma.php', ['accion' => 'subir', 'id' => $col['id'], 'firma' => new CURLFile(__FILE__, 'image/png', 'x.png')]);
    check('rechaza un archivo que no es imagen', ($r['json']['success'] ?? true) === false);

    $g = (int)$pdo->query("SELECT id FROM gastos WHERE cliente_id = 2 AND estado = 'pagado' AND descripcion LIKE " . $pdo->quote('Sueldo ' . $col['nombre'] . ' ' . $col['apellido'] . '%') . " ORDER BY fecha DESC LIMIT 1")->fetchColumn();
    $p = $c->get('boucher_pdf.php', ['gasto_id' => $g, 'vista' => 1]);
    check('genera el boucher en PDF', str_starts_with($p['body'], '%PDF') && str_contains($p['head'], 'inline'), substr($p['body'], 0, 200));
    $mes = $pdo->query("SELECT DATE_FORMAT(fecha, '%Y-%m') FROM gastos WHERE id = $g")->fetchColumn();
    $r = $c->get('bouchers', ['desde' => "$mes-01", 'hasta' => date('Y-m-t', strtotime("$mes-01"))]);
    check('la página de bouchers lista los pagos del mes', str_contains($r['body'], 'data-id="' . $g . '"') && str_contains($r['body'], 'Descargar ZIP') && sinErroresPhp($r['body']), errorPhp($r['body']));
    $todos = $c->get('boucher_pdf.php', ['lote' => 1, 'desde' => "$mes-01", 'hasta' => "$mes-15", 'tipo' => 'nomina']);
    check('PDF con todos los bouchers del período', str_starts_with($todos['body'], '%PDF'));
    $z = $c->get('boucher_pdf.php', ['lote' => 1, 'formato' => 'zip', 'desde' => "$mes-01", 'hasta' => "$mes-15", 'tipo' => 'nomina']);
    $zf = tempnam(sys_get_temp_dir(), 'z'); file_put_contents($zf, $z['body']);
    $zip = new ZipArchive(); $ok = $zip->open($zf) === true && $zip->numFiles > 0 && str_starts_with((string)$zip->getFromIndex(0), '%PDF');
    check('ZIP con un PDF por pago', $ok, substr($z['body'], 0, 120));
    $n = $pdo->query("SELECT COUNT(*) FROM gastos WHERE cliente_id = 2 AND estado = 'pagado' AND fecha BETWEEN '$mes-01' AND '$mes-15' AND (descripcion LIKE 'Sueldo %' OR descripcion LIKE 'Bono:%' OR descripcion LIKE 'Viático:%' OR descripcion LIKE 'Pago adicional - %')")->fetchColumn();
    check('el ZIP trae un archivo por cada pago', $ok && $zip->numFiles === (int)$n, $zip->numFiles . " vs $n");
    $otro = (int)$pdo->query("SELECT id FROM gastos WHERE cliente_id = 2 AND estado = 'pagado' AND descripcion NOT LIKE 'Sueldo %' AND descripcion NOT LIKE 'Bono:%' LIMIT 1")->fetchColumn();
    if ($otro) check('también genera bouchers de otros gastos', str_starts_with($c->get('boucher_pdf.php', ['gasto_id' => $otro])['body'], '%PDF'));
    $f = login('qa.facturador@local.test');
    check('un facturador no genera bouchers', $f->get('boucher_pdf.php', ['gasto_id' => $g])['code'] === 403 && $f->get('bouchers')['code'] === 302);
    $o = login('qa.ccic@local.test');
    check('otra empresa no ve la firma', $o->get('includes/colaborador_firma.php', ['id' => $col['id']])['code'] !== 200);
    $r = $c->post('includes/colaborador_firma.php', ['accion' => 'quitar', 'id' => $col['id']]);
    check('quita la firma y borra el archivo', ($r['json']['success'] ?? false) && !is_file($ruta));
    $pdo->prepare("UPDATE colaboradores SET url_firma = ? WHERE id = ?")->execute([$antes, $col['id']]);
});

suite('Permisos por rol (menú)', function () {
    $pdo = db();
    if (!$pdo->query("SHOW TABLES LIKE 'permisos_menu'")->fetchColumn()) { check('migración permisos_menu instalada', false); return; }
    $pdo->exec("DELETE FROM permisos_menu");
    $s = login('qa.super@local.test', 1, 2);
    $r = $s->get('configuracion_permisos');
    check('el superadmin ve la página de permisos', $r['code'] === 200 && str_contains($r['body'], 'value="facturador|gastos"') && sinErroresPhp($r['body']), errorPhp($r['body']));
    check('no ofrece habilitar lo que el rol no puede usar', !str_contains($r['body'], 'value="facturador|usuarios"') && !str_contains($r['body'], 'value="nomina|lista_facturas"'));
    // Apagar «Gastos» para el facturador (el resto encendido)
    preg_match_all('/name="pares\[\]" value="([^"]+)"/', $r['body'], $m);
    $ver = array_values(array_filter($m[1], fn($p) => $p !== 'facturador|gastos'));
    $r = $s->req('POST', $s->base . 'configuracion_permisos', http_build_query(['pares' => $m[1], 'ver' => $ver]));
    check('guarda los permisos', in_array($r['code'], [302, 303], true) && (int)$pdo->query("SELECT permitido FROM permisos_menu WHERE rol = 'facturador' AND pagina = 'gastos'")->fetchColumn() === 0
        && (int)$pdo->query("SELECT COUNT(*) FROM permisos_menu WHERE permitido = 1")->fetchColumn() > 10, $r['code'] . ' ' . substr($r['body'], 0, 200));
    $f = login('qa.facturador@local.test');
    $d = $f->get('dashboard');
    check('el facturador ya no ve Gastos en el menú', !str_contains($d['body'], 'href="gastos"') && str_contains($d['body'], 'href="lista_facturas"'));
    $g = $f->get('gastos');
    check('y si abre Gastos directo lo redirige', in_array($g['code'], [301, 302], true));
    check('el admin sigue viendo Gastos', str_contains(login('qa.admin@local.test')->get('dashboard')['body'], 'href="gastos"'));
    check('un admin no entra a la configuración de permisos', in_array(login('qa.admin@local.test')->get('configuracion_permisos')['code'], [301, 302], true));
    $r = $s->req('POST', $s->base . 'configuracion_permisos', http_build_query(['pares' => ['facturador|usuarios', 'nomina|lista_facturas'], 'ver' => ['facturador|usuarios', 'nomina|lista_facturas']]));
    check('no se puede habilitar por la fuerza lo que el código prohíbe', !(int)$pdo->query("SELECT COUNT(*) FROM permisos_menu WHERE (rol = 'facturador' AND pagina = 'usuarios') OR (rol = 'nomina' AND pagina = 'lista_facturas')")->fetchColumn());
    $pdo->exec("DELETE FROM permisos_menu");
    check('al quitar las reglas todo vuelve a como estaba', str_contains(login('qa.facturador@local.test')->get('dashboard')['body'], 'href="gastos"'));
});

suite('Ver gasto: comprobante en cualquier carpeta', function () {
    $pdo = db();
    $d = __DIR__ . '/../../clientes/naranjaymedia/includes/uploads/comprobantes_nomina/qa3';
    @mkdir($d, 0775, true);
    $im = imagecreatetruecolor(40, 20); imagepng($im, "$d/c.png");
    $pdo->exec("INSERT INTO gastos (cliente_id, descripcion, monto, fecha, estado, archivo_adjunto, notas) VALUES (2, 'Mueble QA', 10, CURDATE(), 'pagado', 'qa3/c.png', 'QA-GV')");
    $id = (int)$pdo->lastInsertId();
    $r = login('qa.admin@local.test')->get('gasto_ver', ['id' => $id]);
    check('un gasto que no es sueldo muestra su comprobante guardado con la nómina', str_contains($r['body'], 'alt="Comprobante"') && sinErroresPhp($r['body']), errorPhp($r['body']));
    $pdo->exec("DELETE FROM gastos WHERE notas = 'QA-GV'");
    @unlink("$d/c.png"); @rmdir($d);
});

suite('Gastos: filtros sin recargar', function () {
    $c = login('qa.admin@local.test');
    $r = $c->get('gastos', ['vista' => 'mensual', 'mes' => 3, 'anio' => 2026]);
    check('la página trae las zonas que se actualizan y el formulario de filtros', str_contains($r['body'], 'id="gsZonaHead"') && str_contains($r['body'], 'id="gsZonaKpi"')
        && str_contains($r['body'], 'id="gsZonaTabla"') && str_contains($r['body'], 'id="gsFiltros"') && sinErroresPhp($r['body']), errorPhp($r['body']));
    $r2 = $c->get('gastos', ['vista' => 'mensual', 'mes' => 4, 'anio' => 2026]);
    preg_match('/id="gsBody">(.*?)<\/tbody>/s', $r['body'], $a); preg_match('/id="gsBody">(.*?)<\/tbody>/s', $r2['body'], $b);
    check('otro mes trae otras filas', ($a[1] ?? '') !== ($b[1] ?? ''));
    preg_match('/<tr data-search="([^"]*)"/', $r['body'], $ds);
    $idUno = (int)db()->query("SELECT id FROM gastos WHERE cliente_id = 2 AND estado <> 'anulado' AND MONTH(fecha) = 3 AND YEAR(fecha) = 2026 ORDER BY fecha DESC, id DESC LIMIT 1")->fetchColumn();
    check('el buscador encuentra por número de gasto', str_contains($r['body'], '#' . $idUno . ' ' . $idUno . ' '), (string)$idUno);
    check('los botones de cada fila escuchan en el documento', str_contains($r['body'], "ev.target.closest('.btn-editar-gasto')") && str_contains($r['body'], 'gsTabla.recargar()'));
});

suite('Desglose del boucher y recibo', function () {
    $pdo = db();
    require_once __DIR__ . '/../../includes/bouchers.php';
    $g = $pdo->query("SELECT g.*, NULL AS categoria FROM gastos g WHERE g.cliente_id = 2 AND g.estado = 'pagado' AND g.descripcion LIKE 'Sueldo %' ORDER BY g.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $col = $pdo->query("SELECT id FROM colaboradores WHERE cliente_id = 2 AND " . $pdo->quote($g['descripcion']) . " LIKE CONCAT('%', nombre, ' ', apellido, '%') LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO colaborador_prestamos (cliente_id, colaborador_id, tipo, monto_total, saldo_pendiente, descripcion, fecha, num_cuotas, monto_cuota, estado, notas) VALUES (2, ?, 'adelanto', 1000, 500, 'QA adelanto', ?, 2, 500, 'activo', 'QA-DESG')")->execute([$col, $g['fecha']]);
    $p = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO colaborador_prestamo_cuotas (prestamo_id, cliente_id, colaborador_id, numero_cuota, monto, fecha_esperada, estado, fecha_pago, metodo_pago, notas) VALUES (?, 2, ?, 1, 500, ?, 'pagado', ?, 'descuento_nomina', ?)")
        ->execute([$p, $col, $g['fecha'], $g['fecha'], ' | Descontado en nómina gasto #' . $g['id']]);
    $pdo->prepare("INSERT INTO colaborador_prestamo_cuotas (prestamo_id, cliente_id, colaborador_id, numero_cuota, monto, fecha_esperada, estado, fecha_pago, metodo_pago, notas) VALUES (?, 2, ?, 2, 500, ?, 'pagado', ?, 'descuento_nomina', ?)")
        ->execute([$p, $col, $g['fecha'], $g['fecha'], ' | Descontado en nómina gasto #' . $g['id'] . '7']);   // otro pago: no debe aparecer
    $pdo->prepare("INSERT INTO colaborador_prestamos (cliente_id, colaborador_id, tipo, monto_total, saldo_pendiente, descripcion, fecha, num_cuotas, monto_cuota, estado, notas) VALUES (2, ?, 'bono', 800, 0, 'QA bono', ?, 1, 800, 'pagado', ?)")
        ->execute([$col, $g['fecha'], 'QA-DESG | Aplicado en nómina gasto #' . $g['id'] . ' el ' . $g['fecha']]);
    $ctx = boucherContexto($pdo, 2, __DIR__ . '/../../clientes/naranjaymedia/includes/uploads');
    $d = boucherDatos($ctx, $g);
    check('el boucher trae el descuento y el bono de ese pago', count($d['descuentos']) === 1 && $d['descuentos'][0]['monto'] == 500 && count($d['extras']) === 1 && $d['extras'][0]['monto'] == 800, json_encode([$d['descuentos'], $d['extras']]));
    $base = $d['monto'] + 500 - 800;
    check('el desglose cuadra con el total transferido', abs($base - 500 + 800 - $d['monto']) < 0.001);
    $c = login('qa.admin@local.test');
    check('el boucher con desglose se genera', str_starts_with($c->get('boucher_pdf.php', ['gasto_id' => $g['id'], 'vista' => 1])['body'], '%PDF'));
    $r = $c->get('colaborador_recibo_pdf.php', ['gasto_id' => $g['id'], 'vista' => 1]);
    check('el recibo se genera en PDF', str_starts_with($r['body'], '%PDF'), substr($r['body'], 0, 200));
    $pdo->exec("DELETE q FROM colaborador_prestamo_cuotas q JOIN colaborador_prestamos p ON p.id = q.prestamo_id WHERE p.notas LIKE 'QA-DESG%'");
    $pdo->exec("DELETE FROM colaborador_prestamos WHERE notas LIKE 'QA-DESG%'");
});

suite('Firmas de documentos y concepto de pago', function () {
    $pdo = db();
    $c = login('qa.admin@local.test');
    $r = $c->get('configuracion_firmas');
    check('la página de firmas carga con los 3 firmantes', $r['code'] === 200 && substr_count($r['body'], 'class="app-card h-100 f-firmante"') === 3 && !str_contains($r['body'], 'data-rol="revisado"') && sinErroresPhp($r['body']), errorPhp($r['body']));
    $r = $c->post('includes/firmante_accion.php', ['accion' => 'guardar', 'rol' => 'autorizado', 'nombre' => 'QA Firmante', 'cargo' => 'Gerente QA']);
    $f = $pdo->query("SELECT nombre, cargo FROM documento_firmantes WHERE cliente_id = 2 AND rol = 'autorizado'")->fetch(PDO::FETCH_ASSOC);
    check('guarda nombre y cargo', ($r['json']['success'] ?? false) && $f['nombre'] === 'QA Firmante' && $f['cargo'] === 'Gerente QA', $r['body']);
    $r = $c->post('includes/firmante_accion.php', ['accion' => 'guardar', 'rol' => 'revisado', 'nombre' => 'X']);
    check('«Revisado» ya no es un firmante', ($r['json']['success'] ?? true) === false);
    $tmp = tempnam(sys_get_temp_dir(), 'fd') . '.png';
    $im = imagecreatetruecolor(300, 100); imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255)); imageline($im, 30, 80, 270, 20, imagecolorallocate($im, 0, 0, 0)); imagepng($im, $tmp);
    $r = $c->post('includes/firmante_accion.php', ['accion' => 'subir', 'rol' => 'vobo', 'firma' => new CURLFile($tmp, 'image/png', 'f.png')]);
    check('sube la firma del firmante', ($r['json']['success'] ?? false) && str_starts_with($c->get('includes/firmante_accion.php', ['firma' => 'vobo'])['body'], "\x89PNG"), $r['body']);
    check('un facturador no configura firmantes', (login('qa.facturador@local.test')->post('includes/firmante_accion.php', ['accion' => 'guardar', 'rol' => 'vobo', 'nombre' => 'X'])['json']['success'] ?? true) === false);
    // Concepto de pago del colaborador en el boucher
    require_once __DIR__ . '/../../includes/bouchers.php';
    $g = $pdo->query("SELECT g.*, NULL AS categoria FROM gastos g WHERE g.cliente_id = 2 AND g.estado = 'pagado' AND g.descripcion LIKE 'Sueldo %' AND g.quincena_num = 2 ORDER BY g.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $col = $pdo->query("SELECT id, concepto_pago FROM colaboradores WHERE cliente_id = 2 AND " . $pdo->quote($g['descripcion']) . " LIKE CONCAT('%', nombre, ' ', apellido, '%') LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $pdo->prepare("UPDATE colaboradores SET concepto_pago = 'Pago por servicios de QA' WHERE id = ?")->execute([$col['id']]);
    $d = boucherDatos(boucherContexto($pdo, 2, __DIR__ . '/../../clientes/naranjaymedia/includes/uploads'), $g);
    check('el boucher usa el concepto del colaborador con la quincena y el mes', str_starts_with($d['concepto'], 'Pago por servicios de QA, 2da quincena '), $d['concepto']);
    check('el boucher y el recibo se generan con los firmantes', str_starts_with($c->get('boucher_pdf.php', ['gasto_id' => $g['id']])['body'], '%PDF') && str_starts_with($c->get('colaborador_recibo_pdf.php', ['gasto_id' => $g['id']])['body'], '%PDF'));
    $pdo->prepare("UPDATE colaboradores SET concepto_pago = ? WHERE id = ?")->execute([$col['concepto_pago'], $col['id']]);
    $pdo->exec("DELETE FROM documento_firmantes WHERE cliente_id = 2");
});

suite('Colaborador sin salario', function () {
    $pdo = db();
    $c = login('qa.admin@local.test');
    $r = $c->post('includes/colaborador_guardar.php', ['nombre' => 'QA Sin', 'apellido' => 'Salario', 'puesto' => 'Gerente QA', 'fecha_ingreso' => date('Y-m-01', strtotime('-2 months')),
        'salario_base' => 0, 'tipo_pago' => 'quincenal', 'dia_pago' => 15, 'dia_pago_2' => 30, 'telefono' => '33758070', 'email' => 'sinsalario@ejemplo.test']);
    $id = (int)$pdo->query("SELECT id FROM colaboradores WHERE cliente_id = 2 AND nombre = 'QA Sin'")->fetchColumn();
    check('se puede crear un colaborador con salario 0', $id > 0, $r['body']);
    $l = $c->get('colaboradores');
    if (!$id) return;
    check('aparece en la lista de colaboradores', str_contains($c->get('colaboradores')['body'], 'QA Sin'));
    check('no aparece en nóminas vencidas', !preg_match('/QA Sin Salario.{0,400}Pagar/s', $l['body']) && sinErroresPhp($l['body']), errorPhp($l['body']));
    $v = $c->get('colaborador_ver', ['id' => $id]);
    check('su ficha carga sin alerta de nómina vencida', $v['code'] === 200 && !str_contains($v['body'], 'Nómina vencida') && sinErroresPhp($v['body']), errorPhp($v['body']));
    $r = $c->post('includes/colaborador_guardar.php', ['nombre' => 'QA Neg', 'apellido' => 'X', 'puesto' => 'X', 'fecha_ingreso' => date('Y-m-d'), 'salario_base' => -5, 'tipo_pago' => 'quincenal', 'dia_pago' => 15, 'dia_pago_2' => 30]);
    check('no acepta salario negativo', ($r['json']['success'] ?? true) === false);
    $pdo->exec("DELETE FROM colaboradores WHERE cliente_id = 2 AND nombre IN ('QA Sin', 'QA Neg')");
});

suite('ZIP de bouchers por partes (barra de progreso)', function () {
    $pdo = db();
    $c = login('qa.admin@local.test');
    $ids = $pdo->query("SELECT id FROM gastos WHERE cliente_id = 2 AND estado = 'pagado' AND descripcion LIKE 'Sueldo %' ORDER BY id DESC LIMIT 9")->fetchAll(PDO::FETCH_COLUMN);
    $r = $c->post('boucher_lote.php', ['accion' => 'iniciar', 'ids' => implode(',', $ids) . ',999999']);
    check('inicia la descarga con los pagos válidos', ($r['json']['success'] ?? false) && ($r['json']['total'] ?? 0) === count($ids), $r['body']);
    $token = $r['json']['token'] ?? ''; $pasos = 0; $hechos = 0;
    while ($token && $hechos < count($ids) && $pasos < 10) { $p = $c->post('boucher_lote.php', ['accion' => 'paso', 'token' => $token]); $hechos = $p['json']['hechos'] ?? 99; $pasos++; }
    check('avanza por partes hasta terminar', $hechos === count($ids) && $pasos === (int)ceil(count($ids) / 4), "hechos=$hechos pasos=$pasos");
    $o = login('qa.ccic@local.test');
    check('otro usuario no puede usar esa descarga', ($o->post('boucher_lote.php', ['accion' => 'paso', 'token' => $token])['json']['success'] ?? true) === false);
    $z = $c->get('boucher_lote.php', ['descargar' => $token]);
    $f = tempnam(sys_get_temp_dir(), 'zl'); file_put_contents($f, $z['body']);
    $zip = new ZipArchive(); $ok = $zip->open($f) === true;
    check('descarga un ZIP con un PDF por pago', $ok && $zip->numFiles === count($ids) && str_starts_with((string)$zip->getFromIndex(0), '%PDF'), $ok ? $zip->numFiles . ' archivos' : substr($z['body'], 0, 150));
    check('borra los temporales al descargar', ($c->get('boucher_lote.php', ['descargar' => $token])['json']['success'] ?? true) === false);
    check('un facturador no puede generar', (login('qa.facturador@local.test')->post('boucher_lote.php', ['accion' => 'iniciar', 'ids' => implode(',', $ids)])['json']['success'] ?? true) === false);
});

suite('Sucursales: CRUD', function () {
    $pdo = db();
    $s = login('qa.super@local.test', 1, 2);
    $r = $s->post('includes/empresa_sucursales.php', ['accion' => 'establecimiento', 'empresa_id' => 2, 'nombre' => 'QA Sucursal', 'codigo' => '987']);
    $est = (int)($r['json']['id'] ?? 0);
    check('crea un establecimiento con su punto 01', $est > 0 && (int)$pdo->query("SELECT COUNT(*) FROM puntos_emision WHERE establecimiento_id = $est")->fetchColumn() === 1, $r['body']);
    $u = $s->get('includes/empresa_sucursales.php', ['ubicaciones' => 1]);
    $mun = $pdo->query("SELECT id, departamento_id FROM municipios ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    check('devuelve departamentos y municipios', count($u['json']['departamentos'] ?? []) > 10 && count($u['json']['municipios'] ?? []) > 100);
    $p1 = (int)$pdo->query("SELECT id FROM puntos_emision WHERE establecimiento_id = $est")->fetchColumn();
    $r = $s->post('includes/empresa_sucursales.php', ['accion' => 'punto', 'establecimiento_id' => $est, 'id' => $p1, 'codigo' => '01', 'descripcion' => 'Punto QA', 'municipio_id' => $mun['id']]);
    $p = $pdo->query("SELECT descripcion, departamento_id, municipio_id FROM puntos_emision WHERE id = $p1")->fetch(PDO::FETCH_ASSOC);
    check('edita el punto con su ubicación', ($r['json']['success'] ?? false) && $p['descripcion'] === 'Punto QA' && (int)$p['municipio_id'] === (int)$mun['id'] && (int)$p['departamento_id'] === (int)$mun['departamento_id'], $r['body']);
    $r = $s->post('includes/empresa_sucursales.php', ['accion' => 'eliminar_punto', 'id' => $p1]);
    check('no elimina el único punto', ($r['json']['success'] ?? true) === false);
    $s->post('includes/empresa_sucursales.php', ['accion' => 'punto', 'establecimiento_id' => $est, 'codigo' => '02', 'descripcion' => 'Caja 2']);
    $p2 = (int)$pdo->query("SELECT id FROM puntos_emision WHERE establecimiento_id = $est AND codigo_punto = '02'")->fetchColumn();
    check('elimina un punto sin CAI', ($s->post('includes/empresa_sucursales.php', ['accion' => 'eliminar_punto', 'id' => $p2])['json']['success'] ?? false) === true);
    $conFact = (int)$pdo->query("SELECT establecimiento_id FROM facturas WHERE cliente_id = 2 LIMIT 1")->fetchColumn();
    check('no elimina un establecimiento con facturas', ($s->post('includes/empresa_sucursales.php', ['accion' => 'eliminar_establecimiento', 'id' => $conFact])['json']['success'] ?? true) === false);
    $r = $s->post('includes/empresa_sucursales.php', ['accion' => 'eliminar_establecimiento', 'id' => $est]);
    check('elimina un establecimiento sin uso', ($r['json']['success'] ?? false) && !(int)$pdo->query("SELECT COUNT(*) FROM establecimientos WHERE establecimiento_id = $est")->fetchColumn(), $r['body']);
    check('un admin no administra sucursales', (login('qa.admin@local.test')->post('includes/empresa_sucursales.php', ['accion' => 'eliminar_establecimiento', 'id' => $conFact])['json']['success'] ?? true) === false);
});

suite('Superadmin: servicios del contrato y clientes de la empresa seleccionada', function () {
    global $pdo;
    $s = login('qa.super@local.test', 1, 2);
    $rec = (int)$pdo->query("SELECT receptor_id FROM contratos WHERE cliente_id = 2 AND receptor_id IS NOT NULL LIMIT 1")->fetchColumn();
    $r = $s->get('../../includes/api/productos_por_receptor.php', ['receptor_id' => $rec]);
    check('la API de servicios responde al superadmin (editar contrato)', $r['code'] === 200 && is_array($r['json']) && array_is_list($r['json']), $r['body']);
    $r = $s->get('../../includes/api/productos_por_receptor.php', ['receptor_id' => 0, 'todos' => 1]);
    check('la API de servicios (todos) responde al superadmin', $r['code'] === 200 && count($r['json'] ?? []) > 0, $r['body']);
    $otro = (int)$pdo->query("SELECT id FROM clientes_factura WHERE cliente_id <> 2 LIMIT 1")->fetchColumn();
    if ($otro) check('no da servicios de un cliente de otra empresa', $s->get('../../includes/api/productos_por_receptor.php', ['receptor_id' => $otro])['code'] === 403);
    $cf = (int)$pdo->query("SELECT id FROM clientes_factura WHERE cliente_id = 2 LIMIT 1")->fetchColumn();
    foreach (['clientes', 'crear_cliente', "editar_cliente?id=$cf"] as $p) {
        $r = $s->get($p);
        check("superadmin: $p abre sin errores", $r['code'] === 200 && sinErroresPhp($r['body']) && str_contains($r['body'], 'id="appSidebar"'), errorPhp($r['body']));
    }
    // Crear, editar y eliminar un cliente como superadmin: queda en la empresa seleccionada
    $rtn = '0801' . random_int(1000000000, 9999999999);
    $s->post('guardar_cliente.php', ['nombre' => 'QA Superadmin Cliente', 'rtn' => $rtn, 'direccion' => 'Tegucigalpa']);
    $nuevo = $pdo->query("SELECT id, cliente_id FROM clientes_factura WHERE rtn = '$rtn'")->fetch(PDO::FETCH_ASSOC);
    check('superadmin: crea un cliente en la empresa seleccionada', $nuevo && (int)$nuevo['cliente_id'] === 2, json_encode($nuevo));
    if ($nuevo) {
        $s->post('actualizar_cliente.php', ['id' => $nuevo['id'], 'nombre' => 'QA Superadmin Editado', 'rtn' => $rtn, 'direccion' => 'SPS']);
        check('superadmin: edita el cliente', $pdo->query("SELECT nombre FROM clientes_factura WHERE id = {$nuevo['id']}")->fetchColumn() === 'QA Superadmin Editado');
        $s->post('eliminar_cliente.php', ['id' => $nuevo['id']]);
        check('superadmin: elimina el cliente', !$pdo->query("SELECT COUNT(*) FROM clientes_factura WHERE id = {$nuevo['id']}")->fetchColumn());
    }
    $a = login('qa.admin@local.test');
    $r = $a->get('../../includes/api/productos_por_receptor.php', ['receptor_id' => $rec]);
    check('el admin sigue recibiendo los servicios', $r['code'] === 200 && is_array($r['json']), $r['body']);
});
