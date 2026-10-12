-- Historial de cobros de licencias de clientes hechos antes de registrar la licencia (sin gasto ligado; costo puede no saberse).
ALTER TABLE licencias_cobros MODIFY gasto_id INT NULL, MODIFY costo DECIMAL(12,2) NULL, ADD COLUMN nota VARCHAR(255) NULL AFTER costo;
