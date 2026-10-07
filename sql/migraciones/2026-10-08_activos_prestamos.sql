-- Naturaleza de cada salida registrada en Gastos: solo «gasto» resta en el Estado de resultados.
--   gasto    → gasto del período (lo de siempre)
--   capital  → abono a capital de un préstamo recibido (baja la deuda)
--   activo   → compra de un activo fijo (se reconoce por depreciación)
--   anticipo → anticipo a proveedor (dinero a favor hasta que llegue su factura)
ALTER TABLE gastos ADD COLUMN IF NOT EXISTS naturaleza ENUM('gasto','capital','activo','anticipo') NOT NULL DEFAULT 'gasto' AFTER tipo;
ALTER TABLE gastos ADD COLUMN IF NOT EXISTS prestamo_id INT NULL AFTER naturaleza;
ALTER TABLE gastos ADD COLUMN IF NOT EXISTS activo_id INT NULL AFTER prestamo_id;
ALTER TABLE gastos ADD INDEX IF NOT EXISTS idx_naturaleza (cliente_id, naturaleza);

-- Préstamos que recibe la empresa (bancos, fundaciones como THRIIVE, socios…)
CREATE TABLE IF NOT EXISTS prestamos_recibidos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    acreedor VARCHAR(150) NOT NULL,
    descripcion VARCHAR(255) NULL,
    monto DECIMAL(14,2) NOT NULL,              -- capital a devolver
    fecha DATE NOT NULL,                       -- fecha en que se recibió
    tasa_anual DECIMAL(7,4) NOT NULL DEFAULT 0,-- % anual (0 = sin intereses)
    num_cuotas SMALLINT NOT NULL DEFAULT 1,
    cuenta_id INT NULL,                        -- cuenta donde entró el dinero (opcional)
    movimiento_id INT NULL,
    activo_id INT NULL,                        -- activo que se financió (opcional)
    gasto_grupo_id INT NULL,                   -- serie de gastos que son sus cuotas
    notas TEXT NULL,
    estado ENUM('activo','pagado','anulado') NOT NULL DEFAULT 'activo',
    usuario_id INT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cliente (cliente_id, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Activos fijos (equipo de cómputo, mobiliario, vehículos…) y su depreciación en línea recta
CREATE TABLE IF NOT EXISTS activos_fijos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    nombre VARCHAR(200) NOT NULL,
    categoria VARCHAR(60) NOT NULL DEFAULT 'equipo_computo',
    fecha_compra DATE NOT NULL,
    costo DECIMAL(14,2) NOT NULL,
    valor_residual DECIMAL(14,2) NOT NULL DEFAULT 0,
    vida_util_meses SMALLINT NOT NULL DEFAULT 36,
    origen ENUM('compra','donacion','prestamo','mixto') NOT NULL DEFAULT 'compra',
    proveedor VARCHAR(150) NULL,
    notas TEXT NULL,
    fecha_baja DATE NULL,                      -- vendido o dado de baja (deja de depreciarse)
    usuario_id INT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cliente (cliente_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Préstamos y adelantos a colaboradores: de qué cuenta salió el dinero
ALTER TABLE colaborador_prestamos ADD COLUMN IF NOT EXISTS cuenta_id INT NULL AFTER notas;
ALTER TABLE colaborador_prestamos ADD COLUMN IF NOT EXISTS movimiento_id INT NULL AFTER cuenta_id;

-- Tipos de movimiento bancario nuevos
ALTER TABLE movimientos_bancarios MODIFY tipo ENUM('deposito','retiro','transferencia','cheque','pago_gasto','cobro_factura','comision','interes','ajuste','prestamo_colaborador','prestamo_recibido') NOT NULL;

-- Recibos de pagos anticipados adjuntos a los cobros por correo
CREATE TABLE IF NOT EXISTS cobros_programados_anticipos (
    cobro_id INT NOT NULL,
    anticipo_id INT NOT NULL,
    archivo VARCHAR(255) NOT NULL,
    PRIMARY KEY (cobro_id, anticipo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Tasa de cambio US$ → L por día (BCH: compra y venta; respaldo: referencia pública)
CREATE TABLE IF NOT EXISTS tasas_cambio (
    fecha DATE NOT NULL PRIMARY KEY,
    compra DECIMAL(12,4) NULL,
    venta DECIMAL(12,4) NULL,
    referencia DECIMAL(12,4) NULL,
    fuente VARCHAR(40) NOT NULL,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Clave de la Web-API del Banco Central de Honduras (cifrada), por empresa
CREATE TABLE IF NOT EXISTS configuracion_api (
    cliente_id INT NOT NULL PRIMARY KEY,
    bch_clave_cifrada TEXT NULL,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Historial de sueldo de cada colaborador (aumentos y ajustes): el sueldo vigente en cada período
CREATE TABLE IF NOT EXISTS colaborador_salarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    colaborador_id INT NOT NULL,
    desde DATE NOT NULL,
    salario_base DECIMAL(12,2) NOT NULL,
    motivo VARCHAR(255) NULL,
    usuario_id INT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_colab_desde (colaborador_id, desde),
    KEY idx_cliente (cliente_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
