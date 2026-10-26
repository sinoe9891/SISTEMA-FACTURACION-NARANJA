CREATE TABLE IF NOT EXISTS tarjetas_pauta_clientes (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 cliente_id INT NOT NULL,
 receptor_id INT NOT NULL,
 nombre VARCHAR(80) NOT NULL,
 ultimos CHAR(4) NOT NULL,
 UNIQUE KEY tarjeta_cliente (cliente_id, receptor_id, nombre, ultimos)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
