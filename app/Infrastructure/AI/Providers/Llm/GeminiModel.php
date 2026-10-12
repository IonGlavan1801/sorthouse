<?php

namespace App\Infrastructure\AI\Providers\Llm;

final class GeminiModel extends HttpLanguageModel
{
    public function name(): string
    {
        return 'gemini';
    }

    public function generate(string $system, string $user, array $jsonSchema, array $images = []): array
    {
        $started = hrtime(true);
        $imgs = $this->imageParts($images);
        $parts = [['text' => $user]];
        foreach ($imgs as $img) {
            $parts[] = ['inlineData' => ['mimeType' => $img['mime'], 'data' => $img['data']]];
        }
        $url = rtrim($this->baseUrl, '/').'/models/'.$this->model.':generateContent';
        $response = $this->send(fn () => $this->http()->withHeaders(['x-goog-api-key' => $this->apiKey])->post($url, [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => ['responseMimeType' => 'application/json', 'responseSchema' => self::convertSchema($jsonSchema)],
        ]));
        $body = $response->json();
        $json = $this->decode($body['candidates'][0]['content']['parts'][0]['text'] ?? null);

        return ['json' => $json, 'meta' => $this->meta((int) ($body['usageMetadata']['promptTokenCount'] ?? 0), (int) ($body['usageMetadata']['candidatesTokenCount'] ?? 0), count($imgs), (int) ((hrtime(true) - $started) / 1e6))];
    }

    /** Gemini accepts an OpenAPI subset: no additionalProperties, nullable instead of type arrays. */
    public static function convertSchema(array $schema): array
    {
        unset($schema['additionalProperties'], $schema['description']);
        if (isset($schema['type']) && is_array($schema['type'])) {
            $types = array_values(array_filter($schema['type'], fn ($t) => $t !== 'null'));
            $schema['nullable'] = count($types) !== count($schema['type']);
            $schema['type'] = $types[0] ?? 'string';
        }
        if (isset($schema['properties'])) {
            $schema['properties'] = array_map([self::class, 'convertSchema'], $schema['properties']);
        }
        if (isset($schema['items'])) {
            $schema['items'] = self::convertSchema($schema['items']);
        }

        return $schema;
    }
}
