<?php

namespace App\Application\Ingestion;

use App\Infrastructure\AI\Contracts\MalwareScanner;

/** Inventory + security gate for one attachment. Real MIME is sniffed from bytes; the declared type is only a hint. */
final class AttachmentInspector
{
    public function __construct(private MalwareScanner $scanner) {}

    /** @return array{detected_mime:string,sha256:string,size:int,scan_status:string,scan_reason:?string,scan_mode:string} */
    public function inspect(string $filename, ?string $declaredMime, string $bytes): array
    {
        $size = strlen($bytes);
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
        $sha = hash('sha256', $bytes);
        $base = ['detected_mime' => $detected, 'sha256' => $sha, 'size' => $size, 'scan_mode' => 'SIMULATED'];

        if ($size > config('sorthouse.limits.attachment_max_bytes')) {
            return $base + ['scan_status' => 'rejected', 'scan_reason' => 'ATTACHMENT_TOO_LARGE'];
        }
        if (! in_array($detected, config('sorthouse.allowed_mimes'), true)) {
            return $base + ['scan_status' => 'quarantined', 'scan_reason' => 'MIME_NOT_ALLOWED'];
        }
        $scan = $this->scanner->scan($filename, $declaredMime, $detected, $bytes);

        return $base + ['scan_status' => $scan['status'], 'scan_reason' => $scan['reason'], 'scan_mode' => $scan['mode']];
    }
}
