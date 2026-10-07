-- Comprobante de un movimiento bancario (depósito, retiro, comisión…). Se guarda en includes/uploads/gastos/.
ALTER TABLE movimientos_bancarios ADD COLUMN IF NOT EXISTS archivo_adjunto VARCHAR(255) NULL AFTER descripcion;
ALTER TABLE movimientos_bancarios ADD COLUMN IF NOT EXISTS archivo_nombre VARCHAR(255) NULL AFTER archivo_adjunto;
