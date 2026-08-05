<?php
$titulo = 'Nueva Factura';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

$usuario_id = $_SESSION['usuario_id'];
$stmt = $pdo->prepare("
    SELECT u.nombre AS usuario_nombre, u.rol, c.id AS cliente_id, c.logo_url, c.nombre AS cliente_nombre
    FROM usuarios u INNER JOIN clientes_saas c ON u.cliente_id = c.id WHERE u.id = ?");
$stmt->execute([$usuario_id]);
$datos = $stmt->fetch();
$cliente_id = $datos['cliente_id'];
$_SESSION['cliente_id'] = $cliente_id;

$establecimiento_activo = $_SESSION['establecimiento_activo'] ?? null;
$nombre_establecimiento = 'No asignado';
if ($establecimiento_activo) {
    $st = $pdo->prepare("SELECT nombre FROM establecimientos WHERE establecimiento_id = ?");
    $st->execute([$establecimiento_activo]);
    $nombre_establecimiento = $st->fetchColumn() ?: 'No asignado';
}

$get_receptor_id  = (int)($_GET['receptor_id']  ?? 0);
$get_producto_id  = (int)($_GET['producto_id']  ?? 0);
$get_monto        = (float)($_GET['monto']       ?? 0);
$get_contrato_id  = (int)($_GET['contrato_id']  ?? 0);

/* ── Clientes factura ───────────────────────────────────────────────────── */
$stmtClientes = $pdo->prepare("SELECT id, nombre FROM clientes_factura WHERE cliente_id = ? ORDER BY nombre ASC");
$stmtClientes->execute([$cliente_id]);
$clientes = $stmtClientes->fetchAll();

/* ── CAI activos ────────────────────────────────────────────────────────── */
$stmtCai = $pdo->prepare("SELECT id, cai, rango_inicio, rango_fin, correlativo_actual, fecha_limite
    FROM cai_rangos WHERE cliente_id = ? AND fecha_limite >= CURDATE() ORDER BY fecha_creacion ASC");
$stmtCai->execute([$cliente_id]);
$cais = $stmtCai->fetchAll();

/* ── Si viene con receptor: pre-cargar productos y contratos ───────────── */
$productos_iniciales = [];
$contratos_iniciales = [];

if ($get_receptor_id) {
    $stmtProd = $pdo->prepare("SELECT p.id, p.nombre,
        COALESCE((SELECT precio_especial FROM precios_especiales WHERE producto_id=p.id AND cliente_id=p.cliente_id LIMIT 1), p.precio) AS precio,
        p.tipo_isv FROM productos_clientes p WHERE p.cliente_id=? ORDER BY p.nombre ASC");
    $stmtProd->execute([$cliente_id]);
    $productos_iniciales = $stmtProd->fetchAll(PDO::FETCH_ASSOC);

    $stmtCt = $pdo->prepare("SELECT c.id, c.nombre_contrato, c.monto, c.dia_pago,
        GROUP_CONCAT(p.nombre ORDER BY p.nombre SEPARATOR ' + ') AS servicios
        FROM contratos c
        LEFT JOIN contratos_servicios cs ON cs.contrato_id=c.id
        LEFT JOIN productos_clientes  p  ON p.id=cs.producto_id
        WHERE c.receptor_id=? AND c.cliente_id=? AND c.estado='activo'
        GROUP BY c.id ORDER BY c.nombre_contrato ASC");
    $stmtCt->execute([$get_receptor_id, $cliente_id]);
    $contratos_iniciales = $stmtCt->fetchAll(PDO::FETCH_ASSOC);
}

require_once '../../includes/templates/header.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    :root {
        --navy: #1e40af;
        --navy-dark: #1e3a8a;
        --border: #e2e8f0;
        --surface: #fff;
        --surface-2: #f8fafc;
        --text-main: #1e293b;
        --text-muted: #64748b;
        --shadow-sm: 0 1px 3px rgba(0, 0, 0, .06);
        --shadow-md: 0 4px 16px rgba(0, 0, 0, .08);
        --radius: 14px;
        --radius-sm: 8px;
        --tr: .2s cubic-bezier(.4, 0, .2, 1);
    }

    .fv-page {
        padding: 1.5rem 0 3rem;
    }

    .fv-header {
        background: linear-gradient(135deg, #1e40af 0%, #1e3a8a 100%);
        border-radius: var(--radius);
        padding: 1.6rem 2rem;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1.5rem;
        box-shadow: var(--shadow-md);
        position: relative;
        overflow: hidden;
    }

    .fv-header::before {
        content: '';
        position: absolute;
        top: -40px;
        right: -40px;
        width: 180px;
        height: 180px;
        border-radius: 50%;
        background: rgba(255, 255, 255, .08);
        pointer-events: none;
    }

    .fv-header::after {
        content: '';
        position: absolute;
        bottom: -60px;
        left: 30%;
        width: 260px;
        height: 140px;
        border-radius: 50%;
        background: rgba(255, 255, 255, .05);
        pointer-events: none;
    }

    .fv-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow-sm);
        margin-bottom: 1.25rem;
        overflow: hidden;
    }

    .fv-card-header {
        padding: .9rem 1.4rem;
        border-bottom: 1px solid var(--border);
        background: var(--surface-2);
        display: flex;
        align-items: center;
        gap: .6rem;
        font-weight: 700;
        font-size: .92rem;
    }

    .fv-card-body {
        padding: 1.25rem 1.4rem;
    }

    .producto-item {
        background: var(--surface-2);
        border: 1px solid var(--border);
        border-radius: var(--radius-sm);
        padding: 1rem;
        margin-bottom: .85rem;
        transition: box-shadow var(--tr);
    }

    .producto-item:hover {
        box-shadow: var(--shadow-sm);
    }

    .totales-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: .45rem 0;
        border-bottom: 1px solid var(--border);
        font-size: .88rem;
    }

    .totales-row:last-child {
        border-bottom: none;
    }

    .totales-row.total-final {
        font-size: 1.05rem;
        font-weight: 700;
        color: var(--navy);
        padding-top: .7rem;
        margin-top: .3rem;
        border-top: 2px solid var(--navy);
    }

    .totales-label {
        color: var(--text-muted);
        font-weight: 500;
    }

    .totales-val {
        font-weight: 700;
        color: var(--text-main);
    }

    .btn-guardar {
        display: inline-flex;
        align-items: center;
        gap: .5rem;
        padding: .7rem 2rem;
        background: var(--navy);
        color: #fff;
        border: none;
        border-radius: var(--radius-sm);
        font-size: .95rem;
        font-weight: 700;
        cursor: pointer;
        box-shadow: 0 2px 10px rgba(30, 64, 175, .35);
        transition: background var(--tr), transform var(--tr);
    }

    .btn-guardar:hover {
        background: var(--navy-dark);
        transform: translateY(-1px);
    }

    .btn-guardar:disabled {
        opacity: .65;
        cursor: not-allowed;
        transform: none;
    }

    .fv-form-label {
        font-size: .78rem;
        font-weight: 600;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: .04em;
        margin-bottom: .35rem;
        display: block;
    }

    .precio-sugerido {
        font-size: .75rem;
        color: #64748b;
        margin-top: 3px;
    }

    .exo-panel {
        background: #fffbeb;
        border: 1.5px solid #fde68a;
        border-radius: var(--radius-sm);
        padding: 1rem;
        margin-top: .5rem;
    }
</style>

<div class="fv-page container-xxl">

    <!-- Header -->
    <div class="fv-header">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <a href="lista_facturas" class="btn btn-sm btn-outline-light"
                    style="color:#fff;border-color:rgba(255,255,255,.6);background:rgba(255,255,255,.08)">
                    <i class="bi bi-arrow-left me-1"></i>Volver
                </a>
            </div>
            <h4 style="font-size:1.35rem;font-weight:700;margin:0"><i class="bi bi-file-earmark-plus me-2"></i>Nueva
                Factura</h4>
            <p style="font-size:.82rem;opacity:.8;margin:.25rem 0 0">
                <?= htmlspecialchars($nombre_establecimiento) ?> &nbsp;·&nbsp;
                <?= htmlspecialchars(ucfirst($datos['rol'])) ?> &nbsp;·&nbsp;
                <?= htmlspecialchars($datos['cliente_nombre']) ?>
            </p>
        </div>
        <div class="d-flex align-items-center gap-3">
            <?php if (!empty($datos['logo_url'])): ?>
                <img src="<?= htmlspecialchars($datos['logo_url']) ?>" alt="Logo"
                    style="max-height:48px;border-radius:8px;background:#fff;padding:4px;">
            <?php endif; ?>
            <div style="font-size:2.8rem;opacity:.2;font-weight:900;line-height:1">🧾</div>
        </div>
    </div>

    <form id="formFactura" action="guardar_factura" method="POST">
        <input type="hidden" name="establecimiento_id"
            value="<?= htmlspecialchars($_SESSION['establecimiento_activo'] ?? '') ?>">
        <input type="hidden" name="estado" value="emitida">
        <input type="hidden" name="fecha_emision" value="<?= date('Y-m-d H:i:s') ?>">

        <div class="row g-4">
            <div class="col-lg-8">

                <!-- CAI -->
                <div class="fv-card">
                    <div class="fv-card-header"><i class="bi bi-key-fill text-primary"></i>Autorización (CAI)</div>
                    <div class="fv-card-body">
                        <select name="cai_rango_id" id="cai_rango_id" class="form-select" required>
                            <option value="">— Seleccione un CAI activo —</option>
                            <?php foreach ($cais as $cai):
                                $total     = $cai['rango_fin'] - $cai['rango_inicio'] + 1;
                                $restantes = $total - (int)$cai['correlativo_actual'];
                            ?>
                                <option value="<?= $cai['id'] ?>">
                                    <?= htmlspecialchars($cai['cai']) ?> &nbsp;|&nbsp;
                                    Rango: <?= $cai['rango_inicio'] ?>–<?= $cai['rango_fin'] ?> &nbsp;|&nbsp;
                                    <?= $restantes ?> restantes &nbsp;|&nbsp;
                                    Válido hasta: <?= $cai['fecha_limite'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Cliente y Contrato -->
                <div class="fv-card">
                    <div class="fv-card-header"><i class="bi bi-person-fill text-primary"></i>Cliente y Contrato</div>
                    <div class="fv-card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="fv-form-label">Cliente (Receptor) <span
                                        class="text-danger">*</span></label>
                                <select name="receptor_id" id="receptor_id" class="form-select" required>
                                    <option value="">— Seleccione un cliente —</option>
                                    <?php foreach ($clientes as $cl): ?>
                                        <option value="<?= $cl['id'] ?>"
                                            <?= $cl['id'] == $get_receptor_id ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cl['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6" id="bloqueContrato"
                                style="<?= empty($contratos_iniciales) ? 'display:none' : '' ?>">
                                <label class="fv-form-label">Contrato asociado <span
                                        class="badge bg-light text-secondary border ms-1 fw-normal"
                                        style="text-transform:none;letter-spacing:0">Opcional</span></label>
                                <select name="contrato_id" id="contrato_id" class="form-select">
                                    <option value="">— Sin contrato (factura directa) —</option>
                                    <?php foreach ($contratos_iniciales as $ct): ?>
                                        <option value="<?= $ct['id'] ?>" data-monto="<?= $ct['monto'] ?>"
                                            <?= $ct['id'] == $get_contrato_id ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($ct['nombre_contrato']) ?><?= $ct['servicios'] ? ' — ' . htmlspecialchars($ct['servicios']) : '' ?>
                                            — L <?= number_format((float)$ct['monto'], 2) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted"><i class="bi bi-info-circle fa-xs me-1"></i>Asociar facilita
                                    el seguimiento mensual desde Contratos.</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Productos -->
                <div class="fv-card">
                    <div class="fv-card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-cart3 text-primary me-2"></i>Detalle de Servicios</span>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="agregar-producto">
                            <i class="bi bi-plus-circle me-1"></i>Agregar línea
                        </button>
                    </div>
                    <div class="fv-card-body">
                        <div id="productos-container">
                            <div class="producto-item">
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-5">
                                        <label class="fv-form-label">Producto / Servicio</label>
                                        <select name="productos[0][id]" class="form-select" required>
                                            <option value="">— Seleccionar —</option>
                                            <?php foreach ($productos_iniciales as $prod):
                                                $precio = (float)$prod['precio'];
                                            ?>
                                                <option value="<?= $prod['id'] ?>" data-precio="<?= $precio ?>"
                                                    data-isv="<?= $prod['tipo_isv'] ?>"
                                                    <?= $prod['id'] == $get_producto_id ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($prod['nombre']) ?><?php if ($precio > 0): ?> —
                                                    L<?= number_format($precio, 2) ?><?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-2">
                                        <label class="fv-form-label">Cantidad</label>
                                        <input type="number" name="productos[0][cantidad]" class="form-control" min="1"
                                            value="1" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="fv-form-label">Precio unitario (L)</label>
                                        <input type="number" step="0.01" name="productos[0][precio]"
                                            class="form-control precio-unitario"
                                            value="<?= $get_monto > 0 ? number_format($get_monto, 2, '.', '') : '' ?>"
                                            required>
                                    </div>
                                    <div class="col-md-2 d-flex align-items-end">
                                        <button type="button"
                                            class="btn btn-outline-danger btn-sm w-100 remove-producto"><i
                                                class="bi bi-trash3"></i></button>
                                    </div>
                                    <div class="col-12 mt-1">
                                        <textarea name="productos[0][detalles]" class="form-control form-control-sm"
                                            rows="1" placeholder="Descripción / mes de servicio (opcional)"></textarea>
                                    </div>
                                    <div class="col-12"><small class="precio-sugerido"></small></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Condiciones -->
                <div class="fv-card">
                    <div class="fv-card-header"><i class="bi bi-sliders text-secondary me-1"></i>Condiciones de Pago
                    </div>
                    <div class="fv-card-body">
                        <div class="row g-3 align-items-center">
                            <div class="col-md-4">
                                <label class="fv-form-label">Condición</label>
                                <select name="condicion_pago" class="form-select" required>
                                    <option value="Contado">Contado</option>
                                    <option value="Credito">Crédito</option>
                                </select>
                            </div>
                            <div class="col-md-4 d-flex align-items-end pb-1">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="exonerado" id="exonerado">
                                    <label class="form-check-label fw-semibold" for="exonerado">Factura
                                        exonerada</label>
                                </div>
                            </div>
                        </div>
                        <div id="campos-exoneracion" class="exo-panel mt-3" style="display:none">
                            <div class="row g-3">
                                <div class="col-md-4"><label class="fv-form-label">Orden de compra exenta</label><input
                                        type="text" name="orden_compra_exenta" class="form-control"></div>
                                <div class="col-md-4"><label class="fv-form-label">Constancia de
                                        exoneración</label><input type="text" name="constancia_exoneracion"
                                        class="form-control"></div>
                                <div class="col-md-4"><label class="fv-form-label">Registro SAG</label><input
                                        type="text" name="registro_sag" class="form-control"></div>
                            </div>
                        </div>
                    </div>
                </div>

            </div><!-- /col-lg-8 -->

            <!-- Totales sidebar -->
            <div class="col-lg-4">
                <div class="fv-card" style="position:sticky;top:1rem;">
                    <div class="fv-card-header"><i class="bi bi-receipt text-success me-1"></i>Resumen de Totales</div>
                    <div class="fv-card-body">
                        <div class="totales-row"><span class="totales-label">Subtotal</span><span class="totales-val"
                                id="subtotal">L 0.00</span></div>
                        <div class="totales-row"><span class="totales-label">Importe Gravado 15%</span><span
                                class="totales-val" id="importe_gravado_15">L 0.00</span></div>
                        <div class="totales-row"><span class="totales-label">Importe Gravado 18%</span><span
                                class="totales-val" id="importe_gravado_18">L 0.00</span></div>
                        <div class="totales-row"><span class="totales-label">ISV 15%</span><span class="totales-val"
                                id="isv_15">L 0.00</span></div>
                        <div class="totales-row"><span class="totales-label">ISV 18%</span><span class="totales-val"
                                id="isv_18">L 0.00</span></div>
                        <div class="totales-row total-final"><span class="totales-label"
                                style="color:var(--navy)">TOTAL</span><span class="totales-val fs-5" id="total_final"
                                style="color:var(--navy)">L 0.00</span></div>
                        <div class="mt-3 p-2 rounded-2"
                            style="background:#eff6ff;border:1px solid #bfdbfe;font-size:.78rem;color:#1e40af;font-style:italic;"
                            id="total_letras">—</div>

                        <!-- Campos ocultos para el POST -->
                        <input type="hidden" name="subtotal" id="h_subtotal">
                        <input type="hidden" name="importe_gravado_15" id="h_ig15">
                        <input type="hidden" name="importe_gravado_18" id="h_ig18">
                        <input type="hidden" name="isv_15" id="h_isv15">
                        <input type="hidden" name="isv_18" id="h_isv18">
                        <input type="hidden" name="total" id="h_total">
                        <input type="hidden" name="monto_letras" id="h_letras">
                    </div>
                    <div class="p-3 border-top">
                        <button type="submit" class="btn-guardar w-100" id="btnGuardar">
                            <i class="bi bi-floppy-fill me-1"></i>Guardar y Generar Factura
                        </button>
                    </div>
                </div>
            </div>

        </div><!-- /row -->
    </form>
</div>

<script>
    const CLIENTE_ID = <?= json_encode($cliente_id) ?>;
    let productoIndex = 1;

    /* ── Cambio de receptor ──────────────────────────────────────────────────── */
    document.getElementById('receptor_id').addEventListener('change', function() {
        const rId = this.value;
        const bloque = document.getElementById('bloqueContrato');
        const selCt = document.getElementById('contrato_id');
        selCt.innerHTML = '<option value="">— Sin contrato (factura directa) —</option>';
        bloque.style.display = 'none';
        document.querySelectorAll('.producto-item select[name$="[id]"]').forEach(s => {
            s.innerHTML = '<option value="">— Seleccionar —</option>';
        });
        if (!rId) return;

        fetch(`../../includes/api/productos_por_receptor.php?cliente_id=${CLIENTE_ID}&receptor_id=${rId}`)
            .then(r => r.json()).then(prods => {
                document.querySelectorAll('.producto-item select[name$="[id]"]').forEach(sel => {
                    sel.innerHTML = '<option value="">— Seleccionar —</option>';
                    prods.forEach(p => {
                        const o = new Option(
                            `${p.nombre}${parseFloat(p.precio)>0?' — L'+parseFloat(p.precio).toFixed(2):''}`,
                            p.id);
                        o.dataset.precio = p.precio;
                        o.dataset.isv = p.tipo_isv;
                        sel.appendChild(o);
                    });
                });
            }).catch(() => Swal.fire('Error', 'No se pudieron cargar los productos.', 'error'));

        fetch(`../../includes/api/contratos_por_receptor.php?cliente_id=${CLIENTE_ID}&receptor_id=${rId}`)
            .then(r => r.json()).then(cts => {
                if (cts.length > 0) {
                    bloque.style.display = 'block';
                    cts.forEach(ct => {
                        const o = new Option(
                            `${ct.nombre_contrato}${ct.servicios_nombres?' — '+ct.servicios_nombres:''} — L ${parseFloat(ct.monto).toFixed(2)}`,
                            ct.id);
                        o.dataset.monto = ct.monto;
                        selCt.appendChild(o);
                    });
                }
            }).catch(() => {});
    });

    /* ── Seleccionar producto → prellenar precio ─────────────────────────────── */
    document.getElementById('productos-container').addEventListener('change', function(e) {
        if (e.target.tagName === 'SELECT' && e.target.name.includes('[id]')) {
            const precio = parseFloat(e.target.selectedOptions[0]?.dataset.precio) || 0;
            const item = e.target.closest('.producto-item');
            if (precio > 0) item.querySelector('input[name$="[precio]"]').value = precio.toFixed(2);
            const sp = item.querySelector('.precio-sugerido');
            if (sp) sp.textContent = precio > 0 ? `Precio sugerido: L${precio.toFixed(2)}` : '';
            calcularTotales();
        }
    });
    document.getElementById('productos-container').addEventListener('input', calcularTotales);

    /* ── Contrato → sugerir monto ────────────────────────────────────────────── */
    document.getElementById('contrato_id').addEventListener('change', function() {
        const m = parseFloat(this.selectedOptions[0]?.dataset.monto) || 0;
        if (m > 0) {
            const inp = document.querySelector('input[name="productos[0][precio]"]');
            if (inp && !parseFloat(inp.value)) {
                inp.value = m.toFixed(2);
                calcularTotales();
            }
        }
    });

    /* ── Agregar / quitar líneas ─────────────────────────────────────────────── */
    document.getElementById('agregar-producto').addEventListener('click', () => {
        const cont = document.getElementById('productos-container');
        const nuevo = cont.children[0].cloneNode(true);
        nuevo.querySelectorAll('input,select,textarea').forEach(el => {
            if (el.name?.includes('productos')) el.name = el.name.replace(/\[\d+\]/, `[${productoIndex}]`);
            if (el.tagName === 'INPUT') el.value = el.name?.includes('cantidad') ? 1 : '';
            if (el.tagName === 'SELECT') el.selectedIndex = 0;
            if (el.tagName === 'TEXTAREA') el.value = '';
        });
        const sp = nuevo.querySelector('.precio-sugerido');
        if (sp) sp.textContent = '';
        cont.appendChild(nuevo);
        productoIndex++;
    });
    document.getElementById('productos-container').addEventListener('click', e => {
        if (e.target.closest('.remove-producto')) {
            if (document.querySelectorAll('.producto-item').length > 1) {
                e.target.closest('.producto-item').remove();
                calcularTotales();
            }
        }
    });

    /* ── Exoneración ─────────────────────────────────────────────────────────── */
    document.getElementById('exonerado').addEventListener('change', function() {
        document.getElementById('campos-exoneracion').style.display = this.checked ? 'block' : 'none';
        ['orden_compra_exenta', 'constancia_exoneracion', 'registro_sag'].forEach(n => {
            document.querySelector(`[name="${n}"]`).required = this.checked;
        });
        calcularTotales();
    });

    /* ── Calcular totales ────────────────────────────────────────────────────── */
    function calcularTotales() {
        let sub = 0,
            g15 = 0,
            g18 = 0,
            i15 = 0,
            i18 = 0;
        const exo = document.getElementById('exonerado').checked;
        document.querySelectorAll('.producto-item').forEach(item => {
            const c = parseFloat(item.querySelector('input[name$="[cantidad]"]')?.value) || 0;
            const p = parseFloat(item.querySelector('input[name$="[precio]"]')?.value) || 0;
            const v = parseInt(item.querySelector('select[name$="[id]"]')?.selectedOptions[0]?.dataset.isv) || 0;
            const t = c * p;
            sub += t;
            if (!exo) {
                if (v === 15) {
                    i15 += t * .15;
                    g15 += t;
                } else if (v === 18) {
                    i18 += t * .18;
                    g18 += t;
                }
            }
        });
        const total = sub + i15 + i18;
        const fmt = n => 'L ' + n.toLocaleString('es-HN', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
        document.getElementById('subtotal').textContent = fmt(sub);
        document.getElementById('isv_15').textContent = fmt(i15);
        document.getElementById('isv_18').textContent = fmt(i18);
        document.getElementById('total_final').textContent = fmt(total);
        document.getElementById('importe_gravado_15').textContent = fmt(g15);
        document.getElementById('importe_gravado_18').textContent = fmt(g18);
        const letras = numeroALetras(total);
        document.getElementById('total_letras').textContent = letras;
        // Campos ocultos
        document.getElementById('h_subtotal').value = sub.toFixed(2);
        document.getElementById('h_ig15').value = g15.toFixed(2);
        document.getElementById('h_ig18').value = g18.toFixed(2);
        document.getElementById('h_isv15').value = i15.toFixed(2);
        document.getElementById('h_isv18').value = i18.toFixed(2);
        document.getElementById('h_total').value = total.toFixed(2);
        document.getElementById('h_letras').value = letras;
    }

    /* ── Envío AJAX ──────────────────────────────────────────────────────────── */
    document.getElementById('formFactura').addEventListener('submit', function(e) {
        e.preventDefault();
        const btn = document.getElementById('btnGuardar');
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split me-2"></i>Guardando...';
        fetch('guardar_factura', {
                method: 'POST',
                body: new FormData(this)
            })
            .then(r => r.json()).then(data => {
                if (data.success) {
                    Swal.fire({
                            title: '¡Factura creada!',
                            text: data.message,
                            icon: 'success',
                            showCancelButton: true,
                            confirmButtonText: 'Ver Factura',
                            cancelButtonText: 'Ir a lista'
                        })
                        .then(result => {
                            if (result.isConfirmed && data.factura_id) window.open(
                                `ver_factura?id=${data.factura_id}`, '_blank');
                            window.location.href = 'lista_facturas';
                        });
                } else {
                    Swal.fire('Error', data.error, 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-floppy-fill me-1"></i>Guardar y Generar Factura';
                }
            }).catch(() => {
                Swal.fire('Error', 'Error inesperado.', 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-floppy-fill me-1"></i>Guardar y Generar Factura';
            });
    });

    /* ── Número a letras ─────────────────────────────────────────────────────── */
    function numeroALetras(num) {
        const U = ["", "uno", "dos", "tres", "cuatro", "cinco", "seis", "siete", "ocho", "nueve"];
        const D = ["", "", "veinte", "treinta", "cuarenta", "cincuenta", "sesenta", "setenta", "ochenta", "noventa"];
        const T = ["diez", "once", "doce", "trece", "catorce", "quince", "dieciséis", "diecisiete", "dieciocho",
            "diecinueve"
        ];
        const C = ["", "ciento", "doscientos", "trescientos", "cuatrocientos", "quinientos", "seiscientos", "setecientos",
            "ochocientos", "novecientos"
        ];

        function g(n) {
            let o = "";
            if (n == 100) return "cien";
            if (n > 99) {
                o += C[Math.floor(n / 100)] + " ";
                n %= 100;
            }
            if (n >= 20) {
                o += D[Math.floor(n / 10)];
                if (n % 10) o += " y " + U[n % 10];
            } else if (n >= 10) o += T[n - 10];
            else if (n > 0) o += U[n];
            return o.trim();
        }

        function sec(n, s, p) {
            return n === 0 ? "" : (n === 1 ? `un ${s}` : `${words(n)} ${p}`);
        }

        function words(n) {
            const M = Math.floor(n / 1e6),
                K = Math.floor((n - M * 1e6) / 1e3),
                R = n % 1e3;
            return ((M ? sec(M, "millón", "millones") + " " : "") + (K ? sec(K, "mil", "mil") + " " : "") + (R ? g(R) : ""))
                .trim();
        }
        const p = num.toFixed(2).split(".");
        const l = parseInt(p[0]),
            c = parseInt(p[1]);
        const t = words(l) + " lempiras" + (c > 0 ? ` con ${c}/100 centavos` : " exactos");
        return t.charAt(0).toUpperCase() + t.slice(1);
    }

    window.addEventListener('DOMContentLoaded', () => {
        calcularTotales();
        const m = <?= json_encode($get_monto) ?>;
        if (m > 0) {
            const i = document.querySelector('input[name="productos[0][precio]"]');
            if (i && !parseFloat(i.value)) {
                i.value = parseFloat(m).toFixed(2);
                calcularTotales();
            }
        }
    });
</script>

<?php require_once '../../includes/templates/footer.php'; ?>