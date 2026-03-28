<?php

/**
 * debug_contrato13.php — Diagnóstico del contrato DIDEPROB
 * Coloca este archivo en: clientes/naranjaymedia/
 * Accede vía: http://tudominio/clientes/naranjaymedia/debug_contrato13.php
 * BORRAR DESPUÉS DE VERIFICAR
 */
require_once '../../includes/db.php';
require_once '../../includes/session.php';

header('Content-Type: text/html; charset=utf-8');

$cliente_id = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);

echo "<pre style='font-family:monospace;font-size:13px;padding:20px'>";
echo "=== DIAGNÓSTICO CONTRATO 13 (DIDEPROB) ===\n\n";
echo "cliente_id en sesión: $cliente_id\n";
echo "Fecha hoy: " . date('Y-m-d') . "\n";
echo "Primer día del mes actual: " . date('Y-m-01') . "\n\n";

// 1. Datos del contrato
$stmtC = $pdo->prepare("SELECT id, cliente_id, receptor_id, fecha_inicio, fecha_fin, tipo_contrato, estado FROM contratos WHERE id = 13");
$stmtC->execute();
$ct = $stmtC->fetch(PDO::FETCH_ASSOC);
echo "--- CONTRATO 13 ---\n";
print_r($ct);

// 2. Facturas del contrato con COALESCE
$stmtF = $pdo->prepare("
    SELECT id, correlativo, DATE(fecha_emision) as fecha, 
           periodo_mes, periodo_anio,
           COALESCE(periodo_mes, MONTH(fecha_emision)) AS mes_ef,
           COALESCE(periodo_anio, YEAR(fecha_emision))  AS anio_ef,
           CONCAT(COALESCE(periodo_anio,YEAR(fecha_emision)),'-',COALESCE(periodo_mes,MONTH(fecha_emision))) AS clave
    FROM facturas 
    WHERE contrato_id = 13 AND estado = 'emitida'
    ORDER BY anio_ef, mes_ef
");
$stmtF->execute();
$facturas = $stmtF->fetchAll(PDO::FETCH_ASSOC);
echo "\n--- FACTURAS (con COALESCE) ---\n";
echo "Total: " . count($facturas) . "\n";
$claves = [];
foreach ($facturas as $f) {
    echo "  {$f['correlativo']} | fecha:{$f['fecha']} | pm:{$f['periodo_mes']} | pa:{$f['periodo_anio']} → clave: {$f['clave']}\n";
    $claves[] = $f['clave'];
}

// 3. Simular el loop de periodos_pendientes
echo "\n--- SIMULACIÓN LOOP periodos_pendientes ---\n";
$fecha_inicio = new DateTime($ct['fecha_inicio']);
$cursor = clone $fecha_inicio;
$cursor->modify('first day of this month');
$limite = new DateTime('first day of this month');
echo "fecha_inicio: " . $ct['fecha_inicio'] . "\n";
echo "cursor start: " . $cursor->format('Y-m-d') . "\n";
echo "limite (excl): " . $limite->format('Y-m-d') . "\n\n";

$pendientes = [];
while ($cursor < $limite) {
    $mes  = (int)$cursor->format('n');
    $anio = (int)$cursor->format('Y');
    $key  = $anio . '-' . $mes;
    $cubierto = in_array($key, $claves);
    echo "  Mes: $key → " . ($cubierto ? "✓ CUBIERTO" : "✗ PENDIENTE") . "\n";
    if (!$cubierto) $pendientes[] = $key;
    $cursor->modify('+1 month');
}

echo "\n--- RESULTADO ---\n";
echo "Períodos pendientes: " . count($pendientes) . "\n";
if ($pendientes) {
    echo "Meses sin factura: " . implode(', ', $pendientes) . "\n";
} else {
    echo "¡NINGUNO! El contrato está al día.\n";
    echo "\nSi contratos.php SIGUE mostrando '1 atrasado', el problema\n";
    echo "es que el archivo PHP en producción es diferente al que ves.\n";
}

// 4. Ver qué clave usa el stmtBilled con cliente_id de sesión
echo "\n--- stmtBilled con cliente_id=$cliente_id ---\n";
$stmtB = $pdo->prepare("
    SELECT contrato_id,
           COALESCE(periodo_mes, MONTH(fecha_emision)) AS mes,
           COALESCE(periodo_anio, YEAR(fecha_emision))  AS anio,
           CONCAT(COALESCE(periodo_anio,YEAR(fecha_emision)),'-',COALESCE(periodo_mes,MONTH(fecha_emision))) AS clave
    FROM facturas
    WHERE cliente_id = ? AND estado = 'emitida' AND contrato_id = 13
");
$stmtB->execute([$cliente_id]);
$billed = $stmtB->fetchAll(PDO::FETCH_ASSOC);
echo "Filas encontradas con cliente_id=$cliente_id: " . count($billed) . "\n";
foreach ($billed as $b) {
    echo "  contrato:{$b['contrato_id']} clave:{$b['clave']}\n";
}

if (count($billed) === 0) {
    echo "\n⚠ PROBLEMA: stmtBilled no encuentra las facturas!\n";
    echo "Verificando con todos los cliente_id...\n";
    $stmtAll = $pdo->prepare("SELECT DISTINCT cliente_id, COUNT(*) as n FROM facturas WHERE contrato_id=13 AND estado='emitida' GROUP BY cliente_id");
    $stmtAll->execute();
    print_r($stmtAll->fetchAll(PDO::FETCH_ASSOC));
}

echo "\n=== FIN DIAGNÓSTICO ===\n";
echo "</pre>";
