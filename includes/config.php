<?php
// config.php

// Entorno de pruebas: el servidor de desarrollo se arranca con APP_DB=dev y usa la BD
// local (copia de producción). Apache/XAMPP no define esa variable, así que sigue igual.
if (getenv('APP_DB') === 'dev') {
    define('DB_HOST', '127.0.0.1');
    define('DB_PORT', 3309);
    define('DB_NAME', 'facturacion_saas_dev');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('BASE_URL', 'http://localhost:8383/');
    return;
}
// define('DB_HOST', 'localhost');
// define('DB_NAME', 'facturacion_saas');
// define('DB_USER', 'root');
// define('DB_PASS', ''); // Cambia esto por tu contraseña si aplica

// Para trabajar contra la BD de producción desde local:
define('DB_HOST', '50.62.222.52');
define('DB_PORT', 3306);
define('DB_NAME', 'facturacion_saas');
define('DB_USER', 'i9263325_wp4');
define('DB_PASS', 'V.8IpnhLPEFEu9zBdco55');
// Ruta base del sistema
define('BASE_URL', 'http://localhost/facturacion-saas/'); // Ajusta al dominio real