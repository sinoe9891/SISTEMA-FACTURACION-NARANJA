-- Gastos operativos: el proveedor se elige del catálogo (Proveedores), con alta rápida.
ALTER TABLE gastos_operativos ADD COLUMN proveedor_id INT NULL AFTER proveedor;
