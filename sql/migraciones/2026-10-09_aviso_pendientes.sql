-- Resumen diario a gerencia (cuenta Facturación): contratos sin facturar, facturas sin enviar al cliente y facturas vencidas.
-- Se envía una vez al día a la hora indicada (hora de Honduras), solo si hay algo pendiente.
ALTER TABLE configuracion_correo
    ADD COLUMN aviso_pendientes_auto TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN aviso_pendientes_hora TINYINT NOT NULL DEFAULT 8,
    ADD COLUMN aviso_pendientes_dias VARCHAR(10) NOT NULL DEFAULT 'habiles',
    ADD COLUMN aviso_pendientes_para VARCHAR(255) NULL;
