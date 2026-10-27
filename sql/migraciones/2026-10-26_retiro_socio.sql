-- Retiros de socio: el gasto guarda de qué socio es (antes se adivinaba por el nombre en la descripción o las notas).
ALTER TABLE gastos ADD COLUMN socio_id INT NULL;
