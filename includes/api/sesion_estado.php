<?php
/**
 * sesion_estado.php — Segundos que le quedan a la sesión por inactividad (lo usa el aviso de cierre del header).
 *
 * GET                → {restante}; no cuenta como actividad
 * GET ?renovar=1     → {restante}; renueva la sesión («Seguir conectado» y la actividad del usuario)
 * Sin sesión o expirada → 401 {error, expirada?}
 *
 * Ruta: includes/api/sesion_estado.php
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../session.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['restante' => sesionSegundosRestantes(isset($_SESSION['ultima_actividad']) ? (int)$_SESSION['ultima_actividad'] : null)]);
