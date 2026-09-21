<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Payment gateway
    |--------------------------------------------------------------------------
    |
    | Which PaymentGateway implementation to bind: "stripe" (Laravel Cashier,
    | requires STRIPE_SECRET; local/testing fall back to the dummy without it),
    | "simulated" (payments are completed in-process, no card is charged — it
    | is allowed everywhere, but only when selected explicitly) or the local
    | "dummy" (never allowed in production).
    |
    */
    'payment_gateway' => env('SETTLO_PAYMENT_GATEWAY', 'simulated'),

    /*
    |--------------------------------------------------------------------------
    | Billing
    |--------------------------------------------------------------------------
    |
    | Every business workspace has its own subscription. Only the owner's first
    | workspace gets the free trial. Discounts (%) are keyed by the position of
    | the workspace among the owner's active subscriptions; the last entry
    | applies to all further ones. Each discount maps to a Stripe coupon id.
    |
    */
    'billing' => [
        'trial_days' => 14,
        'yearly_multiplier' => 10,
        'workspace_discounts' => [1 => 0, 2 => 20, 3 => 30],
        // Stripe coupon ids per discount (%). Empty uses the
        // `settlo-workspace-{percent}` id created by settlo:stripe-sync-plans.
        'stripe_coupons' => [
            20 => env('STRIPE_COUPON_WORKSPACE_20') ?: 'settlo-workspace-20',
            30 => env('STRIPE_COUPON_WORKSPACE_30') ?: 'settlo-workspace-30',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Legal
    |--------------------------------------------------------------------------
    |
    | Terms of Service / Privacy Notice links shown at registration. The
    | version is stored on the user when they accept the terms.
    |
    */
    'legal' => [
        'terms_url' => env('SETTLO_TERMS_URL', 'https://settlo.ch/terms'),
        'privacy_url' => env('SETTLO_PRIVACY_URL', 'https://settlo.ch/privacy'),
        'version' => env('SETTLO_TERMS_VERSION', '2026-09'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Phone verification (SMS one-time code)
    |--------------------------------------------------------------------------
    |
    | Off by default. When enabled, owners must confirm their mobile number
    | with a one-time code before using the app. "log" is the only driver for
    | now (the code is written to the log in the local environment only).
    |
    */
    'phone_verification' => [
        'enabled' => (bool) env('SETTLO_PHONE_VERIFICATION', false),
        'driver' => env('SETTLO_PHONE_VERIFICATION_DRIVER', 'log'),
        'code_ttl_minutes' => 10,
        'max_attempts' => 5,
        'resend_cooldown_seconds' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature gating
    |--------------------------------------------------------------------------
    |
    | Off by default: the POC shows the whole product to everyone, so no plan
    | ever hides a screen ("no hard paywalls"). The gating infrastructure is
    | always present and the plan tier stays visible in the UI — this switch
    | only decides whether non-quota gates actually block access. Escalation
    | quotas are always enforced regardless of this flag.
    |
    */
    'enforce_feature_gates' => (bool) env('SETTLO_ENFORCE_FEATURE_GATES', false),

    /*
    |--------------------------------------------------------------------------
    | Demo data
    |--------------------------------------------------------------------------
    |
    | Demo fixtures (Anna Müller and her two businesses, the accounting firm and
    | the Ask Settlo history) are seeded automatically in local and testing. A
    | deployed demo needs them too, so `settlo:seed-demo` — and `db:seed` — will
    | seed them outside local/testing only when this flag is explicitly on.
    | Passwords are then STRONG and randomly generated, and printed once so they
    | can be captured; the fixed weak password is local/testing only.
    |
    */
    'demo' => [
        'seed_outside_local' => (bool) env('SETTLO_SEED_DEMO_DATA', false),
        // Local/testing credential. Never used outside those environments.
        'local_password' => 'password',
    ],

    /*
    |--------------------------------------------------------------------------
    | Fiscal
    |--------------------------------------------------------------------------
    |
    | The fiscal year defaults to the current year in Swiss local time, so the
    | app rolls over on 1 January on its own. SETTLO_FISCAL_YEAR pins it (the
    | test suite and a year-end close both rely on being able to pin it).
    |
    */
    'current_fiscal_year' => (int) (env('SETTLO_FISCAL_YEAR')
        ?: (new DateTimeImmutable('now', new DateTimeZone('Europe/Zurich')))->format('Y')),
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

    /*
    |--------------------------------------------------------------------------
    | Accountant escalations
    |--------------------------------------------------------------------------
    |
    | `simulate_answer` keeps the investor-demo stand-in alive for owners whose
    | business has no accounting firm assigned: a few seconds after they
    | escalate, the canned answer is written so the loop can be demonstrated
    | end-to-end. It is never used when a firm *is* assigned — those escalations
    | must wait for a real human, and faking one would hide the queue from the
    | firm and mislead the owner about who answered. Set the switch to false to
    | turn the stand-in off everywhere.
    |
    */
    'escalation' => [
        'simulate_answer' => (bool) env('SETTLO_SIMULATE_ACCOUNTANT_ANSWER', true),
        'simulated_answer_delay_seconds' => (int) env('SETTLO_SIMULATED_ANSWER_DELAY', 4),
    ],

    /*
    |--------------------------------------------------------------------------
    | Security headers
    |--------------------------------------------------------------------------
    |
    | Applied to every response by App\Http\Middleware\SecurityHeaders. HSTS is
    | only emitted over HTTPS; set SETTLO_HSTS_MAX_AGE=0 to suppress it. The
    | Content-Security Policy is conservative on purpose (Filament, Livewire
    | and Alpine require inline scripts/styles and eval) — turn it off with
    | SETTLO_CSP_ENABLED=false if a third-party embed needs a wider policy.
    |
    */
    'security_headers' => [
        'hsts_max_age' => (int) env('SETTLO_HSTS_MAX_AGE', 31536000),
        'csp_enabled' => (bool) env('SETTLO_CSP_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue draining (serverless)
    |--------------------------------------------------------------------------
    |
    | Serverless deploys have no long-lived worker, so `settlo:drain-queue`
    | runs `queue:work --stop-when-empty` from the cron endpoint. max_time
    | must stay comfortably below the function's execution limit (60s on
    | Vercel) so the process exits instead of being killed mid-job.
    |
    */
    'queue_drain' => [
        'queues' => env('SETTLO_DRAIN_QUEUES', 'default,files,ai'),
        'max_time' => (int) env('SETTLO_DRAIN_MAX_TIME', 45),
        'tries' => (int) env('SETTLO_DRAIN_TRIES', 3),
        // MB. The worker runs inside an already-booted HTTP request, so the
        // framework's 128 MB default would stop it immediately.
        'memory' => (int) env('SETTLO_DRAIN_MEMORY', 512),
    ],
];
