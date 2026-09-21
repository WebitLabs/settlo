<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * Google Gemini powers receipt/invoice extraction and the Ask Settlo chat.
     * The timeouts are sized for a 60-second serverless function: the worst
     * case of an extraction is attempts * (connect timeout + timeout) plus the
     * 0.5s pause between attempts, i.e. 2 * (5 + 15) + 0.5 ≈ 40s.
     */
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash'),
        'endpoint' => env('GEMINI_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta'),
        'extract_timeout' => (int) env('GEMINI_EXTRACT_TIMEOUT', 15),
        'extract_connect_timeout' => (int) env('GEMINI_EXTRACT_CONNECT_TIMEOUT', 5),
        'extract_attempts' => max(1, (int) env('GEMINI_EXTRACT_ATTEMPTS', 2)),
        'chat_timeout' => (int) env('GEMINI_CHAT_TIMEOUT', 45),
        'chat_max_output_tokens' => (int) env('GEMINI_CHAT_MAX_OUTPUT_TOKENS', 8192),
        'chat_thinking_level' => env('GEMINI_CHAT_THINKING_LEVEL', 'low'), // minimal|low|medium|high, null = model default
    ],

];
