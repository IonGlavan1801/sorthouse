<?php

return [
    'provider' => env('AI_PROVIDER', 'heuristic'),

    'providers' => [
        'openai' => [
            'key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        ],
        'anthropic' => [
            'key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
        ],
        'gemini' => [
            'key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        ],
    ],

    // Versioned price table (USD per million tokens). Estimated cost only: never reconciled with a provider invoice.
    'pricing' => [
        'version' => 'pricing.2026-10-12.estimate',
        'models' => [
            'heuristic-baseline-v1' => ['input' => 0.0, 'output' => 0.0],
            'default' => ['input' => 3.0, 'output' => 15.0],
        ],
    ],

    'prompt_versions' => [
        'classify' => 'classify.v1',
        'extract' => 'extract.v1',
        'draft' => 'draft.v1',
    ],

    'resilience' => [
        'max_attempts' => 3,
        'base_delay_ms' => 200,
        'sleep' => env('SORTHOUSE_SLEEP', true),
    ],

    'limits' => [
        'attachment_max_bytes' => 4 * 1024 * 1024,
        'attachments_per_message' => 5,
        'pdf_max_pages' => 6,
        'session_ttl_minutes' => (int) env('DEMO_SESSION_TTL_MINUTES', 120),
        'max_cases_per_session' => 80,
    ],

    'allowed_mimes' => ['application/pdf', 'image/jpeg', 'image/png', 'text/plain'],

    'currencies' => ['USD', 'EUR', 'GBP', 'RON', 'MDL', 'CHF', 'PLN'],

    'budget' => [
        'daily_usd' => (float) env('DEMO_DAILY_BUDGET_USD', 2.0),
    ],

    'reference_vendors' => [
        'Northwind Components Ltd', 'Brightline Logistics GmbH', 'Alder & Finch Supplies', 'Kestrel Print Works',
        'Maple Harbor Foods SRL', 'Orbit Office Furniture', 'Quanta Cloud Services', 'Redwood Packaging Co',
        'Tidewater Marine Parts', 'Umbra Lighting Studio', 'Verdant Garden Supply', 'Zenith Cable & Wire',
    ],

    'pending_reconciliation_after_seconds' => 30,
];
