-- Pago de ISV al SAR: el impuesto cobrado a los clientes que se entrega al SAR no es gasto de la empresa
-- (no se cuenta como ingreso al facturar, así que tampoco resta al pagarlo). Multas, intereses, ISR y honorarios sí son gasto.
ALTER TABLE gastos MODIFY naturaleza ENUM('gasto','capital','activo','anticipo','isv') NOT NULL DEFAULT 'gasto';
