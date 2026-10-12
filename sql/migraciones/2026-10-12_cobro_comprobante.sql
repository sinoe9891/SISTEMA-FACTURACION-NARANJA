-- Comprobante del cobro de una factura (captura de la transferencia, foto del cheque…). Se guarda en uploads/gastos como los demás comprobantes.
ALTER TABLE cobros_factura ADD COLUMN archivo_adjunto VARCHAR(255) NULL AFTER notas,
                           ADD COLUMN archivo_nombre VARCHAR(255) NULL AFTER archivo_adjunto;
