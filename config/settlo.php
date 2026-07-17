<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Payment gateway
    |--------------------------------------------------------------------------
    |
    | Which PaymentGateway implementation to bind. The POC ships "dummy"; a real
    | provider (e.g. "stripe") can be added behind the same contract later.
    |
    */
    'payment_gateway' => env('SETTLO_PAYMENT_GATEWAY', 'dummy'),

    /*
    |--------------------------------------------------------------------------
    | Feature gating
    |--------------------------------------------------------------------------
    |
    | When true, plan feature gates are enforced across the app. Even for the
    | investor-demo POC the infrastructure is always present; this switch only
    | decides whether non-quota gates block access. Escalation quotas are always
    | enforced regardless of this flag.
    |
    */
    'enforce_feature_gates' => env('SETTLO_ENFORCE_FEATURE_GATES', true),

    /*
    |--------------------------------------------------------------------------
    | Anthropic (Ask Settlo)
    |--------------------------------------------------------------------------
    |
    | Server-side only. Never expose the API key to the frontend.
    |
    */
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-20250514'),
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fiscal
    |--------------------------------------------------------------------------
    */
    'current_fiscal_year' => (int) env('SETTLO_FISCAL_YEAR', 2026),
    'timezone' => 'Europe/Zurich',
];
