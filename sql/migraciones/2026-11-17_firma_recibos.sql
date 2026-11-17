-- Quién firma los recibos: por defecto el firmante marcado en Configuración → Firmas de documentos
-- (si ninguno, el Vo.Bo. = representante legal) y, si se quiere, uno distinto por contrato.
ALTER TABLE documento_firmantes ADD COLUMN IF NOT EXISTS firma_recibos TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE contratos ADD COLUMN IF NOT EXISTS firma_recibo VARCHAR(12) NULL;   -- NULL = el predeterminado, o 'autorizado' / 'vobo'
