-- Cuenta bancaria del colaborador (donde se le transfiere). El banco ya existía (colaboradores.banco).
ALTER TABLE colaboradores
    ADD COLUMN IF NOT EXISTS tipo_cuenta ENUM('ahorro','cheques') NULL AFTER banco,
    ADD COLUMN IF NOT EXISTS numero_cuenta VARCHAR(40) NULL AFTER tipo_cuenta;
