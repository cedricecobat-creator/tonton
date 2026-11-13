<?php
declare(strict_types=1);

/**
 * Générateur PDF minimal (sans bibliothèque externe) : pages A4, polices
 * Helvetica standard (accents français via WinAnsi), traits, rectangles
 * et images JPEG. Unités en millimètres, origine en haut à gauche.
 */
final class Pdf
{
    private const K = 72 / 25.4; // mm -> points

    private const WIDTHS = [
        'Helvetica'=>[761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,761,556,0,222,556,333,1000,556,556,333,1000,667,333,1000,0,611,0,0,222,222,333,333,350,556,1000,333,1000,500,333,944,0,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333,400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,584,611,556,556,556,556,500,556,500],
        'Helvetica-Bold'=>[761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,761,278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,761,556,0,278,556,500,1000,556,556,333,1000,667,333,1000,0,611,0,0,278,278,500,500,350,556,1000,333,1000,556,333,944,0,500,667,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,333,400,584,333,333,333,611,556,278,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,584,611,611,611,611,611,556,611,556],
    ];

    private array $pages = [];
    private int $cur = -1;
    private array $images = [];
    private string $font = 'Helvetica';
    private float $size = 10;
    private array $couleur = [0, 0, 0];
    public float $w = 210;
    public float $h = 297;

    public function page(bool $paysage = false): void
    {
        $this->w = $paysage ? 297 : 210;
        $this->h = $paysage ? 210 : 297;
        $this->pages[] = ['w' => $this->w, 'h' => $this->h, 'ops' => [], 'img' => []];
        $this->cur = count($this->pages) - 1;
    }

    public function police(bool $gras = false, float $taille = 10): void
    {
        $this->font = $gras ? 'Helvetica-Bold' : 'Helvetica';
        $this->size = $taille;
    }

    public function couleur(int $r, int $g, int $b): void
    {
        $this->couleur = [$r, $g, $b];
    }

    private function op(string $s): void
    {
        $this->pages[$this->cur]['ops'][] = $s;
    }

    private static function enc(string $s): string
    {
        $s = str_replace(["\u{202F}", "\u{00A0}"], [' ', ' '], $s);
        $c = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
        return $c === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $c;
    }

    public function largeur(string $s): float
    {
        $s = self::enc($s);
        $w = self::WIDTHS[$this->font];
        $t = 0;
        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $t += $w[ord($s[$i])];
        }
        return $t * $this->size / 1000 / self::K;
    }

    /** Texte ; $align : 'L', 'R' (x = bord droit), 'C' (x = centre) */
    public function texte(float $x, float $y, string $s, string $align = 'L'): void
    {
        if ($s === '') {
            return;
        }
        if ($align === 'R') {
            $x -= $this->largeur($s);
        } elseif ($align === 'C') {
            $x -= $this->largeur($s) / 2;
        }
        $e = self::enc($s);
        $e = str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $e);
        [$r, $g, $b] = $this->couleur;
        $fid = $this->font === 'Helvetica' ? 'F1' : 'F2';
        $this->op(sprintf('BT %.3F %.3F %.3F rg /%s %.2F Tf %.2F %.2F Td (%s) Tj ET',
            $r / 255, $g / 255, $b / 255, $fid, $this->size, $x * self::K, ($this->h - $y) * self::K, $e));
    }

    /** Coupe le texte pour tenir dans $largeur (ajoute « … ») */
    public function tronquer(string $s, float $largeur): string
    {
        if ($this->largeur($s) <= $largeur) {
            return $s;
        }
        while ($s !== '' && $this->largeur($s . '…') > $largeur) {
            $s = mb_substr($s, 0, -1);
        }
        return $s . '…';
    }

    /** Découpe en lignes pour une largeur donnée */
    public function lignes(string $s, float $largeur): array
    {
        $out = [];
        foreach (preg_split('/\R/', $s) as $para) {
            $ligne = '';
            foreach (preg_split('/\s+/', trim($para)) as $mot) {
                $essai = $ligne === '' ? $mot : "$ligne $mot";
                if ($this->largeur($essai) <= $largeur || $ligne === '') {
                    $ligne = $essai;
                } else {
                    $out[] = $ligne;
                    $ligne = $mot;
                }
            }
            $out[] = $ligne;
        }
        return $out;
    }

    public function trait(float $x1, float $y1, float $x2, float $y2, float $ep = 0.2, int $gris = 120): void
    {
        $g = $gris / 255;
        $this->op(sprintf('%.3F G %.2F w %.2F %.2F m %.2F %.2F l S', $g, $ep * self::K,
            $x1 * self::K, ($this->h - $y1) * self::K, $x2 * self::K, ($this->h - $y2) * self::K));
    }

    public function rect(float $x, float $y, float $w, float $h, array $rgb): void
    {
        $this->op(sprintf('%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f', $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255,
            $x * self::K, ($this->h - $y - $h) * self::K, $w * self::K, $h * self::K));
    }

    /** Place une image JPEG dans le cadre (x, y, w, h) en conservant ses proportions. */
    public function image(string $fichier, float $x, float $y, float $w, float $h): bool
    {
        $info = @getimagesize($fichier);
        if (!$info || $info[2] !== IMAGETYPE_JPEG) {
            return false;
        }
        $key = md5($fichier);
        if (!isset($this->images[$key])) {
            $ch = $info['channels'] ?? 3;
            $this->images[$key] = ['f' => $fichier, 'w' => $info[0], 'h' => $info[1],
                'cs' => $ch === 1 ? 'DeviceGray' : ($ch === 4 ? 'DeviceCMYK' : 'DeviceRGB'), 'n' => count($this->images) + 1];
        }
        $im = $this->images[$key];
        $r = min($w / $im['w'], $h / $im['h']);
        $iw = $im['w'] * $r;
        $ih = $im['h'] * $r;
        $ix = $x + ($w - $iw) / 2;
        $this->pages[$this->cur]['img'][$im['n']] = true;
        $this->op(sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /I%d Do Q', $iw * self::K, $ih * self::K,
            $ix * self::K, ($this->h - $y - $ih) * self::K, $im['n']));
        return true;
    }

    public function sortie(string $titre = ''): string
    {
        $objs = [];      // numéro => contenu
        $n = 2;          // 1 catalogue, 2 arbre des pages
        $fonts = [];
        foreach (['F1' => 'Helvetica', 'F2' => 'Helvetica-Bold'] as $id => $base) {
            $fonts[$id] = ++$n;
            $objs[$n] = "<< /Type /Font /Subtype /Type1 /BaseFont /$base /Encoding /WinAnsiEncoding >>";
        }
        $imgObj = [];
        foreach ($this->images as $im) {
            $data = (string)file_get_contents($im['f']);
            $decode = $im['cs'] === 'DeviceCMYK' ? ' /Decode [1 0 1 0 1 0 1 0]' : '';
            $imgObj[$im['n']] = ++$n;
            $objs[$n] = "<< /Type /XObject /Subtype /Image /Width {$im['w']} /Height {$im['h']} /ColorSpace /{$im['cs']}"
                . " /BitsPerComponent 8 /Filter /DCTDecode$decode /Length " . strlen($data) . " >>\nstream\n" . $data . "\nendstream";
        }
        $kids = [];
        foreach ($this->pages as $p) {
            $flux = gzcompress(implode("\n", $p['ops']));
            $cont = ++$n;
            $objs[$cont] = '<< /Filter /FlateDecode /Length ' . strlen($flux) . " >>\nstream\n" . $flux . "\nendstream";
            $xo = '';
            foreach (array_keys($p['img']) as $i) {
                $xo .= "/I$i {$imgObj[$i]} 0 R ";
            }
            $pg = ++$n;
            $kids[] = "$pg 0 R";
            $objs[$pg] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Contents %d 0 R '
                . '/Resources << /Font << /F1 %d 0 R /F2 %d 0 R >>%s >> >>',
                $p['w'] * self::K, $p['h'] * self::K, $cont, $fonts['F1'], $fonts['F2'],
                $xo ? " /XObject << $xo>>" : '');
        }
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        $info = ++$n;
        $t = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], self::enc($titre));
        $objs[$info] = "<< /Title ($t) /Producer (Tonton) /CreationDate (D:" . date('YmdHis') . ') >>';

        ksort($objs);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $num => $c) {
            $offsets[$num] = strlen($out);
            $out .= "$num 0 obj\n$c\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 " . ($n + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $n; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $out .= "trailer\n<< /Size " . ($n + 1) . " /Root 1 0 R /Info $info 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return $out;
    }
}
