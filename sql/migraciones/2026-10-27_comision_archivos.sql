-- Comisiones por venta: varios comprobantes por pago (el primero queda también en el gasto)
CREATE TABLE IF NOT EXISTS comision_archivos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    comision_id INT NOT NULL,
    archivo VARCHAR(255) NOT NULL,
    nombre VARCHAR(255) NOT NULL,
    usuario_id INT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY comision_id (comision_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
