-- Programas de un cliente (p. ej. Proyecto Aldea Global → PROGRESE): los contratos y las facturas indican si son
-- «directo» al cliente o de uno de sus programas; sale en la factura («Programa: PROGRESE») y en los correos.
-- Gastos operativos: se registran también por programa o por contrato (un contrato rotativo agrupa varias empresas)
-- y al colaborador se le asigna el cliente completo, un programa o un contrato.
CREATE TABLE programas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    receptor_id INT NOT NULL,
    nombre VARCHAR(120) NOT NULL,
    notas VARCHAR(255) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (receptor_id, nombre),
    KEY (cliente_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE contratos ADD COLUMN programa_id INT NULL;
ALTER TABLE facturas ADD COLUMN programa_id INT NULL, ADD KEY (programa_id);
ALTER TABLE gastos_operativos ADD COLUMN programa_id INT NULL, ADD COLUMN contrato_id INT NULL;

-- Asignaciones: cliente completo (sin programa ni contrato), solo un programa o solo un contrato
ALTER TABLE usuarios_clientes DROP PRIMARY KEY;
ALTER TABLE usuarios_clientes ADD COLUMN id INT AUTO_INCREMENT PRIMARY KEY FIRST, ADD COLUMN programa_id INT NULL, ADD COLUMN contrato_id INT NULL, ADD KEY (usuario_id);
