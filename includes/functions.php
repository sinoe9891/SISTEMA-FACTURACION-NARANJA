<?php
function convertirNumeroALetras($numero)
{
	$formatter = new \NumberFormatter("es", \NumberFormatter::SPELLOUT);
	$letras = ucfirst($formatter->format($numero));
	return $letras . ' Lempiras';
};
function formatoCorrelativoCAI($cai, $numero)
{
	$numFormateado = str_pad($numero, 8, '0', STR_PAD_LEFT);
	return "{$cai}-{$numFormateado}";
}
function generarCorrelativoFactura(PDO $pdo, int $cai_id, int $cliente_id, int $establecimiento_id, int $punto_emision_id): string
{
	$stmt = $pdo->prepare("
        SELECT correlativo_actual, rango_inicio, rango_cai_inicio, rango_fin
        FROM cai_rangos
        WHERE id = ? AND cliente_id = ? AND establecimiento_id = ? AND punto_emision_id = ?
        FOR UPDATE
    ");
	$stmt->execute([$cai_id, $cliente_id, $establecimiento_id, $punto_emision_id]);
	$cai = $stmt->fetch(PDO::FETCH_ASSOC);

	if (!$cai) {
		throw new Exception("Rango CAI no válido o no pertenece al cliente/establecimiento.");
	}

	$rango_inicio = (int)$cai['rango_inicio'];
	$rango_fin = (int)$cai['rango_fin'];
	$correlativo_actual = (int)$cai['correlativo_actual'];

	// Calcular correlativo real sumando base + desplazamiento actual
	$correlativo_real = $rango_inicio + $correlativo_actual;

	if ($correlativo_real > $rango_fin) {
		throw new Exception("Se ha alcanzado el límite del rango CAI.");
	}

	// Generar correlativo formateado
	$partes = explode('-', $cai['rango_cai_inicio']);
	if (count($partes) < 4) {
		throw new Exception("Formato de rango_cai_inicio inválido.");
	}

	$partes[count($partes) - 1] = str_pad($correlativo_real, 8, '0', STR_PAD_LEFT);
	$correlativo_formateado = implode('-', $partes);

	// Actualizar correlativo_actual
	$stmtUpdate = $pdo->prepare("UPDATE cai_rangos SET correlativo_actual = ? WHERE id = ?");
	$stmtUpdate->execute([$correlativo_actual + 1, $cai_id]);

	return $correlativo_formateado;
}



function numeroALetras($numero)
{
	if ($numero == 0) {
		return 'Cero lempiras exactos';
	}

	$num = floor($numero);
	$centavos = round(($numero - $num) * 100);

	$letras = convertirNumeroLetrasBasico($num);

	// Corrección para "uno" → "un" antes de "lempiras"
	if (preg_match('/(veintiuno|treinta y uno|cuarenta y uno|cincuenta y uno|sesenta y uno|setenta y uno|ochenta y uno|noventa y uno)$/', $letras)) {
		$letras = preg_replace('/uno$/', 'ún', $letras);
	}

	$letras = ucfirst(trim($letras)) . ' lempiras';

	if ($centavos > 0) {
		$letras .= " con " . str_pad($centavos, 2, '0', STR_PAD_LEFT) . "/100 centavos";
	} else {
		$letras .= " exactos";
	}

	return $letras;
}

function convertirNumeroLetrasBasico($num)
{
	$unidades = ['', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve', 'veinte'];
	$decenas = ['', '', 'veinte', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa'];
	$centenas = ['', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos', 'seiscientos', 'setecientos', 'ochocientos', 'novecientos'];

	$resultado = '';

	if ($num == 100) {
		return 'cien';
	}

	if ($num >= 1000000) {
		$millones = floor($num / 1000000);
		$resultado .= ($millones == 1 ? 'un millón' : convertirNumeroLetrasBasico($millones) . ' millones');
		$num %= 1000000;
		if ($num > 0) $resultado .= ' ';
	}

	if ($num >= 1000) {
		$miles = floor($num / 1000);
		if ($miles == 1) {
			$resultado .= 'mil';
		} else {
			$resultado .= convertirNumeroLetrasBasico($miles) . ' mil';
		}
		$num %= 1000;
		if ($num > 0) $resultado .= ' ';
	}

	if ($num >= 100) {
		$resultado .= $centenas[floor($num / 100)];
		$num %= 100;
		if ($num > 0) $resultado .= ' ';
	}

	if ($num > 20 && $num < 30) {
		$resultado .= 'veinti' . $unidades[$num % 10];
	} elseif ($num >= 30) {
		$resultado .= $decenas[floor($num / 10)];
		if ($num % 10 > 0) {
			$resultado .= ' y ' . $unidades[$num % 10];
		}
	} elseif ($num > 0) {
		$resultado .= $unidades[$num];
	}

	return trim($resultado);
}

function traducirMeses(array $lista_meses_en): array
{
	$traducciones = [
		'January' => 'Enero',
		'February' => 'Febrero',
		'March' => 'Marzo',
		'April' => 'Abril',
		'May' => 'Mayo',
		'June' => 'Junio',
		'July' => 'Julio',
		'August' => 'Agosto',
		'September' => 'Septiembre',
		'October' => 'Octubre',
		'November' => 'Noviembre',
		'December' => 'Diciembre'
	];

	return array_map(function ($mes) use ($traducciones) {
		return strtr($mes, $traducciones);
	}, $lista_meses_en);
}

/**
 * Valida un rango CAI antes de guardarlo (crear o editar).
 * - rango_cai_inicio / rango_cai_fin con formato 000-000-00-00000000 y el mismo prefijo
 * - sus números coinciden con rango_inicio / rango_fin
 * - no se traslapa con otro CAI del mismo cliente y prefijo (establecimiento-punto-tipo):
 *   dos CAI con números en común generarían facturas con el mismo número fiscal.
 */
function validarRangoCai(PDO $pdo, int $cliente_id, int $rango_inicio, int $rango_fin, string $rango_cai_inicio, string $rango_cai_fin, ?int $excluir_id = null): void
{
	if (!preg_match('/^(\d{3}-\d{3}-\d{2})-(\d{8})$/', $rango_cai_inicio, $a))
		throw new Exception("Formato del rango CAI inicial inválido (ejemplo: 000-001-01-00000001).");
	if (!preg_match('/^(\d{3}-\d{3}-\d{2})-(\d{8})$/', $rango_cai_fin, $b))
		throw new Exception("Formato del rango CAI final inválido (ejemplo: 000-001-01-00000100).");
	if ($a[1] !== $b[1])
		throw new Exception("El rango CAI inicial y final deben tener el mismo prefijo ({$a[1]} ≠ {$b[1]}).");
	if ((int)$a[2] !== $rango_inicio || (int)$b[2] !== $rango_fin)
		throw new Exception("Los números del rango CAI ({$a[2]} – {$b[2]}) no coinciden con el rango inicio/fin ($rango_inicio – $rango_fin).");

	$stmt = $pdo->prepare("
		SELECT cai, rango_inicio, rango_fin FROM cai_rangos
		WHERE cliente_id = ? AND LEFT(rango_cai_inicio, 10) = ?
		  AND rango_inicio <= ? AND rango_fin >= ? AND id <> ?
		LIMIT 1
	");
	$stmt->execute([$cliente_id, $a[1], $rango_fin, $rango_inicio, (int)$excluir_id]);
	if ($otro = $stmt->fetch(PDO::FETCH_ASSOC))
		throw new Exception("El rango se traslapa con el CAI {$otro['cai']} ({$a[1]}: {$otro['rango_inicio']} – {$otro['rango_fin']}).");
}
