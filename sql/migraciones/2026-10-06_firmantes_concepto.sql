-- 1) Concepto de pago de cada colaborador para el boucher («Pago Gerente General», «Pago por servicios de diseño gráfico»…).
--    El sistema le agrega la quincena y el mes. NULL = se arma con el puesto.
ALTER TABLE colaboradores ADD COLUMN IF NOT EXISTS concepto_pago VARCHAR(200) NULL AFTER puesto;

-- 2) Firmantes de los documentos (bouchers y recibos): Elaborado por, Revisado, Autorizado y Vo.Bo.
CREATE TABLE IF NOT EXISTS documento_firmantes (
    cliente_id INT NOT NULL,
    rol ENUM('elaborado','revisado','autorizado','vobo') NOT NULL,
    nombre VARCHAR(150) NULL,
    cargo VARCHAR(150) NULL,
    firma VARCHAR(255) NULL,                -- ruta relativa en includes/uploads/firmas/
    actualizado_por INT NULL,
    actualizado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (cliente_id, rol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
