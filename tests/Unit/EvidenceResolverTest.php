<?php

namespace Tests\Unit;

use App\Infrastructure\AI\DTO\CaseInput;
use App\Infrastructure\AI\DTO\SourceDoc;
use App\Infrastructure\AI\QuoteEvidenceResolver;
use PHPUnit\Framework\TestCase;

class EvidenceResolverTest extends TestCase
{
    private function input(): CaseInput
    {
        $words = [
            ['t' => 'Invoice', 'x' => 0.1, 'y' => 0.2, 'w' => 0.1, 'h' => 0.02, 'conf' => 0.99],
            ['t' => 'No:', 'x' => 0.21, 'y' => 0.2, 'w' => 0.04, 'h' => 0.02, 'conf' => 0.98],
            ['t' => 'INV-40B81', 'x' => 0.27, 'y' => 0.2, 'w' => 0.15, 'h' => 0.02, 'conf' => 0.58, 'nl' => true],
        ];

        return new CaseInput('Invoice', 'a@b.test', null, [
            new SourceDoc('msg:body', 'email_body', "Please pay\nthe attached invoice today"),
            new SourceDoc('att:1:p1', 'pdf_text', 'Total due: EUR 120.00', 1),
            new SourceDoc('att:2:p1', 'image_ocr', SourceDoc::textFromWords($words), 1, $words),
        ]);
    }

    public function test_resolves_quote_with_span_and_page(): void
    {
        $r = (new QuoteEvidenceResolver)->resolve($this->input(), 'att:1:p1', 'EUR 120.00');
        $this->assertSame('page_snippet', $r['locator_type']);
        $this->assertSame(1, $r['page']);
        $this->assertSame([11, 21], [$r['span_start'], $r['span_end']]);
    }

    public function test_invented_source_id_is_rejected(): void
    {
        $this->assertNull((new QuoteEvidenceResolver)->resolve($this->input(), 'att:999:p1', 'EUR 120.00'));
    }

    public function test_quote_that_is_not_in_the_source_is_rejected(): void
    {
        $this->assertNull((new QuoteEvidenceResolver)->resolve($this->input(), 'att:1:p1', 'EUR 999.00'));
        $this->assertNull((new QuoteEvidenceResolver)->resolve($this->input(), 'msg:body', ''));
    }

    public function test_whitespace_insensitive_match(): void
    {
        $this->assertNotNull((new QuoteEvidenceResolver)->resolve($this->input(), 'msg:body', 'Please pay the attached'));
    }

    public function test_ocr_quote_resolves_to_bbox_with_min_confidence(): void
    {
        $r = (new QuoteEvidenceResolver)->resolve($this->input(), 'att:2:p1', 'Invoice No: INV-40B81');
        $this->assertSame('bbox', $r['locator_type']);
        $this->assertEqualsWithDelta(0.1, $r['bbox'][0], 0.0001);
        $this->assertEqualsWithDelta(0.58, $r['ocr_confidence'], 0.0001);
        $this->assertSame('ocr', $r['provenance']);
    }
}
