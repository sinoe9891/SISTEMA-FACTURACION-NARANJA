/**
 * moneda-factura.js — Moneda de los precios en Generar / Editar factura (campos de includes/templates/moneda_factura.php).
 * En dólares, los precios se escriben en USD y el servidor los convierte a lempiras con la tasa (la factura sigue en L).
 *   const MF = MonedaFactura({ precios: () => [inputs de precio], recalcular });
 *   MF.usd() · MF.tasa() · MF.lempiras(precio) · MF.desdeLempiras(precioL) · MF.pintarUsd(totalUsd)
 */
window.MonedaFactura = function ({ precios, recalcular }) {
    const sel = document.getElementById('fMoneda'), tasa = document.getElementById('fTasa');
    if (!sel) return { usd: () => false, tasa: () => 0, lempiras: p => p, desdeLempiras: p => p, pintarUsd: () => {} };
    const r2 = n => Math.round(Number(Number(n).toPrecision(15) + 'e2')) / 100;
    const api = {
        usd: () => sel.value === 'USD',
        tasa: () => parseFloat(tasa.value) || 0,
        // Precio unitario en lempiras, como lo guarda el servidor
        lempiras: p => api.usd() ? r2(p * api.tasa()) : p,
        // Precio de un producto (en L) para escribirlo en la moneda elegida
        desdeLempiras: p => api.usd() && api.tasa() > 0 ? r2(p / api.tasa()) : p,
        pintarUsd: tot => {
            const el = document.getElementById('mfTotalUsd');
            if (el) el.textContent = '$ ' + tot.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
    };
    let previa = sel.value;
    const pintar = () => {
        const u = api.usd();
        document.getElementById('fTasaCol').hidden = !u;
        document.getElementById('mfNota').hidden = !u;
        document.querySelectorAll('.mf-sim').forEach(e => e.textContent = u ? 'USD' : 'L');
        document.querySelectorAll('.mf-usd').forEach(e => e.hidden = !u);
        tasa.required = u;
    };
    // Al cambiar de moneda se convierten los precios ya escritos (si hay tasa)
    sel.addEventListener('change', () => {
        const t = api.tasa();
        if (t > 0 && previa !== sel.value)
            precios().forEach(i => { const v = parseFloat(i.value); if (v) i.value = (sel.value === 'USD' ? v / t : v * t).toFixed(2); });
        previa = sel.value;
        pintar();
        recalcular();
    });
    tasa.addEventListener('input', recalcular);
    pintar();
    return api;
};
