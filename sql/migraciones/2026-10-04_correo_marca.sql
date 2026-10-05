-- Logo (PNG/JPG: Gmail no muestra SVG) y enlace que aparecen en los correos de la empresa.
ALTER TABLE configuracion_correo
    ADD COLUMN IF NOT EXISTS logo_url VARCHAR(300) NULL AFTER copia_oculta,
    ADD COLUMN IF NOT EXISTS enlace_url VARCHAR(300) NULL AFTER logo_url;
