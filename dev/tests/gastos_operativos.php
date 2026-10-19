<?php
// Gastos operativos: rol «operativo» con clientes asignados, registro en L o USD con pauta y comprobantes,
// revisión del administrador (aprobar → Gastos, rechazar, regresar), reportes y enlace de acceso.
suite('Gastos operativos: rol con clientes asignados, comprobantes, aprobación y reportes', function () {
    $pdo = db();
    // Usuario de prueba con el rol nuevo (misma contraseña de QA)
    if (!$pdo->query("SELECT COUNT(*) FROM usuarios WHERE correo = 'qa.operativo@local.test'")->fetchColumn()) {
        $pdo->exec("INSERT INTO usuarios (cliente_id, nombre, correo, clave, rol, estado) SELECT 2, 'QA Operativo', 'qa.operativo@local.test', clave, 'operativo', 'activo' FROM usuarios WHERE correo = 'qa.nomina@local.test'");
        $oid = (int)$pdo->lastInsertId();
        $pdo->exec("INSERT INTO usuario_establecimientos (usuario_id, establecimiento_id) VALUES ($oid, 1)");
    }
    $oid = (int)$pdo->query("SELECT id FROM usuarios WHERE correo = 'qa.operativo@local.test'")->fetchColumn();
    $pdo->exec("DELETE FROM usuarios_clientes WHERE usuario_id = $oid");
    $adm = login('qa.admin@local.test');
    $op = login('qa.operativo@local.test');

    $p = $op->get('gastos_operativos');
    check('sin clientes asignados, el operativo ve el aviso', sinErroresPhp($p['body']) && str_contains($p['body'], 'Todavía no tienes clientes asignados'), errorPhp($p['body']));
    $d = $op->get('dashboard');
    check('el operativo no tiene Inicio: va a Gastos operativos', str_contains((string)$d['loc'], 'gastos_operativos') || str_contains($d['body'], 'Gastos operativos'));
    $x = $op->get('lista_facturas');
    check('no abre otras secciones (facturas)', !str_contains($x['body'], 'id="tablaFacturas"') && $x['code'] !== 200 || str_contains((string)$x['loc'], 'gastos_operativos'), (string)$x['loc']);
    $x = $op->post('includes/gasto_guardar.php', ['descripcion' => 'x']);
    check('ni sus acciones (gastos de la empresa)', $x['code'] === 403);

    $p = $adm->get('gastos_operativos');
    check('el administrador ve la página con Tipos y Responsables (y el operativo en la lista)', sinErroresPhp($p['body']) && str_contains($p['body'], 'id="mTipos"') && str_contains($p['body'], 'qa.operativo@local.test'), errorPhp($p['body']));
    $r = $op->post('includes/gasto_operativo_accion.php', ['accion' => 'asignar', 'usuario_id' => $oid, 'cuentas[0]' => 'c:1']);
    check('el operativo no puede asignarse clientes', !($r['json']['success'] ?? true));
    $r = $adm->post('includes/gasto_operativo_accion.php', ['accion' => 'asignar', 'usuario_id' => $oid, 'cuentas[0]' => 'c:1']);
    check('el administrador le asigna un cliente', ($r['json']['success'] ?? false) && (int)$pdo->query("SELECT COUNT(*) FROM usuarios_clientes WHERE usuario_id = $oid AND receptor_id = 1")->fetchColumn() === 1, $r['body']);
    $otro = (int)$pdo->query("SELECT id FROM clientes_factura WHERE cliente_id = 2 AND id <> 1 ORDER BY id LIMIT 1")->fetchColumn();

    $meta = (int)$pdo->query("SELECT id FROM gastos_op_tipos WHERE cliente_id = 2 AND nombre LIKE 'Meta Ads%'")->fetchColumn();
    $comb = (int)$pdo->query("SELECT id FROM gastos_op_tipos WHERE cliente_id = 2 AND nombre = 'Combustible'")->fetchColumn();
    check('se crearon los tipos de fábrica (Meta Ads es pauta)', $meta && $comb && (int)$pdo->query("SELECT es_pauta FROM gastos_op_tipos WHERE id = $meta")->fetchColumn() === 1);
    $base = ['accion' => 'guardar', 'receptor_id' => 1, 'tipo_id' => $meta, 'fecha' => date('Y-m-d'), 'descripcion' => 'QA pauta Meta', 'moneda' => 'USD', 'monto' => 50, 'tasa_cambio' => 26,
        'metodo_pago' => 'tarjeta', 'cobrar_cliente' => 1];
    $r = $op->post('includes/gasto_operativo_accion.php', array_merge($base, ['receptor_id' => $otro]));
    check('no registra gastos de un cliente que no tiene asignado', !($r['json']['success'] ?? true));
    $r = $op->post('includes/gasto_operativo_accion.php', $base);
    check('una pauta pide fechas de inicio y fin', !($r['json']['success'] ?? true) && str_contains($r['json']['error'] ?? '', 'pauta'));
    $r = $op->post('includes/gasto_operativo_accion.php', $base + ['pauta_inicio' => date('Y-m-d', strtotime('-10 days')), 'pauta_fin' => date('Y-m-d'), 'campana' => 'QA',
        'archivos[0]' => new CURLFile(archivoTemporal('txt'), 'text/plain', 'malo.php')]);
    check('rechaza archivos que no son imagen, PDF, Excel o CSV', !($r['json']['success'] ?? true));
    $r = $op->post('includes/gasto_operativo_accion.php', $base + ['pauta_inicio' => date('Y-m-d', strtotime('-10 days')), 'pauta_fin' => date('Y-m-d'), 'campana' => 'QA',
        'archivos[0]' => new CURLFile(archivoTemporal('png'), 'image/png', 'recibo.png'), 'archivos[1]' => new CURLFile(archivoTemporal('pdf'), 'application/pdf', 'reporte_meta.pdf')]);
    $gid = (int)($r['json']['id'] ?? 0);
    $g = $pdo->query("SELECT * FROM gastos_operativos WHERE id = $gid")->fetch(PDO::FETCH_ASSOC);
    check('registra la pauta en USD (50 × 26 = L 1,300) con 2 archivos, por revisar', $g && (float)$g['monto_lempiras'] === 1300.0 && $g['estado'] === 'pendiente'
        && (int)$pdo->query("SELECT COUNT(*) FROM gastos_op_archivos WHERE gasto_op_id = $gid")->fetchColumn() === 2, $r['body']);
    $aid = (int)$pdo->query("SELECT id FROM gastos_op_archivos WHERE gasto_op_id = $gid ORDER BY id LIMIT 1")->fetchColumn();
    $f = $op->get('gasto_operativo_archivo', ['id' => $aid]);
    check('el operativo ve su comprobante', $f['code'] === 200 && str_starts_with($f['body'], "\x89PNG"));
    $ccic = login('qa.ccic@local.test', 3);
    $f = $ccic->get('gasto_operativo_archivo', ['id' => $aid]);
    check('otra empresa no lo ve', !str_starts_with($f['body'], "\x89PNG"));
    $l = $op->get('gastos_operativos', ['desde' => date('Y-m-01', strtotime('-1 month')), 'hasta' => date('Y-m-d')]);
    check('la lista muestra el gasto con $ y las fechas de la pauta', sinErroresPhp($l['body']) && str_contains($l['body'], 'QA pauta Meta') && str_contains($l['body'], '$ 50.00'), errorPhp($l['body']));
    $xl = $op->get('gastos_operativos_exportar', ['formato' => 'xlsx', 'desde' => date('Y-m-01', strtotime('-1 month'))]);
    check('reporte en Excel', $xl['code'] === 200 && str_starts_with($xl['body'], 'PK'));
    $pdf = $op->get('gastos_operativos_exportar', ['formato' => 'pdf', 'desde' => date('Y-m-01', strtotime('-1 month'))]);
    check('reporte en PDF', $pdf['code'] === 200 && str_starts_with($pdf['body'], '%PDF'));

    $r = $op->post('includes/gasto_operativo_accion.php', ['accion' => 'aprobar', 'id' => $gid, 'registro' => 'ninguno']);
    check('el operativo no puede aprobar', !($r['json']['success'] ?? true));
    $r = $adm->post('includes/gasto_operativo_accion.php', ['accion' => 'rechazar', 'id' => $gid]);
    check('rechazar pide el motivo', !($r['json']['success'] ?? true));
    $adm->post('includes/gasto_operativo_accion.php', ['accion' => 'rechazar', 'id' => $gid, 'motivo' => 'Falta la factura']);
    check('rechazado con su motivo', $pdo->query("SELECT CONCAT(estado, '|', motivo_rechazo) FROM gastos_operativos WHERE id = $gid")->fetchColumn() === 'rechazado|Falta la factura');
    $r = $op->post('includes/gasto_operativo_accion.php', $base + ['id' => $gid, 'pauta_inicio' => date('Y-m-d', strtotime('-10 days')), 'pauta_fin' => date('Y-m-d'), 'factura_ref' => 'FB-123']);
    check('al corregirlo vuelve a «Por revisar»', ($r['json']['success'] ?? false) && $pdo->query("SELECT estado FROM gastos_operativos WHERE id = $gid")->fetchColumn() === 'pendiente', $r['body']);
    $mb = $adm->get('gastos');
    check('el menú del administrador avisa los gastos por revisar', str_contains($mb['body'], 'Gastos operativos por revisar'));

    $cat = (int)$pdo->query("SELECT id FROM categorias_gastos WHERE cliente_id = 2 ORDER BY id LIMIT 1")->fetchColumn();
    $r = $adm->post('includes/gasto_operativo_accion.php', ['accion' => 'aprobar', 'id' => $gid, 'registro' => 'nuevo', 'categoria_id' => $cat]);
    $gasto = (int)($r['json']['gasto_id'] ?? 0);
    $gs = $pdo->query("SELECT * FROM gastos WHERE id = $gasto")->fetch(PDO::FETCH_ASSOC);
    check('al aprobarlo se registra en Gastos (L 1,300, pagado con tarjeta, para cobrárselo al cliente, con el comprobante)', $gs && (float)$gs['monto'] === 1300.0 && $gs['estado'] === 'pagado'
        && $gs['metodo_pago'] === 'tarjeta' && (int)$gs['cobrar_receptor_id'] === 1 && $gs['archivo_adjunto'], $r['body']);
    $r = $op->post('includes/gasto_operativo_accion.php', $base + ['id' => $gid, 'pauta_inicio' => date('Y-m-d'), 'pauta_fin' => date('Y-m-d')]);
    check('aprobado, el operativo ya no lo edita', !($r['json']['success'] ?? true));
    $r = $op->post('includes/gasto_operativo_accion.php', ['accion' => 'eliminar', 'id' => $gid]);
    check('ni lo elimina', !($r['json']['success'] ?? true));
    $r = $op->post('includes/gasto_operativo_accion.php', ['accion' => 'archivos', 'id' => $gid, 'archivo_tipo' => 'reporte', 'archivos[0]' => new CURLFile(archivoTemporal('pdf'), 'application/pdf', 'cierre.pdf')]);
    check('pero sí le agrega reportes', ($r['json']['success'] ?? false), $r['body']);
    $r = $adm->post('includes/gasto_operativo_accion.php', ['accion' => 'reabrir', 'id' => $gid]);
    check('regresarlo a revisión anula el gasto que se creó', ($r['json']['success'] ?? false) && $pdo->query("SELECT estado FROM gastos WHERE id = $gasto")->fetchColumn() === 'anulado'
        && $pdo->query("SELECT estado FROM gastos_operativos WHERE id = $gid")->fetchColumn() === 'pendiente', $r['body']);
    $r = $adm->post('includes/gasto_operativo_accion.php', ['accion' => 'aprobar', 'id' => $gid, 'registro' => 'existente', 'gasto_id' => 99999999]);
    check('ligarlo a un gasto que no existe no se permite', !($r['json']['success'] ?? true));
    $r = $adm->post('includes/gasto_operativo_accion.php', ['accion' => 'aprobar', 'id' => $gid, 'registro' => 'ninguno']);
    check('se puede aprobar sin tocar Gastos', ($r['json']['success'] ?? false) && $pdo->query("SELECT gasto_id FROM gastos_operativos WHERE id = $gid")->fetchColumn() === null);

    // Lempiras, efectivo, sin pauta: lo elimina quien lo registró
    $r = $op->post('includes/gasto_operativo_accion.php', ['accion' => 'guardar', 'receptor_id' => 1, 'tipo_id' => $comb, 'fecha' => date('Y-m-d'), 'descripcion' => 'QA combustible visita', 'moneda' => 'HNL', 'monto' => 800, 'metodo_pago' => 'propio']);
    $g2 = (int)($r['json']['id'] ?? 0);
    check('combustible en lempiras pagado por el colaborador', $g2 && (float)$pdo->query("SELECT monto_lempiras FROM gastos_operativos WHERE id = $g2")->fetchColumn() === 800.0, $r['body']);
    $r = $op->post('includes/gasto_operativo_accion.php', ['accion' => 'eliminar', 'id' => $g2]);
    check('el operativo elimina el suyo sin aprobar', ($r['json']['success'] ?? false) && !$pdo->query("SELECT COUNT(*) FROM gastos_operativos WHERE id = $g2")->fetchColumn());

    // Enlace de acceso (el usuario pone su propia contraseña)
    $r = $adm->post('includes/usuario_invitacion.php', ['user_id' => $oid]);
    $tok = preg_match('/token=([a-f0-9]{64})/', $r['json']['enlace'] ?? '', $m) ? $m[1] : '';
    require_once __DIR__ . '/../../includes/clave_reset.php';
    check('Usuarios genera el enlace para que ponga su contraseña (vale 72 h)', $tok && claveResetValidar($pdo, $tok) && (int)$pdo->query("SELECT TIMESTAMPDIFF(HOUR, NOW(), expira) FROM clave_resets WHERE token_hash = '" . hash('sha256', $tok) . "'")->fetchColumn() >= 71, $r['body']);
    $r = $op->post('includes/usuario_invitacion.php', ['user_id' => $oid]);
    check('el operativo no genera enlaces', !($r['json']['success'] ?? true));
    $pdo->exec("UPDATE clave_resets SET usado_en = NOW() WHERE usuario_id = $oid AND usado_en IS NULL");

    // Limpieza
    foreach ($pdo->query("SELECT archivo FROM gastos_op_archivos WHERE gasto_op_id = $gid")->fetchAll(PDO::FETCH_COLUMN) as $a) @unlink(gastosOpDirQa() . $a);
    $pdo->exec("DELETE FROM gastos_op_archivos WHERE gasto_op_id = $gid");
    $pdo->exec("DELETE FROM gastos_operativos WHERE id = $gid");
    if ($gasto) { $a = $pdo->query("SELECT archivo_adjunto FROM gastos WHERE id = $gasto")->fetchColumn(); if ($a) @unlink(__DIR__ . '/../../clientes/naranjaymedia/includes/uploads/gastos/' . $a); $pdo->exec("DELETE FROM gastos WHERE id = $gasto"); }
    $pdo->exec("DELETE FROM usuarios_clientes WHERE usuario_id = $oid");
});

function gastosOpDirQa(): string
{
    return __DIR__ . '/../../clientes/naranjaymedia/includes/uploads/gastos_operativos/';
}
