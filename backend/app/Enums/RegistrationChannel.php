<?php

namespace App\Enums;

// ADR-005 — reporting-only: which method an Agent used to create their
// account. Never used for any authorization decision (that's entirely
// AgentApprovalStatus's job) — this only answers "how did they sign up"
// for admin visibility/analytics.
enum RegistrationChannel: string
{
    case Email = 'email';
    case Facebook = 'facebook';
    case Line = 'line';
    case Google = 'google';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::Facebook => 'Facebook',
            self::Line => 'LINE',
            self::Google => 'Google',
        };
    }
}
