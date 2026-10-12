-- Precio fijo al cliente por año (opcional): si está, se cobra ese monto en vez de costo + comisión (p. ej. VPS de Sig-Urban: L 10,000 al año).
ALTER TABLE licencias ADD COLUMN precio_cliente DECIMAL(12,2) NULL AFTER comision_pct;
