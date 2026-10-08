-- Imprenta de cada CAI: los talonarios impresos (Gráficos de Occidente) tienen otra imprenta y otro certificado que las facturas
-- del sistema. Si se dejan vacíos, la factura usa la imprenta/certificador general de la configuración.
ALTER TABLE cai_rangos ADD COLUMN IF NOT EXISTS imprenta_nombre VARCHAR(150) NULL AFTER numero_certificado;
ALTER TABLE cai_rangos ADD COLUMN IF NOT EXISTS imprenta_rtn VARCHAR(20) NULL AFTER imprenta_nombre;
ALTER TABLE cai_rangos ADD COLUMN IF NOT EXISTS imprenta_telefono VARCHAR(60) NULL AFTER imprenta_rtn;
