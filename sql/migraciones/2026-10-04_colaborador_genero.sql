-- Género del colaborador (opcional): define el ícono de su avatar. NULL = sin indicar.
ALTER TABLE colaboradores ADD COLUMN IF NOT EXISTS genero ENUM('masculino','femenino') NULL AFTER apellido;
