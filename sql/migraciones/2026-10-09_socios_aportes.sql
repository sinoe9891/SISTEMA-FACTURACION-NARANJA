-- Aportes de socios a la empresa (capital): dinero que un socio pone en la empresa. Los retiros de socio son gastos con
-- naturaleza «retiro». La página Socios muestra ambos; el Balance los separa dentro del patrimonio.
CREATE TABLE IF NOT EXISTS socios_aportes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    fecha DATE NOT NULL,
    socio VARCHAR(150) NOT NULL,
    monto DECIMAL(14,2) NOT NULL,
    metodo VARCHAR(20) NOT NULL DEFAULT 'transferencia',
    referencia VARCHAR(80) NULL,
    cuenta_id INT NULL,
    movimiento_id INT NULL,
    notas TEXT NULL,
    archivo_adjunto VARCHAR(255) NULL,
    anulado TINYINT(1) NOT NULL DEFAULT 0,
    usuario_id INT NOT NULL DEFAULT 0,
    creado_en TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_socios_aportes (cliente_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Socios de cada empresa (para elegirlos al registrar un aporte y para asignar los retiros)
CREATE TABLE IF NOT EXISTS socios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_socio (cliente_id, nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
