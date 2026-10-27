-- Vendedores: cuenta bancaria para pagarles la comisión (banco y número).
ALTER TABLE vendedores ADD COLUMN cuenta_banco VARCHAR(150) NULL AFTER email;
