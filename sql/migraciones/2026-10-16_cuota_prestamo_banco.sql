-- Pago de una cuota de préstamo de un colaborador: depósito en Bancos (opcional), para poder anularlo si la cuota vuelve a pendiente
ALTER TABLE colaborador_prestamo_cuotas ADD COLUMN movimiento_id INT NULL;
