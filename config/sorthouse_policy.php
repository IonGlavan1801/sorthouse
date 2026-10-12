<?php

/**
 * Policy rule sets. A version is immutable once seeded: changing a threshold means a new version, and decisions keep the version used.
 * auto_min is chosen from the calibration report on the dev set (docs/calibration.json), not because a number "sounds right".
 */
return [
    'v1' => [
        // Dev sweep (docs/calibration.json): zero false auto actions at every threshold from 0.50 up, so dev cannot discriminate.
        // 0.85 is a conservative choice that keeps clear spam/general/RFQ cases automatable and leaves weak-signal cases to people.
        'auto_min' => 0.85,
        'block_min' => 0.30,
        'auto_actions' => [
            'spam_or_phishing' => 'move_to_demo_folder',
            'general_question' => 'mark_demo_status',
            'request_for_quote' => 'create_demo_task',
        ],
        'notes' => 'Invoice, complaint and order actions are approval-only. Auto actions are reversible and simulated.',
    ],
    'v2' => [
        'auto_min' => 0.75,
        'block_min' => 0.30,
        'auto_actions' => [
            'spam_or_phishing' => 'move_to_demo_folder',
            'general_question' => 'mark_demo_status',
            'request_for_quote' => 'create_demo_task',
        ],
        'notes' => 'Candidate: lower automation threshold. Use `policy:simulate` before activating.',
    ],
];
