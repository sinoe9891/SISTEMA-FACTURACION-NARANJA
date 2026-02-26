<?php
// clientes/naranjaymedia/colaborador_recibo_pdf.php
require_once '../../includes/db.php';
require_once '../../includes/session.php';

// ── Buscar autoload de DOMPDF ─────────────────────────────────────────────────
$candidates = [
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/vendor/autoload.php',
];
$loaded = false;
foreach ($candidates as $p) {
    if (file_exists($p)) { require_once $p; $loaded = true; break; }
}
if (!$loaded) {
    die('<b>Error:</b> No se encontró vendor/autoload.php.<br>Ejecuta en terminal:<br><code>composer require dompdf/dompdf</code>');
}

use Dompdf\Dompdf;
use Dompdf\Options;

// ── Parámetros ────────────────────────────────────────────────────────────────
$gasto_id = filter_input(INPUT_GET, 'gasto_id', FILTER_VALIDATE_INT);
if (!$gasto_id) { http_response_code(400); die('Parámetro gasto_id inválido.'); }

$cliente_id = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);

// ── Gasto principal ───────────────────────────────────────────────────────────
$sg = $pdo->prepare("SELECT * FROM gastos WHERE id=? AND cliente_id=?");
$sg->execute([$gasto_id, $cliente_id]);
$gasto = $sg->fetch(PDO::FETCH_ASSOC);
if (!$gasto) { http_response_code(404); die('Gasto no encontrado.'); }

// ── Cliente SaaS ──────────────────────────────────────────────────────────────
$sc = $pdo->prepare("SELECT nombre, razon_social, direccion, telefono, email, logo_url FROM clientes_saas WHERE id=? LIMIT 1");
$sc->execute([$cliente_id]);
$empresa = $sc->fetch(PDO::FETCH_ASSOC) ?: [];

// ── Colaborador (buscar por nombre en la descripción) ─────────────────────────
// Formato: "Sueldo Nombre Apellido — 1ª Quincena" o "Sueldo Nombre Apellido"
$colab = null;
if (preg_match('/^(?:Sueldo|Bono:|Viático:|Viatico:)\s+(.+?)(?:\s+—|$)/u', $gasto['descripcion'], $m)) {
    $sq = $pdo->prepare("SELECT * FROM colaboradores WHERE cliente_id=? AND CONCAT(nombre,' ',apellido) LIKE ? LIMIT 1");
    $sq->execute([$cliente_id, '%' . trim($m[1]) . '%']);
    $colab = $sq->fetch(PDO::FETCH_ASSOC);
}

// ── Deducciones (cuotas descontadas en esta nómina) ───────────────────────────
$sd = $pdo->prepare("
    SELECT c.monto AS monto, c.numero_cuota, p.descripcion AS desc_prest
    FROM colaborador_prestamo_cuotas c
    JOIN colaborador_prestamos p ON p.id = c.prestamo_id
    WHERE c.notas LIKE ? AND p.cliente_id = ?
");
$sd->execute(['%gasto #' . $gasto_id . '%', $cliente_id]);
$deducciones = $sd->fetchAll(PDO::FETCH_ASSOC);

// ── Bonos y viáticos aplicados (gastos hermanos) ──────────────────────────────
$sbv = $pdo->prepare("
    SELECT descripcion, monto
    FROM gastos
    WHERE cliente_id = ?
      AND notas LIKE ?
      AND (descripcion LIKE 'Bono:%' OR descripcion LIKE 'Viático:%' OR descripcion LIKE 'Viatico:%')
      AND fecha = ?
");
$sbv->execute([$cliente_id, '%gasto #' . $gasto_id . '%', $gasto['fecha']]);
$extras = $sbv->fetchAll(PDO::FETCH_ASSOC);

// ── Cálculos salariales ───────────────────────────────────────────────────────
$IHSS_EMP  = 0.035; $IHSS_PAT = 0.07;
$RAP_EMP   = 0.015; $RAP_PAT  = 0.015;
$IHSS_TOPE = 10294.10;

$salario  = $colab ? (float)$colab['salario_base'] : 0;
$tipo_pago= $colab['tipo_pago'] ?? 'mensual';
$div      = $tipo_pago === 'quincenal' ? 2 : 1;
$base_i   = min($salario, $IHSS_TOPE);
$ihss_e   = ($colab['aplica_ihss'] ?? 0) ? round($base_i * $IHSS_EMP / $div, 2) : 0;
$rap_e    = ($colab['aplica_rap']  ?? 0) ? round($salario * $RAP_EMP  / $div, 2) : 0;
$ihss_p   = ($colab['aplica_ihss'] ?? 0) ? round($base_i * $IHSS_PAT / $div, 2) : 0;
$rap_p    = ($colab['aplica_rap']  ?? 0) ? round($salario * $RAP_PAT  / $div, 2) : 0;
$bruto    = round($salario / $div, 2);
$neto     = round(($salario / $div) - $ihss_e - $rap_e, 2);

$total_desc  = array_sum(array_column($deducciones, 'monto'));
$total_extra = array_sum(array_column($extras, 'monto'));

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmtL(float $n): string {
    return 'L&nbsp;' . number_format($n, 2, '.', ',');
}
function fmtFecha(string $d): string {
    $m = ['','enero','febrero','marzo','abril','mayo','junio',
           'julio','agosto','septiembre','octubre','noviembre','diciembre'];
    $dt = new DateTime($d);
    return (int)$dt->format('d').' de '.$m[(int)$dt->format('n')].' de '.$dt->format('Y');
}

// ── Imagen → base64 (soporta URL http o ruta local) ──────────────────────────
function imgBase64(?string $src): string {
    if (!$src) return '';
    if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
        $ctx  = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
        $data = @file_get_contents($src, false, $ctx);
    } else {
        $path = str_starts_with($src, '/')
            ? $src
            : ($_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($src, '/'));
        $data = file_exists($path) ? file_get_contents($path) : false;
    }
    if (!$data) return '';
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($data);
    return 'data:' . $mime . ';base64,' . base64_encode($data);
}

// ── Logo empresa ──────────────────────────────────────────────────────────────
$logo_b64 = imgBase64($empresa['logo_url'] ?? '');

// ── Firma del colaborador ─────────────────────────────────────────────────────
$firma_b64 = '';
if ($colab && !empty($colab['url_firma'])) {
    // Intentar ruta relativa dentro del proyecto
    $firma_candidates = [
        __DIR__ . '/includes/colaboradores/' . $colab['url_firma'],
        __DIR__ . '/includes/uploads/firmas/' . $colab['url_firma'],
        $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($colab['url_firma'], '/'),
    ];
    foreach ($firma_candidates as $fp) {
        if (file_exists($fp)) {
            $d = file_get_contents($fp);
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($fp);
            $firma_b64 = 'data:' . $mime . ';base64,' . base64_encode($d);
            break;
        }
    }
}

// ── Comprobante adjunto ───────────────────────────────────────────────────────
$comp_b64  = '';
$comp_mime = '';
$es_pdf_comp = false;
if (!empty($gasto['archivo_adjunto'])) {
    $cp = __DIR__ . '/includes/uploads/comprobantes_nomina/' . $gasto['archivo_adjunto'];
    if (file_exists($cp)) {
        $comp_mime   = (new finfo(FILEINFO_MIME_TYPE))->file($cp);
        $es_pdf_comp = ($comp_mime === 'application/pdf');
        if (!$es_pdf_comp) {
            $comp_b64 = 'data:' . $comp_mime . ';base64,' . base64_encode(file_get_contents($cp));
        }
    }
}

// ── Datos de presentación ─────────────────────────────────────────────────────
$razon      = $empresa['razon_social'] ?? $empresa['nombre'] ?? '—';
$direccion  = $empresa['direccion']    ?? '';
$tel_emp    = $empresa['telefono']     ?? '';
$nombre_col = $colab ? trim(($colab['nombre'] ?? '') . ' ' . ($colab['apellido'] ?? '')) : '—';
$puesto_col = $colab['puesto']    ?? '—';
$dpi_col    = $colab['dpi']       ?? '';
$banco_col  = $colab['banco']     ?? '';
$ciudad_col = $colab['ciudad']    ?? '';

$metodo_lbl = [
    'transferencia' => 'Transferencia Bancaria',
    'efectivo'      => 'Efectivo',
    'cheque'        => 'Cheque',
    'tarjeta'       => 'Tarjeta',
    'otro'          => 'Otro',
][$gasto['metodo_pago']] ?? ucfirst($gasto['metodo_pago'] ?? '');

$periodo_lbl = '';
if (!is_null($gasto['quincena_num'])) {
    $periodo_lbl = (int)$gasto['quincena_num'] === 1 ? '1ª Quincena' : '2ª Quincena';
} else {
    $periodo_lbl = 'Mensual';
}
$folio = 'RN-' . str_pad($gasto_id, 5, '0', STR_PAD_LEFT);

// ══════════════════════════════════════════════════════════════════════════════
// HTML DEL RECIBO
// ══════════════════════════════════════════════════════════════════════════════
ob_start(); ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body {
    font-family: DejaVu Sans, Arial, sans-serif;
    font-size: 9pt;
    color: #1a1a1a;
    background: #fff;
}
.page { width:100%; padding: 18px 22px 14px; }

/* ── HEADER ─────────────────────────────────────────── */
.header-wrap {
    display:table; width:100%;
    border-bottom: 3px solid #f97316;
    padding-bottom: 10px; margin-bottom: 12px;
}
.hcol-logo  { display:table-cell; width:110px; vertical-align:middle; }
.hcol-info  { display:table-cell; vertical-align:middle; padding-left:12px; }
.hcol-badge { display:table-cell; width:130px; vertical-align:middle; text-align:right; }

.hcol-logo img { max-width:105px; max-height:52px; }
.empresa-name { font-size:11pt; font-weight:bold; color:#111; line-height:1.3; }
.empresa-sub  { font-size:8pt; color:#666; margin-top:2px; }

.badge-recibo {
    display:inline-block;
    background: linear-gradient(135deg,#f97316,#ea580c);
    color:#fff; border-radius:7px;
    padding:8px 14px; text-align:center;
}
.badge-recibo .br-title { font-size:12pt; font-weight:bold; letter-spacing:1px; }
.badge-recibo .br-sub   { font-size:7.5pt; opacity:.9; margin-top:2px; }
.badge-recibo .br-folio { font-size:8pt; margin-top:3px; opacity:.8; letter-spacing:.3px; }

/* ── SECTION TITLE ──────────────────────────────────── */
.sec {
    background: #f97316;
    color:#fff; font-size:7.5pt; font-weight:bold;
    padding:3px 10px; margin: 10px 0 5px;
    border-radius:3px; text-transform:uppercase; letter-spacing:.4px;
}

/* ── DOS COLUMNAS ───────────────────────────────────── */
.two-col { display:table; width:100%; }
.tc-l    { display:table-cell; width:50%; vertical-align:top; padding-right:8px; }
.tc-r    { display:table-cell; width:50%; vertical-align:top; padding-left:8px; }

/* ── INFO TABLE ─────────────────────────────────────── */
.itbl { width:100%; border-collapse:collapse; }
.itbl td { padding:3.5px 7px; font-size:8.5pt; vertical-align:top; }
.itbl .lbl { color:#888; width:38%; font-weight:bold; white-space:nowrap; font-size:8pt; }
.itbl .val { color:#1a1a1a; }
.itbl tr:nth-child(even) td { background:#fafafa; }

/* ── DESGLOSE TABLA ─────────────────────────────────── */
.dtbl { width:100%; border-collapse:collapse; }
.dtbl th {
    background:#fff7ed; color:#b45309;
    font-weight:bold; font-size:8pt;
    padding:4px 10px; text-align:left; border-bottom:1px solid #fed7aa;
}
.dtbl td { padding:4px 10px; font-size:8.5pt; border-bottom:1px solid #f4f4f4; }
.dtbl .ar { text-align:right; }
.dtbl .lc { width:65%; }
.dtbl .row-d td { color:#dc2626; }
.dtbl .row-e td { color:#16a34a; }
.dtbl .row-t td {
    background:#fff7ed; font-weight:bold; color:#ea580c;
    font-size:10pt; border-top:2px solid #f97316; border-bottom:none;
}
.dtbl .row-p td { background:#fffbeb; color:#92400e; font-size:7.5pt; border-bottom:none; }

/* ── BADGES ─────────────────────────────────────────── */
.badge {
    display:inline-block; padding:2px 8px;
    border-radius:10px; font-size:7.5pt; font-weight:bold;
}
.b-ok  { background:#dcfce7; color:#166534; }
.b-pen { background:#fef9c3; color:#854d0e; }

/* ── COMPROBANTE ─────────────────────────────────────── */
.comp-img {
    max-width:100%; max-height:210px;
    object-fit:contain;
    border:1px solid #e5e7eb; border-radius:4px;
    display:block; margin:6px auto 0;
}

/* ── FIRMAS ──────────────────────────────────────────── */
.firma-row { display:table; width:100%; margin-top:22px; }
.firma-col { display:table-cell; width:50%; text-align:center; padding:0 24px; vertical-align:bottom; }
.firma-img { max-width:150px; max-height:55px; margin-bottom:3px; }
.firma-line {
    border-top:1px solid #bbb;
    padding-top:4px; font-size:8pt; color:#555;
}
.firma-name { font-weight:bold; font-size:9pt; color:#1a1a1a; }

/* ── WATERMARK / ESTADO ──────────────────────────────── */
.estado-paid {
    display:inline-block;
    border:2px solid #16a34a; color:#16a34a;
    border-radius:4px; padding:1px 8px;
    font-size:8pt; font-weight:bold; letter-spacing:.5px;
    transform:rotate(-5deg); opacity:.7;
}

/* ── FOOTER ──────────────────────────────────────────── */
.footer {
    margin-top:16px; padding-top:7px;
    border-top:1px solid #f0f0f0;
    text-align:center; font-size:7pt; color:#bbb;
}

/* ── SEPARADOR ───────────────────────────────────────── */
.sep { border:none; border-top:1px dashed #e0e0e0; margin:8px 0; }
</style>
</head>
<body>
<div class="page">

<!-- ═══════════ HEADER ═══════════ -->
<div class="header-wrap">
    <div class="hcol-logo">
        <?php if ($logo_b64): ?>
            <img src="<?= $logo_b64 ?>" alt="Logo">
        <?php else: ?>
            <div style="font-size:14pt;font-weight:800;color:#f97316;letter-spacing:-1px">
                <?= strtoupper(mb_substr($razon,0,2)) ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="hcol-info">
        <div class="empresa-name"><?= htmlspecialchars($razon) ?></div>
        <?php if ($direccion): ?>
            <div class="empresa-sub"><i></i><?= htmlspecialchars($direccion) ?></div>
        <?php endif; ?>
        <?php if ($tel_emp): ?>
            <div class="empresa-sub">Tel. <?= htmlspecialchars($tel_emp) ?></div>
        <?php endif; ?>
    </div>
    <div class="hcol-badge">
        <div class="badge-recibo">
            <div class="br-title">RECIBO</div>
            <div class="br-sub">Pago de Nómina</div>
            <div class="br-folio"><?= $folio ?></div>
        </div>
    </div>
</div>

<!-- ═══════════ DATOS DEL PAGO + COLABORADOR ═══════════ -->
<div class="two-col">
    <div class="tc-l">
        <div class="sec">&#128203; Datos del Pago</div>
        <table class="itbl">
            <tr>
                <td class="lbl">Folio</td>
                <td class="val"><strong><?= $folio ?></strong></td>
            </tr>
            <tr>
                <td class="lbl">Fecha</td>
                <td class="val"><?= fmtFecha($gasto['fecha']) ?></td>
            </tr>
            <tr>
                <td class="lbl">Período</td>
                <td class="val">
                    <?= $periodo_lbl ?>&nbsp;&middot;&nbsp;<?= date('F Y', strtotime($gasto['fecha'])) ?>
                </td>
            </tr>
            <tr>
                <td class="lbl">Método</td>
                <td class="val"><?= htmlspecialchars($metodo_lbl) ?></td>
            </tr>
            <tr>
                <td class="lbl">Estado</td>
                <td class="val">
                    <span class="badge <?= $gasto['estado'] === 'pagado' ? 'b-ok' : 'b-pen' ?>">
                        <?= strtoupper($gasto['estado']) ?>
                    </span>
                </td>
            </tr>
            <?php if ($gasto['notas']): ?>
            <tr>
                <td class="lbl">Referencia</td>
                <td class="val" style="font-size:7.5pt"><?= htmlspecialchars(mb_substr($gasto['notas'],0,80)) ?></td>
            </tr>
            <?php endif; ?>
        </table>
    </div>
    <div class="tc-r">
        <div class="sec">&#128100; Colaborador</div>
        <table class="itbl">
            <tr>
                <td class="lbl">Nombre</td>
                <td class="val"><strong><?= htmlspecialchars($nombre_col) ?></strong></td>
            </tr>
            <tr>
                <td class="lbl">Puesto</td>
                <td class="val"><?= htmlspecialchars($puesto_col) ?></td>
            </tr>
            <?php if ($dpi_col): ?>
            <tr>
                <td class="lbl">DPI / RTN</td>
                <td class="val"><?= htmlspecialchars($dpi_col) ?></td>
            </tr>
            <?php endif; ?>
            <?php if ($banco_col): ?>
            <tr>
                <td class="lbl">Banco</td>
                <td class="val"><?= htmlspecialchars($banco_col) ?></td>
            </tr>
            <?php endif; ?>
            <?php if ($ciudad_col): ?>
            <tr>
                <td class="lbl">Ciudad</td>
                <td class="val"><?= htmlspecialchars($ciudad_col) ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <td class="lbl">Tipo Pago</td>
                <td class="val"><?= ucfirst($tipo_pago) ?></td>
            </tr>
        </table>
    </div>
</div>

<!-- ═══════════ DESGLOSE SALARIAL ═══════════ -->
<div class="sec">&#128176; Desglose del Pago</div>
<table class="dtbl">
    <tr>
        <th class="lc">Concepto</th>
        <th class="ar">Monto</th>
    </tr>

    <?php if ($bruto > 0): ?>
    <tr>
        <td>Salario bruto &mdash; <?= $periodo_lbl ?></td>
        <td class="ar"><?= fmtL($bruto) ?></td>
    </tr>
    <?php endif; ?>

    <?php if ($ihss_e > 0): ?>
    <tr class="row-d">
        <td>&minus; Descuento IHSS empleado (3.5%)</td>
        <td class="ar">&minus;<?= fmtL($ihss_e) ?></td>
    </tr>
    <?php endif; ?>

    <?php if ($rap_e > 0): ?>
    <tr class="row-d">
        <td>&minus; Descuento RAP empleado (1.5%)</td>
        <td class="ar">&minus;<?= fmtL($rap_e) ?></td>
    </tr>
    <?php endif; ?>

    <?php if (($ihss_e + $rap_e) > 0): ?>
    <tr style="background:#f0fdf4">
        <td><strong>= Neto base</strong></td>
        <td class="ar"><strong style="color:#16a34a"><?= fmtL($neto) ?></strong></td>
    </tr>
    <?php endif; ?>

    <?php foreach ($deducciones as $d): ?>
    <tr class="row-d">
        <td>&minus; <?= htmlspecialchars($d['desc_prest']) ?> (cuota #<?= $d['numero_cuota'] ?>)</td>
        <td class="ar">&minus;<?= fmtL((float)$d['monto']) ?></td>
    </tr>
    <?php endforeach; ?>

    <?php foreach ($extras as $ex):
        $icono = str_starts_with($ex['descripcion'], 'Bono:') ? '&#127873;' : '&#129523;';
    ?>
    <tr class="row-e">
        <td><?= $icono ?> + <?= htmlspecialchars($ex['descripcion']) ?></td>
        <td class="ar">+<?= fmtL((float)$ex['monto']) ?></td>
    </tr>
    <?php endforeach; ?>

    <tr class="row-t">
        <td>&#9989; TOTAL A PAGAR</td>
        <td class="ar"><?= fmtL((float)$gasto['monto']) ?></td>
    </tr>

    <?php if (($ihss_p + $rap_p) > 0): ?>
    <tr class="row-p">
        <td>+ Carga patronal empresa (IHSS <?= $ihss_p>0?number_format($ihss_p,2):'' ?> + RAP <?= $rap_p>0?number_format($rap_p,2):'' ?>)</td>
        <td class="ar">+<?= fmtL($ihss_p + $rap_p) ?></td>
    </tr>
    <?php endif; ?>
</table>

<!-- ═══════════ COMPROBANTE DE TRANSFERENCIA ═══════════ -->
<?php if ($comp_b64): ?>
    <div class="sec">&#128206; Comprobante de Transferencia</div>
    <div style="text-align:center">
        <img src="<?= $comp_b64 ?>" class="comp-img" alt="Comprobante">
    </div>
<?php elseif ($es_pdf_comp): ?>
    <div class="sec">&#128206; Comprobante</div>
    <p style="font-size:8pt;color:#888;margin:5px 0">
        El comprobante adjunto es un archivo PDF. Ver documento original.
    </p>
<?php endif; ?>

<!-- ═══════════ FIRMAS ═══════════ -->
<div class="firma-row">
    <div class="firma-col">
        <?php if ($firma_b64): ?>
            <img src="<?= $firma_b64 ?>" class="firma-img" alt="Firma">
        <?php else: ?>
            <div style="height:48px"></div>
        <?php endif; ?>
        <div class="firma-line">
            <div class="firma-name"><?= htmlspecialchars($nombre_col) ?></div>
            <div style="font-size:7.5pt;color:#777"><?= htmlspecialchars($puesto_col) ?> &mdash; Colaborador</div>
        </div>
    </div>
    <div class="firma-col">
        <div style="height:48px"></div>
        <div class="firma-line">
            <div class="firma-name">Autorizado por</div>
            <div style="font-size:7.5pt;color:#777"><?= htmlspecialchars($razon) ?></div>
        </div>
    </div>
</div>

<!-- ═══════════ FOOTER ═══════════ -->
<div class="footer">
    Generado el <?= fmtFecha(date('Y-m-d')) ?>
    &nbsp;&middot;&nbsp; <?= htmlspecialchars($razon) ?>
    &nbsp;&middot;&nbsp; <?= $folio ?>
    &nbsp;&middot;&nbsp; Documento de pago interno &mdash; no válido como factura fiscal
</div>

</div><!-- /page -->
</body>
</html>
<?php
$html = ob_get_clean();

// ── Generar PDF ───────────────────────────────────────────────────────────────
$opt = new Options();
$opt->set('isRemoteEnabled',    true);
$opt->set('isHtml5ParserEnabled', true);
$opt->set('defaultFont',        'DejaVu Sans');
$opt->set('chroot',             realpath($_SERVER['DOCUMENT_ROOT'] ?: __DIR__));

$pdf = new Dompdf($opt);
$pdf->loadHtml($html, 'UTF-8');
$pdf->setPaper('letter', 'portrait');
$pdf->render();

$filename     = 'recibo_pago_' . $folio . '_' . date('Ymd') . '.pdf';
$es_descarga  = !isset($_GET['vista']);   // ?vista=1  → abre en browser
$pdf->stream($filename, ['Attachment' => $es_descarga ? 1 : 0]);
