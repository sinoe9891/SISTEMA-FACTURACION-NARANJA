// Sin navegador ni envíos: node dev/tests/cobros_parrafo_documentos.cjs
const fs = require('node:fs');
const assert = require('node:assert/strict');
const src = fs.readFileSync(__dirname + '/../../clientes/naranjaymedia/cobros_programados.php', 'utf8');
const inicio = src.indexOf('    function mensajeConDocumentos');
const fin = src.indexOf('    function actualizarParrafoDocumentos', inicio);
const generar = new Function('esc', src.slice(inicio, fin) + '; return mensajeConDocumentos;')(s => s);
const base = '<p>Texto personalizado &lt;pendiente&gt;.</p><p>Formas de pago: Banco 123</p><p>Saludos cordiales,</p>';
const uno = generar(base, ['SAR'], 'saldo_pendiente');
for (const caso of [uno, uno.replaceAll('ó', '&oacute;'), uno.replaceAll('ó', '&#243;'), uno.replaceAll(' ', '&nbsp;')]) {
    const resultado = generar(caso + caso, ['SAR'], 'saldo_pendiente');
    assert.equal((resultado.match(/Para facilitar/g) || []).length, 1);
    assert.ok(resultado.includes('&lt;pendiente&gt;'));
    assert.ok(!generar(caso + caso, [], 'saldo_pendiente').includes('Para facilitar'));
}
console.log('OK: editor sin duplicados, entidades y retiro de documentos.');
