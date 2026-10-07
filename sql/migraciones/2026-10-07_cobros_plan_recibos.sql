-- 1) Recibos con cuenta bancaria: el cobro entra a Bancos como depósito (si es desde el saldo inicial de la cuenta).
ALTER TABLE contratos_recibos ADD COLUMN IF NOT EXISTS cuenta_id INT NULL AFTER metodo_pago;
ALTER TABLE contratos_recibos ADD COLUMN IF NOT EXISTS movimiento_id INT NULL AFTER cuenta_id;

-- 2) Cobros por correo con recibos en PDF (contratos sin factura) y recordatorios del plan de pagos.
CREATE TABLE IF NOT EXISTS cobros_programados_recibos (
    cobro_id INT NOT NULL,
    recibo_id INT NOT NULL,
    archivo VARCHAR(255) NOT NULL,          -- PDF guardado junto a los de facturas (includes/uploads/cobros/)
    PRIMARY KEY (cobro_id, recibo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS cobros_programados_plan (
    cobro_id INT NOT NULL,
    plan_id INT NOT NULL,                   -- línea de contratos_plan que se recordó
    PRIMARY KEY (cobro_id, plan_id),
    KEY idx_plan (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3) Plantillas de mensaje para los tipos nuevos (Configuración → Mensajes y cuentas de pago).
ALTER TABLE configuracion_mensajes MODIFY tipo ENUM('envio_factura','saldo_pendiente','recordatorio_pago','envio_recibo') NOT NULL;
