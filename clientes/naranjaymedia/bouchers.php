<?php
// Bouchers: lista de pagos (gastos pagados) del período con vista previa, PDF de cada uno, PDF con todos y ZIP.
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/bouchers.php';

if (!in_array(USUARIO_ROL, ['admin', 'superadmin', 'nomina'], true)) {
    header('Location: dashboard');
    exit;
}
$cid = (int)cliente_actual();
$f = boucherFiltros($_GET);
$ctx = boucherContexto($pdo, $cid, __DIR__ . '/includes/uploads');
$filas = [];
foreach (boucherGastos($pdo, $cid, $f) as $g) {
    $c = esGastoNomina((string)$g['descripcion']) ? boucherColaborador($ctx, (string)$g['descripcion']) : null;
    $filas[] = $g + [
        'beneficiario' => $c ? trim($c['nombre'] . ' ' . $c['apellido']) : (trim((string)$g['proveedor']) ?: ''),
        'es_colab' => (bool)$c, 'con_firma' => $c && !empty($c['url_firma']), 'colab_id' => $c['id'] ?? null,
    ];
}
$total = array_sum(array_column($filas, 'monto'));
$conComp = count(array_filter($filas, fn($x) => !empty($x['archivo_adjunto'])));
$sinFirma = array_unique(array_column(array_filter($filas, fn($x) => $x['es_colab'] && !$x['con_firma']), 'beneficiario', 'colab_id'));
$qs = http_build_query(['desde' => $f['desde'], 'hasta' => $f['hasta'], 'tipo' => $f['tipo']]);
$meses = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$mesActual = substr($f['desde'], 0, 7) === substr($f['hasta'], 0, 7) && $f['desde'] === date('Y-m-01', strtotime($f['desde'])) && $f['hasta'] === date('Y-m-t', strtotime($f['desde']));
$metodos = ['transferencia' => 'Transferencia', 'efectivo' => 'Efectivo', 'cheque' => 'Cheque', 'tarjeta' => 'Tarjeta', 'otro' => 'Otro'];

require_once '../../includes/templates/header.php';
?>

<div class="app-page-header">
    <div>
        <h1 class="app-page-title"><i class="bi bi-receipt-cutoff me-2"></i>Bouchers</h1>
        <p class="app-page-sub">Comprobante de cada pago con la captura de la transferencia y la firma del colaborador · del <?= date('d/m/Y', strtotime($f['desde'])) ?> al <?= date('d/m/Y', strtotime($f['hasta'])) ?>.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-outline-danger <?= $filas ? '' : 'disabled' ?>" href="boucher_pdf.php?lote=1&vista=1&<?= $e = htmlspecialchars($qs) ?>" target="_blank"><i class="bi bi-file-earmark-pdf me-1"></i> PDF con todos</a>
        <a class="btn btn-primary <?= $filas ? '' : 'disabled' ?>" href="boucher_pdf.php?lote=1&formato=zip&<?= $e ?>" id="btnZip"><i class="bi bi-file-earmark-zip me-1"></i> Descargar ZIP</a>
    </div>
</div>

<form class="app-card app-card-body mb-3" method="get">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2"><label class="form-label small">Mes</label>
            <select class="form-select form-select-sm" id="selMes"><option value="">— Rango —</option>
                <?php for ($i = 0; $i < 14; $i++): $t = strtotime(date('Y-m-01') . " -$i month"); $v = date('Y-m', $t); ?>
                    <option value="<?= $v ?>" <?= $mesActual && substr($f['desde'], 0, 7) === $v ? 'selected' : '' ?>><?= $meses[(int)date('n', $t)] . ' ' . date('Y', $t) ?></option>
                <?php endfor; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small">Desde</label><input type="date" class="form-control form-control-sm" name="desde" id="fDesde" value="<?= $f['desde'] ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small">Hasta</label><input type="date" class="form-control form-control-sm" name="hasta" id="fHasta" value="<?= $f['hasta'] ?>"></div>
        <?php if (USUARIO_ROL !== 'nomina'): ?>
            <div class="col-6 col-md-3"><label class="form-label small">Pagos</label>
                <select class="form-select form-select-sm" name="tipo"><?php foreach (BOUCHER_TIPOS as $k => $t): ?><option value="<?= $k ?>" <?= $f['tipo'] === $k ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <div class="col-md-auto"><button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i> Ver</button></div>
    </div>
</form>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="app-card app-kpi"><div class="app-kpi-label">Pagos</div><div class="app-kpi-value"><?= count($filas) ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="app-card app-kpi"><div class="app-kpi-label">Total pagado</div><div class="app-kpi-value fs-5">L <?= number_format($total, 2) ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="app-card app-kpi"><div class="app-kpi-label">Con captura</div><div class="app-kpi-value <?= $conComp < count($filas) ? 'text-warning' : 'text-success' ?>"><?= $conComp ?>/<?= count($filas) ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="app-card app-kpi"><div class="app-kpi-label">Colaboradores sin firma</div><div class="app-kpi-value <?= $sinFirma ? 'text-danger' : 'text-success' ?>"><?= count($sinFirma) ?></div></div></div>
</div>

<?php if ($sinFirma): ?>
    <div class="alert alert-warning small"><i class="bi bi-pen me-1"></i> Sin firma registrada:
        <?= implode(', ', array_map(fn($id, $n) => '<a href="colaborador_ver?id=' . (int)$id . '#firma">' . htmlspecialchars($n) . '</a>', array_keys($sinFirma), $sinFirma)) ?>.
        Súbela en la ficha del colaborador para que salga en sus bouchers.</div>
<?php endif; ?>

<div class="app-card">
    <div class="table-responsive">
        <table data-paginar class="table app-table mb-0">
            <thead><tr><th>Fecha</th><th>Beneficiario</th><th>Concepto</th><th>Método</th><th class="app-num">Monto</th><th class="text-center">Captura</th><th class="text-center">Firma</th><th class="text-end">Boucher</th></tr></thead>
            <tbody>
                <?php if (!$filas): ?><tr><td colspan="8" class="text-center text-muted py-4">No hay pagos en este período.</td></tr><?php endif; ?>
                <?php foreach ($filas as $x): ?>
                    <tr>
                        <td class="text-nowrap"><?= date('d/m/Y', strtotime($x['fecha'])) ?></td>
                        <td><?= htmlspecialchars($x['beneficiario'] ?: '—') ?><?= $x['es_colab'] ? ' <span class="app-badge app-badge-info">Colaborador</span>' : '' ?></td>
                        <td class="small"><?= htmlspecialchars(mb_strimwidth((string)$x['descripcion'], 0, 70, '…')) ?><?= $x['categoria'] ? '<div class="text-muted">' . htmlspecialchars($x['categoria']) . '</div>' : '' ?></td>
                        <td class="small"><?= $metodos[$x['metodo_pago']] ?? htmlspecialchars((string)$x['metodo_pago']) ?></td>
                        <td class="app-num fw-semibold"><?= number_format((float)$x['monto'], 2) ?></td>
                        <td class="text-center"><?= $x['archivo_adjunto'] ? '<i class="bi bi-image text-success" title="Tiene captura"></i>' : '<i class="bi bi-dash text-muted" title="Sin captura"></i>' ?></td>
                        <td class="text-center"><?= !$x['es_colab'] ? '<span class="text-muted small">—</span>' : ($x['con_firma'] ? '<i class="bi bi-pen-fill text-success" title="Con firma"></i>' : '<i class="bi bi-pen text-danger" title="Sin firma"></i>') ?></td>
                        <td class="text-end text-nowrap">
                            <button type="button" class="btn btn-sm btn-outline-secondary btn-ver-boucher" data-id="<?= (int)$x['id'] ?>" data-titulo="<?= htmlspecialchars(($x['beneficiario'] ?: 'Pago') . ' · ' . date('d/m/Y', strtotime($x['fecha']))) ?>" title="Vista previa"><i class="bi bi-eye"></i></button>
                            <a class="btn btn-sm btn-outline-danger" href="boucher_pdf.php?gasto_id=<?= (int)$x['id'] ?>" title="Descargar PDF"><i class="bi bi-download"></i></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Vista previa -->
<div class="modal fade" id="mBoucher" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title"><i class="bi bi-receipt-cutoff me-1"></i> <span id="bTitulo">Boucher</span></h5>
        <a class="btn btn-sm btn-outline-danger ms-auto me-2" id="bDescargar" href="#"><i class="bi bi-download me-1"></i> Descargar</a>
        <button type="button" class="btn-close ms-0" data-bs-dismiss="modal"></button></div>
    <div class="modal-body p-0"><iframe id="bFrame" title="Vista previa del boucher" style="width:100%;height:78vh;border:0"></iframe></div>
</div></div></div>

<script>
(() => {
    const modal = document.getElementById('mBoucher'), frame = document.getElementById('bFrame');
    document.querySelectorAll('.btn-ver-boucher').forEach(b => b.addEventListener('click', () => {
        frame.src = 'boucher_pdf.php?vista=1&gasto_id=' + b.dataset.id;
        document.getElementById('bTitulo').textContent = b.dataset.titulo;
        document.getElementById('bDescargar').href = 'boucher_pdf.php?gasto_id=' + b.dataset.id;
        bootstrap.Modal.getOrCreateInstance(modal).show();
    }));
    modal.addEventListener('hidden.bs.modal', () => frame.src = 'about:blank');
    // Mes → rango del mes completo
    document.getElementById('selMes').addEventListener('change', e => {
        if (!e.target.value) return;
        const [y, m] = e.target.value.split('-').map(Number);
        document.getElementById('fDesde').value = `${e.target.value}-01`;
        document.getElementById('fHasta').value = `${e.target.value}-${String(new Date(y, m, 0).getDate()).padStart(2, '0')}`;
        e.target.form.submit();
    });
    // El ZIP tarda: aviso mientras se genera
    document.getElementById('btnZip')?.addEventListener('click', () => Swal.fire({ title: 'Generando ZIP…', text: 'Un PDF por pago; puede tardar unos segundos.', timer: 4000, showConfirmButton: false, didOpen: () => Swal.showLoading() }));
})();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
