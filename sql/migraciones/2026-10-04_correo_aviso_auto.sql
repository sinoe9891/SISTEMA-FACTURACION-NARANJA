-- Aviso automático de pago a colaboradores: el cron (cron/avisos_pago.php) lo envía el día del pago
-- a partir de la hora indicada (hora de Honduras), solo si no se envió antes de forma manual.
ALTER TABLE configuracion_correo
    ADD COLUMN IF NOT EXISTS aviso_pago_auto TINYINT(1) NOT NULL DEFAULT 1 AFTER activo,
    ADD COLUMN IF NOT EXISTS aviso_pago_hora TINYINT UNSIGNED NOT NULL DEFAULT 7 AFTER aviso_pago_auto;
