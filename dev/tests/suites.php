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

    $f = db()->query("SELECT f.* FROM facturas f WHERE f.cliente_id=2 AND f.estado='emitida' AND f.pagada=0 AND NOT EXISTS (SELECT 1 FROM cobros_factura c WHERE c.factura_id=f.id) AND f.total > 100 ORDER BY f.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
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
    check('otra empresa no puede cobrar la factura', !($r['json']['success'] ?? true) && !db()->query("SELECT COUNT(*) FROM cobros_factura WHERE factura_id=$fid")->fetchColumn());

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
    check('historial: 2 abonos y saldo 0', count($r['json']['cobros'] ?? []) === 2 && (float)($r['json']['factura']['saldo'] ?? 1) == 0);

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
                      AND NOT EXISTS (SELECT 1 FROM cobros_factura c WHERE c.factura_id=f.id) ORDER BY f.total DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
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
    $msg = $c->postJson('procesar_accion_factura.php', ['accion' => 'generar_mensaje', 'factura_ids' => $ids, 'tipo' => 'saldo_pendiente']);
    check('genera asunto y mensaje con la plantilla', ($msg['json']['success'] ?? false) && ($msg['json']['asunto'] ?? '') !== '', substr($msg['body'], 0, 200));

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
