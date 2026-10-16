<?php
suite('Subsanacion historial', function () {
    $c = login('qa.admin@local.test');
    $r = $c->get('lista_facturas.php', ['subsanacion'=>'1']);
    check('historial filtrado abre', $r['code'] === 200 && sinErroresPhp($r['body']));
    check('filtro seleccionado', str_contains($r['body'], 'value="1" selected>Requieren subsanación'));
    check('no selecciona CAI activo por defecto', !preg_match('/<option[^>]*data-activo="1"[^>]*selected/', $r['body']));
    file_put_contents('/tmp/subsanacion-historial.html', $r['body']);
    foreach (['emitida', 'anulada', 'borrador'] as $estado) {
        $r = $c->get('lista_facturas.php', ['estado'=>$estado, 'subsanacion'=>'2']);
        check('filtro '.$estado.' y subsanadas', $r['code'] === 200 && sinErroresPhp($r['body']) && str_contains($r['body'], 'value="'.$estado.'" selected') && str_contains($r['body'], 'value="2" selected>Subsanadas'));
    }
    $pdo = db();
    $fila = $pdo->query('SELECT id, notas_internas, requiere_subsanacion FROM facturas WHERE cliente_id=2 AND establecimiento_id=1 ORDER BY fecha_emision DESC, id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    try {
        $pdo->prepare('UPDATE facturas SET requiere_subsanacion=1, notas_internas=? WHERE id=?')->execute(['Nota de prueba interna. <b>Texto literal</b>', $fila['id']]);
        $r = $c->get('lista_facturas.php');
        check('nota fuera del contenido que reemplaza el buscador', str_contains($r['body'], '<td><span data-col="receptor">') && str_contains($r['body'], 'class="fh-nota-btn"'));
        file_put_contents('/tmp/subsanacion-responsive.html', $r['body']);
    } finally {
        $pdo->prepare('UPDATE facturas SET requiere_subsanacion=?, notas_internas=? WHERE id=?')->execute([$fila['requiere_subsanacion'], $fila['notas_internas'], $fila['id']]);
    }
});
