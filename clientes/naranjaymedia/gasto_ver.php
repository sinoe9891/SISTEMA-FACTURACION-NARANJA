<?php
// clientes/naranjaymedia/gasto_ver.php
$titulo = 'Comprobante de Gasto';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

$cliente_id = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    header('Location: gastos');
    exit;
}

// Cargar gasto con categoría
$stmt = $pdo->prepare("
    SELECT g.*,
           cg.nombre AS cat_nombre, cg.color AS cat_color, cg.icono AS cat_icono
    FROM gastos g
    LEFT JOIN categorias_gastos cg ON cg.id = g.categoria_id
    WHERE g.id = ? AND g.cliente_id = ?
");
$stmt->execute([$id, $cliente_id]);
$g = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$g) {
    header('Location: gastos');
    exit;
}

// Datos del cliente (para encabezado del comprobante)
$stmtCli = $pdo->prepare("
    SELECT nombre, logo_url AS logo, rtn
    FROM clientes_saas
    WHERE id = ?
");
$stmtCli->execute([$cliente_id]);
$cliente = $stmtCli->fetch(PDO::FETCH_ASSOC) ?: ['nombre' => 'Mi Empresa', 'logo' => null, 'rtn' => null];

// ── Tarjetas del cliente ──────────────────────────────────────────────────────
$stmtTarj = $pdo->prepare("SELECT id, banco, tipo, ultimos_digitos FROM tarjetas WHERE cliente_id=? AND activa=1 ORDER BY banco");
$stmtTarj->execute([$cliente_id]);
$tarjetas_lista = $stmtTarj->fetchAll(PDO::FETCH_ASSOC);

// ── Helpers ────────────────────────────────────────────────────────────────────
$meses      = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
$tipoLabel  = ['fijo' => 'Fijo', 'variable' => 'Variable', 'extraordinario' => 'Extraordinario'];
$frecLabel  = ['unico' => 'Único / Eventual', 'mensual' => 'Mensual', 'quincenal' => 'Quincenal'];
$metLabel   = ['efectivo' => 'Efectivo', 'transferencia' => 'Transferencia', 'cheque' => 'Cheque', 'tarjeta' => 'Tarjeta', 'otro' => 'Otro'];
$estCss     = ['pagado' => 'success', 'pendiente' => 'warning', 'anulado' => 'secondary'];
$estLabel   = ['pagado' => 'Pagado', 'pendiente' => 'Pendiente', 'anulado' => 'Anulado'];

$fechaFmt   = date('d/m/Y', strtotime($g['fecha']));
$fechaLarga = date('j', strtotime($g['fecha'])) . ' de ' . $meses[(int)date('n', strtotime($g['fecha'])) - 1] . ' de ' . date('Y', strtotime($g['fecha']));

// Información de quincena
$quincInfo = '';
if ($g['frecuencia'] === 'quincenal') {
    if ((int)$g['quincena_num'] === 1) {
        $quincInfo = '1ª Quincena — Día ' . (int)$g['dia_pago'];
    } elseif ((int)$g['quincena_num'] === 2) {
        $quincInfo = '2ª Quincena — Día ' . (int)$g['dia_pago_2'];
    } else {
        $quincInfo = 'Días ' . (int)$g['dia_pago'] . ' y ' . (int)$g['dia_pago_2'];
    }
} elseif ($g['frecuencia'] === 'mensual' && $g['dia_pago']) {
    $quincInfo = 'Día ' . (int)$g['dia_pago'] . ' de cada mes';
}

// Archivo adjunto
$archivoRaw    = $g['archivo_adjunto'] ?? '';
$subDir        = (strpos($g['descripcion'], 'Sueldo ') === 0) ? 'comprobantes_nomina' : 'gastos';
$uploadUrl     = 'includes/uploads/' . $subDir . '/' . $archivoRaw;
$uploadPath    = __DIR__ . '/includes/uploads/' . $subDir . '/' . $archivoRaw;
$tieneArchivo  = !empty($archivoRaw) && file_exists($uploadPath);
$extArchivo    = $tieneArchivo ? strtolower(pathinfo($archivoRaw, PATHINFO_EXTENSION)) : '';
$esImagen      = in_array($extArchivo, ['jpg', 'jpeg', 'png', 'webp']);
$esPDF         = ($extArchivo === 'pdf');
$archivoNombre = $g['archivo_nombre'] ?? basename($archivoRaw);
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comprobante #<?= $g['id'] ?> — <?= htmlspecialchars($g['descripcion']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body {
            background: #f4f6fa;
            font-family: 'Segoe UI', sans-serif;
        }

        .comprobante-wrap {
            max-width: 860px;
            margin: 30px auto;
        }

        /* ── Hoja de impresión ── */
        .sheet {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0, 0, 0, .10);
            overflow: hidden;
        }

        .sheet-header {
            background: linear-gradient(135deg, #dc3545 0%, #c0392b 100%);
            color: #fff;
            padding: 28px 32px 20px;
        }

        .sheet-header .badge-estado {
            font-size: 13px;
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 600;
        }

        .sheet-body {
            padding: 28px 32px;
        }

        .dato-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }

        .dato-row:last-child {
            border-bottom: none;
        }

        .dato-label {
            font-size: 12px;
            color: #888;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .dato-value {
            font-size: 14px;
            font-weight: 600;
            color: #222;
            text-align: right;
            max-width: 60%;
        }

        .monto-display {
            background: #fff9f9;
            border: 2px solid #dc354530;
            border-radius: 10px;
            text-align: center;
            padding: 16px 20px;
        }

        .monto-display .monto-num {
            font-size: 2.2rem;
            font-weight: 800;
            color: #dc3545;
        }

        .monto-display .monto-label {
            font-size: 11px;
            color: #aaa;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        /* ── Adjunto ── */
        .adjunto-wrap {
            border: 2px dashed #dee2e6;
            border-radius: 10px;
            overflow: hidden;
            background: #fafafa;
        }

        .adjunto-wrap img {
            width: 100%;
            max-height: 600px;
            object-fit: contain;
            display: block;
            background: #fff;
        }

        .adjunto-wrap iframe {
            width: 100%;
            height: 600px;
            border: none;
            display: block;
        }

        .adjunto-header {
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            padding: 10px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        /* ── Toolbar ── */
        .toolbar {
            position: sticky;
            top: 0;
            z-index: 100;
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            padding: 12px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .06);
        }

        /* ── Imprimir ── */
        @media print {

            .toolbar,
            .no-print {
                display: none !important;
            }

            body {
                background: #fff;
            }

            .comprobante-wrap {
                margin: 0;
                max-width: 100%;
            }

            .sheet {
                border-radius: 0;
                box-shadow: none;
            }

            .adjunto-wrap iframe {
                height: 800px;
            }
        }
    </style>
</head>

<body>

    <!-- ── Toolbar ──────────────────────────────────────────────────────────────── -->
    <div class="toolbar no-print">
        <div class="d-flex align-items-center gap-2">
            <a href="gastos" class="btn btn-sm btn-outline-secondary">
                <i class="fa-solid fa-arrow-left me-1"></i> Volver a Gastos
            </a>
            <span class="text-muted small">Comprobante #<?= $g['id'] ?></span>
        </div>
        <div class="d-flex gap-2">
            <?php if ($tieneArchivo): ?>
                <a href="<?= htmlspecialchars($uploadUrl) ?>" download="<?= htmlspecialchars($archivoNombre) ?>"
                    class="btn btn-sm btn-outline-primary">
                    <i class="fa-solid fa-download me-1"></i> Descargar
                </a>
            <?php endif; ?>
            <?php if ($g['estado'] === 'pendiente'): ?>
                <button class="btn btn-sm btn-success" id="btnMarcarPagado">
                    <i class="bi bi-check-circle-fill me-1"></i> Marcar Pagado
                </button>
            <?php endif; ?>
            <?php if ($g['estado'] !== 'anulado'): ?>
                <button class="btn btn-sm btn-outline-secondary" id="btnEditarGasto">
                    <i class="fa-solid fa-pen-to-square me-1"></i> Editar
                </button>
                <button class="btn btn-sm btn-outline-warning" id="btnAnularGasto">
                    <i class="fa-solid fa-ban me-1"></i> Anular
                </button>
            <?php endif; ?>
            <?php if (in_array(USUARIO_ROL ?? '', ['admin', 'superadmin'])): ?>
                <button class="btn btn-sm btn-outline-danger" id="btnEliminarGasto">
                    <i class="fa-solid fa-trash me-1"></i> Eliminar
                </button>
            <?php endif; ?>
            <button onclick="window.print()" class="btn btn-sm btn-danger">
                <i class="fa-solid fa-print me-1"></i> Imprimir
            </button>
        </div>
    </div>

    <!-- ── Comprobante ───────────────────────────────────────────────────────────── -->
    <div class="comprobante-wrap px-3">
        <div class="sheet">

            <!-- Encabezado -->
            <div class="sheet-header">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                    <div>
                        <?php if (!empty($cliente['logo'])): ?>
                            <img src="<?= htmlspecialchars($cliente['logo']) ?>" alt="Logo"
                                style="height:40px;object-fit:contain;filter:brightness(0) invert(1);margin-bottom:8px">
                            <br>
                        <?php endif; ?>
                        <div style="font-size:22px;font-weight:800;letter-spacing:-.5px">
                            <?= htmlspecialchars($cliente['nombre'] ?? '') ?>
                        </div>
                        <?php if (!empty($cliente['rtn'])): ?>
                            <div style="opacity:.75;font-size:13px">RTN: <?= htmlspecialchars($cliente['rtn']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="text-end">
                        <div style="font-size:12px;opacity:.7;text-transform:uppercase;letter-spacing:1px">Comprobante
                            de Gasto</div>
                        <div style="font-size:28px;font-weight:800">#<?= str_pad($g['id'], 5, '0', STR_PAD_LEFT) ?>
                        </div>
                        <div class="mt-1">
                            <span class="badge-estado badge bg-<?= $estCss[$g['estado']] ?? 'secondary' ?>
                            <?= $g['estado'] === 'pendiente' ? 'text-dark' : '' ?>">
                                <?= $estLabel[$g['estado']] ?? $g['estado'] ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cuerpo -->
            <div class="sheet-body">
                <div class="row g-4">

                    <!-- Col izquierda: datos del gasto -->
                    <div class="col-lg-5">

                        <!-- Monto -->
                        <div class="monto-display mb-4">
                            <div class="monto-label">Monto del Gasto</div>
                            <div class="monto-num">L <?= number_format((float)$g['monto'], 2) ?></div>
                        </div>

                        <!-- Datos -->
                        <div class="dato-row">
                            <span class="dato-label">Descripción</span>
                            <span class="dato-value"><?= htmlspecialchars($g['descripcion']) ?></span>
                        </div>
                        <div class="dato-row">
                            <span class="dato-label">Fecha</span>
                            <span class="dato-value"><?= $fechaLarga ?></span>
                        </div>
                        <?php if ($g['cat_nombre']): ?>
                            <div class="dato-row">
                                <span class="dato-label">Categoría</span>
                                <span class="dato-value">
                                    <span class="badge rounded-pill px-2"
                                        style="background:<?= $g['cat_color'] ?>18;color:<?= $g['cat_color'] ?>;border:1px solid <?= $g['cat_color'] ?>40;font-size:12px">
                                        <i
                                            class="fa-solid <?= $g['cat_icono'] ?> me-1"></i><?= htmlspecialchars($g['cat_nombre']) ?>
                                    </span>
                                </span>
                            </div>
                        <?php endif; ?>
                        <div class="dato-row">
                            <span class="dato-label">Tipo</span>
                            <span class="dato-value"><?= $tipoLabel[$g['tipo']] ?? $g['tipo'] ?></span>
                        </div>
                        <div class="dato-row">
                            <span class="dato-label">Frecuencia</span>
                            <span class="dato-value">
                                <?= $frecLabel[$g['frecuencia']] ?? $g['frecuencia'] ?>
                                <?php if ($quincInfo): ?>
                                    <br><small class="text-muted fw-normal"><?= $quincInfo ?></small>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php if ($g['proveedor']): ?>
                            <div class="dato-row">
                                <span class="dato-label">Proveedor</span>
                                <span class="dato-value"><?= htmlspecialchars($g['proveedor']) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ($g['factura_ref']): ?>
                            <div class="dato-row">
                                <span class="dato-label">N° Factura/Recibo</span>
                                <span class="dato-value"><?= htmlspecialchars($g['factura_ref']) ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="dato-row">
                            <span class="dato-label">Método de Pago</span>
                            <span class="dato-value"><?= $metLabel[$g['metodo_pago']] ?? $g['metodo_pago'] ?></span>
                        </div>
                        <?php if ($g['fecha_vencimiento']): ?>
                            <div class="dato-row">
                                <span class="dato-label">Vigente hasta</span>
                                <span
                                    class="dato-value text-warning"><?= date('d/m/Y', strtotime($g['fecha_vencimiento'])) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if ($g['notas']): ?>
                            <div class="dato-row flex-column align-items-start gap-1">
                                <span class="dato-label">Notas</span>
                                <span class="dato-value text-start" style="max-width:100%;font-weight:400;color:#555">
                                    <?= nl2br(htmlspecialchars($g['notas'])) ?>
                                </span>
                            </div>
                        <?php endif; ?>

                        <div class="mt-4 pt-3 border-top text-center">
                            <small class="text-muted">Registrado el
                                <?= date('d/m/Y H:i', strtotime($g['creado_en'])) ?></small>
                        </div>
                    </div>

                    <!-- Col derecha: archivo adjunto -->
                    <div class="col-lg-7">
                        <?php if ($tieneArchivo): ?>
                            <div class="adjunto-wrap h-100">
                                <div class="adjunto-header">
                                    <span class="fw-semibold small">
                                        <i class="fa-solid fa-<?= $esImagen ? 'image' : 'file-pdf' ?> me-1 text-danger"></i>
                                        <?= htmlspecialchars($archivoNombre) ?>
                                    </span>
                                    <a href="<?= htmlspecialchars($uploadUrl) ?>"
                                        download="<?= htmlspecialchars($archivoNombre) ?>"
                                        class="btn btn-sm btn-outline-secondary py-0 no-print" style="font-size:11px">
                                        <i class="fa-solid fa-download me-1"></i> Descargar
                                    </a>
                                </div>

                                <?php if ($esImagen): ?>
                                    <img src="<?= htmlspecialchars($uploadUrl) ?>" alt="Comprobante"
                                        onclick="document.getElementById('modalImgSrc').src=this.src; new bootstrap.Modal(document.getElementById('modalImagen')).show()"
                                        style="cursor:zoom-in">
                                <?php elseif ($esPDF): ?>
                                    <iframe src="<?= htmlspecialchars($uploadUrl) ?>#toolbar=1"
                                        title="Comprobante PDF"></iframe>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="d-flex flex-column align-items-center justify-content-center h-100 text-muted py-5"
                                style="border:2px dashed #dee2e6;border-radius:10px;min-height:300px">
                                <i class="fa-solid fa-file-invoice fa-3x mb-3 opacity-25"></i>
                                <p class="mb-0">Sin comprobante adjunto</p>
                                <small>Puedes adjuntarlo editando el gasto</small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div><!-- /.sheet -->
    </div><!-- /.comprobante-wrap -->

    <div class="pb-5"></div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Modal zoom imagen -->
    <div class="modal fade" id="modalImagen" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content bg-dark border-0">
                <div class="modal-header border-0 pb-0">
                    <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-2 text-center">
                    <img id="modalImgSrc" src="" alt="Comprobante"
                        style="max-width:100%;max-height:85vh;object-fit:contain;border-radius:6px">
                </div>
            </div>
        </div>
    </div>
    <!-- MODAL: Marcar Gasto como Pagado -->
    <div class="modal fade" id="modalPagarGastoVer" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header py-3" style="background:linear-gradient(135deg,#059669,#065f46)">
                    <h5 class="modal-title fw-bold text-white">
                        <i class="bi bi-check-circle-fill me-2"></i>Registrar Pago
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form id="formPagarGastoVer" enctype="multipart/form-data">
                        <input type="hidden" name="gasto_id" value="<?= $g['id'] ?>">
                        <!-- Desglose monto -->
                        <div class="text-center mb-4 p-3 rounded-3"
                            style="background:#f0fdf4;border:1.5px solid #a7f3d0">
                            <div style="font-size:.75rem;color:#64748b;text-transform:uppercase;letter-spacing:.08em">
                                Monto a registrar</div>
                            <div style="font-size:2rem;font-weight:800;color:#059669">L
                                <?= number_format((float)$g['monto'], 2) ?></div>
                            <div style="font-size:.8rem;color:#64748b">
                                <?= htmlspecialchars(mb_substr($g['descripcion'], 0, 60)) ?></div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Fecha del pago *</label>
                                <input type="date" name="fecha" id="pgv_fecha" class="form-control"
                                    value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Método de pago *</label>
                                <select name="metodo_pago" id="pgv_metodo" class="form-select">
                                    <option value="efectivo">💵 Efectivo</option>
                                    <option value="transferencia">🏦 Transferencia</option>
                                    <option value="tarjeta">💳 Tarjeta</option>
                                    <option value="cheque">📝 Cheque</option>
                                    <option value="otro">🔷 Otro</option>
                                </select>
                            </div>
                            <!-- Select tarjeta (visible solo si metodo=tarjeta) -->
                            <div class="col-12" id="pgv_grp_tarjeta" style="display:none">
                                <label class="form-label small fw-semibold">Tarjeta usada *
                                    <a href="tarjetas" target="_blank" class="ms-2 text-primary"
                                        style="font-size:.75rem">
                                        <i class="bi bi-plus-circle me-1"></i>Gestionar tarjetas
                                    </a>
                                </label>
                                <?php if (!empty($tarjetas_lista)): ?>
                                    <select name="tarjeta_id" id="pgv_tarjeta" class="form-select">
                                        <option value="">— Seleccionar tarjeta —</option>
                                        <?php foreach ($tarjetas_lista as $t): ?>
                                            <option value="<?= $t['id'] ?>">
                                                <?= htmlspecialchars($t['banco']) ?> — <?= ucfirst($t['tipo']) ?> ····
                                                <?= htmlspecialchars($t['ultimos_digitos']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <div class="alert alert-info py-2 mb-0" style="font-size:.83rem">
                                        <i class="bi bi-info-circle me-1"></i>
                                        No tienes tarjetas registradas. <a href="tarjetas" target="_blank"
                                            class="fw-semibold">Registra una aquí →</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <!-- Checkbox factura -->
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="pgv_tiene_factura"
                                        name="tiene_factura" value="1">
                                    <label class="form-check-label fw-semibold" for="pgv_tiene_factura">
                                        📄 Este gasto tiene factura / recibo físico
                                    </label>
                                </div>
                            </div>
                            <div class="col-12" id="pgv_grp_factura" style="display:none">
                                <label class="form-label small fw-semibold">N° Factura / Referencia</label>
                                <input type="text" name="factura_ref" id="pgv_factura_ref" class="form-control"
                                    placeholder="Ej: 001-001-01-00000123"
                                    value="<?= htmlspecialchars($g['factura_ref'] ?? '') ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold">
                                    <i class="bi bi-paperclip me-1 text-secondary"></i>
                                    Comprobante <span class="text-muted fw-normal">(opcional · JPG, PNG, PDF · máx
                                        5MB)</span>
                                </label>
                                <div id="pgv_zona_comp" class="border rounded-3 text-center p-3"
                                    style="cursor:pointer;border-style:dashed!important;border-color:#dee2e6!important;transition:all .2s"
                                    ondragover="event.preventDefault();this.style.borderColor='#059669';this.style.background='#f0fff4'"
                                    ondragleave="this.style.borderColor='';this.style.background=''"
                                    ondrop="pgvHandleDrop(event)">
                                    <i class="bi bi-cloud-upload text-secondary d-block mb-1"
                                        style="font-size:1.8rem;opacity:.5"></i>
                                    <div class="small text-muted">Arrastra aquí o <span class="text-success fw-semibold"
                                            style="cursor:pointer"
                                            onclick="document.getElementById('pgv_archivo').click()">selecciona
                                            archivo</span></div>
                                    <input type="file" id="pgv_archivo" name="archivo_adjunto"
                                        accept=".jpg,.jpeg,.png,.webp,.pdf" class="d-none">
                                </div>
                                <div id="pgv_prev_wrap" class="mt-2 d-none">
                                    <div class="d-flex align-items-center gap-2 p-2 rounded-2 border"
                                        style="background:#f8f9fa">
                                        <div id="pgv_prev_icon" style="font-size:20px"></div>
                                        <div class="flex-grow-1 overflow-hidden">
                                            <div id="pgv_prev_nom" class="small fw-semibold text-truncate"></div>
                                            <div id="pgv_prev_tam" class="text-muted" style="font-size:11px"></div>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-danger"
                                            onclick="pgvLimpiarComp()">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                    <div id="pgv_prev_img_wrap" class="mt-1 d-none">
                                        <img id="pgv_prev_img" src="" alt="" class="rounded-2 border"
                                            style="max-height:100px;max-width:100%;object-fit:cover">
                                    </div>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-semibold">Notas <span
                                        class="text-muted fw-normal">(opcional)</span></label>
                                <textarea name="notas" class="form-control" rows="2"
                                    placeholder="Referencia, banco, observaciones…"><?= htmlspecialchars($g['notas'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer border-top">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success px-4" id="btnConfirmarPagoGasto">
                        <i class="bi bi-check-circle-fill me-1"></i> Confirmar Pago
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL: Editar Gasto desde ver -->
    <div class="modal fade" id="modalEditarGasto" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-bottom py-3">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square me-2 text-primary"></i>Editar
                        Gasto</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <form id="formEditarGasto" enctype="multipart/form-data">
                        <input type="hidden" name="gasto_id" value="<?= $g['id'] ?>">
                        <input type="hidden" name="gasto_grupo_id" value="<?= $g['gasto_grupo_id'] ?? '' ?>">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label fw-semibold">Descripción <span
                                        class="text-danger">*</span></label>
                                <input type="text" name="descripcion" class="form-control"
                                    value="<?= htmlspecialchars($g['descripcion']) ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Monto (L) <span
                                        class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">L</span>
                                    <input type="number" name="monto" class="form-control" step="0.01"
                                        value="<?= number_format((float)$g['monto'], 2, '.', '') ?>" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Fecha <span class="text-danger">*</span></label>
                                <input type="date" name="fecha" class="form-control" value="<?= $g['fecha'] ?>"
                                    required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Estado</label>
                                <select name="estado" class="form-select">
                                    <option value="pendiente" <?= $g['estado'] === 'pendiente' ? 'selected' : '' ?>>⏳
                                        Pendiente</option>
                                    <option value="pagado" <?= $g['estado'] === 'pagado'    ? 'selected' : '' ?>>✅
                                        Pagado</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Tipo</label>
                                <select name="tipo" class="form-select">
                                    <option value="fijo" <?= $g['tipo'] === 'fijo'          ? 'selected' : '' ?>>🔒 Fijo
                                    </option>
                                    <option value="variable" <?= $g['tipo'] === 'variable'      ? 'selected' : '' ?>>
                                        Variable</option>
                                    <option value="extraordinario"
                                        <?= $g['tipo'] === 'extraordinario' ? 'selected' : '' ?>>⭐ Extraordinario
                                    </option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Método Pago</label>
                                <select name="metodo_pago" id="editMetodoPago" class="form-select">
                                    <option value="efectivo"
                                        <?= $g['metodo_pago'] === 'efectivo'      ? 'selected' : '' ?>>💵 Efectivo
                                    </option>
                                    <option value="transferencia"
                                        <?= $g['metodo_pago'] === 'transferencia' ? 'selected' : '' ?>>🏦 Transferencia
                                    </option>
                                    <option value="tarjeta"
                                        <?= $g['metodo_pago'] === 'tarjeta'       ? 'selected' : '' ?>>💳 Tarjeta
                                    </option>
                                    <option value="cheque"
                                        <?= $g['metodo_pago'] === 'cheque'        ? 'selected' : '' ?>>📝 Cheque
                                    </option>
                                    <option value="otro" <?= $g['metodo_pago'] === 'otro'          ? 'selected' : '' ?>>
                                        🔷 Otro</option>
                                </select>
                            </div>
                            <!-- Tarjeta (visible solo si método = tarjeta) -->
                            <div class="col-md-8" id="editGrpTarjeta"
                                style="display:<?= $g['metodo_pago'] === 'tarjeta' ? '' : 'none' ?>">
                                <label class="form-label fw-semibold">Tarjeta usada
                                    <a href="tarjetas" target="_blank" class="ms-2 text-primary"
                                        style="font-size:.75rem;font-weight:normal">
                                        <i class="bi bi-plus-circle me-1"></i>Gestionar
                                    </a>
                                </label>
                                <?php if (!empty($tarjetas_lista)): ?>
                                    <select name="tarjeta_id" id="editTarjetaId" class="form-select">
                                        <option value="">— Seleccionar tarjeta —</option>
                                        <?php foreach ($tarjetas_lista as $t): ?>
                                            <option value="<?= $t['id'] ?>"
                                                <?= (int)($g['tarjeta_id'] ?? 0) === $t['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($t['banco']) ?> — <?= ucfirst($t['tipo']) ?> ····
                                                <?= htmlspecialchars($t['ultimos_digitos']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <div class="alert alert-info py-2 mb-0" style="font-size:.83rem">
                                        Sin tarjetas. <a href="tarjetas" target="_blank" class="fw-semibold">Registrar →</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Frecuencia</label>
                                <select name="frecuencia" id="editFrecuencia" class="form-select">
                                    <option value="unico" <?= $g['frecuencia'] === 'unico'     ? 'selected' : '' ?>>1️⃣
                                        Único</option>
                                    <option value="mensual" <?= $g['frecuencia'] === 'mensual'   ? 'selected' : '' ?>>📅
                                        Mensual</option>
                                    <option value="quincenal" <?= $g['frecuencia'] === 'quincenal' ? 'selected' : '' ?>>
                                        🔄 Quincenal</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Proveedor</label>
                                <input type="text" name="proveedor" class="form-control"
                                    value="<?= htmlspecialchars($g['proveedor'] ?? '') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">N° Factura</label>
                                <input type="text" name="factura_ref" class="form-control"
                                    value="<?= htmlspecialchars($g['factura_ref'] ?? '') ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-semibold">Fecha Vencimiento</label>
                                <input type="date" name="fecha_vencimiento" class="form-control"
                                    value="<?= $g['fecha_vencimiento'] ?? '' ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Notas</label>
                                <textarea name="notas" class="form-control"
                                    rows="2"><?= htmlspecialchars($g['notas'] ?? '') ?></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">
                                    <i class="fa-solid fa-paperclip me-1 text-secondary"></i>
                                    Comprobante
                                </label>
                                <?php if ($tieneArchivo): ?>
                                    <div id="editCompActual" class="rounded-3 p-3 mb-2 d-flex align-items-center gap-3"
                                        style="background:#f0fdf4;border:1.5px solid #a7f3d0">
                                        <div style="font-size:1.6rem"><?= $esImagen ? '🖼️' : '📄' ?></div>
                                        <div class="flex-grow-1 overflow-hidden">
                                            <div class="fw-semibold small text-truncate">
                                                <?= htmlspecialchars($archivoNombre) ?></div>
                                            <a href="<?= htmlspecialchars($uploadUrl) ?>" target="_blank"
                                                class="text-success small">Ver comprobante actual →</a>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-danger"
                                            onclick="document.getElementById('editCompActual').style.display='none';document.getElementById('editCompNuevo').style.display='';"
                                            title="Quitar / reemplazar">
                                            <i class="bi bi-arrow-repeat me-1"></i>Reemplazar
                                        </button>
                                    </div>
                                    <div id="editCompNuevo" style="display:none">
                                    <?php else: ?>
                                        <div id="editCompNuevo">
                                        <?php endif; ?>
                                        <div id="editZonaComp" class="border rounded-3 text-center p-3"
                                            style="cursor:pointer;border-style:dashed!important;border-color:#dee2e6!important;transition:all .2s"
                                            ondragover="event.preventDefault();this.style.borderColor='#1d4ed8';this.style.background='#eff6ff'"
                                            ondragleave="this.style.borderColor='';this.style.background=''"
                                            ondrop="editHandleDrop(event)">
                                            <i class="bi bi-cloud-upload d-block mb-1 text-secondary"
                                                style="font-size:1.8rem;opacity:.5"></i>
                                            <div class="small text-muted">Arrastra aquí o
                                                <span class="text-primary fw-semibold" style="cursor:pointer"
                                                    onclick="document.getElementById('editArchivo').click()">selecciona
                                                    archivo</span>
                                                <span class="text-muted"> · JPG, PNG, PDF · máx 5MB</span>
                                            </div>
                                            <input type="file" id="editArchivo" name="archivo_adjunto"
                                                accept=".jpg,.jpeg,.png,.webp,.pdf" class="d-none">
                                        </div>
                                        <div id="editPrevWrap" class="mt-2 d-none">
                                            <div class="d-flex align-items-center gap-2 p-2 rounded-2 border"
                                                style="background:#f8f9fa">
                                                <div id="editPrevIcon" style="font-size:20px"></div>
                                                <div class="flex-grow-1 overflow-hidden">
                                                    <div id="editPrevNom" class="small fw-semibold text-truncate"></div>
                                                    <div id="editPrevTam" class="text-muted" style="font-size:11px">
                                                    </div>
                                                </div>
                                                <button type="button" class="btn btn-sm btn-outline-danger"
                                                    onclick="editLimpiarComp()">
                                                    <i class="bi bi-x-lg"></i>
                                                </button>
                                            </div>
                                            <div id="editPrevImgWrap" class="mt-1 d-none">
                                                <img id="editPrevImg" src="" alt="" class="rounded-2 border"
                                                    style="max-height:120px;max-width:100%;object-fit:cover">
                                            </div>
                                        </div>
                                        </div>
                                    </div>
                                    <?php if ($g['gasto_grupo_id']): ?>
                                        <div class="col-12">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="actualizar_grupo"
                                                    id="chkGrupo" value="1">
                                                <label class="form-check-label small text-muted" for="chkGrupo">
                                                    <i class="fa-solid fa-link me-1"></i> Actualizar también la otra quincena
                                                    del grupo
                                                </label>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                            </div>
                    </form>
                </div>
                <div class="modal-footer border-top">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary px-4" id="btnGuardarEdicionGasto">
                        <i class="fa-solid fa-floppy-disk me-1"></i> Guardar Cambios
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        /* ══ MODAL PAGAR GASTO ═══════════════════════════════════════════════════ */
        document.getElementById('btnMarcarPagado')?.addEventListener('click', () => {
            new bootstrap.Modal(document.getElementById('modalPagarGastoVer')).show();
        });
        // Toggle tarjeta selector
        document.getElementById('pgv_metodo').addEventListener('change', function() {
            document.getElementById('pgv_grp_tarjeta').style.display = this.value === 'tarjeta' ? '' : 'none';
        });
        // Toggle factura ref
        document.getElementById('pgv_tiene_factura').addEventListener('change', function() {
            document.getElementById('pgv_grp_factura').style.display = this.checked ? '' : 'none';
        });
        // Comprobante upload
        document.getElementById('pgv_archivo').addEventListener('change', function() {
            if (this.files[0]) pgvMostrarComp(this.files[0]);
        });

        function pgvMostrarComp(file) {
            const esPdf = file.type === 'application/pdf';
            document.getElementById('pgv_prev_icon').innerHTML = esPdf ?
                '<i class="bi bi-file-pdf text-danger" style="font-size:1.4rem"></i>' :
                '<i class="bi bi-image text-primary" style="font-size:1.4rem"></i>';
            document.getElementById('pgv_prev_nom').textContent = file.name;
            document.getElementById('pgv_prev_tam').textContent = (file.size < 1048576 ?
                (file.size / 1024).toFixed(1) + ' KB' :
                (file.size / 1048576).toFixed(2) + ' MB') + ' · ' + (esPdf ? 'PDF' : 'Imagen');
            if (!esPdf) {
                const r = new FileReader();
                r.onload = e => {
                    document.getElementById('pgv_prev_img').src = e.target.result;
                    document.getElementById('pgv_prev_img_wrap').classList.remove('d-none');
                };
                r.readAsDataURL(file);
            } else document.getElementById('pgv_prev_img_wrap').classList.add('d-none');
            document.getElementById('pgv_prev_wrap').classList.remove('d-none');
            document.getElementById('pgv_zona_comp').style.cssText = 'border-color:#059669!important;background:#f0fff4';
        }

        function pgvLimpiarComp() {
            document.getElementById('pgv_archivo').value = '';
            document.getElementById('pgv_prev_wrap').classList.add('d-none');
            document.getElementById('pgv_prev_img_wrap').classList.add('d-none');
            document.getElementById('pgv_zona_comp').style.cssText =
                'border-style:dashed!important;border-color:#dee2e6!important';
        }

        function pgvHandleDrop(ev) {
            ev.preventDefault();
            document.getElementById('pgv_zona_comp').style.cssText =
                'border-style:dashed!important;border-color:#dee2e6!important';
            const file = ev.dataTransfer.files[0];
            if (!file) return;
            if (!['image/jpeg', 'image/png', 'image/webp', 'application/pdf'].includes(file.type)) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Tipo no permitido',
                    text: 'Solo JPG, PNG, WEBP o PDF.',
                    timer: 2000,
                    showConfirmButton: false
                });
                return;
            }
            if (file.size > 5 * 1024 * 1024) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Archivo muy grande',
                    text: 'Máximo 5 MB.',
                    timer: 2000,
                    showConfirmButton: false
                });
                return;
            }
            const dt = new DataTransfer();
            dt.items.add(file);
            document.getElementById('pgv_archivo').files = dt.files;
            pgvMostrarComp(file);
        }
        document.getElementById('btnConfirmarPagoGasto').addEventListener('click', () => {
            const btn = document.getElementById('btnConfirmarPagoGasto');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Registrando…';
            fetch('includes/gasto_marcar_pagado.php', {
                    method: 'POST',
                    body: new FormData(document.getElementById('formPagarGastoVer'))
                })
                .then(r => r.json()).then(d => {
                    if (d.success) {
                        bootstrap.Modal.getInstance(document.getElementById('modalPagarGastoVer'))?.hide();
                        Swal.fire({
                                icon: 'success',
                                title: '¡Pago registrado!',
                                text: d.message,
                                timer: 1800,
                                showConfirmButton: false
                            })
                            .then(() => location.reload());
                    } else {
                        Swal.fire('Error', d.error, 'error');
                        btn.disabled = false;
                        btn.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Confirmar Pago';
                    }
                });
        });

        /* ══ EDITAR / ANULAR / ELIMINAR (vanilla JS, sin jQuery) ════════════════ */
        /* ── Edit modal comprobante upload ── */
        document.getElementById('editArchivo')?.addEventListener('change', function() {
            if (this.files[0]) editMostrarComp(this.files[0]);
        });

        function editMostrarComp(file) {
            const esPdf = file.type === 'application/pdf';
            document.getElementById('editPrevIcon').innerHTML = esPdf ?
                '<i class="bi bi-file-pdf text-danger" style="font-size:1.4rem"></i>' :
                '<i class="bi bi-image text-primary" style="font-size:1.4rem"></i>';
            document.getElementById('editPrevNom').textContent = file.name;
            document.getElementById('editPrevTam').textContent = (file.size < 1048576 ?
                (file.size / 1024).toFixed(1) + ' KB' :
                (file.size / 1048576).toFixed(2) + ' MB') + ' · ' + (esPdf ? 'PDF' : 'Imagen');
            if (!esPdf) {
                const r = new FileReader();
                r.onload = e => {
                    document.getElementById('editPrevImg').src = e.target.result;
                    document.getElementById('editPrevImgWrap').classList.remove('d-none');
                };
                r.readAsDataURL(file);
            } else document.getElementById('editPrevImgWrap').classList.add('d-none');
            document.getElementById('editPrevWrap').classList.remove('d-none');
            document.getElementById('editZonaComp').style.cssText = 'border-color:#1d4ed8!important;background:#eff6ff';
        }

        function editLimpiarComp() {
            document.getElementById('editArchivo').value = '';
            document.getElementById('editPrevWrap').classList.add('d-none');
            document.getElementById('editPrevImgWrap').classList.add('d-none');
            document.getElementById('editZonaComp').style.cssText =
                'border-style:dashed!important;border-color:#dee2e6!important';
        }

        function editHandleDrop(ev) {
            ev.preventDefault();
            document.getElementById('editZonaComp').style.cssText =
                'border-style:dashed!important;border-color:#dee2e6!important';
            const file = ev.dataTransfer.files[0];
            if (!file) return;
            if (!['image/jpeg', 'image/png', 'image/webp', 'application/pdf'].includes(file.type)) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Tipo no permitido',
                    text: 'Solo JPG, PNG, WEBP o PDF.',
                    timer: 2000,
                    showConfirmButton: false
                });
                return;
            }
            if (file.size > 5 * 1024 * 1024) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Archivo muy grande',
                    text: 'Máximo 5 MB.',
                    timer: 2000,
                    showConfirmButton: false
                });
                return;
            }
            const dt = new DataTransfer();
            dt.items.add(file);
            document.getElementById('editArchivo').files = dt.files;
            editMostrarComp(file);
        }

        document.getElementById('btnEditarGasto')?.addEventListener('click', () => {
            new bootstrap.Modal(document.getElementById('modalEditarGasto')).show();
        });
        // Toggle tarjeta en modal editar
        document.getElementById('editMetodoPago')?.addEventListener('change', function() {
            document.getElementById('editGrpTarjeta').style.display = this.value === 'tarjeta' ? '' : 'none';
        });

        document.getElementById('btnGuardarEdicionGasto').addEventListener('click', function() {
            const btn = this;
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Guardando...';
            fetch('includes/gasto_actualizar.php', {
                    method: 'POST',
                    body: new FormData(document.getElementById('formEditarGasto'))
                })
                .then(r => r.json()).then(d => {
                    if (d.success) Swal.fire({
                        icon: 'success',
                        title: '¡Guardado!',
                        text: d.message,
                        timer: 1800,
                        showConfirmButton: false
                    }).then(() => location.reload());
                    else Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: d.error
                    });
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i> Guardar Cambios';
                }).catch(() => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error de conexión'
                    });
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i> Guardar Cambios';
                });
        });

        document.getElementById('btnAnularGasto')?.addEventListener('click', function() {
            Swal.fire({
                icon: 'warning',
                title: '¿Anular este gasto?',
                html: '<strong><?= htmlspecialchars(addslashes($g['descripcion'])) ?></strong><br><small class="text-muted">No contará en totales pero quedará en historial.</small>',
                showCancelButton: true,
                confirmButtonColor: '#ffc107',
                confirmButtonText: 'Sí, anular',
                cancelButtonText: 'Cancelar'
            }).then(function(r) {
                if (!r.isConfirmed) return;
                const fd1 = new FormData();
                fd1.append('id', <?= $g['id'] ?>);
                fd1.append('accion', 'anular');
                fetch('includes/gasto_eliminar.php', {
                    method: 'POST',
                    body: fd1
                }).then(r => r.json()).then(d => {
                    if (d.success) Swal.fire({
                        icon: 'success',
                        title: 'Anulado',
                        timer: 1400,
                        showConfirmButton: false
                    }).then(() => {
                        window.location.href = 'gastos';
                    });
                    else Swal.fire('Error', d.error, 'error');
                });
            });
        });

        document.getElementById('btnEliminarGasto')?.addEventListener('click', function() {
            Swal.fire({
                icon: 'error',
                title: '¿Eliminar definitivamente?',
                html: '<strong><?= htmlspecialchars(addslashes($g['descripcion'])) ?></strong><br><span class="text-danger small">Esta acción no se puede deshacer.</span>',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar'
            }).then(function(r) {
                if (!r.isConfirmed) return;
                const fd2 = new FormData();
                fd2.append('id', <?= $g['id'] ?>);
                fd2.append('accion', 'eliminar');
                fetch('includes/gasto_eliminar.php', {
                    method: 'POST',
                    body: fd2
                }).then(r => r.json()).then(d => {
                    if (d.success) Swal.fire({
                        icon: 'success',
                        title: 'Eliminado',
                        timer: 1400,
                        showConfirmButton: false
                    }).then(() => {
                        window.location.href = 'gastos';
                    });
                    else Swal.fire('Error', d.error, 'error');
                });
            });
        });
    </script>
</body>

</html>