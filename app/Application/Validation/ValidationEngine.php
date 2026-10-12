<?php

namespace App\Application\Validation;

use App\Domain\Extraction\Normalizer;
use App\Domain\Extraction\SchemaCatalog;
use App\Infrastructure\Faults\FaultInjector;
use App\Models\ExtractionRun;
use App\Models\FieldCorrection;
use App\Models\TriageCase;
use App\Models\ValidationRun;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Deterministic checks: JSON Schema, types, cross-field rules, reference lookup and evidence availability.
 * A schema-valid object is never treated as correct on that basis alone.
 */
final class ValidationEngine
{
    public const RULES_VERSION = 'rules.v1';

    public function __construct(private Normalizer $normalizer, private FaultInjector $faults) {}

    /** Effective values: predicted normalized values overlaid with the latest human correction per field. */
    public function effectiveFields(TriageCase $case, ExtractionRun $run): array
    {
        $corrections = FieldCorrection::where('triage_case_id', $case->id)->orderBy('created_at')->get()->keyBy('field_name');
        $out = [];
        foreach ($run->fields()->with('evidence')->get() as $f) {
            $c = $corrections->get($f->field_name);
            $out[$f->field_name] = [
                'field' => $f,
                'raw' => $c ? $c->corrected_value : $f->predicted_value,
                'normalized' => $c ? $c->normalized_value : $f->normalized_value,
                'corrected' => $c !== null,
                'has_evidence' => $f->evidence->isNotEmpty(),
            ];
        }

        return $out;
    }

    /** JSON object conforming to the schema's json_schema, built from effective normalized values. */
    public function toObject(string $schemaVersion, array $effective): array
    {
        $def = SchemaCatalog::definition($schemaVersion);
        $object = [];
        foreach ($def['fields'] as $name => $meta) {
            $v = $effective[$name]['normalized'] ?? null;
            $object[$meta['json_key']] = match ($meta['type']) {
                'money' => $v === null ? null : (int) $v,
                'list' => $v === null ? null : (is_array($v) ? $v : json_decode((string) $v, true)),
                default => $v,
            };
        }

        return $object;
    }

    public function validate(TriageCase $case, ExtractionRun $run, bool $withCorrections = false): ValidationRun
    {
        $def = SchemaCatalog::definition($run->schema_version);
        $effective = $this->effectiveFields($case, $run);
        $issues = [];
        $add = function (string $code, string $severity, ?string $field, string $message) use (&$issues) {
            $issues[] = compact('code', 'severity', 'field', 'message');
        };

        // 1. required fields
        foreach ($def['fields'] as $name => $meta) {
            $v = $effective[$name]['normalized'] ?? null;
            if ($meta['required'] && ($v === null || $v === '' || $v === '[]')) {
                $add('SCHEMA_REQUIRED_FIELD', 'error', $name, "Required field {$name} is missing");
            }
        }

        // 2. per-field parsing problems recorded during normalization (not for human-corrected fields, those are re-normalized)
        foreach ($def['fields'] as $name => $meta) {
            $e = $effective[$name] ?? null;
            if (! $e || $e['raw'] === null || $e['raw'] === '') {
                continue;
            }
            $norm = $this->normalizer->normalize($meta['type'], $e['raw']);
            foreach ($norm['warnings'] as $w) {
                $severity = in_array($w, ['UNPARSEABLE_DATE', 'UNPARSEABLE_AMOUNT'], true) ? 'error' : 'warning';
                $add($w, $severity, $name, $this->describe($w, $name, $e['raw']));
            }
            if ($meta['type'] === 'currency') {
                if ($norm['value'] === null || ! in_array($norm['value'], config('sorthouse.currencies'), true)) {
                    $add('INVALID_CURRENCY', 'error', $name, "Currency \"{$e['raw']}\" is not in the demo allow-list");
                }
            }
            if ($meta['type'] === 'email' && $norm['value'] !== null && ! filter_var($norm['value'], FILTER_VALIDATE_EMAIL)) {
                $add('INVALID_EMAIL', 'error', $name, "\"{$e['raw']}\" is not a valid e-mail address");
            }
            if (! $e['corrected'] && ! $e['has_evidence']) {
                $add('EVIDENCE_MISSING', 'error', $name, "No verifiable evidence for {$name}; the value is unsupported");
            }
        }

        // 3. JSON Schema over the normalized object
        $object = $this->toObject($run->schema_version, $effective);
        $schemaValid = true;
        $validator = new Validator;
        $result = $validator->validate(json_decode(json_encode($object)), json_decode(json_encode($def['json_schema'])));
        if (! $result->isValid()) {
            $schemaValid = false;
            foreach ((new ErrorFormatter)->format($result->error(), false) as $path => $messages) {
                $add('SCHEMA_INVALID', 'error', null, trim($path.' '.implode('; ', $messages)));
            }
        }

        // 4. business rules
        match ($def['schema']) {
            'invoice' => $this->invoiceRules($case, $object, $effective, $add),
            'complaint' => $this->complaintRules($object, $add),
            default => null,
        };

        // 5. low OCR confidence is a validation warning, not a silent pass
        foreach ($effective as $name => $e) {
            $min = $e['field']->evidence->pluck('ocr_confidence')->filter(fn ($c) => $c !== null)->map(fn ($c) => (float) $c)->min();
            if (! $e['corrected'] && $min !== null && $min < 0.80) {
                $add('LOW_OCR_CONFIDENCE', 'warning', $name, sprintf('OCR confidence for %s is %.0f%%; characters such as 8/B or 0/O may be misread', $name, $min * 100));
            }
        }

        $vr = ValidationRun::create([
            'workspace_id' => $case->workspace_id, 'triage_case_id' => $case->id, 'extraction_run_id' => $run->id,
            'case_version' => $case->version, 'schema_valid' => $schemaValid, 'with_corrections' => $withCorrections,
            'rules_version' => self::RULES_VERSION, 'issue_count' => count($issues),
        ]);
        foreach ($issues as $i) {
            $vr->issues()->create(['code' => $i['code'], 'severity' => $i['severity'], 'field_name' => $i['field'], 'message' => $i['message']]);
        }

        return $vr->load('issues');
    }

    private function invoiceRules(TriageCase $case, array $o, array $effective, callable $add): void
    {
        if ($o['subtotal_minor'] !== null && $o['tax_minor'] !== null && $o['total_minor'] !== null && $o['total_minor'] !== $o['subtotal_minor'] + $o['tax_minor']) {
            $add('TOTAL_MISMATCH', 'error', 'total', sprintf('subtotal %d + tax %d = %d, but the invoice total is %d (minor units)', $o['subtotal_minor'], $o['tax_minor'], $o['subtotal_minor'] + $o['tax_minor'], $o['total_minor']));
        }
        if ($o['issue_date'] && $o['due_date'] && $o['due_date'] < $o['issue_date']) {
            $add('DUE_BEFORE_ISSUE', 'error', 'due_date', 'Due date is earlier than the issue date');
        }
        if ($o['purchase_order_number'] === null) {
            $add('MISSING_PO', 'warning', 'purchase_order_number', 'No purchase order number found');
        } elseif (! preg_match('/^[A-Z0-9][A-Z0-9\-]{3,}$/', (string) $o['purchase_order_number'])) {
            $add('INVALID_PO_FORMAT', 'warning', 'purchase_order_number', 'Purchase order number has an unexpected format');
        }
        if ($o['vendor_name']) {
            if ($this->faults->consume($case->workspace_id, 'validation_lookup_timeout')) {
                $add('VENDOR_LOOKUP_PENDING', 'warning', 'vendor_name', 'Vendor reference lookup timed out; the vendor is not validated (validation_pending)');
            } elseif (! in_array(mb_strtolower($o['vendor_name']), array_map('mb_strtolower', config('sorthouse.reference_vendors')), true)) {
                $add('VENDOR_NOT_FOUND', 'warning', 'vendor_name', 'Vendor is not in the demo reference list');
            }
        }
    }

    private function complaintRules(array $o, callable $add): void
    {
        if ($o['order_number'] !== null && ! preg_match('/^[A-Z]{0,4}-?\d{4,}[A-Z0-9\-]*$/i', $o['order_number'])) {
            $add('ORDER_NUMBER_FORMAT', 'warning', 'order_number', 'Order number has an unexpected format');
        }
        if ($o['requested_resolution'] === null) {
            $add('MISSING_RESOLUTION', 'warning', 'requested_resolution', 'The customer did not state a requested resolution');
        }
    }

    private function describe(string $code, string $field, string $raw): string
    {
        return match ($code) {
            'UNPARSEABLE_DATE' => "Could not parse \"{$raw}\" as a date",
            'UNPARSEABLE_AMOUNT' => "Could not parse \"{$raw}\" as an amount",
            'AMBIGUOUS_DATE' => "\"{$raw}\" can be read as day/month or month/day; day-first was assumed",
            'AMBIGUOUS_AMOUNT_SEPARATOR' => "\"{$raw}\" is ambiguous (thousands or decimal separator)",
            default => $code.' on '.$field,
        };
    }
}
