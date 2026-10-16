<?php
// dev/qa/sesion.php — Inicia sesión de QA en el servidor de pruebas (8383, BD *_dev) e imprime NOMBRE=valor de la cookie.
//   APP_DB=dev php dev/qa/sesion.php [correo]
if (getenv('APP_DB') !== 'dev') exit("Ejecuta con APP_DB=dev\n");
$run = __DIR__ . '/../tests/run.php';
$src = file($run);
$ini = $fin = 0;
foreach ($src as $i => $l) { if (!$ini && str_starts_with($l, 'const BASE')) $ini = $i; if (str_starts_with($l, 'function suite(')) { $fin = $i; break; } }
require __DIR__ . '/../../includes/db.php';
eval(str_replace('__DIR__', var_export(dirname($run), true), implode('', array_slice($src, $ini, $fin - $ini))));
$c = login($argv[1] ?? 'qa.admin@local.test');
foreach (file($c->jar) as $l) if (preg_match('/\t(PHPSESSID)\t(\S+)/', $l, $m)) { echo $m[1], '=', $m[2]; break; }
