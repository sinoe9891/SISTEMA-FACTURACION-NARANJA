<?php
// includes/xlsx.php — Generador mínimo de .xlsx (una hoja) en PHP puro.
// No depende de ZipArchive ni de Composer: arma el ZIP sin compresión.
//
// Uso:
//   $x = new XlsxSimple('Facturas');
//   $x->columnas([['Correlativo', 22], ['Total', 14, 'dinero']]);   // [título, ancho, formato?]
//   $x->fila(['000-001-01-00000001', 1500.5]);
//   $x->fila(['TOTAL', 1500.5], true);                              // fila en negrita
//   $x->descargar('facturas.xlsx');
//
// Formatos: 'texto' (por defecto), 'dinero' (#,##0.00), 'entero', 'fecha' (dd/mm/yyyy, recibe 'Y-m-d' o 'Y-m-d H:i:s').
// Los textos con saltos de línea se muestran ajustados dentro de la celda.

class XlsxSimple
{
    private array $cols = [];
    private array $filas = [];

    public function __construct(private string $hoja = 'Hoja1') {}

    public function columnas(array $cols): void
    {
        $this->cols = array_map(fn($c) => ['titulo' => $c[0], 'ancho' => $c[1] ?? 15, 'fmt' => $c[2] ?? 'texto'], $cols);
    }

    public function fila(array $valores, bool $negrita = false): void
    {
        $this->filas[] = [$valores, $negrita];
    }

    // Estilos (índices de cellXfs): 0 normal, 1 encabezado, 2 dinero, 3 entero, 4 fecha, 5 texto ajustado,
    // 6 negrita, 7 dinero negrita
    private function estilo(string $fmt, bool $negrita, bool $multilinea): int
    {
        if ($negrita) return $fmt === 'dinero' ? 7 : 6;
        return match ($fmt) { 'dinero' => 2, 'entero' => 3, 'fecha' => 4, default => $multilinea ? 5 : 0 };
    }

    private static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
        return $s;
    }

    private static function esc(string $s): string
    {
        // Quita caracteres de control que XML no admite (deja tab y salto de línea)
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function serialFecha(string $v): ?float
    {
        $t = strtotime($v);
        if ($t === false) return null;
        // Días desde 1899-12-30 (fecha base de Excel); solo la parte de la fecha
        return (float)floor((strtotime(date('Y-m-d', $t) . ' UTC') - strtotime('1899-12-30 UTC')) / 86400);
    }

    private function hojaXml(): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<cols>';
        foreach ($this->cols as $i => $c) $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float)$c['ancho'] . '" customWidth="1"/>';
        $x .= '</cols><sheetData>';

        $x .= '<row r="1">';
        foreach ($this->cols as $i => $c) $x .= '<c r="' . self::col($i) . '1" t="inlineStr" s="1"><is><t>' . self::esc($c['titulo']) . '</t></is></c>';
        $x .= '</row>';

        foreach ($this->filas as $n => [$valores, $negrita]) {
            $r = $n + 2;
            $x .= '<row r="' . $r . '">';
            foreach (array_values($valores) as $i => $v) {
                if ($v === null || $v === '') continue;
                $fmt = $this->cols[$i]['fmt'] ?? 'texto';
                $ref = self::col($i) . $r;
                if (in_array($fmt, ['dinero', 'entero'], true) && is_numeric($v)) {
                    $x .= '<c r="' . $ref . '" s="' . $this->estilo($fmt, $negrita, false) . '"><v>' . (0 + $v) . '</v></c>';
                } elseif ($fmt === 'fecha' && ($serial = self::serialFecha((string)$v)) !== null) {
                    $x .= '<c r="' . $ref . '" s="' . $this->estilo('fecha', $negrita, false) . '"><v>' . $serial . '</v></c>';
                } else {
                    $v = (string)$v;
                    $x .= '<c r="' . $ref . '" t="inlineStr" s="' . $this->estilo('texto', $negrita, str_contains($v, "\n")) . '"><is><t xml:space="preserve">' . self::esc($v) . '</t></is></c>';
                }
            }
            $x .= '</row>';
        }
        $ultima = self::col(max(count($this->cols) - 1, 0));
        return $x . '</sheetData><autoFilter ref="A1:' . $ultima . (count($this->filas) + 1) . '"/></worksheet>';
    }

    public function contenido(): string
    {
        $hoja = self::esc(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', '', $this->hoja), 0, 31));
        $archivos = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $hoja . '" sheetId="1" r:id="rId1"/></sheets><definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\'' . $hoja . '\'!$A$1:$' . self::col(max(count($this->cols) - 1, 0)) . '$' . (count($this->filas) + 1) . '</definedName></definedNames></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<numFmts count="2"><numFmt numFmtId="164" formatCode="#,##0.00"/><numFmt numFmtId="165" formatCode="dd/mm/yyyy"/></numFmts>'
                . '<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
                . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF2563EB"/><bgColor indexed="64"/></patternFill></fill></fills>'
                . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
                . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                . '<cellXfs count="8">'
                . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top"/></xf>'
                . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
                . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment vertical="top"/></xf>'
                . '<xf numFmtId="1" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment vertical="top"/></xf>'
                . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment vertical="top"/></xf>'
                . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
                . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
                . '<xf numFmtId="164" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyNumberFormat="1"/>'
                . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
            'xl/worksheets/sheet1.xml' => $this->hojaXml(),
        ];
        return self::zip($archivos);
    }

    public function descargar(string $nombre): void
    {
        $bin = $this->contenido();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $nombre) . '"');
        header('Content-Length: ' . strlen($bin));
        header('Cache-Control: no-store');
        echo $bin;
    }

    // ZIP sin compresión (método "stored"); suficiente para un .xlsx
    private static function zip(array $archivos): string
    {
        $datos = $central = '';
        $n = 0;
        [$hora, $fecha] = self::fechaDos();
        foreach ($archivos as $nombre => $contenido) {
            $crc = crc32($contenido);
            $len = strlen($contenido);
            $cab = pack('vvvvvVVVvv', 20, 0x0800, 0, $hora, $fecha, $crc, $len, $len, strlen($nombre), 0);
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $hora, $fecha, $crc, $len, $len, strlen($nombre), 0, 0, 0, 0, 0, strlen($datos)) . $nombre;
            $datos .= pack('V', 0x04034b50) . $cab . $nombre . $contenido;
            $n++;
        }
        return $datos . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($central), strlen($datos), 0);
    }

    private static function fechaDos(): array
    {
        $t = getdate();
        return [($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2),
                (($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday']];
    }
}
