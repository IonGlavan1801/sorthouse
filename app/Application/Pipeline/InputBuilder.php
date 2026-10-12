<?php

namespace App\Application\Pipeline;

use App\Infrastructure\AI\DTO\CaseInput;
use App\Infrastructure\AI\DTO\SourceDoc;
use App\Models\TriageCase;

/** Assembles the model input from persisted data only: subject, header, body and the sources extracted from clean attachments. */
final class InputBuilder
{
    public function build(TriageCase $case): CaseInput
    {
        $message = $case->message()->with(['body', 'attachments'])->firstOrFail();
        $sources = [
            new SourceDoc('msg:subject', 'email_body', $message->subject, label: 'Subject'),
            new SourceDoc('msg:header', 'email_body', 'From: '.trim(($message->sender_name ? $message->sender_name.' ' : '').'<'.$message->sender_email.'>')."\nTo: ".implode(', ', $message->recipients), label: 'Header'),
            new SourceDoc('msg:body', 'email_body', $message->body?->text_plain ?? '', label: 'Email body'),
        ];
        foreach ($message->attachments as $attachment) {
            if ($attachment->scan_status !== 'clean') {
                continue;
            }
            foreach ($attachment->metadata['sources'] ?? [] as $s) {
                $sources[] = new SourceDoc($s['id'], $s['type'], $s['text'], $s['page'] ?? null, $s['words'] ?? [], $s['image_path'] ?? null, $attachment->filename.($s['page'] ? ' · p'.$s['page'] : ''));
            }
        }

        return new CaseInput($message->subject, $message->sender_email, $message->sender_name, $sources);
    }
}
