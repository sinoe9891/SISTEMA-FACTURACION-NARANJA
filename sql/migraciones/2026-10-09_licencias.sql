-- Licencias y suscripciones (Adobe, Dropbox, hosting, dominios, correos…): de la empresa o de un cliente al que se le cobran
-- con comisión. Cada renovación genera un gasto pendiente (gastos.licencia_id); si es de un cliente, sale como aviso en su factura.
CREATE TABLE IF NOT EXISTS licencias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    proveedor VARCHAR(150) NULL,
    categoria_id INT NULL,
    frecuencia ENUM('mensual','anual','bienal') NOT NULL DEFAULT 'anual',
    moneda ENUM('HNL','USD') NOT NULL DEFAULT 'HNL',
    costo DECIMAL(14,2) NOT NULL,
    proxima_renovacion DATE NOT NULL,
    receptor_id INT NULL,
    comision_pct DECIMAL(6,2) NOT NULL DEFAULT 35.00,
    metodo_pago VARCHAR(20) NOT NULL DEFAULT 'tarjeta',
    notas TEXT NULL,
    activa TINYINT(1) NOT NULL DEFAULT 1,
    creado_en TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_licencias (cliente_id, activa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE gastos ADD COLUMN IF NOT EXISTS licencia_id INT NULL AFTER cobrado_factura_id;
ALTER TABLE gastos ADD INDEX IF NOT EXISTS idx_gastos_licencia (licencia_id);
-- Renovaciones cada 2 años (p. ej. dominios .hn)
ALTER TABLE licencias MODIFY frecuencia ENUM('mensual','anual','bienal') NOT NULL DEFAULT 'anual';
