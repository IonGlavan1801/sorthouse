<?php

namespace App\Infrastructure\AI;

/**
 * Documents what each provider adapter can do. No false parity: values describe the adapter as implemented,
 * and "verified" says whether the adapter has been exercised against the real API with real credentials.
 */
final class CapabilityMatrix
{
    public static function all(): array
    {
        return [
            'heuristic' => [
                'label' => 'Heuristic baseline (deterministic rules, no LLM)',
                'image_input' => false, 'pdf_input' => false, 'structured_output' => 'native (code)', 'tool_calling' => false,
                'streaming' => false, 'usage_reporting' => 'estimated token counts', 'context_window' => 'n/a', 'rate_limits' => 'none',
                'verified' => 'yes, runs in CI', 'simulated' => true,
            ],
            'openai' => [
                'label' => 'OpenAI Chat Completions',
                'image_input' => true, 'pdf_input' => false, 'structured_output' => 'json_schema response_format', 'tool_calling' => false,
                'streaming' => false, 'usage_reporting' => 'usage.prompt_tokens/completion_tokens', 'context_window' => 'model dependent', 'rate_limits' => 'account dependent (HTTP 429 handled with backoff)',
                'verified' => 'request/response shape covered by HTTP fakes; not exercised against the live API', 'simulated' => false,
            ],
            'anthropic' => [
                'label' => 'Anthropic Messages',
                'image_input' => true, 'pdf_input' => false, 'structured_output' => 'forced single tool call (input_schema)', 'tool_calling' => false,
                'streaming' => false, 'usage_reporting' => 'usage.input_tokens/output_tokens', 'context_window' => 'model dependent', 'rate_limits' => 'account dependent (HTTP 429 handled with backoff)',
                'verified' => 'request/response shape covered by HTTP fakes; not exercised against the live API', 'simulated' => false,
            ],
            'gemini' => [
                'label' => 'Google Gemini generateContent',
                'image_input' => true, 'pdf_input' => false, 'structured_output' => 'responseSchema + application/json', 'tool_calling' => false,
                'streaming' => false, 'usage_reporting' => 'usageMetadata', 'context_window' => 'model dependent', 'rate_limits' => 'account dependent (HTTP 429 handled with backoff)',
                'verified' => 'request/response shape covered by HTTP fakes; not exercised against the live API', 'simulated' => false,
            ],
        ];
    }
}
