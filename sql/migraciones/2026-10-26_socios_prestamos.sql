-- Préstamos a socios (aparte de nómina): la empresa le presta a un socio y él lo devuelve en cuotas quincenales (15 y fin de
-- mes) o mensuales. Cada pago entra al banco; mientras tanto el saldo es una cuenta por cobrar en el Balance. Se avisa por
-- correo antes de cada cuota y si se atrasa; PDF con la tabla de control.
ALTER TABLE socios ADD COLUMN email VARCHAR(150) NULL;
ALTER TABLE movimientos_bancarios MODIFY tipo ENUM('deposito','retiro','transferencia','cheque','pago_gasto','cobro_factura','comision','interes','ajuste','prestamo_colaborador','prestamo_recibido','prestamo_socio') NOT NULL;

CREATE TABLE socios_prestamos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    socio_id INT NOT NULL,
    descripcion VARCHAR(255) NOT NULL,
    monto DECIMAL(14,2) NOT NULL,
    fecha DATE NOT NULL,
    num_cuotas SMALLINT NOT NULL,
    frecuencia ENUM('quincenal','mensual') NOT NULL DEFAULT 'quincenal',
    primera_cuota DATE NOT NULL,
    cuenta_id INT NULL,
    movimiento_id INT NULL,
    referencia VARCHAR(80) NULL,
    archivo_adjunto VARCHAR(255) NULL,
    archivo_nombre VARCHAR(255) NULL,
    estado ENUM('activo','pagado','condonado','anulado') NOT NULL DEFAULT 'activo',
    condonado_monto DECIMAL(14,2) NOT NULL DEFAULT 0,
    condonado_gasto_id INT NULL,
    cerrado_en DATE NULL,
    avisar TINYINT(1) NOT NULL DEFAULT 1,
    aviso_dias TINYINT NOT NULL DEFAULT 3,
    aviso_correo VARCHAR(255) NULL,
    aviso_cc VARCHAR(255) NULL,
    notas TEXT NULL,
    usuario_id INT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY (cliente_id, estado),
    KEY (socio_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE socios_prestamo_cuotas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prestamo_id INT NOT NULL,
    numero SMALLINT NOT NULL,
    fecha DATE NOT NULL,
    monto DECIMAL(14,2) NOT NULL,
    KEY (prestamo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE socios_prestamo_pagos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prestamo_id INT NOT NULL,
    cliente_id INT NOT NULL,
    fecha DATE NOT NULL,
    monto DECIMAL(14,2) NOT NULL,
    metodo VARCHAR(20) NOT NULL DEFAULT 'transferencia',
    referencia VARCHAR(80) NULL,
    cuenta_id INT NULL,
    movimiento_id INT NULL,
    archivo_adjunto VARCHAR(255) NULL,
    archivo_nombre VARCHAR(255) NULL,
    notas VARCHAR(255) NULL,
    anulado TINYINT(1) NOT NULL DEFAULT 0,
    usuario_id INT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY (prestamo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
