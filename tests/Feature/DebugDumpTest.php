<?php

namespace Tests\Feature;

use Tests\TestCase;

class DebugDumpTest extends TestCase
{
    public function test_dump(): void
    {
        $ws = $this->workspace();
        foreach (explode(',', getenv('DUMP') ?: 'showcase-invoice-pdf') as $k) {
            $case = $this->ingest($ws, $k);
            $p = app(\App\Http\Presenters\CasePresenter::class)->detail($case);
            fwrite(STDERR, "== $k {$case->state} {$case->route} {$case->category} sig={$case->overall_signal}\n");
            foreach ($p['extraction']['fields'] ?? [] as $f) {
                fwrite(STDERR, sprintf("  %-22s pred=%-30s norm=%-14s sig=%s st=%s ocr=%s ev=%d\n", $f['name'], $f['predicted'], $f['predicted_normalized'], $f['signal'], $f['status'], $f['ocr_confidence'], count($f['evidence'])));
            }
            foreach ($p['validation']['issues'] ?? [] as $i) {
                fwrite(STDERR, "  ISSUE {$i['code']} {$i['field']} {$i['message']}\n");
            }
            fwrite(STDERR, '  reasons '.json_encode(array_column($p['policy']['reasons'] ?? [], 'code'))."\n");
        }
        $this->assertTrue(true);
    }
}
