<?php
// Licencias y suscripciones: las de la empresa (gasto) y las de clientes (se pagan y se les cobran con comisión).
$titulo = 'Licencias';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/licencias.php';

if (!permisoPuede($pdo, 'licencias')) {
    header('Location: dashboard');
    exit;
}
$cid = (int)cliente_actual();
$instalado = licenciasDisponible($pdo);
if ($instalado) {
    try { $pdo->beginTransaction(); licenciasSincronizar($pdo, $cid, (int)USUARIO_ID); $pdo->commit(); }
    catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('licencias.php: ' . $e->getMessage()); }
}
$lista = $instalado ? licenciasLista($pdo, $cid) : [];
// Empresas de un mismo contrato rotativo (p. ej. Texaco): la licencia se le cobra a cualquiera de ellas
require_once '../../includes/gastos_cobrar.php';
$gruposLic = gastosGruposReceptores($pdo, (int)$cid);
// Proveedores (logo junto al nombre)
require_once '../../includes/proveedores.php';
$provPorId = [];
foreach (proveedoresOpciones($pdo, (int)$cid) as $pv) $provPorId[(int)$pv['id']] = $pv;
// Última factura en la que se le cobró cada licencia al cliente (por sus renovaciones pagadas)
$ultimoCobro = [];
try {
    $st = $pdo->prepare("SELECT g.licencia_id, f.id, f.correlativo, DATE(f.fecha_emision) AS fecha, f.pagada FROM gastos g JOIN facturas f ON f.id = g.cobrado_factura_id
                         WHERE g.cliente_id = ? AND g.licencia_id IS NOT NULL ORDER BY f.fecha_emision");
    $st->execute([$cid]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) $ultimoCobro[(int)$u['licencia_id']] = $u;
    if (licenciasCobrosDisponible($pdo)) {
        // Cobros anuales (licencias pagadas por varios años): también cuentan como último cobro
        $st = $pdo->prepare("SELECT lc.licencia_id, f.id, f.correlativo, DATE(f.fecha_emision) AS fecha, f.pagada FROM licencias_cobros lc JOIN facturas f ON f.id = lc.factura_id WHERE lc.cliente_id = ? ORDER BY f.fecha_emision");
        $st->execute([$cid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) if (!isset($ultimoCobro[(int)$u['licencia_id']]) || $u['fecha'] >= $ultimoCobro[(int)$u['licencia_id']]['fecha']) $ultimoCobro[(int)$u['licencia_id']] = $u;
    }
    // Renovaciones ya pagadas que aún no se le han cobrado
    $st = $pdo->prepare("SELECT licencia_id, COUNT(*) FROM gastos WHERE cliente_id = ? AND licencia_id IS NOT NULL AND cobrar_receptor_id IS NOT NULL AND cobrado_factura_id IS NULL AND estado = 'pagado' GROUP BY licencia_id");
    $st->execute([$cid]);
    $porCobrarLic = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    $proximoAnual = [];
    if (licenciasCobrosDisponible($pdo)) {
        // Con cobro anual: el gasto cuenta como «por cobrar» solo si su año ya empezó; si no, se muestra cuándo toca
        $porCobrarLic = [];
        $st = $pdo->prepare("SELECT g.licencia_id, COUNT(*) FROM gastos g WHERE g.cliente_id = ? AND g.licencia_id IS NOT NULL AND g.cobrar_receptor_id IS NOT NULL AND g.cobrado_factura_id IS NULL AND g.estado = 'pagado'
                             AND NOT EXISTS (SELECT 1 FROM licencias_cobros c WHERE c.gasto_id = g.id) GROUP BY g.licencia_id");
        $st->execute([$cid]);
        $porCobrarLic = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        $st = $pdo->prepare("SELECT licencia_id, SUM(fecha <= LAST_DAY(CURDATE())) AS ya, MIN(CASE WHEN fecha > LAST_DAY(CURDATE()) THEN fecha END) AS siguiente FROM licencias_cobros WHERE cliente_id = ? AND factura_id IS NULL GROUP BY licencia_id");
        $st->execute([$cid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if ((int)$c['ya']) $porCobrarLic[(int)$c['licencia_id']] = ($porCobrarLic[(int)$c['licencia_id']] ?? 0) + (int)$c['ya'];
            if ($c['siguiente']) $proximoAnual[(int)$c['licencia_id']] = $c['siguiente'];
        }
    }
} catch (Throwable $e) {
    $porCobrarLic = [];
}
$tasa = $instalado ? licenciaTasa($pdo, $cid) : 0;
$st = $pdo->prepare("SELECT id, nombre FROM clientes_factura WHERE cliente_id = ? ORDER BY nombre");
$st->execute([$cid]);
$clientes = $st->fetchAll(PDO::FETCH_KEY_PAIR);
$st = $pdo->prepare("SELECT id, nombre FROM categorias_gastos WHERE cliente_id = ? AND activa = 1 ORDER BY nombre");
$st->execute([$cid]);
$categorias = $st->fetchAll(PDO::FETCH_KEY_PAIR);
$catDefecto = (int)(array_search('Licencias Digitales', $categorias, true) ?: 0);

// Totales al año (mensual × 12) de las activas
$anual = fn($l) => licenciaCostoHnl($pdo, $cid, $l) * licenciaVecesAnio($l['frecuencia']);
$costoPropias = $costoClientes = $ventaClientes = 0.0;
foreach ($lista as $l) {
    if (!(int)$l['activa']) continue;
    if ($l['receptor_id']) { $costoClientes += $anual($l); $ventaClientes += licenciaPrecioAnual($pdo, $cid, $l) * ($l['frecuencia'] === 'mensual' ? 12 : 1); }
    else $costoPropias += $anual($l);
}
$L = fn($v) => 'L ' . number_format((float)$v, 2);
$fmtPct = fn($p) => rtrim(rtrim(number_format((float)$p, 2), '0'), '.') . ' %';

require_once '../../includes/templates/header.php';
?>
<style>
    .sem-rojo { background: #fee2e2; color: #b91c1c; }
    .sem-amarillo { background: #fef3c7; color: #b45309; }
    .sem-gris { background: #f1f5f9; color: #475569; }
    .lic-ico { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 8px; font-size: 1rem; vertical-align: middle; }
    .sem-pill { display: inline-block; border-radius: 999px; padding: .1rem .55rem; font-size: .72rem; font-weight: 600; white-space: nowrap; }
</style>

<div class="app-page-header">
    <div>
        <h1 class="app-page-title"><i class="bi bi-key me-2"></i>Licencias</h1>
        <p class="app-page-sub">Suscripciones y renovaciones (Adobe, Dropbox, hosting, dominios, correos…). Cada renovación queda como gasto pendiente en Cuentas por pagar y en la Proyección. Las de un cliente salen como aviso en su factura, con la comisión.</p>
    </div>
    <?php if ($instalado): ?><div><button class="btn btn-primary" id="btnNueva"><i class="bi bi-plus-circle me-1"></i> Nueva licencia</button></div><?php endif; ?>
</div>

<?php if (!$instalado): ?>
    <div class="alert alert-warning">Falta instalar el módulo de licencias (migración 2026-10-09_licencias.sql).</div>
<?php else: ?>

<div class="app-stats">
    <div class="app-stat"><div class="app-stat-icon amber"><i class="bi bi-building"></i></div><div><div class="app-stat-val"><?= $L($costoPropias) ?></div><div class="app-stat-lbl">Costo al año · licencias de la empresa</div></div></div>
    <div class="app-stat"><div class="app-stat-icon"><i class="bi bi-people"></i></div><div><div class="app-stat-val"><?= $L($ventaClientes) ?></div><div class="app-stat-lbl">Se cobra al año a clientes (costo <?= $L($costoClientes) ?>)</div></div></div>
    <div class="app-stat"><div class="app-stat-icon green"><i class="bi bi-graph-up-arrow"></i></div><div><div class="app-stat-val"><?= $L($ventaClientes - $costoClientes) ?></div><div class="app-stat-lbl">Ganancia al año por comisiones</div></div></div>
</div>

<div class="app-card">
    <div class="app-card-body d-flex flex-wrap gap-2 align-items-center border-bottom">
        <div class="input-group" style="max-width:320px">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="search" class="form-control" id="licBuscar" placeholder="Buscar licencia, proveedor, cliente…" autocomplete="off">
        </div>
        <select class="form-select" id="licFDe" style="width:auto" aria-label="De quién"><option value="">Empresa y clientes</option><option value="empresa">Solo de la empresa</option><option value="cliente">Solo de clientes</option></select>
        <select class="form-select" id="licFEstado" style="width:auto" aria-label="Estado"><option value="">Activas e inactivas</option><option value="1" selected>Activas</option><option value="0">Inactivas</option></select>
        <select class="form-select" id="licFFrec" style="width:auto" aria-label="Frecuencia"><option value="">Toda frecuencia</option><?php foreach (LICENCIA_FRECUENCIAS as $k => $t): ?><option value="<?= $k ?>"><?= $t ?></option><?php endforeach; ?></select>
        <select class="form-select" id="licFRenov" style="width:auto" aria-label="Renovación"><option value="">Cualquier renovación</option><option value="vencidas">Vencidas</option><option value="30">En 30 días o menos</option><option value="90">En 90 días o menos</option></select>
        <select class="form-select" id="licPorPagina" style="width:auto" aria-label="Por página"><option value="10">10/pág</option><option value="25" selected>25/pág</option><option value="50">50/pág</option><option value="100">100/pág</option><option value="100000">Todas</option></select>
        <span class="small text-muted ms-auto" id="licCuenta"></span>
        <button type="button" class="btn btn-outline-success" id="licExportar" title="Excel con las licencias que muestra el filtro"><i class="bi bi-file-earmark-excel me-1"></i>Excel</button>
    </div>
    <div class="table-responsive">
        <table class="table app-table mb-0 align-middle" id="licTabla">
            <thead><tr><th class="lic-orden" data-col="0" style="cursor:pointer">N.º <i class="bi bi-arrow-down-up small text-muted"></i></th><th class="lic-orden" data-col="1" style="cursor:pointer">Licencia <i class="bi bi-arrow-down-up small text-muted"></i></th><th class="lic-orden" data-col="2" style="cursor:pointer">De quién <i class="bi bi-arrow-down-up small text-muted"></i></th><th class="lic-orden" data-col="3" style="cursor:pointer">Frecuencia <i class="bi bi-arrow-down-up small text-muted"></i></th><th class="app-num lic-orden" data-col="4" style="cursor:pointer">Costo <i class="bi bi-arrow-down-up small text-muted"></i></th><th class="app-num lic-orden" data-col="5" style="cursor:pointer">Se cobra <i class="bi bi-arrow-down-up small text-muted"></i></th><th class="lic-orden" data-col="6" style="cursor:pointer">Próxima renovación <i class="bi bi-arrow-down-up small text-muted"></i></th><th class="text-end">Acciones</th></tr></thead>
            <tbody>
                <?php if (!$lista): ?><tr><td colspan="8" class="text-center text-muted py-4">Sin licencias. Agrega las de la empresa (Adobe, Dropbox, correos…) y las de clientes (hosting, dominios).</td></tr><?php endif; ?>
                <?php foreach ($lista as $l): $act = (int)$l['activa']; [$dias, $sem, $txt] = licenciaSemaforo($l['proxima_renovacion']); $costoL = licenciaCostoHnl($pdo, $cid, $l); ?>
                    <tr class="<?= $act ? '' : 'text-muted' ?>" data-fila data-id="<?= (int)$l['id'] ?>" data-activa="<?= $act ?>" data-de="<?= $l['receptor_id'] ? 'cliente' : 'empresa' ?>" data-frec="<?= htmlspecialchars($l['frecuencia']) ?>" data-dias="<?= (int)$dias ?>"
                        data-buscar="<?= htmlspecialchars(mb_strtolower(implode(' ', [$l['id'], licenciaIcono($l)[2], $l['nombre'], $l['proveedor'], $l['categoria'], $l['cliente_nombre'], $l['notas'], LICENCIA_FRECUENCIAS[$l['frecuencia']] ?? '', $l['moneda'], date('d/m/Y', strtotime($l['proxima_renovacion']))]))) ?>"
                        data-orden='<?= htmlspecialchars(json_encode([(int)$l['id'], mb_strtolower($l['nombre']), $l['receptor_id'] ? mb_strtolower((string)$l['cliente_nombre']) : '', $l['frecuencia'], $costoL, $l['receptor_id'] ? licenciaPrecio($costoL, (float)$l['comision_pct']) : 0, $l['proxima_renovacion']], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'>
                        <?php [$licIco, $licCol, $licTipo] = licenciaIcono($l); ?>
                        <td class="text-nowrap"><span class="lic-ico" style="background:<?= $licCol ?>1a;color:<?= $licCol ?>" title="<?= $licTipo ?>"><i class="bi <?= $licIco ?>"></i></span> <span class="text-muted small fw-semibold">#<?= (int)$l['id'] ?></span></td>
                        <td><div class="fw-semibold"><?= htmlspecialchars($l['nombre']) ?><?= $act ? '' : ' <span class="badge bg-light text-secondary border">Inactiva</span>' ?></div>
                            <div class="small text-muted"><?= isset($provPorId[(int)($l['proveedor_id'] ?? 0)]) ? proveedorAvatar($provPorId[(int)$l['proveedor_id']], 16) . ' ' : '' ?><?= htmlspecialchars(trim(($l['proveedor'] ?? '') . ($l['categoria'] ? ' · ' . $l['categoria'] : ''), ' ·')) ?></div></td>
                        <td><?= $l['receptor_id'] ? '<i class="bi bi-person me-1"></i>' . htmlspecialchars((string)$l['cliente_nombre']) . ' <span class="small text-muted">(+' . $fmtPct($l['comision_pct']) . ')</span>'
                            . (count($gruposLic[(int)$l['receptor_id']] ?? []) > 1 ? '<div class="small text-muted" title="Se le cobra en la factura de cualquiera de las empresas de su contrato rotativo"><i class="bi bi-diagram-3 me-1"></i>o cualquiera de sus ' . count($gruposLic[(int)$l['receptor_id']]) . ' empresas</div>' : '') : '<span class="text-muted">Empresa</span>' ?></td>
                        <td><?= LICENCIA_FRECUENCIAS[$l['frecuencia']] ?? $l['frecuencia'] ?></td>
                        <td class="app-num"><?= $l['moneda'] === 'USD' ? 'USD ' . number_format((float)$l['costo'], 2) . '<div class="small text-muted">' . $L($costoL) . '</div>' : $L($l['costo']) ?></td>
                        <td class="app-num"><?= $l['receptor_id'] ? '<span class="fw-semibold">' . $L(licenciaPrecioAnual($pdo, $cid, $l)) . '</span>' . (($gp = licenciaGananciaPct($pdo, $cid, $l)) !== null ? ' <span class="badge ' . ($gp < 15 ? 'bg-danger-subtle text-danger-emphasis' : 'bg-success-subtle text-success-emphasis') . '" title="Ganancia sobre lo que se paga al año">+' . number_format($gp, 1) . ' %</span>' : '') . ($l['frecuencia'] === 'bienal' || !empty($l['precio_cliente']) ? '<div class="small text-muted">por año' . (!empty($l['precio_cliente']) ? ' · precio fijo' : '') . '</div>' : '') : '<span class="text-muted">—</span>' ?>
                            <?php if ($l['receptor_id']): $uc = $ultimoCobro[(int)$l['id']] ?? null; ?>
                                <div class="small text-nowrap"><?= $uc ? '<a href="ver_factura?id=' . (int)$uc['id'] . '" target="_blank" class="text-decoration-none" title="Última factura en que se cobró">Última: ' . htmlspecialchars(substr($uc['correlativo'], -3)) . ' · ' . date('d/m/Y', strtotime($uc['fecha'])) . '</a>' : '<span class="text-muted">Sin cobros registrados</span>' ?></div>
                                <?php if (!empty($porCobrarLic[(int)$l['id']])): ?><div class="small"><span class="badge bg-warning-subtle text-warning-emphasis" title="Renovación pagada que todavía no está en una factura">Pagada, por cobrar</span></div><?php endif; ?>
                                <?php if (!empty($proximoAnual[(int)$l['id']])): ?><div class="small text-muted" title="Se pagó por varios años: al cliente se le cobra cada año">Próximo cobro anual: <?= date('d/m/Y', strtotime($proximoAnual[(int)$l['id']])) ?></div><?php endif; ?>
                            <?php endif; ?></td>
                        <td class="text-nowrap"><?= date('d/m/Y', strtotime($l['proxima_renovacion'])) ?><?php if ($act): ?> <span class="sem-pill sem-<?= $sem ?>"><?= $txt ?></span><?php endif; ?></td>
                        <td class="text-end text-nowrap">
                            <button class="btn btn-sm btn-outline-secondary py-0 btn-editar" data-l='<?= htmlspecialchars(json_encode($l, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>' title="Editar"><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm <?= $act ? 'btn-outline-danger' : 'btn-outline-success' ?> py-0 btn-activa" data-id="<?= (int)$l['id'] ?>" data-activa="<?= $act ? 0 : 1 ?>" title="<?= $act ? 'Desactivar (ya no se renueva)' : 'Activar' ?>"><i class="bi <?= $act ? 'bi-pause-circle' : 'bi-play-circle' ?>"></i></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="app-pager" id="licPie"></div>
</div>
<p class="small text-muted mt-2"><i class="bi bi-info-circle me-1"></i>Al pagar la renovación en Gastos se programa la siguiente. Las licencias en dólares se convierten a la tasa del día<?= $tasa > 0 ? ' (L ' . number_format($tasa, 4) . ')' : '' ?>. Comisión por defecto: <?= $fmtPct(LICENCIA_COMISION_DEFECTO) ?>.</p>

<div class="modal fade" id="modalLic" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="licTitulo"><i class="bi bi-key me-1"></i> Nueva licencia</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <form id="formLic">
                <input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" id="licId" value="0">
                <div class="row g-3">
                    <div class="col-md-7"><label class="form-label small">Nombre <span class="text-danger">*</span></label><input class="form-control" name="nombre" required maxlength="150" placeholder="Ej.: Adobe Creative Cloud, Hosting sigurban.com"></div>
                    <div class="col-md-5"><label class="form-label small">Proveedor</label><?php $psId = 'licProv'; $psValor = ''; $psClase = 'form-select'; require '../../includes/templates/proveedor_select.php'; ?></div>
                    <div class="col-md-4"><label class="form-label small">Frecuencia</label><select class="form-select" name="frecuencia"><?php foreach (LICENCIA_FRECUENCIAS as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-3"><label class="form-label small">Moneda</label><select class="form-select" name="moneda"><option value="HNL">Lempiras</option><option value="USD">Dólares</option></select></div>
                    <div class="col-md-5"><label class="form-label small">Costo por renovación <span class="text-danger">*</span></label><input type="number" class="form-control" name="costo" step="0.01" min="0.01" required></div>
                    <div class="col-md-4"><label class="form-label small">Próxima renovación <span class="text-danger">*</span></label><input type="date" class="form-control" name="proxima_renovacion" required></div>
                    <div class="col-md-4"><label class="form-label small">Se paga con</label><select class="form-select" name="metodo_pago"><option value="tarjeta">Tarjeta</option><option value="transferencia">Transferencia</option><option value="efectivo">Efectivo</option><option value="cheque">Cheque</option><option value="otro">Otro</option></select></div>
                    <div class="col-md-4"><label class="form-label small">Categoría de gasto</label><select class="form-select" name="categoria_id"><option value="">—</option><?php foreach ($categorias as $k => $v): ?><option value="<?= (int)$k ?>"><?= htmlspecialchars($v) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-8"><label class="form-label small">¿Se le cobra a un cliente?</label>
                        <select class="form-select" name="receptor_id" id="licCliente"><option value="">No, es de la empresa</option><?php foreach ($clientes as $k => $v): ?><option value="<?= (int)$k ?>"><?= htmlspecialchars($v) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-4"><label class="form-label small">Comisión (%)</label><input type="number" class="form-control" name="comision_pct" step="0.01" min="0" max="500"></div>
                    <div class="col-md-6"><label class="form-label small">Precio fijo al cliente por año <span class="text-muted">(opcional, L)</span></label><input type="number" class="form-control" name="precio_cliente" step="0.01" min="0" placeholder="Si se llena, se cobra esto en vez de costo + %"></div>
                    <div class="col-md-6"><label class="form-label small">Avisar días antes</label><input type="number" class="form-control" name="aviso_dias" step="1" min="0" max="365" placeholder="5">
                        <div class="form-text">De la renovación y del cobro al cliente, en el Dashboard. Vacío: 5 días.</div></div>
                    <div class="col-12"><div class="small text-muted" id="licPrecio"></div></div>
                    <div class="col-12"><label class="form-label small">Notas</label><textarea class="form-control" name="notas" rows="2" placeholder="Cuenta, usuario, dominio, para qué se usa…"></textarea></div>
                </div>
            </form>
        </div>
        <div class="modal-footer"><button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" id="btnGuardarLic"><i class="bi bi-check-circle me-1"></i>Guardar</button></div>
    </div></div>
</div>
<?php endif; ?>

<script>
(function () {
    const modalEl = document.getElementById('modalLic'); if (!modalEl) return;
    const f = document.getElementById('formLic'), modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    const DEF = { comision_pct: <?= json_encode(LICENCIA_COMISION_DEFECTO) ?>, categoria_id: <?= json_encode($catDefecto ?: '') ?>, frecuencia: 'anual', moneda: 'HNL', metodo_pago: 'tarjeta' };
    const TASA = <?= json_encode($tasa) ?>;
    const fmt = n => 'L ' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const precio = () => {
        const c = +f.costo.value || 0, p = +f.comision_pct.value || 0, cl = f.receptor_id.value, usd = f.moneda.value === 'USD', fijo = +f.precio_cliente.value || 0;
        const cL = usd ? c * TASA : c;
        f.comision_pct.disabled = !cl || fijo > 0; f.precio_cliente.disabled = !cl;
        document.getElementById('licPrecio').innerHTML = !c ? '' : (usd ? `Costo en lempiras: <strong>${fmt(cL)}</strong> (tasa ${TASA ? TASA.toFixed(4) : 'sin registrar'}). ` : '')
            + (cl ? `Se le cobra al cliente: <strong>${fmt(fijo || cL * (1 + p / 100) / (f.frecuencia.value === 'bienal' ? 2 : 1))}</strong>${f.frecuencia.value === 'bienal' || fijo ? ' por año' : ''} (${fijo ? 'precio fijo' : 'costo + ' + p + ' %'}). A los clientes se les cobra cada año: sale como aviso en su factura.` : 'Es gasto de la empresa.');
    };
    ['costo', 'comision_pct', 'receptor_id', 'moneda', 'frecuencia', 'precio_cliente'].forEach(n => { f[n].addEventListener('input', precio); f[n].addEventListener('change', precio); });
    f.receptor_id.addEventListener('change', precio);
    const abrir = l => {
        f.reset();
        const d = Object.assign({}, DEF, l || {});
        document.getElementById('licId').value = l ? l.id : 0;
        document.getElementById('licTitulo').innerHTML = '<i class="bi bi-key me-1"></i> ' + (l ? 'Editar licencia' : 'Nueva licencia');
        proveedorSelectValor(document.getElementById('licProv'), d.proveedor || '');
        ['nombre', 'frecuencia', 'moneda', 'costo', 'proxima_renovacion', 'metodo_pago', 'categoria_id', 'receptor_id', 'comision_pct', 'precio_cliente', 'aviso_dias', 'notas'].forEach(k => { if (f[k]) f[k].value = d[k] ?? ''; });
        precio(); modal.show();
    };
    document.getElementById('btnNueva').addEventListener('click', () => abrir(null));
    document.querySelectorAll('.btn-editar').forEach(b => b.addEventListener('click', () => abrir(JSON.parse(b.dataset.l))));
    const enviar = async fd => {
        const r = await fetch('includes/licencia_accion.php', { method: 'POST', body: fd });
        const d = await r.json().catch(() => ({ success: false, error: 'Respuesta inesperada del servidor (' + r.status + ').' }));
        if (!d.success) throw new Error(d.error);
        return d;
    };
    document.getElementById('btnGuardarLic').addEventListener('click', async e => {
        if (!f.reportValidity()) return;
        const fd = new FormData(f); if (!f.receptor_id.value) fd.set('comision_pct', f.comision_pct.value || DEF.comision_pct);
        e.currentTarget.disabled = true;
        try { const d = await enviar(fd); await Swal.fire({ icon: 'success', title: 'Listo', text: d.message }); location.reload(); }
        catch (err) { Swal.fire('No se pudo guardar', err.message, 'error'); e.currentTarget.disabled = false; }
    });
    document.querySelectorAll('.btn-activa').forEach(b => b.addEventListener('click', async () => {
        const act = b.dataset.activa === '1';
        if (!(await Swal.fire({ title: act ? 'Activar licencia' : 'Desactivar licencia', text: act ? 'Se programa su próxima renovación.' : 'Se anula su renovación pendiente y deja de renovarse.', icon: 'question', showCancelButton: true, confirmButtonText: act ? 'Activar' : 'Desactivar', cancelButtonText: 'Cancelar' })).isConfirmed) return;
        const fd = new FormData(); fd.append('accion', 'activa'); fd.append('id', b.dataset.id); fd.append('activa', act ? 1 : 0);
        try { const d = await enviar(fd); await Swal.fire({ icon: 'success', title: 'Listo', text: d.message }); location.reload(); }
        catch (err) { Swal.fire('No se pudo', err.message, 'error'); }
    }));
})();
</script>

<script>
// Tabla de licencias: búsqueda al momento, filtros, orden por columna, paginación y Excel de lo filtrado
(() => {
    const tb = document.querySelector('#licTabla tbody');
    if (!tb) return;
    const filas = [...tb.querySelectorAll('tr[data-fila]')];
    const $ = id => document.getElementById(id);
    let col = -1, dir = 1, pagina = 1;
    const filtradas = () => {
        const q = $('licBuscar').value.trim().toLowerCase(), de = $('licFDe').value, est = $('licFEstado').value, fr = $('licFFrec').value, rn = $('licFRenov').value;
        let r = filas.filter(tr => (!q || tr.dataset.buscar.includes(q)) && (!de || tr.dataset.de === de) && (!est || tr.dataset.activa === est) && (!fr || tr.dataset.frec === fr)
            && (!rn || (rn === 'vencidas' ? +tr.dataset.dias < 0 : +tr.dataset.dias >= 0 && +tr.dataset.dias <= +rn)));
        if (col >= 0) r = r.sort((a, b) => { const x = JSON.parse(a.dataset.orden)[col], y = JSON.parse(b.dataset.orden)[col]; return (typeof x === 'number' ? x - y : String(x).localeCompare(String(y), 'es', { numeric: true })) * dir; });
        return r;
    };
    const pintar = () => {
        const r = filtradas(), n = +$('licPorPagina').value, pags = Math.max(1, Math.ceil(r.length / n));
        pagina = Math.min(pagina, pags);
        filas.forEach(tr => tr.style.display = 'none');
        r.forEach(tr => tb.appendChild(tr));   // el orden elegido se ve en la tabla
        r.slice((pagina - 1) * n, pagina * n).forEach(tr => tr.style.display = '');
        $('licCuenta').textContent = `${r.length} de ${filas.length} licencia${filas.length !== 1 ? 's' : ''}`;
        const pie = $('licPie');
        pie.innerHTML = '';
        if (pags > 1) {
            pie.innerHTML = `<span class="app-pager-info">Mostrando ${(pagina - 1) * n + 1}–${Math.min(pagina * n, r.length)} de ${r.length}</span><div class="app-pager-btns"></div>`;
            for (let p = 1; p <= pags; p++) { const b = document.createElement('span'); b.className = 'app-page-btn' + (p === pagina ? ' active' : ''); b.textContent = p; b.onclick = () => { pagina = p; pintar(); }; pie.lastChild.appendChild(b); }
        }
        document.querySelectorAll('#licTabla .lic-orden i').forEach(i => i.className = 'bi bi-arrow-down-up small text-muted');
        if (col >= 0) document.querySelector(`#licTabla .lic-orden[data-col="${col}"] i`).className = 'bi ' + (dir > 0 ? 'bi-arrow-up' : 'bi-arrow-down') + ' small';
    };
    ['licBuscar'].forEach(id => $(id).addEventListener('input', () => { pagina = 1; pintar(); }));
    ['licFDe', 'licFEstado', 'licFFrec', 'licFRenov', 'licPorPagina'].forEach(id => $(id).addEventListener('change', () => { pagina = 1; pintar(); }));
    document.querySelectorAll('#licTabla .lic-orden').forEach(th => th.addEventListener('click', () => { const c = +th.dataset.col; dir = col === c ? -dir : 1; col = c; pagina = 1; pintar(); }));
    $('licExportar').addEventListener('click', () => {
        const ids = filtradas().map(tr => tr.dataset.id);
        if (!ids.length) return Swal.fire('Sin licencias', 'No hay licencias con este filtro.', 'info');
        location.href = 'licencias_exportar.php?ids=' + ids.join(',');
    });
    pintar();
})();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
