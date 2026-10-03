<?php
// db.php

require_once 'config.php';

// Conexión persistente solo cuando la BD es remota (desarrollo local contra el servidor):
// abrir la conexión por internet cuesta ~400 ms en cada carga. En producción la BD es
// local (localhost) y se mantiene la conexión normal para no agotar conexiones del hosting.
$__dbRemota = !in_array(strtolower(DB_HOST), ['localhost', '127.0.0.1', '::1'], true);

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";port=".(defined('DB_PORT') ? DB_PORT : 3306).";dbname=".DB_NAME, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8",
        PDO::ATTR_PERSISTENT => $__dbRemota,
    ]);
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}
?>
