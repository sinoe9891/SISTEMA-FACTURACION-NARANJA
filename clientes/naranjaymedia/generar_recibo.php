<?php

/**
 * generar_recibo.php
 * Crea un recibo de cobro para contratos tipo 'sin_factura'.
 * Ruta: clientes/naranjaymedia/generar_recibo.php
 */
$titulo = 'Generar Recibo';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';
require_once '../../includes/templates/header.php';

$cliente_id = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);

$contrato_id = filter_input(INPUT_GET, 'contrato_id', FILTER_VALIDATE_INT);
if (!$contrato_id) {
    header('Location: contratos');
    exit;
}

// Cargar contrato (solo tipo sin_factura de este cliente)
$stmt = $pdo->prepare("
    SELECT c.*, cf.nombre AS receptor_nombre, cf.rtn AS receptor_rtn,
           cf.email AS receptor_email, cf.telefono AS receptor_tel,
           p.nombre AS producto_nombre
    FROM contratos c
    INNER JOIN clientes_factura cf   ON cf.id = c.receptor_id AND cf.cliente_id = c.cliente_id
    INNER JOIN productos_clientes p  ON p.id  = c.producto_id AND p.cliente_id  = c.cliente_id
    WHERE c.id = ? AND c.cliente_id = ? AND c.tipo_contrato = 'sin_factura'
");
$stmt->execute([$contrato_id, $cliente_id]);
$ct = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$ct) {
    header('Location: contratos');
    exit;
}

// Siguiente número de recibo
$stmtCnt = $pdo->prepare("
    SELECT COALESCE(MAX(numero_recibo), 0) + 1 FROM contratos_recibos WHERE cliente_id = ?
");
$stmtCnt->execute([$cliente_id]);
$next_num = (int)$stmtCnt->fetchColumn();
$num_formateado = str_pad($next_num, 5, '0', STR_PAD_LEFT);

// Datos del cliente SaaS (para encabezado)
$stmtEmp = $pdo->prepare("SELECT nombre, rtn FROM clientes_saas WHERE id = ?");
$stmtEmp->execute([$cliente_id]);
$empresa = $stmtEmp->fetch(PDO::FETCH_ASSOC) ?: ['nombre' => 'Mi Empresa', 'rtn' => ''];

// Recibos anteriores de este contrato
$stmtRec = $pdo->prepare("
    SELECT * FROM contratos_recibos
    WHERE contrato_id = ? AND cliente_id = ? AND estado = 'emitido'
    ORDER BY fecha_emision DESC LIMIT 12
");
$stmtRec->execute([$contrato_id, $cliente_id]);
$recibos_ant = $stmtRec->fetchAll(PDO::FETCH_ASSOC);

$meses_es = [
    '',
    'Enero',
    'Febrero',
    'Marzo',
    'Abril',
    'Mayo',
    'Junio',
    'Julio',
    'Agosto',
    'Septiembre',
    'Octubre',
    'Noviembre',
    'Diciembre'
];
?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
    rel="stylesheet">

<style>
    :root {
        --brand: #7c3aed;
        --brand-dk: #5b21b6;
        --brand-lt: #ede9fe;
        --surface: #fff;
        --surface-2: #f8fafc;
        --border: #e2e8f0;
        --text: #0f172a;
        --muted: #64748b;
        --radius: 16px;
        --radius-sm: 10px;
        --shadow-sm: 0 1px 3px rgba(0, 0, 0, .06);
        --shadow-md: 0 6px 24px rgba(0, 0, 0, .09);
        --tr: .18s cubic-bezier(.4, 0, .2, 1);
        font-family: 'Plus Jakarta Sans', sans-serif;
    }

    .rc-wrap {
        max-width: 960px;
        margin: 0 auto;
        padding: 1.5rem 0 4rem
    }

    .rc-hero {
        background: linear-gradient(135deg, #5b21b6 0%, #7c3aed 100%);
        border-radius: var(--radius);
        padding: 1.6rem 2rem;
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1.75rem;
        box-shadow: 0 8px 32px rgba(124, 58, 237, .25);
        position: relative;
        overflow: hidden;
    }

    .rc-hero::before {
        content: '';
        position: absolute;
        top: -50px;
        right: -50px;
        width: 200px;
        height: 200px;
        border-radius: 50%;
        background: rgba(255, 255, 255, .07);
        pointer-events: none;
    }

    .rc-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow-sm);
        overflow: hidden;
        margin-bottom: 1.25rem;
    }

    .rc-card-hdr {
        padding: .9rem 1.4rem;
        border-bottom: 1px solid var(--border);
        background: var(--surface-2);
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: .5rem;
    }

    .rc-card-title {
        font-size: .9rem;
        font-weight: 700;
        color: var(--text);
        display: flex;
        align-items: center;
        gap: .5rem
    }

    .rc-field label {
        font-size: .78rem;
        font-weight: 600;
        color: var(--muted);
        text-transform: uppercase;
        letter-spacing: .04em;
        margin-bottom: .3rem;
        display: block
    }

    .rc-field input,
    .rc-field textarea,
    .rc-field select {
        width: 100%;
        padding: .6rem .9rem;
        border: 1.5px solid var(--border);
        border-radius: var(--radius-sm);
        font-size: .9rem;
        color: var(--text);
        background: var(--surface);
        outline: none;
        transition: border-color var(--tr);
    }

    .rc-field input:focus,
    .rc-field textarea:focus,
    .rc-field select:focus {
        border-color: var(--brand);
        box-shadow: 0 0 0 3px rgba(124, 58, 237, .1);
    }

    .btn-rc-primary {
        display: inline-flex;
        align-items: center;
        gap: .45rem;
        padding: .65rem 1.4rem;
        background: var(--brand);
        color: #fff;
        border: none;
        border-radius: var(--radius-sm);
        font-size: .9rem;
        font-weight: 700;
        cursor: pointer;
        transition: background var(--tr), transform var(--tr);
        box-shadow: 0 2px 10px rgba(124, 58, 237, .3);
    }

    .btn-rc-primary:hover {
        background: var(--brand-dk);
        transform: translateY(-1px)
    }

    /* Vista previa del recibo */
    .recibo-preview {
        background: #fff;
        border: 2px solid #7c3aed;
        border-radius: 12px;
        padding: 2rem;
        max-width: 640px;
        margin: 0 auto;
    }

    .recibo-header {
        text-align: center;
        border-bottom: 2px solid #ede9fe;
        padding-bottom: 1rem;
        margin-bottom: 1.5rem
    }

    .recibo-num {
        font-size: 1.8rem;
        font-weight: 800;
        color: #5b21b6;
        letter-spacing: -1px
    }

    .recibo-row {
        display: flex;
        justify-content: space-between;
        padding: .4rem 0;
        border-bottom: 1px solid #f1f5f9;
        font-size: .88rem
    }

    .recibo-monto {
        text-align: center;
        padding: 1rem;
        background: #f5f3ff;
        border-radius: 10px;
        margin-top: 1rem
    }

    .recibo-monto-num {
        font-size: 2rem;
        font-weight: 800;
        color: #5b21b6
    }
</style>

<div class="container-xxl rc-wrap">
    <div class="rc-hero">
        <div>
            <h4 style="font-size:1.35rem;font-weight:800;margin:0"><i class="bi bi-receipt me-2"></i>Generar Recibo de
                Cobro</h4>
            <p style="font-size:.82rem;opacity:.78;margin:.2rem 0 0">
                Contrato sin factura · <?= htmlspecialchars($ct['receptor_nombre']) ?>
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="contratos" class="btn btn-sm"
                style="background:rgba(255,255,255,.18);color:#fff;border:1px solid rgba(255,255,255,.3);font-weight:600">
                <i class="bi bi-arrow-left me-1"></i>Volver
            </a>
        </div>
    </div>

    <div class="row g-3">
        <!-- Formulario -->
        <div class="col-lg-7">
            <div class="rc-card">
                <div class="rc-card-hdr">
                    <span class="rc-card-title"><i class="bi bi-pencil-square" style="color:#7c3aed"></i> Datos del
                        Recibo</span>
                    <span class="badge rounded-pill" style="background:#ede9fe;color:#7c3aed;font-size:.75rem">Recibo
                        #<?= $num_formateado ?></span>
                </div>
                <div class="p-4">
                    <form id="formRecibo">
                        <input type="hidden" name="contrato_id" value="<?= $ct['id'] ?>">
                        <input type="hidden" name="receptor_id" value="<?= $ct['receptor_id'] ?>">
                        <input type="hidden" name="numero_recibo" value="<?= $next_num ?>">

                        <div class="row g-3">
                            <!-- Info del contrato (readonly) -->
                            <div class="col-12">
                                <div
                                    style="background:#f5f3ff;border:1px solid #ddd6fe;border-radius:10px;padding:.85rem 1rem;font-size:.85rem">
                                    <div class="fw-semibold mb-1" style="color:#5b21b6"><i
                                            class="bi bi-person-fill me-1"></i><?= htmlspecialchars($ct['receptor_nombre']) ?>
                                    </div>
                                    <div class="text-muted"><?= htmlspecialchars($ct['producto_nombre']) ?></div>
                                    <?php if ($ct['receptor_rtn']): ?><div class="text-muted">RTN:
                                            <?= htmlspecialchars($ct['receptor_rtn']) ?></div><?php endif; ?>
                                </div>
                            </div>

                            <div class="col-md-6 rc-field">
                                <label>Monto (L) *</label>
                                <input type="number" name="monto" id="rcMonto" step="0.01" min="0.01"
                                    value="<?= number_format((float)$ct['monto'], 2, '.', '') ?>" required>
                            </div>

                            <div class="col-md-6 rc-field">
                                <label>Fecha de Emisión *</label>
                                <input type="date" name="fecha_emision" value="<?= date('Y-m-d') ?>" required>
                            </div>

                            <div class="col-md-6 rc-field">
                                <label>Período — Mes</label>
                                <select name="periodo_mes">
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?= $m ?>" <?= $m == (int)date('n') ? 'selected' : '' ?>>
                                            <?= $meses_es[$m] ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>

                            <div class="col-md-6 rc-field">
                                <label>Período — Año</label>
                                <select name="periodo_anio">
                                    <?php for ($a = date('Y'); $a >= date('Y') - 2; $a--): ?>
                                        <option value="<?= $a ?>" <?= $a == (int)date('Y') ? 'selected' : '' ?>><?= $a ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>

                            <div class="col-12 rc-field">
                                <label>Descripción / Concepto *</label>
                                <input type="text" name="descripcion" required
                                    value="<?= htmlspecialchars($ct['producto_nombre']) ?>"
                                    placeholder="Concepto del recibo…" maxlength="300">
                            </div>

                            <div class="col-12 rc-field">
                                <label>Método de pago</label>
                                <select name="metodo_pago">
                                    <option value="transferencia">🏦 Transferencia bancaria</option>
                                    <option value="efectivo">💵 Efectivo</option>
                                    <option value="cheque">📝 Cheque</option>
                                    <option value="tarjeta">💳 Tarjeta</option>
                                    <option value="otro">🔷 Otro</option>
                                </select>
                            </div>

                            <div class="col-12 rc-field">
                                <label>Notas (opcional)</label>
                                <textarea name="notas" rows="2" placeholder="Observaciones adicionales…"
                                    maxlength="500"></textarea>
                            </div>

                            <div class="col-12 d-flex gap-2 justify-content-end mt-2">
                                <a href="contratos" class="btn btn-outline-secondary">Cancelar</a>
                                <button type="button" id="btnPreview" class="btn btn-outline-secondary">
                                    <i class="bi bi-eye me-1"></i>Vista previa
                                </button>
                                <button type="submit" class="btn-rc-primary">
                                    <i class="bi bi-receipt me-1"></i>Emitir Recibo #<?= $num_formateado ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Vista previa + historial -->
        <div class="col-lg-5">
            <!-- Preview -->
            <div class="rc-card mb-3">
                <div class="rc-card-hdr">
                    <span class="rc-card-title"><i class="bi bi-eye" style="color:#7c3aed"></i> Vista Previa</span>
                </div>
                <div class="p-3">
                    <div class="recibo-preview" id="reciboPreview">
                        <div class="recibo-header">
                            <div style="font-size:.8rem;color:#64748b;text-transform:uppercase;letter-spacing:.1em">
                                Recibo de Cobro</div>
                            <div class="recibo-num">#<?= $num_formateado ?></div>
                            <div style="font-size:.9rem;font-weight:700"><?= htmlspecialchars($empresa['nombre']) ?>
                            </div>
                            <?php if ($empresa['rtn']): ?><div style="font-size:.75rem;color:#64748b">RTN:
                                    <?= htmlspecialchars($empresa['rtn']) ?></div><?php endif; ?>
                            <div style="font-size:.75rem;color:#94a3b8;margin-top:.25rem" id="pvFecha">
                                <?= date('d/m/Y') ?></div>
                        </div>
                        <div class="recibo-row"><span class="text-muted">Recibido de:</span><span class="fw-semibold"
                                style="max-width:200px;text-align:right"><?= htmlspecialchars($ct['receptor_nombre']) ?></span>
                        </div>
                        <?php if ($ct['receptor_rtn']): ?><div class="recibo-row"><span
                                    class="text-muted">RTN:</span><span><?= htmlspecialchars($ct['receptor_rtn']) ?></span>
                            </div><?php endif; ?>
                        <div class="recibo-row"><span class="text-muted">Concepto:</span><span class="fw-semibold"
                                style="max-width:200px;text-align:right"
                                id="pvConcepto"><?= htmlspecialchars($ct['producto_nombre']) ?></span></div>
                        <div class="recibo-row"><span class="text-muted">Período:</span><span
                                id="pvPeriodo"><?= $meses_es[(int)date('n')] . ' ' . date('Y') ?></span></div>
                        <div class="recibo-row"><span class="text-muted">Método pago:</span><span
                                id="pvMetodo">Transferencia bancaria</span></div>
                        <div class="recibo-monto">
                            <div style="font-size:.75rem;color:#64748b;text-transform:uppercase;letter-spacing:.08em">
                                Monto recibido</div>
                            <div class="recibo-monto-num" id="pvMonto">L <?= number_format((float)$ct['monto'], 2) ?>
                            </div>
                        </div>
                        <div style="text-align:center;margin-top:1rem;font-size:.7rem;color:#94a3b8">
                            Este recibo no constituye una factura fiscal.<br>
                            <?= htmlspecialchars($empresa['nombre']) ?> · <?= date('Y') ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Historial -->
            <?php if (!empty($recibos_ant)): ?>
                <div class="rc-card">
                    <div class="rc-card-hdr">
                        <span class="rc-card-title"><i class="bi bi-clock-history text-secondary"></i> Recibos
                            Anteriores</span>
                    </div>
                    <div class="p-3">
                        <?php foreach ($recibos_ant as $r): ?>
                            <div
                                style="display:flex;justify-content:space-between;align-items:center;padding:.5rem 0;border-bottom:1px solid var(--border);font-size:.83rem">
                                <div>
                                    <span class="fw-semibold"
                                        style="color:#5b21b6">#<?= str_pad($r['numero_recibo'], 5, '0', STR_PAD_LEFT) ?></span>
                                    <span class="text-muted ms-2"><?= $meses_es[(int)$r['periodo_mes']] ?>
                                        <?= $r['periodo_anio'] ?></span>
                                </div>
                                <span class="fw-bold" style="color:#059669">L <?= number_format((float)$r['monto'], 0) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    const meses = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre',
        'Noviembre', 'Diciembre'
    ];
    const metodos = {
        transferencia: 'Transferencia bancaria',
        efectivo: 'Efectivo',
        cheque: 'Cheque',
        tarjeta: 'Tarjeta',
        otro: 'Otro'
    };

    // Live preview updates
    function updatePreview() {
        const f = document.getElementById('formRecibo');
        const monto = parseFloat(f.querySelector('[name=monto]').value || 0);
        const mes = parseInt(f.querySelector('[name=periodo_mes]').value);
        const anio = f.querySelector('[name=periodo_anio]').value;
        const desc = f.querySelector('[name=descripcion]').value;
        const met = f.querySelector('[name=metodo_pago]').value;
        const fecha = f.querySelector('[name=fecha_emision]').value;

        document.getElementById('pvMonto').textContent = 'L ' + monto.toLocaleString('es-HN', {
            minimumFractionDigits: 2
        });
        document.getElementById('pvPeriodo').textContent = (meses[mes] || '') + ' ' + anio;
        document.getElementById('pvConcepto').textContent = desc;
        document.getElementById('pvMetodo').textContent = metodos[met] || met;
        if (fecha) {
            const [y, m, d] = fecha.split('-');
            document.getElementById('pvFecha').textContent = d + '/' + m + '/' + y;
        }
    }

    document.getElementById('formRecibo').querySelectorAll('input,select,textarea').forEach(el => {
        el.addEventListener('input', updatePreview);
        el.addEventListener('change', updatePreview);
    });

    // Botón "Vista previa" — actualiza y hace scroll al preview (útil en móvil)
    document.getElementById('btnPreview').addEventListener('click', () => {
        updatePreview();
        const preview = document.getElementById('reciboPreview');
        preview.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
        });
        preview.style.transition = 'box-shadow .3s';
        preview.style.boxShadow = '0 0 0 4px rgba(124,58,237,.4)';
        setTimeout(() => {
            preview.style.boxShadow = '';
        }, 1200);
    });

    // Enviar
    document.getElementById('formRecibo').addEventListener('submit', function(e) {
        e.preventDefault();
        const btn = this.querySelector('[type=submit]');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Emitiendo…';

        fetch('includes/recibo_guardar.php', {
                method: 'POST',
                body: new FormData(this)
            })
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    Swal.fire({
                        icon: 'success',
                        title: '¡Recibo emitido!',
                        html: `Recibo <strong>#${d.numero}</strong> generado correctamente.`,
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => window.location.href = 'contratos');
                } else {
                    Swal.fire('Error', d.error || 'No se pudo emitir el recibo.', 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-receipt me-1"></i>Emitir Recibo';
                }
            }).catch(() => {
                Swal.fire('Error', 'Error de conexión.', 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-receipt me-1"></i>Emitir Recibo';
            });
    });
</script>

<?php require_once '../../includes/templates/footer.php'; ?>