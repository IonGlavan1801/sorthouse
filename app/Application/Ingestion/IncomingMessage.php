<?php

namespace App\Application\Ingestion;

use Carbon\CarbonInterface;

final readonly class IncomingMessage
{
    /**
     * @param  list<string>  $recipients
     * @param  list<array{filename:string,declared_mime:?string,bytes:string,metadata?:array}>  $attachments
     */
    public function __construct(
        public string $provider,
        public string $externalId,
        public ?string $threadId,
        public ?string $senderName,
        public string $senderEmail,
        public array $recipients,
        public string $subject,
        public CarbonInterface $receivedAt,
        public string $textBody,
        public ?string $htmlBody = null,
        public array $attachments = [],
        public ?string $fixtureKey = null,
    ) {}

    /** Fingerprint that survives a different external id (the same mail delivered twice by a flaky provider). */
    public function contentHash(): string
    {
        return hash('sha256', json_encode([
            strtolower($this->senderEmail),
            $this->subject,
            preg_replace('/\s+/', ' ', trim($this->textBody)),
            array_map(fn ($a) => hash('sha256', $a['bytes']), $this->attachments),
        ]));
    }
}
