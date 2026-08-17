<?php

namespace App\Enums;

// The 3 fixed visibility levels defined in CLAUDE.md Section 5, rule 4.
// Not a permissions matrix — see TASK-001 "out of scope" note for why.
enum UserRole: string
{
    case Agent = 'agent';
    case CompanyAdmin = 'company_admin';
    case SuperAdmin = 'super_admin';

    public function label(): string
    {
        return match ($this) {
            self::Agent => 'Agent',
            self::CompanyAdmin => 'Company Admin',
            self::SuperAdmin => 'Super Admin',
        };
    }
}
