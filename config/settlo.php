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
    | Fiscal
    |--------------------------------------------------------------------------
    */
    'current_fiscal_year' => (int) env('SETTLO_FISCAL_YEAR', 2026),
    'timezone' => 'Europe/Zurich',

    /*
    |--------------------------------------------------------------------------
    | Ask Settlo rate limit
    |--------------------------------------------------------------------------
    |
    | Maximum Ask Settlo chat requests per authenticated user per minute. This
    | bounds third-party AI cost and availability: every stream/message turn
    | issues a live model call that is otherwise unmetered, so an authenticated
    | client cannot loop the endpoint into a runaway cost/DoS.
    |
    */
    'ask_settlo_rate_limit' => (int) env('SETTLO_ASK_RATE_LIMIT', 30),
];
