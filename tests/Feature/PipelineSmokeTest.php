<?php

namespace Tests\Feature;

use Tests\TestCase;

class PipelineSmokeTest extends TestCase
{
    public function test_showcase_fixtures_run_end_to_end(): void
    {
        $ws = $this->workspace();
        foreach (['showcase-invoice-pdf', 'showcase-invoice-photo', 'showcase-complaint', 'showcase-rfq', 'showcase-spam', 'showcase-general', 'showcase-ambiguous', 'showcase-invoice-mismatch'] as $key) {
            $case = $this->ingest($ws, $key);
            fwrite(STDERR, sprintf("%-28s %-16s %-16s %-26s signal=%s action=%s reasons=%s\n", $key, $case->state, $case->route, $case->category, $case->overall_signal, $case->proposed_action, json_encode($case->policyDecisions()->latest()->first()?->reasons ? array_column($case->policyDecisions()->latest()->first()->reasons, 'code') : [])));
        }
        $this->assertTrue(true);
    }
}
