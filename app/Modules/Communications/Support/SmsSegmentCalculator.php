<?php

declare(strict_types=1);

namespace App\Modules\Communications\Support;

/**
 * GSM 03.38 / UCS-2 segment counting for SMS cost estimates.
 */
final class SmsSegmentCalculator
{
    private const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    private const GSM_EXTENDED = "^{}\\[~]|€\f";

    /**
     * @return array{encoding: string, characters: int, units: int, segments: int, per_segment: int}
     */
    public function calculate(string $text): array
    {
        $chars = mb_str_split($text);
        $gsm = true;
        $units = 0;
        foreach ($chars as $char) {
            if (mb_strpos(self::GSM_BASIC, $char) !== false) {
                $units++;
            } elseif (mb_strpos(self::GSM_EXTENDED, $char) !== false) {
                $units += 2;
            } else {
                $gsm = false;
                break;
            }
        }

        if ($gsm) {
            $single = 160;
            $multi = 153;
        } else {
            // UCS-2 counts UTF-16 code units; characters outside the BMP take two.
            $units = intdiv(strlen((string) mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')), 2);
            $single = 70;
            $multi = 67;
        }

        $segments = $units === 0 ? 0 : ($units <= $single ? 1 : (int) ceil($units / $multi));

        return [
            'encoding' => $gsm ? 'GSM-7' : 'UCS-2',
            'characters' => count($chars),
            'units' => $units,
            'segments' => $segments,
            'per_segment' => $segments > 1 ? $multi : $single,
        ];
    }
}
