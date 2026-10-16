<?php
suite('Subsanacion historial', function () {
    $c = login('qa.admin@local.test');
    $r = $c->get('lista_facturas.php', ['subsanacion'=>'1']);
    check('historial filtrado abre', $r['code'] === 200 && sinErroresPhp($r['body']));
    check('filtro seleccionado', str_contains($r['body'], 'value="1" selected>Requieren subsanación'));
    check('no selecciona CAI activo por defecto', !preg_match('/<option[^>]*data-activo="1"[^>]*selected/', $r['body']));
    file_put_contents('/tmp/subsanacion-historial.html', $r['body']);
});
