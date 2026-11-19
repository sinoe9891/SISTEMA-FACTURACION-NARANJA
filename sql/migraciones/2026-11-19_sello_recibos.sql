-- Sello de la empresa también en los recibos y avisos de cobro (se puede apagar en Configuración → Firmas)
ALTER TABLE clientes_saas ADD COLUMN IF NOT EXISTS sello_recibos TINYINT(1) NOT NULL DEFAULT 1;
