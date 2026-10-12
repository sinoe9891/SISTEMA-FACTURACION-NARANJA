-- Cotizaciones: se crean como una factura (cliente y productos), tienen vigencia, «Incluye» y «Condiciones»,
-- se envían por correo con su PDF y se pueden convertir en factura.
CREATE TABLE IF NOT EXISTS cotizaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    numero VARCHAR(30) NOT NULL,
    receptor_id INT NOT NULL,
    fecha DATE NOT NULL,
    valida_hasta DATE NOT NULL,
    servicio VARCHAR(255) NULL,
    incluye TEXT NULL,
    condiciones TEXT NULL,
    notas TEXT NULL,
    subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
    isv_15 DECIMAL(14,2) NOT NULL DEFAULT 0,
    isv_18 DECIMAL(14,2) NOT NULL DEFAULT 0,
    total DECIMAL(14,2) NOT NULL DEFAULT 0,
    estado ENUM('borrador','enviada','aceptada','rechazada','facturada') NOT NULL DEFAULT 'borrador',
    factura_id INT NULL,
    enviada_en DATETIME NULL,
    enviada_a VARCHAR(500) NULL,
    usuario_id INT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cotizacion_numero (cliente_id, numero),
    KEY idx_cotizacion_receptor (cliente_id, receptor_id),
    KEY idx_cotizacion_factura (factura_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cotizacion_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cotizacion_id INT NOT NULL,
    producto_id INT NULL,
    descripcion VARCHAR(400) NOT NULL,
    detalle TEXT NULL,
    cantidad DECIMAL(12,2) NOT NULL DEFAULT 1,
    precio DECIMAL(14,2) NOT NULL DEFAULT 0,
    tipo_isv TINYINT NOT NULL DEFAULT 15,
    subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
    orden INT NOT NULL DEFAULT 0,
    KEY idx_item_cotizacion (cotizacion_id),
    CONSTRAINT fk_item_cotizacion FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Textos por defecto de una cotización nueva (por empresa)
CREATE TABLE IF NOT EXISTS cotizaciones_config (
    cliente_id INT PRIMARY KEY,
    vigencia_dias SMALLINT UNSIGNED NOT NULL DEFAULT 15,
    incluye TEXT NULL,
    condiciones TEXT NULL,
    lema VARCHAR(200) NULL,
    contacto_email VARCHAR(150) NULL,
    contacto_telefono VARCHAR(50) NULL,
    firmante VARCHAR(20) NOT NULL DEFAULT 'vobo',
    asunto_correo VARCHAR(255) NULL,
    mensaje_correo TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sello de la empresa (Configuración → Firmas) y en qué documentos sale
ALTER TABLE clientes_saas ADD COLUMN sello VARCHAR(255) NULL,
                          ADD COLUMN sello_facturas TINYINT(1) NOT NULL DEFAULT 0,
                          ADD COLUMN sello_cotizaciones TINYINT(1) NOT NULL DEFAULT 1;
