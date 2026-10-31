-- Clientes: nombre comercial (p. ej. «CARE Internacional Honduras») aparte de la razón social del RTN (va en la factura)
ALTER TABLE clientes_factura ADD COLUMN nombre_comercial VARCHAR(150) NULL AFTER nombre;
