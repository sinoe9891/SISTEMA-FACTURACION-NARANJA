<?php
// clientes/naranjaymedia/colaborador_recibo_pdf.php
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';
require_once '../../includes/firmantes.php';
require_once '../../includes/salarios.php';

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

// Sueldo y puesto vigentes en la fecha del pago (historial), no los de hoy
$histCol  = $colab ? salariosHistorial($pdo, (int)$cliente_id, (int)$colab['id']) : [];
$salario  = $colab ? salarioVigente($histCol, $colab, $gasto['fecha']) : 0;
$puestoHist = null;
foreach ($histCol[(int)($colab['id'] ?? 0)] ?? [] as $h) if ($h['desde'] <= $gasto['fecha'] && trim((string)$h['puesto']) !== '') $puestoHist = $h['puesto'];
$tipo_pago= $colab['tipo_pago'] ?? 'mensual';
$div      = $tipo_pago === 'quincenal' ? 2 : 1;
$base_i   = min($salario, $IHSS_TOPE);
$ihss_e   = ($colab['aplica_ihss'] ?? 0) ? round($base_i * $IHSS_EMP / $div, 2) : 0;
$rap_e    = ($colab['aplica_rap']  ?? 0) ? round($salario * $RAP_EMP  / $div, 2) : 0;
$ihss_p   = ($colab['aplica_ihss'] ?? 0) ? round($base_i * $IHSS_PAT / $div, 2) : 0;
$rap_p    = ($colab['aplica_rap']  ?? 0) ? round($salario * $RAP_PAT  / $div, 2) : 0;
$bruto    = round($salario / $div, 2);
$neto     = round(($salario / $div) - $ihss_e - $rap_e, 2);
// Si se pagó distinto de lo que da el sueldo (p. ej. se redujo ese mes), se muestra la diferencia para que el recibo cuadre

$total_desc  = array_sum(array_column($deducciones, 'monto'));
$total_extra = array_sum(array_column($extras, 'monto'));
$ajuste = $bruto > 0 ? round((float)$gasto['monto'] - ($neto - $total_desc + $total_extra), 2) : 0;

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
    if (!file_exists($cp)) $cp = __DIR__ . '/includes/uploads/gastos/' . basename($gasto['archivo_adjunto']);
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
$puesto_col = $puestoHist ?? ($colab['puesto'] ?? '—');
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
$meses_es  = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$ts_pago   = strtotime($gasto['fecha']);
$periodo_txt = $periodo_lbl . ' · ' . $meses_es[(int)date('n', $ts_pago)] . ' ' . date('Y', $ts_pago);
$referencia = preg_match('/ref\.\s*([A-Za-z0-9-]+)/i', (string)$gasto['notas'], $mref) ? $mref[1] : '';
$cuenta_col = trim(($colab['banco'] ?? '') . ' ' . ($colab['tipo_cuenta'] ?? '') . (!empty($colab['numero_cuenta']) ? ' ••' . substr(preg_replace('/\D/', '', $colab['numero_cuenta']), -4) : ''));
$estado_txt = ['pagado' => 'Pagado', 'pendiente' => 'Pendiente', 'anulado' => 'Anulado'][$gasto['estado']] ?? $gasto['estado'];
$e = fn($t) => htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8');
$firmantes = firmantesParaPdf($pdo, $cliente_id, __DIR__ . '/includes/uploads');
$L = fn($n) => 'L ' . number_format((float)$n, 2);

// ══════════════════════════════════════════════════════════════════════════════
// HTML DEL RECIBO (mismo estilo que el boucher; anchos fijos para que nada se salga de la hoja)
// ══════════════════════════════════════════════════════════════════════════════
ob_start(); ?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8">
<style>
    @page { margin: 1.1cm 1.4cm; }
    body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9.5px; color: #1e293b; }
    table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    td, th { word-wrap: break-word; }
    .enc td { vertical-align: middle; }
    .enc-logo img { max-height: 60px; max-width: 130px; }
    .emp { font-size: 11.5px; font-weight: bold; color: #0f172a; line-height: 1.3; }
    .emp-sub { font-size: 8.5px; color: #64748b; margin-top: 2px; }
    .doc { text-align: right; } .doc-t { font-size: 14px; font-weight: bold; letter-spacing: 2px; color: #0f172a; }
    .doc-n { font-size: 10px; color: #e4550d; font-weight: bold; margin-top: 2px; }
    .franja { height: 4px; background: #e4550d; margin: 10px 0 12px; }
    .bloque-t { font-size: 8.5px; font-weight: bold; color: #0f172a; text-transform: uppercase; letter-spacing: 1.5px; border-bottom: 2px solid #0f172a; padding-bottom: 3px; margin-bottom: 4px; }
    .kv td { padding: 4px 6px; border-bottom: 1px solid #eef2f6; font-size: 9.5px; }
    .kv td.k { width: 36%; color: #64748b; font-size: 8.5px; text-transform: uppercase; letter-spacing: .5px; }
    .kv td.v { font-weight: bold; }
    .pill { background: #dcfce7; color: #166534; padding: 1px 7px; border-radius: 8px; font-size: 8.5px; }
    .des { margin-top: 14px; } .des th { text-align: left; font-size: 8.5px; color: #64748b; text-transform: uppercase; letter-spacing: .5px; padding: 5px 6px; border-bottom: 1px solid #cbd5e1; }
    .des td { padding: 5px 6px; border-bottom: 1px solid #eef2f6; } .num { text-align: right; width: 28%; }
    .menos td { color: #b91c1c; } .mas td { color: #047857; } .sub td { background: #f8fafc; font-weight: bold; }
    .total td { background: #0f172a; color: #fff; font-weight: bold; font-size: 12px; padding: 8px 6px; border: 0; }
    .patronal td { color: #94a3b8; font-size: 8.5px; }
    .letras { font-style: italic; color: #475569; margin-top: 5px; }
    .comp { margin-top: 12px; text-align: center; border: 1px solid #e2e8f0; border-radius: 6px; padding: 6px; }
    .comp img { max-width: 100%; max-height: 260px; } .comp-vacio { color: #94a3b8; padding: 18px 0; }
    /* Arriba + caja de firma de alto fijo: las tres líneas quedan a la misma altura aunque el texto de abajo ocupe 2 renglones */
    .firmas { margin-top: 18px; } .firmas td { width: 33%; text-align: center; vertical-align: top; padding: 0 12px; }
    .firma-caja { height: 90px; margin-bottom: -16px; } .firma-caja img { max-height: 88px; max-width: 200px; }
    .linea { border-top: 1px solid #475569; padding-top: 4px; }
    .linea b { display: block; font-size: 10px; color: #0f172a; } .linea span { font-size: 8.5px; color: #64748b; }
    .pie { margin-top: 16px; padding-top: 6px; border-top: 1px solid #e2e8f0; color: #94a3b8; font-size: 7.5px; text-align: center; }
</style></head><body>

<table class="enc"><tr>
    <td style="width:22%" class="enc-logo"><?php if ($logo_b64): ?><img src="<?= $logo_b64 ?>" alt=""><?php endif; ?></td>
    <td style="width:52%"><div class="emp"><?= $e($razon) ?></div>
        <?php if ($direccion): ?><div class="emp-sub"><?= $e($direccion) ?></div><?php endif; ?>
        <?php if ($tel_emp): ?><div class="emp-sub">Tel. <?= $e($tel_emp) ?></div><?php endif; ?></td>
    <td style="width:26%" class="doc"><div class="doc-t">RECIBO DE PAGO</div><div class="doc-n">N.º <?= $folio ?></div></td>
</tr></table>
<div class="franja"></div>

<table><tr>
    <td style="width:49%; vertical-align:top">
        <div class="bloque-t">Datos del pago</div>
        <table class="kv">
            <tr><td class="k">Fecha</td><td class="v"><?= fmtFecha($gasto['fecha']) ?></td></tr>
            <tr><td class="k">Período</td><td class="v"><?= $e($periodo_txt) ?></td></tr>
            <tr><td class="k">Método</td><td class="v"><?= $e($metodo_lbl) ?></td></tr>
            <?php if ($referencia): ?><tr><td class="k">Referencia</td><td class="v"><?= $e($referencia) ?></td></tr><?php endif; ?>
            <tr><td class="k">Estado</td><td class="v"><span class="pill"><?= $e($estado_txt) ?></span></td></tr>
        </table>
    </td>
    <td style="width:2%"></td>
    <td style="width:49%; vertical-align:top">
        <div class="bloque-t">Colaborador</div>
        <table class="kv">
            <tr><td class="k">Nombre</td><td class="v"><?= $e($nombre_col) ?></td></tr>
            <tr><td class="k">Puesto</td><td class="v"><?= $e($puesto_col) ?></td></tr>
            <?php if ($dpi_col): ?><tr><td class="k">Identidad</td><td class="v"><?= $e($dpi_col) ?></td></tr><?php endif; ?>
            <?php if ($cuenta_col): ?><tr><td class="k">Cuenta</td><td class="v"><?= $e($cuenta_col) ?></td></tr><?php endif; ?>
            <tr><td class="k">Tipo de pago</td><td class="v"><?= $e(ucfirst($tipo_pago)) ?></td></tr>
        </table>
    </td>
</tr></table>

<table class="des">
    <tr><th>Concepto</th><th class="num">Monto</th></tr>
    <?php if ($bruto > 0): ?><tr><td>Salario bruto · <?= $e($periodo_lbl) ?></td><td class="num"><?= $L($bruto) ?></td></tr><?php endif; ?>
    <?php if ($ihss_e > 0): ?><tr class="menos"><td>− IHSS empleado (3.5%)</td><td class="num">− <?= $L($ihss_e) ?></td></tr><?php endif; ?>
    <?php if ($rap_e > 0): ?><tr class="menos"><td>− RAP empleado (1.5%)</td><td class="num">− <?= $L($rap_e) ?></td></tr><?php endif; ?>
    <?php if (($ihss_e + $rap_e) > 0): ?><tr class="sub"><td>Neto base</td><td class="num"><?= $L($neto) ?></td></tr><?php endif; ?>
    <?php foreach ($deducciones as $d): ?><tr class="menos"><td>− <?= $e($d['desc_prest']) ?> (cuota <?= (int)$d['numero_cuota'] ?>)</td><td class="num">− <?= $L($d['monto']) ?></td></tr><?php endforeach; ?>
    <?php foreach ($extras as $ex): ?><tr class="mas"><td>+ <?= $e($ex['descripcion']) ?></td><td class="num">+ <?= $L($ex['monto']) ?></td></tr><?php endforeach; ?>
    <?php if (abs($ajuste) >= 0.01): ?><tr class="<?= $ajuste < 0 ? 'menos' : 'mas' ?>"><td><?= $ajuste < 0 ? '− Pagado menos que el salario del período' : '+ Pagado adicional al salario del período' ?></td><td class="num"><?= $ajuste < 0 ? '− ' : '+ ' ?><?= $L(abs($ajuste)) ?></td></tr><?php endif; ?>
    <tr class="total"><td>TOTAL PAGADO</td><td class="num"><?= $L($gasto['monto']) ?></td></tr>
    <?php if (($ihss_p + $rap_p) > 0): ?><tr class="patronal"><td>Aporte patronal de la empresa (IHSS + RAP), no se descuenta al colaborador</td><td class="num"><?= $L($ihss_p + $rap_p) ?></td></tr><?php endif; ?>
</table>
<div class="letras">Son: <?= $e(numeroALetras((float)$gasto['monto'])) ?></div>

<div class="comp">
    <?php if ($comp_b64): ?><img src="<?= $comp_b64 ?>" alt="Comprobante">
    <?php elseif ($es_pdf_comp): ?><div class="comp-vacio">El comprobante de este pago es un PDF: se archiva por separado.</div>
    <?php else: ?><div class="comp-vacio">Sin captura del comprobante.</div><?php endif; ?>
</div>

<table class="firmas"><tr>
    <td><div class="firma-caja"><?php if ($firma_b64): ?><img src="<?= $firma_b64 ?>" alt="Firma"><?php endif; ?></div>
        <div class="linea"><b><?= $e($nombre_col) ?></b><span>Recibí conforme · <?= $e($puesto_col) ?></span></div></td>
    <?php foreach (['autorizado' => 'Autorizado por', 'vobo' => 'Vo.Bo.'] as $rolF => $tituloF): $f = $firmantes[$rolF]; ?>
        <td><div class="firma-caja"><?php if ($f['firma_b64']): ?><img src="<?= $f['firma_b64'] ?>" alt="Firma"><?php endif; ?></div>
            <div class="linea"><b><?= $e($f['nombre'] ?: $tituloF) ?></b><span><?= $e($tituloF) ?><?= $f['cargo'] ? ' · ' . $e($f['cargo']) : '' ?></span></div></td>
    <?php endforeach; ?>
</tr></table>

<div class="pie">Generado el <?= fmtFecha(date('Y-m-d')) ?> · <?= $folio ?> · Documento de pago interno, no válido como factura fiscal</div>
</body></html>
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
