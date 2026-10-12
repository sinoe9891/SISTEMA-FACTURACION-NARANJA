-- Categorías de cotización (Impresión, Diseño, Desarrollo web…): cada una con su «Incluye», sus «Condiciones» y su vigencia.
-- Al elegir la categoría en una cotización se cargan esos textos.
CREATE TABLE IF NOT EXISTS cotizaciones_categorias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    nombre VARCHAR(100) NOT NULL,
    icono VARCHAR(40) NULL,
    incluye TEXT NULL,
    condiciones TEXT NULL,
    vigencia_dias SMALLINT UNSIGNED NULL,
    orden INT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_cot_categoria (cliente_id, nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE cotizaciones ADD COLUMN categoria_id INT NULL AFTER receptor_id;
