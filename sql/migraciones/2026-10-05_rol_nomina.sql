-- Rol «nomina»: solo Personal (colaboradores, pagos de nómina, préstamos, bonos, viáticos y roles de pago),
-- con CRUD completo de esa sección y sin acceso a facturación, finanzas ni configuración.
ALTER TABLE usuarios
    MODIFY rol ENUM('superadmin','admin','facturador','lector','nomina') NOT NULL DEFAULT 'lector';
