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

        // Sprint 8, Step 5 (plan.md §1.3 build-authorization addition): the
        // caller-side chunk size for `/embed-batch` calls — MUST match (or be
        // ≤) hr-ai's own `EMBED_BATCH_MAX_TEXTS` (default 256, app/config.py),
        // which hr-ai enforces server-side regardless of what this value is.
        'embed_batch_cap' => env('HR_AI_EMBED_BATCH_CAP', 256),

        // Non-secret answer-model config passed to hr-ai /synthesise (ADR-0015).
        // The API KEY is NOT here — it is set via the admin screen, encrypted at
        // rest in answer_model_settings, and passed decrypted per call. These MUST
        // point at an EU-available model/endpoint (GDPR is deploy-time, deploy.md §1).
        'answer_provider' => env('HR_AI_ANSWER_PROVIDER', 'claude'),
        // Sprint 10-M: claude-sonnet-4-5 -> claude-sonnet-5. Governs BOTH
        // /synthesise and /ground (GroundingService reuses this same config —
        // see its own header comment); /route and /explain are unaffected,
        // they use router_model below. Model swap only, no prompt-text or
        // sampling-param changes (isolates the model variable per the sprint
        // scope fence). See hr-docs/sprints/sprint-10-M/ for the measurement.
        'answer_model' => env('HR_AI_ANSWER_MODEL', 'claude-sonnet-5'),
        'answer_endpoint' => env('HR_AI_ANSWER_ENDPOINT', 'https://api.anthropic.com'),

        // The question router (Sprint 2b-2, ADR-0016) — a SMALL/FAST model reusing
        // the SAME key path (the key is owned by hr-backend, passed decrypted per
        // call). NON-SECRET. The endpoint defaults to the answer endpoint; if a
        // distinct router endpoint is set it MUST still be EU (deploy.md §1). The
        // per-claim grounding check (§5) uses ANSWER_MODEL, not this.
        'router_model' => env('HR_AI_ROUTER_MODEL', 'claude-haiku-4-5'),
        'router_endpoint' => env('HR_AI_ROUTER_ENDPOINT', env('HR_AI_ANSWER_ENDPOINT', 'https://api.anthropic.com')),

        // OCR fallback for scanned/text-less pages (Sprint 7e, ADR-0026, review.md
        // §1.5/§2.2). Deliberately its OWN config value, NOT aliased to
        // answer_model — a future chat-quality-driven change to answer_model must
        // never silently change the OCR engine (review.md §1.5's own reasoning).
        // Reuses the SAME answer-model key (AnswerModelSetting) — both are Claude
        // calls against the same configured Anthropic account; there is no
        // separate OCR key setting. NON-SECRET, same EU-endpoint requirement as
        // answer_model (deploy.md §1).
        'ocr_provider' => env('HR_AI_OCR_PROVIDER', 'claude'),
        'ocr_model' => env('HR_AI_OCR_MODEL', 'claude-opus-5'),
        'ocr_endpoint' => env('HR_AI_OCR_ENDPOINT', env('HR_AI_ANSWER_ENDPOINT', 'https://api.anthropic.com')),
        // Per-document page cap (review.md §2.7) — bounds worst-case cost/latency
        // for one pathological upload. `--ocr-page-cap` overrides this per run.
        'ocr_page_cap' => (int) env('HR_AI_OCR_PAGE_CAP', 60),
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
