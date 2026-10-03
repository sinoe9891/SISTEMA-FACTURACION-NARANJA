-- Punto de venta (fase 1): turnos de caja, movimientos de efectivo, ventas y sus pagos.
-- Cada venta genera una factura (con el CAI del punto de emisión = caja) y descuenta inventario.
-- Requiere 2026-10-03_inventario.sql.

CREATE TABLE IF NOT EXISTS pos_turnos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    establecimiento_id INT NOT NULL,
    punto_emision_id INT NOT NULL,              -- la caja
    usuario_id INT NOT NULL,                    -- cajero
    abierto_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    monto_inicial DECIMAL(14,2) NOT NULL DEFAULT 0,
    cerrado_en DATETIME NULL,
    efectivo_esperado DECIMAL(14,2) NULL,
    efectivo_contado DECIMAL(14,2) NULL,
    diferencia DECIMAL(14,2) NULL,
    justificacion VARCHAR(255) NULL,
    estado ENUM('abierto','cerrado') NOT NULL DEFAULT 'abierto',
    KEY idx_punto_estado (punto_emision_id, estado),
    KEY idx_cliente_fecha (cliente_id, abierto_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS pos_movimientos_caja (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    turno_id INT NOT NULL,
    tipo ENUM('entrada','retiro') NOT NULL,
    monto DECIMAL(14,2) NOT NULL,
    motivo VARCHAR(255) NOT NULL,
    usuario_id INT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_turno (turno_id),
    CONSTRAINT fk_movcaja_turno FOREIGN KEY (turno_id) REFERENCES pos_turnos(id),
    CONSTRAINT chk_movcaja_monto CHECK (monto > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS pos_ventas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    turno_id INT NOT NULL,
    factura_id INT NOT NULL,
    total DECIMAL(14,2) NOT NULL,
    efectivo_recibido DECIMAL(14,2) NOT NULL DEFAULT 0,
    cambio DECIMAL(14,2) NOT NULL DEFAULT 0,
    idempotencia CHAR(32) NOT NULL,             -- evita cobrar dos veces si el navegador reintenta
    usuario_id INT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_idempotencia (cliente_id, idempotencia),
    KEY idx_turno (turno_id),
    KEY idx_factura (factura_id),
    CONSTRAINT fk_venta_turno FOREIGN KEY (turno_id) REFERENCES pos_turnos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS pos_venta_pagos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    venta_id INT NOT NULL,
    forma ENUM('efectivo','tarjeta','transferencia') NOT NULL,
    monto DECIMAL(14,2) NOT NULL,               -- parte del total cubierta con esta forma (efectivo: sin el cambio)
    referencia VARCHAR(100) NULL,               -- tarjeta: N.° de autorización; transferencia: referencia
    ultimos4 CHAR(4) NULL,
    KEY idx_venta (venta_id),
    CONSTRAINT fk_pago_venta FOREIGN KEY (venta_id) REFERENCES pos_ventas(id) ON DELETE CASCADE,
    CONSTRAINT chk_pago_monto CHECK (monto > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
