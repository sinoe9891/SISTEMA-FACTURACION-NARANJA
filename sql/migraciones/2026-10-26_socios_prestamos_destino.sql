-- Préstamos a socios: cuenta de quien recibe el préstamo (donde se depositó). En correos y PDF se muestra «Entró a la cuenta …».
ALTER TABLE socios_prestamos ADD COLUMN cuenta_destino VARCHAR(150) NULL AFTER referencia;
