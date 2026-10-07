<?php
$titulo = 'Cuentas por pagar';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/cuentas.php';

$cid = cliente_actual();
$gastos = $cid ? cxpPendientes($pdo, $cid) : [];
$cuentasHnl = bancosDisponible($pdo) ? array_values(array_filter(bancoCuentas($pdo, $cid, true), fn($c) => $c['moneda'] === 'HNL')) : [];

$grupos = ['vencido' => 0.0, 'hoy_7' => 0.0, '8_30' => 0.0, 'mas_30' => 0.0];
$nGrupo = array_fill_keys(array_keys($grupos), 0);
$proveedores = $categorias = $meses = [];
foreach ($gastos as &$g) {
    $d = (int)$g['dias'];                       // > 0 = días de atraso; <= 0 = faltan -d días
    $g['grupo'] = $d > 0 ? 'vencido' : ($d >= -7 ? 'hoy_7' : ($d >= -30 ? '8_30' : 'mas_30'));
    $grupos[$g['grupo']] += (float)$g['monto'];
    $nGrupo[$g['grupo']]++;
    $g['prov'] = trim((string)$g['proveedor']) ?: 'Sin proveedor';
    $proveedores[$g['prov']] = true;
    if ($g['categoria']) $categorias[$g['categoria']] = true;
    $meses[substr($g['fecha'], 0, 7)] = true;
}
unset($g);
ksort($proveedores, SORT_FLAG_CASE | SORT_STRING);
ksort($categorias, SORT_FLAG_CASE | SORT_STRING);
ksort($meses);
$total = array_sum($grupos);
$L = fn($v) => 'L ' . number_format((float)$v, 2);
$etq = ['vencido' => ['Vencido', 'danger'], 'hoy_7' => ['Próximos 7 días', 'warning'], '8_30' => ['8 a 30 días', 'info'], 'mas_30' => ['Más de 30 días', 'muted']];
$MES = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$DIA = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
$mesTxt = fn($ym) => $MES[(int)substr($ym, 5, 2)] . ' ' . substr($ym, 0, 4);
$fechaTxt = fn($f) => $DIA[(int)date('w', strtotime($f))] . ' ' . (int)substr($f, 8, 2) . ' ' . mb_strtolower(mb_substr($MES[(int)substr($f, 5, 2)], 0, 3));
$cuando = fn($d) => $d > 0 ? "venció hace $d día" . ($d > 1 ? 's' : '') : ($d === 0 ? 'vence hoy' : ($d === -1 ? 'vence mañana' : 'en ' . -$d . ' días'));

// Resumen en una frase: cuánto se debe, qué está vencido y cuál es el próximo pago
$vencidos = array_filter($gastos, fn($g) => (int)$g['dias'] > 0);
$proximo = null;
foreach ($gastos as $g) if ((int)$g['dias'] <= 0) { $proximo = $g; break; }

require_once '../../includes/templates/header.php';
?>

<div class="app-page-header">
    <div>
        <h1 class="app-page-title">Cuentas por pagar</h1>
        <p class="app-page-sub">Lo que la empresa debe pagar y cuándo: gastos registrados que aún no se han pagado.</p>
    </div>
    <a href="gastos" class="btn btn-sm btn-outline-secondary"><i class="bi bi-wallet2 me-1"></i> Todos los gastos</a>
</div>

<?php if (!$gastos): ?>
    <div class="alert alert-success d-flex align-items-center gap-2"><i class="bi bi-check-circle-fill fs-5"></i> <div><strong>Todo al día.</strong> No hay pagos pendientes.</div></div>
<?php else: ?>
    <div class="alert <?= $vencidos ? 'alert-danger' : 'alert-primary' ?> d-flex align-items-start gap-3" id="cpResumen">
        <i class="bi <?= $vencidos ? 'bi-exclamation-octagon-fill' : 'bi-calendar-check' ?> fs-4"></i>
        <div>
            <div>Debes <strong><?= $L($total) ?></strong> en <?= count($gastos) ?> pago<?= count($gastos) > 1 ? 's' : '' ?>.
                <?php if ($vencidos): ?><strong><?= count($vencidos) ?> vencido<?= count($vencidos) > 1 ? 's' : '' ?> por <?= $L($grupos['vencido']) ?></strong>.
                <?php else: ?>Nada vencido.<?php endif; ?></div>
            <?php if ($proximo): ?>
                <div class="mt-1">Próximo pago: <strong><?= htmlspecialchars($proximo['descripcion']) ?></strong> · <?= htmlspecialchars($proximo['prov']) ?> ·
                    <strong><?= $L($proximo['monto']) ?></strong> el <?= $fechaTxt($proximo['fecha']) ?> (<?= $cuando((int)$proximo['dias']) ?>).</div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php $iconoGrupo = ['vencido' => ['bi-exclamation-octagon', 'red'], 'hoy_7' => ['bi-alarm', 'amber'], '8_30' => ['bi-calendar-week', 'teal'], 'mas_30' => ['bi-calendar3', 'gray']]; ?>
<div class="app-stats" id="cpTramos">
    <button type="button" class="app-stat app-stat-filtro activo" data-tramo=""><div class="app-stat-icon"><i class="bi bi-wallet2"></i></div>
        <div><div class="app-stat-val"><?= $L($total) ?></div><div class="app-stat-lbl">Total · <?= count($gastos) ?> pago(s)</div></div></button>
    <?php foreach ($grupos as $k => $v): ?>
        <button type="button" class="app-stat app-stat-filtro" data-tramo="<?= $k ?>" <?= $nGrupo[$k] ? '' : 'disabled' ?>><div class="app-stat-icon <?= $v > 0 ? $iconoGrupo[$k][1] : 'gray' ?>"><i class="bi <?= $iconoGrupo[$k][0] ?>"></i></div>
            <div><div class="app-stat-val <?= $v > 0 ? 'text-' . $etq[$k][1] : 'text-muted' ?>"><?= $L($v) ?></div><div class="app-stat-lbl"><?= $etq[$k][0] ?> · <?= $nGrupo[$k] ?></div></div></button>
    <?php endforeach; ?>
</div>

<div class="app-card">
    <div class="app-card-header flex-wrap gap-2">
        <span><i class="bi bi-calendar-event me-1"></i> Calendario de pagos</span>
        <div class="app-toolbar flex-grow-1 justify-content-end flex-wrap gap-2" id="cpFiltros">
            <select class="form-select form-select-sm" id="cpMes" style="width:auto"><option value="">Todos los meses</option>
                <?php foreach (array_keys($meses) as $ym): ?><option value="<?= $ym ?>"><?= $mesTxt($ym) ?></option><?php endforeach; ?></select>
            <select class="form-select form-select-sm" id="cpProv" style="width:auto;max-width:220px"><option value="">Todos los proveedores</option>
                <?php foreach (array_keys($proveedores) as $p): ?><option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option><?php endforeach; ?></select>
            <select class="form-select form-select-sm" id="cpCat" style="width:auto;max-width:220px"><option value="">Todas las categorías</option>
                <?php foreach (array_keys($categorias) as $p): ?><option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option><?php endforeach; ?></select>
            <div class="app-search" style="max-width:240px"><i class="bi bi-search"></i><input type="search" class="form-control form-control-sm" id="buscarPago" placeholder="Buscar gasto o proveedor…"></div>
            <select class="form-select form-select-sm" id="porPagina" style="width:auto"><option value="10">10/pág</option><option value="25" selected>25/pág</option><option value="50">50/pág</option></select>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="cpLimpiar" hidden><i class="bi bi-x-lg"></i> Limpiar</button>
        </div>
    </div>
    <div class="px-3 pt-2 small text-muted" id="cpFiltrado" hidden></div>
    <div class="table-responsive">
        <table class="table app-table" id="tablaPagos">
            <thead><tr><th class="app-n">#</th><th>Fecha</th><th>Descripción</th><th>Proveedor</th><th class="app-num">Monto</th><th>Estado</th><th class="text-end"></th></tr></thead>
            <tbody>
                <?php foreach ($gastos as $g): $d = (int)$g['dias']; ?>
                    <tr data-fila data-mes="<?= substr($g['fecha'], 0, 7) ?>" data-tramo="<?= $g['grupo'] ?>" data-prov="<?= htmlspecialchars($g['prov']) ?>" data-cat="<?= htmlspecialchars($g['categoria'] ?? '') ?>" data-monto="<?= (float)$g['monto'] ?>">
                        <td class="app-n"></td>
                        <td class="text-nowrap"><strong><?= $fechaTxt($g['fecha']) ?></strong><div class="small text-muted"><?= date('d/m/Y', strtotime($g['fecha'])) ?></div></td>
                        <td><a href="gasto_ver?id=<?= (int)$g['id'] ?>"><?= htmlspecialchars($g['descripcion']) ?></a><?= $g['frecuencia'] !== 'unico' ? ' <span class="app-badge app-badge-muted">' . htmlspecialchars(ucfirst($g['frecuencia'])) . '</span>' : '' ?><div class="small text-muted"><?= htmlspecialchars($g['categoria'] ?? '') ?></div></td>
                        <td class="small"><?= htmlspecialchars($g['prov']) ?></td>
                        <td class="app-num fw-semibold"><?= number_format((float)$g['monto'], 2) ?></td>
                        <td class="text-nowrap"><span class="app-badge app-badge-<?= $etq[$g['grupo']][1] ?>"><?= ucfirst($cuando($d)) ?></span></td>
                        <td class="text-end"><button class="btn btn-sm btn-success btn-pagar" data-id="<?= (int)$g['id'] ?>" data-desc="<?= htmlspecialchars($g['descripcion']) ?>" data-monto="<?= number_format((float)$g['monto'], 2, '.', '') ?>"><i class="bi bi-check-lg"></i> Pagar</button></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="app-pager" id="pagosPie"></div>
</div>

<script src="../../clientes/js/app-tabla.js?v=<?= @filemtime(__DIR__ . '/../js/app-tabla.js') ?>"></script>
<script>
(function () {
    // Filtros sin recargar: tramo (tarjetas), mes, proveedor, categoría y búsqueda. Cada mes lleva su subtotal.
    const $ = id => document.getElementById(id);
    let tramo = '';
    const filtro = tr => (!tramo || tr.dataset.tramo === tramo) && (!$('cpMes').value || tr.dataset.mes === $('cpMes').value)
        && (!$('cpProv').value || tr.dataset.prov === $('cpProv').value) && (!$('cpCat').value || tr.dataset.cat === $('cpCat').value);
    const MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    const L = v => 'L ' + v.toLocaleString('es-HN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const tabla = AppTabla('#tablaPagos', { buscar: '#buscarPago', porPagina: '#porPagina', pie: '#pagosPie', filtro, vacio: 'No hay pagos pendientes con estos filtros.' });
    const filas = [...document.querySelectorAll('#tablaPagos tr[data-fila]')];
    const pintar = () => {
        // Encabezado por mes con subtotal (de todo lo filtrado, no solo de la página)
        document.querySelectorAll('#tablaPagos tr.cp-mes').forEach(t => t.remove());
        const q = $('buscarPago').value.trim().toLowerCase();
        const filtradas = filas.filter(tr => filtro(tr) && (!q || tr.textContent.toLowerCase().includes(q)));
        const suma = {};
        filtradas.forEach(tr => { const m = tr.dataset.mes; suma[m] ??= { n: 0, t: 0 }; suma[m].n++; suma[m].t += +tr.dataset.monto; });
        let previo = null;
        filas.filter(tr => tr.style.display !== 'none').forEach(tr => {
            if (tr.dataset.mes === previo) return;
            previo = tr.dataset.mes;
            const s = suma[previo], h = document.createElement('tr');
            h.className = 'cp-mes';
            h.innerHTML = `<td colspan="7"><i class="bi bi-calendar3 me-1"></i><strong>${MESES[+previo.slice(5, 7) - 1]} ${previo.slice(0, 4)}</strong>
                <span class="text-muted ms-2">${s.n} pago${s.n > 1 ? 's' : ''} · <strong class="text-body">${L(s.t)}</strong></span></td>`;
            tr.before(h);
        });
        const activo = tramo || $('cpMes').value || $('cpProv').value || $('cpCat').value || q;
        $('cpLimpiar').hidden = !activo;
        $('cpFiltrado').hidden = !activo;
        $('cpFiltrado').innerHTML = `Filtrado: <strong>${filtradas.length}</strong> pago${filtradas.length !== 1 ? 's' : ''} por <strong>${L(filtradas.reduce((a, tr) => a + +tr.dataset.monto, 0))}</strong>`;
        document.querySelectorAll('.app-stat-filtro').forEach(b => b.classList.toggle('activo', b.dataset.tramo === tramo));
    };
    const aplicar = () => { tabla.refrescar(); pintar(); };
    ['cpMes', 'cpProv', 'cpCat'].forEach(id => $(id).addEventListener('change', aplicar));
    $('buscarPago').addEventListener('input', () => setTimeout(pintar));
    $('porPagina').addEventListener('change', () => setTimeout(pintar));
    $('pagosPie').addEventListener('click', () => setTimeout(pintar));
    document.querySelectorAll('.app-stat-filtro').forEach(b => b.addEventListener('click', () => { tramo = b.dataset.tramo; aplicar(); }));
    $('cpLimpiar').addEventListener('click', () => { tramo = ''; ['cpMes', 'cpProv', 'cpCat', 'buscarPago'].forEach(id => $(id).value = ''); aplicar(); });
    aplicar();
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const cuentas = <?= json_encode(array_map(fn($c) => ['id' => (int)$c['id'], 'txt' => $c['banco'] . ' ' . $c['numero'], 'pred' => !empty($c['predeterminada'])], $cuentasHnl), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    document.querySelectorAll('.btn-pagar').forEach(b => b.addEventListener('click', () => {
        Swal.fire({
            title: 'Pagar gasto',
            html: `<div class="text-start">
                <div class="mb-2"><strong>${esc(b.dataset.desc)}</strong> · L ${Number(b.dataset.monto).toLocaleString('es-HN', { minimumFractionDigits: 2 })}</div>
                <label class="form-label">Fecha de pago</label><input type="date" id="pFecha" class="form-control" value="${new Date().toLocaleDateString('sv-SE')}">
                <label class="form-label mt-2">Método</label><select id="pMetodo" class="form-select"><option value="transferencia">Transferencia</option><option value="efectivo">Efectivo</option><option value="cheque">Cheque</option><option value="tarjeta">Tarjeta</option><option value="otro">Otro</option></select>
                <label class="form-label mt-2">Sale de la cuenta</label><select id="pCuenta" class="form-select"><option value="">— No registrar en banco —</option>${cuentas.map(c => `<option value="${c.id}"${c.pred ? ' selected' : ''}>${esc(c.txt)}</option>`).join('')}</select>
                <label class="form-label mt-2">Comprobante (opcional)</label><input type="file" id="pArchivo" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf"></div>`,
            showCancelButton: true, confirmButtonText: 'Registrar pago', cancelButtonText: 'Cancelar', confirmButtonColor: '#16a34a', focusConfirm: false,
            preConfirm: () => {
                const fd = new FormData();
                fd.append('gasto_id', b.dataset.id);
                fd.append('fecha', document.getElementById('pFecha').value);
                fd.append('metodo_pago', document.getElementById('pMetodo').value);
                fd.append('cuenta_id', document.getElementById('pCuenta').value);
                const a = document.getElementById('pArchivo').files[0];
                if (a) fd.append('archivo_adjunto', a);
                return fetch('includes/gasto_marcar_pagado.php', { method: 'POST', body: fd }).then(r => r.json())
                    .then(d => { if (!d.success) throw new Error(d.error); return d; })
                    .catch(e => Swal.showValidationMessage(e.message));
            }
        }).then(r => { if (r.isConfirmed) Swal.fire({ icon: 'success', title: r.value.message }).then(() => location.reload()); });
    }));
})();
</script>

<?php require_once '../../includes/templates/footer.php'; ?>
