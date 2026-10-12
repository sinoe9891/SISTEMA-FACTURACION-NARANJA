<?php
/**
 * proveedor_select.php — <select> de proveedores del catálogo (con buscador y «Agregar proveedor nuevo…»).
 * Envía «proveedor» (el nombre) y «proveedor_id». Si el gasto tiene un nombre que no está en el catálogo, se muestra igual.
 * Variables: $psId (id del select), $psValor (nombre actual), $psClase (clase CSS, opcional).
 * window.proveedorSelectValor(select, nombre) pone un valor desde JS (lo agrega si no está).
 */
require_once __DIR__ . '/../proveedores.php';
$__psOps = proveedoresOpciones($pdo, (int)cliente_actual());
$__psValor = trim((string)($psValor ?? ''));
$__psEnLista = in_array(mb_strtolower($__psValor), array_map(fn($o) => mb_strtolower($o['nombre']), $__psOps), true);
?>
<select name="proveedor" id="<?= htmlspecialchars($psId) ?>" class="<?= htmlspecialchars($psClase ?? 'form-select') ?> proveedor-select" data-buscar>
    <option value="">— Sin proveedor —</option>
    <?php if ($__psValor !== '' && !$__psEnLista): ?><option value="<?= htmlspecialchars($__psValor) ?>" data-id="" selected><?= htmlspecialchars($__psValor) ?> (no está en el catálogo)</option><?php endif; ?>
    <?php foreach ($__psOps as $o): ?><option value="<?= htmlspecialchars($o['nombre']) ?>" data-id="<?= (int)$o['id'] ?>" <?= mb_strtolower($o['nombre']) === mb_strtolower($__psValor) ? 'selected' : '' ?>><?= htmlspecialchars($o['nombre']) ?></option><?php endforeach; ?>
    <option value="__nuevo__">➕ Agregar proveedor nuevo…</option>
</select>
<input type="hidden" name="proveedor_id" class="proveedor-id" value="">
<?php if (empty($GLOBALS['__psScript'])): $GLOBALS['__psScript'] = true; ?>
<script>
(() => {
    const idDe = sel => sel.selectedOptions[0]?.dataset.id || '';
    const sincronizar = sel => { const h = sel.parentElement.querySelector('.proveedor-id'); if (h) h.value = idDe(sel); };
    window.proveedorSelectValor = (sel, nombre) => {
        nombre = String(nombre || '').trim();
        let o = [...sel.options].find(x => x.value.toLowerCase() === nombre.toLowerCase());
        if (!o && nombre) { o = new Option(nombre + ' (no está en el catálogo)', nombre); o.dataset.id = ''; sel.add(o, sel.options[sel.options.length - 1]); }
        sel.value = o ? o.value : '';
        sincronizar(sel);
    };
    const tipos = <?= json_encode(array_map(fn($t) => $t[0], PROVEEDOR_TIPOS), JSON_UNESCAPED_UNICODE) ?>;
    document.addEventListener('change', async e => {
        const sel = e.target.closest('.proveedor-select');
        if (!sel) return;
        if (sel.value !== '__nuevo__') return sincronizar(sel);
        const r = await Swal.fire({
            title: 'Nuevo proveedor',
            html: `<input id="psNombre" class="swal2-input" placeholder="Nombre" maxlength="150">
                   <select id="psTipo" class="swal2-select">${Object.entries(tipos).map(([k, t]) => `<option value="${k}"${k === 'empresa' ? ' selected' : ''}>${t}</option>`).join('')}</select>
                   <input id="psWeb" class="swal2-input" placeholder="Sitio web (para el logo), ej. godaddy.com" maxlength="150">`,
            showCancelButton: true, confirmButtonText: 'Agregar', cancelButtonText: 'Cancelar',
            preConfirm: async () => {
                const fd = new FormData();
                fd.append('accion', 'guardar'); fd.append('nombre', document.getElementById('psNombre').value);
                fd.append('tipo', document.getElementById('psTipo').value); fd.append('sitio_web', document.getElementById('psWeb').value);
                const res = await fetch((window.APP_BASE ? window.APP_BASE + '/' : '') + 'includes/proveedor_accion.php', { method: 'POST', body: fd, credentials: 'same-origin' });
                const d = await res.json().catch(() => ({ success: false, error: 'Respuesta inesperada del servidor.' }));
                if (!d.success) return Swal.showValidationMessage(d.error);
                return d.proveedor;
            },
        });
        if (!r.isConfirmed) { sel.value = ''; return sincronizar(sel); }
        // Se agrega a todas las listas de proveedores de la página
        document.querySelectorAll('.proveedor-select').forEach(s => {
            if (![...s.options].some(o => o.dataset.id == r.value.id)) { const o = new Option(r.value.nombre, r.value.nombre); o.dataset.id = r.value.id; s.add(o, s.options[s.options.length - 1]); }
        });
        sel.value = r.value.nombre;
        sincronizar(sel);
    });
    document.querySelectorAll('.proveedor-select').forEach(sincronizar);
})();
</script>
<?php endif; ?>
