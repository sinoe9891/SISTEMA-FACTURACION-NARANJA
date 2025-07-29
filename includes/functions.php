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
