<?php

namespace App\Application\Ingestion;

use DOMDocument;
use DOMElement;
use DOMNode;

/** Allow-list HTML sanitizer for the demo viewer. Active content is dropped, remote resources are never loaded, links become text. */
final class HtmlSanitizer
{
    private const ALLOWED = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'h1', 'h2', 'h3', 'h4', 'span', 'div', 'table', 'tbody', 'thead', 'tr', 'td', 'th'];

    private const DROP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'link', 'meta', 'form', 'input', 'button', 'svg', 'math', 'audio', 'video', 'noscript', 'template', 'head'];

    /** @return array{html:string,text:string,report:array} */
    public function sanitize(string $html): array
    {
        $report = ['removed_elements' => 0, 'blocked_remote_images' => 0, 'links_as_text' => 0, 'removed_attributes' => 0, 'tracking_pixels' => 0];
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><div id="root">'.$html.'</div>', LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementById('root');
        $clean = new DOMDocument;
        $out = $clean->createElement('div');
        $clean->appendChild($out);
        if ($root) {
            $this->copyChildren($root, $out, $clean, $report);
        }
        $result = '';
        foreach ($out->childNodes as $child) {
            $result .= $clean->saveHTML($child);
        }
        $text = trim(preg_replace("/[ \t]+/", ' ', html_entity_decode(strip_tags(preg_replace('#<br\s*/?>|</(p|div|li|tr|h\d)>#i', "\n", $result) ?? ''))) ?? '');
        $text = trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? '');

        return ['html' => $result, 'text' => $text, 'report' => $report];
    }

    private function copyChildren(DOMNode $from, DOMNode $to, DOMDocument $doc, array &$report): void
    {
        foreach ($from->childNodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                $to->appendChild($doc->createTextNode($node->textContent));

                continue;
            }
            if (! $node instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($node->tagName);
            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $report['removed_elements']++;

                continue;
            }
            if ($tag === 'img') {
                $w = (int) $node->getAttribute('width');
                $h = (int) $node->getAttribute('height');
                $isPixel = ($w > 0 && $w <= 2) || ($h > 0 && $h <= 2);
                $report[$isPixel ? 'tracking_pixels' : 'blocked_remote_images']++;
                $to->appendChild($doc->createTextNode($isPixel ? '' : '[remote image blocked]'));

                continue;
            }
            if ($tag === 'a') {
                $report['links_as_text']++;
                $this->copyChildren($node, $to, $doc, $report);
                $href = trim($node->getAttribute('href'));
                if ($href !== '' && preg_match('#^(https?:|mailto:)#i', $href)) {
                    $to->appendChild($doc->createTextNode(' ('.preg_replace('#^javascript:#i', '', $href).')'));
                }

                continue;
            }
            if (in_array($tag, self::ALLOWED, true)) {
                $el = $doc->createElement($tag);
                $report['removed_attributes'] += $node->attributes?->length ?? 0;
                $to->appendChild($el);
                $this->copyChildren($node, $el, $doc, $report);
            } else {
                $report['removed_elements']++;
                $this->copyChildren($node, $to, $doc, $report); // unknown wrapper: keep its text
            }
        }
    }
}
