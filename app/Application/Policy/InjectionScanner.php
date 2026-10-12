<?php

namespace App\Application\Policy;

use App\Infrastructure\AI\DTO\CaseInput;

/**
 * Deterministic detector for instruction-like content inside untrusted input. Detection feeds the policy; it is one layer of
 * defence-in-depth (delimited prompts, no tool freedom, server-owned recipients, action allow-list) and does not make injection "solved".
 */
final class InjectionScanner
{
    private const PATTERNS = [
        'IGNORE_INSTRUCTIONS' => '/\b(?:ignore|disregard|forget|override)\b[^.\n]{0,40}\b(?:previous|prior|above|all|any|the)\b[^.\n]{0,30}\b(?:instructions?|rules?|polic(?:y|ies)|guidelines?)\b/i',
        'NEW_INSTRUCTIONS' => '/\b(?:new instructions?|you are now|from now on you|act as (?:an? )?(?:admin|system|root)|developer mode)\b/i',
        'EXFILTRATE_TO_ADDRESS' => '/\b(?:send|forward|email|mail|exfiltrate)\b[^.\n]{0,80}\bto\s+[\w.+-]+@[\w-]+\.[\w.-]+/i',
        'FORCE_APPROVAL' => '/\b(?:mark|set|flag)\b[^.\n]{0,40}\b(?:as )?(?:approved|paid|verified|safe)\b/i',
        'REVEAL_SECRET' => '/\b(?:reveal|print|show|leak|output)\b[^.\n]{0,30}\b(?:api[- ]?key|system prompt|secret|password|credentials?)\b/i',
        'POLICY_BYPASS' => '/\b(?:bypass|disable|skip)\b[^.\n]{0,30}\b(?:review|approval|policy|validation|checks?)\b/i',
    ];

    /** @return list<array{code:string,source_id:string,quote:string}> */
    public function scan(CaseInput $input): array
    {
        $findings = [];
        foreach ($input->sources as $source) {
            foreach (self::PATTERNS as $code => $pattern) {
                if (preg_match($pattern, $source->text, $m)) {
                    $findings[] = ['code' => $code, 'source_id' => $source->id, 'quote' => mb_substr(trim($m[0]), 0, 200)];
                }
            }
        }

        return $findings;
    }
}
