<?php
// clientes/naranjaymedia/colaborador_reporte.php
$titulo = 'Reporte de Nómina';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

$cliente_id = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);

$id          = filter_input(INPUT_GET, 'id',   FILTER_VALIDATE_INT);
$mes_filtro  = (int)($_GET['mes']  ?? date('n'));
$anio_filtro = (int)($_GET['anio'] ?? date('Y'));

if (!$id) { header('Location: colaboradores'); exit; }

// ── Colaborador ───────────────────────────────────────────────────────────────
$stmtC = $pdo->prepare("SELECT * FROM colaboradores WHERE id = ? AND cliente_id = ? AND activo = 1");
$stmtC->execute([$id, $cliente_id]);
$col = $stmtC->fetch(PDO::FETCH_ASSOC);
if (!$col) { header('Location: colaboradores'); exit; }

// ── Empresa ───────────────────────────────────────────────────────────────────
$stmtE = $pdo->prepare("SELECT nombre, logo_url AS logo, rtn FROM clientes_saas WHERE id = ?");
$stmtE->execute([$cliente_id]);
$empresa = $stmtE->fetch(PDO::FETCH_ASSOC) ?: ['nombre' => 'Mi Empresa', 'logo' => null, 'rtn' => null];

// ── Pagos del período ─────────────────────────────────────────────────────────
$nombreCompleto = trim($col['nombre'] . ' ' . $col['apellido']);
$fecha_ini = sprintf('%04d-%02d-01', $anio_filtro, $mes_filtro);
$fecha_fin = date('Y-m-t', strtotime($fecha_ini));

$stmtP = $pdo->prepare("
    SELECT *
    FROM gastos
    WHERE cliente_id = ?
      AND descripcion LIKE ?
      AND fecha BETWEEN ? AND ?
    ORDER BY fecha ASC, quincena_num ASC
");
$stmtP->execute([$cliente_id, 'Sueldo ' . $nombreCompleto . '%', $fecha_ini, $fecha_fin]);
$pagos = $stmtP->fetchAll(PDO::FETCH_ASSOC);

// ── Constantes IHSS / RAP ─────────────────────────────────────────────────────
define('IHSS_EMP_PCT',  0.035);
define('IHSS_PAT_PCT',  0.070);
define('RAP_EMP_PCT',   0.015);
define('RAP_PAT_PCT',   0.015);
define('IHSS_TOPE',     10294.10);

$salario  = (float)$col['salario_base'];
$div      = ($col['tipo_pago'] === 'quincenal') ? 2 : 1;
$base_ihss = min($salario, IHSS_TOPE);

$ihss_emp_mes = !empty($col['aplica_ihss']) ? round($base_ihss * IHSS_EMP_PCT, 2) : 0;
$rap_emp_mes  = !empty($col['aplica_rap'])  ? round($salario   * RAP_EMP_PCT,  2) : 0;
$ihss_pat_mes = !empty($col['aplica_ihss']) ? round($base_ihss * IHSS_PAT_PCT, 2) : 0;
$rap_pat_mes  = !empty($col['aplica_rap'])  ? round($salario   * RAP_PAT_PCT,  2) : 0;
$neto_mes     = $salario - $ihss_emp_mes - $rap_emp_mes;

// Por pago (quincena o mes completo)
$bruto_pago   = round($salario   / $div, 2);
$ihss_emp_p   = round($ihss_emp_mes / $div, 2);
$rap_emp_p    = round($rap_emp_mes  / $div, 2);
$ihss_pat_p   = round($ihss_pat_mes / $div, 2);
$rap_pat_p    = round($rap_pat_mes  / $div, 2);
$neto_pago    = round($neto_mes / $div, 2);
$costo_total_p = round($neto_pago + $ihss_pat_p + $rap_pat_p, 2);

// ── Totales reales del período ────────────────────────────────────────────────
$total_neto     = array_sum(array_column($pagos, 'monto'));
$num_pagos      = count($pagos);
$total_bruto    = round($bruto_pago   * $num_pagos, 2);
$total_ihss_emp = round($ihss_emp_p   * $num_pagos, 2);
$total_rap_emp  = round($rap_emp_p    * $num_pagos, 2);
$total_ihss_pat = round($ihss_pat_p   * $num_pagos, 2);
$total_rap_pat  = round($rap_pat_p    * $num_pagos, 2);
$total_costo    = round($total_neto + $total_ihss_pat + $total_rap_pat, 2);

// ── Helpers ───────────────────────────────────────────────────────────────────
$meses = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$periodo_label = $meses[$mes_filtro - 1] . ' ' . $anio_filtro;

// Antigüedad
$fecha_ingreso = !empty($col['fecha_ingreso']) ? new DateTime($col['fecha_ingreso']) : null;
$antiguedad = '';
if ($fecha_ingreso) {
    $diff = $fecha_ingreso->diff(new DateTime());
    if ($diff->y > 0)      $antiguedad = $diff->y . ' año' . ($diff->y > 1 ? 's' : '');
    elseif ($diff->m > 0)  $antiguedad = $diff->m . ' mes' . ($diff->m > 1 ? 'es' : '');
    else                   $antiguedad = $diff->days . ' día' . ($diff->days > 1 ? 's' : '');
}

$uploadBase = 'includes/uploads/comprobantes_nomina/';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte Nómina — <?= htmlspecialchars($nombreCompleto) ?> — <?= $periodo_label ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --brand: #dc3545;
            --brand-dark: #b02a37;
            --brand-light: #fff5f5;
        }

        body {
            background: #f0f2f5;
            font-family: 'Segoe UI', Arial, sans-serif;
            font-size: 13px;
            color: #222;
        }

        /* ── Toolbar ── */
        .toolbar {
            position: sticky;
            top: 0;
            z-index: 100;
            background: #fff;
            border-bottom: 1px solid #e0e0e0;
            padding: 10px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,.07);
        }

        /* ── Hoja ── */
        .reporte-wrap { max-width: 900px; margin: 24px auto 60px; }

        .hoja {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 3px 20px rgba(0,0,0,.10);
            overflow: hidden;
            margin-bottom: 24px;
        }

        /* ── Encabezado ── */
        .hoja-header {
            background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%);
            color: #fff;
            padding: 24px 28px 18px;
        }
        .empresa-nombre { font-size: 20px; font-weight: 800; letter-spacing: -.4px; }
        .reporte-titulo { font-size: 11px; opacity: .75; text-transform: uppercase; letter-spacing: 1px; }
        .reporte-num    { font-size: 22px; font-weight: 800; }

        /* ── Colaborador card ── */
        .col-card {
            background: var(--brand-light);
            border-bottom: 2px solid #f8d7da;
            padding: 18px 28px;
            display: flex;
            align-items: center;
            gap: 18px;
        }
        .col-avatar {
            width: 56px; height: 56px;
            border-radius: 50%;
            background: var(--brand);
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; font-weight: 800;
            flex-shrink: 0;
            border: 3px solid #fff;
            box-shadow: 0 2px 8px rgba(220,53,69,.3);
        }
        .col-nombre   { font-size: 17px; font-weight: 700; }
        .col-cargo    { font-size: 12px; color: #666; }
        .col-meta span { font-size: 11px; background: #fff; border: 1px solid #f8d7da; border-radius: 20px; padding: 2px 10px; margin-right: 6px; color: #555; }

        /* ── Sección ── */
        .hoja-body { padding: 20px 28px; }
        .seccion-titulo {
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 1px; color: var(--brand);
            border-bottom: 2px solid #f8d7da;
            padding-bottom: 6px; margin-bottom: 14px;
        }

        /* ── Desglose salarial ── */
        .desglose-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 10px;
            margin-bottom: 20px;
        }
        .desglose-item {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 10px 12px;
            border-left: 3px solid #dee2e6;
        }
        .desglose-item.positivo { border-left-color: #198754; background: #f0fdf4; }
        .desglose-item.negativo { border-left-color: #dc3545; background: #fff5f5; }
        .desglose-item.patronal { border-left-color: #fd7e14; background: #fff8f0; }
        .desglose-item.total    { border-left-color: #0d6efd; background: #f0f4ff; }
        .desglose-label { font-size: 10px; color: #888; text-transform: uppercase; letter-spacing: .5px; }
        .desglose-value { font-size: 16px; font-weight: 800; margin-top: 2px; }
        .desglose-item.positivo .desglose-value { color: #198754; }
        .desglose-item.negativo .desglose-value { color: #dc3545; }
        .desglose-item.patronal .desglose-value { color: #d45f00; }
        .desglose-item.total    .desglose-value { color: #0d6efd; }

        /* ── Tabla pagos ── */
        .tabla-pagos th {
            background: #f8f9fa;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .4px;
            color: #555;
            font-weight: 600;
            padding: 8px 10px;
            border-bottom: 2px solid #e0e0e0;
        }
        .tabla-pagos td {
            padding: 8px 10px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }
        .tabla-pagos tr:last-child td { border-bottom: none; }
        .tabla-pagos tfoot td {
            background: #f8f9fa;
            font-weight: 700;
            padding: 10px;
            border-top: 2px solid #dee2e6;
        }

        /* ── Badges ── */
        .badge-quincena {
            font-size: 10px; padding: 2px 8px;
            border-radius: 20px; font-weight: 600;
        }
        .q1 { background: #cfe2ff; color: #0a58ca; }
        .q2 { background: #d1ecf1; color: #0c6374; }
        .qm { background: #d4edda; color: #145a32; }

        /* ── Comprobante thumbnail ── */
        .thumb {
            width: 36px; height: 36px;
            object-fit: cover;
            border-radius: 4px;
            border: 1px solid #dee2e6;
            cursor: pointer;
        }

        /* ── Totales finales ── */
        .totales-box {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 10px;
            padding: 16px 20px;
            border: 1px solid #dee2e6;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px dashed #dee2e6;
            font-size: 13px;
        }
        .total-row:last-child { border-bottom: none; }
        .total-row.destacado { font-weight: 800; font-size: 15px; color: #0d6efd; }
        .total-row.costo     { font-weight: 800; font-size: 15px; color: #d45f00; }

        /* ── Firmas ── */
        .firma-wrap {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 32px;
            margin-top: 28px;
            padding-top: 20px;
            border-top: 1px dashed #ccc;
        }
        .firma-linea {
            border-top: 1.5px solid #333;
            padding-top: 6px;
            text-align: center;
            font-size: 11px;
            color: #555;
        }
        .firma-espacio { height: 48px; }

        /* ── Sin pagos ── */
        .sin-pagos {
            text-align: center; padding: 40px 20px; color: #aaa;
        }

        /* ── Imprimir ── */
        @media print {
            body { background: #fff; font-size: 11px; }
            .toolbar, .no-print { display: none !important; }
            .reporte-wrap { margin: 0; max-width: 100%; }
            .hoja { border-radius: 0; box-shadow: none; page-break-after: avoid; }
            .desglose-grid { grid-template-columns: repeat(4, 1fr); }
            a { text-decoration: none; color: inherit; }
        }
    </style>
</head>
<body>

<!-- Toolbar -->
<div class="toolbar no-print">
    <div class="d-flex align-items-center gap-2">
        <a href="colaboradores" class="btn btn-sm btn-outline-secondary">
            <i class="fa-solid fa-arrow-left me-1"></i> Colaboradores
        </a>
        <a href="colaborador_ver.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">
            <i class="fa-solid fa-user me-1"></i> Ver Colaborador
        </a>
        <span class="text-muted small d-none d-md-inline">
            Reporte: <?= htmlspecialchars($nombreCompleto) ?> — <?= $periodo_label ?>
        </span>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <!-- Selector de período -->
        <form method="GET" class="d-flex gap-1 align-items-center">
            <input type="hidden" name="id" value="<?= $id ?>">
            <select name="mes" class="form-select form-select-sm" style="width:110px">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $m === $mes_filtro ? 'selected' : '' ?>><?= $meses[$m-1] ?></option>
                <?php endfor; ?>
            </select>
            <select name="anio" class="form-select form-select-sm" style="width:80px">
                <?php for ($a = date('Y'); $a >= date('Y') - 4; $a--): ?>
                    <option value="<?= $a ?>" <?= $a === $anio_filtro ? 'selected' : '' ?>><?= $a ?></option>
                <?php endfor; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-outline-primary">
                <i class="fa-solid fa-filter"></i>
            </button>
        </form>
        <button onclick="window.print()" class="btn btn-sm btn-danger">
            <i class="fa-solid fa-print me-1"></i> Imprimir / PDF
        </button>
    </div>
</div>

<!-- Reporte -->
<div class="reporte-wrap px-3">
<div class="hoja">

    <!-- Encabezado empresa -->
    <div class="hoja-header">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <?php if (!empty($empresa['logo'])): ?>
                    <img src="<?= htmlspecialchars($empresa['logo']) ?>"
                         alt="Logo" style="height:36px;object-fit:contain;filter:brightness(0) invert(1);margin-bottom:8px"><br>
                <?php endif; ?>
                <div class="empresa-nombre"><?= htmlspecialchars($empresa['nombre']) ?></div>
                <?php if (!empty($empresa['rtn'])): ?>
                    <div style="opacity:.7;font-size:12px">RTN: <?= htmlspecialchars($empresa['rtn']) ?></div>
                <?php endif; ?>
            </div>
            <div class="text-end">
                <div class="reporte-titulo">Reporte de Nómina</div>
                <div class="reporte-num"><?= $periodo_label ?></div>
                <div style="font-size:11px;opacity:.7;margin-top:4px">
                    Generado: <?= date('d/m/Y H:i') ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Datos del colaborador -->
    <div class="col-card">
        <div class="col-avatar">
            <?= mb_strtoupper(mb_substr($col['nombre'], 0, 1) . mb_substr($col['apellido'], 0, 1)) ?>
        </div>
        <div class="flex-grow-1">
            <div class="col-nombre"><?= htmlspecialchars($nombreCompleto) ?></div>
            <?php if (!empty($col['cargo'])): ?>
                <div class="col-cargo"><?= htmlspecialchars($col['cargo']) ?></div>
            <?php endif; ?>
            <div class="col-meta mt-1">
                <span><?= $col['tipo_pago'] === 'quincenal' ? '🔄 Quincenal' : '📅 Mensual' ?></span>
                <?php if ($antiguedad): ?>
                    <span><i class="fa-solid fa-clock fa-xs me-1"></i><?= $antiguedad ?> de antigüedad</span>
                <?php endif; ?>
                <?php if (!empty($col['fecha_ingreso'])): ?>
                    <span>Ingreso: <?= date('d/m/Y', strtotime($col['fecha_ingreso'])) ?></span>
                <?php endif; ?>
                <?php if (!empty($col['identidad'])): ?>
                    <span>DNI: <?= htmlspecialchars($col['identidad']) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="text-end d-none d-md-block">
            <div style="font-size:11px;color:#888">Salario bruto mensual</div>
            <div style="font-size:22px;font-weight:800;color:var(--brand)">L <?= number_format($salario, 2) ?></div>
        </div>
    </div>

    <!-- Desglose salarial base -->
    <div class="hoja-body">
        <div class="seccion-titulo">
            <i class="fa-solid fa-calculator me-1"></i>Desglose Salarial — <?= $col['tipo_pago'] === 'quincenal' ? 'por quincena' : 'mensual' ?>
        </div>
        <div class="desglose-grid">
            <div class="desglose-item positivo">
                <div class="desglose-label">Salario Bruto</div>
                <div class="desglose-value">L <?= number_format($bruto_pago, 2) ?></div>
            </div>
            <?php if ($ihss_emp_p > 0): ?>
            <div class="desglose-item negativo">
                <div class="desglose-label">IHSS Empleado (3.5%)</div>
                <div class="desglose-value">– L <?= number_format($ihss_emp_p, 2) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($rap_emp_p > 0): ?>
            <div class="desglose-item negativo">
                <div class="desglose-label">RAP Empleado (1.5%)</div>
                <div class="desglose-value">– L <?= number_format($rap_emp_p, 2) ?></div>
            </div>
            <?php endif; ?>
            <div class="desglose-item total">
                <div class="desglose-label">Neto a Pagar</div>
                <div class="desglose-value">L <?= number_format($neto_pago, 2) ?></div>
            </div>
            <?php if ($ihss_pat_p > 0): ?>
            <div class="desglose-item patronal">
                <div class="desglose-label">IHSS Patronal (7%)</div>
                <div class="desglose-value">L <?= number_format($ihss_pat_p, 2) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($rap_pat_p > 0): ?>
            <div class="desglose-item patronal">
                <div class="desglose-label">RAP Patronal (1.5%)</div>
                <div class="desglose-value">L <?= number_format($rap_pat_p, 2) ?></div>
            </div>
            <?php endif; ?>
            <div class="desglose-item" style="border-left-color:#6f42c1;background:#f8f0ff">
                <div class="desglose-label">Costo Total Empresa</div>
                <div class="desglose-value" style="color:#6f42c1">L <?= number_format($costo_total_p, 2) ?></div>
            </div>
        </div>

        <!-- Tabla de pagos del período -->
        <div class="seccion-titulo mt-4">
            <i class="fa-solid fa-list-check me-1"></i>Pagos Registrados — <?= $periodo_label ?>
        </div>

        <?php if (empty($pagos)): ?>
            <div class="sin-pagos">
                <i class="fa-solid fa-inbox fa-2x d-block mb-2 opacity-25"></i>
                No hay pagos registrados para este período.
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="tabla-pagos w-100">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Descripción / Quincena</th>
                        <th class="text-end">Bruto</th>
                        <th class="text-end">IHSS Emp.</th>
                        <th class="text-end">RAP Emp.</th>
                        <th class="text-end">Neto Pagado</th>
                        <th class="text-end">IHSS Pat.</th>
                        <th class="text-end">RAP Pat.</th>
                        <th class="text-end">Costo Total</th>
                        <th class="text-center">Estado</th>
                        <th class="text-center no-print">Comp.</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pagos as $p):
                        $q = (int)($p['quincena_num'] ?? 0);
                        $neto_p = (float)$p['monto'];

                        // Comprobante
                        $archRaw = $p['archivo_adjunto'] ?? '';
                        $archUrl = !empty($archRaw) ? $uploadBase . $archRaw : '';
                        $archPath = !empty($archRaw) ? __DIR__ . '/' . $uploadBase . $archRaw : '';
                        $tieneComp = !empty($archRaw) && file_exists($archPath);
                        $extComp = $tieneComp ? strtolower(pathinfo($archRaw, PATHINFO_EXTENSION)) : '';
                        $esImg = in_array($extComp, ['jpg','jpeg','png','webp']);
                    ?>
                    <tr>
                        <td class="text-nowrap"><?= date('d/m/Y', strtotime($p['fecha'])) ?></td>
                        <td>
                            <div class="fw-semibold" style="font-size:12px"><?= htmlspecialchars($p['descripcion']) ?></div>
                            <?php if ($q === 1): ?>
                                <span class="badge-quincena q1">1ª Quincena</span>
                            <?php elseif ($q === 2): ?>
                                <span class="badge-quincena q2">2ª Quincena</span>
                            <?php else: ?>
                                <span class="badge-quincena qm">Mensual</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">L <?= number_format($bruto_pago, 2) ?></td>
                        <td class="text-end text-danger">
                            <?= $ihss_emp_p > 0 ? '– L ' . number_format($ihss_emp_p, 2) : '—' ?>
                        </td>
                        <td class="text-end text-danger">
                            <?= $rap_emp_p > 0 ? '– L ' . number_format($rap_emp_p, 2) : '—' ?>
                        </td>
                        <td class="text-end fw-bold text-success">L <?= number_format($neto_p, 2) ?></td>
                        <td class="text-end" style="color:#d45f00">
                            <?= $ihss_pat_p > 0 ? 'L ' . number_format($ihss_pat_p, 2) : '—' ?>
                        </td>
                        <td class="text-end" style="color:#d45f00">
                            <?= $rap_pat_p > 0 ? 'L ' . number_format($rap_pat_p, 2) : '—' ?>
                        </td>
                        <td class="text-end fw-bold" style="color:#6f42c1">L <?= number_format($neto_p + $ihss_pat_p + $rap_pat_p, 2) ?></td>
                        <td class="text-center">
                            <?php if ($p['estado'] === 'pagado'): ?>
                                <span style="color:#198754;font-weight:700;font-size:11px">✓ Pagado</span>
                            <?php elseif ($p['estado'] === 'pendiente'): ?>
                                <span style="color:#d45f00;font-weight:700;font-size:11px">⏳ Pendiente</span>
                            <?php else: ?>
                                <span style="color:#888;font-size:11px"><?= $p['estado'] ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center no-print">
                            <?php if ($tieneComp): ?>
                                <?php if ($esImg): ?>
                                    <img src="<?= htmlspecialchars($archUrl) ?>"
                                         class="thumb"
                                         onclick="document.getElementById('modalImgSrc').src=this.src; new bootstrap.Modal(document.getElementById('modalImagen')).show()"
                                         title="Ver comprobante">
                                <?php else: ?>
                                    <a href="<?= htmlspecialchars($archUrl) ?>" target="_blank"
                                       class="btn btn-sm btn-outline-danger py-0" style="font-size:11px">
                                        <i class="fa-solid fa-file-pdf"></i>
                                    </a>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted" style="font-size:11px">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2" class="text-end">TOTALES (<?= $num_pagos ?> pago<?= $num_pagos !== 1 ? 's' : '' ?>):</td>
                        <td class="text-end">L <?= number_format($total_bruto, 2) ?></td>
                        <td class="text-end text-danger">– L <?= number_format($total_ihss_emp, 2) ?></td>
                        <td class="text-end text-danger">– L <?= number_format($total_rap_emp, 2) ?></td>
                        <td class="text-end text-success">L <?= number_format($total_neto, 2) ?></td>
                        <td class="text-end" style="color:#d45f00">L <?= number_format($total_ihss_pat, 2) ?></td>
                        <td class="text-end" style="color:#d45f00">L <?= number_format($total_rap_pat, 2) ?></td>
                        <td class="text-end" style="color:#6f42c1">L <?= number_format($total_costo, 2) ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <!-- Resumen totales -->
        <div class="row g-3 mt-3">
            <div class="col-md-5 offset-md-7">
                <div class="totales-box">
                    <div class="total-row">
                        <span>Salario bruto total</span>
                        <span>L <?= number_format($total_bruto, 2) ?></span>
                    </div>
                    <div class="total-row">
                        <span class="text-danger">– Deducciones empleado</span>
                        <span class="text-danger">L <?= number_format($total_ihss_emp + $total_rap_emp, 2) ?></span>
                    </div>
                    <div class="total-row destacado">
                        <span>Neto pagado al colaborador</span>
                        <span>L <?= number_format($total_neto, 2) ?></span>
                    </div>
                    <div class="total-row" style="color:#d45f00">
                        <span>+ Aportes patronales</span>
                        <span>L <?= number_format($total_ihss_pat + $total_rap_pat, 2) ?></span>
                    </div>
                    <div class="total-row costo">
                        <span>Costo total para la empresa</span>
                        <span>L <?= number_format($total_costo, 2) ?></span>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Firmas -->
        <div class="firma-wrap no-print-hide">
            <div>
                <div class="firma-espacio"></div>
                <div class="firma-linea">
                    <?= htmlspecialchars($nombreCompleto) ?><br>
                    <span style="color:#aaa">Colaborador</span>
                </div>
            </div>
            <div>
                <div class="firma-espacio"></div>
                <div class="firma-linea">
                    Recursos Humanos / Administración<br>
                    <span style="color:#aaa">Firma y Sello</span>
                </div>
            </div>
        </div>

        <div class="text-center text-muted mt-4 pt-3 border-top" style="font-size:11px">
            <?= htmlspecialchars($empresa['nombre']) ?>
            <?php if ($empresa['rtn']): ?> · RTN: <?= htmlspecialchars($empresa['rtn']) ?><?php endif; ?>
            · Reporte generado el <?= date('d/m/Y \a \l\a\s H:i') ?>
        </div>

    </div><!-- /.hoja-body -->
</div><!-- /.hoja -->
</div><!-- /.reporte-wrap -->

<!-- Modal zoom imagen comprobante -->
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

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
<script>
// Evita desplazamiento al abrir modal
document.getElementById('modalImagen').addEventListener('show.bs.modal', function () {
    document.body.style.paddingRight = '0';
});
document.getElementById('modalImagen').addEventListener('hidden.bs.modal', function () {
    document.body.style.paddingRight = '';
});
</script>
</body>
</html>