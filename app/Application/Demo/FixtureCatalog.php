<?php

namespace App\Application\Demo;

use App\Application\Ingestion\IncomingMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/** Loads the synthetic corpus from /fixtures. Every message, invoice and OCR result in it is generated; nothing is real customer data. */
final class FixtureCatalog
{
    private static ?array $cache = null;

    public static function flush(): void
    {
        self::$cache = null;
    }

    /** @return array<string,array> keyed by fixture key */
    public function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $out = [];
        foreach (['emails', 'adversarial', 'failures'] as $dir) {
            $path = base_path("fixtures/{$dir}");
            if (! is_dir($path)) {
                continue;
            }
            foreach (File::files($path) as $file) {
                if ($file->getExtension() === 'json') {
                    $data = json_decode(File::get($file->getPathname()), true);
                    $out[$data['key']] = $data;
                }
            }
        }
        ksort($out);

        return self::$cache = $out;
    }

    public function get(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    /** @return list<array> */
    public function split(string $split): array
    {
        return array_values(array_filter($this->all(), fn ($f) => ($f['split'] ?? '') === $split));
    }

    /** Keys shown in the guided demo inbox, in display order. */
    public function showcase(): array
    {
        $items = $this->split('showcase');
        usort($items, fn ($a, $b) => ($a['order'] ?? 99) <=> ($b['order'] ?? 99));

        return array_column($items, 'key');
    }

    /** $variant appends a harmless reference line so a re-injected fixture is new content (otherwise it is, correctly, a duplicate). */
    public function toIncoming(array $fixture, ?string $externalId = null, ?int $minutesAgo = null, ?string $variant = null): IncomingMessage
    {
        $e = $fixture['email'];
        $attachments = [];
        foreach ($fixture['attachments'] ?? [] as $a) {
            $attachments[] = [
                'filename' => $a['filename'] ?? basename($a['file']),
                'declared_mime' => $a['declared_mime'] ?? null,
                'bytes' => isset($a['inline_bytes_b64']) ? base64_decode($a['inline_bytes_b64']) : (isset($a['generate_zeros']) ? str_repeat("\0", (int) $a['generate_zeros']) : File::get(base_path('fixtures/'.$a['file']))),
                'metadata' => isset($a['ocr']) ? ['ocr' => $a['ocr']] : [],
            ];
        }

        return new IncomingMessage(
            'demo', $externalId ?? $fixture['key'], $fixture['key'].'-thread', $e['from_name'] ?? null, $e['from_email'], [$e['to'] ?? 'inbox@acme-demo.example.test'],
            $e['subject'], CarbonImmutable::now()->subMinutes($minutesAgo ?? ($e['minutes_ago'] ?? 30)), $variant && $e['body'] !== '' ? $e['body']."\n\n[demo ref {$variant}]" : $e['body'], isset($e['html']) && $variant ? $e['html'].'<!-- ref '.$variant.' -->' : ($e['html'] ?? null), $attachments, $fixture['key'],
        );
    }
}
