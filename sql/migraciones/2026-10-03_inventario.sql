-- Inventario: productos tipo "bien" con existencias por establecimiento (tienda),
-- kardex de movimientos y traslados entre tiendas.
-- Los productos existentes quedan como "servicio" (sin inventario): nada cambia para ellos.

ALTER TABLE productos_clientes
    ADD COLUMN IF NOT EXISTS tipo ENUM('servicio','bien') NOT NULL DEFAULT 'servicio',
    ADD COLUMN IF NOT EXISTS sku VARCHAR(50) NULL,
    ADD COLUMN IF NOT EXISTS codigo_barras VARCHAR(64) NULL,
    ADD COLUMN IF NOT EXISTS unidad VARCHAR(20) NOT NULL DEFAULT 'unidad',
    ADD COLUMN IF NOT EXISTS costo DECIMAL(14,4) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS stock_minimo DECIMAL(14,3) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS activo TINYINT(1) NOT NULL DEFAULT 1;

-- Índices para búsqueda por código (el lector de código de barras escribe el código)
CREATE INDEX IF NOT EXISTS idx_pc_sku ON productos_clientes (cliente_id, sku);
CREATE INDEX IF NOT EXISTS idx_pc_barras ON productos_clientes (cliente_id, codigo_barras);

CREATE TABLE IF NOT EXISTS inv_existencias (
    cliente_id INT NOT NULL,
    producto_id INT NOT NULL,
    establecimiento_id INT NOT NULL,
    cantidad DECIMAL(14,3) NOT NULL DEFAULT 0,      -- físico en la tienda
    reservado DECIMAL(14,3) NOT NULL DEFAULT 0,     -- apartado / en carrito de otra caja (no disponible)
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (producto_id, establecimiento_id),
    KEY idx_cliente (cliente_id),
    CONSTRAINT fk_exist_producto FOREIGN KEY (producto_id) REFERENCES productos_clientes(id) ON DELETE CASCADE,
    CONSTRAINT chk_exist_cantidad CHECK (cantidad >= 0),
    CONSTRAINT chk_exist_reservado CHECK (reservado >= 0 AND reservado <= cantidad)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS inv_movimientos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    producto_id INT NOT NULL,
    establecimiento_id INT NOT NULL,
    fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    tipo ENUM('entrada','ajuste_entrada','ajuste_salida','venta','anulacion_venta','traslado_salida','traslado_entrada') NOT NULL,
    cantidad DECIMAL(14,3) NOT NULL,                 -- siempre positiva; el tipo dice si suma o resta
    costo_unitario DECIMAL(14,4) NULL,
    saldo DECIMAL(14,3) NOT NULL,                    -- existencia en la tienda después del movimiento
    documento VARCHAR(20) NULL,                      -- compra | factura | ajuste | traslado
    documento_id INT NULL,
    referencia VARCHAR(100) NULL,
    notas VARCHAR(255) NULL,
    usuario_id INT NULL,
    KEY idx_producto_fecha (producto_id, fecha),
    KEY idx_cliente_fecha (cliente_id, fecha),
    KEY idx_documento (documento, documento_id),
    CONSTRAINT chk_invmov_cantidad CHECK (cantidad > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS inv_traslados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    origen_id INT NOT NULL,
    destino_id INT NOT NULL,
    estado ENUM('en_transito','recibido','anulado') NOT NULL DEFAULT 'en_transito',
    fecha_envio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_recibido DATETIME NULL,
    notas VARCHAR(255) NULL,
    usuario_id INT NULL,
    recibido_por INT NULL,
    KEY idx_cliente_estado (cliente_id, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS inv_traslado_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    traslado_id INT NOT NULL,
    producto_id INT NOT NULL,
    cantidad DECIMAL(14,3) NOT NULL,
    KEY idx_traslado (traslado_id),
    CONSTRAINT fk_tritem_traslado FOREIGN KEY (traslado_id) REFERENCES inv_traslados(id) ON DELETE CASCADE,
    CONSTRAINT chk_tritem_cantidad CHECK (cantidad > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
