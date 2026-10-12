<?php

namespace App\Infrastructure\AI\Providers;

use App\Infrastructure\AI\Contracts\Drafter;
use App\Infrastructure\AI\DTO\CaseInput;
use App\Infrastructure\AI\DTO\DraftResult;
use App\Infrastructure\AI\DTO\ProviderMeta;

/** Template drafter: states only facts present in the message and never promises prices, dates or outcomes. */
final class HeuristicDrafter implements Drafter
{
    public const TEMPLATE = 'reply.v1';

    public function draft(CaseInput $input, string $category, array $facts): DraftResult
    {
        $started = hrtime(true);
        $name = $facts['customer_name'] ?? $facts['contact_name'] ?? null;
        $greeting = $name ? "Hello {$name}," : 'Hello,';
        $claims = [];
        $lines = [$greeting, ''];

        if ($category === 'customer_complaint') {
            $order = $facts['order_number'] ?? null;
            $lines[] = $order ? "Thank you for contacting us about order {$order}. We are sorry about the problem you describe." : 'Thank you for contacting us. We are sorry about the problem you describe.';
            if ($order) {
                $claims[] = ['text' => "Order {$order}", 'evidence_refs' => $facts['_evidence']['order_number'] ?? []];
            }
            if (! empty($facts['issue_summary'])) {
                $lines[] = 'You wrote: "'.$facts['issue_summary'].'"';
                $claims[] = ['text' => 'Issue as described by the customer', 'evidence_refs' => $facts['_evidence']['issue_summary'] ?? []];
            }
            if (! empty($facts['requested_resolution'])) {
                $lines[] = 'We have noted your request: "'.$facts['requested_resolution'].'"';
                $claims[] = ['text' => 'Requested resolution as described by the customer', 'evidence_refs' => $facts['_evidence']['requested_resolution'] ?? []];
            }
            $lines[] = 'A member of our team will review the case and reply with next steps.';
            $subject = 'Re: '.$input->subject;
        } else {
            $lines[] = 'Thank you for your request. We have received the following items:';
            foreach ($facts['items'] ?? [] as $item) {
                $qty = $item['quantity'] ?? null;
                $lines[] = '- '.($qty ? "{$qty} x " : '').$item['description'];
            }
            if (! empty($facts['items'])) {
                $claims[] = ['text' => 'Requested items', 'evidence_refs' => $facts['_evidence']['items'] ?? []];
            }
            if (! empty($facts['deadline'])) {
                $lines[] = "We have noted the requested deadline: {$facts['deadline']}.";
                $claims[] = ['text' => 'Requested deadline', 'evidence_refs' => $facts['_evidence']['deadline'] ?? []];
            }
            $lines[] = 'A colleague will come back with a formal quotation. This message is not an offer and contains no prices or commitments.';
            $subject = 'Re: '.$input->subject;
        }

        $lines[] = '';
        $lines[] = 'Kind regards,';
        $lines[] = 'Support team';

        $meta = new ProviderMeta('heuristic', HeuristicClassifier::MODEL, self::TEMPLATE, 80, 120, latencyMs: (int) ((hrtime(true) - $started) / 1e6), simulated: true);

        return new DraftResult($subject, implode("\n", $lines), $claims, $meta);
    }
}
