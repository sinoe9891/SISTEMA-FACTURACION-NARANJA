-- Permisos del menú lateral por rol (los configura el superadmin en Configuración → Permisos por rol).
-- Una fila = una opción del menú oculta o visible para un rol. Sin fila = se aplica lo de siempre (visible).
CREATE TABLE IF NOT EXISTS permisos_menu (
    rol VARCHAR(20) NOT NULL,
    pagina VARCHAR(60) NOT NULL,
    permitido TINYINT(1) NOT NULL DEFAULT 1,
    actualizado_por INT NULL,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (rol, pagina)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
