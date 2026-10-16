-- Tasa de Seguridad Poblacional (Ley Poblacional): el banco cobra L 2 por cada L 1,000 o fracción de cada débito.
-- Por cuenta se decide si aplica (encendido en las cuentas en lempiras). El sistema la registra sola en cada salida.
ALTER TABLE cuentas_bancarias ADD COLUMN ley_poblacional TINYINT(1) NOT NULL DEFAULT 1;
UPDATE cuentas_bancarias SET ley_poblacional = 0 WHERE moneda <> 'HNL';
