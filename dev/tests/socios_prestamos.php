<?php
// Préstamos a socios: cuotas quincenales (15 y fin de mes) o mensuales, pagos que entran al banco (registrar, editar,
// anular), editar el préstamo, pasar el saldo a retiro, anular, Balance, avisos por correo y PDF de control.
suite('Préstamos a socios: cuotas, pagos, banco, avisos y PDF', function () {
    $pdo = db();
    require_once __DIR__ . '/../../includes/socios_prestamos.php';
    check('quincenal: días 15 y fin de mes', spFechas('2026-11-15', 4, 'quincenal') === ['2026-11-15', '2026-11-30', '2026-12-15', '2026-12-31']);
    check('quincenal empezando a fin de mes', spFechas('2027-01-20', 3, 'quincenal') === ['2027-01-31', '2027-02-15', '2027-02-28']);
    check('mensual: el mismo día (o el último del mes)', spFechas('2026-01-31', 3, 'mensual') === ['2026-01-31', '2026-02-28', '2026-03-31']);
    $cu = spCalcularCuotas(22500, 12, 'quincenal', '2026-11-15');
    check('L 22,500 en 12 cuotas de L 1,875 hasta abril', count($cu) === 12 && $cu[0]['monto'] == 1875 && $cu[11]['fecha'] === '2027-04-30' && array_sum(array_column($cu, 'monto')) == 22500);
    check('los centavos se ajustan en la última cuota', array_sum(array_column(spCalcularCuotas(1000, 3, 'mensual', '2026-01-10'), 'monto')) == 1000);

    $c = login('qa.admin@local.test');
    $soc = (int)$pdo->query("SELECT id FROM socios WHERE cliente_id = 2 AND activo = 1 ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->exec("INSERT INTO cuentas_bancarias (cliente_id, banco, tipo, numero, titular, moneda, saldo_inicial, fecha_saldo_inicial, activa) VALUES (2, 'QA Banco', 'ahorro', 'QA-SP-1', 'QA', 'HNL', 100000, '2020-01-01', 1)");
    $cta = (int)$pdo->lastInsertId();
    $hoy = date('Y-m-d');
    $primera = date('Y-m-d', strtotime('+2 days'));
    $r = $c->post('includes/socio_prestamo_accion.php', ['accion' => 'crear', 'socio_id' => $soc, 'descripcion' => 'QA préstamo socio', 'monto' => 22500, 'fecha' => $hoy, 'num_cuotas' => 12,
        'frecuencia' => 'quincenal', 'primera_cuota' => $primera, 'cuenta_id' => $cta, 'referencia' => 'QA-REF', 'avisar' => 1, 'aviso_dias' => 3, 'aviso_correo' => 'socio@qa.test', 'aviso_cc' => 'conta@qa.test',
        'comprobantes[0]' => new CURLFile(archivoTemporal('png'), 'image/png', 'transferencia.png'), 'comprobantes[1]' => new CURLFile(archivoTemporal('pdf'), 'application/pdf', 'constancia.pdf')]);
    $id = (int)($r['json']['id'] ?? 0);
    $p = $pdo->query("SELECT * FROM socios_prestamos WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
    $mov = $p ? $pdo->query("SELECT * FROM movimientos_bancarios WHERE id = " . (int)$p['movimiento_id'])->fetch(PDO::FETCH_ASSOC) : null;
    check('se crea con 12 cuotas y sale del banco (con su comprobante)', $id && (int)$pdo->query("SELECT COUNT(*) FROM socios_prestamo_cuotas WHERE prestamo_id = $id")->fetchColumn() === 12
        && $mov && $mov['tipo'] === 'prestamo_socio' && $mov['sentido'] === 'salida' && (float)$mov['monto'] === 22500.0 && $mov['archivo_adjunto'], $r['body']);
    check('guarda los 2 comprobantes del préstamo', (int)$pdo->query("SELECT COUNT(*) FROM socios_prestamo_archivos WHERE prestamo_id = $id AND pago_id IS NULL")->fetchColumn() === 2);
    $r = $c->post('includes/socio_prestamo_accion.php', ['accion' => 'archivos', 'id' => $id, 'comprobantes[0]' => new CURLFile(archivoTemporal('png'), 'image/png', 'otro.png')]);
    $arch = (int)$pdo->query("SELECT id FROM socios_prestamo_archivos WHERE prestamo_id = $id ORDER BY id LIMIT 1")->fetchColumn();
    $ver = $c->get('socio_prestamo_archivo', ['archivo' => $arch]);
    $c->post('includes/socio_prestamo_accion.php', ['accion' => 'archivo_quitar', 'archivo_id' => $arch]);
    $mvArch = (string)$pdo->query("SELECT archivo_adjunto FROM movimientos_bancarios WHERE id = " . (int)$p['movimiento_id'])->fetchColumn();
    check('agrega más comprobantes, se ven y al quitar el principal el banco toma el siguiente', ($r['json']['success'] ?? false) && str_starts_with($ver['body'], "\x89PNG")
        && (int)$pdo->query("SELECT COUNT(*) FROM socios_prestamo_archivos WHERE prestamo_id = $id")->fetchColumn() === 2 && $mvArch !== '' && $mvArch === (string)$pdo->query("SELECT archivo_adjunto FROM socios_prestamos WHERE id = $id")->fetchColumn(), $r['body']);
    $s = $c->get('socios');
    check('Socios: pestaña Préstamos, «Nuevo préstamo» y el saldo por cobrar', sinErroresPhp($s['body']) && str_contains($s['body'], 'id="tPrestamos"') && str_contains($s['body'], 'QA préstamo socio') && str_contains($s['body'], 'Préstamos por cobrar'), errorPhp($s['body']));
    $v = $c->get('socio_prestamo', ['id' => $id]);
    check('el detalle muestra cuotas, pagos y avisos', sinErroresPhp($v['body']) && substr_count($v['body'], 'class="text-center">') >= 12 && str_contains($v['body'], 'id="fAvisos"'), errorPhp($v['body']));
    $pdf = $c->get('socio_prestamo_pdf', ['id' => $id]);
    check('PDF de control', $pdf['code'] === 200 && str_starts_with($pdf['body'], '%PDF'));

    $r = $c->post('includes/socio_prestamo_accion.php', ['accion' => 'pagar', 'id' => $id, 'fecha' => $hoy, 'monto' => 1875, 'metodo' => 'transferencia', 'cuenta_id' => $cta]);
    $d = spDetalle($pdo, 2, $id);
    $pago = (int)$pdo->query("SELECT id FROM socios_prestamo_pagos WHERE prestamo_id = $id ORDER BY id DESC LIMIT 1")->fetchColumn();
    $dep = $pdo->query("SELECT m.sentido, m.monto FROM movimientos_bancarios m JOIN socios_prestamo_pagos g ON g.movimiento_id = m.id WHERE g.id = $pago")->fetch(PDO::FETCH_ASSOC);
    check('un pago entra al banco y deja la cuota 1 pagada (saldo L 20,625)', ($r['json']['success'] ?? false) && $d['cuotas'][0]['estado'] === 'pagada' && $d['saldo'] == 20625 && $dep && $dep['sentido'] === 'entrada' && (float)$dep['monto'] === 1875.0, $r['body']);
    $r = $c->post('includes/socio_prestamo_accion.php', ['accion' => 'pagar', 'id' => $id, 'fecha' => $hoy, 'monto' => 99999]);
    check('no acepta un pago mayor que el saldo', !($r['json']['success'] ?? true));
    $r = $c->post('includes/socio_prestamo_accion.php', ['accion' => 'editar_pago', 'pago_id' => $pago, 'fecha' => $hoy, 'monto' => 2000, 'metodo' => 'deposito', 'cuenta_id' => $cta, 'referencia' => 'DEP-1']);
    $d = spDetalle($pdo, 2, $id);
    check('editar el pago: L 2,000 cubre la cuota 1 y abona L 125 a la 2; el depósito cambia', ($r['json']['success'] ?? false) && $d['cuotas'][1]['estado'] === 'parcial' && $d['cuotas'][1]['pagado'] == 125
        && (float)$pdo->query("SELECT m.monto FROM movimientos_bancarios m JOIN socios_prestamo_pagos g ON g.movimiento_id = m.id WHERE g.id = $pago")->fetchColumn() === 2000.0, $r['body']);
    $r = $c->post('includes/socio_prestamo_accion.php', ['accion' => 'editar', 'id' => $id, 'descripcion' => 'QA préstamo socio', 'monto' => 22500, 'fecha' => $hoy, 'num_cuotas' => 6, 'frecuencia' => 'quincenal', 'primera_cuota' => $primera, 'cuenta_id' => $cta, 'referencia' => 'QA-REF']);
    $d = spDetalle($pdo, 2, $id);
    check('editar el préstamo a 6 cuotas: L 3,750 cada una y lo pagado se vuelve a aplicar', ($r['json']['success'] ?? false) && count($d['cuotas']) === 6 && $d['cuotas'][0]['monto'] == 3750 && $d['cuotas'][0]['pagado'] == 2000, $r['body']);
    check('el Balance lo cuenta como préstamo a socios por cobrar', spSaldoAlCorte($pdo, 2, $hoy) >= 20500);
    $r = $c->post('includes/socio_prestamo_accion.php', ['accion' => 'enviar', 'id' => $id]);
    $env = $pdo->query("SELECT destinatario AS para, asunto, estado FROM correos_enviados WHERE tipo LIKE 'prestamo_socio_%' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $eml = glob(__DIR__ . '/../qa/correos/*.eml'); sort($eml);
    // Partes del correo en base64: se decodifican para buscar el texto
    $txt = '';
    if ($eml) foreach (preg_split('/\r?\n\r?\n/', (string)file_get_contents(end($eml))) as $parte) { $b = base64_decode(preg_replace('/\s+/', '', $parte), true); $txt .= ($b !== false ? $b : $parte) . "\n"; }
    $txt .= (string)file_get_contents(end($eml));
    check('el correo trae la transferencia (de qué cuenta salió), los pagos y las cuentas de la empresa para pagar', str_contains($txt, 'Salió de QA Banco QA-SP-1') && str_contains($txt, 'Cuentas de la empresa para hacer el pago') && str_contains($txt, 'de aquí salió el préstamo') && str_contains($txt, 'conta@qa.test'));
    check('envía el recordatorio al socio (con la cuota que viene)', ($r['json']['success'] ?? false) && $env && $env['estado'] === 'enviado' && str_contains($env['para'], 'socio@qa.test') && str_contains($env['asunto'], 'cuota'), $r['body']);
    $r = $c->post('includes/socio_prestamo_accion.php', ['accion' => 'anular_pago', 'pago_id' => $pago, 'motivo' => 'QA']);
    $d = spDetalle($pdo, 2, $id);
    check('anular el pago: vuelve el saldo y se anula su depósito', ($r['json']['success'] ?? false) && $d['saldo'] == 22500 && (int)$pdo->query("SELECT m.anulado FROM movimientos_bancarios m JOIN socios_prestamo_pagos g ON g.movimiento_id = m.id WHERE g.id = $pago")->fetchColumn() === 1, $r['body']);
    $r = $c->post('includes/socio_prestamo_accion.php', ['accion' => 'condonar', 'id' => $id, 'motivo' => 'QA no lo devuelve', 'fecha' => $hoy]);
    $gid = (int)($r['json']['gasto_id'] ?? 0);
    $g = $pdo->query("SELECT naturaleza, socio_id, monto FROM gastos WHERE id = $gid")->fetch(PDO::FETCH_ASSOC);
    check('pasar el saldo a retiro: un retiro con su socio y el préstamo se cierra', $g && $g['naturaleza'] === 'retiro' && (int)$g['socio_id'] === $soc && (float)$g['monto'] === 22500.0
        && $pdo->query("SELECT estado FROM socios_prestamos WHERE id = $id")->fetchColumn() === 'condonado', $r['body']);
    // Préstamo sin pagos: se anula con su salida del banco
    $r = $c->post('includes/socio_prestamo_accion.php', ['accion' => 'crear', 'socio_id' => $soc, 'descripcion' => 'QA anular', 'monto' => 500, 'fecha' => $hoy, 'num_cuotas' => 1, 'frecuencia' => 'mensual', 'primera_cuota' => $primera, 'cuenta_id' => $cta]);
    $id2 = (int)($r['json']['id'] ?? 0);
    $c->post('includes/socio_prestamo_accion.php', ['accion' => 'anular', 'id' => $id2, 'motivo' => 'QA']);
    $mv2 = (int)$pdo->query("SELECT movimiento_id FROM socios_prestamos WHERE id = $id2")->fetchColumn();
    check('anular un préstamo sin pagos anula su salida del banco', $pdo->query("SELECT estado FROM socios_prestamos WHERE id = $id2")->fetchColumn() === 'anulado' && (int)$pdo->query("SELECT anulado FROM movimientos_bancarios WHERE id = $mv2")->fetchColumn() === 1);
    $f = login('qa.facturador@local.test');
    $r = $f->post('includes/socio_prestamo_accion.php', ['accion' => 'anular', 'id' => $id, 'motivo' => 'x']);
    check('otro rol no maneja los préstamos a socios', !($r['json']['success'] ?? true));

    // Limpieza
    foreach ([$id, $id2] as $x) {
        $pdo->exec("DELETE FROM socios_prestamo_pagos WHERE prestamo_id = $x");
        $pdo->exec("DELETE FROM socios_prestamo_cuotas WHERE prestamo_id = $x");
        $pdo->exec("DELETE FROM socios_prestamos WHERE id = $x");
    }
    $pdo->exec("DELETE FROM socios_prestamo_archivos WHERE prestamo_id IN ($id, $id2)");
    $pdo->exec("DELETE FROM gastos WHERE id = $gid");
    $pdo->exec("DELETE FROM movimientos_bancarios WHERE cuenta_id = $cta");
    $pdo->exec("DELETE FROM cuentas_bancarias WHERE id = $cta");
});
