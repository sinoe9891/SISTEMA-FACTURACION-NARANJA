-- Cobro anual de licencias de clientes: si la licencia se paga por más de un año (p. ej. dominio cada 2 años),
-- el pago se reparte en cobros anuales al cliente (uno por año, cada uno con su comisión).
CREATE TABLE IF NOT EXISTS licencias_cobros (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    licencia_id INT NOT NULL,
    gasto_id INT NOT NULL,
    anio_n TINYINT NOT NULL,
    anios TINYINT NOT NULL,
    fecha DATE NOT NULL,
    desde DATE NOT NULL,
    hasta DATE NOT NULL,
    costo DECIMAL(12,2) NOT NULL,
    factura_id INT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_gasto_anio (gasto_id, anio_n),
    KEY idx_pend (cliente_id, factura_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
