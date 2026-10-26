ALTER TABLE gastos_operativos
 ADD COLUMN pauta_presupuesto DECIMAL(14,2) NULL,
 ADD COLUMN pauta_objetivo VARCHAR(120) NULL,
 ADD COLUMN pauta_resultado_tipo VARCHAR(100) NULL,
 ADD COLUMN pauta_resultados BIGINT UNSIGNED NULL,
 ADD COLUMN pauta_alcance BIGINT UNSIGNED NULL,
 ADD COLUMN pauta_impresiones BIGINT UNSIGNED NULL,
 ADD COLUMN pauta_clics BIGINT UNSIGNED NULL;
