-- Verificación del certificado SSL del servidor SMTP. En hosting compartido el certificado
-- suele estar a nombre del servidor y no del subdominio del correo: entonces se desactiva (0).
ALTER TABLE configuracion_correo ADD COLUMN IF NOT EXISTS verificar_ssl TINYINT(1) NOT NULL DEFAULT 0 AFTER seguridad;
