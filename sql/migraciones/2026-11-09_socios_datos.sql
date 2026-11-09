-- Socios: datos completos (identidad, teléfono, % de participación y notas)
ALTER TABLE socios
    ADD COLUMN identidad VARCHAR(30) NULL AFTER email,
    ADD COLUMN telefono VARCHAR(40) NULL AFTER identidad,
    ADD COLUMN participacion DECIMAL(5,2) NULL AFTER telefono,
    ADD COLUMN notas VARCHAR(255) NULL AFTER participacion;
