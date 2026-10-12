<?php

namespace App\Infrastructure\Documents;

use App\Infrastructure\AI\Contracts\DocumentExtractor;
use App\Infrastructure\AI\DTO\SourceDoc;
use Smalot\PdfParser\Parser;

/** Text-layer extraction only. The PDF is parsed, never rendered or executed (no JavaScript, no embedded files). */
final class PdfTextExtractor implements DocumentExtractor
{
    public function supports(string $detectedMime): bool
    {
        return $detectedMime === 'application/pdf';
    }

    public function extract(string $attachmentId, string $absolutePath, string $detectedMime, array $metadata = []): array
    {
        $max = (int) config('sorthouse.limits.pdf_max_pages');
        $pdf = (new Parser)->parseFile($absolutePath);
        $sources = [];
        foreach (array_slice($pdf->getPages(), 0, $max) as $i => $page) {
            $text = trim(preg_replace("/[ \t]+/", ' ', $page->getText()) ?? '');
            $sources[] = new SourceDoc("att:{$attachmentId}:p".($i + 1), 'pdf_text', $text, $i + 1, label: 'PDF page '.($i + 1));
        }

        return $sources;
    }
}
