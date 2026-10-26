-- Contactos del cliente: una persona se registra una vez y se marca a qué cobros va en copia: todos (sin filas aquí),
-- o uno o varios programas (p. ej. PROGRESE) y/o contratos. Reemplaza el contrato único de clientes_factura_contactos.contrato_id.
CREATE TABLE clientes_factura_contactos_alcance (
    contacto_id INT NOT NULL,
    programa_id INT NULL,
    contrato_id INT NULL,
    KEY (contacto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT INTO clientes_factura_contactos_alcance (contacto_id, contrato_id) SELECT id, contrato_id FROM clientes_factura_contactos WHERE contrato_id IS NOT NULL;
