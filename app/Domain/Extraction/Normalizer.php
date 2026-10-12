<?php

namespace App\Domain\Extraction;

use Carbon\CarbonImmutable;

/** Turns printed values into comparable ones. The original printed value is always kept next to the result. */
final class Normalizer
{
    private const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    private const SYMBOLS = ['€' => 'EUR', '$' => 'USD', '£' => 'GBP', 'lei' => 'RON', 'zł' => 'PLN'];

    /** @return array{value:mixed,warnings:list<string>} */
    public function normalize(string $type, ?string $raw): array
    {
        $raw = $raw === null ? null : ($type === 'list' ? $this->cleanMultiline($raw) : $this->clean($raw));
        if ($raw === null || $raw === '') {
            return ['value' => null, 'warnings' => []];
        }

        return match ($type) {
            'date' => $this->date($raw),
            'currency' => ['value' => $this->currency($raw), 'warnings' => []],
            'money' => $this->money($raw),
            'email' => ['value' => strtolower($raw), 'warnings' => []],
            'list' => ['value' => $this->listItems($raw), 'warnings' => []],
            default => ['value' => $raw, 'warnings' => []],
        };
    }

    public function clean(string $value): string
    {
        $value = \Normalizer::normalize($value, \Normalizer::FORM_C) ?: $value;
        $value = str_replace(["\u{00A0}", "\u{202F}"], ' ', $value);

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /** Keeps line structure (list fields) while cleaning each line. */
    public function cleanMultiline(string $value): string
    {
        return implode("\n", array_filter(array_map(fn ($l) => $this->clean($l), preg_split('/\R/u', $value) ?: []), fn ($l) => $l !== ''));
    }

    public function currency(string $raw): ?string
    {
        $upper = strtoupper(trim($raw));
        if (preg_match('/\b(USD|EUR|GBP|RON|MDL|CHF|PLN)\b/', $upper, $m)) {
            return $m[1];
        }
        foreach (self::SYMBOLS as $symbol => $code) {
            if (str_contains(mb_strtolower($raw), mb_strtolower($symbol))) {
                return $code;
            }
        }

        // an unknown 3-letter code is returned as is so validation can reject it (INVALID_CURRENCY)
        return preg_match('/\b([A-Z]{3})\b/', $upper, $m) ? $m[1] : null;
    }

    /** @return array{value:?int,warnings:list<string>} amount in minor units (two decimals) */
    public function money(string $raw): array
    {
        $s = preg_replace('/[^\d.,\-]/u', '', $raw) ?? '';
        if ($s === '' || ! preg_match('/\d/', $s)) {
            return ['value' => null, 'warnings' => ['UNPARSEABLE_AMOUNT']];
        }
        $negative = str_starts_with($s, '-');
        $s = ltrim($s, '-');
        $warnings = [];
        $lastDot = strrpos($s, '.');
        $lastComma = strrpos($s, ',');
        $decimalSep = null;

        if ($lastDot !== false && $lastComma !== false) {
            $decimalSep = $lastDot > $lastComma ? '.' : ',';
        } elseif ($lastDot !== false || $lastComma !== false) {
            $sep = $lastDot !== false ? '.' : ',';
            $pos = $lastDot !== false ? $lastDot : $lastComma;
            if (substr_count($s, $sep) === 1) {
                if (strlen($s) - $pos - 1 === 3) {
                    $warnings[] = 'AMBIGUOUS_AMOUNT_SEPARATOR'; // "1.250": thousands or decimals?
                } else {
                    $decimalSep = $sep;
                }
            }
        }

        if ($decimalSep === null) {
            $minor = (int) (preg_replace('/[.,]/', '', $s) ?? '0') * 100;

            return ['value' => $negative ? -$minor : $minor, 'warnings' => $warnings];
        }

        $thousands = $decimalSep === '.' ? ',' : '.';
        [$int, $frac] = array_pad(explode($decimalSep, str_replace($thousands, '', $s), 2), 2, '0');
        $minor = (int) ($int.str_pad(substr($frac, 0, 2), 2, '0'));

        return ['value' => $negative ? -$minor : $minor, 'warnings' => $warnings];
    }

    /** @return array{value:?string,warnings:list<string>} ISO date; d/m/Y is assumed for slash dates and flagged when ambiguous */
    public function date(string $raw): array
    {
        $raw = trim($raw, " \t.,");
        $warnings = [];
        $y = $m = $d = null;

        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $raw, $x)) {
            [$y, $m, $d] = [(int) $x[1], (int) $x[2], (int) $x[3]];
        } elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})$/', $raw, $x)) {
            $a = (int) $x[1];
            $b = (int) $x[2];
            $y = (int) $x[3] < 100 ? 2000 + (int) $x[3] : (int) $x[3];
            if ($a > 12) {
                [$d, $m] = [$a, $b];
            } elseif ($b > 12) {
                [$m, $d] = [$a, $b];
            } else {
                [$d, $m] = [$a, $b];
                if ($a !== $b) {
                    $warnings[] = 'AMBIGUOUS_DATE';
                }
            }
        } elseif (preg_match('/^(\d{1,2})(?:st|nd|rd|th)?\s+([A-Za-z]{3,9})\.?,?\s+(\d{4})$/', $raw, $x) && isset(self::MONTHS[strtolower(substr($x[2], 0, 3))])) {
            [$d, $m, $y] = [(int) $x[1], self::MONTHS[strtolower(substr($x[2], 0, 3))], (int) $x[3]];
        } elseif (preg_match('/^([A-Za-z]{3,9})\.?\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(\d{4})$/', $raw, $x) && isset(self::MONTHS[strtolower(substr($x[1], 0, 3))])) {
            [$m, $d, $y] = [self::MONTHS[strtolower(substr($x[1], 0, 3))], (int) $x[2], (int) $x[3]];
        }

        if ($y === null || ! checkdate($m, $d, $y)) {
            return ['value' => null, 'warnings' => ['UNPARSEABLE_DATE']];
        }

        return ['value' => CarbonImmutable::create($y, $m, $d)->toDateString(), 'warnings' => $warnings];
    }

    /** @return list<array{description:string,quantity:?int}> */
    public function listItems(string $raw): array
    {
        $items = [];
        foreach (preg_split('/\s*[;\n|]\s*/u', $raw) ?: [] as $line) {
            $line = trim($line, " -*•\t");
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(\d{1,6})\s*(?:x|×|pcs|units)?\s+(.+)$/iu', $line, $m)) {
                $items[] = ['description' => trim($m[2]), 'quantity' => (int) $m[1]];
            } elseif (preg_match('/^(.+?)\s*[x×]\s*(\d{1,6})$/iu', $line, $m)) {
                $items[] = ['description' => trim($m[1]), 'quantity' => (int) $m[2]];
            } else {
                $items[] = ['description' => $line, 'quantity' => null];
            }
        }

        return $items;
    }
}
