<?php

namespace App\Infrastructure\AI;

use App\Infrastructure\AI\Contracts\EvidenceResolver;
use App\Infrastructure\AI\DTO\CaseInput;
use App\Infrastructure\AI\DTO\SourceDoc;

/**
 * Resolves model-provided quotes against the real input. A model can never invent a locator: the source id must be one of
 * the case's own sources and the quote must actually occur in it.
 */
final class QuoteEvidenceResolver implements EvidenceResolver
{
    public function resolve(CaseInput $input, string $sourceId, string $quote): ?array
    {
        $source = $input->source($sourceId);
        $quote = trim($quote);
        if ($source === null || $quote === '') {
            return null;
        }

        if ($source->type === 'image_ocr' && $source->words !== []) {
            $viaWords = $this->resolveWords($source, $quote);
            if ($viaWords !== null) {
                return $viaWords;
            }
        }

        $span = $this->findSpan($source->text, $quote);
        if ($span === null) {
            return null;
        }
        [$start, $end] = $span;

        return [
            'source_id' => $source->id,
            'locator_type' => $source->type === 'email_body' ? 'span' : 'page_snippet',
            'span_start' => $start,
            'span_end' => $end,
            'page' => $source->page,
            'bbox' => null,
            'snippet' => $this->window($source->text, $start, $end),
            'provenance' => 'resolver',
            'ocr_confidence' => null,
        ];
    }

    /** @return array{0:int,1:int}|null character offsets (mb) of the quote inside text */
    private function findSpan(string $text, string $quote): ?array
    {
        $pos = mb_stripos($text, $quote);
        if ($pos !== false) {
            return [$pos, $pos + mb_strlen($quote)];
        }
        // whitespace-insensitive fallback
        $pattern = '/'.implode('\s+', array_map(fn ($w) => preg_quote($w, '/'), preg_split('/\s+/u', $quote, -1, PREG_SPLIT_NO_EMPTY) ?: [])).'/iu';
        if ($pattern !== '//iu' && preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
            $start = mb_strlen(substr($text, 0, $m[0][1]));

            return [$start, $start + mb_strlen($m[0][0])];
        }

        return null;
    }

    private function resolveWords(SourceDoc $source, string $quote): ?array
    {
        $tokens = preg_split('/\s+/u', mb_strtolower($quote), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = $source->words;
        $count = count($tokens);
        for ($i = 0; $i + $count <= count($words); $i++) {
            $ok = true;
            for ($j = 0; $j < $count; $j++) {
                if (mb_strtolower((string) $words[$i + $j]['t']) !== $tokens[$j]) {
                    $ok = false;
                    break;
                }
            }
            if (! $ok) {
                continue;
            }
            $slice = array_slice($words, $i, $count);
            $x1 = min(array_column($slice, 'x'));
            $y1 = min(array_column($slice, 'y'));
            $x2 = max(array_map(fn ($w) => $w['x'] + $w['w'], $slice));
            $y2 = max(array_map(fn ($w) => $w['y'] + $w['h'], $slice));
            $start = $i === 0 ? 0 : mb_strlen(SourceDoc::textFromWords(array_slice($words, 0, $i))) + 1;
            $quoteText = SourceDoc::textFromWords($slice);

            return [
                'source_id' => $source->id,
                'locator_type' => 'bbox',
                'span_start' => $start,
                'span_end' => $start + mb_strlen($quoteText),
                'page' => $source->page ?? 1,
                'bbox' => [round($x1, 4), round($y1, 4), round($x2 - $x1, 4), round($y2 - $y1, 4)],
                'snippet' => $this->window($source->text, $start, $start + mb_strlen($quoteText)),
                'provenance' => 'ocr',
                'ocr_confidence' => round((float) min(array_column($slice, 'conf')), 4),
            ];
        }

        return null;
    }

    private function window(string $text, int $start, int $end): string
    {
        $from = max(0, $start - 40);
        $len = min(mb_strlen($text) - $from, ($end - $start) + 80);

        return trim(mb_substr($text, $from, $len));
    }
}
