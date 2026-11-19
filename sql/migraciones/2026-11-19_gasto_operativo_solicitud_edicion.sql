-- Gastos operativos: el colaborador pide al administrador editar un gasto ya aprobado, con una nota
ALTER TABLE gastos_operativos ADD COLUMN IF NOT EXISTS edicion_solicitud VARCHAR(500) NULL;
ALTER TABLE gastos_operativos ADD COLUMN IF NOT EXISTS edicion_solicitada_en DATETIME NULL;
ALTER TABLE gastos_operativos ADD COLUMN IF NOT EXISTS edicion_solicitada_por INT NULL;
