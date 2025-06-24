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
	// Obtener info del rango CAI asegurando que pertenezca al cliente, establecimiento y punto de emisión
	$stmt = $pdo->prepare("
        SELECT correlativo_actual, rango_cai_inicio, rango_fin
        FROM cai_rangos
        WHERE id = ? AND cliente_id = ? AND establecimiento_id = ? AND punto_emision_id = ?
        FOR UPDATE
    ");
	$stmt->execute([$cai_id, $cliente_id, $establecimiento_id, $punto_emision_id]);
	$cai = $stmt->fetch(PDO::FETCH_ASSOC);

	if (!$cai) {
		throw new Exception("Rango CAI no válido o no pertenece al cliente/establecimiento.");
	}

	$correlativo_actual = (int)$cai['correlativo_actual'];
	$nuevo_correlativo = $correlativo_actual + 1;

	if ($nuevo_correlativo > (int)$cai['rango_fin']) {
		throw new Exception("Se ha alcanzado el límite del rango CAI.");
	}

	// Separar el rango y reemplazar el último bloque
	$partes = explode('-', $cai['rango_cai_inicio']);
	if (count($partes) < 2) {
		throw new Exception("Formato de rango_cai_inicio inválido.");
	}

	$nuevo_bloque = str_pad($nuevo_correlativo, 8, '0', STR_PAD_LEFT);
	$partes[count($partes) - 1] = $nuevo_bloque;

	$correlativo_formateado = implode('-', $partes);

	// Actualizar el correlativo_actual en la tabla
	$stmtUpdate = $pdo->prepare("UPDATE cai_rangos SET correlativo_actual = ? WHERE id = ?");
	$stmtUpdate->execute([$nuevo_correlativo, $cai_id]);

	return $correlativo_formateado;
}


function numeroALetras($numero)
{
	$unidades = [
		'',
		'uno',
		'dos',
		'tres',
		'cuatro',
		'cinco',
		'seis',
		'siete',
		'ocho',
		'nueve',
		'diez',
		'once',
		'doce',
		'trece',
		'catorce',
		'quince',
		'dieciséis',
		'diecisiete',
		'dieciocho',
		'diecinueve',
		'veinte'
	];

	$decenas = [
		'',
		'',
		'veinte',
		'treinta',
		'cuarenta',
		'cincuenta',
		'sesenta',
		'setenta',
		'ochenta',
		'noventa'
	];

	$centenas = [
		'',
		'ciento',
		'doscientos',
		'trescientos',
		'cuatrocientos',
		'quinientos',
		'seiscientos',
		'setecientos',
		'ochocientos',
		'novecientos'
	];

	if ($numero == 0) {
		return 'Cero lempiras exactos';
	}

	$num = floor($numero);
	$centavos = round(($numero - $num) * 100);

	$resultado = '';

	if ($num == 100) {
		$resultado = 'cien';
	} else {
		if ($num >= 1000000) {
			$millones = floor($num / 1000000);
			$resultado .= ($millones == 1 ? 'un millón ' : numeroALetras($millones) . ' millones ');
			$num %= 1000000;
		}

		if ($num >= 1000) {
			$miles = floor($num / 1000);
			if ($miles == 1) {
				$resultado .= 'mil ';
			} else {
				$resultado .= numeroALetras($miles) . ' mil ';
			}
			$num %= 1000;
		}

		if ($num >= 100) {
			$resultado .= $centenas[floor($num / 100)] . ' ';
			$num %= 100;
		}

		if ($num > 20) {
			$resultado .= $decenas[floor($num / 10)];
			if ($num % 10 > 0) {
				$resultado .= ' y ' . $unidades[$num % 10];
			}
		} else {
			$resultado .= $unidades[$num];
		}
	}

	$resultado = ucfirst(trim($resultado)) . ' lempiras';

	if ($centavos > 0) {
		$resultado .= " con " . str_pad($centavos, 2, '0', STR_PAD_LEFT) . "/100 centavos";
	} else {
		$resultado .= " exactos";
	}

	return $resultado;
}
