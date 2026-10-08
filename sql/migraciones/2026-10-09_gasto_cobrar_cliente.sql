-- Gastos que la empresa paga y después le cobra al cliente (p. ej. el hosting de Sig-Urban): a quién se le cobra
-- y en qué factura se incluyó. Mientras no tenga factura, Nueva factura avisa al elegir ese cliente.
ALTER TABLE gastos ADD COLUMN IF NOT EXISTS cobrar_receptor_id INT NULL AFTER naturaleza;
ALTER TABLE gastos ADD COLUMN IF NOT EXISTS cobrado_factura_id INT NULL AFTER cobrar_receptor_id;
ALTER TABLE gastos ADD INDEX IF NOT EXISTS idx_gastos_cobrar (cliente_id, cobrar_receptor_id, cobrado_factura_id);
