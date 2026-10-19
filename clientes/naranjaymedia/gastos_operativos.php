<?php
// Pagos y gastos → Gastos operativos: lo que se gasta por cada cliente (pauta, combustible, alimentación en visitas…),
// con sus comprobantes y reportes. El rol Gastos operativos solo ve sus clientes asignados; el administrador revisa y aprueba.
// Los filtros se aplican sin recargar (se piden los datos y se reemplaza la zona #goZona).
$titulo = 'Gastos operativos';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/gastos_operativos.php';

$cid = (int)cliente_actual();
$uid = (int)USUARIO_ID;
$instalado = gastosOpDisponible($pdo);
$esAdmin = gastosOpEsAdmin(USUARIO_ROL);
$puedeRegistrar = USUARIO_ROL !== 'lector';
$fechaOk = fn($v, $d) => (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v)) ? $v : $d;
$desde = $fechaOk($_GET['desde'] ?? null, date('Y-m-01', strtotime('-2 months')));
$hasta = $fechaOk($_GET['hasta'] ?? null, date('Y-m-d'));
if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];
$filtros = ['desde' => $desde, 'hasta' => $hasta, 'cuenta' => preg_match('/^[cpk]:\d+$/', (string)($_GET['cuenta'] ?? '')) ? $_GET['cuenta'] : '', 'tipo' => (int)($_GET['tipo'] ?? 0),
    'estado' => isset(GASTO_OP_ESTADOS[$_GET['estado'] ?? '']) ? $_GET['estado'] : '', 'moneda' => in_array($_GET['moneda'] ?? '', ['HNL', 'USD'], true) ? $_GET['moneda'] : '',
    'usuario' => $esAdmin ? (int)($_GET['usuario'] ?? 0) : 0, 'sin_archivo' => !empty($_GET['sin_archivo'])];

$permitidos = $tipos = $clientes = $cuentasTodas = $lista = $tarjetas = $categorias = $responsables = [];
$porRevisar = 0;
if ($instalado) {
    $permitidos = gastosOpPermitidos($pdo, $uid, USUARIO_ROL);
    $tipos = gastosOpTipos($pdo, $cid);
    $clientes = gastosOpCuentas($pdo, $cid, $permitidos);   // cuentas: cliente directo, programa o contrato rotativo
    $lista = gastosOpLista($pdo, $cid, $permitidos, $filtros);
    $st = $pdo->prepare("SELECT id, banco, tipo, ultimos_digitos FROM tarjetas WHERE cliente_id = ? AND activa = 1 ORDER BY banco, ultimos_digitos");
    $st->execute([$cid]);
    $tarjetas = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($esAdmin) {
        $st = $pdo->prepare("SELECT id, nombre FROM categorias_gastos WHERE cliente_id = ? AND activa = 1 ORDER BY nombre");
        $st->execute([$cid]);
        $categorias = $st->fetchAll(PDO::FETCH_ASSOC);
        $responsables = gastosOpResponsables($pdo, $cid);
        $cuentasTodas = gastosOpCuentas($pdo, $cid, null);
        $porRevisar = gastosOpPorRevisar($pdo, $cid);
    }
}
$tot = gastosOpTotales($lista);
$pendientes = count(array_filter($lista, fn($g) => $g['estado'] === 'pendiente'));
$rechazados = count(array_filter($lista, fn($g) => $g['estado'] === 'rechazado'));
$sinArchivo = count(array_filter($lista, fn($g) => !(int)$g['archivos'] && $g['estado'] !== 'rechazado'));
$porTipo = [];
foreach ($lista as $g) {
    if ($g['estado'] === 'rechazado') continue;
    $porTipo[$g['tipo']] ??= ['icono' => $g['tipo_icono'], 'L' => 0.0, 'n' => 0];
    $porTipo[$g['tipo']]['L'] += (float)$g['monto_lempiras'];
    $porTipo[$g['tipo']]['n']++;
}
uasort($porTipo, fn($a, $b) => $b['L'] <=> $a['L']);
$tasaDia = facturaTasaSugerida($pdo, $cid);
$hoy = new DateTime('today');
$rangos = [
    'Este mes' => [$hoy->format('Y-m-01'), $hoy->format('Y-m-d')],
    'Mes anterior' => [(new DateTime('first day of last month'))->format('Y-m-d'), (new DateTime('last day of last month'))->format('Y-m-d')],
    'Últimos 3 meses' => [date('Y-m-01', strtotime('-2 months')), $hoy->format('Y-m-d')],
    'Este año' => [$hoy->format('Y-01-01'), $hoy->format('Y-m-d')],
];
$qsExport = http_build_query(array_filter(['desde' => $desde, 'hasta' => $hasta, 'cuenta' => $filtros['cuenta'] ?: null, 'tipo' => $filtros['tipo'] ?: null,
    'estado' => $filtros['estado'] ?: null, 'moneda' => $filtros['moneda'] ?: null, 'usuario' => $filtros['usuario'] ?: null]));
$e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
require_once '../../includes/templates/header.php';
?>

<div class="app-page-header">
    <div>
        <h1 class="app-page-title"><i class="bi bi-clipboard2-data me-2"></i>Gastos operativos</h1>
        <p class="app-page-sub"><?= $esAdmin ? 'Lo que se gasta por cada cliente (pauta, combustible, alimentación en visitas…) con sus comprobantes. Revisa y aprueba lo registrado; al aprobar puede pasar a Gastos para cobrárselo al cliente.'
            : 'Registra lo que gastas por cada uno de tus clientes (pauta, combustible, alimentación en visitas…) y adjunta el comprobante o el reporte. El administrador lo revisa.' ?></p>
    </div>
    <?php if ($instalado): ?>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($esAdmin): ?>
            <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#mTipos"><i class="bi bi-tags me-1"></i> Tipos</button>
            <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#mResp"><i class="bi bi-person-check me-1"></i> Responsables</button>
        <?php endif; ?>
        <div class="dropdown"><button class="btn btn-outline-success dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-download me-1"></i> Reporte</button>
            <div class="dropdown-menu dropdown-menu-end">
                <a class="dropdown-item go-export" data-formato="pdf" href="gastos_operativos_exportar?formato=pdf&<?= $qsExport ?>" target="_blank"><i class="bi bi-file-earmark-pdf me-2 text-danger"></i>PDF (con el filtro actual)</a>
                <a class="dropdown-item go-export" data-formato="xlsx" href="gastos_operativos_exportar?formato=xlsx&<?= $qsExport ?>"><i class="bi bi-file-earmark-excel me-2 text-success"></i>Excel (con el filtro actual)</a>
            </div></div>
        <?php if ($puedeRegistrar && $clientes): ?><button class="btn btn-primary" id="goNuevo"><i class="bi bi-plus-lg me-1"></i> Registrar gasto</button><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php if (!$instalado): ?>
    <div class="alert alert-warning">Falta instalar <code>sql/migraciones/2026-10-19_gastos_operativos.sql</code>.</div>
<?php elseif (!$clientes): ?>
    <div class="alert alert-info"><i class="bi bi-info-circle me-1"></i> <?= $esAdmin ? 'No hay clientes registrados.' : 'Todavía no tienes clientes asignados. Pídele al administrador que te los asigne en Gastos operativos → Responsables.' ?></div>
<?php else: ?>
<?php if ($esAdmin && $porRevisar && $filtros['estado'] !== 'pendiente'): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2 py-2"><i class="bi bi-hourglass-split"></i><div class="flex-grow-1"><strong><?= $porRevisar ?></strong> gasto<?= $porRevisar === 1 ? '' : 's' ?> por revisar (en cualquier fecha).</div>
        <a href="?estado=pendiente&desde=2000-01-01&hasta=<?= date('Y-m-d') ?>" class="btn btn-sm btn-warning go-ver-pend">Revisarlos</a></div>
<?php endif; ?>
<form id="goFiltros" class="app-card app-card-body mb-3" method="get">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2"><label class="form-label small">Desde</label><input type="date" class="form-control form-control-sm" name="desde" value="<?= $desde ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small">Hasta</label><input type="date" class="form-control form-control-sm" name="hasta" value="<?= $hasta ?>"></div>
        <div class="col-12 col-md-<?= $esAdmin ? 2 : 3 ?>"><label class="form-label small">Cliente / programa</label><select class="form-select form-select-sm" name="cuenta" data-buscar><option value="">Todos</option>
            <?php foreach ($clientes as $c): ?><option value="<?= $c['clave'] ?>" <?= $filtros['cuenta'] === $c['clave'] ? 'selected' : '' ?>><?= $e($c['nombre']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small">Tipo</label><select class="form-select form-select-sm" name="tipo"><option value="">Todos</option>
            <?php foreach ($tipos as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $filtros['tipo'] === (int)$t['id'] ? 'selected' : '' ?>><?= $e($t['nombre']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-<?= $esAdmin ? 1 : 2 ?>"><label class="form-label small">Estado</label><select class="form-select form-select-sm" name="estado"><option value="">Todos</option>
            <?php foreach (GASTO_OP_ESTADOS as $k => [$txt]): ?><option value="<?= $k ?>" <?= $filtros['estado'] === $k ? 'selected' : '' ?>><?= $txt ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-1"><label class="form-label small">Moneda</label><select class="form-select form-select-sm" name="moneda"><option value="">Ambas</option>
            <option value="HNL" <?= $filtros['moneda'] === 'HNL' ? 'selected' : '' ?>>L</option><option value="USD" <?= $filtros['moneda'] === 'USD' ? 'selected' : '' ?>>USD</option></select></div>
        <?php if ($esAdmin): ?>
        <div class="col-6 col-md-2"><label class="form-label small">Registrado por</label><select class="form-select form-select-sm" name="usuario"><option value="">Todos</option>
            <?php $st = $pdo->prepare("SELECT DISTINCT u.id, u.nombre FROM gastos_operativos g JOIN usuarios u ON u.id = g.usuario_id WHERE g.cliente_id = ? ORDER BY u.nombre"); $st->execute([$cid]);
            foreach ($st as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $filtros['usuario'] === (int)$u['id'] ? 'selected' : '' ?>><?= $e($u['nombre']) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <div class="col-12 d-flex flex-wrap gap-1 align-items-center">
            <?php foreach ($rangos as $txt => [$d, $h]): ?><a class="btn btn-sm <?= $d === $desde && $h === $hasta ? 'btn-secondary' : 'btn-outline-secondary' ?> go-rango" href="?desde=<?= $d ?>&hasta=<?= $h ?>" data-desde="<?= $d ?>" data-hasta="<?= $h ?>"><?= $txt ?></a><?php endforeach; ?>
            <div class="form-check form-switch ms-md-3"><input class="form-check-input" type="checkbox" role="switch" name="sin_archivo" value="1" id="goSinArch" <?= $filtros['sin_archivo'] ? 'checked' : '' ?> style="cursor:pointer"><label class="form-check-label small" for="goSinArch">Solo sin comprobante</label></div>
        </div>
    </div>
</form>

<div id="goZona">
    <div class="app-stats">
        <div class="app-stat"><div class="app-stat-icon teal"><i class="bi bi-currency-dollar"></i></div><div><div class="app-stat-val">$ <?= number_format($tot['USD'], 2) ?></div><div class="app-stat-lbl">En dólares</div></div></div>
        <div class="app-stat"><div class="app-stat-icon green"><i class="bi bi-cash"></i></div><div><div class="app-stat-val">L <?= number_format($tot['HNL'], 2) ?></div><div class="app-stat-lbl">En lempiras</div></div></div>
        <div class="app-stat"><div class="app-stat-icon purple"><i class="bi bi-calculator"></i></div><div><div class="app-stat-val">L <?= number_format($tot['lempiras'], 2) ?></div><div class="app-stat-lbl">Todo en lempiras · <?= count($lista) - $rechazados ?> gasto<?= count($lista) - $rechazados === 1 ? '' : 's' ?></div></div></div>
        <a href="#" class="app-stat text-decoration-none go-filtro" data-campo="estado" data-valor="pendiente" title="Ver solo los que faltan por revisar"><div class="app-stat-icon <?= $pendientes ? 'amber' : 'gray' ?>"><i class="bi bi-hourglass-split"></i></div><div><div class="app-stat-val"><?= $pendientes ?></div><div class="app-stat-lbl">Por revisar<?= $rechazados ? ' · ' . $rechazados . ' rechazado' . ($rechazados === 1 ? '' : 's') : '' ?></div></div></a>
        <a href="#" class="app-stat text-decoration-none go-filtro" data-campo="sin_archivo" data-valor="1" title="Ver solo los que no tienen comprobante"><div class="app-stat-icon <?= $sinArchivo ? 'red' : 'gray' ?>"><i class="bi bi-paperclip"></i></div><div><div class="app-stat-val"><?= $sinArchivo ?></div><div class="app-stat-lbl">Sin comprobante</div></div></a>
    </div>

    <?php if ($porTipo): ?>
    <div class="d-flex flex-wrap gap-2 mb-3">
        <?php foreach ($porTipo as $n => $t): ?><span class="badge rounded-pill text-bg-light border fw-normal px-3 py-2"><i class="bi <?= $e($t['icono']) ?> me-1"></i><?= $e($n) ?> · <strong>L <?= number_format($t['L'], 2) ?></strong> <span class="text-muted">(<?= $t['n'] ?>)</span></span><?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="app-card">
        <div class="app-card-header flex-wrap gap-2">
            <span><i class="bi bi-list-ul me-1"></i> Gastos del <?= date('d/m/Y', strtotime($desde)) ?> al <?= date('d/m/Y', strtotime($hasta)) ?></span>
            <div class="app-toolbar flex-grow-1 justify-content-end">
                <div class="app-search" style="max-width:320px"><i class="bi bi-search"></i><input type="search" class="form-control form-control-sm" id="goBuscar" placeholder="Buscar cliente, descripción, campaña…"></div>
                <select class="form-select form-select-sm" id="goPorPagina" style="width:auto"><option value="25">25/pág</option><option value="50">50/pág</option><option value="100">100/pág</option><option value="100000">Todas</option></select>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table app-table" id="tGastosOp" data-ordenar>
                <thead><tr><th class="app-n">#</th><th>Fecha</th><th>Cliente</th><th>Tipo</th><th>Descripción</th><th>Pago</th><th class="app-num">Monto</th><th class="app-num">En lempiras</th><th class="text-center">Archivos</th><th>Estado</th><th class="text-end no-orden"></th></tr></thead>
                <tbody>
                <?php foreach ($lista as $g): [$eTxt, $eBg, $eFg, $eIco] = GASTO_OP_ESTADOS[$g['estado']]; $rech = $g['estado'] === 'rechazado'; ?>
                    <tr data-fila class="<?= $rech ? 'text-muted' : '' ?>" data-buscar="<?= $e(mb_strtolower($g['cliente'] . ' ' . $g['programa'] . ' ' . $g['contrato'] . ' ' . $g['tipo'] . ' ' . $g['descripcion'] . ' ' . $g['proveedor'] . ' ' . $g['campana'] . ' ' . $g['factura_ref'] . ' ' . $g['registrado_por'] . ' #' . $g['id'])) ?>">
                        <td class="app-n"></td>
                        <td class="text-nowrap" data-sort="<?= $g['fecha'] ?>"><?= date('d/m/Y', strtotime($g['fecha'])) ?></td>
                        <td><?= $e($g['contrato'] ?: $g['cliente']) ?><?php if ($g['programa']): ?><div><span class="badge rounded-pill text-bg-light border fw-normal"><i class="bi bi-diagram-3 me-1"></i><?= $e($g['programa']) ?></span></div><?php endif; ?><?php if ($g['contrato']): ?><div class="small text-muted"><i class="bi bi-arrow-repeat me-1"></i>contrato de varias empresas</div><?php endif; ?></td>
                        <td class="small text-nowrap"><i class="bi <?= $e($g['tipo_icono']) ?> me-1 text-muted"></i><?= $e($g['tipo']) ?>
                            <?php if ($g['pauta_inicio']): ?><div class="text-muted"><i class="bi bi-calendar-range me-1"></i><?= date('d/m', strtotime($g['pauta_inicio'])) ?> – <?= date('d/m/Y', strtotime($g['pauta_fin'])) ?></div><?php endif; ?></td>
                        <td><?= $e($g['descripcion']) ?>
                            <div class="small text-muted"><?= $e(implode(' · ', array_filter([$g['campana'] ? 'Campaña: ' . $g['campana'] : '', $g['proveedor'], $g['factura_ref'] ? 'Fact. ' . $g['factura_ref'] : '', $esAdmin ? $g['registrado_por'] : '']))) ?></div>
                            <?php if ($rech && $g['motivo_rechazo']): ?><div class="small text-danger"><i class="bi bi-x-circle me-1"></i><?= $e($g['motivo_rechazo']) ?></div><?php endif; ?></td>
                        <td class="small"><?= $g['metodo_pago'] === 'tarjeta' ? ($g['tarjeta'] ? '<i class="bi bi-credit-card me-1"></i>' . $e($g['tarjeta']) : 'Tarjeta') : $e(GASTO_OP_METODOS[$g['metodo_pago']]) ?></td>
                        <td class="app-num fw-semibold text-nowrap" data-sort="<?= (float)$g['monto_lempiras'] ?>"><?= monedaFmt($g['monto'], $g['moneda']) ?><?php if ($g['moneda'] === 'USD'): ?><div class="small text-muted fw-normal">× <?= rtrim(rtrim(number_format((float)$g['tasa_cambio'], 4), '0'), '.') ?></div><?php endif; ?></td>
                        <td class="app-num"><?= number_format((float)$g['monto_lempiras'], 2) ?></td>
                        <td class="text-center"><?= (int)$g['archivos'] ? '<a href="#" class="go-ver text-decoration-none" data-id="' . (int)$g['id'] . '" title="Ver archivos"><i class="bi bi-paperclip"></i> ' . (int)$g['archivos'] . '</a>' : '<span class="badge bg-light text-danger border">Sin</span>' ?></td>
                        <td><span class="badge" style="background:<?= $eBg ?>;color:<?= $eFg ?>"><i class="bi <?= $eIco ?> me-1"></i><?= $eTxt ?></span>
                            <?php if ($g['gasto_id']): ?><div class="small text-muted">Gastos N.º <?= (int)$g['gasto_id'] ?></div><?php endif; ?></td>
                        <td class="text-end text-nowrap">
                            <button class="btn btn-sm btn-outline-primary go-ver" data-id="<?= (int)$g['id'] ?>" title="Ver, editar y adjuntar"><i class="bi bi-<?= $g['estado'] !== 'aprobado' && ($esAdmin || (int)$g['usuario_id'] === $uid) ? 'pencil' : 'eye' ?>"></i></button>
                            <?php if ($esAdmin && $g['estado'] === 'pendiente'): ?><button class="btn btn-sm btn-success go-revisar" data-id="<?= (int)$g['id'] ?>" data-tipo-cat="<?= (int)array_column($tipos, 'categoria_id', 'id')[$g['tipo_id']] ?>" data-desc="<?= $e(gastosOpCuentaTexto($g) . ' · ' . $g['descripcion'] . ' · ' . monedaFmt($g['monto'], $g['moneda'])) ?>" data-cobrar="<?= (int)$g['cobrar_cliente'] ?>" title="Aprobar o rechazar"><i class="bi bi-check2-square"></i></button><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$lista): ?><tr><td colspan="11" class="text-center text-muted py-4">No hay gastos con estos filtros.</td></tr><?php endif; ?>
                </tbody>
                <?php if ($lista): ?><tfoot><tr class="fw-bold"><td colspan="7" class="text-end">Total (sin rechazados)</td><td class="app-num">L <?= number_format($tot['lempiras'], 2) ?></td><td colspan="3"></td></tr></tfoot><?php endif; ?>
            </table>
        </div>
        <div class="app-pager" id="goPie"></div>
    </div>
</div>

<!-- Registrar / ver / editar -->
<div class="modal fade" id="mGasto" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-md-down"><form class="modal-content" id="fGasto" novalidate>
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-clipboard2-plus me-1"></i> <span id="gTitulo">Registrar gasto</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <div id="gAviso"></div>
            <input type="hidden" name="accion" value="guardar"><input type="hidden" name="id">
            <fieldset id="gCampos"><div class="row g-2">
                <div class="col-md-6"><label class="form-label">Cliente, programa o contrato *</label><select name="cuenta" class="form-select" required data-buscar><option value="">— Buscar cliente, programa o contrato —</option>
                    <?php foreach (array_unique(array_column($clientes, 'grupo')) as $gr): ?><optgroup label="<?= $e($gr) ?>">
                        <?php foreach ($clientes as $c): if ($c['grupo'] !== $gr) continue; ?><option value="<?= $c['clave'] ?>" data-empresas="<?= $e($c['empresas'] ?? '') ?>"><?= $e($c['nombre']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label">Tipo de gasto *</label><select name="tipo_id" class="form-select" required data-buscar><option value="">— Buscar o elegir —</option>
                    <?php foreach ($tipos as $t): if (!(int)$t['activo']) continue; ?><option value="<?= (int)$t['id'] ?>" data-pauta="<?= (int)$t['es_pauta'] ?>"><?= $e($t['nombre']) ?></option><?php endforeach; ?>
                    <option value="__nuevo__">➕ Agregar tipo nuevo…</option></select></div>
                <div class="col-12 g-empresas" hidden><div class="small text-muted"><i class="bi bi-arrow-repeat me-1"></i>Empresas de este contrato: <span></span>. A cuál se le cobra se decide al facturar.</div></div>
                <div class="col-12 g-pauta" hidden><div class="row g-2 p-2 rounded-3" style="background:#f0f9ff;border:1px solid #bae6fd">
                    <div class="col-md-6"><label class="form-label small mb-1">Campaña</label><input name="campana" class="form-control form-control-sm" maxlength="200" placeholder="Ej.: Lanzamiento promoción octubre"></div>
                    <div class="col-6 col-md-3"><label class="form-label small mb-1">Inicio de la pauta *</label><input type="date" name="pauta_inicio" class="form-control form-control-sm"></div>
                    <div class="col-6 col-md-3"><label class="form-label small mb-1">Fin de la pauta *</label><input type="date" name="pauta_fin" class="form-control form-control-sm"><div class="form-text" id="gDiasPauta"></div></div>
                </div></div>
                <div class="col-12"><label class="form-label">Descripción *</label><input name="descripcion" class="form-control" maxlength="300" required placeholder="Ej.: Pauta Meta del 1 al 15 · combustible visita a sucursal de San Pedro"></div>
                <div class="col-6 col-md-3"><label class="form-label">Fecha del gasto *</label><input type="date" name="fecha" class="form-control" required max="<?= date('Y-m-d') ?>"></div>
                <div class="col-6 col-md-3"><label class="form-label">Moneda</label><select name="moneda" class="form-select"><option value="HNL">Lempiras (L)</option><option value="USD">Dólares (USD)</option></select></div>
                <div class="col-6 col-md-3"><label class="form-label">Monto *</label><input type="number" name="monto" class="form-control" step="0.01" min="0.01" required></div>
                <div class="col-6 col-md-3 g-usd" hidden><label class="form-label">Tasa (L por USD)</label><input type="number" name="tasa_cambio" class="form-control" step="0.0001" min="1"><div class="form-text" id="gEnL"></div></div>
                <div class="col-md-6"><label class="form-label">Cómo se pagó</label><select name="metodo_pago" class="form-select"><?php foreach (GASTO_OP_METODOS as $k => $t): ?><option value="<?= $k ?>"><?= $t ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6 g-tarjeta"><label class="form-label">Tarjeta</label><select name="tarjeta_id" class="form-select"><option value="">— Sin especificar —</option>
                    <?php foreach ($tarjetas as $t): ?><option value="<?= (int)$t['id'] ?>"><?= $e($t['banco'] . ' ' . strtoupper($t['tipo']) . ' •••• ' . $t['ultimos_digitos']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label">Proveedor <span class="text-muted small">(opcional · búscalo o agrégalo)</span></label><?php $psId = 'gProveedor'; $psValor = ''; $psClase = 'form-select'; require '../../includes/templates/proveedor_select.php'; ?></div>
                <div class="col-md-6"><label class="form-label">N.º de factura o recibo <span class="text-muted small">(opcional)</span></label><input name="factura_ref" class="form-control" maxlength="100"></div>
                <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="cobrar_cliente" value="1" id="gCobrar" checked><label class="form-check-label" for="gCobrar">Se le cobra al cliente (al aprobarlo aparece al facturarle)</label></div></div>
                <div class="col-12"><label class="form-label">Notas <span class="text-muted small">(opcional)</span></label><textarea name="notas" class="form-control" rows="2" maxlength="3000"></textarea></div>
            </div></fieldset>
            <div class="border-top mt-3 pt-3">
                <div class="d-flex justify-content-between align-items-center mb-2"><strong><i class="bi bi-paperclip me-1"></i> Comprobantes y reportes</strong><small class="text-muted">Fotos, PDF, Excel o CSV · hasta 10 MB cada uno</small></div>
                <div id="gArchivos" class="mb-2"></div>
                <div class="row g-2 align-items-end" id="gSubir">
                    <div class="col-md-8"><input type="file" name="archivos[]" class="form-control" multiple accept="image/*,application/pdf,.pdf,.xlsx,.xls,.csv"></div>
                    <div class="col-md-4"><select name="archivo_tipo" class="form-select"><?php foreach (GASTO_OP_ARCHIVO_TIPOS as $k => $t): ?><option value="<?= $k ?>"><?= $t ?></option><?php endforeach; ?></select></div>
                </div>
            </div>
            <details class="mt-3" id="gBitWrap" hidden><summary class="small text-muted">Historial</summary><ul class="small mt-2 mb-0" id="gBitacora"></ul></details>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline-danger me-auto" id="gEliminar" hidden><i class="bi bi-trash3 me-1"></i> Eliminar</button>
            <?php if ($esAdmin): ?><button type="button" class="btn btn-outline-warning" id="gReabrir" hidden><i class="bi bi-arrow-counterclockwise me-1"></i> Regresar a revisión</button><?php endif; ?>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
            <button class="btn btn-primary" id="gGuardar"><i class="bi bi-floppy me-1"></i> Guardar</button>
        </div>
    </form></div>
</div>

<?php if ($esAdmin): ?>
<!-- Aprobar o rechazar -->
<div class="modal fade" id="mRevisar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><form class="modal-content" id="fRevisar" novalidate>
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-check2-square me-1"></i> Revisar gasto</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <input type="hidden" name="id">
            <div class="alert alert-light border small py-2" id="rDesc"></div>
            <div class="btn-group w-100 mb-3" role="group">
                <input type="radio" class="btn-check" name="decision" id="rAprobar" value="aprobar" checked><label class="btn btn-outline-success" for="rAprobar"><i class="bi bi-check-circle me-1"></i> Aprobar</label>
                <input type="radio" class="btn-check" name="decision" id="rRechazar" value="rechazar"><label class="btn btn-outline-danger" for="rRechazar"><i class="bi bi-x-circle me-1"></i> Rechazar</label>
            </div>
            <div id="rAprobarGrp">
                <label class="form-label fw-semibold">¿Cómo queda en Gastos de la empresa?</label>
                <div class="form-check"><input class="form-check-input" type="radio" name="registro" value="nuevo" id="rNuevo" checked><label class="form-check-label" for="rNuevo">Registrarlo en Gastos <span class="text-muted small">(con el comprobante; si se le cobra al cliente, aparece al facturarle)</span></label></div>
                <div class="ms-4 mb-2" id="rCatGrp"><select name="categoria_id" class="form-select form-select-sm"><option value="">Sin categoría</option><?php foreach ($categorias as $k): ?><option value="<?= (int)$k['id'] ?>"><?= $e($k['nombre']) ?></option><?php endforeach; ?></select></div>
                <div class="form-check"><input class="form-check-input" type="radio" name="registro" value="existente" id="rExiste"><label class="form-check-label" for="rExiste">Ya está en Gastos (por ejemplo, del estado de cuenta de la tarjeta): ligarlo</label></div>
                <div class="ms-4 mb-2" id="rExisteGrp" hidden><input type="number" name="gasto_id" class="form-control form-control-sm" placeholder="N.º de gasto" min="1"></div>
                <div class="form-check"><input class="form-check-input" type="radio" name="registro" value="ninguno" id="rNinguno"><label class="form-check-label" for="rNinguno">Solo aprobarlo (no tocar Gastos)</label></div>
            </div>
            <div id="rRechazarGrp" hidden><label class="form-label">Motivo del rechazo * <span class="text-muted small">(lo verá quien lo registró para corregirlo)</span></label><textarea name="motivo" class="form-control" rows="2" maxlength="300"></textarea></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary">Guardar</button></div>
    </form></div>
</div>

<!-- Tipos de gasto -->
<div class="modal fade" id="mTipos" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-tags me-1"></i> Tipos de gasto operativo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <p class="small text-muted">Los de pauta piden campaña y fechas de inicio y fin. La categoría es la que se sugiere al pasarlo a Gastos.</p>
            <div class="table-responsive"><table class="table table-sm align-middle">
                <thead><tr><th>Nombre</th><th>Icono</th><th class="text-center">Pauta</th><th>Categoría en Gastos</th><th class="text-center">Activo</th><th></th></tr></thead>
                <tbody>
                <?php foreach (array_merge($tipos, [['id' => 0, 'nombre' => '', 'icono' => 'bi-tag', 'es_pauta' => 0, 'categoria_id' => null, 'activo' => 1]]) as $t): ?>
                    <tr class="t-fila" data-id="<?= (int)$t['id'] ?>">
                        <td><input class="form-control form-control-sm" name="nombre" value="<?= $e($t['nombre']) ?>" maxlength="80" placeholder="<?= $t['id'] ? '' : 'Nuevo tipo…' ?>"></td>
                        <td><input class="form-control form-control-sm" name="icono" value="<?= $e($t['icono']) ?>" style="width:120px" title="Nombre de Bootstrap Icons, ej.: bi-fuel-pump"></td>
                        <td class="text-center"><input class="form-check-input" type="checkbox" name="es_pauta" <?= (int)$t['es_pauta'] ? 'checked' : '' ?>></td>
                        <td><select class="form-select form-select-sm" name="categoria_id"><option value="">—</option><?php foreach ($categorias as $k): ?><option value="<?= (int)$k['id'] ?>" <?= (int)$t['categoria_id'] === (int)$k['id'] ? 'selected' : '' ?>><?= $e($k['nombre']) ?></option><?php endforeach; ?></select></td>
                        <td class="text-center"><input class="form-check-input" type="checkbox" name="activo" <?= (int)$t['activo'] ? 'checked' : '' ?>></td>
                        <td><button class="btn btn-sm btn-<?= $t['id'] ? 'outline-primary' : 'primary' ?> t-guardar"><?= $t['id'] ? 'Guardar' : 'Agregar' ?></button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table></div>
        </div>
    </div></div>
</div>

<!-- Responsables: qué clientes ve cada usuario con el rol Gastos operativos -->
<div class="modal fade" id="mResp" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-person-check me-1"></i> Responsables y sus clientes</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <?php if (!$responsables): ?><div class="alert alert-info small mb-0">No hay usuarios con el rol <strong>Gastos operativos</strong>. Créalos en <a href="usuarios">Usuarios</a>.</div><?php endif; ?>
            <?php foreach ($responsables as $u): ?>
                <div class="border rounded-3 p-3 mb-3 r-usuario" data-id="<?= (int)$u['id'] ?>">
                    <div class="d-flex justify-content-between align-items-center mb-2"><div><strong><?= $e($u['nombre']) ?></strong> <span class="small text-muted"><?= $e($u['correo']) ?><?= $u['estado'] !== 'activo' ? ' · inactivo' : '' ?></span></div>
                        <button class="btn btn-sm btn-primary r-guardar">Guardar</button></div>
                    <input type="search" class="form-control form-control-sm mb-2 r-buscar" placeholder="Buscar cliente…">
                    <div class="row g-1" style="max-height:220px;overflow:auto">
                        <?php foreach ($cuentasTodas as $c): $idc = 'r' . (int)$u['id'] . '_' . str_replace(':', '_', $c['clave']); ?><div class="col-md-6 r-cli" data-n="<?= $e(mb_strtolower($c['nombre'])) ?>"><div class="form-check"><input class="form-check-input" type="checkbox" value="<?= $c['clave'] ?>" id="<?= $idc ?>" <?= in_array($c['clave'], $u['claves'], true) ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="<?= $idc ?>"><?= $c['grupo'] === 'Programas' ? '<i class="bi bi-diagram-3 me-1"></i>' : ($c['grupo'] === 'Clientes' ? '' : '<i class="bi bi-arrow-repeat me-1"></i>') ?><?= $e($c['nombre']) ?></label></div></div><?php endforeach; ?>
                    </div>
                    <div class="small text-muted mt-1"><span class="r-cuenta"><?= count($u['claves']) ?></span> asignado(s) · un cliente completo incluye sus programas; un programa o contrato, solo eso</div>
                </div>
            <?php endforeach; ?>
        </div>
    </div></div>
</div>
<?php endif; ?>
<?php endif; ?>

<script src="../../clientes/js/app-tabla.js?v=<?= @filemtime(__DIR__ . '/../js/app-tabla.js') ?>"></script>
<script>
(() => {
    const f = document.getElementById('goFiltros');
    if (!f) return;
    const ACC = 'includes/gasto_operativo_accion.php', TASA = <?= json_encode($tasaDia) ?>, ES_ADMIN = <?= $esAdmin ? 'true' : 'false' ?>;
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const post = async fd => { const r = await fetch(ACC, { method: 'POST', body: fd }); const d = await r.json().catch(() => ({ success: false, error: 'Respuesta inesperada (' + r.status + ').' })); if (!d.success) throw new Error(d.error); return d; };
    let pedido = 0;
    const iniciarTabla = () => { AppTabla(document.getElementById('tGastosOp'), { buscar: '#goBuscar', porPagina: '#goPorPagina', pie: '#goPie', vacio: 'Sin resultados.' }); window.AppOrdenarIniciar?.(); };
    iniciarTabla();

    // Filtros sin recargar
    const urlForm = () => location.pathname + '?' + new URLSearchParams(new FormData(f)).toString();
    const cargar = async url => {
        const yo = ++pedido, z = document.getElementById('goZona');
        z.style.opacity = '.5';
        try {
            const r = await fetch(url, { credentials: 'same-origin' });
            if (!r.ok) throw new Error();
            const doc = new DOMParser().parseFromString(await r.text(), 'text/html');
            if (yo !== pedido) return;
            z.replaceWith(doc.getElementById('goZona'));
            document.querySelectorAll('.go-rango').forEach(a => { const on = a.dataset.desde === f.desde.value && a.dataset.hasta === f.hasta.value; a.classList.toggle('btn-secondary', on); a.classList.toggle('btn-outline-secondary', !on); });
            const qs = url.split('?')[1] || '';
            document.querySelectorAll('.go-export').forEach(a => a.href = 'gastos_operativos_exportar?formato=' + a.dataset.formato + '&' + qs);
            history.replaceState(null, '', url);
            iniciarTabla();
        } catch (e) { location.href = url; }
        finally { document.getElementById('goZona').style.removeProperty('opacity'); }
    };
    f.addEventListener('change', () => cargar(urlForm()));
    f.addEventListener('submit', e => { e.preventDefault(); cargar(urlForm()); });
    document.addEventListener('click', e => {
        const a = e.target.closest('.go-rango');
        if (a) { e.preventDefault(); f.desde.value = a.dataset.desde; f.hasta.value = a.dataset.hasta; cargar(urlForm()); }
        const fl = e.target.closest('.go-filtro');
        if (fl) { e.preventDefault(); if (fl.dataset.campo === 'sin_archivo') f.sin_archivo.checked = true; else f[fl.dataset.campo].value = fl.dataset.valor; cargar(urlForm()); }
        if (e.target.closest('.go-ver-pend')) { e.preventDefault(); f.estado.value = 'pendiente'; f.desde.value = '2000-01-01'; cargar(urlForm()); }
    });

    // ── Registrar / ver / editar ──
    const m = new bootstrap.Modal(document.getElementById('mGasto')), fg = document.getElementById('fGasto');
    const campos = document.getElementById('gCampos'), arch = document.getElementById('gArchivos');
    const pintar = () => {
        const oc = fg.cuenta.selectedOptions[0], emp = oc?.dataset.empresas || '';
        fg.querySelector('.g-empresas').hidden = !emp;
        fg.querySelector('.g-empresas span').textContent = emp;
        const o = fg.tipo_id.selectedOptions[0];
        const pauta = o?.dataset.pauta === '1';
        fg.querySelector('.g-pauta').hidden = !pauta;
        fg.pauta_inicio.required = fg.pauta_fin.required = pauta;
        const usd = fg.moneda.value === 'USD';
        fg.querySelector('.g-usd').hidden = !usd;
        fg.tasa_cambio.required = usd;
        if (usd && !fg.tasa_cambio.value && TASA) fg.tasa_cambio.value = TASA;
        document.getElementById('gEnL').textContent = usd && +fg.monto.value && +fg.tasa_cambio.value ? '= L ' + (+fg.monto.value * +fg.tasa_cambio.value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '';
        fg.querySelector('.g-tarjeta').hidden = fg.metodo_pago.value !== 'tarjeta';
        const a = new Date(fg.pauta_inicio.value), b = new Date(fg.pauta_fin.value), d = Math.round((b - a) / 864e5) + 1;
        document.getElementById('gDiasPauta').textContent = d > 0 ? d + ' día' + (d === 1 ? '' : 's') + ' de pauta' : '';
    };
    fg.addEventListener('input', pintar); fg.addEventListener('change', pintar);
    // Alta rápida de un tipo de gasto
    fg.tipo_id.addEventListener('change', async () => {
        if (fg.tipo_id.value !== '__nuevo__') return;
        const r = await Swal.fire({ title: 'Nuevo tipo de gasto', showCancelButton: true, confirmButtonText: 'Agregar', cancelButtonText: 'Cancelar',
            html: '<input id="tnNom" class="swal2-input" placeholder="Ej.: Pinterest Ads, Viáticos de sesión de fotos" maxlength="80"><label class="d-block mt-2"><input type="checkbox" id="tnPauta"> Es pauta (pide campaña y fechas)</label>',
            preConfirm: async () => {
                const fd = new FormData(); fd.append('accion', 'tipo_crear'); fd.append('nombre', document.getElementById('tnNom').value); if (document.getElementById('tnPauta').checked) fd.append('es_pauta', '1');
                try { return (await post(fd)).tipo; } catch (err) { Swal.showValidationMessage(err.message); return false; }
            } });
        if (!r.isConfirmed || !r.value) { fg.tipo_id.value = ''; pintar(); return; }
        let o = [...fg.tipo_id.options].find(x => x.value == r.value.id);
        if (!o) { o = new Option(r.value.nombre, r.value.id); o.dataset.pauta = r.value.es_pauta; fg.tipo_id.add(o, fg.tipo_id.options[fg.tipo_id.options.length - 1]); }
        fg.tipo_id.value = String(r.value.id); pintar();
    });
    const pintarArchivos = (lista, puedeSubir) => {
        arch.innerHTML = lista.length ? lista.map(a => `<div class="d-flex align-items-center gap-2 border rounded-2 px-2 py-1 mb-1 small">
            <i class="bi bi-${/\.(pdf)$/i.test(a.nombre) ? 'file-earmark-pdf text-danger' : /\.(xlsx?|csv)$/i.test(a.nombre) ? 'file-earmark-excel text-success' : 'image text-primary'}"></i>
            <a href="gasto_operativo_archivo?id=${a.id}" target="_blank" class="flex-grow-1 text-truncate">${esc(a.nombre)}</a>
            <span class="badge text-bg-light border">${esc(a.tipo)}</span><span class="text-muted d-none d-md-inline">${esc(a.subido_por || '')} · ${esc(a.fecha)}</span>
            <a href="gasto_operativo_archivo?id=${a.id}&descargar=1" title="Descargar"><i class="bi bi-download"></i></a>
            ${a.puede_quitar ? `<button type="button" class="btn btn-sm btn-link text-danger p-0 g-quitar" data-id="${a.id}" title="Quitar"><i class="bi bi-x-lg"></i></button>` : ''}</div>`).join('')
            : '<div class="small text-muted">Sin archivos todavía.</div>';
        document.getElementById('gSubir').hidden = !puedeSubir;
    };
    const abrir = async id => {
        fg.reset(); fg.id.value = id || ''; arch.innerHTML = ''; document.getElementById('gAviso').innerHTML = '';
        document.getElementById('gBitWrap').hidden = true;
        [...fg.tipo_id.options].forEach(o => o.hidden = o.dataset.inactivo === '1');
        let editable = true;
        if (id) {
            const d = await fetch(`${ACC}?ver=${id}`).then(r => r.json());
            if (!d.success) return Swal.fire('No se pudo', d.error, 'error');
            const g = d.gasto;
            fg.cuenta.value = g.contrato_id ? 'k:' + g.contrato_id : (g.programa_id ? 'p:' + g.programa_id : 'c:' + g.receptor_id);
            for (const k of ['tipo_id', 'fecha', 'descripcion', 'moneda', 'monto', 'tasa_cambio', 'metodo_pago', 'tarjeta_id', 'campana', 'pauta_inicio', 'pauta_fin', 'factura_ref', 'notas'])
                if (fg[k]) fg[k].value = g[k] ?? '';
            [...fg.tipo_id.options].forEach(o => { if (o.value == g.tipo_id) o.hidden = false; });
            fg.cobrar_cliente.checked = +g.cobrar_cliente === 1;
            if (window.proveedorSelectValor) proveedorSelectValor(document.getElementById('gProveedor'), g.proveedor || '');
            if (g.tipo_id && ![...fg.tipo_id.options].some(o => o.value == g.tipo_id)) { const o = new Option(g.tipo, g.tipo_id); o.dataset.pauta = g.es_pauta; fg.tipo_id.add(o, fg.tipo_id.options[1]); fg.tipo_id.value = String(g.tipo_id); }
            editable = g.puede_editar;
            document.getElementById('gTitulo').textContent = 'Gasto #' + g.id + ' · ' + (g.contrato || (g.cliente + (g.programa ? ' → ' + g.programa : '')));
            const est = { pendiente: ['warning', 'Por revisar'], aprobado: ['success', 'Aprobado' + (g.revisado_por_nombre ? ' por ' + g.revisado_por_nombre : '') + (g.gasto_id ? ' · Gastos N.º ' + g.gasto_id : '')], rechazado: ['danger', 'Rechazado: ' + (g.motivo_rechazo || '') + ' — corrígelo y guárdalo para enviarlo otra vez'] }[g.estado];
            document.getElementById('gAviso').innerHTML = `<div class="alert alert-${est[0]} py-2 small">${esc(est[1])}${g.registrado_por ? ' · Registrado por ' + esc(g.registrado_por) : ''}</div>`;
            pintarArchivos(d.archivos, true);
            document.getElementById('gBitacora').innerHTML = d.bitacora.map(b => `<li>${esc(b.fecha)} · ${esc(b.usuario || '')}: ${esc(b.accion)}${b.detalle ? ' — ' + esc(b.detalle) : ''}</li>`).join('');
            document.getElementById('gBitWrap').hidden = !d.bitacora.length;
            document.getElementById('gEliminar').hidden = !editable;
            const re = document.getElementById('gReabrir'); if (re) re.hidden = g.estado !== 'aprobado';
        } else {
            document.getElementById('gTitulo').textContent = 'Registrar gasto';
            fg.fecha.value = new Date().toLocaleDateString('en-CA');
            if (window.proveedorSelectValor) proveedorSelectValor(document.getElementById('gProveedor'), '');
            const opc = fg.cuenta.querySelectorAll('option[value]:not([value=""])');
            if (opc.length === 1) fg.cuenta.value = opc[0].value;
            if (f.cuenta.value) fg.cuenta.value = f.cuenta.value;
            pintarArchivos([], true);
            document.getElementById('gEliminar').hidden = true;
            const re = document.getElementById('gReabrir'); if (re) re.hidden = true;
        }
        campos.disabled = !editable;
        document.getElementById('gGuardar').innerHTML = editable ? '<i class="bi bi-floppy me-1"></i> Guardar' : '<i class="bi bi-upload me-1"></i> Subir archivos';
        pintar(); m.show();
    };
    document.getElementById('goNuevo')?.addEventListener('click', () => abrir(0));
    document.addEventListener('click', e => { const b = e.target.closest('.go-ver'); if (b) { e.preventDefault(); abrir(+b.dataset.id); } });
    fg.addEventListener('submit', async e => {
        e.preventDefault();
        const fd = new FormData(fg);
        if (campos.disabled) fd.set('accion', 'archivos');
        else {
            if (!fg.cuenta.value || !fg.tipo_id.value || !fg.descripcion.value.trim() || !(+fg.monto.value > 0) || !fg.fecha.value) return Swal.fire('Faltan datos', 'Cliente o programa, tipo, descripción, fecha y monto son obligatorios.', 'warning');
            if (!fg.querySelector('.g-pauta').hidden && (!fg.pauta_inicio.value || !fg.pauta_fin.value)) return Swal.fire('Fechas de la pauta', 'Escribe cuándo empieza y cuándo termina la pauta.', 'warning');
            if (!fg.id.value && !fg['archivos[]'].files.length) {
                const r = await Swal.fire({ icon: 'question', title: '¿Sin comprobante?', text: 'Puedes adjuntarlo después, pero el gasto queda marcado «Sin comprobante».', showCancelButton: true, confirmButtonText: 'Guardar así', cancelButtonText: 'Adjuntar' });
                if (!r.isConfirmed) return;
            }
        }
        const btn = document.getElementById('gGuardar'); btn.disabled = true;
        try {
            const d = await post(fd);
            m.hide();
            Swal.fire({ icon: 'success', title: d.message, timer: 1400, showConfirmButton: false });
            cargar(location.pathname + location.search);
        } catch (err) { Swal.fire('No se pudo guardar', err.message, 'error'); }
        finally { btn.disabled = false; }
    });
    arch.addEventListener('click', async e => {
        const b = e.target.closest('.g-quitar'); if (!b) return;
        if (!(await Swal.fire({ icon: 'warning', title: '¿Quitar este archivo?', showCancelButton: true, confirmButtonText: 'Sí, quitar', cancelButtonText: 'No' })).isConfirmed) return;
        const fd = new FormData(); fd.append('accion', 'archivo_quitar'); fd.append('archivo_id', b.dataset.id);
        try { await post(fd); b.closest('.d-flex').remove(); cargar(location.pathname + location.search); } catch (err) { Swal.fire('No se pudo', err.message, 'error'); }
    });
    document.getElementById('gEliminar').addEventListener('click', async () => {
        if (!(await Swal.fire({ icon: 'warning', title: '¿Eliminar este gasto?', text: 'Se borran también sus archivos.', showCancelButton: true, confirmButtonText: 'Sí, eliminar', cancelButtonText: 'No', confirmButtonColor: '#dc3545' })).isConfirmed) return;
        const fd = new FormData(); fd.append('accion', 'eliminar'); fd.append('id', fg.id.value);
        try { const d = await post(fd); m.hide(); Swal.fire({ icon: 'success', title: d.message, timer: 1200, showConfirmButton: false }); cargar(location.pathname + location.search); } catch (err) { Swal.fire('No se pudo', err.message, 'error'); }
    });
    document.getElementById('gReabrir')?.addEventListener('click', async () => {
        if (!(await Swal.fire({ icon: 'question', title: '¿Regresarlo a revisión?', text: 'Si al aprobarlo se creó un gasto en Gastos, ese gasto se anula.', showCancelButton: true, confirmButtonText: 'Sí', cancelButtonText: 'No' })).isConfirmed) return;
        const fd = new FormData(); fd.append('accion', 'reabrir'); fd.append('id', fg.id.value);
        try { const d = await post(fd); m.hide(); Swal.fire({ icon: 'success', title: d.message, timer: 1300, showConfirmButton: false }); cargar(location.pathname + location.search); } catch (err) { Swal.fire('No se pudo', err.message, 'error'); }
    });

    if (!ES_ADMIN) return;
    // ── Aprobar o rechazar ──
    const mr = new bootstrap.Modal(document.getElementById('mRevisar')), fr = document.getElementById('fRevisar');
    const pintarR = () => {
        const ap = fr.decision.value === 'aprobar';
        document.getElementById('rAprobarGrp').hidden = !ap; document.getElementById('rRechazarGrp').hidden = ap;
        document.getElementById('rCatGrp').hidden = fr.registro.value !== 'nuevo';
        document.getElementById('rExisteGrp').hidden = fr.registro.value !== 'existente';
    };
    fr.addEventListener('change', pintarR);
    document.addEventListener('click', e => {
        const b = e.target.closest('.go-revisar'); if (!b) return;
        fr.reset(); fr.id.value = b.dataset.id; fr.categoria_id.value = b.dataset.tipoCat !== '0' ? b.dataset.tipoCat : '';
        document.getElementById('rDesc').textContent = b.dataset.desc + (b.dataset.cobrar === '1' ? ' · se le cobra al cliente' : '');
        pintarR(); mr.show();
    });
    fr.addEventListener('submit', async e => {
        e.preventDefault();
        const fd = new FormData(fr); fd.set('accion', fr.decision.value === 'aprobar' ? 'aprobar' : 'rechazar');
        try { const d = await post(fd); mr.hide(); Swal.fire({ icon: 'success', title: d.message, timer: 1600, showConfirmButton: false }); cargar(location.pathname + location.search); }
        catch (err) { Swal.fire('No se pudo', err.message, 'error'); }
    });
    // ── Tipos ──
    document.getElementById('mTipos').addEventListener('click', async e => {
        const b = e.target.closest('.t-guardar'); if (!b) return;
        const tr = b.closest('tr'), fd = new FormData();
        fd.append('accion', 'tipo_guardar'); fd.append('id', tr.dataset.id);
        tr.querySelectorAll('input,select').forEach(i => { if (i.type === 'checkbox') { if (i.checked) fd.append(i.name, '1'); } else fd.append(i.name, i.value); });
        if (!tr.querySelector('[name=activo]').checked) fd.append('activo', '0');
        try { await post(fd); Swal.fire({ icon: 'success', title: 'Tipo guardado', timer: 1000, showConfirmButton: false }).then(() => location.reload()); } catch (err) { Swal.fire('No se pudo', err.message, 'error'); }
    });
    // ── Responsables ──
    const mResp = document.getElementById('mResp');
    mResp.addEventListener('input', e => {
        if (!e.target.classList.contains('r-buscar')) return;
        const q = e.target.value.trim().toLowerCase();
        e.target.closest('.r-usuario').querySelectorAll('.r-cli').forEach(c => c.hidden = q && !c.dataset.n.includes(q));
    });
    mResp.addEventListener('change', e => { const u = e.target.closest('.r-usuario'); if (u) u.querySelector('.r-cuenta').textContent = u.querySelectorAll('.r-cli input:checked').length; });
    mResp.addEventListener('click', async e => {
        const b = e.target.closest('.r-guardar'); if (!b) return;
        const u = b.closest('.r-usuario'), fd = new FormData();
        fd.append('accion', 'asignar'); fd.append('usuario_id', u.dataset.id);
        u.querySelectorAll('.r-cli input:checked').forEach(i => fd.append('cuentas[]', i.value));
        try { const d = await post(fd); Swal.fire({ icon: 'success', title: d.message, timer: 1100, showConfirmButton: false }); } catch (err) { Swal.fire('No se pudo', err.message, 'error'); }
    });
})();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
