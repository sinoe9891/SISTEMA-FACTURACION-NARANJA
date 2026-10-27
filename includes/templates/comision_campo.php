<?php
// Sección «Comisión por venta» de Generar / Editar factura (opcional): vendedor (colaborador o externo, con alta rápida),
// porcentaje del subtotal sin ISV o cantidad fija. Recibe $cmCid, $cmActual (comisión de la factura o null),
// $cmCard (clase de la tarjeta: fv-card | fe-card) y $cmLabel (clase de las etiquetas).
require_once __DIR__ . '/../comisiones.php';
if (comisionesDisponible($pdo)):
    $__vend = vendedoresLista($pdo, (int)$cmCid);
    $__c = $cmActual ?? null;
    $__pagada = $__c && $__c['estado'] === 'pagada';
    $__e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
?>
<div class="<?= $cmCard ?>" id="cmCard">
    <div class="<?= $cmCard ?>-header"><i class="bi bi-person-badge text-success me-1"></i>Comisión por venta <span class="text-muted fw-normal small">(opcional)</span></div>
    <div class="<?= $cmCard ?>-body">
        <?php if ($__pagada): ?>
            <input type="hidden" name="comision_activa" value="1">
            <div class="small"><i class="bi bi-check-circle text-success me-1"></i>Comisión de <strong><?= $__e($__c['vendedor']) ?></strong> por <strong>L <?= number_format((float)$__c['monto'], 2) ?></strong> ya pagada el <?= date('d/m/Y', strtotime((string)$__c['pagada_en'])) ?>. Para cambiarla, anula el pago en <a href="comisiones">Comisiones</a>.</div>
        <?php else: ?>
        <input type="hidden" name="comision_activa" value="0">
        <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" name="comision_activa" value="1" id="cmActiva" <?= $__c ? 'checked' : '' ?>>
            <label class="form-check-label fw-semibold" for="cmActiva">Esta venta lleva comisión</label></div>
        <div class="row g-2" id="cmCampos" <?= $__c ? '' : 'hidden' ?>>
            <div class="col-md-6"><label class="<?= $cmLabel ?>">Vendedor</label>
                <select name="comision_vendedor_id" id="cmVendedor" class="form-select" data-buscar>
                    <option value="">— Buscar o elegir —</option>
                    <?php foreach (['colaborador' => 'Colaboradores', 'externo' => 'Externos'] as $tp => $tt): $lista = array_filter($__vend, fn($v) => $v['tipo'] === $tp); if (!$lista) continue; ?>
                        <optgroup label="<?= $tt ?>"><?php foreach ($lista as $v): ?><option value="<?= (int)$v['id'] ?>" <?= (int)($__c['vendedor_id'] ?? 0) === (int)$v['id'] ? 'selected' : '' ?>><?= $__e($v['nombre']) ?><?= $v['identidad'] ? ' · ID ' . $__e($v['identidad']) : '' ?></option><?php endforeach; ?></optgroup>
                    <?php endforeach; ?>
                    <option value="__nuevo__">➕ Agregar vendedor nuevo…</option>
                </select></div>
            <div class="col-6 col-md-3"><label class="<?= $cmLabel ?>">Comisión</label>
                <select name="comision_tipo" id="cmTipo" class="form-select"><option value="porcentaje" <?= ($__c['tipo'] ?? 'porcentaje') === 'porcentaje' ? 'selected' : '' ?>>% del subtotal</option><option value="fijo" <?= ($__c['tipo'] ?? '') === 'fijo' ? 'selected' : '' ?>>Cantidad fija (L)</option></select></div>
            <div class="col-6 col-md-3"><label class="<?= $cmLabel ?>" id="cmValorLbl"><?= ($__c['tipo'] ?? 'porcentaje') === 'fijo' ? 'Monto (L)' : 'Porcentaje' ?></label>
                <input type="number" name="comision_valor" id="cmValor" class="form-control" step="0.01" min="0" value="<?= $__c ? rtrim(rtrim(number_format((float)$__c['valor'], 4, '.', ''), '0'), '.') : '' ?>"></div>
            <div class="col-md-8"><input name="comision_notas" class="form-control form-control-sm" maxlength="255" placeholder="Nota (opcional)" value="<?= $__e($__c['notas'] ?? '') ?>"></div>
            <div class="col-md-4 text-md-end small pt-1">Comisión: <strong id="cmMonto">L 0.00</strong></div>
            <div class="col-12 form-text">Sale en <a href="comisiones" target="_blank">Comisiones</a>: «Esperando cobro» hasta que el cliente pague la factura y luego «Por pagar».</div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php if (!$__pagada): ?>
<script>
(() => {
    const act = document.getElementById('cmActiva'), campos = document.getElementById('cmCampos'), sel = document.getElementById('cmVendedor');
    const tipo = document.getElementById('cmTipo'), valor = document.getElementById('cmValor'), monto = document.getElementById('cmMonto'), lbl = document.getElementById('cmValorLbl');
    const sub = () => parseFloat(document.getElementById('h_subtotal')?.value) || 0;
    const L = n => 'L ' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const pintar = () => {
        campos.hidden = !act.checked;
        lbl.textContent = tipo.value === 'fijo' ? 'Monto (L)' : 'Porcentaje';
        const v = parseFloat(valor.value) || 0;
        monto.textContent = L(tipo.value === 'fijo' ? v : Math.round(sub() * v) / 100) + (tipo.value === 'fijo' ? '' : ' (' + v + ' % de ' + L(sub()) + ')');
    };
    [act, tipo, valor].forEach(x => x.addEventListener('input', pintar)); [act, tipo].forEach(x => x.addEventListener('change', pintar));
    // El subtotal cambia al editar las líneas o la moneda: se vuelve a calcular
    document.addEventListener('input', () => setTimeout(pintar, 60));
    document.addEventListener('change', () => setTimeout(pintar, 60));
    // Alta rápida de un vendedor externo
    sel.addEventListener('change', async () => {
        if (sel.value !== '__nuevo__') return;
        const r = await Swal.fire({ title: 'Nuevo vendedor', showCancelButton: true, confirmButtonText: 'Agregar', cancelButtonText: 'Cancelar', focusConfirm: false,
            html: '<input id="vnNom" class="swal2-input" placeholder="Nombre completo" maxlength="150"><input id="vnId" class="swal2-input" placeholder="N.º de identidad (ID)" maxlength="30">'
                + '<input id="vnCta" class="swal2-input" placeholder="Cuenta para pagarle (ej.: BAC 729176511)" maxlength="150"><input id="vnTel" class="swal2-input" placeholder="Teléfono (opcional)" maxlength="40"><input id="vnMail" class="swal2-input" placeholder="Correo (opcional)" maxlength="150">',
            preConfirm: async () => {
                const fd = new FormData(); fd.append('accion', 'vendedor_guardar');
                fd.append('nombre', document.getElementById('vnNom').value); fd.append('identidad', document.getElementById('vnId').value);
                fd.append('telefono', document.getElementById('vnTel').value); fd.append('cuenta_banco', document.getElementById('vnCta').value); fd.append('email', document.getElementById('vnMail').value);
                const d = await fetch('includes/comision_accion.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(x => x.json()).catch(() => ({ success: false, error: 'Error de conexión.' }));
                if (!d.success) { Swal.showValidationMessage(d.error); return false; }
                return d.vendedor;
            } });
        if (!r.isConfirmed || !r.value) { sel.value = ''; return; }
        let grupo = [...sel.querySelectorAll('optgroup')].find(g => g.label === 'Externos');
        if (!grupo) { grupo = document.createElement('optgroup'); grupo.label = 'Externos'; sel.insertBefore(grupo, sel.lastElementChild); }
        const o = new Option(r.value.nombre + (r.value.identidad ? ' · ID ' + r.value.identidad : ''), r.value.id);
        grupo.appendChild(o);
        sel.value = String(r.value.id);
    });
    pintar();
})();
</script>
<?php endif; endif; ?>
