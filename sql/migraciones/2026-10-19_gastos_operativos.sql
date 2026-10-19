-- Gastos operativos de clientes: un rol («operativo») registra y justifica lo que se gasta por cada cliente asignado
-- (pauta en Google/Meta/TikTok, combustible, alimentación en visitas…), en lempiras o dólares, con tarjeta, fechas de la
-- pauta y comprobantes o reportes adjuntos. El administrador lo aprueba y puede pasarlo a Gastos (para cobrárselo al cliente).
ALTER TABLE usuarios MODIFY rol ENUM('superadmin','admin','facturador','lector','nomina','operativo') NOT NULL DEFAULT 'lector';
ALTER TABLE usuarios ADD COLUMN colaborador_id INT UNSIGNED NULL;

CREATE TABLE usuarios_clientes (
    usuario_id INT NOT NULL,
    receptor_id INT NOT NULL,
    asignado_por INT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (usuario_id, receptor_id),
    KEY (receptor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE gastos_op_tipos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    nombre VARCHAR(80) NOT NULL,
    icono VARCHAR(40) NOT NULL DEFAULT 'bi-tag',
    es_pauta TINYINT(1) NOT NULL DEFAULT 0,
    categoria_id INT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    orden SMALLINT NOT NULL DEFAULT 0,
    UNIQUE KEY (cliente_id, nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE gastos_operativos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    receptor_id INT NOT NULL,
    tipo_id INT NOT NULL,
    fecha DATE NOT NULL,
    descripcion VARCHAR(300) NOT NULL,
    proveedor VARCHAR(150) NULL,
    factura_ref VARCHAR(100) NULL,
    moneda CHAR(3) NOT NULL DEFAULT 'HNL',
    monto DECIMAL(14,2) NOT NULL,
    tasa_cambio DECIMAL(10,4) NULL,
    monto_lempiras DECIMAL(14,2) NOT NULL,
    metodo_pago ENUM('tarjeta','efectivo','transferencia','propio','otro') NOT NULL DEFAULT 'tarjeta',
    tarjeta_id INT UNSIGNED NULL,
    campana VARCHAR(200) NULL,
    pauta_inicio DATE NULL,
    pauta_fin DATE NULL,
    cobrar_cliente TINYINT(1) NOT NULL DEFAULT 1,
    notas TEXT NULL,
    estado ENUM('pendiente','aprobado','rechazado') NOT NULL DEFAULT 'pendiente',
    motivo_rechazo VARCHAR(300) NULL,
    revisado_por INT NULL,
    revisado_en DATETIME NULL,
    gasto_id INT NULL,
    usuario_id INT NOT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY (cliente_id, fecha),
    KEY (receptor_id),
    KEY (usuario_id),
    KEY (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE gastos_op_archivos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    gasto_op_id INT NOT NULL,
    cliente_id INT NOT NULL,
    tipo ENUM('comprobante','reporte','otro') NOT NULL DEFAULT 'comprobante',
    archivo VARCHAR(255) NOT NULL,
    nombre VARCHAR(255) NOT NULL,
    usuario_id INT NOT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY (gasto_op_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE gastos_op_bitacora (
    id INT AUTO_INCREMENT PRIMARY KEY,
    gasto_op_id INT NOT NULL,
    usuario_id INT NOT NULL,
    accion VARCHAR(40) NOT NULL,
    detalle VARCHAR(500) NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY (gasto_op_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
