-- Historial de cargo: cada ajuste del historial de sueldo puede indicar el puesto que rige desde esa fecha.
ALTER TABLE colaborador_salarios ADD COLUMN IF NOT EXISTS puesto VARCHAR(150) NULL AFTER salario_base;
