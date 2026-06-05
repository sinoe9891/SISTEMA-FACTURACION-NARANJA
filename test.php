<?php
// test.php — diagnóstico de entorno para cPanel
// ELIMINAR este archivo en producción después de usarlo

error_reporting(E_ALL);
ini_set('display_errors', 1);

$db_host = '50.62.222.52'; // IP del servidor cPanel (remoto)
$db_port = 3306;
$db_name = 'facturacion_saas'; // ajusta si el prefijo del nombre cambia en cPanel
$db_user = 'i9263325_wp4';
$db_pass = 'V.8IpnhLPEFEu9zBdco55';

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Test de Entorno</title>
    <style>
        body {
            font-family: monospace;
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 20px;
        }

        h2 {
            color: #569cd6;
            border-bottom: 1px solid #444;
            padding-bottom: 6px;
        }

        .ok {
            color: #4ec9b0;
        }

        .fail {
            color: #f44747;
        }

        .warn {
            color: #dcdcaa;
        }

        table {
            border-collapse: collapse;
            width: 100%;
            margin-bottom: 20px;
        }

        td,
        th {
            border: 1px solid #444;
            padding: 6px 12px;
            text-align: left;
        }

        th {
            background: #2d2d2d;
            color: #9cdcfe;
        }

        pre {
            background: #2d2d2d;
            padding: 12px;
            border-radius: 4px;
            overflow-x: auto;
        }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 0.85em;
        }

        .badge-ok {
            background: #1e3a2f;
            color: #4ec9b0;
        }

        .badge-fail {
            background: #3a1e1e;
            color: #f44747;
        }

        .badge-warn {
            background: #3a3a1e;
            color: #dcdcaa;
        }
    </style>
</head>

<body>

    <h2>PHP — Version y entorno</h2>
    <table>
        <tr>
            <th>Clave</th>
            <th>Valor</th>
        </tr>
        <tr>
            <td>PHP Version</td>
            <td><?= PHP_VERSION ?></td>
        </tr>
        <tr>
            <td>PHP SAPI</td>
            <td><?= php_sapi_name() ?></td>
        </tr>
        <tr>
            <td>Sistema operativo</td>
            <td><?= PHP_OS ?></td>
        </tr>
        <tr>
            <td>Servidor</td>
            <td><?= $_SERVER['SERVER_SOFTWARE'] ?? 'N/A' ?></td>
        </tr>
        <tr>
            <td>document_root</td>
            <td><?= $_SERVER['DOCUMENT_ROOT'] ?? 'N/A' ?></td>
        </tr>
        <tr>
            <td>php.ini cargado</td>
            <td><?= php_ini_loaded_file() ?: 'No encontrado' ?></td>
        </tr>
        <tr>
            <td>display_errors</td>
            <td><?= ini_get('display_errors') ? 'On' : 'Off' ?></td>
        </tr>
        <tr>
            <td>error_reporting</td>
            <td><?= ini_get('error_reporting') ?></td>
        </tr>
        <tr>
            <td>max_execution_time</td>
            <td><?= ini_get('max_execution_time') ?>s</td>
        </tr>
        <tr>
            <td>memory_limit</td>
            <td><?= ini_get('memory_limit') ?></td>
        </tr>
        <tr>
            <td>upload_max_filesize</td>
            <td><?= ini_get('upload_max_filesize') ?></td>
        </tr>
        <tr>
            <td>post_max_size</td>
            <td><?= ini_get('post_max_size') ?></td>
        </tr>
        <tr>
            <td>default_charset</td>
            <td><?= ini_get('default_charset') ?></td>
        </tr>
        <tr>
            <td>date.timezone</td>
            <td><?= ini_get('date.timezone') ?: '<span class="warn">No definido</span>' ?></td>
        </tr>
    </table>

    <h2>Extensiones requeridas</h2>
    <?php
    $required = ['pdo', 'pdo_mysql', 'mysqli', 'mbstring', 'json', 'openssl', 'curl', 'zip', 'gd', 'intl', 'xml'];
    echo '<table><tr><th>Extensión</th><th>Estado</th></tr>';
    foreach ($required as $ext) {
        $loaded = extension_loaded($ext);
        $badge = $loaded
            ? '<span class="badge badge-ok">OK</span>'
            : '<span class="badge badge-fail">FALTA</span>';
        echo "<tr><td>{$ext}</td><td>{$badge}</td></tr>";
    }
    echo '</table>';
    ?>

    <h2>Conexion a base de datos (PDO)</h2>
    <?php
    try {
        $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
        $pdo = new PDO($dsn, $db_user, $db_pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $version = $pdo->query('SELECT VERSION() AS v')->fetchColumn();
        echo "<p class='ok'>&#10003; Conexion exitosa</p>";
        echo "<table>
        <tr><th>Parámetro</th><th>Valor</th></tr>
        <tr><td>Host</td><td>{$db_host}</td></tr>
        <tr><td>Base de datos</td><td>{$db_name}</td></tr>
        <tr><td>MySQL Version</td><td>{$version}</td></tr>
    </table>";

        // Tablas existentes
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        echo "<p>Tablas encontradas: <strong>" . count($tables) . "</strong></p>";
        if ($tables) {
            echo "<pre>" . implode("\n", $tables) . "</pre>";
        }
    } catch (PDOException $e) {
        echo "<p class='fail'>&#10007; Error PDO: " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    }
    ?>

    <h2>Conexion a base de datos (MySQLi)</h2>
    <?php
    try {
        mysqli_report(MYSQLI_REPORT_OFF); // evita excepciones para leer el error manualmente
        $mysqli = new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);
        if ($mysqli->connect_errno) {
            echo "<p class='fail'>&#10007; MySQLi Error ({$mysqli->connect_errno}): " . htmlspecialchars($mysqli->connect_error) . "</p>";
        } else {
            echo "<p class='ok'>&#10003; MySQLi conectado correctamente</p>";
            $mysqli->close();
        }
    } catch (Throwable $e) {
        echo "<p class='fail'>&#10007; MySQLi Excepcion: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    ?>

    <h2>Bases de datos accesibles para este usuario</h2>
    <?php
    // Conectar sin especificar BD para listar a cuales tiene acceso
    try {
        $pdo2 = new PDO("mysql:host={$db_host};port={$db_port};charset=utf8mb4", $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $dbs = $pdo2->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
        echo "<p class='ok'>&#10003; Usuario conectado. Bases de datos visibles:</p>";
        echo "<pre>" . implode("\n", $dbs) . "</pre>";
        echo "<p class='warn'>&#9888; Usa exactamente uno de esos nombres en <code>\$db_name</code></p>";
    } catch (PDOException $e) {
        echo "<p class='fail'>&#10007; " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    ?>

    <h2>Stack trace de prueba</h2>
    <?php
    function nivel3()
    {
        throw new RuntimeException("Excepcion de prueba para verificar stack trace");
    }
    function nivel2()
    {
        nivel3();
    }
    function nivel1()
    {
        nivel2();
    }

    try {
        nivel1();
    } catch (Throwable $e) {
        echo "<p class='warn'>Excepcion capturada: <strong>" . htmlspecialchars($e->getMessage()) . "</strong></p>";
        echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    }
    ?>

    <h2>Escritura en disco</h2>
    <?php
    $tmp = __DIR__ . '/test_write_' . time() . '.tmp';
    if (file_put_contents($tmp, 'ok') !== false) {
        unlink($tmp);
        echo "<p class='ok'>&#10003; Escritura en directorio OK</p>";
    } else {
        echo "<p class='fail'>&#10007; Sin permisos de escritura en: " . __DIR__ . "</p>";
    }
    ?>

    <h2>Variables de servidor utiles</h2>
    <?php
    $keys = ['HTTP_HOST', 'SERVER_NAME', 'SERVER_PORT', 'REQUEST_URI', 'HTTPS', 'SERVER_PROTOCOL'];
    echo '<table><tr><th>Key</th><th>Valor</th></tr>';
    foreach ($keys as $k) {
        $val = $_SERVER[$k] ?? '<em>no definida</em>';
        echo "<tr><td>{$k}</td><td>" . htmlspecialchars((string)$val) . "</td></tr>";
    }
    echo '</table>';
    ?>

    <p style="margin-top:40px; color:#555; font-size:0.8em;">
        &#9888; Elimina <code>test.php</code> del servidor antes de pasar a producción.
    </p>

</body>

</html>