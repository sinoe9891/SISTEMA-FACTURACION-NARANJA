-- Moneda en cotizaciones (L o USD) y facturas con precios en dólares: la factura sigue en lempiras (SAR);
-- se guardan la tasa usada, el total en USD y el precio en USD de cada línea como referencia.
ALTER TABLE cotizaciones ADD COLUMN moneda CHAR(3) NOT NULL DEFAULT 'HNL';
ALTER TABLE facturas ADD COLUMN moneda CHAR(3) NOT NULL DEFAULT 'HNL', ADD COLUMN tasa_cambio DECIMAL(10,4) NULL, ADD COLUMN total_usd DECIMAL(14,2) NULL;
ALTER TABLE factura_items_receptor ADD COLUMN precio_usd DECIMAL(14,3) NULL;
