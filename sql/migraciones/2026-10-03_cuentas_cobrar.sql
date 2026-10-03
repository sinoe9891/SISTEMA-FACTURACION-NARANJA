-- Cuentas por cobrar: abonos (pagos parciales o totales) de facturas.
-- La factura queda pagada (facturas.pagada = 1) cuando los abonos cubren el total.
-- Requiere 2026-10-03_bancos.sql si se quiere registrar el cobro en una cuenta bancaria.

CREATE TABLE IF NOT EXISTS cobros_factura (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    factura_id INT NOT NULL,
    fecha DATE NOT NULL,
    monto DECIMAL(14,2) NOT NULL,
    metodo ENUM('efectivo','transferencia','cheque','tarjeta','otro') NOT NULL DEFAULT 'transferencia',
    referencia VARCHAR(100) NULL,
    cuenta_id INT NULL,                 -- cuenta bancaria donde entró el dinero (opcional)
    movimiento_id INT NULL,             -- movimiento bancario generado
    notas VARCHAR(255) NULL,
    anulado TINYINT(1) NOT NULL DEFAULT 0,
    motivo_anulacion VARCHAR(255) NULL,
    usuario_id INT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_factura (factura_id),
    KEY idx_cliente_fecha (cliente_id, fecha),
    CONSTRAINT chk_cobro_monto CHECK (monto > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
