<?php
// Campos «Moneda de los precios» y «Tasa» de Generar / Editar factura (los maneja clientes/js/moneda-factura.js).
// Recibe $mfMoneda ('HNL' | 'USD'), $mfTasa (tasa guardada o la del día) y $mfLabel (clase de las etiquetas).
?>
<div class="col-md-4">
    <label class="<?= $mfLabel ?>">Moneda de los precios</label>
    <select name="moneda" id="fMoneda" class="form-select">
        <option value="HNL" <?= $mfMoneda !== 'USD' ? 'selected' : '' ?>>Lempiras (L)</option>
        <option value="USD" <?= $mfMoneda === 'USD' ? 'selected' : '' ?>>Dólares (USD)</option>
    </select>
</div>
<div class="col-md-4" id="fTasaCol" <?= $mfMoneda !== 'USD' ? 'hidden' : '' ?>>
    <label class="<?= $mfLabel ?>">Tasa (L por 1 USD)</label>
    <input type="number" step="0.0001" min="1" name="tasa_cambio" id="fTasa" class="form-control" value="<?= $mfTasa > 0 ? rtrim(rtrim(number_format($mfTasa, 4, '.', ''), '0'), '.') : '' ?>">
</div>
<div class="col-12" id="mfNota" <?= $mfMoneda !== 'USD' ? 'hidden' : '' ?>>
    <div class="small text-muted"><i class="bi bi-info-circle me-1"></i>Escribe los precios en dólares: la factura se emite en lempiras (como pide la SAR) con esta tasa y muestra el total en USD de referencia.</div>
</div>
