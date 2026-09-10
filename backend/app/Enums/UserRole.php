<?php

namespace App\Enums;

// The visibility levels of CLAUDE.md Section 5, rule 4.
// Not a permissions matrix — see TASK-001 "out of scope" note for why.
enum UserRole: string
{
    case Agent = 'agent';
    case CompanyAdmin = 'company_admin';
    case SuperAdmin = 'super_admin';

    /**
     * 2026-09-10 (human: "ที่ได้สิทธิ์ในการตัดได้เฉพาะหน้าการตัดสิทธิ์
     * เพราะทำงานคนละหน้าที่กัน") — THE FOURTH ROLE, and the first one added
     * since TASK-001.
     *
     * Front-desk staff: they redeem vouchers at a branch and do nothing else.
     * They are not a smaller Company Admin — they are a different job, and
     * the reason they need a role of their own is that every other way of
     * expressing them was worse:
     *
     *   · as an Agent, they cannot reach the admin console at all (the
     *     router logs an Agent straight back out);
     *   · as a Company Admin, they can read every sale, every commission
     *     figure and every customer's PDPA record in the company — to scan a
     *     code at a counter.
     *
     * A role says WHICH PART OF THE APP a person sees. What they may DO
     * inside it is the ability table plus their own grants
     * (PermissionResolver), which is why this role's row there holds exactly
     * one thing.
     *
     * BR-6 is unchanged: this user has a company_id like any other and every
     * scope reads it the same way.
     */
    case VoucherStaff = 'voucher_staff';

    public function label(): string
    {
        return match ($this) {
            self::Agent => 'Agent',
            self::CompanyAdmin => 'Company Admin',
            self::SuperAdmin => 'Super Admin',
            self::VoucherStaff => 'Voucher Staff',
        };
    }

    /**
     * Thai, for the screens people actually read. Kept beside label() rather
     * than in the UI layer because a role name is a thing this system names,
     * not a translation of a code identifier — and both admin apps would
     * otherwise carry their own copy.
     */
    public function labelTh(): string
    {
        return match ($this) {
            self::Agent => 'ตัวแทน',
            self::CompanyAdmin => 'ผู้ดูแลบริษัท',
            self::SuperAdmin => 'ผู้ดูแลระบบ',
            self::VoucherStaff => 'พนักงานหน้าร้าน (ตัดสิทธิ์บัตรกำนัล)',
        };
    }
}
