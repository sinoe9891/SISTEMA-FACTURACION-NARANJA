-- Pagos recibidos: comprobante opcional también en recibos de contratos y pagos anticipados (los abonos a facturas ya lo tienen)
ALTER TABLE contratos_recibos ADD COLUMN archivo_adjunto VARCHAR(255) NULL, ADD COLUMN archivo_nombre VARCHAR(255) NULL;
ALTER TABLE contratos_anticipos ADD COLUMN archivo_adjunto VARCHAR(255) NULL, ADD COLUMN archivo_nombre VARCHAR(255) NULL;
