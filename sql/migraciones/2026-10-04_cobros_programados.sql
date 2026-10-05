-- 1) Varias cuentas de envío por empresa: "nomina" (avisos de pago) y "facturacion" (cobros con facturas).
ALTER TABLE configuracion_correo ADD COLUMN IF NOT EXISTS perfil VARCHAR(20) NOT NULL DEFAULT 'nomina' AFTER cliente_id;
ALTER TABLE configuracion_correo DROP PRIMARY KEY, ADD PRIMARY KEY (cliente_id, perfil);

-- 2) Cobros por correo programados: mensaje + facturas en PDF (generadas al programar) + fecha y hora
--    de envío (hora de Honduras). Los envía cron/tareas.php.
CREATE TABLE IF NOT EXISTS cobros_programados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    receptor_id INT NOT NULL,
    tipo VARCHAR(30) NOT NULL DEFAULT 'saldo_pendiente',    -- saldo_pendiente | envio_factura
    para VARCHAR(500) NOT NULL,                             -- uno o varios correos separados por coma
    cc VARCHAR(500) NULL,
    asunto VARCHAR(255) NOT NULL,
    mensaje_html MEDIUMTEXT NOT NULL,
    programado_para DATETIME NOT NULL,
    prueba TINYINT(1) NOT NULL DEFAULT 0,                   -- envío de prueba (a tu correo), no al cliente
    estado ENUM('programado','enviando','enviado','error','cancelado') NOT NULL DEFAULT 'programado',
    intentos TINYINT UNSIGNED NOT NULL DEFAULT 0,
    error VARCHAR(500) NULL,
    enviado_en DATETIME NULL,
    usuario_id INT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pendientes (estado, programado_para),
    KEY idx_cliente (cliente_id, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS cobros_programados_facturas (
    cobro_id INT NOT NULL,
    factura_id INT NOT NULL,
    archivo VARCHAR(255) NOT NULL,                          -- PDF guardado en includes/uploads/cobros/
    PRIMARY KEY (cobro_id, factura_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
