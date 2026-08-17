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

    // TASK-016 — same env var + default as config/cors.php's
    // 'FRONTEND_URL' entry (the Agent Portal, not Admin), reused here so
    // FollowUpReminderNotification can build a link into an email.
    'agent_portal' => [
        'frontend_url' => env('FRONTEND_URL', 'http://agent.localhost:5178'),
    ],

    // TASK-020 — same env var + default as config/cors.php's
    // 'ADMIN_FRONTEND_URL' entry, reused here so
    // NewAgentRegistrationNotification can link a Company Admin straight
    // into the frontend-admin approval queue (not the Agent Portal).
    'company_admin_portal' => [
        'frontend_url' => env('ADMIN_FRONTEND_URL', 'http://admin.localhost:5179'),
    ],

    /*
     * TASK-139 / ADR-027 — Omise (Opn Payments).
     *
     * DELIBERATELY EMPTY OF CREDENTIALS. An earlier draft of this file (same
     * day, 2026-08-08) put OMISE_PUBLIC_KEY / OMISE_SECRET_KEY here, reading
     * one platform-wide pair from .env. That was WRONG and is recorded here so
     * nobody re-adds it: the human confirmed **one Omise account per company**
     * — customers' money lands in the selling company's own account, never the
     * platform's. A single .env key pair would have routed every tenant's
     * revenue into whichever account the platform happened to configure.
     *
     * Per-company credentials therefore live in the `company_payment_gateway_
     * settings` table, encrypted at rest with Laravel's `encrypted` cast (the
     * same treatment users.bank_account_number already gets, TASK-044) — not in
     * .env, because companies are created at runtime by admins and .env cannot
     * grow a new key pair per tenant without a deploy.
     *
     * This is a narrow, reasoned exception to §6 "Secrets: .env only", not a
     * weakening of it: §6's intent is "never in git, never in a response body",
     * and both still hold. See ADR-027 §3 for the full argument.
     *
     * Only genuinely platform-wide, deploy-time Omise settings would belong
     * here. There are none today.
     */

];
