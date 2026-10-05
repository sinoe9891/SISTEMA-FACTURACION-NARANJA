-- Contactos adicionales de cada cliente. Los marcados «copiar_cobros» van en copia (CC) de los cobros por correo.
-- contrato_id: el contacto pertenece a un proyecto/contrato del cliente (p. ej. Aldea Global → PROGRESE) y solo va en
-- copia de las facturas de ese contrato. NULL = contacto general, va en copia de todos los cobros del cliente.
CREATE TABLE IF NOT EXISTS clientes_factura_contactos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    receptor_id INT NOT NULL,
    contrato_id INT NULL,
    nombre VARCHAR(120) NOT NULL,
    cargo VARCHAR(100) NULL,
    email VARCHAR(150) NULL,
    telefono VARCHAR(40) NULL,
    copiar_cobros TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_receptor (cliente_id, receptor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
