-- Módulo de bancos: cuentas (ahorro / cheques), movimientos en HNL o USD,
-- transferencias entre cuentas y chequera.
-- Todas las tablas llevan cliente_id (multiempresa).

CREATE TABLE IF NOT EXISTS cuentas_bancarias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    banco VARCHAR(100) NOT NULL,
    tipo ENUM('ahorro','cheques') NOT NULL DEFAULT 'ahorro',
    numero VARCHAR(40) NOT NULL,
    titular VARCHAR(150) NULL,
    moneda ENUM('HNL','USD') NOT NULL DEFAULT 'HNL',
    saldo_inicial DECIMAL(14,2) NOT NULL DEFAULT 0,
    fecha_saldo_inicial DATE NOT NULL,
    activa TINYINT(1) NOT NULL DEFAULT 1,
    notas VARCHAR(255) NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cuenta (cliente_id, banco, numero),
    KEY idx_cliente (cliente_id),
    CONSTRAINT fk_cuentas_cliente FOREIGN KEY (cliente_id) REFERENCES clientes_saas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS movimientos_bancarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    cuenta_id INT NOT NULL,
    fecha DATE NOT NULL,
    sentido ENUM('entrada','salida') NOT NULL,
    tipo ENUM('deposito','retiro','transferencia','cheque','pago_gasto','cobro_factura','comision','interes','ajuste') NOT NULL,
    monto DECIMAL(14,2) NOT NULL,                 -- siempre positivo, en la moneda de la cuenta
    descripcion VARCHAR(255) NOT NULL,
    referencia VARCHAR(100) NULL,
    tasa_cambio DECIMAL(12,4) NULL,               -- transferencias entre monedas (HNL por 1 USD)
    transferencia_grupo VARCHAR(32) NULL,         -- une la salida y la entrada de una transferencia
    cheque_id INT NULL,
    gasto_id INT NULL,
    factura_id INT NULL,
    conciliado TINYINT(1) NOT NULL DEFAULT 0,
    anulado TINYINT(1) NOT NULL DEFAULT 0,
    usuario_id INT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cuenta_fecha (cuenta_id, fecha),
    KEY idx_cliente_fecha (cliente_id, fecha),
    KEY idx_transferencia (transferencia_grupo),
    CONSTRAINT fk_mov_cuenta FOREIGN KEY (cuenta_id) REFERENCES cuentas_bancarias(id),
    CONSTRAINT chk_mov_monto CHECK (monto > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS cheques (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    cuenta_id INT NOT NULL,
    numero VARCHAR(20) NOT NULL,
    fecha_emision DATE NOT NULL,
    beneficiario VARCHAR(150) NOT NULL,
    monto DECIMAL(14,2) NOT NULL,
    concepto VARCHAR(255) NULL,
    estado ENUM('emitido','cobrado','anulado') NOT NULL DEFAULT 'emitido',
    fecha_cobro DATE NULL,
    motivo_anulacion VARCHAR(255) NULL,
    gasto_id INT NULL,
    usuario_id INT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cheque (cuenta_id, numero),
    KEY idx_cliente_estado (cliente_id, estado),
    CONSTRAINT fk_cheque_cuenta FOREIGN KEY (cuenta_id) REFERENCES cuentas_bancarias(id),
    CONSTRAINT chk_cheque_monto CHECK (monto > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
