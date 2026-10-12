<?php

namespace App\Domain\Extraction;

/**
 * Immutable schema definitions. Seeded into schema_versions; a change means a new version key, never an edit.
 * Field types: string | date | currency | money | email | text | list.
 * "json_key" is the key used in the validated JSON object (money fields become *_minor integers).
 */
final class SchemaCatalog
{
    public static function definitions(): array
    {
        return [
            'invoice.v1' => [
                'schema' => 'invoice',
                'title' => 'Invoice',
                'fields' => [
                    'vendor_name' => ['type' => 'string', 'required' => true, 'critical' => false, 'json_key' => 'vendor_name'],
                    'invoice_number' => ['type' => 'string', 'required' => true, 'critical' => true, 'json_key' => 'invoice_number'],
                    'issue_date' => ['type' => 'date', 'required' => true, 'critical' => false, 'json_key' => 'issue_date'],
                    'due_date' => ['type' => 'date', 'required' => true, 'critical' => true, 'json_key' => 'due_date'],
                    'currency' => ['type' => 'currency', 'required' => true, 'critical' => true, 'json_key' => 'currency'],
                    'subtotal' => ['type' => 'money', 'required' => false, 'critical' => false, 'json_key' => 'subtotal_minor'],
                    'tax' => ['type' => 'money', 'required' => false, 'critical' => false, 'json_key' => 'tax_minor'],
                    'total' => ['type' => 'money', 'required' => true, 'critical' => true, 'json_key' => 'total_minor'],
                    'purchase_order_number' => ['type' => 'string', 'required' => false, 'critical' => true, 'json_key' => 'purchase_order_number'],
                ],
            ],
            'complaint.v1' => [
                'schema' => 'complaint',
                'title' => 'Customer complaint',
                'fields' => [
                    'order_number' => ['type' => 'string', 'required' => true, 'critical' => true, 'json_key' => 'order_number'],
                    'customer_name' => ['type' => 'string', 'required' => true, 'critical' => false, 'json_key' => 'customer_name'],
                    'issue_summary' => ['type' => 'text', 'required' => true, 'critical' => false, 'json_key' => 'issue_summary'],
                    'requested_resolution' => ['type' => 'text', 'required' => false, 'critical' => true, 'json_key' => 'requested_resolution'],
                ],
            ],
            'rfq.v1' => [
                'schema' => 'rfq',
                'title' => 'Request for quote',
                'fields' => [
                    'company_name' => ['type' => 'string', 'required' => true, 'critical' => false, 'json_key' => 'company_name'],
                    'contact_name' => ['type' => 'string', 'required' => false, 'critical' => false, 'json_key' => 'contact_name'],
                    'contact_email' => ['type' => 'email', 'required' => false, 'critical' => false, 'json_key' => 'contact_email'],
                    'items' => ['type' => 'list', 'required' => true, 'critical' => true, 'json_key' => 'items'],
                    'deadline' => ['type' => 'date', 'required' => false, 'critical' => false, 'json_key' => 'deadline'],
                ],
            ],
        ];
    }

    public static function definition(string $version): ?array
    {
        $def = self::definitions()[$version] ?? null;

        return $def ? $def + ['json_schema' => self::jsonSchema($def), 'version' => $version] : null;
    }

    /** JSON Schema (draft 2020-12) for the normalized object. Unknown properties are rejected. */
    public static function jsonSchema(array $def): array
    {
        $props = [];
        $required = [];
        foreach ($def['fields'] as $f) {
            $props[$f['json_key']] = match ($f['type']) {
                'date' => ['type' => ['string', 'null'], 'pattern' => '^\d{4}-\d{2}-\d{2}$'],
                'currency' => ['type' => ['string', 'null'], 'pattern' => '^[A-Z]{3}$'],
                'money' => ['type' => ['integer', 'null']],
                'email' => ['type' => ['string', 'null'], 'format' => 'email'],
                'list' => ['type' => ['array', 'null'], 'items' => ['type' => 'object', 'required' => ['description'], 'properties' => ['description' => ['type' => 'string'], 'quantity' => ['type' => ['integer', 'null']]], 'additionalProperties' => false]],
                default => ['type' => ['string', 'null']],
            };
            $required[] = $f['json_key'];
        }

        return ['$schema' => 'https://json-schema.org/draft/2020-12/schema', 'type' => 'object', 'properties' => $props, 'required' => $required, 'additionalProperties' => false];
    }

    /** @return list<string> */
    public static function criticalFields(string $version): array
    {
        $def = self::definitions()[$version] ?? ['fields' => []];

        return array_keys(array_filter($def['fields'], fn ($f) => $f['critical']));
    }
}
