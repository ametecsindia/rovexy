<?php

declare(strict_types=1);

namespace App\Services\Demo;

use Illuminate\Support\Carbon;

/**
 * Payment slips for demo payments, as an employee would upload them: a photo of the collection receipt (cash, cheque,
 * branch) or a payment-confirmation screenshot (UPI, NEFT/IMPS, payment link). Issued in the demo company's own name;
 * no bank or app branding. Returns JPEG bytes.
 */
final class DemoSlip
{
    public static function render(string $mode, array $d, \Random\Randomizer $rnd): string
    {
        $img = in_array($mode, ['CASH', 'CHEQUE', 'BRANCH'], true) ? self::receipt($mode, $d, $rnd) : self::screenshot($mode, $d, $rnd);
        ob_start();
        imagejpeg($img, null, 74);
        imagedestroy($img);
        return (string) ob_get_clean();
    }

    /** A paper receipt photographed on a desk: slightly turned, warm light. */
    private static function receipt(string $mode, array $d, \Random\Randomizer $rnd)
    {
        [$w, $h] = [760, 980];
        $p = imagecreatetruecolor($w, $h);
        $paper = imagecolorallocate($p, 252, 249, 238);
        $ink = imagecolorallocate($p, 30, 42, 92);
        $print = imagecolorallocate($p, 40, 40, 40);
        $rule = imagecolorallocate($p, 170, 170, 160);
        $pen = imagecolorallocate($p, 22, 52, 150);
        imagefill($p, 0, 0, $paper);
        imagerectangle($p, 18, 18, $w - 19, $h - 19, $rule);
        $f = self::font();
        $b = self::font(true);
        self::center($p, 25, $b, $ink, mb_strtoupper(mb_substr($d['company'], 0, 34)), 78, $w);
        self::center($p, 14, $f, $print, $d['city'] . ' Branch · Collections', 108, $w);
        imagefilledrectangle($p, 40, 128, $w - 40, 131, $ink);
        self::center($p, 19, $b, $print, $mode === 'BRANCH' ? 'BRANCH PAYMENT RECEIPT' : 'COLLECTION RECEIPT', 170, $w);
        $no = sprintf('No. %05d', $rnd->getInt(1200, 98000));
        imagettftext($p, 15, 0, 48, 215, $print, $f, $no);
        $date = 'Date: ' . $d['when']->format('d/m/Y');
        $box = imagettfbbox(15, 0, $f, $date);
        imagettftext($p, 15, 0, $w - 48 - ($box[2] - $box[0]), 215, $print, $f, $date);
        $rows = [['Received with thanks from', $d['customer']], ['Loan account no.', $d['account']], ['The sum of rupees', self::words((int) round($d['amount'])) . ' only'],
            ['By', match ($mode) { 'CASH' => 'Cash', 'CHEQUE' => 'Cheque no. ' . $d['ref'], default => 'Cash at branch counter' }],
            ['Towards', 'EMI / overdue instalment']];
        $y = 280;
        foreach ($rows as [$label, $value]) {
            imagettftext($p, 14, 0, 48, $y, $print, $f, $label);
            $lw = imagettfbbox(14, 0, $f, $label)[2] + 60;
            for ($x = $lw; $x < $w - 48; $x += 9) {
                imageline($p, $x, $y + 4, $x + 4, $y + 4, $rule);
            }
            $lines = self::wrap($value, 19, $f, $w - 48 - $lw - 10);
            foreach ($lines as $i => $line) {
                imagettftext($p, 19, $rnd->getInt(-1, 1), $lw + 6, $y - 2 + $i * 34, $pen, $f, $line);
            }
            $y += 70 + (count($lines) - 1) * 34;
        }
        // Amount box
        imagerectangle($p, 48, $y + 10, 330, $y + 72, $print);
        imagettftext($p, 26, 0, 64, $y + 54, $pen, $b, '₹ ' . number_format($d['amount'], 2));
        imagettftext($p, 13, 0, 48, $y + 120, $print, $f, 'Collected by: ' . $d['agent'] . ' (' . $d['emp'] . ')');
        imagettftext($p, 12, 0, 48, $h - 52, $rule, $f, 'Subject to realisation of cheque.');
        // Signature
        $sx = $w - 250;
        $sy = $h - 150;
        for ($i = 0, $px = $sx, $py = $sy; $i < 26; $i++) {
            $nx = $px + $rnd->getInt(4, 9);
            $ny = $sy + (int) (sin($i * 0.9) * $rnd->getInt(6, 18));
            imagesetthickness($p, 2);
            imageline($p, $px, $py, $nx, $ny, $pen);
            [$px, $py] = [$nx, $ny];
        }
        imagesetthickness($p, 1);
        imageline($p, $w - 270, $h - 115, $w - 48, $h - 115, $rule);
        imagettftext($p, 12, 0, $w - 230, $h - 95, $print, $f, 'Authorised signatory');

        // Desk photo
        $bg = imagecreatetruecolor(900, 1160);
        imagefill($bg, 0, 0, imagecolorallocate($bg, 120 + $rnd->getInt(0, 20), 98 + $rnd->getInt(0, 14), 78));
        for ($i = 0; $i < 2600; $i++) {
            $g = $rnd->getInt(-14, 14);
            imagesetpixel($bg, $rnd->getInt(0, 899), $rnd->getInt(0, 1159), imagecolorallocate($bg, 125 + $g, 102 + $g, 80 + $g));
        }
        $turned = imagerotate($p, $rnd->getInt(-30, 30) / 10, imagecolorallocatealpha($p, 0, 0, 0, 127));
        imagedestroy($p);
        imagecopy($bg, $turned, (900 - imagesx($turned)) >> 1, (1160 - imagesy($turned)) >> 1, 0, 0, imagesx($turned), imagesy($turned));
        imagedestroy($turned);
        imagefilter($bg, IMG_FILTER_BRIGHTNESS, $rnd->getInt(-12, 6));
        return $bg;
    }

    /** A phone screenshot of a successful payment. */
    private static function screenshot(string $mode, array $d, \Random\Randomizer $rnd)
    {
        [$w, $h] = [720, 1480];
        $im = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($im, 255, 255, 255);
        $dark = imagecolorallocate($im, 28, 32, 38);
        $mute = imagecolorallocate($im, 110, 116, 126);
        $line = imagecolorallocate($im, 230, 232, 236);
        $green = imagecolorallocate($im, 26, 150, 84);
        $f = self::font();
        $b = self::font(true);
        imagefill($im, 0, 0, $white);
        $at = $d['when'];
        imagettftext($im, 16, 0, 36, 44, $dark, $b, $at->format('H:i'));
        imagettftext($im, 15, 0, $w - 130, 44, $dark, $f, $rnd->getInt(31, 97) . '%');
        imagefilledrectangle($im, $w - 62, 28, $w - 30, 44, $dark);
        imagefilledellipse($im, $w >> 1, 290, 170, 170, $green);
        imagesetthickness($im, 12);
        imageline($im, ($w >> 1) - 40, 292, ($w >> 1) - 10, 322, $white);
        imageline($im, ($w >> 1) - 10, 322, ($w >> 1) + 44, 262, $white);
        imagesetthickness($im, 1);
        self::center($im, 24, $b, $dark, $mode === 'NEFT_IMPS' ? 'Transfer successful' : 'Payment successful', 450, $w);
        self::center($im, 46, $b, $dark, '₹' . number_format($d['amount'], ($d['amount'] - floor($d['amount'])) > 0 ? 2 : 0), 540, $w);
        self::center($im, 16, $f, $mute, $at->format('d M Y, h:i A'), 590, $w);
        $rows = [['Paid to', mb_substr($d['company'], 0, 30)], ['Paid by', $d['customer']], ['Remarks', 'EMI ' . $d['account']],
            [match ($mode) { 'NEFT_IMPS' => 'UTR number', 'ONLINE_LINK' => 'Payment ID', default => 'UPI transaction ID' }, $d['ref']],
            ['Payment method', match ($mode) { 'NEFT_IMPS' => 'IMPS', 'ONLINE_LINK' => 'Payment link', default => 'UPI' }]];
        $y = 690;
        foreach ($rows as [$k, $v]) {
            imageline($im, 40, $y - 46, $w - 40, $y - 46, $line);
            imagettftext($im, 15, 0, 48, $y, $mute, $f, $k);
            $bw = imagettfbbox(17, 0, $b, $v);
            imagettftext($im, 17, 0, $w - 48 - ($bw[2] - $bw[0]), $y, $dark, $b, $v);
            $y += 92;
        }
        imagerectangle($im, 40, $h - 180, $w - 40, $h - 100, $line);
        self::center($im, 18, $b, $green, 'Share receipt', $h - 128, $w);
        return $im;
    }

    private static function center($im, int $size, string $font, int $color, string $text, int $y, int $w): void
    {
        $bx = imagettfbbox($size, 0, $font, $text);
        imagettftext($im, $size, 0, (int) (($w - ($bx[2] - $bx[0])) / 2), $y, $color, $font, $text);
    }

    private static function wrap(string $text, int $size, string $font, int $max): array
    {
        $lines = [''];
        foreach (explode(' ', $text) as $word) {
            $try = trim(end($lines) . ' ' . $word);
            if (imagettfbbox($size, 0, $font, $try)[2] > $max && end($lines) !== '') {
                $lines[] = $word;
            } else {
                $lines[count($lines) - 1] = $try;
            }
        }
        return $lines;
    }

    private static function font(bool $bold = false): string
    {
        return resource_path('demo/fonts/DejaVuSans' . ($bold ? '-Bold' : '') . '.ttf');
    }

    /** Indian numbering in words: 125000 → "One Lakh Twenty Five Thousand". */
    public static function words(int $n): string
    {
        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
        $two = fn (int $x) => $x < 20 ? $ones[$x] : trim($tens[intdiv($x, 10)] . ' ' . $ones[$x % 10]);
        if ($n === 0) {
            return 'Zero';
        }
        $out = [];
        foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand'], [100, 'Hundred']] as [$unit, $name]) {
            if ($n >= $unit) {
                $out[] = ($unit === 10000000 ? self::words(intdiv($n, $unit)) : $two(intdiv($n, $unit))) . ' ' . $name;
                $n %= $unit;
            }
        }
        if ($n) {
            $out[] = $two($n);
        }
        return implode(' ', $out);
    }
}

