-- Comprobante opcional (foto o PDF) de cheques, préstamos recibidos y compras de activos fijos
ALTER TABLE cheques ADD COLUMN IF NOT EXISTS archivo_adjunto VARCHAR(255) NULL;
ALTER TABLE cheques ADD COLUMN IF NOT EXISTS archivo_nombre VARCHAR(255) NULL;
ALTER TABLE prestamos_recibidos ADD COLUMN IF NOT EXISTS archivo_adjunto VARCHAR(255) NULL;
ALTER TABLE prestamos_recibidos ADD COLUMN IF NOT EXISTS archivo_nombre VARCHAR(255) NULL;
ALTER TABLE activos_fijos ADD COLUMN IF NOT EXISTS archivo_adjunto VARCHAR(255) NULL;
ALTER TABLE activos_fijos ADD COLUMN IF NOT EXISTS archivo_nombre VARCHAR(255) NULL;
