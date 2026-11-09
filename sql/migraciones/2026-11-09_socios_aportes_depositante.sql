-- Aportes de socios: nombre libre de quien depositó (p. ej. un cliente que pagó a nombre del socio); el socio va siempre de la lista
ALTER TABLE socios_aportes ADD COLUMN depositante VARCHAR(150) NULL AFTER socio;
