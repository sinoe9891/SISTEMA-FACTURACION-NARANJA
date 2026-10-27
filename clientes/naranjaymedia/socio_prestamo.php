<?php
// Socios → Préstamos → un préstamo: cuotas (con su estado), pagos (registrar, editar, anular), avisos por correo, PDF de
// control, editar el préstamo, pasar el saldo a retiro o anularlo.
$titulo = 'Préstamo a socio';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/socios_prestamos.php';

$cid = (int)cliente_actual();
$id = (int)($_GET['id'] ?? 0);
if (!spDisponible($pdo)) { header('Location: socios'); exit; }
try {
    $det = spDetalle($pdo, $cid, $id);
} catch (Throwable $e) {
    header('Location: socios#prestamos');
    exit;
}
$p = $det['prestamo'];
$esAdmin = in_array(USUARIO_ROL, ['admin', 'superadmin'], true);
$abierto = $p['estado'] === 'activo' || $p['estado'] === 'pagado';
$cuentasHnl = bancosDisponible($pdo) ? array_values(array_filter(bancoCuentas($pdo, $cid, true), fn($c) => $c['moneda'] === 'HNL')) : [];
$avisos = [];
try {
    $ids = array_merge([$id], array_map(fn($c) => (int)$c['id'], $det['cuotas']));
    $st = $pdo->prepare("SELECT tipo, destinatario AS para, asunto, estado, error, creado_en FROM correos_enviados WHERE cliente_id = ? AND tipo LIKE 'prestamo_socio_%' AND referencia_id IN (" . implode(',', $ids) . ") ORDER BY id DESC LIMIT 20");
    $st->execute([$cid]);
    $avisos = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $avisos = [];
}
$archivos = spArchivos($pdo, $cid, $id);   // comprobantes del préstamo y de cada pago
$L = fn($v) => 'L ' . number_format((float)$v, 2);
$e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
$listaArch = function (array $lista, bool $quitar) use ($e): string {
    if (!$lista) return '<span class="text-muted">Sin comprobantes.</span>';
    return implode('', array_map(fn($a) => '<div class="d-flex align-items-center gap-2 py-1"><i class="bi bi-' . (preg_match('/\.pdf$/i', $a['archivo']) ? 'file-earmark-pdf text-danger' : 'image text-primary') . '"></i>'
        . '<a href="socio_prestamo_archivo?archivo=' . (int)$a['id'] . '" target="_blank" class="text-truncate flex-grow-1">' . $e($a['nombre']) . '</a>'
        . ($quitar ? '<button type="button" class="btn btn-sm btn-link text-danger p-0 sp-quitar-arch" data-id="' . (int)$a['id'] . '" title="Quitar"><i class="bi bi-x-lg"></i></button>' : '') . '</div>', $lista));
};
[$eTxt, $eBg, $eFg] = SP_ESTADOS[$p['estado']];
$px = $det['proxima'];
$titulo = 'Préstamo #' . $id . ' · ' . $p['socio'];
require_once '../../includes/templates/header.php';
?>

<div class="app-page-header">
    <div>
        <h1 class="app-page-title"><i class="bi bi-cash-coin me-2"></i>Préstamo #<?= $id ?> · <?= $e($p['socio']) ?> <span class="badge align-middle fs-6" style="background:<?= $eBg ?>;color:<?= $eFg ?>"><?= $eTxt ?></span></h1>
        <p class="app-page-sub"><?php if (!empty($p['deudor_nombre'])): ?><span class="badge text-bg-light border fw-normal me-1"><i class="bi bi-envelope me-1"></i>Correos y PDF a nombre de <?= $e($p['deudor_nombre']) ?></span><?php endif; ?><?= $e($p['descripcion']) ?> · <?= (int)$p['num_cuotas'] ?> cuotas <?= $p['frecuencia'] === 'quincenal' ? 'quincenales (15 y fin de mes)' : 'mensuales' ?> desde el <?= date('d/m/Y', strtotime($p['primera_cuota'])) ?></p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="socios#prestamos" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> Préstamos</a>
        <a href="socio_prestamo_pdf?id=<?= $id ?>" target="_blank" class="btn btn-outline-danger"><i class="bi bi-file-earmark-pdf me-1"></i> PDF de control</a>
        <?php if ($esAdmin && $p['estado'] === 'activo'): ?>
            <div class="btn-group"><button class="btn btn-outline-primary" id="spInfo"><i class="bi bi-envelope-paper me-1"></i> Enviar información</button>
                <button class="btn btn-outline-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-label="Más correos"></button>
                <div class="dropdown-menu dropdown-menu-end"><button class="dropdown-item" id="spEnviar"><i class="bi bi-bell me-2"></i>Recordatorio de la próxima cuota</button></div></div>
            <button class="btn btn-success" id="spPagar"><i class="bi bi-plus-circle me-1"></i> Registrar pago</button>
        <?php endif; ?>
        <?php if ($esAdmin && $abierto): ?>
        <div class="dropdown"><button class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button>
            <div class="dropdown-menu dropdown-menu-end">
                <button class="dropdown-item" id="spEditar"><i class="bi bi-pencil me-2"></i>Editar préstamo y cuotas</button>
                <?php if ($p['estado'] === 'activo'): ?>
                    <button class="dropdown-item text-warning" id="spCondonar"><i class="bi bi-box-arrow-up me-2"></i>No lo devolverá: pasar saldo a retiro</button>
                    <?php if (!$det['pagado']): ?><button class="dropdown-item text-danger" id="spAnular"><i class="bi bi-slash-circle me-2"></i>Anular préstamo</button><?php endif; ?>
                <?php endif; ?>
            </div></div>
        <?php endif; ?>
    </div>
</div>

<div class="app-stats">
    <div class="app-stat"><div class="app-stat-icon purple"><i class="bi bi-cash-stack"></i></div><div><div class="app-stat-val"><?= $L($p['monto']) ?></div><div class="app-stat-lbl">Prestado el <?= date('d/m/Y', strtotime($p['fecha'])) ?></div></div></div>
    <div class="app-stat"><div class="app-stat-icon green"><i class="bi bi-check2-circle"></i></div><div><div class="app-stat-val"><?= $L($det['pagado']) ?></div><div class="app-stat-lbl">Pagado · <?= count(array_filter($det['pagos'], fn($g) => !(int)$g['anulado'])) ?> pago(s)</div></div></div>
    <div class="app-stat"><div class="app-stat-icon <?= $det['vencidas'] ? 'red' : 'teal' ?>"><i class="bi bi-hourglass-split"></i></div><div><div class="app-stat-val"><?= $L($det['saldo']) ?></div><div class="app-stat-lbl">Saldo<?= $det['vencidas'] ? ' · <span class="text-danger">' . $det['vencidas'] . ' vencida(s) por ' . $L($det['vencido_monto']) . '</span>' : '' ?></div></div></div>
    <div class="app-stat"><div class="app-stat-icon amber"><i class="bi bi-calendar-event"></i></div><div><div class="app-stat-val"><?= $px ? date('d/m/Y', strtotime($px['fecha'])) : '—' ?></div><div class="app-stat-lbl"><?= $px ? 'Próxima cuota · ' . $L($px['pendiente']) . ($px['dias'] >= 0 ? ' · en ' . $px['dias'] . ' d' : '') : 'Sin cuotas pendientes' ?></div></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="app-card mb-3">
            <div class="app-card-header"><span><i class="bi bi-list-ol me-1"></i> Cuotas</span><small class="text-muted">Los pagos se aplican a las cuotas en orden</small></div>
            <div class="table-responsive"><table class="table app-table mb-0">
                <thead><tr><th class="text-center">N.º</th><th>Fecha</th><th class="app-num">Cuota</th><th class="app-num">Pagado</th><th class="app-num">Saldo al pagarla</th><th>Estado</th></tr></thead>
                <tbody>
                <?php foreach ($det['cuotas'] as $c): [$cTxt, $cBg, $cFg] = SP_CUOTA_ESTADOS[$c['estado']]; ?>
                    <tr><td class="text-center"><?= (int)$c['numero'] ?></td>
                        <td class="text-nowrap"><?= date('d/m/Y', strtotime($c['fecha'])) ?><?php if ($c['estado'] === 'vencida'): ?><div class="small text-danger">hace <?= abs($c['dias']) ?> d</div><?php elseif ($c['estado'] === 'proxima'): ?><div class="small text-muted"><?= $c['dias'] === 0 ? 'hoy' : 'en ' . $c['dias'] . ' d' ?></div><?php endif; ?></td>
                        <td class="app-num"><?= number_format((float)$c['monto'], 2) ?></td>
                        <td class="app-num <?= $c['pagado'] > 0 ? 'text-success' : 'text-muted' ?>"><?= $c['pagado'] > 0 ? number_format($c['pagado'], 2) : '—' ?></td>
                        <td class="app-num"><?= number_format($c['saldo'], 2) ?></td>
                        <td><span class="badge" style="background:<?= $cBg ?>;color:<?= $cFg ?>"><?= $cTxt ?></span></td></tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr class="fw-bold"><td colspan="2">Totales</td><td class="app-num"><?= number_format((float)$p['monto'], 2) ?></td><td class="app-num"><?= number_format($det['pagado'], 2) ?></td><td class="app-num"><?= number_format($det['saldo'], 2) ?></td><td></td></tr></tfoot>
            </table></div>
        </div>

        <div class="app-card mb-3">
            <div class="app-card-header"><span><i class="bi bi-wallet2 me-1"></i> Pagos recibidos</span></div>
            <div class="table-responsive"><table class="table app-table mb-0">
                <thead><tr><th>Fecha</th><th class="app-num">Monto</th><th>Método</th><th>Cuenta</th><th class="text-center">Comprobante</th><th class="text-end"></th></tr></thead>
                <tbody>
                <?php if (!$det['pagos']): ?><tr><td colspan="6" class="text-center text-muted py-3">Todavía no hay pagos.</td></tr><?php endif; ?>
                <?php foreach ($det['pagos'] as $g): $anul = (int)$g['anulado']; ?>
                    <tr class="<?= $anul ? 'text-muted text-decoration-line-through' : '' ?>">
                        <td class="text-nowrap"><?= date('d/m/Y', strtotime($g['fecha'])) ?></td>
                        <td class="app-num fw-semibold"><?= number_format((float)$g['monto'], 2) ?></td>
                        <td class="small"><?= $e(SP_METODOS[$g['metodo']] ?? $g['metodo']) ?><?= $g['referencia'] ? '<div class="text-muted">' . $e($g['referencia']) . '</div>' : '' ?><?= $g['notas'] ? '<div class="text-muted">' . $e($g['notas']) . '</div>' : '' ?></td>
                        <td class="small"><?= $g['cuenta'] ? $e($g['cuenta']) : '<span class="text-muted">—</span>' ?></td>
                        <?php $ap = $archivos[(int)$g['id']] ?? []; ?>
                        <td class="text-center"><?= $ap ? implode(' ', array_map(fn($a) => '<a href="socio_prestamo_archivo?archivo=' . (int)$a['id'] . '" target="_blank" title="' . $e($a['nombre']) . '"><i class="bi bi-paperclip fs-5"></i></a>', $ap)) : '<span class="badge bg-light text-secondary border">Sin</span>' ?></td>
                        <td class="text-end text-nowrap"><?php if ($esAdmin && !$anul && $abierto): ?>
                            <button class="btn btn-sm btn-outline-primary py-0 sp-editar-pago" data-pago='<?= $e(json_encode(['id' => (int)$g['id'], 'fecha' => $g['fecha'], 'monto' => (float)$g['monto'], 'metodo' => $g['metodo'], 'referencia' => (string)$g['referencia'], 'cuenta_id' => (int)$g['cuenta_id'] ?: '', 'notas' => (string)$g['notas'], 'archivos' => array_map(fn($a) => ['id' => (int)$a['id'], 'nombre' => $a['nombre']], $archivos[(int)$g['id']] ?? [])])) ?>' title="Editar"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-outline-danger py-0 sp-anular-pago" data-id="<?= (int)$g['id'] ?>" title="Anular (quita el pago y su depósito)"><i class="bi bi-slash-circle"></i></button>
                        <?php endif; ?><?= $anul ? '<span class="small">Anulado</span>' : '' ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="app-card mb-3">
            <div class="app-card-header"><span><i class="bi bi-info-circle me-1"></i> Datos</span></div>
            <div class="app-card-body small">
                <div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">Salió de</span><span><?= $p['cuenta'] ? $e($p['cuenta']) : 'No registrado en banco' ?><?= $p['referencia'] ? ' · ref. ' . $e($p['referencia']) : '' ?></span></div>
                <div class="py-1 border-bottom"><div class="text-muted mb-1">Comprobantes de la transferencia</div><?= $listaArch($archivos['prestamo'], $esAdmin && $abierto) ?>
                    <?php if ($esAdmin && $abierto): ?><form class="sp-subir d-flex gap-2 mt-1" data-pago=""><input type="file" name="comprobantes[]" multiple class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.webp,.pdf"><button class="btn btn-sm btn-outline-primary text-nowrap"><i class="bi bi-upload"></i> Agregar</button></form><?php endif; ?></div>
                <?php if ($p['estado'] === 'condonado'): ?><div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted">Pasado a retiro</span><span><?= $L($p['condonado_monto']) ?> · <a href="gasto_ver?id=<?= (int)$p['condonado_gasto_id'] ?>">gasto N.º <?= (int)$p['condonado_gasto_id'] ?></a></span></div><?php endif; ?>
                <?php if ($p['notas']): ?><div class="pt-2 text-muted"><?= nl2br($e($p['notas'])) ?></div><?php endif; ?>
            </div>
        </div>

        <div class="app-card mb-3">
            <div class="app-card-header"><span><i class="bi bi-envelope me-1"></i> Avisos por correo</span></div>
            <div class="app-card-body">
                <form id="fAvisos" class="row g-2">
                    <input type="hidden" name="accion" value="avisos"><input type="hidden" name="id" value="<?= $id ?>">
                    <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="avisar" value="1" id="avAct" <?= (int)$p['avisar'] ? 'checked' : '' ?> <?= $esAdmin ? '' : 'disabled' ?>><label class="form-check-label" for="avAct">Avisar <input type="number" name="aviso_dias" min="0" max="30" value="<?= (int)$p['aviso_dias'] ?>" class="form-control form-control-sm d-inline-block mx-1" style="width:64px"> días antes de cada cuota y si se atrasa</label></div></div>
                    <div class="col-12"><label class="form-label small mb-0">A nombre de <span class="text-muted">(opcional)</span></label><input class="form-control form-control-sm" name="deudor_nombre" maxlength="150" value="<?= $e($p['deudor_nombre']) ?>" placeholder="Vacío = a nombre del socio">
                        <div class="form-text">Si lo devuelve otra persona: los correos y el PDF salen solo con su nombre. En el sistema el préstamo sigue siendo de <?= $e($p['socio']) ?>.</div></div>
                    <div class="col-12"><label class="form-label small mb-0">Para</label><input class="form-control form-control-sm" name="aviso_correo" value="<?= $e($p['aviso_correo'] ?: $p['socio_email']) ?>" placeholder="correo del socio o de esa persona"></div>
                    <div class="col-12"><label class="form-label small mb-0">Con copia a <span class="text-muted">(varios con coma)</span></label><input class="form-control form-control-sm" name="aviso_cc" value="<?= $e($p['aviso_cc']) ?>"></div>
                    <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="guardar_en_socio" value="1" id="avSocio"><label class="form-check-label small" for="avSocio">Guardar este correo como el del socio (para sus próximos préstamos)</label></div></div>
                    <?php if ($esAdmin): ?><div class="col-12 text-end"><button class="btn btn-sm btn-primary">Guardar avisos</button></div><?php endif; ?>
                    <div class="col-12 form-text">También van en copia los correos de Configuración → Correo («Responder a»). Llevan la tabla del préstamo y el PDF.</div>
                </form>
                <?php if ($avisos): ?>
                    <div class="small fw-semibold mt-3 mb-1">Últimos avisos</div>
                    <ul class="small list-unstyled mb-0">
                        <?php foreach ($avisos as $a): ?><li class="py-1 border-bottom"><i class="bi bi-<?= $a['estado'] === 'enviado' ? 'check2 text-success' : 'x-circle text-danger' ?> me-1"></i><?= date('d/m/Y H:i', strtotime($a['creado_en'])) ?> · <?= $e($a['asunto']) ?><div class="text-muted">a <?= $e($a['para']) ?><?= $a['error'] ? ' · ' . $e($a['error']) : '' ?></div></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($esAdmin): ?>
<!-- Registrar / editar pago -->
<div class="modal fade" id="mPago" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" id="fPago" novalidate>
    <div class="modal-header"><h5 class="modal-title" id="mPagoTit">Registrar pago</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body row g-2">
        <input type="hidden" name="accion" value="pagar"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="pago_id">
        <div class="col-12 small text-muted" id="pAyuda">Saldo: <strong><?= $L($det['saldo']) ?></strong><?= $px ? ' · próxima cuota ' . $L($px['pendiente']) . ' el ' . date('d/m/Y', strtotime($px['fecha'])) : '' ?>. Puedes pagar una cuota, varias o un abono: se aplica en orden.</div>
        <div class="col-6"><label class="form-label small">Fecha *</label><input type="date" class="form-control" name="fecha" required></div>
        <div class="col-6"><label class="form-label small">Monto (L) *</label><input type="number" class="form-control" name="monto" step="0.01" min="0.01" required></div>
        <div class="col-6"><label class="form-label small">Método</label><select class="form-select" name="metodo"><?php foreach (SP_METODOS as $k => $t): ?><option value="<?= $k ?>"><?= $t ?></option><?php endforeach; ?></select></div>
        <div class="col-6"><label class="form-label small">Referencia</label><input class="form-control" name="referencia" maxlength="80"></div>
        <?php if ($cuentasHnl): ?><div class="col-12"><label class="form-label small">Entró a la cuenta</label><select class="form-select" name="cuenta_id"><option value="">— No registrar en banco —</option>
            <?php foreach ($cuentasHnl as $cb): ?><option value="<?= (int)$cb['id'] ?>"><?= $e($cb['banco'] . ' ' . $cb['numero']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div class="col-12"><label class="form-label small">Notas</label><input class="form-control" name="notas" maxlength="255"></div>
        <div class="col-12"><label class="form-label small">Comprobantes <span class="text-muted">(uno o varios · JPG, PNG, PDF · máx 5 MB c/u)</span></label><div class="small mb-1" id="pArchivo"></div><input type="file" class="form-control form-control-sm" name="comprobantes[]" multiple accept=".jpg,.jpeg,.png,.webp,.pdf"></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-success">Guardar</button></div>
</form></div></div>

<!-- Editar préstamo -->
<div class="modal fade" id="mEditar" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-scrollable"><form class="modal-content" id="fEditar" novalidate>
    <div class="modal-header"><h5 class="modal-title">Editar préstamo #<?= $id ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body row g-2">
        <input type="hidden" name="accion" value="editar"><input type="hidden" name="id" value="<?= $id ?>">
        <div class="col-12"><label class="form-label small">Concepto *</label><input class="form-control" name="descripcion" maxlength="255" value="<?= $e($p['descripcion']) ?>" required></div>
        <div class="col-6 col-md-3"><label class="form-label small">Monto (L) *</label><input type="number" class="form-control" name="monto" step="0.01" min="0.01" value="<?= (float)$p['monto'] ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label small">Fecha del préstamo</label><input type="date" class="form-control" name="fecha" value="<?= $p['fecha'] ?>"></div>
        <div class="col-4 col-md-2"><label class="form-label small">Cuotas</label><input type="number" class="form-control" name="num_cuotas" min="1" max="120" value="<?= (int)$p['num_cuotas'] ?>"></div>
        <div class="col-4 col-md-2"><label class="form-label small">Frecuencia</label><select class="form-select" name="frecuencia"><option value="quincenal" <?= $p['frecuencia'] === 'quincenal' ? 'selected' : '' ?>>Quincenal</option><option value="mensual" <?= $p['frecuencia'] === 'mensual' ? 'selected' : '' ?>>Mensual</option></select></div>
        <div class="col-4 col-md-2"><label class="form-label small">1.ª cuota</label><input type="date" class="form-control" name="primera_cuota" value="<?= $p['primera_cuota'] ?>"></div>
        <div class="col-12"><div class="border rounded-3 p-2 small" id="eVista" style="max-height:180px;overflow:auto;background:#f8fafc"></div></div>
        <?php if ($cuentasHnl): ?><div class="col-md-6"><label class="form-label small">Salió de la cuenta</label><select class="form-select" name="cuenta_id"><option value="">— No registrar en banco —</option>
            <?php foreach ($cuentasHnl as $cb): ?><option value="<?= (int)$cb['id'] ?>" <?= (int)$p['cuenta_id'] === (int)$cb['id'] ? 'selected' : '' ?>><?= $e($cb['banco'] . ' ' . $cb['numero']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div class="col-md-6"><label class="form-label small">Referencia</label><input class="form-control" name="referencia" maxlength="80" value="<?= $e($p['referencia']) ?>"></div>
        <div class="col-12"><label class="form-label small">Comprobantes de la transferencia</label><div class="small border rounded-2 px-2 mb-1"><?= $listaArch($archivos['prestamo'], true) ?></div>
            <input type="file" class="form-control form-control-sm" name="comprobantes[]" multiple accept=".jpg,.jpeg,.png,.webp,.pdf"><div class="form-text">Agrega uno o varios; los que ya están se conservan (quítalos con la ×).</div></div>
        <div class="col-12"><label class="form-label small">Notas</label><textarea class="form-control" name="notas" rows="2"><?= $e($p['notas']) ?></textarea></div>
        <div class="col-12 form-text">Al guardar, las cuotas se vuelven a calcular y lo ya pagado (<?= $L($det['pagado']) ?>) se aplica de nuevo en orden. Si cambias el monto, la cuenta o la fecha, también se ajusta la salida del banco.</div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary">Guardar cambios</button></div>
</form></div></div>
<?php endif; ?>

<script>
(() => {
    const ACC = 'includes/socio_prestamo_accion.php', ID = <?= $id ?>;
    const post = async fd => { const r = await fetch(ACC, { method: 'POST', body: fd }); const d = await r.json().catch(() => ({ success: false, error: 'Respuesta inesperada (' + r.status + ').' })); if (!d.success) throw new Error(d.error); return d; };
    const listo = d => Swal.fire({ icon: 'success', title: d.message, timer: 1500, showConfirmButton: false }).then(() => location.reload());
    const err = (t, e) => Swal.fire(t, e.message, 'error');
    const accion = async (datos, titulo) => { const fd = new FormData(); Object.entries(datos).forEach(([k, v]) => fd.append(k, v)); try { listo(await post(fd)); } catch (e) { err(titulo, e); } };

    // Pagos: registrar y editar en el mismo formulario
    const fp = document.getElementById('fPago');
    if (fp) {
        const mp = new bootstrap.Modal(document.getElementById('mPago'));
        document.getElementById('spPagar')?.addEventListener('click', () => {
            fp.reset(); fp.accion.value = 'pagar'; fp.pago_id.value = ''; document.getElementById('mPagoTit').textContent = 'Registrar pago';
            fp.fecha.value = new Date().toLocaleDateString('en-CA'); fp.monto.value = <?= json_encode($px ? $px['pendiente'] : $det['saldo']) ?>;
            document.getElementById('pArchivo').textContent = ''; mp.show();
        });
        document.querySelectorAll('.sp-editar-pago').forEach(b => b.addEventListener('click', () => {
            const g = JSON.parse(b.dataset.pago);
            fp.reset(); fp.accion.value = 'editar_pago'; fp.pago_id.value = g.id; document.getElementById('mPagoTit').textContent = 'Editar pago';
            for (const k of ['fecha', 'monto', 'metodo', 'referencia', 'notas']) fp[k].value = g[k] ?? '';
            if (fp.cuenta_id) fp.cuenta_id.value = g.cuenta_id;
            document.getElementById('pArchivo').innerHTML = g.archivos.length ? g.archivos.map(a => '<div class="d-flex gap-2"><a href="socio_prestamo_archivo?archivo=' + a.id + '" target="_blank" class="flex-grow-1">' + a.nombre.replace(/[<>&"]/g, '') + '</a><button type="button" class="btn btn-sm btn-link text-danger p-0 sp-quitar-arch" data-id="' + a.id + '" title="Quitar"><i class="bi bi-x-lg"></i></button></div>').join('') + '<div class="text-muted">Los que agregues se suman a estos.</div>' : '';
            mp.show();
        }));
        fp.addEventListener('submit', async e => {
            e.preventDefault();
            if (!fp.fecha.value || !(+fp.monto.value > 0)) return Swal.fire('Faltan datos', 'Fecha y monto son obligatorios.', 'warning');
            try { listo(await post(new FormData(fp))); } catch (e2) { err('No se pudo guardar', e2); }
        });
    }
    document.querySelectorAll('.sp-anular-pago').forEach(b => b.addEventListener('click', async () => {
        const { value } = await Swal.fire({ title: 'Anular pago', text: 'Se quita del saldo y se anula su depósito en el banco.', input: 'text', inputLabel: 'Motivo', icon: 'warning', showCancelButton: true, confirmButtonText: 'Anular', cancelButtonText: 'Cancelar', confirmButtonColor: '#dc2626', inputValidator: v => !v.trim() && 'Escribe el motivo' });
        if (value) accion({ accion: 'anular_pago', pago_id: b.dataset.id, motivo: value }, 'No se pudo anular');
    }));

    // Editar préstamo (con vista previa de las cuotas)
    const fe = document.getElementById('fEditar');
    if (fe) {
        const me = new bootstrap.Modal(document.getElementById('mEditar'));
        const L = n => 'L ' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const vista = async () => {
            const q = new URLSearchParams({ calcular: 1, monto: fe.monto.value, num_cuotas: fe.num_cuotas.value, frecuencia: fe.frecuencia.value, primera_cuota: fe.primera_cuota.value });
            const d = await fetch(ACC + '?' + q).then(r => r.json()).catch(() => null);
            if (d?.success) document.getElementById('eVista').innerHTML = `<strong>${d.cuotas.length} cuotas de ${L(d.cuotas[0].monto)}</strong> · hasta ${d.cuotas[d.cuotas.length - 1].fecha.split('-').reverse().join('/')}`;
        };
        fe.addEventListener('change', vista); fe.addEventListener('input', vista);
        document.getElementById('spEditar')?.addEventListener('click', () => { me.show(); vista(); });
        fe.addEventListener('submit', async e => { e.preventDefault(); try { listo(await post(new FormData(fe))); } catch (e2) { err('No se pudo guardar', e2); } });
    }
    document.getElementById('spCondonar')?.addEventListener('click', async () => {
        const r = await Swal.fire({ title: 'Pasar saldo a retiro', icon: 'warning', showCancelButton: true, confirmButtonText: 'Pasar a retiro', cancelButtonText: 'Cancelar',
            html: 'El saldo de <strong><?= $L($det['saldo']) ?></strong> no se devolverá: queda como <strong>retiro de socio</strong> (un gasto con su socio) y el préstamo se cierra.<input id="cMot" class="swal2-input" placeholder="Motivo"><input id="cFec" type="date" class="swal2-input" value="' + new Date().toLocaleDateString('en-CA') + '">',
            preConfirm: () => { const m = document.getElementById('cMot').value.trim(); if (!m) return Swal.showValidationMessage('Escribe el motivo'); return { m, f: document.getElementById('cFec').value }; } });
        if (r.isConfirmed) accion({ accion: 'condonar', id: ID, motivo: r.value.m, fecha: r.value.f }, 'No se pudo');
    });
    document.getElementById('spAnular')?.addEventListener('click', async () => {
        const { value } = await Swal.fire({ title: 'Anular préstamo', text: 'Se anula también su salida del banco.', input: 'text', inputLabel: 'Motivo', icon: 'warning', showCancelButton: true, confirmButtonText: 'Anular', cancelButtonText: 'Cancelar', confirmButtonColor: '#dc2626', inputValidator: v => !v.trim() && 'Escribe el motivo' });
        if (value) accion({ accion: 'anular', id: ID, motivo: value }, 'No se pudo anular');
    });
    document.getElementById('spInfo')?.addEventListener('click', async e => {
        const r = await Swal.fire({ icon: 'question', title: 'Enviar información del préstamo', html: 'Se envía a <strong>' + <?= json_encode($p['aviso_correo'] ?: (string)$p['socio_email']) ?>.replace(/[<>&]/g, '') + '</strong>' + <?= json_encode($p['aviso_cc'] ? ' con copia a ' . $p['aviso_cc'] : '') ?>.replace(/[<>&]/g, '') + ': datos del préstamo, cuadro de pagos, cuentas para pagar y el PDF.',
            showCancelButton: true, confirmButtonText: 'Enviar', cancelButtonText: 'Cancelar' });
        if (!r.isConfirmed) return;
        const b = e.currentTarget; b.disabled = true;
        try { const fd = new FormData(); fd.append('accion', 'enviar_info'); fd.append('id', ID); listo(await post(fd)); } catch (e2) { err('No se pudo enviar', e2); b.disabled = false; }
    });
    document.getElementById('spEnviar')?.addEventListener('click', async e => {
        e.currentTarget.disabled = true;
        try { const fd = new FormData(); fd.append('accion', 'enviar'); fd.append('id', ID); listo(await post(fd)); } catch (e2) { err('No se pudo enviar', e2); e.currentTarget.disabled = false; }
    });
    // Comprobantes: agregar (al préstamo) y quitar (del préstamo o de un pago)
    document.querySelectorAll('.sp-subir').forEach(fs => fs.addEventListener('submit', async e => {
        e.preventDefault();
        const fd = new FormData(fs); fd.append('accion', 'archivos'); fd.append('id', ID); if (fs.dataset.pago) fd.append('pago_id', fs.dataset.pago);
        if (!fs.querySelector('input[type=file]').files.length) return Swal.fire('Elige uno o varios archivos', '', 'info');
        try { listo(await post(fd)); } catch (e2) { err('No se pudo subir', e2); }
    }));
    document.addEventListener('click', async e => {
        const b = e.target.closest('.sp-quitar-arch'); if (!b) return;
        if (!(await Swal.fire({ icon: 'warning', title: '¿Quitar este comprobante?', showCancelButton: true, confirmButtonText: 'Quitar', cancelButtonText: 'Cancelar' })).isConfirmed) return;
        accion({ accion: 'archivo_quitar', archivo_id: b.dataset.id }, 'No se pudo quitar');
    });
    document.getElementById('fAvisos')?.addEventListener('submit', async e => { e.preventDefault(); try { listo(await post(new FormData(e.target))); } catch (e2) { err('No se pudo guardar', e2); } });
})();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
