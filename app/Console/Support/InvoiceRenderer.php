<?php

namespace App\Console\Support;

/**
 * Renders synthetic invoices as text PDFs (FPDF), image-only PDFs and "photographed" JPEGs (GD with rotation, noise and blur).
 * For images it also returns the OCR words (with bounding boxes) an OCR engine would report, including deliberate misreads.
 */
final class InvoiceRenderer
{
    private const CW = 9;      // GD font 5 char width

    private const CH = 15;

    /** @param list<string> $lines */
    public function pdfText(array $lines, string $path, ?string $hiddenText = null): void
    {
        $pdf = new \FPDF('P', 'mm', 'A4');
        $pdf->SetCreator('SORTHOUSE synthetic corpus');
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', '', 11);
        foreach ($lines as $i => $line) {
            if ($i === 0) {
                $pdf->SetFont('Helvetica', 'B', 16);
            } elseif ($i === 1) {
                $pdf->SetFont('Helvetica', '', 11);
            }
            $pdf->Cell(0, 7, iconv('UTF-8', 'windows-1252//TRANSLIT', $line), 0, 1);
        }
        if ($hiddenText !== null) {
            $pdf->SetTextColor(255, 255, 255); // white on white: invisible to a human, visible to a text parser
            $pdf->SetFont('Helvetica', '', 8);
            $pdf->Cell(0, 5, $hiddenText, 0, 1);
        }
        $pdf->Output('F', $path);
    }

    /**
     * @param  list<string>  $lines
     * @param  array<int,array{from:string,to:string,conf:float}>  $misreads  token replacements applied in OCR output
     * @return array{words:list<array>,width:int,height:int}
     */
    public function image(array $lines, string $path, float $angle = 0.0, array $misreads = [], bool $asPdf = false, ?string $pdfPath = null): array
    {
        $w = 900;
        $h = 80 + count($lines) * 34 + 60;
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 244, 241, 232));
        $ink = imagecolorallocate($img, 28, 28, 34);
        imagefilledrectangle($img, 0, 0, $w, 56, imagecolorallocate($img, 210, 205, 190));

        $words = [];
        foreach ($lines as $li => $line) {
            $x = 40;
            $y = $li === 0 ? 18 : 80 + ($li - 1) * 34;
            $tokens = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($tokens as $ti => $token) {
                $read = $token;
                $conf = round(0.955 + (mt_rand(0, 40) / 1000), 4);
                foreach ($misreads as $m) {
                    if ($token === $m['from']) {
                        $read = $m['to'];
                        $conf = $m['conf'];
                    }
                }
                imagestring($img, 5, $x, $y, $token, $ink);
                $ww = strlen($token) * self::CW;
                $words[] = ['t' => $read, 'true' => $token, 'x0' => $x, 'y0' => $y, 'w0' => $ww, 'h0' => self::CH, 'conf' => min(0.995, $conf), 'nl' => $ti === count($tokens) - 1];
                $x += $ww + self::CW;
            }
        }
        imageline($img, 40, 62, $w - 40, 62, $ink);

        // paper noise
        for ($i = 0; $i < 2200; $i++) {
            $c = mt_rand(120, 200);
            imagesetpixel($img, mt_rand(0, $w - 1), mt_rand(0, $h - 1), imagecolorallocate($img, $c, $c, $c - 10));
        }
        if (abs($angle) > 0.01) {
            $rot = imagerotate($img, $angle, imagecolorallocate($img, 70, 66, 60));
            $nw = imagesx($rot);
            $nh = imagesy($rot);
            $th = deg2rad($angle);
            foreach ($words as &$word) {
                $pts = [];
                foreach ([[0, 0], [$word['w0'], 0], [0, $word['h0']], [$word['w0'], $word['h0']]] as [$px, $py]) {
                    $dx = $word['x0'] + $px - $w / 2;
                    $dy = $word['y0'] + $py - $h / 2;
                    $pts[] = [$nw / 2 + $dx * cos($th) + $dy * sin($th), $nh / 2 - $dx * sin($th) + $dy * cos($th)];
                }
                $xs = array_column($pts, 0);
                $ys = array_column($pts, 1);
                $word['x'] = min($xs) / $nw;
                $word['y'] = min($ys) / $nh;
                $word['w'] = (max($xs) - min($xs)) / $nw;
                $word['h'] = (max($ys) - min($ys)) / $nh;
            }
            unset($word);
            imagedestroy($img);
            $img = $rot;
            [$w, $h] = [$nw, $nh];
        } else {
            foreach ($words as &$word) {
                $word['x'] = $word['x0'] / $w;
                $word['y'] = $word['y0'] / $h;
                $word['w'] = $word['w0'] / $w;
                $word['h'] = $word['h0'] / $h;
            }
            unset($word);
        }
        imagefilter($img, IMG_FILTER_GAUSSIAN_BLUR);
        imagefilter($img, IMG_FILTER_CONTRAST, -6);
        imagejpeg($img, $path, 78);
        imagedestroy($img);

        if ($asPdf && $pdfPath) {
            $pdf = new \FPDF('P', 'mm', 'A4');
            $pdf->AddPage();
            $pdf->Image($path, 10, 10, 190, 0, 'JPG');
            $pdf->Output('F', $pdfPath);
        }

        $out = array_map(fn ($wd) => ['t' => $wd['t'], 'x' => round($wd['x'], 5), 'y' => round($wd['y'], 5), 'w' => round($wd['w'], 5), 'h' => round($wd['h'], 5), 'conf' => $wd['conf'], 'nl' => $wd['nl']], $words);

        return ['words' => $out, 'width' => $w, 'height' => $h];
    }
}
