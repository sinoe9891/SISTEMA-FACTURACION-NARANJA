-- Documentos de la empresa (p. ej. Constancia de pago a cuenta del SAR) con fecha de vencimiento,
-- que se pueden adjuntar a los cobros por correo. Aviso cuando vencen (panel y correo).
CREATE TABLE IF NOT EXISTS empresa_documentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    archivo VARCHAR(255) NOT NULL,
    archivo_nombre VARCHAR(255) NOT NULL,
    mime VARCHAR(80) NULL,
    tamano INT NULL,
    fecha_emision DATE NULL,
    fecha_vencimiento DATE NULL,
    adjuntar_por_defecto TINYINT(1) NOT NULL DEFAULT 0,
    notas VARCHAR(255) NULL,
    usuario_id INT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_cliente (cliente_id),
    KEY idx_vence (fecha_vencimiento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copia del documento tal como se envió en cada cobro
CREATE TABLE IF NOT EXISTS cobros_programados_documentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cobro_id INT NOT NULL,
    documento_id INT NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    archivo VARCHAR(255) NOT NULL,
    KEY idx_cobro (cobro_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
