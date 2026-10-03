-- Límite de intentos fallidos de inicio de sesión y de clave autorizadora.
-- includes/intentos.php la crea sola si no existe; este script es para crearla a mano.
CREATE TABLE IF NOT EXISTS login_intentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    clave VARCHAR(190) NOT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_clave_fecha (clave, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
