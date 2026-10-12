<?php

namespace App\Infrastructure\AI\Providers;

use App\Domain\Triage\Category;
use App\Infrastructure\AI\Contracts\Classifier;
use App\Infrastructure\AI\DTO\CaseInput;
use App\Infrastructure\AI\DTO\ClassificationResult;
use App\Infrastructure\AI\DTO\ProviderMeta;

/** Deterministic keyword baseline. It is a rules engine, not an LLM, and every result is labelled SIMULATED in the UI. */
final class HeuristicClassifier implements Classifier
{
    public const MODEL = 'heuristic-baseline-v1';

    /** @return array<string,list<array{0:string,1:float,2:string}>> */
    private function rules(): array
    {
        return [
            Category::Invoice->value => [
                ['/\binvoice\b/i', 1.5, 'KW_INVOICE'],
                ['/invoice\s*(?:no\.?|number|num|#)/i', 2.0, 'KW_INVOICE_NUMBER'],
                ['/\b(?:amount due|total due|balance due|payment terms|net\s*\d{1,2}\b)/i', 1.5, 'KW_PAYMENT_TERMS'],
                ['/\b(?:due date|payment due|due by)\b/i', 1.0, 'KW_DUE_DATE'],
                ['/\b(?:subtotal|sub-total|vat|tax)\b/i', 0.8, 'KW_TAX_LINES'],
                ['/\bplease find (?:the )?attached invoice\b/i', 1.0, 'KW_ATTACHED_INVOICE'],
            ],
            Category::CustomerComplaint->value => [
                ['/\b(?:complain\w*|disappointed|unacceptable|damaged|broken|defective|wrong item|never arrived|not delivered|didn\'t arrive|did not arrive|late delivery|arrived late|missing parts?|not working|stopped working)\b/i', 1.6, 'KW_COMPLAINT'],
                ['/\b(?:refund|replacement|compensation|reship|money back|credit note)\b/i', 1.3, 'KW_RESOLUTION'],
                ['/\border\s*(?:no\.?|number|#|id)?\s*[:#]?\s*[A-Z]{0,4}-?\d{4,}/i', 1.0, 'KW_ORDER_REF'],
                ['/\b(?:very unhappy|terrible|worst|furious|fed up)\b/i', 1.0, 'KW_TONE'],
            ],
            Category::RequestForQuote->value => [
                ['/\b(?:quote|quotation|rfq|request for (?:a )?quote|price list|pricing for|your best price)\b/i', 2.2, 'KW_QUOTE'],
                ['/\b(?:could|can) you (?:please )?(?:send|provide|give) (?:us |me )?(?:a |an )?(?:quote|offer|price|quotation)\b/i', 1.8, 'KW_QUOTE_ASK'],
                ['/^\s*[-*•]?\s*\d{1,6}\s*(?:x|×|pcs|units)\s+\S+/im', 1.2, 'KW_QUANTITY_LINES'],
                ['/\b(?:deadline|no later than|need (?:it|them|this) by|required by)\b/i', 0.6, 'KW_DEADLINE'],
            ],
            Category::OrderOrPurchaseRequest->value => [
                ['/\b(?:please (?:place|process|confirm) (?:an |our |the )?order|we would like to (?:place an )?order|i would like to (?:place an )?order|want to buy|purchase order (?:attached|enclosed))\b/i', 3.0, 'KW_ORDER_INTENT'],
                ['/\bpo\s*#?\s*\d{4,}\b/i', 0.8, 'KW_PO_REF'],
            ],
            Category::GeneralQuestion->value => [
                ['/\b(?:opening hours|working hours|do you (?:have|offer|ship|sell|deliver)|how (?:do|can) i|is it possible|where can i|what is the|which (?:plan|option)|are you open)\b/i', 1.8, 'KW_QUESTION_PHRASE'],
                ['/\?/', 0.8, 'KW_QUESTION_MARK'],
                ['/\b(?:wondering|curious|information about|more information|quick question)\b/i', 1.0, 'KW_INQUIRY'],
            ],
            Category::SpamOrPhishing->value => [
                ['/\b(?:winner|lottery|prize|crypto|bitcoin|click here|limited time|act now|100% free|guaranteed|earn \$|make money|unsubscribe|congratulations)\b/i', 1.7, 'KW_SPAM'],
                ['/\b(?:verify your (?:account|password|identity)|password (?:expires|expiry|reset required)|account (?:suspended|locked|will be closed)|confirm your identity|security alert|unusual sign-?in)\b/i', 2.5, 'KW_PHISHING'],
                ['#https?://\d{1,3}(?:\.\d{1,3}){3}#i', 2.0, 'URL_IP_HOST'],
                ['#https?://\S*\.(?:xyz|top|click|work|zip)\b#i', 1.5, 'URL_SUSPICIOUS_TLD'],
            ],
        ];
    }

    public function classify(CaseInput $input): ClassificationResult
    {
        $started = hrtime(true);
        $scores = [];
        $evidence = [];
        $reasons = [];

        foreach ($this->rules() as $label => $rules) {
            $score = 0.0;
            foreach ($rules as [$pattern, $weight, $code]) {
                foreach ($input->sources as $source) {
                    if (preg_match($pattern, $source->text, $m)) {
                        // subject hits count a little more than body hits
                        $score += $source->id === 'msg:subject' ? $weight * 1.25 : $weight;
                        $evidence[$label][] = ['source_id' => $source->id, 'quote' => trim($m[0])];
                        $reasons[$label][] = $code;
                        break;
                    }
                }
            }
            $scores[$label] = $score;
        }

        // attachment filename hints
        foreach ($input->sources as $source) {
            if ($source->label !== null && stripos($source->label, 'invoice') !== false) {
                $scores[Category::Invoice->value] += 1.0;
            }
        }

        arsort($scores);
        $labels = array_keys($scores);
        $top = $scores[$labels[0]];
        $second = $scores[$labels[1]] ?? 0.0;
        $ratio = ($top - $second + 1.0) / ($top + $second + 1.2);
        // two labels too close, or too little evidence: honest "uncertain" instead of a weak guess
        $label = ($top >= 2.4 && $ratio >= 0.5) ? $labels[0] : Category::OtherOrUncertain->value;
        $signal = $label === Category::OtherOrUncertain->value
            ? min(0.55, 0.30 + 0.1 * $top)
            : min(0.98, $ratio * sqrt(min(1.0, $top / 4.0)));

        $alternatives = [];
        foreach (array_slice($scores, 0, 3, true) as $l => $s) {
            if ($s > 0) {
                $alternatives[] = ['label' => $l, 'score' => round($s / ($top + $second + 1.2), 4)];
            }
        }

        $chosen = $label === Category::OtherOrUncertain->value ? $labels[0] : $label;
        $meta = new ProviderMeta('heuristic', self::MODEL, config('sorthouse.prompt_versions.classify'), (int) ceil(mb_strlen($input->fullText()) / 4), 60, latencyMs: (int) ((hrtime(true) - $started) / 1e6), simulated: true);

        return new ClassificationResult(
            $label,
            $alternatives,
            ['model_signal' => round($signal, 4), 'top_score' => round($top, 2), 'runner_up_score' => round($second, 2), 'margin' => round($top - $second, 2)],
            array_values(array_unique($reasons[$chosen] ?? ['NO_STRONG_KEYWORDS'])),
            array_slice($evidence[$chosen] ?? [], 0, 3),
            $meta,
        );
    }
}
