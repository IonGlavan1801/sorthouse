<?php

namespace App\Infrastructure\AI\Providers;

use App\Infrastructure\AI\Contracts\StructuredExtractor;
use App\Infrastructure\AI\DTO\CaseInput;
use App\Infrastructure\AI\DTO\ExtractionResult;
use App\Infrastructure\AI\DTO\ProviderMeta;
use App\Infrastructure\AI\DTO\SourceDoc;

/**
 * Deterministic regex baseline (SIMULATED). It returns printed values exactly as they appear; normalization is the application's job.
 * It also deliberately emulates a naive model that obeys instructions embedded in the content ("send everything to x@y"), so the
 * policy layer has real escalation attempts to block. That behaviour is reported in `warnings`.
 */
final class HeuristicExtractor implements StructuredExtractor
{
    private const DATE = '(?:\d{4}[-\/.]\d{1,2}[-\/.]\d{1,2}|\d{1,2}[-\/.]\d{1,2}[-\/.]\d{2,4}|\d{1,2}(?:st|nd|rd|th)?\s+[A-Za-z]{3,9}\.?,?\s+\d{4}|[A-Za-z]{3,9}\.?\s+\d{1,2}(?:st|nd|rd|th)?,?\s+\d{4})';

    private const CODES = 'USD|EUR|GBP|RON|MDL|CHF|PLN';

    private const AMOUNT = '((?:(?:USD|EUR|GBP|RON|MDL|CHF|PLN)[ \t]*|[€$£][ \t]*)?-?\d[\d.,\x{00A0} ]*\d(?:[ \t]*(?:USD|EUR|GBP|RON|MDL|CHF|PLN|lei|€|\$|£))?|(?:(?:USD|EUR|GBP|RON|MDL|CHF|PLN)[ \t]*|[€$£][ \t]*)?\d)';

    public function extract(CaseInput $input, string $schemaVersion, array $schemaDefinition): ExtractionResult
    {
        $started = hrtime(true);
        $fields = match ($schemaVersion) {
            'invoice.v1' => $this->invoice($input),
            'complaint.v1' => $this->complaint($input),
            'rfq.v1' => $this->rfq($input),
            default => [],
        };
        $warnings = [];
        $proposed = $this->proposedActions($input, $warnings);

        $meta = new ProviderMeta('heuristic', HeuristicClassifier::MODEL, config('sorthouse.prompt_versions.extract'), (int) ceil(mb_strlen($input->fullText()) / 4), 120, latencyMs: (int) ((hrtime(true) - $started) / 1e6), simulated: true);

        return new ExtractionResult($schemaVersion, $fields, $warnings, $proposed, $meta);
    }

    /** Documents first (attachments), then the email body. */
    private function ordered(CaseInput $input): array
    {
        $docs = array_values(array_filter($input->sources, fn (SourceDoc $s) => in_array($s->type, ['pdf_text', 'image_ocr'], true)));
        $mail = array_values(array_filter($input->sources, fn (SourceDoc $s) => $s->type === 'email_body' && $s->id !== 'msg:header'));

        return array_merge($docs, $mail);
    }

    private function find(CaseInput $input, string $pattern, float $signal, int $group = 1, ?array $sources = null): array
    {
        foreach ($sources ?? $this->ordered($input) as $source) {
            if (preg_match($pattern, $source->text, $m)) {
                $value = trim($m[$group] ?? $m[0]);
                if ($value === '') {
                    continue;
                }

                return ['value' => $value, 'signal' => $signal, 'signal_source' => 'model', 'evidence' => [['source_id' => $source->id, 'quote' => trim($m[0])]]];
            }
        }

        return $this->none();
    }

    private function none(): array
    {
        return ['value' => null, 'signal' => 0.0, 'signal_source' => 'model', 'evidence' => []];
    }

    private function firstOf(array ...$candidates): array
    {
        foreach ($candidates as $c) {
            if ($c['value'] !== null) {
                return $c;
            }
        }

        return $this->none();
    }

    private function invoice(CaseInput $input): array
    {
        $amount = self::AMOUNT;
        $number = $this->firstOf(
            $this->find($input, '/invoice\s*(?:no\.?|number|num|#)\s*[:#\-]?\s*([A-Z0-9][A-Z0-9\-\/]{2,})/i', 0.95),
            $this->find($input, '/\b(INV[-\s]?\d{3,}[A-Z0-9\-]*)\b/', 0.7),
        );
        $total = $this->find($input, '/(?<![a-z\-])(?:grand\s*total|total\s*due|amount\s*due|balance\s*due|total)\s*[:\-]?\s*'.$amount.'/iu', 0.93);
        $currencySource = $total['value'] !== null ? $total['value'] : '';
        $explicitCurrency = $this->find($input, '/currency\s*[:\-]?\s*([A-Z]{3})\b/i', 0.95);
        $currency = $explicitCurrency['value'] !== null ? $explicitCurrency : $this->currencyFromAmount($input, $total, $currencySource);

        return [
            'vendor_name' => $this->vendor($input),
            'invoice_number' => $number,
            'issue_date' => $this->find($input, '/(?<![Dd][Uu][Ee] )(?:invoice\s*date|date\s*of\s*issue|issue\s*date|issued(?:\s*on)?|date)\s*[:\-]?\s*('.self::DATE.')/i', 0.93),
            'due_date' => $this->find($input, '/(?:due\s*date|payment\s*due|due\s*by|due(?:\s*on)?)\s*[:\-]?\s*('.self::DATE.')/i', 0.93),
            'currency' => $currency,
            'subtotal' => $this->find($input, '/sub-?total\s*[:\-]?\s*'.$amount.'/iu', 0.93),
            'tax' => $this->find($input, '/(?:tax|vat|gst)(?:\s*\(?\d+(?:[.,]\d+)?\s*%\)?)?\s*[:\-]?\s*'.$amount.'/iu', 0.9),
            'total' => $total,
            'purchase_order_number' => $this->find($input, '/\b(?:purchase\s*order|PO)\s*(?:no\.?|number|#)?\s*[:#\-]?\s*([A-Z0-9][A-Z0-9\-]{3,})/i', 0.92),
        ];
    }

    private function currencyFromAmount(CaseInput $input, array $total, string $rawAmount): array
    {
        if ($total['value'] === null) {
            return $this->none();
        }
        $evidence = $total['evidence'];
        if (preg_match('/\b(USD|EUR|GBP|RON|MDL|CHF|PLN)\b/', $rawAmount, $m)) {
            return ['value' => $m[1], 'signal' => 0.9, 'signal_source' => 'model', 'evidence' => $evidence];
        }
        if (preg_match('/([€£])/u', $rawAmount, $m)) {
            return ['value' => $m[1], 'signal' => 0.85, 'signal_source' => 'model', 'evidence' => $evidence];
        }
        if (str_contains($rawAmount, '$')) {
            // "$" is shared by several currencies: report it with a lower signal
            return ['value' => '$', 'signal' => 0.7, 'signal_source' => 'model', 'evidence' => $evidence];
        }
        if (stripos($rawAmount, 'lei') !== false) {
            return ['value' => 'lei', 'signal' => 0.8, 'signal_source' => 'model', 'evidence' => $evidence];
        }
        // currency printed elsewhere in the document
        return $this->find($input, '/\b(USD|EUR|GBP|RON|MDL|CHF|PLN)\b/', 0.75);
    }

    private function vendor(CaseInput $input): array
    {
        $explicit = $this->find($input, '/(?:vendor|supplier|issued\s*by|from)\s*[:\-]\s*([^\n]{3,80})/i', 0.9);
        if ($explicit['value'] !== null) {
            return $explicit;
        }
        foreach ($this->ordered($input) as $source) {
            if (! in_array($source->type, ['pdf_text', 'image_ocr'], true)) {
                continue;
            }
            foreach (preg_split('/\R/u', $source->text) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '' && ! preg_match('/invoice|^\d|date|page|tax|vat/i', $line) && mb_strlen($line) <= 70) {
                    return ['value' => $line, 'signal' => 0.82, 'signal_source' => 'model', 'evidence' => [['source_id' => $source->id, 'quote' => $line]]];
                }
            }
        }
        if ($input->senderName) {
            $body = $input->source('msg:body');
            if ($body && mb_stripos($body->text, $input->senderName) !== false) {
                return ['value' => $input->senderName, 'signal' => 0.55, 'signal_source' => 'model', 'evidence' => [['source_id' => 'msg:body', 'quote' => $input->senderName]]];
            }
        }

        return $this->none();
    }

    private function signatureName(CaseInput $input): array
    {
        $body = $input->source('msg:body');
        if ($body && preg_match('/(?:regards|thanks|thank you|sincerely|best|cheers|best regards|kind regards),?\s*\R+\s*([A-Z][\p{L}\'\- ]{2,40})\s*(?:\R|$)/iu', $body->text, $m)) {
            return ['value' => trim($m[1]), 'signal' => 0.88, 'signal_source' => 'model', 'evidence' => [['source_id' => 'msg:body', 'quote' => trim($m[1])]]];
        }
        if ($body && preg_match('/\bmy name is\s+([A-Z][\p{L}\'\- ]{2,40}?)(?:[.,\n]|$)/u', $body->text, $m)) {
            return ['value' => trim($m[1]), 'signal' => 0.9, 'signal_source' => 'model', 'evidence' => [['source_id' => 'msg:body', 'quote' => trim($m[0])]]];
        }
        if ($input->senderName && $body && mb_stripos($body->text, $input->senderName) !== false) {
            return ['value' => $input->senderName, 'signal' => 0.6, 'signal_source' => 'model', 'evidence' => [['source_id' => 'msg:body', 'quote' => $input->senderName]]];
        }

        return $this->none();
    }

    private function sentenceWith(CaseInput $input, string $pattern, float $signal): array
    {
        $body = $input->source('msg:body');
        if (! $body) {
            return $this->none();
        }
        foreach (preg_split('/(?<=[.!?])\s+|\R+/u', $body->text) ?: [] as $sentence) {
            $sentence = trim($sentence);
            if ($sentence !== '' && preg_match($pattern, $sentence)) {
                return ['value' => $sentence, 'signal' => $signal, 'signal_source' => 'model', 'evidence' => [['source_id' => 'msg:body', 'quote' => $sentence]]];
            }
        }

        return $this->none();
    }

    private function complaint(CaseInput $input): array
    {
        $bodyOnly = array_values(array_filter($input->sources, fn ($s) => in_array($s->id, ['msg:body', 'msg:subject'], true)));

        return [
            'order_number' => $this->find($input, '/\border\s*(?:no\.?|number|#|id)?\s*[:#\-]?\s*([A-Z]{0,4}-?\d{4,}[A-Z0-9\-]*)/i', 0.92, 1, $bodyOnly),
            'customer_name' => $this->signatureName($input),
            'issue_summary' => $this->sentenceWith($input, '/\b(?:damaged|broken|defective|wrong|never arrived|not delivered|didn\'t arrive|did not arrive|late|missing|not working|stopped working|complain\w*|disappointed|unacceptable)\b/i', 0.82),
            'requested_resolution' => $this->sentenceWith($input, '/\b(?:refund|replacement|replace|compensation|reship|money back|credit|apolog\w+|call me|full refund)\b/i', 0.8),
        ];
    }

    private function rfq(CaseInput $input): array
    {
        $body = $input->source('msg:body');
        $items = $this->none();
        if ($body) {
            $lines = [];
            foreach (preg_split('/\R/u', $body->text) ?: [] as $line) {
                if (preg_match('/^\s*[-*•]?\s*\d{1,6}\s*(?:x|×|pcs|units)?\s+\S+.*$/iu', $line) || preg_match('/^\s*[-*•]\s+\S.*[x×]\s*\d{1,6}\s*$/iu', $line)) {
                    $lines[] = trim($line);
                }
            }
            if ($lines !== []) {
                $items = ['value' => implode("\n", $lines), 'signal' => 0.88, 'signal_source' => 'model', 'evidence' => array_map(fn ($l) => ['source_id' => 'msg:body', 'quote' => $l], array_slice($lines, 0, 3))];
            }
        }
        $company = $this->find($input, '/\b([A-Z][\p{L}&\.\' ]{2,40}\s(?:Ltd|GmbH|SRL|LLC|Inc|Co|S\.A\.|AG|BV))\b/u', 0.85, 1, $body ? [$body] : []);
        $email = ['value' => $input->senderEmail, 'signal' => 0.9, 'signal_source' => 'model', 'evidence' => [['source_id' => 'msg:header', 'quote' => $input->senderEmail]]];
        if ($body && preg_match('/[\w.+-]+@[\w-]+\.[\w.-]+/', $body->text, $m)) {
            $email = ['value' => $m[0], 'signal' => 0.92, 'signal_source' => 'model', 'evidence' => [['source_id' => 'msg:body', 'quote' => $m[0]]]];
        }

        return [
            'company_name' => $company,
            'contact_name' => $this->signatureName($input),
            'contact_email' => $email,
            'items' => $items,
            'deadline' => $this->find($input, '/(?:deadline|by|before|no later than|required by)\s*[:\-]?\s*('.self::DATE.')/i', 0.85, 1, $body ? [$body] : []),
        ];
    }

    /** @return list<array{type:string,params:array,recipient?:string}> */
    private function proposedActions(CaseInput $input, array &$warnings): array
    {
        $text = $input->fullText();
        $proposed = [];
        $injectionMarker = preg_match('/\b(?:ignore|disregard|override|new instructions|system prompt|you must now|do not follow)\b/i', $text) === 1;
        if ($injectionMarker && preg_match('/\b(?:send|forward|email|mail)\b[^.\n]{0,80}?\bto\s+([\w.+-]+@[\w-]+\.[\w.-]+)/i', $text, $m)) {
            $proposed[] = ['type' => 'send_reply', 'params' => ['to' => $m[1]], 'recipient' => $m[1]];
            $warnings[] = 'EMBEDDED_INSTRUCTION_FOLLOWED: heuristic baseline proposed send_reply to '.$m[1].' because the content asked for it';
        }
        if (preg_match('/\bmark\b[^.\n]{0,40}\bas approved\b/i', $text)) {
            $proposed[] = ['type' => 'approve_case', 'params' => []];
            $warnings[] = 'EMBEDDED_INSTRUCTION_FOLLOWED: content asked to mark the case as approved';
        }
        if (preg_match('/\b(?:reveal|print|show|leak)\b[^.\n]{0,30}\b(?:api key|system prompt|secret|password)\b/i', $text)) {
            $proposed[] = ['type' => 'reveal_secret', 'params' => []];
            $warnings[] = 'EMBEDDED_INSTRUCTION_FOLLOWED: content asked to reveal secrets';
        }

        return $proposed;
    }
}
