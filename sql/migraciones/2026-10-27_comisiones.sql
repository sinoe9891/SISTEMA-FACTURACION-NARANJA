-- Comisiones por venta: en la factura (opcional) se elige un vendedor (colaborador o externo, con su número de identidad)
-- y la comisión en porcentaje del subtotal sin ISV o en cantidad fija. Se debe cuando el cliente paga la factura; al pagarla
-- se registra como gasto (y sale del banco si se elige la cuenta).
CREATE TABLE vendedores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    tipo ENUM('colaborador','externo') NOT NULL DEFAULT 'externo',
    colaborador_id INT NULL,
    nombre VARCHAR(150) NOT NULL,
    identidad VARCHAR(30) NULL,
    telefono VARCHAR(40) NULL,
    email VARCHAR(150) NULL,
    notas VARCHAR(255) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY (cliente_id),
    KEY (colaborador_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE factura_comisiones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    factura_id INT NOT NULL,
    vendedor_id INT NOT NULL,
    tipo ENUM('porcentaje','fijo') NOT NULL DEFAULT 'porcentaje',
    valor DECIMAL(14,4) NOT NULL,
    base DECIMAL(14,2) NOT NULL DEFAULT 0,
    monto DECIMAL(14,2) NOT NULL,
    estado ENUM('pendiente','pagada','anulada') NOT NULL DEFAULT 'pendiente',
    gasto_id INT NULL,
    pagada_en DATE NULL,
    notas VARCHAR(255) NULL,
    usuario_id INT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY (factura_id),
    KEY (cliente_id, estado),
    KEY (vendedor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
