-- Correo saliente (SMTP) por empresa y bitácora de envíos.
-- La contraseña SMTP se guarda cifrada (includes/correo.php); nunca se muestra en pantalla.
CREATE TABLE IF NOT EXISTS configuracion_correo (
    cliente_id INT NOT NULL PRIMARY KEY,
    host VARCHAR(150) NOT NULL,
    puerto SMALLINT UNSIGNED NOT NULL DEFAULT 587,
    seguridad ENUM('tls','ssl','ninguna') NOT NULL DEFAULT 'tls',   -- tls = STARTTLS (587), ssl = SMTPS (465)
    usuario VARCHAR(150) NOT NULL,
    clave_cifrada TEXT NULL,
    remitente_email VARCHAR(150) NOT NULL,
    remitente_nombre VARCHAR(150) NULL,
    responder_a VARCHAR(150) NULL,
    copia_oculta VARCHAR(150) NULL,                                  -- copia de cada envío para archivo interno
    activo TINYINT(1) NOT NULL DEFAULT 1,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS correos_enviados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    tipo VARCHAR(40) NOT NULL,                 -- prueba | pago_colaborador
    referencia_id INT NULL,                    -- p. ej. id del gasto (pago)
    destinatario VARCHAR(150) NOT NULL,
    asunto VARCHAR(255) NOT NULL,
    estado ENUM('enviado','error') NOT NULL,
    error VARCHAR(500) NULL,
    usuario_id INT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cliente (cliente_id, creado_en),
    KEY idx_ref (tipo, referencia_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
