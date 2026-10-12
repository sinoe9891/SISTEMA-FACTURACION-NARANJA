-- Avisos configurables de lo que se le cobra a un cliente y de las renovaciones de licencias.
--   gastos.cobrar_desde: desde qué fecha se le cobra al cliente (vacío = de una vez); antes de eso sale como «próximo».
--   gastos.cobrar_aviso_dias y licencias.aviso_dias: cuántos días antes avisa (vacío = 5).
ALTER TABLE gastos ADD COLUMN cobrar_desde DATE NULL AFTER cobrado_factura_id,
                   ADD COLUMN cobrar_aviso_dias SMALLINT UNSIGNED NULL AFTER cobrar_desde;
ALTER TABLE licencias ADD COLUMN aviso_dias SMALLINT UNSIGNED NULL AFTER precio_cliente;
