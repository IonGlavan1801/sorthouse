<?php

namespace App\Domain\Triage;

enum ActionType: string
{
    case CreateDemoTask = 'create_demo_task';
    case MoveToDemoFolder = 'move_to_demo_folder';
    case MarkDemoStatus = 'mark_demo_status';
    case QueueReply = 'queue_reply';
    case SendReply = 'send_reply';
    case ExportInvoiceJson = 'export_invoice_json';
    case RequestHumanReview = 'request_human_review';

    /** Reversible, internal-only effects that the demo may run without a human. */
    public function isLowImpact(): bool
    {
        return in_array($this, [self::CreateDemoTask, self::MoveToDemoFolder, self::MarkDemoStatus], true);
    }

    /** Parameters the application accepts for the action; anything else is rejected. */
    public function paramRules(): array
    {
        return match ($this) {
            self::CreateDemoTask => ['title' => 'required|string|max:200', 'details' => 'nullable|string|max:2000'],
            self::MoveToDemoFolder => ['folder' => 'required|in:spam,quarantine,archive'],
            self::MarkDemoStatus => ['status' => 'required|in:answered,archived,waiting'],
            self::QueueReply, self::SendReply => ['draft_id' => 'required|uuid'],
            self::ExportInvoiceJson => ['schema_version' => 'required|string', 'payload' => 'required|array'],
            self::RequestHumanReview => ['reason' => 'nullable|string|max:300'],
        };
    }
}
