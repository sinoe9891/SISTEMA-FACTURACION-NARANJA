-- Préstamos a socios: a nombre de quién salen los correos y el PDF (p. ej. un tercero que devuelve el dinero).
-- Internamente el préstamo sigue siendo del socio.
ALTER TABLE socios_prestamos ADD COLUMN deudor_nombre VARCHAR(150) NULL AFTER socio_id;
