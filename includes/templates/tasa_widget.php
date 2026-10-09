<?php
/**
 * tasa_widget.php — Panel del dólar (como el de los bancos): fecha, compra y venta (BCH) o tasa de referencia,
 * y un convertidor USD ↔ lempiras. Se usa en la barra superior (al hacer clic en el dólar) y en Configuración → Tasa del dólar.
 * Espera: $twTasa (fila de tasas_cambio) y $twId (prefijo único de ids).
 * Convertir: de USD a lempiras con la COMPRA (lo que te pagan por tus dólares); de lempiras a USD con la VENTA (lo que cuesta comprarlos).
 */
if (empty($twTasa)) return;
$__twBch = ($twTasa['fuente'] ?? '') === 'BCH';
$__twCompra = (float)($__twBch ? $twTasa['compra'] : $twTasa['referencia']);
$__twVenta = (float)($__twBch ? $twTasa['venta'] : $twTasa['referencia']);
$__twMeses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$__twDias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
$__twTs = strtotime($twTasa['fecha']);
?>
<div class="app-tasa-panel" id="<?= $twId ?>">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="text-muted small"><?= ucfirst($__twDias[(int)date('w', $__twTs)]) ?> <?= (int)date('j', $__twTs) ?> de <?= $__twMeses[(int)date('n', $__twTs)] ?>, <?= date('Y', $__twTs) ?></span>
        <span class="badge <?= $__twBch ? 'bg-success' : 'bg-secondary' ?>"><?= $__twBch ? 'BCH oficial' : 'Referencia' ?></span>
    </div>
    <div class="app-tasa-cv">
        <div><div class="small text-muted">Compra</div><div class="fw-bold">L<?= number_format($__twCompra, 4) ?></div></div>
        <div><div class="small text-muted">Venta</div><div class="fw-bold">L<?= number_format($__twVenta, 4) ?></div></div>
    </div>
    <?php if (!$__twBch): ?><div class="small text-muted mt-1">Sin la clave del BCH: compra y venta usan la tasa de referencia del mercado.</div><?php endif; ?>
    <div class="app-tasa-conv mt-3" data-compra="<?= $__twCompra ?>" data-venta="<?= $__twVenta ?>">
        <button type="button" class="btn btn-link p-0 text-danger app-tasa-swap" title="Cambiar el sentido"><i class="bi bi-arrow-down-up"></i></button>
        <div class="input-group input-group-sm">
            <span class="input-group-text app-tasa-de" style="min-width:3.2rem">USD</span>
            <input type="number" class="form-control text-end app-tasa-monto" min="0" step="0.01" placeholder="0" inputmode="decimal">
        </div>
    </div>
    <div class="text-end fw-bold mt-2 app-tasa-res">LPS 0.00</div>
    <div class="small text-muted text-end app-tasa-nota">a la compra (L<?= number_format($__twCompra, 4) ?>)</div>
    <div class="small text-muted border-top mt-2 pt-2"><i class="bi bi-info-circle me-1"></i>Pagar con tarjeta o comprar dólares → <strong>venta</strong>. Recibir dólares y cambiarlos → <strong>compra</strong>.</div>
</div>
<script>
(function () {
    const p = document.getElementById(<?= json_encode($twId) ?>); if (!p) return;
    const c = p.querySelector('.app-tasa-conv'), inp = p.querySelector('.app-tasa-monto'), de = p.querySelector('.app-tasa-de'), res = p.querySelector('.app-tasa-res'), nota = p.querySelector('.app-tasa-nota');
    const compra = +c.dataset.compra, venta = +c.dataset.venta;
    let usdALps = true;
    const f = (n, d) => n.toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
    const calc = () => {
        const m = +inp.value || 0;
        if (usdALps) { res.textContent = 'LPS ' + f(m * compra, 2); nota.textContent = 'a la compra (L' + compra.toFixed(4) + ')'; }
        else { res.textContent = 'USD ' + f(venta ? m / venta : 0, 2); nota.textContent = 'a la venta (L' + venta.toFixed(4) + ')'; }
        de.textContent = usdALps ? 'USD' : 'LPS';
    };
    inp.addEventListener('input', calc);
    p.querySelector('.app-tasa-swap').addEventListener('click', () => { usdALps = !usdALps; calc(); inp.focus(); });
    calc();
})();
</script>
<?php unset($__twBch, $__twCompra, $__twVenta, $__twMeses, $__twDias, $__twTs); ?>
