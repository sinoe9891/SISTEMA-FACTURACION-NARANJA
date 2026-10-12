<?php
// Excel de licencias (las que muestra el filtro de la página). GET ?ids=1,2,3
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/licencias.php';
require_once '../../includes/xlsx.php';

if (!permisoPuede($pdo, 'licencias')) { http_response_code(403); exit('No autorizado.'); }
$cid = (int)cliente_actual();
$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? ''))))));
$lista = array_values(array_filter(licenciasLista($pdo, $cid), fn($l) => !$ids || in_array((int)$l['id'], $ids, true)));
if (!$lista) { http_response_code(404); exit('No hay licencias para exportar.'); }

$x = new XlsxSimple('Licencias');
$x->columnas([
    ['N.º', 7, 'entero'], ['Tipo', 14], ['Licencia', 44], ['Proveedor', 20], ['Categoría', 20], ['De quién', 34], ['Frecuencia', 13],
    ['Moneda', 8], ['Costo (moneda original)', 14, 'dinero'], ['Costo en lempiras', 15, 'dinero'], ['Comisión %', 10, 'dinero'], ['Se cobra al cliente', 16, 'dinero'],
    ['Costo al año (L)', 15, 'dinero'], ['Próxima renovación', 14, 'fecha'], ['Días que faltan', 10, 'entero'], ['Estado', 10], ['Método de pago', 14], ['Notas', 60],
]);
$totCosto = $totCobro = 0.0;
foreach ($lista as $l) {
    $costoL = licenciaCostoHnl($pdo, $cid, $l);
    $cobro = $l['receptor_id'] ? licenciaPrecio($costoL, (float)$l['comision_pct']) : null;
    [$dias] = licenciaSemaforo($l['proxima_renovacion']);
    $anual = round($costoL * licenciaVecesAnio($l['frecuencia']), 2);
    $act = (int)$l['activa'];
    $x->fila([
        (int)$l['id'], licenciaIcono($l)[2], $l['nombre'], $l['proveedor'], $l['categoria'], $l['receptor_id'] ? $l['cliente_nombre'] : 'Empresa',
        LICENCIA_FRECUENCIAS[$l['frecuencia']] ?? $l['frecuencia'], $l['moneda'], (float)$l['costo'], $costoL, $l['receptor_id'] ? (float)$l['comision_pct'] : null, $cobro,
        $anual, $l['proxima_renovacion'], $dias, $act ? 'Activa' : 'Inactiva', ucfirst((string)$l['metodo_pago']), $l['notas'],
    ], false, !$act);
    if ($act) { $totCosto += $anual; $totCobro += $cobro ? round($cobro * licenciaVecesAnio($l['frecuencia']), 2) : 0; }
}
$x->fila([]);
$x->fila(['TOTAL al año (activas)', null, count($lista) . ' licencia(s)', null, null, 'Se cobra a clientes al año: L ' . number_format($totCobro, 2), null, null, null, null, null, null, round($totCosto, 2)], true);
$x->descargar('licencias_' . date('Y-m-d') . '.xlsx');
