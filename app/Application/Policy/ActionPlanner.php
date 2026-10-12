<?php

namespace App\Application\Policy;

use App\Domain\Triage\ActionType;
use App\Domain\Triage\Category;
use Illuminate\Support\Facades\Validator;

/** Builds the action the *application* intends to run. The model only influences content, never the action type or recipients. */
final class ActionPlanner
{
    /** @return array{type:string,params:array}|null null when the params fail their own schema */
    public function plan(Category $category, array $facts, ?string $draftId, array $invoiceObject = [], string $schemaVersion = ''): ?array
    {
        $type = $category->defaultAction();
        $params = match ($type) {
            ActionType::MoveToDemoFolder => ['folder' => 'spam'],
            ActionType::MarkDemoStatus => ['status' => 'waiting'],
            ActionType::CreateDemoTask => [
                'title' => mb_substr(match ($category) {
                    Category::RequestForQuote => 'Prepare quote'.(! empty($facts['company_name']) ? ' for '.$facts['company_name'] : ''),
                    default => 'Process purchase request'.(! empty($facts['company_name']) ? ' from '.$facts['company_name'] : ''),
                }, 0, 200),
                'details' => mb_substr(collect($facts['items'] ?? [])->map(fn ($i) => (($i['quantity'] ?? null) ? $i['quantity'].' x ' : '').$i['description'])->implode('; '), 0, 2000),
            ],
            ActionType::QueueReply => ['draft_id' => (string) $draftId],
            ActionType::ExportInvoiceJson => ['schema_version' => $schemaVersion, 'payload' => $invoiceObject],
            default => ['reason' => 'Category is other_or_uncertain'],
        };

        $valid = Validator::make($params, $type->paramRules())->passes();

        return $valid ? ['type' => $type->value, 'params' => $params] : null;
    }
}
