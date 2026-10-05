-- Restablecer contraseña por correo: enlaces de un solo uso que vencen en 60 minutos.
-- Se guarda solo el hash SHA-256 del token (el token real solo viaja en el correo).
CREATE TABLE IF NOT EXISTS clave_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expira DATETIME NOT NULL,
    usado_en DATETIME NULL,
    ip VARCHAR(45) NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_token (token_hash),
    KEY idx_usuario (usuario_id, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
