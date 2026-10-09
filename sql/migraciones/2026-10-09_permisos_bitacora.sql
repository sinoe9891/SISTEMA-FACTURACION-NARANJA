-- Bitácora de Configuración → Permisos: cada interruptor que el superadmin enciende o apaga (quién, cuándo, antes y después).
CREATE TABLE IF NOT EXISTS permisos_bitacora (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rol VARCHAR(20) NOT NULL,
    pagina VARCHAR(60) NOT NULL,
    antes TINYINT(1) NOT NULL,
    despues TINYINT(1) NOT NULL,
    usuario_id INT NULL,
    ip VARCHAR(45) NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_fecha (creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
