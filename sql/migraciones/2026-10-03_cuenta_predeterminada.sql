-- Cuenta bancaria predeterminada: queda preseleccionada al registrar cobros, abonos y pagos.
-- Solo una por empresa (lo controla includes/banco_accion.php, acción "predeterminar").
ALTER TABLE cuentas_bancarias ADD COLUMN IF NOT EXISTS predeterminada TINYINT(1) NOT NULL DEFAULT 0 AFTER activa;
