<?php

namespace App\Infrastructure\AI\DTO;

/**
 * One evidence source with an application-controlled id.
 * type: email_body | pdf_text | image_ocr
 * words: OCR words as [{t, x, y, w, h, conf, nl?}] with coordinates relative to the page (0..1); nl marks end of a line.
 */
final readonly class SourceDoc
{
    public function __construct(
        public string $id,
        public string $type,
        public string $text,
        public ?int $page = null,
        public array $words = [],
        public ?string $imagePath = null,
        public ?string $label = null,
    ) {}

    /** Text of OCR words with the line breaks the engine reported. Shared by the OCR extractor and the evidence resolver. */
    public static function textFromWords(array $words): string
    {
        $out = '';
        foreach ($words as $i => $w) {
            $out .= $w['t'];
            if ($i < count($words) - 1) {
                $out .= ! empty($w['nl']) ? "\n" : ' ';
            }
        }

        return $out;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'label' => $this->label,
            'page' => $this->page,
            'chars' => mb_strlen($this->text),
        ];
    }
}
