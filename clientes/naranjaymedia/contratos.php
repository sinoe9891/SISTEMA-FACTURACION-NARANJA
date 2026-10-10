<?php
$titulo = 'Contratos';
require_once '../../includes/db.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';
require_once '../../includes/templates/header.php';

$ctEsAdmin = in_array(USUARIO_ROL, ['admin', 'superadmin'], true);   // casillas para eliminar contratos
$cliente_id = (int)(USUARIO_ROL === 'superadmin'
    ? ($_SESSION['cliente_seleccionado'] ?? 0)
    : CLIENTE_ID);

// Auto-update estados
$pdo->prepare("UPDATE contratos SET estado='vencido' WHERE cliente_id=? AND estado='activo' AND fecha_fin IS NOT NULL AND fecha_fin < CURDATE()")->execute([$cliente_id]);

// KPIs
$stmtKpi = $pdo->prepare("
    SELECT
        SUM(estado='activo')  AS activos,
        SUM(estado='activo' AND fecha_fin BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)) AS proximos_vencer,
        SUM(estado='vencido') AS vencidos,
        SUM(CASE WHEN estado='activo' AND tipo_contrato<>'proyecto' THEN monto ELSE 0 END) AS monto_activo
    FROM contratos WHERE cliente_id=?
");
$stmtKpi->execute([$cliente_id]);
$kpi = $stmtKpi->fetch(PDO::FETCH_ASSOC);

// Lista completa
$stmtLista = $pdo->prepare("
    SELECT c.*,
           cf.nombre AS receptor_nombre, cf.rtn AS receptor_rtn,
           cf.email AS receptor_email, cf.telefono AS receptor_tel,
           p.nombre AS producto_nombre,
           (CASE
               WHEN c.tipo_contrato = 'sin_factura'
               THEN (SELECT COUNT(*) FROM contratos_recibos r WHERE r.contrato_id=c.id AND r.cliente_id=c.cliente_id AND r.estado='emitido' AND MONTH(r.fecha_emision)=MONTH(CURDATE()) AND YEAR(r.fecha_emision)=YEAR(CURDATE()))
               ELSE (SELECT COUNT(*) FROM facturas f WHERE f.contrato_id=c.id AND f.cliente_id=c.cliente_id AND f.estado='emitida' AND MONTH(f.fecha_emision)=MONTH(CURDATE()) AND YEAR(f.fecha_emision)=YEAR(CURDATE()))
           END) AS facturado_este_mes,
           (SELECT DATE(f2.fecha_emision) FROM facturas f2 WHERE f2.contrato_id=c.id AND f2.cliente_id=c.cliente_id AND f2.estado='emitida' ORDER BY f2.fecha_emision DESC LIMIT 1) AS ultima_factura_fecha,
           (SELECT COUNT(*) FROM facturas f3 WHERE f3.contrato_id=c.id AND f3.cliente_id=c.cliente_id AND f3.estado='emitida') AS total_facturas_contrato,
           (SELECT MAX(COALESCE(f5.periodo_anio, YEAR(f5.fecha_emision)) * 12 + COALESCE(f5.periodo_mes, MONTH(f5.fecha_emision)) - 1)
              FROM facturas f5 WHERE f5.contrato_id=c.id AND f5.cliente_id=c.cliente_id AND f5.estado='emitida') AS ultimo_mes_cubierto,
           (SELECT COUNT(*) FROM facturas f6 WHERE f6.contrato_id=c.id AND f6.cliente_id=c.cliente_id AND f6.estado='emitida' AND f6.pagada=0) AS impagas_n,
           (SELECT COALESCE(SUM(f7.total),0) FROM facturas f7 WHERE f7.contrato_id=c.id AND f7.cliente_id=c.cliente_id AND f7.estado='emitida' AND f7.pagada=0) AS impagas_total,
           (SELECT COALESCE(SUM(f4.total),0) FROM facturas f4 WHERE f4.contrato_id=c.id AND f4.cliente_id=c.cliente_id AND f4.estado='emitida') AS total_monto_contrato,
           CASE
               WHEN c.fecha_inicio > CURDATE() THEN DATE(CONCAT(YEAR(c.fecha_inicio),'-',LPAD(MONTH(c.fecha_inicio),2,'0'),'-',LPAD(LEAST(c.dia_pago,DAY(LAST_DAY(c.fecha_inicio))),2,'0')))
               WHEN DAY(CURDATE()) <= c.dia_pago THEN DATE(CONCAT(YEAR(CURDATE()),'-',LPAD(MONTH(CURDATE()),2,'0'),'-',LPAD(LEAST(c.dia_pago,DAY(LAST_DAY(CURDATE()))),2,'0')))
               ELSE DATE(CONCAT(YEAR(DATE_ADD(CURDATE(),INTERVAL 1 MONTH)),'-',LPAD(MONTH(DATE_ADD(CURDATE(),INTERVAL 1 MONTH)),2,'0'),'-',LPAD(LEAST(c.dia_pago,DAY(LAST_DAY(DATE_ADD(CURDATE(),INTERVAL 1 MONTH)))),2,'0')))
           END AS proxima_fecha_pago,
           CASE
               WHEN c.fecha_inicio > CURDATE() THEN DATEDIFF(DATE(CONCAT(YEAR(c.fecha_inicio),'-',LPAD(MONTH(c.fecha_inicio),2,'0'),'-',LPAD(LEAST(c.dia_pago,DAY(LAST_DAY(c.fecha_inicio))),2,'0'))),CURDATE())
               WHEN DAY(CURDATE()) <= c.dia_pago THEN DATEDIFF(DATE(CONCAT(YEAR(CURDATE()),'-',LPAD(MONTH(CURDATE()),2,'0'),'-',LPAD(LEAST(c.dia_pago,DAY(LAST_DAY(CURDATE()))),2,'0'))),CURDATE())
               ELSE DATEDIFF(DATE(CONCAT(YEAR(DATE_ADD(CURDATE(),INTERVAL 1 MONTH)),'-',LPAD(MONTH(DATE_ADD(CURDATE(),INTERVAL 1 MONTH)),2,'0'),'-',LPAD(LEAST(c.dia_pago,DAY(LAST_DAY(DATE_ADD(CURDATE(),INTERVAL 1 MONTH)))),2,'0'))),CURDATE())
           END AS dias_para_pago,
           CASE
               WHEN c.fecha_fin IS NULL THEN 'indefinido'
               WHEN c.fecha_fin < CURDATE() THEN 'vencido'
               WHEN c.fecha_fin BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 3 DAY) THEN 'critico'
               WHEN c.fecha_fin BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY) THEN 'proximo'
               ELSE 'activo'
           END AS alerta,
           DATEDIFF(c.fecha_fin, CURDATE()) AS dias_restantes,
           (c.fecha_inicio > CURDATE()) AS no_iniciado
    FROM contratos c
    INNER JOIN clientes_factura   cf ON cf.id=c.receptor_id AND cf.cliente_id=c.cliente_id
    INNER JOIN productos_clientes p  ON p.id=c.producto_id  AND p.cliente_id=c.cliente_id
    WHERE c.cliente_id=?
    ORDER BY c.estado ASC, dias_para_pago ASC, c.fecha_fin ASC
");
$stmtLista->execute([$cliente_id]);
$contratos = $stmtLista->fetchAll(PDO::FETCH_ASSOC);

// Contratos con plan de pagos: el próximo cobro y lo vencido salen del plan, no del día de pago mensual
require_once '../../includes/contrato_plan.php';
$conPlan = array_flip(planContratosConPlan($pdo, (int)$cliente_id));
foreach ($contratos as &$c) {
    if (!isset($conPlan[(int)$c['id']])) continue;
    $pl = ['venc_n' => 0, 'venc_total' => 0.0, 'venc_dias' => 0, 'prox' => null, 'n' => 0, 'pagados' => 0];
    foreach (planLineas($pdo, (int)$cliente_id, (int)$c['id']) as $l) {
        $pl['n']++;
        if ($l['estado'] === 'pagado') { $pl['pagados']++; continue; }
        if ($l['estado'] === 'facturado') continue;   // ya es factura: la cuenta la columna de cobro
        if ($l['estado'] === 'vencido') { $pl['venc_n']++; $pl['venc_total'] += (float)$l['total']; $pl['venc_dias'] = max($pl['venc_dias'], (int)$l['dias']); }
        if (!$pl['prox']) $pl['prox'] = $l;   // el primero sin cobrar (puede estar vencido)
    }
    $c['plan'] = $pl;
    if ($pl['prox']) {
        $c['proxima_fecha_pago'] = $pl['prox']['fecha'];
        $c['dias_para_pago'] = -(int)$pl['prox']['dias'];
        $c['monto_proximo'] = (float)$pl['prox']['total'];
    }
}
unset($c);

$pendientes_mes = 0;
$monto_pendiente = 0;
foreach ($contratos as $c) {
    if ($c['estado'] === 'activo' && $c['tipo_contrato'] !== 'proyecto' && !(int)$c['no_iniciado'] && !(int)$c['facturado_este_mes']) {
        $pendientes_mes++;
        $monto_pendiente += (float)$c['monto'];
    }
}
$total_contratos = count($contratos);

// Facturas de contrato aún sin pagar, con su fecha de cobro: el día de pago del contrato en el mes que cubre
// la factura (nunca antes de emitirla). Solo es «vencida» cuando esa fecha ya pasó.
$porCobrar = [];
$stPC = $pdo->prepare("
    SELECT f.contrato_id, f.total, DATE(f.fecha_emision) emision,
           COALESCE(f.periodo_anio, YEAR(f.fecha_emision)) pa, COALESCE(f.periodo_mes, MONTH(f.fecha_emision)) pm, c.dia_pago
    FROM facturas f JOIN contratos c ON c.id = f.contrato_id AND c.cliente_id = f.cliente_id
    WHERE f.cliente_id = ? AND f.estado = 'emitida' AND f.pagada = 0 AND f.contrato_id IS NOT NULL
");
$stPC->execute([$cliente_id]);
$hoyStr = date('Y-m-d');
foreach ($stPC->fetchAll(PDO::FETCH_ASSOC) as $f) {
    $ult = (int)date('t', mktime(0, 0, 0, (int)$f['pm'], 1, (int)$f['pa']));
    $vence = sprintf('%04d-%02d-%02d', $f['pa'], $f['pm'], min(max(1, (int)$f['dia_pago']), $ult));
    if ($vence < $f['emision']) $vence = $f['emision'];
    $k = (int)$f['contrato_id'];
    $porCobrar[$k] ??= ['venc_n' => 0, 'venc_total' => 0.0, 'venc_dias' => 0, 'pend_n' => 0, 'pend_total' => 0.0, 'proxima' => null];
    if ($vence < $hoyStr) {
        $porCobrar[$k]['venc_n']++;
        $porCobrar[$k]['venc_total'] += (float)$f['total'];
        $porCobrar[$k]['venc_dias'] = max($porCobrar[$k]['venc_dias'], (int)((strtotime($hoyStr) - strtotime($vence)) / 86400));
    } else {
        $porCobrar[$k]['pend_n']++;
        $porCobrar[$k]['pend_total'] += (float)$f['total'];
        if (!$porCobrar[$k]['proxima'] || $vence < $porCobrar[$k]['proxima']) $porCobrar[$k]['proxima'] = $vence;
    }
}

// Cobros por correo programados (aún sin enviar) de cada contrato: por sus facturas, recibos o pagos del plan
$cobroProg = [];
try {
    $sqlProg = "SELECT x.contrato_id, MIN(c.programado_para) cuando, COUNT(DISTINCT c.id) n FROM (
            SELECT f.contrato_id, cpf.cobro_id FROM cobros_programados_facturas cpf JOIN facturas f ON f.id = cpf.factura_id WHERE f.cliente_id = ? AND f.contrato_id IS NOT NULL"
        . (cobrosExtrasDisponibleLocal($pdo) ? "
            UNION SELECT r.contrato_id, x.cobro_id FROM cobros_programados_recibos x JOIN contratos_recibos r ON r.id = x.recibo_id WHERE r.cliente_id = ?
            UNION SELECT p.contrato_id, x.cobro_id FROM cobros_programados_plan x JOIN contratos_plan p ON p.id = x.plan_id WHERE p.cliente_id = ?" : "") . "
        ) x JOIN cobros_programados c ON c.id = x.cobro_id AND c.estado = 'programado' AND c.prueba = 0 GROUP BY x.contrato_id";
    $stProg = $pdo->prepare($sqlProg);
    $stProg->execute(cobrosExtrasDisponibleLocal($pdo) ? [$cliente_id, $cliente_id, $cliente_id] : [$cliente_id]);
    foreach ($stProg->fetchAll(PDO::FETCH_ASSOC) as $r) $cobroProg[(int)$r['contrato_id']] = $r;
} catch (Throwable $e) {
    $cobroProg = [];   // módulo de cobros no instalado
}

// Contratos rotativos: un mismo cliente que factura a nombre de varias empresas
$rotEmpresas = [];
$stRot = $pdo->prepare("
    SELECT r.contrato_id, cf.nombre
    FROM contratos_clientes_rotativos r
    JOIN contratos c ON c.id = r.contrato_id AND c.cliente_id = ?
    JOIN clientes_factura cf ON cf.id = r.receptor_id
    WHERE r.activo = 1
    ORDER BY r.contrato_id, r.orden
");
$stRot->execute([$cliente_id]);
foreach ($stRot->fetchAll(PDO::FETCH_ASSOC) as $r) $rotEmpresas[(int)$r['contrato_id']][] = $r['nombre'];
$stUlt = $pdo->prepare("
    SELECT f.contrato_id, cf.nombre
    FROM facturas f JOIN clientes_factura cf ON cf.id = f.receptor_id
    WHERE f.cliente_id = ? AND f.estado = 'emitida' AND f.contrato_id IS NOT NULL
      AND f.id = (SELECT f2.id FROM facturas f2 WHERE f2.contrato_id = f.contrato_id AND f2.estado = 'emitida'
                  ORDER BY COALESCE(f2.periodo_anio, YEAR(f2.fecha_emision)) DESC, COALESCE(f2.periodo_mes, MONTH(f2.fecha_emision)) DESC, f2.id DESC LIMIT 1)
");
$stUlt->execute([$cliente_id]);
$rotUltima = array_column($stUlt->fetchAll(PDO::FETCH_ASSOC), 'nombre', 'contrato_id');

// Celda "Cliente": número de contrato, cliente y, si es rotativo, las empresas a las que se factura
/**
 * Semáforo del contrato (vigencia): [clave, texto, palabras para el buscador]. Verde solo para lo pagado,
 * así que «Activo» va en azul; el color sube a amarillo, naranja y rojo según se acerca el fin.
 */
$ctSemaforo = function (array $c): array {
    $d = $c['dias_restantes'] !== null ? (int)$c['dias_restantes'] : null;
    if ($c['estado'] === 'borrador') return ['borrador', 'Borrador', 'borrador'];
    if ($c['estado'] === 'cancelado') return ['cancelado', 'Cancelado', 'cancelado'];
    if ($c['estado'] === 'pausado') return ['pausado', 'Pausado', 'pausado'];
    if ($c['estado'] === 'vencido' || ($d !== null && $d < 0)) return ['vencido', 'Vencido', 'vencido'];
    if ((int)($c['no_iniciado'] ?? 0)) return ['inicia', 'Inicia el ' . date('d/m/Y', strtotime($c['fecha_inicio'])), 'no iniciado inicia'];
    if ($d !== null && $d <= 7) return ['vence-pronto', $d === 0 ? 'Vence hoy' : 'Vence en ' . $d . ' d', 'vence pronto por vencer'];
    if ($d !== null && $d <= 30) return ['por-vencer', 'Vence en ' . $d . ' d', 'por vencer'];
    return ['activo', 'Activo', 'activo'];
};
$celdaCliente = function (array $c, bool $conDetalle) use ($rotEmpresas, $rotUltima): string {
    $h = '<span class="ct-num">#' . (int)$c['id'] . '</span>';
    $empresas = $rotEmpresas[(int)$c['id']] ?? [];
    if (($c['tipo_contrato'] ?? '') === 'rotativo' && $empresas) {
        $h .= '<div class="fw-semibold"><span data-col="cliente">' . htmlspecialchars($c['nombre_contrato'] ?: $c['receptor_nombre']) . '</span> <span class="ct-rot-pill" title="Se factura a nombre de varias empresas del mismo cliente"><i class="bi bi-arrow-repeat"></i> ' . count($empresas) . ' empresas</span></div>';
        $h .= '<ul class="ct-rot-list">';
        foreach ($empresas as $e) $h .= '<li>' . htmlspecialchars($e) . '</li>';
        $h .= '</ul>';
        if (!empty($rotUltima[(int)$c['id']])) $h .= '<small class="text-muted">Última factura: ' . htmlspecialchars($rotUltima[(int)$c['id']]) . '</small>';
        return $h;
    }
    $h .= '<div class="fw-semibold" data-col="cliente">' . htmlspecialchars($c['receptor_nombre']) . '</div>';
    if ($conDetalle && $c['receptor_rtn']) $h .= '<small class="text-muted">RTN: ' . htmlspecialchars($c['receptor_rtn']) . '</small>';
    elseif (!$conDetalle && $c['receptor_tel']) $h .= '<small class="text-muted">' . htmlspecialchars($c['receptor_tel']) . '</small>';
    return $h;
};
// Cobertura: hasta qué mes está facturado (según el mes que cubre cada factura, no su fecha de emisión)
// y cuánto debe. Sirve para clientes que facturan con meses de atraso.
$mesesCorto = [1 => 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
function cobrosExtrasDisponibleLocal(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) { try { $ok = (bool)$pdo->query("SHOW TABLES LIKE 'cobros_programados_plan'")->fetchColumn(); } catch (Throwable $e) { $ok = false; } }
    return $ok;
}
$celdaCobertura = function (array $c) use ($mesesCorto, $porCobrar, $hoyStr, $cobroProg): string {
    if ($c['estado'] !== 'activo' && !(int)$c['impagas_n']) return '';   // (impagas_n: cualquier factura sin pagar)
    $h = '';
    if ($c['tipo_contrato'] === 'proyecto') {
        $h .= '<div class="ct-cob">Proyecto · valor total</div>';
    } elseif ($c['ultimo_mes_cubierto'] !== null && $c['estado'] === 'activo') {
        $u = (int)$c['ultimo_mes_cubierto'];
        $hoy = (int)date('Y') * 12 + (int)date('n') - 1;
        $fin = $c['fecha_fin'] ? (int)substr($c['fecha_fin'], 0, 4) * 12 + (int)substr($c['fecha_fin'], 5, 2) - 1 : $hoy;
        // Meses ya vencidos (sin contar el actual) que aún no tienen factura
        $faltan = [];
        for ($m = $u + 1; $m < min($hoy, $fin + 1); $m++) $faltan[] = $mesesCorto[$m % 12 + 1];
        $txt = 'Facturado hasta ' . $mesesCorto[$u % 12 + 1] . ' ' . intdiv($u, 12);
        if ($faltan) {
            $h .= '<div class="ct-cob ct-cob-mal" title="Meses ya pasados sin factura">' . $txt . '<br>Atraso: ' . count($faltan) . ' mes' . (count($faltan) > 1 ? 'es' : '') . ' (' . implode(', ', $faltan) . ')</div>';
        } else {
            $h .= '<div class="ct-cob">' . $txt . '</div>';
        }
    }
    // Pagos del plan vencidos sin cobrar (con o sin factura de por medio)
    if (!empty($c['plan']['venc_n'])) {
        $h .= '<a class="ct-cob ct-sem ct-sem-rojo d-block" href="facturas_contrato?contrato_id=' . (int)$c['id'] . '#planPagos" title="Pagos del plan de pagos cuya fecha ya pasó y no se han cobrado">'
            . $c['plan']['venc_n'] . ' pago' . ($c['plan']['venc_n'] > 1 ? 's' : '') . ' del plan vencido' . ($c['plan']['venc_n'] > 1 ? 's' : '') . ' · L ' . number_format($c['plan']['venc_total'], 2) . ' (hace ' . $c['plan']['venc_dias'] . ' d)</a>';
    }
    // Cobro de lo ya facturado con semáforo: rojo vencida; naranja ≤ 3 días; amarillo ≤ 7; gris más adelante
    $pc = $porCobrar[(int)$c['id']] ?? null;
    $url = 'facturas_contrato?contrato_id=' . (int)$c['id'];
    if ($pc && $pc['venc_n']) {
        $h .= '<a class="ct-cob ct-sem ct-sem-rojo" href="' . $url . '" title="Facturas cuya fecha de cobro ya pasó">' . $pc['venc_n'] . ' vencida' . ($pc['venc_n'] > 1 ? 's' : '')
            . ' · L ' . number_format($pc['venc_total'], 2) . ' (hace ' . $pc['venc_dias'] . ' d)</a>';
    }
    if ($pc && $pc['pend_n']) {
        $d = (int)((strtotime($pc['proxima']) - strtotime($hoyStr)) / 86400);
        $cls = $d <= 3 ? 'ct-sem-naranja' : ($d <= 7 ? 'ct-sem-amarillo' : 'ct-sem-gris');
        $cuando = $d === 0 ? 'vence hoy' : 'vence ' . (int)substr($pc['proxima'], 8, 2) . ' ' . $mesesCorto[(int)substr($pc['proxima'], 5, 2)] . ' (' . $d . ' d)';
        $h .= '<a class="ct-cob ct-sem ' . $cls . '" href="' . $url . '" title="Facturada, aún dentro del plazo de pago">Por cobrar L ' . number_format($pc['pend_total'], 2) . ' · ' . $cuando . '</a>';
    }
    // Ya hay un correo de cobro o envío programado para este contrato
    if ($cp = $cobroProg[(int)$c['id']] ?? null) {
        $h .= '<a class="ct-cob ct-prog d-block" href="cobros_programados" title="Correo programado en Cobros por correo (aún no se envía)"><i class="bi bi-envelope-check"></i> Correo programado '
            . date('d/m g:i a', strtotime($cp['cuando'])) . ((int)$cp['n'] > 1 ? ' (+' . ((int)$cp['n'] - 1) . ')' : '') . '</a>';
    }
    return $h;
};
$mesesTitulo = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
?>

<style>
    :root {
        --brand: #0f766e;
        --brand-light: #ccfbf1;
        --brand-dark: #0d5c56;
        --success: #10b981;
        --success-bg: #ecfdf5;
        --danger: #ef4444;
        --danger-bg: #fef2f2;
        --warning: #f59e0b;
        --warning-bg: #fffbeb;
        --info: #0ea5e9;
        --info-bg: #f0f9ff;
        --surface: #fff;
        --surface-2: #f8fafc;
        --border: #e2e8f0;
        --text-main: #1e293b;
        --text-muted: #64748b;
        --shadow-sm: 0 1px 3px rgba(0, 0, 0, .06);
        --shadow-md: 0 4px 16px rgba(0, 0, 0, .08);
        --radius: 14px;
        --radius-sm: 8px;
        --tr: .2s cubic-bezier(.4, 0, .2, 1);
    }

    .ct-page {
        padding: 1.5rem 0 3rem;
    }

    .ct-header {
        background: linear-gradient(135deg, #0f766e 0%, #0d5c56 100%);
        border-radius: var(--radius);
        padding: 1.5rem 2rem;
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

    .ct-header::before {
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

    .ct-header::after {
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

    .ct-header-title {
        font-size: 1.35rem;
        font-weight: 700;
        margin: 0;
    }

    .ct-header-sub {
        font-size: .82rem;
        opacity: .8;
        margin: .25rem 0 0;
    }

    /* Stats */
    .ct-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .ct-stat {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 1rem 1.1rem;
        display: flex;
        align-items: center;
        gap: .8rem;
        box-shadow: var(--shadow-sm);
        transition: box-shadow var(--tr), transform var(--tr);
    }

    .ct-stat:hover {
        box-shadow: var(--shadow-md);
        transform: translateY(-2px);
    }

    .ct-stat-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        flex-shrink: 0;
    }

    .ct-stat-icon.teal {
        background: var(--brand-light);
        color: var(--brand);
    }

    .ct-stat-icon.green {
        background: var(--success-bg);
        color: var(--success);
    }

    .ct-stat-icon.red {
        background: var(--danger-bg);
        color: var(--danger);
    }

    .ct-stat-icon.amber {
        background: var(--warning-bg);
        color: var(--warning);
    }

    .ct-stat-icon.blue {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .ct-stat-val {
        font-size: 1.35rem;
        font-weight: 700;
        color: var(--text-main);
        line-height: 1;
    }

    .ct-stat-lbl {
        font-size: .72rem;
        color: var(--text-muted);
        margin-top: 2px;
    }

    /* Toolbar */
    .ct-toolbar {
        display: flex;
        align-items: center;
        gap: .75rem;
        flex-wrap: wrap;
        margin-bottom: 1.25rem;
    }

    .ct-search-wrap {
        position: relative;
        flex: 1 1 200px;
        min-width: 180px;
    }

    .ct-search-wrap>i {
        position: absolute;
        left: .8rem;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-muted);
        font-size: .9rem;
        pointer-events: none;
    }

    .ct-search {
        width: 100%;
        padding: .52rem .8rem .52rem 2.2rem;
        border: 1.5px solid var(--border);
        border-radius: var(--radius-sm);
        font-size: .875rem;
        background: var(--surface);
        color: var(--text-main);
        outline: none;
        transition: border-color var(--tr), box-shadow var(--tr);
    }

    .ct-search:focus {
        border-color: var(--brand);
        box-shadow: 0 0 0 3px rgba(15, 118, 110, .12);
    }

    .ct-search::placeholder {
        color: #94a3b8;
    }

    .ct-clear-btn {
        position: absolute;
        right: .6rem;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        color: var(--text-muted);
        font-size: .95rem;
        cursor: pointer;
        padding: 0;
        display: none;
    }

    .ct-clear-btn.visible {
        display: block;
    }

    .btn-new-ct {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        padding: .52rem 1.05rem;
        background: var(--brand);
        color: #fff !important;
        border-radius: var(--radius-sm);
        font-size: .86rem;
        font-weight: 600;
        text-decoration: none;
        border: none;
        cursor: pointer;
        white-space: nowrap;
        box-shadow: 0 2px 8px rgba(15, 118, 110, .25);
        transition: background var(--tr), transform var(--tr);
    }

    .btn-new-ct:hover {
        background: var(--brand-dark);
        transform: translateY(-1px);
    }

    .ct-per-page {
        padding: .48rem .65rem;
        border: 1.5px solid var(--border);
        border-radius: var(--radius-sm);
        font-size: .82rem;
        background: var(--surface);
        color: var(--text-main);
        cursor: pointer;
        outline: none;
    }

    /* Card */
    .ct-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow-sm);
        overflow: hidden;
        margin-bottom: 1.5rem;
    }

    .ct-card-header {
        padding: 1rem 1.5rem;
        border-bottom: 1px solid var(--border);
        display: flex;
        flex-wrap: wrap;
        gap: .5rem;
        align-items: center;
        justify-content: space-between;
        background: var(--surface-2);
        gap: 1rem;
        flex-wrap: wrap;
    }

    .ct-card-title {
        font-weight: 700;
        font-size: .95rem;
        color: var(--text-main);
        display: flex;
        align-items: center;
        gap: .5rem;
    }

    .ct-result-badge {
        display: inline-flex;
        align-items: center;
        background: var(--brand-light);
        color: var(--brand);
        border-radius: 20px;
        padding: .15rem .65rem;
        font-size: .78rem;
        font-weight: 600;
    }

    /* Table */
    .ct-table-wrap {
        overflow-x: auto;
    }

    .ct-table {
        width: 100%;
        border-collapse: collapse;
        font-size: .845rem;
    }

    .ct-table thead th {
        padding: .7rem 1rem;
        background: var(--surface-2);
        color: var(--text-muted);
        font-weight: 600;
        font-size: .72rem;
        text-transform: uppercase;
        letter-spacing: .05em;
        border-bottom: 1px solid var(--border);
        white-space: nowrap;
        cursor: pointer;
        user-select: none;
        transition: background var(--tr), color var(--tr);
    }

    .ct-table thead th:last-child {
        cursor: default;
    }

    .ct-table thead th:hover:not(:last-child) {
        background: var(--brand-light);
        color: var(--brand);
    }

    .ct-table thead th.sort-asc,
    .ct-table thead th.sort-desc {
        color: var(--brand);
        background: var(--brand-light);
    }

    .sort-icon {
        margin-left: .35rem;
        font-size: .68rem;
        opacity: .3;
        display: inline-block;
        transition: opacity .15s, transform .15s;
    }

    .ct-table thead th:hover:not(:last-child) .sort-icon {
        opacity: .7;
    }

    .ct-table thead th.sort-asc .sort-icon,
    .ct-table thead th.sort-desc .sort-icon {
        opacity: 1;
    }

    .ct-table thead th.sort-desc .sort-icon {
        transform: rotate(180deg);
    }

    .ct-table tbody tr {
        border-bottom: 1px solid var(--border);
        transition: background var(--tr);
    }

    .ct-table tbody tr:last-child {
        border-bottom: none;
    }

    .ct-table tbody tr:hover {
        background: #f0fdf9;
    }

    .ct-table tbody td {
        padding: .8rem 1rem;
        color: var(--text-main);
        vertical-align: middle;
    }

    /* Semáforo de contratos: franja a la izquierda + pastilla de estado (leyenda arriba de la tabla) */
    .ct-table tbody tr[class*="sem-"] td:first-child { box-shadow: inset 4px 0 0 var(--sem, transparent); }
    .sem-activo { --sem: #3b82f6; }
    .sem-inicia { --sem: #06b6d4; }
    .sem-por-vencer { --sem: #eab308; }
    .sem-vence-pronto { --sem: #f97316; }
    .sem-vencido { --sem: #dc2626; }
    .sem-pausado { --sem: #8b5cf6; }
    .sem-cancelado { --sem: #94a3b8; }
    .sem-borrador { --sem: #64748b; }
    .ct-table tbody tr.sem-borrador td { background: repeating-linear-gradient(135deg, #fff, #fff 10px, #f8fafc 10px, #f8fafc 20px); }
    .sem-pill.sem-borrador { background: #fff; color: #475569; border: 1px dashed #94a3b8 !important; }
    .ct-table tbody tr.sem-por-vencer td { background: #fefce8; }
    .ct-table tbody tr.sem-vence-pronto td { background: #fff7ed; }
    .ct-table tbody tr.sem-vencido td { background: #fef2f2; }
    .ct-table tbody tr.sem-cancelado td { background: #f8fafc; color: #94a3b8; }
    .ct-table tbody tr.sem-cancelado td .ct-clamp, .ct-table tbody tr.sem-vencido td .ct-clamp { opacity: .8; }
    .sem-pill { display: inline-flex; align-items: center; gap: .3rem; padding: .2rem .6rem; border-radius: 20px; font-size: .73rem; font-weight: 600; white-space: nowrap; }
    .sem-pill::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: var(--sem); }
    .sem-pill.sem-activo { background: #eff6ff; color: #1d4ed8; }
    .sem-pill.sem-inicia { background: #ecfeff; color: #0e7490; }
    .sem-pill.sem-por-vencer { background: #fef9c3; color: #854d0e; }
    .sem-pill.sem-vence-pronto { background: #ffedd5; color: #9a3412; }
    .sem-pill.sem-vencido { background: #fee2e2; color: #991b1b; }
    .sem-pill.sem-pausado { background: #f3e8ff; color: #6b21a8; }
    .sem-pill.sem-cancelado { background: #f1f5f9; color: #64748b; }
    .ct-leyenda { display: flex; flex-wrap: wrap; gap: .4rem; align-items: center; font-size: .75rem; }
    .ct-leyenda .sem-pill { cursor: pointer; border: 1px solid transparent; }
    .ct-leyenda .sem-pill.on { border-color: currentColor; }
    /* Días para el cobro */
    .dias-pill { display: inline-block; padding: .15rem .5rem; border-radius: 20px; font-size: .72rem; font-weight: 700; }
    .dias-atraso { background: #dc2626; color: #fff; }
    .dias-3 { background: #f97316; color: #fff; }
    .dias-5 { background: #facc15; color: #713f12; }
    .dias-resto { background: #e2e8f0; color: #475569; }
    .dias-inicia { background: #cffafe; color: #0e7490; }

    /* Badges */
    .st-pill {
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        padding: .2rem .6rem;
        border-radius: 20px;
        font-size: .73rem;
        font-weight: 600;
    }

    .st-activo {
        background: var(--success-bg);
        color: var(--success);
    }

    .st-vencido {
        background: var(--danger-bg);
        color: var(--danger);
    }

    .st-cancelado {
        background: #f1f5f9;
        color: var(--text-muted);
    }

    .st-pausado {
        background: var(--warning-bg);
        color: #92400e;
    }

    .fact-pill {
        display: inline-flex;
        align-items: center;
        gap: .25rem;
        padding: .18rem .55rem;
        border-radius: 20px;
        font-size: .72rem;
        font-weight: 600;
    }

    .fact-si {
        background: var(--success-bg);
        color: var(--success);
        border: 1px solid #a7f3d0;
    }

    /* Facturado = por cobrar (azul); verde solo para «Al día» (pagado) */
    .ct-prog {
        display: inline-block;
        margin-top: .2rem;
        padding: .1rem .5rem;
        border-radius: 20px;
        font-size: .7rem;
        font-weight: 600;
        background: #f0fdfa;
        color: #0f766e;
        border: 1px solid #99f6e4;
        text-decoration: none;
    }

    .fact-fac {
        background: #eff6ff;
        color: #1d4ed8;
        border: 1px solid #bfdbfe;
    }

    .fact-no {
        background: var(--warning-bg);
        color: #92400e;
        border: 1px solid #fde68a;
    }

    /* Actions */
    .ct-num {
        display: inline-block;
        font-size: .7rem;
        font-weight: 700;
        color: var(--app-accent, #2563eb);
        background: var(--app-accent-lt, #eff6ff);
        border-radius: 6px;
        padding: 1px 6px;
        margin-bottom: 3px;
    }

    .ct-cob {
        font-size: .7rem;
        color: var(--app-muted, #64748b);
        margin-top: 4px;
        line-height: 1.3;
        white-space: nowrap;
        text-decoration: none;
    }

    .ct-cob-mal {
        color: #dc2626;
        font-weight: 600;
    }

    .ct-rot-pill {
        display: inline-flex;
        align-items: center;
        gap: 3px;
        font-size: .68rem;
        font-weight: 600;
        color: #7c3aed;
        background: #f5f3ff;
        border-radius: 999px;
        padding: 1px 7px;
        white-space: nowrap;
    }

    .ct-rot-list {
        margin: 3px 0 2px;
        padding-left: 1rem;
        font-size: .74rem;
        color: var(--app-muted, #64748b);
    }

    .ct-clamp {
        display: -webkit-box;
        -webkit-line-clamp: 3;
        -webkit-box-orient: vertical;
        overflow: hidden;
        min-width: 180px;
    }

    /* Acciones de "Todos los contratos": 2 por fila para que no se salgan de la tabla */
    #ctTable .ct-actions {
        flex-wrap: wrap !important;
        width: 86px;
    }

    .ct-table td:first-child {
        min-width: 190px;
    }

    /* Casilla para eliminar (administradores): angosta; el cliente queda con su ancho */
    .ct-table th.ct-col-sel,
    .ct-table td.ct-col-sel {
        min-width: 0;
        width: 36px;
        padding-right: 0 !important;
    }

    .ct-table td.ct-col-sel + td {
        min-width: 190px;
    }

    #ctTable th,
    #ctTable td {
        padding-left: .55rem !important;
        padding-right: .55rem !important;
    }

    #ctTable .ct-clamp {
        min-width: 150px;
    }

    .ct-actions {
        display: flex;
        gap: .35rem;
        align-items: center;
        flex-wrap: wrap;
    }

    .btn-fa {
        display: inline-flex;
        align-items: center;
        gap: .3rem;
        padding: .3rem .6rem;
        border-radius: var(--radius-sm);
        font-size: .76rem;
        font-weight: 600;
        cursor: pointer;
        border: 1.5px solid transparent;
        transition: all var(--tr);
        text-decoration: none;
        white-space: nowrap;
    }

    .btn-fa-edit {
        background: var(--info-bg);
        color: var(--info);
        border-color: rgba(14, 165, 233, .2);
    }

    .btn-fa-edit:hover {
        background: var(--info);
        color: #fff;
    }

    .btn-fa-facturar {
        background: var(--success-bg);
        color: var(--success);
        border-color: rgba(16, 185, 129, .2);
    }

    .btn-fa-facturar:hover {
        background: var(--success);
        color: #fff;
    }

    .btn-fa-receipt {
        background: #dbeafe;
        color: #1d4ed8;
        border-color: rgba(29, 78, 216, .2);
    }

    .btn-fa-receipt:hover {
        background: #1d4ed8;
        color: #fff;
    }

    .btn-fa-cancel {
        background: var(--danger-bg);
        color: var(--danger);
        border-color: rgba(239, 68, 68, .2);
    }

    .btn-fa-cancel:hover {
        background: var(--danger);
        color: #fff;
    }

    .btn-fa-dis {
        opacity: .4;
        cursor: not-allowed;
        pointer-events: none;
        background: #f1f5f9;
        color: var(--text-muted);
        border-color: var(--border);
    }

    .ct-highlight {
        background: #fef08a;
        border-radius: 3px;
        padding: 0 2px;
    }

    /* Pagination */
    .ct-pagination {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: .85rem 1.25rem;
        border-top: 1px solid var(--border);
        background: var(--surface-2);
        gap: .75rem;
        flex-wrap: wrap;
    }

    .ct-page-info {
        font-size: .78rem;
        color: var(--text-muted);
    }

    .ct-page-btns {
        display: flex;
        gap: .3rem;
        flex-wrap: wrap;
    }

    .page-btn {
        min-width: 32px;
        height: 32px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: var(--radius-sm);
        border: 1.5px solid var(--border);
        background: var(--surface);
        color: var(--text-muted);
        font-size: .8rem;
        font-weight: 600;
        cursor: pointer;
        transition: all var(--tr);
        padding: 0 .45rem;
        user-select: none;
    }

    .page-btn:hover:not(.disabled):not(.active) {
        border-color: var(--brand);
        color: var(--brand);
        background: var(--brand-light);
    }

    .page-btn.active {
        background: var(--brand);
        border-color: var(--brand);
        color: #fff;
        box-shadow: 0 2px 8px rgba(15, 118, 110, .3);
    }

    .page-btn.disabled {
        opacity: .35;
        cursor: not-allowed;
        pointer-events: none;
    }

    .ct-empty {
        text-align: center;
        padding: 3rem 1rem;
        color: var(--text-muted);
    }

    /* Proximos cobro table */
    .cobro-days {
        display: inline-flex;
        align-items: center;
        gap: .25rem;
        padding: .18rem .55rem;
        border-radius: 20px;
        font-size: .73rem;
        font-weight: 700;
    }

    @media(max-width:768px) {

        .ct-table thead th:nth-child(5),
        .ct-table tbody td:nth-child(5),
        .ct-table thead th:nth-child(6),
        .ct-table tbody td:nth-child(6) {
            display: none;
        }
    }
</style>

<div class="ct-page container-xxl">

    <!-- Header -->
    <div class="ct-header">
        <div>
            <h4 class="ct-header-title"><i class="bi bi-file-earmark-text me-2"></i>Contratos</h4>
            <p class="ct-header-sub">Gestión de contratos de servicio &nbsp;·&nbsp; <?= date('F Y') ?></p>
        </div>
        <a href="crear_contrato" class="btn-new-ct">
            <i class="bi bi-plus-lg"></i> Nuevo Contrato
        </a>
    </div>

    <!-- Stats: lo urgente primero, con los colores del semáforo -->
    <?php
    $kAtr = ['n' => 0, 'monto' => 0.0];        // contratos con facturas o pagos del plan cuya fecha de cobro ya pasó
    $kCinco = ['n' => 0, 'monto' => 0.0];      // activos que se cobran en 5 días o menos
    $kTermina = 0;                             // activos que terminan en 30 días o menos
    foreach ($contratos as $c) {
        $pc = $porCobrar[(int)$c['id']] ?? null;
        $venc = (float)($pc['venc_total'] ?? 0) + (float)($c['plan']['venc_total'] ?? 0);
        if ($venc > 0) { $kAtr['n']++; $kAtr['monto'] += $venc; }
        if ($c['estado'] !== 'activo') continue;
        $dp = isset($c['dias_para_pago']) ? (int)$c['dias_para_pago'] : null;
        if ($dp !== null && $dp >= 0 && $dp <= 5 && !(int)($c['no_iniciado'] ?? 0)) { $kCinco['n']++; $kCinco['monto'] += (float)($c['monto_proximo'] ?? $c['monto']); }
        if ($c['dias_restantes'] !== null && (int)$c['dias_restantes'] >= 0 && (int)$c['dias_restantes'] <= 30) $kTermina++;
    }
    ?>
    <div class="ct-stats">
        <div class="ct-stat" style="border-left:4px solid #3b82f6">
            <div class="ct-stat-icon blue"><i class="bi bi-file-earmark-text-fill"></i></div>
            <div>
                <div class="ct-stat-val"><?= (int)($kpi['activos'] ?? 0) ?></div>
                <div class="ct-stat-lbl">Contratos activos</div>
                <div style="font-size:.7rem;color:#1d4ed8;">MRR L <?= number_format((float)($kpi['monto_activo'] ?? 0), 0) ?></div>
            </div>
        </div>
        <div class="ct-stat" style="border-left:4px solid <?= $kAtr['n'] ? '#dc2626' : '#e2e8f0' ?>">
            <div class="ct-stat-icon <?= $kAtr['n'] ? 'red' : 'blue' ?>"><i class="bi bi-alarm-fill"></i></div>
            <div>
                <div class="ct-stat-val" style="<?= $kAtr['n'] ? 'color:#dc2626' : '' ?>"><?= $kAtr['n'] ?></div>
                <div class="ct-stat-lbl">Cobros atrasados</div>
                <?php if ($kAtr['n']): ?><div style="font-size:.7rem;color:#dc2626;">L <?= number_format($kAtr['monto'], 2) ?> sin cobrar</div><?php endif; ?>
            </div>
        </div>
        <div class="ct-stat" style="border-left:4px solid <?= $kCinco['n'] ? '#f97316' : '#e2e8f0' ?>">
            <div class="ct-stat-icon amber" style="<?= $kCinco['n'] ? 'background:#ffedd5;color:#c2410c' : '' ?>"><i class="bi bi-calendar-event-fill"></i></div>
            <div>
                <div class="ct-stat-val" style="<?= $kCinco['n'] ? 'color:#c2410c' : '' ?>"><?= $kCinco['n'] ?></div>
                <div class="ct-stat-lbl">Se cobran en 5 días</div>
                <?php if ($kCinco['n']): ?><div style="font-size:.7rem;color:#c2410c;">L <?= number_format($kCinco['monto'], 2) ?></div><?php endif; ?>
            </div>
        </div>
        <div class="ct-stat" style="border-left:4px solid <?= $pendientes_mes ? '#eab308' : '#e2e8f0' ?>">
            <div class="ct-stat-icon amber"><i class="bi bi-<?= $pendientes_mes > 0 ? 'clock-fill' : 'check-circle-fill' ?>"></i></div>
            <div>
                <div class="ct-stat-val" style="<?= $pendientes_mes ? 'color:#a16207' : '' ?>"><?= $pendientes_mes ?></div>
                <div class="ct-stat-lbl">Sin facturar este mes</div>
                <?php if ($pendientes_mes > 0): ?><div style="font-size:.7rem;color:#a16207;">L <?= number_format($monto_pendiente, 2) ?></div><?php endif; ?>
            </div>
        </div>
        <div class="ct-stat" style="border-left:4px solid <?= $kTermina ? '#eab308' : '#e2e8f0' ?>">
            <div class="ct-stat-icon amber"><i class="bi bi-hourglass-split"></i></div>
            <div>
                <div class="ct-stat-val"><?= $kTermina ?></div>
                <div class="ct-stat-lbl">Terminan en 30 días</div>
                <div style="font-size:.7rem;color:#64748b;"><?= (int)($kpi['vencidos'] ?? 0) ?> ya vencidos</div>
            </div>
        </div>
    </div>

    <!-- Próximos cobros (top 10 activos) -->
    <?php
    $proximos = array_filter($contratos, fn($c) => $c['estado'] === 'activo' && ($c['tipo_contrato'] !== 'proyecto' || !empty($c['plan']['prox'])));
    usort($proximos, fn($a, $b) => (int)$a['dias_para_pago'] - (int)$b['dias_para_pago']);
    // Prioridad: los que se cobran en 5 días o menos (y los atrasados); el resto con «Cargar más»
    $nPrioridad = count(array_filter($proximos, fn($c) => (int)$c['dias_para_pago'] <= 5));
    ?>
    <?php if (!empty($proximos)): ?>
        <div class="ct-card">
            <div class="ct-card-header">
                <span class="ct-card-title"><i class="bi bi-calendar-check-fill"></i> Próximas Fechas de Cobro —
                    <?= $mesesTitulo[(int)date('n')] . ' ' . date('Y') ?></span>
                <span class="d-flex align-items-center gap-2">
                    <?php if ($ctEsAdmin): ?><button type="button" class="btn btn-sm btn-outline-danger ct-eliminar-sel" disabled><i class="bi bi-trash me-1"></i>Eliminar contratos <span class="ct-sel-n"></span></button><?php endif; ?>
                    <span class="ct-result-badge"><?= $nPrioridad ?> en 5 días o menos · <?= count($proximos) ?> activos</span>
                </span>
            </div>
            <div class="ct-table-wrap">
                <table class="ct-table">
                    <thead>
                        <tr>
                            <?php if ($ctEsAdmin): ?><th class="ct-col-sel"></th><?php endif; ?>
                            <th>Cliente</th>
                            <th>Servicio</th>
                            <th class="text-end">Monto</th>
                            <th class="text-center">Próximo Cobro</th>
                            <th class="text-center">Días</th>
                            <th class="text-center">Este Mes</th>
                            <th style="cursor:default;text-align:center;">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($proximos as $p):
                            $dias       = (int)$p['dias_para_pago'];
                            $facturado  = (int)$p['facturado_este_mes'] > 0;
                            $noIniciado = (int)$p['no_iniciado'];
                            // Semáforo de días para el cobro: rojo atrasado u hoy, naranja ≤3, amarillo ≤5, gris el resto
                            if ($noIniciado) {
                                $dCls = 'dias-inicia';
                                $dTxt = "En {$dias}d";
                            } elseif ($dias < 0) {
                                $dCls = 'dias-atraso';
                                $dTxt = 'Atrasado ' . -$dias . 'd';
                            } elseif ($dias === 0) {
                                $dCls = 'dias-atraso';
                                $dTxt = '¡Hoy!';
                            } elseif ($dias <= 3) {
                                $dCls = 'dias-3';
                                $dTxt = "{$dias}d";
                            } elseif ($dias <= 5) {
                                $dCls = 'dias-5';
                                $dTxt = "{$dias}d";
                            } else {
                                $dCls = 'dias-resto';
                                $dTxt = "{$dias}d";
                            }
                        ?>
                            <tr class="<?= $facturado ? 'table-success' : '' ?> <?= $dias > 5 ? 'd-none ct-prox-mas' : '' ?>">
                                <?php if ($ctEsAdmin): ?><td class="ct-col-sel"><input type="checkbox" class="form-check-input ct-sel" value="<?= (int)$p['id'] ?>" aria-label="Seleccionar contrato #<?= (int)$p['id'] ?>"></td><?php endif; ?>
                                <td><?= $celdaCliente($p, false) ?></td>
                                <td class="small text-muted"><div class="ct-clamp" title="<?= htmlspecialchars($p['producto_nombre']) ?>"><?= htmlspecialchars($p['producto_nombre']) ?></div></td>
                                <td class="text-end fw-bold">L <?= number_format((float)($p['monto_proximo'] ?? $p['monto']), 2) ?></td>
                                <td class="text-center">
                                    <div class="fw-semibold small"><?= htmlspecialchars($p['proxima_fecha_pago']) ?></div>
                                    <small class="text-muted"><?= isset($p['plan']) ? 'Plan de pagos' : 'Día ' . (int)$p['dia_pago'] . ' c/mes' ?></small>
                                    <?php if ($noIniciado): ?><br><span class="badge bg-secondary small">Inicia
                                            <?= $p['fecha_inicio'] ?></span><?php endif; ?>
                                </td>
                                <td class="text-center"><span class="dias-pill <?= $dCls ?>"><?= $dTxt ?></span></td>
                                <td class="text-center">
                                    <?php if ($noIniciado): ?>
                                        <span class="badge bg-secondary small">No iniciado</span>
                                    <?php elseif (isset($p['plan'])): ?>
                                        <?= $p['plan']['venc_n'] ? '' : '<span class="fact-pill fact-si"><i class="bi bi-check-circle-fill"></i> Al día</span>' ?>
                                        <div class="small text-muted"><?= $p['plan']['pagados'] ?> de <?= $p['plan']['n'] ?> pagos del plan</div>
                                    <?php elseif ($facturado): ?>
                                        <span class="fact-pill fact-fac"><i class="bi bi-receipt"></i> Facturado</span>
                                    <?php else: ?>
                                        <span class="fact-pill fact-no"><i class="bi bi-clock-fill"></i> Pendiente</span>
                                    <?php endif; ?>
                                    <?= $celdaCobertura($p) ?>
                                </td>
                                <td class="text-center"><div class="ct-actions justify-content-center">
                                    <a href="facturas_contrato?contrato_id=<?= $p['id'] ?>" class="btn-fa btn-fa-receipt" title="Ver contrato y sus facturas"><i class="bi bi-eye"></i></a>
                                    <a href="editar_contrato?id=<?= $p['id'] ?>" class="btn-fa btn-fa-edit" title="Editar contrato"><i class="bi bi-pencil-fill"></i></a>
                                    <?php if ($facturado || $noIniciado): ?>
                                        <span class="btn-fa btn-fa-dis" title="<?= $noIniciado ? 'No iniciado' : 'Ya facturado este mes' ?>"><i class="bi bi-file-earmark-plus"></i></span>
                                    <?php else: ?>
                                        <?php if (($p['tipo_contrato'] ?? '') === 'sin_factura'): ?>
                                            <a href="generar_recibo?contrato_id=<?= $p['id'] ?>" class="btn-fa btn-fa-receipt">
                                                <i class="bi bi-receipt"></i> Recibo
                                            </a>
                                        <?php else: ?>
                                            <a href="generar_factura?receptor_id=<?= $p['receptor_id'] ?>&producto_id=<?= $p['producto_id'] ?>&monto=<?= $p['monto'] ?>&contrato_id=<?= $p['id'] ?>"
                                                class="btn-fa btn-fa-facturar">
                                                <i class="bi bi-file-earmark-plus"></i> Facturar
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$nPrioridad): ?>
                            <tr class="ct-prox-vacio"><td colspan="<?= $ctEsAdmin ? 8 : 7 ?>" class="text-center text-muted py-3">Ningún contrato se cobra en los próximos 5 días.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($proximos) > $nPrioridad): ?>
                <div class="text-center py-2 border-top">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="ctProxMas"><i class="bi bi-chevron-down me-1"></i>Cargar más (<?= count($proximos) - $nPrioridad ?>)</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="ctProxMenos"><i class="bi bi-chevron-up me-1"></i>Ver menos (solo prioridad)</button>
                </div>
                <script>
                    (() => {
                        const mas = document.getElementById('ctProxMas'), menos = document.getElementById('ctProxMenos');
                        const ver = todo => {
                            document.querySelectorAll('.ct-prox-mas').forEach(tr => tr.classList.toggle('d-none', !todo));
                            document.querySelectorAll('.ct-prox-vacio').forEach(tr => tr.classList.toggle('d-none', todo));
                            mas.classList.toggle('d-none', todo); menos.classList.toggle('d-none', !todo);
                        };
                        mas.addEventListener('click', () => ver(true));
                        menos.addEventListener('click', () => ver(false));
                    })();
                </script>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Todos los contratos -->
    <div class="ct-toolbar">
        <div class="ct-search-wrap">
            <i class="bi bi-search"></i>
            <input type="text" id="ctSearch" class="ct-search" placeholder="Buscar por cliente, servicio, estado…"
                autocomplete="off">
            <button class="ct-clear-btn" id="ctClear"><i class="bi bi-x-lg"></i></button>
        </div>
        <select class="ct-per-page" id="ctPerPage">
            <option value="10" selected>10/pág</option>
            <option value="25">25/pág</option>
            <option value="50">50/pág</option>
        </select>
        <span class="ct-result-badge" id="ctBadge"><?= $total_contratos ?> contratos</span>
        <?php if ($ctEsAdmin): ?>
            <button type="button" class="btn btn-sm btn-outline-danger ct-eliminar-sel" id="ctEliminarSel" disabled><i class="bi bi-trash me-1"></i>Eliminar contratos <span class="ct-sel-n"></span></button>
        <?php endif; ?>
    </div>

    <div class="ct-card">
        <div class="ct-card-header">
            <span class="ct-card-title"><i class="bi bi-table"></i> Todos los Contratos</span>
            <div class="ct-leyenda" title="Clic para filtrar">
                <?php foreach (['activo' => 'Activo', 'inicia' => 'No iniciado', 'por-vencer' => 'Por vencer (≤30 d)', 'vence-pronto' => 'Vence pronto (≤7 d)', 'vencido' => 'Vencido', 'pausado' => 'Pausado', 'cancelado' => 'Cancelado', 'borrador' => 'Borrador'] as $k => $t): ?>
                    <span class="sem-pill sem-<?= $k ?>" data-filtro="<?= ['activo' => 'activo', 'inicia' => 'no iniciado', 'por-vencer' => 'por vencer', 'vence-pronto' => 'vence pronto', 'vencido' => 'vencido', 'pausado' => 'pausado', 'cancelado' => 'cancelado', 'borrador' => 'borrador'][$k] ?>"><?= $t ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="ct-table-wrap">
            <table class="ct-table" id="ctTable">
                <thead>
                    <tr>
                        <?php if ($ctEsAdmin): ?><th class="ct-col-sel" style="cursor:default"><input type="checkbox" class="form-check-input" id="ctSelTodos" title="Marcar los de esta página"></th><?php endif; ?>
                        <th data-col="0"><i class="bi bi-person me-1"></i>Cliente<i
                                class="bi bi-arrow-up sort-icon"></i></th>
                        <th data-col="1"><i class="bi bi-box me-1"></i>Servicio<i class="bi bi-arrow-up sort-icon"></i>
                        </th>
                        <th data-col="2"><i class="bi bi-cash me-1"></i>Monto<i class="bi bi-arrow-up sort-icon"></i>
                        </th>
                        <th data-col="3" class="text-center">Este Mes</th>
                        <th data-col="4"><i class="bi bi-calendar3 me-1"></i>Próx. Cobro<i
                                class="bi bi-arrow-up sort-icon"></i></th>
                        <th data-col="5">Fecha Fin<i class="bi bi-arrow-up sort-icon"></i></th>
                        <th data-col="6">Estado<i class="bi bi-arrow-up sort-icon"></i></th>
                        <th style="cursor:default;">Acciones</th>
                    </tr>
                </thead>
                <tbody id="ctBody">
                    <?php foreach ($contratos as $c):
                        [$semK, $semTxt, $semBusca] = $ctSemaforo($c);
                        $rowCls = 'sem-' . $semK;
                        $facturado  = (int)($c['facturado_este_mes'] ?? 0) > 0;
                        $noIniciado = (int)($c['no_iniciado'] ?? 0);
                        $nFact      = (int)($c['total_facturas_contrato'] ?? 0);
                        $stateCls   = ['activo' => 'st-activo', 'vencido' => 'st-vencido', 'cancelado' => 'st-cancelado', 'pausado' => 'st-pausado'];
                        $diasPago   = isset($c['dias_para_pago']) ? (int)$c['dias_para_pago'] : null;
                        $searchStr  = mb_strtolower('#' . $c['id'] . ' ' . $c['receptor_nombre'] . ' ' . implode(' ', $rotEmpresas[(int)$c['id']] ?? []) . ' ' . $c['nombre_contrato'] . ' ' . $c['producto_nombre'] . ' ' . $c['estado'] . ' ' . $semBusca);
                    ?>
                        <tr class="<?= $rowCls ?>" data-search="<?= htmlspecialchars($searchStr) ?>">
                            <?php if ($ctEsAdmin): ?><td class="ct-col-sel"><input type="checkbox" class="form-check-input ct-sel" value="<?= (int)$c['id'] ?>" aria-label="Seleccionar contrato #<?= (int)$c['id'] ?>"></td><?php endif; ?>
                            <td><?= $celdaCliente($c, true) ?></td>
                            <td>
                                <div class="ct-clamp" data-col="servicio" title="<?= htmlspecialchars($c['producto_nombre']) ?>"><?= htmlspecialchars($c['producto_nombre']) ?></div>
                                <?php if ($nFact > 0): ?><small class="text-muted"><?= $nFact ?> factura(s) · L
                                        <?= number_format((float)$c['total_monto_contrato'], 2) ?></small><?php endif; ?>
                            </td>
                            <td data-sort-val="<?= $c['monto'] ?>"><strong>L
                                    <?= number_format((float)$c['monto'], 2) ?></strong></td>
                            <td class="text-center">
                                <?php if ($c['estado'] !== 'activo' || ($c['tipo_contrato'] === 'proyecto' && !isset($c['plan']))): ?>
                                    <span class="text-muted">—</span>
                                <?php elseif ($noIniciado): ?>
                                    <span class="badge bg-secondary small">No iniciado</span>
                                <?php elseif (isset($c['plan'])): ?>
                                    <?= $c['plan']['venc_n'] ? '' : '<span class="fact-pill fact-si"><i class="bi bi-check-circle-fill"></i> Al día</span>' ?>
                                    <div class="small text-muted"><?= $c['plan']['pagados'] ?> de <?= $c['plan']['n'] ?> pagos del plan</div>
                                <?php elseif ($facturado): ?>
                                    <span class="fact-pill fact-fac"><i class="bi bi-receipt"></i> Facturado</span>
                                <?php else: ?>
                                    <span class="fact-pill fact-no"><i class="bi bi-clock-fill"></i> Pendiente</span>
                                <?php endif; ?>
                                <?= $celdaCobertura($c) ?>
                            </td>
                            <td>
                                <?php if ($c['estado'] === 'activo' && $diasPago !== null && ($c['tipo_contrato'] !== 'proyecto' || !empty($c['plan']['prox']))): ?>
                                    <div class="fw-semibold small"><?= htmlspecialchars($c['proxima_fecha_pago']) ?></div>
                                    <?php if ($noIniciado): ?>
                                        <small class="text-muted">Primer cobro</small>
                                    <?php else:
                                        if ($diasPago < 0) {
                                            $t = 'Vencido hace ' . -$diasPago . 'd';
                                            $cls = 'text-danger fw-bold';
                                        } elseif ($diasPago === 0) {
                                            $t = '¡Hoy!';
                                            $cls = 'text-danger fw-bold';
                                        } elseif ($diasPago <= 3) {
                                            $t = "En {$diasPago}d ⚠";
                                            $cls = 'text-danger';
                                        } elseif ($diasPago <= 7) {
                                            $t = "En {$diasPago}d";
                                            $cls = 'text-warning fw-semibold';
                                        } else {
                                            $t = "En {$diasPago}d";
                                            $cls = 'text-muted';
                                        }
                                    ?><small class="<?= $cls ?>"><?= $t ?></small><?php endif; ?>
                                <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($c['fecha_fin']): ?>
                                    <?= htmlspecialchars($c['fecha_fin']) ?>
                                    <?php if (in_array($c['alerta'], ['critico', 'proximo']) && $c['dias_restantes'] >= 0): ?>
                                        <br><small
                                            class="<?= $c['alerta'] === 'critico' ? 'text-danger fw-bold' : 'text-warning fw-semibold' ?>">⏰
                                            <?= (int)$c['dias_restantes'] ?> día(s)</small>
                                    <?php endif; ?>
                                <?php else: ?><span class="badge bg-info text-white">Indefinido</span><?php endif; ?>
                            </td>
                            <td><span class="sem-pill sem-<?= $semK ?>"><?= htmlspecialchars($semTxt) ?></span></td>
                            <td>
                                <div class="ct-actions">
                                    <a href="facturas_contrato?contrato_id=<?= $c['id'] ?>" class="btn-fa btn-fa-receipt"
                                        title="Ver contrato y sus facturas (<?= $nFact ?>)">
                                        <i class="bi bi-eye"></i>
                                        <?php if ($nFact > 0): ?><span class="badge bg-info ms-1" style="font-size:.68rem;"><?= $nFact ?></span><?php endif; ?>
                                    </a>
                                    <a href="editar_contrato?id=<?= $c['id'] ?>" class="btn-fa btn-fa-edit" title="Editar">
                                        <i class="bi bi-pencil-fill"></i>
                                    </a>
                                    <?php if ($c['estado'] === 'activo'): ?>
                                        <?php if ($noIniciado || $facturado): ?>
                                            <span class="btn-fa btn-fa-dis"
                                                title="<?= $noIniciado ? 'No iniciado' : 'Ya facturado este mes' ?>"><i
                                                    class="bi bi-file-earmark-plus"></i></span>
                                        <?php else: ?>
                                            <?php if (($c['tipo_contrato'] ?? '') === 'sin_factura'): ?>
                                                <a href="generar_recibo?contrato_id=<?= $c['id'] ?>" class="btn-fa btn-fa-receipt"
                                                    title="Crear Recibo">
                                                    <i class="bi bi-receipt"></i>
                                                </a>
                                            <?php else: ?>
                                                <a href="generar_factura?receptor_id=<?= $c['receptor_id'] ?>&producto_id=<?= $c['producto_id'] ?>&monto=<?= $c['monto'] ?>&contrato_id=<?= $c['id'] ?>"
                                                    class="btn-fa btn-fa-facturar" title="Crear Factura">
                                                    <i class="bi bi-file-earmark-plus"></i>
                                                </a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if (!in_array($c['estado'], ['cancelado', 'vencido'])): ?>
                                        <button class="btn-fa btn-fa-cancel btn-cancelar" data-id="<?= $c['id'] ?>"
                                            data-nombre="<?= htmlspecialchars($c['receptor_nombre']) ?>" title="Cancelar">
                                            <i class="bi bi-ban"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="ct-empty" id="ctEmpty" style="display:none;">
                <div style="font-size:2.5rem;opacity:.3;margin-bottom:.7rem;"><i class="bi bi-file-earmark-x"></i></div>
                <div style="font-weight:600;">Sin contratos</div>
                <div id="ctEmptySub" style="font-size:.85rem;margin-top:.3rem;"></div>
            </div>
        </div>
        <div class="ct-pagination">
            <span class="ct-page-info" id="ctPageInfo"></span>
            <div class="ct-page-btns" id="ctPageBtns"></div>
        </div>
    </div>
</div>

<script>
    const CT_OFF = <?= $ctEsAdmin ? 1 : 0 ?>;
    /* ── Table engine ── */
    (() => {
        let query = '',
            page = 1,
            perPage = 10,
            sortCol = -1,
            sortDir = 'asc';
        const allRows = Array.from(document.querySelectorAll('#ctBody tr'));
        const $s = document.getElementById('ctSearch'),
            $cl = document.getElementById('ctClear'),
            $pp = document.getElementById('ctPerPage');
        const $empty = document.getElementById('ctEmpty'),
            $sub = document.getElementById('ctEmptySub');
        const $info = document.getElementById('ctPageInfo'),
            $btns = document.getElementById('ctPageBtns'),
            $badge = document.getElementById('ctBadge');
        const headers = document.querySelectorAll('#ctTable thead th[data-col]');

        function hl(t, q) {
            if (!q) return t;
            return t.replace(new RegExp(`(${q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')})`, `gi`),
                '<mark class="ct-highlight">$1</mark>');
        }

        function colTxt(r, i) {
            const td = r.querySelectorAll('td')[i + CT_OFF];   // CT_OFF: columna de casillas (administradores)
            return td ? (td.dataset.original || td.getAttribute('data-sort-val') || td.textContent).trim()
                .toLowerCase() : '';
        }

        function filtered() {
            const base = !query ? allRows : allRows.filter(r => r.dataset.search.includes(query.toLowerCase()));
            if (sortCol < 0) return base;
            return [...base].sort((a, b) => {
                const va = colTxt(a, sortCol),
                    vb = colTxt(b, sortCol);
                return sortDir === 'asc' ? va.localeCompare(vb, 'es') : vb.localeCompare(va, 'es');
            });
        }

        function updIcons() {
            headers.forEach(th => {
                const i = parseInt(th.dataset.col);
                th.classList.remove('sort-asc', 'sort-desc');
                if (i === sortCol) th.classList.add(sortDir === 'asc' ? 'sort-asc' : 'sort-desc');
            });
        }

        function render() {
            const rows = filtered(),
                total = rows.length,
                totPg = Math.max(1, Math.ceil(total / perPage));
            if (page > totPg) page = totPg;
            const s = (page - 1) * perPage,
                e = Math.min(s + perPage, total);
            allRows.forEach(r => r.style.display = 'none');
            rows.forEach(r => r.parentNode && r.parentNode.appendChild(r));   // el orden elegido se ve en la tabla (antes solo se calculaba)
            if (total === 0) {
                $empty.style.display = 'block';
                $sub.textContent = query ? `Sin resultados para "${query}".` : 'No hay contratos.';
            } else {
                $empty.style.display = 'none';
                rows.slice(s, e).forEach(r => {
                    r.style.display = '';
                    r.querySelectorAll('[data-col]').forEach(c => {
                        const o = c.dataset.original ?? c.textContent;
                        c.dataset.original = o;
                        c.innerHTML = hl(o, query);
                    });
                });
            }
            $badge.textContent = `${total} contrato${total!==1?'s':''}`;
            $info.textContent = total === 0 ? 'Sin resultados' : `Mostrando ${s+1}–${e} de ${total}`;
            buildPg(page, totPg);
        }

        function buildPg(cur, tot) {
            $btns.innerHTML = '';
            if (tot <= 1) return;
            const mk = (html, p, cls = '') => {
                const b = document.createElement('button');
                b.className = `page-btn ${cls}`;
                b.innerHTML = html;
                if (!cls.includes('disabled') && !cls.includes('active')) b.addEventListener('click', () => {
                    page = p;
                    render();
                });
                $btns.appendChild(b);
            };
            mk('<i class="bi bi-chevron-double-left"></i>', 1, cur === 1 ? 'disabled' : '');
            mk('<i class="bi bi-chevron-left"></i>', cur - 1, cur === 1 ? 'disabled' : '');
            let pages = new Set([1, tot]);
            for (let i = Math.max(2, cur - 2); i <= Math.min(tot - 1, cur + 2); i++) pages.add(i);
            pages = [...pages].sort((a, b) => a - b);
            let prev = 0;
            pages.forEach(pg => {
                if (pg - prev > 1) {
                    const d = document.createElement('button');
                    d.className = 'page-btn disabled';
                    d.textContent = '…';
                    $btns.appendChild(d);
                }
                mk(pg, pg, pg === cur ? 'active' : '');
                prev = pg;
            });
            mk('<i class="bi bi-chevron-right"></i>', cur + 1, cur === tot ? 'disabled' : '');
            mk('<i class="bi bi-chevron-double-right"></i>', tot, cur === tot ? 'disabled' : '');
        }
        headers.forEach(th => th.addEventListener('click', () => {
            const i = parseInt(th.dataset.col);
            sortDir = (sortCol === i && sortDir === 'asc') ? 'desc' : 'asc';
            sortCol = i;
            page = 1;
            updIcons();
            render();
        }));
        let deb;
        $s.addEventListener('input', () => {
            clearTimeout(deb);
            deb = setTimeout(() => {
                query = $s.value.trim();
                page = 1;
                $cl.classList.toggle('visible', query.length > 0);
                render();
            }, 180);
        });
        $cl.addEventListener('click', () => {
            $s.value = '';
            query = '';
            page = 1;
            $cl.classList.remove('visible');
            render();
            $s.focus();
        });
        $pp.addEventListener('change', () => {
            perPage = parseInt($pp.value);
            page = 1;
            render();
        });
        updIcons();
        render();
    })();

    /* ── Leyenda del semáforo: clic filtra la tabla (otro clic quita el filtro) ── */
    document.querySelectorAll('.ct-leyenda [data-filtro]').forEach(p => p.addEventListener('click', () => {
        const s = document.getElementById('ctSearch'), on = p.classList.contains('on');
        document.querySelectorAll('.ct-leyenda .on').forEach(x => x.classList.remove('on'));
        if (!on) p.classList.add('on');
        s.value = on ? '' : p.dataset.filtro;
        s.dispatchEvent(new Event('input'));
    }));

    /* ── Eliminar contratos seleccionados ── */
    (() => {
        const botones = document.querySelectorAll('.ct-eliminar-sel');
        if (!botones.length) return;
        // El mismo contrato puede estar en «Próximas fechas» y en «Todos»: se marca en las dos tablas a la vez
        const marcados = () => [...new Set([...document.querySelectorAll('.ct-sel:checked')].map(i => +i.value))];
        const actualizar = () => { const n = marcados().length; botones.forEach(b => { b.disabled = !n; b.querySelector('.ct-sel-n').textContent = n ? '(' + n + ')' : ''; }); };
        document.addEventListener('change', e => {
            if (!e.target.classList.contains('ct-sel')) return;
            document.querySelectorAll('.ct-sel[value="' + e.target.value + '"]').forEach(i => i.checked = e.target.checked);
            actualizar();
        });
        document.getElementById('ctSelTodos').addEventListener('change', e => {
            document.querySelectorAll('#ctBody tr').forEach(tr => { if (tr.style.display !== 'none') document.querySelectorAll('.ct-sel[value="' + tr.querySelector('.ct-sel').value + '"]').forEach(i => i.checked = e.target.checked); });
            actualizar();
        });
        botones.forEach(b => b.addEventListener('click', () => eliminarContratos(marcados())));
    })();

    /* ── Cancelar contrato ── */
    document.querySelectorAll('.btn-cancelar').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.id,
                nombre = btn.dataset.nombre;
            Swal.fire({
                title: '¿Cancelar contrato?',
                html: `Se cancelará el contrato de <strong>${nombre}</strong>.<br><span class="text-danger small">Esta acción no se puede deshacer.</span>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                confirmButtonText: '<i class="bi bi-ban me-1"></i>Sí, cancelar',
                cancelButtonText: 'No, volver',
                reverseButtons: true
            }).then(r => {
                if (!r.isConfirmed) return;
                const fd = new FormData();
                fd.append('id', id);
                fetch('includes/contrato_cancelar.php', {
                        method: 'POST',
                        body: fd
                    })
                    .then(res => res.json())
                    .then(d => {
                        if (d.ok) Swal.fire({
                            icon: 'success',
                            title: 'Cancelado',
                            timer: 1400,
                            showConfirmButton: false
                        }).then(() => location.reload());
                        else Swal.fire('Error', d.msg || 'No se pudo cancelar.', 'error');
                    });
            });
        });
    });
</script>

<script src="../../clientes/js/contratos-eliminar.js?v=<?= @filemtime(__DIR__ . '/../js/contratos-eliminar.js') ?>"></script>
<?php require_once '../../includes/templates/footer.php'; ?>