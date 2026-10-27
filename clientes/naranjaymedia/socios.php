<?php
// Socios: aportes (dinero que un socio pone en la empresa), retiros de socio (gastos personales pagados por la empresa)
// y préstamos a socios (la empresa le presta y él lo devuelve en cuotas).
$titulo = 'Socios: aportes, retiros y préstamos';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/socios.php';
require_once '../../includes/bancos.php';
require_once '../../includes/socios_prestamos.php';

if (!permisoPuede($pdo, 'socios')) {
    header('Location: dashboard');
    exit;
}
$cid = (int)cliente_actual();
$instalado = sociosDisponible($pdo);
$anio = preg_match('/^\d{4}$/', (string)($_GET['anio'] ?? '')) ? (int)$_GET['anio'] : 0;   // 0 = todos los años
$enAnio = fn($fecha) => !$anio || (int)substr((string)$fecha, 0, 4) === $anio;
$aportes = array_values(array_filter($instalado ? sociosAportes($pdo, $cid) : [], fn($a) => $enAnio($a['fecha'])));
$retiros = array_values(array_filter(sociosRetiros($pdo, $cid), fn($r) => $enAnio($r['fecha'])));
$socios = sociosNombres($pdo, $cid);
$registrados = sociosRegistrados($pdo, $cid);
foreach ($retiros as $i => $r) $retiros[$i]['socio'] = socioDeRetiro($r, $registrados);

// Resumen por socio
$resumen = [];
foreach ($socios as $s) $resumen[$s] = ['aportes' => 0.0, 'retiros' => 0.0];
foreach ($aportes as $a) { $resumen[$a['socio']] ??= ['aportes' => 0.0, 'retiros' => 0.0]; $resumen[$a['socio']]['aportes'] += (float)$a['monto']; }
foreach ($retiros as $r) { $resumen[$r['socio']] ??= ['aportes' => 0.0, 'retiros' => 0.0]; $resumen[$r['socio']]['retiros'] += (float)$r['monto']; }
$totAp = array_sum(array_column($aportes, 'monto'));
$totRe = array_sum(array_column($retiros, 'monto'));

$anios = [];
foreach (array_merge($instalado ? sociosAportes($pdo, $cid) : [], sociosRetiros($pdo, $cid)) as $x) $anios[(int)substr($x['fecha'], 0, 4)] = true;
krsort($anios);
$cuentasHnl = bancosDisponible($pdo) ? array_values(array_filter(bancoCuentas($pdo, $cid, true), fn($c) => $c['moneda'] === 'HNL')) : [];
// Préstamos a socios (todos los años: lo que importa es el saldo)
$spInstalado = spDisponible($pdo);
$prestamos = $spInstalado ? spLista($pdo, $cid) : [];
$spSaldo = array_sum(array_map(fn($x) => $x['saldo'], $prestamos));
$spVencido = array_sum(array_map(fn($x) => $x['vencido_monto'], $prestamos));
$sociosSel = $spInstalado ? (function () use ($pdo, $cid) { $st = $pdo->prepare("SELECT id, nombre, email FROM socios WHERE cliente_id = ? AND activo = 1 ORDER BY id"); $st->execute([$cid]); return $st->fetchAll(PDO::FETCH_ASSOC); })() : [];
$L = fn($v) => 'L ' . number_format((float)$v, 2);
$metodos = ['transferencia' => 'Transferencia', 'deposito' => 'Depósito', 'efectivo' => 'Efectivo', 'cheque' => 'Cheque', 'otro' => 'Otro'];

require_once '../../includes/templates/header.php';
?>

<div class="app-page-header">
    <div>
        <h1 class="app-page-title"><i class="bi bi-person-badge me-2"></i>Socios: aportes, retiros y préstamos</h1>
        <p class="app-page-sub">Aportes: dinero que un socio pone en la empresa (capital). Retiros: gastos personales de un socio que pagó la empresa; se registran como un gasto con naturaleza «Retiro de socio» (botón «Registrar retiro»), con su socio y su comprobante. Ninguno de los dos cuenta en el Estado de resultados.</p>
    </div>
    <?php if ($instalado): ?>
    <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-outline-secondary" id="btnSocio"><i class="bi bi-person-plus me-1"></i> Agregar socio</button>
        <?php if ($spInstalado): ?><button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalPrestamo"><i class="bi bi-cash-coin me-1"></i> Nuevo préstamo</button><?php endif; ?>
        <a class="btn btn-outline-warning" href="gastos?nuevo=retiro"><i class="bi bi-box-arrow-up me-1"></i> Registrar retiro</a>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAporte"><i class="bi bi-plus-circle me-1"></i> Registrar aporte</button>
    </div>
    <?php endif; ?>
</div>

<?php if (!$instalado): ?>
    <div class="alert alert-warning">Falta instalar el registro de socios (migración 2026-10-09_socios_aportes.sql).</div>
<?php else: ?>

<form class="app-card app-card-body mb-3" method="get">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-auto" style="min-width:9rem"><label class="form-label small">Año</label>
            <select class="form-select form-select-sm" name="anio" onchange="this.form.submit()">
                <option value="">Todos</option>
                <?php foreach (array_keys($anios) as $y): ?><option value="<?= $y ?>" <?= $anio === $y ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?>
            </select></div>
    </div>
</form>

<div class="app-stats">
    <div class="app-stat"><div class="app-stat-icon green"><i class="bi bi-box-arrow-in-down"></i></div><div><div class="app-stat-val"><?= $L($totAp) ?></div><div class="app-stat-lbl">Aportes · <?= count($aportes) ?></div></div></div>
    <div class="app-stat"><div class="app-stat-icon amber"><i class="bi bi-box-arrow-up"></i></div><div><div class="app-stat-val"><?= $L($totRe) ?></div><div class="app-stat-lbl">Retiros · <?= count($retiros) ?></div></div></div>
    <?php if ($spInstalado): ?><a href="#" class="app-stat text-decoration-none" data-ir-tab="#tPrestamos"><div class="app-stat-icon <?= $spVencido > 0 ? 'red' : 'purple' ?>"><i class="bi bi-cash-coin"></i></div><div><div class="app-stat-val"><?= $L($spSaldo) ?></div><div class="app-stat-lbl">Préstamos por cobrar<?= $spVencido > 0 ? ' · <span class="text-danger">' . $L($spVencido) . ' vencido</span>' : '' ?></div></div></a><?php endif; ?>
    <div class="app-stat"><div class="app-stat-icon <?= $totAp - $totRe >= 0 ? 'teal' : 'purple' ?>"><i class="bi bi-arrow-left-right"></i></div><div><div class="app-stat-val"><?= $L($totAp - $totRe) ?></div><div class="app-stat-lbl">Neto (aportes − retiros)</div></div></div>
</div>

<div class="app-card mb-3">
    <div class="app-card-header"><span><i class="bi bi-people me-1"></i> Por socio</span></div>
    <div class="table-responsive">
        <table class="table app-table mb-0">
            <thead><tr><th>Socio</th><th class="app-num">Aportes</th><th class="app-num">Retiros</th><th class="app-num">Neto</th></tr></thead>
            <tbody>
                <?php foreach ($resumen as $s => $r): if (!$r['aportes'] && !$r['retiros'] && $s === 'Sin asignar') continue; ?>
                    <tr><td><?= htmlspecialchars($s) ?><?= $s === 'Sin asignar' ? ' <span class="text-muted small">(retiros que no dicen de qué socio son)</span>' : '' ?></td>
                        <td class="app-num"><?= $L($r['aportes']) ?></td><td class="app-num"><?= $L($r['retiros']) ?></td>
                        <td class="app-num fw-semibold <?= $r['aportes'] - $r['retiros'] < 0 ? 'text-danger' : '' ?>"><?= $L($r['aportes'] - $r['retiros']) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tAportes" type="button"><i class="bi bi-box-arrow-in-down me-1"></i>Aportes <span class="badge bg-secondary ms-1"><?= count($aportes) ?></span></button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tRetiros" type="button"><i class="bi bi-box-arrow-up me-1"></i>Retiros <span class="badge bg-secondary ms-1"><?= count($retiros) ?></span></button></li>
    <?php if ($spInstalado): ?><li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tPrestamos" type="button" id="tabPrestamos"><i class="bi bi-cash-coin me-1"></i>Préstamos <span class="badge bg-<?= $spVencido > 0 ? 'danger' : 'secondary' ?> ms-1"><?= count(array_filter($prestamos, fn($x) => $x['prestamo']['estado'] === 'activo')) ?></span></button></li><?php endif; ?>
</ul>
<div class="tab-content">
    <div class="tab-pane fade show active" id="tAportes">
        <div class="app-card"><div class="table-responsive">
            <table class="table app-table mb-0">
                <thead><tr><th>Fecha</th><th>Socio</th><th>Método / ref.</th><th>Notas</th><th class="app-num">Monto</th><th class="text-end">Acciones</th></tr></thead>
                <tbody>
                    <?php if (!$aportes): ?><tr><td colspan="6" class="text-center text-muted py-4">Sin aportes registrados<?= $anio ? " en $anio" : '' ?>.</td></tr><?php endif; ?>
                    <?php foreach ($aportes as $a): ?>
                        <tr><td class="text-nowrap"><?= date('d/m/Y', strtotime($a['fecha'])) ?></td><td><?= htmlspecialchars($a['socio']) ?></td>
                            <td class="small"><?= htmlspecialchars($metodos[$a['metodo']] ?? $a['metodo']) ?><?= $a['referencia'] ? ' · ' . htmlspecialchars($a['referencia']) : '' ?></td>
                            <td class="small text-muted"><?= htmlspecialchars((string)$a['notas']) ?></td>
                            <td class="app-num fw-semibold"><?= $L($a['monto']) ?></td>
                            <td class="text-end text-nowrap">
                                <?php if ($a['archivo_adjunto']): ?><a class="btn btn-sm btn-outline-secondary py-0" href="socio_archivo?id=<?= (int)$a['id'] ?>" target="_blank" title="Comprobante"><i class="bi bi-paperclip"></i></a><?php endif; ?>
                                <button class="btn btn-sm btn-outline-danger py-0 btn-anular" data-id="<?= (int)$a['id'] ?>" title="Anular"><i class="bi bi-slash-circle"></i></button></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div></div>
    </div>
    <div class="tab-pane fade" id="tRetiros">
        <div class="app-card"><div class="table-responsive">
            <table class="table app-table mb-0">
                <thead><tr><th>Fecha</th><th>Socio</th><th>Concepto</th><th class="app-num">Monto</th><th class="text-center">Comprobante</th><th class="text-end">Gasto</th></tr></thead>
                <tbody>
                    <?php if (!$retiros): ?><tr><td colspan="6" class="text-center text-muted py-4">Sin retiros<?= $anio ? " en $anio" : '' ?>. Regístralos con «Registrar retiro».</td></tr><?php endif; ?>
                    <?php foreach ($retiros as $r): ?>
                        <tr><td class="text-nowrap"><?= date('d/m/Y', strtotime($r['fecha'])) ?></td><td><?= htmlspecialchars($r['socio']) ?></td>
                            <td><?= htmlspecialchars($r['descripcion']) ?><?= $r['proveedor'] ? '<div class="small text-muted">' . htmlspecialchars($r['proveedor']) . '</div>' : '' ?></td>
                            <td class="app-num fw-semibold"><?= $L($r['monto']) ?></td>
                            <td class="text-center"><?= $r['archivo_adjunto'] ? '<a href="gasto_archivo?id=' . (int)$r['id'] . '" target="_blank" title="' . htmlspecialchars((string)($r['archivo_nombre'] ?: 'Ver comprobante')) . '"><i class="bi bi-paperclip fs-5"></i></a>' : '<a href="gasto_ver?id=' . (int)$r['id'] . '" class="badge bg-light text-danger border text-decoration-none" title="Abrir el gasto para adjuntarlo">Sin</a>' ?></td>
                            <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary py-0" href="gasto_ver?id=<?= (int)$r['id'] ?>#editar" title="Ver y editar el retiro (el mismo formulario de Gastos)"><i class="bi bi-pencil"></i></a>
                                <a class="btn btn-sm btn-outline-secondary py-0" href="gasto_ver?id=<?= (int)$r['id'] ?>" title="Ver gasto">#<?= (int)$r['id'] ?></a></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div></div>
    </div>
    <?php if ($spInstalado): ?>
    <div class="tab-pane fade" id="tPrestamos">
        <div class="app-card"><div class="table-responsive">
            <table class="table app-table mb-0" data-ordenar>
                <thead><tr><th class="app-n">#</th><th>Fecha</th><th>Socio</th><th>Concepto</th><th class="app-num">Prestado</th><th class="app-num">Pagado</th><th class="app-num">Saldo</th><th>Próxima cuota</th><th>Estado</th><th class="text-end no-orden"></th></tr></thead>
                <tbody>
                    <?php if (!$prestamos): ?><tr><td colspan="10" class="text-center text-muted py-4">Sin préstamos a socios. Regístralos con «Nuevo préstamo».</td></tr><?php endif; ?>
                    <?php foreach ($prestamos as $x): $p = $x['prestamo']; [$eTxt, $eBg, $eFg] = SP_ESTADOS[$p['estado']]; $px = $x['proxima']; ?>
                        <tr data-fila><td class="app-n"></td>
                            <td class="text-nowrap" data-sort="<?= $p['fecha'] ?>"><?= date('d/m/Y', strtotime($p['fecha'])) ?></td>
                            <td><?= htmlspecialchars($p['socio']) ?><?php if (!empty($p['deudor_nombre'])): ?><div class="small text-muted"><i class="bi bi-envelope me-1"></i>a nombre de <?= htmlspecialchars($p['deudor_nombre']) ?></div><?php endif; ?></td>
                            <td><?= htmlspecialchars($p['descripcion']) ?><div class="small text-muted"><?= (int)$p['num_cuotas'] ?> cuotas <?= $p['frecuencia'] === 'quincenal' ? 'quincenales' : 'mensuales' ?> de <?= $L($x['cuotas'][0]['monto'] ?? 0) ?></div></td>
                            <td class="app-num"><?= number_format((float)$p['monto'], 2) ?></td>
                            <td class="app-num text-success"><?= number_format($x['pagado'], 2) ?></td>
                            <td class="app-num fw-semibold"><?= number_format($x['saldo'], 2) ?></td>
                            <td class="text-nowrap small" data-sort="<?= $px['fecha'] ?? '9999' ?>"><?php if ($x['vencidas']): ?><span class="badge" style="background:#fee2e2;color:#991b1b"><?= $x['vencidas'] ?> vencida<?= $x['vencidas'] === 1 ? '' : 's' ?> · <?= $L($x['vencido_monto']) ?></span>
                                <?php elseif ($px): ?><?= date('d/m/Y', strtotime($px['fecha'])) ?> · <?= $L($px['pendiente']) ?><div class="text-muted"><?= $px['dias'] === 0 ? 'hoy' : 'en ' . $px['dias'] . ' d' ?></div><?php else: ?>—<?php endif; ?></td>
                            <td><span class="badge" style="background:<?= $eBg ?>;color:<?= $eFg ?>"><?= $eTxt ?></span></td>
                            <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary py-0" href="socio_prestamo?id=<?= (int)$p['id'] ?>" title="Ver cuotas y registrar pagos"><i class="bi bi-list-check"></i></a>
                                <a class="btn btn-sm btn-outline-danger py-0" href="socio_prestamo_pdf?id=<?= (int)$p['id'] ?>" target="_blank" title="PDF de control"><i class="bi bi-file-earmark-pdf"></i></a></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div></div>
    </div>
    <?php endif; ?>
</div>

<?php if ($spInstalado): ?>
<div class="modal fade" id="modalPrestamo" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-md-down"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-cash-coin me-1"></i> Nuevo préstamo a socio</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <form id="formPrestamo" class="row g-3" novalidate>
                <input type="hidden" name="accion" value="crear">
                <div class="col-md-6"><label class="form-label small">Socio <span class="text-danger">*</span></label>
                    <select class="form-select" name="socio_id" id="spSocio" required><option value="">— Elegir —</option>
                        <?php foreach ($sociosSel as $so): ?><option value="<?= (int)$so['id'] ?>" data-email="<?= htmlspecialchars((string)$so['email']) ?>"><?= htmlspecialchars($so['nombre']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-6"><label class="form-label small">Concepto <span class="text-danger">*</span></label><input class="form-control" name="descripcion" maxlength="255" required placeholder="Ej.: Pago de matrícula vehicular"></div>
                <div class="col-6 col-md-3"><label class="form-label small">Monto (L) <span class="text-danger">*</span></label><input type="number" class="form-control" name="monto" step="0.01" min="0.01" required></div>
                <div class="col-6 col-md-3"><label class="form-label small">Fecha del préstamo</label><input type="date" class="form-control" name="fecha" value="<?= date('Y-m-d') ?>" required></div>
                <div class="col-6 col-md-2"><label class="form-label small">Cuotas</label><input type="number" class="form-control" name="num_cuotas" min="1" max="120" value="12" required></div>
                <div class="col-6 col-md-2"><label class="form-label small">Frecuencia</label><select class="form-select" name="frecuencia"><option value="quincenal">Quincenal (15 y fin de mes)</option><option value="mensual">Mensual</option></select></div>
                <div class="col-md-2"><label class="form-label small">1.ª cuota</label><input type="date" class="form-control" name="primera_cuota" required></div>
                <div class="col-12"><div class="border rounded-3 p-2 small" id="spVista" style="max-height:200px;overflow:auto;background:#f8fafc">Escribe el monto, las cuotas y la fecha de la primera para ver el calendario.</div></div>
                <?php if ($cuentasHnl): ?>
                <div class="col-md-6"><label class="form-label small">Sale de la cuenta</label>
                    <select class="form-select" name="cuenta_id"><option value="">— No registrar en banco —</option>
                        <?php foreach ($cuentasHnl as $cb): ?><option value="<?= (int)$cb['id'] ?>"><?= htmlspecialchars($cb['banco'] . ' ' . $cb['numero']) ?></option><?php endforeach; ?></select></div>
                <?php endif; ?>
                <div class="col-md-6"><label class="form-label small">Referencia de la transferencia</label><input class="form-control" name="referencia" maxlength="80"></div>
                <div class="col-12"><label class="form-label small">Depositado en <span class="text-muted">(cuenta de quien recibe el préstamo; sale en el correo y el PDF como «Entró a la cuenta …»)</span></label><input class="form-control" name="cuenta_destino" maxlength="150" placeholder="Ej.: BAC 742474391"></div>
                <div class="col-12"><label class="form-label small">Comprobantes de la transferencia <span class="text-muted">(uno o varios · JPG, PNG, PDF · máx 5 MB c/u)</span></label><input type="file" class="form-control form-control-sm" name="comprobantes[]" multiple accept=".jpg,.jpeg,.png,.webp,.pdf"></div>
                <div class="col-12"><div class="border rounded-3 p-2">
                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="avisar" value="1" id="spAvisar" checked><label class="form-check-label fw-semibold" for="spAvisar"><i class="bi bi-envelope me-1"></i>Avisar por correo antes de cada cuota y si se atrasa</label></div>
                    <div class="row g-2 mt-1">
                        <div class="col-12"><label class="form-label small mb-0">A nombre de <span class="text-muted">(opcional: si lo devuelve otra persona, los correos y el PDF salen solo con su nombre; en el sistema sigue siendo del socio)</span></label><input class="form-control form-control-sm" name="deudor_nombre" maxlength="150" placeholder="Ej.: José Landa"></div>
                        <div class="col-md-5"><label class="form-label small mb-0">Para</label><input class="form-control form-control-sm" name="aviso_correo" id="spCorreo" placeholder="correo del socio o de esa persona"></div>
                        <div class="col-md-5"><label class="form-label small mb-0">Con copia a <span class="text-muted">(opcional, varios con coma)</span></label><input class="form-control form-control-sm" name="aviso_cc"></div>
                        <div class="col-md-2"><label class="form-label small mb-0">Días antes</label><input type="number" class="form-control form-control-sm" name="aviso_dias" min="0" max="30" value="3"></div>
                    </div>
                    <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="enviar_info" value="1" id="spInfo" checked><label class="form-check-label small" for="spInfo">Enviar ahora la información del préstamo con el cuadro de pagos y el PDF</label></div>
                    <div class="form-text">También va en copia a los correos de Configuración → Correo («Responder a»). Lleva la tabla del préstamo y el PDF.</div></div></div>
                <div class="col-12"><label class="form-label small">Notas</label><textarea class="form-control" name="notas" rows="2"></textarea></div>
            </form>
        </div>
        <div class="modal-footer"><button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" id="btnGuardarPrestamo"><i class="bi bi-check-circle me-1"></i>Registrar préstamo</button></div>
    </div></div>
</div>
<?php endif; ?>

<div class="modal fade" id="modalAporte" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-box-arrow-in-down me-1"></i> Registrar aporte de socio</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <form id="formAporte" enctype="multipart/form-data">
                <input type="hidden" name="accion" value="aporte">
                <div class="row g-3">
                    <div class="col-12"><label class="form-label small">Socio <span class="text-danger">*</span></label>
                        <input class="form-control" name="socio" list="listaSocios" required maxlength="150" placeholder="Elige o escribe el nombre">
                        <datalist id="listaSocios"><?php foreach ($socios as $s): ?><option value="<?= htmlspecialchars($s) ?>"><?php endforeach; ?></datalist></div>
                    <div class="col-6"><label class="form-label small">Fecha <span class="text-danger">*</span></label><input type="date" class="form-control" name="fecha" value="<?= date('Y-m-d') ?>" required></div>
                    <div class="col-6"><label class="form-label small">Monto (L) <span class="text-danger">*</span></label><input type="number" class="form-control" name="monto" step="0.01" min="0.01" required></div>
                    <div class="col-6"><label class="form-label small">Método</label>
                        <select class="form-select" name="metodo"><?php foreach ($metodos as $k => $t): ?><option value="<?= $k ?>"><?= $t ?></option><?php endforeach; ?></select></div>
                    <div class="col-6"><label class="form-label small">Referencia</label><input class="form-control" name="referencia" maxlength="80"></div>
                    <?php if ($cuentasHnl): ?>
                    <div class="col-12"><label class="form-label small">Entra a la cuenta</label>
                        <select class="form-select" name="cuenta_id"><option value="">— No registrar en banco —</option>
                            <?php foreach ($cuentasHnl as $cb): ?><option value="<?= (int)$cb['id'] ?>"><?= htmlspecialchars($cb['banco'] . ' ' . $cb['numero']) ?></option><?php endforeach; ?></select>
                        <div class="form-text">Si la fecha es anterior al saldo inicial de la cuenta, no se crea el depósito (ya está en ese saldo).</div></div>
                    <?php endif; ?>
                    <div class="col-12"><label class="form-label small">Notas</label><textarea class="form-control" name="notas" rows="2"></textarea></div>
                    <div class="col-12"><label class="form-label small">Comprobante <span class="text-muted">(opcional · JPG, PNG, PDF · máx 5 MB)</span></label><input type="file" class="form-control form-control-sm" name="comprobante" accept=".jpg,.jpeg,.png,.webp,.pdf"></div>
                </div>
            </form>
        </div>
        <div class="modal-footer"><button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" id="btnGuardarAporte"><i class="bi bi-check-circle me-1"></i>Registrar aporte</button></div>
    </div></div>
</div>
<?php endif; ?>

<script>
(function () {
    const enviar = async fd => {
        const r = await fetch('includes/socio_accion.php', { method: 'POST', body: fd });
        const d = await r.json().catch(() => ({ success: false, error: 'Respuesta inesperada del servidor (' + r.status + ').' }));
        if (!d.success) throw new Error(d.error);
        return d;
    };
    // Préstamos a socios: calendario de cuotas en vivo y alta
    const fp = document.getElementById('formPrestamo');
    if (fp) {
        const L = n => 'L ' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const sugerir = () => {   // 1.ª cuota sugerida: el siguiente 15 o fin de mes después del préstamo
            if (fp.primera_cuota.value) return;
            const d = new Date(fp.fecha.value + 'T12:00:00'), ult = new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate();
            const s = fp.frecuencia.value === 'mensual' ? new Date(d.getFullYear(), d.getMonth() + 1, Math.min(d.getDate(), new Date(d.getFullYear(), d.getMonth() + 2, 0).getDate()))
                : d.getDate() < 15 ? new Date(d.getFullYear(), d.getMonth(), 15) : d.getDate() < ult ? new Date(d.getFullYear(), d.getMonth(), ult) : new Date(d.getFullYear(), d.getMonth() + 1, 15);
            fp.primera_cuota.value = s.toLocaleDateString('en-CA');
        };
        let t;
        const vista = () => {
            clearTimeout(t);
            t = setTimeout(async () => {
                sugerir();
                const q = new URLSearchParams({ calcular: 1, monto: fp.monto.value, num_cuotas: fp.num_cuotas.value, frecuencia: fp.frecuencia.value, primera_cuota: fp.primera_cuota.value });
                if (!(+fp.monto.value > 0) || !fp.primera_cuota.value) return;
                const d = await fetch('includes/socio_prestamo_accion.php?' + q).then(r => r.json()).catch(() => null);
                if (!d?.success) return;
                const c = d.cuotas, ultima = c[c.length - 1];
                document.getElementById('spVista').innerHTML = `<strong>${c.length} cuotas de ${L(c[0].monto)}</strong> · de ${c[0].fecha.split('-').reverse().join('/')} a ${ultima.fecha.split('-').reverse().join('/')}`
                    + '<table class="table table-sm mb-0 mt-1"><tbody>' + c.map(x => `<tr><td>${x.numero}</td><td>${x.fecha.split('-').reverse().join('/')}</td><td class="text-end">${L(x.monto)}</td></tr>`).join('') + '</tbody></table>';
            }, 250);
        };
        fp.addEventListener('input', vista); fp.addEventListener('change', vista);
        fp.frecuencia.addEventListener('change', () => { fp.primera_cuota.value = ''; vista(); });
        fp.fecha.addEventListener('change', () => { fp.primera_cuota.value = ''; vista(); });
        document.getElementById('spSocio').addEventListener('change', e => { const m = e.target.selectedOptions[0]?.dataset.email || ''; if (m && !document.getElementById('spCorreo').value) document.getElementById('spCorreo').value = m; });
        document.getElementById('modalPrestamo').addEventListener('shown.bs.modal', vista);
        document.getElementById('btnGuardarPrestamo').addEventListener('click', async e => {
            if (!fp.socio_id.value || !fp.descripcion.value.trim() || !(+fp.monto.value > 0) || !fp.primera_cuota.value) return Swal.fire('Faltan datos', 'Socio, concepto, monto y primera cuota son obligatorios.', 'warning');
            const b = e.currentTarget; b.disabled = true;
            try {
                const r = await fetch('includes/socio_prestamo_accion.php', { method: 'POST', body: new FormData(fp) });
                const d = await r.json().catch(() => ({ success: false, error: 'Respuesta inesperada (' + r.status + ').' }));
                if (!d.success) throw new Error(d.error);
                if (/No se pudo enviar/.test(d.message)) await Swal.fire('Préstamo registrado', d.message, 'warning');
                location.href = 'socio_prestamo?id=' + d.id;
            } catch (err) { Swal.fire('No se pudo registrar', err.message, 'error'); b.disabled = false; }
        });
    }
    document.querySelectorAll('[data-ir-tab]').forEach(a => a.addEventListener('click', e => { e.preventDefault(); bootstrap.Tab.getOrCreateInstance(document.querySelector('[data-bs-target="' + a.dataset.irTab + '"]')).show(); }));
    if (location.hash === '#prestamos') document.getElementById('tabPrestamos')?.click();
    document.getElementById('btnGuardarAporte')?.addEventListener('click', async e => {
        const f = document.getElementById('formAporte');
        if (!f.reportValidity()) return;
        const b = e.currentTarget; b.disabled = true;
        try { const d = await enviar(new FormData(f)); await Swal.fire({ icon: 'success', title: 'Listo', text: d.message }); location.reload(); }
        catch (err) { Swal.fire('No se pudo registrar', err.message, 'error'); b.disabled = false; }
    });
    document.getElementById('btnSocio')?.addEventListener('click', async () => {
        const { value } = await Swal.fire({ title: 'Agregar socio', input: 'text', inputLabel: 'Nombre completo', showCancelButton: true, confirmButtonText: 'Agregar', cancelButtonText: 'Cancelar', inputValidator: v => !v.trim() && 'Escribe el nombre' });
        if (!value) return;
        const fd = new FormData(); fd.append('accion', 'socio'); fd.append('nombre', value);
        try { await enviar(fd); location.reload(); } catch (err) { Swal.fire('No se pudo agregar', err.message, 'error'); }
    });
    document.querySelectorAll('.btn-anular').forEach(b => b.addEventListener('click', async () => {
        const { value } = await Swal.fire({ title: 'Anular aporte', input: 'text', inputLabel: 'Motivo', icon: 'warning', showCancelButton: true, confirmButtonText: 'Anular', cancelButtonText: 'Cancelar', confirmButtonColor: '#dc2626', inputValidator: v => !v.trim() && 'Escribe el motivo' });
        if (!value) return;
        const fd = new FormData(); fd.append('accion', 'anular'); fd.append('id', b.dataset.id); fd.append('motivo', value);
        try { await enviar(fd); location.reload(); } catch (err) { Swal.fire('No se pudo anular', err.message, 'error'); }
    }));
})();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
