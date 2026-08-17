<?php

namespace App\Enums;

// Lightweight CRM-style lead status for a Client, independent of any
// Referral's pipeline stage (Section 4.3 / PipelineStage) — a client
// with zero referrals still needs a status to show (human request,
// 2026-07-13, following a CRM-standards comparison: "Client-level
// status (แนะนำ)"). Fixed vocabulary like PipelineStage/CertTier,
// not an admin-configurable config table — this is a simple manual
// workflow marker an Agent sets themselves, not a business value like
// commission % or pricing (BR-7 doesn't apply here).
enum ClientStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Interested = 'interested';
    case NotInterested = 'not_interested';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Contacted => 'Contacted',
            self::Interested => 'Interested',
            self::NotInterested => 'Not Interested',
        };
    }
}
