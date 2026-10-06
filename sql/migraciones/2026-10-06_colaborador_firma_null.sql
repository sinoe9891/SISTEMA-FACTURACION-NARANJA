-- La columna antigua «firma» (sin uso; la firma vive en url_firma) era NOT NULL sin valor por defecto:
-- con sql_mode estricto impedía crear colaboradores. Pasa a opcional.
ALTER TABLE colaboradores MODIFY firma VARCHAR(500) NULL DEFAULT NULL;
