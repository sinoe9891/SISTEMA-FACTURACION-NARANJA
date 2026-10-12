-- Catálogo de proveedores (a quién se le paga: personas, empresas, software, bancos, gobierno…).
-- Gastos y licencias eligen el proveedor de una lista; el texto anterior se conserva en gastos.proveedor.
CREATE TABLE IF NOT EXISTS proveedores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    tipo VARCHAR(30) NOT NULL DEFAULT 'empresa',
    rtn VARCHAR(20) NULL,
    sitio_web VARCHAR(150) NULL,
    logo VARCHAR(80) NULL,
    telefono VARCHAR(40) NULL,
    correo VARCHAR(150) NULL,
    notas TEXT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_nombre (cliente_id, nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE gastos ADD COLUMN proveedor_id INT NULL AFTER proveedor, ADD KEY idx_proveedor (proveedor_id);
ALTER TABLE licencias ADD COLUMN proveedor_id INT NULL AFTER proveedor;
