
-- Base de Datos: Sistema de Facturación SaaS
-- Fecha de generación: 2025-06-20 23:19:27

CREATE TABLE clientes_saas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100),
    razon_social VARCHAR(255),
    subdominio VARCHAR(100) UNIQUE,
    rtn VARCHAR(25),
    email VARCHAR(100),
    telefono VARCHAR(20),
    direccion VARCHAR(250) NOT NULL,
    logo_url VARCHAR(255),
    estado ENUM('activo', 'inactivo') DEFAULT 'activo',
    tipo_plan VARCHAR(50) DEFAULT 'basico',
    certificado_digital VARCHAR(255),
    fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT,
    nombre VARCHAR(100),
    correo VARCHAR(100) UNIQUE,
    clave VARCHAR(255),
    rol ENUM('admin', 'facturador', 'lector'),
    FOREIGN KEY (cliente_id) REFERENCES clientes_saas(id) ON DELETE CASCADE
);

CREATE TABLE establecimientos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT,
    nombre VARCHAR(100),
    codigo_establecimiento VARCHAR(3),
    codigo_punto VARCHAR(3),
    FOREIGN KEY (cliente_id) REFERENCES clientes_saas(id) ON DELETE CASCADE
);

CREATE TABLE cai_rangos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT,
    establecimiento_id INT,
    cai VARCHAR(45),
    rango_inicio INT,
    rango_fin INT,
    correlativo_actual INT,
    fecha_recepcion DATE,
    fecha_limite DATE,
    FOREIGN KEY (cliente_id) REFERENCES clientes_saas(id) ON DELETE CASCADE,
    FOREIGN KEY (establecimiento_id) REFERENCES establecimientos(id) ON DELETE CASCADE
);

CREATE TABLE clientes_factura (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT,
    nombre VARCHAR(100),
    rtn VARCHAR(25),
    direccion TEXT,
    FOREIGN KEY (cliente_id) REFERENCES clientes_saas(id) ON DELETE CASCADE
);

CREATE TABLE productos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT,
    nombre VARCHAR(100),
    descripcion TEXT,
    precio DECIMAL(10,2),
    aplica_isv TINYINT(1),
    FOREIGN KEY (cliente_id) REFERENCES clientes_saas(id) ON DELETE CASCADE
);

CREATE TABLE facturas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT,
    cai_id INT,
    receptor_id INT,
    correlativo VARCHAR(25),
    fecha_emision DATETIME,
    subtotal DECIMAL(10,2),
    isv_15 DECIMAL(10,2),
    isv_18 DECIMAL(10,2),
    total DECIMAL(10,2),
    pdf_url VARCHAR(255),
    FOREIGN KEY (cliente_id) REFERENCES clientes_saas(id) ON DELETE CASCADE,
    FOREIGN KEY (cai_id) REFERENCES cai_rangos(id),
    FOREIGN KEY (receptor_id) REFERENCES clientes_factura(id)
);

CREATE TABLE factura_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    factura_id INT,
    producto_id INT,
    cantidad INT,
    precio_unitario DECIMAL(10,2),
    subtotal DECIMAL(10,2),
    isv_aplicado DECIMAL(10,2),
    FOREIGN KEY (factura_id) REFERENCES facturas(id) ON DELETE CASCADE,
    FOREIGN KEY (producto_id) REFERENCES productos(id)
);

CREATE TABLE configuracion_sistema (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre_empresa VARCHAR(150),
    rtn VARCHAR(25),
    telefono VARCHAR(25),
    correo VARCHAR(100),
    direccion TEXT,
    certificador_nombre VARCHAR(150),
    certificador_rtn VARCHAR(25),
    numero_certificado VARCHAR(50),
    footer_factura TEXT
);
