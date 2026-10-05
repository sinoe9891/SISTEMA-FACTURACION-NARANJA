-- Fecha de corte de los avisos de pago automáticos (cuenta «nomina»): el cron envía los avisos pendientes
-- de pagos con fecha desde aquí hasta hoy (se pone al día si un día no corrió). Los pagos anteriores
-- no se avisan automáticamente. NULL = solo los pagos con fecha del mismo día.
ALTER TABLE configuracion_correo
    ADD COLUMN IF NOT EXISTS aviso_pago_desde DATE NULL AFTER aviso_pago_hora;
