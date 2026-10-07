-- Plan de pagos de un contrato: el calendario completo acordado con el cliente
-- (anticipo, cuotas mensuales/anuales, etapas). Cada línea se liga a cómo se cobró:
-- un recibo (contratos sin factura), una factura del contrato o un pago anticipado.
-- El estado (pendiente, vencido, facturado, pagado) se calcula a partir de esos vínculos.
CREATE TABLE IF NOT EXISTS contratos_plan (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    contrato_id INT NOT NULL,
    orden SMALLINT NOT NULL DEFAULT 0,
    tipo ENUM('anticipo','cuota','etapa','anualidad','otro') NOT NULL DEFAULT 'cuota',
    concepto VARCHAR(300) NOT NULL,
    fecha DATE NOT NULL,                   -- fecha acordada de pago
    monto DECIMAL(14,2) NOT NULL,          -- sin ISV
    isv DECIMAL(14,2) NOT NULL DEFAULT 0,  -- 15 % si el contrato se factura; 0 con recibo
    total DECIMAL(14,2) NOT NULL,          -- monto + isv: lo que paga el cliente
    factura_id INT NULL,
    recibo_id INT NULL,
    anticipo_id INT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_contrato (cliente_id, contrato_id, fecha),
    KEY idx_factura (factura_id),
    KEY idx_recibo (recibo_id),
    KEY idx_anticipo (anticipo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
