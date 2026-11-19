-- Préstamos a socios en dólares: el préstamo, sus cuotas y sus pagos van en la moneda del préstamo (la de la cuenta de donde sale)
ALTER TABLE socios_prestamos ADD COLUMN IF NOT EXISTS moneda VARCHAR(3) NOT NULL DEFAULT 'HNL';
ALTER TABLE socios_prestamos ADD COLUMN IF NOT EXISTS tasa_cambio DECIMAL(10,4) NULL;
