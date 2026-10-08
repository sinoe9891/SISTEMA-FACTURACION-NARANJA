-- Interruptor de la depreciación de activos fijos por empresa: apagada no se resta en resultados y los activos quedan a su costo;
-- al encenderla se recalcula todo (la depreciación no se guarda, se calcula al vuelo).
ALTER TABLE clientes_saas ADD COLUMN IF NOT EXISTS depreciacion_activa TINYINT(1) NOT NULL DEFAULT 1;
