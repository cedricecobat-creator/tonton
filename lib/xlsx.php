<?php
declare(strict_types=1);

/**
 * Générateur XLSX minimal (sans bibliothèque externe) : plusieurs feuilles,
 * styles fixes, largeurs de colonnes, cellules fusionnées, formules simples.
 */
final class Xlsx
{
    // Index des styles (voir styles())
    public const NORMAL = 0, GRAS = 1, DATE = 2, EURO = 3, TITRE = 4, ENTETE = 5,
        EURO_GRAS = 6, COEF = 7, GRISE = 8, DATE_GRISE = 9, KM = 10, TEXTE_GRIS = 11;

    private array $feuilles = [];

    public function feuille(string $nom): int
    {
        $nom = mb_substr(preg_replace('#[\\\\/?*\[\]:]#', '', $nom), 0, 31);
        $this->feuilles[] = ['nom' => $nom, 'cells' => [], 'cols' => [], 'merges' => [], 'hauteurs' => []];
        return count($this->feuilles) - 1;
    }

    /** $val : string, int|float, ['f' => 'SUM(A1:A3)', 'v' => valeur] ; $col 1 = A */
    public function set(int $f, int $ligne, int $col, $val, int $style = self::NORMAL): void
    {
        $this->feuilles[$f]['cells'][$ligne][$col] = [$val, $style];
    }

    public function largeur(int $f, int $col, float $w): void
    {
        $this->feuilles[$f]['cols'][$col] = $w;
    }

    public function hauteur(int $f, int $ligne, float $h): void
    {
        $this->feuilles[$f]['hauteurs'][$ligne] = $h;
    }

    public function fusion(int $f, string $plage): void
    {
        $this->feuilles[$f]['merges'][] = $plage;
    }

    public static function col(int $c): string
    {
        $s = '';
        while ($c > 0) {
            $m = ($c - 1) % 26;
            $s = chr(65 + $m) . $s;
            $c = intdiv($c - 1, 26);
        }
        return $s;
    }

    public static function serial(string $ymd): int
    {
        return (int)((strtotime($ymd . ' 00:00:00 UTC') / 86400) + 25569);
    }

    private static function x(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    public function contenu(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);

        $n = count($this->feuilles);
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
        for ($i = 1; $i <= $n; $i++) {
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $zip->addFromString('[Content_Types].xml', $ct . '</Types>');

        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '</Relationships>');

        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
            . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>Tonton</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created>'
            . '</cp:coreProperties>');

        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($this->feuilles as $i => $f) {
            $k = $i + 1;
            $wb .= '<sheet name="' . self::x($f['nom']) . '" sheetId="' . $k . '" r:id="rId' . $k . '"/>';
            $rels .= '<Relationship Id="rId' . $k . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $k . '.xml"/>';
            $zip->addFromString('xl/worksheets/sheet' . $k . '.xml', $this->sheetXml($f));
        }
        $rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $zip->addFromString('xl/workbook.xml', $wb . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels . '</Relationships>');
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->close();

        $bin = (string)file_get_contents($tmp);
        @unlink($tmp);
        return $bin;
    }

    private function sheetXml(array $f): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            . '<sheetViews><sheetView workbookViewId="0" zoomScale="100"/></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>';
        if ($f['cols']) {
            ksort($f['cols']);
            $x .= '<cols>';
            foreach ($f['cols'] as $c => $w) {
                $x .= '<col min="' . $c . '" max="' . $c . '" width="' . $w . '" customWidth="1"/>';
            }
            $x .= '</cols>';
        }
        $x .= '<sheetData>';
        ksort($f['cells']);
        foreach ($f['cells'] as $l => $cols) {
            ksort($cols);
            $ht = isset($f['hauteurs'][$l]) ? ' ht="' . $f['hauteurs'][$l] . '" customHeight="1"' : '';
            $x .= '<row r="' . $l . '"' . $ht . '>';
            foreach ($cols as $c => [$v, $s]) {
                $ref = self::col($c) . $l;
                $st = $s ? ' s="' . $s . '"' : '';
                if (is_array($v)) {
                    $x .= '<c r="' . $ref . '"' . $st . '><f>' . self::x($v['f']) . '</f>'
                        . (isset($v['v']) ? '<v>' . $v['v'] . '</v>' : '') . '</c>';
                } elseif ($v === null || $v === '') {
                    $x .= '<c r="' . $ref . '"' . $st . '/>';
                } elseif (is_int($v) || is_float($v)) {
                    $x .= '<c r="' . $ref . '"' . $st . '><v>' . (is_float($v) ? round($v, 6) : $v) . '</v></c>';
                } else {
                    $x .= '<c r="' . $ref . '"' . $st . ' t="inlineStr"><is><t xml:space="preserve">' . self::x((string)$v) . '</t></is></c>';
                }
            }
            $x .= '</row>';
        }
        $x .= '</sheetData>';
        if ($f['merges']) {
            $x .= '<mergeCells count="' . count($f['merges']) . '">';
            foreach ($f['merges'] as $m) {
                $x .= '<mergeCell ref="' . $m . '"/>';
            }
            $x .= '</mergeCells>';
        }
        $x .= '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>'
            . '<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="1"/>';
        return $x . '</worksheet>';
    }

    private function styles(): string
    {
        // Formats : 164 date, 165 euros, 166 coef, 167 km
        $numFmts = '<numFmts count="4">'
            . '<numFmt numFmtId="164" formatCode="ddd dd/mm/yyyy"/>'
            . '<numFmt numFmtId="165" formatCode="#,##0.00 &quot;€&quot;;-#,##0.00 &quot;€&quot;;&quot;&quot;"/>'
            . '<numFmt numFmtId="166" formatCode="0.000"/>'
            . '<numFmt numFmtId="167" formatCode="#,##0;-#,##0;&quot;&quot;"/>'
            . '</numFmts>';
        $fonts = '<fonts count="4">'
            . '<font><sz val="10"/><name val="Arial"/></font>'
            . '<font><b/><sz val="10"/><name val="Arial"/></font>'
            . '<font><b/><sz val="13"/><name val="Arial"/></font>'
            . '<font><sz val="9"/><color rgb="FF777777"/><name val="Arial"/></font>'
            . '</fonts>';
        $fills = '<fills count="4">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFDDE5EE"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF1F1F1"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>';
        $borders = '<borders count="3">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FFB0B0B0"/></left><right style="thin"><color rgb="FFB0B0B0"/></right>'
            . '<top style="thin"><color rgb="FFB0B0B0"/></top><bottom style="thin"><color rgb="FFB0B0B0"/></bottom><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FFB0B0B0"/></left><right style="thin"><color rgb="FFB0B0B0"/></right>'
            . '<top style="medium"/><bottom style="medium"/><diagonal/></border>'
            . '</borders>';
        $xf = fn(int $num, int $font, int $fill, int $border, string $align = '') =>
            '<xf numFmtId="' . $num . '" fontId="' . $font . '" fillId="' . $fill . '" borderId="' . $border . '"'
            . ($num ? ' applyNumberFormat="1"' : '') . ($font ? ' applyFont="1"' : '') . ($fill ? ' applyFill="1"' : '')
            . ($border ? ' applyBorder="1"' : '') . ($align ? ' applyAlignment="1"><alignment ' . $align . '/></xf>' : '/>');
        $cellXfs = [
            self::NORMAL     => $xf(0, 0, 0, 0),
            self::GRAS       => $xf(0, 1, 0, 0),
            self::DATE       => $xf(164, 0, 0, 1, 'horizontal="left"'),
            self::EURO       => $xf(165, 0, 0, 1),
            self::TITRE      => $xf(0, 2, 0, 0),
            self::ENTETE     => $xf(0, 1, 2, 1, 'horizontal="center" vertical="center" wrapText="1"'),
            self::EURO_GRAS  => $xf(165, 1, 0, 2),
            self::COEF       => $xf(166, 0, 0, 1),
            self::GRISE      => $xf(0, 0, 3, 1, 'wrapText="1" vertical="top"'),
            self::DATE_GRISE => $xf(164, 0, 3, 1, 'horizontal="left"'),
            self::KM         => $xf(167, 0, 0, 1),
            self::TEXTE_GRIS => $xf(0, 3, 0, 0),
        ];
        // style 12 : texte encadré (objet / lieu)
        $cellXfs[12] = $xf(0, 0, 0, 1, 'wrapText="1" vertical="top"');
        // style 13 : libellé gras encadré (ligne de total)
        $cellXfs[13] = $xf(0, 1, 0, 2);
        ksort($cellXfs);
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . $numFmts . $fonts . $fills . $borders
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($cellXfs) . '">' . implode('', $cellXfs) . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }
}
