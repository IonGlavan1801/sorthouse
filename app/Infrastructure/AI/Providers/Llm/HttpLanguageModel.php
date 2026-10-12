<?php

namespace App\Infrastructure\AI\Providers\Llm;

use App\Infrastructure\AI\Contracts\LanguageModel;
use App\Infrastructure\AI\DTO\ProviderMeta;
use App\Infrastructure\AI\Exceptions\ProviderException;
use App\Infrastructure\AI\Exceptions\ProviderInvalidOutput;
use App\Infrastructure\AI\Exceptions\ProviderRateLimited;
use App\Infrastructure\AI\Exceptions\ProviderTimeout;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

abstract class HttpLanguageModel implements LanguageModel
{
    public function __construct(protected string $apiKey, protected string $model, protected string $baseUrl, protected int $timeoutSeconds = 45) {}

    public function model(): string
    {
        return $this->model;
    }

    protected function http(): PendingRequest
    {
        if ($this->apiKey === '' || $this->model === '') {
            throw new ProviderException(ucfirst($this->name()).' is not configured (API key or model missing)', 'not_configured');
        }

        return Http::timeout($this->timeoutSeconds)->acceptJson()->asJson();
    }

    /** Executes the request and maps transport/HTTP problems to typed provider exceptions. */
    protected function send(callable $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            throw new ProviderTimeout($e->getMessage());
        }
        if ($response->status() === 429) {
            throw new ProviderRateLimited;
        }
        if ($response->serverError()) {
            throw new ProviderException(ucfirst($this->name()).' server error '.$response->status(), 'server_error', true);
        }
        if ($response->failed()) {
            throw new ProviderException(ucfirst($this->name()).' request failed with HTTP '.$response->status(), 'client_error');
        }

        return $response;
    }

    protected function decode(?string $text): array
    {
        $json = json_decode($text ?? '', true);
        if (! is_array($json)) {
            throw new ProviderInvalidOutput;
        }

        return $json;
    }

    /** @param list<string> $paths local image files */
    protected function imageParts(array $paths): array
    {
        $parts = [];
        foreach (array_slice($paths, 0, 3) as $path) {
            if (is_file($path)) {
                $parts[] = ['mime' => mime_content_type($path) ?: 'image/jpeg', 'data' => base64_encode((string) file_get_contents($path))];
            }
        }

        return $parts;
    }

    protected function meta(int $in, int $out, int $images, int $latencyMs): ProviderMeta
    {
        return new ProviderMeta($this->name(), $this->model, 'adapter', $in, $out, $images, $latencyMs);
    }

    public function capabilities(): array
    {
        return \App\Infrastructure\AI\CapabilityMatrix::all()[$this->name()] ?? [];
    }
}
