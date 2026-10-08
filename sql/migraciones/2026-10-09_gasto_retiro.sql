-- Retiro de socio: dinero de la empresa usado en gastos personales de los socios (o regalado). No es gasto de la empresa:
-- no resta en el Estado de resultados; reduce lo que los socios tienen en la empresa.
ALTER TABLE gastos MODIFY naturaleza ENUM('gasto','capital','activo','anticipo','isv','retiro') NOT NULL DEFAULT 'gasto';
