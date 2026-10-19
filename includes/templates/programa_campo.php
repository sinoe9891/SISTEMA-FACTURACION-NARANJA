<?php
// Campo «Programa» (directo o un programa del cliente) para contratos y facturas. Se llena según el #receptor_id elegido
// y se oculta si el cliente no tiene programas. Recibe $pgCid, $pgActual (id o null), $pgLabel y $pgSelect (clases CSS) y, opcional, $pgReceptor (id del select del cliente).
require_once __DIR__ . '/../programas.php';
if (programasDisponible($pdo)):
    $__pg = programasPorCliente($pdo, (int)$pgCid, false);
?>
<div class="col-12" id="programaCampo" hidden>
    <label class="<?= $pgLabel ?>"><i class="bi bi-diagram-3 me-1"></i>Programa</label>
    <select name="programa_id" id="programa_id" class="<?= $pgSelect ?>" data-actual="<?= (int)($pgActual ?? 0) ?>"></select>
    <div class="form-text">Sale en la factura como «Programa: …» y en los correos de cobro (saludo al equipo del programa).</div>
</div>
<script>
(() => {
    const PG = <?= json_encode($__pg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    const rec = document.getElementById(<?= json_encode($pgReceptor ?? 'receptor_id') ?>), sel = document.getElementById('programa_id'), box = document.getElementById('programaCampo');
    const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const llenar = () => {
        const lista = (PG[rec.value] || []).filter(p => p.activo || String(p.id) === sel.dataset.actual);
        const antes = sel.value || sel.dataset.actual;
        sel.innerHTML = '<option value="">Directo al cliente</option>' + lista.map(p => `<option value="${p.id}">${esc(p.nombre)}</option>`).join('');
        sel.value = lista.some(p => String(p.id) === antes) ? antes : '';
        box.hidden = !lista.length;
    };
    rec.addEventListener('change', llenar);
    window.ProgramaElegir = id => { sel.dataset.actual = id ? String(id) : ''; sel.value = ''; llenar(); };   // p. ej. al elegir un contrato
    llenar();
})();
</script>
<?php endif; ?>
