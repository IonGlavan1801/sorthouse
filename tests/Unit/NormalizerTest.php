<?php

namespace Tests\Unit;

use App\Domain\Extraction\Normalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NormalizerTest extends TestCase
{
    #[DataProvider('amounts')]
    public function test_amounts_become_minor_units(string $raw, ?int $minor, array $warnings = []): void
    {
        $r = (new Normalizer)->money($raw);
        $this->assertSame($minor, $r['value']);
        $this->assertSame($warnings, $r['warnings']);
    }

    public static function amounts(): array
    {
        return [
            'us' => ['$1,250.00', 125000], 'eu' => ['1.250,00 EUR', 125000], 'iso prefix' => ['EUR 4,930.50', 493050], 'plain' => ['1250.5 RON', 125050],
            'no decimals' => ['1250', 125000], 'thousands only' => ['12,500', 1250000, ['AMBIGUOUS_AMOUNT_SEPARATOR']], 'nbsp' => ["1\u{00A0}250,00", 125000], 'garbage' => ['n/a', null, ['UNPARSEABLE_AMOUNT']],
        ];
    }

    #[DataProvider('dates')]
    public function test_dates(string $raw, ?string $iso, array $warnings = []): void
    {
        $r = (new Normalizer)->date($raw);
        $this->assertSame($iso, $r['value']);
        $this->assertSame($warnings, $r['warnings']);
    }

    public static function dates(): array
    {
        return [
            'iso' => ['2026-10-12', '2026-10-12'], 'dmy unambiguous' => ['25/10/2026', '2026-10-25'], 'mdy unambiguous' => ['10/25/2026', '2026-10-25'],
            'ambiguous' => ['03/04/2026', '2026-04-03', ['AMBIGUOUS_DATE']], 'same day month' => ['05/05/2026', '2026-05-05'], 'long' => ['12 Oct 2026', '2026-10-12'],
            'us' => ['Oct 12, 2026', '2026-10-12'], 'ordinal' => ['12th October 2026', '2026-10-12'], 'impossible' => ['31/02/2026', null, ['UNPARSEABLE_DATE']], 'nonsense' => ['soon', null, ['UNPARSEABLE_DATE']],
        ];
    }

    public function test_currency_symbols_and_codes(): void
    {
        $n = new Normalizer;
        $this->assertSame('EUR', $n->currency('€'));
        $this->assertSame('USD', $n->currency('USD'));
        $this->assertSame('RON', $n->currency('lei'));
        $this->assertNull($n->currency('???'));
    }

    public function test_list_items(): void
    {
        $items = (new Normalizer)->listItems("- 200 x steel brackets\n- gloves x 50\n* LED panels");
        $this->assertSame([['description' => 'steel brackets', 'quantity' => 200], ['description' => 'gloves', 'quantity' => 50], ['description' => 'LED panels', 'quantity' => null]], $items);
    }

    public function test_unicode_and_whitespace_are_normalised(): void
    {
        $n = new Normalizer;
        $this->assertSame("Caf\u{00E9}", $n->clean("  Cafe\u{0301}  "));
        $this->assertSame('a b', $n->clean("a\u{00A0}\u{00A0}b"));
        $this->assertSame('XYZ', $n->currency('XYZ'));
    }
}
