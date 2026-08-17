<?php

namespace App\Enums;

// ADR-005 — self-registered Agents land in Pending until a Company
// Admin reviews them (TASK-020); Company-Admin-created Agents (the
// existing "Manage Agents" flow) skip straight to Approved, since the
// Admin creating the account already IS the approval. Fixed vocabulary,
// same style as ClientStatus/ClientActivityType — not BR-7 territory.
enum AgentApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }
}
