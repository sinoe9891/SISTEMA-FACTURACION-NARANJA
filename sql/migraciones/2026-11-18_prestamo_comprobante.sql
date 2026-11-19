-- Comprobante (foto o PDF) de adelantos, bonos, viáticos, multas y préstamos de colaboradores
ALTER TABLE colaborador_prestamos ADD COLUMN IF NOT EXISTS archivo_adjunto VARCHAR(255) NULL;
ALTER TABLE colaborador_prestamos ADD COLUMN IF NOT EXISTS archivo_nombre VARCHAR(255) NULL;
