-- Pagos anticipados de un contrato: dinero recibido ANTES de emitir la factura
-- (p. ej. proyectos por etapas que se facturan al final). Cuando se emite la factura,
-- los anticipos se aplican como abonos (cobros_factura) y quedan ligados a ella.
-- Requiere 2026-10-03_cuentas_cobrar.sql.
CREATE TABLE IF NOT EXISTS contratos_anticipos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    contrato_id INT NOT NULL,
    fecha DATE NOT NULL,
    monto DECIMAL(14,2) NOT NULL,          -- monto recibido (con ISV, tal como se depositó)
    metodo ENUM('efectivo','transferencia','cheque','tarjeta','otro') NOT NULL DEFAULT 'transferencia',
    referencia VARCHAR(100) NULL,
    concepto VARCHAR(255) NULL,            -- p. ej. "Etapa 1 (40 %)"
    cuenta_id INT NULL,
    movimiento_id INT NULL,
    factura_id INT NULL,                   -- factura a la que se aplicó (NULL = aún sin factura)
    cobro_id INT NULL,                     -- abono generado al aplicarlo
    anulado TINYINT(1) NOT NULL DEFAULT 0,
    motivo_anulacion VARCHAR(255) NULL,
    usuario_id INT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_contrato (contrato_id),
    KEY idx_cliente (cliente_id),
    CONSTRAINT chk_anticipo_monto CHECK (monto > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
