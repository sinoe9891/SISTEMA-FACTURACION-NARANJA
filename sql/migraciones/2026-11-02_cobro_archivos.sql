-- Cobros de facturas: uno o varios comprobantes por cobro (el primero queda también en cobros_factura.archivo_adjunto)
CREATE TABLE IF NOT EXISTS cobro_archivos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    cobro_id INT NOT NULL,
    archivo VARCHAR(255) NOT NULL,
    nombre VARCHAR(255) NOT NULL,
    usuario_id INT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY cobro_id (cobro_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
