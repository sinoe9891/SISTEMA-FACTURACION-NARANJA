-- Préstamos a socios: uno o varios comprobantes por préstamo y por pago (el primero también queda en el movimiento del banco).
CREATE TABLE socios_prestamo_archivos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    prestamo_id INT NOT NULL,
    pago_id INT NULL,
    archivo VARCHAR(255) NOT NULL,
    nombre VARCHAR(255) NOT NULL,
    usuario_id INT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY (prestamo_id),
    KEY (pago_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT INTO socios_prestamo_archivos (cliente_id, prestamo_id, archivo, nombre, usuario_id)
    SELECT cliente_id, id, archivo_adjunto, COALESCE(archivo_nombre, archivo_adjunto), usuario_id FROM socios_prestamos WHERE archivo_adjunto IS NOT NULL;
INSERT INTO socios_prestamo_archivos (cliente_id, prestamo_id, pago_id, archivo, nombre, usuario_id)
    SELECT cliente_id, prestamo_id, id, archivo_adjunto, COALESCE(archivo_nombre, archivo_adjunto), usuario_id FROM socios_prestamo_pagos WHERE archivo_adjunto IS NOT NULL;
