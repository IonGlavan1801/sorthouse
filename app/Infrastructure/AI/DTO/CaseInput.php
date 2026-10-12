<?php

namespace App\Infrastructure\AI\DTO;

final readonly class CaseInput
{
    /** @param list<SourceDoc> $sources */
    public function __construct(
        public string $subject,
        public string $senderEmail,
        public ?string $senderName,
        public array $sources,
    ) {}

    public function source(string $id): ?SourceDoc
    {
        foreach ($this->sources as $source) {
            if ($source->id === $id) {
                return $source;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function sourceIds(): array
    {
        return array_map(fn (SourceDoc $s) => $s->id, $this->sources);
    }

    public function fullText(): string
    {
        return $this->subject."\n".implode("\n", array_map(fn (SourceDoc $s) => $s->text, $this->sources));
    }

    public function hash(): string
    {
        return hash('sha256', json_encode([$this->subject, $this->senderEmail, array_map(fn (SourceDoc $s) => [$s->id, hash('sha256', $s->text)], $this->sources)]));
    }
}
