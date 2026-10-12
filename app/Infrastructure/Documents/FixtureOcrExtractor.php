<?php

namespace App\Infrastructure\Documents;

use App\Infrastructure\AI\Contracts\DocumentExtractor;
use App\Infrastructure\AI\DTO\SourceDoc;

/**
 * SIMULATED OCR: image attachments in the synthetic corpus ship the OCR words (text, bounding box, confidence) that an OCR engine
 * would return, including deliberate misreads such as 8/B. This adapter turns them into a source; it does not read pixels.
 */
final class FixtureOcrExtractor implements DocumentExtractor
{
    public function supports(string $detectedMime): bool
    {
        return in_array($detectedMime, ['image/jpeg', 'image/png'], true);
    }

    public function extract(string $attachmentId, string $absolutePath, string $detectedMime, array $metadata = []): array
    {
        $words = $metadata['ocr']['words'] ?? [];
        if ($words === []) {
            return [];
        }
        $text = SourceDoc::textFromWords($words);

        return [new SourceDoc("att:{$attachmentId}:p1", 'image_ocr', $text, 1, $words, $absolutePath, 'Image (simulated OCR)')];
    }
}
