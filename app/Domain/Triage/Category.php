<?php

namespace App\Domain\Triage;

enum Category: string
{
    case Invoice = 'invoice';
    case CustomerComplaint = 'customer_complaint';
    case RequestForQuote = 'request_for_quote';
    case OrderOrPurchaseRequest = 'order_or_purchase_request';
    case GeneralQuestion = 'general_question';
    case SpamOrPhishing = 'spam_or_phishing';
    case OtherOrUncertain = 'other_or_uncertain';

    public const TAXONOMY_VERSION = 'taxonomy.v1';

    public function schemaKey(): ?string
    {
        return match ($this) {
            self::Invoice => 'invoice.v1',
            self::CustomerComplaint => 'complaint.v1',
            self::RequestForQuote => 'rfq.v1',
            default => null,
        };
    }

    /** @return list<ActionType> */
    public function allowedActions(): array
    {
        return match ($this) {
            self::Invoice => [ActionType::ExportInvoiceJson, ActionType::RequestHumanReview],
            self::CustomerComplaint => [ActionType::QueueReply, ActionType::SendReply, ActionType::RequestHumanReview],
            self::RequestForQuote => [ActionType::CreateDemoTask, ActionType::QueueReply, ActionType::RequestHumanReview],
            self::OrderOrPurchaseRequest => [ActionType::CreateDemoTask, ActionType::RequestHumanReview],
            self::GeneralQuestion => [ActionType::MarkDemoStatus, ActionType::QueueReply, ActionType::RequestHumanReview],
            self::SpamOrPhishing => [ActionType::MoveToDemoFolder, ActionType::RequestHumanReview],
            self::OtherOrUncertain => [ActionType::RequestHumanReview],
        };
    }

    public function defaultAction(): ActionType
    {
        return match ($this) {
            self::Invoice => ActionType::ExportInvoiceJson,
            self::CustomerComplaint => ActionType::QueueReply,
            self::RequestForQuote => ActionType::CreateDemoTask,
            self::OrderOrPurchaseRequest => ActionType::CreateDemoTask,
            self::GeneralQuestion => ActionType::MarkDemoStatus,
            self::SpamOrPhishing => ActionType::MoveToDemoFolder,
            self::OtherOrUncertain => ActionType::RequestHumanReview,
        };
    }

    /** Impact band used by policy: high-impact categories never auto-process. */
    public function risk(): string
    {
        return match ($this) {
            self::Invoice, self::CustomerComplaint, self::OrderOrPurchaseRequest => 'high',
            self::RequestForQuote, self::OtherOrUncertain => 'medium',
            self::GeneralQuestion, self::SpamOrPhishing => 'low',
        };
    }

    public function definition(): string
    {
        return match ($this) {
            self::Invoice => 'A request for payment from a vendor, usually with an invoice number, amounts and a due date.',
            self::CustomerComplaint => 'A customer reporting a problem with an order or service and asking for a resolution.',
            self::RequestForQuote => 'A prospect asking for a price or offer for listed products/quantities.',
            self::OrderOrPurchaseRequest => 'A firm order or purchase request that is not a quote request.',
            self::GeneralQuestion => 'A neutral informational question (opening hours, availability, how-to).',
            self::SpamOrPhishing => 'Unsolicited bulk, scam or credential-harvesting content.',
            self::OtherOrUncertain => 'Anything that does not clearly fit, or where two labels are close.',
        };
    }
}
