-- Contratos en borrador: se guardan sin afectar nada (no se facturan ni cobran, no cuentan en reportes ni proyección).
ALTER TABLE contratos MODIFY estado ENUM('activo','vencido','cancelado','pausado','borrador') DEFAULT 'activo';
