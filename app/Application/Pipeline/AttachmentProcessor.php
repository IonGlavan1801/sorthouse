<?php

namespace App\Application\Pipeline;

use App\Infrastructure\AI\Contracts\DocumentExtractor;
use App\Infrastructure\AI\ProviderGateway;
use App\Models\TriageCase;
use Illuminate\Support\Facades\Storage;

/** Runs document extraction only for attachments that passed the security gate. */
final class AttachmentProcessor
{
    /** @param list<DocumentExtractor> $extractors */
    public function __construct(private array $extractors, private ProviderGateway $gateway) {}

    /** @return array{processed:int,failed:int,skipped:int,retries:int} */
    public function process(TriageCase $case): array
    {
        $stats = ['processed' => 0, 'failed' => 0, 'skipped' => 0, 'retries' => 0];
        foreach ($case->message->attachments as $attachment) {
            if ($attachment->scan_status !== 'clean') {
                $stats['skipped']++;
                $attachment->update(['extraction_status' => 'skipped']);

                continue;
            }
            if (isset($attachment->metadata['sources'])) {
                $stats['processed']++; // idempotent re-entry

                continue;
            }
            $extractor = collect($this->extractors)->first(fn (DocumentExtractor $e) => $e->supports((string) $attachment->detected_mime));
            if (! $extractor) {
                $stats['skipped']++;
                $attachment->update(['extraction_status' => 'unsupported']);

                continue;
            }
            $path = Storage::disk('local')->path((string) $attachment->storage_key);
            try {
                $kind = str_starts_with((string) $attachment->detected_mime, 'image/') ? 'ocr' : 'parser';
                $call = $this->gateway->call($case, $kind === 'ocr' ? 'ocr' : 'pdf_parse', $kind === 'ocr' ? 'ocr-simulated' : 'pdfparser', 'local', fn () => $extractor->extract($attachment->id, $path, (string) $attachment->detected_mime, $attachment->metadata ?? []), $kind === 'ocr' ? 'ocr' : 'parse');
                $stats['retries'] += $call['retries'];
                $result = $call['result'];
                $blank = collect($result)->every(fn ($s) => trim($s->text) === '');
                if ($blank && isset($attachment->metadata['ocr'])) {
                    // image-only PDF: no text layer, so fall back to OCR
                    $ocr = collect($this->extractors)->first(fn (DocumentExtractor $e) => $e->supports('image/jpeg'));
                    $ocrCall = $this->gateway->call($case, 'ocr', 'ocr-simulated', 'local', fn () => $ocr->extract($attachment->id, $path, 'image/jpeg', $attachment->metadata), 'ocr');
                    $stats['retries'] += $ocrCall['retries'];
                    $call['result'] = $ocrCall['result'];
                }
                $sources = array_map(fn ($s) => $s->toArray() + ['text' => $s->text, 'words' => $s->words, 'image_path' => $s->imagePath], $call['result']);
                $attachment->update(['extraction_status' => $sources ? 'ok' : 'empty', 'metadata' => ($attachment->metadata ?? []) + ['sources' => $sources]]);
                $stats['processed']++;
            } catch (\Throwable $e) {
                $attachment->update(['extraction_status' => 'failed']);
                $stats['failed']++;
            }
        }

        return $stats;
    }
}
