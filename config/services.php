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

    // The Python document-extraction / RAG service (ADR-0007, ADR-0010).
    'hr_ai' => [
        'url' => env('HR_AI_URL', 'http://localhost:8001'),
        'internal_token' => env('HR_AI_INTERNAL_TOKEN', 'dev-internal-token'),

        // Non-secret answer-model config passed to hr-ai /synthesise (ADR-0015).
        // The API KEY is NOT here — it is set via the admin screen, encrypted at
        // rest in answer_model_settings, and passed decrypted per call. These MUST
        // point at an EU-available model/endpoint (GDPR is deploy-time, deploy.md §1).
        'answer_provider' => env('HR_AI_ANSWER_PROVIDER', 'claude'),
        'answer_model' => env('HR_AI_ANSWER_MODEL', 'claude-sonnet-4-5'),
        'answer_endpoint' => env('HR_AI_ANSWER_ENDPOINT', 'https://api.anthropic.com'),

        // The question router (Sprint 2b-2, ADR-0016) — a SMALL/FAST model reusing
        // the SAME key path (the key is owned by hr-backend, passed decrypted per
        // call). NON-SECRET. The endpoint defaults to the answer endpoint; if a
        // distinct router endpoint is set it MUST still be EU (deploy.md §1). The
        // per-claim grounding check (§5) uses ANSWER_MODEL, not this.
        'router_model' => env('HR_AI_ROUTER_MODEL', 'claude-haiku-4-5'),
        'router_endpoint' => env('HR_AI_ROUTER_ENDPOINT', env('HR_AI_ANSWER_ENDPOINT', 'https://api.anthropic.com')),
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

];
