<?php

namespace App\Infrastructure\AI\Providers\Llm;

use App\Infrastructure\AI\Exceptions\ProviderInvalidOutput;

/** Structured output through a single forced tool call whose input_schema is the response schema. The model cannot call other tools. */
final class AnthropicModel extends HttpLanguageModel
{
    public function name(): string
    {
        return 'anthropic';
    }

    public function generate(string $system, string $user, array $jsonSchema, array $images = []): array
    {
        $started = hrtime(true);
        $imgs = $this->imageParts($images);
        $content = [];
        foreach ($imgs as $img) {
            $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $img['mime'], 'data' => $img['data']]];
        }
        $content[] = ['type' => 'text', 'text' => $user];
        $response = $this->send(fn () => $this->http()->withHeaders(['x-api-key' => $this->apiKey, 'anthropic-version' => '2023-06-01'])->post(rtrim($this->baseUrl, '/').'/messages', [
            'model' => $this->model,
            'max_tokens' => 2048,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $content]],
            'tools' => [['name' => 'emit_result', 'description' => 'Return the structured result.', 'input_schema' => $jsonSchema]],
            'tool_choice' => ['type' => 'tool', 'name' => 'emit_result'],
        ]));
        $body = $response->json();
        $json = null;
        foreach ($body['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === 'emit_result') {
                $json = $block['input'] ?? null;
            }
        }
        if (! is_array($json)) {
            throw new ProviderInvalidOutput;
        }

        return ['json' => $json, 'meta' => $this->meta((int) ($body['usage']['input_tokens'] ?? 0), (int) ($body['usage']['output_tokens'] ?? 0), count($imgs), (int) ((hrtime(true) - $started) / 1e6))];
    }
}
