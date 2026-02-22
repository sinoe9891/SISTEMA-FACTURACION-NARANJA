<?php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

$usuario_id = $_SESSION['usuario_id'];
$stmt = $pdo->prepare("
    SELECT u.nombre AS usuario_nombre, u.rol, c.id AS cliente_id, c.logo_url, c.nombre AS cliente_nombre
    FROM usuarios u
    INNER JOIN clientes_saas c ON u.cliente_id = c.id
    WHERE u.id = ?
");
$stmt->execute([$usuario_id]);
$datos = $stmt->fetch();
$cliente_id = $datos['cliente_id'];
$_SESSION['cliente_id'] = $cliente_id;

// ── Parámetros GET (llegando desde contratos) ─────────────────────────────────
$get_receptor_id = (int)($_GET['receptor_id']  ?? 0);
$get_producto_id = (int)($_GET['producto_id']  ?? 0);
$get_monto       = (float)($_GET['monto']       ?? 0);
$get_contrato_id = (int)($_GET['contrato_id']  ?? 0);

// ── Clientes ordenados ────────────────────────────────────────────────────────
$stmtClientes = $pdo->prepare("SELECT id, nombre FROM clientes_factura WHERE cliente_id = ? ORDER BY nombre ASC");
$stmtClientes->execute([$cliente_id]);
$clientes = $stmtClientes->fetchAll();

// ── CAI activos ───────────────────────────────────────────────────────────────
$stmtCai = $pdo->prepare("
    SELECT id, cai, rango_inicio, rango_fin, correlativo_actual, fecha_limite
    FROM cai_rangos
    WHERE cliente_id = ? AND fecha_limite >= CURDATE()
    ORDER BY fecha_creacion ASC
");
$stmtCai->execute([$cliente_id]);
$cais = $stmtCai->fetchAll();

// ── Si viene con receptor en GET → cargar productos y contratos en PHP ────────
// (esto soluciona el problema del select que no aparecía: lo cargamos en servidor)
$productos_iniciales = [];
$contratos_iniciales = [];

if ($get_receptor_id) {
    // Productos del receptor via API lógica (misma que productos_por_receptor.php)
    $stmtProd = $pdo->prepare("
        SELECT p.id, p.nombre,
               COALESCE((SELECT precio_especial FROM precios_especiales
                         WHERE producto_id = p.id AND cliente_id = p.cliente_id LIMIT 1),
                        p.precio) AS precio,
               p.tipo_isv
        FROM productos_clientes p
        WHERE p.cliente_id = ?
        ORDER BY p.nombre ASC
    ");
    $stmtProd->execute([$cliente_id]);
    $productos_iniciales = $stmtProd->fetchAll(PDO::FETCH_ASSOC);

    // Contratos activos del receptor con sus servicios
    $stmtCt = $pdo->prepare("
        SELECT c.id, c.nombre_contrato, c.monto, c.dia_pago,
               GROUP_CONCAT(p.nombre ORDER BY p.nombre SEPARATOR ' + ') AS servicios
        FROM contratos c
        LEFT JOIN contratos_servicios cs ON cs.contrato_id = c.id
        LEFT JOIN productos_clientes   p  ON p.id = cs.producto_id
        WHERE c.receptor_id = ? AND c.cliente_id = ? AND c.estado = 'activo'
        GROUP BY c.id
        ORDER BY c.nombre_contrato ASC
    ");
    $stmtCt->execute([$get_receptor_id, $cliente_id]);
    $contratos_iniciales = $stmtCt->fetchAll(PDO::FETCH_ASSOC);
}

require_once '../../includes/templates/header.php';
?>

<div class="container-xxl mt-4">

    <!-- ── Cabecera ─────────────────────────────────────────────────────────── -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <a href="lista_facturas" class="btn btn-sm btn-outline-secondary me-2">
                <i class="fa-solid fa-arrow-left me-1"></i> Volver
            </a>
            <h4 class="d-inline-block mb-0">
                <i class="fa-solid fa-file-invoice me-2 text-primary"></i>Nueva Factura
            </h4>
            <div><small class="text-muted">Complete la información para generar la factura</small></div>
        </div>
    </div>

    <form id="formFactura" action="guardar_factura" method="POST">

        <!-- ── CAI ──────────────────────────────────────────────────────────── -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-bottom py-3">
                <h6 class="mb-0 fw-bold"><i class="fa-solid fa-key me-2 text-primary"></i>Autorización (CAI)</h6>
            </div>
            <div class="card-body p-4">
                <select name="cai_rango_id" id="cai_rango_id" class="form-select" required>
                    <option value="">— Seleccione un CAI —</option>
                    <?php foreach ($cais as $cai):
                        $total     = $cai['rango_fin'] - $cai['rango_inicio'] + 1;
                        $restantes = $total - (int)$cai['correlativo_actual'];
                    ?>
                        <option value="<?= $cai['id'] ?>">
                            <?= htmlspecialchars($cai['cai']) ?> | Rango: <?= $cai['rango_inicio'] ?>–<?= $cai['rango_fin'] ?> | <?= $restantes ?> restantes | Válido hasta: <?= $cai['fecha_limite'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- ── Cliente y Contrato ────────────────────────────────────────────── -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-bottom py-3">
                <h6 class="mb-0 fw-bold"><i class="fa-solid fa-user me-2 text-primary"></i>Cliente y Contrato</h6>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">

                    <!-- Receptor -->
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Cliente (Receptor) <span class="text-danger">*</span>
                        </label>
                        <select name="receptor_id" id="receptor_id" class="form-select" required>
                            <option value="">— Seleccione un cliente —</option>
                            <?php foreach ($clientes as $cl): ?>
                                <option value="<?= $cl['id'] ?>" <?= $cl['id'] == $get_receptor_id ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cl['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Contrato (visible solo si hay contratos) -->
                    <div class="col-md-6" id="bloqueContrato"
                         style="<?= empty($contratos_iniciales) ? 'display:none' : '' ?>">
                        <label class="form-label fw-semibold">
                            Contrato asociado
                            <span class="badge bg-light text-secondary border ms-1">Opcional</span>
                        </label>
                        <select name="contrato_id" id="contrato_id" class="form-select">
                            <option value="">— Sin contrato (factura directa) —</option>
                            <?php foreach ($contratos_iniciales as $ct): ?>
                                <option value="<?= $ct['id'] ?>"
                                        data-monto="<?= $ct['monto'] ?>"
                                        <?= $ct['id'] == $get_contrato_id ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($ct['nombre_contrato']) ?>
                                    <?= $ct['servicios'] ? ' — ' . htmlspecialchars($ct['servicios']) : '' ?>
                                    — L <?= number_format((float)$ct['monto'], 2) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">
                            <i class="fa-solid fa-circle-info fa-xs me-1"></i>
                            Asociar facilita el seguimiento mensual desde Contratos.
                        </small>
                    </div>

                </div>
            </div>
        </div>

        <!-- ── Productos / Servicios ──────────────────────────────────────────── -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
                <h6 class="mb-0 fw-bold"><i class="fa-solid fa-calculator me-2 text-primary"></i>Detalle de Servicios</h6>
                <button type="button" class="btn btn-sm btn-outline-primary" id="agregar-producto">
                    <i class="fa-solid fa-plus me-1"></i> Agregar línea
                </button>
            </div>
            <div class="card-body p-4">
                <div id="productos-container">
                    <div class="producto-item border rounded-3 p-3 mb-3 bg-light">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-5">
                                <label class="form-label small fw-semibold mb-1">Producto / Servicio</label>
                                <select name="productos[0][id]" class="form-select" required>
                                    <option value="">— Seleccionar —</option>
                                    <?php foreach ($productos_iniciales as $prod):
                                        $precio = (float)$prod['precio'];
                                    ?>
                                        <option value="<?= $prod['id'] ?>"
                                                data-precio="<?= $precio ?>"
                                                data-isv="<?= $prod['tipo_isv'] ?>"
                                                <?= $prod['id'] == $get_producto_id ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($prod['nombre']) ?>
                                            <?php if ($precio > 0): ?>— L<?= number_format($precio, 2) ?><?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-semibold mb-1">Cantidad</label>
                                <input type="number" name="productos[0][cantidad]" class="form-control" min="1" value="1" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold mb-1">Precio unitario (L)</label>
                                <input type="number" step="0.01" name="productos[0][precio]" class="form-control"
                                       value="<?= $get_monto > 0 ? number_format($get_monto, 2, '.', '') : '' ?>" required>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="button" class="btn btn-outline-danger btn-sm w-100 remove-producto">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </div>
                            <div class="col-12 mt-1">
                                <textarea name="productos[0][detalles]" class="form-control form-control-sm"
                                          rows="1" placeholder="Descripción / mes de servicio (opcional)"></textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Condiciones ────────────────────────────────────────────────────── -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-bottom py-3">
                <h6 class="mb-0 fw-bold"><i class="fa-solid fa-sliders me-2 text-secondary"></i>Condiciones</h6>
            </div>
            <div class="card-body p-4">
                <div class="row g-3 align-items-center">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Condición de pago</label>
                        <select name="condicion_pago" class="form-select" required>
                            <option value="Contado">Contado</option>
                            <option value="Credito">Crédito</option>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex align-items-end pb-1">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="exonerado" id="exonerado">
                            <label class="form-check-label fw-semibold" for="exonerado">Factura exonerada</label>
                        </div>
                    </div>
                </div>
                <div id="campos-exoneracion" class="row g-3 mt-1" style="display:none">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Orden de compra exenta</label>
                        <input type="text" name="orden_compra_exenta" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Constancia de exoneración</label>
                        <input type="text" name="constancia_exoneracion" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Registro SAG</label>
                        <input type="text" name="registro_sag" class="form-control">
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Totales ────────────────────────────────────────────────────────── -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-bottom py-3">
                <h6 class="mb-0 fw-bold"><i class="fa-solid fa-receipt me-2 text-success"></i>Resumen de Totales</h6>
            </div>
            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label small text-muted">Subtotal</label>
                        <input type="text" id="subtotal" class="form-control fw-semibold" readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small text-muted">Importe Gravado 15%</label>
                        <input type="text" name="importe_gravado_15" id="importe_gravado_15" class="form-control" readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small text-muted">Importe Gravado 18%</label>
                        <input type="text" name="importe_gravado_18" id="importe_gravado_18" class="form-control" readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small text-muted">ISV (15%)</label>
                        <input type="text" id="isv_15" class="form-control" readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small text-muted">ISV (18%)</label>
                        <input type="text" id="isv_18" class="form-control" readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small text-muted">TOTAL</label>
                        <input type="text" id="total_final" class="form-control fw-bold fs-5 text-primary" readonly>
                    </div>
                    <div class="col-12">
                        <label class="form-label small text-muted">En letras</label>
                        <input type="text" id="total_letras" class="form-control fst-italic text-primary" readonly>
                    </div>
                </div>
            </div>
        </div>

        <!-- Campos ocultos -->
        <input type="hidden" name="establecimiento_id" value="<?= htmlspecialchars($_SESSION['establecimiento_activo'] ?? '') ?>">
        <input type="hidden" name="estado" value="emitida">
        <input type="hidden" name="fecha_emision" value="<?= date('Y-m-d H:i:s') ?>">

        <!-- Botón submit -->
        <div class="d-grid mb-5">
            <button type="submit" class="btn btn-primary btn-lg" id="btnGuardar">
                <i class="fa-solid fa-floppy-disk me-2"></i>Guardar y Generar Factura
            </button>
        </div>

    </form>
</div>

<script>
const CLIENTE_ID  = <?= json_encode($cliente_id) ?>;
let productoIndex = 1;

// ── Al cambiar receptor: recargar productos + contratos ───────────────────────
document.getElementById('receptor_id').addEventListener('change', function () {
    const receptorId   = this.value;
    const bloqueCtrato = document.getElementById('bloqueContrato');
    const selCtrato    = document.getElementById('contrato_id');

    // Reset
    selCtrato.innerHTML = '<option value="">— Sin contrato (factura directa) —</option>';
    bloqueCtrato.style.display = 'none';

    document.querySelectorAll('.producto-item select[name$="[id]"]').forEach(sel => {
        sel.innerHTML = '<option value="">— Seleccionar —</option>';
    });

    if (!receptorId) return;

    // Productos
    fetch(`../../includes/api/productos_por_receptor.php?cliente_id=${CLIENTE_ID}&receptor_id=${receptorId}`)
        .then(r => r.json())
        .then(prods => {
            document.querySelectorAll('.producto-item select[name$="[id]"]').forEach(sel => {
                sel.innerHTML = '<option value="">— Seleccionar —</option>';
                prods.forEach(prod => {
                    const o = document.createElement('option');
                    o.value = prod.id;
                    o.textContent = prod.nombre + (parseFloat(prod.precio) > 0 ? ` — L${parseFloat(prod.precio).toFixed(2)}` : '');
                    o.setAttribute('data-precio', prod.precio);
                    o.setAttribute('data-isv', prod.tipo_isv);
                    sel.appendChild(o);
                });
            });
        }).catch(() => Swal.fire('Error', 'No se pudieron cargar los productos.', 'error'));

    // Contratos
    fetch(`../../includes/api/contratos_por_receptor.php?cliente_id=${CLIENTE_ID}&receptor_id=${receptorId}`)
        .then(r => r.json())
        .then(contratos => {
            if (contratos.length > 0) {
                bloqueCtrato.style.display = 'block';
                contratos.forEach(ct => {
                    const o = document.createElement('option');
                    o.value = ct.id;
                    o.textContent = ct.nombre_contrato
                        + (ct.servicios_nombres ? ' — ' + ct.servicios_nombres : '')
                        + ' — L ' + parseFloat(ct.monto).toFixed(2);
                    o.setAttribute('data-monto', ct.monto);
                    selCtrato.appendChild(o);
                });
            }
        }).catch(() => {});
});

// ── Al seleccionar producto: pre-llenar precio ────────────────────────────────
document.getElementById('productos-container').addEventListener('change', function (e) {
    if (e.target.tagName === 'SELECT' && e.target.name.includes('[id]')) {
        const precio = parseFloat(e.target.selectedOptions[0]?.getAttribute('data-precio')) || 0;
        if (precio > 0) e.target.closest('.producto-item').querySelector('input[name$="[precio]"]').value = precio.toFixed(2);
        calcularTotales();
    }
});
document.getElementById('productos-container').addEventListener('input', calcularTotales);

// ── Al seleccionar contrato: sugerir monto ────────────────────────────────────
document.getElementById('contrato_id').addEventListener('change', function () {
    const monto = parseFloat(this.selectedOptions[0]?.getAttribute('data-monto')) || 0;
    if (monto > 0) {
        const inp = document.querySelector('input[name="productos[0][precio]"]');
        if (inp && !parseFloat(inp.value)) { inp.value = monto.toFixed(2); calcularTotales(); }
    }
});

// ── Agregar / quitar líneas ───────────────────────────────────────────────────
document.getElementById('agregar-producto').addEventListener('click', () => {
    const cont = document.getElementById('productos-container');
    const nuevo = cont.children[0].cloneNode(true);
    nuevo.querySelectorAll('input,select,textarea').forEach(el => {
        if (el.name?.includes('productos')) el.name = el.name.replace(/\[\d+\]/, `[${productoIndex}]`);
        if (el.tagName === 'INPUT')    el.value = el.name?.includes('cantidad') ? 1 : '';
        if (el.tagName === 'SELECT')   el.selectedIndex = 0;
        if (el.tagName === 'TEXTAREA') el.value = '';
    });
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

// ── Exoneración ───────────────────────────────────────────────────────────────
document.getElementById('exonerado').addEventListener('change', function () {
    document.getElementById('campos-exoneracion').style.display = this.checked ? 'flex' : 'none';
    ['orden_compra_exenta','constancia_exoneracion','registro_sag'].forEach(n => {
        document.querySelector(`[name="${n}"]`).required = this.checked;
    });
    calcularTotales();
});

// ── Calcular totales ──────────────────────────────────────────────────────────
function calcularTotales() {
    let sub=0, g15=0, g18=0, i15=0, i18=0;
    const exo = document.getElementById('exonerado').checked;
    document.querySelectorAll('.producto-item').forEach(item => {
        const c = parseFloat(item.querySelector('input[name$="[cantidad]"]').value)||0;
        const p = parseFloat(item.querySelector('input[name$="[precio]"]').value)||0;
        const v = parseInt(item.querySelector('select[name$="[id]"]').selectedOptions[0]?.getAttribute('data-isv'))||0;
        const t = c*p; sub+=t;
        if(!exo){ if(v===15){i15+=t*.15;g15+=t;} else if(v===18){i18+=t*.18;g18+=t;} }
    });
    const total=sub+i15+i18;
    const fmt=n=>'L '+n.toLocaleString('es-HN',{minimumFractionDigits:2,maximumFractionDigits:2});
    document.getElementById('subtotal').value=fmt(sub);
    document.getElementById('isv_15').value=fmt(i15);
    document.getElementById('isv_18').value=fmt(i18);
    document.getElementById('total_final').value=fmt(total);
    document.getElementById('importe_gravado_15').value=fmt(g15);
    document.getElementById('importe_gravado_18').value=fmt(g18);
    document.getElementById('total_letras').value=numeroALetras(total);
}

// ── Envío AJAX ────────────────────────────────────────────────────────────────
document.getElementById('formFactura').addEventListener('submit', function (e) {
    e.preventDefault();
    const btn = document.getElementById('btnGuardar');
    btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-spinner fa-spin me-2"></i>Guardando...';
    fetch('guardar_factura',{method:'POST',body:new FormData(this)})
        .then(r=>r.json())
        .then(data=>{
            if(data.success){
                Swal.fire({title:'¡Factura creada!',text:data.message,icon:'success',
                    showCancelButton:true,confirmButtonText:'Ver Factura',cancelButtonText:'Ir a lista'
                }).then(result=>{
                    if(result.isConfirmed&&data.factura_id) window.open(`ver_factura?id=${data.factura_id}`,'_blank');
                    window.location.href='lista_facturas';
                });
            }else{
                Swal.fire('Error',data.error,'error');
                btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk me-2"></i>Guardar y Generar Factura';
            }
        }).catch(()=>{
            Swal.fire('Error','Error inesperado.','error');
            btn.disabled=false; btn.innerHTML='<i class="fa-solid fa-floppy-disk me-2"></i>Guardar y Generar Factura';
        });
});

// ── Número a letras ───────────────────────────────────────────────────────────
function numeroALetras(num){
    const U=["","uno","dos","tres","cuatro","cinco","seis","siete","ocho","nueve"];
    const D=["","","veinte","treinta","cuarenta","cincuenta","sesenta","setenta","ochenta","noventa"];
    const T=["diez","once","doce","trece","catorce","quince","dieciséis","diecisiete","dieciocho","diecinueve"];
    const C=["","ciento","doscientos","trescientos","cuatrocientos","quinientos","seiscientos","setecientos","ochocientos","novecientos"];
    function g(n){let o="";if(n==100)return"cien";if(n>99){o+=C[Math.floor(n/100)]+" ";n%=100;}
        if(n>=20){o+=D[Math.floor(n/10)];if(n%10)o+=" y "+U[n%10];}else if(n>=10)o+=T[n-10];else if(n>0)o+=U[n];return o.trim();}
    function sec(n,s,p){return n===0?"":(n===1?`un ${s}`:`${words(n)} ${p}`);}
    function words(n){const M=Math.floor(n/1000000),K=Math.floor((n-M*1000000)/1000),R=n%1000;
        return((M?sec(M,"millón","millones")+" ":"")+(K?sec(K,"mil","mil")+" ":"")+(R?g(R):"")).trim();}
    const p=num.toFixed(2).split(".");const l=parseInt(p[0]),c=parseInt(p[1]);
    const t=words(l)+" lempiras"+(c>0?` con ${c}/100 centavos`:" exactos");
    return t.charAt(0).toUpperCase()+t.slice(1);
}

// ── Init ──────────────────────────────────────────────────────────────────────
window.addEventListener('DOMContentLoaded',()=>{
    calcularTotales();
    const montoGet=<?= json_encode($get_monto) ?>;
    if(montoGet>0){
        const inp=document.querySelector('input[name="productos[0][precio]"]');
        if(inp&&!parseFloat(inp.value)){inp.value=parseFloat(montoGet).toFixed(2);calcularTotales();}
    }
});
</script>

</body>
</html>