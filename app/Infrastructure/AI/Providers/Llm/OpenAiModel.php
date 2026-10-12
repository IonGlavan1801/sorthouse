<?php

namespace App\Infrastructure\AI\Providers\Llm;

final class OpenAiModel extends HttpLanguageModel
{
    public function name(): string
    {
        return 'openai';
    }

    public function generate(string $system, string $user, array $jsonSchema, array $images = []): array
    {
        $started = hrtime(true);
        $imgs = $this->imageParts($images);
        $content = [['type' => 'text', 'text' => $user]];
        foreach ($imgs as $img) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:'.$img['mime'].';base64,'.$img['data']]];
        }
        $response = $this->send(fn () => $this->http()->withToken($this->apiKey)->post(rtrim($this->baseUrl, '/').'/chat/completions', [
            'model' => $this->model,
            'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $content]],
            'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => 'triage_output', 'strict' => true, 'schema' => $jsonSchema]],
        ]));
        $body = $response->json();
        $json = $this->decode($body['choices'][0]['message']['content'] ?? null);

        return ['json' => $json, 'meta' => $this->meta((int) ($body['usage']['prompt_tokens'] ?? 0), (int) ($body['usage']['completion_tokens'] ?? 0), count($imgs), (int) ((hrtime(true) - $started) / 1e6))];
    }
}
