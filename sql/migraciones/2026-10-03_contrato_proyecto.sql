-- Tipo de contrato "proyecto": trabajo por etapas con un valor total (contratos.monto = total sin ISV),
-- sin cobro mensual. Los pagos por etapa se registran como anticipos (contratos_anticipos) y se aplican
-- a la factura cuando se emite.
ALTER TABLE contratos MODIFY tipo_contrato ENUM('estandar','periodico','rotativo','sin_factura','proyecto') NOT NULL DEFAULT 'estandar';
